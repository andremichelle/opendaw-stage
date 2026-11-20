<?php
declare(strict_types=1);

final class Manifest
{
    public static function shippedFiles(string $root): array
    {
        $files = [];
        exec('git -C ' . escapeshellarg($root) . ' ls-files --cached --others --exclude-standard', $files);
        $shipped = [];
        foreach ($files as $file) {
            if (!is_file($root . '/' . $file)) {continue;}
            $attribute = (string)shell_exec('git -C ' . escapeshellarg($root) . ' check-attr export-ignore -- ' . escapeshellarg($file));
            if (!str_ends_with(trim($attribute), ': set')) {$shipped[] = $file;}
        }
        $shipped[] = Updater::MANIFEST;
        $shipped = array_values(array_unique($shipped));
        sort($shipped);
        return $shipped;
    }
}
