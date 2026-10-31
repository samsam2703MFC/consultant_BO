/* Maquettes « Tableau d'analyse des promotions, plus visuel » (26/10/2026). Demande de Sam : « ce n'est pas très
 * visuel, je ne sais pas quel magasin en vend combien par jour, quel est le volume par rapport au CA ». Quatre formes :
 *   A — la carte des jours : magasins × jours, la case = ce qui s'est vendu en promotion ce jour-là ;
 *   B — la promotion dans le CA : par magasin, la part du CA du jour, et la courbe des 30 jours ;
 *   C — un magasin, une carte : neuf petits graphiques à la même échelle ;
 *   D — où ça marche : chaque magasin placé selon son CA par jour et ce qu'il vend en promotion par jour.
 * Données illustrées (celles de docs/maquettes/promotions-cockpit, déroulées jour par jour) ; la coque et le rail sont
 * ceux du cockpit en ligne.
 *
 *   php -S 127.0.0.1:8099 -t .                              (à la racine du dépôt)
 *   node docs/maquettes/promotions-analyse/generer.cjs      → index.html, a.png … d.png, planche.png
 */
const fs = require('fs');
let pw;
try { pw = require('playwright'); } catch (e) { pw = require('/opt/node22/lib/node_modules/playwright'); }
const D = require('../promotions-cockpit/donnees.cjs');
const ICI = 'http://127.0.0.1:8099/docs/maquettes/promotions-analyse/';
const OUT = __dirname + '/';

const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const nf = (n, d) => Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d || 0, maximumFractionDigits: d || 0 }).replace(/ /g, ' ');
const fE = n => nf(Math.round(n)) + ' €';
const fP = v => nf(v, 1) + ' %';
// Une seule teinte, du clair au foncé (validée : node …/dataviz/scripts/validate_palette.js --ordinal) ; 0 = gris chaud.
const RAMPE = ['#d9a3ad', '#c27583', '#a8495a', '#8d1d2c', '#5e0f1b'];
const ZERO = '#ece5db', GRIS = '#d9d0c4', RUBIS = '#8d1d2c', ENCRE = '#222', MUET = '#6b625a';

