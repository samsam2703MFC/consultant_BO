/* Scouting — reprise après lecture de GeoExplore (geoconsulting.eu/geoexplore).
 *
 * Ce que GeoExplore fait et que notre écran ne fait pas :
 *   1. il PEINT le territoire — une donnée, une rampe de couleurs, une légende
 *      classée avec des bornes chiffrées ; notre carte, elle, pose 4 108
 *      pastilles rouges de même taille : on voit où sont les concurrents, on ne
 *      voit rien du potentiel ;
 *   2. il fait DESSINER la zone — point, cercle, polygone, isochrone — et
 *      recalcule tout dedans ; chez nous la zone est un rayon de 4 km, le même
 *      à Anvers et à Bastogne ;
 *   3. il SORT un rapport paramétrable (PDF, Excel) ; chez nous, un CSV.
 *
 * Trois maquettes, une par point. Les chiffres sont RÉELS :
 *   · fond de carte OpenStreetMap, désaturé ;
 *   · population : grille 1 km² du recensement 2021 (StatBel / Eurostat) déjà
 *     servie avec l'écran ;
 *   · concurrents : les 5 253 positions du cache OSM du serveur, relu le
 *     15/09/2026 (donnees.js — positions seules, ni noms ni adresses) ;
 *   · modèle : les paramètres réellement enregistrés sur le serveur
 *     (/scouting → spend 339 €, passage 15 %, surface 150 m², emprise max 20 %) ;
 *   · Farciennes : la première ligne de ceo_zones telle que l'écran la sort
 *     aujourd'hui (score 100, 20 382 ménages, 3 concurrents, emprise 17,4 %,
 *     1 412 087 € de CA estimé, 9 414 €/m²).
 */
const fs = require('fs'), path = require('path');
const OUT = __dirname;
const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

const ETAT = '4 108 commerces · 565 communes · OpenStreetMap relu le 15/09/2026';
const OEIL = `<svg class="oe" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1.6 12S5.6 5 12 5s10.4 7 10.4 7-4 7-10.4 7S1.6 12 1.6 12z"/><circle cx="12" cy="12" r="2.8"/></svg>`;
const ico = d => `<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">${d}</svg>`;
const I = {
  pin: ico('<path d="M12 21s7-5.6 7-11a7 7 0 1 0-14 0c0 5.4 7 11 7 11z"/><circle cx="12" cy="10" r="2.4"/>'),
  cercle: ico('<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="1.4" fill="currentColor"/>'),
  poly: ico('<path d="M5 9l7-5 7 6-3 9H8z"/>'),
  rect: ico('<rect x="4.5" y="6.5" width="15" height="11" rx="1"/>'),
  iso: ico('<path d="M12 3.5c4 2 6.5 4.6 6.5 8.5S16 18.5 12 20.5 5.5 15.9 5.5 12 8 5.5 12 3.5z"/><circle cx="12" cy="12" r="2"/>'),
  regle: ico('<path d="M3.5 14.5l11-11 5 5-11 11z"/><path d="M7 11l1.8 1.8M10 8l1.8 1.8M13 5l1.8 1.8"/>'),
  gomme: ico('<path d="M8 20h11"/><path d="M15.5 4.5l4 4-9 9-4-4z"/>'),
  print: ico('<path d="M7 9V4h10v5"/><rect x="4" y="9" width="16" height="7" rx="1.5"/><path d="M7 16h10v4H7z"/>')
};

/* ------------------------------------------------------------------ cadre -- */
const BAR = btn => `<div class="bar"><div class="et"><i></i>${esc(ETAT)}</div><span class="sp"></span>${btn}</div>`;
const TABS = on => `<div class="tabs">${
  [['Carte', ''], ['Zones candidates', '6'], ['Concurrents', '1 051'], ['Arrondissements', '43'],
   ['Top 5 par province', ''], ['Mes études', '4']]
    .map(([l, n]) => `<span${l === on ? ' class="on"' : ''}>${esc(l)}${n ? `<em>${n}</em>` : ''}</span>`).join('')}</div>`;

