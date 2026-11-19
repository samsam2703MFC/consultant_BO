/* Maquettes « Suivi de production : short life, medium life, long life » (demande du 06/10/2026).
 * La page Suivi seule, sur les chiffres réels de Halle le mardi 6 octobre à 10:45
 * (reel-suivi-halle-0610.json, lu en lecture seule), avec un paramètre produit nouveau : la durée
 * de vie. Le classement ci-dessous est une PROPOSITION de départ, par catégorie, avec deux
 * exceptions produit ; il se règle dans chaque maquette.
 *   node docs/maquettes/suivi-duree-de-vie/generer.js   → a.html, a-reglage.html, b.html, b-long.html, c.html, c-long.html */
const fs = require('fs');
const path = require('path');
const D = __dirname;
const U = JSON.parse(fs.readFileSync(path.join(D, 'reel-suivi-halle-0610.json'), 'utf8'));

const nf = (n, d = 0) => Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d, maximumFractionDigits: d });
const fI = n => n == null ? '—' : nf(Math.round(n) + 0);
const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;');
const hDe = s => { const [a, b] = String(s).split(':').map(Number); return a + (b || 0) / 60; };

/* La durée de vie proposée : par catégorie, et deux exceptions produit. Les catégories que le
 * magasin a déjà réglées « se garde au lendemain » (cookies, cakes) partent en long life. */
const CLASSE_CAT = {
  'Viennoiserie Ind.': 'S', 'Viennoiserie réduction': 'S', 'Petite Boulangerie': 'S', 'Sandwiches garnis': 'S', 'Wrapps': 'S',
  'Pâtisserie individuelle': 'S', 'Entremets - Individuel': 'S',
  'Pain': 'M', 'Pain Tradition': 'M', 'Tartes': 'M', 'Tartissières - 12Ø': 'M', 'Tartissières - 19Ø': 'M', 'Quiches': 'M',
  'Entremets - 190Ø': 'M', 'Entremets - 4/6 personnes': 'M', 'Salades féculentes': 'M', 'Plat Prepare - Chaud': 'M',
  'Cookies': 'L', 'Cake 100g.': 'L',
};
const EXCEPTION = { 'Brownies': 'L', 'Brookie': 'L' };
const NOM = { S: 'Short life', M: 'Medium life', L: 'Long life' };
const SOUS = { S: 'vendu le jour même', M: 'se garde 2 à 3 jours', L: 'se garde une semaine et plus' };
const REGLE = {
  S: 'suivi heure par heure · ce qui reste le soir part à la poubelle · recuire dès qu’il manque',
  M: 'suivi heure par heure · ce qui reste le soir se garde pour demain (report) · recuire s’il manque avant midi',
  L: 'pas de suivi à l’heure · un stock à tenir · recuire quand il passe sous le stock minimum',
};
const classe = p => EXCEPTION[p.nom] || CLASSE_CAT[p.cat] || 'S';
const P = U.produits.map(p => ({ ...p, cl: classe(p), exc: !!EXCEPTION[p.nom] }));
const par = k => P.filter(p => p.cl === k);
const H = U.heures, now = hDe(U.maintenant);
const VERD = { rupture: ['ko', 'Rupture'], manque: ['att', 'Manque prévu'], trop: ['bleu', 'Trop produit'], ok: ['ok', 'Tient'] };
/* Le verdict selon la durée de vie : un « trop » de medium se garde, un long life se lit en stock. */
const verdict = p => {
  if (p.cl === 'M' && p.verdict === 'trop') { return ['bleu', 'Se garde demain']; }
  if (p.cl === 'L') { return p.stock <= (p.stockMin || 0) ? ['ko', 'Sous le stock'] : (p.verdict === 'trop' ? ['bleu', 'En stock'] : ['ok', 'En stock']); }
  if (p.cl === 'S' && p.verdict === 'trop') { return ['bleu', 'Jeté ce soir']; }
  return VERD[p.verdict] || ['', ''];
};
const conseil = p => p.conseil ? `recuire <b>${fN(p.conseil.pieces)}</b>` : (p.verdict === 'trop' ? `<span class="mu">${fI(p.finJour)} ${p.cl === 'S' ? 'à jeter' : (p.cl === 'M' ? 'pour demain' : 'ce soir')}</span>` : '');
const fN = n => nf(n || 0);
const stats = L => ({ n: L.length, rupt: L.filter(p => p.verdict === 'rupture').length, manq: L.filter(p => p.verdict === 'manque').length, trop: L.filter(p => p.verdict === 'trop').length,
  sorti: L.reduce((a, p) => a + (p.sorti || 0), 0), vendu: L.reduce((a, p) => a + (p.vendu || 0), 0), stock: L.reduce((a, p) => a + (p.stock || 0), 0), fin: L.reduce((a, p) => a + Math.max(0, p.finJour || 0), 0) });
