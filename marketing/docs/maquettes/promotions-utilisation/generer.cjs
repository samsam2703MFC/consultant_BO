/* Maquettes « Tableau d'analyse : quelle promotion, dans quel magasin, quel % du CA général » (26/10/2026).
 * Les quatre formes de docs/maquettes/promotions-analyse sont écartées (« aucun, recommence ») ; la demande : « je veux
 * savoir quelle promotion est utilisée dans quel magasin et quel est le % sur le CA général ». Quatre formes :
 *   A — la matrice : promotions × magasins, la case = ce qu'elle pèse dans le CA du magasin ;
 *   B — promotion par promotion : sa barre en % du CA général, découpée par magasin ;
 *   C — magasin par magasin : ses promotions et ce que chacune pèse dans son CA ;
 *   D — une promotion à la fois : la liste à gauche, la promotion choisie magasin par magasin à droite.
 * Données illustrées (docs/maquettes/promotions-cockpit/donnees.cjs, plus un bon de la marque en caisse dans cinq
 * magasins et sans vente dans deux) ; la coque et le rail sont ceux du cockpit en ligne.
 *
 *   php -S 127.0.0.1:8099 -t .                                 (à la racine du dépôt)
 *   node docs/maquettes/promotions-utilisation/generer.cjs     → index.html, a.png … d.png, v-*.png, planche.png
 */
const fs = require('fs');
let pw;
try { pw = require('playwright'); } catch (e) { pw = require('/opt/node22/lib/node_modules/playwright'); }
const D = require('../promotions-cockpit/donnees.cjs');
const ICI = 'http://127.0.0.1:8099/docs/maquettes/promotions-utilisation/';
const OUT = __dirname + '/';

const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const nf = (n, d) => Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d || 0, maximumFractionDigits: d || 0 }).replace(/ /g, ' ');
const fE = n => nf(Math.round(n)) + ' €';
const fP = (v, d) => nf(v, d == null ? (v < 1 ? 2 : 1) : d) + ' %';
// Une seule teinte, du clair au foncé (validée : node …/dataviz/scripts/validate_palette.js --ordinal) ; le gris chaud
// pour le contexte. Les deux plus claires portent un texte encre, les trois foncées un texte blanc.
const RAMPE = ['#d9a3ad', '#c27583', '#a8495a', '#8d1d2c', '#5e0f1b'];
const GRIS = '#d9d0c4', RUBIS = '#8d1d2c', ENCRE = '#222', MUET = '#6b625a', AMBRE = '#8a5a13';

/* --- 1. Les données : chaque promotion vendue sur 30 jours, magasin par magasin -------------------------------------- */
const MAGS = D.MAGS, CA30 = D.CA30;
const AUTOMNE = { src: 'marque', mags: ['Gosselies', 'Halle', 'Nivelles', 'Liège', 'Wavre'], nom: 'Bon AUTOMNE · −10 % dès 20 €', bon: 'AUTOMNE', statut: 'en_caisse', du: '05/10', au: '15/11', fois: 24, web: 58, ventes: 640, remise: 64, par: [12, 0, 7, 0, 5] };
const CAG = MAGS.reduce((a, m) => a + CA30[m], 0);              // le CA général : tout ce que les neuf magasins ont vendu
const PR = D.R.concat([AUTOMNE]).filter(r => r.fois && r.ventes).map(r => {
  const ou = r.src === 'marque' ? r.mags : [r.mag], par = {};
  ou.forEach((m, i) => { const f = r.src === 'marque' ? r.par[i] : r.fois; par[m] = { fois: f, v: r.ventes * f / r.fois, pc: 100 * (r.ventes * f / r.fois) / CA30[m] }; });
  return { nom: r.nom, src: r.src, bon: r.bon, statut: r.statut, du: r.du, au: r.au, fois: r.fois, v: r.ventes, remise: r.remise, web: r.web,
    achete: r.achete, recoit: (r.recoit || '').replace(/<\/?sup>/g, ''), par, ou, pG: 100 * r.ventes / CAG,
    sans: ou.filter(m => !par[m].fois) };
}).sort((a, b) => b.v - a.v);
const TOT = PR.reduce((a, p) => a + p.v, 0), TOTF = PR.reduce((a, p) => a + p.fois, 0);
const MG = MAGS.map(m => {
  const L = PR.filter(p => p.par[m]).map(p => Object.assign({ p }, p.par[m])).sort((a, b) => b.v - a.v);
  const v = L.reduce((a, x) => a + x.v, 0);
  return { m, ca: CA30[m], v, pc: 100 * v / CA30[m], pG: 100 * v / CAG, L, n: L.filter(x => x.fois).length };
}).sort((a, b) => b.pc - a.pc);
const SANS = PR.filter(p => p.sans.length);
const COURT = { Gosselies: 'Goss.', Halle: 'Halle', Nivelles: 'Niv.', Liège: 'Liège', Namur: 'Nam.', Mons: 'Mons', Waterloo: 'Wat.', Wavre: 'Wav.', Uccle: 'Uccle' };
const marque = p => p.src === 'marque' ? '<em class="an-or m">marque</em>' : '<em class="an-or">magasin</em>';
const etat = p => p.statut === 'terminee' ? ' <em class="an-or t">terminée</em>' : '';
const ouTexte = p => p.src === 'mag' ? esc(p.ou[0]) : p.ou.length === MAGS.length && !p.sans.length ? 'les 9 magasins' : `${p.ou.length - p.sans.length} magasins sur ${p.ou.length}`;

