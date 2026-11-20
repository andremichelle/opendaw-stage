<?php
// Runs the site against a fresh copy of the files git ships (ignored files left out) on php -S. Usage: php tests/run.php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {exit;}

$source = dirname(__DIR__);
$site = sys_get_temp_dir() . '/opendaw-stage-test-' . bin2hex(random_bytes(4));
$port = 18_000 + random_int(0, 999);
$base = "http://127.0.0.1:$port/";
$passed = 0;
$failed = 0;

function copyRepository(string $from, string $to): void
{
    $files = [];
    exec('git -C ' . escapeshellarg($from) . ' ls-files --cached --others --exclude-standard', $files, $status);
    if ($status !== 0 || count($files) === 0) {exit("git ls-files failed\n");}
    foreach ($files as $file) {
        if (str_starts_with($file, 'tests/') || !is_file("$from/$file")) {continue;}
        if (!is_dir(dirname("$to/$file"))) {mkdir(dirname("$to/$file"), 0755, true);}
        copy("$from/$file", "$to/$file");
    }
}

function removeTree(string $path): void
{
    if (is_file($path) || is_link($path)) {unlink($path); return;}
    foreach (scandir($path) as $name) {
        if ($name !== '.' && $name !== '..') {removeTree("$path/$name");}
    }
    rmdir($path);
}

function request(string $method, string $path, array $headers = [], string $body = '', ?array &$jar = null): array
{
    global $base;
    $lines = [];
    foreach ($headers as $name => $value) {$lines[] = "$name: $value";}
    if (!is_null($jar) && count($jar) > 0) {
        $lines[] = 'Cookie: ' . implode('; ', array_map(fn($name, $value) => "$name=$value", array_keys($jar), $jar));
    }
    $context = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $lines),
        'content' => $body, 'ignore_errors' => true, 'timeout' => 20, 'follow_location' => 0]]);
    $content = @file_get_contents($base . $path, false, $context);
    $responseHeaders = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : [];
    preg_match('#^HTTP/\S+ (\d{3})#', $responseHeaders[0] ?? '', $match);
    $named = [];
    foreach (array_slice($responseHeaders, 1) as $line) {
        [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
        $named[strtolower(trim($name))] = trim($value);
        if (!is_null($jar) && strtolower(trim($name)) === 'set-cookie') {
            [$pair] = explode(';', trim($value), 2);
            [$cookie, $cookieValue] = array_pad(explode('=', $pair, 2), 2, '');
            $jar[$cookie] = $cookieValue;
        }
    }
    return ['status' => (int)($match[1] ?? 0), 'headers' => $named, 'body' => (string)$content,
        'json' => json_decode((string)$content, true)];
}

function check(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  ok    $name\n";
    } else {
        $failed++;
        echo "  FAIL  $name" . ($detail === '' ? '' : "  ($detail)") . "\n";
    }
}

function uuid(): string
{
    $hex = bin2hex(random_bytes(16));
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
}

