<?php
/**
 * Ali Dry Fruit — SEO pages (server-rendered head + crawlable content), sitemap.xml
 *   /                      home
 *   /dry-fruits/<id>/      collection page
 *   /product/<id>/         product page
 *   /sitemap.xml           sitemap with images
 * The storefront JavaScript still runs on every page; this file only gives search engines
 * a proper title, description, canonical URL, structured data and readable content.
 */
define('ADF_LIB', 1);
require __DIR__ . '/api.php';
set_exception_handler(function ($e) { error_log('[ali-dry-fruit seo] ' . $e->getMessage()); http_response_code(500); echo 'Server error'; exit; });

const SITE = 'https://alidryfruit.com';
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function abs_url($p) { return preg_match('~^https?://~', $p) ? $p : SITE . '/' . ltrim($p, '/'); }
function rs($n) { return 'Rs ' . number_format((float)$n); }
function jld($o) { return '<script type="application/ld+json">' . json_encode($o, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . '</script>'; }

ensure_seed();
$S = kv_get('settings') ?: [];
$P = array_values(array_filter(kv_get('products') ?: [], function ($p) { return ($p['status'] ?? '') === 'active'; }));
usort($P, function ($a, $b) { return ($a['pos'] ?? 0) <=> ($b['pos'] ?? 0); });
$C = array_values(array_filter(kv_get('collections') ?: [], function ($c) { return ($c['visible'] ?? true) !== false; }));
usort($C, function ($a, $b) { return ($a['pos'] ?? 0) <=> ($b['pos'] ?? 0); });
$byCol = [];
foreach ($P as $p) foreach (($p['cols'] ?? []) as $cid) $byCol[$cid][] = $p;
$C = array_values(array_filter($C, function ($c) use ($byCol) { return !empty($byCol[$c['id']]); }));   // only collections with products
$colById = []; foreach ($C as $c) $colById[$c['id']] = $c;
$prodById = []; foreach ($P as $p) $prodById[$p['id']] = $p;
function prices($p) { $a = array_map(function ($v) { return (float)$v['price']; }, $p['variants'] ?? []); return $a ? [min($a), max($a)] : [0, 0]; }
function kg_price($p) { foreach (($p['variants'] ?? []) as $v) if (strtolower($v['w']) === '1kg') return (float)$v['price']; return prices($p)[1]; }

/* ---------------- sitemap.xml ---------------- */
if (isset($_GET['sitemap'])) {
    header('Content-Type: application/xml; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    $today = gmdate('Y-m-d');
    $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
    $x .= "<url><loc>" . SITE . "/</loc><lastmod>$today</lastmod><changefreq>daily</changefreq><priority>1.0</priority></url>\n";
    foreach ($C as $c) $x .= '<url><loc>' . h(SITE . '/dry-fruits/' . $c['id'] . '/') . "</loc><lastmod>$today</lastmod><changefreq>weekly</changefreq><priority>0.8</priority>" . (!empty($c['image']) ? '<image:image><image:loc>' . h(abs_url($c['image'])) . '</image:loc></image:image>' : '') . "</url>\n";
    foreach ($P as $p) {
        $x .= '<url><loc>' . h(SITE . '/product/' . $p['id'] . '/') . "</loc><lastmod>$today</lastmod><changefreq>weekly</changefreq><priority>0.7</priority>";
        foreach (array_slice($p['images'] ?? [], 0, 3) as $im) $x .= '<image:image><image:loc>' . h(abs_url($im)) . '</image:loc><image:title>' . h($p['name']) . '</image:title></image:image>';
        $x .= "</url>\n";
    }
    echo $x . '</urlset>';
    exit;
}

/* ---------------- which page ---------------- */
$cid = preg_replace('/[^a-z0-9-]/', '', strtolower((string)($_GET['c'] ?? '')));
$pid = preg_replace('/[^a-z0-9-]/', '', strtolower((string)($_GET['p'] ?? '')));
$old = preg_replace('/[^a-z0-9-]/', '', strtolower((string)($_GET['collection'] ?? '')));
if ($cid === '' && $pid === '' && $old !== '' && isset($colById[$old])) {   // old ?collection= links -> clean URL
    header('Location: ' . SITE . '/dry-fruits/' . $old . '/', true, 301); exit;
}
$status = 200;
$store = $S['storeName'] ?? 'Ali Dry Fruit';
$ogImg = abs_url('assets/img/banner-main.jpg');
$crumbs = [['Home', SITE . '/']];
$ld = []; $body = '';

$biz = function () use ($S, $store) {
    $hours = [['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'], 'opens' => '00:00', 'closes' => '23:59']];
    $shops = [];
    if (!empty($S['address'])) $shops[] = ['@type' => 'Store', '@id' => SITE . '/#shop-1', 'name' => $store . ' — Adda Plot', 'image' => abs_url('assets/img/logo.png'), 'url' => SITE . '/',
        'telephone' => $S['phone'] ?? '', 'priceRange' => 'Rs 200 – Rs 18,500', 'currenciesAccepted' => 'PKR', 'paymentAccepted' => 'Cash, Easypaisa, JazzCash, Bank Transfer',
        'address' => ['@type' => 'PostalAddress', 'streetAddress' => 'Roundabout, Adda Plot', 'addressLocality' => 'Lahore', 'addressRegion' => 'Punjab', 'addressCountry' => 'PK'], 'openingHoursSpecification' => $hours];
    if (!empty($S['address2'])) $shops[] = ['@type' => 'Store', '@id' => SITE . '/#shop-2', 'name' => $store . ' — Hussain Chowk, Gulberg', 'image' => abs_url('assets/img/logo.png'), 'url' => SITE . '/',
        'telephone' => $S['phone2'] ?? ($S['phone'] ?? ''), 'priceRange' => 'Rs 200 – Rs 18,500', 'currenciesAccepted' => 'PKR', 'paymentAccepted' => 'Cash, Easypaisa, JazzCash, Bank Transfer',
        'address' => ['@type' => 'PostalAddress', 'streetAddress' => 'Shop No. 3A, 58-B3, Hussain Chowk, Block B3, Gulberg III', 'addressLocality' => 'Lahore', 'addressRegion' => 'Punjab', 'postalCode' => '54660', 'addressCountry' => 'PK'],
        'geo' => ['@type' => 'GeoCoordinates', 'latitude' => 31.5065869, 'longitude' => 74.3499028], 'hasMap' => 'https://www.google.com/maps/search/?api=1&query=Ali+dry+fruits+Hussain+Chowk+Gulberg+Lahore', 'openingHoursSpecification' => $hours];
    $same = array_values(array_filter([$S['facebook'] ?? '', $S['instagram'] ?? ''], function ($u) { return $u && !preg_match('~^https?://(www\.)?(facebook|instagram)\.com/?$~i', $u); }));
    $org = ['@type' => 'Organization', '@id' => SITE . '/#org', 'name' => $store, 'url' => SITE . '/', 'logo' => abs_url('assets/img/logo.png'),
        'contactPoint' => [['@type' => 'ContactPoint', 'telephone' => $S['phone'] ?? '', 'contactType' => 'customer service', 'areaServed' => 'PK', 'availableLanguage' => ['en', 'ur']]]];
    if ($same) $org['sameAs'] = $same;
    if ($shops) $org['subOrganization'] = array_map(function ($s) { return ['@id' => $s['@id']]; }, $shops);
    return array_merge([$org], $shops);
};
$links = function ($list, $max = 200) {
    $o = '<ul class="seo-links">';
    foreach (array_slice($list, 0, $max) as $p) $o .= '<li><a href="/product/' . h($p['id']) . '/">' . h($p['name']) . '</a> <span>' . h(rs(kg_price($p))) . ' / kg</span></li>';
    return $o . '</ul>';
};
$colLinks = function () use ($C, $byCol) {
    $o = '<ul class="seo-cols">';
    foreach ($C as $c) $o .= '<li><a href="/dry-fruits/' . h($c['id']) . '/">' . h($c['name']) . '</a> <span>(' . count($byCol[$c['id']]) . ')</span></li>';
    return $o . '</ul>';
};

if ($pid !== '') {
    if (!isset($prodById[$pid])) { $status = 404; $pid = ''; }
}
if ($cid !== '') {
    if (!isset($colById[$cid])) { $status = 404; $cid = ''; }
}

if ($pid !== '') {
    $p = $prodById[$pid]; [$lo, $hi] = prices($p);
    $c = null; foreach (($p['cols'] ?? []) as $x) if (isset($colById[$x])) { $c = $colById[$x]; break; }
    if ($c) $crumbs[] = [$c['name'], SITE . '/dry-fruits/' . $c['id'] . '/'];
    $crumbs[] = [$p['name'], SITE . '/product/' . $p['id'] . '/'];
    $canon = SITE . '/product/' . $p['id'] . '/';
    $title = $p['name'] . ' Price in Pakistan – ' . rs(kg_price($p)) . '/kg | ' . $store;
    $wts = implode(', ', array_map(function ($v) { return $v['w'] . ' ' . rs($v['price']); }, $p['variants'] ?? []));
    $desc = 'Buy ' . $p['name'] . ' online from ' . $store . ', Lahore. ' . $wts . '. Hand-sorted, freshly packed, delivery all over Pakistan, Cash on Delivery.';
    if (!empty($p['images'][0])) $ogImg = abs_url($p['images'][0]);
    $inStock = false; foreach (($p['variants'] ?? []) as $v) if ($v['stock'] === null || $v['stock'] === '' || (int)$v['stock'] > 0) $inStock = true;
    $ld[] = ['@type' => 'Product', '@id' => $canon . '#product', 'name' => $p['name'], 'sku' => $p['id'], 'url' => $canon,
        'image' => array_map('abs_url', $p['images'] ?? []), 'description' => $p['desc'] ?: $desc, 'brand' => ['@type' => 'Brand', 'name' => $store],
        'category' => $c ? $c['name'] : 'Dry Fruits',
        'offers' => ['@type' => 'AggregateOffer', 'priceCurrency' => 'PKR', 'lowPrice' => $lo, 'highPrice' => $hi, 'offerCount' => count($p['variants'] ?? []),
            'availability' => $inStock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock', 'url' => $canon, 'seller' => ['@id' => SITE . '/#org']]];
    $rows = ''; foreach (($p['variants'] ?? []) as $v) $rows .= '<tr><td>' . h($v['w']) . '</td><td>' . h(rs($v['price'])) . '</td></tr>';
    $more = $c ? array_values(array_filter($byCol[$c['id']], function ($x) use ($p) { return $x['id'] !== $p['id']; })) : [];
    $body = '<article class="wrap seo-block"><h1>' . h($p['name']) . '</h1><p>' . h($p['desc'] ?? '') . ' Packed at our shops in Lahore and delivered all over Pakistan. Cash on Delivery available.</p>'
        . '<table class="seo-price"><caption>' . h($p['name']) . ' price in Pakistan</caption><thead><tr><th>Weight</th><th>Price</th></tr></thead><tbody>' . $rows . '</tbody></table>'
        . ($c ? '<p>More in <a href="/dry-fruits/' . h($c['id']) . '/">' . h($c['name']) . '</a>:</p>' . $links($more, 12) : '') . '</article>';
} elseif ($cid !== '') {
    $c = $colById[$cid]; $list = $byCol[$cid];
    $crumbs[] = [$c['name'], SITE . '/dry-fruits/' . $c['id'] . '/'];
    $canon = SITE . '/dry-fruits/' . $c['id'] . '/';
    $mins = array_map('kg_price', $list); $from = $mins ? min($mins) : 0;
    $title = $c['name'] . ' Price in Pakistan – Buy Online in Lahore | ' . $store;
    $desc = 'Buy ' . $c['name'] . ' online: ' . count($list) . ' varieties' . ($from ? ' from ' . rs($from) . '/kg' : '') . '. ' . implode(', ', array_slice(array_map(function ($p) { return $p['name']; }, $list), 0, 4)) . '. Delivery all over Pakistan, Cash on Delivery.';
    if (!empty($c['image'])) $ogImg = abs_url($c['image']);
    $ld[] = ['@type' => 'CollectionPage', '@id' => $canon, 'url' => $canon, 'name' => $c['name'] . ' | ' . $store, 'description' => $desc,
        'mainEntity' => ['@type' => 'ItemList', 'numberOfItems' => count($list), 'itemListElement' => array_map(function ($p, $i) {
            return ['@type' => 'ListItem', 'position' => $i + 1, 'url' => SITE . '/product/' . $p['id'] . '/', 'name' => $p['name']]; }, $list, array_keys($list))]];
    $body = '<section class="wrap seo-block"><h2>' . h($c['name']) . ' price list in Pakistan</h2><p>Fresh, hand-sorted ' . h(strtolower($c['name'])) . ' from ' . h($store) . ', Lahore. Choose 250g, 500g or 1kg and get it delivered anywhere in Pakistan.</p>'
        . $links($list) . '<h3>More categories</h3>' . $colLinks() . '</section>';
} else {
    $canon = SITE . '/';
    $title = $store . ' | Buy Premium Dry Fruits, Dates & Nuts Online in Lahore, Pakistan';
    $desc = 'Buy fresh dry fruits online in Pakistan: Ajwa, Mabroom & Kalmi khajoor, badam, kaju, pista, akhrot, kishmish, dried fruits and seeds. Two shops in Lahore, open 24 hours. Cash on Delivery all over Pakistan.';
    $ld = array_merge($ld, $biz());
    $ld[] = ['@type' => 'WebSite', '@id' => SITE . '/#website', 'url' => SITE . '/', 'name' => $store, 'publisher' => ['@id' => SITE . '/#org'], 'inLanguage' => 'en-PK'];
    $shopsTxt = [];
    if (!empty($S['address'])) $shopsTxt[] = h($S['address']);
    if (!empty($S['address2'])) $shopsTxt[] = h($S['address2']);
    $body = '<section class="wrap seo-block"><h1>' . h($store) . ' – Premium Dry Fruits, Dates &amp; Nuts in Lahore</h1>'
        . '<p>' . h($store) . ' sells hand-sorted dry fruits, nuts, dates (khajoor) and dried fruit, packed fresh at our shops in Lahore' . ($shopsTxt ? ' (' . implode(' and ', $shopsTxt) . ')' : '') . ' and delivered all over Pakistan with Cash on Delivery. Both shops are open 24 hours.</p>'
        . '<h2>Shop by category</h2>' . $colLinks() . '</section>';
}
if ($status === 404) { $title = 'Page not found | ' . $store; $desc = 'This page is not available. Browse premium dry fruits, dates and nuts at ' . $store . '.'; }
if (count($crumbs) > 1) $ld[] = ['@type' => 'BreadcrumbList', 'itemListElement' => array_map(function ($c, $i) { return ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $c[0], 'item' => $c[1]]; }, $crumbs, array_keys($crumbs))];

/* ---------------- render index.html with SEO head + content ---------------- */
$html = file_get_contents(__DIR__ . '/index.html');
$desc = mb_substr($desc, 0, 300);
$head = '<base href="/">' . "\n";
$html = preg_replace('~<meta charset="utf-8">~', '<meta charset="utf-8">' . "\n" . $head, $html, 1);
$html = preg_replace('~<title>.*?</title>~s', '<title>' . h($title) . '</title>', $html, 1);
$tags = '<meta name="description" content="' . h($desc) . '">' . "\n"
    . ($status === 404 ? '<meta name="robots" content="noindex, follow">' : '<meta name="robots" content="index, follow, max-image-preview:large">') . "\n"
    . ($status === 404 ? '' : '<link rel="canonical" href="' . h($canon) . '">' . "\n")
    . '<meta property="og:type" content="' . ($pid ? 'product' : 'website') . '"><meta property="og:site_name" content="' . h($store) . '"><meta property="og:locale" content="en_PK">' . "\n"
    . '<meta property="og:title" content="' . h($title) . '"><meta property="og:description" content="' . h($desc) . '">' . "\n"
    . ($status === 404 ? '' : '<meta property="og:url" content="' . h($canon) . '">') . '<meta property="og:image" content="' . h($ogImg) . '">' . "\n"
    . '<meta name="twitter:card" content="summary_large_image"><meta name="twitter:title" content="' . h($title) . '"><meta name="twitter:description" content="' . h($desc) . '"><meta name="twitter:image" content="' . h($ogImg) . '">' . "\n"
    . '<meta name="geo.region" content="PK-PB"><meta name="geo.placename" content="Lahore">' . "\n"
    . ($ld ? jld(['@context' => 'https://schema.org', '@graph' => $ld]) . "\n" : '');
$html = preg_replace('~<meta name="description" content="[^"]*">~', $tags, $html, 1);
$html = str_replace('<footer>', '<div id="seoContent">' . $body . '</div>' . "\n<footer>", $html);

http_response_code($status);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Content-Type-Options: nosniff');
echo $html;
