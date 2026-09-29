<?php
declare(strict_types=1);

/**
 * GET api/vehicles.php?q=&type=&date_from=&date_to=&page=
 * Search + paginate registered vehicles. Phone numbers are masked in this public listing.
 */
require_once __DIR__ . '/../includes/helpers.php';

api_bootstrap('vehicles', 'GET', false);

function valid_date(string $d): bool
{
    $dt = DateTime::createFromFormat('!Y-m-d', $d);
    return $dt !== false && $dt->format('Y-m-d') === $d;
}

$q        = mb_substr(clean_text($_GET['q'] ?? ''), 0, 100);
$type     = clean_text($_GET['type'] ?? '');
$dateFrom = clean_text($_GET['date_from'] ?? '');
$dateTo   = clean_text($_GET['date_to'] ?? '');
$page     = max(1, (int) ($_GET['page'] ?? 1));
$perPage  = (int) PER_PAGE;

if ($type !== '' && !in_array($type, BODY_TYPES, true)) {
    json_fail('VALIDATION_FAILED', 'Unknown vehicle type.', 422);
}
foreach (['date_from' => $dateFrom, 'date_to' => $dateTo] as $name => $val) {
    if ($val !== '' && !valid_date($val)) {
        json_fail('VALIDATION_FAILED', "Invalid $name. Use YYYY-MM-DD.", 422);
    }
}

$where = [];
$args = [];

if ($q !== '') {
    // Escape LIKE wildcards so a search for "50%" matches literally.
    $like = '%' . addcslashes($q, '\\%_') . '%';
    $where[] = "(plate_number LIKE ? ESCAPE '\\\\' OR owner_name LIKE ? ESCAPE '\\\\' OR make LIKE ? ESCAPE '\\\\'"
             . " OR model LIKE ? ESCAPE '\\\\' OR CONCAT(make, ' ', model) LIKE ? ESCAPE '\\\\')";
    array_push($args, $like, $like, $like, $like, $like);
    // Plates are stored without spaces, so "abc 123" should still find ABC123.
    $plateLike = '%' . addcslashes(normalize_plate($q), '\\%_') . '%';
    if ($plateLike !== $like && $plateLike !== '%%') {
        $where[0] = substr($where[0], 0, -1) . " OR plate_number LIKE ? ESCAPE '\\\\')";
        $args[] = $plateLike;
    }
}
if ($type !== '') {
    $where[] = 'body_type = ?';
    $args[] = $type;
}
if ($dateFrom !== '') {
    $where[] = 'created_at >= ?';
    $args[] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $where[] = 'created_at < DATE_ADD(?, INTERVAL 1 DAY)';
    $args[] = $dateTo . ' 00:00:00';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$pdo = db();
$st = $pdo->prepare("SELECT COUNT(*) FROM vehicles $whereSql");
$st->execute($args);
$total = (int) $st->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($page, $totalPages);

$st = $pdo->prepare(
    "SELECT id, plate_number, owner_name, phone, make, model, color, body_type, base_fee, currency, fee_converted, created_at
     FROM vehicles $whereSql ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?"
);
$i = 1;
foreach ($args as $a) {
    $st->bindValue($i++, $a, PDO::PARAM_STR);
}
$st->bindValue($i++, $perPage, PDO::PARAM_INT);
$st->bindValue($i, ($page - 1) * $perPage, PDO::PARAM_INT);
$st->execute();

$items = [];
while ($r = $st->fetch()) {
    $items[] = [
        'id'            => (int) $r['id'],
        'plate_number'  => $r['plate_number'],
        'owner_name'    => $r['owner_name'],
        'phone'         => mask_phone($r['phone']),
        'make'          => $r['make'],
        'model'         => $r['model'],
        'color'         => $r['color'],
        'body_type'     => $r['body_type'],
        'base_fee'      => (float) $r['base_fee'],
        'currency'      => $r['currency'],
        'fee_converted' => (float) $r['fee_converted'],
        'created_at'    => $r['created_at'],
    ];
}

json_ok([
    'items'       => $items,
    'page'        => $page,
    'per_page'    => $perPage,
    'total'       => $total,
    'total_pages' => $totalPages,
]);
