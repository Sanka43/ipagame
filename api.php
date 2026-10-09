<?php
declare(strict_types=1);

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') === '443';
ini_set('session.use_strict_mode', '1');
session_set_cookie_params(['path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => $https]);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
date_default_timezone_set('UTC');

const ICON_DIR   = __DIR__ . '/uploads/icons';
const SHOT_DIR   = __DIR__ . '/uploads/screenshots';
const AVATAR_DIR = __DIR__ . '/uploads/avatars';
const IPA_DIR    = __DIR__ . '/uploads/ipa';
const IPA_MAX_MB = 4096;
// Admin login and database live in config.php (git-ignored). Copy config.example.php to create it.
$config = is_file(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];
define('ADMIN_USER', (string)($config['admin_user'] ?? ''));
define('ADMIN_PASS', (string)($config['admin_pass'] ?? ''));
// The same MySQL database the "ipa game site" repo uses (tables games, game_versions, game_screenshots).
// Defaults are local XAMPP.
define('DB', (array)($config['db'] ?? []) + ['host' => '127.0.0.1', 'name' => 'ipa_store', 'user' => 'root', 'pass' => '']);
// Public URL of this site without trailing slash; empty = auto-detect. Uploaded icons are stored
// as absolute URLs so the other site can show them too.
define('SITE_URL', rtrim((string)($config['site_url'] ?? ''), '/'));
// SMTP mailbox that sends verification emails (see config.example.php). Missing = write to data/mail/ (dev).
// Store name used in emails; also the default sender name.
const BRAND = 'IPA Game Store';
define('MAIL', (array)($config['mail'] ?? []) + ['from' => 'info@ipagame.store', 'from_name' => BRAND]);
// New-app / announcement emails may use their own SMTP (e.g. the cPanel mailbox) so the sign-up codes
// keep their own sender; without a 'bulk_mail' block they go through 'mail' as well.
define('MAIL_BULK', (array)($config['bulk_mail'] ?? []) + MAIL);
// Social buttons shown on the home page: only http(s) URLs for the known platforms.
define('SOCIAL', array_filter(array_map(
    fn($u) => preg_match('#^https?://#i', trim((string)$u)) ? trim((string)$u) : '',
    array_intersect_key((array)($config['social'] ?? []), array_flip(['x', 'telegram', 'youtube']))
)));
// OAuth client ID for "Continue with Google" (Google Cloud Console → Credentials). Empty = button hidden.
define('GOOGLE_CLIENT_ID', trim((string)($config['google_client_id'] ?? '')));
require __DIR__ . '/mailer.php';

const REMEMBER_COOKIE = 'ipg_remember';
const REMEMBER_TTL = 180 * 24 * 3600;   // members stay signed in for 180 days (renewed on use)

const VERIFY_TTL = 15 * 60;      // a code is valid for 15 minutes
const VERIFY_RESEND = 60;        // at most one email per minute
const VERIFY_TRIES = 5;          // wrong guesses before a new code is needed

// Per-IP / per-address limits as [max, window seconds] (table rate_hits, see sql/rate_limits.sql).
const LIMIT_LOGIN_FAILS = [10, 15 * 60];   // wrong store passwords per IP
const LIMIT_ADMIN_FAILS = [5, 15 * 60];    // wrong admin passwords per IP
const LIMIT_SIGNUPS = [5, 60 * 60];        // new accounts per IP
const LIMIT_MAIL_IP = [10, 60 * 60];       // code emails requested per IP
const LIMIT_MAIL_TO = [5, 60 * 60];        // code emails to one address
const LIMIT_CHAT = [20, 5 * 60];           // support chat messages per member

const CHAT_MAX_LEN = 2000;                 // characters per support message
const MAX_SHOTS = 20;                      // screenshots per device on one item

// Store categories — offered in the admin panel even before any item uses them.
const CATEGORIES = [
    'games' => ['action', 'adventure', 'arcade', 'casual', 'puzzle', 'racing', 'role-playing', 'simulation', 'strategy',
                'sports', 'board', 'card', 'casino', 'family', 'music', 'trivia', 'word'],
    'apps'  => ['communication', 'entertainment', 'graphics-design', 'health-fitness', 'photo-video', 'productivity',
                'utilities', 'education', 'music', 'emulators'],
];
// 'review' and 'removed' are set on the other site (rights check); only 'published' is public.
const STATUSES = ['published', 'draft', 'review', 'removed'];

class ApiError extends Exception {}

function respond(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function is_admin(): bool
{
    return !empty($_SESSION['admin']);
}

function require_admin(): void
{
    if (!is_admin()) throw new ApiError('Not logged in', 401);
}

/** The logged-in store user (public fields only), or null. */
function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) restore_remembered();
    if (empty($_SESSION['user_id'])) return null;
    // * so a not-yet-imported notify_new_apps column (sql/new_app_emails.sql) cannot break sign-in.
    $st = db()->prepare('SELECT * FROM users WHERE id=?');
    $st->execute([$_SESSION['user_id']]);
    $u = $st->fetch();
    // Deleted, disabled or un-verified by an admin: signed out at once.
    if (!$u || $u['disabled_at'] || !$u['email_verified_at']) {
        unset($_SESSION['user_id']);
        forget_device();
        return null;
    }
    return ['id' => (int)$u['id'], 'username' => $u['username'], 'email' => $u['email'], 'created_at' => iso_date($u['created_at']),
            'avatar' => avatar_of($u), 'custom_avatar' => is_uploaded_avatar($u['avatar_url'] ?? null),
            'google' => !empty($u['google_sub']), 'notify_new_apps' => (bool)($u['notify_new_apps'] ?? true)];
}

/** The picture to show: an uploaded or Google photo if there is one, else the email's Gravatar. */
function avatar_of(array $u): string
{
    return ($u['avatar_url'] ?? '') !== '' ? $u['avatar_url'] : gravatar_url($u['email']);
}

function is_uploaded_avatar(?string $url): bool
{
    return (bool)preg_match('~^uploads/avatars/[a-f0-9]{16}\.(jpg|png|webp|gif)$~', (string)$url);
}

/** Deletes an uploaded avatar file (Google / Gravatar links are left alone). */
function remove_avatar_file(?string $url): void
{
    if (is_uploaded_avatar($url)) @unlink(__DIR__ . '/' . $url);
}

/** Saves an uploaded image as the user's avatar: a 256px square JPEG when GD is available. */
function store_avatar(array $f): string
{
    if ($f['error'] !== UPLOAD_ERR_OK) throw new ApiError('Upload failed', 400);
    if ($f['size'] > 5 * 1024 * 1024) throw new ApiError('Photo must be under 5 MB', 422);
    $info = @getimagesize($f['tmp_name']);
    $exts = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    $ext = $exts[$info[2] ?? 0] ?? null;
    if (!$ext) throw new ApiError('Photo must be PNG, JPG, WEBP or GIF', 422);
    if (!is_dir(AVATAR_DIR)) mkdir(AVATAR_DIR, 0775, true);
    $name = bin2hex(random_bytes(8));

    // Re-encoding also drops anything hidden in the file (EXIF location, appended data).
    $load = ['png' => 'imagecreatefrompng', 'jpg' => 'imagecreatefromjpeg', 'webp' => 'imagecreatefromwebp', 'gif' => 'imagecreatefromgif'][$ext];
    if (function_exists($load) && function_exists('imagecreatetruecolor') && ($img = @$load($f['tmp_name']))) {
        [$w, $h] = [imagesx($img), imagesy($img)];
        $side = min($w, $h);
        $out = imagecreatetruecolor(256, 256);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));   // transparent PNGs on white
        imagecopyresampled($out, $img, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), 256, 256, $side, $side);
        $path = "uploads/avatars/$name.jpg";
        if (!imagejpeg($out, __DIR__ . '/' . $path, 86)) throw new ApiError('Could not save photo', 500);
        return $path;
    }
    if ($f['size'] > 2 * 1024 * 1024) throw new ApiError('Photo must be under 2 MB', 422);
    $path = "uploads/avatars/$name.$ext";
    if (!move_uploaded_file($f['tmp_name'], __DIR__ . '/' . $path)) throw new ApiError('Could not save photo', 500);
    return $path;
}

/**
 * Checks a Google Sign-In ID token with Google and returns its claims
 * (sub, email, email_verified, name, picture), or throws.
 */
