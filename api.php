<?php
/**
 * Ali Dry Fruit — single-file backend (Hostinger / any PHP 7.4+ host)
 *
 * STORAGE
 *   Option A (works right away): leave DB_NAME empty. Data is saved in data/store.php
 *   Option B (MySQL): Hostinger → Databases → MySQL → create a database, copy
 *   config.sample.php to config.php and fill in DB_NAME, DB_USER, DB_PASS. Tables are created automatically.
 *
 * ADMIN
 *   Open yourdomain.com/admin9196  —  first password is 1234 (change it in Admin → Settings).
 */

// Database details go in config.php (copy config.sample.php). config.php is not uploaded to GitHub.
if (is_file(__DIR__ . '/config.php')) require __DIR__ . '/config.php';
defined('DB_HOST') || define('DB_HOST', 'localhost');
defined('DB_NAME') || define('DB_NAME', '');          // empty = save data in data/store.php
defined('DB_USER') || define('DB_USER', '');
defined('DB_PASS') || define('DB_PASS', '');
const ADMIN_PASS_DEFAULT = '1234';

const DATA_DIR   = __DIR__ . '/data';
const FILE_STORE = DATA_DIR . '/store.php';
const UPLOAD_DIR = __DIR__ . '/uploads';
const SEED_FILE  = __DIR__ . '/assets/seed.js';
const MAX_UPLOAD = 6 * 1024 * 1024;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header_remove('X-Powered-By');
ini_set('display_errors', '0');