/* --- 1. Les données : 30 jours, 9 magasins, les promotions de la maquette déroulées jour par jour ----------------- */
const MAGS = D.MAGS, R = D.R, CA30 = D.CA30;
const J = Array.from({ length: 30 }, (_, i) => { const t = new Date('2026-09-27T12:00:00'); t.setDate(t.getDate() + i); return t; });
const iso = t => t.toISOString().slice(0, 10);
const jj = t => String(t.getDate()).padStart(2, '0') + '/' + String(t.getMonth() + 1).padStart(2, '0');
const JS = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'];
const JOUR = [0.95, 0.86, 0.9, 0.98, 0.94, 1.08, 1.3];          // dimanche → samedi
let s = 17; const rnd = () => (s = (s * 9301 + 49297) % 233280) / 233280;
const date = v => { if (!v || v === '—') { return null; } const [d, m] = v.split('/'); return `2026-${m}-${d}`; };
// Les promotions d'un magasin : les siennes et celles de la marque, avec ce qu'elles vendent sur 30 jours dans ce magasin.
const parMag = {};
MAGS.forEach(m => {
  const L = [];
  R.forEach(r => {
    if (!r.fois || !r.ventes) { return; }
    if (r.src === 'mag' && r.mag !== m) { return; }
    if (r.src === 'marque' && !r.mags.includes(m)) { return; }
    const part = r.src === 'marque' ? (r.par ? r.par[r.mags.indexOf(m)] / r.fois : 1 / r.mags.length) : 1;
    L.push({ nom: r.nom, src: r.src, v: r.ventes * part, fois: r.fois * part, du: date(r.du) || '2026-09-27', au: date(r.au) || '2026-12-31' });
  });
  parMag[m] = L;
});
// Jour par jour : le CA du magasin (son CA sur 30 jours, au rythme de la semaine) et chaque promotion sur ses jours actifs.
const S = MAGS.map(m => {
  const poidsJ = J.map(t => JOUR[t.getDay()]), somme = poidsJ.reduce((a, v) => a + v, 0);
  const ca = J.map((t, i) => CA30[m] * poidsJ[i] / somme * (0.94 + 0.12 * rnd()));
  const promos = parMag[m].map(p => {
    const actifs = J.map(t => iso(t) >= p.du && iso(t) <= p.au);
    const pj = J.map((t, i) => actifs[i] ? JOUR[t.getDay()] * (0.75 + 0.5 * rnd()) : 0), tp = pj.reduce((a, v) => a + v, 0) || 1;
    return Object.assign({}, p, { jours: pj.map(v => ({ v: p.v * v / tp, n: p.fois * v / tp })) });
  });
  const promo = J.map((t, i) => promos.reduce((a, p) => a + p.jours[i].v, 0));
  const pieces = J.map((t, i) => promos.reduce((a, p) => a + p.jours[i].n, 0));
  const tCA = ca.reduce((a, v) => a + v, 0), tP = promo.reduce((a, v) => a + v, 0), tN = pieces.reduce((a, v) => a + v, 0);
  return { m, ca, promo, pieces, promos, caJ: tCA / 30, pJ: tP / 30, nJ: tN / 30, part: 100 * tP / tCA,
    top: promos.slice().sort((a, b) => b.v - a.v).slice(0, 3).map(p => ({ nom: p.nom, vJ: p.v / 30, src: p.src })) };
});
const RES = { caJ: S.reduce((a, x) => a + x.caJ, 0), pJ: S.reduce((a, x) => a + x.pJ, 0), nJ: S.reduce((a, x) => a + x.nJ, 0) };
RES.part = 100 * RES.pJ / RES.caJ;
RES.promo = J.map((t, i) => S.reduce((a, x) => a + x.promo[i], 0));
const MOY = RES.pJ / S.length;                                   // ce qu'un magasin vend en moyenne par jour
const top = S.slice().sort((a, b) => b.pJ - a.pJ)[0];
const G = S.find(x => x.m === 'Gosselies'), iG = 27;             // l'infobulle d'exemple : Gosselies, samedi 24/10

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
function coque(sousTitre, corps){
  return `<div style="display:flex;min-height:100vh;background:var(--color-bg);font-family:var(--font-ui);color:var(--color-text)">${rail()}
    <main style="flex:1;min-width:0"><div style="padding:26px 32px 50px;max-width:1460px">
      <header style="display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin-bottom:14px">
        <div><h1 style="font-family:var(--font-display);font-size:30px;font-weight:400;margin:0;line-height:1.15">Tableau d’analyse</h1>
          <p style="font-size:13px;color:var(--color-text-muted);margin:5px 0 0;max-width:720px">${sousTitre}</p></div>
        <div style="display:flex;gap:10px;align-items:center"><span class="an-mu">relu au panel à 15:10 <u>↻ relire</u></span><span class="an-btn">+ Nouveau bon</span><span class="an-btn prim">+ Nouvelle promotion</span></div></header>
      <div class="an-barre"><span class="an-ong"><b class="on">Par magasin</b><b>Toutes les promotions (liste)</b></span>
        <span class="an-seg"><b class="on">€</b><b>Pièces</b><b>% du CA</b></span><span class="an-seg"><b>7 jours</b><b class="on">30 jours</b></span>
        <span class="an-sel">Toutes les promotions ▾</span><span class="an-sel">Marque et magasins ▾</span><span class="an-mu" style="margin-left:auto">du 27/09 au 26/10 · ventes lues dans les tickets</span></div>
      ${corps}</div></main></div>`;
}
const kpis = () => `<div class="an-kpis">
  <div class="an-kpi"><div class="an-lab">En promotion, par jour</div><div class="v">${fE(RES.pJ)}</div><div class="s">tout le réseau · ${nf(RES.nJ)} pièces par jour</div></div>
  <div class="an-kpi"><div class="an-lab">Part du CA</div><div class="v">${fP(RES.part)}</div><div class="s">des ${fE(RES.caJ)} vendus par jour</div></div>
  <div class="an-kpi"><div class="an-lab">Un magasin, en moyenne</div><div class="v">${fE(MOY)}</div><div class="s">par jour en promotion</div></div>
  <div class="an-kpi"><div class="an-lab">Celui qui en vend le plus</div><div class="v">${esc(top.m)}</div><div class="s">${fE(top.pJ)} par jour · ${fP(top.part)} de son CA</div></div></div>`;