/* --- 2. La coque du cockpit (le rail en ligne : Promotions au même niveau que Produits) ---------------------------- */
const navSt = (a, ind) => 'display:flex;align-items:center;justify-content:space-between;gap:8px;width:100%;text-align:left;border:none;font-family:var(--font-ui);font-size:' + (ind ? '12.5px' : '13px') + ';padding:' + (ind ? '7px 10px 7px 24px' : '8px 10px') + ';border-radius:8px;font-weight:300;' + (a ? 'background:rgba(141,29,44,0.08);color:var(--color-primary);font-weight:500' : 'background:transparent;color:var(--color-text)');
const badge = b => b ? `<span style="min-width:18px;height:18px;padding:0 5px;border-radius:999px;background:var(--color-primary);color:#fff;font-size:10px;font-weight:500;display:inline-flex;align-items:center;justify-content:center">${b}</span>` : '';
const sous = l => `<span style="display:flex;align-items:center;gap:7px"><span style="font-size:9px;color:var(--color-text-muted);width:8px;display:inline-block">▸</span>${esc(l)}</span>`;
function rail(){
  const SEC = [['Pilotage', [['Résultat'], ['Dashboard magasin ↗']]], ['Franchisés · évaluation et suivi', [['Fiche & scoring'], ['Suivi des franchisés', 0, 1], ['Budget', 0, 1], ['Suivi journalier et opérations', 0, 1]]],
    ['Magasins', [['Analyse magasin'], ['Invendus et poubelle'], ['Inventaires'], ['Scouting — où ouvrir']]], ['Produits', [['Catalogue'], ['Comptoir · planogramme']]],
    ['Promotions', [['Édition', 3], ['Tableau d’analyse', 2, 0, 1]]], ['Marque & marketing', [['Marketing ↗'], ['Projets de développement'], ['Fonds & Royalties']]],
    ['ERP franchisé', [['Prospection ↗'], ['Tablette vendeuses'], ['Fidélité']]], ['Administration', [['Paramètres'], ['Journal & diagnostic']]]];
  return `<aside style="width:236px;flex:0 0 236px;background:var(--color-surface);border-right:0.5px solid var(--color-border-tertiary)">
    <div style="padding:22px 20px 14px"><img src="../../../public/assets/img/logo.png" alt="L’Atelier" style="width:176px;display:block"></div>
    <div style="padding:0 12px 12px"><input placeholder="Rechercher partout…" style="width:100%;box-sizing:border-box;border:0.5px solid var(--color-border-secondary);background:var(--color-background-secondary);border-radius:9px;height:32px;padding:0 10px;font-family:var(--font-ui);font-size:12px"></div>
    <nav style="padding:0 12px 16px;display:flex;flex-direction:column;gap:18px">${SEC.map(([t, it]) => `<div style="display:flex;flex-direction:column;gap:2px"><div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.09em;padding:0 10px 7px">${esc(t)}</div>
      ${it.map(([l, b, sub, on]) => `<div style="${navSt(on, false)}">${sub ? sous(l) : `<span>${esc(l)}</span>`}${badge(b)}</div>`).join('')}</div>`).join('')}</nav></aside>`;
}
// La barre : la vue (graphique ou la liste d'aujourd'hui), ce qu'on lit (% du CA du magasin, % du CA général, €, fois),
// la période, le canal, marque et magasins.
function coque(sousTitre, mesure, corps){
  const M = ['% du CA du magasin', '% du CA général', '€', 'Fois'];
  return `<div style="display:flex;min-height:100vh;background:var(--color-bg);font-family:var(--font-ui);color:var(--color-text)">${rail()}
    <main style="flex:1;min-width:0"><div style="padding:26px 32px 50px;max-width:1460px">
      <header style="display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin-bottom:14px">
        <div><h1 style="font-family:var(--font-display);font-size:30px;font-weight:400;margin:0;line-height:1.15">Tableau d’analyse</h1>
          <p style="font-size:13px;color:var(--color-text-muted);margin:5px 0 0;max-width:760px">${sousTitre}</p></div>
        <div style="display:flex;gap:10px;align-items:center"><span class="an-mu">relu au panel à 15:10 <u>↻ relire</u></span><span class="an-btn">+ Nouveau bon</span><span class="an-btn prim">+ Nouvelle promotion</span></div></header>
      <div class="an-barre"><span class="an-ong"><b class="on">Qui utilise quoi</b><b>Toutes les promotions (liste)</b></span>
        <span class="an-seg">${M.map(x => `<b class="${x === mesure ? 'on' : ''}">${x}</b>`).join('')}</span><span class="an-seg"><b>7 jours</b><b class="on">30 jours</b></span>
        <span class="an-sel">Comptoir et webshop ▾</span><span class="an-sel">Marque et magasins ▾</span>
        <span class="an-mu" style="margin-left:auto">du 27/09 au 26/10 · ventes lues dans les tickets</span></div>
      ${kpis()}${corps}</div></main></div>`;
}
const kpis = () => `<div class="an-kpis">
  <div class="an-kpi"><div class="an-lab">Promotions utilisées</div><div class="v">${PR.length}</div><div class="s">dans les ${MAGS.length} magasins · ${nf(TOTF)} fois sur 30 jours</div></div>
  <div class="an-kpi"><div class="an-lab">Ventes en promotion</div><div class="v">${fP(100 * TOT / CAG, 1)}</div><div class="s">du CA général · ${fE(TOT)} sur ${fE(CAG)}</div></div>
  <div class="an-kpi"><div class="an-lab">Celle qui pèse le plus</div><div class="v" style="font:700 19px var(--font-ui);line-height:1.6">${esc(PR[0].nom)}</div><div class="s">${fP(PR[0].pG)} du CA général · ${ouTexte(PR[0])}</div></div>
  <div class="an-kpi wa"><div class="an-lab">En caisse, sans vente</div><div class="v">${SANS.reduce((a, p) => a + p.sans.length, 0)}</div><div class="s">${SANS.map(p => `${esc(p.nom.split(' · ')[0])} à ${p.sans.join(' et ')}`).join(' ; ')}</div></div></div>`;
