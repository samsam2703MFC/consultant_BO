/* Maquettes « Promotions » (26/10/2026) : le module de création des promotions sort du dashboard magasin et entre dans
 * le rail du cockpit, une section à lui avec deux entrées — Édition (le formulaire actuel) et Tableau d'analyse (toutes
 * les promotions de tous les magasins). Quatre formes, A à D, deux écrans chacune.
 *
 * Le formulaire n'est pas redessiné : le générateur fait tourner le VRAI dashboard (public/dashboard/) sur des réponses
 * d'API fictives (donnees.cjs), remplit l'assistant et en reprend les étapes telles quelles.
 *
 *   php -S 127.0.0.1:8099 -t .                              (à la racine du dépôt)
 *   node docs/maquettes/promotions-cockpit/generer.cjs      → index.html, a-*.png … d-*.png, planche.png
 */
const fs = require('fs');
let pw;
try { pw = require('playwright'); } catch (e) { pw = require('/opt/node22/lib/node_modules/playwright'); }
const D = require('./donnees.cjs');
const BASE = 'http://127.0.0.1:8099/';
const ICI = BASE + 'docs/maquettes/promotions-cockpit/';
const OUT = __dirname + '/';

const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const nf = (n, d) => Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d || 0, maximumFractionDigits: d || 0 });
const fE = n => n == null ? '—' : nf(Math.round(n)) + ' €';
const fX = v => v == null ? '—' : '×' + nf(v, 2);
const fP = v => v == null ? '—' : nf(v, 1) + ' %';
const mu = t => `<span class="mu">${t}</span>`;

/* --- 1. Le formulaire actuel, repris du dashboard ------------------------------------------------------------------- */
async function fragments(b) {
  const ctx = await b.newContext({ viewport: { width: 1440, height: 1000 }, locale: 'fr-BE', timezoneId: 'Europe/Brussels' });
  await ctx.route('**/api/cockpit/**', r => {
    const d = D.repondre(r.request().url());
    return d ? r.fulfill({ contentType: 'application/json', body: JSON.stringify(d) }) : r.fulfill({ status: 503, contentType: 'application/json', body: '{"error":"hors maquette"}' });
  });
  const p = await ctx.newPage();
  p.on('pageerror', e => console.log('dashboard :', e.message));
  await p.goto(BASE + 'public/dashboard/?shop=4&vue=promos', { waitUntil: 'networkidle' });
  await p.waitForSelector('[data-pmnew]');
  await p.click('[data-pmnew]');
  await p.click('[data-pmexemple="1m"]');
  const aller = async e => { await p.click(`.pm-wz [data-pmet="${e}"]`); await p.waitForTimeout(250); };
  await aller(1);
  await p.fill('[data-pmf="nom"]', 'Cookies 2 + 1 offert · après-midi');
  await p.fill('[data-pmf="description"]', 'Relancer le creux de 14 h à 17 h en semaine.');
  await aller(6);
  await p.click('[data-pmcradd]'); await p.waitForTimeout(200);
  for (const j of [1, 2, 3, 4, 5, 6, 7]) {
    const on = await p.$eval(`[data-pmj="0|${j}"]`, el => el.classList.contains('on'));
    if (on !== (j <= 5)) { await p.click(`[data-pmj="0|${j}"]`); await p.waitForTimeout(80); }
  }
  await p.fill('[data-pmcr="0|de"]', '14:00'); await p.fill('[data-pmcr="0|a"]', '17:00');
  const F = {};
  const prendre = () => p.evaluate(() => { document.querySelectorAll('input').forEach(i => i.setAttribute('value', i.value)); return { wz: document.querySelector('.pm-wz').outerHTML, etape: document.querySelector('.pm-etape').outerHTML, droite: document.querySelector('.pm-droite').outerHTML }; });
  for (const e of [1, 2, 3, 4, 6, 7]) { await aller(e); F['e' + e] = await prendre(); }
  await aller(5);
  await p.click('[data-pmbon="bon"]'); await p.waitForTimeout(250);
  const code = await p.$('[data-pmb="code"]');
  if (code) { await code.fill('AUTOMNE'); await p.waitForTimeout(150); }
  F.e5 = await prendre();
  await ctx.close();
  return F;
}

/* --- 2. Le rail : la section Promotions entre Produits et Marque & marketing ------------------------------------------ */
const navSt = (active, indent) => 'display:flex;align-items:center;justify-content:space-between;gap:8px;width:100%;text-align:left;border:none;cursor:pointer;font-family:var(--font-ui);font-size:' + (indent ? '12.5px' : '13px') + ';padding:' + (indent ? '7px 10px 7px 24px' : '8px 10px') + ';border-radius:8px;font-weight:300;' + (active ? 'background:rgba(141,29,44,0.08);color:var(--color-primary);font-weight:500' : 'background:transparent;color:var(--color-text' + (indent ? '-muted' : '') + ')');
const badge = b => b ? `<span style="min-width:18px;height:18px;padding:0 5px;border-radius:999px;background:var(--color-primary);color:#fff;font-size:10px;font-weight:500;display:inline-flex;align-items:center;justify-content:center">${b}</span>` : '';
const sous = l => `<span style="display:flex;align-items:center;gap:7px"><span style="font-size:9px;color:var(--color-text-muted);width:8px;display:inline-block">▸</span>${esc(l)}</span>`;
function rail(actif) {
  const S = [
    ['Pilotage', [['Résultat'], ['Dashboard magasin ↗']]],
    ['Franchisés · évaluation et suivi', [['Fiche & scoring'], ['Suivi des franchisés', 0, 1], ['Budget', 0, 1], ['Suivi journalier et opérations', 0, 1]]],
    // « Analyse magasin » perd son onglet Promotions : le Tableau d'analyse le remplace.
    ['Magasins', [['Analyse magasin'], ['Invendus et poubelle'], ['Inventaires'], ['Scouting — où ouvrir']]],
    ['Produits', [['Catalogue'], ['Comptoir · planogramme']]],
    ['Promotions', [['Édition', 3, 0, 'edition'], ['Tableau d’analyse', 2, 0, 'tableau']], true],
    ['Marque & marketing', [['Marketing ↗'], ['Projets de développement'], ['Fonds & Royalties']]],
    ['ERP franchisé', [['Prospection ↗'], ['Tablette vendeuses'], ['Fidélité']]],
    ['Application employé', [['Page admin'], ['Page franchisé (magasin)'], ['Application ↗'], ['API du module', 0, 1]]],
    ['Administration', [['Paramètres'], ['Journal & diagnostic']]]];
  const sec = ([titre, items, neuf]) => `<div style="display:flex;flex-direction:column;gap:2px${neuf ? ';background:rgba(242,201,160,.22);border-radius:10px;padding:8px 0 6px;margin:-8px -4px -6px' : ''}">
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.09em;color:var(--color-text);padding:0 10px 7px;display:flex;align-items:center;gap:8px">${esc(titre)}${neuf ? '<span class="mq-neuf">nouveau</span>' : ''}</div>
      ${items.map(([l, b, s, k]) => `<button style="${navSt(k && k === actif, false)}">${s ? sous(l) : `<span>${esc(l)}</span>`}${badge(b)}</button>`).join('')}</div>`;
  return `<aside class="shell-rail" style="width:236px;flex:0 0 236px;background:var(--color-surface);border-right:0.5px solid var(--color-border-tertiary);display:flex;flex-direction:column">
    <div style="padding:22px 20px 14px"><img src="../../../public/assets/img/logo.png" alt="L’Atelier" style="width:176px;max-width:100%;height:auto;display:block"></div>
    <div style="padding:0 12px 12px"><input placeholder="Rechercher partout…" style="width:100%;box-sizing:border-box;border:0.5px solid var(--color-border-secondary);background:var(--color-background-secondary);border-radius:9px;height:32px;padding:0 10px;font-family:var(--font-ui);font-size:12px"></div>
    <nav style="flex:1;padding:0 12px 16px;display:flex;flex-direction:column;gap:18px">${S.map(sec).join('')}</nav></aside>`;
}

