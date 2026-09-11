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
9. **A long-lived worker kept running old code.** A memory run was measured
   against a stale worker and produced numbers that contradicted the code.
   Fix: the evidence script runs `horizon:terminate` before measuring.

## What remains unverified

- **The provider's own reliability and quota behaviour.** One live call was
  made per verification, and a second during the demo. The workspace rate-limit
  path has not been exercised against a real 429 from the provider; it is
  modelled with the fixture.
- **`favoritedCount` as "received likes".** It is 605,782 for this profile
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
