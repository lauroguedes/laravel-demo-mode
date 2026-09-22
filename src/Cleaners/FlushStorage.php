<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Cleaners;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use LauroGuedes\DemoMode\Contracts\Cleaner;
use Throwable;

/**
 * Delete what visitors uploaded.
 *
 * Avatars, attachments, imports and Livewire's temporary directory outlive the
 * rows that referenced them. Nothing points at them after a reset, nothing
 * cleans them up, and a public demo accumulates them until somebody notices the
 * disk. Worse, a file uploaded by one visitor is still served by URL to the next,
 * which on a demo where anyone can upload anything is a content problem as much
 * as a storage one.
 *
 * Configured as directories per disk and empty by default, because deleting a
 * disk's whole root would take the seeded fixtures with it. Naming the
 * directories is the deliberate act; there is no wildcard.
 */
final readonly class FlushStorage implements Cleaner
{
    public function __construct(private FilesystemFactory $filesystem) {}

    public function clean(array $options): void
    {
        $disks = $options['disks'] ?? [];

        if (! is_array($disks)) {
            return;
        }

        foreach ($disks as $disk => $directories) {
            if (! is_string($disk) || ! is_array($directories)) {
                continue;
            }

            foreach ($directories as $directory) {
                if (is_string($directory)) {
                    $this->delete($disk, $directory);
                }
            }
        }
    }

    public function describe(): string
    {
        return 'Delete what visitors uploaded';
    }

    /**
     * The directory is recreated empty, because an application that writes into
     * it without checking would otherwise fail on the first upload after a reset.
     */
    private function delete(string $disk, string $directory): void
    {
        try {
            $storage = $this->filesystem->disk($disk);

            $storage->deleteDirectory($directory);
            $storage->makeDirectory($directory);
        } catch (Throwable) {
            // A disk that is not configured is a misconfiguration for
            // demo:doctor to report, not a reason to leave the demo down.
        }
    }
}