const tip = (x, y, html) => `<div class="an-tip" style="left:${x}px;top:${y}px">${html}</div>`;
const tipG = `<b>Gosselies · samedi 24/10</b><div>${fE(G.promo[iG])} en promotion · ${nf(G.pieces[iG])} pièces</div><div class="an-mu">${fP(100 * G.promo[iG] / G.ca[iG])} du CA du jour (${fE(G.ca[iG])})</div>
  <div class="an-tl">${G.promos.filter(p => p.jours[iG].v > 0).sort((a, b) => b.jours[iG].v - a.jours[iG].v).map(p => `<span>${esc(p.nom)}</span><b>${fE(p.jours[iG].v)}</b>`).join('')}</div>`;
// Une petite courbe des 30 jours, une teinte.
function spark(v, w, h, max){
  const mx = max || Math.max(...v, 1), pts = v.map((x, i) => [i * (w - 4) / (v.length - 1) + 2, h - 3 - (h - 6) * x / mx]);
  return `<svg width="${w}" height="${h}" viewBox="0 0 ${w} ${h}" class="an-svg"><polyline points="${pts.map(p => p.map(q => q.toFixed(1)).join(',')).join(' ')}" fill="none" stroke="${RUBIS}" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/><circle cx="${pts[pts.length - 1][0]}" cy="${pts[pts.length - 1][1]}" r="3" fill="${RUBIS}" stroke="#fff" stroke-width="1.5"/></svg>`;
}

