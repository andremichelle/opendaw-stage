<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

if (!Config::exists()) {Http::redirect('setup.php');}
$config = Config::load();
$storage = new JsonStorage(Config::dataRoot($config));
Session::resume($config);
$slug = Http::query('t');
$track = preg_match('/^[a-z0-9-]{1,80}$/', $slug) === 1 ? $storage->trackBySlug($slug) : null;
if (is_null($track) || ($track['hidden'] && !Session::isAdmin())) {
    Http::html(Template::render('message', array_merge(Page::common($config, $storage, ''),
        ['heading' => 'Not found', 'message' => 'This track does not exist (any more).', 'unfollowKey' => ''])), 404);
}
$data = array_merge(Page::common($config, $storage, $track['slug']), Page::track($track, $storage, true));
$visible = array_values(array_filter($storage->tracks(), fn(array $item) => !$item['hidden']));
$position = array_search($track['uuid'], array_column($visible, 'uuid'), true);
$previous = $position === false || $position === 0 ? null : $visible[$position - 1];
$next = $position === false ? null : ($visible[$position + 1] ?? null);
$data['hasPrevious'] = !is_null($previous);
$data['previousTitle'] = $previous['title'] ?? '';
$data['previousUrl'] = is_null($previous) ? '' : Stage::trackPath($previous['slug']);
$data['hasNext'] = !is_null($next);
$data['nextTitle'] = $next['title'] ?? '';
$data['nextUrl'] = is_null($next) ? '' : Stage::trackPath($next['slug']);
$data['comments'] = Page::comments($track, $storage);
$data['hasComments'] = count($data['comments']) > 0;
Http::html(Template::render('track', $data));
