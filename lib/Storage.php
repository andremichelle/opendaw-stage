<?php
declare(strict_types=1);

interface Storage
{
    public function transaction(callable $work): mixed;

    public function tracks(): array;

    public function track(string $uuid): ?array;

    public function trackBySlug(string $slug): ?array;

    public function saveTrack(array $track): void;

    public function deleteTrack(string $uuid): void;

    public function comments(string $uuid): array;

    public function addComment(string $uuid, array $comment): void;

    public function updateComments(string $uuid, callable $change): mixed;

    public function likes(string $uuid): array;

    public function toggleLike(string $uuid, string $user): bool;

    public function removeLikes(string $uuid, string $user): void;

    public function users(): array;

    public function user(string $id): ?array;

    public function updateUsers(callable $change): mixed;

    public function followers(): array;

    public function updateFollowers(callable $change): mixed;
}
