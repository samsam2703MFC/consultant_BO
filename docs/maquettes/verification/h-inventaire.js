/* Maquette « Entre deux inventaires — le détail d'un produit » (demande du 31/10/2026 : « c'est plus #/inventaires,
 * mais avec un détail de tous les mouvements quand je clique sur un produit, en forme de tableau »).
 *
 * L'écran Inventaires tel qu'il est, avec une ligne de « Matière par matière » cliquée : elle s'ouvre sur le tableau
 * de TOUS ses mouvements d'un inventaire à l'autre — une ligne par jour, les + et les − en colonnes (livraison
 * validée, entrée et correction à la main, vendu et jeté par les recettes), le stock théorique du soir, et l'inventaire
 * qui ferme la période ; puis la même chose semaine par semaine.
 *
 * CHIFFRES RÉELS : Corbais, Baguette Tradition 500 g., inventaires relevés du 11/10, du 18/10 et du 25/10 (moteur de
 * l'écran Inventaires, /stock/inventaires/ecart, et /verification/mouvements, lus le 31/10). Le premier et le dernier
 * jour sont coupés à l'heure de l'inventaire (vendu 18/10 après 14:36 et 25/10 avant 12:40 répartis pour retrouver les
 * 837 pièces du calcul).
 *
 *   node docs/maquettes/verification/h-inventaire.js   puis   node docs/maquettes/verification/capturer.js */
const fs = require('fs');
const path = require('path');

const nf = (n, d = 0) => Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d, maximumFractionDigits: d }).replace(/[  ]/g, ' ');
const q1 = n => nf(n, Math.abs(n - Math.round(n)) > 0.05 ? 1 : 0);
const eur = n => nf(Math.round(n)) + ' €';
const sg = (n, plus = 'pl', moins = 'mo') => Math.abs(n) < 0.05 ? '<span class="z">·</span>' : `<span class="${n > 0 ? plus : moins}">${n > 0 ? '+' : '−'}${q1(Math.abs(n))}</span>`;

/* ---------- l'habillage : le rail tel qu'il est en ligne (Inventaires sous Vérification) ---------- */
const NAV = [
  ['Pilotage', ['Résultat', 'Dashboard magasin ↗']],
  ['Franchisés · évaluation et suivi', ['Fiche & scoring', '▸Suivi des franchisés', '▸Budget', '▸Suivi journalier et opérations']],
  ['Magasins', ['Analyse magasin', 'Invendus et poubelle']],
  ['Vérification', ['Audit des flux', 'Fuites par magasin', 'Inventaires', 'Matières ↔ recettes', 'Chiffre d’affaires', 'Mouvements d’un produit', 'Recettes à corriger', 'Évolution']],
  ['Produits', ['Catalogue', 'Comptoir · planogramme']],
];
const BTN = 'display:flex;align-items:center;justify-content:space-between;gap:8px;width:100%;text-align:left;border:none;font-family:var(--font-ui);font-size:13px;padding:8px 10px;border-radius:8px;font-weight:300;';
const rail = actif => `<aside style="width:236px;flex:0 0 236px;background:var(--color-surface);border-right:0.5px solid var(--color-border-tertiary)">
  <div style="padding:22px 20px 14px"><img src="/public/assets/img/logo.png" alt="L’Atelier" style="width:176px;display:block"><div style="font:600 11px var(--font-ui);letter-spacing:.09em;text-transform:uppercase;margin-top:8px;color:#555">Pilotage réseau</div></div>
  <div style="padding:0 12px 12px"><input placeholder="Rechercher partout…" style="width:100%;box-sizing:border-box;border:0.5px solid var(--color-border-secondary);background:var(--color-background-secondary);border-radius:9px;height:32px;padding:0 10px;font-size:12px"></div>
  <nav style="padding:0 12px 16px;display:flex;flex-direction:column;gap:18px">${NAV.map(([t, items]) => `<div style="display:flex;flex-direction:column;gap:2px">
    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.09em;padding:0 10px 7px">${t}</div>
    ${items.map(x => { const sub = x.startsWith('▸'), nom = sub ? x.slice(1) : x, on = nom === actif;
      return `<button style="${BTN}${on ? 'background:rgba(141,29,44,0.08);color:var(--color-primary);font-weight:500' : 'background:transparent;color:var(--color-text)'}"><span>${sub ? '<span style="font-size:9px;color:#999;margin-right:6px">▸</span>' : ''}${nom}</span></button>`; }).join('')}</div>`).join('')}</nav></aside>`;

