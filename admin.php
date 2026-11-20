<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

if (!Config::exists()) {Http::redirect('setup.php');}
$config = Config::load();
$storage = new JsonStorage(Config::dataRoot($config));
if (!Http::isSecure()) {Http::redirect('https://' . (string)($_SERVER['HTTP_HOST'] ?? '') . (string)($_SERVER['REQUEST_URI'] ?? '/admin.php'));}
Session::resume($config);
header('X-Frame-Options: DENY');

$trackOf = static function (string $uuid) use ($storage): array {
    $track = Stage::isUuid($uuid) ? $storage->track($uuid) : null;
    if (is_null($track)) {
        Session::flash('This track does not exist any more.');
        Http::redirect('admin.php');
    }
    return $track;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $do = Http::post('do');
    if ($do === 'login') {
        Session::start($config);
        if (Lockout::isLocked($config)) {
            Session::flash('Too many wrong passwords. Please try again in 15 minutes.');
        } elseif (password_verify((string)($_POST['password'] ?? ''), (string)$config['passwordHash'])) {
            Lockout::clear($config);
            Session::setAdmin(true);
        } else {
            Lockout::fail($config);
            Session::flash('Wrong password.');
        }
        Http::redirect('admin.php');
    }
    if (!Session::isAdmin()) {Http::redirect('admin.php');}
    Session::checkCsrf();
    $uuid = Http::post('track');
    $id = Http::post('id');
    switch ($do) {
        case 'logout':
            Session::setAdmin(false);
            Http::redirect('./');
        case 'hide':
        case 'show':
            $storage->transaction(function () use ($storage, $trackOf, $uuid, $do) {
                $track = $trackOf($uuid);
                $folder = $do === 'hide' ? Media::hiddenFolder() : $track['slug'];
                if (Media::folder($track) !== $folder
                    && !rename(Config::mediaRoot() . '/' . Media::folder($track), Config::mediaRoot() . '/' . $folder)) {
                    Session::flash('Could not move the files of the track.');
                    Http::redirect('admin.php#tracks');
                }
                $track['hidden'] = $do === 'hide';
                $track['folder'] = $folder;
                $storage->saveTrack($track);
            });
            Session::flash($do === 'hide' ? 'The track is hidden.' : 'The track is visible again.');
            Http::redirect('admin.php#tracks');
        case 'delete-track':
            $storage->transaction(function () use ($storage, $trackOf, $uuid) {
                $track = $trackOf($uuid);
                Files::removeTree(Config::mediaRoot() . '/' . Media::folder($track));
                $storage->deleteTrack($uuid);
            });
            Session::flash('The track is deleted.');
            Http::redirect('admin.php#tracks');
        case 'approve':
        case 'delete-comment':
            $trackOf($uuid);
            $storage->updateComments($uuid, fn(array $comments) => $do === 'approve'
                ? array_map(fn(array $comment) => $comment['id'] === $id ? array_merge($comment, ['status' => 'approved']) : $comment, $comments)
                : array_values(array_filter($comments, fn(array $comment) => $comment['id'] !== $id)));
            Session::flash($do === 'approve' ? 'The comment is approved.' : 'The comment is deleted.');
            Http::redirect('admin.php#comments');
        case 'block':
        case 'unblock':
            $storage->updateUsers(function (array $users) use ($id, $do) {
                if (isset($users[$id])) {$users[$id]['blocked'] = $do === 'block';}
                return $users;
            });
            if ($do === 'block') {
                foreach ($storage->tracks() as $track) {
                    $storage->removeLikes($track['uuid'], $id);
                    $storage->updateComments($track['uuid'], fn(array $comments) => array_values(array_filter($comments,
                        fn(array $comment) => $comment['user'] !== $id)));
                }
                $storage->updateFollowers(function (array $followers) use ($id) {
                    unset($followers[$id]);
                    return $followers;
                });
            }
            Session::flash($do === 'block' ? 'The user is blocked, their likes and comments are removed.' : 'The user is unblocked.');
            Http::redirect('admin.php#users');
        case 'remove-follower':
            $storage->updateFollowers(function (array $followers) use ($id) {
                unset($followers[$id]);
                return $followers;
            });
            Session::flash('The follower is removed.');
            Http::redirect('admin.php#followers');
        case 'studio-prod':
            $moved = $storage->transaction(function () use ($storage) {
                $moved = 0;
                foreach ($storage->tracks() as $summary) {
                    $track = $storage->track($summary['uuid']);
                    if (is_null($track) || Page::studio($track) !== Stage::STUDIO_DEV_URL) {continue;}
                    $track['studio'] = Stage::STUDIO_PROD_URL;
                    $storage->saveTrack($track);
                    $moved++;
                }
                return $moved;
            });
            Session::flash("$moved tracks now open " . Stage::STUDIO_PROD_URL . '.');
            Http::redirect('admin.php#tracks');
        case 'check-update':
            $latest = Updater::latest();
            if (is_string($latest)) {
                Session::flash("Could not check for updates: $latest");
            } else {
                Files::writeJson(Config::dataRoot($config) . '/update.json', $latest + ['checked' => time()]);
                Session::flash(Updater::isNewer($latest, Stage::root()) ? "Version {$latest['version']} is available." : 'Your stage is up to date.');
            }
            Http::redirect('admin.php#updates');
        case 'update':
            $latest = Files::readJson(Config::dataRoot($config) . '/update.json', null);
            $error = match (true) {
                !is_array($latest) || !isset($latest['sha']) || !Updater::isNewer($latest, Stage::root()) => 'There is no newer version. Check for updates first.',
                !Updater::isAutomatic($latest, Stage::root()) => 'This release must be installed by hand.',
                Updater::missingRequirement(Stage::root()) !== '' => Updater::missingRequirement(Stage::root()),
                default => ''
            };
            if ($error === '') {
                $error = Files::withLock(Config::dataRoot($config) . '/.update.lock', function () use ($config, $latest) {
                    set_time_limit(300);
                    ignore_user_abort(true);
                    $work = Config::dataRoot($config) . '/updates/' . bin2hex(random_bytes(8));
                    if (!Files::ensureDir($work)) {return 'Could not create the update folder.';}
                    $archive = $work . '/release.tar.gz';
                    $error = Updater::download($latest['sha'], $archive)
                        ? Updater::install($archive, $latest['version'], Stage::root(), $work) : 'Could not download the release from GitHub.';
                    if (!is_file($work . '/installing.json')) {Files::removeTree($work);}
                    return $error;
                });
            }
            if ($error === '') {unlink(Config::dataRoot($config) . '/update.json');}
            Session::flash($error === '' ? "Your stage is updated to version {$latest['version']}." : $error);
            Http::redirect('admin.php#updates');
        case 'settings':
            $section = Http::post('section');
            $token = Http::post('token');
            $password = (string)($_POST['password'] ?? '');
            $ownerAddress = str_replace("\r\n", "\n", Http::post('ownerAddress'));
            $error = match ($section) {
                'stage' => Http::post('title') === '' || strlen(Http::post('title')) > 200 ? 'Please enter a title for your stage.' : '',
                'access' => match (true) {
                    $token === '' && $password === '' => 'Enter a new token or a new password.',
                    $token !== '' && preg_match('/^[A-Za-z0-9_-]{32,128}$/D', $token) !== 1 => 'The token is not valid. Copy it again from openDAW.',
                    $password !== '' && strlen($password) < 10 => 'The admin password needs at least 10 characters.',
                    $password !== (string)($_POST['repeat'] ?? '') => 'The passwords do not match.',
                    !password_verify((string)($_POST['current'] ?? ''), (string)$config['passwordHash'])
                        => 'Enter your current admin password to change the token or the password.',
                    default => ''
                },
                'owner' => match (true) {
                    Http::post('ownerEmail') !== '' && filter_var(Http::post('ownerEmail'), FILTER_VALIDATE_EMAIL) === false
                        => 'The contact address is not a valid address.',
                    strlen(Http::post('ownerName')) > 200 || strlen($ownerAddress) > 1000 => 'The owner details are too long.',
                    default => ''
                },
                'mail' => Http::post('mailFrom') !== '' && filter_var(Http::post('mailFrom'), FILTER_VALIDATE_EMAIL) === false
                    ? 'The mail sender is not a valid address.' : '',
                'google' => '',
                default => 'Unknown settings.'
            };
            if ($error !== '') {
                Session::flash($error);
                Http::redirect('admin.php#settings-' . $section);
            }
            switch ($section) {
                case 'stage':
                    $config['title'] = Http::post('title');
                    break;
                case 'access':
                    if ($token !== '') {$config['tokenHash'] = hash('sha256', $token);}
                    if ($password !== '') {$config['passwordHash'] = password_hash($password, PASSWORD_DEFAULT);}
                    break;
                case 'owner':
                    $config['ownerName'] = Http::post('ownerName');
                    $config['ownerAddress'] = $ownerAddress;
                    $config['ownerEmail'] = Http::post('ownerEmail');
                    break;
                case 'mail':
                    $config['mailFrom'] = Http::post('mailFrom');
                    break;
                case 'google':
                    $config['googleClientId'] = Http::post('googleClientId');
                    if (Http::post('googleClientId') === '') {
                        $config['googleClientSecret'] = '';
                    } elseif (Http::post('googleClientSecret') !== '') {
                        $config['googleClientSecret'] = Http::post('googleClientSecret');
                    }
                    break;
            }
            Session::flash(Config::save($config) ? 'Saved.' : 'Could not write config.php.');
            Http::redirect('admin.php#settings-' . $section);
        default:
            Http::redirect('admin.php');
    }
}