function out($d, $code = 200) {
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function fail($msg, $code = 400) { out(['ok' => false, 'error' => $msg], $code); }
function body() {
    $j = json_decode(file_get_contents('php://input'), true);
    return is_array($j) ? $j : [];
}
function enc($v) { return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
function use_mysql() { return DB_NAME !== ''; }

set_exception_handler(function ($e) {
    error_log('[ali-dry-fruit] ' . $e->getMessage());
    fail('Server error. Please try again.', 500);
});

/* ---------------- storage ---------------- */
function db() {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("CREATE TABLE IF NOT EXISTS adf_kv (k VARCHAR(40) PRIMARY KEY, v LONGTEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS adf_orders (
        id VARCHAR(24) PRIMARY KEY, created_at DATETIME NOT NULL, status VARCHAR(20) NOT NULL,
        total INT NOT NULL, phone VARCHAR(20) NOT NULL DEFAULT '', data LONGTEXT NOT NULL,
        INDEX (created_at), INDEX (status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    return $pdo;
}
function fs_load() {
    $empty = ['kv' => [], 'orders' => []];
    if (!is_file(FILE_STORE)) return $empty;
    $s = file_get_contents(FILE_STORE);
    $s = substr($s, strpos($s, "\n") + 1);
    $d = json_decode($s, true);
    return is_array($d) ? $d + $empty : $empty;
}
$FS = null;
function &fs() { global $FS; if ($FS === null) $FS = fs_load(); return $FS; }
function fs_save(&$d) {
    if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0755, true);
    $tmp = FILE_STORE . '.tmp';
    $ok = file_put_contents($tmp, "<?php http_response_code(404); exit; ?>\n" . enc($d), LOCK_EX);
    if ($ok === false || !rename($tmp, FILE_STORE)) throw new Exception('Could not write data/store.php');
}
/* one writer at a time */
$LOCK = null;
function lock() {
    global $LOCK;
    if (use_mysql()) { db()->query("SELECT GET_LOCK('adf_write', 10)"); return; }
    if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0755, true);
    $LOCK = fopen(DATA_DIR . '/.lock', 'c');
    flock($LOCK, LOCK_EX);
    $GLOBALS['FS'] = fs_load();   // re-read after taking the lock
}
function unlock() {
    global $LOCK;
    if (use_mysql()) { db()->query("SELECT RELEASE_LOCK('adf_write')"); return; }
    if ($LOCK) { flock($LOCK, LOCK_UN); fclose($LOCK); $LOCK = null; }
}
function kv_get($k) {
    if (use_mysql()) {
        $s = db()->prepare('SELECT v FROM adf_kv WHERE k = ?'); $s->execute([$k]);
        $v = $s->fetchColumn();
        return $v === false ? null : json_decode($v, true);
    }
    return fs()['kv'][$k] ?? null;
}
function kv_set($k, $v) {
    if (use_mysql()) { db()->prepare('REPLACE INTO adf_kv (k, v) VALUES (?, ?)')->execute([$k, enc($v)]); return; }
    $d = &fs(); $d['kv'][$k] = $v; fs_save($d);
}
function orders_all() {
    if (use_mysql()) {
        $rows = db()->query('SELECT data FROM adf_orders ORDER BY created_at DESC')->fetchAll();
        return array_map(function ($r) { return json_decode($r['data'], true); }, $rows);
    }
    $o = array_values(fs()['orders']);
    usort($o, function ($a, $b) { return strcmp($b['at'], $a['at']); });
    return $o;
}
function order_get($id) {
    if (use_mysql()) {
        $s = db()->prepare('SELECT data FROM adf_orders WHERE id = ?'); $s->execute([$id]);
        $v = $s->fetchColumn(); return $v === false ? null : json_decode($v, true);
    }
    return fs()['orders'][$id] ?? null;
}
function order_put($o) {
    if (use_mysql()) {
        db()->prepare('REPLACE INTO adf_orders (id, created_at, status, total, phone, data) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$o['id'], date('Y-m-d H:i:s', strtotime($o['at'])), $o['status'], (int)$o['total'], $o['customer']['phone'] ?? '', enc($o)]);
        return;
    }
    $d = &fs(); $d['orders'][$o['id']] = $o; fs_save($d);
}
function orders_del($ids) {
    if (use_mysql()) {
        $s = db()->prepare('DELETE FROM adf_orders WHERE id = ?');
        foreach ($ids as $id) $s->execute([$id]);
        return;
    }
    $d = &fs(); foreach ($ids as $id) unset($d['orders'][$id]); fs_save($d);
}

/* replace the demo phone / WhatsApp / address with the real ones (only while the demo value is still set) */
function patch_contact() {
    $st = kv_get('settings');
    if (!is_array($st)) return;
    $demo = ['phone' => '+92 300 0000000', 'whatsapp' => '923000000000', 'address' => 'Lahore, Pakistan'];
    $real = ['phone' => '+92 300 4198090', 'whatsapp' => '923004198090', 'address' => 'Roundabout, Adda Plot, Lahore'];
    $ch = false;
    foreach ($demo as $k => $v) if (empty($st[$k]) || $st[$k] === $v) { $st[$k] = $real[$k]; $ch = true; }
    if (($st['email'] ?? '') === 'orders@alidryfruit.pk') { $st['email'] = ''; $ch = true; }
    if (!isset($st['codAdvance']) || (empty($st['adv30']) && (float)$st['codAdvance'] === 20.0)) { $st['codAdvance'] = 30; $st['adv30'] = 1; $ch = true; }
    elseif (empty($st['adv30'])) { $st['adv30'] = 1; $ch = true; }
    $realPay = ['easypaisa' => '0300-8572361', 'jazzcash' => '0300-8572361', 'bank' => "MCB Bank, Lake City, Lahore\nTitle: Ali Dry Fruit Khajure\nIBAN: PK72 MUCB 0613 9365 4100 2037"];
    if (!isset($st['pay']) || !is_array($st['pay'])) $st['pay'] = ['cod' => true];
    foreach ($realPay as $k => $v) { $cur = (string)($st['pay'][$k] ?? ''); if (trim($cur) === '' || strpos($cur, 'XX') !== false) { $st['pay'][$k] = $v; $ch = true; } }
    if ($ch) kv_set('settings', $st);
    // one time: remove every old product before the owner's new catalogue goes in
    $st2 = kv_get('settings');
    if (is_array($st2) && empty($st2['wipe14'])) { kv_set('products', []); $st2['wipe14'] = 1; kv_set('settings', $st2); }
    // one time: the owner's new catalogue (81 products, edited photos) from assets/seed.js
    $st3 = kv_get('settings');
    if (is_array($st3) && empty($st3['cat16'])) {
        $s = @file_get_contents(SEED_FILE);
        $seed = $s === false ? null : json_decode(rtrim(substr(trim($s), strpos(trim($s), '=') + 1), "; \n\r\t"), true);
        if (is_array($seed) && is_array($seed['products'] ?? null) && count($seed['products']) > 0) {
            kv_set('products', $seed['products']); $st3['cat16'] = 1; kv_set('settings', $st3);
        }
    }
    // one time: Hussain Chowk branch phone (owner, 14 Nov 2026)
    $st7 = kv_get('settings');
    if (is_array($st7) && empty($st7['ph2v1'])) { if (empty($st7['phone2'])) $st7['phone2'] = '+92 307 9898705'; $st7['ph2v1'] = 1; kv_set('settings', $st7); }
    // one time: shops are open 24 hours (owner, 14 Nov 2026)
    $st6 = kv_get('settings');
    if (is_array($st6) && empty($st6['hrs24b'])) { $st6['hours'] = 'Both shops open 24 hours · 7 days a week'; $st6['hrs24'] = 1; $st6['hrs24b'] = 1; kv_set('settings', $st6); }
    // one time: second shop (Hussain Chowk, Gulberg III)
    $st5 = kv_get('settings');
    if (is_array($st5) && empty($st5['addr2v1'])) {
        if (empty($st5['address2'])) $st5['address2'] = 'Shop No. 3A, 58-B3, Hussain Chowk, Gulberg III, Lahore';
        $st5['addr2v1'] = 1; kv_set('settings', $st5);
    }
    // one time (owner forgot the admin password, 7 Nov 2026): clear it so the next login must set a new one
    $st4 = kv_get('settings');
    if (is_array($st4) && empty($st4['pwreset1'])) {
        kv_set('admin', []); $st4['pwreset1'] = 1; kv_set('settings', $st4);
    }
    // sharp phone hero: text-free photo + headline as real text
    $bs = kv_get('banners');
    if (is_array($bs)) foreach ($bs as $i => $b) if (($b['id'] ?? '') === 'b0' && ($b['fullMobile'] ?? '') === 'assets/img/banner-main-mobile.jpg') {
        $bs[$i]['fullMobile'] = 'assets/img/banner-main-mobile-bg.jpg';
        $bs[$i]['mText'] = ['img' => 'assets/img/banner-main-mobile-bg.jpg', 'kicker' => 'Pure Goodness From Nature', 't1' => 'Naturally Nutritious,', 't2' => 'Freshly Packed', 'sub' => 'Premium quality dry fruits for a healthier, happier you.'];
        kv_set('banners', $bs);
    }
}

/* first run: copy demo content from assets/seed.js */
function ensure_seed() {
    if (kv_get('products') !== null) { patch_contact(); return; }
    $s = @file_get_contents(SEED_FILE);
    if ($s === false) return;
    $s = trim($s);
    $s = substr($s, strpos($s, '=') + 1);
    $s = rtrim($s, "; \n\r\t");
    $seed = json_decode($s, true);
    if (!is_array($seed)) return;
    lock();
    if (kv_get('products') === null) {
        foreach (['settings', 'banners', 'collections', 'products'] as $k) kv_set($k, $seed[$k] ?? []);
        if (kv_get('seq') === null) kv_set('seq', 1000);
    }
    unlock();
}

/* ---------------- abuse protection ---------------- */
function client_ip() {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $xff = trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))[0]);
    return substr($ip . '|' . $xff, 0, 100);
}
/* count hits in a time window; returns false when the limit is reached (file kept inside the closed data/ folder) */
function rl_hit($bucket, $limit, $window, $count = true) {
    if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0755, true);
    $f = DATA_DIR . '/ratelimit.json';
    $h = @fopen($f, 'c+'); if (!$h) return true;
    flock($h, LOCK_EX);
    $d = json_decode(stream_get_contents($h) ?: '[]', true); if (!is_array($d)) $d = [];
    $now = time();
    foreach ($d as $k => $list) { $d[$k] = array_values(array_filter((array)$list, function ($ts) use ($now) { return $ts > $now - 86400; })); if (!$d[$k]) unset($d[$k]); }
    $key = hash('sha256', $bucket);
    $recent = array_filter($d[$key] ?? [], function ($ts) use ($now, $window) { return $ts > $now - $window; });
    $ok = count($recent) < $limit;
    if ($ok && $count) $d[$key][] = $now;
    ftruncate($h, 0); rewind($h); fwrite($h, json_encode($d)); fflush($h);
    flock($h, LOCK_UN); fclose($h);
    return $ok;
}
function rl_clear($bucket) {
    $f = DATA_DIR . '/ratelimit.json'; $h = @fopen($f, 'c+'); if (!$h) return;
    flock($h, LOCK_EX);
    $d = json_decode(stream_get_contents($h) ?: '[]', true); if (!is_array($d)) $d = [];
    unset($d[hash('sha256', $bucket)]);
    ftruncate($h, 0); rewind($h); fwrite($h, json_encode($d)); fflush($h);
    flock($h, LOCK_UN); fclose($h);
}
/* POSTs must come from this site (blocks cross-site form / script posts) */
function same_origin() {
    $o = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($o === '') return true;
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    return strtolower((string)parse_url($o, PHP_URL_HOST)) === preg_replace('/:\d+$/', '', $host);
}


