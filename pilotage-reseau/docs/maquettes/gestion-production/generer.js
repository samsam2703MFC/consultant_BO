/* Trois maquettes du module « Gestion de production » (côté franchisé) :
 *   A — les paramètres : les cuissons (périodes de vente du panel), le % de la
 *       journée par cuisson, les catégories × cuissons, les règles de recuisson ;
 *   B — le plan de production du jour, cuisson par cuisson ;
 *   C — le suivi de la journée et les recuissons conseillées avant la cuisson suivante.
 *
 * RÉEL : Halle (shop 4), septembre 2026 — 50 850 € de ventes sur 30 jours ouverts,
 * les quantités par heure des catégories de tête, les dix produits de la
 * viennoiserie et leurs parts (reel-halle.json, lu sur /ventes/stats), les trois
 * périodes de vente du panel (/admin/sales-dayparts : Matin 06–11, Midi 11–13,
 * Après-midi 14–19), les trois commandes du 02/10. ILLUSTRÉ : la 4e cuisson (le
 * panel n'en déclare que trois), les pourcentages, les plaques, les lignes de
 * commande (le panel ne joint pas les articles à la liste), les ventes « déjà
 * faites » du suivi à 10 h 35.
 *
 *   node docs/maquettes/gestion-production/generer.js   (serveur statique sur 8099 depuis la racine)
 */
const fs = require('fs');
const { chromium } = require('/opt/node22/lib/node_modules/playwright');
const OUT = __dirname + '/';
const R = require('./reel-halle.json');
const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const nf = (n, d) => Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d || 0, maximumFractionDigits: d || 0 });
const fP0 = n => nf(Math.round(n)) + ' %';
const NJ = R.nJoursOuverts;                                        // 30 jours ouverts en septembre

/* --- Les cuissons : les trois périodes de vente du panel, plus une 4e locale ------------- */
const CUIS = [
  { k: 1, nom: 'Matin', de: '06:00', a: '11:00', h1: 6, h2: 11, pct: 50, four: '05:15', panel: true },
  { k: 2, nom: 'Midi', de: '11:00', a: '13:00', h1: 11, h2: 13, pct: 25, four: '10:15', panel: true },
  { k: 3, nom: 'Après-midi', de: '14:00', a: '16:30', h1: 14, h2: 16.5, pct: 15, four: '13:15', panel: true, note: 'panel : 14:00–19:00' },
  { k: 4, nom: 'Fin de journée', de: '16:30', a: '19:00', h1: 16.5, h2: 19, pct: 10, four: '15:45', panel: false },
];
const AVANCE = 45;   // minutes entre la sortie du four et l'ouverture de la période

/* --- Les catégories qui passent au four, et où --------------------------------------------- */
// [nom, groupe, cuissons cochées, pièces par plaque, dernière recuisson, parJour (réel : q/30 ; illustré sinon)]
const catR = n => (R.categories.find(c => c.nom === n) || { q: 0 });
const CATS = [
  ['Viennoiserie Ind.', 'Viennoiserie', [1, 2], 12, '10:30', catR('Viennoiserie Ind.').q / NJ],
  ['Viennoiserie réduction', 'Viennoiserie', [1, 2], 12, '10:30', catR('Viennoiserie réduction').q / NJ],
  ['Petite Boulangerie', 'Boulangerie', [1, 2, 3], 15, '13:30', catR('Petite Boulangerie').q / NJ],
  ['Pain', 'Boulangerie', [1, 2, 3, 4], 8, '16:30', catR('Pain').q / NJ],
  ['Pain Tradition', 'Boulangerie', [1, 3], 6, '13:30', catR('Pain Tradition').q / NJ],
  ['Cookies', 'Biscuiterie', [3, 4], 20, '16:30', catR('Cookies').q / NJ],
  ['Tartes', 'Tartes', [1], 4, '—', catR('Tartes').q / NJ],
  ['Tartissières - 19Ø', 'Tartes', [1], 4, '—', catR('Tartissières - 19Ø').q / NJ],
  ['Quiches', 'Quiches', [1, 2], 6, '11:00', catR('Quiches').q / NJ],
  ['Pâtisserie individuelle', 'Pâtisserie', [1], 12, '—', catR('Pâtisserie individuelle').q / NJ],
  ['Sandwiches garnis', 'Traiteur', [1, 2], null, '12:00', catR('Sandwiches garnis').q / NJ],
];
// La part de chaque cuisson pour une catégorie : les % globaux, renormalisés sur ses cuissons cochées.
const parts = cochees => { const t = CUIS.filter(c => cochees.includes(c.k)).reduce((a, c) => a + c.pct, 0); return CUIS.map(c => cochees.includes(c.k) ? c.pct / t : 0); };

