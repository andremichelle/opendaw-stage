<?php
declare(strict_types=1);

final class Profile
{
    public const FOLDER = '_stage';
    public const IMAGES = ['cover' => [2400, 600], 'avatar' => [400, 400]];
    public const MAX_IMAGE_BYTES = 4_194_304;
    public const MAX_LINKS = 12;
    public const MAX_BIO = 600;
    private const ICONS = ['bandcamp.com' => 'bandcamp', 'soundcloud.com' => 'soundcloud', 'instagram.com' => 'instagram',
        'youtube.com' => 'youtube', 'youtu.be' => 'youtube', 'bsky.app' => 'bluesky', 'x.com' => 'x', 'twitter.com' => 'x',
        'facebook.com' => 'facebook', 'tiktok.com' => 'tiktok', 'spotify.com' => 'spotify', 'github.com' => 'github'];

    public static function folder(): string
    {
        return Config::mediaRoot() . '/' . self::FOLDER;
    }

    public static function imageUrl(array $config, string $kind): string
    {
        $file = (string)($config[$kind . 'Image'] ?? '');
        return $file === '' ? '' : 'media/' . self::FOLDER . '/' . $file . '?v=' . (int)($config[$kind . 'Version'] ?? 0);
    }

    public static function storeImage(array &$config, string $kind, array $upload): string
    {
        if (!isset(self::IMAGES[$kind])) {return 'Unknown image.';}
        $path = (string)($upload['tmp_name'] ?? '');
        $status = $upload['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($status === UPLOAD_ERR_INI_SIZE || $status === UPLOAD_ERR_FORM_SIZE) {return 'The image is larger than your host accepts.';}
        if ($status !== UPLOAD_ERR_OK || !is_uploaded_file($path)) {return 'Please choose an image.';}
        if ((int)($upload['size'] ?? 0) > self::MAX_IMAGE_BYTES) {return 'The image is larger than 4 MB.';}
        $extension = Media::coverExtension($path);
        $info = getimagesize($path);
        if (is_null($extension) || $info === false) {return 'The image must be a JPEG, PNG or WebP file.';}
        [$maxWidth, $maxHeight] = self::IMAGES[$kind];
        if ($info[0] > $maxWidth * 2 || $info[1] > $maxHeight * 4) {return "The image is too large, at most {$maxWidth} × {$maxHeight} pixels are used.";}
        if (!Files::ensureDir(self::folder())) {return 'Could not create the image folder.';}
        $name = $kind . '.' . $extension;
        if (!move_uploaded_file($path, self::folder() . '/' . $name)) {return 'Could not save the image.';}
        $previous = (string)($config[$kind . 'Image'] ?? '');
        if ($previous !== '' && $previous !== $name) {@unlink(self::folder() . '/' . $previous);}
        $config[$kind . 'Image'] = $name;
        $config[$kind . 'Version'] = time();
        return '';
    }

    public static function removeImage(array &$config, string $kind): void
    {
        $file = (string)($config[$kind . 'Image'] ?? '');
        if ($file !== '' && isset(self::IMAGES[$kind])) {@unlink(self::folder() . '/' . $file);}
        unset($config[$kind . 'Image'], $config[$kind . 'Version']);
    }

    public static function parseLinks(string $text): ?array
    {
        $links = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {continue;}
            if (preg_match('/^(.*?)\s*(https?:\/\/\S+)$/u', $line, $match) !== 1
                || filter_var($match[2], FILTER_VALIDATE_URL) === false || strlen($line) > 300) {return null;}
            $links[] = ['label' => $match[1] === '' ? self::defaultLabel($match[2]) : $match[1], 'url' => $match[2]];
        }
        return count($links) > self::MAX_LINKS ? null : $links;
    }

    public static function linksText(array $config): string
    {
        return implode("\n", array_map(fn(array $link) => $link['label'] . ' ' . $link['url'], self::links($config)));
    }

    public static function links(array $config): array
    {
        $links = $config['links'] ?? [];
        return is_array($links) ? array_values(array_filter($links, fn(mixed $link) => is_array($link)
            && is_string($link['label'] ?? null) && is_string($link['url'] ?? null))) : [];
    }

    public static function icon(string $url): string
    {
        $host = (string)preg_replace('/^(www|m|music)\./', '', strtolower((string)parse_url($url, PHP_URL_HOST)));
        foreach (self::ICONS as $domain => $icon) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {return $icon;}
        }
        return str_starts_with((string)parse_url($url, PHP_URL_PATH), '/@') ? 'mastodon' : 'web';
    }

    private static function defaultLabel(string $url): string
    {
        return (string)preg_replace('/^www\./', '', (string)parse_url($url, PHP_URL_HOST));
    }
}
