<?php
declare(strict_types=1);

final class Http
{
    private const ORIGINS = ['https://opendaw.studio', 'https://dev.opendaw.studio'];

    public static bool $trustForwardedProto = false;

    public static function json(int $status, array $body): never
    {
        http_response_code($status);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function html(string $html, int $status = 200): never
    {
        $etag = '"' . md5($html) . '"';
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-cache, private');
        header('ETag: ' . $etag);
        if ($status === 200 && trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
            http_response_code(304);
            exit;
        }
        echo $html;
        exit;
    }

    public static function redirect(string $location): never
    {
        header('Cache-Control: no-store');
        header('Location: ' . $location, true, 303);
        exit;
    }

    public static function fail(int $status, string $error): never
    {
        self::json($status, ['error' => $error]);
    }

    public static function isHttps(): bool
    {
        return self::isServerHttps() || (self::$trustForwardedProto && self::isForwardedHttps());
    }

    public static function isServerHttps(): bool
    {
        return !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    }

    public static function isForwardedHttps(): bool
    {
        return strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    public static function isLocal(): bool
    {
        $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $host = (string)preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
        return ($remote === '127.0.0.1' || $remote === '::1') && ($host === 'localhost' || $host === '127.0.0.1');
    }

    public static function isSecure(): bool
    {
        return self::isHttps() || self::isLocal();
    }

    public static function ownOrigin(): string
    {
        return (self::isHttps() ? 'https://' : 'http://') . (string)($_SERVER['HTTP_HOST'] ?? '');
    }

    public static function isAllowedOrigin(string $origin): bool
    {
        return $origin === self::ownOrigin() || in_array($origin, self::ORIGINS, true)
            || preg_match('#^https?://(localhost|127\.0\.0\.1)(:[0-9]{1,5})?$#', $origin) === 1;
    }

    public static function cors(): void
    {
        $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($origin !== '') {
            if (!self::isAllowedOrigin($origin)) {self::fail(403, 'origin');}
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            header('Access-Control-Allow-Methods: GET, POST');
            header('Access-Control-Allow-Headers: X-OpenDAW-Token, Content-Type');
            header('Access-Control-Max-Age: 86400');
            http_response_code(204);
            exit;
        }
    }

    public static function query(string $name): string
    {
        $value = $_GET[$name] ?? '';
        return is_string($value) ? $value : '';
    }

    public static function post(string $name): string
    {
        $value = $_POST[$name] ?? '';
        return is_string($value) ? trim($value) : '';
    }

    public static function intQuery(string $name, int $min): int
    {
        $value = self::query($name);
        if (preg_match('/^[0-9]{1,15}$/', $value) !== 1 || (int)$value < $min) {self::fail(400, $name);}
        return (int)$value;
    }

    public static function uuidQuery(string $name): string
    {
        $value = self::query($name);
        if (!Stage::isUuid($value)) {self::fail(400, $name);}
        return $value;
    }
}
