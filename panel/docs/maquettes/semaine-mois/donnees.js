/* Maquettes « Semaine et Mois plus lisibles » (16/11/2026) : les données des quatre écrans.
   Chiffres RÉELS de l'API du cockpit pour Atelier by Harmonie - Sombreffe (shop 5), lus le 16/11/2026 :
   la semaine S46 (09 → 15/11, /exploitation/periode?vue=semaine) et le mois d'octobre 2026 (vue=mois).
   D'exemple : les heures (forme de la journée type du dashboard), les produits, les comparaisons N-1 et réseau
   marquées « ex. ». Seuils du réseau : matière 40 %, main-d'œuvre 20 %, frais généraux 13,5 %. */
const SEUILS = { matiere: 40, mo: 20, frais: 13.5 };
const D = {
  semaine: {
    titre: 'Semaine', lib: 'S46 · du 9 au 15 novembre', court: 'S46', avant: 'S45', n1: 'S46 2025',
    objectif: 12516, ca: 13140, caHt: 12357, tvaPct: 6.0, clients: 1172, panier: 11.21,
    matierePct: 43.3, mb: 7009, mbPct: 56.7, invendus: 259, invendusPct: 2.1, invendusPcs: 314,
    mo: 2924, moPct: 23.7, frais: 4363, fraisPct: 35.3, net: -537, netPct: -4.3, pro: 568, proPct: 4.3,
    // [jour, CA TTC, objectif, clients]
    jours: [['lun.', 1780, 1511, 177], ['mar.', 1902, 1663, 178], ['mer.', 1401, 1670, 120], ['jeu.', 1620, 1672, 161], ['ven.', 1521, 1705, 156], ['sam.', 2399, 2110, 206], ['dim.', 2517, 2185, 174]],
    // la frise de navigation : [libellé, CA, % objectif]
    frise: [['S41', 14.1, 103], ['S42', 15.0, 110], ['S43', 11.8, 86], ['S44', 11.1, 82], ['S45', 16.8, 134], ['S46', 13.1, 105], ['S47', 2.8, null]],
    // comparaisons — période d'avant : CA, atteinte et clients réels (S45), le reste ex. ; N-1 : clients réels, le reste ex. ;
    // réseau : moyenne des 4 magasins (CA, panier, marge brute, résultat réels), le reste ex.
    cmp: { avant: { ca: 16800, clients: 1149, panier: 14.62, mbPct: 57.1, moPct: 19.0, fraisPct: 27.6, invendusPct: 1.6, netPct: 9.8, atteinte: 134 },
      n1: { ca: 13610, clients: 1213, panier: 11.22, mbPct: 55.9, moPct: 22.8, fraisPct: 33.0, invendusPct: 2.4, netPct: -2.6, atteinte: null },
      reseau: { ca: 19912, clients: 1349, panier: 14.76, mbPct: 57.8, moPct: 20.6, fraisPct: 21.0, invendusPct: 1.8, netPct: 16.8, atteinte: 105 } },
    rang: { ca: '2e / 4', clients: '2e / 4', panier: '3e / 4', atteinte: '2e / 4' },
    tendance: [1000, 999, 1071, 1000, 1023, 1036, 1040, 1059, 941, 936, 1149, 1172],   // clients des 12 dernières semaines (réel)
    tendanceN1: [1060, 953, 1021, 1021, 1023, 1074, 1074, 1051, 1000, 896, 1159, 1213],
    taches: { faites: 31, sur: 70 }, commandes: 6,
  },
  mois: {
    titre: 'Mois', lib: 'octobre 2026', court: 'oct.', avant: 'sept.', n1: 'oct. 2025',
    objectif: 60480, ca: 57504, caHt: 53985, tvaPct: 6.1, clients: 4442, panier: 12.95,
    matierePct: 43.9, mb: 30267, mbPct: 56.1, invendus: 543, invendusPct: 1.0, invendusPcs: 370,
    mo: 11918, moPct: 22.1, frais: 18700, fraisPct: 34.6, net: -894, netPct: -1.7, pro: 11423, proPct: 19.9,
    jours: [['1', 1905, 1821, 143], ['2', 1318, 1856, 124], ['3', 2373, 2297, 174], ['4', 1937, 2379, 147], ['5', 1584, 1645, 119], ['6', 1845, 1811, 135], ['7', 2380, 1818, 158],
      ['8', 2251, 1821, 119], ['9', 1760, 1856, 151], ['10', 2208, 2297, 196], ['11', 2051, 2379, 162], ['12', 2438, 1645, 137], ['13', 2089, 1811, 165], ['14', 2366, 1818, 143],
      ['15', 2246, 1821, 147], ['16', 1264, 1856, 137], ['17', 2565, 2297, 177], ['18', 2043, 2379, 153], ['19', 1657, 1645, 119], ['20', 1776, 1811, 150], ['21', 1761, 1818, 128],
      ['22', 1357, 1821, 126], ['23', 1477, 1856, 132], ['24', 1835, 2297, 158], ['25', 1905, 2379, 128], ['26', 1330, 1645, 112], ['27', 1351, 1811, 140], ['28', 1137, 1818, 116],
      ['29', 1458, 1821, 124], ['30', 1613, 1856, 125], ['31', 2226, 2297, 197]],
    premierJour: 3,   // le 1er octobre 2026 est un jeudi (0 = lundi)
    frise: [['janv.', 56.3, 93], ['févr.', 43.8, 93], ['mars', 50.0, 93], ['avr.', 51.6, 96], ['mai', 50.1, 83], ['juin', 56.8, 106], ['juil.', 45.2, 96], ['août', 47.9, 89], ['sept.', 55.7, 95], ['oct.', 57.5, 95], ['nov.', 34.7, null]],
    // comparaisons — sept. : CA et atteinte réels ; le reste ex. ; réseau : CA moyen, panier, marge brute réels.
    cmp: { avant: { ca: 55700, clients: 4301, panier: 12.95, mbPct: 56.4, moPct: 21.4, fraisPct: 33.6, invendusPct: 1.2, netPct: 0.4, atteinte: 95 },
      n1: { ca: 54210, clients: 4486, panier: 12.08, mbPct: 55.2, moPct: 23.0, fraisPct: 35.0, invendusPct: 1.4, netPct: -3.9, atteinte: null },
      reseau: { ca: 83075, clients: 5620, panier: 14.78, mbPct: 56.9, moPct: 20.2, fraisPct: 22.5, invendusPct: 1.5, netPct: 12.4, atteinte: 100 } },
    rang: { ca: '3e / 4', clients: '2e / 4', panier: '4e / 4', atteinte: '3e / 4' },
    tendance: [56.3, 43.8, 50.0, 51.6, 50.1, 56.8, 45.2, 47.9, 55.7, 57.5],   // CA des mois de l'année, k€ (réel)
    tendanceN1: [49.2, 41.0, 47.7, 50.3, 52.8, 53.1, 46.0, 45.5, 52.6, 54.2],
    taches: { faites: 402, sur: 680 }, commandes: 41,
  },
};
// La journée type (moyenne par jour ouvert) : [heure, ventes €, marge nette €] — forme du dashboard, ex.
const HEURES = [[6, 35, -4], [7, 130, 45], [8, 209, 85], [9, 178, 62], [10, 355, 160], [11, 321, 137], [12, 331, 152], [13, 162, 75], [14, 127, 51], [15, 104, 46], [16, 102, 44], [17, 97, 41], [18, 20, 2]];
const TOP = [['Cougnou sucre', 1120, 64], ['Baguette Tradition 500 g', 860, 36], ['Croissant', 540, 61]];   // ex. : marge brute €, marge %

