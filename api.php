<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

Http::cors();
if (!Http::isSecure()) {Http::fail(403, 'https');}
if (!Config::exists()) {Http::fail(503, 'not-configured');}
$config = Config::load();
$action = Http::query('action');
if (in_array($action, Visitors::ACTIONS, true)) {Visitors::handle($action, $config, new JsonStorage(Config::dataRoot($config)));}
$method = (string)($_SERVER['REQUEST_METHOD'] ?? '');
$studioReads = ['alive', 'status'];
$studioWrites = ['begin', 'upload', 'chunk', 'commit', 'publish'];
if (in_array($action, $studioReads, true)) {
    if ($method !== 'GET') {Http::fail(405, 'method');}
} elseif (in_array($action, $studioWrites, true)) {
    if ($method !== 'POST') {Http::fail(405, 'method');}
} else {
    Http::fail(400, 'action');
}
if (!Config::isToken((string)($_SERVER['HTTP_X_OPENDAW_TOKEN'] ?? ''), $config)) {Http::fail(401, 'token');}

$dataRoot = Config::dataRoot($config);
$storage = new JsonStorage($dataRoot);
$upload = new Upload($dataRoot);

$file = static function (): string {
    $file = Http::query('file');
    if (!in_array($file, Stage::FILES, true)) {Http::fail(400, 'file');}
    return $file;
};

switch ($action) {
    case 'alive':
        $upload->cleanup();
        Http::json(200, ['version' => Stage::VERSION, 'title' => (string)($config['title'] ?? '')]);
    case 'status':
        $track = $storage->track(Http::uuidQuery('track'));
        Http::json(200, is_null($track) ? ['published' => false] : ['published' => true, 'slug' => $track['slug'],
            'url' => Stage::trackUrl($track['slug']), 'updated' => $track['updated']]);
    case 'begin':
        $upload->begin(Http::uuidQuery('track'));
        Http::json(200, []);
    case 'upload':
        $upload->upload(Http::uuidQuery('track'), $file());
        Http::json(200, []);
    case 'chunk':
        $upload->chunk(Http::uuidQuery('session'), Http::intQuery('index', 0));
        Http::json(200, []);
    case 'commit':
        $upload->commit(Http::uuidQuery('track'), $file(), Http::uuidQuery('session'),
            Http::intQuery('count', 1), Http::intQuery('size', 0));
        Http::json(200, []);
    case 'publish':
        $track = (new Publisher($storage, $upload, Config::mediaRoot()))->publish(Http::uuidQuery('track'), Stage::studioOf((string)($_SERVER['HTTP_ORIGIN'] ?? '')));
        if (!$track['replaced'] && !$track['hidden']) {
            $followers = $storage->followers();
            register_shutdown_function(static function () use ($config, $followers, $track) {
                if (function_exists('fastcgi_finish_request')) {fastcgi_finish_request();}
                ignore_user_abort(true);
                set_time_limit(0);
                Files::writeJson(Config::dataRoot($config) . '/mail-log.json', Mailer::announce($config, $followers, $track));
            });
        }
        Http::json(200, ['slug' => $track['slug'], 'url' => Stage::trackUrl($track['slug']), 'updated' => $track['updated'],
            'replaced' => $track['replaced']]);
}
