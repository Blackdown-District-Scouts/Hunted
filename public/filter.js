// Player filter: live-search the card grids by team code/name or player name.
// Cards carry a data-filter string; we just toggle visibility, then hide any
// team/section heading left with nothing under it. Re-applies after the live
// region is swapped in by live.js.
(function () {
  var input = document.getElementById('playerfilter');
  if (!input) return;
  var root = document.getElementById('live') || document.querySelector('main');
  if (!root) return;

  function apply() {
    var q = input.value.trim().toLowerCase();

    // Individual player cards.
    root.querySelectorAll('[data-filter]').forEach(function (card) {
      var hit = !q || card.getAttribute('data-filter').indexOf(q) !== -1;
      card.classList.toggle('filterhidden', !hit);
    });

    // A team heading + its grid disappear when no card in the grid matches.
    root.querySelectorAll('.catchgrid').forEach(function (grid) {
      var visible = !!grid.querySelector('[data-filter]:not(.filterhidden)');
      grid.classList.toggle('filterhidden', !visible);
      var head = grid.previousElementSibling;
      if (head && head.classList.contains('teamhead')) {
        head.classList.toggle('filterhidden', !visible);
      }
    });

    // A section heading ("Still out" / "Checked in") hides when everything
    // under it (until the next section heading) is hidden.
    root.querySelectorAll('.sectionhead').forEach(function (sec) {
      var visible = false, el = sec.nextElementSibling;
      while (el && !el.classList.contains('sectionhead')) {
        if (!el.classList.contains('filterhidden') &&
            (el.classList.contains('catchgrid') || el.hasAttribute('data-filter'))) {
          visible = true;
          break;
        }
        el = el.nextElementSibling;
      }
      sec.classList.toggle('filterhidden', !visible);
    });
  }

  input.addEventListener('input', apply);

  // Live pages replace #live's contents on refresh — re-apply the filter then.
  var live = document.getElementById('live');
  if (live && window.MutationObserver) {
    new MutationObserver(apply).observe(live, { childList: true });
  }
})();
