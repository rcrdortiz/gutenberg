#!/bin/bash
# Run the benchmark against all backend combinations locally.
#
# Usage: ./tools/rtc-bench/compare.sh [iterations] [user]
#
# Results are saved to tools/rtc-bench/results/

ITERATIONS="${1:-50}"
USER="${2:-${RTC_BENCH_USER:-admin:password}}"
RESULTS_DIR="tools/rtc-bench/results"
SCENARIOS="empty-poll,with-update,multi-room"

mkdir -p "$RESULTS_DIR"

CONFIGS=(
  "post-meta:rest-api"
  "custom-table:rest-api"
  "post-meta:lightweight"
  "custom-table:lightweight"
)

for config in "${CONFIGS[@]}"; do
  IFS=":" read -r storage endpoint <<< "$config"
  label="${storage}_${endpoint}"

  echo ""
  echo "=============================================="
  echo "  Config: storage=$storage endpoint=$endpoint"
  echo "=============================================="

  bash tools/rtc-bench/switch-backend.sh "$storage" "$endpoint" 2>&1 | tail -3

  # Small pause to let PHP opcache settle.
  sleep 1

  node tools/rtc-bench/bench.mjs \
    --user "$USER" \
    --iterations "$ITERATIONS" \
    --scenarios "$SCENARIOS" \
    --endpoint "$endpoint" \
    --json > "$RESULTS_DIR/${label}.json" 2>&1

  echo "  Results:"
  node -e "
    const r = require('./${RESULTS_DIR}/${label}.json');
    for (const [name, s] of Object.entries(r)) {
      if (s.error) { console.log('  ' + name + ': ERROR - ' + s.error); continue; }
      console.log('  ' + name.padEnd(15) + ' mean=' + s.mean.toFixed(1) + 'ms  median=' + s.median.toFixed(1) + 'ms  p95=' + s.p95.toFixed(1) + 'ms');
    }
  "
done

echo ""
echo "=============================================="
echo "  Summary comparison"
echo "=============================================="
node -e "
  const fs = require('fs');
  const configs = ${JSON.stringify(CONFIGS)};
  const scenarios = '${SCENARIOS}'.split(',');

  console.log('');
  console.log('Scenario'.padEnd(16) + configs.map(c => c.replace(':','|').padStart(24)).join(''));
  console.log('-'.repeat(16 + configs.length * 24));

  for (const sc of scenarios) {
    let line = sc.padEnd(16);
    for (const config of configs) {
      const label = config.replace(':', '_');
      try {
        const r = JSON.parse(fs.readFileSync('${RESULTS_DIR}/' + label + '.json'));
        const s = r[sc];
        if (s && !s.error) {
          line += (s.median.toFixed(1) + 'ms').padStart(24);
        } else {
          line += 'ERROR'.padStart(24);
        }
      } catch { line += 'N/A'.padStart(24); }
    }
    console.log(line);
  }
  console.log('');
"
