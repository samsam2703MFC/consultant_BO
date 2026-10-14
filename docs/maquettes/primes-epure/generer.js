/* Maquettes « Mes primes » épurées (demande du 14/10/2026) : chaque carte est une prime — un target, une
 * catégorie de produits ou une promotion — avec sa récompense réglée par l'admin (50 € par défaut) ; le
 * classement à la Strava dans un menu déroulant (Queen of Tartes, Queen of Quiches, la promo, les ventes
 * croisées) : le rang, les quantités, qui est 1re. Plus de texte d'explication. Prénoms et chiffres inventés.
 *   node docs/maquettes/primes-epure/generer.js
 *   → a-liste.html, a-menu.html, b-une-prime.html, b-menu.html, c-deroule.html, cockpit.html, planche.html */
const fs = require('fs'), path = require('path');
const D = __dirname;

// ---------- les nombres
const NB = ' ';
const e = s => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;');
const ent = v => Math.round(v).toLocaleString('fr-BE');
const n2 = v => v.toLocaleString('fr-BE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const eur = v => ent(v) + NB + '€';
const rangLib = r => r === 1 ? '1re' : r + 'e';

// ---------- la récompense par défaut, réglée dans le cockpit
const RECOMPENSE = 50;
const MAGASIN = 'Halle';

// ---------- les classements : [prénom, quantité] au magasin, [prénom, magasin, quantité] au réseau ; « Toi » = la personne connectée
const CL = {
  tartes: { unite: 'pièces', court: 'pcs',
    mag: [['Léa', 51], ['Toi', 42], ['Manon', 37], ['Sarah', 29], ['Julie', 18], ['Camille', 12]],
    res: [['Inès', 'Namur', 68], ['Nora', 'Louvain-la-Neuve', 63], ['Emma', 'Waterloo', 57], ['Léa', 'Halle', 51], ['Chloé', 'Uccle', 49],
      ['Zoé', 'Sombreffe', 46], ['Toi', 'Halle', 42], ['Lucie', 'Wavre', 40], ['Manon', 'Halle', 37], ['Yasmine', 'Ixelles', 35]], surRes: 38 },
  quiches: { unite: 'pièces', court: 'pcs',
    mag: [['Toi', 27], ['Sarah', 24], ['Léa', 19], ['Manon', 15], ['Julie', 9], ['Camille', 6]],
    res: [['Amélie', 'Wavre', 41], ['Inès', 'Namur', 36], ['Laura', 'Gembloux', 33], ['Toi', 'Halle', 27], ['Nora', 'Louvain-la-Neuve', 26],
      ['Sarah', 'Halle', 24], ['Emma', 'Waterloo', 22], ['Chloé', 'Uccle', 21], ['Léa', 'Halle', 19], ['Zoé', 'Sombreffe', 18]], surRes: 38 },
  promo: { unite: 'vendues', court: 'pcs',
    mag: [['Manon', 34], ['Toi', 31], ['Léa', 22], ['Sarah', 20], ['Julie', 11], ['Camille', 7]],
    res: [['Yasmine', 'Ixelles', 52], ['Emma', 'Waterloo', 47], ['Manon', 'Halle', 34], ['Toi', 'Halle', 31], ['Lucie', 'Wavre', 30],
      ['Inès', 'Namur', 28], ['Laura', 'Gembloux', 26], ['Léa', 'Halle', 22], ['Zoé', 'Sombreffe', 21], ['Sarah', 'Halle', 20]], surRes: 38 },
  croisees: { unite: '%', court: '%',
    mag: [['Sarah', 61], ['Toi', 56], ['Léa', 54], ['Manon', 52], ['Julie', 49], ['Camille', 44]],
    res: [['Chloé', 'Uccle', 66], ['Nora', 'Louvain-la-Neuve', 64], ['Sarah', 'Halle', 61], ['Inès', 'Namur', 60], ['Amélie', 'Wavre', 58],
      ['Toi', 'Halle', 56], ['Emma', 'Waterloo', 55], ['Léa', 'Halle', 54], ['Laura', 'Gembloux', 54], ['Zoé', 'Sombreffe', 53]], surRes: 38 },
};
const liste = (cle, niv) => CL[cle][niv].map((x, i) => niv === 'mag'
  ? { rang: i + 1, nom: x[0], q: x[1], moi: x[0] === 'Toi' }
  : { rang: i + 1, nom: x[0], mag: x[1], q: x[2], moi: x[0] === 'Toi' });
const moiDans = (cle, niv) => liste(cle, niv).find(x => x.moi);

// ---------- les primes : trois targets, deux catégories, une promo
const P = [
  { cle: 'croisees', emo: '🛍️', lib: 'Ventes croisées', type: 'target', val: 56, cible: 55, fmt: v => v + '<small>%</small>', bas: 40, haut: 65, cl: 'croisees' },
  { cle: 'record', emo: '🚀', lib: 'Bats ton record', type: 'target', val: 2.74, cible: 2.8, fmt: n2, unite: 'lignes / ticket', bas: 2.2, haut: 3.0 },
  { cle: 'objectif', emo: '🏪', lib: 'Objectif magasin', type: 'target', val: 98, cible: 100, fmt: v => v + '<small>%</small>', unite: 'projeté', bas: 80, haut: 110 },
  { cle: 'tartes', emo: '👑', lib: 'Queen of Tartes', type: 'categorie', cl: 'tartes' },
  { cle: 'quiches', emo: '👑', lib: 'Queen of Quiches', type: 'categorie', cl: 'quiches' },
  { cle: 'promo', emo: '🏷️', lib: 'Promo café + croissant', type: 'promo', cl: 'promo' },
];
const TYPES = { target: 'Target', categorie: 'Catégorie', promo: 'Promo' };
for (const p of P) {
  p.m = RECOMPENSE;
  if (p.type === 'target') { p.etat = p.val >= p.cible ? 'gagne' : 'att'; }
  else { const m = moiDans(p.cl, 'mag'); p.rang = m.rang; p.q = m.q; p.etat = m.rang === 1 ? 'tete' : 'rang'; }
}
const acquis = P.filter(p => p.etat === 'gagne' || p.etat === 'tete').reduce((a, p) => a + p.m, 0);
const enJeu = P.reduce((a, p) => a + p.m, 0);
const CLASSEMENTS = ['tartes', 'quiches', 'promo', 'croisees'].map(k => P.find(p => p.cle === k));

// ---------- les pièces d'écran
const COULEURS = ['#b07a5a', '#5f7f6a', '#7a6aa0', '#a0606e', '#5a7a9a', '#9a8a4a', '#6a8a8a', '#8a5a7a'];
const couleur = nom => nom === 'Toi' ? 'var(--la-rubis)' : COULEURS[[...nom].reduce((a, c) => a + c.charCodeAt(0), 0) % COULEURS.length];
const av = nom => `<span class="av" style="background:${couleur(nom)}">${nom === 'Toi' ? '<i class="bi bi-person-fill"></i>' : e(nom[0])}</span>`;
const typ = t => `<span class="typ ${t}">${TYPES[t]}</span>`;

const nav = () => `<nav class="emp-bottom-nav">${[['dashboard', 'Accueil', 'bi-house'], ['schedule', 'Planning', 'bi-calendar3'], ['tasks', 'Tâches', 'bi-check2-square'], ['primes', 'Primes', 'bi-trophy'], ['profile', 'Profil', 'bi-person']]
  .map(([k, l, i]) => `<a class="emp-bottom-item${k === 'primes' ? ' is-active' : ''}"><i class="bi ${i}"></i><span>${l}</span></a>`).join('')}</nav>`;
const page = (titre, corps) => `<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>${e(titre)}</title>
<link rel="stylesheet" href="/public/employee/assets/atelier/app.css"><link rel="stylesheet" href="/public/employee/assets/bootstrap-icons/bootstrap-icons.css"><link rel="stylesheet" href="/public/employee/assets/atelier/marque.css"><link rel="stylesheet" href="/public/employee/assets/atelier/primes.css"><link rel="stylesheet" href="ep.css"></head>
<body><div class="tel">
<div class="emp-topbar"><div class="emp-topbar-inner"><div><h1 class="emp-title">Mes primes</h1><p class="emp-subtitle">${MAGASIN} · octobre 2026, au 14</p></div><img src="/public/employee/assets/atelier/logo.png" alt=""></div></div>
<div class="emp-content">${corps}</div>${nav()}</div></body></html>`;

// le haut : acquis et en jeu, rien d'autre
const haut = mini => `<div class="hx${mini ? ' mini' : ''}"><div class="l"><div><b>${eur(acquis)}</b><small>acquis</small></div><div class="r"><b>${eur(enJeu)}</b><small>en jeu</small></div></div>${mini ? '' : `<div class="bar"><i style="width:${(100 * acquis / enJeu).toFixed(1)}%"></i></div>`}</div>`;

// l'état d'une prime, en une pastille
const etat = p => p.etat === 'gagne' ? '<span class="etat ok">✓ gagné</span>'
  : p.etat === 'tete' ? '<span class="etat or">👑 1re</span>'
  : p.etat === 'rang' ? `<span class="etat mu">${rangLib(p.rang)} / ${CL[p.cl].mag.length}</span>`
  : `<span class="etat att">−${p.cle === 'record' ? n2(p.cible - p.val) : ent(p.cible - p.val) + NB + 'pt'}</span>`;

// une prime = une carte : l'emoji, le nom, le type, la récompense ; la mesure, la jauge, l'état ; une ligne de chiffres
const carte = (p, opt = {}) => {
  const cls = p.etat === 'gagne' ? 'gagne' : p.etat === 'tete' ? 'tete' : '';
  const chev = opt.chev ? `<i class="bi bi-chevron-${opt.ouvert ? 'up' : 'down'} chev"></i>` : '';
  const h = `<div class="pp-h"><span class="emo">${p.emo}</span><div class="t"><b>${e(p.lib)}</b>${typ(p.type)}</div><span class="m">${p.etat === 'gagne' ? '+' : ''}${eur(p.m)}</span>${chev}</div>`;
  let b, l;
  if (p.type === 'target') {
    const pos = v => Math.max(0, Math.min(100, 100 * (v - p.bas) / (p.haut - p.bas))).toFixed(1);
    b = `<div class="pp-b"><span class="v">${p.fmt(p.val)}</span><div class="jg"><i style="width:${pos(p.val)}%"></i><span class="c" style="left:${pos(p.cible)}%"></span></div>${etat(p)}</div>`;
    l = `cible <b>${p.fmt(p.cible)}</b>${p.unite ? ' · ' + p.unite : ''}`;
  } else {
    const L = liste(p.cl, 'mag'), un = L[0], deux = L[1];
    b = `<div class="pp-b"><span class="v">${ent(p.q)}<small>${CL[p.cl].court}</small></span><div class="jg"><i style="width:${(100 * p.q / un.q).toFixed(1)}%"></i></div>${etat(p)}</div>`;
    l = p.rang === 1 ? `2e · <b>${e(deux.nom)}</b> ${ent(deux.q)}` : `👑 <b>${e(un.nom)}</b> ${ent(un.q)}`;
  }
  return `<div class="pp ${cls}${opt.ouvert ? ' ouvert' : ''}">${h}${b}<div class="pp-p">${l}</div>${opt.dedans || ''}</div>`;
};

// le classement : le podium, puis les rangs suivants
const val = (cle, x) => `${ent(x.q)}<small>${CL[cle].unite === '%' ? NB + '%' : NB + CL[cle].court}</small>`;
const podium = (cle, niv) => {
  const L = liste(cle, niv);
  const pod = (x, k) => `<div class="pod p${k}${x.moi ? ' moi' : ''}">${k === 1 ? '<span class="cr">👑</span>' : ''}${av(x.nom)}<b>${x.moi ? 'Toi' : e(x.nom)}</b>${niv === 'res' ? `<small>${e(x.mag)}</small>` : ''}<div class="q">${val(cle, x)}</div><div class="socle">${k}</div></div>`;
  return `<div class="podium">${pod(L[1], 2)}${pod(L[0], 1)}${pod(L[2], 3)}</div>`;
};
const ligne = (cle, x, niv) => `<div class="cl-row ${x.moi ? 'moi' : ''} ${x.rang === 1 ? 'un' : ''}"><span class="r">${x.rang === 1 ? '👑' : x.rang}</span>${av(x.nom)}<span class="nom">${x.moi ? 'Toi' : e(x.nom)}${niv === 'res' ? `<span class="mag">· ${e(x.mag)}</span>` : ''}</span><span class="val">${val(cle, x)}</span></div>`;
const rangs = (cle, niv, du, au) => liste(cle, niv).slice(du - 1, au).map(x => ligne(cle, x, niv)).join('');
const togs = niv => `<div class="cl-tog"><span class="${niv === 'mag' ? 'on' : ''}">Mon magasin</span><span class="${niv === 'res' ? 'on' : ''}">Réseau</span></div>`;

// le menu déroulant : fermé, ou ouvert sur la liste des classements
const dd = (p, gros) => `<div class="dd${gros ? ' gros' : ''}"><span class="emo">${p.emo}</span><div class="t"><b>${e(p.lib)}</b>${typ(p.type)}</div><i class="bi bi-chevron-down"></i></div>`;
const etatCourt = p => p.type === 'target' && !p.cl ? etat(p) : (() => { const m = moiDans(p.cl, 'mag'); return m.rang === 1 ? '<span class="etat or">👑 1re</span>' : `<span class="etat mu">${rangLib(m.rang)}</span>`; })();
const menu = (choix, sel, parRang) => `<div class="dd-menu">${choix.map(p => `<div class="o${p.cle === sel ? ' on' : ''}"><i class="bi bi-check2 ck" style="visibility:${p.cle === sel ? 'visible' : 'hidden'}"></i><span class="emo">${p.emo}</span><div class="t"><b>${e(p.lib)}</b>${typ(p.type)}</div>${parRang ? etatCourt(p) : etat(p)}</div>`).join('')}</div>`;

// ---------- A : la liste des primes, le classement en bas dans un menu déroulant
const tartes = P.find(p => p.cle === 'tartes');
const classementA = ouvert => `<div class="titre-section" id="classement"><h2>Classement</h2><small>octobre</small></div>
<div class="dd-zone">${dd(tartes)}${ouvert ? menu(CLASSEMENTS, 'tartes', true) : ''}</div>
<div class="cl-carte">${togs('mag')}${podium('tartes', 'mag')}${rangs('tartes', 'mag', 4, 6)}</div>`;
const corpsA = ouvert => `${haut()}
<div class="titre-section"><h2>Mes primes</h2><small>${P.length} primes</small></div>
${P.map(p => carte(p)).join('\n')}
${classementA(ouvert)}`;
const A = page('Mes primes — A, la liste et le classement', corpsA(false));
const Am = page('Mes primes — A, le menu ouvert', corpsA(true));

// ---------- B : une prime à la fois ; le menu déroulant en haut choisit la prime, la page montre son classement
const corpsB = ouvert => {
  const m = moiDans('tartes', 'mag'), un = liste('tartes', 'mag')[0];
  return `${haut(true)}
<div class="dd-zone">${dd(tartes, true)}${ouvert ? menu(P, 'tartes') : ''}</div>
<div class="focus"><div class="gain"><b>${eur(tartes.m)}</b><small>1re du magasin</small></div><span class="sep"></span><div class="moi"><b>${m.rang}<sup>e</sup></b><small>${ent(m.q)} pcs · −${ent(un.q - m.q)} de la 1re</small></div></div>
<div class="cl-carte">${togs('res')}${podium('tartes', 'res')}${rangs('tartes', 'res', 4, 10)}</div>
<div class="titre-section"><h2>Mes autres primes</h2></div>
<div class="cl-carte autres">${P.filter(p => p.cle !== 'tartes').map(p => `<div class="lg ${p.etat === 'gagne' ? 'gagne' : ''}"><span class="emo">${p.emo}</span><span class="t">${e(p.lib)}</span>${etat(p)}<span class="m">${eur(p.m)}</span></div>`).join('')}</div>`;
};
const B = page('Mes primes — B, une prime à la fois', corpsB(false));
const Bm = page('Mes primes — B, le menu ouvert', corpsB(true));

// ---------- C : chaque carte déroule son propre classement
const dedans = `<div class="pp-cl">${togs('mag')}${rangs('tartes', 'mag', 1, 6)}</div>`;
const C = page('Mes primes — C, chaque prime déroule son classement', `${haut()}
<div class="titre-section"><h2>Mes primes</h2><small>${P.length} primes</small></div>
${P.map(p => p.cle === 'tartes' ? carte(p, { chev: true, ouvert: true, dedans }) : carte(p, { chev: !!p.cl })).join('\n')}`);

// ---------- le cockpit : une ligne par prime, sa récompense
const champ = (v, l) => `<span class="champ${l ? ' l' : ''}">${e(v)}</span>`;
const coche = on => `<span class="ch"><i class="car${on ? ' on' : ''}"></i></span>`;
const LIGNES = [
  ['🛍️', 'Ventes croisées', 'target', `tickets à 2 articles ou plus ≥ ${champ('55 %')}`, 'chaque vendeuse', true],
  ['🚀', 'Bats ton record', 'target', 'lignes par ticket au-dessus du record 12 mois', 'chaque vendeuse', false],
  ['🏪', 'Objectif magasin', 'target', `CA du magasin ≥ ${champ('100 %')} de l’objectif`, 'toute l’équipe', false],
  ['👑', 'Queen of Tartes', 'categorie', `produits ${champ('tarte', true)} · ${champ('10')} pcs min.`, '1re du magasin', true],
  ['👑', 'Queen of Quiches', 'categorie', `produits ${champ('quiche', true)} · ${champ('10')} pcs min.`, '1re du magasin', true],
  ['🏷️', 'Promo café + croissant', 'promo', `${champ('Café + croissant 3,50 €', true)} · ${champ('01/10')} → ${champ('31/10')}`, '1re du magasin', true],
];
const cockpit = `<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Cockpit — Primes de l’app</title>
<link rel="stylesheet" href="/public/assets/ds/global.css"><link rel="stylesheet" href="../prix-volume/pv.css"><link rel="stylesheet" href="/public/employee/assets/atelier/marque.css">
<style>
#ecran{max-width:1160px}
.champ{display:inline-block;min-width:54px;padding:4px 8px;border:.5px solid var(--color-border-secondary);border-radius:7px;background:#fff;font-weight:600;text-align:right;font-variant-numeric:tabular-nums}
.champ.l{text-align:left;font-weight:500;min-width:90px}
.champ.eur{min-width:48px}
.ch{display:inline-flex}.ch i{width:15px;height:15px;border-radius:4px;border:1.5px solid var(--color-border-secondary);display:inline-block;background:#fff;position:relative}
.ch i.on{border-color:var(--color-primary);background:var(--color-primary)}.ch i.on::after{content:'';position:absolute;left:4px;top:1px;width:4px;height:8px;border:solid #fff;border-width:0 2px 2px 0;transform:rotate(45deg)}
.interr{width:34px;height:19px;border-radius:999px;background:var(--color-primary);display:inline-block;position:relative}.interr::after{content:'';position:absolute;right:2px;top:2px;width:15px;height:15px;border-radius:50%;background:#fff}
.t td{padding:9px 12px}.t td.g{font-size:13px}
.t td.c,.t th.c{text-align:center}
.emo{font-size:16px;margin-right:6px}
.typ{display:inline-block;font:700 9px var(--font-ui);letter-spacing:.08em;text-transform:uppercase;padding:2px 7px;border-radius:999px}
.typ.target{background:#e6edf5;color:#2a5a8a}.typ.categorie{background:#fbe9d6;color:#8a4a12}.typ.promo{background:#f6dfe2;color:#8D1D2C}
.bt{display:inline-flex;align-items:center;gap:6px;padding:7px 13px;border-radius:9px;background:var(--color-primary);color:#fff;font-weight:600;font-size:12.5px}
.bt.sec{background:#fff;color:var(--color-text);border:.5px solid var(--color-border-secondary)}
.defaut{display:flex;align-items:center;gap:10px;font-size:13px}
.defaut .champ{font-size:15px;min-width:60px}
.pied{display:flex;justify-content:space-between;align-items:center;padding:12px 16px}
</style></head>
<body><div id="ecran"><div class="cap">Équipe & ventes › Paramètres</div>
<div class="barre"><div><h1>Primes de l’app worker</h1></div><div class="defaut">Récompense par défaut ${champ('50')} €</div></div>
<div class="carte sans"><table class="t"><thead><tr><th class="c">Active</th><th class="g">Prime</th><th class="g">Type</th><th class="g">Ce qui compte</th><th class="g">Qui gagne</th><th class="c">Classement</th><th>Récompense</th></tr></thead><tbody>
${LIGNES.map(([emo, lib, t, mesure, qui, cl]) => `<tr><td class="c"><span class="interr"></span></td><td class="g"><span class="emo">${emo}</span><b>${e(lib)}</b></td><td class="g">${typ(t)}</td><td class="g">${mesure}</td><td class="g mu">${qui}</td><td class="c">${coche(cl)}</td><td class="num"><span class="champ eur">50</span> €</td></tr>`).join('\n')}
</tbody></table>
<div class="pied"><div style="display:flex;gap:8px"><span class="bt sec">+ Target</span><span class="bt sec">+ Catégorie</span><span class="bt sec">+ Promotion</span></div><span class="bt">Enregistrer</span></div></div>
</div></body></html>`;

// ---------- la planche
const planche = `<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>Planche — Mes primes épurées</title><link rel="stylesheet" href="/public/assets/ds/global.css"><link rel="stylesheet" href="/public/employee/assets/atelier/marque.css">
<style>body{margin:0;background:#EAE4DC;font-family:var(--font-ui);color:#222;padding:28px 32px}h1{font:400 30px var(--font-display);margin:0 0 4px}.s{color:#666;font-size:13px;margin-bottom:22px}
.tels{display:flex;gap:26px;align-items:flex-start;margin-bottom:30px}.tel{flex:0 0 auto}.tel h2{font:400 18px var(--font-display);margin:0 0 4px}.tel .d{font-size:12px;color:#666;margin-bottom:8px;max-width:390px;min-height:17px}
.tel img{width:390px;border-radius:18px;box-shadow:0 18px 50px rgba(0,0,0,.22);display:block}.ck img{width:1160px;border-radius:12px;box-shadow:0 18px 50px rgba(0,0,0,.18);display:block}.ck h2{font:400 18px var(--font-display);margin:0 0 8px}</style></head>
<body><h1>Mes primes, épurées</h1><div class="s">Une carte = une prime (target, catégorie ou promo) et sa récompense, 50 € par défaut, réglée dans le cockpit. Le classement dans un menu déroulant.</div>
<div class="tels">
<div class="tel"><h2>A · La liste, puis le classement</h2><div class="d">Les primes, puis le podium sous un menu déroulant.</div><img src="a-liste-long.png"></div>
<div class="tel"><h2>A · Le menu ouvert</h2><div class="d">Queen of Tartes, Queen of Quiches, promo, ventes croisées.</div><img src="a-menu.png"></div>
<div class="tel"><h2>B · Une prime à la fois</h2><div class="d">Le menu en haut choisit la prime ; la page, c’est son classement.</div><img src="b-une-prime-long.png"></div>
</div>
<div class="tels">
<div class="tel"><h2>B · Le menu ouvert</h2><div class="d">Les six primes, ta place dans chacune.</div><img src="b-menu.png"></div>
<div class="tel"><h2>C · Chaque prime déroule son classement</h2><div class="d">Pas de menu : on touche la carte.</div><img src="c-deroule-long.png"></div>
</div>
<div class="ck"><h2>Cockpit · Primes de l’app worker</h2><img src="cockpit.png"></div></body></html>`;

const out = { 'a-liste.html': A, 'a-menu.html': Am, 'b-une-prime.html': B, 'b-menu.html': Bm, 'c-deroule.html': C, 'cockpit.html': cockpit, 'planche.html': planche };
for (const [f, h] of Object.entries(out)) { fs.writeFileSync(path.join(D, f), h); }
console.log('écrits :', Object.keys(out).join(', '), '· acquis', acquis, '€ · en jeu', enJeu, '€');