/* --- Le profil horaire d'une catégorie : réel pour les catégories de tête, illustré sinon ---- */
const HEURES = [5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18];
const profilReel = nom => HEURES.map(h => { const x = R.heures.find(e => e.h === h); const c = x && x.cats.find(k => k.nom === nom); return c ? c.q / NJ : null; });
const PROFILS = {};
const vienn = profilReel('Viennoiserie Ind.'), pain = profilReel('Pain'), pb = profilReel('Petite Boulangerie'), cook = profilReel('Cookies');
const combler = (p, ref) => { const tot = p.reduce((a, v) => a + (v || 0), 0); return p.map((v, i) => v != null ? v : 0); };
PROFILS['Viennoiserie Ind.'] = combler(vienn); PROFILS['Pain'] = combler(pain); PROFILS['Petite Boulangerie'] = combler(pb);
// Les cookies ne figurent en tête qu'après 11 h : le profil réel est complété par zéro le matin (illustré : ils se vendent peu avant midi).
PROFILS['Cookies'] = combler(cook);
const norm = p => { const t = p.reduce((a, v) => a + v, 0); return p.map(v => t ? v / t : 0); };
const formeMatin = norm(PROFILS['Viennoiserie Ind.']), formeJour = norm(PROFILS['Pain']), formeMidi = norm(HEURES.map(h => h >= 10 && h <= 13 ? 1 : (h === 9 || h === 14 ? .4 : .1)));
const profilDe = (nom, parJour) => {
  if (PROFILS[nom] && PROFILS[nom].reduce((a, v) => a + v, 0) > 0) { const p = PROFILS[nom]; const t = p.reduce((a, v) => a + v, 0); return p.map(v => v * parJour / t); }
  const f = ['Viennoiserie réduction', 'Tartes', 'Tartissières - 19Ø', 'Pâtisserie individuelle'].includes(nom) ? formeMatin : (['Sandwiches garnis', 'Quiches'].includes(nom) ? formeMidi : formeJour);
  return f.map(v => v * parJour);
};
// Le vendredi : + 8 % sur la moyenne du mois (illustré — la vraie base sera la moyenne des 6 derniers vendredis, heure par heure).
const VEN = 1.08;
const prevCat = {}; CATS.forEach(c => { prevCat[c[0]] = profilDe(c[0], c[5] * VEN); });
const somme = (p, h1, h2) => HEURES.reduce((a, h, i) => a + (h >= h1 && h < h2 ? p[i] : 0), 0);

/* --- Les produits de la viennoiserie : parts réelles ------------------------------------------ */
const VP = catR('Viennoiserie Ind.').produits.slice(0, 8);
const partsVP = VP.map(p => p.q / catR('Viennoiserie Ind.').q);

/* --- Les commandes du jour (réel : trois commandes ; illustré : leurs articles) -------------- */
const CMD = [
  { heure: '06:00', canal: 'Comptoir', montant: 91.0, lignes: [['Viennoiserie Ind.', 'Croissant', 20], ['Viennoiserie Ind.', 'Pain au Chocolat', 16], ['Tartes', 'Tarte au riz', 2]] },
  { heure: '07:00', canal: 'Comptoir', montant: 77.2, lignes: [['Petite Boulangerie', 'Sandwich', 24], ['Pain', 'Pain Demi-Gris', 4]] },
  { heure: '13:30', canal: 'Click & collect', montant: 5.8, lignes: [['Cookies', 'Cookie Chocolat Lait', 3]] },
];
const cuissonDe = heure => { const h = +heure.slice(0, 2) + (+heure.slice(3, 5)) / 60; return (CUIS.find(c => h < c.h2) || CUIS[3]).k; };
const cmdCat = (cat, k) => CMD.filter(c => cuissonDe(c.heure) === k).reduce((a, c) => a + c.lignes.filter(l => l[0] === cat).reduce((b, l) => b + l[2], 0), 0);
const cmdProd = (nom, k) => CMD.filter(c => cuissonDe(c.heure) === k).reduce((a, c) => a + c.lignes.filter(l => l[1] === nom).reduce((b, l) => b + l[2], 0), 0);

/* --- Coques ------------------------------------------------------------------------------------- */
const nav = on => `<div class="gp-nav">${[['a', 'Paramètres'], ['b', 'Plan du jour'], ['c', 'Suivi et recuissons']].map(([id, n]) => `<a href="${id}.html" class="${on === id ? 'on' : ''}">${n}</a>`).join('')}<span class="mu">Gestion de production · Atelier by - Halle · module franchisé (transférable dans l’ERP franchisé)</span></div>`;
const page = (id, titre, sous, corps) => `<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>${esc(titre)} — maquette</title>
<link rel="stylesheet" href="/public/assets/ds/global.css"><link rel="stylesheet" href="/public/dashboard/dashboard.css"><link rel="stylesheet" href="gp.css"></head>
<body><div id="dash"><div class="db-hd"><img src="/public/assets/img/logo.png" alt=""><div><div class="db-titre">Atelier by - Halle</div><div class="db-sous">Gestion de production · vendredi 3 octobre 2026</div></div><span style="flex:1"></span><a class="db-lien" href="/public/dashboard/">Dashboard magasin ›</a></div>
<div id="mq-${id}" class="gp-page">${nav(id)}<div class="gp-h1">${esc(titre)}</div><div class="gp-sous">${sous}</div>${corps}</div></div></body></html>`;
const card = (lab, mini, corps, droite) => `<div class="db-card"><div class="ct gp-ct"><span class="db-lab">${lab}</span><span class="db-mini">${mini || ''}</span>${droite ? `<span style="margin-left:auto">${droite}</span>` : ''}</div>${corps}</div>`;
const spark = (p, h1, h2, now) => `<span class="gp-spark" title="prévision heure par heure, 05 h → 18 h">${p.map((v, i) => { const h = HEURES[i], m = Math.max(...p) || 1; return `<i class="${h >= h1 && h < h2 ? 'fe' : ''}${now != null && h === Math.floor(now) ? ' now' : ''}" style="height:${Math.max(2, 100 * v / m).toFixed(0)}%"></i>`; }).join('')}</span>`;
const prevJourTot = CATS.reduce((a, c) => a + c[5] * VEN, 0);

