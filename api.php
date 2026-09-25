<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
date_default_timezone_set('UTC');

const ICON_DIR   = __DIR__ . '/uploads/icons';
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

// Store categories — offered in the admin panel even before any item uses them.
const CATEGORIES = [
    'games' => ['action', 'adventure', 'arcade', 'casual', 'puzzle', 'racing', 'role-playing', 'simulation', 'strategy',
                'sports', 'board', 'card', 'casino', 'family', 'music', 'trivia', 'word'],
    'apps'  => ['communication', 'entertainment', 'graphics-design', 'health-fitness', 'photo-video', 'productivity',
                'utilities', 'education', 'music'],
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
    }
    return $pdo;
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

/** All items, newest first. $full adds descriptions, versions and screenshots (admin panel). */
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
        'license_type' => $license,
        'latest_version' => $it['latest_version'],
        'latest_size_mb' => $latest['size_mb'] ?? 0,
        'latest_release_date' => $latest['release_date'] ?: null,
        'seo_title' => $it['seo']['title'],
        'seo_description' => $it['seo']['meta_description'],
        'status' => $it['status'],
        'updated_at' => sql_date($it['updated_at']),
    ];
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
    return $id;
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
    $item['status'] = in_array($in['status'] ?? '', STATUSES, true) ? $in['status'] : 'published';

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
    if ($type !== '' && ($i['type'] ?? '') !== $type) return false;
    if ($cat !== '' && ($i['category'] ?? '') !== $cat) return false;
    if ($q === '') return true;
    $hay = implode(' ', [$i['name'] ?? '', $i['developer'] ?? '', $i['category'] ?? '', $i['short_description'] ?? '', ...(array)($i['tags'] ?? [])]);
    return str_contains(mb_strtolower($hay), $q);
}

try {
    switch ($_GET['action'] ?? '') {
        case 'list':
            $all = is_admin() && !empty($_GET['all']);
            // No page param: full list (admin panel).
            if (!isset($_GET['page'])) respond(['items' => fetch_items($all, true), 'categories' => CATEGORIES]);
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
                if ($type === '' || ($i['type'] ?? 'game') === $type) $counts[$i['category']] = ($counts[$i['category']] ?? 0) + 1;
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
            ]);

        case 'get':
            $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
            $slug = isset($_GET['slug']) ? (string)$_GET['slug'] : null;
            $item = find_item($id, $slug);
            if (!$item || (!is_published($item) && !is_admin())) throw new ApiError('Not found', 404);
            respond(['item' => $item]);

        case 'me':
            respond(['admin' => is_admin()]);

        case 'login':
            require_post();
            $b = body();
            if (ADMIN_PASS === '') throw new ApiError('Admin login not configured (create config.php)', 500);
            $okUser = hash_equals(ADMIN_USER, (string)($b['username'] ?? ''));
            $okPass = hash_equals(ADMIN_PASS, (string)($b['password'] ?? ''));
            if (!$okUser || !$okPass) throw new ApiError('Wrong username or password', 401);
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
            respond(['item' => find_item($id, null)]);

        case 'delete':
            require_post();
            require_admin();
            $id = (int)(body()['id'] ?? 0);
            $removed = find_item($id, null) ?? throw new ApiError('Not found', 404);
            // Versions and screenshots go with it (ON DELETE CASCADE).
            db()->prepare('DELETE FROM games WHERE id=?')->execute([$id]);
            // Remove the uploaded icon if nothing else uses it.
            $icon = ltrim(str_replace(site_url() . '/', '', (string)$removed['icon']), '/');
            if (preg_match('~^uploads/icons/[\w-]+\.(png|jpe?g|webp|gif)$~', $icon)) {
                $st = db()->prepare('SELECT COUNT(*) FROM games WHERE icon LIKE ?');
                $st->execute(['%' . $icon]);
                if (!$st->fetchColumn()) @unlink(__DIR__ . '/' . $icon);
            }
            respond(['ok' => true]);

        case 'upload_icon':
            require_post();
            require_admin();
            $f = $_FILES['icon'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK) throw new ApiError('Upload failed', 400);
            if ($f['size'] > 2 * 1024 * 1024) throw new ApiError('Icon must be under 2 MB', 422);
            $info = @getimagesize($f['tmp_name']);
            $exts = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
            $ext = $exts[$info[2] ?? 0] ?? null;
            if (!$ext) throw new ApiError('Icon must be PNG, JPG, WEBP or GIF', 422);
            if (!is_dir(ICON_DIR)) mkdir(ICON_DIR, 0775, true);
            $name = bin2hex(random_bytes(8)) . '.' . $ext;
            if (!move_uploaded_file($f['tmp_name'], ICON_DIR . '/' . $name)) throw new ApiError('Could not save icon', 500);
            respond(['path' => site_url() . '/uploads/icons/' . $name]);

        default:
            throw new ApiError('Unknown action', 400);
    }
} catch (ApiError $e) {
    respond(['error' => $e->getMessage()], $e->getCode() ?: 400);
} catch (Throwable $e) {
    error_log('api.php: ' . $e->getMessage());
    respond(['error' => 'Server error'], 500);
}
