{{-- Search and link-preview metadata. Pages set $noindex to stay out of search results. --}}
@php
    $grids = config('game.difficulties');
    $linkedGrid = request()->routeIs('game') && isset($grids[request()->query('grid')]) ? $grids[request()->query('grid')]['label'] : null;
    $previewTitle = request()->routeIs('game') && request()->query('mode') === 'daily'
        ? "Today's ".($linkedGrid ? $linkedGrid.' ' : '').'daily challenge · '.config('game.title')
        : (isset($pageTitle) ? $pageTitle.' · ' : '').config('game.title');
    $image = asset('images/og-image.png').'?v=1';
@endphp
<meta name="description" content="{{ config('game.description') }}">
@if ($noindex ?? false)
    <meta name="robots" content="noindex">
@endif
<link rel="canonical" href="{{ request()->routeIs('game') ? url('/') : url()->current() }}">
<meta name="theme-color" content="{{ config('game.colors.background') }}">
<link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="32x32">
<link rel="apple-touch-icon" href="{{ asset('images/apple-touch-icon.png') }}">

<meta property="og:type" content="website">
<meta property="og:site_name" content="{{ config('game.title') }}">
<meta property="og:title" content="{{ $previewTitle }}">
<meta property="og:description" content="{{ config('game.description') }}">
<meta property="og:url" content="{{ url()->full() }}">
<meta property="og:image" content="{{ $image }}">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="{{ config('game.title') }}: a grid of face-down cards marked with a medical cross">
<meta name="twitter:card" content="summary_large_image">