/* ------------------------------------------------------------ A — la carte -- */
const A = `${BAR(`<button>Assistant « où puis-je ouvrir ? »</button><button>Magasins du réseau</button>
  <button class="ac">Comparer</button><button class="pr">Exporter la vue</button>`)}
${TABS('Carte')}
<div class="body">
  <div class="pan g">
    <div class="lb">Ce que la carte montre</div>
    <div class="th">
      <b>Ménages par point de vente<span>maille 1 km²</span></b>
      <i class="on">Ménages accessibles en 4 km ÷ (concurrents + 1)</i>
      <i>Ménages accessibles en 4 km</i>
      <i>Concurrents en 4 km · dont notés 4,3 et plus</i>
      <i>Emprise du modèle · CA estimé · €/m²</i>
      <i>Population du recensement 2021</i>
    </div>
    <div class="lb">Légende — ménages par point, quartiles de la vue</div>
    <div class="lg">
      <div class="r">${OEIL}<span class="sw" style="background:#2f7d32"></span><span class="tx" data-k="l3">—</span><span class="n" data-k="n3">—</span></div>
      <div class="r">${OEIL}<span class="sw" style="background:#7fb076"></span><span class="tx" data-k="l2">—</span><span class="n" data-k="n2">—</span></div>
      <div class="r">${OEIL}<span class="sw" style="background:#bcd6b5"></span><span class="tx" data-k="l1">—</span><span class="n" data-k="n1">—</span></div>
      <div class="r">${OEIL}<span class="sw" style="background:#e9f0e6"></span><span class="tx" data-k="l0">—</span><span class="n" data-k="n0">—</span></div>
      <div class="r">${OEIL}<span class="sw" style="background:#8D1D2C"></span><span class="tx">Commerce concurrent</span><span class="n">5 253</span></div>
    </div>
    <div class="mu" style="margin-bottom:14px">La concurrence est celle qu'OpenStreetMap connaît, dans les neuf secteurs interrogés : une maille verte sans concurrent peut l'être parce que le commerce n'est pas cartographié. La carte le dit au lieu de le taire.</div>
    <div class="lb">Échelle de lecture</div>
    <div class="ech"><span class="on">Maille 1 km²</span><span>Commune</span><span>Arrondissement</span></div>
    <div class="hr"></div>
    <div class="pli"><span>Filtres — note, ménages, rayon, seuil</span><b>+</b></div>
    <div class="pli"><span>Hypothèses du modèle — 13 valeurs</span><b>+</b></div>
    <div class="pli"><span>Calage sur le réseau · Population · Notes Google</span><b>+</b></div>
    <div class="mu">Repliés : ce sont des réglages, pas une lecture. L'écran s'ouvre sur la carte, pas sur la console.</div>
  </div>
  <div class="map">
    <div id="map"></div>
    <div class="mapui">
      <div class="bulle titre"><b>Ménages par point de vente</b>
        <span>Population du recensement 2021 accessible en 4 km, divisée par les commerces déjà installés dans ce même rayon. Peint maille par maille : la couleur est le potentiel, pas la présence.</span></div>
      <div class="bulle etat" data-k="etat">—</div>
    </div>
  </div>
  <div class="pan d">
    <div class="lb">Ce que la carte dit</div>
    <div class="deux">
      <div class="act"><div class="t">Mailles sans aucun concurrent en 4 km</div><div class="n" data-k="sans">—</div><div class="s" data-k="sansPc">—</div></div>
      <div><div class="t">Ménages couverts par la vue</div><div class="n" data-k="hh">—</div><div class="s">recensement 2021</div></div>
    </div>
    <div class="lb">Les meilleures mailles de la vue</div>
    <div class="rang" data-k="rang"></div>
    <div class="mu" style="margin-top:8px">Une ligne par commune : la meilleure maille du territoire de la commune, pas son centre.</div>
    <div class="hr"></div>
    <div class="lb">Et ensuite</div>
    <div class="act">
      <button class="pr">Ouvrir la maille dans l'analyse de zone</button>
      <button>Exporter la carte en PDF (légende comprise)</button>
      <button>Exporter les mailles en Excel</button>
    </div>
  </div>
</div>
<script>
carte({ el: 'map', cadre: [[49.47, 2.55], [51.52, 6.35]], peindre: 'potentiel', apres: function (m) {
  const nf = n => Math.round(n).toLocaleString('fr-BE');
  const q = k => document.querySelector('[data-k="' + k + '"]');
  const b = m.bornes;
  const txt = ['moins de ' + nf(b[0]), nf(b[0]) + ' à ' + nf(b[1]), nf(b[1]) + ' à ' + nf(b[2]), nf(b[2]) + ' et plus'];
  for (let i = 0; i < 4; i++) {
    q('l' + i).textContent = txt[i];
    q('n' + i).textContent = nf(m.classes[i]);
  }
  q('sans').textContent = nf(m.sans);
  q('sansPc').textContent = Math.round(m.sans / m.mailles * 100) + ' % de la vue';
  q('hh').textContent = nf(m.menages);
  q('etat').textContent = nf(m.mailles) + ' mailles peintes · ' + nf(m.menages) + ' ménages · rayon de chalandise 4 km';
  q('rang').innerHTML = m.sommets.map(function (s, i) {
    return '<div class="l"><span class="r">' + (i + 1) + '</span><span class="n">' + s.nom
      + '<em>' + s.arr + ' · ' + nf(s.hh) + ' ménages · ' + s.n + ' conc.</em></span>'
      + '<span class="v">' + nf(s.m) + '<em>par point</em></span></div>';
  }).join('');
} });
</script>`;

