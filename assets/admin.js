/* Pricing rules: the "is" field follows the "when" type and lists this site's
   own values (post types, categories, tags), the full bot catalog (shipped list
   plus the Pro crawler feed plus every bot already seen in the ledger), purposes
   and classes. Path and unknown bot names are free text. */
(function () {
  var data = window.fmppcRules || {};
  var OTHER = '__other__';
  function fill(select, type, current) {
    var opts = data.options && data.options[type] ? data.options[type] : null;
    select.innerHTML = '';
    if (!opts) { select.style.display = 'none'; return false; }
    var found = false;
    opts.forEach(function (o) {
      var el = document.createElement('option');
      el.value = o.value; el.textContent = o.label || o.value;
      if (o.value === current) { el.selected = true; found = true; }
      select.appendChild(el);
    });
    if (type === 'bot') {
      var other = document.createElement('option');
      other.value = OTHER; other.textContent = data.i18n.other || 'Other (type a name)…';
      if (current && !found) { other.selected = true; }
      select.appendChild(other);
    } else if (current && !found) {
      var cur = document.createElement('option'); cur.value = current; cur.textContent = current + ' ' + (data.i18n.missing || '(not found on this site)'); cur.selected = true; select.appendChild(cur);
    }
    select.style.display = '';
    return true;
  }
  function wire(row) {
    var type = row.querySelector('.fmppc-rule-type');
    var sel = row.querySelector('.fmppc-rule-select');
    var txt = row.querySelector('.fmppc-rule-value');
    var price = row.querySelector('.fmppc-rule-price');
    var action = row.querySelector('.fmppc-rule-action');
    function sync() {
      var t = type.value;
      var hasList = t && fill(sel, t, txt.value);
      var free = !t || t === 'path' || (t === 'bot' && sel.value === OTHER);
      txt.style.display = free ? '' : 'none';
      if (hasList && !free && sel.value !== OTHER) { txt.value = sel.value; }
      txt.placeholder = data.placeholders && data.placeholders[t] ? data.placeholders[t] : '';
      price.disabled = action.value !== 'paid';
    }
    type.addEventListener('change', function () { txt.value = ''; sync(); });
    sel.addEventListener('change', function () { if (sel.value === OTHER) { txt.value = ''; txt.style.display = ''; txt.focus(); } else { txt.value = sel.value; txt.style.display = 'none'; } });
    action.addEventListener('change', sync);
    sync();
  }
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.fmppc-rules tbody tr').forEach(wire);
  });
})();

/* Bot directory: live search + filter chips; groups open to show matches. */
(function () {
  document.addEventListener('DOMContentLoaded', function () {
    var q = document.getElementById('fmppc-bot-search');
    if (!q) return;
    var cards = Array.prototype.slice.call(document.querySelectorAll('.fmppc-bot'));
    var groups = Array.prototype.slice.call(document.querySelectorAll('.fmppc-bot-group'));
    var chips = Array.prototype.slice.call(document.querySelectorAll('.fmppc-chip'));
    var count = document.getElementById('fmppc-bot-count');
    var empty = document.getElementById('fmppc-bot-empty');
    var filter = '';
    var defaults = groups.map(function (g) { return g.open; });
    function apply() {
      var t = q.value.trim().toLowerCase();
      var shown = 0;
      cards.forEach(function (c) {
        var ok = (!t || c.getAttribute('data-q').indexOf(t) !== -1) &&
          (filter === '' || (filter === 'seen' && c.getAttribute('data-seen') === '1') || (filter === 'new' && c.getAttribute('data-new') === '1') || (['paid', 'free', 'block'].indexOf(filter) !== -1 && c.getAttribute('data-policy') === filter));
        c.style.display = ok ? '' : 'none';
        if (ok) shown++;
      });
      groups.forEach(function (g, i) {
        var visible = g.querySelectorAll('.fmppc-bot:not([style*="display: none"])').length;
        g.style.display = visible ? '' : 'none';
        g.open = (t || filter) ? true : defaults[i];
      });
      count.textContent = shown + ' / ' + cards.length;
      empty.style.display = shown ? 'none' : '';
    }
    q.addEventListener('input', apply);
    chips.forEach(function (ch) { ch.addEventListener('click', function () { chips.forEach(function (x) { x.classList.remove('is-on'); }); ch.classList.add('is-on'); filter = ch.getAttribute('data-filter'); apply(); }); });
    if (location.hash && location.hash.indexOf('#bot-') === 0) { var el = document.querySelector(location.hash); if (el) { el.closest('details').open = true; el.scrollIntoView(); el.classList.add('is-target'); } }
    apply();
  });
})();
