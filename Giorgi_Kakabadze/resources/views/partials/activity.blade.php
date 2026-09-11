{{--
  Per-account activity panel. Polls /activity.json every few seconds; the
  endpoint aggregates in SQL and asks Redis only for queue sizes.
--}}
<section class="mb-8 rounded-xl border border-slate-200 bg-white shadow-sm" data-activity>
    <div class="flex items-center gap-3 border-b border-slate-200 px-5 py-3">
        <h2 class="font-semibold">Account activity</h2>
        <span class="text-xs text-slate-500" data-activity-updated>loading&hellip;</span>
        <span class="ml-auto hidden rounded-full bg-rose-100 px-2.5 py-1 text-xs font-semibold text-rose-800" data-activity-stale></span>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
            <tr>
                <th class="px-5 py-2.5">Account</th>
                <th class="px-5 py-2.5">Ready / delayed / in progress</th>
                <th class="px-5 py-2.5">Valid refreshes</th>
                <th class="px-5 py-2.5">Retries / dead letters</th>
                <th class="px-5 py-2.5">Oldest waiting</th>
                <th class="px-5 py-2.5">Latest outcome</th>
            </tr>
            </thead>
            <tbody data-activity-rows class="divide-y divide-slate-100">
            <tr><td class="px-5 py-4 text-slate-400" colspan="6">Waiting for the first poll&hellip;</td></tr>
            </tbody>
        </table>
    </div>
</section>

<script>
(() => {
    const POLL_MS = {{ (int) config('fansapi.ui.poll_ms') }};
    const rows = document.querySelector('[data-activity-rows]');
    const updated = document.querySelector('[data-activity-updated]');
    const stale = document.querySelector('[data-activity-stale]');

    // queued / running / retrying / refreshed / dead-lettered, as readable chips.
    const badge = (text, tone) =>
        `<span class="rounded-full px-2 py-0.5 text-xs font-semibold ${tone}">${text}</span>`;

    const toneFor = (status) => ({
        queued: 'bg-slate-100 text-slate-700',
        running: 'bg-sky-100 text-sky-800',
        succeeded: 'bg-emerald-100 text-emerald-800',
        verified_unchanged: 'bg-emerald-50 text-emerald-700',
        stale_ignored: 'bg-amber-100 text-amber-800',
        failed: 'bg-rose-100 text-rose-800',
        dead_lettered: 'bg-rose-200 text-rose-900',
    }[status] || 'bg-slate-100 text-slate-700');

    const escape = (value) =>
        String(value ?? '-').replace(/[&<>"']/g, (c) =>
            ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    async function poll() {
        try {
            const response = await fetch('{{ route('activity') }}', {headers: {'Accept': 'application/json'}});
            if (!response.ok) throw new Error(response.status);
            const data = await response.json();

            rows.innerHTML = data.accounts.map((a) => `
                <tr>
                    <td class="px-5 py-3 font-medium">${escape(a.account)}<div class="text-xs text-slate-500">${escape(a.label)}</div></td>
                    <td class="px-5 py-3 tabular-nums">${a.ready} / ${a.delayed} / ${a.reserved}
                        ${a.cooldown_seconds > 0 ? badge(`cooldown ${a.cooldown_seconds}s`, 'bg-amber-100 text-amber-800') : ''}</td>
                    <td class="px-5 py-3 tabular-nums">${a.valid_refreshes}
                        <span class="text-xs text-slate-500">(${a.data_updates} data updates)</span></td>
                    <td class="px-5 py-3 tabular-nums">${a.retries_scheduled} / ${a.dead_letters}</td>
                    <td class="px-5 py-3 tabular-nums">${a.oldest_waiting_seconds === null ? '-' : a.oldest_waiting_seconds + 's'}</td>
                    <td class="px-5 py-3">${badge(escape(a.latest_outcome ?? 'none'), toneFor(a.latest_status))}</td>
                </tr>`).join('');

            updated.textContent = 'updated ' + new Date(data.updated_at).toLocaleTimeString() + ' UTC-aware';
            stale.classList.toggle('hidden', data.stale_leases === 0);
            stale.textContent = data.stale_leases + ' stale lease(s)';
        } catch (e) {
            updated.textContent = 'poll failed at ' + new Date().toLocaleTimeString();
        } finally {
            setTimeout(poll, POLL_MS);
        }
    }

    poll();
})();
</script>
