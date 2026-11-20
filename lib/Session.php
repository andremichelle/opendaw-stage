<?php
declare(strict_types=1);

final class Session
{
    public static function start(): void
    {
        $path = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/') . '/';
        session_name('stage');
        session_cache_limiter('');
        session_set_cookie_params(['lifetime' => 30 * 86_400, 'path' => $path, 'secure' => Http::isHttps(),
            'httponly' => true, 'samesite' => 'Lax']);
        ini_set('session.gc_maxlifetime', (string)(30 * 86_400));
        session_start();
        if (!is_string($_SESSION['csrf'] ?? null)) {$_SESSION['csrf'] = bin2hex(random_bytes(16));}
    }

    public static function csrf(): string
    {
        return (string)$_SESSION['csrf'];
    }

    public static function checkCsrf(): void
    {
        $sent = (string)($_SERVER['HTTP_X_CSRF'] ?? Http::post('csrf'));
        if ($sent === '' || !hash_equals(self::csrf(), $sent)) {Http::fail(403, 'csrf');}
    }

    public static function user(): ?array
    {
        $user = $_SESSION['user'] ?? null;
        return is_array($user) ? $user : null;
    }

    public static function login(array $user): void
    {
        session_regenerate_id(true);
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
        $_SESSION['admin'] = $admin;
    }

    public static function flash(?string $message = null): string
    {
        if (!is_null($message)) {
            $_SESSION['flash'] = $message;
            return $message;
        }
        $flash = (string)($_SESSION['flash'] ?? '');
        unset($_SESSION['flash']);
        return $flash;
    }
}
