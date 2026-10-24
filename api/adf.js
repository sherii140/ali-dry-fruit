/**
 * Ali Dry Fruit — Vercel backend (same API as api.php)
 *
 * Runs as a Vercel Function. vercel.json sends /api.php here, so the store and admin
 * work the same on Vercel and on Hostinger.
 *
 * SETUP ON VERCEL (one time):
 *   Project → Storage → Create Database → Upstash for Redis (free) → Connect to this project
 *   → Deployments → Redeploy.  That adds KV_REST_API_URL and KV_REST_API_TOKEN automatically.
 *
 * Data lives in Redis. Uploaded images are stored in Redis too and served from
 * /api.php?a=img&id=… with long browser/CDN caching.
 * Admin password starts as 1234 — change it in Admin → Settings.
 */
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');

const ADMIN_PASS_DEFAULT = '1234';
const MAX_IMAGE = 6 * 1024 * 1024;
const MAX_PROOF = 3 * 1024 * 1024;
const P = 'adf:';
const SECTIONS = ['settings', 'banners', 'collections', 'products'];
const STATUSES = ['pending', 'confirmed', 'shipped', 'delivered', 'cancelled'];

/* ---------------- Redis (Upstash REST) ---------------- */
const R_URL = (process.env.KV_REST_API_URL || process.env.UPSTASH_REDIS_REST_URL || '').replace(/\/$/, '');
const R_TOKEN = process.env.KV_REST_API_TOKEN || process.env.UPSTASH_REDIS_REST_TOKEN || '';

class Fail extends Error { constructor(msg, code) { super(msg); this.code = code || 400; } }

