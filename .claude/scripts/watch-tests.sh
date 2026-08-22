#!/usr/bin/env bash
# Live view of a Playwright run.
#
#   npx playwright test --reporter=line > /tmp/pw.txt 2>&1 &
#   .claude/scripts/watch-tests.sh /tmp/pw.txt
#
# Reads the same log the run is writing, so it costs nothing and can be stopped
# and restarted freely. Ctrl-C leaves the display; the run carries on.
#
# Worth having because the suite takes 13 minutes on one worker, and failures
# show here as they happen rather than at the end.

LOG="${1:-/tmp/pw.txt}"

if [ ! -f "$LOG" ]; then
    echo "No log at $LOG — start a run first:"
    echo "  npx playwright test --reporter=line > $LOG 2>&1 &"
    exit 1
fi

START=$(date +%s)

while true; do
    line=$(grep -E "^\s*\[[0-9]+/[0-9]+\]" "$LOG" 2>/dev/null | tail -1)
    done_n=$(echo "$line" | grep -oE "\[[0-9]+/" | tr -d '[/')
    total=$(echo "$line" | grep -oE "/[0-9]+\]" | tr -d '/]')
    spec=$(echo "$line" | grep -oE "tests/e2e/[a-z-]+\.spec\.js" | head -1)
    project=$(echo "$line" | grep -oE "\[(desktop|mobile|shell|reseed|uploads)\]" | tail -1)

    [ -z "$done_n" ] && { sleep 2; continue; }

    pct=$(( done_n * 100 / total ))
    filled=$(( pct * 40 / 100 ))
    bar=$(printf '%*s' "$filled" '' | tr ' ' '#')$(printf '%*s' $(( 40 - filled )) '' | tr ' ' '.')

    elapsed=$(( $(date +%s) - START ))
    rate=$(( done_n > 0 ? elapsed / done_n : 0 ))
    left=$(( (total - done_n) * rate ))

    fails=$(grep -cE "^\s+[0-9]+ failed" "$LOG" 2>/dev/null || echo 0)

    clear
    echo ""
     echo "   Playwright"
    echo "   ─────────────────────────────────────────────────"
    echo ""
    printf "   [%s]  %d%%\n" "$bar" "$pct"
    echo ""
    printf "   %-12s %d of %d\n" "tests" "$done_n" "$total"
    printf "   %-12s %s %s\n" "running" "${project:-—}" "${spec:-—}"
    printf "   %-12s %dm %ds elapsed" "time" $(( elapsed / 60 )) $(( elapsed % 60 ))
    [ "$left" -gt 0 ] && printf "  ·  ~%dm left" $(( left / 60 ))
    echo ""
    echo ""

    # The whole point of watching: failures show as they happen, not at the end.
    bad=$(grep -E "^\s+\[(desktop|mobile|shell|reseed)\].*›" "$LOG" 2>/dev/null | grep -v "^\s*\[[0-9]" | head -6)
    if [ -n "$bad" ]; then
        echo "   Failures so far"
        echo "$bad" | sed 's/^ */     /' | cut -c1-72
        echo ""
    fi

    if grep -qE "[0-9]+ (passed|failed) \(" "$LOG" 2>/dev/null; then
        echo "   ─────────────────────────────────────────────────"
        grep -E "[0-9]+ (passed|failed|flaky)" "$LOG" | tail -3 | sed 's/^ */   /'
        echo ""
        break
    fi

    sleep 3
done
