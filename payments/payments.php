<?php
declare(strict_types=1);
// VIP membership payments: one-time fee -> order -> gateway -> webhook -> membership.
// Loaded by api.php (actions pay_checkout, pay_notify, pay_status). Everything gateway-specific
// lives in payments/gateways/<name>.php behind the Gateway interface - see payments/README.md.

require_once __DIR__ . '/src/Gateway.php';

/** The gateway driver chosen by config.php ('payments' => ['gateway' => 'paddle']). */
function pay_gateway(): Gateway
{
    $name = (string)PAYMENTS['gateway'];
    $file = __DIR__ . "/gateways/$name.php";
    if (!preg_match('/^[a-z0-9_]+$/', $name) || !is_file($file)) throw new ApiError('Payments are not set up', 503);
    $cfg = (array)(PAYMENTS['gateways'][$name] ?? []);
    try {
        return require $file;       // the file returns the driver and reads $cfg
    } catch (GatewayError $e) {
        throw new ApiError($e->getMessage(), 503);
    }
}

/** The one-time VIP fee in the payments currency. */
function pay_vip_price(): float
{
    return round((float)PAYMENTS['vip_price'], 2);
}

function is_vip(?int $userId): bool
{
    if (!$userId) return false;
    try {
        $st = db()->prepare('SELECT 1 FROM memberships WHERE user_id=?');
        $st->execute([$userId]);
        return (bool)$st->fetchColumn();
    } catch (PDOException $e) {
        return false;               // sql/payments.sql not imported yet
    }
}

/** Admins and VIP members may download premium games. */
function can_download_premium(): bool
{
    if (is_admin()) return true;
    $me = current_user();
    return $me !== null && is_vip($me['id']);
}

/** Only a plain store slug may travel through the return URL. */
function pay_clean_from(string $s): string
{
    return preg_match('/^[a-z0-9][a-z0-9-]{0,190}$/', $s) ? $s : '';
}

/** POST pay_checkout {from?}: creates a pending VIP order and returns how to reach the gateway. */
function pay_checkout(): array
{
    $me = current_user() ?? throw new ApiError('Login required', 401);
    if (is_vip($me['id'])) throw new ApiError('You are already a VIP member', 409);
    $price = pay_vip_price();
    if ($price <= 0) throw new ApiError('Payments are not set up', 503);
    $from = pay_clean_from((string)(body()['from'] ?? ''));

    $gw = pay_gateway();
    $cur = (string)PAYMENTS['currency'];
    // The game they were after (shown in the admin Payments tab); needs sql/orders_game.sql.
    $game = $from !== '' ? find_item(null, $from) : null;
    $cols = ['user_id' => $me['id'], 'kind' => 'vip', 'amount' => $price, 'currency' => $cur, 'gateway' => (string)PAYMENTS['gateway'],
             'status' => 'pending', 'created_at' => gmdate('Y-m-d H:i:s')];
    if ($game && has_column('orders', 'game_name')) $cols += ['game_id' => $game['id'], 'game_name' => mb_substr($game['name'], 0, 255)];
    db()->prepare('INSERT INTO orders (' . implode(',', array_keys($cols)) . ') VALUES (' . rtrim(str_repeat('?,', count($cols)), ',') . ')')
        ->execute(array_values($cols));
    $id = (int)db()->lastInsertId();

    $base = site_url();
    $q = "order=$id" . ($from !== '' ? "&from=$from" : '');
    try {
        $go = $gw->begin(
            ['id' => $id, 'amount' => $price, 'currency' => $cur, 'description' => BRAND . ' VIP membership'],
            ['name' => $me['username'], 'email' => $me['email']],
            ['return' => "$base/payments/return.html?$q", 'cancel' => "$base/payments/return.html?$q&cancel=1",
             'notify' => "$base/api.php?action=pay_notify"]
        );
    } catch (GatewayError $e) {
        throw new ApiError($e->getMessage(), 503);
    }
    // Some gateways create their own transaction up front: remember its id (webhooks may only carry that).
    if (!empty($go['ref'])) db()->prepare('UPDATE orders SET gateway_ref=? WHERE id=?')->execute([(string)$go['ref'], $id]);
    unset($go['ref']);
    return $go + ['order' => $id];
}

/** Gateway webhook: verified by the driver, then applied to the order (safe to receive twice). */
function pay_notify(): void
{
    header('Content-Type: text/plain; charset=utf-8');
    try {
        $n = pay_gateway()->parseNotify($_POST, file_get_contents('php://input') ?: '');
    } catch (GatewayError $e) {
        http_response_code(400);
        exit('invalid');
    }
    [$code, $text] = pay_apply($n);
    http_response_code($code);
    exit($text);
}