/* ---------------- website analytics (first-party, no cookies; files in the closed data/ folder) ---------------- */
const AN_DIR = DATA_DIR . '/an';
function an_clean($v, $max = 60) { return mb_substr(preg_replace('/[^\p{L}\p{N} ._\-\/:+|]/u', '', (string)$v), 0, $max); }
function an_update($day, $fn) {
    if (!is_dir(AN_DIR)) @mkdir(AN_DIR, 0755, true);
    $h = @fopen(AN_DIR . '/' . $day . '.json', 'c+'); if (!$h) return;
    flock($h, LOCK_EX);
    $d = json_decode(stream_get_contents($h) ?: '{}', true); if (!is_array($d)) $d = [];
    $d += ['s' => [], 'atc' => [], 'view' => []];
    $fn($d);
    ftruncate($h, 0); rewind($h); fwrite($h, json_encode($d, JSON_UNESCAPED_UNICODE)); fflush($h);
    flock($h, LOCK_UN); fclose($h);
}
function an_track($b) {
    $e = (string)($b['e'] ?? '');
    if (!in_array($e, ['pv', 'eng', 'view', 'atc', 'ic', 'wa'], true)) return;
    $sid = preg_replace('/[^a-z0-9]/i', '', (string)($b['sid'] ?? '')); if (strlen($sid) < 8) return;
    $sid = substr($sid, 0, 24);
    $day = gmdate('Y-m-d', time() + 5 * 3600);   // Pakistan time
    an_update($day, function (&$d) use ($b, $e, $sid) {
        if (!isset($d['s'][$sid])) {
            if (count($d['s']) >= 30000) return;
            $d['s'][$sid] = ['v' => substr(preg_replace('/[^a-z0-9]/i', '', (string)($b['vid'] ?? '')), 0, 24),
                'p' => an_clean($b['p'] ?? 'Direct', 30), 'src' => an_clean($b['src'] ?? '', 60), 'med' => an_clean($b['med'] ?? '', 40),
                'cmp' => an_clean($b['cmp'] ?? '', 80), 'dev' => an_clean($b['dev'] ?? '', 10), 'new' => !empty($b['nv']) ? 1 : 0,
                't' => time(), 'pv' => 0, 'eng' => 0, 'view' => 0, 'atc' => 0, 'ic' => 0, 'wa' => 0, 'o' => 0, 'rev' => 0];
        }
        $s = &$d['s'][$sid];
        $s[$e] = min(999, (int)($s[$e] ?? 0) + 1);
        $pid = an_clean($b['pid'] ?? '', 80);
        if ($pid !== '' && ($e === 'atc' || $e === 'view')) $d[$e][$pid] = (int)($d[$e][$pid] ?? 0) + 1;
    });
}
function an_order($src, $total) {
    $sid = substr(preg_replace('/[^a-z0-9]/i', '', (string)($src['sid'] ?? '')), 0, 24); if (strlen($sid) < 8) return;
    $day = gmdate('Y-m-d', time() + 5 * 3600);
    an_update($day, function (&$d) use ($sid, $total) { if (isset($d['s'][$sid])) { $d['s'][$sid]['o']++; $d['s'][$sid]['rev'] += (int)$total; } });
}
function an_stats($days) {
    $days = max(1, min(120, (int)$days));
    $tot = ['sessions' => 0, 'visitors' => 0, 'newv' => 0, 'bounce' => 0, 'pv' => 0, 'view' => 0, 'atc' => 0, 'ic' => 0, 'wa' => 0, 'orders' => 0, 'rev' => 0,
            'sAtc' => 0, 'sIc' => 0, 'sView' => 0];
    $vis = []; $by = ['p' => [], 'cmp' => [], 'dev' => []]; $daily = []; $atc = []; $view = [];
    $hr = []; for ($h = 0; $h < 24; $h++) $hr[$h] = ['h' => $h, 'sessions' => 0, 'atc' => 0, 'orders' => 0];
    for ($i = $days - 1; $i >= 0; $i--) {
        $day = gmdate('Y-m-d', time() + 5 * 3600 - $i * 86400);
        $f = AN_DIR . '/' . $day . '.json';
        $d = is_file($f) ? (json_decode((string)@file_get_contents($f), true) ?: []) : [];
        $row = ['d' => $day, 'sessions' => 0, 'atc' => 0, 'ic' => 0, 'orders' => 0];
        foreach (($d['s'] ?? []) as $s) {
            if ($days === 1) { $hh = (int)gmdate('G', (int)$s['t'] + 5 * 3600); $hr[$hh]['sessions']++; $hr[$hh]['atc'] += (int)$s['atc']; $hr[$hh]['orders'] += (int)$s['o']; }
            $bounce = (int)$s['eng'] === 0 && (int)$s['view'] === 0 && (int)$s['atc'] === 0 && (int)$s['ic'] === 0 && (int)$s['wa'] === 0 && (int)$s['o'] === 0 && (int)$s['pv'] <= 1;
            $tot['sessions']++; $row['sessions']++;
            if ($bounce) $tot['bounce']++;
            if (!empty($s['v'])) $vis[$s['v']] = 1;
            if (!empty($s['new'])) $tot['newv']++;
            foreach (['pv', 'view', 'atc', 'ic', 'wa'] as $k) $tot[$k] += (int)$s[$k];
            $tot['orders'] += (int)$s['o']; $tot['rev'] += (int)$s['rev'];
            if ($s['atc']) $tot['sAtc']++; if ($s['ic']) $tot['sIc']++; if ($s['view']) $tot['sView']++;
            $row['atc'] += (int)$s['atc']; $row['ic'] += (int)$s['ic']; $row['orders'] += (int)$s['o'];
            $keys = ['p' => $s['p'] ?: 'Direct', 'dev' => $s['dev'] ?: 'Other',
                     'cmp' => ($s['src'] !== '' || $s['cmp'] !== '') ? ($s['src'] ?: '-') . ' / ' . ($s['med'] ?: '-') . ' / ' . ($s['cmp'] ?: '-') : null];
            foreach ($keys as $g => $k) {
                if ($k === null) continue;
                $r = &$by[$g][$k];
                if (!$r) $r = ['k' => $k, 'sessions' => 0, 'bounce' => 0, 'atc' => 0, 'ic' => 0, 'orders' => 0, 'rev' => 0];
                $r['sessions']++; if ($bounce) $r['bounce']++;
                $r['atc'] += (int)$s['atc']; $r['ic'] += (int)$s['ic']; $r['orders'] += (int)$s['o']; $r['rev'] += (int)$s['rev'];
                unset($r);
            }
        }
        foreach (($d['atc'] ?? []) as $k => $n) $atc[$k] = ($atc[$k] ?? 0) + $n;
        foreach (($d['view'] ?? []) as $k => $n) $view[$k] = ($view[$k] ?? 0) + $n;
        $daily[] = $row;
    }
    $tot['visitors'] = count($vis);
    foreach ($by as $g => $list) { $l = array_values($list); usort($l, function ($a, $b) { return $b['sessions'] - $a['sessions']; }); $by[$g] = array_slice($l, 0, 30); }
    arsort($atc); arsort($view);
    return ['days' => $days, 'hourly' => $days === 1 ? array_values($hr) : null, 'tot' => $tot, 'by' => $by, 'daily' => $daily, 'atc' => array_slice($atc, 0, 15, true), 'view' => array_slice($view, 0, 15, true)];
}