async function redis(path, body) {
  const r = await fetch(R_URL + path, {
    method: 'POST',
    headers: { Authorization: 'Bearer ' + R_TOKEN, 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
  const j = await r.json().catch(() => null);
  if (!r.ok || !j) throw new Error('Redis error ' + r.status + ' ' + JSON.stringify(j));
  return j;
}
async function cmd(...args) {
  const j = await redis('', args);
  if (j.error) throw new Error('Redis: ' + j.error);
  return j.result;
}
async function multi(cmds) {               // atomic MULTI/EXEC
  if (!cmds.length) return [];
  const j = await redis('/multi-exec', cmds);
  if (!Array.isArray(j)) throw new Error('Redis transaction failed');
  j.forEach(x => { if (x && x.error) throw new Error('Redis: ' + x.error); });
  return j.map(x => x.result);
}
const parse = v => { if (v === null || v === undefined) return null; try { return JSON.parse(v); } catch (e) { return null; } };

async function getKV(keys) {
  const vals = await cmd('MGET', ...keys.map(k => P + k));
  const o = {}; keys.forEach((k, i) => { o[k] = parse(vals[i]); }); return o;
}
async function allOrders() {
  const flat = await cmd('HGETALL', P + 'orders') || [];
  const out = [];
  if (Array.isArray(flat)) { for (let i = 1; i < flat.length; i += 2) { const o = parse(flat[i]); if (o) out.push(o); } }
  else Object.values(flat).forEach(v => { const o = parse(v); if (o) out.push(o); });
  return out.sort((a, b) => String(b.at).localeCompare(String(a.at)));
}

/* one writer at a time (orders and admin saves) */
async function withLock(fn) {
  const token = crypto.randomBytes(8).toString('hex');
  for (let i = 0; i < 50; i++) {
    if (await cmd('SET', P + 'lock', token, 'NX', 'PX', 8000) === 'OK') {
      try { return await fn(); }
      finally { if (await cmd('GET', P + 'lock') === token) await cmd('DEL', P + 'lock'); }
    }
    await new Promise(r => setTimeout(r, 120));
  }
  throw new Fail('The store is busy. Please try again in a moment.', 503);
}

/* first run: copy the demo content from assets/seed.js */
let seeded = false;
/* one-time updates to existing stores when the built-in banner changes (only if the owner never moved its button) */
function readSeed() {
  let s = fs.readFileSync(path.join(__dirname, '..', 'assets', 'seed.js'), 'utf8').trim();
  return JSON.parse(s.slice(s.indexOf('=') + 1).replace(/;\s*$/, ''));
}
/* v6: the 11 homepage collections (Dates, Almonds, …) with their photos; older collections hidden */
async function patchV6() {
  const seed = readSeed();
  await withLock(async () => {
    const cols = parse(await cmd('GET', P + 'collections')) || [], prods = parse(await cmd('GET', P + 'products')) || [];
    seed.collections.forEach(sc => {
      const c = cols.find(x => x.id === sc.id);
      const f = { name: sc.name, parent: sc.parent, home: sc.home, visible: sc.visible, pos: sc.pos, image: sc.image };
      if (c) Object.assign(c, f); else cols.push(JSON.parse(JSON.stringify(sc)));
    });
    seed.products.forEach(sp => { const p = prods.find(x => x.id === sp.id); if (p) p.cols = [...new Set([...(p.cols || []), ...sp.cols])]; });
    await multi([['SET', P + 'collections', JSON.stringify(cols)], ['SET', P + 'products', JSON.stringify(prods)], ['SET', P + 'v', '6']]);
  });
}
/* v7/v8: product photos (one product per photo) and rate-list prices; keeps anything the owner already changed */
function applyProducts(S, key, cols, prods) {
  const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);
  const pv = p => (p.variants || []).map(v => [v.w, Number(v.price)]);
  const sc = S.collections.find(c => c.id === 'sweet-snacks'), lc = cols.find(c => c.id === 'sweet-snacks');
  if (sc && lc && !lc.image) lc.image = sc.image;
  const pk = S[key]; if (!pk) return;
  pk.ids.forEach(id => {
    const sp = S.products.find(x => x.id === id); if (!sp) return;
    const p = prods.find(x => x.id === id), old = pk.prev[id];
    if (!p) { prods.push(JSON.parse(JSON.stringify(sp))); return; }
    p.cols = [...new Set([...(p.cols || []), ...sp.cols])];
    if (old && same(p.images || [], old.i)) p.images = sp.images.slice();
    else sp.images.forEach(im => { if (!(p.images || []).includes(im)) (p.images = p.images || []).push(im); });
    if (old && same(pv(p), old.v)) p.variants = JSON.parse(JSON.stringify(sp.variants));
    if (old && p.name === old.n) { p.name = sp.name; p.desc = sp.desc; p.tag = sp.tag; }
  });
}
async function patchProducts(v) {
  const S = readSeed();
  await withLock(async () => {
    const cols = parse(await cmd('GET', P + 'collections')) || [], prods = parse(await cmd('GET', P + 'products')) || [];
    if (v < 7) applyProducts(S, 'p7', cols, prods);
    applyProducts(S, 'p8', cols, prods);
    await multi([['SET', P + 'collections', JSON.stringify(cols)], ['SET', P + 'products', JSON.stringify(prods)], ['SET', P + 'v', '8']]);
  });
}
/* v9: real phone, WhatsApp and address, only where the demo placeholder is still set */
const DEMO_CONTACT = { phone: '+92 300 0000000', whatsapp: '923000000000', address: 'Lahore, Pakistan' };
async function patchV9() {
  const S = readSeed();
  await withLock(async () => {
    const st = parse(await cmd('GET', P + 'settings')) || {};
    Object.keys(DEMO_CONTACT).forEach(k => { if (!st[k] || st[k] === DEMO_CONTACT[k]) st[k] = S.settings[k]; });
    await multi([['SET', P + 'settings', JSON.stringify(st)], ['SET', P + 'v', '9']]);
  });
}
/* v10: drop the demo email, 20% COD advance */
async function patchV10() {
  await withLock(async () => {
    const st = parse(await cmd('GET', P + 'settings')) || {};
    if (st.email === 'orders@alidryfruit.pk') st.email = '';
    if (st.codAdvance === undefined) st.codAdvance = 20;
    await multi([['SET', P + 'settings', JSON.stringify(st)], ['SET', P + 'v', '10']]);
  });
}
async function patchV11() {   // COD advance 20% → 30%
  await withLock(async () => {
    const st = parse(await cmd('GET', P + 'settings')) || {};
    if (st.codAdvance === undefined || Number(st.codAdvance) === 20) st.codAdvance = 30;
    await multi([['SET', P + 'settings', JSON.stringify(st)], ['SET', P + 'v', '11']]);
  });
}
const REAL_PAY = {"easypaisa": "0300-8572361", "jazzcash": "0300-8572361", "bank": "MCB Bank, Lake City, Lahore\nTitle: Ali Dry Fruit Khajure\nIBAN: PK72 MUCB 0613 9365 4100 2037"};
async function patchV12() {   // real Easypaisa / JazzCash / MCB details where the demo placeholder (with XX) is still set
  await withLock(async () => {
    const st = parse(await cmd('GET', P + 'settings')) || {}; st.pay = st.pay || {};
    Object.keys(REAL_PAY).forEach(k => { const cur = String(st.pay[k] || ''); if (!cur.trim() || /XX/.test(cur)) st.pay[k] = REAL_PAY[k]; });
    await multi([['SET', P + 'settings', JSON.stringify(st)], ['SET', P + 'v', '12']]);
  });
}
const PHONE_HERO = {"img": "assets/img/banner-main-mobile-bg.jpg", "kicker": "Pure Goodness From Nature", "t1": "Naturally Nutritious,", "t2": "Freshly Packed", "sub": "Premium quality dry fruits for a healthier, happier you."};
async function patchV13() {   // sharp phone hero: text-free photo + headline as real text
  await withLock(async () => {
    const bs = parse(await cmd('GET', P + 'banners')) || [], b0 = bs.find(b => b.id === 'b0');
    if (b0 && b0.fullMobile === 'assets/img/banner-main-mobile.jpg') { b0.fullMobile = PHONE_HERO.img; b0.mText = PHONE_HERO; }
    await multi([['SET', P + 'banners', JSON.stringify(bs)], ['SET', P + 'v', '13']]);
  });
}
async function patchData() {
  const v = Number(await cmd('GET', P + 'v'));
  if (v >= 13) return;
  if (v < 12) await patchData12(v);
  await patchV13();
}
async function patchData12(v) {
  if (v >= 12) return;
  if (v < 9) await patchData9(v);
  if (v < 10) await patchV10();
  if (v < 11) await patchV11();
  await patchV12();
}
async function patchData9(v) {
  if (v < 5) await patchV5();
  if (v < 6) await patchV6();
  if (v < 8) await patchProducts(v);
  await patchV9();
}
async function patchV5() {
  await withLock(async () => {
    const banners = parse(await cmd('GET', P + 'banners')) || [];
    const b0 = banners.find(b => b.id === 'b0');
    if (b0 && b0.full === 'assets/img/banner-main.jpg' && Number(b0.btnX) === 6.2) {
      Object.assign(b0, { btnX: 4.7, btnY: 62.9, btnW: 23.1, btnXm: 4.9, btnYm: 41.5, btnWm: 46 });
      await cmd('SET', P + 'banners', JSON.stringify(banners));
    }
  });
}
async function ensureSeed() {
  if (seeded) return;
  if (await cmd('EXISTS', P + 'products')) { await patchData(); seeded = true; return; }
  const seed = readSeed();
  await withLock(async () => {
    if (await cmd('EXISTS', P + 'products')) return;
    await multi([
      ...SECTIONS.map(k => ['SET', P + k, JSON.stringify(seed[k] || [])]),
      ['SET', P + 'seq', '1000', 'NX'],
      ['SET', P + 'v', '13'],
    ]);
  });
  seeded = true;
}

/* ---------------- admin session (signed cookie) ---------------- */
async function secret() {
  if (process.env.ADF_SECRET) return process.env.ADF_SECRET;
  let s = await cmd('GET', P + 'secret');
  if (!s) { await cmd('SET', P + 'secret', crypto.randomBytes(32).toString('hex'), 'NX'); s = await cmd('GET', P + 'secret'); }
  return s;
}
const sign = (val, key) => crypto.createHmac('sha256', key).update(val).digest('hex');
function cookies(req) {
  const o = {}; String(req.headers.cookie || '').split(';').forEach(p => { const i = p.indexOf('='); if (i > 0) o[p.slice(0, i).trim()] = decodeURIComponent(p.slice(i + 1).trim()); });
  return o;
}
async function isAdmin(req) {
  const c = cookies(req).adf_admin; if (!c) return false;
  const [exp, mac] = c.split('.');
  if (!exp || !mac || Number(exp) < Date.now()) return false;
  const good = sign(exp, await secret());
  return mac.length === good.length && crypto.timingSafeEqual(Buffer.from(mac), Buffer.from(good));
}
async function sessionCookie(req) {
  const exp = String(Date.now() + 7 * 864e5);
  const secure = (req.headers['x-forwarded-proto'] || '').includes('https') ? '; Secure' : '';
  return `adf_admin=${exp}.${sign(exp, await secret())}; Path=/; HttpOnly; SameSite=Lax; Max-Age=${7 * 86400}${secure}`;
}
function hashPass(pw) { const salt = crypto.randomBytes(16).toString('hex'); return salt + ':' + crypto.scryptSync(String(pw), salt, 32).toString('hex'); }
async function checkPassword(pw) {
  const a = parse(await cmd('GET', P + 'admin'));
  if (a && a.hash) {
    const [salt, h] = a.hash.split(':');
    const test = crypto.scryptSync(String(pw), salt, 32).toString('hex');
    return test.length === h.length && crypto.timingSafeEqual(Buffer.from(test), Buffer.from(h));
  }
  const want = Buffer.from(ADMIN_PASS_DEFAULT), got = Buffer.from(String(pw));
  return want.length === got.length && crypto.timingSafeEqual(want, got);
}

/* ---------------- helpers ---------------- */
const str = (v, max) => String(v ?? '').trim().slice(0, max);
function advanceOf(s, pay, total) {
  if (pay !== 'cod') return total;
  const pct = Math.max(0, Math.min(100, Number((s || {}).codAdvance ?? 30) || 0));
  return Math.round(total * pct / 100);
}
function payMethods(s) {
  const p = (s && s.pay) || {}, o = [];
  if (p.cod) o.push('cod');
  ['easypaisa', 'jazzcash', 'bank'].forEach(k => { if (p[k] && String(p[k]).trim()) o.push(k); });
  return o;
}
const hasStock = v => v.stock !== null && v.stock !== '' && v.stock !== undefined;
function imageType(buf) {
  if (buf[0] === 0xFF && buf[1] === 0xD8 && buf[2] === 0xFF) return 'image/jpeg';
  if (buf.slice(0, 8).equals(Buffer.from([0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A]))) return 'image/png';
  if (buf.slice(0, 4).toString() === 'RIFF' && buf.slice(8, 12).toString() === 'WEBP') return 'image/webp';
  if (buf.slice(0, 4).toString() === 'GIF8') return 'image/gif';
  return null;
}
async function readBody(req) {
  let b = req.body;
  if (b === undefined) {
    const chunks = []; for await (const c of req) chunks.push(c);
    b = Buffer.concat(chunks).toString('utf8');
  }
  if (Buffer.isBuffer(b)) b = b.toString('utf8');
  if (typeof b === 'string') { try { b = JSON.parse(b || '{}'); } catch (e) { b = {}; } }
  return b && typeof b === 'object' ? b : {};
}
function send(res, code, data, extra) {
  res.statusCode = code;
  res.setHeader('Content-Type', 'application/json; charset=utf-8');
  res.setHeader('Cache-Control', 'no-store');
  res.setHeader('X-Content-Type-Options', 'nosniff');
  if (extra) Object.entries(extra).forEach(([k, v]) => res.setHeader(k, v));
  res.end(JSON.stringify(data));
}

/* ---------------- routes ---------------- */
module.exports = async function handler(req, res) {
  const url = new URL(req.url, 'http://x');
  const a = url.searchParams.get('a') || '';
  const isPost = req.method === 'POST';
  try {
    if (!R_URL || !R_TOKEN)
      throw new Fail('Database not connected. In Vercel open Storage → Upstash for Redis → Connect to this project, then Redeploy.', 500);

    if (a === 'ping') { await cmd('PING'); return send(res, 200, { ok: true, mode: 'redis' }); }

    if (a === 'img') {
      const id = (url.searchParams.get('id') || '').replace(/[^a-z0-9-]/gi, '');
      const v = id && parse(await cmd('GET', P + 'img:' + id));
      if (!v) { res.statusCode = 404; return res.end('Not found'); }
      res.statusCode = 200;
      res.setHeader('Content-Type', v.type);
      res.setHeader('Cache-Control', 'public, max-age=31536000, s-maxage=31536000, immutable');
      res.setHeader('X-Content-Type-Options', 'nosniff');
      return res.end(Buffer.from(v.b64, 'base64'));
    }

    if (a === 'store') {
      await ensureSeed();
      const d = await getKV(SECTIONS);
      return send(res, 200, { ok: true, data: {
        settings: d.settings || {},
        banners: (d.banners || []).filter(b => b.active !== false),
        collections: (d.collections || []).filter(c => c.visible !== false),
        products: (d.products || []).filter(p => p.status === 'active'),
      } });
    }

    if (a === 'order') {
      if (!isPost) throw new Fail('POST only', 405);
      await ensureSeed();
      const b = await readBody(req), c = b.customer || {};
      const name = str(c.name, 80), phone = String(c.phone || '').replace(/[\s-]/g, ''), city = str(c.city, 60), addr = str(c.addr, 300), notes = str(c.notes, 500);
      if (name.length < 3) throw new Fail('Please enter your full name.');
      if (!/^(\+?92|0)3\d{9}$/.test(phone)) throw new Fail('Enter a valid mobile number, for example 0300 1234567.');
      if (city.length < 2) throw new Fail('Please enter your city.');
      if (addr.length < 8) throw new Fail('Please enter your full address so the rider can find you.');
      const items = Array.isArray(b.items) ? b.items.slice(0, 50) : [];
      if (!items.length) throw new Fail('Your cart is empty.');
      let proof = null;
      if (b.proof) {
        const buf = Buffer.from(String(b.proof).replace(/^data:[^,]*,/, ''), 'base64');
        if (buf.length > MAX_PROOF) throw new Fail('The screenshot is too large. Please try another one.');
        const type = imageType(buf);
        if (!type) throw new Fail('The payment screenshot must be a JPG, PNG or WEBP image.');
        proof = { type, b64: buf.toString('base64') };
      }
      const order = await withLock(async () => {
        const d = await getKV(['settings', 'products', 'seq']);
        const s = d.settings || {}, products = d.products || [];
        const pay = String(b.pay || '');
        if (!payMethods(s).includes(pay)) throw new Fail('Please choose a payment method.');
        const lines = []; let sub = 0;
        for (const it of items) {
          const p = products.find(x => x.id === String(it.pid || '') && x.status === 'active');
          const v = p && p.variants.find(x => x.w === String(it.w || ''));
          if (!p || !v) throw new Fail('One of the items in your cart is no longer available. Please remove it and try again.');
          const qty = Math.max(1, Math.min(99, parseInt(it.qty, 10) || 1));
          if (hasStock(v) && Number(v.stock) < qty)
            throw new Fail(Number(v.stock) > 0 ? `${p.name} (${v.w}) has only ${v.stock} left.` : `${p.name} (${v.w}) is sold out.`);
          if (hasStock(v)) v.stock = Number(v.stock) - qty;
          const price = Number(v.price);
          lines.push({ pid: p.id, name: p.name, w: v.w, price, qty, image: (p.images || [])[0] || '' });
          sub += price * qty;
        }
        const fee = Number(s.deliveryFee) || 0, free = Number(s.freeOver) || 0;
        const ship = free && sub >= free ? 0 : fee;
        const total = sub + ship, advance = advanceOf(s, pay, total);
        if (pay !== 'cod' && advance > 0 && !proof) throw new Fail('Please upload the payment screenshot.');
        const seq = (Number(d.seq) || 1000) + 1;
        const o = { id: 'ADF-' + seq, at: new Date().toISOString(), customer: { name, phone, city, addr, notes },
          items: lines, sub, ship, total, pay, advance, proof: !!proof, status: 'pending', paid: false, note: '' };
        await multi([
          ['SET', P + 'products', JSON.stringify(products)],
          ['SET', P + 'seq', String(seq)],
          ['HSET', P + 'orders', o.id, JSON.stringify(o)],
          ...(proof ? [['SET', P + 'proof:' + o.id, JSON.stringify(proof)]] : []),
        ]);
        return o;
      });
      return send(res, 200, { ok: true, order });
    }

    if (a === 'login') {
      if (!isPost) throw new Fail('POST only', 405);
      await ensureSeed();
      const b = await readBody(req);
      if (!(await checkPassword(b.password || ''))) { await new Promise(r => setTimeout(r, 800)); throw new Fail('Wrong password. Try again.', 401); }
      return send(res, 200, { ok: true }, { 'Set-Cookie': await sessionCookie(req) });
    }
    if (a === 'logout') return send(res, 200, { ok: true }, { 'Set-Cookie': 'adf_admin=; Path=/; HttpOnly; SameSite=Lax; Max-Age=0' });
    if (a === 'me') return send(res, 200, { ok: true, authed: await isAdmin(req) });

    /* ---------- admin only ---------- */
    if (!(await isAdmin(req))) throw new Fail('Please log in again.', 401);

    if (a === 'proof') {   // payment screenshot of an order (admin only)
      const id = (url.searchParams.get('id') || '').replace(/[^A-Za-z0-9-]/g, '');
      const v = id && parse(await cmd('GET', P + 'proof:' + id));
      if (!v) { res.statusCode = 404; return res.end('Not found'); }
      res.statusCode = 200;
      res.setHeader('Content-Type', v.type);
      res.setHeader('Cache-Control', 'private, max-age=86400');
      res.setHeader('X-Content-Type-Options', 'nosniff');
      return res.end(Buffer.from(v.b64, 'base64'));
    }
    if (!isPost && a !== 'all') throw new Fail('POST only', 405);

    if (a === 'all') {
      await ensureSeed();
      const d = await getKV([...SECTIONS, 'seq']);
      return send(res, 200, { ok: true, data: { settings: d.settings || {}, banners: d.banners || [], collections: d.collections || [],
        products: d.products || [], orders: await allOrders(), seq: Number(d.seq) || 1000 } });
    }

    const b = await readBody(req);

    if (a === 'save') {
      if (!SECTIONS.includes(b.key)) throw new Fail('Unknown section.');
      if (!b.value || typeof b.value !== 'object') throw new Fail('Nothing to save.');
      await withLock(() => cmd('SET', P + b.key, JSON.stringify(b.value)));
      return send(res, 200, { ok: true });
    }

    if (a === 'order_update') {
      const id = String(b.id || ''), patch = b.patch || {};
      const o = await withLock(async () => {
        const cur = parse(await cmd('HGET', P + 'orders', id));
        if (!cur) throw new Fail('Order not found.', 404);
        if (STATUSES.includes(patch.status)) cur.status = patch.status;
        if ('paid' in patch) cur.paid = !!patch.paid;
        if ('note' in patch) cur.note = str(patch.note, 1000);
        await cmd('HSET', P + 'orders', id, JSON.stringify(cur));
        return cur;
      });
      return send(res, 200, { ok: true, order: o });
    }

    if (a === 'orders_put') {
      const list = Array.isArray(b.orders) ? b.orders.filter(o => o && o.id && o.at) : [];
      if (list.length) await cmd('HSET', P + 'orders', ...list.flatMap(o => [String(o.id), JSON.stringify(o)]));
      return send(res, 200, { ok: true });
    }

    if (a === 'orders_delete') {
      const ids = Array.isArray(b.ids) ? b.ids.map(String) : [];
      if (ids.length) { await cmd('HDEL', P + 'orders', ...ids); await cmd('DEL', ...ids.map(i => P + 'proof:' + i)); }
      return send(res, 200, { ok: true });
    }

    if (a === 'password') {
      if (!(await checkPassword(b.current || ''))) throw new Fail('Current password is wrong.');
      const next = String(b.new || '');
      if (next.length < 4) throw new Fail('Use at least 4 characters for the new password.');
      await multi([
        ['SET', P + 'admin', JSON.stringify({ hash: hashPass(next) })],
        ['SET', P + 'secret', crypto.randomBytes(32).toString('hex')],   // signs out other devices
      ]);
      return send(res, 200, { ok: true }, { 'Set-Cookie': await sessionCookie(req) });
    }

    if (a === 'upload') {
      const raw = String(b.data || '').replace(/^data:[^,]*,/, '');
      const buf = Buffer.from(raw, 'base64');
      if (!buf.length) throw new Fail('Upload failed. Try a smaller image.');
      if (buf.length > MAX_IMAGE) throw new Fail('Image is larger than 6 MB.');
      const type = imageType(buf);
      if (!type) throw new Fail('Use a JPG, PNG, WEBP or GIF image.');
      const id = new Date().toISOString().slice(0, 10).replace(/-/g, '') + '-' + crypto.randomBytes(6).toString('hex');
      await cmd('SET', P + 'img:' + id, JSON.stringify({ type, b64: buf.toString('base64') }));
      return send(res, 200, { ok: true, url: 'api.php?a=img&id=' + id });
    }

    if (a === 'import') {
      const d = b.data;
      if (!d || !Array.isArray(d.products)) throw new Fail('That file is not a valid backup.');
      await withLock(async () => {
        const cmds = SECTIONS.filter(k => d[k] && typeof d[k] === 'object').map(k => ['SET', P + k, JSON.stringify(d[k])]);
        if (b.replaceOrders && Array.isArray(d.orders)) {
          cmds.push(['DEL', P + 'orders']);
          const list = d.orders.filter(o => o && o.id && o.at);
          if (list.length) cmds.push(['HSET', P + 'orders', ...list.flatMap(o => [String(o.id), JSON.stringify(o)])]);
          if (d.seq) cmds.push(['SET', P + 'seq', String(Number(d.seq) || 1000)]);
        }
        await multi(cmds);
      });
      return send(res, 200, { ok: true });
    }

    throw new Fail('Unknown action.', 404);
  } catch (e) {
    if (e instanceof Fail) return send(res, e.code, { ok: false, error: e.message });
    console.error('[ali-dry-fruit]', e);
    return send(res, 500, { ok: false, error: 'Server error. Please try again.' });
  }
};
