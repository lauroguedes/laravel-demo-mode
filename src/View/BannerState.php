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
    /**
     * @param  array{label: string, url: string}|null  $cta
     */
    public function __construct(
        public string $variant,
        public ?string $class,
        public bool $dismissible,
        public string $position,
        public ?string $message,
        public ?CarbonImmutable $nextResetAt,
        public ?string $resetsIn,
        public string $style = 'bare',
        public ?string $label = null,
        public ?array $cta = null,
        public ?string $resetUrl = null,
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
            ? (string) trans($this->key('without_countdown'))
            : (string) trans($this->key('with_countdown'), ['time' => $this->resetsIn]);
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
        ), e((string) trans($this->key('with_countdown'), ['time' => $marker]))));
    }

    /**
     * The translated sentence either side of the countdown.
     *
     * The bar builds its own elements, so it cannot be handed the HTML above —
     * but it still must not assemble the sentence itself. Word order differs by
     * language: "deleted in 20 minutes" and "apagado em 20 minutos" put the
     * duration in the same place, "20分後に削除されます" does not. Splitting the
     * translation on the marker keeps that decision in the translation file.
     *
     * Null when there is no countdown to wrap — a custom message, or a schedule
     * that could not be read.
     *
     * @return array{before: string, after: string}|null
     */
    public function around(): ?array
    {
        if ($this->message !== null || ! $this->nextResetAt instanceof CarbonImmutable || $this->resetsIn === null) {
            return null;
        }

        $marker = '__demo_countdown__';

        $parts = explode($marker, (string) trans($this->key('with_countdown'), ['time' => $marker]), 2);

        return ['before' => $parts[0], 'after' => $parts[1] ?? ''];
    }

    /**
     * The same state in the shape the floating bar's element reads.
     *
     * Beside toArray() rather than composed in the view component, for the
     * reason this class exists at all: the banner used to be shaped for Blade in
     * one place and for Inertia in another, and the two drifted. A third shape
     * assembled somewhere else would be the same mistake with a new name.
     *
     * Everything here is request-independent. The CSRF token is not, so it is
     * the one field the component adds.
     *
     * @param  array{hour: string, minute: string, second: string}  $units
     * @return array<string, mixed>
     */
    public function forBar(array $units): array
    {
        $around = $this->around();

        return [
            'variant' => $this->variant,
            'position' => $this->position,
            'dismissible' => $this->dismissible,
            'label' => $this->label,
            'cta' => $this->cta,
            'message' => $this->text(),
            'messageBefore' => $around['before'] ?? null,
            'messageAfter' => $around['after'] ?? null,
            'countdown' => $around === null ? null : $this->resetsIn,
            'nextResetAt' => $this->nextResetAt?->toIso8601String(),
            'units' => $units,
            'resetUrl' => $this->resetUrl,
            'strings' => [
                'reset' => (string) trans('demo::demo.bar.reset'),
                'confirm' => (string) trans('demo::demo.bar.confirm'),
                'confirmYes' => (string) trans('demo::demo.bar.confirm_yes'),
                'cancel' => (string) trans('demo::demo.bar.cancel'),
                'working' => (string) trans('demo::demo.bar.working'),
                'failed' => (string) trans('demo::demo.bar.failed'),
                'dismiss' => (string) trans('demo::demo.banner.dismiss'),
            ],
        ];
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

    /**
     * The bar says less than the banner, and deliberately.
     *
     * A full-width strip has room for a sentence; a pill does not, and the first
     * one rendered was 827 pixels of prose. Same fact, said in the shape it is
     * being said in — which is a translation-file decision, not a substring one.
     */
    private function key(string $name): string
    {
        return $this->style === 'pill'
            ? 'demo::demo.bar.'.$name
            : 'demo::demo.banner.'.$name;
    }
}
