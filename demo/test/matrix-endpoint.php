<?php
/**
 * Database-backed emulation of GET /ajax/project/{projectUrl}/matrix-advance on the
 * anonymised demo schema (elements -> items, element_id -> item_id, fir_b -> fi_a).
 *
 * Adapted from the fixture-only emulation in the original codebase. Mirrors
 * ProjectController::getProjectMatrixAdvance(), Provider\Building::getBuildingAdvance(),
 * FilterTrait::getFilterValues() and UnitRepository::findUnits() / searchUnits() /
 * arrangeFilter(), including their current quirks (marked QUIRK). arrangeFilter() runs
 * in SQL against MySQL, as it does in production, instead of being re-implemented in PHP.
 *
 * HTTP:  php -S localhost:8089 demo/test/matrix-endpoint.php
 *        curl 'localhost:8089/ajax/project/harbourline-residences/matrix-advance?isMatrix=true'
 * CLI:   php demo/test/matrix-endpoint.php 'filters=1&statusName=available&_as=agent&_agency=AG1' [projectUrl]
 *
 * The real endpoint takes the viewer from the session. Here it is passed as:
 *   _as=admin|agent (default admin), _agency=<agency id>, _email=<user email>
 * The underscore keeps them clear of the real filter parameters such as agency.
 *
 * Case study (SQL+ML query):
 *   _ml=1                          score each returned unit with demo/test/model.json in the
 *                                  element loop and keep only the target class (baseline)
 *   _predicates=atomic|composite   also add the tree-derived SQL predicates to the units
 *                                  query so MySQL filters first (proposed configuration)
 *
 * Not emulated: house-and-land package metas; the upgrade, carParkList and packageConfig
 * filters; the agency.code search term; and agency names, project access, status colours
 * and custom labels, whose tables were pruned from the demo schema (every agency is
 * treated as having access, names fall back to the agency id).
 *
 * Connection: MYSQL_HOST (127.0.0.1), MYSQL_PORT (3307), MYSQL_DATABASE (unitledger),
 * root / MYSQL_ROOT_PASSWORD (root).
 */
declare(strict_types=1);

require_once __DIR__ . '/ml.php';

const RESERVED_STATUSES = [
    'reserved', 'sales-advice', 'contract-transit', 'contracted', 'contract-signed',
    'contract-require-amendments', 'has-deposit', 'conditional-exchanged',
    'unconditional', 'withheld', 'settled',
];
// Same as Symfony's JsonResponse::DEFAULT_ENCODING_OPTIONS.
const JSON_OPTIONS = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;

/** Stands in for the pruned agency_projects table: any agency id is permitted. */
final class AllAgencies implements ArrayAccess
{
    public function offsetExists(mixed $k): bool { return $k !== null; }
    public function offsetGet(mixed $k): mixed { return $k !== null; }
    public function offsetSet(mixed $k, mixed $v): void {}
    public function offsetUnset(mixed $k): void {}
}

// Timing and the last SQL issued by selectUnits(), for the benchmark harness.
$GLOBALS['matrix'] = ['timing' => [], 'query' => null];

if (PHP_SAPI !== 'cli' || realpath($argv[0] ?? '') === __FILE__) {
    main($argv ?? []);
}

function main(array $argv): void
{
    if (PHP_SAPI === 'cli') {
        parse_str($argv[1] ?? '', $query);
        $projectUrl = $argv[2] ?? 'harbourline-residences';
    } else {
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        if (!preg_match('#^/ajax/project/([^/]+)/matrix-advance/?$#', $path, $m)) {
            respond(404, ['error' => 'No route found']);
            return;
        }
        $projectUrl = rawurldecode($m[1]);
        $query = $_GET;
    }

    $project = loadProject($projectUrl);
    if (!$project) {
        respond(404, ['error' => 'Project not found']);
        return;
    }

    $viewer = viewerFromQuery($query);

    // hasProjectAccess(): an agency user needs can_see on the project.
    if ($viewer['isAgent'] && empty($project['agencyPermissions'][$viewer['agency']])) {
        respond(401, ['error' => 'You do not have access to this project']);
        return;
    }

    respond(200, ['data' => buildMatrix($project, $viewer, $query)]);
}

