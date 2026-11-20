<?php
declare(strict_types=1);

final class Updater
{
    public const REPOSITORY = 'andremichelle/opendaw-stage';
    public const MANIFEST = 'release.json';
    private const MAX_ARCHIVE_BYTES = 50 * 1024 * 1024;
    private const MAX_FILES = 500;
    private const MAX_UNPACKED_BYTES = 200 * 1024 * 1024;
    private const MARKER = 'installing.json';

    public static function current(string $root): array
    {
        $manifest = Files::readJson($root . '/' . self::MANIFEST, null);
        return is_array($manifest) && self::isVersion((string)($manifest['version'] ?? ''))
            ? $manifest : ['version' => '0.0.0', 'autoUpdateFrom' => '0.0.0', 'files' => []];
    }

    public static function missingRequirement(string $root): string
    {
        return match (true) {
            !class_exists('PharData') => 'PHP cannot unpack archives (Phar extension missing).',
            !extension_loaded('zlib') => 'PHP cannot unpack archives (zlib extension missing).',
            !function_exists('curl_init') && !filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)
                => 'PHP cannot download files (no curl, allow_url_fopen off).',
            !is_writable($root) => 'PHP cannot write the files of the stage.',
            default => ''
        };
    }

    public static function latest(): array|string
    {
        $redirect = self::request('https://github.com/' . self::REPOSITORY . '/releases/latest', 1_000_000);
        $prefix = 'https://github.com/' . self::REPOSITORY . '/releases/tag/';
        $tag = str_starts_with($redirect['location'], $prefix) ? substr($redirect['location'], strlen($prefix)) : '';
        if (!self::isTag($tag)) {return 'GitHub did not name a latest release (' . self::describe($redirect) . ').';}
        $refs = self::request('https://github.com/' . self::REPOSITORY . '.git/info/refs?service=git-upload-pack', 5_000_000);
        $pattern = '/^[0-9a-f]{4}([0-9a-f]{40}) refs\/tags\/' . preg_quote($tag, '/') . '(\^\{\})?$/mD';
        preg_match_all($pattern, (string)$refs['body'], $matches, PREG_SET_ORDER);
        $sha = '';
        foreach ($matches as $match) {
            if ($sha === '' || ($match[2] ?? '') !== '') {$sha = $match[1];}
        }
        if (!self::isSha($sha)) {return "GitHub did not name the commit of $tag (" . self::describe($refs) . ').';}
        $file = self::request('https://raw.githubusercontent.com/' . self::REPOSITORY . '/' . $sha . '/' . self::MANIFEST, 1_000_000);
        $manifest = json_decode((string)$file['body'], true);
        if (!is_array($manifest) || ($manifest['version'] ?? '') !== substr($tag, 1)
            || !self::isVersion((string)($manifest['autoUpdateFrom'] ?? ''))) {return "The release $tag has no valid release.json (" . self::describe($file) . ').';}
        return ['tag' => $tag, 'sha' => $sha, 'version' => $manifest['version'], 'autoUpdateFrom' => $manifest['autoUpdateFrom'],
            'url' => $prefix . $tag];
    }

    public static function recover(string $root, string $updates): string
    {
        $failed = [];
        $recovered = 0;
        foreach (is_dir($updates) ? (scandir($updates) ?: []) : [] as $name) {
            $work = $updates . '/' . $name;
            $state = Files::readJson($work . '/' . self::MARKER, null);
            if ($name === '.' || $name === '..' || !is_array($state)) {continue;}
            $missed = self::rollback($root, $work . '/backup', (array)$state['existed'], (array)$state['written']);
            if (count($missed) === 0) {Files::removeTree($work);}
            $failed = array_merge($failed, $missed);
            $recovered++;
        }
        if ($recovered === 0) {return '';}
        return count($failed) === 0 ? 'An update was interrupted, the previous version was restored.'
            : 'An update was interrupted and could not restore ' . implode(', ', $failed) . '. Please install the latest release by hand.';
    }

    public static function isNewer(array $latest, string $root): bool
    {
        return version_compare($latest['version'], self::current($root)['version'], '>');
    }

    public static function isAutomatic(array $latest, string $root): bool
    {
        return version_compare(self::current($root)['version'], $latest['autoUpdateFrom'], '>=');
    }

    public static function download(string $sha, string $target): bool
    {
        if (!self::isSha($sha)) {return false;}
        $archive = self::fetch('https://codeload.github.com/' . self::REPOSITORY . '/tar.gz/' . $sha, self::MAX_ARCHIVE_BYTES);
        return !is_null($archive) && file_put_contents($target, $archive) === strlen($archive);
    }

    public static function install(string $archive, string $version, string $root, string $work): string
    {
        $current = self::current($root);
        $extract = $work . '/extract';
        $backup = $work . '/backup';
        if (!Files::ensureDir($extract) || !Files::ensureDir($backup)) {return 'Could not create the update folder.';}
        $size = filesize($archive);
        $handle = fopen($archive, 'rb');
        if ($size === false || $size < 18 || $handle === false) {return 'The archive could not be unpacked.';}
        fseek($handle, -4, SEEK_END);
        $unpacked = unpack('V', (string)fread($handle, 4));
        fclose($handle);
        if (!is_array($unpacked) || $unpacked[1] > self::MAX_UNPACKED_BYTES) {return 'The archive is too large.';}
        try {
            $phar = new PharData($archive);
            $tops = [];
            foreach ($phar as $entry) {
                if ($entry->isDir()) {$tops[] = $entry->getFilename();}
            }
            if (count($tops) !== 1 || preg_match('/^[A-Za-z0-9._-]+$/', $tops[0]) !== 1) {return 'The archive has an unexpected layout.';}
            $top = $tops[0];
            $phar->extractTo($extract, $top . '/' . self::MANIFEST, true);
            $manifest = Files::readJson($extract . '/' . $top . '/' . self::MANIFEST, null);
            $error = self::checkManifest($manifest, $current, $version);
            if ($error !== '') {return $error;}
            $phar->extractTo($extract, array_map(fn(string $file) => $top . '/' . $file, $manifest['files']), true);
        } catch (Throwable) {
            return 'The archive could not be unpacked.';
        }
        $source = $extract . '/' . $top;
        foreach ($manifest['files'] as $file) {
            if (!is_file($source . '/' . $file)) {return "The archive misses $file.";}
        }
        $touched = array_values(array_unique(array_merge($current['files'], $manifest['files'])));
        $existed = [];
        foreach ($touched as $file) {
            if (!is_file($root . '/' . $file)) {continue;}
            if (!Files::ensureDir(dirname($backup . '/' . $file)) || !copy($root . '/' . $file, $backup . '/' . $file)) {
                return 'Could not back up the current files, nothing was changed.';
            }
            $existed[] = $file;
        }
        $order = array_merge(array_values(array_diff($manifest['files'], [self::MANIFEST])), [self::MANIFEST]);
        if (!Files::writeJson($work . '/' . self::MARKER, ['existed' => $existed, 'written' => $order])) {
            return 'Could not write to the update folder, nothing was changed.';
        }
        foreach ($order as $file) {
            $target = $root . '/' . $file;
            if (!Files::ensureDir(dirname($target)) || !copy($source . '/' . $file, $target . '.update')
                || !rename($target . '.update', $target)) {
                @unlink($target . '.update');
                $failed = self::rollback($root, $backup, $existed, $order);
                if (count($failed) > 0) {return "Could not write $file, and could not restore " . implode(', ', $failed) . '. The copy of the previous files stays in the update folder.';}
                unlink($work . '/' . self::MARKER);
                return "Could not write $file, the previous version was restored.";
            }
        }
        foreach (array_diff($current['files'], $manifest['files']) as $file) {
            if (is_file($root . '/' . $file)) {unlink($root . '/' . $file);}
        }
        unlink($work . '/' . self::MARKER);
        if (function_exists('opcache_reset')) {opcache_reset();}
        return '';
    }

    public static function isShippedPath(string $file): bool
    {
        if (preg_match('#^[A-Za-z0-9_-][A-Za-z0-9._-]*(/[A-Za-z0-9_-][A-Za-z0-9._-]*)*$#D', $file) !== 1
            && preg_match('#^([A-Za-z0-9_-]+/)*\.htaccess$#D', $file) !== 1) {return false;}
        $lower = strtolower($file);
        $first = explode('/', $lower)[0];
        return $lower !== 'config.php' && !str_starts_with($first, 'data-')
            && !in_array($first, ['media', 'custom'], true) && !str_contains($file, '..');
    }

    private static function checkManifest(mixed $manifest, array $current, string $version): string
    {
        if (!is_array($manifest) || !self::isVersion((string)($manifest['version'] ?? ''))
            || !self::isVersion((string)($manifest['autoUpdateFrom'] ?? '')) || !is_array($manifest['files'] ?? null)
            || count($manifest['files']) === 0 || count($manifest['files']) > self::MAX_FILES
            || !in_array(self::MANIFEST, $manifest['files'], true)) {
            return 'The release has no valid release.json.';
        }
        if ($manifest['version'] !== $version) {return 'The release does not match the version that was checked.';}
        foreach ($manifest['files'] as $file) {
            if (!is_string($file) || !self::isShippedPath($file)) {return 'The release lists a file it may not replace.';}
        }
        if (!version_compare($manifest['version'], $current['version'], '>')) {return 'This version is already installed.';}
        if (version_compare($current['version'], $manifest['autoUpdateFrom'], '<')) {return 'This release must be installed by hand.';}
        return '';
    }

    private static function rollback(string $root, string $backup, array $existed, array $written): array
    {
        $failed = [];
        foreach ($written as $file) {
            if (!in_array($file, $existed, true) && is_file($root . '/' . $file) && !unlink($root . '/' . $file)) {$failed[] = $file;}
        }
        foreach ($existed as $file) {
            if (!is_file($backup . '/' . $file) || !copy($backup . '/' . $file, $root . '/' . $file)) {$failed[] = $file;}
        }
        return $failed;
    }

    private static function isVersion(string $version): bool
    {
        return preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}$/D', $version) === 1;
    }

    private static function isSha(string $sha): bool
    {
        return preg_match('/^[0-9a-f]{40}$/D', $sha) === 1;
    }

    private static function isTag(string $tag): bool
    {
        return str_starts_with($tag, 'v') && self::isVersion(substr($tag, 1));
    }

    private static function fetch(string $url, int $maxBytes): ?string
    {
        $response = self::request($url, $maxBytes);
        return $response['status'] === 200 ? $response['body'] : null;
    }

    private static function describe(array $response): string
    {
        return $response['status'] === 0 ? 'no connection' : 'answer ' . $response['status'];
    }

    private static function request(string $url, int $maxBytes): array
    {
        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_USERAGENT => 'opendaw-stage', CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_MAXFILESIZE => $maxBytes, CURLOPT_NOPROGRESS => false,
                CURLOPT_PROGRESSFUNCTION => fn($resource, int $total, int $received) => $received > $maxBytes ? 1 : 0]);
            $body = curl_exec($curl);
            $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $location = (string)curl_getinfo($curl, CURLINFO_REDIRECT_URL);
            curl_close($curl);
            return ['status' => is_string($body) && strlen($body) <= $maxBytes ? $status : 0,
                'body' => is_string($body) ? $body : null, 'location' => $location];
        }
        $context = stream_context_create(['http' => ['timeout' => 60, 'follow_location' => 0, 'ignore_errors' => true,
            'header' => "User-Agent: opendaw-stage"]]);
        $body = @file_get_contents($url, false, $context, 0, $maxBytes + 1);
        $headers = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
        preg_match('#^HTTP/\S+ (\d{3})#', $headers[0] ?? '', $status);
        $location = '';
        foreach ($headers as $header) {
            if (stripos($header, 'Location:') === 0) {$location = trim(substr($header, 9));}
        }
        return ['status' => is_string($body) && strlen($body) <= $maxBytes ? (int)($status[1] ?? 0) : 0,
            'body' => is_string($body) ? $body : null, 'location' => $location];
    }
}
