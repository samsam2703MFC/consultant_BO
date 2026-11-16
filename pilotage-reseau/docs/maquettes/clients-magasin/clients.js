/* Maquette « Clients du magasin » v2 (16/11/2026) : ce que les trois écrans partagent, repris de l'onglet Fidélité du
   dashboard (public/dashboard/dashboard.js : profils, radars, « Comment il achète », carte de fidélité).
   Les niveaux et leurs couleurs sont les profils de départ du cockpit (src/fidelite_profils.php, FP_DEFAUTS) ;
   les axes des radars sont FP_AXES et FP_FAMILLES. */
RAIL[3][1].splice(4, 0, ['clients', 'Clients', 'personnes', 'nouveau']);

// Les niveaux de fidélité : [nom, couleur, condition sur 30 jours].
const NIV = {
  fanbase: ['Fanbase', '#8D1D2C', '3 fois par semaine ou plus'],
  fidele: ['Fidèle', '#B03048', '2 fois par semaine'],
  hebdo: ['Hebdo', '#B0792A', '1 fois par semaine'],
  regulier: ['Régulier', '#3f7a52', '2 à 3 fois par mois'],
  occasionnel: ['Occasionnel', '#1B6FA8', '1 fois par mois'],
  decouverte: ['Découverte', '#9a948b', 'moins d’1 fois par mois']
};
const nivTag = (k, sous) => `<span class="cl-niv"><i style="background:${NIV[k][1]}"></i>${NIV[k][0]}</span>${sous ? `<span class="sub">${sous}</span>` : ''}`;

// Les cartes de fidélité de l'exemple (ERP › Fidélité) : une carte à timbres et une carte à points.
const CARTES = {
  vienno: { nom: 'Carte viennoiseries', coul: '#8D1D2C', timbres: 10, recompense: 'une viennoiserie offerte' },
  points: { nom: 'Points L’Atelier', coul: '#B0792A', parEuro: 1, palier: 150, recompense: '5 € offerts' }
};
const dots = (n, sur, coul) => `<span class="fdc-dots">${Array.from({ length: sur }, (_, i) => `<i style="background:${i < n ? coul : 'var(--color-border-tertiary)'}"></i>`).join('')}</span>`;
// La carte d'un client dans la liste : [carte, progrès] ; null = pas de carte.
function carteCell(c, sansCarte) {
  if (!c) { return sansCarte; }
  const C = CARTES[c[0]];
  if (C.timbres) {
    const pret = c[1] >= C.timbres;
    return `<span class="cl-carte"><i class="fdc-ic" style="background:${C.coul}"></i>${C.nom}</span><span class="sub">${dots(c[1], C.timbres, C.coul)} ${c[1]} / ${C.timbres}${pret ? ' <span class="fdc-pret">récompense prête</span>' : ` · plus que ${C.timbres - c[1]}`}</span>`;
  }
  return `<span class="cl-carte"><i class="fdc-ic" style="background:${C.coul}"></i>${C.nom}</span><span class="sub"><b style="font-weight:400;color:var(--ink)">${c[1]} pts</b> · ${C.palier - c[1]} pts du palier</span>`;
}