$data = Page::common($config, $storage, '');
if (!Session::isAdmin()) {Http::html(Template::render('admin-login', $data));}
$recovered = Files::withLock(Config::dataRoot($config) . '/.update.lock',
    fn() => Updater::recover(Stage::root(), Config::dataRoot($config) . '/updates'));
if ($recovered !== '') {$data['flash'] = $recovered;}
$tracks = $storage->tracks();
$pending = [];
$approved = [];
foreach ($tracks as $track) {
    foreach ($storage->comments($track['uuid']) as $comment) {
        if (($comment['system'] ?? false) === true) {continue;}
        $item = ['trackUuid' => $track['uuid'], 'trackTitle' => $track['title'], 'trackUrl' => $track['slug'],
            'commentId' => $comment['id'], 'author' => (string)($comment['name'] ?? ''), 'authorId' => (string)$comment['user'],
            'textHtml' => nl2br(htmlspecialchars($comment['text'], ENT_QUOTES, 'UTF-8')),
            'time' => date('j M Y, H:i', (int)$comment['time']), 'sort' => (int)$comment['time']];
        if ($comment['status'] === 'pending') {$pending[] = $item;} else {$approved[] = $item;}
    }
}
$byTime = fn(array $a, array $b) => $b['sort'] <=> $a['sort'];
usort($pending, $byTime);
usort($approved, $byTime);
$followers = $storage->followers();
$data['tracks'] = array_map(fn(array $track) => Page::track($track, $storage), $tracks);
$data['hasTracks'] = count($tracks) > 0;
$data['pending'] = $pending;
$data['hasPending'] = count($pending) > 0;
$data['pendingCount'] = count($pending);
$data['approved'] = array_slice($approved, 0, 100);
$data['hasApproved'] = count($approved) > 0;
$data['users'] = array_values(array_map(fn(array $user) => ['userId' => $user['id'], 'userLabel' => $user['name'],
    'userSince' => date('j M Y', (int)$user['created']), 'blocked' => ($user['blocked'] ?? false) === true,
    'isFollower' => isset($followers[$user['id']])], $storage->users()));
