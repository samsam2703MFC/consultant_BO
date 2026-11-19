/* La coque des écrans tablette (1280 × 800, paysage) : l'onglet « SOP » de l'app Cuisine (pwa_kitchen), à côté de
   Production du jour, Checklists et Produits et recettes. Au comptoir, la même chose sur la tablette des vendeuses.
   On s'identifie comme pour les checklists : son nom, puis son PIN (panel, /shops/{id}/employees). */
function tablette(corps, opts) {
  opts = opts || {};
  const L = opts.langue || 'FR';
  const ong = L === 'NL' ? ['Productie van vandaag', 'Checklists', 'Producten en recepten', 'SOP'] : ['Production du jour', 'Checklists', 'Produits et recettes', 'SOP'];
  const qui = opts.qui ? `<span class="moi" style="white-space:nowrap"><i>${opts.qui.split(' ').map(x => x[0]).join('')}</i>${esc(opts.qui)}</span>` : `<span class="moi" style="color:var(--color-text-muted)">${L === 'NL' ? 'Niet aangemeld' : 'Personne d’identifié'}</span>`;
  document.body.innerHTML = `<div class="tab-app">
    <div class="tab-hd" style="gap:20px"><img src="/public/assets/img/logo.png" style="height:30px"><div class="t" style="font-size:20px">${L === 'NL' ? 'Keuken' : 'Cuisine'} · Halle<small>${esc(opts.sous || 'tablette du poste de cuisson')}</small></div>
      <div style="display:flex;gap:6px;margin-left:10px">${ong.map((o, i) => `<span style="padding:9px 12px;border-radius:10px;font:600 13.5px var(--font-ui);white-space:nowrap;${i === 3 ? 'background:#EFE3DE;color:var(--color-primary)' : 'color:var(--color-text-muted)'}">${o}</span>`).join('')}</div>
      <span class="sp"></span><span class="bascule"><span class="${L === 'FR' ? 'on' : ''}">FR</span><span class="${L === 'NL' ? 'on' : ''}">NL</span></span>${qui}</div>
    ${corps}</div>`;
}
const pave = (n, total) => `<div style="display:flex;gap:12px;justify-content:center;margin:6px 0 14px">${Array.from({ length: total || 4 }, (_, i) => `<span style="width:16px;height:16px;border-radius:50%;${i < n ? 'background:#222' : 'border:2px solid #bbb'}"></span>`).join('')}</div>
  <div style="display:grid;grid-template-columns:repeat(3,72px);gap:10px;justify-content:center">${['1', '2', '3', '4', '5', '6', '7', '8', '9', '⌫', '0', '✓'].map(k => `<span style="height:56px;border-radius:14px;display:flex;align-items:center;justify-content:center;font:600 22px var(--font-ui);${k === '✓' ? 'background:var(--color-primary);color:#fff' : 'background:#F3EEE7'}">${k}</span>`).join('')}</div>`;
