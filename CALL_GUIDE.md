# Call guide

Everything below was run on this machine. Transcripts are in `evidence/`.

## Before the call

```bash
docker compose up -d              # mysql, redis, fixture, app, horizon, scheduler
docker compose exec app php artisan fans:demo seed
```

Open exactly two browser tabs:

| Tab | URL | What to point at |
| --- | --- | --- |
| App | http://127.0.0.1:8000 | The **Account activity** panel at the top, and the **Last valid likes** column |
| Horizon | http://127.0.0.1:8000/horizon | *Dashboard* for jobs per minute and processes, *Pending Jobs*, *Failed Jobs* |

Use `127.0.0.1`, not `localhost`: Chrome on this machine has a cached redirect
for `localhost:8000` from an earlier project that sends it to `/ka`.

In Horizon, **Dashboard** shows the supervisors and the process count per queue.
`refresh-a` and `refresh-b` each have one process; that is the reserved capacity.
Jobs are tagged `run:<id>`, so a single refresh can be found under *Monitoring*.

## Opening, 60 to 90 seconds

> This app keeps a stored copy of OnlyFans profile data. It pulls a profile
> over HTTP, validates what comes back, and writes it to MySQL. Refreshes run
> as Redis queue jobs under Horizon. There are two live adapters behind one
> interface: a direct signed OnlyFans client, which is the default, and a
> managed provider as the fallback.
>
> What broke was upstream, not us: the provider started returning the likes
> count nested under a `profile` object instead of at the top level, and it
> started returning 429s and empty 500s. The old handler read only the
> top-level key, turned a missing value into zero, and never looked at the HTTP
> status. So it wrote zero and marked every job successful. Monitoring looked
> perfect, because a completed queue job is not the same thing as a valid
> refresh. That is the whole incident.
>
> The fix is that a response has to prove itself before anything is written.
> Both payload shapes are accepted, a missing count is an error while an
> explicit zero is valid data, and any response we cannot validate leaves the
> last good snapshot exactly as it was. The write is one short transaction that
> re-checks the upstream revision under a row lock, so an old response arriving
> late cannot overwrite a newer one.
>
> On retrieval: I implemented the OnlyFans request signing myself, the way the
> web client does it, no browser and no account. As of today a signed anonymous
> request still gets a 400 asking to refresh the page, because OnlyFans issues
> the session on a real page load with a JS challenge, which is the browser
> dependency I was avoiding for memory. So the direct adapter reports that
> cleanly and preserves the last good data, and the managed provider is the
> fallback that returns data. Everything else is measured locally against a
> fixture upstream in its own container.

## Five-minute demo

Run these from the repo root. Each one prints a table; nothing needs SQL.

**1. The last valid data.**
```bash
docker compose exec app php artisan fans:demo seed
docker compose exec app php artisan fans:demo state
```
Point at `likes = 120000`, `revision = 9` for every profile.

**2. The broken handler, rerun.**
```bash
docker compose exec app php artisan fans:demo broken
```
Three of four cases are overwritten with `0`, the old-format case never
advances its revision, and all four get a `last success` timestamp. It exits 1
on purpose: the run is supposed to fail its expectation.
Test: `tests/Feature/ResponseFormatTest.php`, `tests/Unit/CountValueTest.php`.

**3. The fixed path, same four inputs.**
```bash
docker compose exec app php artisan fans:demo fixed
```
New nested format applies 121000 at revision 11, old format applies at
revision 10, and the 429 and the empty 500 both keep 120000 at revision 9 with
no `last success`.
Test: `tests/Feature/FailurePreservesDataTest.php`.

**4. A late old response, and a replayed run.**
Both are covered by tests that read better than a live demo:
```bash
docker compose exec app ./vendor/bin/phpunit --filter 'late_older_revision|replaying_a_committed_run|concurrent_older_revision' --testdox
```
- revision 10 arriving after revision 11 is recorded as `stale_ignored` and
  changes nothing;
- the same logical run delivered twice after its commit produces no second
  success and no timestamp change;
- the concurrent version runs the same race across two real processes and two
  MySQL connections.
Tests: `tests/Feature/DataProtectionTest.php`, `tests/Feature/ConcurrentCommitTest.php`.

**5. Queues, isolation and recovery.**
Show the saved evidence first, then the live panel if there is time.
```bash
cat evidence/workload-broken.txt
cat evidence/workload-fixed.txt
```
Then, with the app tab open, start a run and watch it:
```bash
docker compose exec app php artisan fans:demo workload --mode=fixed
```
In the panel: account A's *ready/delayed* column fills and its *retries* climb
while account B keeps recording *valid refreshes* with an oldest wait of zero.
After the outage ends, A drains and its valid refreshes reach 12. In Horizon,
`refresh-a` shows the retries and `refresh-b` stays quiet.

**If time remains**, the dead letter:
```bash
docker compose exec app php artisan fans:demo dlq-demo
```
Permanent throttling exhausts the request budget, the run is dead-lettered into
`failed_jobs` with the profile's 120000 preserved, the fixture is repaired, one
replay is queued, a second concurrent replay request is refused, and the replay
commits 121000 at revision 11. The original stays dead-lettered and is marked
resolved. Visible at http://127.0.0.1:8000/dead-letters.

## File map

The request path is: **UI, CLI or scheduler → `RefreshDispatcher` → Redis →
`RefreshProfileJob` → `ClientFactory` → `ProfileNormalizer` → `ProfileWriter` → MySQL.**

