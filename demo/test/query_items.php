#!/usr/bin/env php
<?php
/**
 * Unit listing query from the original codebase (UnitRepository::findUnits /
 * searchUnits), translated onto the anonymised `unitledger` schema:
 *   elements -> items, element_id -> item_id, fir_b -> fi_a
 * Tables that were pruned from the demo schema (packages, agencies) and the
 * filters that depended on them (packageConfig, agency.code search) are left out.
 *
 *   php demo/test/query_items.php --project=SLUG [filters] [--show] [--explain]
 *
 *   --project=SLUG      project title_canonical (required; resolved to its id)
 *                       e.g. harbourline-residences, elm-street-village, riverbend-estate
 *   --status=a,b        units.status IN (...)
 *   --aspect=N,NE       units.aspect IN (...)
 *   --bed="2 Bed 2 Bath"  product.name IN (...)   (comma separated)
 *   --fia=0|1           reservations.fi_a IN (...)
 *   --agency=ID         reservations.agency_id IN (...) AND reservations.status = 'accepted'
 *   --upgrade=NAME      contract_upgrade.name IN (...) AND contract_upgrade.type = 'upgrade'
 *   --min-area=N        units.total_area >= N   (total_lot_area for land projects)
 *   --max-area=N        units.total_area <= N
 *   --block=ID          units.block_id IN (...)
 *   --extra-config=X    extras.configuration IN (...)
 *   --search=TERM       stock_id / level / classification LIKE '%TERM%'
 *   --show              print the result rows (default: rows are counted only)
 *   --explain           also print the EXPLAIN plan
 *   --db=NAME           database (default: MYSQL_DATABASE or unitledger)
 *
 * Connection: MYSQL_HOST (127.0.0.1), MYSQL_PORT (3307), root / MYSQL_ROOT_PASSWORD (root).
 */
declare(strict_types=1);

$opts = getopt('h', [
    'project:', 'status:', 'aspect:', 'bed:', 'fia:', 'agency:', 'upgrade:',
    'min-area:', 'max-area:', 'block:', 'extra-config:', 'search:',
    'show', 'explain', 'db:', 'help',
]);
if (isset($opts['h']) || isset($opts['help'])) {
    $lines = file(__FILE__);
    echo implode('', array_map(fn ($l) => preg_replace('/^ \* ?/', '', $l), array_slice($lines, 3, 27)));
    exit(0);
}
$project = $opts['project'] ?? null;
if ($project === null || $project === '') {
    fwrite(STDERR, "usage: {$argv[0]} --project=SLUG [filters]  (see --help)\n");
    exit(1);
}

$host = getenv('MYSQL_HOST') ?: '127.0.0.1';
$port = getenv('MYSQL_PORT') ?: '3307';
$db   = $opts['db'] ?? (getenv('MYSQL_DATABASE') ?: 'unitledger');
$pw   = getenv('MYSQL_ROOT_PASSWORD') ?: 'root';

$pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", 'root', $pw, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,   // server-side prepares, like the original
]);

/** Split "a,b,c" into a list; empty option -> empty list. */
$list = static fn (?string $v): array => $v === null || $v === '' ? [] : array_map('trim', explode(',', $v));

// The original binds :project to the project id; resolve the slug the same way.
$st = $pdo->prepare('SELECT id, type FROM projects WHERE title_canonical = :slug');
$st->execute([':slug' => $project]);
$proj = $st->fetch();
if ($proj === false) {
    fwrite(STDERR, "no project with title_canonical '$project'\n");
    exit(1);
}
$projectId = $proj['id'];
$areaCol   = $proj['type'] === 'land' ? 'total_lot_area' : 'total_area';
$search    = $opts['search'] ?? null;

// --- base query (list path) ------------------------------------------------
$select = 'units.*, reservations.*, allocations.*, product.*, extras.*';
$joins  = <<<SQL
  LEFT JOIN contract_upgrades contract_upgrade ON units.id = contract_upgrade.item_id
  LEFT JOIN reservations reservations          ON units.id = reservations.item_id
  LEFT JOIN allocations allocations            ON units.id = allocations.item_id
  LEFT JOIN products product                   ON units.product_id = product.id
  LEFT JOIN extras extras                      ON units.id = extras.item_id
SQL;
$where  = ["units.project_id = :project", "units.discr IN ('unit')"];
$params = [':project' => $projectId];

// --- search path overrides joins/select and adds the LIKE clauses -----------
if ($search !== null && $search !== '') {
    $select = 'units.*, reservations.*, allocations.*';
    $joins  = <<<SQL
  LEFT JOIN reservations reservations ON units.id = reservations.item_id
  LEFT JOIN allocations allocations   ON units.id = allocations.item_id
SQL;
    // Native prepares need a distinct placeholder per occurrence.
    $where[] = "(units.stock_id LIKE :term1\n         OR units.level LIKE :term2\n         OR units.classification LIKE :term3)";
    $params += [':term1' => "%$search%", ':term2' => "%$search%", ':term3' => "%$search%"];
}