const barre = (pc, lib) => `<span class="an-bar"><span class="t"><em style="width:${Math.max(0.5, pc)}%"></em></span><b>${lib}</b></span>`;
const tip = (x, y, html) => `<div class="an-tip" style="left:${x}px;top:${y}px">${html}</div>`;
const SEUILS = [0.25, 0.5, 1, 2];                                // % du CA du magasin
const classe = pc => SEUILS.filter(s => pc >= s).length;

/* --- 3. Les quatre formes ------------------------------------------------------------------------------------------- */
const E = {};
/* A — la matrice : une ligne par promotion, une colonne par magasin. */
E.a = () => {
  const cas = (p, m) => {
    const c = p.par[m];
    if (!c) { return `<td class="mx-c"><span class="mx-non">·</span></td>`; }
    if (!c.fois) { return `<td class="mx-c"><span class="mx-zero" title="en caisse, pas utilisée">0</span></td>`; }
    const k = classe(c.pc);
    return `<td class="mx-c"><span class="mx-v${k >= 2 ? ' f' : ''}${p.nom === 'Cookies 2 + 1 offert' && m === 'Gosselies' ? ' sel' : ''}" style="background:${RAMPE[k]}">${fP(c.pc)}</span></td>`;
  };
  const mxG = PR[0].pG;
  const lig = p => `<tr><td class="mx-n"><b>${esc(p.nom)}</b><div>${marque(p)}${etat(p)} <span class="an-mu">${esc(ouTexte(p))}</span></div></td>${MG.map(x => cas(p, x.m)).join('')}
    <td class="r">${fE(p.v)}</td><td class="mx-g">${barre(100 * p.pG / mxG, fP(p.pG))}</td></tr>`;
  const pied = `<tr class="mx-tot"><td class="mx-n"><b>Toutes les promotions</b><div class="an-mu">ce qu’elles pèsent dans le CA du magasin</div></td>${MG.map(x => `<td class="mx-c"><b>${fP(x.pc, 1)}</b></td>`).join('')}
    <td class="r"><b>${fE(TOT)}</b></td><td class="mx-g"><b>${fP(100 * TOT / CAG, 1)}</b> <span class="an-mu">du CA général</span></td></tr>`;
  const ex = PR.find(p => p.nom === 'Cookies 2 + 1 offert' && p.ou[0] === 'Gosselies'), c = ex.par.Gosselies;
  return coque('Une ligne par promotion, une colonne par magasin : la case dit ce que la promotion pèse dans le CA du magasin. À droite, ce qu’elle pèse dans le CA général.', '% du CA du magasin',
    `<div class="an-carte" style="position:relative"><div class="an-ct"><b>Quelle promotion, dans quel magasin</b><span class="an-mu">plus la case est foncée, plus la promotion pèse dans le CA du magasin · les promotions qui pèsent le plus en haut</span></div>
      <div class="mx"><table><thead><tr><th class="mx-n">Promotion</th>${MG.map(x => `<th class="mx-c"><b>${esc(x.m)}</b><small>${fE(x.ca / 30)} / j</small></th>`).join('')}<th class="r">Ventes</th><th class="mx-g">% du CA général</th></tr></thead>
      <tbody>${PR.map(lig).join('')}</tbody><tfoot>${pied}</tfoot></table></div>
      <div class="an-leg"><span>% du CA du magasin :</span>${RAMPE.map((c, i) => `<i style="background:${c}"></i><span>${i === 0 ? 'moins de 0,25' : i < 4 ? nf(SEUILS[i - 1], 2).replace(',00', '').replace(/0$/, '') + ' à ' + nf(SEUILS[i], 2).replace(',00', '').replace(/0$/, '') : '2 et plus'}</span>`).join('')}
        <span class="mx-non" style="margin-left:14px">·</span><span>pas dans ce magasin</span><span class="mx-zero">0</span><span>en caisse, pas utilisée</span></div>
      ${tip(560, 128, `<b>${esc(ex.nom)} · Gosselies</b><div>${nf(c.fois)} fois · ${fE(c.v)} vendus en promotion</div><div class="an-tl"><span>dans le CA de Gosselies (${fE(CA30.Gosselies)})</span><b>${fP(c.pc)}</b><span>dans le CA général (${fE(CAG)})</span><b>${fP(100 * c.v / CAG)}</b><span>remise accordée</span><b>${fE(ex.remise)}</b></div>`)}</div>`);
};
/* B — promotion par promotion : la barre en % du CA général, découpée par magasin. */
E.b = () => {
  const mx = Math.ceil(PR[0].pG * 10) / 10;
  const barre = p => {
    const seg = p.ou.filter(m => p.par[m].fois).sort((a, b) => p.par[b].v - p.par[a].v).map(m => {
      const w = 100 * (100 * p.par[m].v / CAG) / mx;
      const px = w * 5.5;
      return `<span class="pb-s" style="width:${w}%" title="${esc(m)}">${px >= m.length * 7 + 14 ? esc(m) : px >= COURT[m].length * 7 + 12 ? esc(COURT[m]) : ''}</span>`;
    }).join('');
    return `<div class="pb-bar">${seg}<b class="pb-val">${fP(p.pG)}</b></div>`;
  };
  const ou = p => p.src === 'mag' ? `<b>${esc(p.ou[0])}</b> seulement` : `${ouTexte(p)}${p.sans.length ? `<div class="pb-sans">en caisse sans vente : ${p.sans.map(esc).join(', ')}</div>` : ''}`;
  const graduation = [0, 0.2, 0.4, 0.6, 0.8].filter(g => g <= mx).map(g => `<span style="left:${100 * g / mx}%">${fP(g, 1)}</span>`).join('');
  return coque('Une ligne par promotion. Sa barre dit ce qu’elle pèse dans le CA général ; elle est découpée par magasin, du plus gros au plus petit.', '% du CA général',
    `<div class="an-carte"><div class="an-ct"><b>Ce que chaque promotion pèse dans le CA général</b><span class="an-mu">CA général : ${fE(CAG)} sur 30 jours, les neuf magasins, comptoir et webshop · un morceau = un magasin</span></div>
      <div class="pb"><div class="pb-r pb-t"><span>Promotion</span><span class="pb-ech">${graduation}</span><span>Où elle est utilisée</span><span class="r">Ventes</span><span class="r">Fois</span></div>
      ${PR.map(p => `<div class="pb-r"><span><b>${esc(p.nom)}</b><div>${marque(p)}${etat(p)}</div></span>${barre(p)}<span class="pb-ou">${ou(p)}</span><span class="r">${fE(p.v)}</span><span class="r">${nf(p.fois)}</span></div>`).join('')}
      <div class="pb-r tot"><span><b>Toutes les promotions</b></span><span><b>${fP(100 * TOT / CAG, 1)}</b> <span class="an-mu">du CA général</span></span><span></span><span class="r"><b>${fE(TOT)}</b></span><span class="r"><b>${nf(TOTF)}</b></span></div></div></div>`);
};
/* C — magasin par magasin : ses promotions et ce que chacune pèse dans son CA (même échelle pour les neuf). */
E.c = () => {
  const MX = 2.5;
  const carte = x => `<div class="an-mc"><div class="an-mc-t"><b>${esc(x.m)}</b><span class="an-mu">${x.n} promotion${x.n > 1 ? 's' : ''} utilisée${x.n > 1 ? 's' : ''}</span></div>
      <div class="an-mc-v">${fP(x.pc, 1)}<small> de son CA en promotion</small></div>
      <div class="an-mu" style="margin-bottom:8px">${fE(x.v)} sur ${fE(x.ca)} · ${fP(x.pG)} du CA général</div>
      <div class="mc-l">${x.L.map(l => `<div class="mc-r${l.fois ? '' : ' o'}"><span class="mc-n">${esc(l.p.nom)}${l.p.src === 'marque' ? ' <em class="an-or m">marque</em>' : ''}</span>
        ${l.fois ? `<span class="mc-b"><em style="width:${Math.min(100, 100 * l.pc / MX)}%"></em></span><b>${fP(l.pc)}</b>` : `<span class="mc-b"></span><span class="mc-0">en caisse, pas utilisée</span>`}</div>`).join('')}</div></div>`;
  return coque('Une carte par magasin : ce que la promotion pèse dans son CA, puis chacune de ses promotions (les siennes et celles de la marque). Toutes les barres sont à la même échelle.', '% du CA du magasin',
    `<div class="an-mcg">${MG.map(carte).join('')}</div>`);
};
/* D — une promotion à la fois : la liste à gauche, la promotion choisie magasin par magasin à droite. */
E.d = () => {
  const ch = PR[0], mxG = PR[0].pG;
  const liste = PR.map(p => `<div class="pd-i${p === ch ? ' on' : ''}"><div class="pd-it"><b>${esc(p.nom)}</b><span>${fP(p.pG)}</span></div>
      <div class="pd-ib"><span class="an-bar"><span class="t"><em style="width:${100 * p.pG / mxG}%"></em></span></span></div>
      <div class="an-mu">${marque(p)}${etat(p)} ${esc(ouTexte(p))}${p.sans.length ? ` · <span style="color:${AMBRE}">sans vente à ${p.sans.map(esc).join(', ')}</span>` : ''}</div></div>`).join('');
  const tri = ch.ou.slice().sort((a, b) => ch.par[b].pc - ch.par[a].pc), mx = Math.max(...tri.map(m => ch.par[m].pc));
  const absents = MAGS.filter(m => !ch.par[m]);
  const det = `<div class="pd-d"><div class="an-mu" style="margin-bottom:4px">${marque(ch)} du ${esc(ch.du)} ${ch.au ? 'au ' + esc(ch.au) : '· sans fin'}</div>
      <h2>${esc(ch.nom)}</h2><div style="font-size:13px">${esc(ch.achete)} → ${esc(ch.recoit)}</div>
      <div class="pd-k"><div><div class="an-lab">Dans le CA général</div><div class="v">${fP(ch.pG)}</div><div class="an-mu">${fE(ch.v)} sur ${fE(CAG)}</div></div>
        <div><div class="an-lab">Utilisée</div><div class="v">${nf(ch.fois)} fois</div><div class="an-mu">dont ${ch.web} % au webshop</div></div>
        <div><div class="an-lab">Dans</div><div class="v">${ch.ou.length - ch.sans.length} magasins</div><div class="an-mu">${absents.length ? 'pas à ' + absents.join(', ') : 'tout le réseau'}</div></div>
        <div><div class="an-lab">Remise accordée</div><div class="v">${fE(ch.remise)}</div><div class="an-mu">${fP(100 * ch.remise / CAG)} du CA général</div></div></div>
      <div class="an-ct" style="padding:14px 0 8px"><b>Magasin par magasin</b><span class="an-mu">ce qu’elle pèse dans le CA de chaque magasin</span></div>
      <div class="pd-m"><div class="pd-mr pd-mt"><span>Magasin</span><span>% du CA du magasin</span><span class="r">Ventes</span><span class="r">Fois</span><span class="r">% du CA général</span></div>
      ${tri.map(m => { const c = ch.par[m]; return `<div class="pd-mr"><b>${esc(m)}</b>${barre(100 * c.pc / mx, fP(c.pc))}<span class="r">${fE(c.v)}</span><span class="r">${nf(c.fois)}</span><span class="r">${fP(100 * c.v / CAG)}</span></div>`; }).join('')}</div></div>`;
  return coque('Choisissez une promotion à gauche : à droite, ce qu’elle pèse dans le CA général, et dans le CA de chaque magasin où elle est en caisse.', '% du CA du magasin',
    `<div class="pd"><div class="an-carte"><div class="an-ct"><b>Les promotions</b><span class="an-mu">${PR.length} utilisées · % du CA général</span></div><div class="pd-l">${liste}</div></div><div class="an-carte">${det}</div></div>`);
};

