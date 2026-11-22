/**
 * Les schémas de l'article « Pôle vente : les leviers du chiffre d'affaires » (FO-41, 16/11/2026) :
 * data/formation/gestion/photos/lev-*.jpg, 1600 × 1200 (le cadre 4:3 de la tablette), libellés FR et NL.
 *
 * Les chiffres sont ceux de l'exemple de l'article — un magasin type, pas un magasin du réseau : 150 clients par jour,
 * panier de 12 €, 26 jours ouverts par mois, matière à 40 % du prix de vente (le taux budget du réseau), 10 000 € de
 * personnel par mois. Même calcul que le dossier Sombreffe (docs/dossiers/sombreffe-2026-11, la dernière page) : le
 * trafic et le panier coûtent leur matière, le prix (à volume égal) non ; l'équipe reste la même.
 *
 *   node tools/formation/schemas_leviers.js          (depuis pilotage-reseau/)
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const RACINE = path.join(__dirname, '..', '..');
const SORTIE = path.join(RACINE, 'data', 'formation', 'gestion', 'photos');
const POLICES = path.join(RACINE, 'public', 'assets', 'ds', 'fonts');

// L'exemple de l'article.
const EX = { clients: 150, panier: 12, jours: 26, matiere: 0.40, personnel: 10000 };
const calc = (dc = 0, dp = 0, px = 0) => {
  const vol = (EX.clients + dc) * (EX.panier + dp) * EX.jours, ca = vol * (1 + px / 100);
  return { ca, marge: ca - vol * EX.matiere, pers: EX.personnel / ca * 100 };
};
const BASE = calc();
const gain = (...a) => { const c = calc(...a); return { ca: c.ca - BASE.ca, res: c.marge - BASE.marge, pers: c.pers }; };

// Les nombres à la française : espace insécable des milliers, virgule décimale.
const NB = ' ';
const grp = v => Math.round(v).toString().replace(/\B(?=(\d{3})+(?!\d))/g, NB);
const eur = v => grp(v) + NB + '€';
const dec = (v, n = 1) => v.toFixed(n).replace('.', ',');
const k1 = v => dec(v / 1000) + NB + 'k€';

// Les couleurs du design system : bordeaux (le trafic), vert (le panier), doré (le prix).
const C = { encre: '#1c1a17', doux: '#5c5852', pale: '#7a736a', papier: '#F8F4EE', ligne: '#e6dfd4',
  trafic: '#8D1D2C', panier: '#3f7a52', prix: '#B0792A' };

const police = f => fs.readFileSync(path.join(POLICES, f)).toString('base64');
const STYLE = `<style>
@font-face { font-family: G; src: url(data:font/otf;base64,${police('Gotham_Medium.otf')}); font-weight: 400; }   /* texte (DESIGN_SYSTEM : ds.css) */
@font-face { font-family: G; src: url(data:font/otf;base64,${police('Gotham_Regular.otf')}); font-weight: 700; }  /* accents */
* { box-sizing: border-box; margin: 0; }
body { width: 1600px; height: 1200px; font-family: G, sans-serif; color: ${C.encre}; background: #fff; padding: 70px 84px; position: relative; }
.k { font-size: 26px; letter-spacing: .14em; text-transform: uppercase; color: ${C.pale}; }
h1 { font-size: 64px; font-weight: 400; margin-top: 14px; line-height: 1.1; }
.nl { color: ${C.pale}; font-size: .62em; font-weight: 400; display: block; margin-top: 6px; }
.pied { position: absolute; left: 84px; right: 84px; bottom: 56px; font-size: 25px; color: ${C.pale}; line-height: 1.45; border-top: 2px solid ${C.ligne}; padding-top: 22px; }
.cartes { display: grid; grid-template-columns: repeat(3, 1fr); gap: 34px; margin-top: 44px; }
.carte { border-radius: 22px; padding: 30px 36px; }
.carte .pas { font-size: 56px; font-weight: 700; }
.carte .pas small { font-size: 28px; font-weight: 400; }
.carte .l { font-size: 25px; color: ${C.doux}; margin-top: 24px; }
.carte .v { font-size: 46px; margin-top: 6px; }
.carte .an { font-size: 27px; margin-top: 20px; font-weight: 700; }
.note { margin-top: 34px; border-radius: 18px; padding: 24px 36px; font-size: 31px; line-height: 1.4; }
</style>`;

const page = corps => `<!DOCTYPE html><html><head><meta charset="utf-8">${STYLE}</head><body>${corps}</body></html>`;
const exemple = `Exemple : ${EX.clients} clients par jour, panier de ${EX.panier}${NB}€, ${EX.jours} jours ouverts par mois, matière à ${Math.round(EX.matiere * 100)}${NB}%.<br>`
  + `<span style="color:#9a938a">Voorbeeld: ${EX.clients} klanten per dag, ticket van ${EX.panier}${NB}€, ${EX.jours} open dagen per maand, grondstoffen ${Math.round(EX.matiere * 100)}${NB}%.</span>`;
const teinte = (hex, a) => { const n = parseInt(hex.slice(1), 16); return `rgba(${n >> 16},${(n >> 8) & 255},${n & 255},${a})`; };

/* 1 · La formule. */
function formule() {
  const bloc = (t, nl, c) => `<span style="display:inline-block;text-align:center;padding:30px 40px;border-radius:20px;background:${teinte(c, .1)};border:3px solid ${c};color:${c}">`
    + `<b style="font-size:58px">${t}</b><span style="display:block;font-size:26px;margin-top:4px;color:${C.doux}">${nl}</span></span>`;
  const op = s => `<span style="font-size:64px;color:${C.pale};margin:0 26px">${s}</span>`;
  const ligne = (...x) => `<div style="display:flex;align-items:center;justify-content:center;margin-top:60px">${x.join('')}</div>`;
  const ca = `<span style="display:inline-block;text-align:center;padding:26px 34px;border-radius:20px;background:${C.papier}"><b style="font-size:58px">Chiffre d’affaires</b><span style="display:block;font-size:26px;margin-top:4px;color:${C.doux}">omzet</span></span>`;
  const pan = `<span style="display:inline-block;text-align:center;padding:26px 34px;border-radius:20px;background:${C.papier}"><b style="font-size:58px">Panier</b><span style="display:block;font-size:26px;margin-top:4px;color:${C.doux}">ticket</span></span>`;
  return page(`<div class="k">Pôle vente · Verkoop</div><h1>Trois leviers, pas un de plus<span class="nl">Drie hefbomen, niet één meer</span></h1>`
    + ligne(ca, op('='), bloc('Clients', 'klanten · le trafic', C.trafic), op('×'), pan)
    + ligne(pan, op('='), bloc('Articles', 'artikelen · le panier', C.panier), op('×'), bloc('Prix', 'prijs · le prix', C.prix))
    + `<div class="note" style="background:${C.papier};text-align:center;margin-top:64px"><b>${EX.clients} clients × ${EX.panier}${NB}€ = ${eur(EX.clients * EX.panier)} par jour</b>`
    + ` · × ${EX.jours} jours = <b>${eur(BASE.ca)} par mois</b><br><span style="color:${C.pale};font-size:27px">${EX.clients} klanten × ${EX.panier}${NB}€ = ${eur(EX.clients * EX.panier)} per dag · × ${EX.jours} dagen = ${eur(BASE.ca)} per maand</span></div>`
    + `<div class="pied">${exemple}</div>`);
}

/* 2 à 4 · Un levier, trois pas : ce qu'il rapporte au chiffre d'affaires et au résultat. */
function levier({ k, titre, nl, sous, couleur, pas, note }) {
  const cartes = pas.map(p => {
    const g = gain(...p.args);
    return `<div class="carte" style="background:${teinte(couleur, .08)};border:2px solid ${teinte(couleur, .35)}">`
      + `<div class="pas" style="color:${couleur}">${p.lab}<br><small>${p.sous}</small></div>`
      + `<div class="l">Chiffre d’affaires · omzet</div><div class="v">+${eur(g.ca)}</div>`
      + `<div class="l">Résultat · resultaat</div><div class="v" style="color:${C.panier}">+${eur(g.res)}</div>`
      + `<div class="an" style="color:${C.panier}">+${k1(g.res * 12)} par an · per jaar</div></div>`;
  }).join('');
  return page(`<div class="k">${k}</div><h1>${titre}<span class="nl">${nl}</span></h1>`
    + `<div style="font-size:30px;color:${C.doux};margin-top:22px">${sous}</div>`
    + `<div class="cartes">${cartes}</div>`
    + (note ? `<div class="note" style="background:${note.fond};color:${note.couleur || C.encre}">${note.html}</div>` : '')
    + `<div class="pied">${exemple}</div>`);
}

/* 5 · Les trois ensemble : le triangle (même dessin que la dernière page du dossier Sombreffe). */
function triangle() {
  const PX = [2, 4, 6], DC = [5, 10, 15], DP = [0.5, 1, 2], N = [['Petit pas', 'kleine stap'], ['Moyen', 'middel'], ['Fort', 'groot']];
  const ens = [0, 1, 2].map(i => gain(DC[i], DP[i], PX[i]));
  const cx = 420, cy = 480, Lg = 290, ang = [-90, 150, 30].map(a => a * Math.PI / 180);
  const pt = (a, k) => [cx + Math.cos(ang[a]) * Lg * k / 3, cy + Math.sin(ang[a]) * Lg * k / 3];
  const vert = [0.10, 0.16, 0.24].map(o => teinte(C.panier, o));
  const coul = [C.prix, C.trafic, C.panier];
  let s = `<svg width="1432" height="800" viewBox="0 0 1432 800" style="position:absolute;left:84px;top:236px;font-family:G">`;
  [2, 1, 0].forEach(i => { s += `<polygon points="${[0, 1, 2].map(a => pt(a, i + 1).map(v => v.toFixed(1)).join(',')).join(' ')}" fill="${vert[2 - i]}" stroke="${C.panier}" stroke-width="${i === 2 ? 4 : 2.5}" stroke-opacity=".8"/>`; });
  const noms = [['Le prix', 'à volume égal', 'de prijs'], ['Le trafic', 'au panier d’aujourd’hui', 'de traffic'], ['Le panier', 'au prix d’aujourd’hui', 'het ticket']];
  const pas = [PX.map(x => `+${x}${NB}%`), DC.map(x => `+${x} clients`), DP.map(x => `+${dec(x, x % 1 ? 2 : 0)}${NB}€`)];
  [0, 1, 2].forEach(a => {
    const [tx, ty] = pt(a, 3), [ex, ey] = pt(a, 3.25);
    s += `<line x1="${cx}" y1="${cy}" x2="${ex.toFixed(1)}" y2="${ey.toFixed(1)}" stroke="${coul[a]}" stroke-width="3.5"/>`;
    const ny = a === 0 ? ey - 82 : ty + 66;
    s += `<text x="${tx.toFixed(1)}" y="${ny.toFixed(1)}" font-size="36" font-weight="700" text-anchor="middle" fill="${coul[a]}">${noms[a][0]}</text>`
      + `<text x="${tx.toFixed(1)}" y="${(ny + 32).toFixed(1)}" font-size="23" text-anchor="middle" fill="${C.doux}">${noms[a][1]}</text>`
      + `<text x="${tx.toFixed(1)}" y="${(ny + 60).toFixed(1)}" font-size="22" text-anchor="middle" fill="${C.pale}">${noms[a][2]}</text>`;
    [1, 2, 3].forEach(k => {
      const [x, y] = pt(a, k);
      s += `<circle cx="${x.toFixed(1)}" cy="${y.toFixed(1)}" r="8" fill="${coul[a]}"/>`;
      const off = a === 0 ? [20, 9, 'start'] : a === 1 ? [-18, 9, 'end'] : [18, 9, 'start'];
      s += `<text x="${(x + off[0]).toFixed(1)}" y="${(y + off[1]).toFixed(1)}" font-size="27" text-anchor="${off[2]}" fill="${C.encre}" style="paint-order:stroke;stroke:#fff;stroke-width:7px">${pas[a][k - 1]}</text>`;
    });
  });
  s += `<circle cx="${cx}" cy="${cy}" r="9" fill="${C.encre}"/><text x="${cx - 16}" y="${cy - 14}" font-size="22" text-anchor="end" fill="${C.doux}" style="paint-order:stroke;stroke:#fff;stroke-width:7px">aujourd’hui · vandaag</text>`;
  const bx = 870;
  s += `<text x="${bx}" y="60" font-size="27" fill="${C.pale}">Le résultat en plus · extra resultaat</text>`;
  ens.forEach((g, i) => {
    const y0 = 86 + i * 218;
    s += `<rect x="${bx}" y="${y0}" width="560" height="196" rx="20" fill="${vert[i]}" stroke="${C.panier}" stroke-opacity=".5" stroke-width="2"/>`
      + `<text x="${bx + 32}" y="${y0 + 44}" font-size="27" font-weight="700" fill="${C.panier}">${N[i][0]} · ${N[i][1]}</text>`
      + `<text x="${bx + 32}" y="${y0 + 80}" font-size="24" fill="${C.doux}">+${PX[i]}${NB}% · +${DC[i]} clients · +${dec(DP[i], DP[i] % 1 ? 2 : 0)}${NB}€ par client</text>`
      + `<text x="${bx + 32}" y="${y0 + 138}" font-size="50" font-weight="700" fill="${C.panier}">+${eur(g.res)}</text>`
      + `<text x="${bx + 32}" y="${y0 + 176}" font-size="24" fill="${C.panier}">par mois · per maand ; +${k1(g.res * 12)} par an</text>`;
  });
  s += '</svg>';
  return page(`<div class="k">Pôle vente · Verkoop</div><h1>Les trois ensemble<span class="nl">De drie samen</span></h1>${s}<div class="pied">${exemple}</div>`);
}

/* 6 · L'équipe : même coût, plus de chiffre d'affaires, une part plus petite. */
function equipe() {
  const L = [['Aujourd’hui', 'vandaag', BASE.pers, C.pale], ['Petit pas', 'kleine stap', gain(5, 0.5, 2).pers, teinte(C.panier, .55)],
    ['Moyen', 'middel', gain(10, 1, 4).pers, teinte(C.panier, .75)], ['Fort', 'groot', gain(15, 2, 6).pers, C.panier]];
  const max = 24;
  const barres = L.map(([fr, nl, p, c]) => `<div style="display:grid;grid-template-columns:300px 1fr 150px;align-items:center;gap:26px;margin-top:40px">`
    + `<div style="font-size:36px">${fr}<span style="display:block;font-size:24px;color:${C.pale}">${nl}</span></div>`
    + `<div style="height:74px;border-radius:12px;background:${C.papier}"><div style="height:100%;width:${(p / max * 100).toFixed(1)}%;border-radius:12px;background:${c}"></div></div>`
    + `<div style="font-size:46px;font-weight:700;text-align:right">${dec(p)}${NB}%</div></div>`).join('');
  return page(`<div class="k">Pôle vente · Verkoop</div><h1>La même équipe pèse moins<span class="nl">Hetzelfde team weegt minder</span></h1>`
    + `<div style="font-size:30px;color:${C.doux};margin-top:22px">Le personnel en % du chiffre d’affaires, à ${eur(EX.personnel)} par mois · personeel in % van de omzet</div>`
    + `<div style="margin-top:30px">${barres}</div>`
    + `<div class="note" style="background:${C.papier};margin-top:60px">Pas une heure de moins : c’est le chiffre d’affaires qui grandit.<br><span style="color:${C.pale};font-size:27px">Geen uur minder: het is de omzet die groeit.</span></div>`
    + `<div class="pied">${exemple}</div>`);
}

const perte = (() => { const parClient = (EX.panier * 1.02 - EX.panier * EX.matiere) * EX.jours; return gain(0, 0, 2).res / parClient; })();
const SCHEMAS = {
  'lev-formule': formule(),
  'lev-trafic': levier({ k: 'Levier 1 · Hefboom 1', titre: 'Le trafic : plus de clients', nl: 'De traffic: meer klanten', couleur: C.trafic,
    sous: `Au panier d’aujourd’hui (${EX.panier}${NB}€) ; chaque client de plus coûte sa matière (${Math.round(EX.matiere * 100)}${NB}%).`,
    pas: [{ lab: '+5', sous: 'clients par jour', args: [5, 0, 0] }, { lab: '+10', sous: 'clients par jour', args: [10, 0, 0] }, { lab: '+15', sous: 'clients par jour', args: [15, 0, 0] }] }),
  'lev-panier': levier({ k: 'Levier 2 · Hefboom 2', titre: 'Le panier : plus d’articles par client', nl: 'Het ticket: meer artikelen per klant', couleur: C.panier,
    sous: `Au prix d’aujourd’hui, pour les ${EX.clients} clients de chaque jour ; la matière suit (${Math.round(EX.matiere * 100)}${NB}%).`,
    pas: [{ lab: `+0,50${NB}€`, sous: 'par client', args: [0, 0.5, 0] }, { lab: `+1${NB}€`, sous: 'par client', args: [0, 1, 0] }, { lab: `+2${NB}€`, sous: 'par client', args: [0, 2, 0] }],
    note: { fond: C.papier, html: `0,50${NB}€ par client : un café ou un cookie à un client sur quatre.<br><span style="color:${C.pale};font-size:27px">0,50${NB}€ per klant: een koffie of een koekje bij één klant op vier.</span>` } }),
  'lev-prix': levier({ k: 'Levier 3 · Hefboom 3', titre: 'Le prix : ce que vaut chaque article', nl: 'De prijs: wat elk artikel waard is', couleur: C.prix,
    sous: 'À volume égal : pas de matière en plus, tout va au résultat.',
    pas: [{ lab: `+2${NB}%`, sous: 'de prix', args: [0, 0, 2] }, { lab: `+4${NB}%`, sous: 'de prix', args: [0, 0, 4] }, { lab: `+6${NB}%`, sous: 'de prix', args: [0, 0, 6] }],
    note: { fond: teinte(C.trafic, .07), couleur: C.trafic, html: `Attention : perdre ${Math.round(perte)} clients par jour efface les +2${NB}%.<br><span style="font-size:27px;opacity:.75">Opgelet: ${Math.round(perte)} klanten per dag verliezen wist de +2${NB}% uit.</span>` } }),
  'lev-triangle': triangle(),
  'lev-equipe': equipe(),
};

(async () => {
  fs.mkdirSync(SORTIE, { recursive: true });
  const nav = await chromium.launch();
  try {
    const p = await nav.newPage({ viewport: { width: 1600, height: 1200 } });
    for (const [nom, html] of Object.entries(SCHEMAS)) {
      await p.setContent(html);
      await p.evaluate(() => document.fonts.ready);
      await p.screenshot({ path: path.join(SORTIE, nom + '.jpg'), type: 'jpeg', quality: 88 });
      console.log('écrit :', path.relative(RACINE, path.join(SORTIE, nom + '.jpg')));
    }
  } finally { await nav.close(); }
})();
