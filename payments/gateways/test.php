<?php
declare(strict_types=1);
// Fake gateway for local development: shows a page with "Pay" / "Fail" buttons (payments/test_pay.php)
// that sends the same kind of notification a real gateway would. Refuses to run off localhost.
// config.php: 'payments' => ['gateway' => 'test']

return new class($cfg) implements Gateway {
    public function __construct(private array $cfg)
    {
        $host = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? '');
        if (PHP_SAPI !== 'cli' && !in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)) throw new GatewayError('The test gateway only works on localhost');
    }

    /** Signature the fake notification carries (test_pay.php and parseNotify share it). */
    public static function sign(int $orderId, string $status): string
    {
        return hash_hmac('sha256', "$orderId|$status", 'ipagame-test-gateway');
    }

    public function begin(array $order, array $buyer, array $urls): array
    {
        parse_str((string)parse_url($urls['return'], PHP_URL_QUERY), $q);   // keeps ?from=<slug> for the way back
        return ['method' => 'redirect', 'url' => dirname($urls['return']) . '/test_pay.php?' . http_build_query(['order' => $order['id'], 'from' => $q['from'] ?? ''])];
    }

    public function parseNotify(array $post, string $raw): array
    {
        $id = (int)($post['order_id'] ?? 0);
        $status = (string)($post['status'] ?? '');
        if (!hash_equals(self::sign($id, $status), (string)($post['sig'] ?? ''))) throw new GatewayError('Bad signature');
        return ['order_id' => $id, 'status' => $status, 'ref' => 'TEST-' . $id, 'amount' => (float)($post['amount'] ?? 0),
                'currency' => (string)($post['currency'] ?? '')];
    }
};
