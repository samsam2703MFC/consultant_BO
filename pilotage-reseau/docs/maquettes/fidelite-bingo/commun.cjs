/* Fidélité — la carte à points de l'ERP couplée au bingo de saison : ce que les trois maquettes partagent
 * (style, rail, données de la saison, petits rendus). Demande de Sam du 13/11/2026 : « une carte à points, avec en
 * plus un bingo pour la récurrence » ; « works only with api : not from table, just only from api ».
 *
 * Tout ce que les écrans montrent vient de l'API de l'ERP (TF Buddy, openapi du 13/11/2026) ou s'en calcule : aucune
 * table du cockpit. Chaque bloc porte la route qui le sert. Ce que l'API ne sait pas faire est dit en ambre.
 * Les chiffres clients sont ILLUSTRÉS : l'API de test répond 401 sans jeton, rien n'a été lu. */
const fs = require('fs');
const path = require('path');

const RACINE = '../../../public/assets';           // depuis docs/maquettes/fidelite-bingo/
const nf = (n, d) => Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d || 0, maximumFractionDigits: d || 0 });
const eur = v => nf(v, v % 1 ? 2 : 0) + ' €';
const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
const ill = (n, cls) => `<img class="${cls || 'ill'}" src="ill/${n}.png" alt="">`;
const api = t => `<code class="api">${esc(t)}</code>`;
const gap = (t, titre) => `<div class="gap"><span>${titre || 'Pas dans l’API'}</span>${t}</div>`;

/* La saison d'hiver de Sam (bingo-maquette, 12/11/2026) : 13 semaines du lundi 23/11/2026 au dimanche 21/02/2027. */
const SEMAINES = [
  { n: 1, du: '23/11', titre: 'Le cougnou est là', fete: 1, ill: 'bread-2' },
  { n: 2, du: '30/11', titre: 'Saint-Nicolas', fete: 1, ill: 'saint-nicolas' },
  { n: 3, du: '07/12', titre: 'Le chocolat chaud', ill: 'chocolat' },
  { n: 4, du: '14/12', titre: 'Le pain d’épices', ill: 'cookies' },
  { n: 5, du: '21/12', titre: 'Noël', fete: 1, ill: 'noel' },
  { n: 6, du: '28/12', titre: 'Nouvel An', fete: 1, ill: 'artboard-4' },
  { n: 7, du: '04/01', titre: 'Épiphanie', fete: 1, ill: 'galette' },
  { n: 8, du: '11/01', titre: 'Le bon pain', ill: 'bread-1' },
  { n: 9, du: '18/01', titre: 'Blue Monday', ill: 'cake-slice' },
  { n: 10, du: '25/01', titre: 'Le croissant', ill: 'croissant' },
  { n: 11, du: '01/02', titre: 'Chandeleur', fete: 1, ill: 'crepes' },
  { n: 12, du: '08/02', titre: 'Saint-Valentin', fete: 1, ill: 'valentin' },
  { n: 13, du: '15/02', titre: 'La tarte du dimanche', ill: 'tarte' },
];
const BONS = [
  { k: 3, v: 4.9, nom: 'Bon de l’Avent', motif: 'cake' },
  { k: 6, v: 9.9, nom: 'Bon des Rois', motif: 'tartelette' },
  { k: 9, v: 19.9, nom: 'Bon de l’hiver', motif: 'hiver' },
  { k: 12, v: 29.9, nom: 'Bon carte pleine', motif: 'cupcake' },
];
const SUPER = 49.9, SEUIL = 20;   // la case : 20 points gagnés sur la carte dans la semaine (×2 compris)
/* Julie, jeudi 14 janvier 2027, 14 h 58 (semaine 8) : six cases, le Nouvel An manqué ; le bon de l'Avent utilisé, celui
 * des Rois dans son portefeuille ; 6 points cette semaine (6,40 € lundi). */
