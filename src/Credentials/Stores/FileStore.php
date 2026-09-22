<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Credentials\Stores;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use JsonException;
use LauroGuedes\DemoMode\Contracts\CredentialStore;
use LauroGuedes\DemoMode\Credentials\Credential;
use Throwable;

/**
 * A JSON file on a private disk.
 *
 * The default, and the right one when the cache is flushed as part of the reset —
 * which it is, by default. A file survives the step that empties the cache, so the
 * order of the cleaners stops being something an application has to get right.
 *
 * The disk must not be web-reachable. 'local' is, by Laravel's own convention, not
 * served; 'public' is. 'demo:doctor' treats a public disk here as an error rather
 * than a warning, because the failure is a password served at a guessable URL.
 */
final readonly class FileStore implements CredentialStore
{
    public function __construct(
        private FilesystemFactory $filesystem,
        private string $disk,
        private string $path,
    ) {}

    public function put(array $credentials): void
    {
        try {
            $this->filesystem->disk($this->disk)->put($this->path, json_encode(
                Credential::toArrays($credentials),
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT,
            ));
        } catch (Throwable) {
            // A demo that cannot publish its credentials is still a demo worth
            // serving; demo:status and demo:doctor are where this surfaces.
        }
    }

    public function get(): array
    {
        try {
            $contents = $this->filesystem->disk($this->disk)->get($this->path);
        } catch (Throwable) {
            return [];
        }

        if (! is_string($contents)) {
            return [];
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? Credential::listFromArray($decoded) : [];
    }

    public function forget(): void
    {
        try {
            $this->filesystem->disk($this->disk)->delete($this->path);
        } catch (Throwable) {
            //
        }
    }

    public function describe(): string
    {
        return sprintf('file [%s] on disk [%s]', $this->path, $this->disk);
    }
}