/** Applies a verified, normalised gateway event (webhook or lookup) to its order. Returns [http code, text]. */
function pay_apply(array $n): array
{
    if ($n['status'] === 'ignored') return [200, 'ok'];          // an event type we do not act on

    $pdo = db();
    $pdo->beginTransaction();
    // The order is found by our own id, or (refunds) by the gateway's transaction id.
    if ($n['order_id'] > 0) {
        $st = $pdo->prepare('SELECT * FROM orders WHERE id=? FOR UPDATE');
        $st->execute([$n['order_id']]);
    } else {
        $st = $pdo->prepare('SELECT * FROM orders WHERE gateway=? AND gateway_ref=? FOR UPDATE');
        $st->execute([(string)PAYMENTS['gateway'], $n['ref']]);
    }
    $o = $st->fetch();
    if (!$o || $o['gateway'] !== PAYMENTS['gateway']) { $pdo->rollBack(); return [404, 'unknown order']; }

    if ($n['status'] === 'paid' && $o['status'] !== 'paid') {
        // What was paid has to match what we asked for.
        if (abs((float)$o['amount'] - $n['amount']) > 0.001 || $o['currency'] !== $n['currency']) {
            $pdo->rollBack();
            return [400, 'amount mismatch'];
        }
        $now = gmdate('Y-m-d H:i:s');
        $pdo->prepare("UPDATE orders SET status='paid', gateway_ref=?, paid_at=? WHERE id=?")->execute([$n['ref'] ?: $o['gateway_ref'], $now, $o['id']]);
        $pdo->prepare('INSERT IGNORE INTO memberships (user_id, order_id, created_at) VALUES (?,?,?)')->execute([$o['user_id'], $o['id'], $now]);
    } elseif ($n['status'] === 'refunded' && $o['status'] === 'paid') {
        $pdo->prepare("UPDATE orders SET status='refunded' WHERE id=?")->execute([$o['id']]);
        $pdo->prepare('DELETE FROM memberships WHERE order_id=?')->execute([$o['id']]);
    } elseif (in_array($n['status'], ['failed', 'cancelled'], true) && $o['status'] === 'pending') {
        $pdo->prepare('UPDATE orders SET status=? WHERE id=?')->execute([$n['status'], $o['id']]);
    }
    $pdo->commit();
    return [200, 'ok'];
}

/**
 * GET pay_status?order=ID: the member's own order, for the page the gateway sends them back to.
 * A still-pending order is checked with the gateway itself when the driver can (StatusLookup), so the
 * buyer does not depend on the webhook arriving first (it cannot reach localhost at all).
 */
function pay_status(): array
{
    $me = current_user() ?? throw new ApiError('Login required', 401);
    $st = db()->prepare('SELECT * FROM orders WHERE id=? AND user_id=?');
    $st->execute([(int)($_GET['order'] ?? 0), $me['id']]);
    $o = $st->fetch() ?: throw new ApiError('Not found', 404);

    if ($o['status'] === 'pending' && $o['gateway_ref'] && $o['gateway'] === PAYMENTS['gateway']) {
        try {
            $gw = pay_gateway();
            $n = $gw instanceof StatusLookup ? $gw->lookup((string)$o['gateway_ref']) : null;
            // Only trust an answer that is about this very order.
            if ($n && ($n['order_id'] === 0 || $n['order_id'] === (int)$o['id'])) {
                pay_apply(['order_id' => (int)$o['id']] + $n);
                $st->execute([(int)$o['id'], $me['id']]);
                $o = $st->fetch();
            }
        } catch (Throwable $e) {
            // The webhook will still settle it.
        }
    }
    return ['status' => $o['status']];
}

/** Admin Payments tab: totals over all orders plus the latest 2000 with the member and the game they wanted. */
function pay_admin_overview(): array
{
    $game = has_column('orders', 'game_name') ? 'o.game_id, o.game_name' : 'NULL AS game_id, NULL AS game_name';
    $rows = db()->query("SELECT o.id, o.user_id, o.kind, o.amount, o.currency, o.gateway, o.gateway_ref, o.status, o.created_at, o.paid_at,
                                $game, u.username, u.email
                         FROM orders o LEFT JOIN users u ON u.id = o.user_id ORDER BY o.id DESC LIMIT 2000")->fetchAll();
    // Sums are in Sri Lanka time boundaries: today / this month start at 00:00 UTC+5:30.
    $sl = new DateTimeImmutable('now', new DateTimeZone('Asia/Colombo'));
    $utc = fn(DateTimeImmutable $d) => $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $today = $utc($sl->setTime(0, 0));
    $month = $utc($sl->modify('first day of this month')->setTime(0, 0));
    $t = db()->prepare("SELECT COALESCE(SUM(status='paid'),0) AS paid_n, COALESCE(SUM(CASE WHEN status='paid' THEN amount END),0) AS paid_sum,
                               COALESCE(SUM(status='paid' AND paid_at >= ?),0) AS today_n, COALESCE(SUM(CASE WHEN status='paid' AND paid_at >= ? THEN amount END),0) AS today_sum,
                               COALESCE(SUM(status='paid' AND paid_at >= ?),0) AS month_n, COALESCE(SUM(CASE WHEN status='paid' AND paid_at >= ? THEN amount END),0) AS month_sum,
                               COALESCE(SUM(status='refunded'),0) AS refunded_n, COALESCE(SUM(CASE WHEN status='refunded' THEN amount END),0) AS refunded_sum,
                               COALESCE(SUM(status='pending'),0) AS pending_n, COALESCE(SUM(status IN ('failed','cancelled')),0) AS failed_n
                        FROM orders");
    $t->execute([$today, $today, $month, $month]);
    $s = $t->fetch();
    return [
        'currency' => PAYMENTS['currency'],
        'totals' => array_map(fn($v) => str_contains((string)$v, '.') ? (float)$v : (int)$v, $s),
        'orders' => array_map(fn($r) => [
            'id' => (int)$r['id'], 'user_id' => (int)$r['user_id'], 'username' => $r['username'], 'email' => $r['email'],
            'amount' => (float)$r['amount'], 'currency' => $r['currency'], 'gateway' => $r['gateway'], 'ref' => $r['gateway_ref'],
            'status' => $r['status'], 'game' => $r['game_name'], 'game_id' => $r['game_id'] === null ? null : (int)$r['game_id'],
            'created_at' => iso_date($r['created_at']), 'paid_at' => iso_date($r['paid_at']),
        ], $rows),
    ];
}