$data['hasUsers'] = count($data['users']) > 0;
$data['followers'] = array_values(array_map(fn(array $follower) => ['followerId' => $follower['id'], 'followerName' => $follower['name'],
    'followerEmail' => $follower['email'], 'followerSince' => date('j M Y', (int)$follower['since'])], $followers));
$data['hasFollowers'] = count($followers) > 0;
$data['followerCount'] = count($followers);
$data['settingTitle'] = (string)($config['title'] ?? '');
$data['settingMailFrom'] = (string)($config['mailFrom'] ?? '');
$data['settingOwnerName'] = (string)($config['ownerName'] ?? '');
$data['settingOwnerAddress'] = (string)($config['ownerAddress'] ?? '');
$data['settingOwnerEmail'] = (string)($config['ownerEmail'] ?? '');
$data['ownerMissing'] = $data['settingOwnerName'] === '' || $data['settingOwnerEmail'] === '';
$data['privacyUrl'] = Stage::baseUrl() . 'privacy';
$data['imprintUrl'] = Stage::baseUrl() . 'imprint';
$data['authorizedDomain'] = (string)preg_replace(['/:\d+$/', '/^www\./'], '', (string)($_SERVER['HTTP_HOST'] ?? ''));
$data['mailSender'] = Mailer::sender($config);
$data['settingGoogleClientId'] = (string)($config['googleClientId'] ?? '');
$data['googleSecretSet'] = ($config['googleClientSecret'] ?? '') !== '';
$data['redirectUrl'] = Google::redirectUrl();
$latest = Files::readJson(Config::dataRoot($config) . '/update.json', null);
$data['version'] = Updater::current(Stage::root())['version'];
$data['updateKnown'] = is_array($latest) && Updater::isNewer($latest, Stage::root());
$data['updateVersion'] = $data['updateKnown'] ? $latest['version'] : '';
$data['updateUrl'] = $data['updateKnown'] ? $latest['url'] : '';
$data['updateRequirement'] = Updater::missingRequirement(Stage::root());
$data['updateAutomatic'] = $data['updateKnown'] && Updater::isAutomatic($latest, Stage::root()) && $data['updateRequirement'] === '';
$data['updateManual'] = $data['updateKnown'] && !$data['updateAutomatic'];
$data['manualUpdate'] = Stage::MANUAL_URL . '#4-updating';
$data['manualMail'] = Stage::MANUAL_URL . '#2-mails-to-followers';
$data['manualGoogle'] = Stage::MANUAL_URL . '#1-google-login';
$data['devCount'] = count(array_filter($tracks, fn(array $track) => Page::studio($track) === Stage::STUDIO_DEV_URL));
$data['devUrl'] = Stage::STUDIO_DEV_URL;
Http::html(Template::render('admin', $data));