/* ------------------------------------------------------------- B — la zone -- */
const B = `${BAR(`<button>Assistant « où puis-je ouvrir ? »</button><button class="ac">Analyse de zone</button>
  <button>Magasins du réseau</button><button class="pr">Enregistrer la zone</button>`)}
${TABS('Carte')}
<div class="body">
  <div class="pan g">
    <div class="lb">La zone d'étude</div>
    <div class="th">
      <b>Isochrone 10 min voiture<span>proposé</span></b>
      <i class="on">Isochrone — 10, 15 ou 20 min, voiture ou à pied</i>
      <i>Cercle — rayon libre</i>
      <i>Polygone dessiné à la main</i>
      <i>Commune · arrondissement · province</i>
    </div>
    <div class="mu" style="margin-bottom:14px">Aujourd'hui la zone est un disque de 4 km, le même partout. À Anvers il tombe dans le port, à Bastogne il s'arrête avant les villages qui font le chiffre. Le temps de trajet, lui, épouse les routes.</div>
    <div class="hr"></div>
    <div class="lb">Ce qu'on additionne dedans</div>
    <div class="lg">
      <div class="r">${OEIL}<span class="tx">Ménages du recensement 2021</span><span class="n" data-k="ehh">—</span></div>
      <div class="r">${OEIL}<span class="tx">Commerces concurrents</span><span class="n" data-k="en">—</span></div>
      <div class="r">${OEIL}<span class="tx">Notes Google des concurrents</span><span class="n">4,3 moy.</span></div>
      <div class="r">${OEIL}<span class="tx">Zoning d'activité et de vente</span><span class="n">2</span></div>
      <div class="r">${OEIL}<span class="tx">Magasins du réseau</span><span class="n">0</span></div>
    </div>
    <div class="hr"></div>
    <div class="lb">Le modèle appliqué</div>
    <div class="kv"><span>Dépense par ménage et par an</span><b>339 €</b></div>
    <div class="kv"><span>Part du passage</span><b>15 %</b></div>
    <div class="kv"><span>Surface nette visée</span><b>150 m²</b></div>
    <div class="kv"><span>Emprise retenue</span><b>17,4 %</b></div>
    <div class="mu" style="margin-top:8px">Valeurs réellement enregistrées sur le serveur, calage réseau compris. L'emprise est tenue constante entre les deux zones : ce qui change ici, c'est la forme de la zone, rien d'autre.</div>
  </div>
  <div class="map">
    <div id="map"></div>
    <div class="mapui">
      <div class="outils">
        <s>${I.pin}</s><s>${I.cercle}</s><s>${I.poly}</s><s>${I.rect}</s>
        <div class="sep"></div><s class="on">${I.iso}</s><s>${I.regle}</s>
        <div class="sep"></div><s>${I.gomme}</s><s>${I.print}</s>
      </div>
      <div class="bulle titre" style="left:64px"><b>Farciennes — isochrone 10 min</b>
        <span>Le trait plein : 10 minutes de voiture. Le pointillé : le rayon de 4 km d'aujourd'hui. Les carrés ambrés : la population du recensement, là où elle vit.</span></div>
      <div class="bulle etat" data-k="etat">—</div>
      <div class="pin v" data-k="pin" style="left:50%;top:50%">Farciennes</div>
    </div>
  </div>
  <div class="pan d">
    <div class="lb">La zone dessinée</div>
    <div class="deux">
      <div><div class="t">Rayon 4 km — la règle actuelle</div><div class="n" data-k="hhC">—</div><div class="s" data-k="nC">—</div></div>
      <div class="act"><div class="t">Isochrone 10 min — proposé</div><div class="n" data-k="hhI">—</div><div class="s" data-k="nI">—</div></div>
    </div>
    <div class="mu" style="margin-bottom:12px">Les deux zones partent du centre de la commune. La ligne Farciennes de ceo_zones, elle, retient le meilleur point du territoire : 20 382 ménages, 3 concurrents.</div>
    <div class="kv"><span>Marché boulangerie — isochrone</span><b data-k="marche">—</b></div>
    <div class="kv"><span>Marché boulangerie — rayon 4 km</span><b data-k="marcheC">—</b></div>
    <div class="kv"><span>Ce que la forme change</span><b data-k="ecart">—</b></div>
    <div class="kv"><span>CA estimé, à emprise égale</span><b class="v" data-k="ca">—</b></div>
    <div class="kv"><span>Rendement sur 150 m²</span><b data-k="m2">—</b></div>
    <div class="mu" style="margin-top:8px">L'emprise est tenue à 17,4 % dans les deux colonnes pour isoler l'effet de la forme. En vrai elle se recalculerait sur la concurrence de la zone : un concurrent de plus la fait descendre, et le CA avec elle. Repère : Halle mesurée rend 5 188 €/m².</div>
    <div class="lb" style="margin:16px 0 7px">Ce que ça vaut, comparé au réseau</div>
    <div class="rang">
      <div class="l"><span class="r">1</span><span class="n">L'Atelier by Halle<em>mesuré 08/2024 · 250 m²</em></span><span class="v">1 296 881 €<em>12 164 mén.</em></span></div>
      <div class="l"><span class="r">2</span><span class="n">L'Atelier by Berlo<em>en exploitation</em></span><span class="v">7 605 148 €<em>marché</em></span></div>
      <div class="l"><span class="r">3</span><span class="n">By Max &amp; Sandra<em>en exploitation</em></span><span class="v">1 086 800 €<em>marché</em></span></div>
    </div>
    <div class="act">
      <button class="pr">Enregistrer comme zone candidate</button>
      <button>Éditer le dossier d'implantation (PDF)</button>
      <button>Exporter la zone en Excel</button>
    </div>
  </div>
</div>
<script>
(function () {
  const c = (window.SC.communes.filter(function (x) { return x[0] === 'Farciennes'; })[0]) || ['Farciennes', 'Charleroi', 'WHT', 50.4306, 4.5433];
  carte({ el: 'map', centre: [c[3], c[4]], zoom: 11.6, peindre: 'population',
    zone: { lat: c[3], lng: c[4], rayon: 4, isochrone: 4.05 },
    apres: function (m, map) {
      const nf = n => Math.round(n).toLocaleString('fr-BE');
      const eur = n => nf(n) + ' €';
      const q = k => document.querySelector('[data-k="' + k + '"]');
      const z = m.zone, SPEND = 339, PASS = 0.15, EMP = 0.174, SURF = 150;
      const ca = h => h * SPEND * EMP / (1 - PASS);
      q('hhC').textContent = nf(z.hhCer); q('nC').textContent = z.nCer + ' concurrents';
      q('hhI').textContent = nf(z.hhIso); q('nI').textContent = z.nIso + ' concurrents';
      q('ehh').textContent = nf(z.hhIso); q('en').textContent = z.nIso;
      q('marche').textContent = eur(z.hhIso * SPEND);
      q('marcheC').textContent = eur(z.hhCer * SPEND);
      q('ca').textContent = eur(ca(z.hhIso));
      q('m2').textContent = eur(ca(z.hhIso) / SURF) + ' / m²';
      const d = (z.hhIso - z.hhCer) * SPEND;
      q('ecart').textContent = (d >= 0 ? '+ ' : '− ') + eur(Math.abs(d)) + ' de marché';
      q('ecart').className = d >= 0 ? 'v' : 'r';
      q('etat').textContent = 'isochrone 10 min · ' + nf(z.hhIso) + ' ménages · ' + z.nIso
        + ' concurrents  —  rayon 4 km · ' + nf(z.hhCer) + ' ménages · ' + z.nCer + ' concurrents';
      const p = map.latLngToContainerPoint(L.latLng(c[3], c[4]));
      q('pin').style.left = p.x + 'px'; q('pin').style.top = (p.y - 12) + 'px';
    } });
})();
</script>`;

