{{--
    The floating bar.

    Two tags and nothing else: the element carries its state as JSON and builds
    its own interface inside a shadow root, which is the point of it. A styled
    banner in the page is reached by the host's reset, by Tailwind's preflight,
    by a daisyUI theme — differently in every application. Inside a shadow root
    nothing crosses that is not asked for by name, so it looks the same in Blade,
    Livewire and Inertia without any of them passing a class.

    Inertia applications put this in their root Blade view, next to @inertia. It
    is outside the Vue or React tree on purpose: it must survive a client-side
    navigation, and it has nothing the application's own components need.

    Publish these views if you want different markup:

        php artisan vendor:publish --tag=demo-views
--}}
<demo-mode-bar
    data-demo-state="{{ json_encode($payload(), JSON_THROW_ON_ERROR) }}"
    {{ $attributes }}
></demo-mode-bar>

{{-- One script however many bars a page renders. --}}
@once('demo-mode-bar-script')
    <script src="{{ $script() }}" defer data-demo-script></script>
@endonce
