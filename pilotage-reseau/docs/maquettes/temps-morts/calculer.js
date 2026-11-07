/* Temps morts au comptoir — le calcul.
 *
 *   node pilotage-reseau/docs/maquettes/temps-morts/calculer.js        (heures.json → data.json)
 *
 * Un TEMPS MORT : un trou d'au moins `seuil` minutes entre deux tickets qui se suivent,
 * pendant l'ouverture. On le compte (nombre par jour) et on le mesure (minutes, et part de
 * chaque heure passée sans client).
 *
 * Ce que l'on sait : le nombre de tickets de chaque heure, jour par jour (panel,
 * hourly-distribution). Ce que l'on ne sait pas : la minute de chaque ticket. On la tire donc
 * au hasard dans son heure — à nombre de tickets connu, c'est la répartition la plus neutre —
 * 500 fois par journée, et l'on garde la moyenne. Les vrais clients arrivent par grappes
 * (une file, puis personne) : les vrais trous sont plus longs que ceux d'un tirage régulier.
 * Le résultat est donc un PLANCHER.
 *
 * L'ouverture d'une journée va de son premier à son dernier ticket : un magasin fermé plus tôt
 * (Gosselies à 16 h depuis le 19/10, la Toussaint) ne compte pas de temps mort après la
 * fermeture, et l'attente avant le premier client du matin n'est pas comptée non plus.
 * Une heure n'est retenue que si le magasin y a vendu plus d'un jour sur deux ce jour de
 * semaine-là, et au moins 2 tickets en moyenne : un ticket isolé à 5 h ou à 21 h n'est pas
 * une ouverture.
 *
 * La première et la dernière heure retenues sont les moins sûres : si le magasin ouvre ou
 * ferme à la demi-heure, le tirage étale leurs tickets sur l'heure entière et gonfle leurs
 * trous. Elles sont marquées (`bords`), pour que l'écran le dise et les écarte des classements.
 */
const fs = require('fs');
const path = require('path');

const SEUILS = [5, 10, 15];          // minutes sans ticket au moins
const SEUIL_DEFAUT = 10;
const TIRAGES = 500;                 // tirages par journée
const PRESENCE_MIN = 0.5;            // une heure est ouverte si vendue plus d'un jour sur deux…
const TICKETS_MIN = 2;               // …et 2 tickets au moins en moyenne

