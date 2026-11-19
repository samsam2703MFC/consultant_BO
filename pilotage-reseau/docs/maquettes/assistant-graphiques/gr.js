/* Les données et le graphique de la maquette : clients par semaine de Sombreffe, S35 → S46, face au N-1 (réels, lus au
   cockpit le 16/11/2026 : /ventes/semaines, la courbe « Les douze dernières semaines »). */
const SEM = ['S35', 'S36', 'S37', 'S38', 'S39', 'S40', 'S41', 'S42', 'S43', 'S44', 'S45', 'S46'];
const CLI = [1000, 999, 1071, 1000, 1023, 1036, 1040, 1059, 941, 936, 1149, 1172];
const N1 = [1060, 953, 1021, 1021, 1023, 1074, 1074, 1051, 1000, 996, 1159, 1213];
const fN = v => Math.round(v).toLocaleString('fr-FR').replace(/ /g, ' ');
/* Les couleurs des graphiques (17/11/2026, « soigner les graphiques ») — validées par scripts/validate_palette.js du
   skill dataviz, en clair et en sombre : la série du magasin #A82E40 (le Ruby un cran plus clair : #8D1D2C sort de la
   bande de clarté), le pôle froid #1B6FA8 ; la référence (l'an passé, le réseau) en gris, en retrait, jamais en
   couleur de série. Le texte ne prend jamais la couleur d'une série. */
const COUL = { moi: '#A82E40', froid: '#1B6FA8', ref: '#8a847c', bande: '#8a847c', grille: '#ece6dc', axe: '#9a948b', encre: '#2b2724', doux: '#8a847c', fond: '#ffffff' };
/** Des graduations rondes : 0, 400, 800, 1 200… */
function graduations(max, n = 4) {
  const brut = max / n, p = Math.pow(10, Math.floor(Math.log10(brut))), m = brut / p;
  const pas = (m <= 1 ? 1 : m <= 2 ? 2 : m <= 2.5 ? 2.5 : m <= 5 ? 5 : 10) * p;
  const T = []; for (let v = 0; v <= max + pas * 0.001; v += pas) { T.push(v); }
  if (T[T.length - 1] < max) { T.push(T[T.length - 1] + pas); }
  return T;
}
/** Une colonne : 4 px d'arrondi en haut, carrée sur la ligne de base. */
const colonne = (x, y, w, base, r = 4) => { r = Math.min(r, w / 2, Math.max(0, base - y)); return `M${x.toFixed(1)},${base.toFixed(1)}V${(y + r).toFixed(1)}Q${x.toFixed(1)},${y.toFixed(1)} ${(x + r).toFixed(1)},${y.toFixed(1)}H${(x + w - r).toFixed(1)}Q${(x + w).toFixed(1)},${y.toFixed(1)} ${(x + w).toFixed(1)},${(y + r).toFixed(1)}V${base.toFixed(1)}Z`; };
/** Colonnes (le magasin) et ligne grise (la référence : même semaine l'an passé). Une seule échelle. w × h en px réels.
 *  mini : la vignette, sans texte. Les étiquettes : la dernière valeur et le creux seulement ; le reste à l'axe et au survol. */
