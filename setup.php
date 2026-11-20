<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

header('Cache-Control: no-store');
$page = static function (string $body): never {
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex"><title>openDAW Stage Setup</title><link rel="icon" type="image/svg+xml" href="' . Template::asset('favicon.svg') . '"><link rel="stylesheet" href="' . Template::asset('style.css') . '">'
        . '</head><body class="page-setup"><header class="site-header"><span class="site-title">openDAW Stage</span></header>'
        . '<main class="content setup">' . $body . '</main></body></html>';
    exit;
};
$escape = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
if (Config::exists()) {
    http_response_code(403);
    $page('<h1>openDAW Stage</h1><p>This stage is already set up. Change the settings on the admin page.</p>');
}
if (!Http::isSecure()) {
    http_response_code(403);
    $page('<h1>openDAW Stage</h1><p>Setup needs HTTPS. Open this page with https://.</p>');
}
$values = ['title' => '', 'token' => '', 'googleClientId' => '', 'googleClientSecret' => ''];
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    foreach (array_keys($values) as $name) {$values[$name] = Http::post($name);}
    $password = (string)($_POST['password'] ?? '');
    $repeat = (string)($_POST['repeat'] ?? '');
    if ($values['title'] === '' || strlen($values['title']) > 200) {
        $error = 'Please enter a title for your stage.';
    } elseif (preg_match('/^[A-Za-z0-9_-]{32,128}$/', $values['token']) !== 1) {
        $error = 'The token is not valid. Copy it again from openDAW.';
    } elseif (strlen($password) < 10) {
        $error = 'The admin password needs at least 10 characters.';
    } elseif ($password !== $repeat) {
        $error = 'The passwords do not match.';
    } elseif (($values['googleClientId'] === '') !== ($values['googleClientSecret'] === '')) {
        $error = 'Google login needs both the client id and the client secret, or neither.';
    } else {
        $config = [
            'version' => Stage::VERSION,
            'title' => $values['title'],
            'tokenHash' => hash('sha256', $values['token']),
            'passwordHash' => password_hash($password, PASSWORD_DEFAULT),
            'googleClientId' => $values['googleClientId'],
            'googleClientSecret' => $values['googleClientSecret'],
            'dataFolder' => 'data-' . bin2hex(random_bytes(16))
        ];
        $dataRoot = Config::dataRoot($config);
        $mediaRoot = Config::mediaRoot();
        if (!Files::ensureDir($dataRoot) || !Files::ensureDir($mediaRoot)) {
            $error = 'Could not create the data folders. Please check the write permissions of this folder.';
        } else {
            Files::deny($dataRoot);
            file_put_contents($mediaRoot . '/index.html', '');
            file_put_contents($mediaRoot . '/.htaccess', "<IfModule mod_mime.c>\nAddType audio/ogg .opus\n"
                . "AddType application/octet-stream .odb .bin\n</IfModule>\n"
                . "<FilesMatch \"\\.(php[0-9]?|phtml|phar|pl|py|cgi|sh)$\">\n<IfModule mod_authz_core.c>\nRequire all denied\n"
                . "</IfModule>\n</FilesMatch>\n"
                . "<IfModule mod_headers.c>\nHeader set Cache-Control \"public, max-age=31536000, immutable\"\n</IfModule>\n");
            if (!Config::save($config)) {
                $error = 'Could not write config.php. Please check the write permissions of this folder.';
            } else {
                $page('<h1>Your stage is ready</h1><p>Publish your first track from openDAW.</p>'
                    . '<p><a class="button primary" href="./">Open your stage</a></p>');
            }
        }
    }
}
$field = static fn(string $name, string $label, string $type = 'text') => '<label>' . $label
    . '<input type="' . $type . '" name="' . $name . '" value="' . ($type === 'password' ? '' : $escape($values[$name] ?? ''))
    . '" autocomplete="' . ($type === 'password' ? 'new-password' : 'off') . '"></label>';
$page('<h1>openDAW Stage Setup</h1>'
    . ($error === '' ? '' : '<p class="flash error">' . $escape($error) . '</p>')
    . '<form method="post" class="panel settings">'
    . $field('title', 'Title of your stage')
    . $field('token', 'Token from openDAW')
    . $field('password', 'Admin password', 'password')
    . $field('repeat', 'Repeat admin password', 'password')
    . '<p class="hint">Optional, for likes, comments and follow:</p>'
    . $field('googleClientId', 'Google client id')
    . $field('googleClientSecret', 'Google client secret')
    . '<button class="button primary" type="submit">Set up</button></form>');
