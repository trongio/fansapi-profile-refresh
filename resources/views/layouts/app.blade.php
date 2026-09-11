<!DOCTYPE html>
<html lang="en" class="bg-slate-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Profile refresh')</title>
    @vite('resources/css/app.css')
</head>
<body class="min-h-screen font-sans text-slate-800 antialiased">
<header class="border-b border-slate-200 bg-white">
    <div class="mx-auto flex max-w-6xl items-center gap-4 px-6 py-4">
        <a href="{{ route('profiles.index') }}" class="text-lg font-semibold">Profile refresh</a>

        @php($live = config('fansapi.source') === 'ofapi')
        <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $live ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">
            {{ $live ? 'LIVE' : 'FIXTURE' }} default source
        </span>

        <nav class="ml-auto flex items-center gap-4 text-sm">
            <a class="hover:underline" href="{{ route('profiles.index') }}">Profiles</a>
            <a class="hover:underline" href="{{ route('dlq.index') }}">Dead letters</a>
            <a class="hover:underline" href="/horizon" target="_blank" rel="noopener">Horizon &#8599;</a>
        </nav>
    </div>
</header>

<main class="mx-auto max-w-6xl px-6 py-8">
    @if (session('status'))
        <div class="mb-6 rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">
            {{ session('status') }}
        </div>
    @endif

    @yield('content')
</main>
</body>
</html>