const JULIE = { cochees: [1, 2, 3, 4, 5, 7], manquees: [6], enCours: 8, semaine: 6 };   // 6 points cette semaine

/* Le style : design system commun (docs/DESIGN_SYSTEM.md) — pas d'ombre, bordures #e6e0d8, papier, un seul bordeaux,
 * pas de gras hors chiffres clés et entrée active du rail, coins de 4 à 8 px, interrupteur or. */
const CSS = `
@font-face{font-family:'Gotham';src:url('${RACINE}/ds/fonts/Gotham_Medium.otf') format('opentype');font-weight:400}
@font-face{font-family:'Gotham';src:url('${RACINE}/ds/fonts/Gotham_Regular.otf') format('opentype');font-weight:700}
@font-face{font-family:'Vank';src:url('${RACINE}/ds/fonts/GC_Vank.ttf') format('truetype');unicode-range:U+0020-007E}
:root{--ink:#1c1a17;--muted-2:#5c5852;--muted:#7a736a;--faint:#B8B2A8;--paper:#faf7f2;--table-head:#FBF9F5;--chip:#EDE7DE;
  --line:#e6e0d8;--row-line:rgba(34,34,34,.06);--accent:#8D1D2C;--accent-f:#6f1622;--abricot:#F2C9A0;--abricot-d:#F8E2CB;
  --green:#3f7a52;--prize:#B0792A;--prize-soft:#F6E7CE;--prize-ink:#7A5207;--chart-real:#B03048;--chart-prev:#EBD3D8}
*{box-sizing:border-box}
body{margin:0;background:var(--paper);color:var(--ink);font:400 13px/1.45 'Gotham',system-ui,sans-serif;-webkit-font-smoothing:antialiased}
h1,h2,h3,h4,b{font-weight:400;margin:0}
.ill{display:block;width:100%;height:100%;object-fit:contain;filter:drop-shadow(.35px 0 0 #8D1D2C) drop-shadow(-.35px 0 0 #8D1D2C) drop-shadow(0 .35px 0 #8D1D2C) drop-shadow(0 -.35px 0 #8D1D2C)}
.blanc .ill{filter:brightness(0) invert(1) drop-shadow(.4px 0 0 #fff) drop-shadow(-.4px 0 0 #fff) drop-shadow(0 .4px 0 #fff) drop-shadow(0 -.4px 0 #fff)}
.app{display:grid;grid-template-columns:246px minmax(0,1fr);min-height:100vh}
/* Le rail */
.rail{background:#fff;border-right:1px solid var(--line);padding:20px 12px 24px}
.rail .lg{height:26px;display:block;margin:0 4px}
.rail .kick{font-size:10px;letter-spacing:.2em;text-transform:uppercase;color:var(--accent);margin:9px 4px 6px}
.rail .grp{margin-top:18px}
.rail .gl{font-size:10px;letter-spacing:.14em;text-transform:uppercase;color:var(--faint);padding:0 10px 8px}
.rail .it{display:block;padding:8px 10px;border-radius:8px;font-size:13px;color:var(--muted-2)}
.rail .it.on{background:rgba(141,29,44,.08);color:var(--accent);font-weight:700}
/* L'en-tête */
main{padding:24px 32px 48px;min-width:0}
.fil{font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:var(--muted)}
.fil code{font:10.5px ui-monospace,Menlo,monospace;letter-spacing:0;text-transform:none;background:#fff;border:1px solid var(--line);border-radius:4px;padding:1px 6px;margin-left:6px;color:var(--muted-2)}
h1{font-size:26px;margin:4px 0 4px}
.intro{color:var(--muted-2);max-width:860px;margin:0}
.hd{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;margin-bottom:16px}
.hd .dr{display:flex;flex-direction:column;align-items:flex-end;gap:6px;flex:0 0 auto}
.tag{display:inline-block;font-size:10px;letter-spacing:.06em;text-transform:uppercase;border-radius:4px;padding:2px 7px;white-space:nowrap}
.tag.mq{background:var(--ink);color:#fff}
.tag.ill{background:var(--prize-soft);color:var(--prize-ink)}
.tag.ok{background:rgba(63,122,82,.12);color:var(--green)}
.tag.neu{background:var(--chip);color:var(--muted-2)}
.tag.acc{border:1px solid var(--accent);color:var(--accent)}
.tag.plein{background:var(--accent);color:#fff}
.barre{display:flex;align-items:center;gap:12px;margin-bottom:18px}
.seg{display:inline-flex;background:var(--chip);border-radius:8px;padding:2px}
.seg span{padding:6px 16px;border-radius:6px;color:var(--muted-2);white-space:nowrap}
.seg span.on{background:#fff;color:var(--accent);box-shadow:0 1px 2px rgba(28,26,23,.12)}
.seg.p span{padding:4px 11px;font-size:12px}
.barre .etat{margin-left:auto;font-size:12px;color:var(--muted)}
/* La route de l'API qui sert un bloc */
.api{font:10.5px/1.5 ui-monospace,Menlo,monospace;text-transform:none;letter-spacing:0;color:var(--muted-2);background:var(--table-head);border:1px solid var(--line);border-radius:4px;padding:0 5px;white-space:nowrap}
.src{margin:6px 14px 0;font-size:11.5px;color:var(--muted);display:flex;flex-wrap:wrap;gap:6px;align-items:center}
.gap{display:flex;gap:8px;align-items:baseline;background:var(--prize-soft);color:var(--prize-ink);border-radius:8px;padding:7px 10px;font-size:11.5px;line-height:1.45}
.gap>span{flex:0 0 auto;font-size:9.5px;letter-spacing:.07em;text-transform:uppercase;border:1px solid rgba(122,82,7,.35);border-radius:4px;padding:0 5px}
/* Listes groupées */
.g{margin-bottom:20px}
.g>h3{display:flex;align-items:center;gap:8px;margin:0 0 7px 14px;font-size:11px;letter-spacing:.07em;text-transform:uppercase;color:var(--muted-2)}
.liste{background:#fff;border:1px solid var(--line);border-radius:12px;overflow:hidden}
.r{display:grid;grid-template-columns:30px minmax(0,1fr) auto;gap:12px;align-items:center;padding:10px 16px;border-top:1px solid var(--row-line)}
.r:first-child{border-top:none}
.r .ic{width:28px;height:28px;border-radius:7px;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px}
.r .ic.gris{background:var(--chip);color:var(--muted-2)}
.r .ic.ill2{background:var(--abricot-d);padding:3px}
.r .ph{line-height:1.9}
.r .ph small{display:block;line-height:1.45;font-size:11.5px;color:var(--muted)}
.r .ph small .api{font-size:10px}
.r .dr{text-align:right;font-size:11.5px;color:var(--muted);white-space:nowrap}
.v{display:inline-block;border-radius:6px;background:rgba(118,118,128,.09);padding:0 7px;color:var(--accent);font-variant-numeric:tabular-nums;line-height:1.7}
.v.t{color:var(--ink)}
.jours{display:inline-flex;gap:2px;vertical-align:1px}
.jours i{font-style:normal;font-size:9px;width:15px;height:15px;border-radius:3px;display:inline-flex;align-items:center;justify-content:center;background:var(--chip);color:var(--faint)}
.jours i.on{background:var(--accent);color:#fff}
.tog{display:inline-block;position:relative;width:38px;height:22px;border-radius:999px;background:var(--chip);vertical-align:middle}
.tog::after{content:'';position:absolute;top:3px;left:3px;width:16px;height:16px;border-radius:999px;background:#fff;box-shadow:0 1px 2px rgba(28,26,23,.25)}
.tog.on{background:var(--prize)}.tog.on::after{left:19px}
.ok{color:var(--green)}.att{color:var(--prize-ink)}
/* Cartes de la colonne de droite */
.card{background:#fff;border:1px solid var(--line);border-radius:12px;padding:14px 16px;margin-bottom:14px}
.card>h3{font-size:11px;letter-spacing:.07em;text-transform:uppercase;color:var(--muted-2);margin:0 0 10px;display:flex;justify-content:space-between;align-items:center;gap:8px}
.kv{width:100%;border-collapse:collapse;font-size:12px;font-variant-numeric:tabular-nums}
.kv td{padding:5px 0;border-top:1px solid var(--row-line);color:var(--muted-2);vertical-align:top}
.kv tr:first-child td{border-top:none}
.kv td+td{text-align:right;padding-left:10px;color:var(--ink)}
.btn{display:inline-flex;align-items:center;justify-content:center;border-radius:6px;padding:8px 14px;font-size:13px;border:1px solid var(--line);background:#fff;color:var(--muted-2)}
.btn.p{background:var(--accent);border-color:var(--accent);color:#fff}
.kpi b{font-weight:700;font-size:23px;line-height:1.1;display:block;font-variant-numeric:tabular-nums}
/* Le téléphone (aperçu de l'app du client) */
.tel{width:300px;border:9px solid var(--ink);border-radius:40px;background:var(--paper);overflow:hidden;height:690px;position:relative;flex:0 0 auto}
.tel .bar{display:flex;justify-content:space-between;padding:10px 22px 4px;font-size:11px;color:var(--ink)}
.tel .sc{padding:6px 14px 14px}
.tel .tt{font-size:17px;margin:4px 2px 10px;display:flex;justify-content:space-between;align-items:baseline}
.tel .tt small{font-size:11px;color:var(--muted)}
.tel .cap{font-size:9.5px;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin:12px 2px 6px}
.pc{background:var(--accent);color:#fff;border-radius:16px;padding:14px 14px 12px}
.pc .h{display:flex;justify-content:space-between;font-size:9.5px;letter-spacing:.12em;text-transform:uppercase;opacity:.85}
.pc .n{font-size:30px;line-height:1;margin:12px 0 2px;font-variant-numeric:tabular-nums}
.pc .n small{font-size:12px;opacity:.85}
.pc .j{height:5px;border-radius:3px;background:rgba(255,255,255,.25);margin:9px 0 7px;position:relative}
.pc .j i{position:absolute;left:0;top:0;bottom:0;border-radius:3px;background:var(--abricot)}
.pc .m{font-size:11px;opacity:.95}
.blc{background:#fff;border:1px solid var(--line);border-radius:12px;padding:10px 12px;margin-top:8px;font-size:11.5px}
.blc .l{display:flex;justify-content:space-between;align-items:center;gap:8px}
.dots{display:inline-flex;gap:3px;margin-top:5px}
.dots i{width:9px;height:9px;border-radius:999px;border:1.2px solid var(--accent)}
.dots i.on{background:var(--accent)}
/* La grille du bingo (la carte de Sam) */
.bingo{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));border:1.4px solid var(--accent);border-radius:6px;overflow:hidden;background:#fff}
.bc{position:relative;min-height:56px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;box-shadow:inset -1px -1px 0 var(--accent);padding:4px 3px;text-align:center}
.bc .w{width:28px;height:28px}
.bc span{font-size:7.5px;line-height:1.1;color:var(--ink)}
.bc em{position:absolute;top:2px;left:4px;font-style:normal;font-size:7px;color:var(--muted)}
.bc.f{background:var(--abricot-d)}
.bc.b{background:var(--accent)}.bc.b span{color:#fff;font-size:8.5px}
.bc.b.pris{background:var(--accent-f)}
.bc.coch::after{content:'';position:absolute;top:5px;left:50%;width:36px;height:36px;margin-left:-18px;border:2px solid var(--accent);border-radius:999px;opacity:.8}
.bc.manq{background:repeating-linear-gradient(135deg,#f3efe9 0 5px,#fff 5px 10px)}
.bc.manq .w{opacity:.35}
.bc.cours{box-shadow:inset 0 0 0 2px var(--accent)}
.bc.cours .pg{width:80%;height:4px;border-radius:2px;background:var(--chip);position:relative;margin-top:2px}
.bc.cours .pg i{position:absolute;left:0;top:0;bottom:0;border-radius:2px;background:var(--accent)}
.bc.sup{grid-column:span 3;flex-direction:row;gap:9px;background:var(--accent-f)}
.bc.sup span{color:#fff;font-size:9px;text-align:left}
.bc.sup b{display:block;font:400 14px/1 'Vank','Gotham';color:#fff}
.btit{display:flex;justify-content:space-between;align-items:baseline;margin:2px 2px 8px}
.btit b{font:400 22px/1 'Vank','Gotham';color:var(--accent)}
.btit span{font-size:10px;color:var(--muted)}
`;