/* La coque du cockpit : le rail, l'entête de l'écran (titre, sous-titre, à droite), le corps. */
function coque(actif, titre, sousTitre, droite, corps, apres) {
  return `<div class="shell" style="display:flex;min-height:100vh;background:var(--color-bg);font-family:var(--font-ui);color:var(--color-text)">${rail(actif)}
    <main style="flex:1;min-width:0;position:relative"><div class="shell-page" style="padding:26px 32px 60px;max-width:1460px">
      <header class="shell-head" style="display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin-bottom:18px">
        <div><div class="db-mini" style="margin-bottom:2px">Promotions ›</div><h1 style="font-family:var(--font-display);font-size:30px;font-weight:400;margin:0;line-height:1.15">${titre}</h1>
          <p class="shell-sub" style="font-size:13px;color:var(--color-text-muted);margin:5px 0 0;max-width:720px">${sousTitre}</p></div>
        <div style="display:flex;align-items:center;gap:10px;white-space:nowrap">${droite || ''}</div></header>
      ${corps}</div>${apres || ''}</main></div>`;
}

/* --- 3. Les briques communes ----------------------------------------------------------------------------------------- */
const R = D.R, MAGS = D.MAGS;
const ETAT = { en_caisse: ['En caisse', 'ok'], a_venir: ['À venir', 'a'], brouillon: ['Brouillon', 'b'], terminee: ['Terminée', 'b'] };
const statut = r => `<span class="pm-chip ${ETAT[r.statut][1]}">${ETAT[r.statut][0]}</span>`;
const coefC = (v, gras) => v == null ? mu('—') : `<span class="${v >= 2 ? 'ok' : 'ko'}" style="font-weight:700">${fX(v)}${v < 2 ? ' ⚠' : ''}</span>`;
const ouVaut = r => r.src === 'marque' ? (r.mags.length === MAGS.length ? 'Réseau · ' + r.mags.length + ' magasins' : r.mags.length + ' magasins') : r.mag;
const qui = r => r.src === 'marque' ? `<span class="pm-or r">Marque</span><div class="mq-mag">${esc(ouVaut(r))}</div>` : `<span class="mq-mag"><b>${esc(r.mag)}</b></span>`;
const canaux = r => (r.cpt ? '<span class="pm-bdg c">Comptoir</span>' : '') + (r.webOk ? '<span class="pm-bdg w">Webshop</span>' : '') + (r.seuil ? `<span class="pm-bdg s">dès ${fE(r.seuil)}</span>` : '');
const periode = r => r.du === '—' ? mu('à régler') : esc(r.du) + ' → ' + (r.au ? esc(r.au) : mu('sans fin'));
const bonC = r => r.bon ? `<span class="pm-chip a">bon · ${esc(r.bon)}</span>` : '';
const pc = (a, b) => b ? 100 * a / b : null;
const CA_RES = Object.values(D.CA30).reduce((a, v) => a + v, 0);
const caDe = r => r.src === 'marque' ? r.mags.reduce((a, m) => a + D.CA30[m], 0) : D.CA30[r.mag];
const tot = L => ({ fois: L.reduce((a, r) => a + (r.fois || 0), 0), ventes: L.reduce((a, r) => a + (r.ventes || 0), 0), remise: L.reduce((a, r) => a + (r.remise || 0), 0),
  web: (() => { const n = L.reduce((a, r) => a + (r.fois || 0), 0); return n ? L.reduce((a, r) => a + (r.fois || 0) * (r.web || 0), 0) / n : 0; })() });
const T = tot(R);
const N = k => R.filter(r => r.statut === k).length;
const sousRepere = R.filter(r => r.coef != null && r.coef < 2 && r.statut === 'en_caisse');
const sansPromo = MAGS.filter(m => !R.some(r => r.src === 'mag' && r.mag === m && r.statut === 'en_caisse'));

function kpis() {
  const k = (lab, v, s, cl) => `<div class="mq-kpi${cl ? ' ' + cl : ''}"><div class="db-lab">${lab}</div><div class="v">${v}</div><div class="s">${s}</div></div>`;
  return `<div class="mq-kpis">
    ${k('En caisse', N('en_caisse'), R.filter(r => r.statut === 'en_caisse' && r.src === 'marque').length + ' de la marque · ' + R.filter(r => r.statut === 'en_caisse' && r.src === 'mag').length + ' des magasins')}
    ${k('À venir · brouillons', N('a_venir') + ' · ' + N('brouillon'), 'les brouillons sont inactifs au panel')}
    ${k('Utilisations · 30 jours', nf(T.fois), nf(T.web, 0) + ' % sur le webshop')}
    ${k('Ventes en promotion', fE(T.ventes), nf(pc(T.ventes, CA_RES), 1) + ' % du CA du réseau · remise ' + fE(T.remise))}
    ${k('Sous le repère ×2', sousRepere.length, sousRepere.map(r => esc(r.mag)).join(', ') + ' · le lot coûte trop cher', 'ko')}
    ${k('Sans promotion à eux', sansPromo.length, esc(sansPromo.join(', ')) + ' · seulement celles de la marque', 'wa')}</div>`;
}
const relu = `<span class="db-mini">relu au panel à 10:42 <span class="pm-lien">↻ relire</span></span>`;
const btnNouv = `<button class="db-btn pm-gros">+ Nouveau bon</button><button class="db-btn prim pm-gros">+ Nouvelle promotion</button>`;
const filtres = (sel, extra) => `<div class="pm-filtres">
  <div class="db-ong">${[['actives', 'Actives', N('en_caisse')], ['a_venir', 'À venir', N('a_venir')], ['brouillons', 'Brouillons', N('brouillon')], ['terminees', 'Terminées', N('terminee')], ['toutes', 'Toutes', R.length]].map(([k, l, n]) => `<button class="${k === sel ? 'on' : ''}">${l} <small>${n}</small></button>`).join('')}</div>
  <select class="pm-in"><option>Tous les magasins</option></select><select class="pm-in"><option>Marque et magasins</option></select><select class="pm-in"><option>Toutes les sortes</option></select>
  <span class="pm-seg"><button>7 jours</button><button class="on">30 jours</button></span>
  <input class="pm-in pm-cherche" placeholder="Chercher une promotion…">${extra || '<button class="db-btn">Colonnes ▾</button>'}</div>`;
