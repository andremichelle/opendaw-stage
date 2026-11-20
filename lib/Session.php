<?php
declare(strict_types=1);

final class Session
{
    private const NAME = 'stage';
    private const LIFETIME = 30 * 86_400;

    public static function start(array $config): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {return;}
        $folder = Config::dataRoot($config) . '/sessions';
        if (!Files::ensureDir($folder)) {Http::fail(500, 'storage');}
        if (random_int(0, 99) === 0) {Files::removeStale($folder, self::LIFETIME);}
        $path = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/') . '/';
        session_save_path($folder);
        session_name(self::NAME);
        session_cache_limiter('');
        session_set_cookie_params(['lifetime' => self::LIFETIME, 'path' => $path, 'secure' => Http::isHttps(),
            'httponly' => true, 'samesite' => 'Lax']);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.gc_probability', '0');
        session_start();
        if (!is_string($_SESSION['csrf'] ?? null)) {self::renewCsrf();}
    }

    public static function resume(array $config): void
    {
        if (is_string($_COOKIE[self::NAME] ?? null)) {self::start($config);}
    }

    public static function csrf(): string
    {
        return (string)($_SESSION['csrf'] ?? '');
    }

    public static function checkCsrf(): void
    {
        $sent = (string)($_SERVER['HTTP_X_CSRF'] ?? Http::post('csrf'));
        if ($sent === '' || self::csrf() === '' || !hash_equals(self::csrf(), $sent)) {Http::fail(403, 'csrf');}
    }

    public static function user(): ?array
    {
        $user = $_SESSION['user'] ?? null;
        return is_array($user) ? $user : null;
    }

    public static function login(array $user): void
    {
        session_regenerate_id(true);
        self::renewCsrf();
        $_SESSION['user'] = ['id' => $user['id'], 'name' => $user['name'], 'picture' => $user['picture']];
    }

    public static function logout(): void
    {
        unset($_SESSION['user']);
        session_regenerate_id(true);
    }

    public static function isAdmin(): bool
    {
        return ($_SESSION['admin'] ?? false) === true;
    }

    public static function setAdmin(bool $admin): void
    {
        session_regenerate_id(true);
        self::renewCsrf();
        $_SESSION['admin'] = $admin;
    }

    public static function flash(?string $message = null): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {return '';}
        if (!is_null($message)) {
            $_SESSION['flash'] = $message;
            return $message;
        }
        $flash = (string)($_SESSION['flash'] ?? '');
        unset($_SESSION['flash']);
        return $flash;
    }

    private static function renewCsrf(): void
    {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
}
