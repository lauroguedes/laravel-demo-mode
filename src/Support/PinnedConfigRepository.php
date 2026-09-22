<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Support;

use Illuminate\Config\Repository;
use Psr\Log\LoggerInterface;

/**
 * A config repository that refuses to have certain keys changed.
 *
 * Setting a value at boot is not enough for an application with a settings screen.
 * The concrete case this was written for: a project whose administration UI writes
 * to config at request time, where a visitor could re-enable mandatory email
 * verification — and then nobody else could complete a sign-up, because the
 * verification email goes to the array transport and is never delivered. One
 * visitor, using the product exactly as designed, closing the demo to everyone
 * after them.
 *
 * Pinning the key is the difference between a default and a decision. The
 * application still renders its settings screen and the write still appears to
 * succeed, which is the right trade for a demo: the alternative is an error page
 * on a feature a visitor was invited to explore.
 *
 * Refusals are logged once per key per request, so a developer looking for why
 * their setting will not stick finds the reason rather than a mystery.
 */
final class PinnedConfigRepository extends Repository
{
    /** @var array<string, true> */
    private array $reported = [];

    /** @var array<string, mixed> */
    private array $pinned = [];

    private ?LoggerInterface $logger = null;

    /**
     * Takes over from whatever repository the application already booted with,
     * carrying its items across, so nothing that was configured is lost.
     *
     * @param  array<string, mixed>  $pinned
     */
    public static function replacing(Repository $original, array $pinned, ?LoggerInterface $logger = null): self
    {
        $replacement = new self($original->all());
        $replacement->logger = $logger;

        foreach ($pinned as $key => $value) {
            $replacement->set($key, $value);
        }

        $replacement->pinned = $pinned;

        return $replacement;
    }

    /**
     * @param  array<string, mixed>|string  $key
     */
    public function set($key, mixed $value = null): void
    {
        if (is_string($key) && array_key_exists($key, $this->pinned)) {
            $this->reportRefusal($key);

            return;
        }

        if (is_array($key)) {
            /* Report first, then remove — the other order reports nothing. */
            foreach (array_keys(array_intersect_key($key, $this->pinned)) as $pinnedKey) {
                $this->reportRefusal((string) $pinnedKey);
            }

            $key = array_diff_key($key, $this->pinned);
        }

        parent::set($key, $value);
    }

    private function reportRefusal(string $key): void
    {
        if (isset($this->reported[$key])) {
            return;
        }

        $this->reported[$key] = true;

        $this->logger?->info('A pinned configuration key was not changed.', [
            'key' => $key,
            'reason' => 'demo mode pins this value',
        ]);
    }
}
