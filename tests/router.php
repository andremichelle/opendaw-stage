<?php
// Router for local testing with php -S, adds what .htaccess and Apache do: php -S localhost:8000 tests/router.php
declare(strict_types=1);

putenv('STAGE_SHORT_URLS=1');
$path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$root = (string)$_SERVER['DOCUMENT_ROOT'];
if (preg_match('#^/(privacy|imprint)$#', $path, $legal) === 1) {
    $_GET['page'] = $legal[1];
    $_SERVER['SCRIPT_NAME'] = '/legal.php';
    require $root . '/legal.php';
    return true;
}
if (preg_match('#^/([a-z0-9-]+)$#', $path, $slug) === 1 && !file_exists($root . $path)) {
    $_GET['t'] = $slug[1];
    $_SERVER['SCRIPT_NAME'] = '/track.php';
    require $root . '/track.php';
    return true;
}
$file = realpath($root . $path);
if (!str_starts_with($path, '/media/')) {return false;}
if ($file === false || !is_file($file)) {
    http_response_code(404);
    return true;
}
$size = filesize($file);
$types = ['opus' => 'audio/ogg', 'jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
header('Content-Type: ' . ($types[pathinfo($file, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
header('Accept-Ranges: bytes');
$start = 0;
$end = $size - 1;
if (preg_match('/^bytes=(\d*)-(\d*)$/', (string)($_SERVER['HTTP_RANGE'] ?? ''), $match) === 1) {
    $start = $match[1] === '' ? max(0, $size - (int)$match[2]) : (int)$match[1];
    $end = $match[1] !== '' && $match[2] !== '' ? min((int)$match[2], $size - 1) : $end;
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
}
header('Content-Length: ' . ($end - $start + 1));
$handle = fopen($file, 'rb');
fseek($handle, $start);
echo fread($handle, $end - $start + 1);
fclose($handle);
