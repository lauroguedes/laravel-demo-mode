{{--
    This renders a working password into the page. That is the point of a demo.
    See docs/security.md for what bounds it — and note that a full-page cache or
    CDN in front of this stores the password for as long as it says it does.

    The two fields are one loop rather than two blocks, so a published view has
    one place to restyle instead of two that drift apart.
--}}
<div data-demo-credentials {{ $attributes }}>
    <p data-demo-credentials-heading>{{ __('demo::demo.credentials.heading') }}</p>

    <ul>
        @foreach ($accounts() as $account)
            <li data-demo-account @if ($account->primary) data-demo-primary @endif>
                @if ($account->label)
                    <span data-demo-label>{{ $account->label }}</span>
                @endif

                <dl>
                    @foreach (['email' => $account->email, 'password' => $account->password] as $field => $value)
                        <dt>{{ __('demo::demo.credentials.'.$field) }}</dt>
                        <dd data-demo-field="{{ $field }}">
                            <span data-demo-value>{{ $value }}</span>

                            @if ($copyable())
                                <button
                                    type="button"
                                    data-demo-copy="{{ $value }}"
                                    aria-label="{{ __('demo::demo.credentials.copy') }}"
                                >{{ __('demo::demo.credentials.copy') }}</button>
                            @endif
                        </dd>
                    @endforeach
                </dl>
            </li>
        @endforeach
    </ul>
</div>

<x-demo-script />
