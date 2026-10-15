<?php
// Web Push to phones that added the store to their Home Screen (iOS 16.4+), without Composer:
// VAPID (RFC 8292) + aes128gcm payload encryption (RFC 8291) with openssl. Tables: sql/push.sql.
// Included by api.php; the functions here use its db().

function b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64u_dec(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/')); }

/** False until sql/push.sql and sql/push_release.sql have been imported. */
function push_ready(): bool
{
    static $ok;
    if ($ok === null) {
        try { $ok = (bool)db()->query("SHOW TABLES LIKE 'push_subscriptions'")->fetch() && has_column('games', 'pushed_at'); } catch (Throwable) { $ok = false; }
    }
    return $ok;
}

/** An EC P-256 key as ['pem' => private key, 'pub' => raw 65-byte public point]. */
function push_new_key(): array
{
    $opt = ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC];
    $k = openssl_pkey_new($opt);
    if (!$k && is_file($cnf = 'C:/xampp/apache/conf/openssl.cnf')) {   // local XAMPP on Windows cannot find openssl.cnf by itself
        $opt['config'] = $cnf;
        $k = openssl_pkey_new($opt);
    }
    openssl_pkey_export($k, $pem, null, isset($opt['config']) ? ['config' => $opt['config']] : []);
    $ec = openssl_pkey_get_details($k)['ec'];
    return ['pem' => $pem, 'pub' => "\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT)];
}

/** The site's VAPID key pair, created on first use and kept in the database. */
function push_vapid(): array
{
    static $v;
    if ($v) return $v;
    $row = db()->query("SELECT v FROM push_keys WHERE k='vapid'")->fetchColumn();
    if (!$row) {
        $k = push_new_key();
        $row = json_encode(['pem' => $k['pem'], 'pub' => b64u($k['pub'])]);
        db()->prepare("INSERT IGNORE INTO push_keys (k, v) VALUES ('vapid', ?)")->execute([$row]);
        $row = db()->query("SELECT v FROM push_keys WHERE k='vapid'")->fetchColumn();   // another request may have won
    }
    return $v = json_decode($row, true);
}

/** ES256 JWT proving to the push service that this server owns the VAPID key. */
function push_jwt(string $endpoint): string
{
    $p = parse_url($endpoint);
    $sub = str_starts_with(SITE_URL, 'https://') ? SITE_URL : 'mailto:' . MAIL['from'];
    $in = b64u('{"typ":"JWT","alg":"ES256"}') . '.' . b64u(json_encode([
        'aud' => $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : ''),
        'exp' => time() + 12 * 3600, 'sub' => $sub,
    ]));
    openssl_sign($in, $der, push_vapid()['pem'], OPENSSL_ALGO_SHA256);
    // DER SEQUENCE { INTEGER r, INTEGER s }  ->  raw r || s (32 bytes each)
    $i = 3;
    $rl = ord($der[$i]); $r = substr($der, $i + 1, $rl); $i += $rl + 2;
    $sl = ord($der[$i]); $s = substr($der, $i + 1, $sl);
    $raw = str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT) . str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
    return $in . '.' . b64u($raw);
}

/** Encrypts $payload for one subscription (RFC 8291). Returns the request body, or null on a bad key. */
function push_encrypt(string $payload, string $p256dh, string $auth): ?string
{
    $ua = b64u_dec($p256dh);
    $authSecret = b64u_dec($auth);
    if (strlen($ua) !== 65 || $ua[0] !== "\x04" || $authSecret === '') return null;
    $peer = openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(
        hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $ua), 64, "\n") . "-----END PUBLIC KEY-----\n");
    if (!$peer) return null;
    $as = push_new_key();
    $ecdh = openssl_pkey_derive($peer, openssl_pkey_get_private($as['pem']), 32);
    if ($ecdh === false) return null;
    $ikm = hash_hkdf('sha256', $ecdh, 32, "WebPush: info\0" . $ua . $as['pub'], $authSecret);
    $salt = random_bytes(16);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
    $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    return $salt . pack('N', 4096) . chr(65) . $as['pub'] . $cipher . $tag;
}

