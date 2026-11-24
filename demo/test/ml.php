<?php
/**
 * Decision tree evaluation and Smart-inspired predicate generation (case study,
 * Section III.E). Shared by matrix-endpoint.php and query_items.php.
 *
 * Model file shape (demo/test/model.json): {"target_class": 1, "features": [...],
 * "tree": node} where node = {"feature","threshold","left","right"} or {"class"}.
 * Left branch = feature <= threshold, right = feature > threshold (scikit-learn).
 */
declare(strict_types=1);

function mlModel(?string $path = null): array
{
    static $cache = [];
    $path ??= getenv('ML_MODEL') ?: __DIR__ . '/model.json';
    return $cache[$path] ??= json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
}

/** Walk the tree for one record (feature => value): left when <= threshold, else right. */
function mlPredict(array $node, array $row): int
{
    while (!isset($node['class'])) {
        $v = (float) ($row[$node['feature']] ?? 0);
        $node = $v <= $node['threshold'] ? $node['left'] : $node['right'];
    }
    return (int) $node['class'];
}

/** True when the model assigns the record to the target class (the ML predicate). */
function mlAccepts(array $model, array $row): bool
{
    return mlPredict($model['tree'], $row) === (int) $model['target_class'];
}

/**
 * Step 1: every root-to-leaf path ending in the target class, as a list of
 * [feature, op, threshold] conditions ('<=' for a left branch, '>' for a right).
 */
function mlTargetPaths(array $node, int $target, array $path = []): array
{
    if (isset($node['class'])) {
        return (int) $node['class'] === $target ? [$path] : [];
    }
    return array_merge(
        mlTargetPaths($node['left'],  $target, [...$path, [$node['feature'], '<=', $node['threshold']]]),
        mlTargetPaths($node['right'], $target, [...$path, [$node['feature'], '>',  $node['threshold']]]),
    );
}

/**
 * Step 2 (atomic): for each feature, the range permitted across ALL target paths.
 * A bound survives only when every path constrains that side; otherwise a
 * qualifying record could fall outside it and the predicate would stop being a
 * necessary condition. Returns [feature => ['lower' => x|null, 'upper' => y|null]].
 */
function mlAtomicBounds(array $paths): array
{
    $perPath = [];
    foreach ($paths as $i => $path) {
        foreach ($path as [$f, $op, $t]) {
            $b = &$perPath[$f][$i];
            $b ??= ['lower' => null, 'upper' => null];
            if ($op === '<=') $b['upper'] = $b['upper'] === null ? $t : min($b['upper'], $t);
            else              $b['lower'] = $b['lower'] === null ? $t : max($b['lower'], $t);
            unset($b);
        }
    }
    $bounds = [];
    $n = count($paths);
    foreach ($perPath as $f => $byPath) {
        if (count($byPath) < $n) continue;                       // unconstrained on some path
        $lowers = array_column($byPath, 'lower');
        $uppers = array_column($byPath, 'upper');
        $lower  = in_array(null, $lowers, true) ? null : min($lowers);
        $upper  = in_array(null, $uppers, true) ? null : max($uppers);
        if ($lower !== null || $upper !== null) $bounds[$f] = ['lower' => $lower, 'upper' => $upper];
    }
    return $bounds;
}

/**
 * Step 3: the predicates as parameterised SQL on the given table alias.
 * atomic    -> conjunction of per-column bounds
 * composite -> OR of the full path conjunctions (tight: exactly the target region)
 * Returns ['sql' => string, 'params' => [...], 'paths' => [...], 'bounds' => [...]].
 */
function mlPredicates(array $model, string $mode = 'atomic', string $alias = 'units'): array
{
    $paths  = mlTargetPaths($model['tree'], (int) $model['target_class']);
    $bounds = mlAtomicBounds($paths);
    $params = []; $n = 0;
    $bind = static function ($v) use (&$params, &$n): string {
        $name = ':ml' . $n++;
        $params[$name] = $v;
        return $name;
    };
    if ($mode === 'atomic') {
        $parts = [];
        foreach ($bounds as $f => $b) {
            if ($b['lower'] !== null) $parts[] = "$alias.$f > " . $bind($b['lower']);
            if ($b['upper'] !== null) $parts[] = "$alias.$f <= " . $bind($b['upper']);
        }
        $sql = implode(' AND ', $parts);
    } elseif ($mode === 'composite') {
        $disj = [];
        foreach ($paths as $path) {
            $conj = [];
            foreach ($path as [$f, $op, $t]) $conj[] = "$alias.$f $op " . $bind($t);
            $disj[] = '(' . implode(' AND ', $conj) . ')';
        }
        $sql = $disj === [] ? '' : '(' . implode("\n         OR ", $disj) . ')';
    } else {
        throw new InvalidArgumentException("unknown predicate mode '$mode'");
    }
    return ['sql' => $sql, 'params' => $params, 'paths' => $paths, 'bounds' => $bounds];
}
