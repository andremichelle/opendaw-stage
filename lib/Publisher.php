<?php
declare(strict_types=1);

final class Publisher
{
    private readonly Storage $storage;
    private readonly Upload $upload;
    private readonly string $mediaRoot;

    public function __construct(Storage $storage, Upload $upload, string $mediaRoot)
    {
        $this->storage = $storage;
        $this->upload = $upload;
        $this->mediaRoot = $mediaRoot;
    }

    public function publish(string $uuid, string $studio): array
    {
        $staging = $this->upload->staging($uuid);
        foreach (Stage::REQUIRED as $file) {
            if (!is_file($staging . '/' . $file)) {Http::json(422, ['error' => 'missing', 'file' => $file]);}
        }
        $meta = Media::meta($staging . '/meta.json') ?? Http::fail(422, 'meta');
        $coverExtension = Media::coverExtension($staging . '/cover') ?? Http::fail(422, 'cover');
        if (!Media::isOpus($staging . '/mixdown.opus')) {Http::fail(422, 'mixdown');}
        if (!Media::isPeaks($staging . '/peaks.bin')) {Http::fail(422, 'peaks');}
        $hasProject = is_file($staging . '/project.odb');
        if ($hasProject && !Media::isZip($staging . '/project.odb')) {Http::fail(422, 'project');}
        $track = $this->storage->transaction(function () use ($uuid, $staging, $meta, $coverExtension, $hasProject, $studio) {
            $existing = $this->storage->track($uuid);
            $now = time();
            $slug = $existing['slug'] ?? $this->uniqueSlug($meta['title']);
            $folder = $this->mediaRoot . '/' . (is_null($existing) ? $slug : Media::folder($existing));
            if (!Files::ensureDir($folder)) {Http::fail(507, 'storage');}
            foreach (glob($folder . '/cover.*') ?: [] as $old) {unlink($old);}
            if (!$hasProject && is_file($folder . '/project.odb')) {unlink($folder . '/project.odb');}
            $moves = ['cover' => 'cover.' . $coverExtension, 'mixdown.opus' => 'mixdown.opus', 'peaks.bin' => 'peaks.bin'];
            if ($hasProject) {$moves['project.odb'] = 'project.odb';}
            foreach ($moves as $from => $to) {
                if (!rename($staging . '/' . $from, $folder . '/' . $to)) {Http::fail(507, 'storage');}
            }
            $track = ['uuid' => $uuid, 'slug' => $slug, ...$meta, 'cover' => 'cover.' . $coverExtension,
                'project' => $hasProject, 'studio' => $studio, 'hidden' => $existing['hidden'] ?? false,
                'folder' => is_null($existing) ? $slug : Media::folder($existing),
                'created' => $existing['created'] ?? $now, 'updated' => $now];
            $this->storage->saveTrack($track);
            if (!is_null($existing)) {
                $this->storage->addComment($uuid, ['id' => bin2hex(random_bytes(8)), 'user' => null, 'text' => 'Updated',
                    'time' => $now, 'status' => 'approved', 'system' => true]);
            }
            return $track + ['replaced' => !is_null($existing)];
        });
        Files::removeTree($staging);
        return $track;
    }

    private function uniqueSlug(string $title): string
    {
        $base = Media::slug($title);
        if (in_array($base, Stage::RESERVED_SLUGS, true) || str_starts_with($base, 'data-')) {$base = 'track-' . $base;}
        $taken = array_map(fn(array $track) => $track['slug'], $this->storage->tracks());
        $slug = $base;
        $isTaken = fn(string $slug) => in_array($slug, $taken, true)
            || is_dir($this->mediaRoot . '/' . $slug) || file_exists(Stage::root() . '/' . $slug);
        for ($index = 2; $isTaken($slug); $index++) {
            $slug = $base . '-' . $index;
        }
        return $slug;
    }
}
