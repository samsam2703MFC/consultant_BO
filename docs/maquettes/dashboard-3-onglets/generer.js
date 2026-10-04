/* Le dashboard magasin au téléphone, en trois onglets (demande du 04/10/2026) :
 *   Exploitation — chiffre d'affaires, marges, clients, ce que la journée rapporte ;
 *   Contrôle     — les photos en carrousel, les contrôles, le stock, les commandes ;
 *   Semaine      — la semaine en une vue, pour tout vérifier d'un coup.
 * Le but : que l'opérateur sache en deux ou trois coups d'œil dans quel état est son magasin.
 *
 * Trois directions, chacune montrée sur ses trois onglets :
 *   A — les feux    : des tuiles, une pastille de couleur chacune ; la couleur se lit avant le chiffre ;
 *   B — le mur      : la suite du mur en ligne, sans cadre, des chiffres et des filets ; la photo en grand ;
 *   C — le verdict  : chaque onglet répond d'abord à sa question en une phrase, le détail suit.
 *
 * Chiffres RÉELS, Atelier by - Halle (shop 4), lus sur le serveur le 04/10/2026 :
 *   samedi 3 octobre 2026      /exploitation/jour, /exploitation/invendus, /exploitation/canaux
 *   contrôles et photos        /pwa/tasks?date=, /pwa/tasks/photos?shop=4 (photos/ : celles du jour)
 *   stock, commandes           /ventes/stock, /ventes/commandes
 *   semaine du 28/09, au samedi soir : /exploitation/periode?vue=semaine, /pwa/tasks jour par jour,
 *                              /exploitation/invendus jour par jour, /ventes/semaines (six semaines)
 *
 * node docs/maquettes/dashboard-3-onglets/generer.js   → a.html, b.html, c.html, planche.html
 * node docs/maquettes/dashboard-3-onglets/capturer.js  → les images (serveur statique sur 8099)
 */
const fs = require('fs'), path = require('path');
const OUT = __dirname;
const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const nf = (n, d) => Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d || 0, maximumFractionDigits: d || 0 });
const eur = n => nf(Math.round(n)) + ' €';
const sg = n => (n >= 0 ? '+ ' : '− ') + eur(Math.abs(n));
const pc = (n, d) => nf(n, d == null ? 1 : d) + ' %';

const D = {
  shop: 'Atelier by - Halle', jour: 'samedi 3 octobre', semaine: 'semaine du 28/09 au 04/10',
  ca: 2683.2, obj: 2325.64, tickets: 181, j7: 184, j7d: 'sam. 26/09', panier: 14.82, avance: 7,
  net: 730.17, netPct: 27.2, mat: 39.6, mb: 60.4, mo: 13.7, moH: 33.5, fg: 18.1, seuilMat: 35,
  inv: { cout: 36.2, pieces: 76, vente: 104.5, pct: 1.3, top: 'Sandwich', topP: 30 },
  comptoir: { ca: 2425.2, n: 178, panier: 13.62 }, pro: { ca: 258, n: 3 }, web: { ca: 97.2, n: 1, prep: 2 },
  rang: { ca: 2, cli: 2, pan: 2, n: 4 },
  heures: [[8, 43.1], [9, 297.7], [10, 279.2], [11, 515.8], [12, 506.1], [13, 199.4], [14, 185.3], [15, 277.5], [16, 176.8], [17, 80.8], [18, 82.2], [19, 39.3]],
  cats: [['Viennoiserie', 965.3, 19.6], ['Tartes', 684.9, -8.8], ['Boulangerie', 578.6, 1.6], ['Pâtisserie', 179.1, -28.8], ['Quiches', 172.2, 18]],
  // Les écarts d'abord, puis les photos notées, les non rendues en fin de piste (la règle du carrousel en ligne).
  photos: [{ id: 1206, nom: 'Pains & Boulangerie', h: '09:28', note: null }, { id: 1207, nom: 'Viennoiseries', h: '09:28', note: 4 },
    { id: 1209, nom: 'Tartes', h: '09:29', note: 4 }, { id: 1211, nom: 'Quiches', h: '09:29', note: 4 }, { id: 1213, nom: 'Boissons', h: '09:29', note: 4 },
    { id: 1214, nom: 'Épicerie', h: '09:29', note: 4 }, { id: 1216, nom: 'Propreté du magasin', h: '09:28', note: 4 }, { id: 1267, nom: 'Prix et allergènes', h: '09:30', note: 4 }],
  manquent: ['Biscuiterie', 'Pâtisseries', 'Traiteur'],
  par: 'Nathan C.', notePar: 'Sam V.', noteA: '16:09',
  ctrl: { rendus: 8, total: 11, notes: 7, nc: 0 },
  stock: { refs: 632, zero: 0, sous: 0, compte: '01/10 à 20:19' },
  cmd: { auj: 4, retard: 12, venir: 2, montant: 737.5 },
  sem: [
    { j: 'L', d: 28, ca: 1307.3, obj: 1782.46, cli: 130, net: 207.18, t: [9, 11], notees: 0, inv: 22.24, invP: 44 },
    { j: 'Ma', d: 29, ca: 1139.15, obj: 1888.32, cli: 114, net: 123.56, t: [10, 12], notees: 1, inv: 6.43, invP: 21 },
    { j: 'Me', d: 30, ca: 1453.4, obj: 1889.75, cli: 111, net: 285.22, t: [11, 13], notees: 0, inv: 0, invP: 0 },
    { j: 'J', d: 1, ca: 1691.85, obj: 1833.57, cli: 119, net: 440.79, t: [9, 11], notees: 0, inv: 13.62, invP: 26 },
    { j: 'V', d: 2, ca: 1880.6, obj: 1861.06, cli: 154, net: 518.67, t: [9, 11], notees: 7, inv: 89.85, invP: 41, invV: 316.8 },
    { j: 'S', d: 3, ca: 2683.2, obj: 2325.64, cli: 181, net: 730.17, t: [8, 11], notees: 7, inv: 36.2, invP: 76, auj: true },
    { j: 'D', d: 4, obj: 2382, fut: true }],
  semMat: 42.7, semRang: { ca: 3, cli: 3, pan: 4, n: 4 }, semPanier: 12.61, medPanier: 13.87,
  six: [['S35', 11320.2], ['S36', 11530.15], ['S37', 12077.9], ['S38', 12591.7], ['S39', 11908.45], ['S40', 10155.5]],
};
const att = 100 * D.ca / D.obj, ecart = D.ca - D.obj;
const S = D.sem.filter(x => !x.fut);
const T = { ca: S.reduce((a, x) => a + x.ca, 0), obj: S.reduce((a, x) => a + x.obj, 0), cli: S.reduce((a, x) => a + x.cli, 0), net: S.reduce((a, x) => a + x.net, 0),
  r: S.reduce((a, x) => a + x.t[0], 0), n: S.reduce((a, x) => a + x.t[1], 0), notees: S.reduce((a, x) => a + x.notees, 0), inv: 168.34, invP: 208, invV: 518.49 };
