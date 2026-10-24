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
    if (!isset($st['codAdvance'])) { $st['codAdvance'] = 20; $ch = true; }
    if ($ch) kv_set('settings', $st);
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

/* ---------------- admin session ---------------- */
function start_session() {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('adf_admin');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')]);
    session_start();
}
function is_admin() { start_session(); return !empty($_SESSION['adf_admin']); }
function need_admin() { if (!is_admin()) fail('Please log in again.', 401); }
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
    $pct = max(0, min(100, (float)($s['codAdvance'] ?? 20)));
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

if ($a === 'order') {
    if (!$isPost) fail('POST only', 405);
    ensure_seed();
    $b = body(); $c = $b['customer'] ?? [];
    $name = str($c['name'] ?? '', 80);
    $phone = preg_replace('/[\s-]/', '', (string)($c['phone'] ?? ''));
    $city = str($c['city'] ?? '', 60);
    $addr = str($c['addr'] ?? '', 300);
    $notes = str($c['notes'] ?? '', 500);
    if (mb_strlen($name) < 3) fail('Please enter your full name.');
    if (!preg_match('/^(\+?92|0)3\d{9}$/', $phone)) fail('Enter a valid mobile number, for example 0300 1234567.');
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
        if ($proof) {
            if (!is_dir(PROOF_DIR)) @mkdir(PROOF_DIR, 0755, true);
            if (@file_put_contents(proof_file($order['id']), $proof) === false) fail('Could not save the screenshot. Please try again.');
        }
        kv_set('products', $products);
        kv_set('seq', $seq);
        order_put($order);
    } finally { unlock(); }
    out(['ok' => true, 'order' => $order]);
}

if ($a === 'login') {
    if (!$isPost) fail('POST only', 405);
    ensure_seed();
    $pw = (string)(body()['password'] ?? '');
    start_session();
    if (!check_password($pw)) { usleep(800000); fail('Wrong password. Try again.', 401); }
    session_regenerate_id(true);
    $_SESSION['adf_admin'] = true;
    out(['ok' => true]);
}
if ($a === 'logout') { start_session(); $_SESSION = []; session_destroy(); out(['ok' => true]); }
if ($a === 'me') { out(['ok' => true, 'authed' => is_admin()]); }

/* ---------- everything below needs the admin session ---------- */
need_admin();
if ($a === 'proof') {   // payment screenshot of an order
    $f = proof_file((string)($_GET['id'] ?? ''));
    if (!is_file($f)) fail('Not found', 404);
    $info = @getimagesize($f);
    header('Content-Type: ' . ($info['mime'] ?? 'image/jpeg'));
    header('Cache-Control: private, max-age=86400');
    readfile($f); exit;
}
if (!$isPost && $a !== 'all') fail('POST only', 405);

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
    if (!check_password((string)($b['current'] ?? ''))) fail('Current password is wrong.');
    $new = (string)($b['new'] ?? '');
    if (strlen($new) < 4) fail('Use at least 4 characters for the new password.');
    lock(); try { kv_set('admin', ['hash' => password_hash($new, PASSWORD_DEFAULT)]); } finally { unlock(); }
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