/* ---------------------------------------------------------- C — le dossier -- */
const C = `${BAR(`<button>Assistant « où puis-je ouvrir ? »</button><button>Analyse de zone</button>
  <button class="ac">Mes études</button><button class="pr">Partager avec l'équipe</button>`)}
${TABS('Mes études')}
<div class="body">
  <div class="pan g">
    <div class="lb">Mes études</div>
    <div class="rang" style="margin-bottom:12px">
      <div class="l"><span class="r">●</span><span class="n">Farciennes<em>17/09/2026 · brouillon</em></span><span class="v">100<em>score</em></span></div>
      <div class="l"><span class="r">○</span><span class="n">Sambreville<em>16/09/2026 · partagée</em></span><span class="v">96<em>score</em></span></div>
      <div class="l"><span class="r">○</span><span class="n">Tamise<em>16/09/2026 · partagée</em></span><span class="v">93<em>score</em></span></div>
      <div class="l"><span class="r">○</span><span class="n">Dour<em>12/09/2026 · candidate retenue</em></span><span class="v">83<em>score</em></span></div>
    </div>
    <div class="lb">Sections du rapport</div>
    <div class="lg">
      <div class="r">${OEIL}<span class="tx">Carte de situation</span></div>
      <div class="r">${OEIL}<span class="tx">Le marché — ménages, dépense</span></div>
      <div class="r">${OEIL}<span class="tx">La concurrence relevée</span></div>
      <div class="r">${OEIL}<span class="tx">Le modèle et ses hypothèses</span></div>
      <div class="r">${OEIL}<span class="tx">Comparaison au réseau</span></div>
      <div class="r">${OEIL}<span class="tx">Photos et notes de terrain</span></div>
    </div>
    <div class="mu">Le rapport se compose ici et se régénère à chaque changement d'hypothèse : il porte la date et les paramètres qui l'ont produit.</div>
  </div>
  <div class="doss">
    <div class="page">
      <div class="hd">
        <img src="/public/assets/img/logo.png" alt="">
        <div class="t"><b>Farciennes — étude d'implantation</b>
          <span>Arrondissement de Charleroi · Hainaut · éditée le 17 septembre 2026</span></div>
        <div class="sc"><b>100</b><span>SCORE</span></div>
      </div>
      <h3>Situation — rayon de chalandise 4 km</h3>
      <div class="mini"><div id="map"></div></div>
      <h3>L'essentiel</h3>
      <div class="q4">
        <div><div class="t">Ménages accessibles</div><div class="n">20 382</div></div>
        <div><div class="t">Concurrents en 4 km</div><div class="n">3</div></div>
        <div><div class="t">Emprise du modèle</div><div class="n">17,4 %</div></div>
        <div><div class="t">CA annuel estimé</div><div class="n">1 412 087 €</div></div>
      </div>
      <h3>Le marché</h3>
      <table>
        <tr><th>Mesure</th><th>Valeur</th><th>Source</th></tr>
        <tr><td>Population du rayon</td><td class="n">47 082 hab.</td><td>recensement 2021, maille 1 km²</td></tr>
        <tr><td>Ménages (2,31 personnes)</td><td class="n">20 382</td><td>recensement 2021</td></tr>
        <tr><td>Dépense boulangerie par ménage</td><td class="n">339 €/an</td><td>étude GeoConsulting, calée sur le réseau</td></tr>
        <tr><td>Marché boulangerie du rayon</td><td class="n">6 909 498 €</td><td>ménages × dépense</td></tr>
        <tr><td>Emprise retenue</td><td class="n">17,4 %</td><td>20 % ÷ (1 + 0,2 × pression)</td></tr>
        <tr><td>CA annuel estimé TTC</td><td class="g">1 412 087 €</td><td>marché × emprise ÷ (1 − 15 % de passage)</td></tr>
        <tr><td>Rendement sur 150 m²</td><td class="n">9 414 €/m²</td><td>Halle mesurée : 5 188 €/m²</td></tr>
      </table>
      <h3>La concurrence relevée en 4 km</h3>
      <table>
        <tr><th>Commerce</th><th>Distance</th><th>Note</th><th>Force retenue</th></tr>
        <tr><td>Boulangerie — Farciennes centre</td><td>0,9 km</td><td class="n">4,1</td><td>62 %</td></tr>
        <tr><td>Boulangerie — Aiseau-Presles</td><td>2,7 km</td><td class="n">4,4</td><td>81 %</td></tr>
        <tr><td>Pâtisserie — Châtelet</td><td>3,6 km</td><td class="n">—</td><td>50 %</td></tr>
      </table>
      <h3>Comparaison au réseau</h3>
      <table>
        <tr><th>Point de vente</th><th>Ménages</th><th>Dépense</th><th>Emprise</th><th>CA</th></tr>
        <tr><td class="n">Farciennes — projet</td><td>20 382</td><td>339 €</td><td>17,4 %</td><td class="g">1 412 087 €</td></tr>
        <tr><td>L'Atelier by Halle — mesuré 08/2024</td><td>12 164</td><td>586 €</td><td>15,5 %</td><td>1 296 881 €</td></tr>
        <tr><td>L'Atelier by Berlo</td><td>13 821</td><td>550 €</td><td>—</td><td>—</td></tr>
        <tr><td>L'Atelier by Max &amp; Sandra</td><td>2 613</td><td>416 €</td><td>—</td><td>—</td></tr>
      </table>
      <div class="src">Sources — commerces et communes : OpenStreetMap, cache du serveur relu le 15/09/2026 · population : grille 1 km² du recensement 2021 (StatBel, diffusion Eurostat) · dépense par ménage, emprise et surface : étude GeoConsulting (Halle, 28/08/2024), dépense calée sur les magasins du réseau · notes : Google Places.
        <br>Hypothèses du modèle au moment de l'édition : dépense 339 €, passage 15 %, emprise maximale 20 %, sensibilité 0,2, ménage 2,31 personnes, rayon 4 km, surface 150 m².</div>
    </div>
  </div>
  <div class="pan d">
    <div class="lb">Éditer</div>
    <div class="act" style="margin-top:0">
      <button class="pr">Télécharger le PDF (4 pages)</button>
      <button>Exporter les tableaux en Excel</button>
      <button>Envoyer par courriel</button>
      <button>Copier le lien de l'étude</button>
    </div>
    <div class="hr"></div>
    <div class="lb">Historique</div>
    <div class="rang">
      <div class="l"><span class="r">●</span><span class="n">Dépense calée sur le réseau<em>17/09 · 586 € → 339 €</em></span><span class="v">−42 %<em>CA estimé</em></span></div>
      <div class="l"><span class="r">○</span><span class="n">Surface ramenée à 150 m²<em>16/09</em></span><span class="v">9 414 €<em>/m²</em></span></div>
      <div class="l"><span class="r">○</span><span class="n">Zone créée depuis la carte<em>16/09</em></span><span class="v">100<em>score</em></span></div>
    </div>
    <div class="mu" style="margin-top:10px">Chaque édition garde ses hypothèses : deux rapports de dates différentes restent comparables.</div>
    <div class="hr"></div>
    <div class="lb">Partage</div>
    <div class="pli"><span>Équipe développement — 3 personnes</span><b>✓</b></div>
    <div class="pli"><span>Lien externe (lecture seule)</span><b>+</b></div>
  </div>