T.att = 100 * T.ca / T.obj; T.ecart = T.ca - T.obj;
const matExces = (D.mat - D.seuilMat) / 100 * D.ca;

// Les seuils de couleur, les mêmes dans les trois directions.
const cCA = p => p >= 100 ? 'v' : (p >= 90 ? 'o' : 'r');
const cNet = (n, ca) => { const p = 100 * n / ca; return p >= 15 ? 'v' : (p >= 5 ? 'o' : 'r'); };
const cCtrl = (r, n) => r === n ? 'v' : (r / n >= 0.8 ? 'o' : 'r');
const cInv = (i, ca) => { if (!i) { return 'n'; } const p = 100 * i / ca; return p <= 1.5 ? 'v' : (p <= 3 ? 'o' : 'r'); };

const ICO = {
  exp: '<svg viewBox="0 0 24 24"><path d="M5 20V11M11 20V5M17 20v-8M2 20h20"/></svg>',
  ctrl: '<svg viewBox="0 0 24 24"><path d="M3 8h4l2-3h6l2 3h4v11H3z"/><circle cx="12" cy="13" r="3.6"/></svg>',
  sem: '<svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg>',
};
/** La barre d'onglets ; `marque` : la pastille ou le compte de chaque onglet, selon la direction. */
function onglets(on, marque) {
  return `<div class="tb">${[['exp', 'Exploitation'], ['ctrl', 'Contrôle'], ['sem', 'Semaine']].map(([k, l]) =>
    `<div class="${on === k ? 'on' : ''}">${ICO[k]}${(marque && marque[k]) || ''}<span>${l}</span></div>`).join('')}</div>`;
}
function tete(sous) {
  return `<div class="enc"><span>9:41</span><span>●●● ▮</span></div>
  <div class="mh"><img src="/public/assets/img/logo.png" alt=""><div><div class="t">${esc(D.shop)}</div>
    <div class="d"><span>‹</span><b>${esc(sous)}</b><span>›</span></div></div><span class="sp"></span><div class="ic">↻</div></div>`;
}
const tel = (sous, corps, on, marque) => `<div class="ph">${tete(sous)}<div class="sc">${corps}</div>${onglets(on, marque)}</div>`;
const photo = id => `photos/${id}.jpg`;

/* ======================================================================= A
 * Les feux : six à huit tuiles par onglet, une pastille chacune. Le premier
 * coup d'œil lit les couleurs, le second les chiffres des tuiles qui ne sont
 * pas vertes. La barre d'onglets porte déjà la couleur de chaque onglet. */
