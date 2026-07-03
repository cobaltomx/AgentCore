'use strict';

/**
 * Rutas públicas (sin auth) — cédula web de propiedad.
 * Servida por el backend para que sea accesible vía la URL pública (ngrok),
 * con etiquetas Open Graph para que WhatsApp muestre una tarjeta con preview.
 */

const { publicBase, absUrl } = require('../services/public-url');

function esc(s) {
  return String(s == null ? '' : s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function renderCedula(p, base) {
  const a   = p.attributes || {};
  const imgs = (Array.isArray(p.images) && p.images.length ? p.images : (p.image_url ? [p.image_url] : [])).map(u => absUrl(u, base));
  const cover = imgs[0] || '';
  const biz = p.tenant_name || 'Inmobiliaria';
  const price = a.priceLabel || (p.price_cents ? '$' + (p.price_cents / 100).toLocaleString('es-MX') : '');
  const opLabel = a.operation === 'renta' ? 'En renta' : a.operation === 'venta' ? 'En venta' : '';
  const url = `${base}/p/${p.id}`;

  const specs = [];
  if (a.bedrooms)  specs.push(['🛏️', `${a.bedrooms} recámara${a.bedrooms > 1 ? 's' : ''}`]);
  if (a.bathrooms) specs.push(['🛁', `${a.bathrooms} baño${a.bathrooms > 1 ? 's' : ''}`]);
  if (a.areaM2)    specs.push(['📐', `${a.areaM2} m²`]);
  if (a.parking)   specs.push(['🚗', `${a.parking} estac.`]);
  if (a.zone)      specs.push(['📍', a.zone]);

  const desc = (p.description || '').slice(0, 200);
  const metaDesc = `${price}${opLabel ? ' · ' + opLabel : ''}${a.zone ? ' · ' + a.zone : ''}. ${desc}`;

  return `<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1"/>
<title>${esc(p.name)} — ${esc(biz)}</title>
<meta name="description" content="${esc(metaDesc)}"/>
<!-- Open Graph (preview en WhatsApp / redes) -->
<meta property="og:type" content="website"/>
<meta property="og:title" content="${esc(p.name)} — ${esc(price)}"/>
<meta property="og:description" content="${esc(metaDesc)}"/>
<meta property="og:image" content="${esc(cover)}"/>
<meta property="og:url" content="${esc(url)}"/>
<meta name="twitter:card" content="summary_large_image"/>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'Segoe UI',system-ui,sans-serif;background:#f4f5fb;color:#2c2c40;line-height:1.5}
  .wrap{max-width:560px;margin:0 auto;background:#fff;min-height:100vh;box-shadow:0 0 40px rgba(0,0,0,.06)}
  .gallery{position:relative;background:#111}
  .gallery .main{width:100%;aspect-ratio:3/2;object-fit:cover;display:block}
  .badge{position:absolute;top:14px;left:14px;background:#696cff;color:#fff;padding:.35rem .8rem;border-radius:999px;font-size:.8rem;font-weight:600}
  .thumbs{display:flex;gap:6px;padding:8px;background:#111;overflow-x:auto}
  .thumbs img{height:64px;width:90px;object-fit:cover;border-radius:6px;cursor:pointer;opacity:.6;flex:0 0 auto;border:2px solid transparent}
  .thumbs img.active{opacity:1;border-color:#696cff}
  .body{padding:1.4rem}
  .price{font-size:1.7rem;font-weight:800;color:#696cff}
  .op{display:inline-block;font-size:.78rem;color:#71dd37;font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.3rem}
  h1{font-size:1.25rem;margin:.2rem 0 .1rem}
  .zone{color:#8592a3;font-size:.95rem;margin-bottom:1rem}
  .specs{display:flex;flex-wrap:wrap;gap:.6rem;margin:1rem 0;padding:1rem;background:#f8f8ff;border-radius:.8rem}
  .specs div{font-size:.9rem}
  .amen{display:flex;flex-wrap:wrap;gap:.4rem;margin:.8rem 0}
  .amen span{background:#eef0ff;color:#5659d6;padding:.25rem .7rem;border-radius:999px;font-size:.8rem}
  .desc{color:#4b4b63;margin:1rem 0;font-size:.95rem}
  .cta{display:block;text-align:center;background:#25d366;color:#fff;text-decoration:none;padding:.9rem;border-radius:.8rem;font-weight:700;margin-top:1.2rem}
  .biz{text-align:center;color:#8592a3;font-size:.82rem;padding:1.2rem}
</style>
</head>
<body>
<div class="wrap">
  <div class="gallery">
    ${cover ? `<img class="main" id="mainImg" src="${esc(cover)}" alt="${esc(p.name)}"/>` : ''}
    ${opLabel ? `<span class="badge">${esc(opLabel)}</span>` : ''}
    ${imgs.length > 1 ? `<div class="thumbs">${imgs.map((u, i) => `<img src="${esc(u)}" class="${i === 0 ? 'active' : ''}" onclick="sel(this,'${esc(u)}')"/>`).join('')}</div>` : ''}
  </div>
  <div class="body">
    ${opLabel ? `<div class="op">${esc(opLabel)}</div>` : ''}
    <div class="price">${esc(price)}</div>
    <h1>${esc(p.name)}</h1>
    ${a.zone ? `<div class="zone">📍 ${esc(a.zone)}</div>` : ''}
    ${specs.length ? `<div class="specs">${specs.map(s => `<div>${s[0]} ${esc(s[1])}</div>`).join('')}</div>` : ''}
    ${Array.isArray(a.amenities) && a.amenities.length ? `<div class="amen">${a.amenities.map(x => `<span>${esc(x)}</span>`).join('')}</div>` : ''}
    ${p.description ? `<p class="desc">${esc(p.description)}</p>` : ''}
    <a class="cta" href="https://wa.me/?text=${encodeURIComponent('Me interesa: ' + p.name + ' ' + url)}">💬 Me interesa esta propiedad</a>
  </div>
  <div class="biz">${esc(biz)}</div>
</div>
<script>
function sel(el,u){document.getElementById('mainImg').src=u;document.querySelectorAll('.thumbs img').forEach(t=>t.classList.remove('active'));el.classList.add('active');}
</script>
</body>
</html>`;
}

async function publicRoutes(app) {
  // GET /p/:id — cédula pública de propiedad
  app.get('/p/:id', async (request, reply) => {
    const { id } = request.params;
    if (!/^[0-9a-f-]{36}$/i.test(id)) {
      return reply.code(400).type('text/html').send('<h1>ID inválido</h1>');
    }
    const r = await app.db.query(
      `SELECT p.*, t.name AS tenant_name, t.settings AS tenant_settings
       FROM products p JOIN tenants t ON t.id = p.tenant_id
       WHERE p.id = $1 AND p.is_active = true`,
      [id]
    );
    const p = r.rows[0];
    if (!p) return reply.code(404).type('text/html').send('<h1>Propiedad no encontrada</h1>');
    const base = publicBase(p.tenant_settings || {});
    reply.type('text/html; charset=utf-8').send(renderCedula(p, base));
  });
}

module.exports = publicRoutes;
