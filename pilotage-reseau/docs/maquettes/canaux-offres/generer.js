/* Trois maquettes : A — le split des commandes par canal (comptoir, click &
 * collect, livraison) dans le dashboard magasin ; B — le suivi des promotions
 * et des bundles dans le dashboard magasin ; C — la vue réseau des deux dans
 * le cockpit (Marque & marketing › Offres et canaux).
 *
 * RÉEL : la journée de Halle (shop 4) du vendredi 02/10/2026 (1 881 € de
 * ventes, 154 tickets, 254 € de clients pro) et ses 14 derniers jours
 * (reel-halle.json, lu sur /exploitation/pro), les coques du dashboard et du
 * cockpit en ligne. ILLUSTRÉ : la part webshop (click & collect, livraison)
 * et les offres — au 03/10/2026, le réseau n'a adopté aucune promotion
 * « jours creux », n'a vendu aucun produit de la catégorie « Bundle &
 * Promotion » en septembre, et le webshop n'a qu'une commande en base
 * (juillet). Les chiffres illustrés sont posés sur la journée réelle.
 *
 *   node docs/maquettes/canaux-offres/generer.js   (serveur statique sur 8099 depuis la racine)
 */
const fs = require('fs'), path = require('path');
const { chromium } = require('/opt/node22/lib/node_modules/playwright');
const OUT = __dirname + '/';
const R = require('./reel-halle.json');
const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const nf = (n, d) => Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d || 0, maximumFractionDigits: d || 0 });
const fE = n => nf(Math.round(n)) + ' €';
const fU = n => nf(n, 2) + ' €';
const fP = n => nf(n, 1) + ' %';
const fP0 = n => nf(Math.round(n)) + ' %';
const JOURS = ['di', 'lu', 'ma', 'me', 'je', 've', 'sa'];

/* --- Les chiffres ----------------------------------------------------------- */
const J = R.jour;                                    // réel : 1 880,6 € · 154 tickets · pro 254,4 €
const serie = R.serie.slice(-14);                    // réel : 14 jours de CA
// Le webshop a ouvert le 1er octobre : rien avant le 29/09 (tests), puis une part qui monte. Illustré.
const WS = { '2026-09-29': [1, 18.4, 0, 0], '2026-09-30': [2, 31.2, 1, 48.0], '2026-10-01': [4, 79.6, 2, 116.5], '2026-10-02': [7, 142.6, 3, 186.5] };
const cc = { n: 7, ca: 142.6, retirees: 5, aPreparer: 2 }, liv = { n: 3, ca: 186.5, livrees: 2, enRoute: 1 };
const compt = { ca: J.ca - cc.ca - liv.ca, tickets: J.tickets - cc.n - liv.n };
const commandes = [
  ['07:30', 'w', 4, 18.4, 'remise'], ['08:15', 'l', 18, 62.5, 'livrée'], ['09:00', 'w', 2, 9.8, 'remise'], ['10:30', 'w', 6, 27.9, 'remise'],
  ['11:00', 'l', 24, 78.0, 'livrée'], ['12:00', 'w', 3, 14.5, 'remise'], ['12:30', 'l', 12, 46.0, 'en route'], ['16:00', 'w', 5, 22.6, 'remise'],
  ['17:30', 'w', 8, 31.2, 'à préparer'], ['18:00', 'w', 4, 18.2, 'à préparer'],
];
const offres = [
  { type: 'b', nom: 'Formule petit-déj', regle: 'croissant + boisson chaude · 3,90 €', canaux: ['c', 'w'], depuis: '2026-09-28', auj: [23, 89.7], sept: [148, 577.2], marge: 71, ref: null, delta: null, verdict: ['Garder', 'ok'], spark: [14, 19, 22, 21, 25, 24, 23], mot: '12 % des tickets du matin, panier +1,10 €' },
  { type: 'b', nom: 'Box apéro 6 personnes', regle: 'webshop · livraison au bureau · 39 €', canaux: ['l'], depuis: '2026-10-01', auj: [3, 117.0], sept: [11, 429.0], marge: 58, ref: null, delta: null, verdict: ['Trop tôt', 'tot'], spark: [0, 0, 0, 0, 2, 6, 3], mot: '2 jours lus · 4 bureaux servis' },
  { type: 'p', nom: 'Tartes −20 % de 16 à 18 h', regle: 'jours creux · trafic · mar → jeu', canaux: ['c'], depuis: '2026-09-22', auj: [0, 0], sept: [87, 348.0], marge: 49, ref: { caH: 96, tkH: 9.1 }, delta: [11, 9], verdict: ['Garder', 'ok'], spark: [0, 11, 14, 12, 0, 0, 0], mot: 'CA/h 107 € contre 96 € en référence' },
  { type: 'p', nom: 'Pain du jour −30 % après 17 h', regle: 'jours creux · écouler · tous les jours', canaux: ['c', 'w'], depuis: '2026-09-15', auj: [9, 20.7], sept: [63, 145.0], marge: 44, ref: { pertes: 31 }, delta: [-38, null], verdict: ['Ajuster', 'att'], spark: [8, 10, 9, 11, 7, 9, 9], mot: 'pertes −38 % · marge du créneau 44 %, sous le seuil' },
];
const CANAL = { c: ['Comptoir', 'c'], w: ['Click & collect', 'w'], l: ['Livraison', 'l'] };
const canal = k => `<span class="co-mode ${CANAL[k][1]}">${CANAL[k][0]}</span>`;
const STAT = { 'remise': ['remise', 'ok'], 'livrée': ['livrée', 'ok'], 'en route': ['en route', 'enc'], 'à préparer': ['à préparer', 'att'] };