const pA = { exp: '<i class="pt o"></i>', ctrl: '<i class="pt r"></i>', sem: '<i class="pt o"></i>' };
const tA = (k, pt, v, s, cls, w) => `<div class="a-t ${cls || ''}${w ? ' w' : ''}"><div class="k"><i class="pt bg-${pt}"></i>${k}</div><div class="v">${v}</div><div class="s">${s}</div><span class="ch">›</span></div>`;
const verdictA = (n, coul, t, em, dots) => `<div class="a-v"><span class="n ${coul}${String(n).length > 3 ? ' long' : ''}">${n}</span><span class="t">${t}<em>${em}</em></span><span class="dots">${dots.map(c => `<i class="bg-${c}"></i>`).join('')}</span></div>`;

const A1 = tel(D.jour, `
  ${verdictA('1', 'wa', 'point à surveiller', '8 au vert · la matière dépasse 35 %', ['v', 'v', 'v', 'o', 'v', 'v', 'v', 'v', 'v'])}
  <div class="a-g">
    <div class="a-t w"><div class="k"><i class="pt bg-v"></i>Chiffre d’affaires · objectif atteint</div>
      <div class="v">${eur(D.ca)} <small class="ok" style="font:600 13px var(--font-ui)">${sg(ecart)}</small></div>
      <div class="s">objectif ${eur(D.obj)} · ${pc(att)} · ${D.avance} clients comptoir d’avance</div>
      <div class="gauge"><i class="v" style="width:100%"></i></div><span class="ch">›</span></div>
    ${tA('Résultat', 'v', sg(D.net), pc(D.netPct) + ' des ventes')}
    ${tA('Clients', 'v', nf(D.tickets) + ' <small class="mu" style="font:600 12px var(--font-ui)">− 3</small>', D.j7 + ' à J−7 · panier ' + nf(D.panier, 2) + ' €')}
    ${tA('Matière', 'o', pc(D.mat), 'seuil 35 % · marge brute ' + pc(D.mb), 'o')}
    ${tA('Main-d’œuvre', 'v', pc(D.mo), nf(D.moH, 1) + ' h au planning')}
    ${tA('Invendus', 'v', eur(D.inv.cout), D.inv.pieces + ' pièces · ' + pc(D.inv.pct) + ' du CA')}
    ${tA('Réseau', 'v', '2<sup style="font-size:12px">e</sup> / 4', 'chiffre, clients et panier')}
    ${tA('Canaux', 'v', eur(D.comptoir.ca), 'comptoir · pro ' + eur(D.pro.ca) + ' · web ' + eur(D.web.ca), '', true)}
    <div class="a-t w"><div class="k"><i class="pt bg-v"></i>Heure par heure · pic à 11 h, 516 €</div>
      <div class="b-h" style="height:44px;padding:6px 0 0">${D.heures.map(([h, v]) => `<div><i class="${v === Math.max(...D.heures.map(x => x[1])) ? 'top' : ''}" style="height:${Math.max(2, Math.round(v / 516 * 28))}px"></i>${h}</div>`).join('')}</div><span class="ch">›</span></div>
  </div>`, 'exp', pA);

const A2 = tel(D.jour, `
  ${verdictA('3', 'ko', 'points à régler', '3 contrôles non rendus · 1 photo à contrôler · 12 commandes en retard', ['r', 'o', 'r', 'v', 'v', 'v'])}
  <div class="a-car"><div class="h"><b>Les contrôles en photo</b><span>${D.ctrl.rendus} rendues à 09:28 · ${D.ctrl.notes} notées ${D.ctrl.notes ? '4 / 5' : ''}</span></div>
    <div class="a-strip">${D.photos.map(p => `<div class="a-ph"><img src="${photo(p.id)}" alt=""><span class="bdg ${p.note == null ? 'bg-o' : 'bg-v'}">${p.note == null ? 'à contrôler' : p.note + ' / 5'}</span><div class="c"><b>${esc(p.nom)}</b><span>${p.h} · ${esc(D.par)}</span></div></div>`).join('')}
      ${D.manquent.map(m => `<div class="a-ph x"><div class="vide"><i>✕</i>pas rendue</div><div class="c"><b>${esc(m)}</b><span>attendue</span></div></div>`).join('')}</div></div>
  <div class="a-g">
    ${tA('Contrôles', 'r', D.ctrl.rendus + ' / ' + D.ctrl.total, 'manquent ' + D.manquent.join(', '), 'r')}
    ${tA('À contrôler', 'o', '1', 'Pains & Boulangerie, pas encore notée', 'o')}
    ${tA('Commandes', 'r', D.cmd.auj + ' auj.', D.cmd.retard + ' en retard · ' + D.cmd.venir + ' à venir', 'r')}
    ${tA('Non-conformités', 'v', '0', D.ctrl.notes + ' photos notées, toutes conformes')}
    ${tA('Stock', 'v', '✓', D.stock.refs + ' références · 0 à zéro · compté le 01/10')}
    ${tA('Poubelle', 'v', D.inv.pieces + ' p.', 'déclarée · dont ' + D.inv.topP + ' ' + D.inv.top.toLowerCase() + 's')}
  </div>
  <div class="btn-rc">✎ Réclamer un produit au fournisseur</div>`, 'ctrl', pA);