function google_claims(string $credential): array
{
    if (GOOGLE_CLIENT_ID === '') throw new ApiError('Google sign-in is not set up', 400);
    if (!preg_match('/^[\w-]+\.[\w-]+\.[\w-]+$/', $credential)) throw new ApiError('Google sign-in failed. Try again.', 401);
    $ch = curl_init('https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($credential));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $c = is_string($raw) ? json_decode($raw, true) : null;
    if ($code !== 200 || !is_array($c)) throw new ApiError('Google sign-in failed. Try again.', 401);
    // The token must be for this site, issued by Google, unexpired and carry a verified email.
    if (($c['aud'] ?? '') !== GOOGLE_CLIENT_ID
        || !in_array($c['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true)
        || (int)($c['exp'] ?? 0) < time()
        || empty($c['sub']) || empty($c['email'])
        || !in_array($c['email_verified'] ?? '', [true, 'true'], true)) {
        throw new ApiError('Google sign-in failed. Try again.', 401);
    }
    return $c;
}

/** A free username based on the Google name or the email's first part. */
function username_from(string $name, string $email): string
{
    $base = trim((string)preg_replace('/[^A-Za-z0-9]+/', '_', $name !== '' ? $name : strtok($email, '@')), '_');
    $base = substr($base, 0, 24);
    if (strlen($base) < 3) $base = 'player';
    $st = db()->prepare('SELECT 1 FROM users WHERE username=?');
    $try = $base;
    for ($n = 2; $st->execute([$try]) && $st->fetchColumn(); $n++) $try = $base . '_' . $n;
    return $try;
}

// Profile picture linked to the email on gravatar.com; d=404 lets the page fall back to the initial.
function gravatar_url(string $email): string
{
    return 'https://gravatar.com/avatar/' . hash('sha256', strtolower(trim($email))) . '?s=160&d=404';
}

// The store is members-only: browsing needs a logged-in user (or the admin, for the panel).
function require_member(): void
{
    if (!is_admin() && !current_user()) throw new ApiError('Login required', 401);
}

function login_user(int $id): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = $id;
    unset($_SESSION['pending_user_id']);
    db()->prepare('UPDATE users SET last_login_at=UTC_TIMESTAMP() WHERE id=?')->execute([$id]);
    remember_device($id);
}

// "Remember me": the PHP session cookie dies when the browser or home-screen app closes (and the
// server drops idle sessions), so a long-lived cookie signs the member back in (table user_remember).
function set_remember_cookie(string $value, int $expires): void
{
    global $https;
    setcookie(REMEMBER_COOKIE, $value, ['expires' => $expires, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => $https]);
}

function remember_device(int $id): void
{
    try {
        forget_device();
        $sel = bin2hex(random_bytes(12));
        $val = bin2hex(random_bytes(32));
        db()->prepare('INSERT INTO user_remember (selector, validator_hash, user_id, expires_at) VALUES (?, ?, ?, UTC_TIMESTAMP() + INTERVAL ? SECOND)')
            ->execute([$sel, hash('sha256', $val), $id, REMEMBER_TTL]);
        set_remember_cookie("$sel:$val", time() + REMEMBER_TTL);
        if (random_int(1, 50) === 1) db()->exec('DELETE FROM user_remember WHERE expires_at < UTC_TIMESTAMP()');
    } catch (PDOException) {
        // Table not imported yet (sql/user_remember.sql): plain session login still works.
    }
}

/** Signs the member in from the remember cookie when the session has none. */
function restore_remembered(): void
{
    [$sel, $val] = explode(':', (string)($_COOKIE[REMEMBER_COOKIE] ?? ''), 2) + ['', ''];
    if (!preg_match('/^[a-f0-9]{24}$/', $sel) || !preg_match('/^[a-f0-9]{64}$/', $val)) return;
    try {
        $st = db()->prepare('SELECT user_id, validator_hash FROM user_remember WHERE selector=? AND expires_at > UTC_TIMESTAMP()');
        $st->execute([$sel]);
        $row = $st->fetch();
        if (!$row || !hash_equals($row['validator_hash'], hash('sha256', $val))) {
            set_remember_cookie('', 1);
            return;
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$row['user_id'];
        // Using the app keeps it signed in: push the expiry out again.
        db()->prepare('UPDATE user_remember SET expires_at=UTC_TIMESTAMP() + INTERVAL ? SECOND WHERE selector=?')->execute([REMEMBER_TTL, $sel]);
        set_remember_cookie("$sel:$val", time() + REMEMBER_TTL);
    } catch (PDOException) {
    }
}

/** Drops this device's remember cookie (sign out). */
function forget_device(): void
{
    $sel = explode(':', (string)($_COOKIE[REMEMBER_COOKIE] ?? ''))[0];
    if ($sel === '') return;
    try {
        db()->prepare('DELETE FROM user_remember WHERE selector=?')->execute([$sel]);
    } catch (PDOException) {
    }
    set_remember_cookie('', 1);
    unset($_COOKIE[REMEMBER_COOKIE]);
}

/** Signs a member out on every device (password reset). */
function forget_all_devices(int $id): void
{
    try {
        db()->prepare('DELETE FROM user_remember WHERE user_id=?')->execute([$id]);
    } catch (PDOException) {
    }
}

// REMOTE_ADDR only: forwarded-for headers are set by the client and can't be trusted here.
function client_ip(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/** Throws a 429 once $key has been hit $limit[0] times within the last $limit[1] seconds. */
function rate_check(string $key, array $limit, string $message): void
{
    [$max, $window] = $limit;
    $st = db()->prepare('SELECT COUNT(*) FROM rate_hits WHERE k=? AND t > UTC_TIMESTAMP() - INTERVAL ? SECOND');
    $st->execute([$key, $window]);
    if ((int)$st->fetchColumn() >= $max) throw new ApiError($message, 429);
}

function rate_hit(string $key): void
{
    db()->prepare('INSERT INTO rate_hits (k, t) VALUES (?, UTC_TIMESTAMP())')->execute([$key]);
    // Now and then, forget hits older than any window.
    if (random_int(1, 50) === 1) db()->exec('DELETE FROM rate_hits WHERE t < UTC_TIMESTAMP() - INTERVAL 1 DAY');
}

function throttle_login(): void
{
    rate_check('login:' . client_ip(), LIMIT_LOGIN_FAILS, 'Too many sign-in attempts. Try again in 15 minutes.');
}

function login_failed(): void
{
    rate_hit('login:' . client_ip());
    throw new ApiError('Wrong username or password', 401);
}

function check_mail_ip(): void
{
    rate_check('mail-ip:' . client_ip(), LIMIT_MAIL_IP, 'Too many emails requested from your network. Try again later.');
}

/** The account waiting for its email code in this session (right after register / sign-in), or null. */
function pending_user(): ?array
{
    if (empty($_SESSION['pending_user_id'])) return null;
    $st = db()->prepare('SELECT * FROM users WHERE id=? AND email_verified_at IS NULL');
    $st->execute([$_SESSION['pending_user_id']]);
    $u = $st->fetch();
    if (!$u) unset($_SESSION['pending_user_id']);
    return $u ?: null;
}

// Emailed 6-digit codes. Two kinds, each with its own columns: 'verify' (confirm the email after
// sign-up) and 'reset' (forgot password). The code is stored hashed and expires after VERIFY_TTL.
const CODE_KINDS = ['verify', 'reset'];

/** Seconds until another code of this kind may be emailed to this account (0 = now). */
function resend_wait(array $u, string $kind = 'verify'): int
{
    $sent = $u["{$kind}_sent_at"] ? strtotime($u["{$kind}_sent_at"] . ' UTC') : 0;
    return max(0, $sent + VERIFY_RESEND - time());
}

/** Puts an unverified account in the session's "enter your code" step and emails a code if allowed. */
function start_verification(array $u): array
{
    session_regenerate_id(true);
    unset($_SESSION['user_id']);
    $_SESSION['pending_user_id'] = (int)$u['id'];
    $error = null;
    try {
        $sent = resend_wait($u) === 0 ? send_code($u, 'verify') : null;
    } catch (ApiError $e) {                               // an email limit: still go to the code step
        $sent = false;
        $error = $e->getMessage();
    }
    // sent: true = new code emailed, false = email failed, null = an earlier code (under a minute old) still stands.
    return ['verify' => true, 'email' => $u['email'], 'sent' => $sent, 'error' => $error,
            'resend_in' => $sent === null ? resend_wait($u) : ($sent ? VERIFY_RESEND : 0)];
}

/** Throws unless $code is the account's current, unexpired code of this kind; counts wrong guesses. */
function check_code(array $u, string $kind, string $code): void
{
    if (!in_array($kind, CODE_KINDS, true)) throw new LogicException("bad code kind $kind");
    if (strlen($code) !== 6) throw new ApiError('Enter the 6-digit code', 422);
    if (!$u["{$kind}_code_hash"] || strtotime($u["{$kind}_expires_at"] . ' UTC') < time()) {
        throw new ApiError('This code has expired. Tap “Resend code” for a new one.', 410);
    }
    if ($u["{$kind}_attempts"] >= VERIFY_TRIES) throw new ApiError('Too many wrong codes. Tap “Resend code” for a new one.', 429);
    if (!password_verify($code, $u["{$kind}_code_hash"])) {
        db()->prepare("UPDATE users SET {$kind}_attempts={$kind}_attempts+1 WHERE id=?")->execute([$u['id']]);
        $left = VERIFY_TRIES - $u["{$kind}_attempts"] - 1;
        throw new ApiError($left > 0 ? "That code isn't right. $left " . ($left === 1 ? 'try' : 'tries') . ' left.'
                                     : 'Too many wrong codes. Tap “Resend code” for a new one.', 422);
    }
}

/** Emails a fresh 6-digit code of this kind (replacing any earlier one). Returns whether the email went out. */
function send_code(array $u, string $kind): bool
{
    if (!in_array($kind, CODE_KINDS, true)) throw new LogicException("bad code kind $kind");
    check_mail_ip();
    rate_check('mail-to:' . $u['email'], LIMIT_MAIL_TO, 'Too many codes were sent to this email. Try again in an hour.');
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    db()->prepare("UPDATE users SET {$kind}_code_hash=?, {$kind}_expires_at=UTC_TIMESTAMP() + INTERVAL ? SECOND,
                   {$kind}_sent_at=UTC_TIMESTAMP(), {$kind}_attempts=0 WHERE id=?")
        ->execute([password_hash($code, PASSWORD_DEFAULT), VERIFY_TTL, $u['id']]);

    // Wording kept plain on purpose: no code in the subject and no links, which spam filters
    // treat as phishing signs coming from a new domain.
    $brand = BRAND;
    $copy = $kind === 'reset' ? [
        'subject' => "Your $brand password reset code",
        'title' => 'Your password reset code',
        'intro' => 'here is the code to choose a new password for your account.',
        'why' => "You're receiving this because a password reset was requested for your $brand account.",
        'ignore' => "If that wasn't you, you can ignore this email. Your password won't change.",
    ] : [
        'subject' => "Your $brand sign-up code",
        'title' => 'Your sign-up code',
        'intro' => 'here is the code to finish creating your account.',
        'why' => "You're receiving this because this email address was used to create an account at $brand.",
        'ignore' => "If that wasn't you, you can ignore this email and no account will be set up.",
    ];
    $name = htmlspecialchars($u['username'], ENT_QUOTES);
    $mins = VERIFY_TTL / 60;
    $text = "Hi {$u['username']},\n\n" . ucfirst($copy['intro']) . "\n\n    $code\n\n"
          . "The code expires in $mins minutes.\n\n{$copy['why']} {$copy['ignore']}\n\nThanks,\nThe $brand team\n";
    $html = <<<HTML
<!doctype html>
<html><body style="margin:0;padding:0;background:#f4f5f7">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:32px 12px">
<tr><td align="center">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:460px;background:#ffffff;border-radius:18px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;color:#111418">
    <tr><td style="padding:32px 32px 8px;text-align:center">
      <div style="font-size:13px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#7c3aed">$brand</div>
      <h1 style="margin:14px 0 6px;font-size:24px;line-height:1.25">{$copy['title']}</h1>
      <p style="margin:0;font-size:15px;line-height:1.5;color:#6b7280">Hi $name, {$copy['intro']}</p>
    </td></tr>
    <tr><td style="padding:22px 32px;text-align:center">
      <div style="display:inline-block;padding:14px 22px;border-radius:14px;background:#f1edff;font-size:34px;font-weight:800;letter-spacing:.3em;color:#111418;font-family:'SF Mono',Menlo,Consolas,monospace">$code</div>
      <p style="margin:14px 0 0;font-size:13px;color:#6b7280">This code expires in $mins minutes.</p>
    </td></tr>
    <tr><td style="padding:8px 32px 30px;text-align:center;font-size:13px;line-height:1.5;color:#9ca3af">
      {$copy['why']} {$copy['ignore']}
    </td></tr>
  </table>
</td></tr>
</table>
</body></html>
HTML;
    $ok = send_mail(MAIL, $u['email'], $copy['subject'], $text, $html);
    if ($ok) {
        rate_hit('mail-ip:' . client_ip());
        rate_hit('mail-to:' . $u['email']);
    }
    return $ok;
}

/** The account named by the email in this session's forgot-password step, or null. */
function reset_user(): ?array
{
    if (empty($_SESSION['reset_email'])) return null;
    $st = db()->prepare('SELECT * FROM users WHERE email=?');
    $st->execute([$_SESSION['reset_email']]);
    return $st->fetch() ?: null;
}

// Admin writes must be POST and carry a custom header (blocks simple cross-site form posts).
function require_post(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new ApiError('POST required', 405);
    if (empty($_SERVER['HTTP_X_REQUESTED_WITH'])) throw new ApiError('Bad request', 400);
}

function body(): array
{
    $d = json_decode(file_get_contents('php://input') ?: '', true);
    return is_array($d) ? $d : [];
}

function db(): PDO
{
    static $pdo;
    if (!$pdo) {
        $c = DB;
        $pdo = new PDO("mysql:host={$c['host']};dbname={$c['name']};charset=utf8mb4", $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        publish_due($pdo);
    }
    return $pdo;
}

/** Scheduled releases (draft + publish_at) go live once their time has passed. Returns how many. */
function publish_due(?PDO $pdo = null): int
{
    try {
        return (int)($pdo ?? db())->exec("UPDATE games SET status='published', publish_at=NULL, updated_at=UTC_TIMESTAMP()
                                          WHERE status='draft' AND publish_at IS NOT NULL AND publish_at <= UTC_TIMESTAMP()");
    } catch (Throwable $e) {
        return 0;   // publish_at column not added yet (sql/games_schedule.sql)
    }
}

function has_publish_at(): bool
{
    static $has;
    return $has ??= (bool)db()->query("SHOW COLUMNS FROM games LIKE 'publish_at'")->fetch();
}

function site_url(): string
{
    if (SITE_URL !== '') return SITE_URL;
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . str_replace(' ', '%20', $dir);
}

function iso_date(?string $dt): string
{
    return $dt ? gmdate('Y-m-d\TH:i:s\Z', strtotime($dt . ' UTC')) : '';
}

function sql_date(string $iso): string
{
    return gmdate('Y-m-d H:i:s', strtotime($iso) ?: time());
}

/** A games row (+ its versions and screenshots) in the apps.json item shape the front-end expects. */
function row_to_item(array $r, array $versions = [], array $shots = []): array
{
    $csv = fn($s) => ($s ?? '') === '' ? [] : explode(',', $s);
    $screens = fn($dev) => array_values(array_column(array_filter($shots, fn($s) => $s['device'] === $dev), 'url'));
    return [
        'id' => (int)$r['id'],
        'type' => $r['type'] ?? 'game',
        'slug' => $r['slug'] ?? '',
        'name' => $r['name'] ?? '',
        'category' => $r['category'] ?? '',
        'developer' => $r['developer'] ?? '',
        'bundle_id' => $r['bundle_id'] ?? null,
        'app_store_id' => $r['app_store_id'] ?? null,
        'app_store_url' => $r['app_store_url'] ?? null,
        'price' => (float)($r['price'] ?? 0),
        'icon' => $r['icon'] ?? '',
        'screenshots' => $screens('iphone'),
        'ipad_screenshots' => $screens('ipad'),
        'min_ios' => $r['min_ios'] ?? '',
        'compatible' => array_keys(array_filter(['iphone' => $r['is_iphone'] ?? 1, 'ipad' => $r['is_ipad'] ?? 0])),
        'languages' => $csv($r['languages'] ?? ''),
        'content_rating' => $r['content_rating'] ?? '',
        'rating' => ['value' => (float)($r['rating_value'] ?? 0), 'count' => (int)($r['rating_count'] ?? 0)],
        'short_description' => $r['short_description'] ?? '',
        'description' => $r['description'] ?? '',
        'tags' => $csv($r['tags'] ?? ''),
        'featured' => ['popular' => !empty($r['is_popular']), 'editors_choice' => !empty($r['is_editors_choice'])],
        'latest_version' => $r['latest_version'] ?? '',
        'versions' => array_map(fn($v) => [
            'version' => $v['version'],
            'release_date' => $v['release_date'] ?? '',
            'size_mb' => (float)$v['size_mb'] ?: null,
            'changelog' => $v['changelog'] ?? '',
            'download_url' => $v['download_url'],
        ], $versions),
        'seo' => ['title' => $r['seo_title'] ?? '', 'meta_description' => $r['seo_description'] ?? ''],
        'license_type' => $r['license_type'] ?? 'app-store-link',
        'status' => $r['status'] ?? 'published',
        'publish_at' => iso_date($r['publish_at'] ?? null),
        'notify_email' => !empty($r['notify_email']),
        'notified_at' => iso_date($r['notified_at'] ?? null),
        'created_at' => iso_date($r['created_at'] ?? null),
        'updated_at' => iso_date($r['updated_at'] ?? null),
    ];
}

function group_by_game(string $sql): array
{
    $out = [];
    foreach (db()->query($sql) as $row) $out[$row['game_id']][] = $row;
    return $out;
}

/** Admin list rows: just what the list shows. The editor loads one full item with 'get'. */
function fetch_admin_rows(): array
{
    $pub = has_publish_at() ? ' g.publish_at,' : '';
    $rows = db()->query('SELECT g.id, g.slug, g.type, g.name, g.developer, g.category, g.icon, g.latest_version, g.status,' . $pub . '
                                (SELECT COUNT(*) FROM game_versions v WHERE v.game_id = g.id) AS versions_count,
                                (SELECT v.download_url FROM game_versions v WHERE v.game_id = g.id ORDER BY v.id LIMIT 1) AS download_url,
                                EXISTS(SELECT 1 FROM game_versions v WHERE v.game_id = g.id
                                       AND v.download_url NOT LIKE \'%apps.apple.com%\') AS ipa
                         FROM games g ORDER BY g.updated_at DESC, g.id DESC')->fetchAll();
    // ipa: some version links off the App Store (an IPA file), same test as save_item's $offStore.
    return array_map(fn($r) => ['id' => (int)$r['id'], 'versions_count' => (int)$r['versions_count'], 'ipa' => (bool)$r['ipa'],
                                'download_url' => (string)$r['download_url'],
                                'publish_at' => iso_date($r['publish_at'] ?? null)] + $r, $rows);
}

/** All items, newest first. $full adds descriptions, versions and screenshots. */
function fetch_items(bool $all, bool $full): array
{
    $cols = $full ? '*' : 'id, slug, type, name, developer, category, icon, short_description, latest_version,
                          is_popular, is_editors_choice, tags, status, updated_at';
    $where = $all ? '' : " WHERE status='published'";
    $rows = db()->query("SELECT $cols FROM games$where ORDER BY updated_at DESC, id DESC")->fetchAll();
    if (!$full) return array_map('row_to_item', $rows);
    $vers = group_by_game('SELECT * FROM game_versions ORDER BY id');
    $shots = group_by_game('SELECT game_id, device, url FROM game_screenshots ORDER BY sort, id');
    return array_map(fn($r) => row_to_item($r, $vers[$r['id']] ?? [], $shots[$r['id']] ?? []), $rows);
}

// Old links use slugs ending in "-ipa"; the database stores them without it.
function find_item(?int $id, ?string $slug): ?array
{
    if ($id !== null) {
        $st = db()->prepare('SELECT * FROM games WHERE id=?');
        $st->execute([$id]);
    } elseif ($slug !== null && $slug !== '') {
        $st = db()->prepare('SELECT * FROM games WHERE slug IN (?, ?) ORDER BY slug=? DESC LIMIT 1');
        $st->execute([$slug, preg_replace('/-ipa$/', '', $slug), $slug]);
    } else {
        return null;
    }
    $r = $st->fetch();
    if (!$r) return null;
    $v = db()->prepare('SELECT * FROM game_versions WHERE game_id=? ORDER BY id');
    $v->execute([$r['id']]);
    $s = db()->prepare('SELECT device, url FROM game_screenshots WHERE game_id=? ORDER BY sort, id');
    $s->execute([$r['id']]);
    return row_to_item($r, $v->fetchAll(), $s->fetchAll());
}

/** Insert or update a cleaned item and replace its versions. Returns the item id. */
function save_item(array $it, ?int $id): int
{
    $latest = $it['versions'][0];
    // Rights layer (shared with the other site): links off the App Store need a human to confirm them.
    $offStore = (bool)array_filter($it['versions'], fn($v) => !str_contains($v['download_url'], 'apps.apple.com'));
    $license = $it['license_type'] ?? 'app-store-link';
    if ($offStore && $license === 'app-store-link') $license = 'unverified';

    $cols = [
        'type' => $it['type'],
        'name' => $it['name'],
        'category' => $it['category'],
        'developer' => $it['developer'],
        'icon' => $it['icon'],
        'short_description' => $it['short_description'],
        'description' => $it['description'],
        'min_ios' => $it['min_ios'],
        'is_popular' => (int)$it['featured']['popular'],
        'is_editors_choice' => (int)$it['featured']['editors_choice'],
        'rating_value' => $it['rating']['value'],
        'rating_count' => $it['rating']['count'],
        'license_type' => $license,
        'latest_version' => $it['latest_version'],
        'latest_size_mb' => $latest['size_mb'] ?? 0,
        'latest_release_date' => $latest['release_date'] ?: null,
        'seo_title' => $it['seo']['title'],
        'seo_description' => $it['seo']['meta_description'],
        'status' => $it['status'],
        'updated_at' => sql_date($it['updated_at']),
    ];
    if (has_publish_at()) $cols['publish_at'] = $it['publish_at'] !== '' ? sql_date($it['publish_at']) : null;
    if (has_column('games', 'notify_email')) $cols['notify_email'] = (int)!empty($it['notify_email']);
    if ($id === null) {
        $cols += ['slug' => $it['slug'], 'created_at' => sql_date($it['created_at'])];
        $sql = 'INSERT INTO games (' . implode(',', array_keys($cols)) . ') VALUES (' . rtrim(str_repeat('?,', count($cols)), ',') . ')';
        db()->prepare($sql)->execute(array_values($cols));
        $id = (int)db()->lastInsertId();
    } else {
        $sql = 'UPDATE games SET ' . implode(',', array_map(fn($c) => "$c=?", array_keys($cols))) . ' WHERE id=?';
        db()->prepare($sql)->execute([...array_values($cols), $id]);
    }

    db()->prepare('DELETE FROM game_versions WHERE game_id=?')->execute([$id]);
    $add = db()->prepare('INSERT INTO game_versions (game_id,version,release_date,size_mb,changelog,download_url) VALUES (?,?,?,?,?,?)');
    foreach ($it['versions'] as $v) {
        $add->execute([$id, $v['version'], $v['release_date'] ?: null, $v['size_mb'] ?? 0, $v['changelog'], $v['download_url']]);
    }

    // Screenshots: replaced as a whole, in the order given.
    $old = db()->prepare('SELECT url FROM game_screenshots WHERE game_id=?');
    $old->execute([$id]);
    $oldUrls = $old->fetchAll(PDO::FETCH_COLUMN);
    db()->prepare('DELETE FROM game_screenshots WHERE game_id=?')->execute([$id]);
    $add = db()->prepare('INSERT INTO game_screenshots (game_id,device,url,sort) VALUES (?,?,?,?)');
    foreach (['iphone' => $it['screenshots'], 'ipad' => $it['ipad_screenshots']] as $device => $urls) {
        foreach (array_values($urls) as $i => $url) $add->execute([$id, $device, $url, $i]);
    }
    foreach (array_diff($oldUrls, $it['screenshots'], $it['ipad_screenshots']) as $url) remove_unused_upload($url);
    return $id;
}

/** Deletes an uploaded icon / screenshot file once no game uses it any more (outside links are left alone). */
function remove_unused_upload(?string $url): void
{
    $path = ltrim(str_replace(site_url() . '/', '', (string)$url), '/');
    if (!preg_match('~^uploads/(icons|screenshots)/[\w-]+\.(png|jpe?g|webp|gif)$~', $path)) return;
    $like = '%' . $path;
    $st = db()->prepare('SELECT (SELECT COUNT(*) FROM games WHERE icon LIKE ?) + (SELECT COUNT(*) FROM game_screenshots WHERE url LIKE ?)');
    $st->execute([$like, $like]);
    if (!$st->fetchColumn()) @unlink(__DIR__ . '/' . $path);
}

/** Saves an uploaded image into $dir under a random name and returns its public URL. */
function store_upload_image(?array $f, string $dir, int $maxMb, string $label): string
{
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) throw new ApiError('Upload failed', 400);
    if ($f['size'] > $maxMb * 1024 * 1024) throw new ApiError("$label must be under $maxMb MB", 422);
    $info = @getimagesize($f['tmp_name']);
    $exts = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    $ext = $exts[$info[2] ?? 0] ?? null;
    if (!$ext) throw new ApiError("$label must be PNG, JPG, WEBP or GIF", 422);
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $name = bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) throw new ApiError("Could not save $label", 500);
    return site_url() . '/uploads/' . basename($dir) . '/' . $name;
}

/** Appends slice $index of $total to a partial file; after the last one moves it to uploads/ipa/ and returns its URL + size. */
function store_ipa_chunk(?array $f, string $uid, int $index, int $total, string $name): array
{
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) throw new ApiError('Upload failed', 400);
    if (!preg_match('/^[a-f0-9]{16}$/', $uid) || $total < 1 || $index < 0 || $index >= $total) throw new ApiError('Bad upload', 400);
    if (!preg_match('/\.ipa$/i', $name)) throw new ApiError('Choose an .ipa file', 422);
    if (!is_dir(IPA_DIR)) mkdir(IPA_DIR, 0775, true);
    $part = IPA_DIR . "/$uid.part";
    if ($index === 0) @unlink($part);
    elseif (!is_file($part)) throw new ApiError('Upload expired, start again', 409);
    if (file_put_contents($part, file_get_contents($f['tmp_name']), FILE_APPEND | LOCK_EX) === false) throw new ApiError('Could not save the file', 500);
    clearstatcache(true, $part);
    $size = filesize($part);
    if ($size > IPA_MAX_MB * 1024 * 1024) { @unlink($part); throw new ApiError('IPA must be under ' . IPA_MAX_MB . ' MB', 422); }
    if ($index < $total - 1) return ['done' => false];
    $handle = fopen($part, 'rb');
    $magic = $handle ? fread($handle, 4) : '';
    if ($handle) fclose($handle);
    if ($magic !== "PK\x03\x04") { @unlink($part); throw new ApiError('That is not a valid IPA (zip) file', 422); }
    $base = trim((string)preg_replace('~[^A-Za-z0-9._-]+~', '-', pathinfo($name, PATHINFO_FILENAME)), '-.') ?: 'app';
    $final = mb_substr($base, 0, 60) . '-' . substr($uid, 0, 8) . '.ipa';
    if (!rename($part, IPA_DIR . '/' . $final)) throw new ApiError('Could not save the file', 500);
    return ['done' => true, 'path' => site_url() . '/uploads/ipa/' . $final, 'size_mb' => round($size / 1048576, 1)];
}

function str_in(array $in, string $key, int $max): string
{
    return mb_substr(trim((string)($in[$key] ?? '')), 0, $max);
}

// Only http(s) links or local uploads/ paths — never javascript: etc.
function clean_url(string $u, string $field): string
{
    if ($u === '') return '';
    if (preg_match('~^https?://\S+$~i', $u) || preg_match('~^/?uploads/[\w./-]+$~', $u)) return $u;
    throw new ApiError("$field must be an http(s) URL or an uploads/ path", 422);
}

function slugify(string $s): string
{
    $s = strtolower(trim((string)preg_replace('~[^A-Za-z0-9]+~', '-', $s), '-'));
    return $s === '' ? 'item' : $s;
}

// The other site builds URLs as /ipa-games/{cat}/{slug}-ipa/, so slugs never end in "-ipa".
function unique_slug(string $name): string
{
    $base = preg_replace('/-ipa$/', '', slugify($name)) ?: 'item';
    $st = db()->prepare('SELECT 1 FROM games WHERE slug=?');
    $slug = $base;
    $n = 2;
    while ($st->execute([$slug]) && $st->fetchColumn()) $slug = $base . '-' . $n++;
    return $slug;
}

function clean_item(array $in, ?array $existing): array
{
    $item = $existing ?? [];

    $name = str_in($in, 'name', 120);
    if ($name === '') throw new ApiError('Name is required', 422);

    $category = str_in($in, 'category', 60);
    $item['type'] = in_array($in['type'] ?? '', ['game', 'app'], true) ? $in['type'] : 'game';
    $item['name'] = $name;
    $item['category'] = $category === '' ? '' : slugify($category);
    $item['developer'] = str_in($in, 'developer', 120);
    $item['icon'] = clean_url(str_in($in, 'icon', 500), 'Icon');
    $item['short_description'] = str_in($in, 'short_description', 200);
    $item['description'] = str_in($in, 'description', 20000);
    $item['min_ios'] = str_in($in, 'min_ios', 16);
    $item['featured'] = [
        'popular' => !empty($in['popular']),
        'editors_choice' => !empty($in['editors_choice']),
    ];

    // Rating and screenshots: only changed when sent, so other callers keep what is stored.
    $item['rating'] ??= ['value' => 0.0, 'count' => 0];
    if (array_key_exists('rating_value', $in)) {
        $r = trim((string)$in['rating_value']);
        if ($r !== '' && (!is_numeric($r) || $r < 0 || $r > 5)) throw new ApiError('Rating must be between 0 and 5', 422);
        $item['rating']['value'] = round((float)$r, 2);
    }
    if (array_key_exists('rating_count', $in)) {
        $c = trim((string)$in['rating_count']);
        if ($c !== '' && !preg_match('/^\d{1,9}$/', $c)) throw new ApiError('Rating count must be a whole number', 422);
        $item['rating']['count'] = (int)$c;
    }
    foreach (['screenshots' => 'iPhone', 'ipad_screenshots' => 'iPad'] as $key => $label) {
        if (!array_key_exists($key, $in)) { $item[$key] ??= []; continue; }
        $urls = [];
        foreach ((array)$in[$key] as $u) {
            $u = clean_url(mb_substr(trim((string)$u), 0, 500), "$label screenshot");
            if ($u !== '' && !in_array($u, $urls, true)) $urls[] = $u;
        }
        if (count($urls) > MAX_SHOTS) throw new ApiError('Up to ' . MAX_SHOTS . " $label screenshots", 422);
        $item[$key] = $urls;
    }

    $versions = [];
    foreach ((array)($in['versions'] ?? []) as $v) {
        if (!is_array($v)) continue;
        $ver = str_in($v, 'version', 40);
        $url = clean_url(str_in($v, 'download_url', 1000), 'Download URL');
        if ($ver === '' && $url === '') continue;
        if ($ver === '') throw new ApiError('Every version needs a version number', 422);
        if ($url === '') throw new ApiError("Version $ver needs a download URL", 422);
        if (isset($versions[$ver])) throw new ApiError("Version $ver is listed twice", 422);
        $date = str_in($v, 'release_date', 10);
        $size = $v['size_mb'] ?? '';
        $versions[$ver] = [
            'version' => $ver,
            'release_date' => preg_match('~^\d{4}-\d{2}-\d{2}$~', $date) ? $date : '',
            'size_mb' => is_numeric($size) ? round((float)$size, 1) : null,
            'changelog' => str_in($v, 'changelog', 5000),
            'download_url' => $url,
        ];
    }
    if (!$versions) throw new ApiError('Add at least one version', 422);
    $versions = array_values($versions);
    usort($versions, fn($a, $b) => version_compare($b['version'], $a['version']));
    $item['latest_version'] = $versions[0]['version'];
    $item['versions'] = $versions;

    $ver = $item['latest_version'];
    $item['seo'] = [
        'title' => $name . ' ' . (preg_match('/^v/i', $ver) ? $ver : "v$ver"),
        'meta_description' => $item['short_description'] !== '' ? $item['short_description'] : $name,
    ];
    // "Email members when this goes live": only changed when sent, so other callers keep what is stored.
    if (array_key_exists('notify_email', $in)) $item['notify_email'] = !empty($in['notify_email']);
    $status = (string)($in['status'] ?? '');
    $item['publish_at'] = '';
    if ($status === 'scheduled') {
        // A scheduled release is a hidden draft that publish_due() flips at publish_at (UTC, ISO 8601).
        $at = strtotime((string)($in['publish_at'] ?? ''));
        if (!$at) throw new ApiError('Pick the date and time to publish', 422);
        if ($at <= time()) throw new ApiError('The publish time must be in the future', 422);
        if (!has_publish_at()) throw new ApiError('Scheduling needs sql/games_schedule.sql imported first', 500);
        $item['publish_at'] = gmdate('Y-m-d\TH:i:s\Z', $at);
        $status = 'draft';
    }
    $item['status'] = in_array($status, STATUSES, true) ? $status : 'published';

    $now = gmdate('Y-m-d\TH:i:s\Z');
    $item['created_at'] = ($item['created_at'] ?? '') ?: $now;
    $item['updated_at'] = $now;
    return $item;
}

function is_published(array $i): bool
{
    return ($i['status'] ?? 'published') === 'published';
}

// Fields the store list needs — keeps paged responses small.
const LIST_FIELDS = ['id', 'slug', 'type', 'name', 'developer', 'category', 'icon', 'short_description', 'latest_version', 'featured'];

function list_row(array $i): array
{
    return array_intersect_key($i, array_flip(LIST_FIELDS));
}

function matches_filters(array $i, string $type, string $cat, string $q): bool
{
    // "ipa" is the IPA tab: games whose name ends in "IPA" (e.g. "Minecraft IPA").
    if ($type === 'ipa') {
        if (!preg_match('/\bIPA$/i', trim($i['name'] ?? ''))) return false;
    } elseif ($type !== '' && ($i['type'] ?? '') !== $type) return false;
    if ($cat !== '' && ($i['category'] ?? '') !== $cat) return false;
    if ($q === '') return true;
    $hay = implode(' ', [$i['name'] ?? '', $i['developer'] ?? '', $i['category'] ?? '', $i['short_description'] ?? '', ...(array)($i['tags'] ?? [])]);
    return str_contains(mb_strtolower($hay), $q);
}

/* ---------- Support chat (tables in sql/support_chat.sql) ---------- */

/** The message text from a request body: trimmed, control characters removed, length-checked. */
function chat_text(array $b): string
{
    $t = trim(preg_replace('/[^\P{C}\n\t]/u', '', str_replace("\r\n", "\n", (string)($b['body'] ?? ''))) ?? '');
    if ($t === '') throw new ApiError('Type a message', 422);
    if (mb_strlen($t) > CHAT_MAX_LEN) throw new ApiError('Messages can be up to ' . CHAT_MAX_LEN . ' characters', 422);
    return $t;
}

function chat_thread(int $userId): ?array
{
    $st = db()->prepare('SELECT * FROM support_threads WHERE user_id=?');
    $st->execute([$userId]);
    return $st->fetch() ?: null;
}

function chat_row(array $m): array
{
    return ['id' => (int)$m['id'], 'from' => $m['from_admin'] ? 'admin' : 'user', 'body' => $m['body'], 'at' => iso_date($m['created_at'])];
}

/** Messages after id $after, oldest first. $after = 0: the latest 100 (the start of an open chat). */
function chat_messages(int $userId, int $after): array
{
    if ($after > 0) {
        $st = db()->prepare('SELECT * FROM support_messages WHERE user_id=? AND id>? ORDER BY id LIMIT 200');
        $st->execute([$userId, $after]);
        return array_map('chat_row', $st->fetchAll());
    }
    $st = db()->prepare('SELECT * FROM support_messages WHERE user_id=? ORDER BY id DESC LIMIT 100');
    $st->execute([$userId]);
    return array_map('chat_row', array_reverse($st->fetchAll()));
}

/** Records that one side has read up to message $id. */
function chat_mark_read(int $userId, bool $admin, int $id): void
{
    $col = $admin ? 'admin_read_id' : 'user_read_id';
    db()->prepare("UPDATE support_threads SET $col=GREATEST($col, ?) WHERE user_id=?")->execute([$id, $userId]);
}

/** Messages from the other side that this side hasn't read yet. */
function chat_unread(int $userId, bool $admin): int
{
    $col = $admin ? 'admin_read_id' : 'user_read_id';
    $st = db()->prepare("SELECT COUNT(*) FROM support_messages m JOIN support_threads t ON t.user_id=m.user_id
                         WHERE m.user_id=? AND m.from_admin=? AND m.id > t.$col");
    $st->execute([$userId, $admin ? 0 : 1]);
    return (int)$st->fetchColumn();
}

/** Adds a message to a member's chat (opening the chat again if it was closed). */
function chat_post(int $userId, bool $admin, string $text): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO support_messages (user_id, from_admin, body, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())')
            ->execute([$userId, (int)$admin, $text]);
        $id = (int)$pdo->lastInsertId();
        // The sender has obviously read everything up to their own message.
        $col = $admin ? 'admin_read_id' : 'user_read_id';
        $pdo->prepare("INSERT INTO support_threads (user_id, status, last_message_at, $col) VALUES (?, 'open', UTC_TIMESTAMP(), ?)
                       ON DUPLICATE KEY UPDATE status='open', last_message_at=VALUES(last_message_at), $col=VALUES($col)")
            ->execute([$userId, $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    $st = $pdo->prepare('SELECT * FROM support_messages WHERE id=?');
    $st->execute([$id]);
    return chat_row($st->fetch());
}

/* ---------- New-app emails to members (tables in sql/new_app_emails.sql) ---------- */

const MAIL_BATCH = 25;       // emails per cron run (the cron runs every minute)
const MAIL_TRIES = 3;        // attempts per recipient before it counts as failed

function has_column(string $table, string $col): bool
{
    static $cache = [];
    return $cache["$table.$col"] ??= (bool)db()->query("SHOW COLUMNS FROM `$table` LIKE " . db()->quote($col))->fetch();
}

/** False until sql/new_app_emails.sql has been imported. */
function mail_ready(): bool
{
    return has_column('games', 'notified_at') && has_column('users', 'notify_new_apps');
}

/** Signed token for a member's unsubscribe link (no extra column needed). */
function unsub_token(int $uid): string
{
    return substr(hash_hmac('sha256', "unsub:$uid", hash('sha256', ADMIN_PASS . '|' . DB['pass'] . '|' . DB['name'])), 0, 32);
}

function unsub_url(int $uid): string
{
    return site_url() . '/api.php?action=unsubscribe&u=' . $uid . '&t=' . unsub_token($uid);
}

/** [subject, text, html] of one campaign for one member, or null when its game no longer exists. */
function mail_compose(array $camp, array $u): ?array
{
    static $games = [];
    $brand = BRAND;
    $site = site_url();
    $unsub = unsub_url((int)$u['id']);
    $unsubH = htmlspecialchars($unsub, ENT_QUOTES);
    $name = htmlspecialchars((string)$u['username'], ENT_QUOTES);
    $subject = (string)$camp['subject'];

    if ($camp['kind'] === 'game') {
        $games[$camp['game_id']] ??= find_item((int)$camp['game_id'], null);
        $g = $games[$camp['game_id']];
        if (!$g) return null;
        // Opens on the main ipagame.store site (a plain Safari page); a link into the store would also open in Safari, without the member's login.
        $link = ($g['category'] ?? '') !== ''
            ? 'https://ipagame.store/ipa-games/' . rawurlencode($g['category']) . '/' . rawurlencode(preg_replace('/-ipa$/', '', $g['slug'])) . '-ipa/'
            : $site . '/game.html?slug=' . rawurlencode($g['slug']);
        $icon = preg_match('#^https?://#i', $g['icon']) ? $g['icon'] : ($g['icon'] !== '' ? $site . '/' . ltrim($g['icon'], '/') : '');
        $ver = $g['latest_version'] !== '' ? 'v' . ltrim($g['latest_version'], 'vV') : '';
        $title = htmlspecialchars($g['name'], ENT_QUOTES);
        $desc = htmlspecialchars($g['short_description'], ENT_QUOTES);
        $kind = $g['type'] === 'app' ? 'app' : 'game';
        $text = "Hi {$u['username']},\n\nA new $kind just landed on $brand: {$g['name']}" . ($ver ? " ($ver)" : '') . ".\n"
              . ($g['short_description'] !== '' ? "\n{$g['short_description']}\n" : '') . "\nOpen it: $link\n";
        $iconHtml = $icon !== '' ? '<img src="' . htmlspecialchars($icon, ENT_QUOTES) . '" width="96" height="96" alt="" style="display:block;margin:0 auto 14px;border-radius:22px">' : '';
        $inner = <<<HTML
<p style="margin:0 0 18px;font-size:15px;color:#6b7280">Hi $name, a new $kind just landed on $brand.</p>
$iconHtml
<h1 style="margin:0 0 4px;font-size:24px;line-height:1.25">$title</h1>
<p style="margin:0 0 6px;font-size:13px;color:#7c3aed;font-weight:700">$ver</p>
<p style="margin:0 0 22px;font-size:15px;line-height:1.5;color:#374151">$desc</p>
<a href="$link" style="display:inline-block;padding:13px 28px;border-radius:12px;background:#7c3aed;color:#ffffff;font-size:15px;font-weight:700;text-decoration:none">View game</a>
HTML;
    } else {
        $paras = array_filter(array_map('trim', preg_split('/\n{2,}/', str_replace("\r\n", "\n", (string)$camp['body']))));
        $text = "Hi {$u['username']},\n\n" . implode("\n\n", $paras) . "\n";
        $inner = "<p style=\"margin:0 0 16px;font-size:15px;color:#6b7280\">Hi $name,</p>"
               . implode('', array_map(fn($p) => '<p style="margin:0 0 14px;font-size:15px;line-height:1.55;color:#374151;text-align:left">'
                   . nl2br(htmlspecialchars($p, ENT_QUOTES)) . '</p>', $paras));
    }

    $text .= "\n--\nYou get this because you have an account at $brand. Unsubscribe: $unsub\n";
    $html = <<<HTML
<!doctype html>
<html><body style="margin:0;padding:0;background:#f4f5f7">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:32px 12px">
<tr><td align="center">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px;background:#ffffff;border-radius:18px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;color:#111418">
    <tr><td style="padding:28px 32px 0;text-align:center;font-size:13px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#7c3aed">$brand</td></tr>
    <tr><td style="padding:18px 32px 28px;text-align:center">$inner</td></tr>
    <tr><td style="padding:0 32px 28px;text-align:center;font-size:12px;line-height:1.5;color:#9ca3af">
      You get this because you have an account at $brand.<br><a href="$unsubH" style="color:#9ca3af">Unsubscribe</a>
    </td></tr>
  </table>
</td></tr>
</table>
</body></html>
HTML;
    return [$subject, $text, $html];
}

/** Sends one campaign email. true = sent, false = failed, null = nothing to send (game deleted). */
function mail_deliver(array $camp, array $u): ?bool
{
    $c = mail_compose($camp, $u);
    if (!$c) return null;
    $unsub = unsub_url((int)$u['id']);
    return send_mail(MAIL_BULK, $u['email'], $c[0], $c[1], $c[2], [
        'List-Unsubscribe' => "<$unsub>",
        'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        'Precedence' => 'bulk',
    ]);
}

/** Creates a campaign and queues it for every verified, active member who has not opted out. */
function mail_enqueue(string $kind, ?int $gameId, string $subject, ?string $body): array
{
    $pdo = db();
    $pdo->prepare('INSERT INTO mail_campaigns (kind, game_id, subject, body, created_at) VALUES (?,?,?,?,UTC_TIMESTAMP())')
        ->execute([$kind, $gameId, $subject, $body]);
    $id = (int)$pdo->lastInsertId();
    $ins = $pdo->prepare('INSERT INTO mail_queue (campaign_id, user_id) SELECT ?, id FROM users
                          WHERE email_verified_at IS NOT NULL AND disabled_at IS NULL AND notify_new_apps = 1');
    $ins->execute([$id]);
    $total = $ins->rowCount();
    $pdo->prepare('UPDATE mail_campaigns SET total=? WHERE id=?')->execute([$total, $id]);
    return ['id' => $id, 'total' => $total];
}

/** Queues the announcement of every game that is now published and was flagged to be emailed. */
function mail_enqueue_due(): int
{
    if (!mail_ready()) return 0;
    $n = 0;
    $rows = db()->query("SELECT id, name FROM games WHERE status='published' AND notify_email=1 AND notified_at IS NULL")->fetchAll();
    foreach ($rows as $g) {
        // The claim makes sure two runs never queue the same game twice. updated_at stays as it is.
        $claim = db()->prepare('UPDATE games SET notified_at=UTC_TIMESTAMP(), updated_at=updated_at WHERE id=? AND notified_at IS NULL');
        $claim->execute([$g['id']]);
        if ($claim->rowCount() === 1) {
            mail_enqueue('game', (int)$g['id'], 'New on ' . BRAND . ': ' . $g['name'], null);
            $n++;
        }
    }
    return $n;
}

/** One cron run: queue newly published games, then send up to $limit pending emails. */
function mail_sweep(int $limit = MAIL_BATCH): array
{
    $out = ['queued' => 0, 'sent' => 0, 'failed' => 0, 'pending' => 0, 'note' => ''];
    if (!mail_ready()) return ['note' => 'sql/new_app_emails.sql is not imported'] + $out;
    $pdo = db();
    if (!$pdo->query("SELECT GET_LOCK('ipa_mail_sweep', 0)")->fetchColumn()) return ['note' => 'another run is in progress'] + $out;
    try {
        $out['queued'] = mail_enqueue_due();
        $rows = $pdo->query("SELECT q.id, q.campaign_id, q.attempts, u.id AS uid, u.username, u.email, u.disabled_at, u.notify_new_apps
                             FROM mail_queue q LEFT JOIN users u ON u.id = q.user_id
                             WHERE q.status='pending' ORDER BY q.id LIMIT " . max(1, $limit))->fetchAll();
        $camps = [];
        $set = $pdo->prepare('UPDATE mail_queue SET status=?, attempts=?, sent_at=? WHERE id=?');
        $strikes = 0;
        foreach ($rows as $r) {
            if (!$r['uid'] || $r['disabled_at'] || !$r['notify_new_apps']) {   // gone, blocked or unsubscribed since queueing
                $set->execute(['cancelled', $r['attempts'], null, $r['id']]);
                continue;
            }
            $camps[$r['campaign_id']] ??= $pdo->query('SELECT * FROM mail_campaigns WHERE id=' . (int)$r['campaign_id'])->fetch();
            $res = mail_deliver($camps[$r['campaign_id']], ['id' => $r['uid'], 'username' => $r['username'], 'email' => $r['email']]);
            if ($res === null) {
                $set->execute(['cancelled', $r['attempts'], null, $r['id']]);
            } elseif ($res) {
                $set->execute(['sent', $r['attempts'] + 1, gmdate('Y-m-d H:i:s'), $r['id']]);
                $out['sent']++;
                $strikes = 0;
            } else {
                $tries = $r['attempts'] + 1;
                $set->execute([$tries >= MAIL_TRIES ? 'failed' : 'pending', $tries, null, $r['id']]);
                if ($tries >= MAIL_TRIES) $out['failed']++;
                if (++$strikes >= 3) { $out['note'] = 'stopped: 3 sends failed in a row (check the SMTP settings)'; break; }
            }
        }
        $out['pending'] = (int)$pdo->query("SELECT COUNT(*) FROM mail_queue WHERE status='pending'")->fetchColumn();
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('ipa_mail_sweep')")->fetchAll();
    }
    return $out;
}

/** Numbers and recent campaigns for the admin "Emails" tab. */
function mail_overview(): array
{
    $pdo = db();
    $subs = $pdo->query('SELECT SUM(notify_new_apps=1) AS yes, SUM(notify_new_apps=0) AS no FROM users
                         WHERE email_verified_at IS NOT NULL AND disabled_at IS NULL')->fetch();
    $camps = $pdo->query("SELECT c.id, c.kind, c.game_id, c.subject, c.total, c.created_at, g.name AS game_name,
                                 COALESCE(SUM(q.status='sent'),0) AS sent, COALESCE(SUM(q.status='failed'),0) AS failed,
                                 COALESCE(SUM(q.status='pending'),0) AS pending, COALESCE(SUM(q.status='cancelled'),0) AS cancelled
                          FROM mail_campaigns c LEFT JOIN mail_queue q ON q.campaign_id = c.id LEFT JOIN games g ON g.id = c.game_id
                          GROUP BY c.id ORDER BY c.id DESC LIMIT 50")->fetchAll();
    $games = $pdo->query("SELECT id, name, notify_email, notified_at FROM games WHERE status='published' ORDER BY updated_at DESC, id DESC LIMIT 300")->fetchAll();
    $tot = $pdo->query("SELECT COALESCE(SUM(status='sent'),0) AS sent, COALESCE(SUM(status='failed'),0) AS failed,
                               COALESCE(SUM(status='pending'),0) AS pending, COALESCE(SUM(status='cancelled'),0) AS cancelled FROM mail_queue")->fetch();
    // Sent per day in Sri Lanka time (UTC+5:30), last 14 days.
    $daily = $pdo->query("SELECT DATE(DATE_ADD(sent_at, INTERVAL 330 MINUTE)) AS d, COUNT(*) AS n FROM mail_queue
                          WHERE status='sent' AND sent_at > UTC_TIMESTAMP() - INTERVAL 15 DAY GROUP BY d")->fetchAll(PDO::FETCH_KEY_PAIR);
    return [
        'totals' => array_map('intval', $tot), 'daily' => (object)array_map('intval', $daily),
        'subscribers' => (int)$subs['yes'], 'unsubscribed' => (int)$subs['no'],
        'smtp' => !empty(MAIL_BULK['host']), 'site_url' => SITE_URL !== '',
        'games' => array_map(fn($g) => ['id' => (int)$g['id'], 'name' => $g['name'], 'auto' => (bool)$g['notify_email'],
                                        'notified_at' => iso_date($g['notified_at'])], $games),
        'campaigns' => array_map(fn($c) => [
            'id' => (int)$c['id'], 'kind' => $c['kind'], 'subject' => $c['subject'], 'game' => $c['game_name'],
            'total' => (int)$c['total'], 'sent' => (int)$c['sent'], 'failed' => (int)$c['failed'],
            'pending' => (int)$c['pending'], 'cancelled' => (int)$c['cancelled'], 'created_at' => iso_date($c['created_at']),
        ], $camps),
    ];
}

if (PHP_SAPI === 'cli') return;   // publish_scheduled.php only needs the functions above

try {
    switch ($_GET['action'] ?? '') {
        case 'list':
            require_member();
            $all = is_admin() && !empty($_GET['all']);
            // No page param: the admin panel's list.
            if (!isset($_GET['page'])) {
                require_admin();
                respond(['items' => fetch_admin_rows(), 'categories' => CATEGORIES]);
            }
            $items = fetch_items($all, false);

            $type = (string)($_GET['type'] ?? '');
            $cat = (string)($_GET['category'] ?? '');
            $q = mb_strtolower(trim((string)($_GET['q'] ?? '')));
            $facets = ['types' => [], 'categories' => []];
            $counts = [];
            foreach ($items as $i) {
                $facets['types'][$i['type'] ?? 'game'] = true;
                if (empty($i['category'])) continue;
                $facets['categories'][$i['category']] = true;
                // Counts are scoped to the selected type so "Games" only shows game categories.
                if (matches_filters($i, $type, '', '')) $counts[$i['category']] = ($counts[$i['category']] ?? 0) + 1;
            }
            $facets = ['types' => array_keys($facets['types']), 'categories' => array_keys($facets['categories'])];
            sort($facets['categories']);
            arsort($counts);
            $facets['category_counts'] = $counts ?: new stdClass();

            $featured = array_values(array_map('list_row', array_filter($items,
                fn($i) => !empty($i['featured']['popular']) || !empty($i['featured']['editors_choice']))));
            $filtered = array_values(array_filter($items, fn($i) => matches_filters($i, $type, $cat, $q)));

            // Home page shelves: the newest N items of each category, biggest category first.
            $shelves = [];
            if (isset($_GET['shelves'])) {
                $n = max(1, min(30, (int)$_GET['shelves']));
                foreach ($filtered as $i) {
                    $c = $i['category'] ?? '';
                    if ($c === '') continue;
                    $shelves[$c] ??= ['category' => $c, 'count' => 0, 'items' => []];
                    if ($shelves[$c]['count']++ < $n) $shelves[$c]['items'][] = list_row($i);
                }
                $shelves = array_values($shelves);
                usort($shelves, fn($a, $b) => $b['count'] <=> $a['count']);
            }

            $per = max(1, min(100, (int)($_GET['per_page'] ?? 24)));
            $total = count($filtered);
            $pages = max(1, (int)ceil($total / $per));
            $page = max(1, min($pages, (int)$_GET['page']));
            respond([
                'items' => array_map('list_row', array_slice($filtered, ($page - 1) * $per, $per)),
                'total' => $total,
                'page' => $page,
                'pages' => $pages,
                'per_page' => $per,
                'facets' => $facets,
                'featured' => $featured,
                'shelves' => $shelves,
                'social' => SOCIAL,
            ]);

        case 'get':
            require_member();
            $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
            $slug = isset($_GET['slug']) ? (string)$_GET['slug'] : null;
            $item = find_item($id, $slug);
            if (!$item || (!is_published($item) && !is_admin())) throw new ApiError('Not found', 404);
            respond(['item' => $item]);

        case 'me':
            $pending = pending_user();
            respond(['admin' => is_admin(), 'user' => current_user(), 'google_client_id' => GOOGLE_CLIENT_ID,
                     'pending' => $pending ? ['email' => $pending['email'], 'resend_in' => resend_wait($pending)] : null]);

        case 'register':
            require_post();
            $b = body();
            $username = trim((string)($b['username'] ?? ''));
            $email = mb_strtolower(trim((string)($b['email'] ?? '')));
            $pass = (string)($b['password'] ?? '');
            if (!preg_match('/^[A-Za-z0-9_]{3,30}$/', $username)) throw new ApiError('Username: 3–30 letters, numbers or _', 422);
            if (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new ApiError('Enter a valid email', 422);
            if (strlen($pass) < 8) throw new ApiError('Password must be at least 8 characters', 422);
            if (strlen($pass) > 72) throw new ApiError('Password is too long', 422);
            rate_check('signup:' . client_ip(), LIMIT_SIGNUPS, 'Too many new accounts from your network. Try again later.');
            check_mail_ip();
            // Sign-ups never confirmed within a day give their username and email back.
            db()->exec('DELETE FROM users WHERE email_verified_at IS NULL AND created_at < UTC_TIMESTAMP() - INTERVAL 1 DAY');
            $st = db()->prepare('SELECT username=? AS same_name FROM users WHERE username=? OR email=? LIMIT 1');
            $st->execute([$username, $username, $email]);
            if ($row = $st->fetch()) throw new ApiError($row['same_name'] ? 'That username is taken' : 'That email is already registered', 409);
            db()->prepare('INSERT INTO users (username, email, password_hash, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())')
                ->execute([$username, $email, password_hash($pass, PASSWORD_DEFAULT)]);
            $newId = (int)db()->lastInsertId();              // read before rate_hit() inserts its own row
            rate_hit('signup:' . client_ip());
            $st = db()->prepare('SELECT * FROM users WHERE id=?');
            $st->execute([$newId]);
            respond(start_verification($st->fetch()));

        case 'user_login':
            require_post();
            throttle_login();
            $b = body();
            $login = trim((string)($b['login'] ?? ''));
            $st = db()->prepare('SELECT * FROM users WHERE username=? OR email=? LIMIT 1');
            $st->execute([$login, mb_strtolower($login)]);
            $u = $st->fetch();
            if (!$u || !password_verify((string)($b['password'] ?? ''), $u['password_hash'])) login_failed();
            if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
                db()->prepare('UPDATE users SET password_hash=? WHERE id=?')
                    ->execute([password_hash((string)$b['password'], PASSWORD_DEFAULT), $u['id']]);
            }
            if ($u['disabled_at']) throw new ApiError('This account has been disabled. Contact support if you think this is a mistake.', 403);
            // Right password but the email was never confirmed: ask for the code first.
            if (!$u['email_verified_at']) respond(start_verification($u));
            login_user((int)$u['id']);
            respond(['user' => current_user()]);

        case 'verify_email':
            require_post();
            $u = pending_user() ?? throw new ApiError('Your session expired. Sign in again.', 440);
            check_code($u, 'verify', preg_replace('/\D/', '', (string)(body()['code'] ?? '')));
            db()->prepare('UPDATE users SET email_verified_at=UTC_TIMESTAMP(), verify_code_hash=NULL, verify_expires_at=NULL,
                           verify_attempts=0 WHERE id=?')->execute([$u['id']]);
            login_user((int)$u['id']);
            respond(['user' => current_user()]);

        case 'resend_code':
            require_post();
            $u = pending_user() ?? throw new ApiError('Your session expired. Sign in again.', 440);
            if ($wait = resend_wait($u)) throw new ApiError("Wait $wait s before asking for another code.", 429);
            if (!send_code($u, 'verify')) throw new ApiError("We couldn't send the email right now. Try again in a minute.", 502);
            respond(['ok' => true, 'resend_in' => VERIFY_RESEND]);

        // Forgot password, step 1: email a reset code. The reply is the same whether or not the email
        // has an account, so this can't be used to find out who is registered.
        case 'forgot_password':
            require_post();
            $email = mb_strtolower(trim((string)(body()['email'] ?? '')));
            if (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new ApiError('Enter a valid email', 422);
            check_mail_ip();                                 // same for every address, so it reveals nothing
            $_SESSION['reset_email'] = $email;
            $u = reset_user();
            if ($u && $u['disabled_at']) $u = null;        // disabled accounts get the same reply but no email
            $wait = $u ? resend_wait($u, 'reset') : 0;
            $reply = ['ok' => true, 'email' => $email, 'resend_in' => $wait ?: VERIFY_RESEND];
            if (!$u || $wait) respond($reply);
            $send = function () use ($u) {
                try {
                    if (!send_code($u, 'reset')) error_log("reset email to user {$u['id']} failed");
                } catch (ApiError $e) {
                    // Per-address limit reached: stay silent so the reply matches unknown emails.
                }
            };
            // On PHP-FPM (cPanel) answer first and send afterwards, so a slow mail server can't hint
            // that this email has an account.
            if (function_exists('fastcgi_finish_request')) {
                http_response_code(200);
                echo json_encode($reply, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                session_write_close();
                fastcgi_finish_request();
                $send();
                exit;
            }
            $send();
            respond($reply);

        // Step 2: the code plus a new password. Also counts as proof the email is theirs.
        case 'reset_password':
            require_post();
            $b = body();
            if (empty($_SESSION['reset_email'])) throw new ApiError('Your session expired. Start again.', 440);
            $code = preg_replace('/\D/', '', (string)($b['code'] ?? ''));
            $pass = (string)($b['password'] ?? '');
            if (strlen($code) !== 6) throw new ApiError('Enter the 6-digit code', 422);
            if (strlen($pass) < 8) throw new ApiError('Password must be at least 8 characters', 422);
            if (strlen($pass) > 72) throw new ApiError('Password is too long', 422);
            $u = reset_user() ?? throw new ApiError("That code isn't right.", 422);
            if ($u['disabled_at']) throw new ApiError("That code isn't right.", 422);
            check_code($u, 'reset', $code);
            db()->prepare('UPDATE users SET password_hash=?, reset_code_hash=NULL, reset_expires_at=NULL, reset_attempts=0,
                           email_verified_at=COALESCE(email_verified_at, UTC_TIMESTAMP()) WHERE id=?')
                ->execute([password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
            unset($_SESSION['reset_email']);
            forget_all_devices((int)$u['id']);
            login_user((int)$u['id']);
            respond(['user' => current_user()]);

        // "Continue with Google": signs in the account with this Google ID or email, or creates one.
        case 'google_login':
            require_post();
            $c = google_claims((string)(body()['credential'] ?? ''));
            $email = mb_strtolower(trim((string)$c['email']));
            $picture = preg_match('~^https://[\w.-]+\.googleusercontent\.com/~', (string)($c['picture'] ?? '')) ? (string)$c['picture'] : '';
            $st = db()->prepare('SELECT * FROM users WHERE google_sub=? OR email=? ORDER BY google_sub=? DESC LIMIT 1');
            $st->execute([$c['sub'], $email, $c['sub']]);
            $u = $st->fetch();
            if ($u) {
                if ($u['disabled_at']) throw new ApiError('This account has been disabled. Contact support if you think this is a mistake.', 403);
                // Google has confirmed the email, so link it and count it as verified.
                $set = ['google_sub' => $c['sub'], 'email_verified_at' => $u['email_verified_at'] ?: gmdate('Y-m-d H:i:s')];
                // An unverified sign-up with this email may have been made by someone else, who would
                // know its password: throw that password away ("Forgot password" sets a new one).
                if (!$u['email_verified_at']) $set['password_hash'] = password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT);
                // Keep a photo the user uploaded; otherwise follow their current Google photo.
                if ($picture !== '' && !is_uploaded_avatar($u['avatar_url'])) $set['avatar_url'] = $picture;
                db()->prepare('UPDATE users SET ' . implode(',', array_map(fn($k) => "$k=?", array_keys($set))) . ' WHERE id=?')
                    ->execute([...array_values($set), $u['id']]);
                $id = (int)$u['id'];
            } else {
                rate_check('signup:' . client_ip(), LIMIT_SIGNUPS, 'Too many new accounts from your network. Try again later.');
                // No password yet (a random one nobody knows); "Forgot password" can set one later.
                db()->prepare('INSERT INTO users (username, email, password_hash, created_at, email_verified_at, google_sub, avatar_url)
                               VALUES (?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), ?, ?)')
                    ->execute([username_from((string)($c['name'] ?? ''), $email), $email,
                               password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), $c['sub'], $picture ?: null]);
                $id = (int)db()->lastInsertId();
                rate_hit('signup:' . client_ip());
            }
            login_user($id);
            respond(['user' => current_user(), 'created' => !$u]);

        case 'upload_avatar':
            require_post();
            $me = current_user() ?? throw new ApiError('Login required', 401);
            $path = store_avatar($_FILES['avatar'] ?? ['error' => UPLOAD_ERR_NO_FILE]);
            $st = db()->prepare('SELECT avatar_url FROM users WHERE id=?');
            $st->execute([$me['id']]);
            $old = $st->fetchColumn();
            db()->prepare('UPDATE users SET avatar_url=? WHERE id=?')->execute([$path, $me['id']]);
            remove_avatar_file($old ?: null);
            respond(['user' => current_user()]);

        case 'remove_avatar':
            require_post();
            $me = current_user() ?? throw new ApiError('Login required', 401);
            $st = db()->prepare('SELECT avatar_url FROM users WHERE id=?');
            $st->execute([$me['id']]);
            remove_avatar_file($st->fetchColumn() ?: null);
            db()->prepare('UPDATE users SET avatar_url=NULL WHERE id=?')->execute([$me['id']]);
            respond(['user' => current_user()]);

        case 'delete_account':
            require_post();
            $me = current_user() ?? throw new ApiError('Login required', 401);
            throttle_login();
            $st = db()->prepare('SELECT password_hash, google_sub FROM users WHERE id=?');
            $st->execute([$me['id']]);
            $row = $st->fetch();
            if (isset(body()['credential'])) {
                // Google accounts may have no known password: a fresh Google sign-in confirms instead.
                $c = google_claims((string)body()['credential']);
                if (empty($row['google_sub']) || !hash_equals((string)$row['google_sub'], (string)$c['sub'])) {
                    throw new ApiError('That Google account is not linked to this account', 403);
                }
            } elseif (!password_verify((string)(body()['password'] ?? ''), (string)$row['password_hash'])) {
                rate_hit('login:' . client_ip());
                throw new ApiError('Wrong password', 401);
            }
            $st = db()->prepare('SELECT avatar_url FROM users WHERE id=?');
            $st->execute([$me['id']]);
            remove_avatar_file($st->fetchColumn() ?: null);
            db()->prepare('DELETE FROM users WHERE id=?')->execute([$me['id']]);
            unset($_SESSION['user_id'], $_SESSION['pending_user_id'], $_SESSION['reset_email']);
            session_regenerate_id(true);
            respond(['ok' => true]);

        case 'user_logout':
            require_post();
            forget_device();
            unset($_SESSION['user_id'], $_SESSION['pending_user_id']);
            session_regenerate_id(true);
            respond(['ok' => true]);

        case 'login':
            require_post();
            $b = body();
            if (ADMIN_PASS === '') throw new ApiError('Admin login not configured (create config.php)', 500);
            rate_check('admin:' . client_ip(), LIMIT_ADMIN_FAILS, 'Too many wrong attempts. Try again in 15 minutes.');
            $okUser = hash_equals(ADMIN_USER, (string)($b['username'] ?? ''));
            $okPass = hash_equals(ADMIN_PASS, (string)($b['password'] ?? ''));
            if (!$okUser || !$okPass) {
                rate_hit('admin:' . client_ip());
                throw new ApiError('Wrong username or password', 401);
            }
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            respond(['ok' => true]);

        case 'logout':
            require_post();
            $_SESSION = [];
            session_destroy();
            respond(['ok' => true]);

        case 'save':
            require_post();
            require_admin();
            $b = body();
            $id = isset($b['id']) && $b['id'] !== '' && $b['id'] !== null ? (int)$b['id'] : null;
            $pdo = db();
            $pdo->beginTransaction();
            try {
                if ($id === null) {
                    $item = clean_item($b, null);
                    $item['slug'] = unique_slug($item['name']);
                } else {
                    $item = clean_item($b, find_item($id, null) ?? throw new ApiError('Not found', 404));
                }
                $id = save_item($item, $id);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            // A game published with "Email members" ticked is queued now; the cron sends the emails.
            try { mail_enqueue_due(); } catch (Throwable $e) { error_log('mail_enqueue_due: ' . $e->getMessage()); }
            respond(['item' => find_item($id, null)]);

        case 'delete':
            require_post();
            require_admin();
            $id = (int)(body()['id'] ?? 0);
            $removed = find_item($id, null) ?? throw new ApiError('Not found', 404);
            // Versions and screenshots go with it (ON DELETE CASCADE).
            db()->prepare('DELETE FROM games WHERE id=?')->execute([$id]);
            // Remove its uploaded icon and screenshots if nothing else uses them.
            foreach ([$removed['icon'], ...$removed['screenshots'], ...$removed['ipad_screenshots']] as $url) remove_unused_upload($url);
            respond(['ok' => true]);

        /* ---------- Admin: store users ---------- */
        case 'admin_users':
            require_admin();
            $rows = db()->query('SELECT id, username, email, created_at, last_login_at, email_verified_at, disabled_at, avatar_url, google_sub
                                 FROM users ORDER BY created_at DESC, id DESC')->fetchAll();
            respond(['users' => array_map(fn($u) => [
                'id' => (int)$u['id'],
                'username' => $u['username'],
                'email' => $u['email'],
                'avatar' => avatar_of($u),
                'google' => !empty($u['google_sub']),
                'created_at' => iso_date($u['created_at']),
                'last_login_at' => iso_date($u['last_login_at']),
                'verified_at' => iso_date($u['email_verified_at']),
                'disabled_at' => iso_date($u['disabled_at']),
            ], $rows)]);

        // Edit one user. Only the fields sent are changed: username, email, verified (bool), disabled (bool).
        case 'admin_user_update':
            require_post();
            require_admin();
            $b = body();
            $id = (int)($b['id'] ?? 0);
            $st = db()->prepare('SELECT * FROM users WHERE id=?');
            $st->execute([$id]);
            $u = $st->fetch() ?: throw new ApiError('User not found', 404);
            $set = [];
            if (array_key_exists('username', $b)) {
                $username = trim((string)$b['username']);
                if (!preg_match('/^[A-Za-z0-9_]{3,30}$/', $username)) throw new ApiError('Username: 3–30 letters, numbers or _', 422);
                $set['username'] = $username;
            }
            if (array_key_exists('email', $b)) {
                $email = mb_strtolower(trim((string)$b['email']));
                if (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new ApiError('Enter a valid email', 422);
                $set['email'] = $email;
            }
            foreach (['username', 'email'] as $col) {
                if (!isset($set[$col])) continue;
                $dup = db()->prepare("SELECT 1 FROM users WHERE $col=? AND id<>?");
                $dup->execute([$set[$col], $id]);
                if ($dup->fetchColumn()) throw new ApiError($col === 'email' ? 'That email is already registered' : 'That username is taken', 409);
            }
            if (array_key_exists('verified', $b)) {
                $set['email_verified_at'] = $b['verified'] ? ($u['email_verified_at'] ?: gmdate('Y-m-d H:i:s')) : null;
                if ($b['verified']) $set += ['verify_code_hash' => null, 'verify_expires_at' => null, 'verify_attempts' => 0];
            }
            if (array_key_exists('disabled', $b)) {
                $set['disabled_at'] = $b['disabled'] ? ($u['disabled_at'] ?: gmdate('Y-m-d H:i:s')) : null;
            }
            if ($set) {
                db()->prepare('UPDATE users SET ' . implode(',', array_map(fn($c) => "$c=?", array_keys($set))) . ' WHERE id=?')
                    ->execute([...array_values($set), $id]);
            }
            respond(['ok' => true]);

        case 'admin_user_delete':
            require_post();
            require_admin();
            $id = (int)(body()['id'] ?? 0);
            $st = db()->prepare('SELECT avatar_url FROM users WHERE id=?');
            $st->execute([$id]);
            $avatar = $st->fetchColumn();
            $st = db()->prepare('DELETE FROM users WHERE id=?');
            $st->execute([$id]);
            if (!$st->rowCount()) throw new ApiError('User not found', 404);
            remove_avatar_file($avatar ?: null);
            respond(['ok' => true]);

        /* ---------- Downloads ---------- */
        // A signed-in member tapped Download on a game page. Guests aren't recorded.
        case 'track_download':
            require_post();
            $me = current_user();
            if (!$me) respond(['ok' => true]);
            $b = body();
            $item = find_item(null, (string)($b['slug'] ?? ''));
            if (!$item || !is_published($item)) respond(['ok' => true]);
            $ver = mb_substr(trim((string)($b['version'] ?? '')), 0, 40);
            // Same member + game + version within a minute counts once (double taps).
            $dup = db()->prepare('SELECT 1 FROM downloads WHERE user_id=? AND game_id=? AND version=? AND created_at > ?');
            $dup->execute([$me['id'], $item['id'], $ver, gmdate('Y-m-d H:i:s', time() - 60)]);
            if (!$dup->fetchColumn()) {
                db()->prepare('INSERT INTO downloads (user_id, game_id, game_name, version, created_at) VALUES (?,?,?,?,?)')
                    ->execute([$me['id'], $item['id'], mb_substr($item['name'], 0, 255), $ver, gmdate('Y-m-d H:i:s')]);
            }
            respond(['ok' => true]);

        // Latest 5000 downloads by members, plus all-time totals per member.
        case 'admin_downloads':
            require_admin();
            $rows = db()->query('SELECT d.id, d.user_id, d.game_id, d.game_name, d.version, d.created_at, u.username, u.email
                                 FROM downloads d JOIN users u ON u.id = d.user_id ORDER BY d.id DESC LIMIT 5000')->fetchAll();
            $tot = db()->query('SELECT COUNT(*) AS n, COUNT(DISTINCT user_id) AS users FROM downloads')->fetch();
            $per = db()->query('SELECT user_id, COUNT(*) AS n FROM downloads GROUP BY user_id')->fetchAll(PDO::FETCH_KEY_PAIR);
            respond(['total' => (int)$tot['n'], 'users' => (int)$tot['users'],
                     'per_user' => (object)array_map('intval', $per),
                     'downloads' => array_map(fn($r) => [
                         'id' => (int)$r['id'], 'user_id' => (int)$r['user_id'], 'username' => $r['username'], 'email' => $r['email'],
                         'game_id' => $r['game_id'] === null ? null : (int)$r['game_id'], 'game' => $r['game_name'],
                         'version' => $r['version'], 'created_at' => iso_date($r['created_at']),
                     ], $rows)]);

        /* ---------- New-app emails ---------- */
        // Member: turn the "new app" emails on or off from the account page.
        case 'set_notify':
            require_post();
            $me = current_user() ?? throw new ApiError('Login required', 401);
            if (!mail_ready()) throw new ApiError('Not available yet', 500);
            db()->prepare('UPDATE users SET notify_new_apps=? WHERE id=?')->execute([(int)!empty(body()['on']), $me['id']]);
            respond(['ok' => true]);

        // The link in every email. GET shows a confirm button; the POST (button or mail-client one-click) unsubscribes.
        case 'unsubscribe':
            $uid = (int)($_GET['u'] ?? 0);
            $valid = $uid > 0 && hash_equals(unsub_token($uid), (string)($_GET['t'] ?? ''));
            $done = false;
            if ($valid && $_SERVER['REQUEST_METHOD'] === 'POST' && mail_ready()) {
                db()->prepare('UPDATE users SET notify_new_apps=0 WHERE id=?')->execute([$uid]);
                $done = true;
            }
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
            $brand = BRAND;
            $msg = !$valid ? "<h1>Invalid link</h1><p>This unsubscribe link isn't valid.</p>"
                 : ($done ? "<h1>You're unsubscribed</h1><p>You won't get new-app emails from $brand any more. You can turn them back on in your account.</p>"
                          : "<h1>Unsubscribe?</h1><p>Stop new-app emails from $brand?</p><form method=\"post\"><button>Unsubscribe</button></form>");
            echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
               . '<title>Unsubscribe</title><style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#0b0d12;color:#e8eaed;'
               . 'font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;text-align:center;padding:20px}main{max-width:380px}h1{font-size:22px}'
               . 'p{color:#9aa0a6;line-height:1.5}button{border:0;border-radius:12px;background:#7c3aed;color:#fff;font-size:15px;font-weight:700;padding:13px 28px}</style></head>'
               . "<body><main>$msg</main></body></html>";
            exit;

        // Admin "Emails" tab: numbers, the games you can announce, recent campaigns.
        case 'admin_mail':
            require_admin();
            if (!mail_ready()) respond(['ready' => false]);
            respond(['ready' => true] + mail_overview());

        // Queue the announcement of one published game for every subscribed member.
        case 'admin_mail_game':
            require_post();
            require_admin();
            if (!mail_ready()) throw new ApiError('Import sql/new_app_emails.sql first', 500);
            $item = find_item((int)(body()['game_id'] ?? 0), null);
            if (!$item || !is_published($item)) throw new ApiError('Pick a published game', 422);
            respond(mail_enqueue('game', $item['id'], 'New on ' . BRAND . ': ' . $item['name'], null));

        // Queue a custom message for every subscribed member.
        case 'admin_mail_custom':
            require_post();
            require_admin();
            if (!mail_ready()) throw new ApiError('Import sql/new_app_emails.sql first', 500);
            $subject = str_in(body(), 'subject', 150);
            $text = str_in(body(), 'body', 5000);
            if ($subject === '' || $text === '') throw new ApiError('Write a subject and a message', 422);
            respond(mail_enqueue('custom', null, $subject, $text));

        // Send one sample to an address (a custom draft, or a game's announcement) without queueing anything.
        case 'admin_mail_test':
            require_post();
            require_admin();
            $b = body();
            $to = mb_strtolower(trim((string)($b['to'] ?? '')));
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new ApiError('Enter the email to send the test to', 422);
            if (!empty($b['game_id'])) {
                $item = find_item((int)$b['game_id'], null) ?? throw new ApiError('Game not found', 404);
                $camp = ['kind' => 'game', 'game_id' => $item['id'], 'subject' => 'New on ' . BRAND . ': ' . $item['name'], 'body' => null];
            } else {
                $camp = ['kind' => 'custom', 'game_id' => null, 'subject' => str_in($b, 'subject', 150), 'body' => str_in($b, 'body', 5000)];
                if ($camp['subject'] === '' || $camp['body'] === '') throw new ApiError('Write a subject and a message', 422);
            }
            $camp['subject'] = '[Test] ' . $camp['subject'];
            if (!mail_deliver($camp, ['id' => 0, 'username' => 'there', 'email' => $to])) throw new ApiError('The email could not be sent. Check the SMTP settings.', 502);
            respond(['ok' => true]);

        // Send a few pending emails right now (what the cron does every minute).
        case 'admin_mail_run':
            require_post();
            require_admin();
            respond(mail_sweep(10));

        // Every recipient of one campaign with where their email stands.
        case 'admin_mail_detail':
            require_admin();
            if (!mail_ready()) throw new ApiError('Import sql/new_app_emails.sql first', 500);
            $st = db()->prepare('SELECT q.id, q.status, q.attempts, q.sent_at, u.username, u.email
                                 FROM mail_queue q LEFT JOIN users u ON u.id = q.user_id WHERE q.campaign_id=? ORDER BY q.id LIMIT 2000');
            $st->execute([(int)($_GET['id'] ?? 0)]);
            respond(['recipients' => array_map(fn($r) => [
                'id' => (int)$r['id'], 'status' => $r['status'], 'attempts' => (int)$r['attempts'],
                'sent_at' => iso_date($r['sent_at']), 'username' => $r['username'] ?? '(deleted)', 'email' => $r['email'] ?? '',
            ], $st->fetchAll())]);

        // Put a campaign's failed emails back in the queue for another go.
        case 'admin_mail_retry':
            require_post();
            require_admin();
            $st = db()->prepare("UPDATE mail_queue SET status='pending', attempts=0 WHERE campaign_id=? AND status='failed'");
            $st->execute([(int)(body()['id'] ?? 0)]);
            respond(['ok' => true, 'retried' => $st->rowCount()]);

        // Stop a campaign: its unsent emails are cancelled.
        case 'admin_mail_cancel':
            require_post();
            require_admin();
            db()->prepare("UPDATE mail_queue SET status='cancelled' WHERE campaign_id=? AND status='pending'")->execute([(int)(body()['id'] ?? 0)]);
            respond(['ok' => true]);

        /* ---------- Support chat: member side ---------- */
        // New messages after ?after=<id> (marked read). ?peek=1 only returns the unread count (chat closed).
        // 403 rather than 401 so an admin browsing the store isn't sent to the sign-in page.
        case 'chat':
            $me = current_user() ?? throw new ApiError('Sign in to chat with support', 403);
            $thread = chat_thread($me['id']);
            $messages = [];
            if ($thread && empty($_GET['peek'])) {
                $messages = chat_messages($me['id'], max(0, (int)($_GET['after'] ?? 0)));
                if ($messages) chat_mark_read($me['id'], false, end($messages)['id']);
                $thread = chat_thread($me['id']);
            }
            respond(['messages' => $messages, 'unread' => $thread ? chat_unread($me['id'], false) : 0,
                     'seen' => (int)($thread['admin_read_id'] ?? 0)]);

        case 'chat_send':
            require_post();
            $me = current_user() ?? throw new ApiError('Sign in to chat with support', 403);
            $text = chat_text(body());
            $key = 'chat:' . $me['id'];
            rate_check($key, LIMIT_CHAT, 'You are sending messages too fast. Wait a few minutes.');
            rate_hit($key);
            respond(['message' => chat_post($me['id'], false, $text)]);

        /* ---------- Support chat: admin side ---------- */
        case 'admin_chats':
            require_admin();
            $rows = db()->query("SELECT t.*, u.username, u.email, u.avatar_url, u.disabled_at,
                    (SELECT COUNT(*) FROM support_messages m WHERE m.user_id=t.user_id AND m.from_admin=0 AND m.id > t.admin_read_id) AS unread,
                    (SELECT CONCAT(m.from_admin, ':', LEFT(m.body, 140)) FROM support_messages m WHERE m.user_id=t.user_id ORDER BY m.id DESC LIMIT 1) AS last
                FROM support_threads t JOIN users u ON u.id=t.user_id
                ORDER BY t.last_message_at DESC LIMIT 500")->fetchAll();
            respond(['chats' => array_map(fn($r) => [
                'user' => ['id' => (int)$r['user_id'], 'username' => $r['username'], 'email' => $r['email'],
                           'avatar' => avatar_of($r), 'disabled' => (bool)$r['disabled_at']],
                'status' => $r['status'],
                'unread' => (int)$r['unread'],
                'last_at' => iso_date($r['last_message_at']),
                'last_from' => str_starts_with((string)$r['last'], '1:') ? 'admin' : 'user',
                'last' => (string)substr((string)$r['last'], 2),
            ], $rows)]);

        // One member's chat: messages after ?after=<id> (marked read by the admin) + who they are.
        case 'admin_chat':
            require_admin();
            $uid = (int)($_GET['user_id'] ?? 0);
            $st = db()->prepare('SELECT id, username, email, avatar_url, disabled_at FROM users WHERE id=?');
            $st->execute([$uid]);
            $u = $st->fetch() ?: throw new ApiError('User not found', 404);
            $thread = chat_thread($uid);
            $messages = $thread ? chat_messages($uid, max(0, (int)($_GET['after'] ?? 0))) : [];
            if ($messages) chat_mark_read($uid, true, end($messages)['id']);
            respond([
                'user' => ['id' => (int)$u['id'], 'username' => $u['username'], 'email' => $u['email'],
                           'avatar' => avatar_of($u), 'disabled' => (bool)$u['disabled_at']],
                'status' => $thread['status'] ?? 'open',
                'seen' => (int)($thread['user_read_id'] ?? 0),
                'messages' => $messages,
            ]);

        case 'admin_chat_send':
            require_post();
            require_admin();
            $b = body();
            $uid = (int)($b['user_id'] ?? 0);
            $st = db()->prepare('SELECT 1 FROM users WHERE id=?');
            $st->execute([$uid]);
            if (!$st->fetchColumn()) throw new ApiError('User not found', 404);
            respond(['message' => chat_post($uid, true, chat_text($b))]);

        // Mark a chat as resolved ('closed') or open again. A new message from either side reopens it.
        case 'admin_chat_status':
            require_post();
            require_admin();
            $b = body();
            $status = (string)($b['status'] ?? '');
            if (!in_array($status, ['open', 'closed'], true)) throw new ApiError('Bad status', 422);
            $st = db()->prepare('UPDATE support_threads SET status=? WHERE user_id=?');
            $st->execute([$status, (int)($b['user_id'] ?? 0)]);
            respond(['ok' => true]);

        case 'upload_icon':
            require_post();
            require_admin();
            respond(['path' => store_upload_image($_FILES['icon'] ?? null, ICON_DIR, 2, 'Icon')]);

        case 'upload_screenshot':
            require_post();
            require_admin();
            respond(['path' => store_upload_image($_FILES['shot'] ?? null, SHOT_DIR, 5, 'Screenshot')]);

        case 'upload_ipa':
            // One slice of a big IPA per request (stays under post_max_size); the last slice returns the link.
            require_post();
            require_admin();
            respond(store_ipa_chunk($_FILES['chunk'] ?? null, (string)($_POST['upload_id'] ?? ''), (int)($_POST['index'] ?? -1),
                                    (int)($_POST['total'] ?? 0), (string)($_POST['name'] ?? '')));

        default:
            throw new ApiError('Unknown action', 400);
    }
} catch (ApiError $e) {
    respond(['error' => $e->getMessage()], $e->getCode() ?: 400);
} catch (Throwable $e) {
    error_log('api.php: ' . $e->getMessage());
    respond(['error' => 'Server error'], 500);
}
