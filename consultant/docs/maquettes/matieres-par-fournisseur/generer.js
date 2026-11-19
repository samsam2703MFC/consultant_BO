/* Maquette réelle « Matières premières : par fournisseur ou par catégorie, le prix du fournisseur face à chaque magasin »
 * (demande du 14/11/2026 : « un tableau avec les matières premières, sélection soit par fournisseur soit par catégorie,
 * avec le prix marqué fournisseur + celui de chaque magasin »).
 *
 *   node docs/maquettes/matieres-par-fournisseur/generer.js    → a.html (par fournisseur : SLFood), b.html (par catégorie)
 *   node docs/maquettes/matieres-par-fournisseur/capturer.js   → a.png, b.png
 *
 * Données : donnees.json, lu en ligne le 14/11/2026 (lecture seule) par GET /verification/recettes/matieres : les 400
 * matières des recettes vendues, leurs fournisseurs liés (référence, colis, liste en vigueur), le prix de chaque magasin. */
const fs = require('fs');
const path = require('path');
const D = __dirname;
const CHROME = fs.readFileSync(path.join(D, 'chrome.html'), 'utf8');
const DATA = JSON.parse(fs.readFileSync(path.join(D, 'donnees.json'), 'utf8'));
const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const nf = (n, d) => Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d, maximumFractionDigits: d }).replace(/[  ]/g, ' ');
const px = (v, par) => v == null ? '—' : nf(v, par === 'pce' ? (v < 1 ? 4 : (v < 10 ? 3 : 2)) : (v < 0.1 ? 4 : 2)) + ' €';
const jm = s => s ? s.slice(8, 10) + '/' + s.slice(5, 7) + '/' + s.slice(2, 4) : '';
const court = n => String(n).replace(/^.* - /, '');

