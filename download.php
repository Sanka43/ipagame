<?php
declare(strict_types=1);
// Serves an uploaded IPA to a member holding a signed, time-limited link:
//   /download/<expires>.<signature>/<file>.ipa   (made by signed_ipa_url() in api.php)
// The link needs no cookies, so LiveContainer can fetch it. The files themselves sit in uploads/ipa/,
// which uploads/ipa/.htaccess blocks from direct access. Range requests are supported (resume).

define('API_LIB', true);
require __DIR__ . '/api.php';
session_write_close();                       // a long download must not hold the session lock
header_remove('Content-Type');

function dl_fail(int $code, string $msg): never
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    exit($msg);
}

// /download/<expires>.<signature>/<file>.ipa is rewritten to ?e=&s=&f= by .htaccess.
[$exp, $sig, $file] = [(string)($_GET['e'] ?? ''), (string)($_GET['s'] ?? ''), (string)($_GET['f'] ?? '')];
if (!preg_match('/^\d{9,11}$/', $exp) || !preg_match('/^[a-f0-9]{64}$/', $sig) || !preg_match('/^[A-Za-z0-9._-]+\.ipa$/', $file)) dl_fail(404, 'Not found');
if (!hash_equals(hash_hmac('sha256', "$exp|$file", dl_secret()), $sig)) dl_fail(403, 'Bad link');
if ((int)$exp < time()) dl_fail(410, 'This download link has expired. Open the game page again.');
$full = IPA_DIR . '/' . $file;
if (!is_file($full)) dl_fail(404, 'Not found');

$size = filesize($full);
$start = 0;
$end = $size - 1;
if (preg_match('/^bytes=(\d*)-(\d*)$/', $_SERVER['HTTP_RANGE'] ?? '', $r) && ($r[1] !== '' || $r[2] !== '')) {
    if ($r[1] === '') { $start = max(0, $size - (int)$r[2]); }
    else { $start = (int)$r[1]; if ($r[2] !== '') $end = min($end, (int)$r[2]); }
    if ($start > $end || $start >= $size) { header("Content-Range: bytes */$size"); dl_fail(416, 'Bad range'); }
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
}
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $file . '"');
header('Accept-Ranges: bytes');
header('Content-Length: ' . ($end - $start + 1));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

while (ob_get_level()) ob_end_clean();
$h = fopen($full, 'rb');
fseek($h, $start);
for ($left = $end - $start + 1; $left > 0 && !feof($h) && !connection_aborted(); ) {
    $chunk = fread($h, min(1048576, $left));
    if ($chunk === false || $chunk === '') break;
    echo $chunk;
    $left -= strlen($chunk);
    flush();
}
fclose($h);
