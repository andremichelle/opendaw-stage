<?php
declare(strict_types=1);

final class Stage
{
    public const VERSION = 1;
    public const STUDIO_PROD_URL = 'https://opendaw.studio';
    public const STUDIO_DEV_URL = 'https://dev.opendaw.studio';
    public const MAX_BYTES = 1_073_741_824;
    public const STALE_SECONDS = 86_400;
    public const FILES = ['meta.json', 'cover', 'mixdown.opus', 'peaks.bin', 'project.odb'];
    public const REQUIRED = ['meta.json', 'cover', 'mixdown.opus', 'peaks.bin'];
    public const RESERVED_SLUGS = ['admin', 'api', 'assets', 'custom', 'index', 'lib', 'media', 'setup', 'templates', 'tests', 'track'];

    public static function root(): string
    {
        return dirname(__DIR__);
    }

    public static function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value) === 1;
    }

    public static function baseUrl(): string
    {
        $scheme = Http::isHttps() ? 'https' : 'http';
        $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        $path = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
        return $scheme . '://' . $host . $path . '/';
    }

    public static function studioOf(string $origin): string
    {
        return $origin !== '' && Http::isAllowedOrigin($origin) && $origin !== Http::ownOrigin() ? $origin : self::STUDIO_PROD_URL;
    }

    public static function trackUrl(string $slug): string
    {
        return self::baseUrl() . rawurlencode($slug);
    }
}
