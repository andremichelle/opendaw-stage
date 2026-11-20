<?php
declare(strict_types=1);

final class Visitors
{
    public const ACTIONS = ['login', 'logout', 'like', 'comment', 'follow', 'unfollow', 'project'];
    public const COMMENT_SECONDS = 300;
    public const COMMENT_BYTES = 2000;
    public const ACTION_SECONDS = 1.0;

    public static function handle(string $action, array $config, Storage $storage): never
    {
        $method = (string)($_SERVER['REQUEST_METHOD'] ?? '');
        if ($action === 'project') {self::project($storage);}
        if ($action === 'unfollow') {self::unfollow($config, $storage, $method);}
        if ($action === 'login' || $action === 'oauth') {
            Session::start($config);
            $action === 'login' ? self::login($config) : self::oauth($config, $storage);
        }
        if ($method !== 'POST') {Http::fail(405, 'method');}
        Session::resume($config);
        if ($action === 'logout') {
            if (!is_null(Session::user())) {
                Session::checkCsrf();
                Session::logout();
            }
            Http::redirect(self::returnUrl(self::returnPath(Http::post('return'))));
        }
        $user = Session::user() ?? Http::fail(401, 'login');
        Session::checkCsrf();
        $record = $storage->user($user['id']);
        if (is_null($record) || ($record['blocked'] ?? false) === true) {Http::fail(403, 'blocked');}
        if ($action === 'like' || $action === 'follow') {self::throttle($storage, $user['id']);}
        match ($action) {
            'like' => self::like($storage, $user),
            'comment' => self::comment($storage, $user),
            'follow' => self::follow($config, $storage, $record),
            default => Http::fail(400, 'action')
        };
    }

    public static function returnPath(string $return): string
    {
        return preg_match('/^[a-z0-9-]{1,80}$/', $return) === 1 ? $return : './';
    }

    private static function throttle(Storage $storage, string $id): void
    {
        $now = microtime(true);
        $allowed = false;
        $storage->updateUsers(function (array $users) use ($id, $now, &$allowed) {
            $allowed = $now - (float)($users[$id]['lastAction'] ?? 0) >= self::ACTION_SECONDS;
            if ($allowed) {$users[$id]['lastAction'] = $now;}
            return $users;
        });
        if (!$allowed) {Http::fail(429, 'wait');}
    }

    private static function returnUrl(string $return): string
    {
        return $return === './' ? './' : Stage::trackPath($return);
    }

    private static function visibleTrack(Storage $storage, string $uuid): array
    {
        $track = Stage::isUuid($uuid) ? $storage->track($uuid) : null;
        if (is_null($track) || $track['hidden']) {Http::fail(404, 'track');}
        return $track;
    }

    private static function login(array $config): never
    {
        if (!Google::isEnabled($config)) {Http::fail(404, 'login');}
        $state = bin2hex(random_bytes(16));
        $_SESSION['oauth'] = ['state' => $state, 'return' => self::returnPath(Http::query('return'))];
        Http::redirect(Google::authUrl($config, $state));
    }

    private static function oauth(array $config, Storage $storage): never
    {
        $pending = $_SESSION['oauth'] ?? null;
        unset($_SESSION['oauth']);
        $state = Http::query('state');
        if (!is_array($pending) || $state === '' || !hash_equals((string)$pending['state'], $state) || Http::query('code') === '') {
            self::message($config, 'Login failed', 'The login did not complete. Please try again.', 400);
        }
        $profile = Google::exchange($config, Http::query('code'));
        if (is_null($profile)) {self::message($config, 'Login failed', 'Google did not confirm the login. Please try again.', 400);}
        $storage->updateUsers(function (array $users) use ($profile) {
            $existing = $users[$profile['id']] ?? [];
            $users[$profile['id']] = array_merge(['created' => time(), 'blocked' => false, 'lastComment' => 0], $existing,
                ['id' => $profile['id'], 'name' => $profile['name'], 'picture' => $profile['picture'], 'email' => $profile['email']]);
            return $users;
        });
        Session::login($profile);
        Http::redirect(self::returnUrl((string)$pending['return']));
    }

