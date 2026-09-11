<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An ACCOUNT is the upstream access context (credentials, quota, queue).
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('key', 32)->unique();          // "A", "B"
            $table->string('label');
            $table->string('queue', 64);                  // reserved Horizon queue
            $table->string('source', 16)->default('fixture');
            $table->string('workspace', 64)->default('default'); // shared provider quota scope
            $table->timestamp('cooldown_until')->nullable();
            $table->timestamps();
        });

        // A PROFILE is a refresh target. One busy account owns many profiles.
        Schema::create('profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('username', 128);
            $table->string('upstream_id', 64)->nullable(); // stable provider id once discovered
            $table->string('display_name')->nullable();

            // Last ACCEPTED values. Never touched by a rejected response.
            $table->unsignedBigInteger('likes')->nullable();
            $table->unsignedBigInteger('revision')->nullable();
            $table->json('snapshot')->nullable();          // bounded, validated metadata
            $table->string('snapshot_source', 16)->nullable();
            $table->boolean('snapshot_from_cache')->default(false);

            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->string('last_failure_category', 48)->nullable();
            $table->string('last_failure_message', 255)->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->timestamp('verified_unchanged_at')->nullable();
            $table->unsignedInteger('verified_unchanged_count')->default(0);

            // Pending pointer: which logical run currently owns this profile.
            $table->unsignedBigInteger('pending_run_id')->nullable();
            $table->timestamp('next_refresh_at')->nullable();
            // Set when a run ends terminally, so the scheduler stops re-queuing it.
            $table->timestamp('terminal_failed_at')->nullable();

            $table->timestamps();

            // SQL-enforced identity. Both scoped to the account.
            $table->unique(['account_id', 'username']);
            $table->unique(['account_id', 'upstream_id']);
            // Due-selection index: cheap bounded scan of schedulable rows.
            $table->index(['terminal_failed_at', 'pending_run_id', 'next_refresh_at'], 'profiles_due_idx');
        });

        // A REFRESH RUN is the durable logical unit of work: idempotency key,
        // metrics row, recovery record and replay link, all in one place.
        Schema::create('refresh_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();

            // queued | running | succeeded | verified_unchanged | failed | dead_lettered
            $table->string('status', 24)->default('queued');
            $table->string('outcome_category', 48)->nullable();
            $table->string('outcome_message', 255)->nullable();
            $table->string('trigger', 24)->default('schedule'); // schedule|ui|cli|replay

            $table->timestamp('enqueued_at');                   // ORIGINAL enqueue, kept across releases
            $table->timestamp('deadline_at');
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('available_at')->nullable();       // when a delayed release becomes ready

            $table->unsignedInteger('requests_used')->default(0);   // real upstream requests
            $table->unsignedInteger('deliveries')->default(0);      // queue deliveries
            $table->unsignedInteger('admission_deferrals')->default(0); // cooldown/lease deferrals

            $table->unsignedBigInteger('received_revision')->nullable();
            $table->unsignedBigInteger('accepted_revision')->nullable();

            $table->uuid('claim_token');                        // survives worker death
            $table->string('failed_job_uuid', 36)->nullable();  // link into failed_jobs (DLQ)
            $table->foreignId('replay_of_run_id')->nullable()->constrained('refresh_runs')->nullOnDelete();
            // Set when a linked replay finishes. The original stays dead-lettered:
            // queuing a replay is not the same as resolving the failure.
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'account_id']);
            $table->index(['profile_id', 'status']);
            $table->index('enqueued_at');
        });

        // Compact per-request history. Bounded by the HTTP budget, not by retries.
        Schema::create('refresh_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('refresh_run_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt_no');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('category', 48);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->unsignedInteger('retry_after_seconds')->nullable();
            $table->unsignedBigInteger('received_revision')->nullable();
            $table->string('message', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['refresh_run_id', 'attempt_no']);
        });

        Schema::table('profiles', function (Blueprint $table) {
            $table->foreign('pending_run_id')->references('id')->on('refresh_runs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('profiles', fn (Blueprint $t) => $t->dropForeign(['pending_run_id']));
        Schema::dropIfExists('refresh_attempts');
        Schema::dropIfExists('refresh_runs');
        Schema::dropIfExists('profiles');
        Schema::dropIfExists('accounts');
    }
};
