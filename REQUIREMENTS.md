# Requirements to evidence

Every requested behaviour, and where to verify it.

## Live retrieval

| Requirement | Where |
| --- | --- |
| Managed endpoint called with bearer auth and `fresh=true` | `app/Refresh/Clients/OfapiProfileClient.php` |
| Actual profile JSON obtained | `evidence/live-retrieval.json`, README "Live retrieval" |
| Client behind a small interface, one live and one fixture adapter | `app/Refresh/Clients/ProfileClient.php`, `ClientFactory.php` |
| Full metadata preserved in one bounded validated snapshot | `ProfileNormalizer::boundSnapshot()`, `profiles.snapshot` |
| `favoritedCount` used as received likes, `favoritesCount` retained but unused | `ProfileNormalizer::normalizeProvider()`, `ResponseFormatTest::the_provider_envelope_maps_favorited_count_to_likes` |
| Missing subscriber count stays null | README "Live retrieval" |
| Credits and credentials kept out of logs | `boundSnapshot()` strips `_meta`, `RefreshLogger` allow-lists context |
| No invented live revision | `NormalizedProfile->revision` is null for the provider; README and `AI.md` |
| Managed provider disclosed | README "Live retrieval" |

## The reproduced bug

| Requirement | Where |
| --- | --- |
| Seeded at last valid likes 120000 | `DemoData::SEED_LIKES` |
| Both fixture payloads plus 429 without `Retry-After` and empty 500 | `FixtureScenario::incidentCases()` |
| Broken handler reads top-level likes only, defaults to zero, always succeeds | `app/Demo/BrokenProfileHandler.php`, `BrokenRefreshJob.php` |
| Failing reproduction output saved, all four cases | `evidence/reproduction-broken.txt` (exit 1) |
| Normal suite passes while the reproduction stays rerunnable | `evidence/tests.txt` (62 pass at submission; the suite is 96 now), `fans:demo broken` |
| Broken handler confined to the demo namespace | `app/Demo/`, guarded by `abort_unless(app()->environment(...))` |

## Data protection

| Requirement | Where |
| --- | --- |
| Both formats accepted | `ResponseFormatTest` |
| Missing likes invalid, explicit zero valid | `ResponseFormatTest::explicit_zero_likes_is_valid_but_missing_likes_is_not` |
| Negative, non-numeric, boolean, null, fractional, overflow rejected | `tests/Unit/CountValueTest.php` (17 cases) |
| Numeric-string acceptance defined explicitly | `app/Refresh/CountValue.php` docblock |
| Disagreeing locations rejected | `ResponseFormatTest::disagreeing_locations_are_rejected` |
| Every failure preserves the snapshot and last success | `FailurePreservesDataTest` (7 cases) |
| Profile uniqueness in SQL | migration unique indexes, `DataProtectionTest::profile_identity_is_unique_in_sql` |
| Revision checked and written atomically | `ProfileWriter::commit()` |
| Revision 10 after 11 cannot overwrite | `DataProtectionTest`, `ConcurrentCommitTest` (two processes) |
| Replay after commit does nothing | `DataProtectionTest::replaying_a_committed_run_changes_nothing` |
| Equal revision, conflicting content invalid | `DataProtectionTest::an_equal_revision_with_different_content_is_rejected` |
| `verified_unchanged` recorded once, separate from a data update | `DataProtectionTest`, `profiles.verified_unchanged_count` |
| Late run cannot clear a newer pending pointer | `DataProtectionTest::a_late_run_cannot_clear_a_newer_runs_pending_pointer` |
| 24h above 100000, 72h at or below, exactly 100000 tested | `ProfileWriter::nextRefreshAt()`, `DataProtectionTest` |
| Bounded indexed due-selection | `Profile::due()` scope, `profiles_due_idx`, `fans:dispatch-due`, `SchedulingTest` |
| Repeated/concurrent scheduling creates no duplicate work | `SchedulingTest::repeated_scheduling_does_not_create_work_that_is_already_pending` |
| Terminal failure not rescheduled every minute | `SchedulingTest::an_unresolved_terminal_failure_is_not_rescheduled_every_minute` |
| Abandoned claim recovered under the same run id | `SchedulingTest::an_abandoned_claim_is_recovered_under_the_same_logical_run_id` |
| Reconciliation respects delayed work | `SchedulingTest::reconciliation_leaves_delayed_and_recently_touched_work_alone` |
| Scout database engine, one verified query | `Profile::toSearchableArray()`, `InterfaceTest::scout_searches_profiles_through_the_database_engine` |

## Queues, retries, isolation

