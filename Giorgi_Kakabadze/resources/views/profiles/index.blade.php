@extends('layouts.app')
@section('title', 'Profiles')

@section('content')
    @include('partials.activity')

    <div class="mb-4 flex items-center gap-3">
        <h2 class="text-lg font-semibold">Profiles</h2>
        <form method="GET" action="{{ route('profiles.index') }}" class="ml-auto flex gap-2">
            <input type="search" name="q" value="{{ $term }}" placeholder="Search username or name"
                   class="w-64 rounded-lg border border-slate-300 px-3 py-1.5 text-sm">
            <button class="rounded-lg bg-slate-800 px-3 py-1.5 text-sm font-medium text-white">Search</button>
        </form>
    </div>

    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
        <table class="w-full text-left text-sm">
            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
            <tr>
                <th class="px-5 py-2.5">Account</th>
                <th class="px-5 py-2.5">Profile</th>
                <th class="px-5 py-2.5">Last valid likes</th>
                <th class="px-5 py-2.5">Revision</th>
                <th class="px-5 py-2.5">Last successful refresh</th>
                <th class="px-5 py-2.5">Status</th>
                <th class="px-5 py-2.5"></th>
            </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
            @forelse ($profiles as $profile)
                <tr>
                    <td class="px-5 py-3">{{ $profile->account->key }}</td>
                    <td class="px-5 py-3">
                        <a class="font-medium hover:underline" href="{{ route('profiles.show', $profile) }}">{{ $profile->username }}</a>
                    </td>
                    {{-- Last valid values stay visible even while the profile is retrying. --}}
                    <td class="px-5 py-3 tabular-nums">{{ $profile->likes === null ? '-' : number_format($profile->likes) }}</td>
                    <td class="px-5 py-3 tabular-nums">{{ $profile->revision ?? '-' }}</td>
                    <td class="px-5 py-3 text-slate-600">{{ $profile->last_success_at?->toDateTimeString() ?? 'never' }}</td>
                    <td class="px-5 py-3">
                        @if ($profile->pending_run_id)
                            <span class="rounded-full bg-sky-100 px-2 py-0.5 text-xs font-semibold text-sky-800">queued</span>
                        @elseif ($profile->terminal_failed_at)
                            <span class="rounded-full bg-rose-100 px-2 py-0.5 text-xs font-semibold text-rose-800">{{ $profile->last_failure_category }}</span>
                        @else
                            <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-800">idle</span>
                        @endif
                    </td>
                    <td class="px-5 py-3 text-right">
                        <form method="POST" action="{{ route('profiles.refresh', $profile) }}">
                            @csrf
                            <button class="rounded-lg border border-slate-300 px-3 py-1 text-xs font-medium hover:bg-slate-50">Queue refresh</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td class="px-5 py-6 text-slate-400" colspan="7">No profiles. Run <code>php artisan fans:demo seed</code>.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $profiles->links() }}</div>
@endsection