const dots = '<button class="pm-dots" aria-label="actions"><svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><circle cx="5" cy="12" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="19" cy="12" r="1.8"/></svg></button>';
const tri = (a, b) => (b.fois || -1) - (a.fois || -1) || a.nom.localeCompare(b.nom, 'fr');
const ligne = (r, sansMag) => `<tr>${sansMag ? '' : `<td>${qui(r)}</td>`}<td class="nm"><b>${esc(r.nom)}</b> ${bonC(r)}<div class="db-mini mq-regle">${r.achete} → ${r.recoit}${r.cren ? ' · ' + esc(r.cren) : ''}</div></td><td>${canaux(r)}</td>
  <td>${periode(r)}</td><td>${statut(r)}</td><td class="r"><b>${r.fois == null ? mu('—') : nf(r.fois)}</b></td><td class="r">${fE(r.ventes)}</td><td class="r">${r.ventes ? fP(pc(r.ventes, caDe(r))) : mu('—')}</td>
  <td class="r">${r.remise ? '−' + fE(r.remise) : mu('—')}</td><td class="r">${r.fois ? nf(r.web, 0) + ' %' : mu('—')}</td><td class="r">${coefC(r.coef)}</td><td class="pm-act">${dots}</td></tr>`;
const entete = sansMag => `<thead><tr>${sansMag ? '' : '<th>Magasin</th>'}<th>Promotion · le client achète → il reçoit</th><th>Où</th><th>Période</th><th>Statut</th><th class="r">Fois ▾</th><th class="r">Ventes</th><th class="r" title="ventes des lignes en promotion ÷ CA des magasins concernés">Poids CA</th><th class="r">Remise</th><th class="r">Web</th><th class="r" title="prix payé HT ÷ coût matière, le cas le plus serré">Coef.</th><th></th></tr></thead>`;

/* Le formulaire : l'entête de l'assistant, au cockpit. Le magasin n'est plus celui de l'adresse : il se choisit. */
function teteEdition(opts) {
  const o = opts || {};
  return `<div class="pm-tete" style="margin-top:0"><div><div class="db-mini"><span class="pm-lien">‹ Tableau d’analyse</span></div><div class="pm-titre">Nouvelle promotion « X achetés + Y »</div></div><span style="flex:1"></span>
    <div class="pm-f"><span class="db-lab">Remplir un exemple</span><span class="pm-seg"><button>1 semaine</button><button>1 mois</button><button>1 an</button><button>Tout le temps</button></span></div>
    ${o.sansMagasin ? '' : `<div class="pm-f"><span class="db-lab">Magasin</span><div class="pm-in pm-mag mq-select"><b>L’Atelier by - Gosselies</b><span>▾</span></div></div>`}
    <button class="db-btn pm-gros">Garder en brouillon</button></div>`;
}

/* --- 4. Les écrans --------------------------------------------------------------------------------------------------- */
const E = {};

/* A — deux pages pleines, au plus près de l'existant. */
E['a-tableau'] = () => coque('tableau', 'Tableau d’analyse', 'Toutes les promotions de tous les magasins — celles de la marque et celles des magasins —, lues au panel, et ce qu’elles ont fait sur 30 jours.', relu + btnNouv,
  kpis() + `<div class="db-card pm-liste">${filtres('toutes')}<div class="pm-large"><table class="pm-tab pm-tab2 mq-tab">${entete(false)}<tbody>${R.slice().sort(tri).map(r => ligne(r)).join('')}</tbody>
    <tfoot><tr><td colspan="5"><b>${R.length} promotions</b> · ${MAGS.length} magasins</td><td class="r"><b>${nf(T.fois)}</b></td><td class="r"><b>${fE(T.ventes)}</b></td><td class="r"><b>${fP(pc(T.ventes, CA_RES))}</b></td><td class="r"><b>−${fE(T.remise)}</b></td><td class="r"><b>${nf(T.web, 0)} %</b></td><td></td><td></td></tr></tfoot></table></div></div>`);

E['a-edition'] = F => coque('edition', 'Édition', 'Le formulaire du dashboard magasin, tel quel, avec le magasin à choisir. La promotion s’écrit au panel, réservée au magasin, sans validation.', '',
  `<div class="mq-reprendre"><span class="db-lab">Brouillons à reprendre</span>${R.filter(r => r.statut === 'brouillon').map(r => `<span class="mq-pill"><b>${esc(r.nom)}</b> · ${esc(r.mag)}</span>`).join('')}<span class="pm-lien" style="margin-left:auto">en caisse : ⋯ › Modifier dans le Tableau d’analyse</span></div>
  ${teteEdition()}${F.e3.wz}<div class="pm-grille">${F.e3.etape}${F.e3.droite}</div>`);

