<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Doctor\Checks;

use Illuminate\Contracts\Config\Repository;
use LauroGuedes\DemoMode\Configuration;
use LauroGuedes\DemoMode\Contracts\DoctorCheck;
use LauroGuedes\DemoMode\Doctor\Finding;

/**
 * The published passwords must not be reachable over HTTP.
 *
 * The single check in this command with the worst failure mode. A credentials
 * file written to the 'public' disk is a working administrator password served
 * at a guessable URL — and it stays served after DEMO_MODE goes off, because the
 * flag gates the package's reads and has no say over what a web server hands out.
 *
 * Treated as an error rather than a warning for that reason: a warning is
 * something you can decide to live with, and this is not.
 */
final readonly class CredentialsAreNotPublic implements DoctorCheck
{
    public function __construct(
        private Configuration $config,
        private Repository $appConfig,
    ) {}

    public function run(): array
    {
        if ($this->config->string('credentials.store', 'file') !== 'file') {
            return [];
        }

        $disk = $this->config->string('credentials.stores.file.disk', 'local');
        $definition = $this->appConfig->get('filesystems.disks.'.$disk);

        if (! is_array($definition)) {
            return [Finding::error(
                'credentials-disk',
                sprintf('The credential store is set to disk [%s], which is not configured.', $disk),
                'Point demo.credentials.stores.file.disk at a private disk, usually "local".',
            )];
        }

        $findings = [];

        if (isset($definition['url'])) {
            $findings[] = Finding::error(
                'credentials-disk',
                sprintf(
                    'Disk [%s] has a public URL, so the published passwords would be served over HTTP at a guessable address.',
                    $disk,
                ),
                'Use a disk with no "url", such as "local".',
            );
        }

        if (($definition['visibility'] ?? null) === 'public') {
            $findings[] = Finding::error(
                'credentials-disk',
                sprintf('Disk [%s] defaults to public visibility, so the credentials file would be world-readable.', $disk),
                'Use a private disk for demo.credentials.stores.file.disk.',
            );
        }

        $root = $definition['root'] ?? null;
        $publicPath = $this->appConfig->get('app.public_path');

        if (is_string($root) && is_string($publicPath) && str_starts_with($root, $publicPath)) {
            $findings[] = Finding::error(
                'credentials-disk',
                sprintf('Disk [%s] is rooted inside the public directory, so the credentials file is web-reachable.', $disk),
                'Move the credential store to a disk outside public/.',
            );
        }

        return $findings;
    }
}