/* --- A : la carte « Commandes et canaux » ----------------------------------------- */
const pct = v => 100 * v / J.ca;
const piles = serie.map(s => {
  const w = WS[s.j] || [0, 0, 0, 0]; const c = s.ca - w[1] - w[3];
  return { j: s.j, c, w: w[1], l: w[3], tot: s.ca };
});
const maxJ = Math.max(...piles.map(p => p.tot));
const A = `<div class="db-card db-split on" id="mq-a"><div class="ct"><span class="db-lab">Commandes et canaux — la journée</span><span class="db-mini">comptoir = tickets caisse (dont clients pro) · click &amp; collect = commande webshop retirée en boutique · livraison = commande webshop livrée au bureau · part webshop illustrée</span><span class="db-cdr" style="padding:0;margin-left:auto;white-space:nowrap">replier ▴</span></div>
  <div class="db-split-bar" title="comptoir ${fP(pct(compt.ca))} · click & collect ${fP(pct(cc.ca))} · livraison ${fP(pct(liv.ca))}"><i class="c" style="width:${pct(compt.ca).toFixed(1)}%"></i><i class="w" style="width:${pct(cc.ca).toFixed(1)}%"></i><i class="l" style="width:${pct(liv.ca).toFixed(1)}%"></i></div>
  <div class="db-split-g co3">
    <div class="c"><div class="k">Comptoir</div><div class="v">${fE(compt.ca)}</div><div class="s">${fP(pct(compt.ca))} du CA · ${compt.tickets} tickets · panier ${fU(compt.ca / compt.tickets)}<br>dont clients pro <b>${fE(J.caPro)}</b> (${J.ticketsPro} tickets, à facturer)</div></div>
    <div class="w"><div class="k">Click &amp; collect</div><div class="v">${fE(cc.ca)}</div><div class="s">${fP(pct(cc.ca))} du CA · ${cc.n} commandes · panier ${fU(cc.ca / cc.n)}<br><b>${cc.retirees} retirées</b> · ${cc.aPreparer} à préparer pour ce soir</div></div>
    <div class="l"><div class="k">Livraison</div><div class="v">${fE(liv.ca)}</div><div class="s">${fP(pct(liv.ca))} du CA · ${liv.n} commandes · panier ${fU(liv.ca / liv.n)}<br><b>${liv.livrees} livrées</b> · ${liv.enRoute} en route · 3 bureaux</div></div>
  </div>
  <div class="co-corps">
    <div><span class="db-lab">Les 14 derniers jours — par canal</span>
      <div class="co-pile">${piles.map(p => { const d = new Date(p.j + 'T12:00:00').getDay(), we = d === 0 || d === 6; const h = v => (100 * v / maxJ).toFixed(1) + '%'; return `<span class="${we ? 'we' : ''}" title="${p.j} · ${fE(p.tot)} dont webshop ${fE(p.w + p.l)}"><i class="c" style="height:${h(p.c)}"></i><i class="w" style="height:${h(p.w)}"></i><i class="l" style="height:${h(p.l)}"></i></span>`; }).join('')}</div>
      <div class="co-axe">${piles.map(p => { const d = new Date(p.j + 'T12:00:00'); const we = d.getDay() === 0 || d.getDay() === 6; return `<span class="${we ? 'we' : ''}">${JOURS[d.getDay()]} ${d.getDate()}</span>`; }).join('')}</div>
      <div class="db-leg" style="padding:8px 0 0"><span><i style="background:#b8ad9f"></i>comptoir</span><span><i style="background:#1f5f8b"></i>click &amp; collect</span><span><i style="background:#0f3b5c"></i>livraison</span><span style="margin-left:auto">webshop ouvert le 1er octobre · 14 jours : <b>${fE(piles.reduce((a, p) => a + p.w + p.l, 0))}</b> de webshop sur ${fE(piles.reduce((a, p) => a + p.tot, 0))}</span></div></div>
    <div><span class="db-lab">Les commandes webshop du jour</span>
      <table class="db-pro-tab co-tab" style="margin:6px 0 0;width:100%"><thead><tr><th>Retrait</th><th>Canal</th><th class="n">Articles</th><th class="n">Montant</th><th></th></tr></thead><tbody>${commandes.map(c => `<tr><td class="mu">${c[0]}</td><td>${canal(c[1])}</td><td class="n">${c[2]}</td><td class="n">${fU(c[3])}</td><td><span class="co-st ${STAT[c[4]][1]}">${STAT[c[4]][0]}</span></td></tr>`).join('')}</tbody></table>
      <div class="db-mini" style="margin-top:8px">${cc.n + liv.n} commandes · ${fE(cc.ca + liv.ca)} · <b>2 à préparer</b> pour 17 h 30 et 18 h · demain : 4 commandes déjà prises (${fE(96.3)})</div></div>
  </div></div>`;

