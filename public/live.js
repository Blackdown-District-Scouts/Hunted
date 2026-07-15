// Live updates for the admin screens.
//  - Action forms inside #live submit via fetch (no full page reload).
//  - A WebSocket relay tells every open page when something changed; each page
//    then re-fetches its own live region (?fragment=1) and swaps it in.
//  - Falls back to polling if the WebSocket can't connect.
//  - Open cards and the field you're typing in are preserved across refreshes.
(function () {
  var live = document.getElementById('live');
  if (!live) return;
  var wsPort = live.dataset.wsport || '8081';

  var refreshing = false, queued = false;
  var forceCollapse = {}; // pids to leave collapsed after the next refresh

  // Remember which cards are open and what the user is editing.
  function snapshot() {
    var open = [];
    live.querySelectorAll('details[open]').forEach(function (d) {
      var pid = d.dataset.pid;
      if (pid && !forceCollapse[pid]) open.push(pid);
    });
    var a = document.activeElement, focus = null;
    if (a && live.contains(a) && a.name) {
      var card = a.closest('[data-pid]');
      var pid = card ? card.dataset.pid : null;
      if (!pid || !forceCollapse[pid]) {
        focus = { pid: pid, name: a.name, value: a.value, start: a.selectionStart, end: a.selectionEnd };
      }
    }
    forceCollapse = {}; // consumed
    return { open: open, focus: focus };
  }

  function restore(s) {
    s.open.forEach(function (pid) {
      var d = live.querySelector('details[data-pid="' + pid + '"]');
      if (d) d.open = true;
    });
    var f = s.focus;
    if (f && f.pid) {
      var card = live.querySelector('[data-pid="' + f.pid + '"]');
      var el = card && card.querySelector('[name="' + f.name + '"]');
      if (el) {
        el.focus();
        try {
          el.value = f.value;
          if (el.setSelectionRange) el.setSelectionRange(f.start, f.end);
        } catch (e) {}
      }
    }
  }

  function refresh() {
    if (refreshing) { queued = true; return Promise.resolve(); }
    refreshing = true;
    return fetch(location.pathname + '?fragment=1', { headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) { return r.ok ? r.text() : null; })
      .then(function (html) {
        if (html !== null) {
          var s = snapshot();
          live.innerHTML = html;
          restore(s);
        }
      })
      .catch(function () {})
      .then(function () {
        refreshing = false;
        if (queued) { queued = false; return refresh(); }
      });
  }

  // Intercept action forms (delegated, so it also covers re-rendered content).
  live.addEventListener('submit', function (e) {
    var form = e.target;
    if (!(form instanceof HTMLFormElement)) return;
    e.preventDefault();
    var fd = new FormData(form);
    // include the button that was used, if any
    if (form._submitter && form._submitter.name) fd.append(form._submitter.name, form._submitter.value);
    fd.append('ajax', '1');
    var subjectCard = form.closest('details[data-pid]');
    fetch(location.pathname, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) {
        if (!r.ok) throw new Error('bad status');
        // Collapse the card whose form was just submitted (don't reopen it on refresh).
        if (subjectCard && subjectCard.dataset.pid) forceCollapse[subjectCard.dataset.pid] = true;
        return refresh();
      })
      .then(function () { broadcast(); })
      .catch(function () { form.submit(); }); // fall back to a normal submit
  }, false);
  // Track which submit button triggered the submit (for named buttons).
  live.addEventListener('click', function (e) {
    var b = e.target.closest('button, input[type=submit]');
    if (b && b.form) b.form._submitter = b;
  }, true);

  // ---- WebSocket relay with polling fallback ----
  var ws = null, reconnectTimer = null, pollTimer = null;

  function startPoll() { if (!pollTimer) pollTimer = setInterval(refresh, 4000); }
  function stopPoll() { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } }

  function broadcast() {
    if (ws && ws.readyState === 1) { try { ws.send('{"t":"changed"}'); } catch (e) {} }
  }

  function connect() {
    try {
      ws = new WebSocket('ws://' + location.hostname + ':' + wsPort);
    } catch (e) { startPoll(); return; }
    ws.onopen = function () { stopPoll(); };
    ws.onmessage = function () { refresh(); };
    ws.onerror = function () { try { ws.close(); } catch (e) {} };
    ws.onclose = function () { startPoll(); scheduleReconnect(); };
  }
  function scheduleReconnect() {
    clearTimeout(reconnectTimer);
    reconnectTimer = setTimeout(connect, 3000);
  }

  connect();
})();
