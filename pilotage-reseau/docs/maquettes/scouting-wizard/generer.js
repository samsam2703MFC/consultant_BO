/* Scouting — assistant « où puis-je ouvrir, et pour combien ».
 * Quatre questions, une réponse : les points chauds sur la carte.
 * Le fond est une capture RÉELLE de l'écran en production ; l'assistant est
 * dessiné par-dessus. Chiffres d'illustration. */
const fs = require('fs'), path = require('path');
const OUT = __dirname;
const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const nb = n => Math.round(n).toLocaleString('fr-BE');

const PAS = [['La zone', 'province'], ['L’arrondissement', 'où précisément'], ['La concurrence', 'qui compte, qui non'], ['Le chiffre visé', 'CA et hypothèses']];
function stepper(i) {
  return `<div class="wz-pas">${PAS.map((p, k) => `<div class="${k === i ? 'on' : (k < i ? 'fait' : '')}"><b>${k < i ? '✓' : k + 1}</b>${p[0]}<small>${p[1]}</small></div>`).join('')}</div>`;
}
const PROV = [
  ['Bruxelles', [['Bruxelles-Capitale', 393, 178000]]],
  ['Flandre', [['Anvers', 567, 811000], ['Brabant flamand', 304, 486000], ['Flandre-Occidentale', 508, 540000], ['Flandre-Orientale', 483, 675000], ['Limbourg', 329, 384000]]],
  ['Wallonie', [['Brabant wallon', 118, 165000], ['Hainaut', 523, 578000], ['Liège', 512, 492000], ['Luxembourg', 165, 120000], ['Namur', 195, 216000]]],
];
const CHOISIES = ['Brabant wallon', 'Hainaut'];
// Triés par « ménages / point de vente » décroissant : c'est la colonne qui
// répond à la question de l'étape, donc celle qui ordonne le tableau.
const ARR = [
  ['25', 'Nivelles', 27, 165000, 118, 12, 1398, 4.1, true],
  ['55', 'Soignies', 9, 78400, 61, 7, 1285, 3.9, false],
  ['56', 'Thuin', 10, 64400, 51, 5, 1263, 3.9, false],
  ['51', 'Ath', 10, 51200, 42, 4, 1219, 4.2, false],
  ['58', 'La Louvière', 4, 52300, 44, 5, 1189, 3.8, false],
  ['57', 'Tournai', 10, 66800, 58, 6, 1152, 4.0, false],
  ['53', 'Mons', 9, 108900, 96, 11, 1134, 4.0, false],
  ['52', 'Charleroi', 14, 182600, 171, 23, 1068, 3.7, false],
];

/* --- 1 & 2 : où ---------------------------------------------------------- */
const E1 = `<div class="wz">
  <span class="wz-x">✕</span>
  <div class="wz-hd"><h2>Où puis-je ouvrir, et pour combien ?</h2>
    <p>Quatre questions. À la fin, la carte ne montre plus que les emplacements qui tiennent vos conditions.</p></div>
  ${stepper(0)}
  <div class="wz-corps">
    <div class="wz-q">Dans quelles provinces cherchez-vous ?</div>
    <p class="wz-a">Le chiffre est le nombre de boulangeries et pâtisseries relevées. Vous pourrez resserrer sur un arrondissement à l’étape suivante.</p>
    ${PROV.map(([reg, L]) => `<div class="wz-reg">${reg}</div><div class="wz-prov">${L.map(([n, c, h]) => `
      <div class="wz-p${CHOISIES.includes(n) ? ' on' : ''}"><span class="ck"></span><div class="n">${esc(n)}</div><div class="s">${nb(c)} commerces<br>${nb(h)} ménages</div></div>`).join('')}</div>`).join('')}
  </div>
  <div class="wz-pd"><span class="t"><b>2 provinces</b> retenues · 641 commerces · 743 000 ménages</span><span class="sp"></span>
    <button class="wz-b">Annuler</button><button class="wz-b pri">Continuer →</button></div></div>`;