/* ---------- les chiffres réels ---------- */
const T = { justesse: 76.4, debut: 7676.79, entrees: 9816.94, fin: 7376.81, sorties: 10116.92, explique: 10850.74, manquant: 2541.93, excedent: 2594.57, n: 103 };
// Matière par matière (les huit plus gros écarts en euros, Corbais 18/10 → 25/10)
const MAT = [
  ['Baguette Tradition 500 g.', 'Petite Boulangerie', 1.425, 0, 1152, 0, 1152, 837, 0, 315, 448.88, 72.7, 'fiable'],
  ['Moelleux Chocolat - 28', 'Tartes - 28', 8.66, 14, 0, 56, -42, 7.75, 0, -49.75, -431.01, null, 'surplus'],
  ['Mini - Chocolat', 'Viennoiserie réduction', 0.504, 0, 1000, 600, 400, 1024, 0, -624, -314.31, 256, 'fiable'],
  ['Mini - Croissant', 'Viennoiserie réduction', 0.413, 0, 1000, 800, 200, 918, 0, -718, -296.68, 459, 'fiable'],
  ['Mini - Frangipane', 'Viennoiserie réduction', 0.648, 0, 400, 320, 80, 386, 0, -306, -198.40, 482.5, 'fiable'],
  ['Citron Meringué - 20⌀', 'Tartissières - 19Ø', 8.91, 6, 12, 0, 18, 40, 0, -22, -196.06, 222.2, 'fiable'],
  ['Pomme Amandes 28 Ø - (Insert)', 'Tartes - 28', 6.19, 0, 0, 16, -16, 13, 0, -29, -179.62, null, 'surplus'],
  ['Frangipane & Pommes – 28 Ø', 'Tartes - 28', 8.85, 10, 0, 30, -20, 0, 0, -20, -176.90, null, 'calme'],
];
const STATUT = { fiable: ['fiable', 'ok'], surplus: ['vendu sans sortie', 'att'], calme: ['rien ne bouge', 'mu'] };
// Le détail : la Baguette Tradition, jour par jour, de l'inventaire du 18/10 14:36 à celui du 25/10 12:40.
const JOURS = [
  { j: 'dim. 18/10', coupe: 'après 14:36', liv: 0, ent: 0, cor: 0, v: 36, w: 0, ev: ['14:36 inventaire : compté 0 (le panel attendait 108)'] },
  { j: 'lun. 19/10', liv: 0, ent: 0, cor: 0, v: 98.5, w: 0, ev: [] },
  { j: 'mar. 20/10', liv: 0, ent: 0, cor: 0, v: 109, w: 0, ev: [] },
  { j: 'mer. 21/10', liv: 0, ent: 0, cor: 0, v: 135, w: 0, ev: [] },
  { j: 'jeu. 22/10', liv: 1152, ent: 0, cor: 0, v: 114.5, w: 0, ev: ['13:58 livraison validée : +1 152 (stock du panel 0 → 1 152), en une fois'] },
  { j: 'ven. 23/10', liv: 0, ent: 0, cor: 0, v: 102, w: 0, ev: [] },
  { j: 'sam. 24/10', liv: 0, ent: 0, cor: 0, v: 139.5, w: 0, ev: [] },
  { j: 'dim. 25/10', coupe: 'avant 12:40', liv: 0, ent: 0, cor: 0, v: 102.5, w: 0, ev: ['12:40 inventaire : compté 0'] },
];
const PROD = [['Baguette Tradition 500 g.', 1, 530], ['Club', 0.5, 190], ['Italien', 0.5, 115], ['Poulet César', 0.5, 68], ['SuperClub (Comté & Jambon de Pays)', 0.5, 67], ['Américain Maison', 0.5, 62], ['Tartare à l’italienne', 0.5, 59], ['Thon', 0.5, 54]];
const PRIX = 1.425;
const SEMAINES = [
  { s: 'S42', de: 'dim. 11/10 13:39', a: 'dim. 18/10 14:36', debut: 108, liv: 0, ent: 0, cor: 0, v: 789.5, w: 0, compte: 0 },
  { s: 'S43', de: 'dim. 18/10 14:36', a: 'dim. 25/10 12:40', debut: 0, liv: 1152, ent: 0, cor: 0, v: 837, w: 0, compte: 0, on: true },
];

