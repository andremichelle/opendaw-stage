<?php
declare(strict_types=1);

final class Media
{
    public static function meta(string $path): ?array
    {
        $meta = Files::readJson($path, null);
        if (!is_array($meta)) {return null;}
        $title = self::text($meta['title'] ?? null, 200);
        $artist = self::text($meta['artist'] ?? '', 200);
        $description = self::text($meta['description'] ?? '', 10_000);
        $tags = self::tags($meta['tags'] ?? []);
        $bpm = $meta['bpm'] ?? null;
        $duration = $meta['duration'] ?? null;
        $downloads = $meta['downloads'] ?? [];
        if ($title === null || $title === '' || $artist === null || $description === null || $tags === null) {return null;}
        if (!is_null($bpm) && (!is_int($bpm) && !is_float($bpm) || $bpm <= 0 || $bpm > 999)) {return null;}
        if ((!is_int($duration) && !is_float($duration)) || $duration <= 0 || $duration > 86_400) {return null;}
        if (!is_array($downloads)) {return null;}
        return [
            'title' => $title, 'artist' => $artist, 'description' => $description, 'tags' => $tags,
            'bpm' => $bpm, 'duration' => $duration,
            'downloads' => ['mixdown' => ($downloads['mixdown'] ?? false) === true]
        ];
    }

    public static function coverExtension(string $path): ?string
    {
        $head = self::head($path, 12);
        if (str_starts_with($head, "\xFF\xD8\xFF")) {return 'jpg';}
        if (str_starts_with($head, "\x89PNG\r\n\x1A\n")) {return 'png';}
        if (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP') {return 'webp';}
        return null;
    }

    public static function isOpus(string $path): bool
    {
        $head = self::head($path, 36);
        return str_starts_with($head, 'OggS') && substr($head, 28, 8) === 'OpusHead';
    }

    public static function isPeaks(string $path): bool
    {
        $size = filesize($path);
        return $size !== false && $size > 0 && $size <= 65_536 && $size % 2 === 0;
    }

    public static function isZip(string $path): bool
    {
        return str_starts_with(self::head($path, 4), "PK\x03\x04");
    }

    public static function slug(string $title): string
    {
        $ascii = function_exists('iconv') ? (string)iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title) : $title;
        $slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii)), '-');
        $slug = rtrim(substr($slug, 0, 60), '-');
        return $slug === '' ? 'track' : $slug;
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (!is_string($value)) {return null;}
        $value = trim($value);
        return strlen($value) <= $max && preg_match('//u', $value) === 1 ? $value : null;
    }

    private static function tags(mixed $tags): ?array
    {
        if (!is_array($tags) || count($tags) > 16) {return null;}
        $result = [];
        foreach ($tags as $tag) {
            $tag = self::text($tag, 40);
            if ($tag === null) {return null;}
            if ($tag !== '' && !in_array($tag, $result, true)) {$result[] = $tag;}
        }
        return $result;
    }

    private static function head(string $path, int $length): string
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {return '';}
        $head = fread($handle, $length);
        fclose($handle);
        return $head === false ? '' : $head;
    }
}
