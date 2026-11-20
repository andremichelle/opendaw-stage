<?php
declare(strict_types=1);

final class Page
{
    public static function common(array $config, Storage $storage, string $return): array
    {
        $user = Session::user();
        $following = !is_null($user) && isset($storage->followers()[$user['id']]);
        return [
            'siteTitle' => (string)($config['title'] ?? 'openDAW Stage'),
            'baseUrl' => Stage::baseUrl(),
            'style' => Template::asset('style.css'),
            'script' => Template::asset('stage.js'),
            'favicon' => Template::asset('favicon.svg'),
            'csrf' => Session::csrf(),
            'google' => Google::isEnabled($config),
            'loggedIn' => !is_null($user),
            'userName' => $user['name'] ?? '',
            'userPicture' => $user['picture'] ?? '',
            'loginUrl' => 'api.php?action=login&return=' . rawurlencode($return),
            'following' => $following,
            'flash' => Session::flash(),
            'privacyPath' => Stage::legalPath('privacy'),
            'imprintPath' => Stage::legalPath('imprint'),
            'year' => date('Y'),
            'avatar' => Profile::imageUrl($config, 'avatar')
        ];
    }

    public static function track(array $track, Storage $storage, bool $withDescription = false): array
    {
        $user = Session::user();
        $likes = $storage->likes($track['uuid']);
        $comments = array_filter($storage->comments($track['uuid']),
            fn(array $comment) => $comment['status'] === 'approved' && ($comment['system'] ?? false) !== true);
        $media = 'media/' . rawurlencode(Media::folder($track)) . '/';
        $version = '?v=' . $track['updated'];
        $projectUrl = Stage::baseUrl() . 'api.php?action=project&t=' . rawurlencode($track['slug']);
        $description = $withDescription ? (string)($track['description'] ?? '') : '';
        return [
            'uuid' => $track['uuid'],
            'slug' => $track['slug'],
            'title' => $track['title'],
            'artist' => $track['artist'],
            'url' => Stage::trackPath($track['slug']),
            'absoluteUrl' => Stage::trackUrl($track['slug']),
            'cover' => $media . $track['cover'] . $version,
            'absoluteCover' => Stage::baseUrl() . $media . $track['cover'] . $version,
            'mixdown' => $media . 'mixdown.opus' . $version,
            'absoluteMixdown' => Stage::baseUrl() . $media . 'mixdown.opus' . $version,
            'peaks' => $media . 'peaks.bin' . $version,
            'duration' => self::duration((float)$track['duration']),
            'seconds' => (string)$track['duration'],
            'date' => date('j M Y', (int)$track['created']),
            'updatedDate' => date('j M Y', (int)$track['updated']),
            'wasUpdated' => $track['updated'] - $track['created'] > 60,
            'bpm' => is_null($track['bpm']) ? '' : (string)round((float)$track['bpm'], 2),
            'tags' => array_map(fn(string $tag) => ['tag' => $tag, 'tagUrl' => './?tag=' . rawurlencode($tag)], self::tags($track)),
            'tagList' => implode("\n", self::tags($track)),
            'created' => (string)$track['created'],
            'hasTags' => count(self::tags($track)) > 0,
            'likes' => count($likes),
            'liked' => !is_null($user) && in_array($user['id'], $likes, true),
            'commentCount' => count($comments),
            'description' => $description,
            'descriptionHtml' => nl2br(htmlspecialchars($description, ENT_QUOTES, 'UTF-8')),
            'shortDescription' => self::shorten($description, 200),
            'hasDescription' => $description !== '',
            'hasProject' => $track['project'],
            'studio' => self::studio($track),
            'openUrl' => self::studio($track) . '/open-stage?url=' . rawurlencode($projectUrl),
            'mixdownDownload' => $track['downloads']['mixdown'],
            'mixdownFileName' => $track['slug'] . '.opus',
            'hidden' => $track['hidden']
        ];
    }

    public static function tags(array $track): array
    {
        return array_values(array_filter(array_map('trim', $track['tags']), fn(string $tag) => $tag !== ''));
    }

    public static function studio(array $track): string
    {
        return (string)($track['studio'] ?? Stage::STUDIO_PROD_URL);
    }

    public static function comments(array $track, Storage $storage): array
    {
        $user = Session::user();
        $visible = array_filter($storage->comments($track['uuid']), fn(array $comment) => $comment['status'] === 'approved'
            || (!is_null($user) && $comment['user'] === $user['id']));
        return array_values(array_map(fn(array $comment) => [
            'commentId' => $comment['id'],
            'author' => ($comment['system'] ?? false) === true ? '' : (string)($comment['name'] ?? ''),
            'authorPicture' => (string)($comment['picture'] ?? ''),
            'text' => $comment['text'],
            'textHtml' => nl2br(htmlspecialchars($comment['text'], ENT_QUOTES, 'UTF-8')),
            'time' => date('j M Y, H:i', (int)$comment['time']),
            'pending' => $comment['status'] === 'pending',
            'system' => ($comment['system'] ?? false) === true
        ], $visible));
    }

    public static function duration(float $seconds): string
    {
        $total = (int)round($seconds);
        return $total >= 3600
            ? sprintf('%d:%02d:%02d', intdiv($total, 3600), intdiv($total % 3600, 60), $total % 60)
            : sprintf('%d:%02d', intdiv($total, 60), $total % 60);
    }

    public static function shorten(string $text, int $max): string
    {
        $line = trim((string)preg_replace('/\s+/', ' ', $text));
        if (strlen($line) <= $max) {return $line;}
        $cut = substr($line, 0, $max);
        $cut = (string)preg_replace('/[\x80-\xBF]*$/', '', (string)preg_replace('/[\xC0-\xFF][\x80-\xBF]*$/', '', $cut));
        return rtrim($cut) . '…';
    }
}
