// Maquette « assistant de mise en page » (17/11/2026, Sam : « la mise en page pourrait se faire sous forme d'assistant, la
// dernière page de l'assistant serait la mise en page finale, si il veut déplacer une catégorie en drag and drop » ;
// « c'est aussi catégorie par catégorie sélectionnée »).
// Les aperçus sont les vrais rendus du catalogue de Halle (collecter.cjs, rien n'est enregistré) ; le reste est la maquette,
// posée à la place de l'étape 3 dans le dashboard en ligne. Usage : DONNEES=…/am_donnees2.json node generer.cjs
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const ICI = __dirname;
const L = path.join(ICI, '../../../public/dashboard/');
// Les dessins du document pointaient vers 127.0.0.1 (corrigé le même jour : X-Forwarded-Host) : on montre l'adresse publique.
const D = JSON.parse(fs.readFileSync(process.env.DONNEES || path.join(ICI, 'am_donnees2.json'), 'utf8').split('http://127.0.0.1/marketing/').join('http://185.180.206.46/marketing/'));
const CSS = fs.readFileSync(path.join(ICI, 'maquette.css'), 'utf8');

const feuille = h => h;   // les aperçus sont réduits à leur seule feuille par le navigateur (plus bas)
const th = (h, leg, w) => '<div class="am-th" data-w="' + w + '">' + feuille(h) + (leg ? '<span>' + leg + '</span>' : '') + '</div>';
const ap = (h, leg, w) => '<div class="am-ap" data-w="' + w + '">' + feuille(h) + '<span>' + leg + '</span></div>';
const mini = (l) => l === 'vedette' ? '<span class="mini vedette tA">' + '<i></i>'.repeat(5) + '</span>' : l === 'grille' ? '<span class="mini grille tA">' + '<i></i>'.repeat(6) + '</span>'
  : l === 'liste' ? '<span class="mini liste tA">' + '<i></i>'.repeat(6) + '</span>' : l === 'mosaique' ? '<span class="mini mosaique tA">' + '<i></i>'.repeat(4) + '</span>' : '<span class="mini pleine tA"><i></i><i></i></span>';
const COUL = { pain: '#8D1D2C', cougnou: '#B0792A', sand: '#3f7a52' };
const pt = c => '<i class="pt" style="background:' + c + '"></i>';

function sous(on) {
  const e = [['Pain Tradition', '11 produits'], ['Cougnou', '5 produits'], ['Plateau Sandwich', '8 produits'], ['Mise en page finale', 'l’ordre, en glisser-déposer']];
  return '<div class="am-sous">' + e.map((x, i) => '<div class="am-ss' + (i === on ? ' on' : i < on ? ' fait' : '') + (i === 3 ? ' fin' : '') + '"><span class="no">' + (i < on ? '✓' : i === 3 ? '⠿' : i + 1) + '</span><div style="min-width:0"><div class="l">' + x[0] + '</div><div class="d">' + x[1] + '</div></div></div>').join('') + '</div>';
}
function lays(n, on, sur) {
  const L4 = [['vedette', '1 vedette + 4', 'la vedette en haut', 5], ['grille', '2 par largeur', 'grille 2 × 3', 6], ['liste', '1 par largeur', 'liste avec prix', 12], ['mosaique', '2 grandes', 'vignettes 2 × 2', 4]];
  return '<div class="am-q">' + sur + '</div><div class="am-lays">' + L4.map(x => { const p = Math.ceil(n / x[3]); return '<div class="am-lay' + (x[0] === on ? ' on' : '') + (p > 2 ? ' trop' : '') + '">' + mini(x[0]) + '<b>' + x[1] + '</b><small>' + x[2] + ' · ' + x[3] + '</small><em>' + p + ' page' + (p > 1 ? 's' : '') + (p > 2 ? ' · plus de 2' : '') + '</em></div>'; }).join('') + '</div>';
}
const seg = (a, b, on) => '<span class="am-seg"><i class="' + (on === 0 ? 'on' : '') + '">' + a + '</i><i class="' + (on === 1 ? 'on' : '') + '">' + b + '</i></span>';
const gabs = '<div class="am-gabs">' + ['A · Classique', 'B · Bandeau', 'C · Épuré'].map((g, i) => '<div class="am-gab' + (i === 0 ? ' on' : '') + '">' + g + '</div>').join('') + '</div>';
function cote(etat, lignes, note) {
  const tot = 6, pris = etat.reduce((s, x) => s + x[1], 0);
  return '<aside class="am-cote"><h3>Mon catalogue · 10 pages A5</h3><div><b>' + pris + ' pages de produits sur ' + tot + '</b></div>'
    + '<div class="am-jauge">' + etat.map(x => '<i style="width:' + (100 * x[1] / tot) + '%;background:' + x[0] + '"></i>').join('') + '</div>'
    + '<div class="am-lg">' + lignes.map(l => '<div>' + pt(l[0]) + l[1] + '<span class="n">' + l[2] + '</span></div>').join('') + '</div>'
    + '<div class="am-note">' + note + '</div></aside>';
}

