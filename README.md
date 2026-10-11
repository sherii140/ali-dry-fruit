# Ali Dry Fruit — Store + Admin

```
index.html            → Store (customers)
admin9196/index.html  → Admin panel  (yourdomain.com/admin9196)
assets/               → store.js, seed.js (demo data), images
api/adf.js            → Backend for Vercel (Upstash Redis)
api.php               → Backend for Hostinger (PHP, file or MySQL)
config.sample.php     → Hostinger MySQL details (copy to config.php)
vercel.json           → Vercel settings
data/, uploads/       → Hostinger only: saved data and uploaded images
```

Admin password starts as **1234**. Change it right after going live: **Admin → Settings → Admin password**.

---

## A) Vercel pe live karna (GitHub se)

1. **vercel.com** → GitHub se login → **Add New… → Project** → `ali-dry-fruit` repo → **Import**.
2. Framework Preset: **Other**. Baaki sab waisa hi chhor dein → **Deploy**.
3. Database jorna (ek dafa): project kholein → **Storage** → **Create Database** → **Upstash for Redis** (Free) → **Connect** → is project ko select karein.
4. **Deployments** → sab se upar wali deployment ke **⋯** → **Redeploy**.
5. `your-project.vercel.app/admin9196` kholein → password **1234** → foran badlein.

Uske baad jo bhi GitHub pe push hoga, Vercel khud nayi site bana dega.

Apna domain: Vercel project → **Settings → Domains** → domain likhein → jo DNS records Vercel bataye wo domain wale panel me daal dein.

Vercel pe data (products, orders, banners) aur upload ki hui images Upstash Redis me save hoti hain. Free plan: 250 MB data, 500K commands/month.

## B) Hostinger pe live karna

1. hPanel → **File Manager** → `public_html` kholein.
2. Zip upload karein → **Extract**. Dhyan rahe `index.html` seedha `public_html` me ho.
3. `yourdomain.com/admin9196` → password **1234** → foran badlein.
4. Settings me WhatsApp number (923001234567 format), Easypaisa/JazzCash/Bank details daalein.

Data khud `data/store.php` me save hota hai. `data` aur `uploads` folders ki permission 755 honi chahiye.

**MySQL (optional):** hPanel → Databases → MySQL → database + user banayein. `config.sample.php` ko `config.php` naam se copy karein aur details bharein. `config.php` GitHub pe nahi jata, password safe rehta hai. Pehle se data ho to pehle **Settings → Download backup**, MySQL ke baad **Restore backup**.

## C) Computer pe check karna

- **VS Code Live Server:** `index.html` → Open with Live Server. Admin: `http://127.0.0.1:5500/admin9196/`. Isme "Testing mode" aata hai: changes sirf isi browser me save hote hain.
- **PHP ke saath:** folder me terminal → `php -S localhost:8000` → `http://localhost:8000`.

---

## Admin me kya kya hai
- **Dashboard**: sales, orders, average order, pending, sales chart, top products, low stock
- **Orders**: status (Pending → Confirmed → Shipped → Delivered / Cancelled), paid, note, customer ko WhatsApp
- **Products**: add/edit, multiple photos, weights (250g/500g/1kg), price, compare-at (crossed price + % off), stock, badge, draft
- **Collections**: categories (Shop by Category), main + sub collections, image, products select
- **Banners**: Canva ka ready banner ya "Text + photos". Desktop **1536 × 864 px** (16:9), phone **1080 × 1440 px** (3:4). Banner pe clickable button, drag karke jagah set karein. Ek banner on ho to slider ruka rehta hai, 2+ on hon to chalta hai.
- **Homepage**: header style (transparent / white), top strip, moving line (100% Organic…), sale heading, benefit banner, footer text
- **Customers**: orders se khud banti list
- **Settings**: store info, delivery charges, payments, password, backup/restore

Stock khali chhorein = unlimited. Order aate hi stock khud kam hota hai; khatam hone pe store pe "Sold out".