const RAIL = `<nav class="rail"><img class="lg" src="${RACINE}/img/logo.png" alt="L’Atelier by"><div class="kick">Pilotage réseau</div>
  <div class="grp"><div class="gl">Pilotage</div><span class="it">Vue générale</span><span class="it">Performance</span></div>
  <div class="grp"><div class="gl">Magasins</div><span class="it">Analyse magasin</span><span class="it">Budget</span><span class="it">Jours creux</span></div>
  <div class="grp"><div class="gl">Marque &amp; marketing</div><span class="it">Campagnes</span><span class="it">Offres et canaux</span></div>
  <div class="grp"><div class="gl">ERP franchisé</div><span class="it">Développement commercial</span><span class="it">Prospection</span><span class="it">Tablette vendeuses</span><span class="it">Newsletter</span><span class="it on">Fidélité</span></div>
  <div class="grp"><div class="gl">Administration</div><span class="it">Paramètres</span><span class="it">Journal &amp; diagnostic</span></div></nav>`;

/** La carte de bingo de Julie, telle que l'app la montre (grille de Sam : trois semaines puis le bon de la ligne). */
function grille(opts) {
  const o = Object.assign({ coch: JULIE.cochees, manq: JULIE.manquees, cours: JULIE.enCours, depense: JULIE.semaine, pris: [3] }, opts || {});
  const c = [];
  SEMAINES.forEach(s => {
    const cl = ['bc', s.fete ? 'f' : '', o.coch.includes(s.n) ? 'coch' : '', o.manq.includes(s.n) ? 'manq' : '', s.n === o.cours ? 'cours' : ''].join(' ');
    const pied = s.n === o.cours ? `<div class="pg"><i style="width:${Math.round(100 * o.depense / SEUIL)}%"></i></div>` : '';
    c.push(`<div class="${cl}"><em>S${s.n}</em><div class="w">${ill(s.ill)}</div><span>${o.manq.includes(s.n) ? 'manquée' : s.titre}</span>${pied}</div>`);
    const b = BONS.find(x => x.k === s.n);
    if (b) { c.push(`<div class="bc b blanc${o.pris.includes(b.k) ? ' pris' : ''}"><div class="w">${ill(b.motif)}</div><span>${eur(b.v)}${o.pris.includes(b.k) ? ' · utilisé' : ''}</span></div>`); }
  });
  c.push(`<div class="bc sup blanc"><div class="w">${ill('cake')}</div><div><span>13 / 13 · ${eur(SUPER)}</span><b>SUPER BON</b></div></div>`);
  return `<div class="bingo">${c.join('')}</div>`;
}

function page(titre, corps, rail) {
  return `<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=1440"><title>${esc(titre)}</title><style>${CSS}</style></head>
<body>${rail === false ? corps : `<div class="app">${RAIL}<main>${corps}</main></div>`}</body></html>`;
}

function ecrire(nom, html) { fs.writeFileSync(path.join(__dirname, nom), html); }

module.exports = { nf, eur, esc, ill, api, gap, SEMAINES, BONS, SUPER, SEUIL, JULIE, grille, page, ecrire };