/* ---------- le corps ---------- */
const kpi = (k, v, s, t = '') => `<div class="vf-kpi ${t}"><div class="k">${k}</div><div class="v">${v}</div><div class="s">${s}</div></div>`;
let stock = 0;
const lignesJours = JOURS.map(d => {
  const matin = stock;
  stock = stock + d.liv + d.ent + d.cor - d.v - d.w;
  const neg = stock < 0;
  const ev = d.ev.slice();
  if (neg && !d.liv) { ev.push('vendu sans stock : aucune livraison validée'); }
  return `<tr class="${d.liv ? 'hl' : ''}"><td class="g"><b>${d.j}</b>${d.coupe ? `<small>${d.coupe}</small>` : ''}</td>
    <td class="st">${q1(matin)}</td><td>${sg(d.liv)}</td><td>${sg(d.ent)}</td><td>${sg(d.cor)}</td><td>${sg(-d.v)}</td><td>${sg(-d.w)}</td>
    <td class="st ${neg ? 'neg' : ''}">${neg ? '−' + q1(-stock) : q1(stock)}</td><td class="g ev">${ev.map(e => `<div>${e}</div>`).join('')}</td></tr>`;
}).join('');
const totV = JOURS.reduce((a, d) => a + d.v, 0), totL = JOURS.reduce((a, d) => a + d.liv, 0);
const detail = `<tr class="in-det"><td colspan="10"><div class="det">
  <div class="det-t"><div><b>Baguette Tradition 500 g.</b><small>Petite Boulangerie · 1,425 € la pièce · tous les mouvements de l’inventaire du dim. 18/10 14:36 à celui du dim. 25/10 12:40</small></div>
    <div class="det-act"><span class="vf-seg"><button class="on">Par jour</button><button>Par mouvement</button></span><button class="vf-btn">4 semaines</button><button class="vf-btn">Exporter</button><button class="x">✕</button></div></div>
  <div class="det-chips"><span>Compté au début <b>0</b></span><span class="pl">+ Livré validé <b>1 152</b></span><span class="mo">− Vendu (recettes) <b>837</b></span><span class="mo">− Jeté <b>0</b></span><span>= Attendu à l’inventaire <b>315</b></span><span>Compté <b>0</b></span><span class="ko">Écart <b>−315 pièces · −449 €</b></span></div>
  <div class="vf-lect">Du 18 au 21/10, <b>379 baguettes vendues sans aucune livraison validée</b> : le stock théorique passe sous zéro dès le lundi. La livraison n’est validée que le <b>jeudi 22/10 à 13:58, en une fois (1 152)</b>. À l’inventaire du 25/10, rien n’est compté alors que 315 devraient rester : soit cette livraison couvrait aussi les jours d’avant et d’autres livraisons manquent, soit des baguettes sortent sans passer en caisse ni en poubelle. À demander en visite : les bons de livraison de la semaine, et où sont comptées les baguettes surgelées.</div>
  <table class="vf-t det-tab"><thead>
    <tr class="grp"><th></th><th><span>Stock</span></th><th colspan="3"><span>Ce qui entre</span></th><th colspan="2"><span>Ce qui sort (recettes)</span></th><th><span>Stock</span></th><th></th></tr>
    <tr><th class="g">Jour</th><th>Théorique<small>le matin</small></th><th>+ Livraison<small>validée à la réception</small></th><th>+ Entrée<small>à la main</small></th><th>+/− Correction<small>à la main</small></th><th>− Vendu<small>tickets × recette</small></th><th>− Jeté<small>poubelle × recette</small></th><th>= Théorique<small>le soir</small></th><th class="g">Ce qui s’est passé</th></tr></thead><tbody>
    <tr class="inv"><td class="g"><b>Inventaire de début</b><small>dim. 18/10 14:36</small></td><td colspan="6" class="g">compté <b>0</b> — le panel attendait 108 (−108 à l’inventaire précédent)</td><td class="st">0</td><td class="g ev"><div>le stock repart du compté</div></td></tr>
    ${lignesJours}
    <tr class="inv fin"><td class="g"><b>Inventaire de fin</b><small>dim. 25/10 12:40</small></td><td colspan="6" class="g">attendu <b>${q1(stock)}</b> · compté <b>0</b></td><td class="st neg">−${q1(stock)}<small>${eur(stock * PRIX)} au coût</small></td><td class="g ev"><div><b>${q1(stock)} baguettes ni vendues, ni jetées, ni comptées</b></div></td></tr>
    <tr class="tot"><td class="g">La période</td><td class="st">0</td><td>${sg(totL)}</td><td>${sg(0)}</td><td>${sg(0)}</td><td>${sg(-totV)}</td><td>${sg(0)}</td><td class="st">${q1(stock)}</td><td class="g">7 jours · 1 livraison validée · 0 correction</td></tr>
  </tbody></table>
  <div class="det-2">
    <div><div class="det-st">Vendu par produit — ce qui consomme la baguette <small>journées du 18 au 25/10 entières</small></div>
      <table class="vf-t"><thead><tr><th class="g">Produit</th><th>Vendus</th><th>× recette</th><th>Baguettes</th></tr></thead><tbody>
      ${PROD.map(([n, r, v]) => `<tr><td class="g">${n}</td><td>${q1(v)}</td><td>${r === 1 ? '1' : '½'}</td><td><b>${q1(v * r)}</b></td></tr>`).join('')}
      <tr class="tot"><td class="g">+ 11 autres produits</td><td></td><td></td><td>89</td></tr></tbody></table></div>
    <div><div class="det-st">Semaine par semaine — d’un inventaire à l’autre <small>le relevé du stock commence le 12/10</small></div>
      <table class="vf-t"><thead><tr><th class="g">Semaine</th><th>Compté<small>au début</small></th><th>+ Livré</th><th>− Vendu</th><th>− Jeté</th><th>= Attendu</th><th>Compté<small>à la fin</small></th><th>Écart</th></tr></thead><tbody>
      ${SEMAINES.map(w => { const att = w.debut + w.liv + w.ent + w.cor - w.v - w.w, e = w.compte - att;
        return `<tr class="${w.on ? 'on' : ''}"><td class="g"><b>${w.s}</b><small>${w.de} → ${w.a.slice(5)}</small></td><td>${q1(w.debut)}</td><td>${sg(w.liv)}</td><td>${sg(-w.v)}</td><td>${sg(-w.w)}</td><td class="st">${att < 0 ? '−' + q1(-att) : q1(att)}</td><td>${q1(w.compte)}</td><td>${sg(e, 'att', 'mo')}<small>${eur(e * PRIX)}</small></td></tr>`; }).join('')}
      <tr class="tot"><td class="g">Deux semaines</td><td>108</td><td>${sg(1152)}</td><td>${sg(-1626.5)}</td><td>${sg(0)}</td><td class="st">−366,5</td><td>0</td><td>${sg(366.5, 'att', 'mo')}<small>+522 €</small></td></tr></tbody></table>
      <div class="det-n">En S42, 790 baguettes vendues sans une seule livraison validée ; en S43, une livraison de 1 152 validée en une fois, et 315 baguettes qui manquent. Sur les deux semaines, il manque des livraisons validées pour 367 baguettes : <b>le magasin reçoit plus qu’il ne valide</b>.</div></div>
  </div></div></td></tr>`;

