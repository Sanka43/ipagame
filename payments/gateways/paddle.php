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
        // Pasted keys often carry spaces, quotes or a "Bearer " prefix: clean them so Paddle accepts the header.
        foreach (['api_key', 'client_token', 'price_id', 'webhook_secret'] as $k) {
            $this->cfg[$k] = trim((string)preg_replace('/^\s*Bearer\s+/i', '', trim((string)($cfg[$k] ?? ''), " \t\r\n\"'")));
        }
        // webhook_secret is only needed for webhooks (not on localhost, where pay_status asks Paddle instead).
        foreach (['api_key', 'client_token', 'price_id'] as $k) {
            if ($this->cfg[$k] === '') throw new GatewayError('Paddle is not configured');
        }
        if (!preg_match('/^pdl_(sdbx|live)_apikey_/', $this->cfg['api_key'])) {
            throw new GatewayError('Paddle api_key looks wrong: it must start with pdl_sdbx_apikey_ (sandbox) or pdl_live_apikey_ (live)');
        }
        // A full key is pdl_<env>_apikey_<id>_<secret>...; Paddle shows it once and a cut-off copy is a common mistake.
        if (!preg_match('/^pdl_(sdbx|live)_apikey_[^_]+_\S{8,}$/', $this->cfg['api_key'])) {
            throw new GatewayError('Paddle api_key looks cut off: copy the whole key (create a new one if you cannot see it again)');
        }
        if (!empty($this->cfg['sandbox']) !== str_contains($this->cfg['api_key'], '_sdbx_')) {
            throw new GatewayError("Paddle: 'sandbox' and the api_key do not match (a sandbox key contains _sdbx_)");
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

    /** The Paddle customer for this email (so the checkout opens with the member's email filled in), or null. */
    private function customerId(array $buyer): ?string
    {
        try {
            return (string)$this->api('/customers', ['email' => $buyer['email'], 'name' => mb_substr($buyer['name'], 0, 100)])['id'];
        } catch (GatewayError $e) {
            // Already a customer: Paddle's conflict message names the existing one ("... customer of id ctm_...").
            return preg_match('/\b(ctm_[0-9a-z]+)\b/', $e->getMessage(), $m) ? $m[1] : null;
        }
    }

    public function begin(array $order, array $buyer, array $urls): array
    {
        $body = [
            'items' => [['price_id' => $this->cfg['price_id'], 'quantity' => 1]],
            'custom_data' => ['order_id' => (string)$order['id']],
        ];
        if ($cust = $this->customerId($buyer)) $body['customer_id'] = $cust;
        $txn = $this->api('/transactions', $body);
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
