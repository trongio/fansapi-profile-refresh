@extends('layouts.app')
@section('title', 'Dead letters')

@section('content')
    <h2 class="mb-1 text-lg font-semibold">Dead letters</h2>
    <p class="mb-6 text-sm text-slate-600">
        Terminal work stored in Laravel's <code>failed_jobs</code> table, joined to the refresh run that produced it.
        Accepted profile data is preserved; replay creates a new linked run through the ordinary dispatch path.
    </p>

    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
        <table class="w-full text-left text-sm">
            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
            <tr>
                <th class="px-5 py-2.5">Run</th><th class="px-5 py-2.5">Account</th><th class="px-5 py-2.5">Profile</th>
                <th class="px-5 py-2.5">Reason</th><th class="px-5 py-2.5">Preserved likes</th>
                <th class="px-5 py-2.5">Last success</th><th class="px-5 py-2.5"></th>
            </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
            @forelse ($runs as $run)
                <tr>
                    <td class="px-5 py-3 tabular-nums">
                        <a class="font-medium hover:underline" href="{{ route('dlq.show', $run) }}">{{ $run->id }}</a>
                    </td>
                    <td class="px-5 py-3">{{ $run->account->key }}</td>
                    <td class="px-5 py-3">{{ $run->profile->username }}</td>
                    <td class="px-5 py-3">
                        <span class="rounded-full bg-rose-100 px-2 py-0.5 text-xs font-semibold text-rose-800">{{ $run->outcome_category }}</span>
                        @if ($run->resolved_at)
                            <span class="ml-1 rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-800">resolved by replay</span>
                        @endif
                    </td>
                    <td class="px-5 py-3 tabular-nums">{{ $run->profile->likes === null ? '-' : number_format($run->profile->likes) }}</td>
                    <td class="px-5 py-3 text-slate-600">{{ $run->profile->last_success_at?->toDateTimeString() ?? 'never' }}</td>
                    <td class="px-5 py-3 text-right">
                        <form method="POST" action="{{ route('dlq.replay', $run) }}">
                            @csrf
                            <button class="rounded-lg border border-slate-300 px-3 py-1 text-xs font-medium hover:bg-slate-50">Replay</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td class="px-5 py-6 text-slate-400" colspan="7">No dead letters.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $runs->links() }}</div>
@endsection