const damier = () => {
  const L = (lab, f) => `<tr><td class="l">${lab}</td>${D.sem.map(x => x.fut ? '<td class="n">·</td>' : f(x)).join('')}</tr>`;
  return `<div class="a-dam"><table><tr><th class="l">la semaine</th>${D.sem.map(x => `<th class="${x.auj ? 'auj' : ''}">${x.j} ${x.d}</th>`).join('')}</tr>
    ${L('Chiffre / obj.', x => `<td class="${cCA(100 * x.ca / x.obj)}">${nf(100 * x.ca / x.obj)}</td>`)}
    ${L('Résultat', x => `<td class="${cNet(x.net, x.ca)}">${nf(x.net)}</td>`)}
    ${L('Contrôles', x => `<td class="${cCtrl(x.t[0], x.t[1])}">${x.t[0]}/${x.t[1]}</td>`)}
    ${L('Notés', x => x.notees ? `<td class="v">${x.notees}</td>` : '<td class="n">—</td>')}
    ${L('Poubelle', x => `<td class="${cInv(x.inv, x.ca)}">${x.inv ? nf(x.inv) + '€' : '0'}</td>`)}
    </table><div class="a-leg"><span><i class="bg-v"></i>bon</span><span><i class="bg-o"></i>à surveiller</span><span><i class="bg-r"></i>à reprendre</span><span><i style="background:rgba(34,34,34,.12)"></i>rien</span></div></div>`;
};
const A3 = tel(D.semaine, `
  ${verdictA(sg(T.ecart), 'wa', 'sur l’attendu au samedi', 'en retard lundi à mercredi, au-dessus depuis vendredi', ['r', 'r', 'r', 'o', 'v', 'v'])}
  <div class="a-t w"><div class="k"><i class="pt bg-o"></i>Chiffre de la semaine · au samedi</div>
    <div class="v">${eur(T.ca)} <small class="mu" style="font:600 12px var(--font-ui)">/ ${eur(T.obj)}</small></div>
    <div class="s">${pc(T.att)} de l’attendu · ${nf(T.cli)} clients · dimanche : objectif ${eur(2382)}</div>
    <div class="a-bars">${D.sem.map(x => { const p = x.fut ? 0 : x.ca / x.obj; return `<div><i class="bg-${x.fut ? 'n' : cCA(100 * p)}" style="height:${x.fut ? 3 : Math.round(Math.min(1.2, p) * 30)}px;${x.fut ? 'background:rgba(34,34,34,.12)' : ''}"></i>${x.j}</div>`; }).join('')}</div></div>
  ${damier()}
  <div class="a-g">
    ${tA('Résultat', 'v', sg(T.net), 'somme des six jours · ' + pc(100 * T.net / T.ca) + ' des ventes')}
    ${tA('Matière', 'o', pc(D.semMat), 'seuil 35 % · la semaine', 'o')}
    ${tA('Poubelle', 'o', eur(T.inv), T.invP + ' pièces · vendredi ' + eur(89.85), 'o')}
    ${tA('Réseau', 'o', '3<sup style="font-size:12px">e</sup> / 4', 'panier ' + nf(D.semPanier, 2) + ' €, 4e · médiane ' + nf(D.medPanier, 2) + ' €', 'o')}
  </div>`, 'sem', pA);

/* ======================================================================= B
 * Le mur : la suite de la version en ligne, retenue en septembre — pas de
 * cadre, des chiffres et des filets. La photo prend toute la largeur ; les
 * points sous elle disent d'un coup combien sont rendues, à voir, absentes. */