/* --- B : la carte « Promotions et bundles » ----------------------------------------- */
const totSept = offres.reduce((a, o) => a + o.sept[1], 0), piecesSept = offres.reduce((a, o) => a + o.sept[0], 0);
const margeSept = offres.reduce((a, o) => a + o.sept[1] * o.marge / 100, 0);
const spark = s => { const m = Math.max(1, ...s); return `<span class="co-spark">${s.map((v, i) => `<i class="${i === s.length - 1 ? 'auj' : ''}" style="height:${Math.max(2, Math.round(100 * v / m))}%"></i>`).join('')}</span>`; };
const delta = o => o.delta == null ? `<span class="db-mini">pas de référence : le bundle est nouveau</span>` : `<span class="co-d ${o.delta[0] >= 0 ? 'ok' : 'ko'}">${o.delta[0] >= 0 ? '+' : '−'}${Math.abs(o.delta[0])} %</span> <span class="db-mini">${o.type === 'p' && o.ref.caH ? 'de CA sur le créneau' + (o.delta[1] != null ? ', ' + (o.delta[1] >= 0 ? '+' : '−') + Math.abs(o.delta[1]) + ' % de clients' : '') : 'de pertes sur le créneau'}</span>`;
const B = `<div class="db-card" id="mq-b"><div class="ct"><span class="db-lab">Promotions et bundles — ce qu’elles rapportent</span><span class="db-mini">bundles = produits de la catégorie « Bundle &amp; Promotion » du panel · promotions = celles posées par le cockpit sur les jours creux · référence : les 4 semaines d’avant, mêmes jours, mêmes heures · chiffres illustrés</span></div>
  <div class="db-obj-t4 co-kpi">
    <div><div class="k">CA des offres · 7 jours</div><div class="v">${fE(totSept)}</div><div class="s">${fP(100 * totSept / 12400)} du CA de la semaine · ${piecesSept} pièces et commandes</div></div>
    <div><div class="k">Aujourd’hui</div><div class="v">${fE(offres.reduce((a, o) => a + o.auj[1], 0))}</div><div class="s">${offres.reduce((a, o) => a + o.auj[0], 0)} pièces · ${fP(100 * offres.reduce((a, o) => a + o.auj[1], 0) / J.ca)} du jour</div></div>
    <div><div class="k">Marge brute des offres</div><div class="v">${fP0(100 * margeSept / totSept)}</div><div class="s">${fE(margeSept)} sur 7 jours · coef × ${nf(1 / (1 - margeSept / totSept), 2)}</div></div>
    <div><div class="k">Actives</div><div class="v">2 + 2</div><div class="s">2 bundles, 2 promotions · 1 à ajuster</div></div>
  </div>
  <table class="db-pro-tab co-tab"><thead><tr><th>Offre</th><th>Canaux</th><th>Depuis</th><th class="n">Aujourd’hui</th><th class="n">7 jours</th><th class="n">Marge</th><th>7 jours</th><th>Face à la référence</th><th>Verdict</th></tr></thead><tbody>
    ${offres.map(o => `<tr><td class="off"><span class="co-type ${o.type}">${o.type === 'b' ? 'Bundle' : 'Promo'}</span><b style="display:inline">${esc(o.nom)}</b><small>${esc(o.regle)}</small></td><td>${o.canaux.map(canal).join(' ')}</td><td class="mu">${o.depuis.slice(8, 10)}/${o.depuis.slice(5, 7)}</td><td class="n">${o.auj[0] ? o.auj[0] + ' · ' + fE(o.auj[1]) : '<span class="mu">pas ce jour</span>'}</td><td class="n"><b>${o.sept[0]}</b> · ${fE(o.sept[1])}</td><td class="n" style="color:${o.marge >= 60 ? '#2d7a3e' : o.marge >= 50 ? '#B26A00' : '#C0182B'};font-weight:600">${o.marge} % <span class="mu" style="font-weight:500">× ${nf(1 / (1 - o.marge / 100), 2)}</span></td><td>${spark(o.spark)}</td><td>${delta(o)}<div class="db-mini">${esc(o.mot)}</div></td><td><span class="co-verdict ${o.verdict[1]}">${o.verdict[0]}</span></td></tr>`).join('')}
  </tbody></table>
  <div class="db-leg" style="padding-top:10px"><span><span class="co-verdict ok">Garder</span> l’offre rapporte : CA du créneau ≥ +8 % ou marge tenue</span><span><span class="co-verdict att">Ajuster</span> entre −3 % et +8 %, ou marge sous le seuil</span><span><span class="co-verdict tot">Trop tôt</span> moins de 5 jours lus</span><span style="margin-left:auto">un clic sur une offre ouvre son détail jour par jour</span></div></div>`;

