# VIP membership payments

A member pays once ($10 by default) and becomes **VIP**: they can download every premium game.
Free games are open to everyone. Everything about collecting the money lives in this folder; the rest
of the site only calls three API actions (`pay_checkout`, `pay_notify`, `pay_status`) and never knows
which gateway is behind them.

```
payments/
  payments.php          order, checkout, webhook, membership (gateway independent)
  src/Gateway.php       the interface every gateway driver implements
  gateways/
    paddle.php          Paddle Billing (USD, card/wallets worldwide)  <- live
    payhere.php         PayHere (Sri Lanka)
    test.php            fake gateway for localhost
  paddle_checkout.html  Paddle's payment overlay page (Paddle driver only)
  test_pay.php          the fake gateway's payment page (localhost only)
  return.html           where the buyer lands after paying (waits for the webhook, then says paid)
../donate.html          the page a premium Download button leads to
../download.php         serves uploaded IPAs to a signed link (see "Download lock")
```

Database: `sql/game_access.sql` (free/premium per game) and `sql/payments.sql` (`orders`, `memberships`).

## Flow

1. A non-VIP member taps **Download** on a premium game -> `donate.html?from=<slug>`.
2. **Donate** calls `pay_checkout`: a `pending` order is created and the driver returns the hand-off.
3. The buyer pays on the gateway. The gateway calls `api.php?action=pay_notify`; the driver verifies the
   signature, we check the amount and currency, mark the order `paid` and add a `memberships` row.
   A refund removes the membership again.
4. The gateway sends the buyer to `return.html`, which polls `pay_status` until the order is paid.

Admins can also give or take VIP by hand: admin panel -> Users -> Manage -> "VIP member".

## Download lock

- Premium game + not VIP (and not admin): the API (`get`) returns **empty download links**.
- Uploaded IPAs (`uploads/ipa/`) are never served from a public URL: `uploads/ipa/.htaccess` denies direct
  access, and members get `/download/<expires>.<signature>/<file>.ipa` (rewritten to `download.php` in the
  root `.htaccess`), valid 6 hours. It needs no cookies, so LiveContainer can download it.
- Limit: a game whose link points at another host (GitHub releases, a file host) can only be hidden from
  the API, not protected there. App Store links are public anyway. Host premium IPAs by uploading them.

## Switch gateway

1. Copy `gateways/paddle.php` to `gateways/<name>.php` and implement `begin()` and `parseNotify()`.
2. In `config.php` set `'payments' => ['gateway' => '<name>', 'gateways' => ['<name>' => [...keys...]]]`.

Nothing else changes. The driver file receives its config as `$cfg`.

## Paddle setup (live)

1. paddle.com -> create the account and a **one-time product** with a **$10.00 USD** price. Copy its price id (`pri_...`).
2. Developer tools -> Authentication: create an API key (`api_key`) and a client-side token (`client_token`).
3. Checkout settings: set the default payment link and approve the store's domain.
4. Developer tools -> Notifications: add the destination `https://<site>/api.php?action=pay_notify` with the
   events `transaction.completed`, `transaction.payment_failed` and `adjustment.updated`; copy its secret (`webhook_secret`).
5. Fill the `paddle` block in the server's `config.php` (see `config.example.php`). Test first with
   `'sandbox' => true` and the sandbox account, then switch to live keys.

## Local testing

`'gateway' => 'test'` (the default in your local `config.php`): Donate opens a fake payment page with
Pay / Fail buttons. It refuses to run on any host except localhost. Paddle cannot reach `localhost`
for the webhook, so test the real gateway on the live site with the sandbox.
