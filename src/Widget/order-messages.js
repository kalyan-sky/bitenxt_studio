/*
 * BiteNXT order messages: lets a Pro customer write to the studio about one
 * order and upload files (scans, photos, PDFs). Served at /order-messages.js.
 *
 *   <script src="https://<studio-service>/order-messages.js" data-token-key="customerToken"></script>
 *
 *   BitenxtOrderMessages.open('000001234');            // open the panel for an order
 *   BitenxtOrderMessages.unread().then(r => r.total);   // unread studio replies (for a badge)
 *   BitenxtOrderMessages.setToken(token);               // if Pro keeps the token in app state
 *   BitenxtOrderMessages.send('000001234', 'text', fileList) // send without the panel (e.g. from the chatbot)
 *
 * The token is the customer's Magento token, the same one the support chatbot uses.
 * Everything is rendered as text (no HTML from the server is ever inserted).
 */
(function () {
  'use strict';
  if (window.BitenxtOrderMessages) return;

  var script = document.currentScript;
  var apiBase = (script && script.dataset.api) || (script ? new URL(script.src).origin : '');
  var tokenKey = (script && script.dataset.tokenKey) || 'customerToken';
  var explicitToken = null;
  var state = { order: null, timer: null, root: null };

  function readToken() {
    if (explicitToken) return explicitToken;
    var raw = null;
    try { raw = localStorage.getItem(tokenKey) || sessionStorage.getItem(tokenKey); } catch (e) {}
    if (!raw) {
      var m = document.cookie.match(new RegExp('(?:^|; )' + tokenKey.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '=([^;]*)'));
      raw = m ? decodeURIComponent(m[1]) : null;
    }
    if (raw && raw.charAt(0) === '"') { try { raw = JSON.parse(raw); } catch (e) {} }
    return raw || null;
  }

  function request(path, opts) {
    opts = opts || {};
    var token = readToken();
    if (!token) return Promise.reject(new Error('Please log in to your BiteNXT Pro account.'));
    var headers = { Authorization: 'Bearer ' + token };
    var body = opts.body;
    if (body && !(body instanceof FormData)) { headers['Content-Type'] = 'application/json'; body = JSON.stringify(body); }
    return fetch(apiBase + path, { method: opts.method || 'GET', headers: headers, body: body }).then(function (res) {
      if (opts.raw) return res;
      return res.json().catch(function () { return {}; }).then(function (data) {
        if (!res.ok) throw new Error(data.message || 'Something went wrong (' + res.status + ').');
        return data;
      });
    });
  }

  function orderPath(order) { return '/api/orders/' + encodeURIComponent(String(order).trim()) + '/messages'; }

  function send(order, text, files) {
    var form = new FormData();
    form.append('text', text || '');
    Array.prototype.forEach.call(files || [], function (f) { form.append('files[]', f); });
    return request(orderPath(order), { method: 'POST', body: form });
  }

  // ---------- panel ----------
  var css = [
    '.bnx-om{position:fixed;inset:0;z-index:2147483000;display:flex;align-items:center;justify-content:center;background:rgba(15,20,30,.45);font:14px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}',
    '.bnx-om *{box-sizing:border-box}',
    '.bnx-om-box{background:#fff;color:#1d2330;width:min(560px,100vw - 24px);height:min(680px,100vh - 24px);border-radius:12px;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 12px 40px rgba(0,0,0,.25)}',
    '.bnx-om-head{display:flex;align-items:center;gap:8px;padding:12px 16px;border-bottom:1px solid #e3e6ea}',
    '.bnx-om-head strong{flex:1}',
    '.bnx-om-x{border:0;background:none;font-size:20px;cursor:pointer;color:#6b7280}',
    '.bnx-om-list{flex:1;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:10px;background:#f7f8fa}',
    '.bnx-om-msg{max-width:80%;padding:8px 12px;border-radius:10px;background:#fff;border:1px solid #e3e6ea;white-space:pre-wrap;overflow-wrap:anywhere}',
    '.bnx-om-msg.you{align-self:flex-end;background:#e8f1fb;border-color:#cfe1f5}',
    '.bnx-om-meta{font-size:12px;color:#6b7280;margin-bottom:2px}',
    '.bnx-om-file{display:block;margin-top:6px;padding:4px 8px;border:1px solid #d5d9df;border-radius:6px;background:#fff;cursor:pointer;font:inherit;font-size:13px;text-align:left}',
    '.bnx-om-empty{margin:auto;color:#6b7280;text-align:center;padding:0 20px}',
    '.bnx-om-form{border-top:1px solid #e3e6ea;padding:10px 14px;display:grid;gap:8px}',
    '.bnx-om-form textarea{width:100%;min-height:60px;resize:vertical;border:1px solid #d5d9df;border-radius:8px;padding:8px;font:inherit}',
    '.bnx-om-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}',
    '.bnx-om-row span{flex:1;color:#b42318;font-size:13px}',
    '.bnx-om-send{background:#0f6cbd;color:#fff;border:0;border-radius:8px;padding:8px 16px;cursor:pointer;font:inherit}',
    '.bnx-om-send:disabled{opacity:.6}',
  ].join('');

  function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }
  function when(ts) { return new Date(ts * 1000).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }); }
  function size(b) { return b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'; }

  function build() {
    if (!document.getElementById('bnx-om-style')) {
      var style = el('style'); style.id = 'bnx-om-style'; style.textContent = css; document.head.appendChild(style);
    }
    var root = el('div', 'bnx-om');
    var box = el('div', 'bnx-om-box');
    var head = el('div', 'bnx-om-head');
    var title = el('strong');
    var close = el('button', 'bnx-om-x', '×'); close.type = 'button'; close.setAttribute('aria-label', 'Close');
    head.append(title, close);
    var list = el('div', 'bnx-om-list');
    var form = el('form', 'bnx-om-form');
    var text = el('textarea'); text.placeholder = 'Write to the BiteNXT studio about this order…'; text.maxLength = 4000;
    var row = el('div', 'bnx-om-row');
    var files = el('input'); files.type = 'file'; files.multiple = true;
    var err = el('span');
    var sendBtn = el('button', 'bnx-om-send', 'Send'); sendBtn.type = 'submit';
    row.append(files, err, sendBtn);
    form.append(text, row);
    box.append(head, list, form);
    root.appendChild(box);

    close.addEventListener('click', closePanel);
    root.addEventListener('click', function (e) { if (e.target === root) closePanel(); });
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      sendBtn.disabled = true; err.textContent = '';
      send(state.order, text.value, files.files).then(function () {
        text.value = ''; files.value = '';
        return load(true);
      }).catch(function (e2) { err.textContent = e2.message; }).then(function () { sendBtn.disabled = false; });
    });

    return { root: root, title: title, list: list };
  }

  function render(data, scroll) {
    var list = state.ui.list;
    list.replaceChildren();
    state.ui.title.textContent = 'Order #' + data.order_number + ' · Messages with the studio';
    if (!data.messages.length) {
      list.appendChild(el('div', 'bnx-om-empty', 'No messages yet. Send a note or upload files (scans, photos, PDFs) and the studio team will reply here.'));
    }
    data.messages.forEach(function (m) {
      var b = el('div', 'bnx-om-msg ' + m.from);
      b.appendChild(el('div', 'bnx-om-meta', m.name + ' · ' + when(m.at)));
      if (m.text) b.appendChild(el('div', '', m.text));
      m.attachments.forEach(function (a) {
        var f = el('button', 'bnx-om-file', '📎 ' + a.name + ' (' + size(a.size) + ')'); f.type = 'button';
        f.addEventListener('click', function () { download(a); });
        b.appendChild(f);
      });
      list.appendChild(b);
    });
    if (scroll) list.scrollTop = list.scrollHeight;
  }

  function load(scroll) {
    var order = state.order;
    return request(orderPath(order)).then(function (data) {
      if (state.order === order && state.ui) render(data, scroll);
      return data;
    });
  }

  function download(a) {
    request('/api/attachments/' + a.id, { raw: true }).then(function (res) {
      if (!res.ok) throw new Error('That file is not available.');
      return res.blob();
    }).then(function (blob) {
      var url = URL.createObjectURL(blob);
      var link = el('a'); link.href = url; link.download = a.name; document.body.appendChild(link); link.click(); link.remove();
      setTimeout(function () { URL.revokeObjectURL(url); }, 30000);
    }).catch(function (e) { alert(e.message); });
  }

  function open(order) {
    if (!order) throw new Error('BitenxtOrderMessages.open needs an order number');
    closePanel();
    state.order = String(order).trim();
    state.ui = build();
    state.root = state.ui.root;
    document.body.appendChild(state.root);
    state.ui.list.appendChild(el('div', 'bnx-om-empty', 'Loading…'));
    load(true).catch(function (e) { state.ui.list.replaceChildren(el('div', 'bnx-om-empty', e.message)); });
    state.timer = setInterval(function () { if (!document.hidden) load(false).catch(function () {}); }, 20000);
  }

  function closePanel() {
    clearInterval(state.timer);
    if (state.root) state.root.remove();
    state.root = null; state.ui = null; state.order = null;
  }

  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && state.root) closePanel(); });

  window.BitenxtOrderMessages = {
    open: open,
    close: closePanel,
    send: send,
    thread: function (order) { return request(orderPath(order)); },
    unread: function () { return request('/api/unread'); },
    setToken: function (t) { explicitToken = t || null; },
  };
})();