const ICO = { creer: 'M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z', liens: 'M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7', modeles: 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M8 13h8M8 17h5' };
const rail = (ico, nom, on) => `<button class="sidebar__item${on ? ' is-active" aria-current="page' : ''}"><svg class="ico" viewBox="0 0 24 24"><path d="${ICO[ico]}"></path></svg><span class="sidebar__item-label">${esc(nom)}</span></button>`;
const GROUPE = `<div class="sidebar__group"><div class="sidebar__group-label">Vérification</div>${rail('liens', 'Audit des flux')}${rail('modeles', 'Matières ↔ recettes')}${rail('creer', 'Recettes à corriger', true)}</div>`;
function page(contenu){
  let h = CHROME;
  h = h.replace('<link rel="stylesheet" href="../../../public/assets/css/controles-photo.css">', '<link rel="stylesheet" href="../../../public/assets/css/verification.css">\n<link rel="stylesheet" href="mf.css">');
  h = h.replace(/<title>[^<]*<\/title>/, '<title>Matières par fournisseur ou catégorie — Cockpit CEO (maquette réelle)</title>');
  h = h.replace('class="sidebar__item is-active" aria-current="page"', 'class="sidebar__item"');
  h = h.replace(/<div class="sidebar__group">\s*<div class="sidebar__group-label">Produits<\/div>/, m => GROUPE + m);
  h = h.replace(/<h1>Résultat<\/h1>/, '<h1>Recettes à corriger</h1>');
  h = h.replace(/<div class="topbar__actions">/, '<div class="topbar__actions"><span class="mf-pill">Maquette réelle · lue en ligne le 14/11 · rien n’est écrit</span>');
  h = h.replace(/<p class="shell-sub page-intro">[\s\S]*?<\/p>/, '');
  h = h.replace('class="app__body"', 'class="app__body app__body--wide"');
  return h.replace(/<div data-screen="resultat"[\s\S]*?<div id="mq-contenu"><\/div><\/div>/, contenu);
}

const MAGS = DATA.magasins;
const L = DATA.lignes;
const fournisseurs = (() => { const c = {}; L.forEach(l => { const fs2 = [...new Set(l.fournisseurs.map(f => f.nom))]; if (!fs2.length) { c['Aucun fournisseur lié'] = (c['Aucun fournisseur lié'] || 0) + 1; } fs2.forEach(f => { c[f] = (c[f] || 0) + 1; }); }); return Object.entries(c).sort((a, b) => b[1] - a[1]); })();
const categories = (() => { const c = {}; L.forEach(l => { c[l.cat] = (c[l.cat] || 0) + 1; }); return Object.entries(c).sort((a, b) => b[1] - a[1]); })();

/** Le prix de référence d'une matière : la liste en vigueur du fournisseur, sinon le prix général du panel. */
const reference = (l, fNom) => {
  const fs2 = l.fournisseurs.filter(f => !fNom || f.nom === fNom);
  const f = fs2.find(x => x.enVigueur && x.prix) || fs2.find(x => x.enVigueur) || fs2[0] || null;
  if (f && f.prix) { return { prix: f.prix, f, quoi: 'liste' }; }
  if (l.general) { return { prix: l.general, f, quoi: 'general' }; }
  return { prix: null, f, quoi: f ? (f.conversion ? 'conversion' : (f.enVigueur ? 'sansprix' : 'nonlue')) : 'aucun' };
};
const cellule = (l, m, ref) => {
  if (m.prix == null) { return '<span class="mf-c ko">sans prix</span>'; }
  const v = ref.prix ? 100 * (m.prix - ref.prix) / ref.prix : null;
  const ton = v == null ? '' : (Math.abs(v) <= 10 ? 'ok' : (Math.abs(v) > 100 ? 'ko' : 'att'));
  return `<span class="mf-c ${ton}">${px(m.prix * l.facteur, l.par)}${v != null ? `<small>${Math.abs(v) <= 10 ? '✓ ' : ''}${v > 0 ? '+' : (v < 0 ? '−' : '')}${nf(Math.abs(v), 0)} %</small>` : ''}</span>`;
};
const ligne = (l, fNom, montrerF) => {
  const ref = reference(l, fNom);
  const f = ref.f;
  const fTxt = f ? `<b>${esc(f.nom)}</b><small>${f.colis ? 'colis ' + nf(f.colis.taille || 1, 0) + ' ' + esc(f.colis.unite || '') + (f.colis.parColis > 1 ? ' × ' + nf(f.colis.parColis, 0) : '') + ' · ' + nf(f.colis.prix, 2) + ' € HT' : (f.listeLue ? 'pas de prix en vigueur' : 'liste non lue')}${f.depuis ? ' · depuis le ' + jm(f.depuis) : ''}</small>` : '<span class="mf-mu">aucun fournisseur lié</span>';
  const refTxt = ref.prix ? `<b>${px(ref.prix * l.facteur, l.par)}</b><small>${ref.quoi === 'general' ? 'prix général du panel' : 'liste du fournisseur'}</small>`
    : `<span class="mf-mu">${{ conversion: 'colis en pièces : poids à poser', sansprix: 'pas de prix en vigueur', nonlue: 'liste non lue', aucun: '—' }[ref.quoi] || '—'}</span>`;
  const ecarts = l.magasins.filter(m => m.prix != null && ref.prix).map(m => Math.abs(100 * (m.prix - ref.prix) / ref.prix));
  const sans = l.magasins.filter(m => m.prix == null).length;
  const etat = !ref.prix ? (sans ? 'ko' : 'mu') : (sans || ecarts.some(e => e > 10) ? (ecarts.some(e => e > 100) ? 'ko' : 'att') : 'ok');
  return `<tr><td class="mf-e ${etat}"></td><td class="g nom"><b>${esc(l.nom)}</b><small>€ / ${esc(l.par)}${l.source === 'CENTRAL' ? ' · centrale' : ' · libre'}</small></td>
    ${montrerF ? `<td class="g mf-f">${fTxt}</td>` : ''}<td class="mf-r">${refTxt}</td>
    ${MAGS.map(m => `<td>${cellule(l, l.magasins.find(x => x.id === m.id) || {}, ref)}</td>`).join('')}
    <td class="mf-mu">${l.nRecettes} · ${nf(l.ca, 0)} €</td><td><button class="mf-b">Fiche</button></td></tr>`;
};

function ecran(mode, choix){
  const lignes = mode === 'fournisseur'
    ? L.filter(l => choix === 'Aucun fournisseur lié' ? !l.fournisseurs.length : l.fournisseurs.some(f => f.nom === choix))
    : L.filter(l => l.cat === choix);
  // Par fournisseur : groupées par catégorie ; par catégorie : groupées par fournisseur.
  const cle = l => mode === 'fournisseur' ? l.cat : ((l.fournisseurs[0] || {}).nom || 'Aucun fournisseur lié');
  const groupes = {}; lignes.forEach(l => { (groupes[cle(l)] = groupes[cle(l)] || []).push(l); });
  const ordre = Object.keys(groupes).sort((a, b) => groupes[b].reduce((t, l) => t + l.ca, 0) - groupes[a].reduce((t, l) => t + l.ca, 0));
  const fNom = mode === 'fournisseur' ? choix : null;
  const n = c => lignes.filter(l => { const r = reference(l, fNom); const e = l.magasins.filter(m => m.prix != null && r.prix).map(m => Math.abs(100 * (m.prix - r.prix) / r.prix)); return c(r, e, l); }).length;
  const kpis = [['Matières', lignes.length, (mode === 'fournisseur' ? 'chez ' + choix : 'dans « ' + choix + ' »'), ''],
    ['Alignées', n((r, e, l) => r.prix && !l.magasins.some(m => m.prix == null) && e.every(x => x <= 10)), 'les 4 magasins à 10 % près du prix de référence', 'ok'],
    ['Écart > 10 %', n((r, e) => r.prix && e.some(x => x > 10 && x <= 100)), 'au moins un magasin s’écarte', 'att'],
    ['Sans prix en magasin', n((r, e, l) => l.magasins.some(m => m.prix == null)), 'au moins un magasin ne la chiffre pas', 'ko'],
    ['Sans prix de référence', n(r => !r.prix), 'pas de liste en vigueur ni de prix général', 'mu']];
  const montrerF = mode === 'categorie';
  const sel = mode === 'fournisseur'
    ? `<select class="vr-sel mf-sel"><option>${esc(choix)} (${(fournisseurs.find(f => f[0] === choix) || [])[1]})</option>${fournisseurs.filter(f => f[0] !== choix).map(f => `<option>${esc(f[0])} (${f[1]})</option>`).join('')}</select><select class="vr-sel"><option>Toutes les catégories</option></select>`
    : `<select class="vr-sel"><option>Tous les fournisseurs</option></select><select class="vr-sel mf-sel"><option>${esc(choix)} (${(categories.find(c => c[0] === choix) || [])[1]})</option></select>`;
  const head = `<tr><th style="width:8px"></th><th class="g">Matière</th>${montrerF ? '<th class="g">Fournisseur<small>référence, colis, liste en vigueur</small></th>' : ''}<th>Prix fournisseur<small>par unité, hors TVA</small></th>${MAGS.map(m => `<th>${esc(court(m.nom))}<small>prix du magasin · écart</small></th>`).join('')}<th>Recettes<small>n · CA 30 j</small></th><th></th></tr>`;
  const ncol = 6 + MAGS.length + (montrerF ? 1 : 0);
  return `<div class="vr mf">
  <div class="vr-haut"><div class="vm-bascule"><button>Recettes à corriger</button><button class="on">Matières premières</button></div><span class="vr-sp"></span><span class="vr-lu">Lu en ligne le 14/11 · 400 matières dans les recettes vendues</span></div>
  <div class="vr-card">
    <div class="vr-fil mf-fil"><div class="vm-bascule"><button class="${mode === 'fournisseur' ? 'on' : ''}">Par fournisseur</button><button class="${mode === 'categorie' ? 'on' : ''}">Par catégorie</button></div>${sel}<input class="vm-q" placeholder="Chercher une matière…"><span class="vr-sp"></span><button class="vr-btn">Exporter</button></div>
    <div class="vm-kpis">${kpis.map(k => `<div class="vm-kpi ${k[3]}"><div class="k">${esc(k[0])}</div><div class="v">${k[1]}</div><div class="s">${esc(k[2])}</div></div>`).join('')}</div>
    <div class="vr-tw"><table class="vr-t mf-t"><thead>${head}</thead><tbody>
    ${ordre.map(g => `<tr class="mf-g"><td colspan="${ncol}">${esc(g)} <span>${groupes[g].length} matière${groupes[g].length > 1 ? 's' : ''} · ${nf(groupes[g].reduce((t, l) => t + l.ca, 0), 0)} € de CA</span></td></tr>${groupes[g].sort((a, b) => b.ca - a.ca).map(l => ligne(l, fNom, montrerF)).join('')}`).join('')}
    </tbody></table></div>
    <div class="vr-pied"><b>Le prix fournisseur</b> : la liste de prix en vigueur de la référence liée à la matière au panel (prix du colis ÷ taille), sinon le prix général du panel. <b>Chaque magasin</b> : son prix, et son écart au prix fournisseur (✓ à 10 % près, orange au-delà, rouge au-delà de ×2 ou sans prix). « Fiche » ouvre la fiche de la matière (paramètres et prix, modifiables).</div>
  </div></div>`;
}

fs.writeFileSync(path.join(D, 'a.html'), page(ecran('fournisseur', 'SLFood')));
fs.writeFileSync(path.join(D, 'b.html'), page(ecran('categorie', 'Matières premières (laitier)')));
fs.writeFileSync(path.join(D, 'mf.css'), `/* Maquette réelle « Matières par fournisseur ou catégorie » (14/11/2026) : s'ajoute à verification.css. */
.mf-pill{display:inline-block;font:400 10.5px var(--font-ui);letter-spacing:.08em;text-transform:uppercase;background:#8D1D2C;color:#fff;border-radius:999px;padding:5px 12px}
.mf-fil{gap:12px;padding:12px 18px;border-bottom:1px solid var(--vr-trait)}
.mf-sel{font-weight:400;border-color:var(--color-text)}
.mf-t td{vertical-align:middle}
.mf-t tr.mf-g>td{background:var(--table-head);font:400 11px var(--font-ui);letter-spacing:.06em;text-transform:uppercase;color:var(--color-primary);text-align:left;padding:10px 12px 6px}
.mf-t tr.mf-g>td span{text-transform:none;letter-spacing:0;color:var(--color-text-muted);margin-left:8px}
.mf-t td.mf-e{width:6px;padding:0!important}
.mf-t td.mf-e.ok{background:var(--vr-ok)}.mf-t td.mf-e.att{background:#E8A33D}.mf-t td.mf-e.ko{background:var(--vr-ko)}.mf-t td.mf-e.mu{background:#cfc8bf}
.mf-f{white-space:normal!important;max-width:230px;font-size:11.5px;line-height:1.35}
.mf-f b{font-weight:400}.mf-f small,.mf-r small{display:block;font-size:10.5px;color:var(--color-text-muted)}
.mf-r b{font-weight:400}
.mf-c{display:inline-block;min-width:70px;padding:2px 7px;border-radius:6px;text-align:right;font-size:12px}
.mf-c small{display:block;font-size:10px}
.mf-c.ok{color:var(--vr-ok)}.mf-c.att{color:var(--vr-att);background:var(--prize-soft)}.mf-c.ko{color:var(--vr-ko);background:var(--stop-soft)}
.mf-mu{color:var(--color-text-muted);font-size:11.5px}
.mf-b{font:400 11px var(--font-ui);border:1px solid var(--color-border-secondary);border-radius:6px;padding:3px 9px;background:#fff;cursor:pointer}
`);
fs.writeFileSync(path.join(D, 'capturer.js'), `/* Captures de la maquette réelle « Matières par fournisseur ou catégorie ».
 *   php -S 127.0.0.1:8099 -t .   (depuis pilotage-reseau/) ;   node docs/maquettes/matieres-par-fournisseur/capturer.js */
const path = require('path');
let pw; try { pw = require('playwright'); } catch (e) { pw = require('/opt/node22/lib/node_modules/playwright'); }
(async () => {
  const b = await pw.chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost', '--no-proxy-server'] });
  const p = await b.newPage({ viewport: { width: 1700, height: 1500 }, deviceScaleFactor: 2 });
  const err = []; p.on('pageerror', e => err.push(e.message)); p.on('requestfailed', r => err.push('échec ' + r.url()));
  for (const m of ['a', 'b']) {
    await p.goto('http://127.0.0.1:8099/docs/maquettes/matieres-par-fournisseur/' + m + '.html', { waitUntil: 'networkidle' });
    await p.evaluate(() => document.fonts.ready);
    await p.screenshot({ path: path.join(__dirname, m + '.png') });
  }
  console.log('erreurs', err.length ? err : 'aucune');
  await b.close();
})();
`);
console.log('a.html (SLFood,', L.filter(l => l.fournisseurs.some(f => f.nom === 'SLFood')).length, 'matières), b.html (laitier)');
