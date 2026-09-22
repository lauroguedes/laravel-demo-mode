<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\View;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * The banner, resolved once, for whichever front end is asking.
 *
 * This exists because the banner had two owners at two altitudes: DemoMode::
 * toBanner() built a payload for Inertia and the Blade component built its own
 * from the same config keys, each carrying its own copy of the defaults. They had
 * already drifted in three ways — the variant-to-class rule existed only on the
 * Blade side, so every Inertia application had to reimplement it; the sentence
 * was a flat string on one side and a marker substitution on the other; and
 * nothing in the package's own Blade path called toBanner() at all.
 *
 * Both paths now read this. Neither reads a banner.* key, so they cannot disagree
 * about a default without disagreeing about the same line of code.
 *
 * The sentence is offered in two forms on purpose. Plain text is what a JSON
 * payload can carry; the HTML form carries a <time> element the countdown can
 * update in place. Keeping them adjacent is the point — that is exactly the pair
 * that drifted before.
 */
final readonly class BannerState
{
    public function __construct(
        public string $variant,
        public ?string $class,
        public bool $dismissible,
        public string $position,
        public ?string $message,
        public ?CarbonImmutable $nextResetAt,
        public ?string $resetsIn,
    ) {}

    /**
     * The sentence as a JSON payload can carry it.
     */
    public function text(): string
    {
        if ($this->message !== null) {
            return $this->message;
        }

        return $this->resetsIn === null
            ? (string) trans('demo::demo.banner.without_countdown')
            : (string) trans('demo::demo.banner.with_countdown', ['time' => $this->resetsIn]);
    }

    /**
     * The same sentence with the remaining time as a <time> element.
     *
     * The time has to land inside a translated sentence whose word order differs
     * by language, so the translation is rendered with a marker, escaped as a
     * whole, and the marker replaced by the element. A translation file is data:
     * it does not get to inject markup into every page of an application.
     *
     * @param  array{hour: string, minute: string, second: string}  $units
     */
    public function html(array $units): Htmlable
    {
        if ($this->message !== null || ! $this->nextResetAt instanceof CarbonImmutable || $this->resetsIn === null) {
            return new HtmlString(e($this->text()));
        }

        $marker = '__demo_countdown__';

        return new HtmlString(str_replace($marker, sprintf(
            '<time datetime="%s" data-demo-countdown data-demo-unit-hour="%s" data-demo-unit-minute="%s" data-demo-unit-second="%s">%s</time>',
            $this->nextResetAt->toIso8601String(),
            e($units['hour']),
            e($units['minute']),
            e($units['second']),
            e($this->resetsIn),
        ), e((string) trans('demo::demo.banner.with_countdown', ['time' => $marker]))));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'message' => $this->text(),
            'variant' => $this->variant,
            'class' => $this->class,
            'dismissible' => $this->dismissible,
            'position' => $this->position,
            'next_reset_at' => $this->nextResetAt?->toIso8601String(),
        ];
    }
}
