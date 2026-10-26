/* Ali Dry Fruit — shared data layer (storefront + admin)
 *
 * SERVER mode : api.php is running (Hostinger / any PHP host). Data is saved in MySQL
 *               or in data/store.php, so every customer sees the same products and
 *               every order reaches the admin panel.
 * LOCAL mode  : no PHP (VS Code Live Server, file preview). Data is saved in this
 *               browser's localStorage. Good for testing: admin changes show on the
 *               storefront in the same browser.
 * OFFLINE mode: a live domain where api.php could not be reached or is misconfigured.
 *               The store still shows products and customers send the order on WhatsApp.
 */
(function () {
  const ROOT = window.ADF_ROOT || '';
  const API = ROOT + 'api.php';
  const LKEY = 'adf_db_v1', CKEY = 'adf_cache_v1', SKEY = 'adf_admin_session', PKEY = 'adf_admin_pass';
  const clone = o => JSON.parse(JSON.stringify(o));
  const h = location.hostname;
  const DEV = location.protocol === 'file:' || h === 'localhost' || h === '127.0.0.1' || h === '' ||
    /^192\.168\.|^10\./.test(h) || /claude|anthropic/.test(h) || window.ADF_FORCE_LOCAL === true;
  let mode = null, note = '';

  function seed() { const d = clone(window.ADF_SEED); d.orders = []; d.seq = 1000; return d; }
  /* bring older saved data up to the latest demo version (adds new collections, keeps your edits) */
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
  function migrate(d) {
    const S = window.ADF_SEED;
    if ((d.v || 1) >= (S.v || 1)) return d;
    d.collections = d.collections || [];
    S.collections.forEach(sc => {
      const c = d.collections.find(x => x.id === sc.id);
      if (!c) d.collections.push(clone(sc));
      else { if (c.parent === undefined) c.parent = sc.parent; if (c.home === undefined) c.home = sc.home; }
    });
    if ((d.v || 1) < 2) S.products.forEach(sp => {
      const p = (d.products || []).find(x => x.id === sp.id);
      if (p) p.cols = [...new Set([...(p.cols || []), ...sp.cols])];
    });
    if ((d.v || 1) < 3) {
      d.banners = d.banners || [];
      const hero = S.banners.find(b => b.id === 'b0');
      if (hero && !d.banners.some(b => b.id === 'b0')) d.banners.unshift(clone(hero));
      d.settings = d.settings || {};
      if (!d.settings.headerStyle) d.settings.headerStyle = 'transparent';
    }
    if ((d.v || 1) < 4) {
      const hero = S.banners.find(b => b.id === 'b0'), mine = (d.banners || []).find(b => b.id === 'b0');
      if (hero && mine && !mine.fullMobile) Object.assign(mine, { fullMobile: hero.fullMobile, btnXm: hero.btnXm, btnYm: hero.btnYm, btnWm: hero.btnWm });
      (d.banners || []).forEach(b => { if (['b1', 'b2', 'b3'].includes(b.id)) b.active = false; });
    }
    if ((d.v || 1) < 5) {
      const hero = S.banners.find(b => b.id === 'b0'), mine = (d.banners || []).find(b => b.id === 'b0');
      if (hero && mine && mine.full === hero.full && Number(mine.btnX) === 6.2) ['btnX', 'btnY', 'btnW', 'btnXm', 'btnYm', 'btnWm'].forEach(k => { mine[k] = hero[k]; });
    }
    if ((d.v || 1) < 6) {
      d.collections = d.collections || [];
      S.collections.forEach(sc => {
        const c = d.collections.find(x => x.id === sc.id);
        const f = { name: sc.name, parent: sc.parent, home: sc.home, visible: sc.visible, pos: sc.pos, image: sc.image };
        if (c) Object.assign(c, f); else d.collections.push(clone(sc));
      });
      S.products.forEach(sp => { const p = (d.products || []).find(x => x.id === sp.id); if (p) p.cols = [...new Set([...(p.cols || []), ...sp.cols])]; });
    }
    if ((d.v || 1) < 8) {
      const cols = d.collections = d.collections || [], prods = d.products = d.products || [];
      if ((d.v || 1) < 7) applyProducts(S, 'p7', cols, prods);
      applyProducts(S, 'p8', cols, prods);
    }
    if ((d.v || 1) < 9) {
      const demo = { phone: '+92 300 0000000', whatsapp: '923000000000', address: 'Lahore, Pakistan' }, st = d.settings = d.settings || {};
      Object.keys(demo).forEach(k => { if (!st[k] || st[k] === demo[k]) st[k] = S.settings[k]; });
    }
    if ((d.v || 1) < 10) { const st = d.settings = d.settings || {}; if (st.email === 'orders@alidryfruit.pk') st.email = ''; if (st.codAdvance === undefined) st.codAdvance = 20; }
    if ((d.v || 1) < 11) { const st = d.settings = d.settings || {}; if (st.codAdvance === undefined || Number(st.codAdvance) === 20) st.codAdvance = 30; }
    if ((d.v || 1) < 12) { const st = d.settings = d.settings || {}; st.pay = st.pay || {}; ['easypaisa', 'jazzcash', 'bank'].forEach(k => { const cur = String(st.pay[k] || ''); if (!cur.trim() || /XX/.test(cur)) st.pay[k] = S.settings.pay[k]; }); }
    if ((d.v || 1) < 13) { const b0 = (d.banners || []).find(b => b.id === 'b0'), sb = S.banners.find(b => b.id === 'b0'); if (b0 && sb && b0.fullMobile === 'assets/img/banner-main-mobile.jpg') { b0.fullMobile = sb.fullMobile; b0.mText = sb.mText; } }
    if ((d.v || 1) < 14) d.products = [];
    d.v = S.v;
    try { localStorage.setItem(LKEY, JSON.stringify(d)); } catch (e) {}
    return d;
  }
  function readLocal() {
    try { const d = JSON.parse(localStorage.getItem(LKEY)); if (d && Array.isArray(d.products)) return migrate(d); } catch (e) {}
    return seed();
  }
  function writeLocal(d) {
    try { localStorage.setItem(LKEY, JSON.stringify(d)); }
    catch (e) { throw new Error('Browser storage is full. Remove some uploaded images, or run the site on a PHP server.'); }
  }
  function readCache() { try { const d = JSON.parse(localStorage.getItem(CKEY)); if (d && Array.isArray(d.products)) return d; } catch (e) {} return null; }
  const pub = d => ({ settings: d.settings, banners: d.banners, collections: d.collections, products: d.products });

  async function call(a, data, form) {
    const o = { credentials: 'same-origin', cache: 'no-store' };
    if (data !== undefined) {
      o.method = 'POST';
      if (form) o.body = data; else { o.body = JSON.stringify(data); o.headers = { 'Content-Type': 'application/json' }; }
    }
    let r, j;
    try { r = await fetch(API + '?a=' + a, o); } catch (e) { throw new Error('Could not reach the server. Check your internet connection and try again.'); }
    try { j = await r.json(); } catch (e) { throw new Error('The server sent an unexpected reply. Check that api.php is uploaded.'); }
    if (!j.ok) { const e = new Error(j.error || 'Something went wrong.'); e.status = r.status; throw e; }
    return j;
  }

  async function init() {
    if (mode) return mode;
    if (window.ADF_FORCE_LOCAL === true) { mode = 'local'; return mode; }
    try {
      const ctl = new AbortController(); const t = setTimeout(() => ctl.abort(), 6000);
      const r = await fetch(API + '?a=ping', { cache: 'no-store', signal: ctl.signal, credentials: 'same-origin' });
      clearTimeout(t);
      const j = JSON.parse(await r.text());
      if (j && j.ok) { mode = 'server'; note = j.mode === 'mysql' ? 'MySQL database' : j.mode === 'redis' ? 'Vercel database (Upstash Redis)' : 'server file storage (data/store.php)'; }
      else { mode = 'offline'; note = (j && j.error) || 'api.php returned an error.'; }
    } catch (e) { mode = DEV ? 'local' : 'offline'; if (!DEV) note = 'api.php could not be reached.'; }
    return mode;
  }

  /* ---------- order maths (local + offline modes; api.php does the same on the server) ---------- */
  function payMethods(s) {
    const p = (s && s.pay) || {}, out = [];
    if (p.cod) out.push('cod');
    ['easypaisa', 'jazzcash', 'bank'].forEach(k => { if (p[k] && String(p[k]).trim()) out.push(k); });
    return out;
  }
  /* COD needs an advance (settings.codAdvance %, default 20); other methods are paid in full before dispatch */
  function advanceOf(s, pay, total) {
    if (pay !== 'cod') return total;
    const pct = Math.max(0, Math.min(100, Number((s || {}).codAdvance ?? 30) || 0));
    return Math.round(total * pct / 100);
  }
  function computeOrder(db, payload, applyStock) {
    const s = db.settings || {}, c = payload.customer || {};
    const name = String(c.name || '').trim().slice(0, 80);
    const phone = String(c.phone || '').replace(/[\s-]/g, '');
    const city = String(c.city || '').trim().slice(0, 60);
    const addr = String(c.addr || '').trim().slice(0, 300);
    const notes = String(c.notes || '').trim().slice(0, 500);
    if (name.length < 3) throw new Error('Please enter your full name.');
    if (!/^(\+?92|0)3\d{9}$/.test(phone)) throw new Error('Enter a valid mobile number, for example 0300 1234567.');
    if (city.length < 2) throw new Error('Please enter your city.');
    if (addr.length < 8) throw new Error('Please enter your full address so the rider can find you.');
    if (!payMethods(s).includes(payload.pay)) throw new Error('Please choose a payment method.');
    const list = Array.isArray(payload.items) ? payload.items.slice(0, 50) : [];
    if (!list.length) throw new Error('Your cart is empty.');
    const lines = [];
    for (const it of list) {
      const p = db.products.find(x => x.id === it.pid && x.status === 'active');
      const v = p && p.variants.find(x => x.w === it.w);
      if (!p || !v) throw new Error('One of the items in your cart is no longer available. Please remove it and try again.');
      const qty = Math.max(1, Math.min(99, parseInt(it.qty, 10) || 1));
      if (v.stock !== null && v.stock !== '' && v.stock !== undefined && Number(v.stock) < qty)
        throw new Error(Number(v.stock) > 0 ? `${p.name} (${v.w}) has only ${v.stock} left.` : `${p.name} (${v.w}) is sold out.`);
      lines.push({ p, v, qty });
    }
    if (applyStock) lines.forEach(l => { if (l.v.stock !== null && l.v.stock !== '' && l.v.stock !== undefined) l.v.stock = Number(l.v.stock) - l.qty; });
    const items = lines.map(l => ({ pid: l.p.id, name: l.p.name, w: l.v.w, price: Number(l.v.price), qty: l.qty, image: l.p.images[0] || '' }));
    const sub = items.reduce((t, i) => t + i.price * i.qty, 0);
    const fee = Number(s.deliveryFee) || 0, free = Number(s.freeOver) || 0;
    const ship = free && sub >= free ? 0 : fee;
    const total = sub + ship, advance = advanceOf(s, payload.pay, total);
    if (applyStock && payload.pay !== 'cod' && advance > 0 && !payload.proof) throw new Error('Please upload the payment screenshot.');
    const o = { at: new Date().toISOString(), customer: { name, phone, city, addr, notes }, items, sub, ship, total,
      pay: payload.pay, advance, status: 'pending', paid: false, note: '' };
    if (applyStock && payload.proof) { o.proof = true; o.proofData = payload.proof; }
    return o;
  }

  /* ---------- images ---------- */
  function loadImg(file) {
    return new Promise((res, rej) => {
      const u = URL.createObjectURL(file), im = new Image();
      im.onload = () => { res(im); setTimeout(() => URL.revokeObjectURL(u), 1000); };
      im.onerror = () => rej(new Error('This file is not an image we can read. Use JPG, PNG or WEBP.'));
      im.src = u;
    });
  }
  async function compress(file, max, q) {
    const im = await loadImg(file);
    const k = Math.min(1, max / Math.max(im.naturalWidth, im.naturalHeight));
    const w = Math.round(im.naturalWidth * k), hh = Math.round(im.naturalHeight * k);
    const c = document.createElement('canvas'); c.width = w; c.height = hh;
    const x = c.getContext('2d'); x.fillStyle = '#fff'; x.fillRect(0, 0, w, hh); x.drawImage(im, 0, 0, w, hh);
    return { canvas: c, blob: await new Promise(r => c.toBlob(r, 'image/jpeg', q || 0.82)) };
  }

  window.ADF = {
    init,
    mode: () => mode,
    note: () => note,
    seed,
    payMethods,
    advanceOf,
    /* payment screenshot → small JPEG data URL (max 1400px) */
    async proofImage(file) {
      if (!file) throw new Error('Please upload the payment screenshot.');
      const { canvas } = await compress(file, 1400, 0.8);
      return canvas.toDataURL('image/jpeg', 0.8);
    },
    proofSrc(o) { if (!o || !o.proof) return ''; if (o.proofData) return o.proofData; return ROOT + 'api.php?a=proof&id=' + encodeURIComponent(o.id); },
    src(p) { if (!p) return ''; return /^(data:|https?:|blob:|\/)/.test(p) ? p : ROOT + p; },

    /* storefront */
    quick() { const c = readCache(); return c || pub(readLocal()); },
    async store() {
      if (mode === 'server') {
        const j = await call('store');
        try { localStorage.setItem(CKEY, JSON.stringify(j.data)); } catch (e) {}
        return j.data;
      }
      if (mode === 'offline') return readCache() || pub(seed());
      return pub(readLocal());
    },
    async placeOrder(payload) {
      if (mode === 'server') return (await call('order', payload)).order;
      if (mode === 'local') {
        const d = readLocal(); const o = computeOrder(d, payload, true);
        d.seq = (d.seq || 1000) + 1; o.id = 'ADF-' + d.seq; d.orders = d.orders || []; d.orders.push(o);
        writeLocal(d); return o;
      }
      const o = computeOrder(readCache() || seed(), payload, false);
      o.id = 'WA-' + Date.now().toString().slice(-6); o.offline = true; return o;
    },

    /* admin */
    async login(pw) {
      if (mode === 'server') { await call('login', { password: pw }); return true; }
      if (mode === 'offline') throw new Error('The server is not set up yet: ' + note);
      let cur = '1234'; try { cur = localStorage.getItem(PKEY) || '1234'; } catch (e) {}
      if (pw !== cur) throw new Error('Wrong password. Try again.');
      try { sessionStorage.setItem(SKEY, '1'); } catch (e) {}
      return true;
    },
    async me() {
      if (mode === 'server') { try { return (await call('me')).authed; } catch (e) { return false; } }
      try { return sessionStorage.getItem(SKEY) === '1'; } catch (e) { return false; }
    },
    async logout() {
      if (mode === 'server') { try { await call('logout', {}); } catch (e) {} }
      try { sessionStorage.removeItem(SKEY); } catch (e) {}
    },
    async all() { if (mode === 'server') return (await call('all')).data; return readLocal(); },
    async save(key, value) {
      if (!['settings', 'banners', 'collections', 'products'].includes(key)) throw new Error('Unknown section');
      if (mode === 'server') { await call('save', { key, value }); return; }
      const d = readLocal(); d[key] = value; writeLocal(d);
    },
    async updateOrder(id, patch) {
      if (mode === 'server') return (await call('order_update', { id, patch })).order;
      const d = readLocal(), o = (d.orders || []).find(x => x.id === id);
      if (!o) throw new Error('Order not found.');
      ['status', 'paid', 'note'].forEach(k => { if (k in patch) o[k] = patch[k]; });
      writeLocal(d); return o;
    },
    async putOrders(list) {
      if (mode === 'server') { await call('orders_put', { orders: list }); return; }
      const d = readLocal(); d.orders = d.orders || [];
      list.forEach(o => { const i = d.orders.findIndex(x => x.id === o.id); if (i > -1) d.orders[i] = o; else d.orders.push(o); });
      writeLocal(d);
    },
    async deleteOrders(ids) {
      if (mode === 'server') { await call('orders_delete', { ids }); return; }
      const d = readLocal(); d.orders = (d.orders || []).filter(o => !ids.includes(o.id)); writeLocal(d);
    },
    async setPassword(current, next) {
      if (String(next).length < 4) throw new Error('Use at least 4 characters for the new password.');
      if (mode === 'server') { await call('password', { current, new: next }); return; }
      let cur = '1234'; try { cur = localStorage.getItem(PKEY) || '1234'; } catch (e) {}
      if (current !== cur) throw new Error('Current password is wrong.');
      localStorage.setItem(PKEY, next);
    },
    async upload(file, max) {
      const big = max || 1200;
      if (mode === 'server') {
        let blob = file;
        if (!(file.type === 'image/png' && file.size < 400000) && file.type !== 'image/gif') blob = (await compress(file, big, 0.85)).blob;
        const data = await new Promise((res, rej) => { const fr = new FileReader(); fr.onload = () => res(fr.result); fr.onerror = () => rej(new Error('Could not read the image.')); fr.readAsDataURL(blob); });
        return (await call('upload', { data })).url;
      }
      const r = await compress(file, Math.min(big, 1000), 0.8);
      return r.canvas.toDataURL('image/jpeg', 0.8);
    },
    async replaceAll(data, replaceOrders) {
      if (mode === 'server') { await call('import', { data, replaceOrders: !!replaceOrders }); return; }
      const d = readLocal();
      ['settings', 'banners', 'collections', 'products'].forEach(k => { if (data[k]) d[k] = data[k]; });
      if (replaceOrders && Array.isArray(data.orders)) { d.orders = data.orders; d.seq = data.seq || d.seq; }
      writeLocal(d);
    }
  };
})();
