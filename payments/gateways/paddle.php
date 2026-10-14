<?php
declare(strict_types=1);
// Paddle Billing (paddle.com) - cards and wallets worldwide, Paddle is the merchant of record (USD).
// config.php: 'payments' => ['gateway' => 'paddle', 'currency' => 'USD', 'vip_price' => 10, 'gateways' => ['paddle' => [
//     'api_key' => 'pdl_live_apikey_...',      // Paddle -> Developer tools -> Authentication
//     'client_token' => 'live_...',            // same page, client-side token (public)
//     'price_id' => 'pri_...',                 // Catalog -> a one-time product with a $10.00 USD price
//     'webhook_secret' => 'pdl_ntfset_...',    // Developer tools -> Notifications -> the destination's secret
//     'sandbox' => false]]]
// Paddle dashboard setup: approve this site's domain (Checkout -> Checkout settings -> Approved domains /
// Default payment link) and add a notification destination: https://<site>/api.php?action=pay_notify with
// the events transaction.completed, transaction.payment_failed and adjustment.updated.
// The buyer pays in Paddle's overlay on payments/paddle_checkout.html.

return new class($cfg) implements Gateway, StatusLookup {
    public function __construct(private array $cfg)
    {
        // webhook_secret is only needed for webhooks (not on localhost, where pay_status asks Paddle instead).
        foreach (['api_key', 'client_token', 'price_id'] as $k) {
            if (empty($cfg[$k])) throw new GatewayError('Paddle is not configured');
        }
    }

    private function api(string $path, ?array $body = null): array
    {
        $host = !empty($this->cfg['sandbox']) ? 'https://sandbox-api.paddle.com' : 'https://api.paddle.com';
        $ch = curl_init($host . $path);
        $opts = [
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->cfg['api_key'], 'Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
        ];
        if ($body !== null) $opts += [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body)];
        curl_setopt_array($ch, $opts);
        $res = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $data = is_string($res) ? json_decode($res, true) : null;
        if ($code < 200 || $code >= 300 || !isset($data['data'])) {
            throw new GatewayError('Paddle: ' . ($data['error']['detail'] ?? ($res === false ? 'no connection' : "HTTP $code")));
        }
        return $data['data'];
    }

    /** Public so payments/selfcheck.php can verify the keys and the price. */
    public function price(): array
    {
        return $this->api('/prices/' . rawurlencode((string)$this->cfg['price_id']));
    }

    /** Asks Paddle about a transaction (used when the webhook cannot reach us, e.g. on localhost). */
    public function lookup(string $ref): ?array
    {
        $d = $this->api('/transactions/' . rawurlencode($ref));
        $item = $d['items'][0] ?? [];
        if (($item['price']['id'] ?? '') !== $this->cfg['price_id']) return null;
        $status = match ($d['status'] ?? '') { 'paid', 'completed' => 'paid', 'canceled' => 'cancelled', default => 'pending' };
        return ['order_id' => (int)($d['custom_data']['order_id'] ?? 0), 'status' => $status, 'ref' => (string)$d['id'],
                'amount' => ((int)($item['price']['unit_price']['amount'] ?? 0)) / 100, 'currency' => (string)($d['currency_code'] ?? '')];
    }

    public function begin(array $order, array $buyer, array $urls): array
    {
        $txn = $this->api('/transactions', [
            'items' => [['price_id' => $this->cfg['price_id'], 'quantity' => 1]],
            'custom_data' => ['order_id' => (string)$order['id']],
        ]);
        $page = dirname($urls['return']) . '/paddle_checkout.html?' . http_build_query([
            'txn' => $txn['id'], 'token' => $this->cfg['client_token'],
            'env' => !empty($this->cfg['sandbox']) ? 'sandbox' : 'live', 'success' => $urls['return'], 'cancel' => $urls['cancel'],
        ]);
        return ['method' => 'redirect', 'url' => $page, 'ref' => $txn['id']];
    }

    public function parseNotify(array $post, string $raw): array
    {
        if (empty($this->cfg['webhook_secret'])) throw new GatewayError('No webhook secret configured');
        // Paddle-Signature: ts=1671552777;h1=<hmac sha256 of "ts:body">
        $parts = [];
        foreach (explode(';', (string)($_SERVER['HTTP_PADDLE_SIGNATURE'] ?? '')) as $kv) {
            [$k, $v] = array_pad(explode('=', $kv, 2), 2, '');
            $parts[$k] = $v;
        }
        $ts = (int)($parts['ts'] ?? 0);
        $sig = hash_hmac('sha256', $ts . ':' . $raw, (string)$this->cfg['webhook_secret']);
        if (!$ts || abs(time() - $ts) > 300 || !hash_equals($sig, (string)($parts['h1'] ?? ''))) throw new GatewayError('Bad signature');

        $ev = json_decode($raw, true);
        if (!is_array($ev) || !isset($ev['event_type'], $ev['data'])) throw new GatewayError('Bad event');
        $d = $ev['data'];
        $ignored = ['order_id' => 0, 'status' => 'ignored', 'ref' => '', 'amount' => 0.0, 'currency' => ''];

        switch ($ev['event_type']) {
            case 'transaction.completed':
            case 'transaction.payment_failed':
                $item = $d['items'][0] ?? [];
                // Only our $10 price counts; the amount we report is that price, not what a coupon or tax changed.
                if (($item['price']['id'] ?? '') !== $this->cfg['price_id']) throw new GatewayError('Unexpected price');
                return [
                    'order_id' => (int)($d['custom_data']['order_id'] ?? 0),
                    'status' => $ev['event_type'] === 'transaction.completed' ? 'paid' : 'failed',
                    'ref' => (string)$d['id'],
                    'amount' => ((int)($item['price']['unit_price']['amount'] ?? 0)) / 100,
                    'currency' => (string)($d['currency_code'] ?? ''),
                ];
            case 'adjustment.updated':
                if (($d['action'] ?? '') !== 'refund' || ($d['status'] ?? '') !== 'approved') return $ignored;
                return ['order_id' => 0, 'status' => 'refunded', 'ref' => (string)($d['transaction_id'] ?? ''), 'amount' => 0.0, 'currency' => ''];
            default:
                return $ignored;
        }
    }
};
