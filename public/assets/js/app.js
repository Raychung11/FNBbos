// Light enhancements — confirms on destructive actions and sortable tables.
document.addEventListener('click', function (e) {
  const t = e.target.closest('[data-confirm]');
  if (t && !window.confirm(t.dataset.confirm)) e.preventDefault();
});

document.querySelectorAll('table.data th[data-sort]').forEach(function (th) {
  th.style.cursor = 'pointer';
  th.addEventListener('click', function () {
    const table = th.closest('table');
    const idx = Array.prototype.indexOf.call(th.parentNode.children, th);
    const numeric = th.dataset.sort === 'num';
    const tbody = table.tBodies[0];
    const rows = Array.from(tbody.rows);
    const dir = th.dataset.dir === 'asc' ? 'desc' : 'asc';
    th.dataset.dir = dir;
    rows.sort(function (a, b) {
      let av = a.cells[idx].innerText.trim();
      let bv = b.cells[idx].innerText.trim();
      if (numeric) { av = parseFloat(av.replace(/[^0-9.\-]/g, '')) || 0;
                     bv = parseFloat(bv.replace(/[^0-9.\-]/g, '')) || 0; }
      return (av > bv ? 1 : av < bv ? -1 : 0) * (dir === 'asc' ? 1 : -1);
    });
    rows.forEach(function (r) { tbody.appendChild(r); });
  });
});