// --- arrangeFilter(): each clause only when its filter is set ---------------
$n = 0;
/** Build "IN (:p0, :p1, ...)" and register the bound values. */
$in = static function (array $values) use (&$params, &$n): string {
    $names = [];
    foreach ($values as $v) {
        $name = ':p' . $n++;
        $names[] = $name;
        $params[$name] = $v;
    }
    return 'IN (' . implode(', ', $names) . ')';
};
$isList = ($search === null || $search === '');

if ($v = $list($opts['status'] ?? null))   $where[] = 'units.status ' . $in($v);
if ($v = $list($opts['aspect'] ?? null))   $where[] = 'units.aspect ' . $in($v);
if ($v = $list($opts['fia'] ?? null))      $where[] = 'reservations.fi_a ' . $in($v);
if ($isList && ($v = $list($opts['bed'] ?? null)))
    $where[] = 'product.name ' . $in($v);
if ($isList && ($v = $list($opts['upgrade'] ?? null)))
    $where[] = 'contract_upgrade.name ' . $in($v) . " AND contract_upgrade.type = 'upgrade'";
if ($v = $list($opts['agency'] ?? null))
    $where[] = 'reservations.agency_id ' . $in($v) . " AND reservations.status = 'accepted'";
if (($v = $opts['min-area'] ?? '') !== '') { $where[] = "units.$areaCol >= :minArea"; $params[':minArea'] = (float) $v; }
if (($v = $opts['max-area'] ?? '') !== '') { $where[] = "units.$areaCol <= :maxArea"; $params[':maxArea'] = (float) $v; }
if ($v = $list($opts['block'] ?? null))    $where[] = 'units.block_id ' . $in($v);
if ($isList && ($v = $list($opts['extra-config'] ?? null)))
    $where[] = 'extras.configuration ' . $in($v);

$query = "SELECT $select\n  FROM items units\n$joins\n  WHERE " . implode("\n    AND ", $where)
       . "\n  ORDER BY units.stock_id_order ASC";

echo "database : $db @ $host:$port\n";
echo "project  : $project ($projectId, type={$proj['type']})\n";
echo 'path     : ' . ($isList ? 'list' : "search '$search'") . "\n";
echo "query    :\n" . preg_replace('/^/m', '  ', $query) . "\n";
echo "params   : " . json_encode($params, JSON_UNESCAPED_SLASHES) . "\n\n";

if (isset($opts['explain'])) {
    $ex = $pdo->prepare('EXPLAIN ' . $query);
    $ex->execute($params);
    $rows = $ex->fetchAll();
    echo "--- EXPLAIN ---\n";
    printTable($rows);
    echo "\n";
}

// --- run and time -----------------------------------------------------------
$t0 = hrtime(true);
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$t1 = hrtime(true);
$rows = $stmt->fetchAll();
$t2 = hrtime(true);

if (isset($opts['show'])) {
    printTable($rows);
    echo "\n";
}

$ms = static fn (int $ns): string => number_format($ns / 1e6, 2) . ' ms';
echo "--- timing ---\n";
printf("rows    : %d\n", count($rows));
printf("execute : %s  (prepare + execute, server-side work)\n", $ms($t1 - $t0));
printf("fetch   : %s  (transfer + hydrate %d rows)\n", $ms($t2 - $t1), count($rows));
printf("total   : %s\n", $ms($t2 - $t0));
printf("memory  : %.1f MB peak\n", memory_get_peak_usage(true) / 1048576);

/** Print rows as a simple aligned text table (columns truncated to 40 chars). */
function printTable(array $rows): void
{
    if ($rows === []) { echo "(empty set)\n"; return; }
    $cols = array_keys($rows[0]);
    $cell = static fn ($v): string => mb_strimwidth($v === null ? 'NULL' : (string) $v, 0, 40, '…');
    $width = array_combine($cols, array_map('mb_strlen', $cols));
    foreach ($rows as $r) foreach ($cols as $c) $width[$c] = max($width[$c], mb_strlen($cell($r[$c])));
    $line = '+' . implode('+', array_map(fn ($c) => str_repeat('-', $width[$c] + 2), $cols)) . '+';
    $fmt  = static fn (array $vals) => '| ' . implode(' | ', array_map(fn ($c, $v) => mb_str_pad($v, $width[$c]), $cols, $vals)) . ' |';
    echo "$line\n" . $fmt($cols) . "\n$line\n";
    foreach ($rows as $r) echo $fmt(array_map($cell, array_values($r))) . "\n";
    echo "$line\n";
}