const rB = (cells, t3) => `<div class="b-r${t3 ? ' t3' : ''}">${cells.map(([k, v, s, c]) => `<div><div class="k">${k}</div><div class="v ${c || ''}">${v}</div><div class="s">${s}</div></div>`).join('')}</div>`;
const maxH = Math.max(...D.heures.map(h => h[1]));
const B1 = tel(D.jour, `
  <div class="b-big"><div class="k">Chiffre d’affaires du jour · <span class="ok">objectif atteint</span></div>
    <div class="v">${eur(D.ca)}<small class="ok">${sg(ecart)}</small></div>
    <div class="s">objectif ${eur(D.obj)} · ${pc(att)} · ${D.avance} clients comptoir d’avance</div>
    <div class="gauge"><i class="v" style="width:100%"></i></div></div>
  <div class="b-h">${D.heures.map(([h, v]) => `<div><i class="${v === maxH ? 'top' : ''}" style="height:${Math.max(2, Math.round(v / maxH * 42))}px"></i>${h}</div>`).join('')}</div>
  <div class="b-w">
    ${rB([['Clients', nf(D.tickets) + ' <small class="mu" style="font:600 11px var(--font-ui)">− 3</small>', D.j7 + ' à J−7 · panier ' + nf(D.panier, 2) + ' €'], ['Résultat', sg(D.net), pc(D.netPct) + ' des ventes', 'ok']])}
    ${rB([['Matière', pc(D.mat), 'marge brute ' + pc(D.mb), 'wa'], ['Main-d’œuvre', pc(D.mo), nf(D.moH, 1) + ' h'], ['Invendus', eur(D.inv.cout), D.inv.pieces + ' pièces']], true)}
    ${rB([['Comptoir', eur(D.comptoir.ca), D.comptoir.n + ' clients · ' + nf(D.comptoir.panier, 2) + ' €'], ['Pro', eur(D.pro.ca), D.pro.n + ' clients'], ['Webshop', eur(D.web.ca), D.web.prep + ' à préparer']], true)}
    ${rB([['Réseau · chiffre', '2<sup style="font-size:11px">e</sup> / 4', 'sur 4 magasins'], ['Clients', '2<sup style="font-size:11px">e</sup> / 4', ''], ['Panier', '2<sup style="font-size:11px">e</sup> / 4', '']], true)}
  </div>
  <div class="b-sec"><span>Les rayons face à la moyenne des samedis</span><span>›</span></div>
  <div class="b-cat">${D.cats.map(([n, v, d]) => `<span>${esc(n)}</span><span class="n">${eur(v)}</span><span class="n ${d >= 0 ? 'ok' : 'ko'}">${d >= 0 ? '+' : '−'} ${nf(Math.abs(d))} %</span>`).join('')}</div>`, 'exp');

const nB = D.photos.length + D.manquent.length;
const B2 = tel(D.jour, `
  <div class="b-hero"><img src="${photo(1207)}" alt=""><span class="cpt">2 / ${nB} · ${D.ctrl.rendus} rendues à 09:28</span><span class="nt">4 / 5 conforme</span>
    <span class="fl" style="left:10px">‹</span><span class="fl" style="right:10px">›</span>
    <div class="ov"><b>Viennoiseries</b><span>09:28 · ${esc(D.par)} · notée à ${D.noteA} par ${esc(D.notePar)}</span></div></div>
  <div class="b-dots">${D.photos.map((p, i) => `<i class="${i === 1 ? 'on' : ''}${p.note == null ? ' o' : ''}"></i>`).join('')}${D.manquent.map(() => '<i class="x"></i>').join('')}</div>
  <div class="b-w" style="margin-top:2px">
    <div class="b-l"><span class="i ko">✕</span><span class="c"><b>3 contrôles non rendus</b><span>${D.manquent.join(' · ')}</span></span><span class="v ko">${D.ctrl.rendus}/${D.ctrl.total}</span></div>
    <div class="b-l"><span class="i wa">!</span><span class="c"><b>1 photo à contrôler</b><span>Pains &amp; Boulangerie · rendue à 09:28</span></span><span class="v wa">1</span></div>
    <div class="b-l"><span class="i ko">✕</span><span class="c"><b>Commandes clients</b><span>${D.cmd.auj} à retirer aujourd’hui · ${D.cmd.retard} en retard · ${eur(D.cmd.montant)}</span></span><span class="v ko">${D.cmd.retard}</span></div>
    <div class="b-l"><span class="i ok">✓</span><span class="c"><b>Non-conformités</b><span>${D.ctrl.notes} photos notées, toutes conformes</span></span><span class="v ok">0</span></div>
    <div class="b-l"><span class="i ok">✓</span><span class="c"><b>Stock au complet</b><span>${D.stock.refs} références · 0 à zéro · compté le ${D.stock.compte}</span></span><span class="v ok">✓</span></div>
    <div class="b-l"><span class="i ok">✓</span><span class="c"><b>Poubelle déclarée</b><span>${D.inv.pieces} pièces · dont ${D.inv.topP} sandwichs</span></span><span class="v">${D.inv.pieces}</span></div>
  </div>
  <div class="btn-rc">✎ Réclamer un produit au fournisseur</div>`, 'ctrl');