const ST = { S: stats(par('S')), M: stats(par('M')), L: stats(par('L')) };
/* Un long life est « sous le stock » quand ce qui reste ne dépasse pas son stock minimum (0 tant qu'il n'est pas réglé). */
const sousStock = par('L').filter(p => p.stock <= (p.stockMin || 0)).length;

/* Le tableau de la page Suivi, tel qu'il est en ligne (production.js, pageSuivi). */
const caseH = (p, x) => {
  const c = (p.cases || []).find(y => y.h === x);
  if (!c) { return '<td class="c h"></td>'; }
  const cls = c.reel ? (c.q < -0.5 ? 'r neg' : 'r') : (c.q < -0.5 ? 'ko' : (c.q < Math.max(1, (c.prev || 0) * 0.25) ? 'att' : 'ok'));
  return `<td class="c h ${cls}${x <= now && now < x + 1 ? ' now' : ''}">${fI(c.q)}</td>`;
};
const ligne = (p, opt) => {
  opt = opt || {};
  const v = verdict(p);
  const badge = opt.badge === false ? '' : `<span class="sl ${p.cl}${p.exc ? ' exc' : ''}" title="${NOM[p.cl]}${p.exc ? ' · exception produit' : ''}">${p.cl}</span>`;
  const seg = opt.seg ? `<span class="mini-seg">${['S', 'M', 'L'].map(k => `<span class="${k === p.cl ? 'on ' + k : ''}">${k}</span>`).join('')}</span>` : '';
  return `<tr><td class="nom">${badge}${p.oblig ? '<span class="pf-ob">★</span> ' : ''}${esc(p.nom)}<small>${esc(p.cat)}${p.exc ? ' · exception : long life' : ''}</small>${seg}</td><td class="n mu">${p.report ? fI(p.report) : '—'}</td><td class="n">${fI(p.sorti)}</td><td class="n">${fI(p.vendu)}</td><td class="n q"><b>${fI(p.stock)}</b></td>
    ${H.map(x => caseH(p, x)).join('')}
    <td><span class="pf-tag ${v[0]}">${v[1]}</span>${p.manque && p.cl !== 'L' ? `<small>à ${p.manque.h} h · −${fI(p.manque.q)}</small>` : ''}</td><td>${conseil(p)}</td></tr>`;
};
const tete = () => `<thead><tr><th>Produit</th><th class="n">Report</th><th class="n">Sorti</th><th class="n">Vendu</th><th class="n">En vitrine</th>${H.map(x => `<th class="c h${x <= now && now < x + 1 ? ' now' : ''}">${x} h</th>`).join('')}<th>Verdict</th><th>Conseil</th></tr></thead>`;
const ordre = L => L.slice().sort((a, b) => ({ rupture: 0, manque: 1, trop: 2, ok: 3 }[a.verdict] - { rupture: 0, manque: 1, trop: 2, ok: 3 }[b.verdict]) || a.nom.localeCompare(b.nom));

