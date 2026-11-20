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

    public static function send(array $config, string $to, string $subject, string $text, string $unfollowUrl): bool
    {
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {return false;}
        $name = str_replace(["\r", "\n", '"'], '', (string)($config['title'] ?? 'openDAW Stage'));
        $from = self::sender($config);
        $headers = [
            'From' => '"' . self::encode($name) . '" <' . $from . '>',
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Transfer-Encoding' => '8bit',
            'List-Unsubscribe' => '<' . $unfollowUrl . '>'
        ];
        return @mail($to, self::encode(str_replace(["\r", "\n"], ' ', $subject)), $text, $headers);
    }

    public static function welcome(array $config, array $follower): bool
    {
        $title = (string)($config['title'] ?? 'openDAW Stage');
        $url = self::unfollowUrl($follower['key']);
        $text = "Hi {$follower['name']},\n\nyou now follow {$title}. You get a mail like this one whenever a new track is published:\n"
            . Stage::baseUrl() . "\n\nIf this mail landed in your spam folder, please mark it as not spam and add "
            . self::sender($config) . " to your contacts, so the next ones reach your inbox.\n\nUnfollow: {$url}\n";
        return self::send($config, $follower['email'], "You follow {$title}", $text, $url);
    }

    public static function announce(array $config, array $followers, array $track): void
    {
        $title = (string)($config['title'] ?? 'openDAW Stage');
        foreach ($followers as $follower) {
            $url = self::unfollowUrl($follower['key']);
            $by = $track['artist'] === '' ? '' : ' by ' . $track['artist'];
            $text = "Hi {$follower['name']},\n\n{$title} published a new track: {$track['title']}{$by}\n"
                . Stage::trackUrl($track['slug']) . "\n\nUnfollow: {$url}\n";
            self::send($config, $follower['email'], "New track: {$track['title']}", $text, $url);
        }
    }

    public static function unfollowUrl(string $key): string
    {
        return Stage::baseUrl() . 'api.php?action=unfollow&key=' . rawurlencode($key);
    }

    private static function encode(string $text): string
    {
        return preg_match('/[^\x20-\x7e]/', $text) === 1 ? '=?UTF-8?B?' . base64_encode($text) . '?=' : $text;
    }
}
