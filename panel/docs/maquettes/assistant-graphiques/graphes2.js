/* Les autres graphiques de l'assistant (17/11/2026, « proposer d'autres graphiques ») — mêmes règles que gr.js :
   couleurs validées (COUL), une échelle, marques fines, graduations rondes, étiquettes choisies, texte à l'encre,
   valeur au survol (<title>). */
const MOIS_C = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
const moisLib = k => MOIS_C[+k.slice(5, 7) - 1];
const fE = v => (v < 0 ? '− ' : '') + fN(Math.abs(v)) + ' €';
const fD = (v, d = 1) => v.toLocaleString('fr-FR', { minimumFractionDigits: d, maximumFractionDigits: d });
/** Des graduations rondes entre min et max (une ligne n'a pas besoin de partir de zéro). */
function grad2(min, max, n = 4) {
  const brut = (max - min) / n, p = Math.pow(10, Math.floor(Math.log10(brut))), m = brut / p;
  const pas = (m <= 1 ? 1 : m <= 2 ? 2 : m <= 2.5 ? 2.5 : m <= 5 ? 5 : 10) * p;
  const T = []; for (let v = Math.floor(min / pas) * pas; v <= max + pas * 0.999; v += pas) { T.push(+v.toFixed(6)); }
  return T;
}
const svg = (w, h, s, titre) => `<svg width="100%" viewBox="0 0 ${w} ${h}" style="display:block" role="img" aria-label="${titre || ''}">${s}</svg>`;
const etiq = (x, y, t, anc = 'middle', gras = true, coul) => `<text x="${x.toFixed(1)}" y="${y.toFixed(1)}" font-size="12" text-anchor="${anc}" fill="${coul || COUL.encre}"${gras ? ' font-weight="600"' : ''} stroke="${COUL.fond}" stroke-width="4" stroke-linejoin="round" paint-order="stroke">${t}</text>`;

/** Une ligne dans le temps : 2 px, un voile de 10 %, le point de fin ; le dernier point « en cours » creux.
 *  o : {libs, ref (gris, peut contenir null), min0, unite, fmt, cible:{v, lib}, enCours, mini, etiquettes:[i…]} */
