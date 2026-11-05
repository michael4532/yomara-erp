<script>
(() => {
  // Visite du logiciel : onglets accessibles (clic, flèches)
  const tabs = [...document.querySelectorAll('.tab')], imgs = [...document.querySelectorAll('.viewer img')];
  const select = t => { tabs.forEach(x => x.setAttribute('aria-selected', x === t ? 'true' : 'false')); imgs.forEach(i => i.classList.toggle('on', i.dataset.img === t.dataset.img)); };
  tabs.forEach((t, i) => {
    t.addEventListener('click', () => select(t));
    t.addEventListener('keydown', e => { const d = { ArrowDown: 1, ArrowRight: 1, ArrowUp: -1, ArrowLeft: -1 }[e.key]; if (d) { e.preventDefault(); const n = tabs[(i + d + tabs.length) % tabs.length]; n.focus(); select(n); } });
  });
  // Téléchargement de l'appareil du visiteur mis en avant
  const ua = navigator.userAgent, p = (navigator.userAgentData && navigator.userAgentData.platform) || navigator.platform || '';
  const os = /android/i.test(ua) ? 'android' : (/iphone|ipad|ipod/i.test(ua) || (/Mac/.test(p) && navigator.maxTouchPoints > 1)) ? 'ios' : /win/i.test(p) ? 'windows' : /mac/i.test(p) ? 'mac' : /linux/i.test(p) ? 'linux' : null;
  const el = os && document.querySelector('.dl-list li[data-os="' + os + '"]'); if (el) el.classList.add('mine');
  const calm = matchMedia('(prefers-reduced-motion: reduce)').matches;
  // Apparition au défilement
  const io = 'IntersectionObserver' in window ? new IntersectionObserver(es => es.forEach(e => { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); } }), { threshold: .12, rootMargin: '0px 0px -40px 0px' }) : null;
  document.querySelectorAll('.rv').forEach(e => io ? io.observe(e) : e.classList.add('in'));
  document.querySelectorAll('.mods .mod').forEach((m, i) => m.style.setProperty('--d', (i % 3) * .08 + 's'));
  document.querySelectorAll('.facts .fact').forEach((m, i) => m.style.setProperty('--d', i * .1 + 's'));
  // Compteur
  document.querySelectorAll('[data-count]').forEach(b => { if (calm) return; const n = +b.dataset.count; let t0; const step = t => { t0 ??= t; const k = Math.min(1, (t - t0) / 1200); b.textContent = Math.round(n * (1 - Math.pow(1 - k, 3))).toLocaleString('fr-FR').replace(/\u202f/g, ' ') + (b.dataset.suffix || ''); if (k < 1) requestAnimationFrame(step); }; const o = new IntersectionObserver(es => { if (es[0].isIntersecting) { o.disconnect(); requestAnimationFrame(step); } }); o.observe(b); });
  // Plateformes du haut : chacune s'allume à tour de rôle
  const plats = [...document.querySelectorAll('.platforms li')]; let pi = 0;
  if (!calm && plats.length) setInterval(() => { plats.forEach(p => p.classList.remove('lit')); plats[pi++ % plats.length].classList.add('lit'); }, 1400);
  // Tarifs : au mois / à l'année
  document.querySelectorAll('.billing button').forEach(b => b.addEventListener('click', () => {
    document.querySelectorAll('.billing button').forEach(x => x.setAttribute('aria-checked', x === b ? 'true' : 'false'));
    const y = b.dataset.b === 'y'; document.querySelectorAll('.plan .m').forEach(e => e.hidden = y); document.querySelectorAll('.plan .y').forEach(e => e.hidden = !y);
  }));
})();
</script>