// Le radar du dashboard (fdRadar), tel quel.
function radar(series, axes, taille, maxv) {
  const K = Object.keys(axes), n = K.length, cx = taille / 2, R = taille / 2 - 72, mx = maxv || 100;
  const ang = i => -Math.PI / 2 + 2 * Math.PI * i / n;
  const pt = (i, f) => (cx + R * f * Math.cos(ang(i))).toFixed(1) + ',' + (cx + R * f * Math.sin(ang(i))).toFixed(1);
  let h = '';
  [0.25, 0.5, 0.75, 1].forEach(f => { h += `<polygon points="${K.map((k, i) => pt(i, f)).join(' ')}" fill="none" stroke="var(--color-border-tertiary)"/>`; });
  K.forEach((k, i) => {
    const c = Math.cos(ang(i)), sn = Math.sin(ang(i));
    h += `<line x1="${cx}" y1="${cx}" x2="${pt(i, 1).split(',')[0]}" y2="${pt(i, 1).split(',')[1]}" stroke="var(--color-border-tertiary)"/>`;
    h += `<text x="${(cx + (R + 12) * c).toFixed(1)}" y="${(cx + (R + 12) * sn + (sn < -0.5 ? -4 : sn > 0.5 ? 12 : 4)).toFixed(1)}" font-size="11" text-anchor="${Math.abs(c) < 0.3 ? 'middle' : (c > 0 ? 'start' : 'end')}" fill="var(--color-text-muted)">${axes[k]}</text>`;
  });
  series.forEach(x => {
    h += `<polygon points="${K.map((k, i) => pt(i, Math.min(mx, Math.max(2, x.v[k] || 0)) / mx)).join(' ')}" fill="${x.c}" fill-opacity="${x.fond}" stroke="${x.c}" stroke-width="${x.w}" stroke-linejoin="round"/>`;
  });
  return `<svg viewBox="0 0 ${taille} ${taille}" class="fd-radar" role="img">${h}</svg>`;
}
const AXES = { frequence: 'Fréquence', recence: 'Récence', anciennete: 'Ancienneté', panier: 'Panier', diversite: 'Diversité', comptoir: 'Comptoir' };
const FAMILLES = { pain: 'Pain', vienno: 'Viennoiserie', antigaspi: 'Anti-gaspi', tartes: 'Tartes & fêtes', gouter: 'Goûter', sale: 'Salé' };
const legende = (c, lib) => `<div class="fd-leg"><span><i style="background:${c}"></i>${lib}</span><span><i style="background:var(--color-text-muted)"></i>Moyenne du magasin</span></div>`;

// « Comment il achète » (fdCanaux) : [clé, libellé, couleur, fois].
const CANAUX = { commande: ['Commande comptoir', '#8D1D2C'], comptoir: ['Comptoir', '#B0792A'], webshop: ['Webshop', '#1B6FA8'], livraison: ['Livraison bureau', '#3f7a52'] };
function canaux(c, phrase) {
  const tot = Object.values(c).reduce((a, b) => a + b, 0);
  const L = Object.keys(CANAUX).map(k => ({ lib: CANAUX[k][0], coul: CANAUX[k][1], n: c[k] || 0, p: 100 * (c[k] || 0) / tot }));
  return `<div class="db-card fd-can" style="margin-top:0"><div class="fd-can-h"><b>Comment il achète</b><span>${phrase}</span></div>
    <div class="fd-can-b">${L.filter(x => x.n).map(x => `<i style="width:${x.p.toFixed(2)}%;background:${x.coul}"></i>`).join('')}</div>
    <div class="fd-can-l">${L.map(x => `<span><i style="background:${x.coul}"></i>${x.lib} <b>${Math.round(x.p)} %</b><small>${x.n} fois</small></span>`).join('')}</div></div>`;
}

// Un champ de la fiche, modifiable (o.mod : modifié, pas encore enregistré) ou en lecture (o.lu).
function champ(k, v, api, o = {}) {
  const val = o.lu ? `<div class="cl-ro">${v}</div>` : `<div class="cl-in${o.mod ? ' mod' : ''}${o.ta ? ' ta' : ''}">${v}${o.sel ? '<span class="dd">▾</span>' : ''}</div>`;
  return `<div class="cl-c${o.plein ? ' plein' : ''}"><div class="k">${k}${o.mod ? '<span class="cl-modif">modifié · était ' + o.mod + '</span>' : ''}</div>${val}<span class="cl-api">${api}</span></div>`;
}
const carte = (titre, mini, corps, cl) => `<div class="db-card${cl ? ' ' + cl : ''}"><div class="ct"><span class="db-lab">${titre}</span>${mini ? `<span class="db-mini">${mini}</span>` : ''}</div>${corps}</div>`;
