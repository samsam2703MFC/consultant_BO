/* Maquette RÉELLE « Aligner les prix des magasins sur les listes des fournisseurs » (demande du 14/11/2026 : « est-ce que
 * tu peux mettre à jour les produits — avant de le faire, fais une maquette réelle en screenshot, ensuite on valide »).
 *
 *   node docs/maquettes/alignement-fournisseurs/generer.js    → a.html
 *   node docs/maquettes/alignement-fournisseurs/capturer.js   → a.png
 *
 * Données : donnees.json, lu en ligne le 14/11/2026 (lecture seule) — la liste « Recettes à corriger » (règle « prix en
 * retard sur le fournisseur », seuil 10 %) et le tableau des matières (prix de chaque magasin, liste de prix en vigueur
 * de chaque fournisseur). Rien n'est d'illustration ; RIEN N'EST ÉCRIT : c'est l'écran de ce qui le serait. */
const fs = require('fs');
const path = require('path');
const D = __dirname;
const CHROME = fs.readFileSync(path.join(D, 'chrome.html'), 'utf8');
const DATA = JSON.parse(fs.readFileSync(path.join(D, 'donnees.json'), 'utf8'));
const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const nf = (n, d) => Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d, maximumFractionDigits: d }).replace(/[  ]/g, ' ');
const px = (v, par) => v == null ? '—' : nf(v, par === 'pce' ? (v < 1 ? 4 : (v < 10 ? 3 : 2)) : (v < 0.1 ? 4 : 2)) + ' €';
const jm = s => s ? s.slice(8, 10) + '/' + s.slice(5, 7) : '';
const court = n => String(n).replace(/^.* - /, '');