| Requirement | Where |
| --- | --- |
| All working refreshes go through Redis under Horizon | `RefreshDispatcher::push()`, `config/horizon.php` |
| Configured timeouts documented and validated | README table, `config/fansapi.php`, `config/horizon.php`, `config/queue.php` |
| `Retry-After` in seconds or HTTP-date, never shortened | `BoundedHttpClient::retryAfter()`, `FailurePreservesDataTest::a_valid_retry_after_header_is_respected_exactly` |
| Backoff with jitter, release rather than sleep | `RefreshProfileJob::backoffSeconds()`, `releaseRun()` |
| Deliveries, admission deferrals and upstream requests counted separately | `refresh_runs.deliveries`, `.admission_deferrals`, `.requests_used`; `DeadLetterReplayTest::the_http_budget_bounds_the_real_upstream_requests` |
| `retryUntil` precedence handled, not assumed | `RefreshProfileJob` docblock, `AI.md` |
| Per-account reserved workers, two total | `config/horizon.php` supervisors |
| Account cooldown, monotonic | `Admission::coolDownAccount()` (SQL `GREATEST`) |
| Workspace-scoped admission for the shared provider quota | `Admission::coolDownWorkspace()` (Redis Lua compare-and-set) |
| Overlap lease shared across workers, ownership-safe release | `RefreshProfileJob` `Cache::lock` + `finally` |

## Dead letters

| Requirement | Where |
| --- | --- |
| Laravel `failed_jobs` used as the DLQ, linked to the run | `Listeners\LinkDeadLetterToRun`, `DeadLetters::record()`, `refresh_runs.failed_job_uuid` |
| Paginated CLI and UI inspection | `fans:demo dlq`, `/dead-letters` |
| Terminal failure preserves data and reconciles pending state | `DeadLetterReplayTest::an_exhausted_run_becomes_a_dead_letter_with_the_data_preserved` |
| Replay is bounded and goes through the normal path | `DeadLetters::replay()` |
| Concurrent replay creates no duplicate work | `DeadLetterReplayTest::a_repeated_replay_request_does_not_create_duplicate_active_work` |
| Original failure preserved, resolved only when the replay finishes | `refresh_runs.resolved_at`, `RefreshRun::resolveReplayedOriginal()` |
| Full cycle demonstrated | `evidence/dlq-demo.txt` |

## Memory

| Requirement | Where |
| --- | --- |
| Jobs carry ids only | `RefreshProfileJob::__construct(int $runId)` |
| 1 MiB cap applied while the body arrives | `BoundedHttpClient::readBounded()`, `FailurePreservesDataTest::a_body_above_the_cap_is_rejected_while_it_is_still_arriving` |
| Decoded once, resources closed on all paths | `BoundedHttpClient::get()`, `finally` block |
| Bounded queries, at most 100 rows, scheduling columns only | `RefreshDispatcher::dispatchDue()` |
| 25-row UI pages | `config('fansapi.ui.per_page')` |
| 128M PHP limit, 96 MiB worker recycling | `docker/php.ini`, `config/horizon.php` |
| 100-job scenario with PID, restarts, PHP peak and RSS | `evidence/memory.txt`, `evidence/memory.json` |
| Compose usage recorded separately | `evidence/compose-usage.txt` |

## Workload and evidence

| Requirement | Where |
| --- | --- |
| One command, `--mode=broken\|fixed`, identical inputs | `fans:demo workload`, `app/Demo/Workload.php` |
| 12 A + 4 B, B at 1/4/7/10s, 15s outage, one empty 500 after it | `Workload::scenario()` |
| Concurrent fixture server | `fixture-server/router.php` with `PHP_CLI_SERVER_WORKERS` |
| Scenario clock starts only when ready | `Workload::run()` |
| Valid refreshes separated from false successes | `Workload::accountResult()`, `QueueStats::accountRow()` |
| Attempts per successful refresh, undefined if none | same |
| Waiting age from original enqueue, delayed work included | `RefreshRun::waitingSeconds()`, `refresh_runs.enqueued_at` |
| Ready/delayed/reserved reported separately, peak and final | `QueueStats::depth()`, evidence JSON `samples` |
| Healthy-account progress and recovery asserted | `evidence/workload-fixed.json` `stored_data` |
| Structured logs with correlated ids and distinct events | `app/Refresh/RefreshLogger.php` |
| Sanitized evidence with commands, exit status, elapsed | `scripts/capture-evidence.sh`, `evidence/*.txt` |

## Interface

| Requirement | Where |
| --- | --- |
| Activity panel, profile list/detail, queued refresh, DLQ with replay | `resources/views/` |
| LIVE vs FIXTURE label | `resources/views/layouts/app.blade.php` |
| Escaped output, POST plus CSRF, backend deduplication, local only | `InterfaceTest`, `app/Http/Middleware/EnsureLocalDemo.php` |
| Web actions enqueue only | `InterfaceTest::the_refresh_action_only_enqueues_and_never_calls_the_upstream`, `evidence/browser-run.txt` |
| Horizon as the operational view, jobs tagged | `RefreshProfileJob::tags()` |
| ~3s bounded polling with an updated-at indicator | `resources/views/partials/activity.blade.php` |
| Last valid likes visible while retrying | `resources/views/profiles/index.blade.php` |
| Stale leases surfaced | `QueueStats::staleLeases()` |