/* L'en-tête : la page Suivi seule, sans les quatre autres étapes. */
const entete = `<div class="pf-hd"><img src="/public/assets/img/logo.png" alt=""><div><div class="pf-titre">Production <small>SUIVI</small></div><div class="pf-sous">Atelier by - Halle · mardi 6 octobre 2026 · aujourd’hui</div></div><span class="sp"></span>
  <label class="pf-lab">Magasin <select><option>Atelier by - Halle</option></select></label></div>`;
const jours = `<div class="pf-jours"><span class="pf-k">Jour</span><div class="ch">${[['Mercredi', '30/09'], ['Jeudi', '01/10'], ['Vendredi', '02/10'], ['Samedi', '03/10'], ['Dimanche', '04/10'], ['Hier', 'lun. 05/10'], ['Aujourd’hui', 'mar. 06/10']].map(([l, d], i) => `<button class="${i === 6 ? 'on auj' : ''}"><b>${l}</b><small>${d}</small></button>`).join('')}</div>
  <span class="autre"><button class="pf-btn">‹</button><input type="date" value="2026-10-06"><button class="pf-btn" disabled>›</button></span></div>`;
const cuissons = `<div class="pf-cuis">${U.cuissons.map(c => `<div class="${c.valide ? 'ok' : 'att'}"><div class="k">${esc(c.nom)} · four ${esc(c.four)} · vente ${esc(c.de)}–${esc(c.a)}</div><div class="v">${fN(c.pieces)} <small>pièces prévues</small></div><div class="s">pas encore validée</div><button class="pf-btn prim">Valider la cuisson</button></div>`).join('')}</div>`;
const intro = `<div class="pf-intro"><b>mardi 6 octobre — ${esc(U.maintenant)}.</b> Validez chaque cuisson à la sortie du four. Chaque produit porte sa durée de vie : <b>short life</b> se vend le jour même, <b>medium life</b> se garde deux à trois jours, <b>long life</b> se garde une semaine et plus. Le suivi à l’heure vaut pour les deux premières ; le long life se lit en stock.</div>`;
const page = (lettre, titre, corps, extra) => `<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>${titre}</title>
<link rel="stylesheet" href="/public/assets/ds/global.css"><link rel="stylesheet" href="/public/production/production.css"><link rel="stylesheet" href="sl.css"></head>
<body><div id="pf">${entete}${jours}${intro}${cuissons}${corps}<span class="mq">Maquette ${lettre}</span>${extra || ''}</div></body></html>`;
const chips = s => `<span class="pf-chips"><span class="pf-tag ko">${s.rupt} en rupture</span><span class="pf-tag att">${s.manq} manque${s.manq > 1 ? 's' : ''} prévu${s.manq > 1 ? 's' : ''}</span><span class="pf-tag bleu">${s.trop} trop produit${s.trop > 1 ? 's' : ''}</span></span>`;
const somme = (a, b) => ({ n: a.n + b.n, rupt: a.rupt + b.rupt, manq: a.manq + b.manq, trop: a.trop + b.trop, sorti: a.sorti + b.sorti, vendu: a.vendu + b.vendu, stock: a.stock + b.stock, fin: a.fin + b.fin });