const E2 = `<div class="wz">
  <span class="wz-x">✕</span>
  <div class="wz-hd"><h2>Où puis-je ouvrir, et pour combien ?</h2>
    <p>Brabant wallon et Hainaut · 641 commerces relevés</p></div>
  ${stepper(1)}
  <div class="wz-corps">
    <div class="wz-q">Quel arrondissement ?</div>
    <p class="wz-a">« Ménages par point de vente » dit où l’offre est la moins dense : plus le chiffre est haut, plus il reste de la place. C’est la colonne qui trie.</p>
    <table class="wz-t">
      <tr><th style="width:26px"></th><th>Arrondissement</th><th>Communes</th><th>Ménages</th><th>Commerces</th><th>dont forts</th><th>Ménages / point de vente</th><th>Note moy.</th></tr>
      ${ARR.map(([c, n, co, hh, sh, st, per, avg, on]) => `<tr class="${on ? 'on' : ''}">
        <td><span class="rad"></span></td><td>${esc(n)}</td><td>${nb(co)}</td><td>${nb(hh)}</td><td>${nb(sh)}</td><td>${nb(st)}</td>
        <td><span class="bar" style="width:${Math.round((per - 1000) / 4.5)}px"></span>${nb(per)}</td><td>${avg.toFixed(1)}</td></tr>`).join('')}
    </table>
  </div>
  <div class="wz-pd"><span class="t"><b>Nivelles</b> · 27 communes · 165 000 ménages · 118 commerces, dont 12 forts</span><span class="sp"></span>
    <button class="wz-b">← Retour</button><button class="wz-b pri">Continuer →</button></div></div>`;

/* --- 3 : la concurrence -------------------------------------------------- */
const NOTES = [['0 – 2,5', 2, 'ign'], ['2,5 – 3,0', 4, 'ign'], ['3,0 – 3,5', 5, ''], ['3,5 – 4,0', 9, ''], ['4,0 – 4,3', 11, 'mid'], ['4,3 – 4,5', 4, 'fort'], ['4,5 – 4,7', 3, 'fort'], ['4,7 – 5', 3, 'fort']];
const mx = Math.max(...NOTES.map(x => x[1]));
const E3 = `<div class="wz">
  <span class="wz-x">✕</span>
  <div class="wz-hd"><h2>Où puis-je ouvrir, et pour combien ?</h2><p>Nivelles · 118 commerces, 41 notés</p></div>
  ${stepper(2)}
  <div class="wz-corps">
    <div class="wz-q">Qu’est-ce qu’un vrai concurrent, pour vous ?</div>
    <p class="wz-a">Deux notes suffisent à le dire. En dessous de la première, le commerce est ignoré ; au-dessus de la seconde, il interdit une implantation autour de lui. Entre les deux, il pèse à proportion de sa note.</p>
    <div class="wz-seuil">
      <div class="wz-s faible"><div class="k">En dessous, ce n’est pas un concurrent</div>
        <div class="v"><input value="3,0"><em>★ sur 5</em></div>
        <div class="s">Un commerce noté sous cette barre ne compte ni dans la pression concurrentielle ni dans les zones rouges. <b>6 commerces</b> sortent ainsi du calcul.</div></div>
      <div class="wz-s fort"><div class="k">Au-dessus, c’est un concurrent fort</div>
        <div class="v"><input value="4,3"><em>★ sur 5</em></div>
        <div class="s">Zone rouge de <b>3,0 km</b> autour de lui : aucune implantation n’y est proposée, et son poids est majoré de moitié. <b>10 commerces</b> sont dans ce cas.</div></div>
    </div>
    <div class="wz-q" style="margin-top:4px">Où ces deux barres coupent</div>
    <p class="wz-a">Les 41 commerces notés de Nivelles, par tranche de note.</p>
    <div class="wz-dist">
      ${NOTES.map(([l, v, c]) => `<div><em>${v}</em><i class="${c}" style="height:${Math.round(58 * v / mx)}px"></i><span>${l}</span></div>`).join('')}
      <div class="wz-coupe" style="left:24.2%"><span>3,0 ★</span></div>
      <div class="wz-coupe f" style="left:61.8%"><span>4,3 ★ — fort</span></div>
    </div>
    <div class="wz-cnt"><span class="ign">6 ignorés</span><span>25 comptés à proportion</span><span class="fort">10 forts → 10 zones rouges</span><span>77 sans note</span><span class="ok">reste 19 % du territoire libre</span></div>
  </div>
  <div class="wz-pd"><span class="t">Les deux barres sont enregistrées dans les hypothèses : <b>elles servent aussi à l’écran Scouting</b> hors assistant.</span><span class="sp"></span>
    <button class="wz-b">← Retour</button><button class="wz-b pri">Continuer →</button></div></div>`;