/* B — magasin par magasin : le tableau groupé, l'édition à côté de la liste du magasin. */
E['b-tableau'] = () => {
  const groupe = (titre, L, ouvert, note) => {
    const t = tot(L), enC = L.filter(r => r.statut === 'en_caisse').length, br = L.filter(r => r.statut === 'brouillon').length, cmin = L.filter(r => r.coef != null && r.statut === 'en_caisse').reduce((a, r) => Math.min(a, r.coef), 9);
    const ca = titre === 'marque' ? CA_RES : D.CA30[titre], poids = pc(t.ventes, ca);
    return `<tr class="mq-gr${ouvert ? ' on' : ''}"><td class="nm"><span class="mq-chev">${ouvert ? '▾' : '▸'}</span><b>${titre === 'marque' ? 'Marque · tout le réseau' : esc(titre)}</b>${note ? ` <span class="pm-chip a">${note}</span>` : ''}</td>
      <td>${enC ? `<span class="pm-chip ok">${enC} en caisse</span>` : mu('aucune en caisse')}${br ? ` <span class="pm-chip b">${br} brouillon${br > 1 ? 's' : ''}</span>` : ''}</td>
      <td class="r"><b>${t.fois ? nf(t.fois) : mu('—')}</b></td><td class="r">${t.ventes ? fE(t.ventes) : mu('—')}</td>
      <td class="mq-pbar">${poids ? `<span class="mq-bar"><i style="width:${Math.min(100, poids * 25)}%"></i></span><b>${fP(poids)}</b>` : mu('—')}</td>
      <td class="r">${t.remise ? '−' + fE(t.remise) : mu('—')}</td><td class="r">${cmin < 9 ? coefC(cmin) : mu('—')}</td><td class="pm-act"><button class="db-btn">+ Promotion</button></td></tr>
      ${ouvert ? L.slice().sort(tri).map(r => `<tr class="mq-sl"><td class="nm"><b>${esc(r.nom)}</b> ${bonC(r)}<div class="db-mini">${r.achete} · ${r.recoit}${r.cren ? ' · ' + esc(r.cren) : ''}</div></td><td>${statut(r)} <span class="db-mini">${periode(r)}</span></td>
        <td class="r">${r.fois == null ? mu('—') : nf(r.fois)}</td><td class="r">${fE(r.ventes)}</td><td class="mq-pbar">${r.ventes ? `<span class="mq-bar s"><i style="width:${Math.min(100, pc(r.ventes, caDe(r)) * 25)}%"></i></span>${fP(pc(r.ventes, caDe(r)))}` : mu('—')}</td><td class="r">${r.remise ? '−' + fE(r.remise) : mu('—')}</td><td class="r">${coefC(r.coef)}</td><td class="pm-act">${dots}</td></tr>`).join('') : ''}`;
  };
  const marque = R.filter(r => r.src === 'marque');
  const corps = groupe('marque', marque, false) + MAGS.map(m => {
    const L = R.filter(r => r.src === 'mag' && r.mag === m);
    return groupe(m, L, m === 'Gosselies' || m === 'Liège', !L.some(r => r.statut === 'en_caisse') ? 'rien à lui en caisse' : (L.some(r => r.coef < 2 && r.statut === 'en_caisse') ? 'coef. sous ×2' : ''));
  }).join('');
  return coque('tableau', 'Tableau d’analyse', 'Magasin par magasin : ce que ses promotions font sur 30 jours, à côté de celles de la marque. Une ligne s’ouvre sur ses promotions ; « + Promotion » ouvre l’édition sur ce magasin.', relu + btnNouv,
    `<div class="db-card pm-liste">${filtres('toutes', '<span class="pm-seg" style="margin-left:auto"><button class="on">Par magasin</button><button>À plat</button></span>')}<div class="pm-large"><table class="pm-tab pm-tab2 mq-tab mq-grp">
      <thead><tr><th>Magasin · promotion</th><th>Statut</th><th class="r">Fois ▾</th><th class="r">Ventes</th><th>Poids dans le CA du magasin</th><th class="r">Remise</th><th class="r">Coef. le plus bas</th><th></th></tr></thead><tbody>${corps}</tbody>
      <tfoot><tr><td><b>Réseau</b> · ${R.length} promotions</td><td>${N('en_caisse')} en caisse</td><td class="r"><b>${nf(T.fois)}</b></td><td class="r"><b>${fE(T.ventes)}</b></td><td><b>${fP(pc(T.ventes, CA_RES))}</b></td><td class="r"><b>−${fE(T.remise)}</b></td><td></td><td></td></tr></tfoot></table></div></div>`);
};

E['b-edition'] = F => {
  const L = R.filter(r => r.src === 'mag' && r.mag === 'Gosselies').concat(R.filter(r => r.src === 'marque' && r.statut !== 'terminee'));
  const item = r => `<div class="mq-it"><div class="mq-ith"><b>${esc(r.nom)}</b>${statut(r)}</div><div class="db-mini">${r.achete} · ${r.recoit}</div><div class="mq-itp"><span>${r.src === 'marque' ? '<span class="pm-or r">Marque</span> ' : ''}${periode(r)}</span>${coefC(r.coef)}</div></div>`;
  return coque('edition', 'Édition', 'Un magasin à la fois : à gauche ses promotions — on en reprend une ou on en crée une —, à droite le formulaire du dashboard.', '',
    `<div class="mq-md"><div class="db-card mq-gauche"><div class="pm-f"><span class="db-lab">Magasin</span><div class="pm-in pm-mag mq-select" style="min-width:0"><b>L’Atelier by - Gosselies</b><span>▾</span></div></div>
      <div style="display:flex;gap:8px"><button class="db-btn prim pm-gros" style="flex:1">+ Nouvelle promotion</button><button class="db-btn pm-gros">+ Bon</button></div>
      <div class="mq-it on"><div class="mq-ith"><b>Cookies 2 + 1 offert · après-midi</b><span class="pm-chip r">en cours d’écriture</span></div><div class="db-mini">2 × Cookies · le 3<sup>e</sup> offert</div><div class="mq-itp"><span>27/10 → 26/11</span>${coefC(2.99)}</div></div>
      <div class="db-lab" style="margin-top:6px">Ses promotions · ${L.length}</div>${L.map(item).join('')}</div>
      <div class="mq-droite">${F.e2.wz}<div class="pm-grille">${F.e2.etape}${F.e2.droite}</div></div></div>`);
};