function respond(int $status, array $body): void
{
    if (PHP_SAPI === 'cli') {
        echo json_encode($body, JSON_OPTIONS | JSON_PRETTY_PRINT), PHP_EOL;
        return;
    }
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body, JSON_OPTIONS);
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $host = getenv('MYSQL_HOST') ?: '127.0.0.1';
        $port = getenv('MYSQL_PORT') ?: '3307';
        $db   = getenv('MYSQL_DATABASE') ?: 'unitledger';
        $pw   = getenv('MYSQL_ROOT_PASSWORD') ?: 'root';
        $pdo  = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", 'root', $pw, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,   // server-side prepares, like the ORM
        ]);
    }
    return $pdo;
}

function viewerFromQuery(array $query): array
{
    return [
        'isAgent' => ($query['_as'] ?? 'admin') === 'agent',
        'agency'  => $query['_agency'] ?? null,
        'email'   => $query['_email'] ?? null,
    ];
}

/** ProjectRepository::findOneByTitleCanonical() plus the related config the matrix reads. */
function loadProject(string $projectUrl): ?array
{
    $st = db()->prepare('SELECT id, title, title_canonical, type, levels, block_sort, show_held FROM projects WHERE title_canonical = :url');
    $st->execute([':url' => $projectUrl]);
    $p = $st->fetch();
    if ($p === false) {
        return null;
    }
    return [
        'id'                => $p['id'],
        'title'             => $p['title'],
        'titleCanonical'    => $p['title_canonical'],
        'type'              => $p['type'],
        'levels'            => (int) $p['levels'],
        'blockSort'         => $p['block_sort'] !== null ? (array) json_decode($p['block_sort'], true) : [],
        'showHeld'          => (bool) $p['show_held'],
        'agencies'          => [],   // agencies table pruned: agencyName() falls back to the id
        'agencyPermissions' => new AllAgencies(), // agency_projects pruned: every agency can_see
        'statusColours'     => [],   // status_management pruned
        'customAgentLabels' => [],   // project_configs pruned
    ];
}

/** ProjectController::getProjectMatrixAdvance() */
function buildMatrix(array $project, array $viewer, array $query): array
{
    $search   = isset($query['search']) && strlen($query['search']) > 0 ? $query['search'] : null;
    $block    = $query['block'] ?? null;
    $isMatrix = array_key_exists('isMatrix', $query); // QUIRK: presence only, "isMatrix=false" is true.
    // QUIRK: stageId is read by the controller but never passed on, so it has no effect.

    $filterValues = [];
    $agentFilterValues = [];
    if (array_key_exists('filters', $query)) {
        $filterValues = getFilterValues($query, $viewer);
        if ($viewer['isAgent']) {
            $agentFilterValues = explode(',', $query['statusName'] ?? '');
        }
    }

    // Case study: the ML predicate and, for the proposed configuration, its SQL predicates.
    $ml = !empty($query['_ml']) ? mlModel() : null;
    $predicates = $ml && !empty($query['_predicates']) ? mlPredicates($ml, $query['_predicates']) : null;

    $units = selectUnits($project, $viewer, $search, $block, $filterValues, $predicates);

    $levels = [];
    for ($i = 0; $i < $project['levels']; $i++) {
        $levels[$i] = ['height' => $i * 3, 'elements' => ['data' => []]];
    }

    $t0 = hrtime(true);
    $scored = 0;
    foreach ($units as $unit) {
        if ($ml !== null) {
            // Baseline SQL+ML path: every candidate MySQL returned is scored here.
            $scored++;
            if (!mlAccepts($ml, $unit['_row'])) {
                continue;
            }
        }
        if ($viewer['isAgent'] && isset($filterValues['statusName'])
            && !agentCanSeeUnderFilter($unit, $viewer, $agentFilterValues, $isMatrix)) {
            continue;
        }
        $order = $unit['levelOrder'];
        if (!isset($levels[$order])) {
            $levels[$order] = ['height' => $order * 3, 'elements' => ['data' => []]];
        }
        $levels[$order]['elements']['data'][] = buildElement($project, $unit, $viewer);
    }

    // Building::getLevelsByHeight(): highest level first.
    usort($levels, function ($a, $b) {
        return $b['height'] <=> $a['height'];
    });

    $hasElements = false;
    foreach ($levels as $level) {
        $hasElements = $hasElements || count($level['elements']['data']) > 0;
    }
    $GLOBALS['matrix']['timing']['build_ms'] = (hrtime(true) - $t0) / 1e6;
    $GLOBALS['matrix']['timing']['scored']   = $scored;

    return [
        'blockOrder' => blockOrder($project),
        'name'       => $project['title'],
        'type'       => $project['type'],
        'aspects'    => $hasElements ? ['Other'] : [],
        'meta'       => [],
        'levels'     => ['data' => $levels],
    ];
}

