<?php

namespace App\Http\Controllers;

use App\Models\Profile;
use App\Refresh\RefreshDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /** Scout's database engine searches SQL directly; there is no index worker. */
    public function index(Request $request): View
    {
        $term = trim((string) $request->query('q'));

        $query = $term !== ''
            ? Profile::search($term)->query(fn ($q) => $q->with('account:id,key,label'))
            : Profile::query()->with('account:id,key,label')->orderBy('id');

        return view('profiles.index', [
            // Bounded columns only: no snapshots are loaded into a list page.
            'profiles' => $query->paginate((int) config('fansapi.ui.per_page'))->withQueryString(),
            'term' => $term,
        ]);
    }

    public function show(Profile $profile): View
    {
        return view('profiles.show', [
            'profile' => $profile->load('account'),
            'runs' => $profile->runs()->with('attempts')->orderByDesc('id')->limit(10)->get(),
        ]);
    }

    /** Web actions only ENQUEUE. They never talk to the upstream in-request. */
    public function refresh(Profile $profile, RefreshDispatcher $dispatcher): RedirectResponse
    {
        $run = $dispatcher->enqueue($profile, 'ui');

        return back()->with('status', $run === null
            ? "A refresh for {$profile->username} is already pending."
            : "Refresh queued for {$profile->username} (run {$run->id}).");
    }
}