/* --- 3. Les quatre formes ------------------------------------------------------------------------------------------- */
const E = {};
/* A — la carte des jours. */
E.a = () => {
  const MAXC = 300, coul = v => v < 1 ? ZERO : RAMPE[Math.min(4, Math.floor(v / (MAXC / 5)))];
  const tri = S.slice().sort((a, b) => b.pJ - a.pJ);
  const W = 22, H = 30;
  const tete = `<div class="an-hm-r an-hm-t"><span></span>${J.map(t => `<i class="${[0, 6].includes(t.getDay()) ? 'we' : ''}">${t.getDate()}</i>`).join('')}<span class="r">€ / jour</span><span>Part du CA</span><span class="r">Pièces / j</span></div>`;
  const lig = x => `<div class="an-hm-r"><span class="nm"><b>${esc(x.m)}</b><small>${fE(x.caJ)} de CA / j</small></span>${x.promo.map((v, i) => `<i style="background:${coul(v)}"${x.m === 'Gosselies' && i === iG ? ' class="sel"' : ''}></i>`).join('')}
    <span class="r"><b>${fE(x.pJ)}</b></span><span class="an-meter"><em style="width:${Math.min(100, x.part / 8 * 100)}%"></em><b>${fP(x.part)}</b></span><span class="r">${nf(x.nJ)}</span></div>`;
  const res = `<div class="an-hm-r tot"><span class="nm"><b>Réseau</b><small>par magasin, en moyenne</small></span>${RES.promo.map(v => `<i style="background:${coul(v / S.length)}"></i>`).join('')}<span class="r"><b>${fE(MOY)}</b></span><span class="an-meter"><em style="width:${RES.part / 8 * 100}%"></em><b>${fP(RES.part)}</b></span><span class="r">${nf(RES.nJ / S.length)}</span></div>`;
  return coque('Quel magasin vend combien en promotion, jour après jour : une ligne par magasin, une case par jour. À droite, la moyenne par jour et ce qu’elle pèse dans le CA.',
    kpis() + `<div class="an-carte" style="position:relative"><div class="an-ct"><b>Ventes en promotion, par magasin et par jour</b><span class="an-mu">plus la case est foncée, plus le magasin a vendu en promotion ce jour-là · les plus gros vendeurs en haut</span></div>
      <div class="an-hm" style="--w:${W}px;--h:${H}px">${tete}${tri.map(lig).join('')}${res}</div>
      <div class="an-leg"><span>0 €</span><i style="background:${ZERO}"></i>${RAMPE.map((c, i) => `<i style="background:${c}"></i><span>${i < 4 ? (i + 1) * MAXC / 5 : MAXC + ' € et +'}</span>`).join('')}<span class="an-mu" style="margin-left:16px">samedi et dimanche en gras · un clic sur un magasin ouvre ses promotions, un clic sur une case le détail du jour</span></div>
      ${tip(560, 150, tipG)}</div>`);
};
/* B — la promotion dans le CA. */
E.b = () => {
  const tri = S.slice().sort((a, b) => b.part - a.part), maxCA = Math.max(...S.map(x => x.caJ));
  const mxR = Math.max(...RES.promo), WC = 1040, HC = 150, bw = WC / 30;
  const cols = RES.promo.map((v, i) => { const h = (HC - 20) * v / mxR; return `<rect x="${i * bw + 3}" y="${HC - h}" width="${bw - 6}" height="${h}" rx="3" fill="${RUBIS}"/>${i % 7 === 0 || i === 29 ? `<text x="${i * bw + bw / 2}" y="${HC + 14}" text-anchor="middle" class="an-ax">${jj(J[i])}</text>` : ''}`; }).join('');
  const grilles = [500, 1000, 1500, 2000].filter(g => g < mxR).map(g => `<line x1="0" x2="${WC}" y1="${HC - (HC - 20) * g / mxR}" y2="${HC - (HC - 20) * g / mxR}" stroke="#eee7de"/><text x="${WC + 6}" y="${HC - (HC - 20) * g / mxR + 3}" class="an-ax">${nf(g)} €</text>`).join('');
  const reseau = `<div class="an-carte"><div class="an-ct"><b>Le réseau, jour par jour</b><span class="an-mu">ventes en promotion, tous magasins · ${fE(RES.pJ)} par jour en moyenne</span></div>
    <svg viewBox="0 0 ${WC + 50} ${HC + 22}" class="an-svg" style="width:100%;padding:0 16px 12px;box-sizing:border-box">${grilles}${cols}
      <line x1="${28 * bw}" x2="${28 * bw}" y1="6" y2="${HC}" stroke="${ENCRE}" stroke-dasharray="3 3"/><text x="${28 * bw - 6}" y="16" text-anchor="end" class="an-ax" style="fill:${ENCRE}">Halloween, 3 cookies + 1 (25/10)</text></svg></div>`;
  const lig = x => `<div class="an-pc-r"><span class="nm"><b>${esc(x.m)}</b></span>
      <span class="an-pc-bar" title="CA par jour"><span style="width:${100 * x.caJ / maxCA}%"><em style="width:${x.part}%"></em></span></span>
      <span class="r"><b>${fE(x.pJ)}</b> <span class="an-mu">sur ${fE(x.caJ)}</span></span>
      <span class="an-meter"><em style="width:${Math.min(100, x.part / 8 * 100)}%"></em><b>${fP(x.part)}</b></span>
      <span>${spark(x.promo, 150, 30, 330)}</span><span class="r">${nf(x.nJ)}</span></div>`;
  return coque('Ce que la promotion pèse dans le CA de chaque magasin, par jour, et comment elle évolue sur 30 jours.',
    kpis() + reseau + `<div class="an-carte"><div class="an-ct"><b>Dans le CA de chaque magasin</b><span class="an-mu">les magasins où la promotion pèse le plus en haut</span></div>
      <div class="an-pc"><div class="an-pc-r an-pc-t"><span>Magasin</span><span>Le CA d’un jour moyen · <em class="an-pastille"></em> la part vendue en promotion</span><span class="r">Promotion / jour</span><span>Part du CA</span><span>30 jours, même échelle</span><span class="r">Pièces / j</span></div>
      ${tri.map(lig).join('')}<div class="an-pc-r tot"><span class="nm"><b>Réseau</b></span><span class="an-mu">${fE(RES.caJ)} de CA par jour, dont ${fE(RES.pJ)} en promotion</span><span class="r"><b>${fE(RES.pJ)}</b></span><span class="an-meter"><em style="width:${RES.part / 8 * 100}%"></em><b>${fP(RES.part)}</b></span><span></span><span class="r">${nf(RES.nJ)}</span></div></div></div>`);
};
/* C — un magasin, une carte. */
E.c = () => {
  const MX = 330, w = 360, h = 96, bw = w / 30;
  const carte = x => {
    const cols = x.promo.map((v, i) => { const hh = (h - 6) * Math.min(v, MX) / MX; return `<rect x="${i * bw + 1}" y="${h - hh}" width="${bw - 2}" height="${hh}" rx="2" fill="${RUBIS}"/>`; }).join('');
    const ym = h - (h - 6) * MOY / MX;
    return `<div class="an-mc"><div class="an-mc-t"><b>${esc(x.m)}</b><span class="an-mu">${nf(x.promos.length)} promotions vendues</span></div>
      <div class="an-mc-v">${fE(x.pJ)}<small> / jour en promotion</small></div>
      <div class="an-mc-s"><span class="an-meter s"><em style="width:${Math.min(100, x.part / 8 * 100)}%"></em><b>${fP(x.part)} du CA</b></span><span class="an-mu">${nf(x.nJ)} pièces / j · CA ${fE(x.caJ)} / j</span></div>
      <svg viewBox="0 0 ${w} ${h + 14}" class="an-svg" style="width:100%">${cols}<line x1="0" x2="${w}" y1="${ym}" y2="${ym}" stroke="${ENCRE}" stroke-dasharray="4 3" stroke-width="1"/><text x="2" y="${ym - 5}" class="an-ax" style="fill:${ENCRE}" stroke="#fff" stroke-width="4" paint-order="stroke">moyenne d’un magasin</text>
        <text x="0" y="${h + 12}" class="an-ax">${jj(J[0])}</text><text x="${w}" y="${h + 12}" text-anchor="end" class="an-ax">${jj(J[29])}</text></svg>
      <div class="an-mc-p">${x.top.map(p => `<div><span>${esc(p.nom)}${p.src === 'marque' ? ' <em class="an-or">marque</em>' : ''}</span><b>${fE(p.vJ)} / j</b></div>`).join('')}</div></div>`;
  };
  return coque('Un magasin, une carte : ce qu’il vend en promotion chaque jour (même échelle pour tous), ce que ça pèse dans son CA, et ses promotions qui vendent le plus.',
    kpis() + `<div class="an-mcg">${S.slice().sort((a, b) => b.pJ - a.pJ).map(carte).join('')}</div>`);
};
/* D — où ça marche. */
E.d = () => {
  const W = 760, H = 430, x0 = 70, y0 = 20, x1 = W - 20, y1 = H - 46;
  const cx = v => x0 + (x1 - x0) * (v - 1800) / (3600 - 1800), cy = v => y1 - (y1 - y0) * v / 200;
  const diag = [2, 4, 6].map(p => { const xa = 1800, xb = 3600, ya = xa * p / 100, yb = xb * p / 100;
    return `<line x1="${cx(xa)}" y1="${cy(ya)}" x2="${cx(xb)}" y2="${cy(Math.min(yb, 200))}" stroke="#cfc6bb" stroke-dasharray="5 4"/><text x="${cx(Math.min(xb, 200 * 100 / p))}" y="${cy(Math.min(yb, 200)) - 6}" text-anchor="end" class="an-ax">${p} % du CA</text>`; }).join('');
  const axes = [0, 50, 100, 150, 200].map(v => `<line x1="${x0}" x2="${x1}" y1="${cy(v)}" y2="${cy(v)}" stroke="#f1ebe3"/><text x="${x0 - 8}" y="${cy(v) + 3}" text-anchor="end" class="an-ax">${v} €</text>`).join('')
    + [2000, 2500, 3000, 3500].map(v => `<text x="${cx(v)}" y="${y1 + 18}" text-anchor="middle" class="an-ax">${nf(v)} €</text>`).join('')
    + `<text x="${(x0 + x1) / 2}" y="${H - 6}" text-anchor="middle" class="an-ax" style="fill:${ENCRE}">CA par jour du magasin</text><text x="16" y="${(y0 + y1) / 2}" transform="rotate(-90 16 ${(y0 + y1) / 2})" text-anchor="middle" class="an-ax" style="fill:${ENCRE}">Ventes en promotion par jour</text>`;
  const AG = ['Gosselies', 'Uccle'];  // libellé à gauche du point : la diagonale « 4 % » à droite de Gosselies, Namur à droite d'Uccle
  const pts = S.map(x => `<circle cx="${cx(x.caJ)}" cy="${cy(x.pJ)}" r="${x.m === 'Gosselies' ? 9 : 7}" fill="${RUBIS}" stroke="#fff" stroke-width="2"/><text x="${cx(x.caJ) + (AG.includes(x.m) ? -13 : 12)}" y="${cy(x.pJ) + 4}" text-anchor="${AG.includes(x.m) ? 'end' : 'start'}" class="an-lb">${esc(x.m)}</text>`).join('');
  const moy = `<g><rect x="${cx(RES.caJ / S.length) - 6}" y="${cy(MOY) - 6}" width="12" height="12" transform="rotate(45 ${cx(RES.caJ / S.length)} ${cy(MOY)})" fill="#fff" stroke="${ENCRE}" stroke-width="2"/><text x="${cx(RES.caJ / S.length) - 12}" y="${cy(MOY) - 8}" text-anchor="end" class="an-ax" style="fill:${ENCRE}" stroke="#fff" stroke-width="4" paint-order="stroke">moyenne du réseau</text></g>`;
  const tri = S.slice().sort((a, b) => b.pJ - a.pJ), mx = tri[0].pJ;
  const table = `<div class="an-rk"><div class="an-rk-r an-rk-t"><span>Magasin</span><span>Promotion / jour</span><span class="r">Part du CA</span><span>30 jours</span></div>${tri.map(x => `<div class="an-rk-r"><b>${esc(x.m)}</b><span class="an-bar"><em style="width:${100 * x.pJ / mx}%"></em><b>${fE(x.pJ)}</b></span><span class="r">${fP(x.part)}</span><span>${spark(x.promo, 110, 26, 330)}</span></div>`).join('')}</div>`;
  return coque('Chaque magasin placé selon son CA par jour et ce qu’il vend en promotion par jour : au-dessus d’une diagonale, la promotion pèse plus que ce pourcentage de son CA.',
    kpis() + `<div class="an-d"><div class="an-carte" style="position:relative"><div class="an-ct"><b>Où la promotion pèse le plus</b><span class="an-mu">un point = un magasin, sur 30 jours</span></div>
      <svg viewBox="0 0 ${W} ${H}" class="an-svg" style="width:100%;padding:0 10px 10px;box-sizing:border-box">${axes}${diag}${moy}${pts}</svg>
      ${tip(110, 120, `<span class="an-mu">au survol d’un point</span><br><b>Gosselies</b><div>${fE(G.pJ)} par jour en promotion · ${fP(G.part)} de son CA (${fE(G.caJ)} / j)</div><div class="an-tl">${G.top.map(p => `<span>${esc(p.nom)}</span><b>${fE(p.vJ)} / j</b>`).join('')}</div>`)}</div>
      <div class="an-carte"><div class="an-ct"><b>Le classement</b><span class="an-mu">ventes en promotion par jour</span></div>${table}</div></div>`);
};

