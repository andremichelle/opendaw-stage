<?php
// Writes release.json before a release: php tools/release.php <version> <oldest version that may update automatically>
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {exit;}
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once __DIR__ . '/Manifest.php';

$root = dirname(__DIR__);
[$version, $autoUpdateFrom] = [(string)($argv[1] ?? ''), (string)($argv[2] ?? '')];
foreach ([$version, $autoUpdateFrom] as $value) {
    if (preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}$/', $value) !== 1) {exit("Usage: php tools/release.php 1.2.0 1.1.0\n");}
}
$files = Manifest::shippedFiles($root);
foreach ($files as $file) {
    if (!Updater::isShippedPath($file)) {exit("Not allowed in a release: $file\n");}
}
file_put_contents($root . '/' . Updater::MANIFEST, json_encode(['version' => $version, 'autoUpdateFrom' => $autoUpdateFrom,
        'files' => $files], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
echo count($files) . " files, version $version, automatic update from $autoUpdateFrom\n";
