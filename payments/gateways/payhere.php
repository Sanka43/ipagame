<?php
declare(strict_types=1);
// PayHere (payhere.lk) - Sri Lankan cards, wallets and bank apps.
// config.php: 'payments' => ['gateway' => 'payhere', 'gateways' => ['payhere' => [
//     'merchant_id' => '...', 'merchant_secret' => '...', 'sandbox' => true]]]
// Merchant Portal -> Settings -> Domains & Credentials. The notify URL must be reachable from the
// internet, so use the live site (or a tunnel) to test the full flow.

return new class($cfg) implements Gateway {
    public function __construct(private array $cfg)
    {
        if (empty($cfg['merchant_id']) || empty($cfg['merchant_secret'])) throw new GatewayError('PayHere is not configured');
    }

    private function secretHash(): string
    {
        return strtoupper(md5((string)$this->cfg['merchant_secret']));
    }

    public function begin(array $order, array $buyer, array $urls): array
    {
        $amount = number_format($order['amount'], 2, '.', '');
        [$first, $last] = array_pad(explode(' ', trim($buyer['name']), 2), 2, '-');
        $mid = (string)$this->cfg['merchant_id'];
        return [
            'method' => 'post',
            'url' => !empty($this->cfg['sandbox']) ? 'https://sandbox.payhere.lk/pay/checkout' : 'https://www.payhere.lk/pay/checkout',
            'fields' => [
                'merchant_id' => $mid,
                'return_url' => $urls['return'],
                'cancel_url' => $urls['cancel'],
                'notify_url' => $urls['notify'],
                'order_id' => (string)$order['id'],
                'items' => $order['description'],
                'currency' => $order['currency'],
                'amount' => $amount,
                'first_name' => $first,
                'last_name' => $last,
                'email' => $buyer['email'],
                'phone' => '0000000000',
                'address' => 'N/A',
                'city' => 'Colombo',
                'country' => 'Sri Lanka',
                'hash' => strtoupper(md5($mid . $order['id'] . $amount . $order['currency'] . $this->secretHash())),
            ],
        ];
    }

    public function parseNotify(array $post, string $raw): array
    {
        foreach (['merchant_id', 'order_id', 'payhere_amount', 'payhere_currency', 'status_code', 'md5sig'] as $k) {
            if (!isset($post[$k])) throw new GatewayError("Missing $k");
        }
        $sig = strtoupper(md5($post['merchant_id'] . $post['order_id'] . $post['payhere_amount'] . $post['payhere_currency']
                              . $post['status_code'] . $this->secretHash()));
        if (!hash_equals($sig, strtoupper((string)$post['md5sig'])) || $post['merchant_id'] !== (string)$this->cfg['merchant_id']) {
            throw new GatewayError('Bad signature');
        }
        $status = ['2' => 'paid', '0' => 'pending', '-1' => 'cancelled', '-2' => 'failed', '-3' => 'refunded'][(string)$post['status_code']] ?? 'failed';
        return ['order_id' => (int)$post['order_id'], 'status' => $status, 'ref' => (string)($post['payment_id'] ?? ''),
                'amount' => (float)$post['payhere_amount'], 'currency' => (string)$post['payhere_currency']];
    }
};
