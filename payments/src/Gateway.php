<?php
declare(strict_types=1);

/** Thrown by a gateway driver for a bad notification (wrong signature, unknown order, ...). */
class GatewayError extends Exception {}

/**
 * What every payment gateway driver (payments/gateways/<name>.php) has to do. The rest of the store
 * only talks to this interface, so switching gateway = a new driver file + 'gateway' in config.php.
 *
 * $order: ['id' => int, 'amount' => float, 'currency' => 'LKR', 'description' => string]
 * $buyer: ['name' => string, 'email' => string]
 * $urls:  ['return' => url, 'cancel' => url, 'notify' => url]
 */
interface Gateway
{
    /**
     * How to send the buyer to the gateway's payment page. Either
     *   ['method' => 'redirect', 'url' => ...]  or
     *   ['method' => 'post', 'url' => ..., 'fields' => [name => value]]  (an auto-submitted form).
     * A driver that creates its own transaction up front adds 'ref' => its id (stored on the order).
     */
    public function begin(array $order, array $buyer, array $urls): array;

    /**
     * The gateway's server-to-server notification (webhook). Must verify its signature and return
     *   ['order_id' => int (0 if the event only carries the gateway's id), 'ref' => gateway transaction id,
     *    'status' => 'paid'|'failed'|'cancelled'|'pending'|'refunded'|'ignored' (event we do not act on),
     *    'amount' => float, 'currency' => string]
     * or throw GatewayError.
     */
    public function parseNotify(array $post, string $raw): array;
}

/**
 * Optional: a gateway that can be asked about a transaction it created ('ref' from begin()). It lets the
 * return page confirm a payment without waiting for the webhook (needed on localhost, where the gateway
 * cannot call us, and a safety net when a webhook is late). Returns the same shape as parseNotify(), or null.
 */
interface StatusLookup
{
    public function lookup(string $ref): ?array;
}
