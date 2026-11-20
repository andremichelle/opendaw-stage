<?php
declare(strict_types=1);

final class Lockout
{
    public const WINDOW_SECONDS = 900;
    public const MAX_PER_ADDRESS = 5;
    public const MAX_TOTAL = 30;

    public static function isLocked(array $config): bool
    {
        $failures = self::current(Files::readJson(self::file($config), []));
        return ($failures['addresses'][self::address()] ?? 0) >= self::MAX_PER_ADDRESS || $failures['total'] >= self::MAX_TOTAL;
    }

    public static function fail(array $config): void
    {
        Files::withLock(self::file($config) . '.lock', function () use ($config) {
            $failures = self::current(Files::readJson(self::file($config), []));
            $failures['addresses'][self::address()] = ($failures['addresses'][self::address()] ?? 0) + 1;
            $failures['total']++;
            Files::writeJson(self::file($config), $failures);
        });
    }

    public static function clear(array $config): void
    {
        Files::withLock(self::file($config) . '.lock', function () use ($config) {
            $failures = self::current(Files::readJson(self::file($config), []));
            unset($failures['addresses'][self::address()]);
            Files::writeJson(self::file($config), $failures);
        });
    }

    private static function current(array $failures): array
    {
        $since = (int)($failures['since'] ?? 0);
        if (time() - $since >= self::WINDOW_SECONDS) {return ['since' => time(), 'total' => 0, 'addresses' => []];}
        return ['since' => $since, 'total' => (int)($failures['total'] ?? 0), 'addresses' => (array)($failures['addresses'] ?? [])];
    }

    private static function address(): string
    {
        return hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? ''));
    }

    private static function file(array $config): string
    {
        return Config::dataRoot($config) . '/login-failures.json';
    }
}
