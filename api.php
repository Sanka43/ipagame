<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

const DATA_FILE  = __DIR__ . '/data/apps.json';
const ICON_DIR   = __DIR__ . '/uploads/icons';
// Admin login lives in config.php (git-ignored). Copy config.example.php to create it.
$config = is_file(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];
define('ADMIN_USER', (string)($config['admin_user'] ?? ''));
define('ADMIN_PASS', (string)($config['admin_pass'] ?? ''));

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

function default_data(): array
{
    return ['meta' => ['version' => 1, 'updated_at' => '', 'total' => 0], 'categories' => ['games' => [], 'apps' => []], 'items' => []];
}

function read_data(): array
{
    if (!is_file(DATA_FILE)) return default_data();
    $fp = fopen(DATA_FILE, 'r');
    flock($fp, LOCK_SH);
    $raw = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $d = json_decode($raw ?: '', true);
    if (!is_array($d)) throw new ApiError('Data file is not valid JSON', 500);
    $d['items'] = $d['items'] ?? [];
    return $d;
}

// Read-modify-write the JSON file under an exclusive lock.
function write_data(callable $fn)
{
    if (!is_dir(dirname(DATA_FILE))) mkdir(dirname(DATA_FILE), 0775, true);
    $fp = fopen(DATA_FILE, 'c+');
    if (!$fp) throw new ApiError('Cannot open data file', 500);
    try {
        flock($fp, LOCK_EX);
        $raw = stream_get_contents($fp);
        $d = trim((string)$raw) === '' ? default_data() : json_decode($raw, true);
        if (!is_array($d)) throw new ApiError('Data file is not valid JSON', 500);
        $d['items'] = $d['items'] ?? [];

        $result = $fn($d);

        $d['items'] = array_values($d['items']);
        $d['meta']['total'] = count($d['items']);
        $d['meta']['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
        $json = json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, $json . "\n");
        fflush($fp);
        return $result;
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
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

function unique_slug(string $base, array $items, ?int $selfId): string
{
    $slug = $base;
    $n = 2;
    $taken = fn($s) => (bool)array_filter($items, fn($i) => ($i['slug'] ?? '') === $s && (int)($i['id'] ?? 0) !== $selfId);
    while ($taken($slug)) $slug = $base . '-' . $n++;
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
    $item['min_ios'] = str_in($in, 'min_ios', 20);
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

    $item['seo'] = [
        'title' => "$name v{$item['latest_version']}",
        'meta_description' => $item['short_description'] !== '' ? $item['short_description'] : $name,
    ];
    $item['status'] = in_array($in['status'] ?? '', ['published', 'draft'], true) ? $in['status'] : 'published';

    $now = gmdate('Y-m-d\TH:i:s\Z');
    $item['created_at'] = $item['created_at'] ?? $now;
    $item['updated_at'] = $now;
    return $item;
}

function find_item(array $items, ?int $id, ?string $slug): ?array
{
    foreach ($items as $i) {
        if (($id !== null && (int)($i['id'] ?? 0) === $id) || ($slug !== null && ($i['slug'] ?? '') === $slug)) return $i;
    }
    return null;
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
            $d = read_data();
            $all = is_admin() && !empty($_GET['all']);
            $items = array_values(array_filter($d['items'], fn($i) => $all || is_published($i)));
            usort($items, fn($a, $b) => strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? ''));
            // No page param: full list (admin panel).
            if (!isset($_GET['page'])) respond(['items' => $items, 'categories' => $d['categories'] ?? new stdClass()]);

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
            $item = find_item(read_data()['items'], $id, $slug);
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
            $item = write_data(function (array &$d) use ($b, $id) {
                if ($id === null) {
                    $new = clean_item($b, null);
                    $nextId = max(array_merge([0], array_map(fn($i) => (int)($i['id'] ?? 0), $d['items']))) + 1;
                    $slug = unique_slug(slugify($new['name']), $d['items'], null);
                    $new = ['id' => $nextId, 'type' => $new['type'], 'slug' => $slug] + $new;
                    $d['items'][] = $new;
                    return $new;
                }
                foreach ($d['items'] as $k => $existing) {
                    if ((int)($existing['id'] ?? 0) !== $id) continue;
                    $u = clean_item($b, $existing);
                    if (empty($u['slug'])) $u['slug'] = unique_slug(slugify($u['name']), $d['items'], $id);
                    $d['items'][$k] = $u;
                    return $u;
                }
                throw new ApiError('Not found', 404);
            });
            respond(['item' => $item]);

        case 'delete':
            require_post();
            require_admin();
            $id = (int)(body()['id'] ?? 0);
            $removed = write_data(function (array &$d) use ($id) {
                foreach ($d['items'] as $k => $i) {
                    if ((int)($i['id'] ?? 0) === $id) {
                        unset($d['items'][$k]);
                        return $i;
                    }
                }
                throw new ApiError('Not found', 404);
            });
            // Remove the uploaded icon if nothing else uses it.
            $icon = ltrim((string)($removed['icon'] ?? ''), '/');
            if (preg_match('~^uploads/icons/[\w-]+\.(png|jpe?g|webp|gif)$~', $icon)) {
                $inUse = array_filter(read_data()['items'], fn($i) => ltrim((string)($i['icon'] ?? ''), '/') === $icon);
                if (!$inUse) @unlink(__DIR__ . '/' . $icon);
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
            respond(['path' => 'uploads/icons/' . $name]);

        default:
            throw new ApiError('Unknown action', 400);
    }
} catch (ApiError $e) {
    respond(['error' => $e->getMessage()], $e->getCode() ?: 400);
} catch (Throwable $e) {
    respond(['error' => 'Server error'], 500);
}