</div>
<script>
(function () {
  const c = (window.SC.communes.filter(function (x) { return x[0] === 'Farciennes'; })[0]) || ['Farciennes', 'Charleroi', 'WHT', 50.4306, 4.5433];
  carte({ el: 'map', centre: [c[3], c[4]], zoom: 11.2, peindre: 'population',
    zone: { lat: c[3], lng: c[4], rayon: 4 } });
})();
</script>`;


/* --------------------------------------------------- D — trois lectures --- */
const TRIO = [
  ['marche', 'Le marché', 'Ménages accessibles en 4 km — la population du recensement, là où elle vit, ramenée au rayon de chalandise.', 'ménages'],
  ['concurrence', 'La concurrence', 'Commerces de boulangerie-pâtisserie présents dans ces mêmes 4 km, tels qu’OpenStreetMap les connaît.', 'concurrents'],
  ['ca', 'Ce que le modèle en tire', 'CA annuel estimé : ménages × 339 € × emprise ÷ (1 − 15 %), l’emprise descendant avec la pression concurrentielle.', '€ / an'],
];
const D = `${BAR(`<button>Assistant « où puis-je ouvrir ? »</button><button class="ac">Comparer les lectures</button>
  <button>Magasins du réseau</button><button class="pr">Exporter les trois cartes</button>`)}