/* --- C : la vue réseau dans le cockpit ------------------------------------------- */
const SHOPS = [['Corbais', 1480, 0, 0, [31, 0, 18, 12]], ['Gosselies', 1357, 96, 0, [26, 2, 22, 9]], ['Halle', compt.ca, cc.ca, liv.ca, [23, 3, 0, 9]], ['Sombreffe', 1212, 41, 0, [19, 0, 14, 7]]];
const card = 'background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px';
const TH = 'text-align:left;font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);padding:12px 14px;border-bottom:0.5px solid var(--color-border-tertiary)';
const td = 'padding:10px 14px;border-top:0.5px solid var(--color-border-tertiary);font-size:12.5px';
const totWeb = SHOPS.reduce((a, s) => a + s[2] + s[3], 0), totCA = SHOPS.reduce((a, s) => a + s[1] + s[2] + s[3], 0);
const C = `<header style="display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin-bottom:22px"><div><h1 style="font-family:var(--font-display);font-size:30px;font-weight:400;margin:0;line-height:1.15">Offres et canaux</h1><p style="font-size:13px;color:var(--color-text-muted);margin:5px 0 0;max-width:640px">Ce que les promotions et les bundles rapportent, magasin par magasin, et par où passent les commandes : comptoir, click &amp; collect, livraison.</p></div><div style="font-size:12px;color:var(--color-text-muted)">vendredi 2 octobre 2026 · chiffres illustrés sur la journée réelle de Halle</div></header>
  <div style="display:inline-flex;background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:11px;padding:3px;margin-bottom:14px;gap:2px"><button style="font-family:var(--font-ui);font-size:12.5px;font-weight:600;padding:8px 16px;border-radius:8px;border:none;background:var(--color-primary);color:#fff">Aujourd’hui</button><button style="font-family:var(--font-ui);font-size:12.5px;font-weight:600;padding:8px 16px;border-radius:8px;border:none;background:transparent;color:var(--color-text)">7 jours</button><button style="font-family:var(--font-ui);font-size:12.5px;font-weight:600;padding:8px 16px;border-radius:8px;border:none;background:transparent;color:var(--color-text)">30 jours</button></div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;margin-bottom:16px">
    ${[['Webshop · réseau', fE(totWeb), fP(100 * totWeb / totCA) + ' du CA du jour · 13 commandes · 1 magasin livre'], ['Click & collect', fE(SHOPS.reduce((a, s) => a + s[2], 0)), '10 commandes · panier 20,40 € · 3 magasins'], ['Livraison', fE(SHOPS.reduce((a, s) => a + s[3], 0)), '3 commandes · panier 62,17 € · Halle seul, 3 bureaux'], ['Offres actives', '4', '2 bundles · 2 promotions · 1 à ajuster'], ['CA des offres', fE(offres.reduce((a, o) => a + o.auj[1], 0) + 96), '6,4 % du jour · marge 63 %']]
      .map(k => `<div style="${card};padding:16px 18px"><div style="font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.08em;color:var(--color-text-muted)">${k[0]}</div><div style="font-size:26px;font-weight:500;margin-top:6px;line-height:1.1">${k[1]}</div><div style="font-size:11.5px;color:var(--color-text-muted);margin-top:4px">${k[2]}</div></div>`).join('')}
  </div>
  <div style="${card};overflow:hidden;margin-bottom:16px"><div style="padding:13px 18px;border-bottom:0.5px solid var(--color-border-tertiary);display:flex;align-items:center;gap:12px"><div style="font-size:13.5px;font-weight:600">Les commandes par canal, magasin par magasin</div><div style="font-size:11.5px;color:var(--color-text-muted);margin-left:auto">comptoir · click &amp; collect · livraison — la part webshop, et ce qu’elle vaut</div></div>
    <table style="width:100%;border-collapse:collapse"><thead><tr><th style="${TH}">Magasin</th><th style="${TH};width:38%">Part de chaque canal</th><th style="${TH};text-align:right">Comptoir</th><th style="${TH};text-align:right">Click &amp; collect</th><th style="${TH};text-align:right">Livraison</th><th style="${TH};text-align:right">Webshop</th></tr></thead><tbody>
    ${SHOPS.map(s => { const t = s[1] + s[2] + s[3]; const w = v => (100 * v / t).toFixed(1); return `<tr><td style="${td};font-weight:600">${s[0]}</td><td style="${td}"><div class="db-split-bar" style="margin:0"><i class="c" style="width:${w(s[1])}%"></i><i class="w" style="width:${w(s[2])}%"></i><i class="l" style="width:${w(s[3])}%"></i></div></td><td style="${td};text-align:right">${fE(s[1])}</td><td style="${td};text-align:right;color:#1f5f8b;font-weight:600">${s[2] ? fE(s[2]) : '<span style="color:var(--color-text-muted);font-weight:400">—</span>'}</td><td style="${td};text-align:right;color:#0f3b5c;font-weight:600">${s[3] ? fE(s[3]) : '<span style="color:var(--color-text-muted);font-weight:400">—</span>'}</td><td style="${td};text-align:right;font-weight:600">${s[2] + s[3] ? fP(100 * (s[2] + s[3]) / t) : '<span style="color:var(--color-text-muted);font-weight:400">pas encore</span>'}</td></tr>`; }).join('')}
    <tr><td style="${td};font-weight:600;border-top:1px solid var(--color-border-secondary)">Réseau</td><td style="${td};border-top:1px solid var(--color-border-secondary)"><div class="db-split-bar" style="margin:0"><i class="c" style="width:${(100 * SHOPS.reduce((a, s) => a + s[1], 0) / totCA).toFixed(1)}%"></i><i class="w" style="width:${(100 * SHOPS.reduce((a, s) => a + s[2], 0) / totCA).toFixed(1)}%"></i><i class="l" style="width:${(100 * SHOPS.reduce((a, s) => a + s[3], 0) / totCA).toFixed(1)}%"></i></div></td><td style="${td};text-align:right;font-weight:600;border-top:1px solid var(--color-border-secondary)">${fE(SHOPS.reduce((a, s) => a + s[1], 0))}</td><td style="${td};text-align:right;font-weight:600;border-top:1px solid var(--color-border-secondary)">${fE(SHOPS.reduce((a, s) => a + s[2], 0))}</td><td style="${td};text-align:right;font-weight:600;border-top:1px solid var(--color-border-secondary)">${fE(SHOPS.reduce((a, s) => a + s[3], 0))}</td><td style="${td};text-align:right;font-weight:600;border-top:1px solid var(--color-border-secondary)">${fP(100 * totWeb / totCA)}</td></tr>
    </tbody></table><div class="db-leg" style="padding:8px 18px 12px"><span><i style="background:#b8ad9f"></i>comptoir</span><span><i style="background:#1f5f8b"></i>click &amp; collect</span><span><i style="background:#0f3b5c"></i>livraison</span><span style="margin-left:auto">source : commandes du panel (<code>is_webshop</code>, <code>fulfilment_mode</code>) et tickets caisse</span></div></div>
  <div style="${card};overflow:hidden"><div style="padding:13px 18px;border-bottom:0.5px solid var(--color-border-tertiary);display:flex;align-items:center;gap:12px"><div style="font-size:13.5px;font-weight:600">Les offres, magasin par magasin</div><div style="font-size:11.5px;color:var(--color-text-muted);margin-left:auto">pièces vendues aujourd’hui · le verdict est celui de chaque magasin, face à sa propre référence</div></div>
    <table style="width:100%;border-collapse:collapse"><thead><tr><th style="${TH}">Offre</th>${SHOPS.map(s => `<th style="${TH};text-align:right">${s[0]}</th>`).join('')}<th style="${TH};text-align:right">Réseau</th><th style="${TH}">Verdict</th></tr></thead><tbody>
    ${offres.map((o, i) => `<tr><td style="${td}"><span class="co-type ${o.type}">${o.type === 'b' ? 'Bundle' : 'Promo'}</span><b>${esc(o.nom)}</b><div style="font-size:11px;color:var(--color-text-muted)">${esc(o.regle)}</div></td>${SHOPS.map(s => `<td style="${td};text-align:right;font-variant-numeric:tabular-nums">${s[4][i] ? '<b>' + s[4][i] + '</b>' : '<span style="color:var(--color-text-muted)">—</span>'}</td>`).join('')}<td style="${td};text-align:right;font-weight:700">${SHOPS.reduce((a, s) => a + s[4][i], 0)}</td><td style="${td}"><span class="co-verdict ${o.verdict[1]}">${o.verdict[0]}</span> <span style="font-size:11px;color:var(--color-text-muted)">${esc(o.mot)}</span></td></tr>`).join('')}
    </tbody></table></div>`;

