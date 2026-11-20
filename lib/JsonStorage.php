<?php
declare(strict_types=1);

final class JsonStorage implements Storage
{
    private readonly string $root;

    public function __construct(string $root)
    {
        $this->root = $root;
    }

    public function transaction(callable $work): mixed
    {
        return Files::withLock($this->root . '/.lock', $work);
    }

    public function tracks(): array
    {
        $tracks = array_values(Files::readJson($this->index(), []));
        usort($tracks, fn(array $a, array $b) => $b['created'] <=> $a['created']);
        return $tracks;
    }

    public function track(string $uuid): ?array
    {
        $track = Files::readJson($this->folder($uuid) . '/track.json', null);
        return is_array($track) ? $track : null;
    }

    public function trackBySlug(string $slug): ?array
    {
        foreach (Files::readJson($this->index(), []) as $uuid => $summary) {
            if ($summary['slug'] === $slug) {return $this->track((string)$uuid);}
        }
        return null;
    }

    public function saveTrack(array $track): void
    {
        $folder = $this->folder($track['uuid']);
        if (!Files::ensureDir($folder) || !Files::writeJson($folder . '/track.json', $track)) {Http::fail(507, 'storage');}
        $index = Files::readJson($this->index(), []);
        unset($track['description']);
        $index[$track['uuid']] = $track;
        if (!Files::writeJson($this->index(), $index)) {Http::fail(507, 'storage');}
    }

    public function deleteTrack(string $uuid): void
    {
        $index = Files::readJson($this->index(), []);
        unset($index[$uuid]);
        if (!Files::writeJson($this->index(), $index)) {Http::fail(507, 'storage');}
        Files::removeTree($this->folder($uuid));
    }

    public function comments(string $uuid): array
    {
        return Files::readJson($this->folder($uuid) . '/comments.json', []);
    }

    public function addComment(string $uuid, array $comment): void
    {
        $this->updateComments($uuid, function (array $comments) use ($comment) {
            $comments[] = $comment;
            return $comments;
        });
    }

    public function updateComments(string $uuid, callable $change): mixed
    {
        return $this->updateFile($uuid, 'comments.json', $change);
    }

    public function likes(string $uuid): array
    {
        return Files::readJson($this->folder($uuid) . '/likes.json', []);
    }

    public function toggleLike(string $uuid, string $user): bool
    {
        $liked = false;
        $this->updateFile($uuid, 'likes.json', function (array $likes) use ($user, &$liked) {
            $index = array_search($user, $likes, true);
            $liked = $index === false;
            if ($liked) {$likes[] = $user;} else {array_splice($likes, $index, 1);}
            return $likes;
        });
        return $liked;
    }

    public function removeLikes(string $uuid, string $user): void
    {
        $this->updateFile($uuid, 'likes.json', fn(array $likes) => array_values(array_filter($likes, fn(string $id) => $id !== $user)));
    }

    public function users(): array
    {
        return Files::readJson($this->root . '/users.json', []);
    }

    public function user(string $id): ?array
    {
        $user = $this->users()[$id] ?? null;
        return is_array($user) ? $user : null;
    }

    public function updateUsers(callable $change): mixed
    {
        return $this->updateGlobal('users.json', $change);
    }

    public function followers(): array
    {
        return Files::readJson($this->root . '/followers.json', []);
    }

    public function updateFollowers(callable $change): mixed
    {
        return $this->updateGlobal('followers.json', $change);
    }

    private function updateFile(string $uuid, string $file, callable $change): mixed
    {
        $folder = $this->folder($uuid);
        if (!Files::ensureDir($folder)) {Http::fail(507, 'storage');}
        return Files::withLock($folder . '/.lock', function () use ($folder, $file, $change) {
            $value = $change(Files::readJson($folder . '/' . $file, []));
            if (!Files::writeJson($folder . '/' . $file, $value)) {Http::fail(507, 'storage');}
            return $value;
        });
    }

    private function updateGlobal(string $file, callable $change): mixed
    {
        return Files::withLock($this->root . '/.' . $file . '.lock', function () use ($file, $change) {
            $value = $change(Files::readJson($this->root . '/' . $file, []));
            if (!Files::writeJson($this->root . '/' . $file, $value)) {Http::fail(507, 'storage');}
            return $value;
        });
    }

    private function index(): string
    {
        return $this->root . '/index.json';
    }

    private function folder(string $uuid): string
    {
        return $this->root . '/tracks/' . $uuid;
    }
}