/**
 * Sends one notification to each subscription row (id, endpoint, p256dh, auth) in parallel.
 * Subscriptions the push service says are gone (404/410) or whose keys are bad are deleted.
 * Returns ['sent' => n, 'failed' => n, 'removed' => n].
 */
function push_send(array $subs, array $msg): array
{
    $out = ['sent' => 0, 'failed' => 0, 'removed' => 0];
    $json = json_encode($msg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $pub = push_vapid()['pub'];
    foreach (array_chunk($subs, 20) as $chunk) {
        $mh = curl_multi_init();
        $handles = [];
        foreach ($chunk as $s) {
            $body = push_encrypt($json, $s['p256dh'], $s['auth']);
            if ($body === null) { $out['removed']++; push_forget((int)$s['id']); continue; }
            $ch = curl_init($s['endpoint']);
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/octet-stream', 'Content-Encoding: aes128gcm', 'TTL: 86400', 'Urgency: normal',
                    'Authorization: vapid t=' . push_jwt($s['endpoint']) . ', k=' . $pub,
                ],
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[(int)$s['id']] = $ch;
        }
        do { curl_multi_exec($mh, $running); if ($running) curl_multi_select($mh, 1); } while ($running);
        foreach ($handles as $id => $ch) {
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($code >= 200 && $code < 300) $out['sent']++;
            elseif ($code === 404 || $code === 410) { $out['removed']++; push_forget($id); }
            else $out['failed']++;
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
    }
    return $out;
}

function push_forget(int $id): void
{
    db()->prepare('DELETE FROM push_subscriptions WHERE id=?')->execute([$id]);
}

/** Phones of members who allowed notifications (the subscription itself is the opt-in; blocked members are skipped). */
function push_audience(?int $userId = null): array
{
    $where = 'u.disabled_at IS NULL';
    if ($userId !== null) $where .= ' AND u.id = ' . $userId;
    return db()->query("SELECT p.id, p.endpoint, p.p256dh, p.auth FROM push_subscriptions p JOIN users u ON u.id = p.user_id WHERE $where")->fetchAll();
}

/** "New release" notification for one game, sent to every subscribed phone. Never throws. */
function push_announce_game(int $gameId): void
{
    try {
        if (!push_ready()) return;
        $g = db()->prepare('SELECT slug, name, icon FROM games WHERE id=?');
        $g->execute([$gameId]);
        $g = $g->fetch();
        $subs = $g ? push_audience() : [];
        if (!$subs) return;
        push_send($subs, [
            'title' => 'New on ' . BRAND, 'body' => $g['name'] . ' is now available',
            'url' => 'game.html?slug=' . rawurlencode($g['slug']),
            'icon' => preg_match('#^https?://#i', (string)$g['icon']) ? $g['icon'] : (SITE_URL !== '' && $g['icon'] ? SITE_URL . '/' . ltrim($g['icon'], '/') : null),
        ]);
    } catch (Throwable $e) {
        error_log('push_announce_game: ' . $e->getMessage());
    }
}

/**
 * Announces every published game that has not been pushed yet (a few per run), once each.
 * Runs after requests, from the crons and when a scheduled release goes live; independent of the email tick.
 */
function push_sweep(): int
{
    try {
        if (!push_ready()) return 0;
        $ids = db()->query("SELECT id FROM games WHERE status='published' AND pushed_at IS NULL ORDER BY id LIMIT 5")->fetchAll(PDO::FETCH_COLUMN);
        $claim = db()->prepare('UPDATE games SET pushed_at=UTC_TIMESTAMP(), updated_at=updated_at WHERE id=? AND pushed_at IS NULL');
        $n = 0;
        foreach ($ids as $id) {
            $claim->execute([$id]);                       // two runs never announce the same game twice
            if ($claim->rowCount() === 1) { push_announce_game((int)$id); $n++; }
        }
        return $n;
    } catch (Throwable $e) {
        error_log('push_sweep: ' . $e->getMessage());
        return 0;
    }
}
