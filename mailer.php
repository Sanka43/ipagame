<?php
declare(strict_types=1);

// Transactional email over authenticated SMTP (the site's own cPanel mailbox, e.g. info@ipagame.store).
// Sending as a real, logged-in mailbox on the domain — instead of PHP mail() — is what keeps
// messages out of spam, together with the domain's SPF/DKIM/DMARC records.
//
// Without a 'mail' block in config.php (local dev) messages are written to data/mail/*.eml instead.

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(404);
    exit;
}

/** Sends one email with a plain-text and an HTML part. Returns false (and logs why) on failure. */
function send_mail(array $cfg, string $to, string $subject, string $text, string $html): bool
{
    $from = (string)($cfg['from'] ?? $cfg['user'] ?? '');
    $fromName = (string)($cfg['from_name'] ?? '');
    $domain = substr(strrchr($from, '@') ?: '@localhost', 1);
    $boundary = 'b' . bin2hex(random_bytes(12));

    $headers = [
        'Date' => date(DATE_RFC2822),
        'From' => ($fromName !== '' ? mime_word($fromName) . ' ' : '') . "<$from>",
        'To' => "<$to>",
        'Reply-To' => "<$from>",
        'Subject' => mime_word($subject),
        'Message-ID' => '<' . bin2hex(random_bytes(16)) . "@$domain>",
        'MIME-Version' => '1.0',
        'Content-Type' => "multipart/alternative; boundary=\"$boundary\"",
        // Marks it as an automatic message so mail servers do not send out-of-office replies to it.
        'Auto-Submitted' => 'auto-generated',
    ];
    $part = fn(string $type, string $body) => "--$boundary\r\n"
        . "Content-Type: $type; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . rtrim(chunk_split(base64_encode($body), 76, "\r\n")) . "\r\n";
    $message = implode('', array_map(fn($k, $v) => "$k: $v\r\n", array_keys($headers), $headers))
        . "\r\n" . $part('text/plain', $text) . $part('text/html', $html) . "--$boundary--\r\n";

    if (empty($cfg['host'])) {
        // Only a local dev server may fake sending; on a real host a missing config must fail loudly,
        // otherwise users would be told "code sent" while nothing leaves the server.
        $local = PHP_SAPI === 'cli-server' || in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1', '::1'], true);
        if (!$local) {
            error_log("mailer: no 'mail' block in config.php, email to $to not sent");
            return false;
        }
        $dir = __DIR__ . '/data/mail';
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        return (bool)file_put_contents($dir . '/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.eml', $message);
    }

    try {
        smtp_send($cfg, $from, $to, $message);
        return true;
    } catch (RuntimeException $e) {
        error_log('mailer: ' . $e->getMessage());
        return false;
    }
}

// RFC 2047 encoding for header text that may contain non-ASCII characters.
function mime_word(string $s): string
{
    return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
}

function smtp_send(array $cfg, string $from, string $to, string $message): void
{
    $secure = $cfg['secure'] ?? 'ssl';                 // 'ssl' (port 465) or 'tls' (STARTTLS, port 587)
    $port = (int)($cfg['port'] ?? ($secure === 'ssl' ? 465 : 587));
    $ctx = stream_context_create(['ssl' => [
        'verify_peer' => $cfg['verify_peer'] ?? true,
        'verify_peer_name' => $cfg['verify_peer'] ?? true,
    ]]);
    $target = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $cfg['host'] . ':' . $port;
    $sock = @stream_socket_client($target, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$sock) throw new RuntimeException("connect $target failed: $errstr ($errno)");
    stream_set_timeout($sock, 20);

    $read = function () use ($sock): array {
        $lines = '';
        while (($line = fgets($sock, 1024)) !== false) {
            $lines .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;   // last line of a multi-line reply
        }
        return [(int)substr($lines, 0, 3), trim($lines)];
    };
    $cmd = function (string $line, array $ok) use ($sock, $read): string {
        fwrite($sock, $line . "\r\n");
        [$code, $reply] = $read();
        if (!in_array($code, $ok, true)) {
            $shown = str_starts_with($line, 'AUTH') || strlen($line) > 60 ? strtok($line, ' ') : $line;
            throw new RuntimeException("SMTP '$shown' -> $reply");
        }
        return $reply;
    };

    try {
        [$code, $reply] = $read();
        if ($code !== 220) throw new RuntimeException("SMTP greeting: $reply");
        $helo = 'EHLO ' . (substr(strrchr($from, '@') ?: '@localhost', 1));
        $cmd($helo, [250]);
        if ($secure === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new RuntimeException('STARTTLS failed');
            }
            $cmd($helo, [250]);
        }
        $cmd('AUTH LOGIN', [334]);
        $cmd(base64_encode((string)$cfg['user']), [334]);
        $cmd(base64_encode((string)$cfg['pass']), [235]);
        $cmd("MAIL FROM:<$from>", [250]);
        $cmd("RCPT TO:<$to>", [250, 251]);
        $cmd('DATA', [354]);
        // Dot-stuffing: a line starting with "." must be sent as "..".
        $cmd(preg_replace('/^\./m', '..', $message) . "\r\n.", [250]);
        fwrite($sock, "QUIT\r\n");                        // already delivered; the reply does not matter
    } finally {
        fclose($sock);
    }
}