/* --- A — Paramètres ----------------------------------------------------------------------------- */
function pageA() {
  const cuis = `<div class="gp-cuis">${CUIS.map(c => `<div class="c${c.k}${c.panel ? '' : ' loc'}"><div class="k">Cuisson ${c.k} · ${esc(c.nom)}</div><div class="v"><span class="gp-in${c.k === 1 ? ' w' : ''}">${c.pct} %</span> <small style="font:400 12px var(--font-ui);color:var(--color-text-muted)">de la journée</small></div>
    <div class="s">vente <b>${c.de} – ${c.a}</b> ${c.panel ? `<span class="gp-tag">panel</span>` : `<span class="gp-tag loc">locale — à créer dans le panel</span>`}${c.note ? ` · ${esc(c.note)}` : ''}<br>sortie du four <b>${c.four}</b> · ${nf(prevJourTot * c.pct / 100)} pièces un vendredi moyen</div></div>`).join('')}</div>
    <div class="gp-pied"><b>Total : 100 %.</b> Les périodes de vente viennent de l’API du panel (<code>/admin/sales-dayparts</code>, trois aujourd’hui : Matin 06–11, Midi 11–13, Après-midi 14–19 ; le créneau 13–14 h n’est dans aucune). La 4e cuisson est locale tant qu’elle n’existe pas dans le panel ; créée là-bas, elle prend sa place ici. Sortie du four = ouverture de la période − <b>${AVANCE} min</b> d’avance (réglable).</div>`;
  const grp = {}; CATS.forEach(c => { (grp[c[1]] = grp[c[1]] || []).push(c); });
  const matrice = `<table class="gp-tab"><thead><tr><th>Catégorie</th>${CUIS.map(c => `<th class="c">C${c.k} · ${esc(c.nom)}<br><small style="font-weight:400;text-transform:none;letter-spacing:0">${c.de}–${c.a}</small></th>`).join('')}<th>Part par cuisson</th><th class="n">Pièces / plaque</th><th class="n">Dernière recuisson</th><th class="n">Vendredi moyen</th></tr></thead><tbody>
    ${Object.entries(grp).map(([g, L]) => `<tr class="grp"><td colspan="9">${esc(g)}</td></tr>` + L.map(c => { const P = parts(c[2]); return `<tr><td class="nom">${esc(c[0])}<small>${c[3] ? 'passe au four' : 'préparé, pas cuit'}</small></td>${CUIS.map(k => `<td class="c"><span class="gp-chk${c[2].includes(k.k) ? ' on' : ''}"></span></td>`).join('')}<td>${P.map((p, i) => p ? `<span class="gp-tag c${i + 1}">${fP0(100 * p)}</span>` : '').join(' ')}</td><td class="n">${c[3] ? `<span class="gp-in">${c[3]}</span>` : '<span class="mu">—</span>'}</td><td class="n">${c[4] === '—' ? '<span class="mu">jamais</span>' : `<span class="gp-in l">${c[4]}</span>`}</td><td class="n mu">${nf(c[5] * VEN)} pcs</td></tr>`; }).join('')).join('')}
    </tbody></table><div class="gp-pied">Une case cochée = la catégorie se cuit pour cette période. La part par cuisson reprend les % de la journée, renormalisés sur les cases cochées : la viennoiserie cochée en 1 et 2 fait <b>67 % / 33 %</b>, les cookies en 3 et 4 font <b>60 % / 40 %</b>. « Dernière recuisson » : au-delà de cette heure, le suivi ne propose plus de recuire cette catégorie (ce qui reste finit à la poubelle). Les catégories viennent du catalogue du panel ; celles qui ne se vendent pas ici n’apparaissent pas.</div>`;
  const regles = `<div class="gp-regles">
    <div class="r"><b>Base de prévision</b><span class="gp-in">6</span> dernières semaines, le même jour, heure par heure<small>un vendredi se prévoit sur les 6 derniers vendredis ; un jour férié s’exclut à la main</small></div>
    <div class="r"><b>Sécurité</b><span class="gp-in">+ 10 %</span> sur la prévision de chaque cuisson<small>le risque de manquer coûte une vente, celui de trop produire coûte la matière : à régler par magasin</small></div>
    <div class="r"><b>Arrondi</b> à la plaque pleine, jamais en dessous d’<span class="gp-in">1</span> plaque<small>12 croissants prévus et 12 par plaque = 1 plaque ; 13 = 2 plaques</small></div>
    <div class="r"><b>Commandes</b> <span class="gp-chk on"></span> comptoir &amp; clients pro <span class="gp-chk on"></span> webshop (click &amp; collect, livraison)<small>une commande à retirer à 13 h 30 s’ajoute à la cuisson qui précède son retrait</small></div>
    <div class="r"><b>Recuisson</b> conseillée quand le stock estimé couvre moins de <span class="gp-in">80 %</span> du besoin jusqu’à la cuisson suivante<small>besoin = prévision des heures restantes + commandes à venir ; stock = produit − vendu − jeté</small></div>
    <div class="r"><b>Stock de fin de journée visé</b> <span class="gp-in">0</span> viennoiserie · <span class="gp-in">4</span> pains<small>ce qu’on accepte de garder pour le lendemain ; le reste compte en invendu</small></div></div>`;
  return page('a', 'Paramètres de production', 'Trois réglages, une fois pour toutes, que le franchisé ajuste quand il veut : <b>les cuissons</b> et le % de la journée que chacune produit, <b>quelles catégories se cuisent à quelles cuissons</b>, et <b>les règles</b> de prévision et de recuisson. Tout le reste (plan du jour, recuissons) en découle automatiquement, chaque jour.',
    card('1 · Les cuissons de la journée', 'les périodes de vente du panel, et le % de la journée que chaque cuisson produit', cuis)
    + card('2 · Quelles catégories à quelles cuissons', 'cocher = la catégorie se produit pour cette période', matrice)
    + card('3 · Les règles de prévision et de recuisson', '', regles, '<span class="gp-btn">Enregistrer</span>'));
}

