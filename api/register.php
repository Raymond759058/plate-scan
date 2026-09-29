<?php
declare(strict_types=1);

/**
 * POST api/register.php   (JSON body + X-CSRF-Token header)
 * Validates, sanitises and stores a vehicle registration. The fee is always
 * calculated on the server from cached exchange rates; the client's number is never trusted.
 */
require_once __DIR__ . '/../includes/helpers.php';

api_bootstrap('register', 'POST', true);

[$v, $errors] = validate_vehicle(read_json_body());
if ($errors) {
    json_fail('VALIDATION_FAILED', 'Please correct the highlighted fields.', 422, ['fields' => $errors]);
}

try {
    $rates = get_rates();
} catch (RuntimeException $e) {
    if ($e->getMessage() !== 'RATES_UNAVAILABLE') {
        throw $e;
    }
    json_fail('RATES_UNAVAILABLE', 'Exchange rates are temporarily unavailable. Please try again shortly.', 503);
}

$baseFee = round((float) REGISTRATION_FEE_USD, 2);
$feeConverted = convert_usd($baseFee, $v['currency'], $rates['rates']);

try {
    $st = db()->prepare(
        'INSERT INTO vehicles (plate_number, owner_name, phone, make, model, color, body_type, base_fee, currency, fee_converted)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $st->execute([
        $v['plate_number'], $v['owner_name'], $v['phone'], $v['make'], $v['model'],
        $v['color'], $v['body_type'], $baseFee, $v['currency'], $feeConverted,
    ]);
} catch (PDOException $e) {
    if ((int) ($e->errorInfo[1] ?? 0) === 1062) {   // duplicate key on uq_plate_number
        json_fail('PLATE_EXISTS', 'This plate number is already registered.', 409, ['fields' => ['plate_number' => 'This plate number is already registered.']]);
    }
    throw $e;
}

json_ok([
    'id'            => (int) db()->lastInsertId(),
    'plate_number'  => $v['plate_number'],
    'base_fee'      => $baseFee,
    'currency'      => $v['currency'],
    'fee_converted' => $feeConverted,
    'rate'          => $rates['rates'][$v['currency']],
    'rate_source'   => $rates['source'],
], 201);
