<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Postmaster — @yield('title', 'Dashboard')</title>
    <link rel="icon" type="image/svg+xml" href="{{ route('postmaster.logo') }}">
    <link rel="stylesheet" href="{{ route('postmaster.css') }}">
    <script defer src="{{ route('postmaster.alpine') }}"></script>
</head>
<body class="pm-body">
<div class="pm-layout">
    <aside class="pm-sidebar">
        <div class="pm-brand">
            <img src="{{ route('postmaster.logo') }}" alt="" class="pm-brand-mark">
            Postmaster
        </div>
        <nav class="pm-nav">
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
            @yield('content')
        </div>
    </main>
</div>
</body>
</html>