/* --- 4 : le chiffre visé ------------------------------------------------- */
const E4 = `<div class="wz">
  <span class="wz-x">✕</span>
  <div class="wz-hd"><h2>Où puis-je ouvrir, et pour combien ?</h2><p>Nivelles · concurrent dès 3,0 ★, fort à partir de 4,3 ★</p></div>
  ${stepper(3)}
  <div class="wz-corps">
    <div class="wz-q">Quel chiffre d’affaires visez-vous ?</div>
    <p class="wz-a">L’assistant ne retiendra que les emplacements dont le CA théorique atteint ce montant, au modèle de l’étude Halle.</p>
    <div class="wz-ca">
      <div class="big"><div class="k">CA annuel TTC visé</div><input value="900 000 €"></div>
      <div class="eq">CA = ménages du rayon × dépense par ménage × emprise ÷ (1 − passage)<br>
        À ce montant et à une emprise de 15 %, il faut environ <b>8 700 ménages</b> dans un rayon de 3 km,<br>
        soit une commune de <b>20 000 habitants</b> ou deux communes voisines.</div>
    </div>
    <div class="wz-q" style="margin-top:6px">Les hypothèses du modèle</div>
    <p class="wz-a">Elles viennent de l’étude GeoConsulting (Halle, août 2024) et restent modifiables ici comme dans l’écran.</p>
    <div class="wz-hyp">
      <label><span class="k">Dépense boulangerie par ménage</span><input value="586 €/an"></label>
      <label><span class="k">Part du passage</span><input value="15 %"></label>
      <label><span class="k">Emprise maximale</span><input value="30 %"></label>
      <label><span class="k">Surface nette cible</span><input value="250 m²"></label>
    </div>
  </div>
  <div class="wz-pd"><span class="t">Sur Nivelles, <b>7 emplacements</b> atteignent 900 000 € — le meilleur à 1 128 000 €.</span><span class="sp"></span>
    <button class="wz-b">← Retour</button><button class="wz-b pri">Voir les points chauds →</button></div></div>`;

