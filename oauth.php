<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

if (!Config::exists()) {Http::redirect('setup.php');}
if (!Http::isSecure()) {Http::fail(403, 'https');}
$config = Config::load();
Visitors::handle('oauth', $config, new JsonStorage(Config::dataRoot($config)));