copyRepository($source, $site);
$server = proc_open([PHP_BINARY, '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0', '-S', "127.0.0.1:$port", '-t', $site, __DIR__ . '/router.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
for ($attempt = 0; $attempt < 50 && @fsockopen('127.0.0.1', $port) === false; $attempt++) {usleep(100_000);}

$token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$auth = ['X-OpenDAW-Token' => $token];
$meta = static fn(array $override = []) => json_encode(array_merge(['title' => 'Night Drive', 'artist' => 'Ana',
    'description' => 'First', 'tags' => ['synth', 'synth', 'night'], 'bpm' => 120, 'duration' => 184.5,
    'downloads' => ['mixdown' => true, 'project' => false], 'evil' => '<script>'], $override));
$cover = "\xFF\xD8\xFF" . random_bytes(2000);
$mixdown = 'OggS' . str_repeat("\0", 24) . 'OpusHead' . random_bytes(2_600_000);
$peaks = random_bytes(4096);
$project = "PK\x03\x04" . random_bytes(5000);
$upload = static fn(string $track, string $file, string $body) => request('POST', "api.php?action=upload&track=$track&file=$file", $auth, $body);
$chunked = static function (string $track, string $file, string $body, int $chunkSize = 1_048_576) use ($auth): array {
    $session = uuid();
    $chunks = str_split($body, $chunkSize);
    foreach ($chunks as $index => $chunk) {request('POST', "api.php?action=chunk&session=$session&index=$index", $auth, $chunk);}
    return request('POST', "api.php?action=commit&track=$track&file=$file&session=$session&count=" . count($chunks) . '&size=' . strlen($body), $auth);
};
$stage = static function (string $track, ?string $project = null, array $metaOverride = []) use ($auth, $upload, $chunked, $meta, $cover, $mixdown, $peaks): void {
    request('POST', "api.php?action=begin&track=$track", $auth);
    $upload($track, 'meta.json', $meta($metaOverride));
    $upload($track, 'cover', $cover);
    $upload($track, 'peaks.bin', $peaks);
    $chunked($track, 'mixdown.opus', $mixdown);
    if (!is_null($project)) {$upload($track, 'project.odb', $project);}
};

echo "Setup\n";
check('api before setup answers 503', request('GET', 'api.php?action=alive', $auth)['status'] === 503);
check('setup page renders', request('GET', 'setup.php')['status'] === 200);
$form = ['Content-Type' => 'application/x-www-form-urlencoded'];
$setup = static fn(array $fields) => request('POST', 'setup.php', $form, http_build_query(array_merge(['title' => 'My Stage',
    'token' => $token, 'password' => 'correct horse', 'repeat' => 'correct horse', 'googleClientId' => '', 'googleClientSecret' => ''], $fields)));
check('short password refused', str_contains($setup(['password' => 'short', 'repeat' => 'short'])['body'], 'at least 10'));
check('password mismatch refused', str_contains($setup(['repeat' => 'other password'])['body'], 'do not match'));
check('bad token refused', str_contains($setup(['token' => 'abc'])['body'], 'token is not valid'));
check('setup asks for no Google keys', !str_contains(request('GET', 'setup.php')['body'], 'googleClientId'));
check('no config after refusals', !is_file("$site/config.php"));
check('setup succeeds', str_contains($setup([])['body'], 'Your stage is ready'));
$config = include "$site/config.php";
check('config stores the token hash only', $config['tokenHash'] === hash('sha256', $token) && !str_contains(file_get_contents("$site/config.php"), $token));
check('config stores a password hash', password_verify('correct horse', $config['passwordHash']));
$data = "$site/" . $config['dataFolder'];
check('data folder is denied', is_file("$data/.htaccess") && str_contains(file_get_contents("$data/.htaccess"), 'denied'));
check('media folder blocks scripts', str_contains(file_get_contents("$site/media/.htaccess"), 'php'));
check('setup locks itself', request('POST', 'setup.php', $form, http_build_query(['title' => 'Hijack']))['status'] === 403);

echo "Auth and CORS\n";
check('missing token 401', request('GET', 'api.php?action=alive')['status'] === 401);
check('wrong token 401', request('GET', 'api.php?action=alive', ['X-OpenDAW-Token' => 'wrong'])['status'] === 401);
$alive = request('GET', 'api.php?action=alive', $auth);
check('alive answers version and title', $alive['status'] === 200 && $alive['json'] === ['version' => 1, 'title' => 'My Stage']);
check('foreign origin 403', request('GET', 'api.php?action=alive', $auth + ['Origin' => 'https://evil.example'])['status'] === 403);
$allowed = request('GET', 'api.php?action=alive', $auth + ['Origin' => 'https://opendaw.studio']);
check('openDAW origin allowed', $allowed['status'] === 200 && ($allowed['headers']['access-control-allow-origin'] ?? '') === 'https://opendaw.studio');
check('localhost origin allowed', request('GET', 'api.php?action=alive', $auth + ['Origin' => 'https://localhost:8080'])['status'] === 200);
$preflight = request('OPTIONS', 'api.php?action=upload', ['Origin' => 'https://dev.opendaw.studio']);
check('preflight without token 204', $preflight['status'] === 204 && str_contains($preflight['headers']['access-control-allow-headers'] ?? '', 'X-OpenDAW-Token'));
check('GET on a write action 405', request('GET', 'api.php?action=begin&track=' . uuid(), $auth)['status'] === 405);
check('unknown action 400', request('GET', 'api.php?action=list', $auth)['status'] === 400);

echo "Input rules\n";
$track = uuid();
check('bad track id 400', request('POST', 'api.php?action=begin&track=../x', $auth)['status'] === 400);
check('uppercase uuid 400', request('POST', 'api.php?action=begin&track=' . strtoupper($track), $auth)['status'] === 400);
check('unknown file name 400', $upload($track, 'index.php', '<?php echo 1;')['status'] === 400);
check('traversal file name 400', $upload($track, '../../x', 'x')['status'] === 400);
check('array parameter 400', request('POST', "api.php?action=upload&track=$track&file[]=cover", $auth, 'x')['status'] === 400);
check('negative chunk index 400', request('POST', 'api.php?action=chunk&session=' . uuid() . '&index=-1', $auth, 'x')['status'] === 400);
check('size over 1 GB 413', request('POST', "api.php?action=commit&track=$track&file=mixdown.opus&session=" . uuid() . '&count=1&size=1073741825', $auth)['status'] === 413);

echo "Chunks\n";
$session = uuid();
request('POST', "api.php?action=chunk&session=$session&index=0", $auth, 'aaaa');
check('commit with missing chunk 409', request('POST', "api.php?action=commit&track=$track&file=peaks.bin&session=$session&count=2&size=8", $auth)['status'] === 409);
request('POST', "api.php?action=chunk&session=$session&index=1", $auth, 'bbbb');
check('commit with wrong size 409', request('POST', "api.php?action=commit&track=$track&file=peaks.bin&session=$session&count=2&size=9", $auth)['status'] === 409);
request('POST', "api.php?action=chunk&session=$session&index=1", $auth, 'cccc');
check('retried chunk overwrites', request('POST', "api.php?action=commit&track=$track&file=peaks.bin&session=$session&count=2&size=8", $auth)['status'] === 200
    && file_get_contents("$data/staging/$track/peaks.bin") === 'aaaacccc');
check('repeated commit after success 200', request('POST', "api.php?action=commit&track=$track&file=peaks.bin&session=$session&count=2&size=8", $auth)['status'] === 200);
check('session removed after commit', !is_dir("$data/sessions/$session"));

echo "Publish\n";
$track = uuid();
check('status before publish', request('GET', "api.php?action=status&track=$track", $auth)['json'] === ['published' => false]);
request('POST', "api.php?action=begin&track=$track", $auth);
$upload($track, 'meta.json', $meta());
check('publish with missing files 422', request('POST', "api.php?action=publish&track=$track", $auth)['json'] === ['error' => 'missing', 'file' => 'cover']);
$stage($track, $project);
$published = request('POST', "api.php?action=publish&track=$track", $auth);
check('publish succeeds', $published['status'] === 200 && $published['json']['slug'] === 'night-drive', $published['body']);
check('first publish is no replace', $published['json']['replaced'] === false);
check('track url', ($published['json']['url'] ?? '') === "{$base}night-drive");
$media = "$site/media/night-drive";
check('media files in place', file_get_contents("$media/cover.jpg") === $cover && file_get_contents("$media/mixdown.opus") === $mixdown
    && file_get_contents("$media/peaks.bin") === $peaks && file_get_contents("$media/project.odb") === $project);
check('mixdown served as static file', request('GET', 'media/night-drive/mixdown.opus')['body'] === $mixdown);
check('staging cleared', !is_dir("$data/staging/$track"));
$record = json_decode(file_get_contents("$data/tracks/$track/track.json"), true);
check('meta normalised', $record['tags'] === ['synth', 'night'] && !isset($record['evil']) && $record['project'] === true
    && $record['downloads'] === ['mixdown' => true] && $record['hidden'] === false);
$index = json_decode(file_get_contents("$data/index.json"), true);
check('index holds a summary', ($index[$track]['title'] ?? '') === 'Night Drive' && !isset($index[$track]['description']));
check('status after publish', request('GET', "api.php?action=status&track=$track", $auth)['json']['published'] === true);

echo "Replace\n";
sleep(1);
$stage($track, null, ['title' => 'Night Drive Remastered']);
$replaced = request('POST', "api.php?action=publish&track=$track", $auth);
$after = json_decode(file_get_contents("$data/tracks/$track/track.json"), true);
check('replace keeps the slug', ($replaced['json']['slug'] ?? '') === 'night-drive');
check('replace is reported', ($replaced['json']['replaced'] ?? null) === true);
check('replace keeps created, moves updated', $after['created'] === $record['created'] && $after['updated'] > $record['updated']);
check('replace takes the new meta', $after['title'] === 'Night Drive Remastered' && $after['project'] === false);
check('replace without project removes the bundle', !is_file("$media/project.odb"));
$comments = json_decode(file_get_contents("$data/tracks/$track/comments.json"), true);
check('replace adds an Updated comment', count($comments) === 1 && $comments[0]['text'] === 'Updated' && $comments[0]['system'] === true);
$second = uuid();
$stage($second);
check('same title gets its own slug', request('POST', "api.php?action=publish&track=$second", $auth)['json']['slug'] === 'night-drive-2');
$third = uuid();
$stage($third, null, ['title' => 'Écoute ça!']);
check('slug from accented title', in_array(request('POST', "api.php?action=publish&track=$third", $auth)['json']['slug'], ['ecoute-ca', 'coute-a', 'e-coute-c-a'], true));

$reserved = uuid();
$stage($reserved, null, ['title' => 'Admin']);
check('reserved name gets a prefix', request('POST', "api.php?action=publish&track=$reserved", $auth)['json']['slug'] === 'track-admin');
$dataLike = uuid();
$stage($dataLike, null, ['title' => 'Data Loss']);
check('data folder prefix avoided', request('POST', "api.php?action=publish&track=$dataLike", $auth)['json']['slug'] === 'track-data-loss');

echo "Validation\n";
$bad = uuid();
$stage($bad, null, ['title' => '']);
check('empty title 422', request('POST', "api.php?action=publish&track=$bad", $auth)['json'] === ['error' => 'meta']);
$stage($bad, null, ['duration' => 0]);
check('zero duration 422', request('POST', "api.php?action=publish&track=$bad", $auth)['json'] === ['error' => 'meta']);
$stage($bad, null, ['tags' => 'synth']);
check('tags not a list 422', request('POST', "api.php?action=publish&track=$bad", $auth)['json'] === ['error' => 'meta']);
$stage($bad);
$upload($bad, 'cover', '<?php echo 1;');
check('cover with wrong bytes 422', request('POST', "api.php?action=publish&track=$bad", $auth)['json'] === ['error' => 'cover']);
$stage($bad);
$upload($bad, 'mixdown.opus', 'RIFF....WAVE');
check('mixdown not Ogg Opus 422', request('POST', "api.php?action=publish&track=$bad", $auth)['json'] === ['error' => 'mixdown']);
$stage($bad);
$upload($bad, 'peaks.bin', 'abc');
check('odd peaks size 422', request('POST', "api.php?action=publish&track=$bad", $auth)['json'] === ['error' => 'peaks']);
$stage($bad, '<?php echo 1;');
check('project not a zip 422', request('POST', "api.php?action=publish&track=$bad", $auth)['json'] === ['error' => 'project']);
check('nothing published for refused tracks', !is_file("$data/tracks/$bad/track.json"));
$stage($bad);
request('POST', "api.php?action=begin&track=$bad", $auth);
check('begin clears earlier staging', !is_dir("$data/staging/$bad"));

echo "Pages\n";
$visitor = [];
$withProject = uuid();
$stage($withProject, $project, ['title' => 'Open Me', 'downloads' => ['mixdown' => false, 'project' => false]]);
request('POST', "api.php?action=publish&track=$withProject", $auth + ['Origin' => 'https://dev.opendaw.studio']);
$evil = uuid();
$stage($evil, null, ['title' => '<script>alert(1)</script>{{csrf}}', 'description' => "Line one\n<b>{{{csrf}}}</b>"]);
request('POST', "api.php?action=publish&track=$evil", $auth);
$list = request('GET', '', [], '', $visitor);
$legacy = request('GET', 'index.php');
check('index.php redirects to the root', $legacy['status'] === 301 && ($legacy['headers']['location'] ?? '') === './');
check('no index.php links left', !str_contains($list['body'], 'index.php'));
check('list renders', $list['status'] === 200 && str_contains($list['body'], 'Night Drive Remastered') && str_contains($list['body'], 'Open Me'));
check('list has no leftover template tags', !str_contains($list['body'], '{{') && !str_contains($list['body'], '<!-- if:') && !str_contains($list['body'], '<!-- each:'));
check('list sends no-cache and an ETag', ($list['headers']['cache-control'] ?? '') === 'no-cache, private' && ($list['headers']['etag'] ?? '') !== '');
check('unchanged list answers 304', request('GET', '', ['If-None-Match' => $list['headers']['etag'] ?? ''], '', $visitor)['status'] === 304);
preg_match('/name="csrf" content="([0-9a-f]+)"/', $list['body'], $csrfMatch);
$csrf = $csrfMatch[1] ?? '';
check('titles are escaped', str_contains($list['body'], '&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($list['body'], '<script>alert'));
check('placeholders inside values stay text', str_contains($list['body'], '&lt;/script&gt;&#123;&#123;csrf}}'));
check('anonymous page views start no session', $csrf === '' && !isset($visitor['stage']));
$page = request('GET', 'track.php?t=open-me', [], '', $visitor);
check('track page renders', $page['status'] === 200 && str_contains($page['body'], '<h1 class="title">Open Me</h1>'));
check('track page has Open Graph tags', str_contains($page['body'], 'property="og:image" content="' . $base . 'media/open-me/cover.jpg?v='));
check('open in openDAW links the bundle', str_contains($page['body'], 'https://dev.opendaw.studio/open-stage?url=' . rawurlencode($base . 'api.php?action=project&t=open-me')));
$local = uuid();
$stage($local, $project, ['title' => 'Local Test']);
request('POST', "api.php?action=publish&track=$local", $auth + ['Origin' => 'https://localhost:8080']);
check('track published from localhost opens localhost', str_contains(request('GET', 'local-test')['body'], 'href="https://localhost:8080/open-stage?url='));
check('no download buttons when not allowed', !str_contains($page['body'], 'Download mixdown') && !str_contains($page['body'], 'Download project'));
check('no like and comments without Google', !str_contains($page['body'], 'data-like=') && !str_contains($page['body'], 'id="comments"') && !str_contains($list['body'], 'data-like='));
$evilPage = request('GET', 'track.php?t=script-alert-1-script-csrf', [], '', $visitor);
check('description escaped with line breaks', $evilPage['status'] === 200 && str_contains($evilPage['body'], 'Line one<br />' . "\n" . '&lt;b&gt;&#123;&#123;&#123;csrf}}}&lt;/b&gt;'));
check('short track address', request('GET', 'open-me')['status'] === 200 && str_contains(request('GET', 'open-me')['body'], '<h1 class="title">Open Me</h1>'));
check('list links the short address', str_contains($list['body'], 'href="open-me"'));
check('unknown short address 404', request('GET', 'nope')['status'] === 404);
check('old address still works', request('GET', 'track.php?t=open-me')['status'] === 200);
check('unknown track 404', request('GET', 'track.php?t=nope', [], '', $visitor)['status'] === 404);
check('bad slug 404', request('GET', 'track.php?t=../x', [], '', $visitor)['status'] === 404);
$bundle = request('GET', 'api.php?action=project&t=open-me', ['Origin' => 'https://dev.opendaw.studio']);
check('bundle served with CORS', $bundle['status'] === 200 && $bundle['body'] === $project && ($bundle['headers']['access-control-allow-origin'] ?? '') === 'https://dev.opendaw.studio');
check('bundle of a track without project 404', request('GET', 'api.php?action=project&t=night-drive')['status'] === 404);

echo "Visitors\n";
check('like without session 401', request('POST', 'api.php?action=like', ['Content-Type' => 'application/x-www-form-urlencoded'], "track=$withProject", $visitor)['status'] === 401);
check('like without login 401', request('POST', 'api.php?action=like', ['Content-Type' => 'application/x-www-form-urlencoded', 'X-CSRF' => $csrf], "track=$withProject", $visitor)['status'] === 401);
check('comment without login 401', request('POST', 'api.php?action=comment', ['Content-Type' => 'application/x-www-form-urlencoded', 'X-CSRF' => $csrf], "track=$withProject&text=hi", $visitor)['status'] === 401);
check('login without Google 404', request('GET', 'api.php?action=login', [], '', $visitor)['status'] === 404);
check('foreign origin on visitor action 403', request('POST', 'api.php?action=like', ['Origin' => 'https://evil.example', 'X-CSRF' => $csrf], '', $visitor)['status'] === 403);
check('oauth without state 400', request('GET', 'oauth.php?code=x&state=y', [], '', $visitor)['status'] === 400);
check('unfollow with a bad key 400', request('GET', 'api.php?action=unfollow&key=zz', [], '', $visitor)['status'] === 400);
$followersFile = "$data/followers.json";
file_put_contents($followersFile, json_encode(['google:1' => ['id' => 'google:1', 'name' => 'Ana', 'email' => 'ana@example.com', 'key' => str_repeat('a', 32), 'since' => time()]]));
check('unfollow link asks first', str_contains(request('GET', 'api.php?action=unfollow&key=' . str_repeat('a', 32), [], '', $visitor)['body'], 'name="key" value="' . str_repeat('a', 32)));
check('follower still there before confirming', count(json_decode(file_get_contents($followersFile), true)) === 1);
request('POST', 'api.php?action=unfollow', ['Content-Type' => 'application/x-www-form-urlencoded'], 'key=' . str_repeat('a', 32), $visitor);
check('unfollow removes the follower', count(json_decode(file_get_contents($followersFile), true)) === 0);

echo "Logged in visitor\n";
$userId = 'google:42';
$userCsrf = str_repeat('c', 32);
$sessionId = bin2hex(random_bytes(16));
file_put_contents("$data/users.json", json_encode([$userId => ['id' => $userId, 'name' => 'Ana', 'picture' => '', 'email' => 'ana@example.com',
    'created' => time(), 'blocked' => false, 'lastComment' => 0]]));
file_put_contents("$data/sessions/sess_$sessionId", 'csrf|' . serialize($userCsrf) . 'user|' . serialize(['id' => $userId, 'name' => 'Ana', 'picture' => '']));
$ana = ['stage' => $sessionId];
$asAna = static function (string $action, string $body, string $csrf = '') use (&$ana, $userCsrf) {
    return request('POST', "api.php?action=$action", ['Content-Type' => 'application/x-www-form-urlencoded', 'X-CSRF' => $csrf === '' ? $userCsrf : $csrf], $body, $ana);
};
check('foreign session id refused (strict mode)', request('GET', 'local-test', [], '', ...[$foreign = ['stage' => str_repeat('a', 32)]])['status'] === 200 && !is_file("$data/sessions/sess_" . str_repeat('a', 32)));
check('wrong csrf 403 for a logged in user', $asAna('like', "track=$withProject", str_repeat('x', 32))['status'] === 403);
$liked = $asAna('like', "track=$withProject");
check('like works', $liked['status'] === 200 && $liked['json'] === ['liked' => true, 'likes' => 1]);
check('second like within a second 429', $asAna('like', "track=$withProject")['status'] === 429);
usleep(1_100_000);
check('like toggles after a second', $asAna('like', "track=$withProject")['json']['liked'] === false);
$commented = $asAna('comment', "track=$withProject&text=" . rawurlencode('Nice <b>track</b>'));
$stored = json_decode(file_get_contents("$data/tracks/$withProject/comments.json"), true);
check('comment saved as pending', $commented['status'] === 200 && end($stored)['status'] === 'pending');
$withGoogle = include "$site/config.php";
file_put_contents("$site/config.php", "<?php\nreturn " . var_export(['googleClientId' => 'test-id', 'googleClientSecret' => 'test-secret'] + $withGoogle, true) . ";\n");
$anaPage = request('GET', 'open-me', [], '', $ana)['body'];
check('author sees own pending comment', str_contains($anaPage, 'Waiting for approval') && str_contains($anaPage, 'Nice &lt;b&gt;track&lt;/b&gt;'));
check('others do not see the pending comment', !str_contains(request('GET', 'open-me')['body'], 'Nice &lt;b&gt;'));
file_put_contents("$site/config.php", "<?php\nreturn " . var_export($withGoogle, true) . ";\n");
check('second comment within 5 minutes 429', $asAna('comment', "track=$withProject&text=again")['status'] === 429);
usleep(1_100_000);
check('follow works', $asAna('follow', '')['json'] === ['following' => true]);
$welcomed = json_decode(file_get_contents("$data/users.json"), true)[$userId]['welcomed'] ?? 0;
check('welcome mail marked as sent', $welcomed > 0);
usleep(1_100_000);
check('unfollow works', $asAna('follow', '')['json'] === ['following' => false]);
sleep(1);
usleep(100_000);
$asAna('follow', '');
check('refollow sends no second welcome mail', (json_decode(file_get_contents("$data/users.json"), true)[$userId]['welcomed'] ?? 0) === $welcomed);
file_put_contents("$data/tracks/$withProject/comments.json", '[]');
file_put_contents("$data/tracks/$withProject/likes.json", '[]');
file_put_contents("$data/followers.json", '[]');

echo "Admin\n";
$admin = [];
$login = request('GET', 'admin.php', [], '', $admin);
check('admin shows the login', $login['status'] === 200 && str_contains($login['body'], 'name="password"') && !str_contains($login['body'], 'Settings'));
preg_match('/name="csrf" content="([0-9a-f]+)"/', $login['body'], $adminMatch);
$adminCsrf = $adminMatch[1] ?? '';
$adminPost = static function (array $fields) use (&$admin, $adminCsrf) {
    return request('POST', 'admin.php', ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query($fields + ['csrf' => $adminCsrf]), $admin);
};
$adminPost(['do' => 'login', 'password' => 'wrong password']);
check('wrong password refused', str_contains(request('GET', 'admin.php', [], '', $admin)['body'], 'Wrong password.'));
check('action before login is ignored', $adminPost(['do' => 'hide', 'track' => $withProject])['status'] === 303 && !json_decode(file_get_contents("$data/tracks/$withProject/track.json"), true)['hidden']);
$adminPost(['do' => 'login', 'password' => 'correct horse']);
preg_match('/name="csrf" content="([0-9a-f]+)"/', request('GET', 'admin.php', [], '', $admin)['body'], $adminMatch);
$adminCsrf = $adminMatch[1] ?? '';
$adminPost = static function (array $fields) use (&$admin, $adminCsrf) {
    return request('POST', 'admin.php', ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query($fields + ['csrf' => $adminCsrf]), $admin);
};
$panel = request('GET', 'admin.php', [], '', $admin);
check('admin page after login', str_contains($panel['body'], 'id="settings"') && str_contains($panel['body'], 'Open Me'));
check('admin action without csrf 403', request('POST', 'admin.php', ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query(['do' => 'hide', 'track' => $withProject]), $admin)['status'] === 403);
$adminPost(['do' => 'hide', 'track' => $withProject]);
check('hidden track leaves the list', !str_contains(request('GET', '')['body'], 'Open Me'));
check('hidden track media moved away', !is_dir("$site/media/open-me") && request('GET', 'media/open-me/mixdown.opus')['status'] === 404);
check('hidden track 404 for visitors', request('GET', 'track.php?t=open-me')['status'] === 404);
check('hidden track visible to the admin', request('GET', 'track.php?t=open-me', [], '', $admin)['status'] === 200);
check('hidden track bundle 404', request('GET', 'api.php?action=project&t=open-me')['status'] === 404);
$adminPost(['do' => 'show', 'track' => $withProject]);
check('shown track is back', str_contains(request('GET', '')['body'], 'Open Me'));
check('shown track media back in place', is_file("$site/media/open-me/mixdown.opus") && request('GET', 'media/open-me/mixdown.opus')['status'] === 200);
$adminPost(['do' => 'settings', 'title' => 'My Stage', 'token' => '', 'password' => '', 'repeat' => '', 'mailFrom' => '', 'googleClientId' => 'test-id', 'googleClientSecret' => 'test-secret']);
check('Google keys show likes and comments', str_contains(request('GET', 'open-me')['body'], 'id="comments"'));
file_put_contents("$data/tracks/$withProject/comments.json", json_encode([
    ['id' => 'c1', 'user' => 'google:2', 'name' => 'Bob', 'picture' => '', 'text' => 'Pending one', 'time' => time(), 'status' => 'pending'],
    ['id' => 'c2', 'user' => 'google:2', 'name' => 'Bob', 'picture' => '', 'text' => 'Spam', 'time' => time(), 'status' => 'pending']]));
check('pending comment hidden from visitors', !str_contains(request('GET', 'track.php?t=open-me')['body'], 'Pending one'));
$adminPost(['do' => 'approve', 'track' => $withProject, 'id' => 'c1']);
$adminPost(['do' => 'delete-comment', 'track' => $withProject, 'id' => 'c2']);
$trackPage = request('GET', 'track.php?t=open-me')['body'];
check('approved comment visible', str_contains($trackPage, 'Pending one') && !str_contains($trackPage, 'Spam'));
file_put_contents("$data/users.json", json_encode(['google:2' => ['id' => 'google:2', 'name' => 'Bob', 'picture' => '', 'email' => '', 'created' => time(), 'blocked' => false]]));
file_put_contents("$data/tracks/$withProject/likes.json", json_encode(['google:2', 'google:3']));
$adminPost(['do' => 'block', 'id' => 'google:2']);
check('block removes comments and likes', json_decode(file_get_contents("$data/tracks/$withProject/likes.json"), true) === ['google:3']
    && json_decode(file_get_contents("$data/tracks/$withProject/comments.json"), true) === []);
check('block marks the user', json_decode(file_get_contents("$data/users.json"), true)['google:2']['blocked'] === true);
$adminPost(['do' => 'settings', 'title' => 'Renamed Stage', 'token' => 'short', 'password' => '', 'repeat' => '', 'mailFrom' => '', 'googleClientId' => '', 'googleClientSecret' => '']);
check('settings refuse a bad token', str_contains(request('GET', 'admin.php', [], '', $admin)['body'], 'token is not valid'));
$newToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$adminPost(['do' => 'settings', 'title' => 'Renamed Stage', 'token' => $newToken, 'password' => '', 'repeat' => '', 'mailFrom' => 'me@example.com', 'googleClientId' => '', 'googleClientSecret' => '']);
check('token change needs the current password', str_contains(request('GET', 'admin.php', [], '', $admin)['body'], 'Enter your current admin password'));
$adminPost(['do' => 'settings', 'title' => 'Renamed Stage', 'token' => $newToken, 'password' => '', 'repeat' => '', 'current' => 'correct horse', 'mailFrom' => 'me@example.com', 'googleClientId' => '', 'googleClientSecret' => '']);
check('settings rename the stage', str_contains(request('GET', '')['body'], 'Renamed Stage'));
check('old token stops working', request('GET', 'api.php?action=alive', $auth)['status'] === 401);
check('new token works', request('GET', 'api.php?action=alive', ['X-OpenDAW-Token' => $newToken])['status'] === 200);
$auth = ['X-OpenDAW-Token' => $newToken];
$studioRow = request('GET', 'admin.php', [], '', $admin)['body'];
check('admin counts the DEV tracks with the switch', str_contains($studioRow, '1 tracks open <code>https://dev.opendaw.studio</code>') && str_contains($studioRow, 'value="studio-prod"'));
check('track published without Origin opens PROD', json_decode(file_get_contents("$data/tracks/$second/track.json"), true)['studio'] === 'https://opendaw.studio');
$adminPost(['do' => 'studio-prod']);
check('switch moves Open in openDAW to PROD', str_contains(request('GET', 'track.php?t=open-me', [], '', $admin)['body'], 'href="https://opendaw.studio/open-stage?url='));
check('switch leaves localhost tracks alone', str_contains(request('GET', 'local-test')['body'], 'href="https://localhost:8080/open-stage?url='));
check('switch stores PROD for DEV tracks', json_decode(file_get_contents("$data/tracks/$withProject/track.json"), true)['studio'] === 'https://opendaw.studio');
check('switch button gone on PROD', !str_contains(request('GET', 'admin.php', [], '', $admin)['body'], 'value="studio-prod"'));
$adminPost(['do' => 'delete-track', 'track' => $withProject]);
check('delete removes data and media', !is_dir("$site/media/open-me") && !is_dir("$data/tracks/$withProject") && request('GET', 'track.php?t=open-me')['status'] === 404);
$adminPost(['do' => 'logout']);
check('logout ends the admin session', !str_contains(request('GET', 'admin.php', [], '', $admin)['body'], 'id="settings"'));
$intruder = [];
for ($attempt = 0; $attempt < 5; $attempt++) {
    request('POST', 'admin.php', ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query(['do' => 'login', 'password' => "wrong $attempt"]), $intruder);
}
request('POST', 'admin.php', ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query(['do' => 'login', 'password' => 'correct horse']), $intruder);
$locked = request('GET', 'admin.php', [], '', $intruder)['body'];
check('five wrong passwords lock the login', str_contains($locked, 'Too many wrong passwords') && !str_contains($locked, 'id="settings"'));

proc_terminate($server);
proc_close($server);
removeTree($site);
echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