/* --- résultat : les points chauds ---------------------------------------- */
const HOT = [
  { n: 1, x: 52, y: 54, d: 104, lab: 'bas',  nom: 'Tubize', ca: '1 128 000 €', hh: '11 400 ménages', c: '3 concurrents', em: '16,2 %', sc: 86 },
  { n: 2, x: 40, y: 66, d: 96,  lab: 'bas',  nom: 'Braine-le-Comte', ca: '1 042 000 €', hh: '10 600 ménages', c: '2 concurrents', em: '16,8 %', sc: 84 },
  { n: 3, x: 62, y: 62, d: 92,  lab: 'bas',  nom: 'Nivelles nord', ca: '986 000 €', hh: '9 900 ménages', c: '4 concurrents', em: '15,1 %', sc: 81 },
  { n: 4, x: 70, y: 74, d: 86,  lab: 'bas',  nom: 'Genappe', ca: '934 000 €', hh: '9 200 ménages', c: '2 concurrents', em: '17,3 %', sc: 78 },
  { n: 5, x: 32, y: 54, d: 84,  lab: 'haut', nom: 'Rebecq', ca: '912 000 €', hh: '9 050 ménages', c: '1 concurrent', em: '18,0 %', sc: 77 },
  { n: 6, x: 47, y: 42, d: 82,  lab: 'haut', nom: 'Ittre', ca: '903 000 €', hh: '8 800 ménages', c: '3 concurrents', em: '16,0 %', sc: 74 },
  { n: 7, x: 63, y: 46, d: 78,  lab: 'haut', nom: 'Waterloo sud', ca: '897 000 €', hh: '8 700 ménages', c: '5 concurrents', em: '13,9 %', sc: 71 },
];
// La carte occupe, en pixels de scène (1600 × 950), la bande x 549→1248,
// y 98→912. Mesuré sur la capture, ramené à l'échelle de la scène.
const MX = 549, MY = 98, MW = 699, MH = 814;
const RES = `
<div class="wz-rappel">
  <span class="t">7 points chauds</span>
  <span class="ch"><b>Nivelles</b></span>
  <span class="ch">concurrent <b>3,0 ★</b> · fort <b>4,3 ★</b></span>
  <span class="ch">rayon <b>3 km</b></span>
  <span class="ch">CA ≥ <b>900 000 €</b></span>
  <span class="sp"></span>
  <span class="ch" style="background:#f0f7f2;color:#2d7a3e">897 000 € → 1 128 000 €</span>
  <button class="wz-b" style="padding:4px 11px">Modifier</button></div>
${HOT.map(h => `<div class="wz-hot" style="left:${Math.round(MX + MW * h.x / 100)}px;top:${Math.round(MY + MH * h.y / 100)}px;--d:${h.d}px">
  <i></i><b>${h.n}</b><u class="${h.lab}">${esc(h.nom)} <small>${h.ca}</small></u></div>`).join('')}
<div class="wz-res">
  <div class="h"><span class="k">Les 7 emplacements retenus</span><span class="sp"></span><a href="#">Exporter</a></div>
  ${HOT.map(h => `<div class="r"><span class="n">${h.n}</span>
    <span class="t"><b>${esc(h.nom)}</b><small>${h.hh} · ${h.c} · emprise ${h.em}</small></span>
    <span class="v"><b>${h.ca}</b><small>score ${h.sc}</small></span></div>`).join('')}
  <div class="f">Le halo vert est le rayon de 3 km évalué. Cliquer un point ouvre sa fiche d’implantation ; « Exporter » écrit les sept dans <b>ceo_zones</b>.</div></div>`;

const PAGES = [
  ['1-ou.html', 'Assistant · 1 et 2 — où chercher',
    'L’assistant s’ouvre par-dessus l’écran, qui reste visible derrière. Deux premières questions : les provinces, puis l’arrondissement. Le tableau des arrondissements trie par « ménages par point de vente », le seul chiffre qui dit où il reste de la place.', [E1, E2]],
  ['2-concurrence-ca.html', 'Assistant · 3 et 4 — qui compte, et pour combien',
    'Deux notes définissent la concurrence : en dessous de la première le commerce est ignoré, au-dessus de la seconde il interdit une implantation autour de lui. Les deux sont modifiables et la distribution montre où elles coupent. Ensuite, le CA visé, avec les hypothèses qui le produisent.', [E3, E4]],
  ['3-points-chauds.html', 'Assistant · résultat — les points chauds sur la carte',
    'L’assistant se referme en un bandeau qui rappelle les quatre réponses et se rouvre d’un clic. La carte ne garde que les emplacements qui tiennent les conditions : un halo pour le rayon évalué, un numéro, le CA. La liste de droite les classe par chiffre d’affaires.', [RES]],
];
for (const [f, titre, sous, cartes] of PAGES) {
  const voile = f !== '3-points-chauds.html';
  const scenes = cartes.map(c => `<div class="wz-scene" style="margin-bottom:16px">${voile ? '<div class="wz-voile"></div>' : ''}${c}</div>`).join('');
  const html = `<!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>${titre}</title>
<link rel="stylesheet" href="/public/assets/ds/global.css">
<link rel="stylesheet" href="wizard.css">
</head><body><div class="mq">
<span class="u">maquette · Scouting commercial · /consulant_bo/#/scouting</span>
<h1>${titre}</h1><p class="s">${sous}</p>
${scenes}</div></body></html>`;
  fs.writeFileSync(path.join(OUT, f), html);
  console.log('écrit', f, html.length, 'octets');
}
