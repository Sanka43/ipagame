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
    db()->prepare('INSERT INTO orders (user_id, kind, amount, currency, gateway, status, created_at) VALUES (?,?,?,?,?,?,?)')
        ->execute([$me['id'], 'vip', $price, $cur, (string)PAYMENTS['gateway'], 'pending', gmdate('Y-m-d H:i:s')]);
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