/* --- 4. La page, les captures, la planche ------------------------------------------------------------------------ */
const CSS = `
html,body{margin:0;background:var(--color-bg)}
.an-ecran{display:none}.an-ecran.on{display:block}
.an-mu{font-size:11.5px;color:var(--color-text-muted);font-weight:500}
.an-btn{border:.5px solid var(--color-border-secondary);background:#fff;border-radius:8px;padding:9px 15px;font:600 12.5px var(--font-ui);white-space:nowrap}.an-btn.prim{background:${RUBIS};border-color:${RUBIS};color:#fff}
.an-barre{display:flex;gap:10px;align-items:center;flex-wrap:wrap;background:#fff;border:.5px solid var(--color-border-tertiary);border-radius:12px;padding:10px 14px;margin-bottom:12px}
.an-ong,.an-seg{display:inline-flex;background:var(--color-background-secondary);border-radius:9px;padding:3px}.an-ong b,.an-seg b{padding:6px 12px;border-radius:7px;font:600 12px var(--font-ui);color:var(--color-text-muted)}
.an-ong b.on{background:${RUBIS};color:#fff}.an-seg b.on{background:#222;color:#fff}
.an-sel{border:.5px solid var(--color-border-secondary);border-radius:8px;padding:7px 11px;font:500 12.5px var(--font-ui);background:#fff}
.an-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:12px}
.an-kpi{background:#fff;border:.5px solid var(--color-border-tertiary);border-radius:12px;padding:12px 14px}.an-kpi .v{font-family:var(--font-display);font-size:30px;line-height:1.1;margin-top:4px}.an-kpi .s{font-size:11.5px;color:var(--color-text-muted)}
.an-lab{font:600 10px var(--font-ui);letter-spacing:.07em;text-transform:uppercase;color:var(--color-text-muted)}
.an-carte{background:#fff;border:.5px solid var(--color-border-tertiary);border-radius:12px;margin-bottom:12px}
.an-ct{display:flex;align-items:baseline;gap:12px;flex-wrap:wrap;padding:14px 16px 10px}.an-ct b{font-family:var(--font-display);font-weight:400;font-size:19px}
.an-svg{display:block;overflow:visible}.an-svg .an-ax,.an-ax{font:500 10.5px var(--font-ui);fill:${MUET}}.an-lb{font:700 12px var(--font-ui);fill:${ENCRE}}
.an-hm{padding:0 16px 8px;overflow-x:auto}
.an-hm-r{display:grid;grid-template-columns:150px repeat(30,var(--w)) 84px 140px 78px;gap:2px;align-items:center;margin-bottom:2px}
.an-hm-r i{display:block;height:var(--h);border-radius:3px}.an-hm-r i.sel{outline:2px solid ${ENCRE};outline-offset:1px}
.an-hm-t i{height:auto;text-align:center;font:500 10px var(--font-ui);font-style:normal;color:${MUET}}.an-hm-t i.we{font-weight:800;color:${ENCRE}}
.an-hm-t span{font:600 10px var(--font-ui);letter-spacing:.06em;text-transform:uppercase;color:var(--color-text-muted);padding-left:10px;white-space:nowrap}.an-hm-t span.r{padding-left:0}
.an-hm-r .nm{display:flex;flex-direction:column;font-size:12.5px;line-height:1.2}.an-hm-r .nm small{font-size:10.5px;color:var(--color-text-muted)}
.an-hm-r .r{text-align:right;font-variant-numeric:tabular-nums;font-size:12.5px}
.an-hm-r.tot{border-top:.5px solid var(--color-border-tertiary);padding-top:6px;margin-top:4px}
.an-meter{position:relative;display:flex;align-items:center;gap:8px;margin-left:12px;font-size:12px}.an-meter::before{content:'';flex:0 0 70px;height:8px;border-radius:4px;background:${GRIS}}
.an-meter em{position:absolute;left:0;top:50%;transform:translateY(-50%);height:8px;border-radius:4px;background:${RUBIS};max-width:70px}
.an-meter.s{margin-left:0}
.an-leg{display:flex;align-items:center;gap:6px;padding:4px 16px 14px;font-size:11px;color:${MUET}}.an-leg i{width:18px;height:12px;border-radius:3px;display:inline-block}
.an-tip{position:absolute;background:#fff;border:.5px solid var(--color-border-secondary);border-radius:10px;box-shadow:0 10px 30px rgba(0,0,0,.14);padding:10px 12px;font-size:12px;min-width:250px;z-index:3;line-height:1.45}
.an-tl{display:grid;grid-template-columns:1fr auto;gap:2px 14px;margin-top:6px;padding-top:6px;border-top:.5px solid var(--color-border-tertiary);font-size:11.5px}
.an-pc{padding:0 16px 14px}
.an-pc-r{display:grid;grid-template-columns:110px minmax(0,1fr) 170px 150px 160px 70px;gap:12px;align-items:center;padding:7px 0;border-bottom:.5px solid var(--color-border-tertiary);font-size:12.5px}
.an-pc-t{font:600 10px var(--font-ui);letter-spacing:.06em;text-transform:uppercase;color:var(--color-text-muted)}.an-pc-t span{display:flex;align-items:center;gap:5px}
.an-pc-r .r{text-align:right;font-variant-numeric:tabular-nums}.an-pc-r.tot{border-bottom:none}
.an-pc-bar{display:block;height:18px}.an-pc-bar>span{display:block;height:18px;border-radius:4px;background:${GRIS};position:relative;overflow:hidden}.an-pc-bar em{position:absolute;left:0;top:0;bottom:0;background:${RUBIS};border-right:2px solid #fff}
.an-pastille{display:inline-block;width:10px;height:10px;border-radius:2px;background:${RUBIS}}
.an-mcg{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
.an-mc{background:#fff;border:.5px solid var(--color-border-tertiary);border-radius:12px;padding:14px 16px}
.an-mc-t{display:flex;justify-content:space-between;align-items:baseline}.an-mc-t b{font-family:var(--font-display);font-weight:400;font-size:20px}
.an-mc-v{font-family:var(--font-display);font-size:30px;margin:2px 0}.an-mc-v small{font-family:var(--font-ui);font-size:12px;color:var(--color-text-muted)}
.an-mc-s{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}
.an-mc-p{margin-top:8px;border-top:.5px solid var(--color-border-tertiary);padding-top:6px;font-size:12px}.an-mc-p div{display:flex;justify-content:space-between;padding:2px 0}
.an-or{font-style:normal;font:600 9.5px var(--font-ui);background:#efe9df;color:#5d554d;border-radius:5px;padding:1px 5px}
.an-d{display:grid;grid-template-columns:minmax(0,1.5fr) minmax(0,1fr);gap:12px;align-items:start}
.an-rk{padding:0 16px 12px}.an-rk-r{display:grid;grid-template-columns:90px minmax(0,1fr) 70px 110px;gap:10px;align-items:center;padding:6px 0;border-bottom:.5px solid var(--color-border-tertiary);font-size:12.5px}
.an-rk-t{font:600 10px var(--font-ui);letter-spacing:.06em;text-transform:uppercase;color:var(--color-text-muted)}.an-rk-r .r{text-align:right}
.an-bar{position:relative;display:flex;align-items:center;gap:8px;white-space:nowrap}.an-bar em{display:block;height:12px;border-radius:0 4px 4px 0;background:${RUBIS};max-width:72%}
/* La planche */
.an-pl{padding:28px 32px}.an-pl h1{font-family:var(--font-display);font-weight:400;font-size:34px;margin:0}
.an-pl-g{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;margin-top:16px}
.an-pl-c{background:#fff;border-radius:14px;padding:16px;border:.5px solid var(--color-border-tertiary)}.an-pl-c h2{font-family:var(--font-display);font-weight:400;font-size:24px;margin:0 0 4px}
.an-pl-c img{width:100%;height:auto;border-radius:8px;border:.5px solid var(--color-border-tertiary);margin:8px 0}
.an-pl-c ul{margin:4px 0 0;padding-left:18px;font-size:12.5px;line-height:1.55}`;
const PL = [
  ['a', 'A · La carte des jours', 'Une ligne par magasin, une case par jour : on voit d’un coup qui vend, quand, et les week-ends. À droite, la moyenne par jour et la part du CA.', ['répond aux deux questions sur un seul écran', 'les jours forts et les trous sautent aux yeux'], ['la valeur exacte d’une case est dans l’infobulle']],
  ['b', 'B · La promotion dans le CA', 'Le réseau jour par jour en haut ; puis, par magasin, la barre de son CA d’un jour moyen avec la part vendue en promotion, et sa courbe des 30 jours.', ['le volume face au CA se lit directement, en vraie proportion', 'trié par part du CA'], ['la part est petite (2 à 5 %) : la jauge « part du CA » la grossit']],
  ['c', 'C · Un magasin, une carte', 'Neuf petits graphiques à la même échelle : ce que chaque magasin vend en promotion chaque jour, face à la moyenne d’un magasin ; ses trois promotions qui vendent le plus.', ['le plus visuel, magasin par magasin', 'on voit quelle promotion fait le chiffre'], ['plus long : il faut faire défiler']],
  ['d', 'D · Où ça marche', 'Chaque magasin placé selon son CA par jour et ses ventes en promotion par jour ; les diagonales disent 2, 4, 6 % du CA. À côté, le classement.', ['compare les magasins de tailles différentes d’un coup d’œil', 'repère qui est au-dessus de la moyenne'], ['pas le détail jour par jour (seulement la courbe du classement)']]];

