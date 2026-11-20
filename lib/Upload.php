<?php
declare(strict_types=1);

final class Upload
{
    private readonly string $root;

    public function __construct(string $root)
    {
        $this->root = $root;
        foreach (['tmp', 'sessions', 'staging'] as $folder) {
            if (!Files::ensureDir($root . '/' . $folder)) {Http::fail(500, 'storage');}
        }
    }

    public function staging(string $uuid): string
    {
        return $this->root . '/staging/' . $uuid;
    }

    public function begin(string $uuid): void
    {
        if (!Files::removeTree($this->staging($uuid))) {Http::fail(500, 'storage');}
    }

    public function upload(string $uuid, string $file): void
    {
        $folder = $this->staging($uuid);
        if (!Files::ensureDir($folder)) {Http::fail(507, 'storage');}
        $temp = $this->temp();
        $this->receive($temp);
        $this->place($temp, $folder . '/' . $file);
    }

    public function chunk(string $session, int $index): void
    {
        $folder = $this->session($session);
        if (!Files::ensureDir($folder)) {Http::fail(507, 'storage');}
        $temp = $this->temp();
        $this->receive($temp);
        $this->place($temp, $folder . '/' . sprintf('%08d', $index));
        touch($folder);
    }

    public function commit(string $uuid, string $file, string $session, int $count, int $size): void
    {
        if ($size > Stage::MAX_BYTES) {Http::fail(413, 'too-large');}
        $folder = $this->staging($uuid);
        $target = $folder . '/' . $file;
        $chunks = $this->session($session);
        if (!is_dir($chunks)) {
            if (is_file($target) && filesize($target) === $size) {return;}
            Http::fail(409, 'missing-chunk');
        }
        if (!Files::ensureDir($folder)) {Http::fail(507, 'storage');}
        $temp = $this->temp();
        $output = fopen($temp, 'wb');
        if ($output === false) {Http::fail(507, 'storage');}
        $total = 0;
        for ($index = 0; $index < $count; $index++) {
            $chunk = $chunks . '/' . sprintf('%08d', $index);
            $input = is_file($chunk) ? fopen($chunk, 'rb') : false;
            if ($input === false) {$this->abort($output, $temp, 409, 'missing-chunk');}
            $copied = stream_copy_to_stream($input, $output);
            fclose($input);
            if ($copied === false) {$this->abort($output, $temp, 507, 'storage');}
            $total += $copied;
            if ($total > Stage::MAX_BYTES) {$this->abort($output, $temp, 413, 'too-large');}
        }
        if (!fclose($output)) {
            unlink($temp);
            Http::fail(507, 'storage');
        }
        if ($total !== $size) {
            unlink($temp);
            Http::fail(409, 'size');
        }
        $this->place($temp, $target);
        Files::removeTree($chunks);
    }

    public function cleanup(): void
    {
        foreach (['tmp', 'sessions', 'staging'] as $folder) {
            Files::removeStale($this->root . '/' . $folder, Stage::STALE_SECONDS);
        }
    }

    private function session(string $session): string
    {
        return $this->root . '/sessions/' . $session;
    }

    private function temp(): string
    {
        return $this->root . '/tmp/' . bin2hex(random_bytes(16));
    }

    private function receive(string $target): void
    {
        $expected = (int)($_SERVER['CONTENT_LENGTH'] ?? -1);
        if ($expected > Stage::MAX_BYTES) {Http::fail(413, 'too-large');}
        $input = fopen('php://input', 'rb');
        $output = fopen($target, 'wb');
        if ($input === false || $output === false) {Http::fail(507, 'storage');}
        $written = stream_copy_to_stream($input, $output, Stage::MAX_BYTES + 1);
        fclose($input);
        $closed = fclose($output);
        if ($written === false || !$closed) {
            unlink($target);
            Http::fail(507, 'storage');
        }
        if ($written > Stage::MAX_BYTES) {
            unlink($target);
            Http::fail(413, 'too-large');
        }
        if ($expected >= 0 && $written !== $expected) {
            unlink($target);
            Http::fail(400, 'incomplete');
        }
    }

    private function place(string $temp, string $target): void
    {
        if (!rename($temp, $target)) {
            unlink($temp);
            Http::fail(507, 'storage');
        }
    }

    private function abort(mixed $output, string $temp, int $status, string $error): never
    {
        fclose($output);
        unlink($temp);
        Http::fail($status, $error);
    }
}
