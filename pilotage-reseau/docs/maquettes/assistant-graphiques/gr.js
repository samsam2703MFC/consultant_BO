/* Les données et le graphique de la maquette : clients par semaine de Sombreffe, S35 → S46, face au N-1 (réels, lus au
   cockpit le 16/11/2026 : /ventes/semaines, la courbe « Les douze dernières semaines »). */
const SEM = ['S35', 'S36', 'S37', 'S38', 'S39', 'S40', 'S41', 'S42', 'S43', 'S44', 'S45', 'S46'];
const CLI = [1000, 999, 1071, 1000, 1023, 1036, 1040, 1059, 941, 936, 1149, 1172];
const N1 = [1060, 953, 1021, 1021, 1023, 1074, 1074, 1051, 1000, 996, 1159, 1213];
const fN = v => Math.round(v).toLocaleString('fr-FR').replace(/ /g, ' ');
/** Barres (cette année) et ligne pointillée (N-1). w × h en px ; mini : sans textes. */
function graphe(w, h, mini, serie, ref, libs, opts = {}) {
  serie = serie || CLI; ref = ref === undefined ? N1 : ref; libs = libs || SEM;
  const pad = mini ? [6, 6, 6, 6] : [18, 10, 26, 34], mn = 0, mx = Math.max(...serie, ...(ref || [0])) * 1.12;
  const W = w - pad[1] - pad[3], H = h - pad[0] - pad[2], n = serie.length, bw = W / n;
  const y = v => pad[0] + H - (v - mn) / (mx - mn) * H, x = i => pad[3] + i * bw;
  let s = '';
  if (!mini) { [0.25, 0.5, 0.75, 1].forEach(f => { const v = mx * f; s += `<line x1="${pad[3]}" x2="${w - pad[1]}" y1="${y(v)}" y2="${y(v)}" stroke="#eee7dd"/><text x="${pad[3] - 6}" y="${y(v) + 4}" font-size="10" text-anchor="end" fill="#9a948b">${fN(v)}</text>`; }); }
  serie.forEach((v, i) => {
    const bas = ref && v < ref[i];
    s += `<rect x="${(x(i) + bw * 0.18).toFixed(1)}" y="${y(v).toFixed(1)}" width="${(bw * 0.64).toFixed(1)}" height="${(pad[0] + H - y(v)).toFixed(1)}" rx="${mini ? 1.5 : 3}" fill="${opts.couleur || (bas ? '#c98a92' : '#8D1D2C')}"/>`;
    if (!mini) { s += `<text x="${(x(i) + bw / 2).toFixed(1)}" y="${(y(v) - 5).toFixed(1)}" font-size="10.5" text-anchor="middle" fill="#3a3631">${fN(v)}</text><text x="${(x(i) + bw / 2).toFixed(1)}" y="${h - 8}" font-size="10.5" text-anchor="middle" fill="#9a948b">${libs[i]}</text>`; }
  });
  if (ref) { s += `<polyline points="${ref.map((v, i) => (x(i) + bw / 2).toFixed(1) + ',' + y(v).toFixed(1)).join(' ')}" fill="none" stroke="#7d776f" stroke-width="${mini ? 1.2 : 1.6}" stroke-dasharray="4 3"/>`;
    if (!mini) { s += ref.map((v, i) => `<circle cx="${(x(i) + bw / 2).toFixed(1)}" cy="${y(v).toFixed(1)}" r="2.6" fill="#fff" stroke="#7d776f" stroke-width="1.4"/>`).join(''); } }
  return `<svg width="100%" viewBox="0 0 ${w} ${h}" preserveAspectRatio="none" style="display:block">${s}</svg>`;
}
const AVATAR = '/public/dashboard/avatars/pierre.svg';
function tete(mode) {
  const M = [['bilan', 'Bilan'], ['conv', 'Conversation'], ['modeles', 'Mes modèles', true], ['bureau', 'Demande au bureau']];
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