/* ───────── A : trois interrupteurs, et le réglage dans un panneau ───────── */
function maquetteA(reglage) {
  const actifs = { S: true, M: true, L: false };
  const vis = ordre(P.filter(p => actifs[p.cl]));
  const s = somme(ST.S, ST.M);
  const sw = k => `<span class="sw ${k}${actifs[k] ? ' on' : ' off'}"><i></i><b>${NOM[k]}</b><small>${ST[k].n} · ${SOUS[k]}</small></span>`;
  const corps = `<div class="pf-card"><div class="pf-ct"><span class="pf-k">Surveillance heure par heure</span>${chips(s)}
    <span class="pf-mini">sorti ${fN(s.sorti)} · vendu ${fN(s.vendu)} · en vitrine ${fN(s.stock)} · fin de journée projetée ${fN(s.fin)}</span>
    <label class="pf-mini"><input type="checkbox"> moyenne par heure</label><label class="pf-mini"><input type="checkbox"> seulement les alertes</label></div>
    <div class="sws"><span class="lab">Durée de vie</span>${sw('S')}${sw('M')}${sw('L')}<span class="regle">${actifs.L ? '' : `long life masqué : ${ST.L.n} produits, ${sousStock} sous le stock · `}</span><span class="reglage${reglage ? ' on' : ''}">⚙ Durée de vie des produits</span></div>
    <div class="pf-defile"><table class="pf-tab suivi">${tete()}<tbody>${vis.map(p => ligne(p)).join('')}</tbody></table></div>
    <div class="pf-leg"><span><span class="sl S">S</span>short life</span><span><span class="sl M">M</span>medium life</span><span><span class="sl L">L</span>long life</span><span><span class="sl L exc">L</span>exception au réglage de sa catégorie</span><span><i class="r"></i>stock réel en fin d’heure</span><span><i class="ko"></i>projeté, manque</span><span><i class="now"></i>l’heure en cours</span></div></div>`;
  let extra = '';
  if (reglage) {
    const cats = {};
    P.forEach(p => { (cats[p.groupe] = cats[p.groupe] || {}); (cats[p.groupe][p.cat] = cats[p.groupe][p.cat] || []).push(p); });
    const seg = k => `<span class="seg">${['S', 'M', 'L'].map(x => `<span class="${x === k ? 'on ' + x : ''}">${x === 'S' ? 'Short' : (x === 'M' ? 'Medium' : 'Long')}</span>`).join('')}</span>`;
    extra = `<div class="voile"></div><div class="panneau"><div class="t"><b>Durée de vie des produits</b><small>par catégorie, avec ses exceptions</small><span class="x">✕</span></div>
      ${Object.entries(cats).slice(0, 9).map(([g, cs]) => `<div class="rg gr"><span>${esc(g)}</span><span></span></div>${Object.entries(cs).map(([c, L]) => `<div class="rg"><span class="n">${esc(c)}<small>${L.length} produit${L.length > 1 ? 's' : ''}${L.some(p => p.exc) ? ' · ' + L.filter(p => p.exc).map(p => esc(p.nom) + ' en long life').join(', ') : ''}</small></span>${seg(CLASSE_CAT[c] || 'S')}</div>`).join('')}`).join('')}
      <div class="pied">Un clic sur la pastille d’un produit dans le tableau le change seul : il devient une exception à sa catégorie. Au départ : les catégories déjà réglées « se garde au lendemain » passent en long life, les autres en short ou medium life selon le rayon.</div></div>`;
  }
  return page('A', 'Suivi A : trois interrupteurs', corps, extra);
}