/* C — une promotion, plusieurs magasins : la matrice, et l'étape « Magasins » rendue à l'assistant. */
E['c-tableau'] = () => {
  // Une ligne par mécanique : la même promotion dans plusieurs magasins tient sur une ligne.
  const lignes = [];
  R.forEach(r => {
    if (r.src === 'marque') { lignes.push({ r, cel: Object.fromEntries(r.mags.map((m, i) => [m, Object.assign({}, r, { fois: r.par ? r.par[i] : null })])) }); return; }
    const l = lignes.find(x => x.r.src === 'mag' && x.r.nom === r.nom);
    if (l) { l.cel[r.mag] = r; l.multi = true; } else { lignes.push({ r, cel: { [r.mag]: r } }); }
  });
  const max = Math.max(...R.filter(r => r.src === 'mag').map(r => r.fois || 0), ...R.filter(r => r.par).flatMap(r => r.par));
  const cel = c => {
    if (!c) { return '<td class="mq-cx vide">·</td>'; }
    if (c.statut === 'brouillon') { return '<td class="mq-cx"><span class="pm-chip b">brouillon</span></td>'; }
    if (c.statut === 'a_venir') { return `<td class="mq-cx"><span class="pm-chip a">${esc(c.du)}</span></td>`; }
    const a = c.fois ? 0.10 + 0.55 * c.fois / max : 0;
    return `<td class="mq-cx${c.statut === 'terminee' ? ' fin' : ''}" style="background:rgba(141,29,44,${a.toFixed(2)});color:${a > .4 ? '#fff' : 'inherit'}"><b>${nf(c.fois)}</b>${c.coef != null && c.coef < 2 ? '<i class="mq-alerte" title="coef. sous ×2"></i>' : ''}${c.statut === 'terminee' ? '<small>fini</small>' : ''}</td>`;
  };
  const parMag = m => R.filter(r => (r.src === 'mag' && r.mag === m) || (r.src === 'marque' && r.mags.includes(m)));
  const foisMag = m => parMag(m).reduce((a, r) => a + (r.src === 'marque' ? (r.par ? r.par[r.mags.indexOf(m)] : 0) : (r.fois || 0)), 0);
  return coque('tableau', 'Tableau d’analyse', 'Qui a quoi : une ligne par promotion, une colonne par magasin. La même mécanique dans plusieurs magasins tient sur une ligne — on voit où elle marche et où elle manque.', relu + btnNouv,
    kpis() + `<div class="db-card pm-liste"><div class="pm-filtres"><span class="db-lab">Dans les cases</span><span class="pm-seg"><button class="on">Fois</button><button>Ventes</button><button>Remise €</button><button>Coef.</button></span>
      <span class="pm-seg"><button>7 jours</button><button class="on">30 jours</button></span><select class="pm-in"><option>Toutes les sortes</option></select><input class="pm-in pm-cherche" placeholder="Chercher une promotion…">
      <span class="cn-leg mq-leg"><span><i style="background:rgba(141,29,44,.2)"></i>peu</span><span><i style="background:rgba(141,29,44,.65)"></i>beaucoup</span><span><i class="mq-alerte" style="position:static;display:inline-block"></i>coef. sous ×2</span></span></div>
      <div class="pm-large"><table class="pm-tab mq-mx"><thead><tr><th>Promotion</th>${MAGS.map(m => `<th class="c">${esc(m)}<div class="db-mini" style="text-transform:none;letter-spacing:0">${parMag(m).filter(r => r.statut === 'en_caisse').length} en caisse</div></th>`).join('')}<th class="r">Réseau</th><th class="r">Coef.</th></tr></thead>
      <tbody>${lignes.map(l => { const L = Object.values(l.cel), f = L.reduce((a, c) => a + (c.fois || 0), 0), cmin = L.reduce((a, c) => c.coef != null ? Math.min(a, c.coef) : a, 9);
        return `<tr><td class="nm"><b>${esc(l.r.nom)}</b> ${l.r.src === 'marque' ? '<span class="pm-or r">Marque</span>' : (l.multi ? `<span class="pm-or m">${L.length} magasins</span>` : '')}<div class="db-mini">${l.r.achete} · ${l.r.recoit}</div></td>${MAGS.map(m => cel(l.cel[m])).join('')}<td class="r"><b>${f ? nf(f) : mu('—')}</b></td><td class="r">${cmin < 9 ? coefC(cmin) : mu('—')}</td></tr>`; }).join('')}</tbody>
      <tfoot><tr><td><b>Fois par magasin</b></td>${MAGS.map(m => `<td class="c"><b>${nf(foisMag(m))}</b></td>`).join('')}<td class="r"><b>${nf(T.fois)}</b></td><td></td></tr>
      <tr><td class="db-mini">Ventes en promotion, part du CA</td>${MAGS.map(m => { const v = parMag(m).reduce((a, r) => a + (r.src === 'marque' ? (r.ventes || 0) * (r.par ? r.par[r.mags.indexOf(m)] / r.fois : 0) : (r.ventes || 0)), 0); return `<td class="c db-mini">${fP(pc(v, D.CA30[m]))}</td>`; }).join('')}<td class="r db-mini">${fP(pc(T.ventes, CA_RES))}</td><td></td></tr></tfoot></table></div></div>`);
};

E['c-edition'] = F => {
  const etapes = ['Magasins', 'Nom', 'Ce qu’on achète (X)', 'Ce qu’on reçoit (Y)', 'Conditions du ticket', 'Bon de réduction', 'Période', 'Vérifier et enregistrer'];
  const wz = `<div class="pm-wz">${etapes.map((n, i) => `<button class="e${i === 0 ? ' on' : (i < 7 ? ' fait' : '')}"><span class="n">${i > 0 && i < 7 ? '✓' : i + 1}</span>${n}</button>`).join('<span class="sep"></span>')}</div>`;
  const CO = { Gosselies: [2.99, 1.83], Halle: [2.99, 1.83], Nivelles: [3.18, 1.72], Liège: [1.92, 2.85], Namur: [2.74, 2.0], Mons: [3.05, 1.79], Waterloo: [2.88, 1.9], Wavre: [2.61, 2.1], Uccle: [2.52, 2.17] };
  const choisis = ['Gosselies', 'Halle', 'Nivelles', 'Mons', 'Namur'];
  const enC = m => R.filter(r => r.src === 'mag' && r.mag === m && r.statut === 'en_caisse');
  const carte = m => { const on = choisis.includes(m), c = CO[m][0], dbl = enC(m).find(r => /Cookies 2 \+ 1/.test(r.nom));
    return `<button class="pm-opt mq-mopt${on ? ' on' : ''}"><span class="mq-ck">${on ? '✓' : ''}</span><b>${esc(m)}</b><small>${enC(m).length ? enC(m).length + ' promotion' + (enC(m).length > 1 ? 's' : '') + ' à lui en caisse' : 'rien à lui en caisse'}</small>
      <span class="mq-mc">coef. ici ${coefC(c)}</span>${dbl ? `<span class="mq-dbl">déjà « ${esc(dbl.nom)} » jusqu’au ${esc(dbl.au)}</span>` : ''}</button>`; };
  const etape = `<div class="db-card pm-etape"><div class="pm-step"><div class="pm-sh"><span class="n">1</span><div><div class="db-lab">Étape 1 · où elle vaut</div><div class="pm-h2">Magasins</div></div></div>
    <div class="mq-ligne"><span class="pm-seg"><button>Un magasin</button><button class="on">Plusieurs magasins</button><button>Tout le réseau</button></span><span class="pm-lien">Comme « Cookies 2 + 1 offert » (Gosselies, Halle)</span><span class="db-mini" style="margin-left:auto">${choisis.length} sur ${MAGS.length}</span></div>
    <div class="mq-mgrille">${MAGS.map(carte).join('')}</div>
    <div class="pm-f" style="margin-top:14px"><label>Au panel</label><span class="pm-seg"><button class="on">Une promotion par magasin</button><button>Une seule, « magasins choisis »</button></span>
      <span class="db-mini">Une par magasin : chacune réservée à son magasin, comme aujourd’hui — elle se suit, se modifie et s’arrête à part. Les ${choisis.length} portent le même nom et se retrouvent sur une ligne du tableau.</span></div>
    <div class="pm-warn">Gosselies et Halle ont déjà « Cookies 2 + 1 offert » en caisse jusqu’au 13/11 et au 15/11 : la nouvelle s’ajoute — les deux se cumulent-elles en caisse ? Retirez-les ou décalez la période.</div>
    <div class="pm-fixe ko"><span class="pm-chip r">×1,92 ✕</span><span>Liège, non coché : ses cookies coûtent plus cher (coût de recette du magasin) — le lot y passerait sous ×2.</span></div></div>
    <div class="pm-piedw"><span></span><button class="db-btn prim pm-gros">Suivant : nom ›</button></div></div>`;
  const droite = `<div class="pm-droite"><div class="db-card pm-c"><div class="db-lab">Récapitulatif</div><div class="pm-phrase g">2 achetés, le 3<sup>e</sup> offert</div><div class="db-mini">5 magasins · du 27/10 au 26/11 23:59 · lun.–ven. 14–17 h</div>
      <div class="pm-ck">✓ Magasins : Gosselies, Halle, Nivelles, Mons, Namur</div><div class="pm-ck">✓ Déclencheur : 2 × Cookies</div><div class="pm-ck">✓ Avantage : 1 pièce offerte, la moins chère</div><div class="pm-ck wa">! Déjà en caisse : Gosselies, Halle</div></div>
    <div class="db-card pm-c"><div class="pm-ligne"><span class="db-lab">Le coefficient, magasin par magasin</span><span class="pm-chip ok">repère ×2</span></div>
      <table class="pm-tab mq-ctab"><thead><tr><th>Magasin</th><th class="r">Payé HT</th><th class="r">Coût matière</th><th class="r">Coef.</th></tr></thead><tbody>
      ${choisis.map(m => `<tr><td><b>${esc(m)}</b></td><td class="r">${nf(CO[m][0] * CO[m][1], 2)} €</td><td class="r">${nf(CO[m][1], 2)} €</td><td class="r">${coefC(CO[m][0])}</td></tr>`).join('')}
      <tr class="mu"><td>Liège <span class="db-mini">non coché</span></td><td class="r">${nf(1.92 * 2.85, 2)} €</td><td class="r">2,85 €</td><td class="r">${coefC(1.92)}</td></tr></tbody></table>
      <div class="db-mini" style="margin-top:8px">Le cas le plus serré de chaque magasin, à ses prix et ses coûts de recette d’aujourd’hui (sinon ceux du réseau).</div></div></div>`;
  return coque('edition', 'Édition', 'Le formulaire du dashboard, avec une étape en tête : les magasins. Une même promotion part dans un, plusieurs ou tous les magasins ; le coefficient se lit magasin par magasin.', '',
    teteEdition({ sansMagasin: true }) + wz + `<div class="pm-grille">${etape}${droite}</div>`);
};

