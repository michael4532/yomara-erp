// Yomara ERP — WhatsApp Web en affichage téléphone.
// WhatsApp Web n'accepte que les navigateurs d'ordinateur : la page est chargée en version bureau,
// puis ce script n'affiche qu'un volet à la fois (liste des discussions, ou discussion ouverte en plein écran
// avec une flèche de retour), comme l'application WhatsApp. Sans effet sur la page de connexion (QR code).
(function () {
  if (window.__ymWaMobile) return;
  window.__ymWaMobile = true;

  function ready(fn) { if (document.head && document.body) fn(); else document.addEventListener('DOMContentLoaded', fn); }

  ready(function () {
    var vp = document.querySelector('meta[name=viewport]');
    if (!vp) { vp = document.createElement('meta'); vp.name = 'viewport'; document.head.appendChild(vp); }
    vp.content = 'width=device-width, initial-scale=1, maximum-scale=1';

    var css = document.createElement('style');
    css.id = 'ym-wa-mobile';
    css.textContent = [
      'html.ym-m, html.ym-m body { min-width: 0 !important; overflow-x: hidden !important; }',
      'html.ym-m .ym-chain { min-width: 0 !important; max-width: 100vw !important; width: 100vw !important; left: 0 !important; right: 0 !important; top: 0 !important; height: 100% !important; margin: 0 !important; }',
      'html.ym-m .ym-grid { display: block !important; position: relative !important; overflow: hidden !important; }',
      'html.ym-m .ym-grid > .ym-col { position: absolute !important; top: 0 !important; bottom: 0 !important; height: 100% !important; max-width: none !important; min-width: 0 !important; flex: none !important; }',
      'html.ym-m .ym-grid > .ym-full { left: 0 !important; right: 0 !important; width: 100% !important; }',
      'html.ym-m .ym-grid > .ym-hide { display: none !important; }',
      'html.ym-m #main header { padding-left: 46px !important; }',
      '#ym-back { position: absolute; left: 4px; top: 50%; transform: translateY(-50%); width: 40px; height: 40px; border: 0; border-radius: 20px;',
      '  background: transparent; color: inherit; font-size: 24px; line-height: 40px; cursor: pointer; z-index: 5; display: none; }',
      'html.ym-m #main header #ym-back { display: block; }',
    ].join('\n');
    document.head.appendChild(css);

    var grid = null, cols = new WeakMap(), scheduled = false;

    function isOn() { return document.documentElement.classList.contains('ym-m'); }

    // Grille principale : plus petit ancêtre du volet des discussions qui a aussi un autre volet large
    function findGrid(side) {
      for (var a = side.parentElement; a && a !== document.body; a = a.parentElement) {
        var kids = a.children;
        if (kids.length < 2) continue;
        for (var i = 0; i < kids.length; i++) {
          if (!kids[i].contains(side) && kids[i].getBoundingClientRect().width > 200) return a;
        }
      }
      return null;
    }

    function classify(g, side) {
      var railW = 0;
      for (var i = 0; i < g.children.length; i++) {
        var c = g.children[i];
        var w = c.getBoundingClientRect().width;
        var kind = c.contains(side) ? 'side' : (w > 0 && w < 120 ? 'rail' : 'pane');
        if (kind === 'rail') railW = Math.max(railW, Math.round(w));
        cols.set(c, { kind: kind, w: Math.round(w) });
      }
      g.__ymRail = railW;
    }

    function apply() {
      scheduled = false;
      if (!isOn()) return;
      var side = document.getElementById('side');
      if (!side) return; // page de connexion
      if (!grid || !grid.isConnected || !grid.contains(side)) {
        if (grid) grid.classList.remove('ym-grid');
        grid = findGrid(side);
        if (!grid) return;
        classify(grid, side);
        for (var a = grid; a && a !== document.body; a = a.parentElement) a.classList.add('ym-chain');
        grid.classList.add('ym-grid');
      }
      var main = document.getElementById('main');
      var open = !!(main && grid.contains(main));
      if (open !== window.__ymOpen) {
        window.__ymOpen = open;
        try { window.flutter_inappwebview && window.flutter_inappwebview.callHandler('ymChat', open); } catch (e) {}
      }
      var rail = grid.__ymRail || 0;
      for (var i = 0; i < grid.children.length; i++) {
        var c = grid.children[i];
        var info = cols.get(c);
        if (!info) { info = { kind: 'pane' }; cols.set(c, info); } // volet apparu ensuite (infos du contact…)
        if (open && c.contains(main)) info = { kind: 'main' };
        c.classList.add('ym-col');
        var hide, full = true;
        if (info.kind === 'side') { hide = open; full = false; c.style.left = rail + 'px'; c.style.right = '0'; c.style.width = 'auto'; }
        else if (info.kind === 'rail') { hide = open; full = false; c.style.left = '0'; c.style.width = rail + 'px'; }
        else if (info.kind === 'main') { hide = false; }
        else {
          // Volet vide ou accueil « WhatsApp sur ordinateur » : masqué ; tiroirs ouverts (infos, recherche) : par-dessus
          hide = c.childElementCount === 0 || (!open && cols.get(c) && cols.get(c).w > 200 && !c.querySelector('header, [role=dialog]'));
        }
        c.classList.toggle('ym-hide', hide);
        c.classList.toggle('ym-full', full);
      }
      if (open) {
        var header = main.querySelector('header');
        if (header && !header.querySelector('#ym-back')) {
          if (getComputedStyle(header).position === 'static') header.style.position = 'relative';
          var b = document.createElement('button');
          b.id = 'ym-back';
          b.type = 'button';
          b.setAttribute('aria-label', 'Retour aux discussions');
          b.textContent = '←';
          b.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); window.__ymBack(); });
          header.insertBefore(b, header.firstChild);
        }
      }
    }

    function schedule() { if (!scheduled) { scheduled = true; requestAnimationFrame(apply); } }

    // Retour : ferme la discussion ouverte (Échap, comme sur ordinateur). Renvoie true si une discussion était ouverte.
    window.__ymBack = function () {
      if (!isOn() || !document.getElementById('main')) return false;
      var ev = { key: 'Escape', code: 'Escape', keyCode: 27, which: 27, bubbles: true, cancelable: true };
      var t = document.activeElement || document.body;
      t.dispatchEvent(new KeyboardEvent('keydown', ev));
      document.dispatchEvent(new KeyboardEvent('keydown', ev));
      setTimeout(schedule, 50);
      return true;
    };

    window.__ymSetMobile = function (on) {
      document.documentElement.classList.toggle('ym-m', !!on);
      if (!on && grid) {
        for (var i = 0; i < grid.children.length; i++) {
          var c = grid.children[i];
          c.classList.remove('ym-col', 'ym-hide', 'ym-full');
          c.style.left = c.style.right = c.style.width = '';
        }
      }
      schedule();
    };

    new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true });
    window.addEventListener('resize', schedule);
    window.__ymSetMobile(window.__ymWaMobileDefault !== false);
  });
})();
