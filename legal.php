<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

if (!Config::exists()) {Http::redirect('setup.php');}
$config = Config::load();
$storage = new JsonStorage(Config::dataRoot($config));
Session::resume($config);
$page = Http::query('page') === 'imprint' ? 'imprint' : 'privacy';
$owner = trim((string)($config['ownerName'] ?? ''));
$address = trim((string)($config['ownerAddress'] ?? ''));
$email = trim((string)($config['ownerEmail'] ?? ''));
Http::html(Template::render($page, array_merge(Page::common($config, $storage, ''), [
    'ownerName' => $owner,
    'ownerAddressHtml' => nl2br(htmlspecialchars($address, ENT_QUOTES, 'UTF-8')),
    'hasOwnerAddress' => $address !== '',
    'ownerEmail' => $email,
    'hasOwner' => $owner !== '' && $email !== ''
])));
