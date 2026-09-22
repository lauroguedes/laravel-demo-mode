<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\View\Components;

use Illuminate\View\Component;
use LauroGuedes\DemoMode\Credentials\Credential;
use LauroGuedes\DemoMode\Credentials\Manager;
use LauroGuedes\DemoMode\DemoMode;

/**
 * The credentials a stranger signs in with, on the page they sign in from.
 *
 * Renders nothing when this is not a demo, or when the installation publishes no
 * credentials — so a login page can carry it unconditionally.
 *
 * This component puts a working password into HTML on purpose. That is what a
 * demo is for, and it is bounded by everything in docs/security.md: the store is
 * private, the password rotates on every reset, and a leftover file goes inert
 * the moment DEMO_MODE goes off. What it is not bounded by is the page's own
 * caching — if a CDN or a full-page cache sits in front of this, the password
 * lives there too, and for as long as that cache says it does.
 */
class Credentials extends Component
{
    /** @var list<Credential>|null */
    private ?array $accounts = null;

    public function __construct(
        private readonly Manager $credentials,
        private readonly DemoMode $demo,
        private readonly ?bool $copyable = null,
    ) {}

    /**
     * Manager::all() already returns nothing when this is not a demo or when
     * publishing is off, so an empty list is the whole question.
     */
    public function shouldRender(): bool
    {
        return $this->accounts() !== [];
    }

    public function render(): string
    {
        return 'demo::components.credentials';
    }

    /**
     * @return list<Credential>
     */
    public function accounts(): array
    {
        return $this->accounts ??= $this->credentials->all();
    }

    /**
     * Whether to offer a copy button.
     *
     * Folds in whether the script will actually be on the page: a copy button
     * with nothing behind it is a control that lies, and on a demo with a strict
     * Content-Security-Policy every one of them did.
     */
    public function copyable(): bool
    {
        return ($this->copyable ?? true) && $this->demo->scripted();
    }
}
