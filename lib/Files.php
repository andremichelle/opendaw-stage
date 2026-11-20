<?php
declare(strict_types=1);

final class Files
{
    public static function writeAtomic(string $path, string $content): bool
    {
        $temp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($temp, $content) !== strlen($content)) {
            @unlink($temp);
            return false;
        }
        if (!rename($temp, $path)) {
            @unlink($temp);
            return false;
        }
        return true;
    }

    public static function writeJson(string $path, mixed $value): bool
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        return $json !== false && self::writeAtomic($path, $json);
    }

    public static function readJson(string $path, mixed $fallback): mixed
    {
        if (!is_file($path)) {return $fallback;}
        $content = file_get_contents($path);
        $value = $content === false ? null : json_decode($content, true);
        return $value ?? $fallback;
    }

    public static function ensureDir(string $path): bool
    {
        return is_dir($path) || @mkdir($path, 0755, true) || is_dir($path);
    }

    public static function removeTree(string $path): bool
    {
        if (is_link($path) || is_file($path)) {return unlink($path);}
        if (!is_dir($path)) {return true;}
        foreach (scandir($path) ?: [] as $name) {
            if ($name === '.' || $name === '..') {continue;}
            if (!self::removeTree($path . '/' . $name)) {return false;}
        }
        return rmdir($path);
    }

    public static function removeStale(string $folder, int $seconds): void
    {
        $limit = time() - $seconds;
        foreach (scandir($folder) ?: [] as $name) {
            if ($name === '.' || $name === '..') {continue;}
            $path = $folder . '/' . $name;
            $modified = filemtime($path);
            if ($modified !== false && $modified < $limit) {self::removeTree($path);}
        }
    }

    public static function deny(string $folder): void
    {
        file_put_contents($folder . '/index.html', '');
        file_put_contents($folder . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
    }

    public static function withLock(string $lockFile, callable $work): mixed
    {
        $handle = fopen($lockFile, 'c');
        if ($handle === false || !flock($handle, LOCK_EX)) {Http::fail(500, 'lock');}
        try {
            return $work();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
