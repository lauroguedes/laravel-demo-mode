/**
 * The floating demo bar: one custom element, no dependencies, no build step.
 *
 * Shadow DOM rather than plain CSS in the page, and that is the whole reason
 * this file exists. A styled banner in the light DOM is hit by Tailwind's
 * preflight, by a daisyUI theme, by `* { box-sizing }`, by any reset the host
 * happens to ship — and it is hit differently in every application, which is
 * exactly what the bare banner could not solve.
 *
 * What a shadow root stops is selectors, not inheritance. Every inheritable
 * property — font-weight, letter-spacing, text-transform — still crosses from
 * the host element, so .bar states all of them rather than assuming. The first
 * application this ran in put "font-black" on the element and got a bar at
 * weight 900.
 *
 * Served from a route rather than inlined, so a Content-Security-Policy can
 * allow it by origin instead of needing a hash or a nonce for a script that
 * changes with the state it carries.
 *
 * Everything it knows arrives as JSON on the element. It makes no requests of
 * its own except the reset, which is the one thing a visitor asked for.
 */
(() => {
    if (customElements.get('demo-mode-bar')) return;

    const ACCENTS = {
        warning: '#f5a524',
        danger: '#f4584f',
        info: '#5b9bf8',
        success: '#3fb984',
        neutral: '#a8a8ad',
    };

    const CSS = `
        /*
         * Every layout property lives on .viewport, inside the shadow root,
         * rather than on :host — because a :host rule loses to any rule in the
         * outer document that matches the host element, whatever the
         * specificity. Tailwind's preflight carries "*, ::before, ::after
         * { margin: 0; padding: 0 }", and that quietly won: the bar sat flush
         * against the bottom edge with the gap removed. Nothing out there can
         * reach inside here.
         *
         * The host itself is display:contents, set inline by the element, so it
         * generates no box for the outer stylesheet to have an opinion about.
         */
        .viewport {
            position: fixed;
            inset-inline: 0;
            z-index: 2147483000;
            display: flex;
            justify-content: center;
            /* It spans the viewport so the pill can centre in it, which would
               swallow every click across that strip without this. */
            pointer-events: none;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        }
        :host([data-position="bottom"]) .viewport {
            inset-block-end: 0;
            padding: 0 12px calc(16px + env(safe-area-inset-bottom, 0px));
        }
        :host([data-position="top"]) .viewport {
            inset-block-start: 0;
            padding: calc(16px + env(safe-area-inset-top, 0px)) 12px 0;
        }

        .bar {
            pointer-events: auto;
            box-sizing: border-box;
            max-width: 100%;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 7px 8px 7px 10px;
            border-radius: 999px;
            background: rgba(24, 24, 27, 0.88);
            -webkit-backdrop-filter: blur(14px) saturate(160%);
            backdrop-filter: blur(14px) saturate(160%);
            border: 1px solid rgba(255, 255, 255, 0.12);
            box-shadow: 0 8px 28px rgba(0, 0, 0, 0.32), 0 1px 2px rgba(0, 0, 0, 0.25);
            color: #f4f4f5;
            /*
             * Spelled out rather than left to cascade. A shadow root isolates
             * selectors, not inheritance: font-weight, letter-spacing and the
             * rest still cross from the host, so a layout that put "font-black"
             * on the element rendered this at weight 900.
             */
            font-size: 13px;
            font-weight: 500;
            font-style: normal;
            line-height: 1;
            letter-spacing: normal;
            word-spacing: normal;
            text-transform: none;
            text-align: start;
            text-indent: 0;
            animation: enter 260ms cubic-bezier(0.22, 1, 0.36, 1) both;
        }
        :host([data-position="top"]) .viewport .bar { animation-name: enter-top; }

        @keyframes enter { from { opacity: 0; transform: translateY(12px); } }
        @keyframes enter-top { from { opacity: 0; transform: translateY(-12px); } }
        @media (prefers-reduced-motion: reduce) {
            .bar { animation: none; }
        }

        .badge {
            flex: none;
            padding: 4px 9px;
            border-radius: 999px;
            background: color-mix(in srgb, var(--accent) 18%, transparent);
            color: var(--accent);
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            white-space: nowrap;
        }
        .rule {
            flex: none;
            width: 1px;
            height: 18px;
            background: rgba(255, 255, 255, 0.14);
        }
        .message {
            min-width: 0;
            padding-inline: 2px;
            color: rgba(255, 255, 255, 0.76);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        time { font-variant-numeric: tabular-nums; }

        button, .cta {
            font: inherit;
            color: inherit;
            border: 0;
            cursor: pointer;
            /* The same curve as the bar around it. Only <button> carried this,
               and the call to action is an <a>, so it sat square inside a pill. */
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: background-color 120ms ease, opacity 120ms ease;
        }
        button:focus-visible {
            outline: 2px solid var(--accent);
            outline-offset: 2px;
        }
        button[disabled] { opacity: 0.5; cursor: default; }

        .icon {
            flex: none;
            width: 28px;
            height: 28px;
            padding: 0;
            background: transparent;
            color: rgba(255, 255, 255, 0.72);
        }
        .icon:hover:not([disabled]) {
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
        }
        .icon svg { width: 15px; height: 15px; }

        .cta, .confirm, .ghost {
            flex: none;
            padding: 7px 12px;
            font-size: 12.5px;
            white-space: nowrap;
        }
        .cta, .confirm {
            background: var(--accent);
            color: #18181b;
            font-weight: 600;
        }
        .cta {
            padding-inline-end: 13px;
            text-decoration: none;
        }
        .cta:hover { filter: brightness(1.08); }
        .cta svg { width: 12px; height: 12px; }

        .ghost {
            background: transparent;
            color: rgba(255, 255, 255, 0.7);
        }
        .ghost:hover { color: #fff; }

        .spin { animation: spin 900ms linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        @media (prefers-reduced-motion: reduce) { .spin { animation: none; } }

        /* Narrow screens: the countdown and the call to action are the first
           things to go, because the badge is what the bar is for. */
        @media (max-width: 560px) {
            .cta span { display: none; }
            .cta { padding: 7px 10px; }
        }
        @media (max-width: 420px) {
            .message, .rule { display: none; }
        }
    `;

    const ICON = {
        reset: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 3v6h-6"/></svg>',
        close: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>',
        link: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M19 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h6"/></svg>',

        /*
         * A ring and an arc, not the reset glyph turning. Spinning that one
         * looked wrong for a reason worth remembering: it is a circular arrow
         * with a head and a gap, so rotating it reads as a shape tumbling rather
         * than something loading. A symmetric track with one moving arc is what
         * the eye reads as progress.
         */
        spinner: '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2.5" opacity="0.25"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/></svg>',
    };

    class DemoModeBar extends HTMLElement {
        connectedCallback() {
            if (this.shadowRoot) return;

            try {
                this.state = JSON.parse(this.dataset.demoState || '{}');
            } catch (e) {
                return;
            }

            this.setAttribute('data-position', this.state.position === 'bottom' ? 'bottom' : 'top');
            this.setAttribute('role', 'status');
            this.setAttribute('aria-live', 'polite');

            /*
             * Inline, so the outer stylesheet cannot give the host a box. A
             * :host rule would not survive a "*" selector out there.
             */
            this.style.setProperty('display', 'contents');

            const root = this.attachShadow({ mode: 'open' });
            const style = document.createElement('style');
            style.textContent = CSS;

            const viewport = document.createElement('div');
            viewport.className = 'viewport';

            this.bar = document.createElement('div');
            this.bar.className = 'bar';
            this.bar.style.setProperty('--accent', ACCENTS[this.state.variant] || ACCENTS.neutral);

            viewport.append(this.bar);
            root.append(style, viewport);

            /*
             * Bound once, to an element that outlives every state. Adding it in
             * confirm() added another on every confirm/cancel round, because
             * only .bar's children are ever replaced.
             */
            this.bar.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && this.confirming) this.idle();
            });

            this.idle();
        }

        disconnectedCallback() {
            clearTimeout(this.timer);
            this.waiting = false;
        }

        /* ---- states ------------------------------------------------------ */

        idle() {
            clearTimeout(this.timer);
            this.confirming = false;
            this.bar.replaceChildren();

            if (this.state.label) {
                this.bar.append(this.node('span', { class: 'badge', text: this.state.label }));
            }

            if (this.state.message) {
                if (this.state.label) this.bar.append(this.node('span', { class: 'rule' }));
                this.bar.append(this.message());
            }

            if (this.state.resetUrl) {
                this.bar.append(this.button({
                    class: 'icon',
                    html: ICON.reset,
                    label: this.state.strings.reset,
                    onClick: () => this.confirm(),
                }));
            }

            if (this.state.cta) {
                const cta = this.node('a', { class: 'cta', html: `<span>${escape(this.state.cta.label)}</span>${ICON.link}` });
                cta.href = this.state.cta.url;
                cta.target = '_blank';
                cta.rel = 'noopener noreferrer';
                this.bar.append(cta);
            }

            if (this.state.dismissible) {
                this.bar.append(this.button({
                    class: 'icon',
                    html: ICON.close,
                    label: this.state.strings.dismiss,
                    onClick: () => this.dismiss(),
                }));
            }

            this.countdown();
        }

        /**
         * Pressing reset drops every table, so it asks first — inline rather
         * than through confirm(), which some browsers suppress and none of them
         * style. Escape backs out, and so does anything else being pressed.
         */
        confirm() {
            clearTimeout(this.timer);
            this.confirming = true;
            this.bar.replaceChildren();

            this.bar.append(this.node('span', { class: 'message', text: this.state.strings.confirm }));

            const yes = this.button({
                class: 'confirm',
                text: this.state.strings.confirmYes,
                label: this.state.strings.confirmYes,
                onClick: () => this.reset(),
            });

            this.bar.append(yes, this.button({
                class: 'ghost',
                text: this.state.strings.cancel,
                label: this.state.strings.cancel,
                onClick: () => this.idle(),
            }));

            yes.focus();
        }

        async reset() {
            this.working(this.state.strings.working);

            try {
                const response = await fetch(this.state.resetUrl, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': this.state.token || '',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                });

                const body = await response.json().catch(() => ({}));
                const message = body.message || this.state.strings.failed;

                if (!response.ok) return this.notice(message);

                /*
                 * 200 means the rebuild already happened, so the page in front
                 * of the visitor is stale and reloading shows them what they
                 * asked for. 202 means it is queued and nothing has changed
                 * yet — reloading immediately would show the old data and look
                 * like the button did nothing, so it waits.
                 */
                this.working(message);

                this.timer = setTimeout(() => window.location.reload(), response.status === 202 ? 8000 : 0);
            } catch (e) {
                this.notice(this.state.strings.failed);
            }
        }

        /**
         * The reset the scheduler is doing, waited out rather than guessed at.
         *
         * The first version reloaded once after a fixed delay, which is fine for
         * a rebuild that finishes inside it and bad for one that does not: the
         * visitor lands on the maintenance page, which carries none of this and
         * no way to try again, and sits there until they think to reload. That
         * is worse than the stuck clock it replaced.
         *
         * So it asks. A HEAD for the page answers 503 while maintenance is on
         * and something else when the demo is back, which is the only signal
         * available without inventing an endpoint. It gives up after a couple of
         * minutes and reloads anyway, because a bar waiting forever is the same
         * dead clock in a different costume.
         *
         * The first wait is jittered widely because every visitor's countdown
         * reaches zero on the same second.
         */
        rebuilding() {
            this.working(this.state.strings.rebuilding);

            this.waiting = true;

            this.pollUntilItIsBack(0);
        }

        pollUntilItIsBack(attempt) {
            const first = attempt === 0;

            this.timer = setTimeout(async () => {
                if (!this.waiting) return;

                if (attempt >= 24) return window.location.reload();

                try {
                    const response = await fetch(window.location.href, { method: 'HEAD', cache: 'no-store' });

                    if (!this.waiting) return;

                    if (response.status !== 503) return window.location.reload();
                } catch (e) {
                    /* Not answering at all yet, which is the same as not ready. */
                }

                this.pollUntilItIsBack(attempt + 1);
            }, first ? 4000 + Math.random() * 8000 : 3000 + Math.random() * 2000);
        }

        working(text) {
            clearTimeout(this.timer);
            this.confirming = false;
            this.bar.replaceChildren(
                this.node('span', { class: 'icon spin', html: ICON.spinner }),
                this.node('span', { class: 'message', text }),
            );
        }

        notice(text) {
            clearTimeout(this.timer);
            this.confirming = false;
            this.bar.replaceChildren(this.node('span', { class: 'message', text }));
            this.timer = setTimeout(() => this.idle(), 6000);
        }

        /* ---- pieces ------------------------------------------------------ */

        message() {
            const wrap = this.node('span', { class: 'message' });

            if (!this.state.nextResetAt || !this.state.countdown) {
                wrap.textContent = this.state.message;
                return wrap;
            }

            /*
             * The sentence is translated and its word order differs by language,
             * so the server hands over the two halves around the time rather
             * than a string this has to cut up.
             */
            this.time = this.node('time', { text: this.state.countdown });
            this.time.dateTime = this.state.nextResetAt;

            wrap.append(this.state.messageBefore || '', this.time, this.state.messageAfter || '');

            return wrap;
        }

        /**
         * The same formatting as the bare banner's inline script, which is the
         * one duplication here worth naming. Unifying them would mean composing
         * a served file out of a Blade partial to save fifteen lines; if the
         * units or the shape change, change both — resources/views/components/
         * script.blade.php has the other copy.
         */
        countdown() {
            if (!this.time) return;

            const target = Date.parse(this.state.nextResetAt);
            const unit = this.state.units;

            const tick = () => {
                const left = Math.max(0, target - Date.now());
                const total = Math.floor(left / 1000);
                const hours = Math.floor(total / 3600);
                const minutes = Math.floor((total % 3600) / 60);
                const seconds = total % 60;

                this.time.textContent = hours > 0
                    ? `${hours}${unit.hour} ${minutes}${unit.minute}`
                    : minutes > 0
                        ? `${minutes}${unit.minute} ${seconds}${unit.second}`
                        : `${seconds}${unit.second}`;

                if (left > 0) {
                    this.timer = setTimeout(tick, 1000);

                    return;
                }

                /*
                 * Zero means the scheduler is rebuilding, not that it finished.
                 * Sitting on "0s" was a clock that had plainly stopped, so the
                 * bar says what is going on and fetches the page again.
                 *
                 * After a jittered wait, because every visitor's countdown hits
                 * zero on the same second: reloading immediately would send the
                 * whole room at the maintenance page together, which is the one
                 * thing the old comment here was right about.
                 */
                this.rebuilding();
            };

            tick();
        }

        button({ class: className, html, text, label, onClick }) {
            const el = this.node('button', { class: className, html, text });
            el.type = 'button';
            el.setAttribute('aria-label', label);
            el.addEventListener('click', onClick);
            return el;
        }

        node(tag, { class: className, html, text } = {}) {
            const el = document.createElement(tag);
            if (className) el.className = className;
            if (html) el.innerHTML = html;
            if (text) el.textContent = text;
            return el;
        }

        /* ---- dismissal --------------------------------------------------- */

        /**
         * For this page, and nothing longer.
         *
         * It used to be remembered in sessionStorage, keyed to the next reset.
         * That was worse than it sounds: sessionStorage survives a reload and is
         * not touched by clearing the cache, so a visitor who dismissed the bar
         * could not work out how to get it back — and the notice saying the data
         * is temporary is the one thing on a demo that should be hard to lose.
         *
         * Dismissing it now means "move out of the way while I look at this".
         * Reload, or follow a link, and it is back.
         */
        hide() {
            /*
             * Including a reload the countdown had queued. Closing the bar is
             * "leave me alone", and a page that reloads itself afterwards is the
             * opposite of that — the data comes back fresh on the next
             * navigation anyway.
             */
            clearTimeout(this.timer);
            this.waiting = false;

            this.hidden = true;
            this.style.setProperty('display', 'none');
        }

        dismiss() {
            this.hide();
        }
    }

    function escape(value) {
        return String(value).replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        })[c]);
    }

    customElements.define('demo-mode-bar', DemoModeBar);
})();