/* ---------------- admin session ---------------- */
function start_session() {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('adf_admin');
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict', 'secure' => $https]);
    session_start();
}
const ADMIN_IDLE = 8 * 3600;   // log out after 8 hours without activity
function is_admin() {
    start_session();
    if (empty($_SESSION['adf_admin'])) return false;
    if (time() - (int)($_SESSION['t'] ?? 0) > ADMIN_IDLE) { $_SESSION = []; session_destroy(); return false; }
    $_SESSION['t'] = time();
    return true;
}
function need_admin($a = '') {
    if (!is_admin()) fail('Please log in again.', 401);
    if (!empty($_SESSION['must_change']) && $a !== 'password') fail('Please set a new admin password first.', 403);
}
function check_password($pw) {
    $a = kv_get('admin');
    if ($a && !empty($a['hash'])) return password_verify($pw, $a['hash']);
    return hash_equals(ADMIN_PASS_DEFAULT, (string)$pw);
}

/* ---------------- helpers ---------------- */
function pay_methods($s) {
    $p = $s['pay'] ?? []; $o = [];
    if (!empty($p['cod'])) $o[] = 'cod';
    foreach (['easypaisa', 'jazzcash', 'bank'] as $k) if (!empty($p[$k]) && trim($p[$k]) !== '') $o[] = $k;
    return $o;
}
function advance_of($s, $pay, $total) {
    if ($pay !== 'cod') return $total;
    $pct = max(0, min(100, (float)($s['codAdvance'] ?? 30)));
    return (int)round($total * $pct / 100);
}
const PROOF_DIR = DATA_DIR . '/proofs';   // inside data/, which is closed to the public
function proof_file($id) { return PROOF_DIR . '/' . preg_replace('/[^A-Za-z0-9-]/', '', $id) . '.img'; }
function str($v, $max) { return mb_substr(trim((string)$v), 0, $max); }
function public_settings($s) { unset($s['admin']); return $s; }

