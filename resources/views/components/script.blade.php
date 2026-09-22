{{--
    The only JavaScript this package ships: a ticking countdown, a dismiss
    button, and copy-to-clipboard. Emitted once per response, and never when
    demo.script is false — set that under a strict Content-Security-Policy, or
    publish these views and move this into your own bundle.

    The @once lives here rather than in the callers. Blade compiles a bare @once
    to a fresh UUID per compiled occurrence, so a block in each caller is a
    separate key, and a page carrying both components emitted this twice. One
    occurrence, one key, one copy.

    Dismissal is keyed to the next reset, so a visitor who hides the banner sees
    it again after the data has been rebuilt rather than never again.

    The countdown's unit words come from the translations rather than being
    hardcoded here. The first tick overwrites whatever the server rendered, so
    hardcoding "h" and "m" meant a Portuguese demo showed "30 minutos" for one
    frame and "30m 0s" thereafter.
--}}
@once
<script data-demo-script>
(() => {
    const banner = document.querySelector('[data-demo-banner]');
    const resetAt = banner?.dataset.demoResetAt;
    const key = resetAt ? 'demo-mode:dismissed:' + resetAt : null;

    try {
        if (key && sessionStorage.getItem(key) === '1') {
            banner.hidden = true;
        }
    } catch (e) {
        /* Private browsing, or storage disabled. The banner simply stays. */
    }

    document.addEventListener('click', (event) => {
        const dismiss = event.target.closest('[data-demo-dismiss]');

        if (dismiss && banner) {
            banner.hidden = true;

            try {
                if (key) sessionStorage.setItem(key, '1');
            } catch (e) { /* nothing to do */ }

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

        /*
         * At zero the scheduler is rebuilding, not finished. Reloading here
         * would put every visitor on the maintenance page at once, so the
         * countdown stops and the next navigation tells the truth.
         */
        if (left > 0) setTimeout(tick, 1000);
    };

    tick();
})();
</script>
@endonce
