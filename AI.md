# AI use on this assignment

## What was actually used

- **Claude (Anthropic) in an agentic CLI**, running on my Ubuntu machine with
  shell, file and Docker access. It wrote the code, ran it, and iterated on
  failures. Everything in `evidence/` is real output from real runs on this
  machine, captured by `scripts/capture-evidence.sh`.
- No other AI tool produced code in this repository.

## What was checked rather than assumed

- **The live endpoint was called before any code was written.** The first
  action was a plain `curl` to
  `https://app.onlyfansapi.com/api/profiles/madison420ivy?fresh=true`, which
  returned HTTP 200 and 4,448 bytes. The field list in `ProfileNormalizer` is
  derived from that actual response, not from documentation.
- **Package versions were resolved, not guessed.** Laravel 13.31.0, Horizon
  5.49.0 (6.x has no stable release), Scout 11.7.0, PHPUnit 12.5.35. See
  `composer.lock` and `evidence/environment.txt`.
- **Horizon's `retryUntil` precedence was read in the installed source**, not
  recalled: when a job defines `retryUntil()`, Laravel stops enforcing `$tries`.
  That is why `RefreshProfileJob` defines no `retryUntil()` and enforces the
  deadline and the HTTP budget itself.
- **The memory numbers come from inside the worker process** (`/proc/self/statm`
  plus PHP's own counters), not from `docker stats`. Compose-level usage is
  recorded separately in `evidence/compose-usage.txt`.

## Mistakes actually caught while building

These were real defects found by running the thing, not hypotheticals:

1. **Containers captured an empty `APP_KEY`.** `env_file: [.env]` is read by
   Compose when the container is created, which was before `setup` generated
   the key, and the empty variable then shadowed the correct value in `.env`.
   Symptom: every HTTP test failed with `MissingAppKeyException`. Fix: the PHP
   services no longer use `env_file` and read the bind-mounted `.env` directly.
2. **Tests leaked queued jobs between cases.** `RefreshDatabase` rolls back SQL
   but not Redis, so a job left queued by one test was popped by the next and a
   scheduling assertion failed for the wrong reason. Fix: `Tests\TestCase`
   clears this app's own keys on the dedicated test Redis databases.
3. **The scheduler was polluting the measured workload.** Seeded profiles were
   immediately due, so `schedule:work` enqueued extra runs mid-measurement and
   account A reported 16 runs instead of 12. Fix: seeded profiles are not due.
4. **The workspace cooldown was stalling the healthy account.** A fixture
   outage on account A was pausing account B, which would have made the
   isolation result look worse than it is for the wrong reason. The shared
   workspace quota is a property of the managed provider, so that cooldown now
   applies only to provider-backed accounts.
5. **A second, undocumented size cap.** The normalizer rejected snapshots over
   256 KiB while the README described a single 1 MiB limit, so valid 900 KB
   responses in the memory scenario failed. Fix: one documented limit, enforced
   on the wire.
6. **Helper containers wrote root-owned files into the bind mount.** The
   Composer and Node steps ran as root, so `vendor`, `node_modules`,
   `package-lock.json` and compiled views became undeletable from the host and
   a clean rebuild failed with `EACCES`. Found by actually tearing the stack
   down and bringing it back up. Fix: every service runs as the host uid:gid
   (`DOCKER_USER` in `.env`).
7. **The concurrency test failed on a brand-new test database.** It does not
   use `RefreshDatabase`, so nothing had migrated when it ran first in the
   suite. It passed in isolation and on a warm database, which is exactly the
   kind of failure a reviewer hits on first run. Fix: it migrates in `setUp`.
8. **Horizon's baseline supervisor ran one worker, not two.** With balancing
   off, Horizon starts `floor((minProcesses + maxProcesses) / 2)` workers, so
   `maxProcesses: 2` alone gave one. The broken-mode comparison would have
   quietly used half the capacity it claimed. Found by counting the actual
   processes in the container rather than trusting the config. Fix: both bounds
   are pinned, and the workload evidence was regenerated.
9. **Service-location cycle.** `ProfileWriter` and `RunRecorder` reached for
   `app(DeadLetters::class)` because injecting it would have created a cycle
   (`DeadLetters -> RefreshDispatcher -> RunRecorder -> DeadLetters`). Caught in
   the paradigm review; the replay-resolution step moved onto the `RefreshRun`
   model, and the remaining dependencies are constructor-injected.
10. **`abort_unless()` inside a queue job.** It throws an HTTP exception, which
    means nothing in a worker. Replaced with a plain `RuntimeException`.
11. **Listeners registered twice.** Laravel discovers classes in
    `app/Listeners` from their `handle()` type hints, and the provider also
    registered them with `Event::listen`, so the memory probe wrote two
    samples per job (250 for 125 deliveries) and the dead-letter linker ran
    twice. Found because the sample count did not match the delivery count.
    Fix: the provider registers nothing; `event:list` confirms one entry each.
12. **A memory change that measured nothing.** Loading the profile without
    its snapshot in the job is the right shape, but an A/B on a second pass
    with ~880 KB stored snapshots showed the same 18 MiB peak either way. The
    writer still loads the snapshot under its lock, and the peak counter
    reports in 2 MiB pages. Recorded as "kept, no measurable effect" rather
    than claimed as an improvement.
13. **A long-lived worker kept running old code.** A memory run was measured
   against a stale worker and produced numbers that contradicted the code.
   Fix: the evidence script runs `horizon:terminate` before measuring.

## Review pass after the first submission

A second pass checked the code against ordinary Laravel conventions and made
these changes, all covered by the test suite (now 62 tests):

- run status is a backed enum (`App\Enums\RunStatus`) cast on the model,
  instead of string constants;
- factories for `Account` and `Profile`, used by every test;
- the `Queue::failing` closure became `App\Listeners\LinkDeadLetterToRun`;
- the scheduler runs `fans:dispatch-due` and `fans:reconcile` commands rather
  than closures;
- the `due` scope uses the `#[Scope]` attribute;
- Laravel Pint was run.

The same pass drove the UI with Playwright (`evidence/browser-run.txt`) and
scanned every tracked file for prompt-injection content: instruction-like
text aimed at an AI, zero-width or bidirectional Unicode, hidden HTML. Nothing
was found. Upstream profile text (the `about` field, for instance) is stored in
the JSON snapshot and is never rendered by any view; if it ever were, Blade's
escaping applies.

## Direct OnlyFans access, added after the follow-up

The reviewer's follow-up framed OnlyFans itself as the upstream and said no
account is needed. A direct adapter was added and made the default live source:
`OnlyfansSigner` reproduces the web client's request signing, and
`OnlyfansDirectClient` sends a signed anonymous request with no browser and no
account.

The first version consumed community-published rules; they lagged a web build
rotation and every request was rejected (`evidence/direct-route-diagnosis.md`).
That feed was removed. The rules are now extracted locally: the internal
`services/rulegen` container fetches the current build's signing chunk and runs
it in a killable, permission-restricted child process to recover the constants
(method adapted from mikigoalie/onlyfans-rulegen, MIT). PHP recomputes 8 proof
signs from the real extracted function, runs one live canary, and only then
activates the rules as last-known-good in Redis.

Verified on this machine: the extractor recovers the exact constants of a
synthetic chunk and of two archived real chunks; the PHP signer matches the
Node reference on shared vectors; the container isolation holds (no host port,
no route to MySQL/Redis, read-only root, no capabilities). Live, on
2026-09-19: build `202609171554-a5a528bc87` was extracted, proved 8/8 and
canaried, and a real background refresh of `madison420ivy` returned HTTP 200
with 606,831 likes in one upstream request (`evidence/direct-route-live.txt`).
Later additions, all tested: a delegated signer for builds that change the
formula itself (the build's own function signs, via rulegen, canary
required), signers stored per build, a live canary every 5 minutes on
madison420ivy, automatic replay of held `signature_rejected` runs after a new
build activates, and rejection/recovery metrics. The delegated path is
proven on synthetic chunks only; no live build has needed it yet.
The first live run exposed two bugs, both fixed with tests: the app shell
contains a "Just a moment" placeholder that was misread as a challenge, and a
random `x-bc` left in Redis by the old code was never replaced.

## What remains unverified

- **The provider's own reliability and quota behaviour.** One live call was
  made per verification, and a second during the demo. The workspace rate-limit
  path has not been exercised against a real 429 from the provider; it is
  modelled with the fixture.
- **`favoritedCount` as "received likes".** It is ~605,800 for this profile
  while `favoritesCount` is 16, which is only consistent with one reading, and
  both fields are retained. It has not been confirmed with the provider.
- **Crash semantics beyond the commit boundary.** The replay test proves
  idempotency when the same logical run is delivered again after its database
  commit. It does not prove behaviour under OS-level termination, Redis
  reservation expiry, or a partial network write.
- **Scale.** Everything here was measured on one machine with two workers.
  Nothing in this repository demonstrates behaviour at 50 million jobs/day.

This file describes tooling. It is not evidence that the application works;
that is `evidence/` and the test suite.