const ECRANS = {
  'a-categorie': () => sous(0) + '<div class="am-corps"><section class="am-pan">'
    + '<div class="am-kick">Étape 3 · Présentation — catégorie 1 sur 3</div><div class="am-tit"><h2>Pain Tradition</h2><span>11 produits choisis à l’étape 2</span></div>'
    + lays(11, 'grille', 'Comment présenter ces 11 produits ?')
    + '<div class="am-aps">' + ap(D.pain2[0], 'page <b>3</b> · 6 produits', 210) + ap(D.pain2[1], 'page <b>4</b> · 5 produits', 210) + '</div>'
    + '<div class="am-opts"><div class="am-opt"><div class="t"><span><span class="am-pt"></span>Un produit seul sur une page, avec sa promotion</span><span class="sp"></span>' + seg('Non', 'Oui', 0) + '</div>'
    + '<div class="s">Une page de plus, avant les autres : le produit en grand, son poids, ses allergènes, son prix barré par sa remise et l’offre qui le contient.</div></div>'
    + '<div class="am-opt"><div class="t">Gabarit</div>' + gabs + '<div class="t">Catégorie manuscrite<span class="sp"></span>' + seg('Non', 'Oui', 0) + '</div></div></div>'
    + '<div class="am-pied"><span class="cm-btn">‹ Gammes</span><span class="sp"></span><span class="cm-cpt">2 pages · il en reste 4 pour les 2 autres catégories</span><span class="cm-btn pri">Cougnou ›</span></div>'
    + '</section>' + cote([[COUL.pain, 2]], [[COUL.pain, 'Pain Tradition', '2 pages'], ['#D9D2C7', 'Cougnou', 'à faire'], ['#D9D2C7', 'Plateau Sandwich', 'à faire']],
      'Une étape par catégorie choisie, dans l’ordre de l’étape 2. Les 4 pages de la marque (couverture, page 2, avant-dernière, dernière) ne bougent pas.') + '</div>',
  'b-pleine-page': () => sous(2) + '<div class="am-corps"><section class="am-pan">'
    + '<div class="am-kick">Étape 3 · Présentation — catégorie 3 sur 3</div><div class="am-tit"><h2>Plateau Sandwich</h2><span>8 produits choisis à l’étape 2</span></div>'
    + '<div class="am-opt on"><div class="t"><span><span class="am-pt"></span>Un produit seul sur une page, avec sa promotion</span><span class="sp"></span>' + seg('Non', 'Oui', 1) + '</div>'
    + '<div class="am-sel"><span>Mini sandwiche garni / pièces</span><span>▾</span></div>'
    + '<div class="s">« Mini sandwiche garni / pièces » seul sur sa page · pas de promotion cochée pour lui à Halle : la page montre son prix. Avec une offre (bundle, « 2+1 ») qui le contient, elle s’imprime dessous.</div></div>'
    + lays(7, 'liste', 'Et les 7 autres ?')
    + '<div class="am-aps">' + ap(D.sand[0], 'page <b>7</b> · pleine page', 210) + ap(D.sand[1], 'page <b>8</b> · 7 produits', 210) + '</div>'
    + '<div class="am-opts"><div class="am-opt"><div class="t">Gabarit</div>' + gabs + '</div><div class="am-opt"><div class="t">Catégorie manuscrite<span class="sp"></span>' + seg('Non', 'Oui', 0) + '</div></div></div>'
    + '<div class="am-pied"><span class="cm-btn">‹ Cougnou</span><span class="sp"></span><span class="cm-cpt">2 pages · 5 pages de produits sur 6</span><span class="cm-btn pri">Mise en page finale ›</span></div>'
    + '</section>' + cote([[COUL.pain, 2], [COUL.cougnou, 1], [COUL.sand, 2]], [[COUL.pain, 'Pain Tradition', '2 pages ✓'], [COUL.cougnou, 'Cougnou', '1 page ✓'], [COUL.sand, 'Plateau Sandwich', '2 pages']],
      'Il reste 1 page : elle restera libre (une photo ou l’illustration la prend), ou je repasse 2 pages en moins à la dernière étape.') + '</div>',
  'c-finale': () => sous(3) + '<div class="am-corps"><section class="am-pan">'
    + '<div class="am-kick">Étape 3 · Présentation — la mise en page finale</div><div class="am-tit"><h2>Mon catalogue</h2><span>10 pages A5 · je glisse une catégorie pour changer l’ordre, ses pages la suivent</span></div>'
    + '<div class="am-fin" style="position:relative">'
    + '<div class="am-bloc lock"><div class="h">🔒 Marque</div><div class="pp">' + th(D.doc[0].html, '1', 92) + th(D.doc[1].html, '2', 92) + '</div></div>'
    + '<div class="am-depot"></div>'
    + '<div class="am-bloc"><div class="h"><span class="poi">⠿</span>' + pt(COUL.pain) + 'Pain Tradition<span class="pg">p. 3-4</span></div><div class="pp">' + th(D.pain2[0], '', 92) + th(D.pain2[1], '', 92) + '</div></div>'
    + '<div class="am-bloc"><div class="h"><span class="poi">⠿</span>' + pt(COUL.cougnou) + 'Cougnou<span class="pg">p. 5</span></div><div class="pp">' + th(D.cougnou[0], '', 92) + '</div></div>'
    + '<div class="am-bloc fantome"><div class="h"><span class="poi">⠿</span>' + pt(COUL.sand) + 'Plateau Sandwich<span class="pg">p. 6-7</span></div><div class="pp">' + th(D.sand[0], '', 92) + th(D.sand[1], '', 92) + '</div></div>'
    + '<div class="am-bloc"><div class="h"><span class="poi">⠿</span>Page libre<span class="pg">p. 8</span></div><div class="pp"><div class="am-vide">Photo ou illustration de la gamme · ou −2 pages</div></div></div>'
    + '<div class="am-bloc lock"><div class="h">🔒 Marque</div><div class="pp">' + th(D.doc[8].html, '9', 92) + th(D.doc[9].html, '10', 92) + '</div></div>'
    + '<div class="am-bloc glisse" style="position:absolute;left:226px;top:112px;z-index:3"><div class="am-bulle">Déposer ici : Plateau Sandwich en pages 3-4</div><div class="h"><span class="poi">⠿</span>' + pt(COUL.sand) + 'Plateau Sandwich<span class="pg">2 pages</span></div><div class="pp">' + th(D.sand[0], '', 92) + th(D.sand[1], '', 92) + '</div></div>'
    + '</div>'
    + '<div class="am-note">⠿ prendre une catégorie : ses pages suivent ensemble · un clic sur une page l’ouvre en grand · les pages de la marque ne bougent pas.</div>'
    + '<div class="am-opts"><div class="am-opt"><div class="t">Nombre de pages<span class="sp"></span><span class="am-seg"><i>8</i><i class="on">10</i><i>12</i><i>14</i><i>16</i></span></div><div class="s">Toujours par 2. À 8 pages, la page libre part.</div></div>'
    + '<div class="am-opt"><div class="t">Format<span class="sp"></span>' + seg('A5', 'A4', 0) + '</div><div class="t">Langues<span class="sp"></span>' + seg('FR', 'FR + NL', 0) + '</div></div></div>'
    + '<div class="am-pied"><span class="cm-btn">‹ Plateau Sandwich</span><span class="sp"></span><span class="cm-cpt">rien n’est perdu : chaque catégorie se reprend d’un clic en haut</span><span class="cm-btn pri">Validation ›</span></div>'
    + '</section>' + cote([[COUL.sand, 2], [COUL.pain, 2], [COUL.cougnou, 1]], [[COUL.sand, 'Plateau Sandwich', 'p. 3-4'], [COUL.pain, 'Pain Tradition', 'p. 5-6'], [COUL.cougnou, 'Cougnou', 'p. 7'], ['#D9D2C7', 'Page libre', 'p. 8']],
      'L’ordre une fois la catégorie déposée. L’ordre des pages est celui de l’impression : la page 3 suit la page 2 de la marque.') + '</div>',
};