/* ---------------- routes ---------------- */
$a = $_GET['a'] ?? '';
$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';

if ($a === 'ping') {
    try {
        if (use_mysql()) { db()->query('SELECT 1'); out(['ok' => true, 'mode' => 'mysql']); }
        if (!is_dir(DATA_DIR) && !@mkdir(DATA_DIR, 0755, true)) fail('The data folder could not be created. Create a folder named "data" next to api.php.');
        if (!is_writable(DATA_DIR)) fail('The data folder is not writable. Set its permission to 755.');
        out(['ok' => true, 'mode' => 'file']);
    } catch (Throwable $e) {
        fail('Database connection failed. Check DB_NAME, DB_USER and DB_PASS in api.php.');
    }
}

if ($a === 'store') {
    ensure_seed();
    $products = array_values(array_filter(kv_get('products') ?? [], function ($p) { return ($p['status'] ?? '') === 'active'; }));
    $cols = array_values(array_filter(kv_get('collections') ?? [], function ($c) { return ($c['visible'] ?? true) !== false; }));
    $banners = array_values(array_filter(kv_get('banners') ?? [], function ($b) { return ($b['active'] ?? true) !== false; }));
    out(['ok' => true, 'data' => ['settings' => public_settings(kv_get('settings') ?? []), 'banners' => $banners, 'collections' => $cols, 'products' => $products]]);
}