/* ───────── B : trois blocs dans le tableau, chacun avec son interrupteur ───────── */
function maquetteB(longOuvert) {
  const ouvert = { S: true, M: true, L: !!longOuvert };
  const s = somme(somme(ST.S, ST.M), ST.L);
  const bloc = k => {
    const t = ST[k];
    const head = `<tr class="bloc ${k}"><td colspan="${7 + H.length}"><div class="bl"><span class="sw ${k}${ouvert[k] ? ' on' : ' off'}"><i></i><b>${NOM[k]}</b></span><span class="nm">${SOUS[k]}</span><span class="rg">${REGLE[k]}</span>
      <span class="ch">${k === 'L' ? `<span class="pf-tag ${sousStock ? 'ko' : 'ok'}">${sousStock} sous le stock</span>` : `<span class="pf-tag ko">${t.rupt} rupture${t.rupt > 1 ? 's' : ''}</span><span class="pf-tag att">${t.manq} manque${t.manq > 1 ? 's' : ''}</span><span class="pf-tag bleu">${t.trop} ${k === 'M' ? 'pour demain' : 'trop'}</span>`}<span class="tot">${t.n} produits · sorti ${fN(t.sorti)} · vendu ${fN(t.vendu)} · vitrine ${fN(t.stock)}</span></span></div></td></tr>`;
    return head + (ouvert[k] ? ordre(par(k)).map(p => ligne(p, { badge: false })).join('') : '');
  };
  const corps = `<div class="pf-card"><div class="pf-ct"><span class="pf-k">Surveillance heure par heure</span>${chips(s)}
    <span class="pf-mini">sorti ${fN(s.sorti)} · vendu ${fN(s.vendu)} · en vitrine ${fN(s.stock)} · un bloc par durée de vie, chacun s’ouvre et se ferme</span>
    <label class="pf-mini"><input type="checkbox"> moyenne par heure</label><label class="pf-mini"><input type="checkbox"> seulement les alertes</label></div>
    <div class="pf-defile"><table class="pf-tab suivi">${tete()}<tbody>${bloc('S')}${bloc('M')}${bloc('L')}</tbody></table></div>
    <div class="pf-leg"><span>La durée de vie se règle par catégorie dans les réglages, avec ses exceptions produit</span><span><i class="r"></i>stock réel en fin d’heure</span><span><i class="ko"></i>projeté, manque</span><span><i class="now"></i>l’heure en cours</span></div></div>`;
  return page('B', 'Suivi B : trois blocs', corps);
}

/* ───────── C : trois onglets, et le réglage sur la ligne ───────── */
function maquetteC(onglet) {
  const k = onglet || 'S';
  const L = ordre(par(k));
  const tab = x => `<span class="t3 ${x === k ? 'on ' + x : ''}"><span class="sl ${x}">${x}</span>${NOM[x]} <b>${ST[x].n}</b><small>${SOUS[x]}</small><span class="al">${x === 'L' ? `<span class="pf-tag ${sousStock ? 'ko' : 'ok'}">${sousStock} sous le stock</span>` : (ST[x].rupt ? `<span class="pf-tag ko">${ST[x].rupt} rupture${ST[x].rupt > 1 ? 's' : ''}</span>` : '') + (ST[x].manq ? `<span class="pf-tag att">${ST[x].manq} manque${ST[x].manq > 1 ? 's' : ''}</span>` : '')}</span></span>`;
  let table;
  if (k === 'L') {
    // Long life : pas de suivi à l'heure, un stock à tenir.
    table = `<table class="pf-tab"><thead><tr><th>Produit</th><th class="n">Report d’hier</th><th class="n">Sorti</th><th class="n">Vendu</th><th class="n">En stock</th><th class="n">Vendu par jour</th><th>Stock face à 3 jours de vente</th><th class="n">Jours de stock</th><th>État</th><th>Conseil</th></tr></thead><tbody>
      ${L.map(p => { const parJ = p.moyJ || p.prevJ || 0; const j = parJ > 0 ? Math.max(0, p.stock) / parJ : null; const cible = parJ * 3; const pc = cible > 0 ? Math.min(100, 100 * Math.max(0, p.stock) / cible) : 0; const v = verdict(p);
        return `<tr><td class="nom">${p.oblig ? '<span class="pf-ob">★</span> ' : ''}${esc(p.nom)}<small>${esc(p.cat)}${p.exc ? ' · exception : long life' : ''}</small><span class="mini-seg">${['S', 'M', 'L'].map(x => `<span class="${x === p.cl ? 'on ' + x : ''}">${x}</span>`).join('')}</span></td><td class="n mu">${p.report ? fI(p.report) : '—'}</td><td class="n">${fI(p.sorti)}</td><td class="n">${fI(p.vendu)}</td><td class="n q"><b>${fI(p.stock)}</b></td><td class="n">${nf(parJ, 1)}</td>
          <td><span class="jauge"><i style="width:${pc.toFixed(0)}%;background:${pc < 34 ? '#C0182B' : (pc < 67 ? '#D97706' : '#2d7a3e')}"></i></span></td><td class="n">${j == null ? '—' : nf(j, 1)}</td><td><span class="pf-tag ${v[0]}">${v[1]}</span></td><td>${p.stock <= 0 && p.conseil ? `recuire <b>${fN(Math.max(p.conseil.pieces, Math.ceil(cible)))}</b>` : (p.stock <= 0 ? 'recuire' : '')}</td></tr>`; }).join('')}</tbody></table>`;
  } else {
    table = `<table class="pf-tab suivi">${tete()}<tbody>${L.map(p => ligne(p, { badge: false, seg: true })).join('')}</tbody></table>`;
  }
  const corps = `<div class="pf-card"><div class="pf-ct"><span class="pf-k">Surveillance heure par heure</span>
    <span class="pf-mini">sorti ${fN(ST[k].sorti)} · vendu ${fN(ST[k].vendu)} · en vitrine ${fN(ST[k].stock)}</span>
    <label class="pf-mini"><input type="checkbox"> moyenne par heure</label><label class="pf-mini"><input type="checkbox"> seulement les alertes</label></div>
    <div class="tabs3">${tab('S')}${tab('M')}${tab('L')}</div>
    <div class="regle3"><b>${NOM[k]}</b> : ${REGLE[k]}. Le petit S · M · L sous chaque produit change sa durée de vie d’un clic.</div>
    <div class="pf-defile">${table}</div>
    <div class="pf-leg"><span><span class="mini-seg"><span class="on S">S</span><span>M</span><span>L</span></span> la durée de vie du produit, réglable sur la ligne</span><span><i class="r"></i>stock réel en fin d’heure</span><span><i class="ko"></i>projeté, manque</span><span><i class="now"></i>l’heure en cours</span></div></div>`;
  return page('C', 'Suivi C : trois onglets', corps);
}