function ligne(w, h, V, o = {}) {
  const libs = o.libs || V.map((_, i) => i), fmt = o.fmt || fN, mini = o.mini;
  const tous = V.concat((o.ref || []).filter(v => v != null), o.cible ? [o.cible.v] : []);
  const T = o.min0 ? graduations(Math.max(...tous) * 1.06) : grad2(Math.min(...tous) * 0.97, Math.max(...tous) * 1.02);
  const lo = T[0], hi = T[T.length - 1], pad = mini ? [8, 8, 8, 8] : [24, 70, 30, 52], n = V.length;
  const W = w - pad[1] - pad[3], H = h - pad[0] - pad[2], x = i => pad[3] + (n === 1 ? W / 2 : i * W / (n - 1)), y = v => pad[0] + H - (v - lo) / (hi - lo) * H;
  let s = '';
  if (!mini) { T.forEach(v => { s += `<line x1="${pad[3]}" x2="${w - pad[1] + 6}" y1="${y(v).toFixed(1)}" y2="${y(v).toFixed(1)}" stroke="${COUL.grille}"/><text x="${pad[3] - 8}" y="${(y(v) + 4).toFixed(1)}" font-size="11" text-anchor="end" fill="${COUL.axe}" style="font-variant-numeric:tabular-nums">${fmt(v)}</text>`; });
    libs.forEach((l, i) => { s += `<text x="${x(i).toFixed(1)}" y="${h - 9}" font-size="11" text-anchor="middle" fill="${COUL.axe}">${l}</text>`; }); }
  if (o.cible) { s += `<line x1="${pad[3]}" x2="${w - pad[1]}" y1="${y(o.cible.v).toFixed(1)}" y2="${y(o.cible.v).toFixed(1)}" stroke="${COUL.ref}" stroke-width="1.5"/>${mini ? '' : `<text x="${(w - pad[1] + 8).toFixed(1)}" y="${(y(o.cible.v) + 4).toFixed(1)}" font-size="11" fill="${COUL.doux}">${o.cible.lib}</text>`}`; }
  if (o.ref) {
    const pts = o.ref.map((v, i) => v == null ? null : [x(i), y(v)]).filter(Boolean);
    if (pts.length > 1) { s += `<polyline points="${pts.map(p => p.map(c => c.toFixed(1)).join(',')).join(' ')}" fill="none" stroke="${COUL.ref}" stroke-width="${mini ? 1.5 : 2}" stroke-linejoin="round"/>`; }
    if (!mini) { o.ref.forEach((v, i) => { if (v != null) { s += `<circle cx="${x(i).toFixed(1)}" cy="${y(v).toFixed(1)}" r="4" fill="${COUL.ref}" stroke="${COUL.fond}" stroke-width="2"><title>${libs[i]} l’an passé : ${fmt(v)}</title></circle>`; } }); }
  }
  const fin = o.enCours ? n - 2 : n - 1;
  const trace = V.slice(0, fin + 1).map((v, i) => x(i).toFixed(1) + ',' + y(v).toFixed(1));
  s += `<path d="M${trace.join('L')}L${x(fin).toFixed(1)},${(pad[0] + H).toFixed(1)}L${x(0).toFixed(1)},${(pad[0] + H).toFixed(1)}Z" fill="${o.couleur || COUL.moi}" fill-opacity=".10"/>`;
  s += `<polyline points="${trace.join(' ')}" fill="none" stroke="${o.couleur || COUL.moi}" stroke-width="${mini ? 1.6 : 2}" stroke-linejoin="round" stroke-linecap="round"/>`;
  if (o.enCours) { s += `<line x1="${x(fin).toFixed(1)}" y1="${y(V[fin]).toFixed(1)}" x2="${x(n - 1).toFixed(1)}" y2="${y(V[n - 1]).toFixed(1)}" stroke="${o.couleur || COUL.moi}" stroke-width="${mini ? 1.4 : 2}" stroke-dasharray="3 4" stroke-linecap="round"/>`; }
  V.forEach((v, i) => {
    const creux = o.enCours && i === n - 1;
    if (!mini || i === fin) { s += `<circle cx="${x(i).toFixed(1)}" cy="${y(v).toFixed(1)}" r="${mini ? 3 : 4}" fill="${creux ? COUL.fond : (o.couleur || COUL.moi)}" stroke="${creux ? (o.couleur || COUL.moi) : COUL.fond}" stroke-width="2"><title>${libs[i]} : ${fmt(v)}${creux ? ' (en cours)' : ''}</title></circle>`; }
  });
  // Les étiquettes se placent pour ne rien toucher : le premier point à droite (loin des graduations), le dernier à droite
  // du point (la marge est là pour lui), le point « en cours » dessous (la ligne pointillée arrive par-dessus), les autres au-dessus.
  if (!mini) { (o.etiquettes || [fin]).forEach(i => {
    const t = fmt(V[i]) + (o.enCours && i === n - 1 ? ' · en cours' : ''), px = x(i), py = y(V[i]);
    s += o.enCours && i === n - 1 ? etiq(px + 4, py + 22, t, 'end')
      : i === n - 1 ? etiq(px + 10, py + 4, t, 'start')
      : i === 0 ? etiq(px + 7, py - 10, t, 'start')
      : etiq(px, py - 12, t);
  }); }
  return svg(w, h, s, o.titre);
}
/** Des colonnes de part et d'autre de zéro (une marge) : au-dessus en bleu, en dessous en Ruby. */
function colDiv(w, h, V, o = {}) {
  const libs = o.libs || [], mini = o.mini, fmt = o.fmt || fN;
  const T = grad2(Math.min(0, ...V) - 1, Math.max(...V) * 1.08), lo = T[0], hi = T[T.length - 1];
  const pad = mini ? [6, 6, 6, 6] : [22, 18, 30, 52], n = V.length, W = w - pad[1] - pad[3], H = h - pad[0] - pad[2], bw = W / n;
  const y = v => pad[0] + H - (v - lo) / (hi - lo) * H, cx = i => pad[3] + i * bw + bw / 2, larg = Math.min(mini ? 12 : 24, bw - (mini ? 3 : 10)), z = y(0);
  let s = '';
  if (!mini) { T.forEach(v => { s += `<line x1="${pad[3]}" x2="${w - pad[1]}" y1="${y(v).toFixed(1)}" y2="${y(v).toFixed(1)}" stroke="${v === 0 ? '#cfc8bd' : COUL.grille}"/><text x="${pad[3] - 8}" y="${(y(v) + 4).toFixed(1)}" font-size="11" text-anchor="end" fill="${COUL.axe}">${fmt(v)}</text>`; }); }
  else { s += `<line x1="${pad[3]}" x2="${w - pad[1]}" y1="${z}" y2="${z}" stroke="#d9d2c7"/>`; }
  const imax = V.indexOf(Math.max(...V)), imin = V.indexOf(Math.min(...V));
  V.forEach((v, i) => {
    const haut = v >= 0, y0 = haut ? y(v) : z, hh = Math.abs(y(v) - z), r = Math.min(mini ? 2 : 4, hh, larg / 2), x0 = cx(i) - larg / 2;
    const d = haut ? colonne(x0, y(v), larg, z, mini ? 2 : 4)
      : `M${x0.toFixed(1)},${z.toFixed(1)}V${(z + hh - r).toFixed(1)}Q${x0.toFixed(1)},${(z + hh).toFixed(1)} ${(x0 + r).toFixed(1)},${(z + hh).toFixed(1)}H${(x0 + larg - r).toFixed(1)}Q${(x0 + larg).toFixed(1)},${(z + hh).toFixed(1)} ${(x0 + larg).toFixed(1)},${(z + hh - r).toFixed(1)}V${z.toFixed(1)}Z`;
    s += `<path d="${d}" fill="${haut ? COUL.froid : COUL.moi}"><title>${libs[i] || ''} : ${fmt(v)}</title></path>`;
    if (!mini) { s += `<text x="${cx(i).toFixed(1)}" y="${h - 9}" font-size="11" text-anchor="middle" fill="${COUL.axe}">${libs[i]}</text>`;
      if (i === imax || i === imin) { s += etiq(cx(i), haut ? y(v) - 8 : y(v) + 16, (v > 0 ? '+ ' : '') + fmt(v)); } }
  });
  return svg(w, h, s, o.titre);
}
/** Des barres horizontales rangées, la valeur au bout (HTML, pour que les libellés longs respirent). */
function barresH(rows, o = {}) {
  const mx = Math.max(...rows.map(r => r.v)) * (o.mini ? 1 : 1.12);
  if (o.mini) { return `<div class="bh-mini">${rows.map(r => `<span><i style="width:${(100 * r.v / mx).toFixed(1)}%"></i></span>`).join('')}</div>`; }
  return `<div class="bh">${rows.map(r => `<div class="l" title="${r.titre || ''}"><span class="t">${r.t}</span><span class="b"><i style="width:${(100 * r.v / mx).toFixed(1)}%;background:${r.coul || COUL.moi}"></i><em style="left:calc(${(100 * r.v / mx).toFixed(1)}% + 8px)">${r.lib}</em></span><span class="s">${r.sous || ''}</span></div>`).join('')}</div>`;
}
/** Le radar en vignette. */
function radarMini(w, h, A, B) {
  const n = A.length, cx = w / 2, cy = h / 2, R = Math.min(w, h) / 2 - 6, MX = 32, a = i => -Math.PI / 2 + 2 * Math.PI * i / n;
  const poly = V => V.map((v, i) => (cx + R * Math.min(v, MX) / MX * Math.cos(a(i))).toFixed(1) + ',' + (cy + R * Math.min(v, MX) / MX * Math.sin(a(i))).toFixed(1)).join(' ');
  let s = [16, 32].map(r => `<polygon points="${poly(A.map(() => r))}" fill="none" stroke="${COUL.grille}"/>`).join('');
  s += `<polygon points="${poly(B)}" fill="none" stroke="${COUL.ref}" stroke-width="1.5"/><polygon points="${poly(A)}" fill="${COUL.moi}" fill-opacity=".1" stroke="${COUL.moi}" stroke-width="1.6"/>`;
  return svg(w, h, s);
}
/** La cascade du P&L en vignette : CA, puis chaque poste qui descend, le résultat. */
function cascadeMini(w, h, etapes) {
  const mx = etapes[0], n = etapes.length, bw = (w - 12) / n, y = v => 6 + (h - 12) * (1 - v / mx);
  let s = '', niveau = mx;
  etapes.forEach((v, i) => {
    const x = 6 + i * bw + bw * 0.2, l = bw * 0.6;
    if (i === 0) { s += `<rect x="${x.toFixed(1)}" y="${y(v).toFixed(1)}" width="${l.toFixed(1)}" height="${(h - 6 - y(v)).toFixed(1)}" rx="2" fill="${COUL.moi}"/>`; return; }
    const haut = niveau, bas = niveau - v; niveau = bas;
    s += `<rect x="${x.toFixed(1)}" y="${y(haut).toFixed(1)}" width="${l.toFixed(1)}" height="${Math.max(1, y(Math.max(bas, 0)) - y(haut)).toFixed(1)}" rx="2" fill="${COUL.ref}" fill-opacity=".55"/>`;
  });
  return svg(w, h, s);
}
