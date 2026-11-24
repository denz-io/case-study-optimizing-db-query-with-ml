#!/usr/bin/env php
<?php
/**
 * Baseline vs Smart-inspired predicate generation (case study, Section III),
 * measured on the emulated matrix-advance endpoint (demo/test/matrix-endpoint.php).
 *
 *   baseline  the endpoint as it is: findUnits()/arrangeFilter() in MySQL, every
 *             returned unit hydrated and scored by the decision tree in the element loop
 *   proposed  the same endpoint with SQL predicates derived from the tree added to the
 *             units query, so MySQL discards records that cannot satisfy the ML
 *             predicate before returning them; the tree still scores what remains
 *
 * Both share the dataset, schema, indexes, model, viewer and request. Each run
 * builds the full response (query, hydration, filter loop, element construction,
 * JSON encoding) and records the paper's metrics: query execution time from the
 * Performance Schema, total processing time, rows examined / returned / scored,
 * result correctness (hash of the sorted element ids), peak memory. The order of
 * the configurations alternates between runs.
 *
 *   php demo/test/query_items.php --project=SLUG [--request=QUERY] [options]
 *
 *   --project=SLUG        project title_canonical (required)
 *                         harbourline-residences | elm-street-village | riverbend-estate
 *   --request=QUERY       the endpoint's query string, e.g.
 *                         'filters=1&statusName=available,sold&aspect=N,NE&bedConfig=2 Bed 2 Bath|3 Bed 2 Bath'
 *                         'filters=1&unitTotalAreaList=80-150&block=A'   'search=H03'   'isMatrix=true'
 *   --as=admin|agent --agency=ID --email=EMAIL   the viewer (session user in production)
 *   --predicates=atomic   per-column bounds across all target-class paths (default)
 *   --predicates=composite  OR of the full root-to-leaf conjunctions (tight predicate)
 *   --config=both|baseline|proposed   which configurations to run (default both)
 *   --warmup=5 --runs=30  warm-up and measured executions per configuration
 *   --explain             print EXPLAIN for both units queries
 *   --json                emit the full measurement set as JSON after the report
 *
 * Environment: MYSQL_HOST, MYSQL_PORT, MYSQL_DATABASE, MYSQL_ROOT_PASSWORD, ML_MODEL.
 */
declare(strict_types=1);

require_once __DIR__ . '/ml.php';
require_once __DIR__ . '/matrix-endpoint.php';

// ---------------------------------------------------------------------------
// Options
// ---------------------------------------------------------------------------
$opts = getopt('h', ['project:', 'request:', 'as:', 'agency:', 'email:', 'predicates:', 'config:',
    'warmup:', 'runs:', 'explain', 'json', 'help']);
if (isset($opts['h']) || isset($opts['help'])) {
    $lines = file(__FILE__);
    echo implode('', array_map(fn ($l) => preg_replace('/^ \* ?/', '', $l), array_slice($lines, 3, 33)));
    exit(0);
}
$project = $opts['project'] ?? '';
if ($project === '') {
    fwrite(STDERR, "usage: {$argv[0]} --project=SLUG [--request=QUERY] [options]  (see --help)\n");
    exit(1);
}
$predMode = $opts['predicates'] ?? 'atomic';
if (!in_array($predMode, ['atomic', 'composite'], true)) {
    fwrite(STDERR, "--predicates must be atomic or composite\n");
    exit(1);
}
$configs = match ($opts['config'] ?? 'both') {
    'baseline' => ['baseline'],
    'proposed' => ['proposed'],
    default    => ['baseline', 'proposed'],
};
$warmup = (int) ($opts['warmup'] ?? 5);
$runs   = max(1, (int) ($opts['runs'] ?? 30));

parse_str($opts['request'] ?? '', $request);
$request['_as'] = $opts['as'] ?? ($request['_as'] ?? 'admin');
if (isset($opts['agency'])) $request['_agency'] = $opts['agency'];
if (isset($opts['email']))  $request['_email']  = $opts['email'];
$viewer = viewerFromQuery($request);

$pdo = db();
$threadId = (int) $pdo->query(
    'SELECT THREAD_ID FROM performance_schema.threads WHERE PROCESSLIST_ID = CONNECTION_ID()'
)->fetchColumn();