for (const [f, html] of [['a.html', maquetteA(false)], ['a-reglage.html', maquetteA(true)], ['b.html', maquetteB(false)], ['b-long.html', maquetteB(true)], ['c.html', maquetteC('S')], ['c-long.html', maquetteC('L')]]) {
  fs.writeFileSync(path.join(D, f), html);
  console.log(f, html.length, 'octets');
}
console.log('classes', JSON.stringify({ S: ST.S.n, M: ST.M.n, L: ST.L.n }));

/* La planche : les trois côte à côte, la vue par défaut puis l'état ouvert, avec ce qu'elles apportent et coûtent. */
const PL = [
  ['A', 'Trois interrupteurs', 'Au-dessus du tableau, un interrupteur par durée de vie. Short et medium allumés, long éteint au départ. Une pastille S, M ou L devant chaque produit. Le réglage par catégorie s’ouvre dans un panneau.', 'a.png', 'a-reglage.png', 'le panneau des réglages',
    ['le tableau d’aujourd’hui, à peine changé', 'on combine librement : short seul, short et medium, tout', 'le réglage reste à côté, sans quitter la page'],
    ['les trois classes se mélangent dans un seul tableau, triées par urgence']],
  ['B', 'Trois blocs', 'Le tableau coupé en trois blocs, short, medium, long, chacun avec son interrupteur, sa règle et ses compteurs. Un bloc fermé garde sa ligne de résumé.', 'b.png', 'b-long.png', 'le bloc long life ouvert',
    ['on lit chaque famille à part, avec ses propres ruptures', 'le long life fermé reste visible par son résumé'],
    ['plus long à parcourir quand tout est ouvert', 'le réglage reste dans les paramètres']],
  ['C', 'Trois onglets', 'Un onglet par durée de vie, avec ses compteurs. Le long life a son propre tableau : un stock à tenir, en jours de vente, sans les heures. Le S · M · L sous chaque produit change sa durée de vie d’un clic.', 'c.png', 'c-long.png', 'l’onglet long life',
    ['chaque classe a la lecture qui lui convient', 'le réglage se fait sur la ligne, sans autre écran'],
    ['une seule classe à la fois', 'le petit sélecteur sur chaque ligne charge le tableau']],
];
const planche = `<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>Suivi de production : short, medium, long life</title><link rel="stylesheet" href="/public/assets/ds/global.css"><style>
body{margin:0;background:#EAE4DC;font-family:var(--font-ui);color:#222}.w{width:2280px;margin:0 auto;padding:24px}h1{font:400 30px var(--font-display);margin:0 0 4px}.sous{font-size:13px;color:#555;margin-bottom:18px;line-height:1.5}
.g{display:grid;grid-template-columns:repeat(3,1fr);gap:24px}.col{background:#fff;border-radius:14px;padding:16px}.col h2{font:400 22px var(--font-display);margin:0}.col h2 span{color:#8D1D2C;margin-right:8px}
.col p{font-size:12.5px;color:#444;margin:6px 0 10px;line-height:1.45}.cadre{height:900px;overflow:hidden;border:.5px solid rgba(0,0,0,.12);border-radius:8px;margin-bottom:6px}.cadre img{width:100%;display:block}.cadre.p{height:520px}
h4{font:600 10px var(--font-ui);letter-spacing:.08em;text-transform:uppercase;color:#666;margin:10px 0 4px}ul{margin:0;padding-left:18px;font-size:12.5px;line-height:1.5}ul.p li::marker{color:#2D7A3E}ul.m li::marker{color:#C0182B}.cap{font-size:11px;color:#777;margin:0 0 10px}
table{border-collapse:collapse;font-size:12px;margin-top:8px}td,th{padding:4px 10px;border-bottom:.5px solid rgba(0,0,0,.1);text-align:left}th{font:600 10px var(--font-ui);letter-spacing:.06em;text-transform:uppercase;color:#666}</style></head><body><div class="w">
<h1>Suivi de production : short life, medium life, long life</h1>
<div class="sous">Atelier by - Halle · le suivi réel du mardi 6 octobre à ${esc(U.maintenant)} · ${P.length} produits suivis · la page Suivi seule, sans les quatre autres étapes · la durée de vie est un paramètre produit nouveau, réglé par catégorie avec des exceptions par produit</div>
<table><tr><th></th><th>Short life</th><th>Medium life</th><th>Long life</th></tr>
<tr><td>Ce que c’est</td><td>${SOUS.S}</td><td>${SOUS.M}</td><td>${SOUS.L}</td></tr>
<tr><td>Produits suivis</td><td>${ST.S.n}</td><td>${ST.M.n}</td><td>${ST.L.n}</td></tr>
<tr><td>Ce qui reste le soir</td><td>part à la poubelle</td><td>se garde pour demain</td><td>reste en stock</td></tr>
<tr><td>Suivi à l’heure</td><td>oui</td><td>oui</td><td>non, un stock à tenir</td></tr></table>
<div class="g" style="margin-top:18px">${PL.map(([l, t, d, i1, i2, c2, plus, moins]) => `<div class="col"><h2><span>${l}</span>${t}</h2><p>${d}</p>
<div class="cadre"><img src="${i1}" alt=""></div><div class="cap">par défaut</div><div class="cadre p"><img src="${i2}" alt=""></div><div class="cap">${c2}</div>
<h4>Ce qu’elle apporte</h4><ul class="p">${plus.map(x => `<li>${x}</li>`).join('')}</ul><h4>Ce qu’elle coûte</h4><ul class="m">${moins.map(x => `<li>${x}</li>`).join('')}</ul></div>`).join('')}</div></div></body></html>`;
fs.writeFileSync(path.join(D, 'planche.html'), planche);
console.log('planche.html', planche.length, 'octets');