async function main(){
  const tete = `<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Tableau d’analyse — maquettes</title>
<link rel="stylesheet" href="../../../public/assets/ds/global.css"><style>${CSS}</style></head><body>`;
  const ecrans = ['a', 'b', 'c', 'd'].map(k => `<div class="an-ecran" id="e-${k}">${E[k]()}</div>`).join('\n');
  const planche = `<div class="an-ecran" id="e-planche"><div class="an-pl"><h1>Tableau d’analyse des promotions — quatre formes</h1><div class="an-mu" style="font-size:13px">Qui vend combien par jour, et ce que ça pèse dans le CA. Données illustrées.</div>
    <div class="an-pl-g">${PL.map(([k, t, d, pour, contre]) => `<div class="an-pl-c"><h2>${t}</h2><div style="font-size:13px">${d}</div><img src="v-${k}.png"><ul>${pour.map(x => `<li>${x}</li>`).join('')}${contre.map(x => `<li style="color:#8a5a13">${x}</li>`).join('')}</ul></div>`).join('')}</div></div></div>`;
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
      const r = await p.evaluate(k => { const k0 = document.querySelector('#e-' + k + ' .an-kpis'), e = k0.nextElementSibling.getBoundingClientRect(), fin = k0.parentElement.getBoundingClientRect().bottom; return { x: e.left - 8, y: e.top + scrollY - 8, width: e.width + 16, height: Math.min(fin - e.top + 8, 620) }; }, id);
      await p.screenshot({ path: OUT + 'v-' + id + '.png', clip: r, fullPage: true });
    }
    console.log(id, await p.evaluate(() => document.body.scrollHeight), 'px', large ? 'DÉBORDE' : '', err.join(' | '));
    await p.close();
  }
  await b.close();
}
main().catch(e => { console.error(e); process.exit(1); });