/** ProjectManager::getUnitBlockIds() */
function blockOrder(array $project): array
{
    if (!empty($project['blockSort'])) {
        return array_map('trim', $project['blockSort']);
    }
    $st = db()->prepare('SELECT DISTINCT block_id FROM items WHERE project_id = :p AND block_id IS NOT NULL AND block_id <> \'\' ORDER BY block_id');
    $st->execute([':p' => $project['id']]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

/** FilterTrait::getFilterValues() with $isMatrix = true */
function getFilterValues(array $query, array $viewer): array
{
    $values = [];
    $split = ['aspect' => ',', 'firb' => ',', 'bedConfig' => '|', 'agency' => ',', 'block' => ',', 'unitTotalAreaList' => '-'];

    if (!empty($query['statusName'])) {
        $names = explode(',', $query['statusName']);
        $hasUnavailable = in_array('unavailable', $names);
        if (in_array('sold', $names) || $hasUnavailable) {
            $names = array_merge(RESERVED_STATUSES, $hasUnavailable ? ['pending', 'held'] : [], $names);
        }
        $values['statusName'] = $names;
    }
    foreach ($split as $key => $delimiter) {
        if (!empty($query[$key])) {
            $values[$key] = explode($delimiter, $query[$key]);
        }
    }
    $values['excludeSold'] = $viewer['isAgent'];

    return $values;
}

/**
 * Unit selection at the top of getBuildingAdvance(): UnitRepository::findUnits()
 * (list path) or searchUnits() (search path), both with arrangeFilter() appended.
 * The select list is the repository's own; the joined rows are hydrated back
 * into one array per unit the way the ORM does.
 */
function selectUnits(array $project, array $viewer, ?string $search, ?string $block, array $filterValues, ?array $predicates = null): array
{
    $params = [':project' => $project['id']];
    $where  = ['units.project_id = :project', "units.discr IN ('unit')"];
    $limit  = '';

    if ($search === null) {
        $select = 'units.*, reservations.*, allocations.*, product.*, extras.*';
        $joins  = <<<SQL
  LEFT JOIN contract_upgrades contract_upgrade ON units.id = contract_upgrade.item_id
  LEFT JOIN reservations reservations          ON units.id = reservations.item_id
  LEFT JOIN allocations allocations            ON units.id = allocations.item_id
  LEFT JOIN products product                   ON units.product_id = product.id
  LEFT JOIN extras extras                      ON units.id = extras.item_id
SQL;
    } else {
        // searchUnits(): agencies was pruned, so the agency.code term is left out.
        $select = 'units.*, reservations.*, allocations.*';
        $joins  = <<<SQL
  LEFT JOIN reservations reservations ON units.id = reservations.item_id
  LEFT JOIN allocations allocations   ON units.id = allocations.item_id
SQL;
        $where[] = "(units.stock_id LIKE :term1\n         OR units.level LIKE :term2\n         OR units.classification LIKE :term3)";
        $term = '%' . trim($search) . '%';
        $params += [':term1' => $term, ':term2' => $term, ':term3' => $term];
        // QUIRK: setMaxResults(20) counts joined rows, so it can return fewer than 20 units.
        $limit = "\n  LIMIT 20";
    }

    if ($filterValues) {
        // With filters, the block comes from filterValues['block'] unless searching.
        arrangeFilter($filterValues, $viewer, $project, $search !== null ? $block : null, $where, $params);
    } elseif ($block) {
        $where[] = 'units.block_id = :block';
        $params[':block'] = $block;
    }

    if ($predicates !== null && $predicates['sql'] !== '') {
        $where[] = $predicates['sql'];
        $params += $predicates['params'];
    }

    $sql = "SELECT $select\n  FROM items units\n$joins\n  WHERE " . implode("\n    AND ", $where)
         . "\n  ORDER BY units.stock_id_order ASC" . $limit;
    $GLOBALS['matrix']['query'] = ['sql' => $sql, 'params' => $params];

    $t0 = hrtime(true);
    $st = db()->prepare($sql);
    $st->execute($params);
    $t1 = hrtime(true);
    [$units, $rowCount] = hydrateUnits($st, $search === null);
    $t2 = hrtime(true);
    $GLOBALS['matrix']['timing'] += [
        'execute_ms' => ($t1 - $t0) / 1e6,
        'fetch_ms'   => ($t2 - $t1) / 1e6,
        'rows'       => $rowCount,
        'units'      => count($units),
    ];
    return $units;
}

/** UnitRepository::arrangeFilter(): the SQL clauses, each only when its filter is set. */
function arrangeFilter(array $fv, array $viewer, array $project, ?string $blockId, array &$where, array &$params): void
{
    $n = 0;
    $in = static function (array $values, string $prefix) use (&$params, &$n): string {
        $names = [];
        foreach (array_values($values) as $v) { $name = ":$prefix" . $n++; $names[] = $name; $params[$name] = $v; }
        return 'IN (' . implode(', ', $names) . ')';
    };

    if (!empty($fv['statusName'])) {
        $names = $fv['statusName'];
        if ($viewer['isAgent'] && in_array('unavailable', $names)) {
            $names = array_diff($names, ['unavailable']);
            $names[] = 'available';
        }
        $inStatus = 'units.status ' . $in($names, 'status');
        if ($fv['excludeSold'] && in_array('sold', $names)) {
            $where[] = "($inStatus OR allocations.agency_id IS NOT NULL OR allocations.status NOT IN ('enabled'))";
        } else {
            $where[] = $inStatus;
        }
    }
    if (!empty($fv['aspect'])) {
        $where[] = 'units.aspect ' . $in($fv['aspect'], 'aspect');
    }
    if (!empty($fv['firb'])) {
        $flags = array_map(fn ($v) => strtolower($v) === 'yes' ? 1 : 0, $fv['firb']);
        $where[] = 'reservations.fi_a ' . $in(array_unique($flags), 'firb');
    }
    if (!empty($fv['bedConfig'])) {
        $where[] = 'product.name ' . $in($fv['bedConfig'], 'bed');
    }
    if (!empty($fv['agency'])) {
        $where[] = 'reservations.agency_id ' . $in($fv['agency'], 'agency') . " AND reservations.status = 'accepted'";
    }
    if (!empty($fv['unitTotalAreaList'])) {
        $col = $project['type'] === 'land' ? 'total_lot_area' : 'total_area'; // total_lot_area for house-and-land
        $where[] = "units.$col BETWEEN :minTotalArea AND :maxTotalArea";
        $params[':minTotalArea'] = (float) $fv['unitTotalAreaList'][0];
        $params[':maxTotalArea'] = (float) ($fv['unitTotalAreaList'][1] ?? 0);
    }
    $blocks = !empty($fv['block']) ? $fv['block'] : ($blockId ? [$blockId] : []);
    if ($blocks) {
        $where[] = 'units.block_id ' . $in($blocks, 'block');
    }
}

/**
 * Hydrate the joined result set into one array per unit, like Doctrine's object
 * hydrator: columns are attributed to their source alias (units, reservations,
 * allocations, product, extras) via column metadata, so the duplicate column
 * names in "units.*, reservations.*, ..." do not clobber each other.
 * Returns [units ordered as fetched, number of joined rows].
 */
function hydrateUnits(PDOStatement $st, bool $withProduct): array
{
    $cols = [];
    for ($i = 0, $c = $st->columnCount(); $i < $c; $i++) {
        $m = $st->getColumnMeta($i);
        $cols[$i] = [$m['table'] ?? '', $m['name']];
    }
    $units = [];
    $rows  = 0;
    while (($row = $st->fetch(PDO::FETCH_NUM)) !== false) {
        $rows++;
        $by = [];
        foreach ($cols as $i => [$alias, $name]) {
            $by[$alias][$name] = $row[$i];
        }
        $u = $by['units'];
        $id = $u['id'];
        $units[$id] ??= unitFromRow($u, $withProduct ? ($by['product'] ?? null) : null);
        $unit = &$units[$id];
        if (!empty($by['reservations']['id'])) {
            $r = $by['reservations'];
            $unit['_reservations'][$r['id']] = [
                'agencyCode'        => $r['agency_id'],
                'status'            => $r['status'],
                'current'           => (bool) $r['current'],
                'firb'              => (bool) $r['fi_a'],
                'purchaser'         => $r['purchaser'],
                'agentComment'      => $r['agent_comment'],
                'salesPersonEmails' => salesPersonEmails($r['sales_persons']),
            ];
        }
        if (!empty($by['allocations']['id'])) {
            $a = $by['allocations'];
            $unit['allocations'][$a['id']] = ['agencyCode' => $a['agency_id'], 'status' => $a['status']];
        }
        unset($unit);
    }
    foreach ($units as &$unit) {
        // Unit::getReservation(): the current reservation, else the last one recorded.
        $reservations = $unit['_reservations'] ?? [];
        $current = array_filter($reservations, fn ($r) => $r['current']);
        $unit['reservation'] = $current ? reset($current) : ($reservations ? end($reservations) : null);
        $unit['allocations'] = array_values($unit['allocations'] ?? []);
        unset($unit['_reservations']);
    }
    unset($unit);
    return [array_values($units), $rows];
}

function salesPersonEmails(?string $json): array
{
    $list = $json !== null ? json_decode($json, true) : null;
    if (!is_array($list)) {
        return [];
    }
    return array_values(array_filter(array_map(
        fn ($p) => is_array($p) ? ($p['email'] ?? null) : (is_string($p) ? $p : null), $list
    )));
}

/** Map a hydrated items row onto the unit shape the matrix code reads. */
function unitFromRow(array $u, ?array $product): array
{
    return [
        'id'             => $u['id'],
        'stockId'        => $u['stock_id'],
        'stockIdOrder'   => (float) $u['stock_id_order'],
        'level'          => $u['level'],
        'levelOrder'     => (int) $u['level_order'],
        'blockId'        => $u['block_id'],
        'status'         => $u['status'],
        'width'          => (int) $u['width'],
        'aspect'         => $u['aspect'],
        'classification' => $u['classification'],
        'product'        => $product['name'] ?? null,
        'totalArea'      => $u['total_area'],
        'price'          => $u['price'],
        'priceM2'        => $u['price_m2'],
        'consumerPrice'  => $u['consumer_price'] !== null ? (array) json_decode($u['consumer_price'], true) : [],
        'isLeased'       => (bool) $u['is_leased'],
        'hidePrice'      => (bool) $u['hide_price'],
        'notes'          => $u['notes'],
        'outgoings'      => $u['outgoings'],
        'reservation'    => null,
        'allocations'    => [],
        '_row'           => $u,   // raw columns, used by the ML model's feature lookup
    ];
}

function enabledAllocationAgencies(array $unit): array
{
    $codes = [];
    foreach ($unit['allocations'] ?? [] as $allocation) {
        if ($allocation['status'] === 'enabled') {
            $codes[] = $allocation['agencyCode'];
        }
    }
    return $codes;
}

/** Per-unit agent status filter inside the getBuildingAdvance() loop */
function agentCanSeeUnderFilter(array $unit, array $viewer, array $names, bool $isMatrix): bool
{
    $allocated = enabledAllocationAgencies($unit);
    $ownAllocation = in_array($viewer['agency'], $allocated);
    $hasAllocation = $allocated && !($ownAllocation && $isMatrix);
    $status = $unit['status'];
    $reservation = $unit['reservation'] ?? null;

    if (in_array('available', $names) && $status === 'available' && !$hasAllocation) {
        return true;
    }
    if ($reservation) {
        $own = $reservation['agencyCode'] === $viewer['agency'];
        return (in_array('held', $names) && $status === 'held' && $own)
            || (in_array('pending', $names) && $status === 'pending' && $own)
            || (in_array('unavailable', $names) && $status !== 'available' && !$own)
            || (in_array('sold', $names) && !in_array($status, ['pending', 'held', 'available']) && $own);
    }

    return in_array('unavailable', $names)
        && (($status === 'available' && $allocated && !$ownAllocation) || $status === 'withheld');
}

/** Element construction inside the getBuildingAdvance() loop */
function buildElement(array $project, array $unit, array $viewer): array
{
    $reservation = $unit['reservation'] ?? null;
    $status = $unit['status'];
    $allocatedCodes = enabledAllocationAgencies($unit);
    // Unit::getAllocation($agency), falling back to any enabled allocation.
    $allocatedCode = in_array($viewer['agency'], $allocatedCodes) ? $viewer['agency'] : ($allocatedCodes[0] ?? null);

    $consumerPrice = $unit['consumerPrice'] ?? [];
    $latest = $consumerPrice ? end($consumerPrice) : null;
    $price = $latest ? ($latest['value'] ?? 0) : ($unit['price'] ?? 0);
    $priceM2 = $latest ? ($latest['m2'] ?? 0) : ($unit['priceM2'] ?? 0);

    $colours = $project['statusColours'][$status] ?? null;
    $metas = [
        'isLeased'     => $unit['isLeased'] ?? false,
        'hidePrice'    => $unit['hidePrice'] ?? false,
        'aspect'       => $unit['aspect'] ?? null,
        'notes'        => $unit['notes'] ?? null,
        'projectUrl'   => $project['titleCanonical'],
        'statusColour' => false,
    ];

    if ($viewer['isAgent']) {
        $ownReservation = $reservation && $reservation['agencyCode'] === $viewer['agency'];
        $isSalesPerson = $reservation && in_array($viewer['email'], $reservation['salesPersonEmails'] ?? []);

        if ($ownReservation) {
            $metas['firb']       = $reservation['firb'] ?? false;
            $metas['agency']     = agencyName($project, $reservation['agencyCode']);
            $metas['agencyCode'] = $reservation['agencyCode'];
            $metas['purchaser']  = $reservation['purchaser'] ?? null;
            $metas['agentNotes'] = $reservation['agentComment'] ?? null;
            $metas['price']      = $price;
            $metas['pricem2']    = $priceM2;
        }
        if ($colours) {
            $metas['statusColour'] = $colours['agentColour'];
        }

        if ($status === 'pending') {
            if ($ownReservation) {
                $metas['pending'] = true;
            } else {
                $status = 'sold';
            }
        } elseif ($status === 'held') {
            if (!empty($project['showHeld']) && $ownReservation) {
                $status = 'held-green';
            }
        } elseif (in_array($status, RESERVED_STATUSES) && !in_array($status, ['withheld', 'settled'])) {
            if ($ownReservation || $isSalesPerson) {
                $status = 'sold-green';
            }
        }

        $canSee = !empty($project['agencyPermissions'][$viewer['agency']]);
        $allocatedElsewhere = $allocatedCode !== null && $allocatedCode !== $viewer['agency'];
        if ((!$canSee && ($allocatedCode === null || $allocatedElsewhere)) || $allocatedElsewhere) {
            $status = 'sold';
        } elseif ($status === 'available') {
            $metas['price']   = $price;
            $metas['pricem2'] = $priceM2;
        }

        // getUnitStatusLabel(), then custom labels, then checkHeldStatusForAgent().
        $preLabelStatus = $status;
        if (in_array($status, RESERVED_STATUSES) || $status === 'held') {
            $label = 'Sold';
            $status = 'sold';
        } else {
            $label = statusLabel($status);
        }
        $label = $project['customAgentLabels'][$preLabelStatus] ?? $label;
        if ($unit['status'] === 'held') {
            $own = $ownReservation || $isSalesPerson;
            $label = $own ? 'Held' : 'Sold';
            $status = $own ? 'held' : 'sold';
        }
    } else {
        if ($colours) {
            $metas['statusColour'] = $colours['colour'];
        }
        if ($reservation) {
            $metas['reservationCode'] = $reservation['agencyCode'];
            $metas['reservationName'] = agencyName($project, $reservation['agencyCode']);
        }
        $label = statusLabel($status);
    }

    $metas['status']     = $status;
    $metas['class']      = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $status), '-'));
    $metas['statusName'] = $label;
    $metas['price']      = $price; // QUIRK: sent for every unit and role.
    // QUIRK: Allocation entities have no public properties, so each encodes as {}.
    $metas['allocations'] = array_map(function () {
        return new stdClass();
    }, $allocatedCodes);
    $metas['level'] = $unit['level'];
    if ($allocatedCode !== null) {
        $metas['allocatedCode'] = $allocatedCode;
        $metas['allocatedName'] = agencyName($project, $allocatedCode);
    }
    $metas['outgoings'] = $unit['outgoings'] ?? null;
    $metas['type']      = $unit['classification'] ?? '';

    return [
        'id'          => $unit['id'],
        'order'       => $unit['stockIdOrder'],
        'aspect'      => 'Other',
        'agentDimmed' => false,
        'area'        => 0,    // QUIRK: never set by getBuildingAdvance().
        'name'        => $unit['stockId'],
        'width'       => max(1, (int) ($unit['width'] ?? 1)),
        'metas'       => $metas,
        'blockId'     => null, // QUIRK: never set by getBuildingAdvance().
    ];
}

/** DashesToCamelCaseTrait::dashesToCamelCase($status, ' ') */
function statusLabel(string $status): string
{
    return ucwords(str_replace('-', ' ', str_replace('-green', '', $status)));
}

function agencyName(array $project, string $code): string
{
    return $project['agencies'][$code] ?? $code;
}
