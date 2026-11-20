<?php
declare(strict_types=1);

final class Config
{
    public static function file(): string
    {
        return Stage::root() . '/config.php';
    }

    public static function exists(): bool
    {
        return is_file(self::file());
    }

    public static function load(): array
    {
        $config = self::exists() ? include self::file() : null;
        return is_array($config) ? $config : [];
    }

    public static function save(array $config): bool
    {
        $content = "<?php\n// openDAW Stage configuration, written by setup.php and the admin page\nreturn "
            . var_export($config, true) . ";\n";
        if (!Files::writeAtomic(self::file(), $content)) {return false;}
        if (function_exists('opcache_invalidate')) {opcache_invalidate(self::file(), true);}
        return true;
    }

    public static function dataRoot(array $config): string
    {
        return Stage::root() . '/' . $config['dataFolder'];
    }

    public static function mediaRoot(): string
    {
        return Stage::root() . '/media';
    }

    public static function isToken(string $token, array $config): bool
    {
        return $token !== '' && hash_equals((string)($config['tokenHash'] ?? ''), hash('sha256', $token));
    }
}
