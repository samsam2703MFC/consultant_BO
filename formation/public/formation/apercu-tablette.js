/* La vue tablette d'une SOP ou d'un article du manuel, dans le portail (07/11/2026, client : « je dois pouvoir éditer et
 * créer les SOP et articles du manuel opératoire ; tout doit être présenté comme les SOP, en vue tablette »). C'est la
 * tablette elle-même (sop/tablette.html?sop=<id>&apercu=1) dans un cadre d'appareil, en portrait (800 × 1110, la tablette
 * du magasin) ou en paysage (1280 × 800), réduite pour tenir dans l'écran : ce que l'équipe verra, exactement.
 *
 *   FormationTablette.cadre(id, {orientation, largeurMax, hauteurMax, langue}) → le HTML du cadre (dans une page) ;
 *   FormationTablette.ouvrir(id, titre) → une fenêtre (Portrait | Paysage, FR | NL, « Ouvrir la tablette ↗ », Échap).
 *   Tout bouton ou lien portant data-tbx="<id>" (et data-tbx-titre) ouvre la fenêtre : aucun branchement à écrire.
 */
(function () {
  'use strict';
  const TAILLES = { portrait: [800, 1110], paysage: [1280, 800] };
  const esc = v => String(v == null ? '' : v).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const url = (id, langue) => 'sop/tablette.html?sop=' + encodeURIComponent(id) + '&apercu=1' + (langue ? '&langue=' + langue : '');

  function cadre(id, o) {
    o = o || {};
    const [w, h] = TAILLES[o.orientation === 'paysage' ? 'paysage' : 'portrait'];
    const k = Math.min(1, (o.largeurMax || 1e4) / w, (o.hauteurMax || 1e4) / h);
    return `<div class="tbx-cadre" style="width:${Math.round(w * k)}px;height:${Math.round(h * k)}px">
      <iframe src="${esc(url(id, o.langue))}" width="${w}" height="${h}" style="transform:scale(${k})" title="Vue tablette" loading="lazy"></iframe></div>`;
  }

  let ouverte = null;
  function fermer() {
    if (!ouverte) { return; }
    ouverte.el.remove();
    document.removeEventListener('keydown', auClavier, true);
    if (ouverte.avant && ouverte.avant.focus) { try { ouverte.avant.focus(); } catch (e) { /* l'élément a pu disparaître */ } }
    ouverte = null;
  }
  function auClavier(ev) { if (ev.key === 'Escape') { ev.preventDefault(); ev.stopPropagation(); fermer(); } }
  function dessiner() {
    const o = ouverte;
    const seg = (k, v, l) => `<button type="button" data-tbxo="${k}" data-v="${v}"${o[k] === v ? ' class="is-on"' : ''}>${l}</button>`;
    o.el.querySelector('.tbx-box').innerHTML = `<div class="tbx-tete"><div class="tbx-t"><span>Vue tablette</span><b>${esc(o.titre || '')}</b></div>
        <div class="seg" role="group" aria-label="Orientation">${seg('orientation', 'portrait', 'Portrait')}${seg('orientation', 'paysage', 'Paysage')}</div>
        <div class="seg" role="group" aria-label="Langue">${seg('langue', 'fr', 'FR')}${seg('langue', 'nl', 'NL')}</div>
        <a class="btn btn--sm" href="${esc(url(o.id, o.langue).replace('&apercu=1', ''))}" target="_blank" rel="noopener">Ouvrir la tablette ↗</a>
        <button type="button" class="btn btn--icon" data-tbxo="fermer" aria-label="Fermer">✕</button></div>
      <div class="tbx-corps">${cadre(o.id, { orientation: o.orientation, langue: o.langue, largeurMax: innerWidth - 96, hauteurMax: innerHeight - 150 })}</div>`;
  }
  function ouvrir(id, titre) {
    fermer();
    const el = document.createElement('div');
    el.className = 'confirm tbx';
    el.setAttribute('role', 'dialog');
    el.setAttribute('aria-modal', 'true');
    el.setAttribute('aria-label', 'Vue tablette');
    el.innerHTML = '<div class="confirm__box card tbx-box"></div>';
    document.body.appendChild(el);
    // Portrait d'office : la tablette du magasin est tenue en portrait ; « Paysage » pour le voir couché.
    ouverte = { el, id, titre, orientation: 'portrait', langue: 'fr', avant: document.activeElement };
    el.addEventListener('click', ev => {
      if (ev.target === el) { return fermer(); }   // un clic sur le voile ferme
      const b = ev.target.closest('[data-tbxo]');
      if (!b) { return; }
      if (b.dataset.tbxo === 'fermer') { return fermer(); }
      ouverte[b.dataset.tbxo] = b.dataset.v; dessiner();
    });
    document.addEventListener('keydown', auClavier, true);
    dessiner();
    const f = el.querySelector('[data-tbxo="fermer"]'); if (f) { f.focus(); }
  }
  // Tout élément portant data-tbx ouvre la vue tablette (fiche de formation, SOP, rédaction, assistant).
  document.addEventListener('click', ev => {
    const b = ev.target.closest('[data-tbx]');
    if (!b) { return; }
    ev.preventDefault();
    ouvrir(b.getAttribute('data-tbx'), b.getAttribute('data-tbx-titre') || '');
  });
  window.FormationTablette = { cadre, ouvrir, fermer };
})();