/* --- 4. La page, les captures, la planche ------------------------------------------------------------------------ */
const CSS = `
html,body{margin:0;background:var(--color-bg)}
.an-ecran{display:none}.an-ecran.on{display:block}
.an-mu{font-size:11.5px;color:var(--color-text-muted);font-weight:500}
.an-btn{border:.5px solid var(--color-border-secondary);background:#fff;border-radius:8px;padding:9px 15px;font:600 12.5px var(--font-ui);white-space:nowrap}.an-btn.prim{background:${RUBIS};border-color:${RUBIS};color:#fff}
.an-barre{display:flex;gap:10px;align-items:center;flex-wrap:wrap;background:#fff;border:.5px solid var(--color-border-tertiary);border-radius:12px;padding:10px 14px;margin-bottom:12px}
.an-ong,.an-seg{display:inline-flex;background:var(--color-background-secondary);border-radius:9px;padding:3px}.an-ong b,.an-seg b{padding:6px 12px;border-radius:7px;font:600 12px var(--font-ui);color:var(--color-text-muted);white-space:nowrap}
.an-ong b.on{background:${RUBIS};color:#fff}.an-seg b.on{background:#222;color:#fff}
.an-sel{border:.5px solid var(--color-border-secondary);border-radius:8px;padding:7px 11px;font:500 12.5px var(--font-ui);background:#fff;white-space:nowrap}
.an-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:12px}
.an-kpi{background:#fff;border:.5px solid var(--color-border-tertiary);border-radius:12px;padding:12px 14px}.an-kpi .v{font-family:var(--font-display);font-size:30px;line-height:1.1;margin-top:4px}.an-kpi .s{font-size:11.5px;color:var(--color-text-muted);line-height:1.45}
.an-kpi.wa .v{color:${AMBRE}}
.an-lab{font:600 10px var(--font-ui);letter-spacing:.07em;text-transform:uppercase;color:var(--color-text-muted)}
.an-carte{background:#fff;border:.5px solid var(--color-border-tertiary);border-radius:12px;margin-bottom:12px}
.an-ct{display:flex;align-items:baseline;gap:12px;flex-wrap:wrap;padding:14px 16px 10px}.an-ct b{font-family:var(--font-display);font-weight:400;font-size:19px}
.an-or{font-style:normal;font:600 9.5px var(--font-ui);background:#efe9df;color:#5d554d;border-radius:5px;padding:1px 6px;white-space:nowrap}.an-or.m{background:#f3e3e6;color:${RUBIS}}.an-or.t{background:#eee;color:#777}
.an-bar{display:flex;align-items:center;gap:8px;white-space:nowrap;font-size:12px}.an-bar .t{flex:1;min-width:0}.an-bar em{display:block;height:12px;border-radius:0 4px 4px 0;background:${RUBIS};min-width:2px}.an-bar b{flex:0 0 54px}
.an-leg{display:flex;align-items:center;gap:6px;flex-wrap:wrap;padding:6px 16px 14px;font-size:11px;color:${MUET}}.an-leg i{width:18px;height:12px;border-radius:3px;display:inline-block}
.an-tip{position:absolute;background:#fff;border:.5px solid var(--color-border-secondary);border-radius:10px;box-shadow:0 10px 30px rgba(0,0,0,.14);padding:10px 12px;font-size:12px;min-width:270px;z-index:3;line-height:1.45}
.an-tl{display:grid;grid-template-columns:1fr auto;gap:2px 14px;margin-top:6px;padding-top:6px;border-top:.5px solid var(--color-border-tertiary);font-size:11.5px}
/* A */
.mx{padding:0 16px 6px;overflow-x:auto}.mx table{border-collapse:separate;border-spacing:3px;width:100%;font-size:12px}
.mx th{font:600 10px var(--font-ui);letter-spacing:.05em;text-transform:uppercase;color:var(--color-text-muted);text-align:left;padding:4px 4px 8px;vertical-align:bottom}
.mx th.mx-c{text-align:center}.mx th.mx-c b{display:block;font:700 11.5px var(--font-ui);text-transform:none;letter-spacing:0;color:${ENCRE}}.mx th.mx-c small{font:500 10px var(--font-ui);text-transform:none;letter-spacing:0}
.mx td{padding:0;vertical-align:middle}.mx td.r,.mx th.r{text-align:right;padding-right:10px;font-variant-numeric:tabular-nums;white-space:nowrap}
.mx-n{min-width:230px;padding:5px 8px 5px 0!important;line-height:1.35}.mx-n div{margin-top:2px}
.mx-c{width:78px;text-align:center}
.mx-v{display:flex;align-items:center;justify-content:center;height:34px;border-radius:4px;font-weight:700;font-variant-numeric:tabular-nums;color:${ENCRE}}.mx-v.f{color:#fff}.mx-v.sel{outline:2px solid ${ENCRE};outline-offset:1px}
.mx-non{display:flex;align-items:center;justify-content:center;height:34px;color:#c9bfb3;font-size:18px}
.mx-zero{display:inline-flex;align-items:center;justify-content:center;height:34px;width:100%;box-sizing:border-box;border:1.5px dashed ${AMBRE};border-radius:4px;color:${AMBRE};font-weight:700}
.an-leg .mx-non,.an-leg .mx-zero{height:16px;width:22px;font-size:13px}
.mx-g{min-width:190px}.mx tbody tr td{border-bottom:.5px solid #f1ebe3}
.mx-tot td{padding-top:10px!important;border-top:1px solid var(--color-border-tertiary)}
/* B */
.pb{padding:0 16px 12px}
.pb-r{display:grid;grid-template-columns:250px minmax(0,1fr) 230px 80px 56px;gap:14px;align-items:center;padding:8px 0;border-bottom:.5px solid var(--color-border-tertiary);font-size:12.5px}
.pb-r .r{text-align:right;font-variant-numeric:tabular-nums}.pb-r>span>div{margin-top:3px}
.pb-t{font:600 10px var(--font-ui);letter-spacing:.06em;text-transform:uppercase;color:var(--color-text-muted);border-bottom:none;padding-bottom:2px}
.pb-ech{position:relative;height:14px}.pb-ech span{position:absolute;transform:translateX(-50%);font:500 10px var(--font-ui);letter-spacing:0;text-transform:none}.pb-ech span{white-space:nowrap}.pb-ech span:first-child{transform:none}.pb-ech span:last-child{transform:translateX(-100%)}
.pb-bar{display:flex;align-items:center;gap:0;height:26px}
.pb-s{display:flex;align-items:center;justify-content:center;height:26px;background:${RUBIS};color:#fff;font:600 10.5px var(--font-ui);overflow:hidden;white-space:nowrap;border-right:2px solid #fff;box-sizing:border-box;min-width:3px}
.pb-s:nth-child(even){background:#a8495a}.pb-s:first-child{border-radius:4px 0 0 4px}.pb-s:nth-last-child(2){border-radius:0 4px 4px 0;border-right:none}
.pb-val{margin-left:8px;font-size:12.5px;white-space:nowrap}
.pb-ou{font-size:12px;line-height:1.4}.pb-sans{color:${AMBRE};font-size:11.5px}
.pb-r.tot{border-bottom:none}
/* C */
.an-mcg{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
.an-mc{background:#fff;border:.5px solid var(--color-border-tertiary);border-radius:12px;padding:14px 16px}
.an-mc-t{display:flex;justify-content:space-between;align-items:baseline}.an-mc-t b{font-family:var(--font-display);font-weight:400;font-size:20px}
.an-mc-v{font-family:var(--font-display);font-size:30px;margin:2px 0}.an-mc-v small{font-family:var(--font-ui);font-size:12px;color:var(--color-text-muted)}
.mc-l{border-top:.5px solid var(--color-border-tertiary);padding-top:6px}
.mc-r{display:grid;grid-template-columns:minmax(0,1fr) 92px 52px;gap:8px;align-items:center;padding:4px 0;font-size:12px}.mc-r b{text-align:right;font-variant-numeric:tabular-nums}
.mc-n{line-height:1.3}.mc-b{display:block;height:10px;background:#f1ebe3;border-radius:0 4px 4px 0}.mc-b em{display:block;height:10px;background:${RUBIS};border-radius:0 4px 4px 0;min-width:2px}
.mc-r.o .mc-n{color:${MUET}}.mc-0{grid-column:2/4;color:${AMBRE};font-size:11px;font-weight:600;text-align:right}.mc-r.o .mc-b{display:none}
/* D */
.pd{display:grid;grid-template-columns:400px minmax(0,1fr);gap:12px;align-items:start}
.pd-l{padding:0 8px 8px}.pd-i{padding:9px 10px;border-radius:9px;margin-bottom:2px}.pd-i.on{background:#f6ece9;box-shadow:inset 3px 0 0 ${RUBIS}}
.pd-it{display:flex;justify-content:space-between;gap:10px;font-size:12.5px}.pd-it span{font-weight:700;font-variant-numeric:tabular-nums}.pd-ib{margin:4px 0}
.pd-d{padding:18px 20px}.pd-d h2{font:700 24px var(--font-ui);margin:0 0 4px}
.pd-k{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:14px}.pd-k>div{background:var(--color-background-secondary);border-radius:10px;padding:10px 12px}.pd-k .v{font-family:var(--font-display);font-size:24px;margin:2px 0}
.pd-mr{display:grid;grid-template-columns:110px minmax(0,1fr) 80px 56px 110px;gap:12px;align-items:center;padding:7px 0;border-bottom:.5px solid var(--color-border-tertiary);font-size:12.5px}.pd-mr .r{text-align:right;font-variant-numeric:tabular-nums}
.pd-mt{font:600 10px var(--font-ui);letter-spacing:.06em;text-transform:uppercase;color:var(--color-text-muted)}
/* La planche */
.an-pl{padding:28px 32px}.an-pl h1{font-family:var(--font-display);font-weight:400;font-size:34px;margin:0}
.an-pl-g{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;margin-top:16px}
.an-pl-c{background:#fff;border-radius:14px;padding:16px;border:.5px solid var(--color-border-tertiary)}.an-pl-c h2{font-family:var(--font-display);font-weight:400;font-size:24px;margin:0 0 4px}
.an-pl-c img{width:100%;height:auto;border-radius:8px;border:.5px solid var(--color-border-tertiary);margin:8px 0}
.an-pl-c ul{margin:4px 0 0;padding-left:18px;font-size:12.5px;line-height:1.55}`;
const PL = [
  ['a', 'A · La matrice', 'Une ligne par promotion, une colonne par magasin : la case dit ce que la promotion pèse dans le CA du magasin. À droite, le % du CA général.', ['les deux réponses dans le même tableau', 'on voit d’un coup les cases vides et les promotions sans vente'], ['les petits pourcentages se lisent dans la case, pas à l’œil']],
  ['b', 'B · Promotion par promotion', 'Une barre par promotion, à l’échelle du CA général, découpée par magasin ; à côté, où elle est utilisée et où elle ne vend pas.', ['le % du CA général se voit en longueur', 'trié de la plus forte à la plus faible'], ['le poids dans le CA de chaque magasin n’y est pas']],
  ['c', 'C · Magasin par magasin', 'Une carte par magasin : la part de son CA en promotion, puis chacune de ses promotions, barres à la même échelle.', ['la vue du franchisé : ce qui marche chez lui', 'les promotions de la marque à côté des siennes'], ['une promotion de la marque se lit en neuf endroits']],
  ['d', 'D · Une promotion à la fois', 'La liste des promotions à gauche, avec leur % du CA général ; à droite, la promotion choisie magasin par magasin.', ['le plus de détail : fois, remise, webshop', 'les magasins où elle n’est pas sont nommés'], ['une seule promotion à la fois']]];

