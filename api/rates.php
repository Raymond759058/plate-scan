<?php
declare(strict_types=1);

/**
 * GET api/rates.php
 * Returns USD-based exchange rates for the supported currencies.
 * Served from the MySQL cache (1 h TTL); refreshes from open.er-api.com, then
 * api.frankfurter.dev, and falls back to the last cached rates if both are down.
 */
require_once __DIR__ . '/../includes/helpers.php';

api_bootstrap('rates', 'GET', false);
header('Cache-Control: public, max-age=60');

try {
    json_ok(get_rates());
} catch (RuntimeException $e) {
    if ($e->getMessage() !== 'RATES_UNAVAILABLE') {
        throw $e;
    }
    json_fail('RATES_UNAVAILABLE', 'Exchange rates are temporarily unavailable. Please try again shortly.', 503);
}