/* D — le tableau d'abord, l'édition en panneau par-dessus. */
function graphe() {
  const L = R.filter(r => r.fois).sort(tri).slice(0, 9);
  const max = Math.max(...L.map(r => r.fois));
  const seg = r => { const w = Math.round(r.fois * r.web / 100), c = r.fois - w, cc = Math.round(w * .6), lv = w - cc;
    return [['c', c], ['w', cc], ['l', lv]].map(([k, v]) => v ? `<i class="${k}" style="width:${100 * v / r.fois}%"><em>${100 * v / r.fois * (100 * r.fois / max) / 100 >= 6 ? nf(v) : ''}</em></i>` : '').join(''); };
  const rows = L.map(r => `<div class="cn-r"><div class="cn-rn"><b>${esc(r.nom)}</b><span class="db-mini">${esc(ouVaut(r))} · ${nf(r.fois)} fois · ${nf(r.web, 0)} % web</span></div><div class="cn-rb"><span class="cn-bar big" style="width:${Math.max(3, 100 * r.fois / max)}%">${seg(r)}</span></div><div class="cn-rc n">${coefC(r.coef)}</div></div>`).join('');
  // Le jour par jour du réseau, comptoir en bas, webshop au-dessus.
  let s = 11; const rnd = () => (s = (s * 9301 + 49297) % 233280) / 233280;
  const J = Array.from({ length: 30 }, (_, i) => { const t = new Date('2026-10-26T12:00:00'); t.setDate(t.getDate() - 29 + i); const we = [0, 6].includes(t.getDay()); return { d: t, c: Math.round((we ? 70 : 52) + 26 * rnd() + (i > 27 ? 30 : 0)), w: Math.round(5 + 9 * rnd()) }; });
  const W = 520, H = 160, mx = 130, bw = (W - 32) / J.length, y = v => H - v / mx * (H - 12);
  const svg = `<svg class="cn-svg" viewBox="0 0 ${W} ${H + 22}">${[0, 25, 50, 75, 100, 125].map(g => `<line x1="28" x2="${W}" y1="${y(g)}" y2="${y(g)}" stroke="#eee7de"/><text x="22" y="${y(g) + 3}" text-anchor="end" font-size="9.5" fill="#8a8178">${g}</text>`).join('')}
    ${J.map((j, i) => { const X = 32 + i * bw, hc = H - y(j.c), hw = H - y(j.w); return `<rect x="${X + 1}" y="${H - hc}" width="${bw - 4}" height="${hc}" fill="#b8ad9f" rx="1.5"/><rect x="${X + 1}" y="${H - hc - hw - 1}" width="${bw - 4}" height="${hw}" fill="#1f5f8b" rx="1.5"/>${i % 7 === 1 ? `<text x="${X + bw / 2}" y="${H + 15}" text-anchor="middle" font-size="9.5" fill="#8a8178">${String(j.d.getDate()).padStart(2, '0')}/${String(j.d.getMonth() + 1).padStart(2, '0')}</text>` : ''}`; }).join('')}</svg>`;
  return `<div class="db-card cn-card"><div class="ct"><span class="cn-h">Où les promotions sont utilisées · le réseau</span><span class="fi-seg"><button class="on">En nombre</button><button>En remise €</button></span><span class="fi-seg"><button>7 jours</button><button class="on">30 jours</button></span>
      <select class="pm-in" style="min-height:30px"><option>Tous les magasins</option></select><span class="db-mini">${nf(T.web, 0)} % sur le webshop · ${nf(T.fois)} utilisations</span></div>
    <div class="cn-deux"><div><div class="cn-r cn-th"><span class="db-lab">Promotion</span><span class="db-lab">Par canal</span><span class="db-lab n">Coef. du lot</span></div>${rows}</div>
      <div><div class="db-lab">Jour par jour · toutes promotions, tout le réseau</div><div class="db-mini">comptoir en bas, webshop au-dessus · Halloween depuis le 25/10</div>${svg}</div></div>
    <div class="cn-pied db-mini"><span class="cn-leg"><span><i class="c"></i>Comptoir</span><span><i class="w"></i>Click &amp; collect</span><span><i class="l"></i>Livraison</span></span><span class="cn-note">La carte de l’onglet Promotions du dashboard, à l’échelle du réseau ; un magasin choisi la ramène à lui.</span></div></div>`;
}
function tableauD(sel) {
  const L = R.filter(r => r.statut === 'en_caisse').sort(tri);
  return `<div class="db-card pm-liste">${filtres('actives')}<div class="pm-large"><table class="pm-tab pm-tab2 mq-tab">${entete(false)}<tbody>${L.map(r => ligne(r).replace('<tr>', r.id === sel ? '<tr class="mq-sel">' : '<tr>')).join('')}</tbody></table></div></div>`;
}
E['d-tableau'] = () => coque('tableau', 'Tableau d’analyse', 'D’abord ce que les promotions font dans le réseau, puis la liste. Une ligne ou « + Nouvelle promotion » ouvre l’édition dans un panneau, sans quitter le tableau.', relu + btnNouv,
  graphe() + tableauD());