const tableMat = MAT.map(([nom, cat, prix, deb, liv, fin, sorti, v, w, ecart, eurE, just, st], i) => {
  const S = STATUT[st];
  return `<tr class="in-row${i === 0 ? ' on' : ''}"><td class="g"><b>${i === 0 ? '▾ ' : '▸ '}${nom}</b> <span class="vf-st ${S[1]}">${S[0]}</span><small>${cat} · ${nf(prix, 3)} € / pce</small></td>
    <td>${q1(deb)}</td><td>${q1(liv)}</td><td>${q1(fin)}</td><td><b>${sorti < 0 ? '−' + q1(-sorti) : q1(sorti)}</b></td><td>${q1(v)}</td><td>${q1(w)}</td>
    <td>${sg(ecart, 'mo', 'att')}</td><td><b>${eurE < 0 ? '−' : ''}${eur(Math.abs(eurE))}</b></td><td>${just == null ? '—' : nf(just, 1) + ' %'}</td></tr>${i === 0 ? detail : ''}`;
}).join('');

const corps = `
<div class="vf-card" style="padding:12px 16px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;overflow:visible">
  <span class="lab">Magasin</span><select class="vf-sel"><option>Atelier by Berlo - Corbais</option></select>
  <span class="lab">Du</span><select class="vf-sel"><option>18/10 14:36 · 86 matières · relevé</option></select>
  <span class="lab">au</span><select class="vf-sel"><option>25/10 12:40 · 103 matières · relevé</option></select>
  <span class="vf-seg"><button class="on">1 semaine</button><button>4 semaines</button><button>12 semaines</button></span>
  <button class="vf-btn" style="margin-left:auto">Recalculer</button></div>
<div class="src">Relevé en direct depuis le 12/10 07:39 · 3 inventaires relevés (le dernier : 25/10 12:40) · copie du panel jusqu’au 12/07/2026</div>
<div class="tete"><div class="vf-kpi big att"><div class="k">Justesse des ventes</div><div class="v">${nf(T.justesse, 1)} %</div><div class="s">de ce qui est sorti du stock, la caisse et la poubelle l’expliquent</div><div class="p">du dim. 18/10 14:36 au dim. 25/10 12:40 · 7 jours</div></div>
  <div class="vf-kpis" style="grid-template-columns:repeat(4,1fr);margin:0">
  ${kpi('Stock au début', eur(T.debut), 'inventaire, au coût d’achat')}${kpi('+ Livré', eur(T.entrees), 'commandes validées à la réception')}${kpi('− Stock à la fin', eur(T.fin), 'inventaire, au coût d’achat')}${kpi('= Sorti du stock', eur(T.sorties), eur(T.explique) + ' que ventes et poubelle expliquent')}</div></div>
<div class="vf-card"><div class="vf-ct"><h3>Matière par matière</h3><span class="vf-chips"><button class="on">Toutes <b>${T.n}</b></button><button>Fiables <b>79</b></button><button>Sans recette <b>7</b></button><button>À vérifier <b>5</b></button><button>Vendu sans sortie <b>7</b></button></span><span class="s r">début + livré − fin = sorti · sorti − vendu − jeté = écart · <b>cliquer une ligne pour tous ses mouvements</b></span></div>
  <table class="vf-t mat"><thead><tr><th class="g">Matière</th><th>Début</th><th>Livré</th><th>Fin</th><th>Sorti</th><th>Vendu<small>recettes</small></th><th>Jeté</th><th>Écart<small>pièces</small></th><th>Écart<small>au coût</small></th><th>Justesse</th></tr></thead>
  <tbody>${tableMat}</tbody></table>
  <div class="vf-pied">Une ligne cliquée s’ouvre sur le tableau de tous ses mouvements, d’un inventaire à l’autre : une ligne par jour (ou par mouvement), les + et les − en colonnes, le stock théorique du soir, et l’inventaire qui ferme la période — puis la même lecture semaine par semaine. Le stock théorique part du compté et suit les livraisons validées, les entrées et corrections à la main, le vendu et le jeté passés par les recettes ; l’écart est ce que l’inventaire de fin ne retrouve pas.</div></div>`;