/* --- B — Plan du jour ---------------------------------------------------------------------------- */
function lignesCuisson(k) {
  const c = CUIS[k - 1];
  // Lignes : les catégories cochées pour cette cuisson ; la viennoiserie éclatée par produit.
  const rows = [];
  CATS.filter(x => x[2].includes(k)).forEach(x => {
    const P = parts(x[2])[k - 1], prevJ = x[5] * VEN, fen = somme(prevCat[x[0]], c.h1, c.h2);
    const aPrev = prevJ * P * 1.10;
    if (x[0] === 'Viennoiserie Ind.') {
      VP.forEach((p, i) => { const pj = prevJ * partsVP[i], ap = pj * P * 1.10, cmd = cmdProd(p.nom, k), stock = k === 1 ? 0 : Math.max(0, Math.round(pj * parts(x[2])[0] * 1.10 + cmdProd(p.nom, 1) - somme(prevCat[x[0]].map(v => v * partsVP[i]), 5, c.h1)));
        const aCuire = Math.max(0, ap + cmd - stock), pl = aCuire > 0 ? Math.max(1, Math.ceil(aCuire / 12)) : 0;
        rows.push({ cat: x[0], nom: p.nom, prof: prevCat[x[0]].map(v => v * partsVP[i]), prevJ: pj, fen: fen * partsVP[i], part: P, ap, cmd, ws: 0, stock, aCuire, pl, plaque: 12 }); });
    } else {
      const cmd = cmdCat(x[0], k), ws = x[0] === 'Cookies' && k === 2 ? 0 : 0;
      const stock = k === 1 ? 0 : Math.max(0, Math.round(prevJ * parts(x[2])[0] * 1.10 + cmdCat(x[0], 1) - somme(prevCat[x[0]], 5, c.h1)));
      const aCuire = Math.max(0, aPrev + cmd + ws - stock), pl = x[3] && aCuire > 0 ? Math.max(1, Math.ceil(aCuire / x[3])) : null;
      rows.push({ cat: x[0], nom: x[0], prof: prevCat[x[0]], prevJ, fen, part: P, ap: aPrev, cmd, ws, stock, aCuire, pl, plaque: x[3] });
    }
  });
  const tot = rows.reduce((a, r) => ({ ap: a.ap + r.ap, cmd: a.cmd + r.cmd, stock: a.stock + r.stock, aCuire: a.aCuire + (r.pl != null ? r.pl * r.plaque : r.aCuire), pl: a.pl + (r.pl || 0) }), { ap: 0, cmd: 0, stock: 0, aCuire: 0, pl: 0 });
  return { c, rows, tot };
}
function planCuisson(k, ouvert) {
  const { c, rows, tot } = lignesCuisson(k);
  const tete = `<div class="ct gp-ct"><span class="db-lab">Cuisson ${k} · ${esc(c.nom)} — four à ${c.four}, vente ${c.de}–${c.a}</span><span class="db-mini">${c.pct} % de la journée · ${nf(tot.aCuire)} pièces à sortir · ${nf(tot.pl)} plaques${k === 1 ? ' · <b>à lancer</b>' : (k === 2 ? ' · se recalcule à 10 h 00 avec les ventes du matin' : '')}</span><span class="db-cdr" style="padding:0;margin-left:auto">${ouvert ? 'replier ▴' : 'voir le détail ▾'}</span></div>`;
  if (!ouvert) { return `<div class="db-card">${tete}</div>`; }
  let g = '';
  const table = `<table class="gp-tab"><thead><tr><th>Produit</th><th>Prévision heure par heure</th><th class="n">Vendredi moyen<br><small style="font-weight:400;text-transform:none;letter-spacing:0">6 dernières sem.</small></th><th class="n">Part C${k}</th><th class="n">Prévu + 10 %</th><th class="n">Commandes</th><th class="n">Webshop</th><th class="n">Stock estimé</th><th class="n">À cuire</th><th class="n">Plaques</th></tr></thead><tbody>
    ${rows.map(r => { const h = r.cat !== g ? `<tr class="grp"><td colspan="10">${esc(r.cat)}</td></tr>` : ''; g = r.cat; return h + `<tr><td class="nom">${esc(r.nom)}</td><td>${spark(r.prof, c.h1, c.h2)} <small class="mu">${nf(r.fen)} sur la fenêtre</small></td><td class="n">${nf(r.prevJ)}</td><td class="n mu">${fP0(100 * r.part)}</td><td class="n">${nf(r.ap)}</td><td class="n">${r.cmd ? `<b>+ ${nf(r.cmd)}</b>` : '<span class="mu">—</span>'}</td><td class="n">${r.ws ? `<b>+ ${nf(r.ws)}</b>` : '<span class="mu">—</span>'}</td><td class="n">${r.stock ? `− ${nf(r.stock)}` : '<span class="mu">—</span>'}</td><td class="n"><b>${nf(r.pl != null ? r.pl * r.plaque : r.aCuire)}</b></td><td class="n">${r.pl != null ? `${r.pl} × ${r.plaque}` : (r.plaque ? '<span class="mu">—</span>' : '<span class="mu">à la main</span>')}</td></tr>`; }).join('')}
    <tr class="tot"><td>Cuisson ${k}</td><td></td><td></td><td></td><td class="n">${nf(tot.ap)}</td><td class="n">${tot.cmd ? '+ ' + nf(tot.cmd) : '—'}</td><td class="n">—</td><td class="n">${tot.stock ? '− ' + nf(tot.stock) : '—'}</td><td class="n">${nf(tot.aCuire)}</td><td class="n">${nf(tot.pl)}</td></tr>
    </tbody></table><div class="gp-pied"><b>À cuire = prévision du jour × part de la cuisson × (1 + sécurité) + commandes à retirer pendant la période + webshop − stock estimé</b>, arrondi à la plaque. La prévision est la moyenne des 6 derniers vendredis, heure par heure (tickets du panel) ; la barre colorée marque les heures de la période. ${k === 1 ? 'Le stock de départ vaut zéro : le panel ne rend pas les reports de la veille (carryover) — à saisir à la main si on les garde.' : 'Le stock estimé = produit à la cuisson précédente − ventes prévues jusqu’ici ; il se remplace par le réel dès que la caisse a vendu.'}</div>`;
  return `<div class="db-card">${tete}${table}</div>`;
}
function pageB() {
  const PL = CUIS.map(c => lignesCuisson(c.k)), totJ = PL.reduce((a, x) => a + x.tot.aCuire, 0), plJ = PL.reduce((a, x) => a + x.tot.pl, 0);
  const tete = `<div class="gp-cuis">${CUIS.map(c => { const L = PL[c.k - 1], nCat = new Set(L.rows.map(r => r.cat)).size, cmd = L.tot.cmd; return `<div class="c${c.k}${c.panel ? '' : ' loc'}"><div class="k">Cuisson ${c.k} · ${esc(c.nom)} · four ${c.four}</div><div class="v">${nf(L.tot.aCuire)} <small style="font:400 12px var(--font-ui);color:var(--color-text-muted)">pièces · ${nf(L.tot.pl)} plaques</small></div><div class="s">${nCat} catégories · ${c.pct} % de la journée${cmd ? ` · dont <b>${nf(cmd)} de commandes</b>` : ''}<br>${c.k === 1 ? '<span class="gp-tag ok">en cours — sortie 05:15</span>' : (c.k === 2 ? '<span class="gp-tag att">à lancer à 10:15 · recalcul à 10:00</span>' : '<span class="gp-tag">prévu</span>')}</div></div>`; }).join('')}</div>
    <div class="gp-pied"><b>${nf(totJ)} pièces en ${nf(plJ)} plaques</b> à sortir ce vendredi (prévision + 10 % + commandes, arrondi à la plaque, stock déduit d’une cuisson à l’autre) · 3 commandes du jour (<b>69 pièces</b>) · webshop : aucune commande à retirer aujourd’hui · demain : 7 commandes déjà prises (524 €) — elles entreront dans le plan de demain.</div>`;
  const cmd = `<table class="gp-tab"><thead><tr><th>Retrait</th><th>Canal</th><th>Articles</th><th class="n">Montant</th><th>Entre dans</th></tr></thead><tbody>${CMD.map(m => `<tr><td class="nom">${m.heure}</td><td><span class="gp-tag">${esc(m.canal)}</span></td><td>${m.lignes.map(l => `${l[2]} × ${esc(l[1])}`).join(' · ')}</td><td class="n">${nf(m.montant, 2)} €</td><td><span class="gp-tag c${cuissonDe(m.heure)}">Cuisson ${cuissonDe(m.heure)}</span></td></tr>`).join('')}</tbody></table><div class="gp-pied">Réel : trois commandes ce jour (panel, <code>/shops/{id}/client-orders</code>). Illustré : leurs articles — la liste du panel ne les joint pas toujours, le module relira chaque commande.</div>`;
  return page('b', 'Plan de production du jour', 'Calculé chaque nuit à partir des paramètres et des tickets des 6 dernières semaines, puis <b>recalculé avant chaque cuisson</b> avec les ventes réelles de la journée et les commandes arrivées entre-temps. Le franchisé lit ce qu’il sort du four, cuisson par cuisson, en plaques.',
    `<div class="db-card"><div class="ct"><span class="db-lab">Vendredi 3 octobre — les quatre cuissons</span><span class="db-mini">prévision = moyenne des 6 derniers vendredis, heure par heure · + 10 % de sécurité · commandes du jour incluses</span></div>${tete}</div>`
    + planCuisson(1, true) + planCuisson(2, true) + planCuisson(3, false) + planCuisson(4, false)
    + card('Les commandes du jour, cuisson par cuisson', 'comptoir, clients pro et webshop — chacune s’ajoute à la cuisson qui précède son retrait', cmd));
}