E['d-edition'] = F => {
  const tiroir = `<div class="mq-voile"></div><aside class="mq-tiroir"><div class="mq-tt"><div><div class="db-mini">Promotions › Édition</div><div class="pm-titre" style="font-size:24px">Nouvelle promotion « X achetés + Y »</div></div><span class="mq-x">✕</span></div>
    <div class="mq-ligne" style="margin-bottom:12px"><div class="pm-f" style="flex:1"><span class="db-lab">Magasin</span><div class="pm-in pm-mag mq-select" style="min-width:0"><b>L’Atelier by - Gosselies</b><span>▾</span></div></div>
      <div class="pm-f"><span class="db-lab">Remplir un exemple</span><span class="pm-seg"><button>1 semaine</button><button>1 mois</button><button>1 an</button><button>Tout le temps</button></span></div></div>
    ${F.e5.wz}<div class="pm-grille">${F.e5.etape}${F.e5.droite}</div></aside>`;
  return coque('edition', 'Tableau d’analyse', 'D’abord ce que les promotions font dans le réseau, puis la liste. Une ligne ou « + Nouvelle promotion » ouvre l’édition dans un panneau, sans quitter le tableau.', relu + btnNouv,
    graphe() + tableauD(21), tiroir);
};

/* --- 5. La page, les captures, la planche ---------------------------------------------------------------------------- */
const ECRANS = [
  ['a-tableau', 'A · Tableau d’analyse', 'Deux pages pleines'], ['a-edition', 'A · Édition', 'Deux pages pleines'],
  ['b-tableau', 'B · Tableau d’analyse', 'Magasin par magasin'], ['b-edition', 'B · Édition', 'Magasin par magasin'],
  ['c-tableau', 'C · Tableau d’analyse', 'Une promotion, plusieurs magasins'], ['c-edition', 'C · Édition', 'Une promotion, plusieurs magasins'],
  ['d-tableau', 'D · Tableau d’analyse', 'Le tableau d’abord, l’édition en panneau'], ['d-edition', 'D · Édition', 'Le tableau d’abord, l’édition en panneau']];
const CSS = `
html,body{overflow:auto!important;background:var(--color-bg)}
.mq-bar-maq{position:sticky;top:0;z-index:50;display:flex;gap:6px;flex-wrap:wrap;align-items:center;padding:8px 12px;background:#222;color:#fff;font:600 12px var(--font-ui)}
.mq-bar-maq a{color:#fff;opacity:.7;padding:5px 9px;border-radius:7px;text-decoration:none}.mq-bar-maq a.on{opacity:1;background:#8D1D2C}
body.capture .mq-bar-maq{display:none}
.mq-ecran{display:none}.mq-ecran.on{display:block}
.mq-neuf{font:600 9.5px var(--font-ui);letter-spacing:.04em;text-transform:none;background:var(--color-secondary);color:var(--color-on-abricot);border-radius:6px;padding:2px 7px}
.mq-kpis{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px;margin-bottom:12px}
.mq-kpi{background:var(--color-surface);border:.5px solid var(--color-border-tertiary);border-radius:12px;padding:12px 14px}
.mq-kpi .v{font-family:var(--font-display);font-size:28px;line-height:1.1;margin-top:4px}.mq-kpi .s{font-size:11px;color:var(--color-text-muted);margin-top:2px}
.mq-kpi.ko .v{color:#A61E1E}.mq-kpi.wa .v{color:#8a5a13}
.mq-tab td{white-space:nowrap}.mq-tab td.nm{white-space:normal;min-width:240px}.mq-regle{font-size:11.5px;margin-top:2px;color:#5d554d}.mq-regle sup{font-size:8px}.mq-tab tfoot td{border-bottom:none;padding-top:12px}
.mq-tab tr.mq-sel td{background:#fdf3f4}.mq-tab tr.mq-sel td:first-child{box-shadow:inset 3px 0 0 #8D1D2C}
.mq-mag{font-size:12px}.pm-bdg{margin-right:4px}
.mq-select{display:flex;align-items:center;justify-content:space-between;gap:12px;background:#fff;cursor:pointer}.mq-select span{color:var(--color-primary);font-size:12px}
.mq-reprendre{display:flex;align-items:center;gap:8px;flex-wrap:wrap;background:var(--color-surface);border:.5px solid var(--color-border-tertiary);border-radius:12px;padding:10px 14px;margin-bottom:14px}
.mq-pill{display:inline-flex;gap:4px;padding:5px 10px;border-radius:8px;background:var(--color-background-secondary);font-size:12px;cursor:pointer}
.mq-grp tr.mq-gr td{background:#faf7f3;border-bottom:.5px solid var(--color-border-tertiary)}.mq-grp tr.mq-gr.on td{background:#f4eee6}
.mq-grp tr.mq-sl td:first-child{padding-left:34px}.mq-chev{display:inline-block;width:16px;color:var(--color-text-muted);font-size:10px}
.mq-pbar{white-space:nowrap}.mq-bar{display:inline-block;width:90px;height:8px;border-radius:4px;background:var(--color-background-secondary);margin-right:8px;vertical-align:middle;overflow:hidden}.mq-bar i{display:block;height:100%;background:#8D1D2C}.mq-bar.s i{background:#c99aa1}
.mq-md{display:grid;grid-template-columns:300px minmax(0,1fr);gap:14px;align-items:start}
.mq-gauche{padding:14px;display:flex;flex-direction:column;gap:8px;margin:0}
.mq-it{border:.5px solid var(--color-border-tertiary);border-radius:10px;padding:9px 11px;background:#fff}.mq-it.on{border:1.5px solid var(--color-primary);background:#fdf8f6}
.mq-ith{display:flex;justify-content:space-between;gap:8px;align-items:flex-start}.mq-ith b{font-size:12.5px}.mq-itp{display:flex;justify-content:space-between;margin-top:4px;font-size:11.5px}
.mq-droite .pm-wz .e:not(.on),.mq-tiroir .pm-wz .e:not(.on){font-size:0;gap:0;padding:6px}.mq-droite .pm-wz .e .n{font-size:11px}
.mq-ligne{display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;margin-bottom:12px}
.mq-mgrille{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}
.mq-mopt{position:relative;padding:10px 12px 10px 38px;display:flex;flex-direction:column;gap:2px}.mq-mopt .mq-ck{position:absolute;left:11px;top:11px;width:18px;height:18px;border-radius:5px;border:1.5px solid var(--color-border-secondary);display:flex;align-items:center;justify-content:center;font-size:11px;color:#fff}
.mq-mopt.on .mq-ck{background:var(--color-primary);border-color:var(--color-primary)}.mq-mc{font-size:11.5px;margin-top:4px}.mq-dbl{font-size:10.5px;color:#8a5a13;background:#FBF3DC;border-radius:6px;padding:2px 6px;margin-top:4px;align-self:flex-start}
.mq-ctab td,.mq-ctab th{padding:7px 6px}.mq-ctab tr.mu td{color:var(--color-text-muted)}
.mq-mx th.c,.mq-mx td.c{text-align:center}.mq-mx td.nm{min-width:250px}
.mq-cx{text-align:center;position:relative;min-width:84px;border-left:2px solid #fff;font-variant-numeric:tabular-nums}.mq-cx.vide{color:#cfc6bb}.mq-cx small{display:block;font-size:9.5px;opacity:.8}.mq-cx.fin{opacity:.65}
.mq-alerte{position:absolute;top:5px;right:5px;width:8px;height:8px;border-radius:50%;background:#e8a317;box-shadow:0 0 0 2px #fff}
.mq-leg{margin-left:auto;font-size:11px}.mq-leg i{width:12px;height:12px}
.mq-voile{position:absolute;inset:0;background:rgba(34,34,34,.38);z-index:5}
.mq-tiroir{position:absolute;top:0;right:0;bottom:0;width:780px;background:var(--color-bg);z-index:6;box-shadow:-12px 0 32px rgba(0,0,0,.18);padding:20px 22px;overflow:hidden}
.mq-tiroir .pm-grille{grid-template-columns:1fr}.mq-tiroir .pm-droite .db-card:nth-child(n+2){display:none}
.mq-tiroir .pm-wz .e:not(.on){font-size:0;gap:0;padding:6px}.mq-tiroir .pm-wz .e .n{font-size:11px}
.mq-tt{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px}.mq-x{width:34px;height:34px;border-radius:50%;background:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer}
/* La planche */
.mq-pl{padding:28px 32px;max-width:1700px}.mq-pl h1{font-family:var(--font-display);font-weight:400;font-size:34px;margin:0}
.mq-pl-g{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;margin-top:18px}
.mq-pl-c{background:#fff;border-radius:14px;padding:16px;border:.5px solid var(--color-border-tertiary)}.mq-pl-c h2{font-family:var(--font-display);font-weight:400;font-size:24px;margin:0 0 4px}
.mq-pl-im{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:10px 0}.mq-pl-im img{width:100%;height:230px;object-fit:cover;object-position:top left;border-radius:8px;border:.5px solid var(--color-border-tertiary)}
.mq-pl-c ul{margin:6px 0 0;padding-left:18px;font-size:12.5px;line-height:1.55}`;