/* --- outils ------------------------------------------------------------------------------------------------- */
const P = new URLSearchParams(location.search).get('p') === 'mois' ? 'mois' : 'semaine';
const X = D[P];
const fE = v => (v < 0 ? '− ' : '') + Math.round(Math.abs(v)).toLocaleString('fr-FR').replace(/ /g, ' ') + ' €';
const fK = v => (v / 1000).toLocaleString('fr-FR', { maximumFractionDigits: 1 }) + ' k€';
const fP = (v, d = 1) => (v == null ? '—' : v.toLocaleString('fr-FR', { minimumFractionDigits: d, maximumFractionDigits: d }) + ' %');
const fN = v => Math.round(v).toLocaleString('fr-FR').replace(/ /g, ' ');
const att = () => Math.round(100 * X.ca / X.objectif);
const ecart = (a, b) => b ? Math.round(100 * (a - b) / b) : null;
const sgn = v => v == null ? '—' : (v > 0 ? '+' : v < 0 ? '−' : '') + Math.abs(v) + ' %';
// La couleur d'un jour face à son objectif (la palette du dashboard).
const coulJour = p => p >= 110 ? '#2e7d32' : p >= 100 ? '#43a047' : p >= 90 ? '#c9b98a' : p >= 75 ? '#ef9a3a' : '#e46f2c';
// Le libellé de la frise, la navigation de la période (gardée : c'est le seul accès aux autres semaines).
function frise() {
  return `<div class="sm-frise">${X.frise.map(([l, v, p], i) => `<div class="${i === X.frise.length - 2 ? 'on' : ''}"><span>${l}</span><b>${v.toLocaleString('fr-FR', { maximumFractionDigits: 1 })} k€</b><small>${p == null ? 'en cours' : p + ' %'}</small><i style="background:${p == null ? 'var(--chip)' : coulJour(p)}"></i></div>`).join('')}</div>`;
}
const RAIL_ACTIF = P;