const maxS = Math.max(...D.six.map(s => s[1]));
const B3 = tel(D.semaine, `
  <div class="b-big"><div class="k">Chiffre de la semaine · au samedi</div>
    <div class="v">${eur(T.ca)}<small class="wa">${sg(T.ecart)}</small></div>
    <div class="s">attendu ${eur(T.obj)} · ${pc(T.att)} · ${nf(T.cli)} clients · résultat ${sg(T.net)}</div>
    <div class="gauge"><i style="width:${Math.min(100, T.att).toFixed(1)}%;background:#D98A0B"></i></div></div>
  <table class="b-t"><tr><th>jour</th><th>chiffre</th><th>clients</th><th>résultat</th><th>contrôles</th><th>poubelle</th></tr>
    ${D.sem.map(x => x.fut ? `<tr class="fut"><td>${x.j} ${x.d}</td><td>obj. ${eur(x.obj)}</td><td colspan="4" style="text-align:left;padding-left:12px">demain</td></tr>`
      : `<tr class="${x.auj ? 'auj' : ''}"><td>${x.j} ${x.d}</td><td>${eur(x.ca)}<small class="${({ v: 'ok', o: 'wa', r: 'ko' })[cCA(100 * x.ca / x.obj)]}">${nf(100 * x.ca / x.obj)} %</small></td><td>${x.cli}</td>
        <td class="${({ v: 'ok', o: 'wa', r: 'ko' })[cNet(x.net, x.ca)]}">${sg(x.net)}</td><td class="${({ v: 'ok', o: 'wa', r: 'ko' })[cCtrl(x.t[0], x.t[1])]}">${x.t[0]}/${x.t[1]}<small>${x.notees ? x.notees + (x.notees > 1 ? ' notées' : ' notée') : 'non notées'}</small></td>
        <td class="${({ v: 'ok', o: 'wa', r: 'ko', n: 'mu' })[cInv(x.inv, x.ca)]}">${x.inv ? eur(x.inv) : '—'}<small>${x.invP} p.</small></td></tr>`).join('')}
    <tr class="tot"><td>Total</td><td>${eur(T.ca)}</td><td>${nf(T.cli)}</td><td>${sg(T.net)}</td><td>${T.r}/${T.n}</td><td>${eur(T.inv)}</td></tr></table>
  <div class="b-w">${rB([['Matière', pc(D.semMat), 'seuil 35 %', 'wa'], ['Panier', nf(D.semPanier, 2) + ' €', '4e / 4 · médiane ' + nf(D.medPanier, 2) + ' €', 'wa'], ['Réseau', '3<sup style="font-size:11px">e</sup> / 4', 'au chiffre']], true)}</div>
  <div class="b-sec"><span>Les six dernières semaines</span><span>au samedi</span></div>
  <div class="b-6">${D.six.map(([l, v], i) => `<div><em>${nf(v / 1000, 1)} k</em><i class="${i === 5 ? 'c' : ''}" style="height:${Math.round(v / maxS * 40)}px"></i>${l}</div>`).join('')}</div>`, 'sem');

/* ======================================================================= C
 * Le verdict : chaque onglet répond d'abord à SA question, en une phrase —
 * « Ça rapporte ? », « Tout est fait ? », « La semaine tient ? » — avec ce qui
 * la justifie juste dessous ; le reste est replié. La barre d'onglets compte
 * ce qui demande un geste. */
const pC = { ctrl: '<i class="bd">3</i>', sem: '<i class="bd" style="background:#D98A0B">1</i>' };
const ring = (pct, coul, lab, sous) => { const c = 2 * Math.PI * 38, f = Math.min(1, pct / 100);
  return `<div class="c-ring"><svg viewBox="0 0 88 88"><circle cx="44" cy="44" r="38" stroke="rgba(34,34,34,.08)" stroke-width="9" fill="none"/><circle cx="44" cy="44" r="38" stroke="${coul}" stroke-width="9" fill="none" stroke-linecap="round" stroke-dasharray="${(c * f).toFixed(1)} ${c.toFixed(1)}"/></svg><div class="lb">${lab}<small>${sous}</small></div></div>`; };
const liC = (coul, sym, t, s, v) => `<div class="c-li"><span class="ic" style="background:${coul}">${sym}</span><span class="c">${t}<span>${s}</span></span><span class="v">${v}</span></div>`;
const V = '#2d7a3e', O = '#D98A0B', R = '#C0182B';
const C1 = tel(D.jour, `
  <div class="c-q"><div class="k">La journée a-t-elle rapporté ?</div><div class="a">Oui : ${sg(D.net)} de résultat.</div>
    <div class="s">Objectif dépassé de ${eur(ecart)}, 2<sup>e</sup> du réseau. Une seule chose à regarder : la matière.</div></div>
  <div class="c-hero">${ring(att, V, nf(att) + ' %', 'de l’objectif')}
    <div class="l"><div><span>Chiffre d’affaires</span><b>${eur(D.ca)}</b></div><div><span>Objectif</span><b>${eur(D.obj)}</b></div>
      <div><span>Clients · panier</span><b>${D.tickets} · ${nf(D.panier, 2)} €</b></div><div><span>Résultat</span><b class="ok">${sg(D.net)}</b></div></div></div>
  <div class="c-box o"><div class="k"><span>À surveiller</span><span>›</span></div><div class="t">Matière ${pc(D.mat)}, au-dessus des 35 %</div>
    <div class="s">Environ ${eur(matExces)} de marge laissés aujourd’hui. Pâtisserie − 29 % et tartes − 9 % face aux samedis.</div></div>
  <div class="c-box v"><div class="k"><span>Ce qui va bien</span></div>
    ${liC(V, '✓', 'Main-d’œuvre ' + pc(D.mo), nf(D.moH, 1) + ' h au planning', '')}
    ${liC(V, '✓', 'Invendus ' + eur(D.inv.cout), D.inv.pieces + ' pièces, ' + pc(D.inv.pct) + ' du chiffre', '')}
    ${liC(V, '✓', 'Viennoiserie + 20 %', 'face à la moyenne des samedis', '')}</div>
  <div class="c-fold"><span><b>Comptoir, pro et webshop</b> · 2 425 € · 258 € · 97 €</span><span>▾</span></div>
  <div class="c-fold"><span><b>Les heures et les rayons</b> · pic à 11 h, 516 €</span><span>▾</span></div>`, 'exp', pC);