// mulberry32 — le même générateur que bin/seed.php : un même fichier donne un même résultat.
function mulberry32(a) {
  return function () {
    a |= 0; a = (a + 0x6D2B79F5) | 0;
    let t = Math.imul(a ^ (a >>> 15), 1 | a);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

const IN = JSON.parse(fs.readFileSync(path.join(__dirname, 'heures.json'), 'utf8'));
const jourSemaine = d => (new Date(d + 'T12:00:00Z').getUTCDay() + 6) % 7;   // 0 = lundi
const r1 = x => Math.round(x * 10) / 10;

const out = {
  meta: {
    source: IN.source, du: IN.du, au: IN.au, semaines: 8,
    seuils: SEUILS, seuilDefaut: SEUIL_DEFAUT, tirages: TIRAGES,
    calculeLe: new Date().toISOString().slice(0, 10),
    definition: 'un trou d’au moins N minutes entre deux tickets, entre le premier et le dernier ticket du jour',
  },
  magasins: {},
};

for (const [sid, nom] of Object.entries(IN.magasins)) {
  const jours = IN.jours[sid] || {};
  const dates = Object.keys(jours).sort();

  // Les heures ouvertes, par jour de semaine : vendues plus d'un jour sur deux.
  const pres = {}, somTk = {}, nbJ = {};
  for (const d of dates) {
    const w = jourSemaine(d); nbJ[w] = (nbJ[w] || 0) + 1; pres[w] = pres[w] || {}; somTk[w] = somTk[w] || {};
    for (const [h, [tk]] of Object.entries(jours[d])) if (tk > 0) { pres[w][h] = (pres[w][h] || 0) + 1; somTk[w][h] = (somTk[w][h] || 0) + tk; }
  }
  const ouvertes = {}, bords = {};
  for (const w of Object.keys(nbJ)) {
    ouvertes[w] = Object.keys(pres[w] || {}).map(Number)
      .filter(h => pres[w][h] / nbJ[w] > PRESENCE_MIN && somTk[w][h] / nbJ[w] >= TICKETS_MIN).sort((a, b) => a - b);
    bords[w] = ouvertes[w].length ? [ouvertes[w][0], ouvertes[w][ouvertes[w].length - 1]] : [];
  }

  // Une journée : l'espérance, sur TIRAGES, de chaque mesure.
  const parJour = [];
  for (const d of dates) {
    const w = jourSemaine(d);
    const hs = ouvertes[w];
    const n = hs.map(h => (jours[d][h] || [0])[0]);
    const total = n.reduce((a, b) => a + b, 0);
    if (total < 2) { parJour.push({ d, w, ferme: true }); continue; }
    const rnd = mulberry32(Number(sid) * 100000 + Number(d.replace(/-/g, '')) % 100000);
    const acc = {
      ouvert: 0, ouvertH: {},
      s: Object.fromEntries(SEUILS.map(s => [s, { nb: 0, min: 0, minH: {}, nbH: {} }])),
      plusLong: 0, plusLongH: {},
    };
    const t = new Float64Array(total);
    for (let r = 0; r < TIRAGES; r++) {
      let k = 0;
      hs.forEach((h, i) => { for (let j = 0; j < n[i]; j++) t[k++] = h * 60 + rnd() * 60; });
      t.sort();
      const deb = t[0], fin = t[total - 1];
      acc.ouvert += fin - deb;
      for (const h of hs) {
        const o = Math.max(0, Math.min(fin, h * 60 + 60) - Math.max(deb, h * 60));
        acc.ouvertH[h] = (acc.ouvertH[h] || 0) + o;
      }
      let long = 0, longA = deb;
      for (let i = 0; i + 1 < total; i++) {
        const a = t[i], b = t[i + 1], g = b - a;
        if (g > long) { long = g; longA = a; }
        for (const s of SEUILS) {
          if (g < s) continue;
          const S = acc.s[s];
          S.nb++; S.min += g;
          const hA = Math.floor(a / 60); S.nbH[hA] = (S.nbH[hA] || 0) + 1;
          // Les minutes du trou, réparties sur les heures qu'il traverse.
          for (let h = hA; h * 60 < b; h++) {
            const o = Math.min(b, h * 60 + 60) - Math.max(a, h * 60);
            if (o > 0) S.minH[h] = (S.minH[h] || 0) + o;
          }
        }
      }
      acc.plusLong += long;
      const hL = Math.floor(longA / 60); acc.plusLongH[hL] = (acc.plusLongH[hL] || 0) + 1;
    }
    const div = o => Object.fromEntries(Object.entries(o).map(([k, v]) => [k, v / TIRAGES]));
    parJour.push({
      d, w, tickets: total,
      ouvert: acc.ouvert / TIRAGES, ouvertH: div(acc.ouvertH),
      plusLong: acc.plusLong / TIRAGES, plusLongH: div(acc.plusLongH),
      s: Object.fromEntries(SEUILS.map(s => [s, { nb: acc.s[s].nb / TIRAGES, min: acc.s[s].min / TIRAGES, minH: div(acc.s[s].minH), nbH: div(acc.s[s].nbH) }])),
      tk: Object.fromEntries(hs.map((h, i) => [h, n[i]])),
      ca: Object.fromEntries(hs.map(h => [h, (jours[d][h] || [0, 0])[1]])),
    });
  }

  // Une moyenne sur un groupe de journées (un jour de semaine, ou toute la semaine).
  const agreger = lot => {
    const ouverts = lot.filter(j => !j.ferme);
    const nj = ouverts.length;
    if (!nj) return { jours: 0, fermes: lot.length };
    const heures = [...new Set(ouverts.flatMap(j => Object.keys(j.ouvertH).map(Number)))].sort((a, b) => a - b);
    const somme = f => ouverts.reduce((a, j) => a + f(j), 0);
    const res = {
      jours: nj, fermes: lot.length - nj,
      tickets: r1(somme(j => j.tickets) / nj),
      ouvert: Math.round(somme(j => j.ouvert) / nj),
      plusLong: r1(somme(j => j.plusLong) / nj),
      seuils: {},
      heures: {},
    };
    // L'heure où tombe d'habitude le plus long trou de la journée.
    const pl = {};
    for (const j of ouverts) for (const [h, v] of Object.entries(j.plusLongH)) pl[h] = (pl[h] || 0) + v;
    res.plusLongHeure = Number(Object.entries(pl).sort((a, b) => b[1] - a[1])[0][0]);
    for (const h of heures) {
      const ouv = somme(j => j.ouvertH[h] || 0);
      res.heures[h] = {
        ouvert: r1(ouv / nj),
        tk: r1(somme(j => j.tk[h] || 0) / nj),
        ca: Math.round(somme(j => j.ca[h] || 0) / nj),
      };
    }
    for (const s of SEUILS) {
      const min = somme(j => j.s[s].min), ouv = somme(j => j.ouvert);
      const H = {};
      for (const h of heures) {
        const o = somme(j => j.ouvertH[h] || 0), m = somme(j => j.s[s].minH[h] || 0);
        H[h] = { pct: o > 0 ? r1(100 * m / o) : null, min: r1(m / nj), nb: r1(somme(j => j.s[s].nbH[h] || 0) / nj) };
      }
      // Ce qui tombe dans la première et la dernière heure : la part la moins sûre du total.
      const minBords = somme(j => bords[j.w].reduce((x, h) => x + (j.s[s].minH[h] || 0), 0));
      res.seuils[s] = { nb: r1(somme(j => j.s[s].nb) / nj), min: Math.round(min / nj), pct: r1(100 * min / ouv), minBords: Math.round(minBords / nj), heures: H };
    }
    return res;
  };

  const M = { nom, heuresOuvertes: ouvertes, bords, semaine: agreger(parJour), jours: {} };
  for (let w = 0; w < 7; w++) M.jours[w] = agreger(parJour.filter(j => j.w === w));
  // Les journées une à une, au seuil par défaut : de quoi voir la dispersion.
  M.journees = parJour.map(j => j.ferme ? { d: j.d, ferme: true } : {
    d: j.d, tickets: j.tickets, nb: r1(j.s[SEUIL_DEFAUT].nb), min: Math.round(j.s[SEUIL_DEFAUT].min), ouvert: Math.round(j.ouvert),
  });
  out.magasins[sid] = M;
}

fs.writeFileSync(path.join(__dirname, 'data.json'), JSON.stringify(out));

// Le résumé, pour le LISEZMOI.
const JC = ['lun', 'mar', 'mer', 'jeu', 'ven', 'sam', 'dim'];
for (const s of SEUILS) {
  console.log(`\nSeuil ${s} min — temps morts par jour (minutes mortes)`);
  console.log('magasin     ' + JC.map(j => j.padStart(11)).join('') + '    semaine   % ouvert');
  for (const M of Object.values(out.magasins)) {
    const c = w => { const x = M.jours[w].seuils[s]; return `${x.nb.toFixed(1)} (${x.min})`.padStart(11); };
    const S = M.semaine.seuils[s];
    console.log(M.nom.padEnd(12) + [0, 1, 2, 3, 4, 5, 6].map(c).join('') + `${S.nb.toFixed(1)} (${S.min})`.padStart(11) + `${S.pct.toFixed(1)} %`.padStart(11));
  }
}
console.log(`\nSeuil ${SEUIL_DEFAUT} min — part de chaque heure sans client, moyenne de la semaine`);
for (const M of Object.values(out.magasins)) {
  const H = M.semaine.seuils[SEUIL_DEFAUT].heures;
  console.log(M.nom.padEnd(12) + Object.entries(H).map(([h, x]) => `${h}h ${x.pct == null ? '—' : x.pct.toFixed(0) + '%'}`).join('  '));
}