$proj = loadProject($project);
if ($proj === null) {
    fwrite(STDERR, "no project with title_canonical '$project'\n");
    exit(1);
}
if ($viewer['isAgent'] && empty($proj['agencyPermissions'][$viewer['agency']])) {
    fwrite(STDERR, "agent viewer needs --agency=ID\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Model, predicates and the validity check over the full dataset (Section III.E)
// ---------------------------------------------------------------------------
$model = mlModel();
$pred  = mlPredicates($model, $predMode);

$featureCols = implode(', ', array_map(fn ($f) => "units.$f", $model['features']));
$all = $pdo->query("SELECT units.id, $featureCols FROM items units")->fetchAll();
$predictedIds = [];
foreach ($all as $r) if (mlAccepts($model, $r)) $predictedIds[$r['id']] = true;
if ($pred['sql'] !== '') {
    $st = $pdo->prepare("SELECT units.id FROM items units WHERE {$pred['sql']}");
    $st->execute($pred['params']);
    $allowedIds = array_fill_keys($st->fetchAll(PDO::FETCH_COLUMN), true);
} else {
    $allowedIds = array_fill_keys(array_column($all, 'id'), true);
}
$excluded = array_diff_key($predictedIds, $allowedIds);
$validity = [
    'records'          => count($all),
    'model_target'     => count($predictedIds),
    'predicate_allows' => count($allowedIds),
    'wrongly_excluded' => count($excluded),
];
if ($excluded !== []) {
    fwrite(STDERR, "predicate validity check FAILED: {$validity['wrongly_excluded']} target-class records excluded\n");
    exit(2);
}

// The two endpoint requests: identical apart from the ML-derived predicates.
$requests = [
    'baseline' => $request + ['_ml' => '1'],
    'proposed' => $request + ['_ml' => '1', '_predicates' => $predMode],
];

// ---------------------------------------------------------------------------
// Measurement
// ---------------------------------------------------------------------------
/** Performance Schema figures for the units query (a statement/com/Execute event). */
function perfSchema(PDO $pdo, int $threadId): array
{
    // blockOrder() runs a prepared statement after the units query, so take the
    // Execute event with the most rows examined among the last few on this thread.
    $rows = $pdo->query(
        "SELECT TIMER_WAIT, ROWS_EXAMINED, ROWS_SENT, CREATED_TMP_TABLES, SORT_ROWS, NO_INDEX_USED
           FROM performance_schema.events_statements_history
          WHERE THREAD_ID = $threadId AND EVENT_NAME = 'statement/com/Execute'
          ORDER BY EVENT_ID DESC LIMIT 3"
    )->fetchAll();
    if ($rows === []) return [];
    usort($rows, fn ($a, $b) => $b['ROWS_EXAMINED'] <=> $a['ROWS_EXAMINED']);
    $r = $rows[0];
    return [
        'query_ms'      => ((int) $r['TIMER_WAIT']) / 1e9,   // TIMER_WAIT is picoseconds
        'rows_examined' => (int) $r['ROWS_EXAMINED'],
        'rows_sent'     => (int) $r['ROWS_SENT'],
        'tmp_tables'    => (int) $r['CREATED_TMP_TABLES'],
        'sort_rows'     => (int) $r['SORT_ROWS'],
        'no_index_used' => (int) $r['NO_INDEX_USED'],
    ];
}

/** One request through the endpoint: build the response, encode it, collect metrics. */
function runOnce(PDO $pdo, int $threadId, array $project, array $viewer, array $request): array
{
    $GLOBALS['matrix']['timing'] = [];
    $t0 = hrtime(true);
    $data = buildMatrix($project, $viewer, $request);
    $t1 = hrtime(true);
    $body = json_encode(['data' => $data], JSON_OPTIONS);
    $t2 = hrtime(true);

    $ids = [];
    foreach ($data['levels']['data'] as $level) {
        foreach ($level['elements']['data'] as $el) $ids[] = $el['id'];
    }
    sort($ids, SORT_STRING);
    $t = $GLOBALS['matrix']['timing'];
    return [
        'execute_ms' => $t['execute_ms'],
        'fetch_ms'   => $t['fetch_ms'],          // transfer + hydration
        'build_ms'   => $t['build_ms'],          // ML scoring, agent filter, element construction
        'encode_ms'  => ($t2 - $t1) / 1e6,
        'total_ms'   => ($t2 - $t0) / 1e6,
        'joined_rows'=> $t['rows'],
        'units'      => $t['units'],
        'scored'     => $t['scored'],
        'result'     => count($ids),
        'bytes'      => strlen($body),
        'hash'       => hash('sha256', implode("\n", $ids)),
        'peak_mb'    => memory_get_peak_usage(true) / 1048576,
    ] + perfSchema($pdo, $threadId);
}

// Fresh optimizer statistics for both configurations (indexes themselves are unchanged).
$pdo->query('ANALYZE TABLE items, projects, stages, products, reservations, allocations, contract_upgrades, extras')->fetchAll();

$measure = array_fill_keys($configs, []);
$sqls = [];
for ($i = 0; $i < $warmup + $runs; $i++) {
    $order = $i % 2 === 0 ? $configs : array_reverse($configs);   // alternate order
    foreach ($order as $c) {
        $m = runOnce($pdo, $threadId, $proj, $viewer, $requests[$c]);
        $sqls[$c] = $GLOBALS['matrix']['query'];
        if ($i >= $warmup) $measure[$c][] = $m;
    }
}

// ---------------------------------------------------------------------------
// Statistics (Section III.I)
// ---------------------------------------------------------------------------
function percentile(array $xs, float $p): float
{
    sort($xs);
    $k = ($p / 100) * (count($xs) - 1);
    $f = (int) floor($k); $c = (int) ceil($k);
    return $xs[$f] + ($xs[$c] - $xs[$f]) * ($k - $f);
}
$median = static fn (array $xs): float => percentile($xs, 50);
$col    = static fn (array $ms, string $k): array => array_map(fn ($m) => (float) ($m[$k] ?? 0), $ms);

/**
 * Wilcoxon signed-rank test on paired samples, normal approximation with
 * tie-corrected variance (adequate for n >= 20). Returns n, W+, z, two-sided p.
 */
function wilcoxon(array $a, array $b): array
{
    $d = [];
    foreach ($a as $i => $x) { $diff = $x - $b[$i]; if (abs($diff) > 1e-12) $d[] = $diff; }
    $n = count($d);
    if ($n === 0) return ['n' => 0, 'w_plus' => 0.0, 'z' => 0.0, 'p' => 1.0];
    $abs = array_map('abs', $d);
    $idx = array_keys($abs); usort($idx, fn ($i, $j) => $abs[$i] <=> $abs[$j]);
    $rank = []; $tieTerm = 0.0;
    for ($i = 0; $i < $n;) {                       // average ranks for ties
        $j = $i;
        while ($j + 1 < $n && abs($abs[$idx[$j + 1]] - $abs[$idx[$i]]) < 1e-12) $j++;
        $r = ($i + 1 + $j + 1) / 2;
        for ($k = $i; $k <= $j; $k++) $rank[$idx[$k]] = $r;
        $t = $j - $i + 1; if ($t > 1) $tieTerm += $t ** 3 - $t;
        $i = $j + 1;
    }
    $wPlus = 0.0;
    foreach ($d as $i => $diff) if ($diff > 0) $wPlus += $rank[$i];
    $mean = $n * ($n + 1) / 4;
    $var  = $n * ($n + 1) * (2 * $n + 1) / 24 - $tieTerm / 48;
    $z    = $var > 0 ? ($wPlus - $mean) / sqrt($var) : 0.0;
    $p    = 2 * (1 - 0.5 * (1 + erf(abs($z) / M_SQRT2)));
    return ['n' => $n, 'w_plus' => $wPlus, 'z' => $z, 'p' => $p];
}
function erf(float $x): float
{   // Abramowitz & Stegun 7.1.26, |error| < 1.5e-7
    $s = $x < 0 ? -1 : 1; $x = abs($x);
    $t = 1 / (1 + 0.3275911 * $x);
    $y = 1 - ((((1.061405429 * $t - 1.453152027) * $t + 1.421413741) * $t - 0.284496736) * $t + 0.254829592) * $t * exp(-$x * $x);
    return $s * $y;
}

$summary = [];
foreach ($configs as $c) {
    $ms = $measure[$c];
    $summary[$c] = [
        'runs'           => count($ms),
        'query_ms_med'   => $median($col($ms, 'query_ms')),
        'query_ms_p95'   => percentile($col($ms, 'query_ms'), 95),
        'total_ms_med'   => $median($col($ms, 'total_ms')),
        'total_ms_p95'   => percentile($col($ms, 'total_ms'), 95),
        'execute_ms_med' => $median($col($ms, 'execute_ms')),
        'fetch_ms_med'   => $median($col($ms, 'fetch_ms')),
        'build_ms_med'   => $median($col($ms, 'build_ms')),
        'encode_ms_med'  => $median($col($ms, 'encode_ms')),
        'rows_examined'  => (int) $median($col($ms, 'rows_examined')),
        'rows_sent'      => (int) $median($col($ms, 'rows_sent')),
        'units'          => (int) $median($col($ms, 'units')),
        'scored'         => (int) $median($col($ms, 'scored')),
        'result'         => (int) $median($col($ms, 'result')),
        'bytes'          => (int) $median($col($ms, 'bytes')),
        'tmp_tables'     => (int) $median($col($ms, 'tmp_tables')),
        'sort_rows'      => (int) $median($col($ms, 'sort_rows')),
        'peak_mb'        => max($col($ms, 'peak_mb')),
        'hash'           => $ms[0]['hash'],
        'stable'         => count(array_unique(array_column($ms, 'hash'))) === 1,
    ];
}
$both = count($configs) === 2;
$pct  = static fn (float $a, float $b): string => $a == 0.0 ? 'n/a' : sprintf('%+.1f%%', ($b - $a) / $a * 100);
$tests = [];
if ($both) {
    foreach (['query_ms', 'total_ms', 'rows_examined'] as $k) {
        $tests[$k] = wilcoxon($col($measure['baseline'], $k), $col($measure['proposed'], $k));
    }
}

// ---------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------
$dsn = (getenv('MYSQL_HOST') ?: '127.0.0.1') . ':' . (getenv('MYSQL_PORT') ?: '3307');
echo "database   : " . (getenv('MYSQL_DATABASE') ?: 'unitledger') . " @ $dsn  (MySQL "
   . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . ", PHP " . PHP_VERSION . ")\n";
echo "endpoint   : GET /ajax/project/$project/matrix-advance?" . http_build_query($request) . "\n";
echo "project    : {$proj['title']} ({$proj['id']}, type={$proj['type']}, levels={$proj['levels']})\n";
echo 'viewer     : ' . ($viewer['isAgent'] ? "agent {$viewer['agency']}" : 'admin') . "\n";
echo "model      : target class {$model['target_class']}, features " . implode(', ', $model['features']) . "\n";
echo "predicates : $predMode\n";
echo "target paths from the tree:\n";
foreach ($pred['paths'] as $i => $path) {
    echo '  P' . ($i + 1) . ': ' . implode(' AND ', array_map(fn ($c) => "$c[0] $c[1] $c[2]", $path)) . "\n";
}
echo "generated SQL predicate:\n  " . ($pred['sql'] === '' ? '(none: no feature is bounded on every target path)' : str_replace("\n", "\n  ", $pred['sql'])) . "\n";
echo '  params ' . json_encode($pred['params']) . "\n";
printf("validity   : %d records, %d in target class, predicate allows %d, wrongly excluded %d  -> %s\n\n",
    $validity['records'], $validity['model_target'], $validity['predicate_allows'], $validity['wrongly_excluded'],
    $validity['wrongly_excluded'] === 0 ? 'VALID' : 'INVALID');

echo "units query (proposed configuration; baseline is the same without the ML-derived clause):\n";
$shown = $sqls['proposed'] ?? $sqls['baseline'];
echo preg_replace('/^/m', '  ', $shown['sql']) . "\n";
echo '  params ' . json_encode($shown['params'], JSON_UNESCAPED_SLASHES) . "\n\n";

if (isset($opts['explain'])) {
    foreach ($configs as $c) {
        $ex = $pdo->prepare('EXPLAIN ' . $sqls[$c]['sql']);
        $ex->execute($sqls[$c]['params']);
        echo "--- EXPLAIN $c ---\n";
        printTable($ex->fetchAll());
        echo "\n";
    }
}

printf("--- results: %d warm-up + %d measured requests per configuration, order alternated ---\n", $warmup, $runs);
$rowsOut = [];
$metric = static function (string $label, string $key, string $fmt, bool $pctChange = true) use (&$rowsOut, $summary, $configs, $both, $pct) {
    $r = ['metric' => $label];
    foreach ($configs as $c) $r[$c] = sprintf($fmt, $summary[$c][$key]);
    $r['change'] = $both && $pctChange ? $pct((float) $summary['baseline'][$key], (float) $summary['proposed'][$key]) : '';
    $rowsOut[] = $r;
};
$metric('query execution ms (median, Performance Schema)', 'query_ms_med', '%.3f');
$metric('query execution ms (p95)',                         'query_ms_p95', '%.3f');
$metric('total processing ms (median, full response)',     'total_ms_med', '%.3f');
$metric('total processing ms (p95)',                        'total_ms_p95', '%.3f');
$metric('  execute ms (median)',                            'execute_ms_med', '%.3f');
$metric('  fetch + hydrate ms (median)',                    'fetch_ms_med', '%.3f');
$metric('  score + filter + build elements ms (median)',    'build_ms_med', '%.3f');
$metric('  JSON encode ms (median)',                        'encode_ms_med', '%.3f');
$metric('rows examined by MySQL',                           'rows_examined', '%d');
$metric('rows returned to application (joined)',            'rows_sent', '%d');
$metric('units hydrated',                                   'units', '%d');
$metric('records scored by the model',                      'scored', '%d');
$metric('elements in response',                             'result', '%d');
$metric('response bytes',                                   'bytes', '%d');
$metric('temp tables created',                              'tmp_tables', '%d', false);
$metric('rows sorted (filesort)',                           'sort_rows', '%d');
$metric('peak PHP memory MB',                               'peak_mb', '%.1f');
printTable($rowsOut);
echo "\n";

foreach ($configs as $c) {
    printf("%-9s element-id hash %s  (%s across runs)\n", $c, substr($summary[$c]['hash'], 0, 16),
        $summary[$c]['stable'] ? 'stable' : 'UNSTABLE');
}
if ($both) {
    $same = $summary['baseline']['hash'] === $summary['proposed']['hash'];
    echo 'correctness: ' . ($same ? 'proposed returns exactly the baseline element set' : 'MISMATCH between configurations') . "\n";
    echo "\nWilcoxon signed-rank (paired, n=$runs, alpha 0.05):\n";
    foreach ($tests as $k => $t) {
        printf("  %-14s W+=%-8.1f z=%-7.3f p=%-10.2e %s\n", $k, $t['w_plus'], $t['z'], $t['p'],
            $t['n'] === 0 ? 'no differences' : ($t['p'] < 0.05 ? 'significant' : 'not significant'));
    }
}

if (isset($opts['json'])) {
    echo "\n" . json_encode([
        'project' => $project, 'project_id' => $proj['id'], 'request' => $request, 'viewer' => $viewer,
        'predicates' => $predMode, 'predicate_sql' => $pred['sql'], 'predicate_params' => $pred['params'],
        'queries' => $sqls, 'validity' => $validity, 'warmup' => $warmup, 'runs' => $runs,
        'summary' => $summary, 'tests' => $tests, 'measurements' => $measure,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}

/** Print rows as a simple aligned text table (cells truncated to 48 chars). */
function printTable(array $rows): void
{
    if ($rows === []) { echo "(empty set)\n"; return; }
    $cols = array_keys($rows[0]);
    $cell = static fn ($v): string => mb_strimwidth($v === null ? 'NULL' : (string) $v, 0, 48, '…');
    $width = array_combine($cols, array_map('mb_strlen', $cols));
    foreach ($rows as $r) foreach ($cols as $c) $width[$c] = max($width[$c], mb_strlen($cell($r[$c])));
    $line = '+' . implode('+', array_map(fn ($c) => str_repeat('-', $width[$c] + 2), $cols)) . '+';
    $fmt  = static fn (array $vals) => '| ' . implode(' | ', array_map(fn ($c, $v) => mb_str_pad($v, $width[$c]), $cols, $vals)) . ' |';
    echo "$line\n" . $fmt($cols) . "\n$line\n";
    foreach ($rows as $r) echo $fmt(array_map($cell, array_values($r))) . "\n";
    echo "$line\n";
}