${TABS('Carte')}
<div class="body trio">
  ${TRIO.map(([k, t, d], i) => `
  <div class="map${i ? ' bordg' : ''}">
    <div id="map${i}"></div>
    <div class="mapui">
      <div class="bulle titre" style="max-width:none;right:14px"><b>${esc(t)}</b><span>${esc(d)}</span></div>
      <div class="bulle leg" data-k="leg${i}"></div>
    </div>
  </div>`).join('')}
</div>
<script>
window.__attendus = 3;
(function () {
  const nf = n => Math.round(n).toLocaleString('fr-BE');
  const CAD = [[50.18, 3.55], [50.72, 5.15]];
  const RA = { marche: ['#eaf0f4', '#c2d4e0', '#8fb0c6', '#3f6f92'],
               concurrence: ['#f7e9ea', '#e3b9bd', '#c77c85', '#8D1D2C'],
               ca: ['#e9f0e6', '#bcd6b5', '#7fb076', '#2f7d32'] };
  const UNITE = { marche: ' ménages', concurrence: '', ca: ' €' };
  [['marche', 0], ['concurrence', 1], ['ca', 2]].forEach(function (x) {
    carte({ el: 'map' + x[1], cadre: CAD, peindre: x[0], apres: function (m) {
      const b = m.bornes, r = RA[x[0]], u = UNITE[x[0]];
      const t = ['moins de ' + nf(b[0]), nf(b[0]) + ' à ' + nf(b[1]), nf(b[1]) + ' à ' + nf(b[2]), nf(b[2]) + ' et plus'];
      document.querySelector('[data-k="leg' + x[1] + '"]').innerHTML =
        [3, 2, 1, 0].map(function (i) {
          return '<div class="l"><span class="sw" style="background:' + r[i] + '"></span>'
            + '<span class="tx">' + t[i] + u + '</span><span class="n">' + nf(m.classes[i]) + '</span></div>';
        }).join('') + '<div class="p">maille 1 km² · ' + nf(m.mailles) + ' mailles peintes</div>';
    } });
  });
})();
</script>`;

/* ------------------------------------------------------ E — les échelles --- */
const ARR_PT = {
  'Alost': 1882, 'Anvers': 1404, 'Arlon': 544, 'Ath': 1143, 'Audenarde': 1983, 'Bastogne': 1215,
  'Bruges': 958, 'Bruxelles-Capitale': 1348, 'Charleroi': 2635, 'Courtrai': 1150, 'Dinant': 798,
  'Dixmude': 1025, 'Eeklo': 1788, 'Furnes': 603, 'Gand': 1112, 'Hal-Vilvorde': 2085, 'Hasselt': 1467,
  'Huy': 1452, 'La Louvière': 1603, 'Liège': 1341, 'Louvain': 1308, 'Maaseik': 1267, 'Malines': 1519,
  'Marche-en-Famenne': 785, 'Mons': 1147, 'Namur': 1257, 'Neufchâteau': 826, 'Nivelles': 1504,
  'Ostende': 1491, 'Philippeville': 1082, 'Roulers': 1002, 'Saint-Nicolas': 1820, 'Soignies': 1288,
  'Termonde': 1071, 'Thuin': 1732, 'Tielt': 1195, 'Tongres': 743, 'Tournai': 440, 'Turnhout': 1343,
  'Verviers': 492, 'Virton': 812, 'Waremme': 1552, 'Ypres': 840
};
const ARR_TOP = [
  [1, 'Charleroi', '2 635', '171 253 ménages · 65 commerces'],
  [2, 'Hal-Vilvorde', '2 085', '289 828 ménages · 139 commerces'],
  [3, 'Audenarde', '1 983', '53 545 ménages · 27 commerces'],
  [4, 'Alost', '1 882', '127 989 ménages · 68 commerces'],
  [5, 'Saint-Nicolas', '1 820', '121 944 ménages · 67 commerces'],
  [6, 'Eeklo', '1 788', '37 548 ménages · 21 commerces'],
  [7, 'Thuin', '1 732', '39 842 ménages · 23 commerces'],
];
const ARR_BAS = [
  [43, 'Tournai', '440', '97 717 ménages · 222 commerces'],
  [42, 'Verviers', '492', '125 474 ménages · 255 commerces'],
  [41, 'Arlon', '544', '27 720 ménages · 51 commerces'],
];
const E = `${BAR(`<button>Assistant « où puis-je ouvrir ? »</button><button>Magasins du réseau</button>
  <button class="ac">Comparer 2 arrondissements</button><button class="pr">Exporter la vue</button>`)}