if ($isPost && !same_origin()) fail('Not allowed', 403);

if ($a === 't') {   // analytics beacon
    if (!$isPost) fail('POST only', 405);
    if (rl_hit('t|' . client_ip(), 400, 600)) { $tb = json_decode(file_get_contents('php://input'), true); if (is_array($tb)) an_track($tb); }
    out(['ok' => true]);
}

if ($a === 'order') {
    if (!$isPost) fail('POST only', 405);
    if (!rl_hit('order|' . client_ip(), 6, 600) || !rl_hit('order-all', 120, 3600)) fail('Too many orders from this connection. Please wait a few minutes or order on WhatsApp.', 429);
    ensure_seed();
    $b = body(); $c = $b['customer'] ?? [];
    $name = str($c['name'] ?? '', 80);
    $phone = preg_replace('/[\s-]/', '', (string)($c['phone'] ?? ''));
    $city = str($c['city'] ?? '', 60);
    $addr = str($c['addr'] ?? '', 300);
    $notes = str($c['notes'] ?? '', 500);
    if (mb_strlen($name) < 3) fail('Please enter your full name.');
    if (!preg_match('/^(\+?92|0)3\d{9}$/', $phone)) fail('Enter a valid mobile number, for example 0300 1234567.');
    if (!rl_hit('order-phone|' . substr($phone, -10), 5, 3600)) fail('Too many orders for this number. Please wait or message us on WhatsApp.', 429);
    if (mb_strlen($city) < 2) fail('Please enter your city.');
    if (mb_strlen($addr) < 8) fail('Please enter your full address so the rider can find you.');
    $items = is_array($b['items'] ?? null) ? array_slice($b['items'], 0, 50) : [];
    if (!$items) fail('Your cart is empty.');
    $proof = null;
    if (!empty($b['proof'])) {
        $proof = base64_decode(preg_replace('/^data:[^,]*,/', '', (string)$b['proof']), true);
        if (!$proof || strlen($proof) > 3 * 1024 * 1024) fail('The screenshot is too large. Please try another one.');
        $info = @getimagesizefromstring($proof);
        if (!in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)) fail('The payment screenshot must be a JPG, PNG or WEBP image.');
    }

    lock();
    try {
        $settings = kv_get('settings') ?? [];
        $pay = (string)($b['pay'] ?? '');
        if (!in_array($pay, pay_methods($settings), true)) fail('Please choose a payment method.');
        $products = kv_get('products') ?? [];
        $index = [];
        foreach ($products as $i => $p) $index[$p['id']] = $i;
        $lines = []; $sub = 0;
        foreach ($items as $it) {
            $pid = (string)($it['pid'] ?? ''); $w = (string)($it['w'] ?? '');
            $qty = max(1, min(99, (int)($it['qty'] ?? 1)));
            if (!isset($index[$pid]) || ($products[$index[$pid]]['status'] ?? '') !== 'active')
                fail('One of the items in your cart is no longer available. Please remove it and try again.');
            $p = &$products[$index[$pid]];
            $vi = null;
            foreach ($p['variants'] as $k => $v) if ($v['w'] === $w) $vi = $k;
            if ($vi === null) fail('One of the items in your cart is no longer available. Please remove it and try again.');
            $v = &$p['variants'][$vi];
            $hasStock = isset($v['stock']) && $v['stock'] !== '' && $v['stock'] !== null;
            if ($hasStock && (int)$v['stock'] < $qty)
                fail((int)$v['stock'] > 0 ? "{$p['name']} ({$w}) has only {$v['stock']} left." : "{$p['name']} ({$w}) is sold out.");
            if ($hasStock) $v['stock'] = (int)$v['stock'] - $qty;
            $price = (int)$v['price'];
            $lines[] = ['pid' => $pid, 'name' => $p['name'], 'w' => $w, 'price' => $price, 'qty' => $qty, 'image' => $p['images'][0] ?? ''];
            $sub += $price * $qty;
            unset($v, $p);
        }
        $fee = (int)($settings['deliveryFee'] ?? 0); $free = (int)($settings['freeOver'] ?? 0);
        $ship = ($free && $sub >= $free) ? 0 : $fee;
        $total = $sub + $ship; $advance = advance_of($settings, $pay, $total);
        if ($pay !== 'cod' && $advance > 0 && !$proof) fail('Please upload the payment screenshot.');
        $seq = (int)(kv_get('seq') ?? 1000) + 1;
        $order = ['id' => 'ADF-' . $seq, 'at' => gmdate('c'),
            'customer' => compact('name', 'phone', 'city', 'addr', 'notes'),
            'items' => $lines, 'sub' => $sub, 'ship' => $ship, 'total' => $total,
            'pay' => $pay, 'advance' => $advance, 'proof' => (bool)$proof, 'status' => 'pending', 'paid' => false, 'note' => ''];
        $sb = is_array($b['src'] ?? null) ? $b['src'] : [];
        $order['src'] = ['p' => an_clean($sb['p'] ?? 'Direct', 30), 'src' => an_clean($sb['src'] ?? '', 60), 'med' => an_clean($sb['med'] ?? '', 40),
                         'cmp' => an_clean($sb['cmp'] ?? '', 80), 'dev' => an_clean($sb['dev'] ?? '', 10)];
        if ($proof) {
            if (!is_dir(PROOF_DIR)) @mkdir(PROOF_DIR, 0755, true);
            if (@file_put_contents(proof_file($order['id']), $proof) === false) fail('Could not save the screenshot. Please try again.');
        }
        kv_set('products', $products);
        kv_set('seq', $seq);
        order_put($order);
    } finally { unlock(); }
    try { an_order($b['src'] ?? [], $order['total']); } catch (Throwable $e) {}
    out(['ok' => true, 'order' => $order]);
}

