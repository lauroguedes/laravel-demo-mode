{{--
    No styling of its own. The variant and position are data attributes so a
    stylesheet can target them, and banner.classes supplies the class name your
    framework wants. Publish this view if you need different markup:

        php artisan vendor:publish --tag=demo-views
--}}
@php($banner = $state())

<div
    role="status"
    aria-live="polite"
    data-demo-banner
    data-demo-variant="{{ $banner->variant }}"
    data-demo-position="{{ $banner->position }}"
    @if ($banner->nextResetAt) data-demo-reset-at="{{ $banner->nextResetAt->toIso8601String() }}" @endif
    data-demo-rebuilding="{{ __('demo::demo.bar.rebuilding') }}"
    {{ $attributes->class($banner->class ?? '') }}
>
    <p data-demo-banner-message>{{ $banner->html($units()) }}</p>

    @if ($banner->dismissible)
        <button type="button" data-demo-dismiss aria-label="{{ __('demo::demo.banner.dismiss') }}">
            <span aria-hidden="true">&times;</span>
        </button>
    @endif
</div>

<x-demo-script />