const C2 = tel(D.jour, `
  <div class="c-q" style="padding-bottom:0"><div class="k">Tout est-il fait et en ordre ?</div><div class="a">Pas encore : 3 choses à faire.</div></div>
  <div class="c-story"><img src="${photo(1206)}" alt="">
    <div class="seg">${D.photos.map((p, i) => `<i class="${p.note == null ? 'o' : 'v'}${i === 0 ? ' on' : ''}"></i>`).join('')}${D.manquent.map(() => '<i class="r"></i>').join('')}</div>
    <div class="top"><b>Pains &amp; Boulangerie</b><span>· 09:28 · ${esc(D.par)}</span></div>
    <div class="bot"><div class="c"><b>À contrôler</b><span>rendue à 09:28, pas encore notée · 1 / ${nB}</span></div><span class="btn">voir les ${nB} ›</span></div></div>
  <div class="c-box r"><div class="k"><span>À faire</span><span>3</span></div>
    ${liC(R, '3', 'Contrôles non rendus', D.manquent.join(' · '), D.ctrl.rendus + '/' + D.ctrl.total)}
    ${liC(R, '!', 'Commandes en retard', D.cmd.auj + ' à retirer aujourd’hui · ' + eur(D.cmd.montant) + ' en cours', String(D.cmd.retard))}
    ${liC(O, '1', 'Photo à contrôler', 'Pains & Boulangerie', '1')}</div>
  <div class="c-fold"><span><b>En ordre</b> · stock au complet · 0 non-conformité · poubelle déclarée</span><span>▾</span></div>
  <div class="btn-rc">✎ Réclamer un produit au fournisseur</div>`, 'ctrl', pC);

const C3 = tel(D.semaine, `
  <div class="c-q"><div class="k">La semaine tient-elle ?</div><div class="a">Pas encore : ${eur(Math.abs(T.ecart))} de retard.</div>
    <div class="s">Mais elle remonte : lundi à mercredi loin de l’objectif, vendredi et samedi au-dessus. Il reste dimanche, ${eur(2382)} d’objectif.</div></div>
  <div class="c-box">${D.sem.map(x => {
    if (x.fut) { return `<div class="c-day fut"><span class="j">${x.j}<small>${x.d}/10</small></span><span class="b"><span class="bar"><u></u></span><span>objectif <b>${eur(x.obj)}</b> · à venir</span></span><span class="p"><i class="n">ctr</i><i class="n">pb</i></span></div>`; }
    const p = 100 * x.ca / x.obj, c = cCA(p);
    return `<div class="c-day${x.auj ? ' auj' : ''}"><span class="j">${x.j}<small>${String(x.d).padStart(2, '0')}/${x.d > 20 ? '09' : '10'}</small></span>
      <span class="b"><span class="bar"><i style="width:${Math.min(100, p / 1.2).toFixed(1)}%;background:${({ v: V, o: O, r: R })[c]}"></i><u></u></span><span><b>${eur(x.ca)}</b> · ${nf(p)} % · ${sg(x.net)}</span></span>
      <span class="p"><i class="${cCtrl(x.t[0], x.t[1])}">${x.t[0]}/${x.t[1]}</i><i class="${cInv(x.inv, x.ca)}">${x.inv ? nf(x.inv) + '€' : '0'}</i></span></div>`; }).join('')}
    <div class="a-leg" style="margin-top:4px"><span>barre : chiffre, le trait = objectif</span><span style="margin-left:auto">contrôles · poubelle</span></div></div>
  <div class="c-hero" style="padding:10px 12px">${ring(T.att, O, nf(T.att) + ' %', 'de l’attendu')}
    <div class="l"><div><span>Clients</span><b>${nf(T.cli)}</b></div><div><span>Résultat</span><b class="ok">${sg(T.net)}</b></div>
      <div><span>Matière</span><b class="wa">${pc(D.semMat)}</b></div><div><span>Réseau</span><b>3<sup>e</sup> / 4</b></div></div></div>`, 'sem', pC);