const PLANCHE = [
  ['A', 'Deux pages pleines', 'Au plus près de l’existant : le tableau du dashboard avec une colonne Magasin et les chiffres sur 30 jours ; l’édition en page pleine, le magasin dans une liste.',
    ['le formulaire ne bouge pas : seul le magasin devient un choix', 'tableau plat, triable : le plus simple à coder', 'les brouillons à reprendre en tête de l’édition'], ['une promotion pour plusieurs magasins = la refaire magasin par magasin']],
  ['B', 'Magasin par magasin', 'Le tableau groupé par magasin, avec ses totaux ; l’édition à côté de la liste des promotions du magasin choisi.',
    ['on voit d’un coup d’œil les magasins sans promotion ou sous ×2', 'en éditant, on a sous les yeux ce qui tourne déjà dans le magasin'], ['le formulaire a moins de largeur', 'toujours un magasin à la fois']],
  ['C', 'Une promotion, plusieurs magasins', 'La matrice promotions × magasins ; l’assistant retrouve une étape « Magasins » en tête, avec le coefficient de chaque magasin.',
    ['déployer une mécanique qui marche dans d’autres magasins en un envoi', 'repérer les doublons (déjà en caisse) avant d’envoyer'], ['une étape de plus ; écrire N promotions au panel (une par magasin) ou une « magasins choisis » : à trancher', 'matrice creuse tant que chaque magasin a ses propres promotions']],
  ['D', 'Le tableau d’abord, l’édition en panneau', 'La carte « Où les promotions sont utilisées » du dashboard, à l’échelle du réseau, puis la liste ; l’édition s’ouvre dans un panneau par-dessus.',
    ['l’analyse en premier, comme son nom l’indique', 'on édite sans perdre le tableau'], ['le panneau est étroit : le formulaire perd sa colonne de droite (calculateur, ticket)', '« Édition » dans le rail ouvre le panneau sur le tableau']]];

async function main() {
  const b = await pw.chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost'] });
  const F = await fragments(b);
  const tete = `<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Promotions au cockpit — maquettes</title>
<link rel="stylesheet" href="../../../public/assets/ds/global.css"><link rel="stylesheet" href="../../../public/assets/css/app.css"><link rel="stylesheet" href="../../../public/dashboard/dashboard.css"><style>${CSS}</style></head><body>`;
  const barre = `<div class="mq-bar-maq"><span style="margin-right:8px">Maquettes · Promotions au cockpit</span>${ECRANS.map(([id, l]) => `<a href="#${id}" data-e="${id}">${l}</a>`).join('')}<a href="#planche" data-e="planche">Planche</a></div>`;
  const planche = `<div class="mq-ecran" id="e-planche"><div class="mq-pl"><h1>Promotions au cockpit — quatre formes</h1><div class="db-mini" style="font-size:13px;margin-top:4px">Une section « Promotions » dans le rail, deux entrées : Édition (le formulaire actuel du dashboard magasin) et Tableau d’analyse (toutes les promotions de tous les magasins).</div>
    <div class="mq-pl-g">${PLANCHE.map(([k, t, d, pour, contre]) => `<div class="mq-pl-c"><h2>${k} · ${t}</h2><div style="font-size:13px">${d}</div><div class="mq-pl-im"><img src="${k.toLowerCase()}-tableau.png"><img src="${k.toLowerCase()}-edition.png"></div>
      <ul>${pour.map(x => `<li>${x}</li>`).join('')}${contre.map(x => `<li style="color:#8a5a13">${x}</li>`).join('')}</ul></div>`).join('')}</div></div></div>`;
  const html = tete + barre + ECRANS.map(([id]) => `<div class="mq-ecran" id="e-${id}">${E[id](F)}</div>`).join('\n') + planche
    + `<script>(function(){var q=new URLSearchParams(location.search);if(q.has('capture')){document.body.classList.add('capture');}
function voir(){var id=(location.hash||'#a-tableau').slice(1);document.querySelectorAll('.mq-ecran').forEach(function(s){s.classList.toggle('on',s.id==='e-'+id);});document.querySelectorAll('.mq-bar-maq a').forEach(function(a){a.classList.toggle('on',a.dataset.e===id);});window.scrollTo(0,0);}
addEventListener('hashchange',voir);voir();})();</script></body></html>`;
  fs.writeFileSync(OUT + 'index.html', html);

  const ctx = await b.newContext({ viewport: { width: 1600, height: 1000 }, deviceScaleFactor: 1.25, locale: 'fr-BE' });
  for (const [id] of ECRANS.concat([['planche']])) {
    const p = await ctx.newPage();
    const err = [];
    p.on('pageerror', e => err.push(e.message));
    p.on('requestfailed', r => err.push('échec ' + r.url()));
    await p.goto(ICI + 'index.html?capture=1#' + id, { waitUntil: 'networkidle' });
    await p.evaluate('document.fonts.ready');
    await p.waitForTimeout(300);
    const large = await p.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
    await p.screenshot({ path: OUT + id + '.png', fullPage: true });
    console.log(id, '·', await p.evaluate(() => document.body.scrollHeight), 'px', large ? '· DÉBORDE EN LARGEUR' : '', err.length ? '· ' + err.join(' | ') : '');
    await p.close();
  }
  await b.close();
}
main().catch(e => { console.error(e); process.exit(1); });