const html = `<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Entre deux inventaires — le détail d’un produit — Cockpit CEO</title>
<link rel="stylesheet" href="/public/assets/ds/global.css"><link rel="stylesheet" href="/public/assets/css/app.css"><link rel="stylesheet" href="vf.css">
<style>
.lab{font-size:12px;font-weight:600;color:#555}
.src{font-size:11.5px;color:var(--color-text-muted);margin:-6px 0 14px;font-weight:600}
.tete{display:grid;grid-template-columns:300px 1fr;gap:12px;margin-bottom:16px}
.vf-kpi.big .v{font-size:40px}.vf-kpi.big .p{font-size:11px;color:var(--color-text-muted);margin-top:8px}
.vf-st{font:600 10px var(--font-ui);padding:2px 7px;border-radius:999px;margin-left:6px;white-space:nowrap}
.vf-st.ok{background:rgba(45,122,62,.1);color:#2d7a3e}.vf-st.att{background:rgba(217,119,6,.13);color:#B26A00}.vf-st.mu{background:#efe9e1;color:#777}
.mat tr.in-row td{cursor:pointer}.mat tr.in-row.on td{background:#fbf3f4;border-bottom:none}
.mat td.g small{display:block}
.pl{color:#2d7a3e;font-weight:600}.mo{color:#C0182B;font-weight:600}.att{color:#B26A00;font-weight:600}.z{color:#bbb}
tr.in-det>td{padding:0!important;background:#fbf3f4;text-align:left;white-space:normal}
.det{max-width:1116px;box-sizing:border-box;margin:0 12px 14px;background:var(--color-surface);border:.5px solid rgba(141,29,44,.25);border-radius:12px;overflow:hidden;box-shadow:0 6px 20px rgba(0,0,0,.06)}
.det-t{display:flex;align-items:flex-start;gap:12px;padding:14px 18px 10px}
.det-t>div:first-child{flex:1}.det-t b{font:400 22px var(--font-display);display:block}.det-t small{font-size:12px;color:var(--color-text-muted)}
.det-act{display:flex;gap:8px;align-items:center}.det-act .x{border:none;background:#f3efe9;width:30px;height:30px;border-radius:50%;font-size:14px;color:#555}
.det-chips{display:flex;gap:8px;flex-wrap:wrap;padding:0 18px 12px}
.det-chips span{border:.5px solid var(--color-border-secondary);border-radius:999px;padding:4px 11px;font-size:11.5px;color:#555;background:#fff}
.det-chips span b{color:var(--color-text);margin-left:3px}.det-chips span.pl b{color:#2d7a3e}.det-chips span.mo b{color:#C0182B}
.det-chips span.ko{background:#fbe3e6;border-color:#f0b9c1}.det-chips span.ko b{color:#C0182B}
.det-tab td.st,.det-tab th:nth-child(2),.det-tab th:nth-child(8){background:#faf6f1}
.det-tab td.st{font-weight:700}.det-tab td.st.neg{color:#C0182B}
.det-tab tr.hl td{background:#f2f8f3}.det-tab tr.hl td.st{background:#e8f2ea}
.det-tab tr.inv td{background:#f6f1ea;font-size:12px}.det-tab tr.inv.fin td{background:#fbe3e6}
.det-tab td.ev{white-space:normal;font-size:11px;color:#555;min-width:170px;max-width:230px;line-height:1.35}
.det-tab th,.det-tab td{padding:7px 6px}
.det-2 .vf-t{font-size:11.5px}.det-2 .vf-t th,.det-2 .vf-t td{padding:6px 5px}
.det .vf-lect{font-size:12px}
.det-tab td.ev div+div{margin-top:2px}
.det-tab tr.tot td,.det-2 tr.tot td{font-weight:700;background:#f6f1ea;border-top:1px solid rgba(34,34,34,.18)}
.det-2{display:grid;grid-template-columns:1fr 1.25fr;gap:14px;padding:14px 18px 16px;border-top:.5px solid var(--vf-trait)}
.det-st{font:600 10.5px var(--font-ui);letter-spacing:.07em;text-transform:uppercase;color:var(--color-primary);margin-bottom:6px}
.det-st small{font:500 11px var(--font-ui);letter-spacing:0;text-transform:none;color:var(--color-text-muted);margin-left:6px}
.det-2 tr.on td{background:#fbf3f4}
.det-n{font-size:12px;line-height:1.5;margin-top:8px;color:#444}
</style></head>
<body><div id="app"><div style="display:flex;min-height:100vh;background:var(--color-bg);font-family:var(--font-ui);color:var(--color-text);position:relative">
${rail('Inventaires')}
<main style="flex:1;min-width:0"><div style="padding:26px 32px 60px;max-width:1460px">
<header style="margin-bottom:20px"><h1 style="font-family:var(--font-display);font-size:30px;font-weight:400;margin:0;line-height:1.15">Entre deux inventaires</h1>
<p style="font-size:13px;color:var(--color-text-muted);margin:5px 0 0;max-width:760px;font-weight:600">Par magasin, entre deux inventaires : ce qui est sorti du stock (compté au début + livré − compté à la fin) face à ce que la caisse et la poubelle expliquent, ramené aux matières par les recettes. Un clic sur une matière : tous ses mouvements, jour par jour.</p></header>
${corps}
</div></main></div></div><div class="mq-illu">Maquette · chiffres réels de Corbais, 18 → 25/10</div></body></html>`;
fs.writeFileSync(path.join(__dirname, 'h.html'), html);
console.log('h.html écrit (' + html.length + ' octets)');
