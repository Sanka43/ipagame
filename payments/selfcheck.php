<?php
declare(strict_types=1);
// Command line only: checks the payments config and, for Paddle, the keys and the $10 price.
//   C:\xampp\php\php.exe payments\selfcheck.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('API_LIB', true);
require __DIR__ . '/../api.php';

$ok = fn(string $m) => print("  [ok]   $m\n");
$bad = function (string $m): never { echo "  [FAIL] $m\n"; exit(1); };

echo "Gateway: " . (PAYMENTS['gateway'] ?: '(none)') . ", " . PAYMENTS['currency'] . ' ' . PAYMENTS['vip_price'] . "\n";
try {
    db()->query('SELECT 1 FROM orders LIMIT 1');
    db()->query('SELECT 1 FROM memberships LIMIT 1');
    $ok('tables orders + memberships exist');
} catch (Throwable $e) {
    $bad('import sql/payments.sql first');
}

try {
    $gw = pay_gateway();
} catch (ApiError $e) {
    $bad($e->getMessage());
}
$ok('driver loaded');

if (method_exists($gw, 'price')) {
    try {
        $p = $gw->price();
    } catch (GatewayError $e) {
        $bad($e->getMessage() . ' (check api_key, price_id and sandbox)');
    }
    $amount = ((int)$p['unit_price']['amount']) / 100;
    $cur = $p['unit_price']['currency_code'];
    $ok("price {$p['id']}: $cur $amount, status {$p['status']}, " . ($p['billing_cycle'] ? 'recurring' : 'one-time'));
    if ($p['billing_cycle']) $bad('the price must be one-time (no billing cycle)');
    if ($cur !== PAYMENTS['currency'] || abs($amount - (float)PAYMENTS['vip_price']) > 0.001) {
        $bad("the price ($cur $amount) does not match config (" . PAYMENTS['currency'] . ' ' . PAYMENTS['vip_price'] . ')');
    }
}
echo "All good.\n";