if ($a === 'login') {
    if (!$isPost) fail('POST only', 405);
    ensure_seed();
    $pw = (string)(body()['password'] ?? '');
    $ipKey = 'login|' . client_ip();
    if (!rl_hit($ipKey, 5, 900, false) || !rl_hit('login-all', 30, 900, false)) fail('Too many wrong tries. Wait 15 minutes and try again.', 429);
    start_session();
    if (!check_password($pw)) { rl_hit($ipKey, 5, 900); rl_hit('login-all', 30, 900); usleep(800000); fail('Wrong password. Try again.', 401); }
    rl_clear($ipKey);
    session_regenerate_id(true);
    $_SESSION['adf_admin'] = true; $_SESSION['t'] = time();
    $a0 = kv_get('admin'); $must = !($a0 && !empty($a0['hash']));   // still on the factory password
    if ($must) $_SESSION['must_change'] = true; else unset($_SESSION['must_change']);
    out(['ok' => true, 'mustChange' => $must]);
}
if ($a === 'logout') { start_session(); $_SESSION = []; session_destroy(); out(['ok' => true]); }
if ($a === 'me') { out(['ok' => true, 'authed' => is_admin() && empty($_SESSION['must_change'])]); }

/* ---------- everything below needs the admin session ---------- */
need_admin($a);
if ($a === 'proof') {   // payment screenshot of an order
    $f = proof_file((string)($_GET['id'] ?? ''));
    if (!is_file($f)) fail('Not found', 404);
    $info = @getimagesize($f);
    header('Content-Type: ' . ($info['mime'] ?? 'image/jpeg'));
    header('Cache-Control: private, max-age=86400');
    readfile($f); exit;
}
if (!$isPost && $a !== 'all' && $a !== 'stats') fail('POST only', 405);

if ($a === 'stats') { out(['ok' => true, 'data' => an_stats((int)($_GET['days'] ?? 7))]); }

