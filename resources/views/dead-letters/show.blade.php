@extends('layouts.app')
@section('title', 'Dead letter '.$run->id)

@section('content')
    <div class="mb-6 flex items-center gap-3">
        <h2 class="text-lg font-semibold">Dead letter {{ $run->id }}</h2>
        <span class="rounded-full bg-rose-100 px-2.5 py-1 text-xs font-semibold text-rose-800">{{ $run->outcome_category }}</span>
        <form method="POST" action="{{ route('dlq.replay', $run) }}" class="ml-auto">
            @csrf
            <button class="rounded-lg bg-slate-800 px-3 py-1.5 text-sm font-medium text-white">Replay</button>
        </form>
    </div>

    <dl class="mb-8 grid grid-cols-2 gap-4 rounded-xl border border-slate-200 bg-white p-5 text-sm shadow-sm md:grid-cols-4">
        <div><dt class="text-xs uppercase text-slate-500">Profile</dt>
            <dd class="mt-1"><a class="hover:underline" href="{{ route('profiles.show', $run->profile) }}">{{ $run->profile->username }}</a></dd></div>
        <div><dt class="text-xs uppercase text-slate-500">Preserved likes</dt>
            <dd class="mt-1 tabular-nums">{{ $run->profile->likes === null ? '-' : number_format($run->profile->likes) }}</dd></div>
        <div><dt class="text-xs uppercase text-slate-500">Deliveries / upstream requests</dt>
            <dd class="mt-1 tabular-nums">{{ $run->deliveries }} / {{ $run->requests_used }}</dd></div>
        <div><dt class="text-xs uppercase text-slate-500">Original enqueue (UTC)</dt>
            <dd class="mt-1">{{ $run->enqueued_at->toDateTimeString() }}</dd></div>
        <div><dt class="text-xs uppercase text-slate-500">Resolved by replay</dt>
            <dd class="mt-1">{{ $run->resolved_at?->toDateTimeString() ?? 'not yet' }}</dd></div>
        <div class="col-span-2"><dt class="text-xs uppercase text-slate-500">Reason</dt>
            <dd class="mt-1">{{ $run->outcome_message }}</dd></div>
        <div class="col-span-2"><dt class="text-xs uppercase text-slate-500">failed_jobs uuid</dt>
            <dd class="mt-1 font-mono text-xs">{{ $run->failed_job_uuid ?? 'not linked' }}</dd></div>
    </dl>

    <h3 class="mb-3 font-semibold">Attempt history</h3>
    <div class="mb-8 overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
        <table class="w-full text-left text-sm">
            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
            <tr><th class="px-5 py-2.5">#</th><th class="px-5 py-2.5">HTTP</th><th class="px-5 py-2.5">Category</th>
                <th class="px-5 py-2.5">Duration</th><th class="px-5 py-2.5">Retry-After</th><th class="px-5 py-2.5">Note</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
            @foreach ($run->attempts as $attempt)
                <tr>
                    <td class="px-5 py-3 tabular-nums">{{ $attempt->attempt_no }}</td>
                    <td class="px-5 py-3 tabular-nums">{{ $attempt->http_status ?? '-' }}</td>
                    <td class="px-5 py-3">{{ $attempt->category }}</td>
                    <td class="px-5 py-3 tabular-nums">{{ $attempt->duration_ms }}ms</td>
                    <td class="px-5 py-3 tabular-nums">{{ $attempt->retry_after_seconds ?? '-' }}</td>
                    <td class="px-5 py-3 text-slate-600">{{ $attempt->message ?? '-' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    @if ($run->replays->isNotEmpty())
        <h3 class="mb-3 font-semibold">Replays</h3>
        <ul class="mb-8 list-inside list-disc text-sm">
            @foreach ($run->replays as $replay)
                <li>Run {{ $replay->id }} &mdash; {{ $replay->status->label() }} ({{ $replay->outcome_category ?? 'in flight' }})</li>
            @endforeach
        </ul>
    @endif

    @if ($failedJob)
        <h3 class="mb-3 font-semibold">Framework failure record</h3>
        <pre class="max-h-64 overflow-auto rounded-xl border border-slate-200 bg-white p-4 text-xs">{{ \Illuminate\Support\Str::limit($failedJob->exception, 2000) }}</pre>
    @endif
@endsection