${TABS('Carte')}
<div class="body">
  <div class="pan g">
    <div class="lb">Échelle de lecture</div>
    <div class="ech"><span>Maille 1 km²</span><span>Commune</span><span class="on">Arrondissement</span></div>
    <div class="mu" style="margin-bottom:14px">La même question à trois distances : la maille dit où poser le magasin, l'arrondissement dit où aller chercher.</div>
    <div class="lb">Ce que la carte montre</div>
    <div class="th">
      <b>Ménages par point de vente<span>43 arrondissements</span></b>
      <i class="on">Ménages ÷ commerces de l'arrondissement</i>
      <i>Commerces pour 10 000 habitants</i>
      <i>Part de concurrents notés 4,3 et plus</i>
      <i>Marché boulangerie total</i>
    </div>
    <div class="lb">Légende — quartiles des 43</div>
    <div class="lg" data-k="leg"></div>
    <div class="mu" style="margin-top:8px">Beaucoup de ménages pour peu de commerces : le territoire est sous-servi. Peu de ménages par commerce : il est saturé.</div>
    <div class="hr"></div>
    <div class="pli"><span>Filtres — note, ménages, rayon, seuil</span><b>+</b></div>
    <div class="pli"><span>Hypothèses du modèle — 13 valeurs</span><b>+</b></div>
  </div>
  <div class="map">
    <div id="map"></div>
    <div class="mapui">
      <div class="bulle titre"><b>Ménages par point de vente, par arrondissement</b>
        <span>Les 43 arrondissements administratifs, chacun coloré par le nombre de ménages que se partage un commerce de boulangerie. Le découpage suit la commune la plus proche de chaque maille.</span></div>
      <div class="bulle etat">43 arrondissements · 4 993 400 ménages · 4 108 commerces · médiane 1 257 ménages par point</div>
    </div>
  </div>
  <div class="pan d">
    <div class="lb">Les plus sous-servis</div>
    <div class="rang">
      ${ARR_TOP.map(r => `<div class="l"><span class="r">${r[0]}</span><span class="n">${esc(r[1])}<em>${esc(r[3])}</em></span><span class="v">${esc(r[2])}<em>par point</em></span></div>`).join('')}
    </div>
    <div class="lb" style="margin:16px 0 7px">Les plus saturés</div>
    <div class="rang">
      ${ARR_BAS.map(r => `<div class="l"><span class="r">${r[0]}</span><span class="n">${esc(r[1])}<em>${esc(r[3])}</em></span><span class="v">${esc(r[2])}<em>par point</em></span></div>`).join('')}
    </div>
    <div class="mu" style="margin-top:8px">Le réseau est déjà là où il y a de la place : Gosselies est dans Charleroi (1er), Halle dans Hal-Vilvorde (2e). Berlo, à Liège, est 18e sur 43.</div>
    <div class="hr"></div>
    <div class="lb">Et ensuite</div>
    <div class="act">
      <button class="pr">Descendre à la maille dans Charleroi</button>
      <button>Comparer Charleroi et Hal-Vilvorde</button>
      <button>Exporter les 43 lignes en Excel</button>
    </div>
  </div>