if ($a === 'all') {
    ensure_seed();
    out(['ok' => true, 'data' => ['settings' => public_settings(kv_get('settings') ?? []), 'banners' => kv_get('banners') ?? [],
        'collections' => kv_get('collections') ?? [], 'products' => kv_get('products') ?? [], 'orders' => orders_all(), 'seq' => kv_get('seq') ?? 1000]]);
}

if ($a === 'save') {
    $b = body(); $k = $b['key'] ?? '';
    if (!in_array($k, ['settings', 'banners', 'collections', 'products'], true)) fail('Unknown section.');
    if (!is_array($b['value'] ?? null)) fail('Nothing to save.');
    lock(); try { kv_set($k, $b['value']); } finally { unlock(); }
    out(['ok' => true]);
}

if ($a === 'order_update') {
    $b = body(); $id = (string)($b['id'] ?? ''); $patch = $b['patch'] ?? [];
    lock();
    try {
        $o = order_get($id);
        if (!$o) fail('Order not found.', 404);
        if (isset($patch['status']) && in_array($patch['status'], ['pending', 'confirmed', 'shipped', 'delivered', 'cancelled'], true)) $o['status'] = $patch['status'];
        if (isset($patch['paid'])) $o['paid'] = (bool)$patch['paid'];
        if (isset($patch['note'])) $o['note'] = str($patch['note'], 1000);
        order_put($o);
    } finally { unlock(); }
    out(['ok' => true, 'order' => $o]);
}

if ($a === 'orders_put') {
    $list = body()['orders'] ?? [];
    if (!is_array($list)) fail('Nothing to save.');
    lock();
    try { foreach ($list as $o) if (!empty($o['id']) && !empty($o['at'])) order_put($o); } finally { unlock(); }
    out(['ok' => true]);
}

if ($a === 'orders_delete') {
    $ids = body()['ids'] ?? [];
    if (!is_array($ids)) fail('Nothing to delete.');
    lock(); try { $ids = array_map('strval', $ids); orders_del($ids); foreach ($ids as $id) @unlink(proof_file($id)); } finally { unlock(); }
    out(['ok' => true]);
}

if ($a === 'password') {
    $b = body();
    if (!rl_hit('pwchange|' . client_ip(), 10, 900)) fail('Too many tries. Wait 15 minutes.', 429);
    if (!check_password((string)($b['current'] ?? ''))) fail('Current password is wrong.');
    $new = (string)($b['new'] ?? '');
    if (strlen($new) < 8) fail('Use at least 8 characters for the new password.');
    if ($new === ADMIN_PASS_DEFAULT || $new === (string)($b['current'] ?? '')) fail('Choose a new password that is different from the old one.');
    lock(); try { kv_set('admin', ['hash' => password_hash($new, PASSWORD_DEFAULT)]); } finally { unlock(); }
    unset($_SESSION['must_change']); session_regenerate_id(true);
    out(['ok' => true]);
}

if ($a === 'upload') {
    $raw = null;
    if (!empty($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) $raw = file_get_contents($_FILES['file']['tmp_name']);
    else {
        $d = (string)(body()['data'] ?? '');
        $d = preg_replace('/^data:[^,]*,/', '', $d);
        $raw = base64_decode($d, true);
    }
    if (!$raw) fail('Upload failed. Try a smaller image.');
    if (strlen($raw) > MAX_UPLOAD) fail('Image is larger than 6 MB.');
    $info = @getimagesizefromstring($raw);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$info['mime'] ?? ''] ?? null;
    if (!$ext) fail('Use a JPG, PNG, WEBP or GIF image.');
    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
    $name = date('Ymd') . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (file_put_contents(UPLOAD_DIR . '/' . $name, $raw) === false) fail('Could not save the image. Check that the uploads folder is writable.');
    out(['ok' => true, 'url' => 'uploads/' . $name]);
}

if ($a === 'import') {
    $b = body(); $d = $b['data'] ?? null;
    if (!is_array($d) || !is_array($d['products'] ?? null)) fail('That file is not a valid backup.');
    lock();
    try {
        foreach (['settings', 'banners', 'collections', 'products'] as $k) if (isset($d[$k]) && is_array($d[$k])) kv_set($k, $d[$k]);
        if (!empty($b['replaceOrders']) && is_array($d['orders'] ?? null)) {
            orders_del(array_map(function ($o) { return $o['id']; }, orders_all()));
            foreach ($d['orders'] as $o) if (!empty($o['id']) && !empty($o['at'])) order_put($o);
            if (!empty($d['seq'])) kv_set('seq', (int)$d['seq']);
        }
    } finally { unlock(); }
    out(['ok' => true]);
}

fail('Unknown action.', 404);