    private static function like(Storage $storage, array $user): never
    {
        $track = self::visibleTrack($storage, Http::post('track'));
        $liked = $storage->toggleLike($track['uuid'], $user['id']);
        Http::json(200, ['liked' => $liked, 'likes' => count($storage->likes($track['uuid']))]);
    }

    private static function comment(Storage $storage, array $user): never
    {
        $track = self::visibleTrack($storage, Http::post('track'));
        $text = str_replace("\r\n", "\n", Http::post('text'));
        if ($text === '' || strlen($text) > self::COMMENT_BYTES || preg_match('//u', $text) !== 1) {Http::fail(422, 'text');}
        $now = time();
        $wait = 0;
        $storage->updateUsers(function (array $users) use ($user, $now, &$wait) {
            $last = (int)($users[$user['id']]['lastComment'] ?? 0);
            $wait = max(0, $last + self::COMMENT_SECONDS - $now);
            if ($wait === 0) {$users[$user['id']]['lastComment'] = $now;}
            return $users;
        });
        if ($wait > 0) {Http::json(429, ['error' => 'wait', 'seconds' => $wait]);}
        $storage->addComment($track['uuid'], ['id' => bin2hex(random_bytes(8)), 'user' => $user['id'], 'name' => $user['name'],
            'picture' => $user['picture'], 'text' => $text, 'time' => $now, 'status' => 'pending']);
        Session::flash('Thanks, your comment is waiting for approval.');
        Http::json(200, []);
    }

    private static function follow(array $config, Storage $storage, array $record): never
    {
        if (($record['email'] ?? '') === '') {Http::fail(422, 'email');}
        $added = null;
        $storage->updateFollowers(function (array $followers) use ($record, &$added) {
            if (isset($followers[$record['id']])) {
                unset($followers[$record['id']]);
                return $followers;
            }
            $added = ['id' => $record['id'], 'name' => $record['name'], 'email' => $record['email'],
                'key' => bin2hex(random_bytes(16)), 'since' => time()];
            $followers[$record['id']] = $added;
            return $followers;
        });
        if (!is_null($added) && (int)($record['welcomed'] ?? 0) === 0 && Mailer::welcome($config, $added)) {
            $storage->updateUsers(function (array $users) use ($record) {
                $users[$record['id']]['welcomed'] = time();
                return $users;
            });
        }
        Http::json(200, ['following' => !is_null($added)]);
    }

    private static function unfollow(array $config, Storage $storage, string $method): never
    {
        $key = $method === 'POST' ? Http::post('key') : Http::query('key');
        if (preg_match('/^[0-9a-f]{32}$/', $key) !== 1) {self::message($config, 'Unfollow', 'This link is not valid.', 400);}
        if ($method !== 'POST') {
            self::message($config, 'Unfollow', 'Stop getting mails about new tracks?', 200, $key);
        }
        $storage->updateFollowers(fn(array $followers) => array_filter($followers, fn(array $follower) => $follower['key'] !== $key));
        self::message($config, 'Unfollowed', 'You will not get mails about new tracks any more.', 200);
    }

    private static function project(Storage $storage): never
    {
        $slug = Http::query('t');
        $track = preg_match('/^[a-z0-9-]{1,80}$/', $slug) === 1 ? $storage->trackBySlug($slug) : null;
        $file = is_null($track) ? '' : Config::mediaRoot() . '/' . Media::folder($track) . '/project.odb';
        if (is_null($track) || $track['hidden'] || !$track['project'] || !is_file($file)) {Http::fail(404, 'project');}
        while (ob_get_level() > 0) {ob_end_clean();}
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: no-cache');
        readfile($file);
        exit;
    }

    private static function message(array $config, string $heading, string $message, int $status, string $unfollowKey = ''): never
    {
        Http::html(Template::render('message', array_merge(Page::common($config, new JsonStorage(Config::dataRoot($config)), ''),
            ['heading' => $heading, 'message' => $message, 'unfollowKey' => $unfollowKey])), $status);
    }
}
