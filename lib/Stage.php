<?php
declare(strict_types=1);

final class Stage
{
    public const VERSION = 1;
    public const STUDIO_PROD_URL = 'https://opendaw.studio';
    public const MANUAL_URL = 'https://github.com/andremichelle/opendaw-stage/blob/main/MANUAL.md';
    public const STUDIO_DEV_URL = 'https://dev.opendaw.studio';
    public const MAX_BYTES = 1_073_741_824;
    public const STALE_SECONDS = 86_400;
    public const FILES = ['meta.json', 'cover', 'mixdown.opus', 'peaks.bin', 'project.odb'];
    public const REQUIRED = ['meta.json', 'cover', 'mixdown.opus', 'peaks.bin'];
    public const RESERVED_SLUGS = ['admin', 'api', 'assets', 'custom', 'imprint', 'index', 'legal', 'lib', 'media', 'oauth', 'privacy', 'setup', 'templates', 'tests', 'tools', 'track'];

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

    public static function hasShortUrls(): bool
    {
        foreach (['HTTP_STAGE_SHORT_URLS', 'REDIRECT_HTTP_STAGE_SHORT_URLS', 'STAGE_SHORT_URLS', 'REDIRECT_STAGE_SHORT_URLS'] as $name) {
            if (($_SERVER[$name] ?? getenv($name)) === '1') {return true;}
        }
        return false;
    }

    public static function trackPath(string $slug): string
    {
        return self::hasShortUrls() ? rawurlencode($slug) : 'track.php?t=' . rawurlencode($slug);
    }

    public static function legalPath(string $page): string
    {
        return self::hasShortUrls() ? $page : 'legal.php?page=' . $page;
    }

    public static function trackUrl(string $slug): string
    {
        return self::baseUrl() . self::trackPath($slug);
    }
}