</div>
<script>
carte({ el: 'map', cadre: [[49.47, 2.55], [51.52, 6.35]], peindre: 'arr', valeursArr: ${JSON.stringify(ARR_PT)}, apres: function (m) {
  const nf = n => Math.round(n).toLocaleString('fr-BE');
  const oeil = ${JSON.stringify(OEIL)};
  const r = ['#e9f0e6', '#bcd6b5', '#7fb076', '#2f7d32'], b = m.bornes;
  const t = ['moins de ' + nf(b[0]), nf(b[0]) + ' à ' + nf(b[1]), nf(b[1]) + ' à ' + nf(b[2]), nf(b[2]) + ' et plus'];
  document.querySelector('[data-k="leg"]').innerHTML = [3, 2, 1, 0].map(function (i) {
    return '<div class="r">' + oeil + '<span class="sw" style="background:' + r[i] + '"></span>'
      + '<span class="tx">' + t[i] + ' ménages</span></div>';
  }).join('');
} });
</script>`;

/* --------------------------------------------------------------- écriture -- */
const PAGES = [
  ['a-carte-potentiel.html', 'A — la carte se lit', A,
   'La carte peint le potentiel au lieu de pointer les concurrents : une maille d’un kilomètre carré, la population du recensement accessible en 4 km divisée par les commerces déjà installés, quatre classes et une légende chiffrée. Les 4 108 pastilles rouges deviennent une couche discrète. Les six réglages du panneau gauche se replient : ce sont des boutons de réglage, pas une lecture.'],
  ['b-zone-dessinee.html', 'B — la zone se dessine', B,
   'Une boîte à outils sur la carte — point, cercle, polygone, rectangle, isochrone, mesure — et tout se recalcule dans la zone tracée. Ici l’isochrone de 15 minutes de voiture face au disque de 4 km d’aujourd’hui, à Farciennes : la forme de la zone change le marché, donc le CA estimé. Les deux colonnes sont calculées par la même arithmétique, avec les hypothèses réellement enregistrées.'],
  ['d-trois-lectures.html', 'D — le même territoire, trois lectures', D,
   'Le sélecteur de thème en action : le marché (ménages accessibles en 4 km), la concurrence (les commerces du même rayon) et la synthèse (le CA que le modèle en tire, emprise comprise). Trois cartes de la même fenêtre — Hainaut, Namur, Brabant wallon — qui ne disent pas la même chose : là où le marché est le plus épais, la concurrence l’est aussi, et c’est l’emprise qui tranche.'],
  ['e-echelle-arrondissement.html', 'E — l’échelle change', E,
   'La même question posée à l’arrondissement : combien de ménages se partagent un commerce. Charleroi en compte 2 635 pour un seul point de vente, Tournai 440. C’est la carte qui dit où aller chercher, avant de descendre à la maille pour dire où poser le magasin.'],
  ['c-dossier.html', 'C — le dossier sort tout seul', C,
   'Ce que l’écran sait déjà calculer, mis en page : une étude d’implantation datée, avec sa carte, son marché, sa concurrence, sa comparaison au réseau et les hypothèses qui l’ont produite — en PDF et en Excel, partageable, versionnée. Aujourd’hui la sortie est un CSV de coordonnées ; un dossier se refait à la main dans un traitement de texte.'],
];
const TETE = titre => `<!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>${esc(titre)}</title>
<link rel="stylesheet" href="/public/assets/ds/global.css">
<link rel="stylesheet" href="/public/assets/vendor/leaflet/leaflet.css">
<link rel="stylesheet" href="scouting2.css">
<script src="/public/assets/vendor/leaflet/leaflet.js"></script>
<script src="donnees.js"></script>
<script src="carte.js"></script>`;

for (const [f, titre, corps] of PAGES) {
  fs.writeFileSync(path.join(OUT, f), `${TETE(titre)}
<style>body{margin:0;background:#F5F1EB;display:flex;justify-content:center;padding:22px 0}</style>
</head><body><div class="app">${corps}</div></body></html>`);
  console.log('écrit', f);
}
fs.writeFileSync(path.join(OUT, 'planche.html'), `${TETE('Scouting — reprise après GeoExplore')}
<style>body{margin:0;background:#F5F1EB}</style>
</head><body><div class="planche">
${PAGES.map(([f, titre, , sous]) => `<div class="col"><p class="tt">${esc(titre)}<em>${esc(sous)}</em></p>
  <img src="${f.replace('.html', '.jpg')}" style="width:1600px;display:block;border-radius:10px"></div>`).join('')}
</div></body></html>`);
console.log('écrit planche.html');