/* ============================================================== les pages */
const page = (titre, corps) => `<!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>${esc(titre)}</title>
<link rel="stylesheet" href="/public/assets/ds/global.css">
<link rel="stylesheet" href="d3.css">
</head><body>${corps}</body></html>`;
const PIED = `<div class="pl-pied">Chiffres réels : ${esc(D.shop)}, samedi 3 octobre 2026, et la semaine du lundi 28 septembre au samedi soir. Photos : celles rendues ce jour-là.
  Seuils des couleurs : chiffre ≥ 100 % de l’objectif vert, ≥ 90 % orange ; résultat ≥ 15 % des ventes vert, ≥ 5 % orange ; contrôles tous rendus vert, ≥ 80 % orange ; poubelle ≤ 1,5 % du chiffre vert, ≤ 3 % orange ; matière au-dessus de 35 % orange.</div>`;
const DIR = {
  a: ['A — Les feux', 'Une tuile par mesure, une pastille de couleur par tuile, la couleur de chaque onglet dans la barre du bas. Le premier coup d’œil lit les couleurs, le second les seules tuiles qui ne sont pas vertes. Le carrousel est une bande de vignettes qu’on fait glisser.', [A1, A2, A3],
    ['Exploitation', 'Un point à surveiller sur neuf : la matière. Le chiffre, le résultat et le réseau sont au vert.'],
    ['Contrôle', 'La bande de photos, puis six tuiles : contrôles non rendus, à contrôler, commandes, non-conformités, stock, poubelle.'],
    ['Semaine', 'Le damier : chaque jour, chaque mesure, une couleur. On voit d’un coup que lundi à mercredi ont manqué l’objectif.']],
  b: ['B — Le mur', 'La suite de la version en ligne, retenue en septembre : pas de cadre, des chiffres et des filets, beaucoup d’information sans défiler. La photo prend toute la largeur ; les points dessous disent combien sont rendues, à voir, absentes.', [B1, B2, B3],
    ['Exploitation', 'Le chiffre et sa jauge, les ventes heure par heure, puis le mur : clients, résultat, matière, main-d’œuvre, canaux, réseau, rayons.'],
    ['Contrôle', 'Une photo en grand, ‹ › pour passer à la suivante, puis la liste : ce qui manque d’abord, ce qui est en ordre ensuite.'],
    ['Semaine', 'Le tableau des sept jours, chiffre par chiffre, avec le total ; puis les six dernières semaines.']],
  c: ['C — Le verdict', 'Chaque onglet répond d’abord à sa question, en une phrase : la journée a-t-elle rapporté ? tout est-il fait ? la semaine tient-elle ? Ce qui la justifie suit, le reste est replié. La barre d’onglets compte ce qui demande un geste.', [C1, C2, C3],
    ['Exploitation', 'La réponse, l’anneau de l’objectif, le seul point à surveiller chiffré en euros, puis ce qui va bien.'],
    ['Contrôle', 'Les photos en « stories » : la barre du haut montre d’un coup les notées, celle à contrôler, les trois absentes. Puis les trois choses à faire.'],
    ['Semaine', 'Une ligne par jour : la barre du chiffre face au trait de l’objectif, et deux pastilles, contrôles et poubelle.']],
};
const planche = k => { const [t, s, P, ...cols] = DIR[k];
  return `<div class="planche"><h1 class="pl-t">${esc(t)}<small>${esc(s)}</small></h1>
  <div class="pl-row">${P.map((p, i) => `<div class="pl-col"><div class="lg">${i + 1} · ${esc(cols[i][0])}<em>${esc(cols[i][1])}</em></div>${p}</div>`).join('')}</div>${PIED}</div>`; };
for (const k of ['a', 'b', 'c']) { fs.writeFileSync(path.join(OUT, k + '.html'), page('Dashboard en 3 onglets — ' + DIR[k][0], planche(k))); }
fs.writeFileSync(path.join(OUT, 'planche.html'), page('Dashboard en 3 onglets — les trois directions',
  `<div class="planche"><h1 class="pl-t">Le dashboard magasin en trois onglets<small>Exploitation · Contrôle · Semaine. Le but : que l’opérateur sache en deux ou trois coups d’œil dans quel état est son magasin. Trois directions, chacune sur ses trois onglets.</small></h1>
  ${['a', 'b', 'c'].map(k => `<div class="pl-row">${DIR[k][2].map((p, i) => `<div class="pl-col"><div class="lg">${esc(DIR[k][0])} · ${esc(DIR[k][3 + i][0])}</div>${p}</div>`).join('')}</div>`).join('')}${PIED}</div>`));
console.log('écrit : a.html, b.html, c.html, planche.html');
