<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

if (!Config::exists()) {Http::redirect('setup.php');}
$config = Config::load();
$storage = new JsonStorage(Config::dataRoot($config));
if (str_ends_with((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/index.php')) {
    header('Location: ./', true, 301);
    exit;
}
Session::start();
$tracks = array_values(array_filter($storage->tracks(), fn(array $track) => !$track['hidden']));
$data = Page::common($config, $storage, '');
$data['tracks'] = array_map(fn(array $summary) => Page::track($summary, $storage), $tracks);
$data['empty'] = count($tracks) === 0;
Http::html(Template::render('list', $data));
