<?php
declare(strict_types=1);

final class Mailer
{
    public static function sender(array $config): string
    {
        $configured = (string)($config['mailFrom'] ?? '');
        if (filter_var($configured, FILTER_VALIDATE_EMAIL) !== false) {return $configured;}
        $host = preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
        return 'stage@' . preg_replace('/^www\./', '', (string)$host);
    }

    public static function send(array $config, string $to, string $subject, string $text, string $unfollowUrl, string $html = ''): bool
    {
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {return false;}
        $name = str_replace(["\r", "\n", '"'], '', (string)($config['title'] ?? 'openDAW Stage'));
        $from = self::sender($config);
        $headers = [
            'From' => '"' . self::encode($name) . '" <' . $from . '>',
            'MIME-Version' => '1.0',
            'List-Unsubscribe' => '<' . $unfollowUrl . '>'
        ];
        if ($html === '') {
            $headers['Content-Type'] = 'text/plain; charset=UTF-8';
            $headers['Content-Transfer-Encoding'] = 'base64';
            $body = chunk_split(base64_encode($text));
        } else {
            $boundary = 'stage-' . bin2hex(random_bytes(12));
            $headers['Content-Type'] = 'multipart/alternative; boundary="' . $boundary . '"';
            $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode($text))
                . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode($html)) . "--$boundary--\r\n";
        }
        $subject = self::encode(str_replace(["\r", "\n"], ' ', $subject));
        return @mail($to, $subject, $body, $headers, '-f' . $from) || @mail($to, $subject, $body, $headers);
    }

    public static function welcome(array $config, array $follower): bool
    {
        $title = (string)($config['title'] ?? 'openDAW Stage');
        $url = self::unfollowUrl($follower['key']);
        $text = "Hi {$follower['name']},\n\nyou now follow {$title}. You get a mail like this one whenever a new track is published:\n"
            . Stage::baseUrl() . "\n\n" . self::ABOUT . "\n\nIf this mail landed in your spam folder, please mark it as not spam and add "
            . self::sender($config) . " to your contacts, so the next ones reach your inbox.\n\nUnfollow: {$url}\n";
        return self::send($config, $follower['email'], "You follow {$title}", $text, $url);
    }

    public static function announce(array $config, array $followers, array $track): array
    {
        $title = (string)($config['title'] ?? 'openDAW Stage');
        $accepted = 0;
        $link = Stage::trackUrl($track['slug']);
        $cover = Stage::baseUrl() . 'media/' . rawurlencode(Media::folder($track)) . '/' . $track['cover'] . '?v=' . $track['updated'];
        $by = $track['artist'] === '' ? '' : ' by ' . $track['artist'];
        $description = Page::shorten((string)($track['description'] ?? ''), 600);
        $about = self::ABOUT . ($track['project'] ? ' ' . self::ABOUT_PROJECT : '');
        foreach ($followers as $follower) {
            $url = self::unfollowUrl($follower['key']);
            $text = "Hi {$follower['name']},\n\n{$title} published a new track: {$track['title']}{$by}\n{$link}\n"
                . ($description === '' ? '' : "\n{$description}\n") . "\n{$about}\n\nUnfollow: {$url}\n";
            $html = self::announceHtml($follower['name'], $title, $track, $by, $description, $about, $link, $cover, $url);
            if (self::send($config, $follower['email'], "New track: {$track['title']}", $text, $url, $html)) {$accepted++;}
        }
        return ['time' => time(), 'track' => $track['title'], 'followers' => count($followers), 'accepted' => $accepted];
    }

    public static function test(array $config, string $to): bool
    {
        $title = (string)($config['title'] ?? 'openDAW Stage');
        $text = "This is a test mail from {$title}:\n" . Stage::baseUrl() . "\n\nIf it reached your inbox, mails to your followers work.\n";
        return self::send($config, $to, "Test mail from {$title}", $text, Stage::baseUrl());
    }

    public static function unfollowUrl(string $key): string
    {
        return Stage::baseUrl() . 'api.php?action=unfollow&key=' . rawurlencode($key);
    }

    private const ABOUT = 'This track was made with openDAW, a free music studio that runs right in the web browser. '
        . 'No installation and no account needed, anyone can start making music at https://opendaw.studio.';
    private const ABOUT_PROJECT = 'The artist shared the project too: open it in openDAW from the track page and see how the track was made.';

    private static function announceHtml(string $name, string $site, array $track, string $by, string $description,
                                         string $about, string $link, string $cover, string $unfollow): string
    {
        $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $font = 'font-family:-apple-system,Segoe UI,Helvetica,Arial,sans-serif;';
        return '<!doctype html><html><body style="margin:0;padding:24px;background:#f4f5f6;' . $font . 'color:#1d2226">'
            . '<div style="max-width:480px;margin:0 auto;background:#ffffff;border-radius:6px;padding:24px">'
            . '<p style="margin:0 0 16px">Hi ' . $escape($name) . ',</p>'
            . '<p style="margin:0 0 16px">' . $escape($site) . ' published a new track.</p>'
            . '<a href="' . $escape($link) . '"><img src="' . $escape($cover) . '" alt="" width="432" '
            . 'style="display:block;width:100%;max-width:432px;height:auto;border-radius:4px"></a>'
            . '<h1 style="margin:16px 0 4px;font-size:22px">' . $escape($track['title']) . '</h1>'
            . ($by === '' ? '' : '<p style="margin:0 0 12px;color:#5b6670">' . $escape(substr($by, 1)) . '</p>')
            . ($description === '' ? '' : '<p style="margin:0 0 16px">' . nl2br($escape($description)) . '</p>')
            . '<p style="margin:16px 0"><a href="' . $escape($link) . '" style="display:inline-block;padding:10px 20px;'
            . 'background:#4ce6ff;color:#0d1113;text-decoration:none;border-radius:4px;font-weight:600">Listen</a></p>'
            . '<p style="margin:24px 0 0;font-size:13px;color:#5b6670">' . $escape($about) . '</p>'
            . '</div><p style="max-width:480px;margin:16px auto 0;font-size:12px;color:#8a949c;text-align:center">'
            . 'You get this mail because you follow ' . $escape($site) . '. <a href="' . $escape($unfollow) . '" style="color:#8a949c">Unfollow</a></p>'
            . '</body></html>';
    }

    private static function encode(string $text): string
    {
        return preg_match('/[^\x20-\x7e]/', $text) === 1 ? '=?UTF-8?B?' . base64_encode($text) . '?=' : $text;
    }
}
