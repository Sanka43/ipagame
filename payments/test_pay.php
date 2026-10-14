<?php
declare(strict_types=1);
// The "payment page" of the fake gateway (payments/gateways/test.php) - local development only.
define('API_LIB', true);
require __DIR__ . '/../api.php';
header('Content-Type: text/html; charset=utf-8');

if (PAYMENTS['gateway'] !== 'test') { http_response_code(404); exit('Not found'); }
$gw = pay_gateway();                          // also refuses to run off localhost
$me = current_user();
$st = db()->prepare('SELECT * FROM orders WHERE id=?');
$st->execute([(int)($_GET['order'] ?? 0)]);
$o = $st->fetch();
if (!$o || !$me || (int)$o['user_id'] !== $me['id']) { http_response_code(404); exit('Not found'); }

$send = fn(string $status) => [
    'order_id' => (int)$o['id'], 'status' => $status, 'amount' => $o['amount'], 'currency' => $o['currency'],
    'sig' => $gw::sign((int)$o['id'], $status),
];
$from = pay_clean_from((string)($_GET['from'] ?? ''));
$back = 'return.html?order=' . $o['id'] . ($from !== '' ? '&from=' . $from : '');
$h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES);
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Test payment</title>
<style>body{font:16px system-ui;background:#111;color:#eee;display:grid;place-items:center;min-height:100vh;margin:0}
.box{background:#1c1c1e;border-radius:16px;padding:28px;width:min(360px,90vw)}h1{font-size:20px;margin:0 0 4px}
.muted{color:#999;font-size:14px}button{width:100%;padding:12px;border:0;border-radius:10px;font-size:16px;margin-top:10px;cursor:pointer}
.pay{background:#0a84ff;color:#fff}.fail{background:#333;color:#eee}</style></head>
<body><div class="box">
  <h1>Test gateway</h1>
  <p class="muted">Local development only. No real money moves.</p>
  <p><strong><?= $h(BRAND) ?> VIP membership</strong><br><?= $h($o['currency']) ?> <?= number_format((float)$o['amount'], 2) ?></p>
  <button class="pay" data-s="paid">Pay</button>
  <button class="fail" data-s="failed">Fail the payment</button>
  <button class="fail" data-s="cancelled">Cancel</button>
</div>
<script>
  const data = <?= json_encode(['paid' => $send('paid'), 'failed' => $send('failed'), 'cancelled' => $send('cancelled')]) ?>;
  document.querySelectorAll('button').forEach(b => b.addEventListener('click', async () => {
    b.disabled = true;
    await fetch('../api.php?action=pay_notify', { method: 'POST', body: new URLSearchParams(data[b.dataset.s]) });
    location.href = <?= json_encode($back) ?> + (b.dataset.s === 'paid' ? '' : '&cancel=1');
  }));
</script></body></html>
