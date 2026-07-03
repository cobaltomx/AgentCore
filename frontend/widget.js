/* AgentCore — Widget de chat web embebible
 * Uso: <script src="https://app.tudominio/widget.js" data-key="wgt_xxx"></script>
 * Opcional: data-api="https://api.tudominio/api/v1/widget"
 *
 * Aislado con Shadow DOM para no chocar con los estilos del sitio anfitrión.
 */
(function () {
  'use strict';

  // ── Config desde el <script> ───────────────────────────────────────────────
  var script = document.currentScript ||
    (function () { var s = document.getElementsByTagName('script'); return s[s.length - 1]; })();
  var KEY = script.getAttribute('data-key');
  if (!KEY) { console.warn('[AgentCore] Falta data-key en el script del widget'); return; }

  // API base: 1) data-api (lo emite el snippet), 2) derivar del origin del script.
  var API = script.getAttribute('data-api');
  if (!API) {
    try {
      var u = new URL(script.src);
      if (u.hostname === 'localhost' || u.hostname === '127.0.0.1') {
        API = u.protocol + '//' + u.hostname + ':3001/api/v1/widget';  // dev (backend en :3001)
      } else {
        API = u.protocol + '//' + u.host + '/api/v1/widget';            // prod (mismo origen / reverse proxy)
      }
    } catch (e) { API = '/api/v1/widget'; }
  }

  // visitor_id persistente por navegador
  var VKEY = 'ac_widget_visitor';
  var visitorId = localStorage.getItem(VKEY);
  if (!visitorId) {
    visitorId = 'v_' + Date.now() + '_' + Math.random().toString(36).slice(2, 10);
    localStorage.setItem(VKEY, visitorId);
  }

  var cfg = { color: '#696cff', name: 'Asistente', welcome: '¡Hola! 👋 ¿En qué puedo ayudarte?', enabled: true };
  var history = [];
  var open = false;

  // ── Cargar config y arrancar ───────────────────────────────────────────────
  fetch(API + '/config?key=' + encodeURIComponent(KEY))
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (data && data.enabled === false) return; // widget apagado
      if (data) cfg = Object.assign(cfg, data);
      build();
    })
    .catch(function () { /* si falla config, no montar el widget */ });

  // ── Construir UI (Shadow DOM) ──────────────────────────────────────────────
  function build() {
    var host = document.createElement('div');
    host.id = 'agentcore-widget-host';
    host.style.cssText = 'position:fixed;bottom:20px;right:20px;z-index:2147483647';
    document.body.appendChild(host);
    var root = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;

    root.innerHTML =
      '<style>' + styles() + '</style>' +
      '<div class="ac-panel" id="ac-panel">' +
        '<div class="ac-header">' +
          '<div class="ac-htitle">' + esc(cfg.name) + '</div>' +
          '<button class="ac-close" id="ac-close" aria-label="Cerrar">&times;</button>' +
        '</div>' +
        '<div class="ac-body" id="ac-body"></div>' +
        '<div class="ac-typing" id="ac-typing"><span></span><span></span><span></span></div>' +
        '<div class="ac-cart" id="ac-cart" style="display:none">' +
          '<span class="ac-cart-info" id="ac-cart-info">🛒 0</span>' +
          '<button class="ac-cart-btn" id="ac-cart-btn">Finalizar pedido</button>' +
        '</div>' +
        '<div class="ac-input">' +
          '<input id="ac-text" type="text" placeholder="Escribe tu mensaje..." autocomplete="off"/>' +
          '<button id="ac-send" aria-label="Enviar">➤</button>' +
        '</div>' +
        '<div class="ac-foot">Con tecnología de <b>AgentCore</b></div>' +
      '</div>' +
      '<button class="ac-bubble" id="ac-bubble" aria-label="Abrir chat">' +
        '<svg viewBox="0 0 24 24" width="26" height="26" fill="#fff"><path d="M12 3C6.5 3 2 6.9 2 11.7c0 2.2.9 4.2 2.5 5.7L3.5 21l3.9-1.2c1.4.6 2.9.9 4.6.9 5.5 0 10-3.9 10-8.7S17.5 3 12 3z"/></svg>' +
      '</button>';

    var panel  = root.getElementById('ac-panel');
    var bubble = root.getElementById('ac-bubble');
    var body   = root.getElementById('ac-body');
    var input  = root.getElementById('ac-text');
    var typing = root.getElementById('ac-typing');
    var cartBar  = root.getElementById('ac-cart');
    var cartInfo = root.getElementById('ac-cart-info');
    root.getElementById('ac-cart-btn').onclick = function () { send('Quiero finalizar mi pedido'); };

    function toggle(show) {
      open = show;
      panel.style.display  = show ? 'flex' : 'none';
      bubble.style.display = show ? 'none' : 'flex';
      if (show) { input.focus(); if (!body.children.length) botSay(cfg.welcome); }
    }

    bubble.onclick = function () { toggle(true); };
    root.getElementById('ac-close').onclick = function () { toggle(false); };
    root.getElementById('ac-send').onclick = send;
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter') send(); });

    function userSay(t) { addMsg(t, 'user'); }
    function botSay(t)  { addMsg(t, 'bot'); }

    function addMsg(text, who) {
      var d = document.createElement('div');
      d.className = 'ac-msg ac-' + who;
      d.textContent = text;
      body.appendChild(d);
      body.scrollTop = body.scrollHeight;
    }

    // Menú visual (tarjetas con foto), agrupado por categoría.
    function renderMenu(cards) {
      var groups = {};
      cards.forEach(function (it) { var c = it.category || 'Menú'; (groups[c] = groups[c] || []).push(it); });
      var html = '';
      Object.keys(groups).forEach(function (cat) {
        html += '<div class="ac-menu-cat">' + esc(cat) + '</div><div class="ac-menu-grid">';
        groups[cat].forEach(function (it) {
          var price = '$' + ((it.price_cents || 0) / 100).toLocaleString('es-MX') + ' ' + esc(it.currency || 'MXN');
          var img = it.image_url
            ? '<img src="' + esc(it.image_url) + '" loading="lazy" onerror="this.outerHTML=\'<div class=&quot;ac-menu-ph&quot;>🍽️</div>\'">'
            : '<div class="ac-menu-ph">🍽️</div>';
          html += '<div class="ac-menu-card"><div class="ac-menu-img">' + img + '</div>' +
            '<div class="ac-menu-info"><div class="ac-menu-name">' + esc(it.name) + '</div>' +
            '<div class="ac-menu-foot"><span class="ac-menu-price">' + price + '</span>' +
            '<button class="ac-menu-add" data-name="' + esc(it.name) + '" title="Agregar">+</button>' +
            '</div></div></div>';
        });
        html += '</div>';
      });
      var wrap = document.createElement('div');
      wrap.className = 'ac-menu';
      wrap.innerHTML = html;
      body.appendChild(wrap);
      // Enganchar los botones "+" (agregar al carrito)
      var btns = wrap.querySelectorAll('.ac-menu-add');
      for (var i = 0; i < btns.length; i++) {
        btns[i].onclick = function () { addItem(this.getAttribute('data-name'), this); };
      }
      body.scrollTop = body.scrollHeight;
    }

    // Agrega un platillo al carrito de forma DETERMINISTA (endpoint dedicado,
    // sin pasar por el LLM → siempre agrega y devuelve el carrito actualizado).
    function addItem(name, btn) {
      if (btn) btn.disabled = true;
      fetch(API + '/cart', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ key: KEY, visitor_id: visitorId, product_name: name }),
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (btn) btn.disabled = false;
          if (data && data.cart) updateCart(data.cart);
          if (btn) { btn.textContent = '✓'; setTimeout(function () { btn.textContent = '+'; }, 900); }
        })
        .catch(function () { if (btn) btn.disabled = false; });
    }

    function updateCart(cart) {
      if (cart && cart.count > 0) {
        var tot = '$' + ((cart.total_cents || 0) / 100).toLocaleString('es-MX');
        cartInfo.textContent = '🛒 ' + cart.count + ' producto' + (cart.count > 1 ? 's' : '') + ' · ' + tot;
        cartBar.style.display = 'flex';
      } else {
        cartBar.style.display = 'none';
      }
    }

    // POST al bot. Devuelve la promesa con la data (o null si falla). Maneja el
    // indicador "escribiendo…". No pinta burbujas (lo decide quien lo llama).
    function postMessage(text) {
      typing.style.display = 'flex';
      body.scrollTop = body.scrollHeight;
      return fetch(API + '/message', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ key: KEY, visitor_id: visitorId, text: text }),
      })
        .then(function (r) { return r.json(); })
        .then(function (data) { typing.style.display = 'none'; return data; })
        .catch(function () { typing.style.display = 'none'; return null; });
    }

    function send(forced) {
      var t = forced || input.value.trim();
      if (!t) return;
      if (!forced) input.value = '';
      userSay(t);
      history.push({ role: 'user', content: t });
      postMessage(t).then(function (data) {
        if (!data) { botSay('Hubo un problema de conexión. Intenta de nuevo.'); return; }
        var reply = data.reply || 'Disculpa, no pude responder. Intenta de nuevo.';
        botSay(reply);
        history.push({ role: 'assistant', content: reply });
        if (data.cards && data.cards.length) renderMenu(data.cards);
        if (data.cart) updateCart(data.cart);
      });
    }
  }

  // ── Estilos (scoped al Shadow DOM) ─────────────────────────────────────────
  function styles() {
    var c = cfg.color;
    return [
      '*{box-sizing:border-box;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif}',
      '.ac-bubble{width:60px;height:60px;border-radius:50%;background:' + c + ';border:none;cursor:pointer;',
        'box-shadow:0 4px 14px rgba(0,0,0,.25);display:flex;align-items:center;justify-content:center;transition:transform .15s}',
      '.ac-bubble:hover{transform:scale(1.08)}',
      '.ac-panel{display:none;flex-direction:column;width:340px;height:480px;max-height:75vh;background:#fff;',
        'border-radius:14px;overflow:hidden;box-shadow:0 8px 30px rgba(0,0,0,.28)}',
      '.ac-header{background:' + c + ';color:#fff;padding:14px 16px;display:flex;align-items:center;justify-content:space-between}',
      '.ac-htitle{font-weight:600;font-size:15px}',
      '.ac-close{background:none;border:none;color:#fff;font-size:22px;cursor:pointer;line-height:1;opacity:.85}',
      '.ac-close:hover{opacity:1}',
      '.ac-body{flex:1;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:8px;background:#f7f7fb}',
      '.ac-msg{max-width:82%;padding:9px 12px;border-radius:13px;font-size:14px;line-height:1.45;white-space:pre-wrap;word-break:break-word}',
      '.ac-user{align-self:flex-end;background:' + c + ';color:#fff;border-bottom-right-radius:4px}',
      '.ac-bot{align-self:flex-start;background:#fff;color:#2f2f3a;border:1px solid #ececf2;border-bottom-left-radius:4px}',
      '.ac-typing{display:none;gap:4px;padding:0 16px 6px 18px}',
      '.ac-typing span{width:7px;height:7px;border-radius:50%;background:' + c + ';opacity:.5;animation:acb 1.2s infinite}',
      '.ac-typing span:nth-child(2){animation-delay:.2s}.ac-typing span:nth-child(3){animation-delay:.4s}',
      '@keyframes acb{0%,80%,100%{transform:translateY(0)}40%{transform:translateY(-5px)}}',
      '.ac-input{display:flex;gap:6px;padding:10px;border-top:1px solid #ececf2;background:#fff}',
      '.ac-input input{flex:1;border:1px solid #d9d9e3;border-radius:20px;padding:9px 14px;font-size:14px;outline:none}',
      '.ac-input input:focus{border-color:' + c + '}',
      '.ac-input button{background:' + c + ';border:none;color:#fff;width:40px;height:40px;border-radius:50%;cursor:pointer;font-size:15px}',
      '.ac-foot{text-align:center;font-size:11px;color:#9a9aa8;padding:6px;background:#fff}',
      // Menú visual
      '.ac-menu{align-self:stretch;background:#fff;border:1px solid #ececf2;border-radius:13px;padding:8px 10px}',
      '.ac-menu-cat{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#9a9aa8;margin:6px 0 4px}',
      '.ac-menu-grid{display:grid;grid-template-columns:1fr 1fr;gap:6px}',
      '.ac-menu-card{border:1px solid #ececf2;border-radius:9px;overflow:hidden;background:#fff;transition:transform .12s,box-shadow .12s}',
      '.ac-menu-card:hover{transform:translateY(-2px);box-shadow:0 5px 14px rgba(0,0,0,.12)}',
      '.ac-menu-img{aspect-ratio:4/3;background:#f1f1f6}',
      '.ac-menu-img img{width:100%;height:100%;object-fit:cover;display:block}',
      '.ac-menu-ph{width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:24px;background:linear-gradient(135deg,#eceefe,#f6e9fb)}',
      '.ac-menu-info{padding:5px 7px}',
      '.ac-menu-name{font-size:12px;font-weight:600;color:#2f2f3a;line-height:1.2}',
      '.ac-menu-foot{display:flex;align-items:center;justify-content:space-between;gap:4px;margin-top:3px}',
      '.ac-menu-price{font-size:12px;font-weight:700;color:' + c + '}',
      '.ac-menu-add{border:none;background:' + c + ';color:#fff;width:22px;height:22px;border-radius:6px;font-size:15px;line-height:1;cursor:pointer;flex-shrink:0;display:flex;align-items:center;justify-content:center;padding:0}',
      '.ac-menu-add:hover{filter:brightness(1.08)}',
      '.ac-menu-add:disabled{opacity:.6;cursor:default}',
      // Barra de carrito
      '.ac-cart{display:none;align-items:center;justify-content:space-between;gap:8px;padding:8px 12px;background:#fff;border-top:1px solid #ececf2}',
      '.ac-cart-info{font-size:13px;font-weight:600;color:#2f2f3a}',
      '.ac-cart-btn{border:none;background:' + c + ';color:#fff;border-radius:18px;padding:7px 14px;font-size:13px;font-weight:600;cursor:pointer}',
      '.ac-cart-btn:hover{filter:brightness(1.08)}',
    ].join('');
  }

  function esc(s) { return String(s || '').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
})();