function graphe(w, h, mini, serie, ref, libs, opts = {}) {
  serie = serie || CLI; ref = ref === undefined ? N1 : ref; libs = libs || SEM;
  const n = serie.length, T = graduations(Math.max(...serie, ...(ref || [0])) * 1.04);
  const pad = mini ? [8, 8, 6, 8] : [24, 92, 30, 46], mx = T[T.length - 1];
  const W = w - pad[1] - pad[3], H = h - pad[0] - pad[2], bw = W / n, base = pad[0] + H;
  const y = v => pad[0] + H - v / mx * H, cx = i => pad[3] + i * bw + bw / 2;
  const larg = Math.min(mini ? 14 : 24, bw - (mini ? 4 : 10));
  let s = '';
  if (!mini) { T.forEach(v => { s += `<line x1="${pad[3]}" x2="${w - pad[1] + 6}" y1="${y(v).toFixed(1)}" y2="${y(v).toFixed(1)}" stroke="${v === 0 ? '#d9d2c7' : COUL.grille}" stroke-width="1"/><text x="${pad[3] - 8}" y="${(y(v) + 4).toFixed(1)}" font-size="11" text-anchor="end" fill="${COUL.axe}" style="font-variant-numeric:tabular-nums">${fN(v)}</text>`; }); }
  else { s += `<line x1="${pad[3]}" x2="${w - pad[1]}" y1="${base}" y2="${base}" stroke="#e2dbd0"/>`; }
  const creux = serie.indexOf(Math.min(...serie)), der = n - 1;
  serie.forEach((v, i) => {
    s += `<path d="${colonne(cx(i) - larg / 2, y(v), larg, base, mini ? 2 : 4)}" fill="${opts.couleur || COUL.moi}"><title>${libs[i]} : ${fN(v)}${ref ? ' · l’an passé ' + fN(ref[i]) : ''}</title></path>`;
    if (!mini) {
      s += `<text x="${cx(i).toFixed(1)}" y="${h - 9}" font-size="11" text-anchor="middle" fill="${COUL.axe}">${libs[i]}</text>`;
      // L'étiquette au-dessus de la colonne ET du point de l'an passé, sur un halo blanc : jamais sous la ligne.
      if ((opts.etiquettes || [der, creux]).includes(i)) { const yy = Math.min(y(v), ref ? y(ref[i]) : y(v)) - 10; s += `<text x="${cx(i).toFixed(1)}" y="${yy.toFixed(1)}" font-size="12" text-anchor="middle" fill="${COUL.encre}" font-weight="600" stroke="${COUL.fond}" stroke-width="4" stroke-linejoin="round" paint-order="stroke">${fN(v)}</text>`; }
    }
  });
  if (ref) {
    s += `<polyline points="${ref.map((v, i) => cx(i).toFixed(1) + ',' + y(v).toFixed(1)).join(' ')}" fill="none" stroke="${COUL.ref}" stroke-width="${mini ? 1.5 : 2}" stroke-linejoin="round" stroke-linecap="round"/>`;
    if (!mini) {
      s += ref.map((v, i) => `<circle cx="${cx(i).toFixed(1)}" cy="${y(v).toFixed(1)}" r="4" fill="${COUL.ref}" stroke="${COUL.fond}" stroke-width="2"><title>${libs[i]} 2025 : ${fN(v)}</title></circle>`).join('');
      s += `<text x="${(cx(der) + 14).toFixed(1)}" y="${(y(ref[der]) + 4).toFixed(1)}" font-size="11.5" fill="${COUL.doux}">${fN(ref[der])} en 2025</text>`;
    }
  }
  return `<svg width="100%" viewBox="0 0 ${w} ${h}" style="display:block" role="img" aria-label="${opts.titre || 'graphique'}">${s}</svg>`;
}
/** La légende : une pastille pour une série en colonnes, un trait pour une ligne, un aplat pour une bande. Texte à l'encre. */
const legende = L => `<div class="gr-leg">${L.map(([t, k, c]) => `<span>${k === 'trait' ? `<i class="k-trait" style="background:${c}"></i>` : k === 'tirets' ? `<i class="k-trait" style="background:repeating-linear-gradient(90deg,${c} 0 3px,transparent 3px 7px)"></i>` : k === 'bande' ? `<i style="background:${c};opacity:.22"></i>` : `<i style="background:${c}"></i>`}${t}</span>`).join('')}</div>`;
const AVATAR = '/public/dashboard/avatars/pierre.svg';
function tete(mode) {
  const M = [['bilan', 'Bilan'], ['conv', 'Conversation'], ['graphiques', 'Graphiques', true], ['modeles', 'Mes modèles', true], ['bureau', 'Demande au bureau']];
  return `<div class="gr-tete"><img src="${AVATAR}" alt=""><div><b>Pierre</b><small>votre assistant · Atelier by Harmonie - Sombreffe</small></div>
    <div class="gr-modes">${M.map(([k, l, n]) => `<span class="${k === mode ? 'on' : ''}">${l}${n ? '<span class="neuf">nouveau</span>' : ''}</span>`).join('')}</div><span class="gr-x">×</span></div>`;
}
function gauche() {
  const G = ['Mes clients par semaine face à l’an passé', 'Le CA heure par heure du samedi', 'Le panier moyen mois par mois', 'Les 10 produits qui rapportent le plus', 'Mes invendus jour par jour'];
  const Q = ['Pourquoi mon résultat est-il négatif ?', 'Que faire cette semaine pour le panier ?', 'Comment va le magasin face au réseau ?'];
  return `<div class="gr-gauche"><div class="lab">Graphiques</div>${G.map((t, i) => `<span class="gr-q gr${i === 0 ? '' : ''}"><i>▥</i>${t}</span>`).join('')}
    <div class="lab" style="margin-top:16px">Questions</div>${Q.map(t => `<span class="gr-q">${t}</span>`).join('')}
    <div class="lab" style="margin-top:16px">Mes modèles</div><span class="gr-q"><i>★</i>CA par semaine face au N-1</span><span class="gr-q"><i>★</i>Clients par heure, samedi</span></div>`;
}
function fond() {
  return `<div class="db-tuiles" style="grid-template-columns:repeat(4,1fr);opacity:.9"><div class="db-tui"><div class="k">Chiffre d’affaires</div><div class="v">34 707 €</div><div class="s">121 % de l’objectif</div></div><div class="db-tui"><div class="k">Clients</div><div class="v">2 583</div><div class="s">161 par jour</div></div><div class="db-tui"><div class="k">Panier</div><div class="v">11,49 €</div><div class="s">réseau 15,46 €</div></div><div class="db-tui"><div class="k">Résultat</div><div class="v">+ 952 €</div><div class="s">2,9 %</div></div></div>
    <div class="db-card" style="height:300px"></div><div class="db-card" style="height:420px;margin-top:12px"></div>`;
}
