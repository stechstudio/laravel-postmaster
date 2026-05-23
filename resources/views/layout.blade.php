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
</body>
</html>
