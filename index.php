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
Session::resume($config);
$tracks = array_values(array_filter($storage->tracks(), fn(array $track) => !$track['hidden']));
$data = Page::common($config, $storage, '');
$data['tracks'] = array_map(fn(array $summary) => Page::track($summary, $storage), $tracks);
$data['empty'] = count($tracks) === 0;
$data['trackCount'] = count($tracks);
$data['many'] = count($tracks) > 1;
$featured = array_values(array_filter($data['tracks'], fn(array $track) => $track['uuid'] === ($config['featured'] ?? '')));
$data['hasFeatured'] = count($featured) === 1 && count($tracks) > 1;
if ($data['hasFeatured']) {
    $full = $storage->track($featured[0]['uuid']);
    $data['featured'] = [array_merge($featured[0], is_null($full) ? [] : array_intersect_key(Page::track($full, $storage, true),
        array_flip(['description', 'shortDescription', 'hasDescription'])))];
}
$tagCounts = [];
foreach ($data['tracks'] as $track) {
    foreach (explode("\n", $track['tagList']) as $tag) {
        if ($tag !== '') {$tagCounts[$tag] = ($tagCounts[$tag] ?? 0) + 1;}
    }
}
arsort($tagCounts);
$data['filterTags'] = array_map(fn(string $tag, int $count) => ['filterTag' => (string)$tag, 'filterCount' => $count],
    array_keys($tagCounts), array_values($tagCounts));
$data['hasTagFilter'] = count($tagCounts) > 1 && count($tracks) > 1;
$data['hasSearch'] = count($tracks) >= 6;
$data['hasSort'] = $data['google'] && count($tracks) > 1 && array_sum(array_column($data['tracks'], 'likes')) > 0;
$followers = count($storage->followers());
$data['followerCount'] = $followers;
$data['hasFollowers'] = $data['google'] && $followers > 0;
$data['cover'] = Profile::imageUrl($config, 'cover');
$siteImage = $data['cover'] !== '' ? $data['cover'] : $data['avatar'];
$data['siteImage'] = $siteImage === '' ? '' : Stage::baseUrl() . $siteImage;
$data['siteImageLarge'] = $data['cover'] !== '';
$data['bio'] = (string)($config['bio'] ?? '');
$data['links'] = array_map(fn(array $link) => ['linkLabel' => $link['label'], 'linkUrl' => $link['url'],
    'linkIcon' => Profile::icon($link['url'])], Profile::links($config));
$data['hasLinks'] = count($data['links']) > 0;
Http::html(Template::render('list', $data));