(async () => {
  const b = await chromium.launch();
  const p = await b.newPage({ viewport: { width: 1440, height: 1000 }, deviceScaleFactor: 2 });
  await p.route(u => /\/catalogues?(\/|\?|$)/.test(u.pathname) && !/\.(js|css)$/.test(u.pathname), r => r.request().method() !== 'GET' ? r.fulfill({ status: 409, body: '{}' }) : r.continue());
  await p.route('**/garde.js*', r => r.fulfill({ contentType: 'application/javascript', body: '' }));
  for (const f of ['catalogue.js', 'catalogue.css']) await p.route('**/dashboard/' + f + '*', r => r.fulfill({ contentType: f.endsWith('.css') ? 'text/css' : 'application/javascript', body: fs.readFileSync(L + f, 'utf8') }));
  await p.goto('http://185.180.206.46/consulant_bo/dashboard/?shop=4&vue=catalogue', { waitUntil: 'networkidle', timeout: 180000 }).catch(() => {});
  await p.waitForSelector('[data-cm="ouvrir"]', { timeout: 60000 }); await p.waitForTimeout(1500);
  await p.locator('[data-cm="ouvrir"]').filter({ hasText: 'Continuer' }).first().click();
  await p.waitForSelector('[data-cm="etape"][data-v="3"]', { timeout: 60000 }); await p.waitForTimeout(2500);
  await p.locator('[data-cm="etape"][data-v="3"]').first().click(); await p.waitForTimeout(6000);
  await p.addStyleTag({ content: CSS });
  // Le zoom rend un bouton, la feuille et sa légende : on ne garde que la feuille (DOM du navigateur, balises fermées).
  for (const k of ['pain2', 'cougnou', 'sand']) {
    D[k] = await p.evaluate(a => a.map(h => { const t = document.createElement('template'); t.innerHTML = h; const f = t.content.querySelector('.cm-feuille'); return f ? f.outerHTML : h; }), D[k]);
  }
  for (const [nom, f] of Object.entries(ECRANS)) {
    if (process.env.SEUL && process.env.SEUL !== nom) { continue; }
    await p.evaluate(html => {
      const c = document.querySelector('.cm-corps, .am');
      const n = document.createElement('div'); n.className = 'am'; n.innerHTML = html; c.replaceWith(n);
      n.querySelectorAll('[data-w]').forEach(x => { const f = x.querySelector('.cm-feuille'); if (f) { f.style.zoom = (+x.dataset.w / parseFloat(f.style.width)).toFixed(4); } });
      window.scrollTo(0, 0);
    }, f());
    await p.waitForTimeout(1500);
    const haut = await p.locator('.cm-et4, .cm-et4.cm-et5').first().boundingBox();
    const bas = await p.locator('.am').boundingBox();
    const titre = await p.evaluate(() => { const t = document.querySelector('.cm-et4'); const r = t.parentElement.getBoundingClientRect(); return r.top + window.scrollY; });
    const y = Math.max(0, Math.min(titre, haut.y) - 8);
    const H = bas.y + bas.height - y + 12;
    await p.setViewportSize({ width: 1440, height: Math.max(1000, Math.ceil(bas.y + bas.height + 40)) }); await p.waitForTimeout(600);
    await p.screenshot({ path: path.join(ICI, nom + '.png'), clip: { x: haut.x - 8, y, width: bas.width + 16, height: H } });
    console.log(nom, Math.round(bas.width), Math.round(H));
  }
  await b.close();
})();