const ICO = { creer: 'M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z', liens: 'M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7', modeles: 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M8 13h8M8 17h5' };
const rail = (ico, nom, on) => `<button class="sidebar__item${on ? ' is-active" aria-current="page' : ''}"><svg class="ico" viewBox="0 0 24 24"><path d="${ICO[ico]}"></path></svg><span class="sidebar__item-label">${esc(nom)}</span></button>`;
const GROUPE = `<div class="sidebar__group"><div class="sidebar__group-label">Vérification</div>${rail('liens', 'Audit des flux')}${rail('modeles', 'Matières ↔ recettes')}${rail('creer', 'Recettes à corriger', true)}</div>`;
function page(contenu){
  let h = CHROME;
  h = h.replace('<link rel="stylesheet" href="../../../public/assets/css/controles-photo.css">', '<link rel="stylesheet" href="../../../public/assets/css/verification.css">\n<link rel="stylesheet" href="al.css">');
  h = h.replace(/<title>[^<]*<\/title>/, '<title>Aligner les prix sur les fournisseurs — Cockpit CEO (maquette réelle)</title>');
  h = h.replace('class="sidebar__item is-active" aria-current="page"', 'class="sidebar__item"');
  h = h.replace(/<div class="sidebar__group">\s*<div class="sidebar__group-label">Produits<\/div>/, m => GROUPE + m);
  h = h.replace(/<h1>Résultat<\/h1>/, '<h1>Recettes à corriger</h1>');
  h = h.replace(/<div class="topbar__actions">/, '<div class="topbar__actions"><span class="al-pill">Maquette réelle · lue en ligne le 14/11 · rien n’est écrit</span>');
  h = h.replace(/<p class="shell-sub page-intro">[\s\S]*?<\/p>/, '');
  h = h.replace('class="app__body"', 'class="app__body app__body--wide"');
  return h.replace(/<div data-screen="resultat"[\s\S]*?<div id="mq-contenu"><\/div><\/div>/, contenu);
}

const MAGS = DATA.magasins;
const P = DATA.plan, X = DATA.exclus;
P.concat(X).forEach(e => { e.fournisseur = String(e.fournisseur).replace(/ · réf\. .*$/, ''); });
const nEcr = P.reduce((t, e) => t + e.shops.length, 0);
const parMag = {}; P.forEach(e => e.shops.forEach(s => { parMag[s] = (parMag[s] || 0) + 1; }));
const hausses = P.reduce((t, e) => t + e.magasins.filter(m => m.nouveau != null && m.prix != null && m.nouveau > m.prix).length, 0);
const baisses = nEcr - hausses;
const nProduits = new Set([].concat(...P.map(e => e.produits.map(p => p.pid)))).size;
const caProduits = (() => { const vu = {}; P.forEach(e => e.produits.forEach(p => { vu[p.pid] = p.ca; })); return Object.values(vu).reduce((a, b) => a + b, 0); })();

const cellule = (e, m) => {
  if (m.nouveau == null) { return m.prix == null ? '<span class="al-mu">sans prix</span>' : `<span class="al-ok">${px(m.prix * e.facteur, e.par)} ✓</span>`; }
  const v = m.prix == null ? null : 100 * (m.nouveau - m.prix) / m.prix;
  return `<span class="al-av">${m.prix == null ? 'sans prix' : px(m.prix * e.facteur, e.par)}</span><b class="al-ap">${px(m.nouveau * e.facteur, e.par)}</b>${v != null ? `<small class="${v > 0 ? 'h' : 'b'}">${v > 0 ? '+' : '−'}${nf(Math.abs(v), 0)} %</small>` : ''}`;
};
const ligne = (e, i) => {
  const p0 = e.produits.slice().sort((a, b) => b.ca - a.ca);
  const c = e.colis;
  return `<tr><td class="al-n"><input type="checkbox" checked></td><td class="g nom"><b>${esc(e.nom)}</b><small>${esc(e.cat)} · € / ${esc(e.par)}</small></td>
    <td class="g al-f"><b>${esc(e.fournisseur)}</b><small>${c ? 'colis de ' + nf(c.taille || 1, 0) + ' ' + esc(c.unite || '') + (c.parColis > 1 ? ' × ' + nf(c.parColis, 0) : '') + ' à ' + nf(c.prix, 2) + ' € HT · ' : ''}en vigueur depuis le ${jm(e.depuis)}</small></td>
    ${MAGS.map(m => `<td class="al-c">${cellule(e, e.magasins.find(x => x.id === m.id) || {})}</td>`).join('')}
    <td class="g al-p">${p0.slice(0, 2).map(p => `<div><b>${esc(p.nom)}</b> <span class="al-mu">${esc(p.magasin)} : ${p.avant != null ? nf(p.avant, 2) + ' → ' + nf(p.apres, 2) + ' €' : ''}</span></div>`).join('')}${p0.length > 2 ? `<div class="al-mu">+ ${p0.length - 2} autre${p0.length > 3 ? 's' : ''}</div>` : ''}</td>
    <td>${nf(e.ca, 0)} €</td></tr>`;
};
const exclu = e => `<tr><td class="g nom"><b>${esc(e.nom)}</b><small>${esc(e.cat)} · € / ${esc(e.par)}</small></td><td class="g">${esc(e.fournisseur)}</td>
  <td>${px((e.magasins.find(m => m.prix != null) || {}).prix * e.facteur, e.par)}</td><td>${px(e.ref * e.facteur, e.par)}</td><td><span class="al-x">× ${nf(e.ratio, 2)}</span></td>
  <td class="g al-mu">${e.produits.slice(0, 2).map(p => esc(p.nom)).join(', ')}${e.produits.length > 2 ? '…' : ''}</td></tr>`;

const contenu = `<div class="vr al">
  <div class="vr-haut"><div class="vm-bascule"><button>Recettes à corriger</button><button class="on">Matières premières</button></div><span class="vr-sp"></span><span class="vr-lu">Lu en ligne le 14/11 · couverture ${nf(DATA.couverture, 1)} %</span></div>
  <div class="vr-card">
    <div class="al-t"><h2>Aligner les prix des magasins sur les listes des fournisseurs</h2>
      <p>Chaque matière dont le prix, dans un magasin, s’écarte de plus de 10 % de la liste de prix <b>en vigueur</b> de son fournisseur (règle « prix en retard sur le fournisseur »). Une coche par matière : décochée, elle ne part pas. Les écarts de plus de ×2 sont mis de côté (colis à vérifier).</p></div>
    <div class="vm-kpis">
      <div class="vm-kpi"><div class="k">Matières à aligner</div><div class="v">${P.length}</div><div class="s">${new Set(P.map(e => e.fournisseur)).size} fournisseurs : ${[...new Set(P.map(e => e.fournisseur))].map(esc).join(', ')}</div></div>
      <div class="vm-kpi att"><div class="k">Prix à écrire</div><div class="v">${nEcr}</div><div class="s">${MAGS.map(m => court(m.nom) + ' ' + (parMag[m.id] || 0)).join(' · ')}</div></div>
      <div class="vm-kpi"><div class="k">Sens</div><div class="v">${hausses} ↑ · ${baisses} ↓</div><div class="s">hausses : le magasin payait moins que la liste ; baisses : plus</div></div>
      <div class="vm-kpi"><div class="k">Produits touchés</div><div class="v">${nProduits}</div><div class="s">${nf(caProduits, 0)} € de CA sur 30 jours</div></div>
      <div class="vm-kpi ko"><div class="k">Mis de côté</div><div class="v">${X.length}</div><div class="s">écart de plus de ×2 : colis ou unité à vérifier</div></div>
    </div>
    <div class="vr-tw"><table class="vr-t al-t2"><thead><tr><th class="g" style="width:26px"><input type="checkbox" checked></th><th class="g">Matière</th><th class="g">Fournisseur<small>liste de prix en vigueur</small></th>${MAGS.map(m => `<th>${esc(court(m.nom))}<small>avant → après</small></th>`).join('')}<th class="g">Produits touchés<small>coût de la pièce, avant → après</small></th><th>CA<small>30 jours</small></th></tr></thead>
      <tbody>${P.map(ligne).join('')}</tbody></table></div>
    <div class="al-sec"><h3>Mis de côté : colis ou unité à vérifier (${X.length})</h3><p class="al-mu">Un écart de plus de ×2 entre le magasin et la liste n’est pas un retard de prix : le colis ou l’unité est mal décrit au panel. Rien ne part pour ces matières ; à revoir dans leur fiche.</p>
      <table class="vr-t"><thead><tr><th class="g">Matière</th><th class="g">Fournisseur</th><th>Magasin</th><th>Liste</th><th>Écart</th><th class="g">Produits</th></tr></thead><tbody>${X.map(exclu).join('')}</tbody></table></div>
    <div class="rc-barre al-barre"><div><b>${P.length} matières cochées — ${nEcr} prix à écrire</b><small>Une écriture par matière et par magasin (le prix du magasin, route du registre : POST /materials/{mid}/manual-price). Chacune est relue ; une écriture refusée ou sans effet arrête la matière, pas les autres. Tout va au registre des corrections, et chaque prix remplacé s’annule (« Annuler » remet l’ancien).</small></div><span class="vr-sp"></span><button class="rc-btn gris">Annuler</button><button class="rc-btn">Vérifier ce qui sera écrit</button></div>
  </div>
</div>`;

fs.writeFileSync(path.join(D, 'a.html'), page(contenu));
fs.writeFileSync(path.join(D, 'capturer.js'), `/* Capture de la maquette réelle « Aligner les prix sur les fournisseurs » (pleine page, 1700 px).
 *   php -S 127.0.0.1:8099 -t .   (depuis pilotage-reseau/) ;   node docs/maquettes/alignement-fournisseurs/capturer.js */
const path = require('path');
let pw; try { pw = require('playwright'); } catch (e) { pw = require('/opt/node22/lib/node_modules/playwright'); }
(async () => {
  const b = await pw.chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost', '--no-proxy-server'] });
  const p = await b.newPage({ viewport: { width: 1700, height: 1000 }, deviceScaleFactor: 2 });
  const err = []; p.on('pageerror', e => err.push(e.message)); p.on('requestfailed', r => err.push('échec ' + r.url()));
  await p.goto('http://127.0.0.1:8099/docs/maquettes/alignement-fournisseurs/a.html', { waitUntil: 'networkidle' });
  await p.addStyleTag({ content: 'html,body,#app,.shell,.workspace,.workspace__main,.app__body{height:auto!important;max-height:none!important;overflow:visible!important}' });
  await p.evaluate(() => document.fonts.ready);
  await p.screenshot({ path: path.join(__dirname, 'a.png'), fullPage: true });
  await p.screenshot({ path: path.join(__dirname, 'b.png'), clip: { x: 246, y: 0, width: 1454, height: 1300 } });
  console.log('erreurs', err.length ? err : 'aucune');
  await b.close();
})();
`);
console.log('a.html, capturer.js —', P.length, 'matières,', nEcr, 'écritures,', X.length, 'mises de côté');
