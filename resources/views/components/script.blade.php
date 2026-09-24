{{--
    The only JavaScript this package ships: a ticking countdown, a dismiss
    button, and copy-to-clipboard. Emitted once per response, and never when
    demo.script is false — set that under a strict Content-Security-Policy, or
    publish these views and move this into your own bundle.

    The @once lives here rather than in the callers. Blade compiles a bare @once
    to a fresh UUID per compiled occurrence, so a block in each caller is a
    separate key, and a page carrying both components emitted this twice. One
    occurrence, one key, one copy.

    Dismissal lasts for the page it happened on and nothing longer. It used to be
    remembered in sessionStorage, which survives a reload and is not cleared with
    the cache — so a visitor who hid the banner could not work out how to get it
    back, and the notice saying the data is temporary is the one thing on a demo
    that should be hard to lose.

    This is the bare banner's copy of the countdown, and of what happens when it
    runs out. The floating bar has its own in resources/dist/demo-bar.js, because
    it runs inside a shadow root from a served file — if the units, the shape or
    the ending change, change both. The last change to reach zero only landed
    here on the second pass, which is exactly what this note is for.

    The countdown's unit words come from the translations rather than being
    hardcoded here. The first tick overwrites whatever the server rendered, so
    hardcoding "h" and "m" meant a Portuguese demo showed "30 minutos" for one
    frame and "30m 0s" thereafter.
--}}
@once
<script data-demo-script>
(() => {
    const banner = document.querySelector('[data-demo-banner]');

    document.addEventListener('click', (event) => {
        const dismiss = event.target.closest('[data-demo-dismiss]');

        if (dismiss && banner) {
            banner.hidden = true;

            return;
        }

        const copy = event.target.closest('[data-demo-copy]');

        if (copy) {
            navigator.clipboard?.writeText(copy.dataset.demoCopy).then(() => {
                copy.dataset.demoCopied = '1';
                setTimeout(() => delete copy.dataset.demoCopied, 2000);
            }).catch(() => { /* no clipboard permission */ });
        }
    });

    const countdown = document.querySelector('[data-demo-countdown]');

    if (!countdown) return;

    const target = new Date(countdown.dateTime).getTime();
    const unit = {
        hour: countdown.dataset.demoUnitHour,
        minute: countdown.dataset.demoUnitMinute,
        second: countdown.dataset.demoUnitSecond,
    };

    const tick = () => {
        const left = Math.max(0, target - Date.now());
        const total = Math.floor(left / 1000);
        const hours = Math.floor(total / 3600);
        const minutes = Math.floor((total % 3600) / 60);
        const seconds = total % 60;

        countdown.textContent = hours > 0
            ? `${hours}${unit.hour} ${minutes}${unit.minute}`
            : (minutes > 0 ? `${minutes}${unit.minute} ${seconds}${unit.second}` : `${seconds}${unit.second}`);

        if (left > 0) return setTimeout(tick, 1000);

        /*
         * Zero means the scheduler is rebuilding, not that it finished. Sitting
         * on "0s" was a clock that had plainly stopped.
         */
        rebuilding();
    };

    /*
     * The same ending as the floating bar's, in the other runtime. It asks for
     * the page rather than guessing how long a rebuild takes: a HEAD answers 503
     * while maintenance is on, and reloading into that leaves the visitor on a
     * page with nothing on it. Gives up after a couple of minutes.
     *
     * The first wait is jittered widely because every visitor's countdown
     * reaches zero on the same second.
     */
    const rebuilding = (attempt = 0) => {
        const message = banner?.querySelector('[data-demo-banner-message]');

        if (message && banner.dataset.demoRebuilding) {
            message.textContent = banner.dataset.demoRebuilding;
        }

        setTimeout(async () => {
            if (attempt >= 24) return location.reload();

            try {
                const response = await fetch(location.href, { method: 'HEAD', cache: 'no-store' });

                if (response.status !== 503) return location.reload();
            } catch (e) { /* not answering yet, which is not ready */ }

            rebuilding(attempt + 1);
        }, attempt === 0 ? 4000 + Math.random() * 8000 : 3000 + Math.random() * 2000);
    };

    tick();
})();
</script>
@endonce
