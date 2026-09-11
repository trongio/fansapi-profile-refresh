<?php

namespace App\Http\Controllers;

use App\Models\RefreshRun;
use App\Refresh\DeadLetters;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class DeadLetterController extends Controller
{
    public function index(DeadLetters $dlq): View
    {
        return view('dead-letters.index', [
            'runs' => $dlq->paginate((int) config('fansapi.ui.per_page')),
        ]);
    }

    public function show(RefreshRun $run, DeadLetters $dlq): View
    {
        return view('dead-letters.show', [
            'run' => $run->load(['profile.account', 'attempts', 'replays']),
            'failedJob' => $dlq->failedJob($run->failed_job_uuid),
        ]);
    }

    /**
     * Replay is a domain action, not queue:retry: the original failure record
     * stays, and the new run goes through the ordinary dispatch path.
     */
    public function replay(RefreshRun $run, DeadLetters $dlq): RedirectResponse
    {
        $result = $dlq->replay($run);

        return back()->with('status', $result['run'] === null
            ? 'No replay created: '.$result['reason']
            : ($result['reason'] ?? "Replay run {$result['run']->id} queued."));
    }
}