| File | One sentence |
| --- | --- |
| `app/Jobs/RefreshProfileJob.php` | The whole queue outcome: budgets, admission, one HTTP call, one write, one release-or-fail decision. |
| `app/Refresh/RefreshDispatcher.php` | Takes the durable claim under a row lock, pushes to Redis after commit, and recovers claims that were never delivered. |
| `app/Refresh/Clients/BoundedHttpClient.php` | Streams the body with a 1 MiB cap applied while it arrives, and maps status codes to outcome categories. |
| `app/Refresh/ProfileNormalizer.php` | The accepted response shapes and the field rules, including `favoritedCount` as received likes. |
| `app/Refresh/CountValue.php` | The explicit likes/revision rule: what counts as a number and what does not. |
| `app/Refresh/ProfileWriter.php` | The only writer of accepted data: one short transaction, revision re-checked under the lock, idempotent completion. |
| `app/Refresh/DeadLetters.php` | The dead-letter list over `failed_jobs`, and the replay action that keeps the original failure. |
| `app/Enums/RunStatus.php` | The run lifecycle as a backed enum, cast on the model, with the terminal/committed/active sets. |
| `app/Listeners/LinkDeadLetterToRun.php` | On `JobFailed`, links the framework failure record to the refresh run. |
| `app/Console/Commands/DispatchDueCommand.php` | The every-minute scheduler entry that enqueues due profiles. |
| `app/Demo/BrokenRefreshJob.php` | The pre-fix handler, quarantined in the demo namespace. |
| `config/horizon.php` | Two reserved supervisors plus the baseline supervisor, with the timeout ordering spelled out. |
| `config/fansapi.php` | Every timeout, budget and interval the README quotes. |
| `tests/Feature/DataProtectionTest.php` | Uniqueness, revision ordering, replay after commit, and the 100000 boundary. |
| `tests/Feature/ConcurrentCommitTest.php` | The same ordering rule under real two-process contention. |

## Likely questions

**Why did it break with no deployment of ours?**
The upstream contract changed: the payload shape moved and new status codes
appeared. Our code was unchanged; its assumptions stopped being true.

**Why is zero different from missing?**
Zero is a real measurement the upstream asserted. Missing means the response
did not tell us, and guessing zero is how 120000 became 0.

**Unique jobs would have prevented the duplicates, wouldn't they?**
A scheduling lock stops two jobs existing. It says nothing about SQL identity,
about which upstream revision is newer, or about a worker that committed and
then died before acknowledging. Those need a unique index, a revision check
under a row lock, and a durable run id, which is what is here.

**How do you stop an old response overwriting a new one?**
The write re-reads the accepted revision under `SELECT ... FOR UPDATE` and
refuses anything older. That is independent of queue delivery order.

**What if a worker dies after the commit?**
The run row is already terminal, so the redelivered job returns immediately.
No second success, no timestamp change. `tests/Feature/DataProtectionTest.php`
covers exactly that boundary, and nothing wider.

**What protects the other account?**
One reserved Horizon process per account, so a burst on A cannot consume B's
capacity, plus an account cooldown after a throttle so A stops hammering the
upstream. The honest limit: the provider bills a quota per workspace shared by
every key, so if that is exhausted, isolation does not help.

**Why those timeout numbers?**
Job timeout 20s, Horizon supervisor timeout 30s, Redis `retry_after` 60s. Each
is above the one it supervises, so a normal attempt can never have its
reservation expire while it is still running, and a hung attempt is killed long
before Redis would hand it to a second worker.

**What is the dead-letter queue here?**
Laravel's `failed_jobs` table, linked to the refresh run that produced it. The
reason, the attempt history and the preserved data are all still there, and
replay is a domain action, not `queue:retry`, so the record is not deleted.

**Why is memory low?**
Small jobs carrying one integer, a 1 MiB cap enforced while the body arrives,
a single decode, batched queries limited to scheduling columns, and no browser
or media pipeline. Measured: PHP peak 18 MiB, worker RSS flat at 72.5 MiB
across 119 jobs on a second pass over ~880 KB stored snapshots, with no
measurable drift.

**What fails first at scale?**
Upstream quota, then network-bound worker capacity, then SQL write and history
growth. See the README for what to measure before changing anything.

## New symptom checklist

1. Start from stored data, not from the queue: is the last valid value still
   there, and when was the last successful refresh?
2. Split by account and by outcome category. One account failing and others
   fine is a credential or quota problem; everything failing at once is
   upstream or transport.
3. Look at queue age from original enqueue, and at retries per successful
   refresh. Rising age with flat retries is capacity; flat age with rising
   retries is upstream.
4. Compare the upstream response shape and status against what the normalizer
   accepts. A jump in `schema_failure` means the contract moved.
5. Check acceptance reasons in the runs table: `stale_response_ignored` points
   at ordering, `identity_mismatch` at the wrong profile, lock waits at
   contention.
6. Form one hypothesis and test it against a single profile before changing
   anything.

Transport problems show up as `timeout`, `network_error` and 5xx with no body.
Validation problems arrive as clean 200s that are rejected. Persistence
problems show accepted responses that do not change stored values. Capacity
problems show a growing oldest-waiting age with a normal outcome mix.

## Honest limitations

- Live retrieval works and is recorded in `evidence/live-retrieval.json`, but
  only a couple of live calls were made. The provider's failure modes are
  modelled with the fixture, not observed.
- The provider exposes no version field, so live work has no upstream revision.
  Ordering for live refreshes relies on the run lease and idempotency. The
  revision ordering proofs use fixtures, which is where a revision exists.
- The crash test covers redelivery after a database commit. It does not cover
  OS-level kills, reservation expiry, or partial writes.
- Two workers on one machine. Nothing here demonstrates production scale.
- If anything live fails during the call, use `evidence/`; every number quoted
  above is in there with its command and exit status.