/* --- Les pages ------------------------------------------------------------------- */
function finDiv(h, i) { let prof = 0; const re = /<div\b|<\/div>/g; re.lastIndex = i; let m; while ((m = re.exec(h))) { if (m[0] === '<div') { prof++; } else { prof--; if (prof === 0) { return m.index + 6; } } } return -1; }
function dashboard(remplace, ajoute) {
  let h = fs.readFileSync(OUT + 'contexte-dashboard.html', 'utf8');
  h = h.replace(/<script[\s\S]*?<\/script>/g, '').replace(/href="dashboard\.css"/g, 'href="/public/dashboard/dashboard.css"').replace(/(src|href)="\.\.\/assets\//g, '$1="/public/assets/').replace(/(src|href)="\/consulant_bo\/assets\//g, '$1="/public/assets/');
  const i = h.indexOf('<div class="db-card db-split'); const j = finDiv(h, i);
  h = h.slice(0, i) + (remplace || h.slice(i, j)) + (ajoute || '') + h.slice(j);
  return h.replace('</head>', '<link rel="stylesheet" href="co.css"></head>');
}
function cockpit(corps) {
  let h = fs.readFileSync(OUT + 'contexte-cockpit.html', 'utf8');
  h = h.replace(/<script[\s\S]*?<\/script>/g, '').replace(/(src|href)="assets\//g, '$1="/public/assets/').replace(/(src|href)="\/consulant_bo\/assets\//g, '$1="/public/assets/');
  const i = h.indexOf('<header', h.indexOf('id="main-scroll"'));
  const k = h.indexOf('data-screen="controle"'); const d0 = h.lastIndexOf('<div', k); const d1 = finDiv(h, d0);
  h = h.slice(0, i) + corps + h.slice(d1);
  return h.replace('</head>', '<link rel="stylesheet" href="/public/dashboard/dashboard.css"><link rel="stylesheet" href="co.css"><style>body .db-leg{padding-left:0}</style></head>');
}
const PLANCHE = { titre: 'Commandes par canal, promotions et bundles : trois maquettes', items: [
  { id: 'a', titre: 'A — Dashboard magasin : commandes et canaux', img: 'a.jpg', acc: 'La carte « Comptoir et clients pro » devient « Commandes et canaux » : une barre à trois segments et trois tuiles — comptoir (tickets caisse, dont les clients pro), click & collect et livraison (les commandes webshop, retirées en boutique ou livrées au bureau) — avec le CA, la part, le nombre de commandes, le panier et l’état du jour. Dessous, les 14 derniers jours empilés par canal, et la liste des commandes webshop du jour à préparer.',
    plus: ['Le webshop se lit dans le même geste que le comptoir : une barre, trois chiffres', 'Ce qu’il reste à préparer ce soir est écrit, pas à deviner', 'La part webshop jour après jour depuis l’ouverture du 1er octobre', 'Source déjà là : les commandes du panel portent is_webshop et fulfilment_mode'], moins: ['Une commande webshop payée en ligne n’est pas un ticket caisse : à poser une fois pour toutes (CA = tickets + webshop, ou webshop inclus)', 'Les livraisons dépendent des sites de livraison (bureaux) : à nommer sans rien de nominatif'] },
  { id: 'b', titre: 'B — Dashboard magasin : promotions et bundles', img: 'b.jpg', acc: 'Une carte par magasin : quatre chiffres (CA des offres sur 7 jours, aujourd’hui, marge brute et coefficient, offres actives), puis une ligne par offre — bundle ou promotion, ses canaux, depuis quand, pièces et CA du jour et des 7 jours, marge, la tendance des 7 jours, l’effet face à la référence des 4 semaines d’avant (déjà calculé pour les promotions jours creux), et un verdict : garder, ajuster, trop tôt.',
    plus: ['Même verdict et même référence que les promotions jours creux : rien de nouveau à apprendre', 'Un bundle nouveau dit « pas de référence » plutôt qu’un faux effet', 'La marge et le coefficient de chaque offre, à côté de ce qu’elle vend'], moins: ['Les bundles se reconnaissent par la catégorie « Bundle & Promotion » du panel : si un bundle est rangé ailleurs, il échappe', 'Illustré : aucun bundle vendu ni promotion adoptée dans le réseau en septembre'] },
  { id: 'c', titre: 'C — Cockpit : la vue réseau, Marque & marketing › Offres et canaux', img: 'c.jpg', acc: 'Une page du cockpit pour la direction : les cinq chiffres du réseau (webshop, click & collect, livraison, offres actives, CA des offres), les commandes par canal magasin par magasin avec la part webshop de chacun, puis les offres en lignes et les magasins en colonnes — où chaque offre marche, où elle ne marche pas, avec le verdict de chaque magasin.',
    plus: ['Le réseau d’un coup d’œil : qui livre, qui vend des bundles, qui n’a pas démarré', 'Les mêmes trois couleurs de canal que le dashboard', 'Bascule aujourd’hui / 7 jours / 30 jours'], moins: ['Les commandes du panel se lisent magasin par magasin : quatre appels par lecture, à mettre en cache', 'Les verdicts par magasin demandent une référence par magasin et par offre'] } ] };

(async () => {
  fs.writeFileSync(OUT + 'a.html', dashboard(A, ''));
  fs.writeFileSync(OUT + 'b.html', dashboard(null, B));
  fs.writeFileSync(OUT + 'c.html', cockpit(C));
  const b = await chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost'] });
  const p = await b.newPage({ viewport: { width: 1348, height: 1000 }, deviceScaleFactor: 2 });
  for (const id of ['a', 'b']) {
    await p.goto('http://127.0.0.1:8099/docs/maquettes/canaux-offres/' + id + '.html', { waitUntil: 'load' }); await p.waitForTimeout(700);
    const el = await p.$('#mq-' + id); await el.screenshot({ path: OUT + id + '.jpg', type: 'jpeg', quality: 88 });
    await p.evaluate(i => { document.getElementById('mq-' + i).scrollIntoView({ block: 'start' }); window.scrollBy(0, -70); }, id); await p.waitForTimeout(300);
    await p.screenshot({ path: OUT + id + '-page.jpg', type: 'jpeg', quality: 80 });
    console.log('✓', id, await el.evaluate(e => e.getBoundingClientRect().width + '×' + Math.round(e.getBoundingClientRect().height) + ' · débordement ' + e.scrollWidth + '/' + e.clientWidth));
  }
  const q = await b.newPage({ viewport: { width: 1400, height: 1120 }, deviceScaleFactor: 1.5 });
  await q.goto('http://127.0.0.1:8099/docs/maquettes/canaux-offres/c.html', { waitUntil: 'load' }); await q.waitForTimeout(700);
  await q.screenshot({ path: OUT + 'c.jpg', type: 'jpeg', quality: 86 });
  console.log('✓ c');
  const html = `<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>${esc(PLANCHE.titre)}</title><link rel="stylesheet" href="/public/assets/ds/global.css"><link rel="stylesheet" href="co.css"></head><body><div class="pl"><span class="u">Maquettes · dashboard magasin et cockpit · journée réelle de Halle, 02/10/2026 · webshop et offres illustrés</span><h1>${esc(PLANCHE.titre)}</h1>
  ${PLANCHE.items.map(it => `<h2>${esc(it.titre)}</h2><p class="acc">${esc(it.acc)}</p><div class="g"><div style="flex:1;min-width:0"><img src="${it.img}"></div><div class="col"><h4>Ce que ça apporte</h4><ul class="pl-p">${it.plus.map(x => `<li>${esc(x)}</li>`).join('')}</ul><h4>Limites</h4><ul class="pl-m">${it.moins.map(x => `<li>${esc(x)}</li>`).join('')}</ul></div></div>`).join('')}</div></body></html>`;
  fs.writeFileSync(OUT + 'planche.html', html);
  const r = await b.newPage({ viewport: { width: 1500, height: 900 }, deviceScaleFactor: 1.4 });
  await r.goto('http://127.0.0.1:8099/docs/maquettes/canaux-offres/planche.html', { waitUntil: 'load' }); await r.waitForTimeout(800);
  await r.screenshot({ path: OUT + 'planche.jpg', type: 'jpeg', quality: 84, fullPage: true });
  console.log('✓ planche');
  await b.close();
})().catch(e => { console.error(e); process.exit(1); });