/* --- C — Suivi et recuissons : chaque cuisson se recalcule sur le réel juste avant le four ------ */
function pageC() {
  const NOW = 10, K = 2, C2 = CUIS[K - 1];                          // recalcul de 10:00, avant le four de 10:15
  const partiel = (p, a, b) => HEURES.reduce((s2, h, i) => s2 + (h + 1 <= a || h >= b ? 0 : p[i] * (Math.min(h + 1, b) - Math.max(h, a))), 0);
  const P1 = lignesCuisson(1).rows, P2 = lignesCuisson(2).rows;
  const sortie = r => r ? (r.pl != null ? r.pl * r.plaque : Math.round(r.aCuire)) : 0;
  // Illustré : ce qui part plus ou moins vite que la moyenne des 6 derniers vendredis.
  const ECART = { 'Croissant': 1.18, 'Pain au Chocolat': 1.05, 'Pain au Chocolat Nappé': .78, 'Couque au Beurre': .95, 'Suisse': 1.22, 'Huit': .7, 'Baulus': 1.0, 'Couque à la Crème': .9, 'Viennoiserie réduction': 1.0, 'Petite Boulangerie': 1.12, 'Pain': .9, 'Quiches': 1.3, 'Sandwiches garnis': .85 };
  const L = P2.filter(r => r.cat !== 'Viennoiserie réduction').map(r => {
    const x = CATS.find(c => c[0] === r.cat), r1 = P1.find(q => q.nom === r.nom);
    // Jusqu'où le stock doit tenir : la sortie de la prochaine cuisson cochée pour la catégorie, sinon la fermeture.
    const suiv = CUIS.find(c => c.k > K && x[2].includes(c.k)), fin = suiv ? suiv.h1 : 19;
    const produit = sortie(r1), cmdPasse = r.cat === 'Viennoiserie Ind.' ? cmdProd(r.nom, 1) : cmdCat(r.cat, 1);
    const prevu = partiel(r.prof, 5, NOW) + cmdPasse, vendu = Math.round(prevu * (ECART[r.nom] || 1));
    const stock = Math.max(0, produit - vendu);
    const besoin = partiel(r.prof, NOW, fin) * 1.10 + r.cmd + r.ws;
    const manque = Math.max(0, besoin - stock), pl = r.plaque ? (manque > 0 ? Math.ceil(manque / r.plaque) : 0) : null;
    const aCuire = pl != null ? pl * r.plaque : Math.ceil(manque), plan = sortie(r);
    const lim = x[4], tard = lim !== '—' && NOW >= +lim.slice(0, 2) + (+lim.slice(3)) / 60;
    const v = tard ? ['tard', 'Trop tard', `après ${lim} : plus de recuisson`, 'mu']
      : stock >= besoin * 1.4 ? ['trop', 'Trop produit', `le stock couvre ${fP0(100 * stock / Math.max(1, besoin))} du besoin : ne pas recuire`, 'att']
      : aCuire === 0 ? ['tient', 'Tient', 'rien à recuire', 'ok']
      : ['recuire', 'Recuire', pl != null ? `<b>${pl} plaque${pl > 1 ? 's' : ''}</b> (${nf(aCuire)})` : `<b>${nf(aCuire)}</b> à préparer`, 'ko'];
    return { cat: r.cat, nom: r.nom, prof: r.prof, plaque: r.plaque, lim, fin: suiv ? suiv.de : '19:00', produit, prevu, vendu, stock, besoin, aCuire, pl, plan, v };
  });
  const tot = L.reduce((a, l) => ({ cuire: a.cuire + (l.v[0] === 'recuire' ? l.aCuire : 0), pl: a.pl + (l.v[0] === 'recuire' ? (l.pl || 0) : 0), plan: a.plan + l.plan, plPlan: a.plPlan + (l.plaque ? l.plan / l.plaque : 0), vendu: a.vendu + l.vendu, prevu: a.prevu + l.prevu }), { cuire: 0, pl: 0, plan: 0, plPlan: 0, vendu: 0, prevu: 0 });
  const nRec = L.filter(l => l.v[0] === 'recuire'), nTrop = L.filter(l => l.v[0] === 'trop');
  const ecartJ = 100 * (tot.vendu / tot.prevu - 1);
  const alerte = `<div class="gp-alert"><b>Cuisson 2, four à 10:15 : ${nf(tot.pl)} plaques à enfourner au lieu des ${nf(Math.round(tot.plPlan))} prévues cette nuit</b><span class="mu">${nRec.length} produit${nRec.length > 1 ? 's' : ''} à recuire${nTrop.length ? ` · ${nTrop.length} déjà en trop, à ne pas recuire (${nTrop.map(l => esc(l.nom)).join(', ')})` : ''}</span><span style="margin-left:auto" class="gp-btn">Valider la cuisson 2</span></div>`;
  let g = '';
  const table = `<table class="gp-tab"><thead><tr><th>Produit</th><th>Journée prévue · déjà passée</th><th class="n">Sorti C1</th><th class="n">Vendu 06:00–10:00</th><th class="n">Prévu à 10:00</th><th class="n">Écart</th><th class="n">Stock à 10:00</th><th class="n">Besoin + 10 %<br><small style="font-weight:400;text-transform:none;letter-spacing:0">jusqu’à la cuisson suivante</small></th><th class="n">Plan de la nuit</th><th>À enfourner à 10:15</th></tr></thead><tbody>
    ${L.map(l => { const h = l.cat !== g ? `<tr class="grp"><td colspan="10">${esc(l.cat)}</td></tr>` : ''; g = l.cat; const e = l.prevu ? 100 * (l.vendu / l.prevu - 1) : 0; const couv = l.besoin > 0 ? Math.min(100, 100 * l.stock / l.besoin) : 100; return h + `<tr><td class="nom">${esc(l.nom)}<small>${l.plaque ? 'plaque de ' + l.plaque : 'préparé à la main'} · recuisson jusqu’à ${l.lim === '—' ? 'jamais' : l.lim}</small></td><td>${spark(l.prof, 5, NOW, NOW)}</td><td class="n">${nf(l.produit)}</td><td class="n"><b>${nf(l.vendu)}</b></td><td class="n mu">${nf(l.prevu)}</td><td class="n" style="color:${e > 8 ? '#2d7a3e' : (e < -8 ? '#C0182B' : 'inherit')}">${e >= 0 ? '+' : '−'} ${fP0(Math.abs(e))}</td><td class="n">${nf(l.stock)}</td><td class="n"><span class="gp-bar"><i class="${l.v[3] === 'ko' ? 'ko' : (l.v[3] === 'att' ? 'att' : '')}" style="width:${couv.toFixed(0)}%"></i></span>${nf(Math.round(l.besoin))} <small class="mu">→ ${l.fin}</small></td><td class="n mu">${l.plan ? nf(l.plan) : '—'}</td><td><span class="gp-tag ${l.v[3]}">${l.v[1]}</span> <small class="mu">${l.v[2]}</small></td></tr>`; }).join('')}
    <tr class="tot"><td>Cuisson 2</td><td></td><td></td><td class="n">${nf(tot.vendu)}</td><td class="n">${nf(tot.prevu)}</td><td class="n">${ecartJ >= 0 ? '+' : '−'} ${fP0(Math.abs(ecartJ))}</td><td></td><td></td><td class="n">${nf(tot.plan)}</td><td><b>${nf(tot.cuire)} pièces · ${nf(tot.pl)} plaques</b></td></tr>
    </tbody></table><div class="gp-pied"><b>À enfourner = besoin − stock, arrondi à la plaque.</b> Stock à 10:00 = sorti à la cuisson 1 − vendu (tickets du panel, relus toutes les 10 minutes) − jeté. Besoin = prévision des heures qui restent jusqu’à la sortie de la prochaine cuisson de la catégorie (la fermeture s’il n’y en a plus) × 1,10 + commandes et webshop à retirer d’ici là. « Plan de la nuit » : ce que la cuisson 2 prévoyait avant l’ouverture. Trop produit au-delà de 140 % de couverture : l’excédent finira en invendu (carte Invendus et poubelle). La même chose se refait avant les cuissons 3 et 4.</div>`;
  const journee = `<div class="gp-cuis">
    <div class="c1"><div class="k">Cuisson 1 · Matin · sortie 05:15</div><div class="v">${ecartJ >= 0 ? '+' : '−'} ${fP0(Math.abs(ecartJ))}</div><div class="s">vendu face au prévu à 10:00 · <b>${nf(tot.vendu)} pièces</b> vendues sur les produits de la cuisson 2</div></div>
    <div class="c2"><div class="k">Cuisson 2 · Midi · four 10:15</div><div class="v">${nf(tot.pl)} <small style="font:400 12px var(--font-ui);color:var(--color-text-muted)">plaques · ${nf(tot.cuire)} pièces</small></div><div class="s">plan de la nuit : ${nf(Math.round(tot.plPlan))} plaques · <b>recalculée à 10:00</b></div></div>
    <div class="c3"><div class="k">Cuisson 3 · Après-midi · four 13:15</div><div class="v">—</div><div class="s">se recalcule à <b>13:00</b> sur les ventes du midi</div></div>
    <div class="c4 loc"><div class="k">Cuisson 4 · Fin de journée · four 15:45</div><div class="v">—</div><div class="s">se recalcule à <b>15:30</b></div></div></div>`;
  return page('c', 'Suivi de la journée et recuissons', 'Avant chaque cuisson, le module <b>recalcule la cuisson sur le réel</b> : ce qui est vendu depuis l’ouverture (tickets du panel), ce qui reste en vitrine, ce que les heures suivantes vont demander d’après les 6 derniers vendredis, les commandes et le webshop à retirer — et dit <b>combien de plaques enfourner</b>, produit par produit, ou quoi ne pas recuire. Vendredi 3 octobre, recalcul de 10:00 avant le four de 10:15.',
    `<div class="db-card"><div class="ct gp-ct"><span class="db-lab">La journée en cours</span><span class="gp-now">10:00</span><span class="db-mini">tickets relus il y a 4 min · ${ecartJ >= 0 ? '+' : '−'} ${fP0(Math.abs(ecartJ))} face aux 6 derniers vendredis à la même heure</span></div>${journee}</div>`
    + `<div class="db-card"><div class="ct gp-ct"><span class="db-lab">Recalcul de la cuisson 2 — Midi, four à 10:15</span><span class="db-mini">les catégories cochées en cuisson 2 · stock réel face au besoin jusqu’à la cuisson suivante</span></div>${alerte}${table}</div>`);
}

