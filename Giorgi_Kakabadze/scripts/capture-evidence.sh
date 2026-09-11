#!/usr/bin/env bash
# Regenerates everything in evidence/ from a clean demo state.
# Nothing here touches the live provider: see `fans:demo live` for that.
set -uo pipefail

cd "$(dirname "$0")/.." || exit 1
OUT=evidence
mkdir -p "$OUT"

run() {
    local name=$1; shift
    local file="$OUT/$name.txt"
    local started elapsed status

    {
        echo "\$ $*"
        echo "started: $(date -u +%FT%TZ)"
        echo "----------------------------------------------------------------"
    } > "$file"

    started=$(date +%s)
    "$@" >> "$file" 2>&1
    status=$?
    elapsed=$(( $(date +%s) - started ))

    {
        echo "----------------------------------------------------------------"
        echo "exit status: $status"
        echo "elapsed: ${elapsed}s"
    } >> "$file"

    printf '%-22s exit=%-3s %ss\n' "$name" "$status" "$elapsed"
}

dc() { docker compose exec -T app "$@"; }

echo "== environment =="
{
    echo "captured: $(date -u +%FT%TZ)"
    docker --version
    docker compose version
    dc php -v | head -1
    dc php artisan --version
    dc php -r 'echo "horizon ", \Composer\InstalledVersions::getPrettyVersion("laravel/horizon"), PHP_EOL;'
    dc php -r 'echo "scout ", \Composer\InstalledVersions::getPrettyVersion("laravel/scout"), PHP_EOL;'
    docker compose exec -T mysql mysql --version
    docker compose exec -T redis redis-server --version
} > "$OUT/environment.txt" 2>&1

echo "== workers restarted so the evidence uses the current code =="
dc php artisan horizon:terminate > /dev/null 2>&1
sleep 8

echo "== tests =="
run tests dc ./vendor/bin/phpunit --testdox

echo "== incident reproduction (broken mode exits 1 by design) =="
run reproduction-broken dc php artisan fans:demo broken --wait=120
run reproduction-fixed  dc php artisan fans:demo fixed  --wait=120

echo "== A/B workload =="
run workload-broken dc php artisan fans:demo workload --mode=broken --wait=180 --json=evidence/workload-broken.json
run workload-fixed  dc php artisan fans:demo workload --mode=fixed  --wait=180 --json=evidence/workload-fixed.json

echo "== dead letter and replay =="
run dlq-demo dc php artisan fans:demo dlq-demo --wait=180

echo "== memory scenario =="
dc php artisan fans:demo seed > /dev/null 2>&1
run memory dc php artisan fans:memory --jobs=100 --wait=240 --json=evidence/memory.json
# Scoped to this project's own containers: other stacks on the machine are
# none of this submission's business.
{
    echo "captured: $(date -u +%FT%TZ)"
    docker stats --no-stream --format 'table {{.Name}}\t{{.MemUsage}}\t{{.CPUPerc}}' $(docker compose ps -q)
} > "$OUT/compose-usage.txt" 2>&1

echo
echo "evidence written to $OUT/"