async function main(){
  const tete = `<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Tableau d’analyse — maquettes</title>
<link rel="stylesheet" href="../../../public/assets/ds/global.css"><style>${CSS}</style></head><body>`;
  const ecrans = ['a', 'b', 'c', 'd'].map(k => `<div class="an-ecran" id="e-${k}">${E[k]()}</div>`).join('\n');
  const planche = `<div class="an-ecran" id="e-planche"><div class="an-pl"><h1>Quelle promotion, dans quel magasin, quel % du CA — quatre formes</h1><div class="an-mu" style="font-size:13px">Tableau d’analyse des promotions. Données illustrées.</div>
    <div class="an-pl-g">${PL.map(([k, t, d, pour, contre]) => `<div class="an-pl-c"><h2>${t}</h2><div style="font-size:13px">${d}</div><img src="v-${k}.png"><ul>${pour.map(x => `<li>${x}</li>`).join('')}${contre.map(x => `<li style="color:${AMBRE}">${x}</li>`).join('')}</ul></div>`).join('')}</div></div></div>`;
  const html = tete + ecrans + planche + `<script>(function(){function v(){var id=(location.hash||'#a').slice(1);document.querySelectorAll('.an-ecran').forEach(function(s){s.classList.toggle('on',s.id==='e-'+id);});}addEventListener('hashchange',v);v();})();</script></body></html>`;
  fs.writeFileSync(OUT + 'index.html', html);
  const b = await pw.chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost'] });
  const ctx = await b.newContext({ viewport: { width: 1600, height: 1000 }, deviceScaleFactor: 1.25, locale: 'fr-BE' });
  for (const id of ['a', 'b', 'c', 'd', 'planche']) {
    const p = await ctx.newPage();
    const err = []; p.on('pageerror', e => err.push(e.message)); p.on('requestfailed', r => err.push('échec ' + r.url()));
    await p.goto(ICI + 'index.html#' + id, { waitUntil: 'networkidle' });
    await p.evaluate('document.fonts.ready'); await p.waitForTimeout(250);
    const large = await p.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
    await p.screenshot({ path: OUT + id + '.png', fullPage: true });
    if (id !== 'planche') {
      // La vignette de la planche : le graphique, sans la coque ni les chiffres (les mêmes pour les quatre).
      const r = await p.evaluate(k => { const k0 = document.querySelector('#e-' + k + ' .an-kpis'), e = k0.nextElementSibling.getBoundingClientRect(), fin = k0.parentElement.getBoundingClientRect().bottom; return { x: e.left - 8, y: e.top + scrollY - 8, width: e.width + 16, height: Math.min(fin - e.top + 8, 640) }; }, id);
      await p.screenshot({ path: OUT + 'v-' + id + '.png', clip: r, fullPage: true });
    }
    console.log(id, await p.evaluate(() => document.body.scrollHeight), 'px', large ? 'DÉBORDE' : '', err.join(' | '));
    await p.close();
  }
  await b.close();
}
main().catch(e => { console.error(e); process.exit(1); });