/* --- Génération + captures ------------------------------------------------------------------------ */
(async () => {
  fs.writeFileSync(OUT + 'a.html', pageA()); fs.writeFileSync(OUT + 'b.html', pageB()); fs.writeFileSync(OUT + 'c.html', pageC());
  const b = await chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost'] });
  const c = await b.newContext({ viewport: { width: 1348, height: 900 }, deviceScaleFactor: 1.5 });
  const p = await c.newPage(); const err = []; p.on('pageerror', e => err.push(e.message));
  for (const id of ['a', 'b', 'c']) {
    await p.goto('http://127.0.0.1:8099/docs/maquettes/gestion-production/' + id + '.html', { waitUntil: 'load' }); await p.waitForTimeout(700);
    const el = await p.$('#mq-' + id); await el.screenshot({ path: OUT + id + '.jpg', type: 'jpeg', quality: 86 });
    console.log(id, ':', await p.evaluate(() => document.body.innerText.replace(/\s+/g, ' ').slice(0, 160)), '· déborde :', await p.evaluate(() => document.documentElement.scrollWidth + '/' + document.documentElement.clientWidth));
  }
  const planche = `<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>Gestion de production : trois maquettes</title><link rel="stylesheet" href="/public/assets/ds/global.css"><link rel="stylesheet" href="gp.css"></head><body><div class="pl" id="planche">
  <span class="u">Maquettes · module franchisé · 03/10/2026</span><h1>Gestion de production — les paramètres, le plan du jour, les recuissons</h1>
  <p class="acc">Un module à transférer côté franchisé : il règle une fois ses cuissons, ses catégories et ses règles ; chaque jour, le plan se calcule tout seul à partir des 6 dernières semaines heure par heure (tickets du panel), des commandes et du webshop, et se recalcule avant chaque cuisson avec les ventes réelles. Réel : Halle, septembre 2026 et les trois périodes de vente du panel. Illustré : la 4e cuisson, les plaques, les articles des commandes, les ventes du matin du suivi.</p>
  ${[['a', 'A — Paramètres de production', 'Les cuissons viennent des périodes de vente du panel (trois aujourd’hui), avec le % de la journée par cuisson (50 % à la première) ; la 4e est locale tant qu’elle n’existe pas dans le panel. La matrice catégories × cuissons dit où chaque catégorie se cuit (viennoiserie en 1 et 2, cookies en 3 et 4) et renormalise les parts. Les règles : 6 semaines de base, + 10 % de sécurité, l’arrondi à la plaque, le seuil de recuisson, les commandes.', ['Tout vient du panel : périodes, catégories, tickets, commandes.', 'Trois réglages lisibles, pas un paramétrage par produit.', 'La part par cuisson se déduit : cocher suffit.'], ['Le créneau 13–14 h du panel n’est dans aucune période : à trancher.', 'La 4e cuisson n’existe pas dans l’API tant qu’elle n’est pas créée dans le panel.']],
    ['b', 'B — Plan de production du jour', 'Quatre tuiles, une par cuisson, avec l’heure de sortie du four et les pièces ; puis, cuisson par cuisson, le détail par produit : prévision heure par heure (les heures de la période en couleur), vendredi moyen, part de la cuisson, + 10 %, commandes et webshop à retirer pendant la période, stock estimé de la cuisson précédente, à cuire et plaques. Les commandes du jour en bas, rangées dans leur cuisson.', ['Le franchisé lit des plaques, pas des pourcentages.', 'Les commandes entrent d’elles-mêmes dans la bonne cuisson.', 'Le stock de la cuisson précédente est déduit.'], ['Le report de la veille vaut zéro : le panel ne rend pas le carryover.', 'La liste des commandes ne joint pas toujours les articles : une lecture par commande.']],
    ['c', 'C — Suivi de la journée et recuissons', 'À 10 h 00, avant le four de la cuisson 2 : vendu depuis l’ouverture face au prévu à cette heure, stock en vitrine, besoin jusqu’à la prochaine cuisson de la catégorie (prévision + 10 % + commandes + webshop), et ce qu’il faut enfourner, produit par produit, face au plan de la nuit — recuire tant de plaques, tient, trop produit (ne pas recuire), trop tard. Le même recalcul avant les cuissons 3 et 4.', ['La décision du four se prend sur le réel, pas sur le plan de la nuit.', 'Le « trop produit » évite la recuisson réflexe et nourrit la carte Invendus.', 'Les tickets sont déjà relus toutes les 10 minutes : rien à ajouter côté panel.'], ['Le stock estimé suppose que ce qui n’est pas vendu est encore là : un jeté non déclaré le fausse.', 'Le webshop et les commandes doivent être relus avant chaque cuisson.']]
  ].map(([id, t, acc, plus, moins]) => `<h2>${t}</h2><p class="acc">${acc}</p><div class="g"><img src="${id}.jpg" alt=""><div class="col"><h4>Ce que ça apporte</h4><ul class="pl-p">${plus.map(x => `<li>${x}</li>`).join('')}</ul><h4>Limites</h4><ul class="pl-m">${moins.map(x => `<li>${x}</li>`).join('')}</ul></div></div>`).join('')}
  </div></body></html>`;
  fs.writeFileSync(OUT + 'planche.html', planche);
  const q = await c.newPage(); await q.setViewportSize({ width: 1700, height: 900 });
  await q.goto('http://127.0.0.1:8099/docs/maquettes/gestion-production/planche.html', { waitUntil: 'load' }); await q.waitForTimeout(900);
  await (await q.$('#planche')).screenshot({ path: OUT + 'planche.jpg', type: 'jpeg', quality: 80 });
  console.log('erreurs :', err.join(' | ') || 'aucune');
  await b.close();
})().catch(e => { console.error(e); process.exit(1); });
