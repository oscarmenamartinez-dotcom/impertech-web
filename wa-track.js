// Seguimiento de clics a WhatsApp — impertech.cl (inicio, blog y páginas internas).
//
// En cada clic a un link de wa.me:
//  1. Agrega al mensaje precargado un código de origen, ej. "(ref. G)":
//       G  = Google Ads (llegó con gclid o utm de pago)
//       B  = buscador orgánico (Google, Bing, etc. sin anuncio)
//       IA = buscador de IA (ChatGPT, Perplexity, Claude, Gemini…)
//       IG = Instagram   FB = Facebook
//       W  = directo u otro sitio
//     Hugo responde normal; para contar contactos reales por canal basta buscar "ref." en WhatsApp.
//  2. Registra el evento whatsapp_click en GA4 y la conversión de Google Ads (una por visita),
//     solo en páginas que cargan gtag.
//  3. Envía el aviso interno (correo + planilla) vía Apps Script, uno por visita.
(function () {
  var LEAD_ENDPOINT = 'https://script.google.com/macros/s/AKfycbxsCmcHFu4W4n5tZIedGJKaKd-9irfoipaISWPW7aVLOcLihXZRrujX8ObC5ynJ_ssq/exec';
  var CONVERSION = 'AW-18244838549/EcuxCPuewNwcEJXJ6PtD';

  function ss(k, v) {
    try {
      if (v === undefined) return sessionStorage.getItem(k);
      sessionStorage.setItem(k, v);
    } catch (e) { return null; }
  }

  function getGclid() {
    try {
      var m = location.search.match(/[?&](?:gclid|gbraid|wbraid)=([^&#]+)/);
      if (m) ss('imp_gclid', decodeURIComponent(m[1]));
      var g = ss('imp_gclid');
      if (g) return g;
      var c = document.cookie.match(/(?:^|; )_gcl_aw=GCL\.\d+\.([^;]+)/);
      return c ? c[1] : '';
    } catch (e) { return ''; }
  }

  // Guarda el origen de la visita al entrar (utm / referrer), para no perderlo
  // si la persona navega dentro del sitio antes de escribir.
  getGclid();
  try {
    var q = location.search;
    var u = (q.match(/[?&]utm_source=([^&#]+)/) || [])[1];
    var med = (q.match(/[?&]utm_medium=([^&#]+)/) || [])[1];
    if (u) ss('imp_utm', decodeURIComponent(u));
    if (med) ss('imp_medium', decodeURIComponent(med));
    if (!ss('imp_ref')) {
      var r = document.referrer || '';
      // Navegación interna no cuenta como origen.
      if (r && r.indexOf(location.host) !== -1) r = '';
      ss('imp_ref', r || '(directo)');
    }
  } catch (e) {}

  function codigoOrigen() {
    var utm = (ss('imp_utm') || '').toLowerCase();
    var med = (ss('imp_medium') || '').toLowerCase();
    var ref = (ss('imp_ref') || '').toLowerCase();
    var src = utm + ' ' + ref;
    if (getGclid() || /cpc|ppc|paid/.test(med)) return 'G';
    if (/chatgpt|openai|perplexity|claude\.ai|gemini|copilot/.test(src)) return 'IA';
    if (/instagram|\big\b/.test(src)) return 'IG';
    if (/facebook|fb\.|\bfb\b|m\.me/.test(src)) return 'FB';
    if (/google\.|bing\.|duckduckgo|yahoo\.|ecosia|search\./.test(src)) return 'B';
    return 'W';
  }

  // Agrega "(ref. X)" al texto precargado del link de WhatsApp.
  function conCodigo(href, codigo) {
    try {
      var m = href.match(/([?&]text=)([^&#]*)/);
      var texto = m ? decodeURIComponent(m[2]) : '';
      if (!texto) texto = 'Hola, quiero cotizar';
      texto = texto.replace(/\s*\(ref\. [A-Z]+\)\s*$/, '') + ' (ref. ' + codigo + ')';
      var enc = encodeURIComponent(texto);
      if (m) return href.replace(m[0], m[1] + enc);
      return href + (href.indexOf('?') === -1 ? '?' : '&') + 'text=' + enc;
    } catch (e) { return href; }
  }

  // Servicio consultado: se saca del texto precargado del link de WhatsApp.
  function servicioDe(href) {
    try {
      var t = decodeURIComponent((href.match(/[?&]text=([^&#]+)/) || [])[1] || '');
      var m = t.match(/(?:impermeabilizaci[oó]n de|cotizar (?:una|un)?)\s*(.+)$/i);
      var r = m ? m[1].replace(/^impermeabilizaci[oó]n( de)?\s*/i, '').trim() : '';
      return r || (t ? 'general' : 'sin texto');
    } catch (e) { return ''; }
  }

  function sessionId() {
    var id = ss('imp_sid');
    if (!id) {
      id = Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
      ss('imp_sid', id);
    }
    return id || '';
  }

  function notifyLead(label, servicio, codigo) {
    if (!LEAD_ENDPOINT || LEAD_ENDPOINT.indexOf('https://') !== 0) return;
    if (ss('imp_aviso_hecho')) return; // un aviso por visita
    ss('imp_aviso_hecho', '1');
    try {
      var body = JSON.stringify({
        tel: '',
        boton: label,
        servicio: servicio,
        codigo: codigo,
        gclid: getGclid(),
        utm: ss('imp_utm') || '',
        ref: ss('imp_ref') || document.referrer || '',
        pagina: location.pathname,
        movil: /Mobi|Android|iPhone/i.test(navigator.userAgent)
      });
      if (window.fetch) {
        fetch(LEAD_ENDPOINT, {
          method: 'POST', mode: 'no-cors', credentials: 'omit', keepalive: true,
          headers: { 'Content-Type': 'text/plain;charset=UTF-8' }, body: body
        }).catch(function () {});
      }
    } catch (e) {}
  }

  function track(label, servicio, codigo) {
    if (typeof window.gtag === 'function') {
      window.gtag('event', 'whatsapp_click', {
        'event_category': 'contacto',
        'event_label': label,
        'servicio': servicio,
        'origen': codigo
      });
      // Conversión de Google Ads: una por visita (transaction_id evita duplicados).
      if (!ss('imp_conv')) {
        ss('imp_conv', '1');
        window.gtag('event', 'conversion', { 'send_to': CONVERSION, 'transaction_id': sessionId() });
      }
    }
    notifyLead(label, servicio, codigo);
  }

  document.addEventListener('click', function (e) {
    var el = e.target.closest && e.target.closest('a[href*="wa.me"], [onclick*="wa.me"]');
    if (!el) return;
    var href = el.tagName === 'A'
      ? el.getAttribute('href')
      : (el.getAttribute('onclick').match(/https:\/\/wa\.me\/[^'")]+/) || [])[0];
    if (!href) return;
    e.preventDefault();
    if (e.stopImmediatePropagation) e.stopImmediatePropagation();
    var codigo = 'W';
    try { codigo = codigoOrigen(); } catch (err) {}
    var label = (el.innerText || el.getAttribute('aria-label') || '').trim() || 'WhatsApp';
    try { track(label, servicioDe(href), codigo); } catch (err) {}
    window.open(conCodigo(href, codigo), '_blank', 'noopener');
  }, true);
})();
