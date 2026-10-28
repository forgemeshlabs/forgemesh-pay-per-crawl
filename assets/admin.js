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
