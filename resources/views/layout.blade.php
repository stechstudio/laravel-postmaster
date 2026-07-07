<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Postmaster — @yield('title', 'Dashboard')</title>
    <link rel="icon" type="image/png" href="{{ route('postmaster.logo') }}">
    <link rel="stylesheet" href="{{ route('postmaster.css') }}">
    <script defer src="{{ route('postmaster.alpine') }}"></script>
</head>
<body class="pm-body">
<div class="pm-layout">
    <aside class="pm-sidebar" x-data="{ navOpen: false }">
        <div class="pm-brand">
            <img src="{{ route('postmaster.logo') }}" alt="" class="pm-brand-mark">
            Postmaster
        </div>
        <button type="button" class="pm-nav-toggle" @click="navOpen = ! navOpen"
                :aria-expanded="navOpen" aria-label="Menu">
            <svg x-show="! navOpen" width="20" height="20" viewBox="0 0 20 20" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <line x1="3" y1="6" x2="17" y2="6"/>
                <line x1="3" y1="10" x2="17" y2="10"/>
                <line x1="3" y1="14" x2="17" y2="14"/>
            </svg>
            <svg x-show="navOpen" style="display: none;" width="20" height="20" viewBox="0 0 20 20"
                 fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <line x1="5" y1="5" x2="15" y2="15"/>
                <line x1="15" y1="5" x2="5" y2="15"/>
            </svg>
        </button>
        <nav class="pm-nav" :class="{ 'is-open': navOpen }">
            <a href="{{ route('postmaster.overview') }}" class="{{ request()->routeIs('postmaster.overview') ? 'is-active' : '' }}">Overview</a>
            <a href="{{ route('postmaster.messages') }}" class="{{ request()->routeIs('postmaster.messages*') ? 'is-active' : '' }}">Messages</a>
            <a href="{{ route('postmaster.activity') }}" class="{{ request()->routeIs('postmaster.activity') ? 'is-active' : '' }}">Activity</a>
            <a href="{{ route('postmaster.addresses') }}" class="{{ request()->routeIs('postmaster.addresses') ? 'is-active' : '' }}">Addresses</a>
        </nav>
    </aside>
    <main class="pm-main">
        <header class="pm-header">
            <h1>@yield('title', 'Dashboard')</h1>
            <div x-data="pmTimezone()" x-init="init()">
                <button type="button" class="pm-tz-btn" @click="toggle()" :title="title" :aria-label="title">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="12" cy="12" r="9"/>
                        <polyline points="12 7 12 12 15 14"/>
                    </svg>
                    <span x-text="label"></span>
                </button>
            </div>
        </header>
        <div class="pm-content">
            @if (session('postmasterFlash'))
                <div class="pm-flash pm-flash--ok">{{ session('postmasterFlash') }}</div>
            @endif
            @if (session('postmasterError'))
                <div class="pm-flash pm-flash--err">{{ session('postmasterError') }}</div>
            @endif
            @yield('content')
        </div>
    </main>
</div>
<script>
    // Filters auto-submit as a full-page GET reload, which would otherwise
    // drop keyboard focus mid-word — you type into "Subject", the debounced
    // submit fires, the page reloads, and the caret is gone. Stash the
    // focused filter field's name and caret position just before the form
    // submits, then restore both once the fresh page loads so typing feels
    // continuous. Scoped to text inputs inside a .pm-filters form; selects
    // and date pickers submit on change and don't need it.
    (function () {
        var KEY = 'pm-filter-focus';

        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!form.classList || !form.classList.contains('pm-filters')) return;
            var el = document.activeElement;
            if (!el || el.tagName !== 'INPUT' || el.type !== 'text' || !form.contains(el)) return;
            try {
                sessionStorage.setItem(KEY, JSON.stringify({
                    path: location.pathname,
                    name: el.name,
                    caret: el.selectionStart,
                }));
            } catch (err) { /* private mode — just lose focus */ }
        }, true);

        document.addEventListener('DOMContentLoaded', function () {
            var raw;
            try { raw = sessionStorage.getItem(KEY); sessionStorage.removeItem(KEY); }
            catch (err) { return; }
            if (!raw) return;

            var state;
            try { state = JSON.parse(raw); } catch (err) { return; }
            if (!state || state.path !== location.pathname) return;

            var input = document.querySelector('.pm-filters input[name="' + state.name + '"]');
            if (!input) return;

            input.focus();
            // Restore the caret (clamped — the value may be shorter now).
            var pos = Math.min(state.caret == null ? input.value.length : state.caret, input.value.length);
            try { input.setSelectionRange(pos, pos); } catch (err) { /* non-text input */ }
        });
    })();
</script>
<script>
    // Reformats every <time class="pm-when"> into the viewer's chosen
    // timezone, defaulting to whatever the browser reports and falling
    // back to UTC. The header toggle swaps between them; the choice
    // persists in localStorage. A MutationObserver catches <time>
    // elements added later (by the live activity feed) so they're
    // formatted the same way.
    function pmTimezone() {
        return {
            zone: 'UTC',
            detected: 'UTC',
            init() {
                try {
                    this.detected = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
                } catch (e) { /* keep UTC */ }
                this.zone = localStorage.getItem('pm-tz') || this.detected;
                this.formatAll();
                new MutationObserver((records) => {
                    for (const record of records) {
                        for (const node of record.addedNodes) {
                            if (node.nodeType !== 1) continue;
                            if (node.matches && node.matches('time.pm-when')) {
                                this.formatOne(node);
                            }
                            if (node.querySelectorAll) {
                                node.querySelectorAll('time.pm-when').forEach((el) => this.formatOne(el));
                            }
                        }
                    }
                }).observe(document.body, { childList: true, subtree: true });
            },
            toggle() {
                this.zone = this.zone === 'UTC' ? this.detected : 'UTC';
                localStorage.setItem('pm-tz', this.zone);
                this.formatAll();
            },
            formatAll() {
                document.querySelectorAll('time.pm-when').forEach((el) => this.formatOne(el));
            },
            formatOne(el) {
                const iso = el.getAttribute('datetime');
                if (!iso) return;
                const d = new Date(iso);
                if (isNaN(d.getTime())) return;
                const base = { hour: 'numeric', minute: '2-digit', hour12: true, month: 'short', day: 'numeric' };
                const opts = el.dataset.style === 'long' ? { ...base, year: 'numeric' } : base;
                el.textContent = new Intl.DateTimeFormat('en-US', { timeZone: this.zone, ...opts }).format(d);
            },
            get label() {
                if (this.zone === 'UTC') return 'UTC';
                try {
                    return new Intl.DateTimeFormat('en-US', { timeZone: this.zone, timeZoneName: 'short' })
                        .formatToParts(new Date())
                        .find((p) => p.type === 'timeZoneName')?.value || this.zone;
                } catch (e) {
                    return this.zone;
                }
            },
            get title() {
                const other = this.zone === 'UTC' ? this.detected : 'UTC';
                return 'Showing times in ' + this.zone + '. Click to switch to ' + other + '.';
            },
        };
    }
</script>
</body>
</html>
