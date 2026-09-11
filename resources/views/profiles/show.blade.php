@extends('layouts.app')
@section('title', $profile->username)

@section('content')
    <div class="mb-6 flex items-center gap-3">
        <h2 class="text-lg font-semibold">{{ $profile->username }}</h2>
        <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold">account {{ $profile->account->key }}</span>
        <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $profile->account->source === 'ofapi' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">
            {{ strtoupper($profile->account->source) }}
        </span>
        <form method="POST" action="{{ route('profiles.refresh', $profile) }}" class="ml-auto">
            @csrf
            <button class="rounded-lg bg-slate-800 px-3 py-1.5 text-sm font-medium text-white">Queue refresh</button>
        </form>
    </div>

    <dl class="mb-8 grid grid-cols-2 gap-4 rounded-xl border border-slate-200 bg-white p-5 text-sm shadow-sm md:grid-cols-4">
        <div><dt class="text-xs uppercase text-slate-500">Last valid likes</dt>
            <dd class="mt-1 text-xl font-semibold tabular-nums">{{ $profile->likes === null ? '-' : number_format($profile->likes) }}</dd></div>
        <div><dt class="text-xs uppercase text-slate-500">Accepted revision</dt>
            <dd class="mt-1 text-xl font-semibold tabular-nums">{{ $profile->revision ?? 'none' }}</dd></div>
        <div><dt class="text-xs uppercase text-slate-500">Last successful refresh</dt>
            <dd class="mt-1">{{ $profile->last_success_at?->toDateTimeString() ?? 'never' }}</dd></div>
        <div><dt class="text-xs uppercase text-slate-500">Next refresh due</dt>
            <dd class="mt-1">{{ $profile->next_refresh_at?->toDateTimeString() ?? 'unscheduled' }}</dd></div>
        <div><dt class="text-xs uppercase text-slate-500">Last attempt</dt>
            <dd class="mt-1">{{ $profile->last_attempt_at?->toDateTimeString() ?? '-' }}</dd></div>
        <div><dt class="text-xs uppercase text-slate-500">Last failure</dt>
            <dd class="mt-1">{{ $profile->last_failure_category ?? '-' }}</dd></div>
        <div><dt class="text-xs uppercase text-slate-500">Data updates / verified unchanged</dt>
            <dd class="mt-1 tabular-nums">{{ $profile->success_count }} / {{ $profile->verified_unchanged_count }}</dd></div>
        <div><dt class="text-xs uppercase text-slate-500">Snapshot source</dt>
            <dd class="mt-1">{{ $profile->snapshot_source ?? '-' }}{{ $profile->snapshot_from_cache ? ' (provider cache)' : '' }}</dd></div>
    </dl>

    <h3 class="mb-3 font-semibold">Recent refresh runs</h3>
    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
        <table class="w-full text-left text-sm">
            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
            <tr>
                <th class="px-5 py-2.5">Run</th><th class="px-5 py-2.5">Trigger</th><th class="px-5 py-2.5">Status</th>
                <th class="px-5 py-2.5">Outcome</th><th class="px-5 py-2.5">Deliveries</th><th class="px-5 py-2.5">Upstream requests</th>
                <th class="px-5 py-2.5">Enqueued (UTC)</th><th class="px-5 py-2.5">Attempts</th>
            </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
            @foreach ($runs as $run)
                <tr>
                    <td class="px-5 py-3 tabular-nums">{{ $run->id }}</td>
                    <td class="px-5 py-3">{{ $run->trigger }}</td>
                    <td class="px-5 py-3">{{ $run->status->label() }}</td>
                    <td class="px-5 py-3">{{ $run->outcome_category ?? '-' }}</td>
                    <td class="px-5 py-3 tabular-nums">{{ $run->deliveries }}</td>
                    <td class="px-5 py-3 tabular-nums">{{ $run->requests_used }}</td>
                    <td class="px-5 py-3">{{ $run->enqueued_at->toDateTimeString() }}</td>
                    <td class="px-5 py-3 text-xs text-slate-600">
                        @foreach ($run->attempts as $attempt)
                            <div>#{{ $attempt->attempt_no }} {{ $attempt->http_status ?? '-' }} {{ $attempt->category }} ({{ $attempt->duration_ms }}ms)</div>
                        @endforeach
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endsection
