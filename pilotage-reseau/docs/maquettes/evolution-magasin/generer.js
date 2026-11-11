/* Maquettes « Résultat › Évolution » — un magasin depuis que le panel a ses ventes :
 * le trafic clients et le chiffre d'affaires, mois après mois (ou semaine après semaine),
 * avec la ligne N-1 dès qu'elle existe.
 *
 *   node docs/maquettes/evolution-magasin/generer.js     → a.html, a-halle.html, b.html, c.html
 *   node docs/maquettes/evolution-magasin/capturer.js    → les PNG
 *
 * Données : donnees.json, lu au panel le 11/11/2026 (/ventes/reseau?vue=annee pour 2024,
 * 2025 et 2026, /ventes/semaines?vue=semaine&n=12 par tranches de douze semaines).
 * Le décor (rail, en-tête, onglets de Résultat) : chrome.html, l'écran Résultat du BO
 * rendu tel quel, l'onglet « Évolution » ajouté, le contenu remplacé ici. */
const fs = require('fs');
const path = require('path');
const D = __dirname;
const DATA = JSON.parse(fs.readFileSync(path.join(D, 'donnees.json'), 'utf8'));
const CHROME = fs.readFileSync(path.join(D, 'chrome.html'), 'utf8');

/* ── formats ─────────────────────────────────────────────────────────── */
const fI = n => Math.round(n || 0).toLocaleString('fr-BE').replace(/ | /g, ' ');
const fK = n => Math.abs(n) >= 1e6 ? (n / 1e6).toFixed(2).replace('.', ',') + ' M€' : (Math.abs(n) >= 10000 ? (n / 1000).toFixed(1).replace('.', ',') + ' k€' : fI(n) + ' €');
const fE2 = n => n.toFixed(2).replace('.', ',') + ' €';
const pc = (a, b) => b ? 100 * (a - b) / b : null;
const sg = v => v == null ? '—' : (v >= 0.5 ? '+' : (v <= -0.5 ? '−' : '±')) + Math.abs(Math.round(v)) + ' %';
const col = v => v == null ? '#B8B2A8' : (v >= 3 ? '#3f7a52' : (v <= -3 ? '#A32014' : '#7A5207'));
const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const MC = ['', 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
const ML = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
const court = nom => (String(nom).match(/-\s*([^-]+)$/) || [, nom])[1].trim();
const jm = k => new Date(+k.slice(0, 4), +k.slice(5, 7), 0).getDate();      // jours du mois
const BX = '#8D1D2C', GRIS = '#B8B2A8', N2 = '#d9d2c8', ft = 'font-family:var(--font-ui)';

/* ── lecture d'un magasin ────────────────────────────────────────────── */
function magasin(id){
  const m = DATA.magasins.find(x => x.id === String(id));
  // Un mois « partiel » : l'ouverture (moins de 75 % des jours vendus) — hors courbe,
  // un cercle vide. Le mois en cours : hors courbe, dans sa tuile, à jours égaux.
  const mois = m.mois.map(x => {
    const nj = jm(x.mois), an = +x.mois.slice(0, 4), mo = +x.mois.slice(5, 7);
    const partiel = !x.enCours && x.jours < 0.75 * nj;
    const n1Partiel = !!(x.n1 && x.n1.jours < 0.75 * nj && !x.enCours);
    const n1Court = !!(x.n1 && !x.enCours && !n1Partiel && x.n1.jours < x.jours - 2);   // N-1 fermé quelques jours
    return Object.assign({}, x, { an, mo, nj, partiel, n1Partiel, n1Court });
  });
  const med = (a => a.sort((p, q) => p - q)[Math.floor(a.length / 2)])(m.semaines.map(x => x.clients));
  const sem0 = m.semaines.slice(m.semaines.findIndex(x => x.clients >= 0.1 * med));   // les tickets d'essai d'avant l'ouverture : hors série
  const sem = sem0.map(x => Object.assign({}, x, { partiel: !x.enCours && x.jours < 5, n1Partiel: !!(x.n1 && (x.n1.jours < 5 || (!x.enCours && x.n1.jours < x.jours - 1))) }));
  return { id: m.id, nom: m.nom, court: court(m.nom), mois, sem };
}
const MAGS = ['2', '3', '5', '4'].map(magasin);

/* Les agrégats d'un magasin : totaux, douze mois glissants face aux douze d'avant, mois en cours. */
function agregats(M){
  const clos = M.mois.filter(x => !x.enCours && !x.partiel);
  const tous = M.mois.filter(x => !x.enCours);
  const cur = M.mois.find(x => x.enCours);
  const sum = (a, f) => a.reduce((s, x) => s + f(x), 0);
  const tot = { clients: sum(M.mois, x => x.clients), ca: sum(M.mois, x => x.ca) };
  const d12 = clos.slice(-12);
  const r12 = { clients: sum(d12, x => x.clients), ca: sum(d12, x => x.ca) };
  const c12 = d12.filter(x => x.n1 && !x.n1Partiel);
  const r12n1 = c12.length === 12 ? { clients: sum(c12, x => x.n1.clients), ca: sum(c12, x => x.n1.ca) } : null;
  const best = clos.reduce((a, b) => b.clients > a.clients ? b : a, clos[0]);
  const bestCa = clos.reduce((a, b) => b.ca > a.ca ? b : a, clos[0]);
  const low = clos.reduce((a, b) => b.clients < a.clients ? b : a, clos[0]);
  const prem = M.mois[0];
  const premN1 = M.mois.find(x => x.n1 && !x.n1Partiel);
  return { clos, tous, cur, tot, d12, r12, r12n1, best, bestCa, low, prem, premN1 };
}

/* ── le graphique temporel : catégories en abscisse, séries en ordonnée ── */
/* o = { cats:[{lab, sep, sepLab, chip, chipCoul, titre}], series:[{vals, coul, dash, w, pts, ptsVides, labels:'tout'|'cles'|false, partiels}],
 *       aire:{n, n1} (le gain en vert, la perte en rouge, entre les deux courbes), barres:{vals, coul, partiels},
 *       W, H, fmt, fmtAxe, yMin0 } */
function graphe(o){
  const W = o.W || 1000, H = o.H || 230, ml = o.ml != null ? o.ml : 52, mr = o.mr != null ? o.mr : 14, mt = o.mt != null ? o.mt : 24;
  const avecChips = o.cats.some(c => c.chip);
  const mb = (o.mb != null ? o.mb : 24) + (avecChips ? 18 : 0);
  const N = o.cats.length;
  const vals = [];
  (o.series || []).forEach(s => s.vals.forEach((v, i) => { if (v != null && !(s.partiels && s.partiels[i])) { vals.push(v); } }));
  if (o.barres) { o.barres.vals.forEach(v => { if (v != null) { vals.push(v); } }); }
  const vmin = Math.min(...vals), vmax = Math.max(...vals), et = (vmax - vmin) || Math.abs(vmax) || 1;
  let lo = o.yMin0 ? Math.min(0, vmin) : vmin - et * 0.25, hi = vmax + et * 0.18;
  if (!o.yMin0 && vmin >= 0 && lo < 0) { lo = 0; }
  // Des graduations rondes.
  const pas = (() => { const r = (hi - lo) / 4, p = Math.pow(10, Math.floor(Math.log10(r))); return [1, 2, 2.5, 5, 10].map(k => k * p).find(k => k >= r); })();
  lo = Math.floor(lo / pas) * pas; hi = Math.ceil(hi / pas) * pas;
  const X = i => ml + (i + 0.5) * (W - ml - mr) / N;
  const Y = v => mt + (hi - Math.max(lo, v)) / (hi - lo || 1) * (H - mt - mb);
  const f1 = v => v.toFixed(1);
  let s = `<svg viewBox="0 0 ${W} ${H}" preserveAspectRatio="xMidYMid meet" style="width:100%;height:auto;display:block">`;
  for (let v = lo; v <= hi + 1e-6; v += pas) {
    s += `<line x1="${ml}" x2="${W - mr}" y1="${f1(Y(v))}" y2="${f1(Y(v))}" stroke="rgba(34,34,34,${v === lo ? '.16' : '.07'})"/>`;
    s += `<text x="${ml - 8}" y="${f1(Y(v) + 3.5)}" font-size="10" text-anchor="end" fill="#8a8378" style="${ft}">${esc((o.fmtAxe || o.fmt)(Math.abs(v) < 1e-9 ? 0 : v))}</text>`;
  }
  if (o.zero && lo < 0 && hi > 0) { s += `<line x1="${ml}" x2="${W - mr}" y1="${f1(Y(0))}" y2="${f1(Y(0))}" stroke="rgba(34,34,34,.45)"/>`; }
  // Les années : un trait, l'année en haut.
  o.cats.forEach((c, i) => { if (c.sep) {
    const x = i === 0 ? ml : (X(i - 1) + X(i)) / 2;
    if (i > 0) { s += `<line x1="${f1(x)}" x2="${f1(x)}" y1="${mt - 14}" y2="${f1(H - mb)}" stroke="rgba(34,34,34,.22)" stroke-dasharray="2 3"/>`; }
    s += `<text x="${f1(x + 5)}" y="${mt - 6}" font-size="11" font-weight="600" fill="#1c1a17" style="${ft}">${esc(c.sepLab)}</text>`;
  } });
  // Le gain et la perte face au N-1, entre les deux courbes.
  if (o.aire) {
    const a = o.aire.n, b = o.aire.n1;
    for (let i = 0; i < N - 1; i++) {
      if ([a[i], a[i + 1], b[i], b[i + 1]].some(v => v == null)) { continue; }
      const d0 = a[i] - b[i], d1 = a[i + 1] - b[i + 1];
      const poly = (x0, x1, pa0, pa1, pb0, pb1, pos) => `<polygon points="${f1(x0)},${f1(Y(pa0))} ${f1(x1)},${f1(Y(pa1))} ${f1(x1)},${f1(Y(pb1))} ${f1(x0)},${f1(Y(pb0))}" fill="${pos ? 'rgba(63,122,82,.16)' : 'rgba(163,32,20,.13)'}"/>`;
      if (d0 * d1 >= 0) { s += poly(X(i), X(i + 1), a[i], a[i + 1], b[i], b[i + 1], d0 + d1 >= 0); }
      else {
        const t = d0 / (d0 - d1), xm = X(i) + t * (X(i + 1) - X(i)), vm = a[i] + t * (a[i + 1] - a[i]);
        s += poly(X(i), xm, a[i], vm, b[i], vm, d0 >= 0) + poly(xm, X(i + 1), vm, a[i + 1], vm, b[i + 1], d1 >= 0);
      }
    }
  }
  // Les barres (semaine par semaine).
  if (o.barres) {
    const bw = Math.max(2, (W - ml - mr) / N * 0.68);
    o.barres.vals.forEach((v, i) => { if (v == null) { return; }
      const part = o.barres.partiels && o.barres.partiels[i];
      s += `<rect x="${f1(X(i) - bw / 2)}" y="${f1(Y(v))}" width="${f1(bw)}" height="${f1(Y(lo) - Y(v))}" rx="1.5" fill="${part ? '#e3c2c7' : (o.barres.coul || '#c9858f')}"><title>${esc((o.cats[i].titre || o.cats[i].lab) + ' · ' + o.fmt(v))}</title></rect>`;
    });
  }
  // Les courbes : coupées là où il manque une valeur.
  (o.series || []).forEach(se => {
    let seg = [];
    const flush = () => { if (seg.length > 1) { s += `<polyline points="${seg.join(' ')}" fill="none" stroke="${se.coul}" stroke-width="${se.w || 2.5}" stroke-linejoin="round" stroke-linecap="round"${se.dash ? ` stroke-dasharray="${se.dash}"` : ''}/>`; } seg = []; };
    se.vals.forEach((v, i) => { if (v == null || (se.partiels && se.partiels[i])) { flush(); } else { seg.push(f1(X(i)) + ',' + f1(Y(v))); } });
    flush();
    if (se.pts) { se.vals.forEach((v, i) => { if (v == null) { return; }
      const vide = se.partiels && se.partiels[i];
      s += `<circle cx="${f1(X(i))}" cy="${f1(Y(v))}" r="${se.r || 3.2}" fill="${vide || se.ptsVides ? '#fff' : se.coul}" stroke="${se.coul}" stroke-width="${vide || se.ptsVides ? 1.6 : 0}"><title>${esc((o.cats[i].titre || o.cats[i].lab) + ' · ' + (se.nom || '') + ' ' + o.fmt(v))}</title></circle>`;
    }); }
    // Les valeurs écrites : toutes, ou les repères (le plus haut, le plus bas, le dernier).
    if (se.labels) {
      const idx = se.vals.map((v, i) => i).filter(i => se.vals[i] != null && !(se.partiels && se.partiels[i]));
      let garde = idx;
      if (se.labels === 'cles' && idx.length) {
        const mx = idx.reduce((a, b) => se.vals[b] > se.vals[a] ? b : a), mn = idx.reduce((a, b) => se.vals[b] < se.vals[a] ? b : a);
        garde = [...new Set([mx, mn, idx[idx.length - 1], idx[0]])];
      }
      garde.forEach(i => {
        const autre = se.contre ? se.contre[i] : null;
        const dessous = se.labelsDessous || (autre != null && se.vals[i] < autre);
        s += `<text x="${f1(X(i))}" y="${f1(Y(se.vals[i]) + (dessous ? 15 : -8))}" font-size="${se.fsz || 10.5}" font-weight="600" text-anchor="middle" fill="${se.coulTxt || se.coul}" style="${ft};paint-order:stroke;stroke:#fff;stroke-width:3px">${esc(o.fmt(se.vals[i]))}</text>`;
      });
    }
    // Les mois partiels : la valeur et le nombre de jours, à côté du cercle vide.
    if (se.partiels) { se.vals.forEach((v, i) => { if (v == null || !se.partiels[i] || !se.notePartiel) { return; }
      s += `<text x="${f1(X(i) + 7)}" y="${f1(Y(v) - 6)}" font-size="9.5" fill="#7a736a" style="${ft}">${esc(se.notePartiel(i))}</text>`; }); }
  });
  // Les abscisses, puis les écarts au N-1 sous chaque mois.
  const pasLab = o.pasLab || 1;
  o.cats.forEach((c, i) => {
    if (c.lab && (i % pasLab === 0 || c.labForce)) { s += `<text x="${f1(X(i))}" y="${f1(H - mb + 15)}" font-size="${o.fszLab || 10}" font-weight="${c.labFort ? 600 : 400}" text-anchor="middle" fill="${c.labCoul || '#3b3731'}" style="${ft}">${esc(c.lab)}</text>`; }
    if (c.chip) { s += `<text x="${f1(X(i))}" y="${f1(H - mb + 32)}" font-size="9.5" font-weight="600" text-anchor="middle" fill="${c.chipCoul}" style="${ft}">${esc(c.chip)}</text>`; }
  });
  if (avecChips && o.chipTitre) { s += `<text x="${ml - 8}" y="${f1(H - mb + 32)}" font-size="9" text-anchor="end" fill="#8a8378" style="${ft}">${esc(o.chipTitre)}</text>`; }
  return s + '</svg>';
}

/* Les barres divergentes : l'écart au N-1 en %, semaine par semaine. */
function divergent(o){
  const W = o.W || 1000, H = o.H || 120, ml = 52, mr = 14, mt = 10, mb = 22, N = o.cats.length;
  const mx = Math.max(10, ...o.vals.filter(v => v != null).map(v => Math.min(40, Math.abs(v)))) * 1.1;
  const X = i => ml + (i + 0.5) * (W - ml - mr) / N, Y = v => mt + (mx - Math.max(-mx, Math.min(mx, v))) / (2 * mx) * (H - mt - mb);
  const bw = Math.max(2, (W - ml - mr) / N * 0.7), f1 = v => v.toFixed(1);
  let s = `<svg viewBox="0 0 ${W} ${H}" style="width:100%;height:auto;display:block">`;
  [-20, 0, 20].filter(v => Math.abs(v) < mx).forEach(v => {
    s += `<line x1="${ml}" x2="${W - mr}" y1="${f1(Y(v))}" y2="${f1(Y(v))}" stroke="rgba(34,34,34,${v === 0 ? '.3' : '.07'})"/><text x="${ml - 8}" y="${f1(Y(v) + 3.5)}" font-size="10" text-anchor="end" fill="#8a8378" style="${ft}">${v > 0 ? '+' : (v < 0 ? '−' : '')}${Math.abs(v)} %</text>`; });
  o.cats.forEach((c, i) => { if (c.sep && i > 0) { const x = (X(i - 1) + X(i)) / 2; s += `<line x1="${f1(x)}" x2="${f1(x)}" y1="${mt}" y2="${H - mb}" stroke="rgba(34,34,34,.22)" stroke-dasharray="2 3"/>`; } });
  o.vals.forEach((v, i) => { if (v == null) { return; }
    const y0 = Y(0), y1 = Y(v);
    s += `<rect x="${f1(X(i) - bw / 2)}" y="${f1(Math.min(y0, y1))}" width="${f1(bw)}" height="${f1(Math.max(1, Math.abs(y1 - y0)))}" fill="${v >= 0 ? '#3f7a52' : '#A32014'}" opacity="${Math.abs(v) > 40 ? .55 : .85}"><title>${esc(o.cats[i].titre + ' · ' + sg(v))}</title></rect>`; });
  // La moyenne glissante de l'écart : la tendance, sans le bruit d'une semaine.
  if (o.moy) { let seg = []; o.moy.forEach((v, i) => { if (v == null) { if (seg.length > 1) { s += `<polyline points="${seg.join(' ')}" fill="none" stroke="#1c1a17" stroke-width="1.8"/>`; } seg = []; } else { seg.push(f1(X(i)) + ',' + f1(Y(v))); } });
    if (seg.length > 1) { s += `<polyline points="${seg.join(' ')}" fill="none" stroke="#1c1a17" stroke-width="1.8"/>`; } }
  o.cats.forEach((c, i) => { if (c.lab) { s += `<text x="${f1(X(i))}" y="${H - 6}" font-size="10" font-weight="${c.labFort ? 600 : 400}" text-anchor="middle" fill="#3b3731" style="${ft}">${esc(c.lab)}</text>`; } });
  return s + '</svg>';
}

/* Une mini-courbe (les cartes des magasins). */
function spark(vals, vals1, W, H){
  const all = vals.concat(vals1).filter(v => v != null), lo = Math.min(...all) * 0.92, hi = Math.max(...all) * 1.04;
  const X = i => 3 + i * (W - 6) / Math.max(1, vals.length - 1), Y = v => 3 + (hi - v) / (hi - lo) * (H - 6);
  const pl = (a, c, w, d) => { let out = '', seg = []; a.forEach((v, i) => { if (v == null) { if (seg.length > 1) { out += `<polyline points="${seg.join(' ')}" fill="none" stroke="${c}" stroke-width="${w}"${d ? ` stroke-dasharray="${d}"` : ''}/>`; } seg = []; } else { seg.push(X(i).toFixed(1) + ',' + Y(v).toFixed(1)); } }); if (seg.length > 1) { out += `<polyline points="${seg.join(' ')}" fill="none" stroke="${c}" stroke-width="${w}"${d ? ` stroke-dasharray="${d}"` : ''}/>`; } return out; };
  return `<svg viewBox="0 0 ${W} ${H}" style="width:100%;height:auto;display:block">${pl(vals1, GRIS, 1.4, '3 3')}${pl(vals, BX, 1.8)}</svg>`;
}

/* ── les pièces communes ─────────────────────────────────────────────── */
const cap = 'font-size:10.5px;font-weight:400;letter-spacing:.05em;text-transform:uppercase;color:var(--color-text-muted)';
const bloc = (titre, corps, sous, style) => `<div class="rm-bloc" style="${style || ''}"><div style="${cap};margin-bottom:8px;display:flex;justify-content:space-between;gap:12px"><span>${titre}</span>${sous ? `<span style="font-weight:400;text-transform:none;letter-spacing:0">${sous}</span>` : ''}</div><div class="rm-card">${corps}</div></div>`;
const tuile = t => `<div class="t ${t.cls || ''}"><div class="k">${esc(t.k)}</div><div class="v">${esc(t.v)}</div><div class="s">${t.s || ''}</div></div>`;
const seg = (opts, on) => `<div class="rr-ong">${opts.map(o => `<button class="${o === on ? 'on' : ''}">${esc(o)}</button>`).join('')}</div>`;
const pill = (t, c) => `<span class="rr-pill" style="background:${c}">${esc(t)}</span>`;
const leg = items => `<div class="rm-leg" style="margin-top:6px">${items.map(i => `<span>${i}</span>`).join('')}</div>`;
const legN = `<i style="background:${BX};border-radius:50%"></i>`;
const legN1 = `<svg width="20" height="9" style="vertical-align:-1px;margin-right:4px"><line x1="0" x2="20" y1="4.5" y2="4.5" stroke="${GRIS}" stroke-width="2" stroke-dasharray="4 3"/></svg>`;
const legAire = `<i style="background:rgba(63,122,82,.35)"></i><i style="background:rgba(163,32,20,.3);margin-left:-2px"></i>`;
const choixMag = (sel) => `<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap"><span style="${cap}">Magasin</span>${seg(MAGS.map(m => m.court), sel.court)}</div>`;
const etiquette = l => `<span style="position:absolute;right:16px;top:-11px;font:600 10px var(--font-ui);letter-spacing:.08em;text-transform:uppercase;background:#222;color:#fff;padding:5px 10px;border-radius:999px;opacity:.85">Maquette ${l}</span>`;
const cadre = (lettre, titre, sousTitre, droite, corps) => `<div class="rr" style="position:relative;background:var(--color-background-secondary);border:1px solid var(--color-border-tertiary);border-radius:12px;padding:14px 16px 18px;display:flex;flex-direction:column;gap:14px">${etiquette(lettre)}
  <div style="display:flex;align-items:baseline;gap:12px;flex-wrap:wrap"><span style="font-family:var(--font-display);font-size:18px">${esc(titre)}</span><span style="font-size:11.5px;color:var(--color-text-muted)">${sousTitre}</span><span style="margin-left:auto;display:flex;gap:10px;align-items:center;flex-wrap:wrap">${droite}</span></div>
  ${corps}</div>`;
const page = (corps, fichier) => {
  fs.writeFileSync(path.join(D, fichier), CHROME.replace('<div id="mq-contenu"></div>', `<div id="mq-contenu">${corps}</div>`)
    .replace('</head>', `<style>/* capture pleine page : le corps défile avec la fenêtre */.workspace{height:auto!important;overflow:visible!important;min-height:100vh}.app__body{overflow:visible!important}</style></head>`));
  console.log('écrit', fichier);
};

/* Le mois en cours, à jours égaux. */
function tuileEnCours(M, A, quoi){
  const c = A.cur; if (!c) { return { k: 'Mois en cours', v: '—', s: '' }; }
  const v = quoi === 'ca' ? c.ca : c.clients, v1 = c.n1 ? (quoi === 'ca' ? c.n1.ca : c.n1.clients) : null, e = v1 ? pc(v, v1) : null;
  return { k: MC[c.mo] + ' en cours · ' + c.jours + ' j', v: quoi === 'ca' ? fK(v) : fI(v),
    s: e == null ? 'pas de N-1 : premier ' + ML[c.mo] : `<b style="color:${col(e)}">${sg(e)}</b> face aux mêmes ${c.jours} jours de ${ML[c.mo]} ${c.an - 1} (${quoi === 'ca' ? fK(v1) : fI(v1)})`, cls: e == null ? '' : (e >= 0 ? 'good' : 'bad') };
}

/* ═══ A — le fil depuis l'ouverture, mois par mois : deux courbes l'une sous l'autre ═══ */
function maquetteA(M, fichier){
  const A = agregats(M);
  const L = M.mois.filter(x => !x.enCours);                     // les mois sur l'axe
  const cats = L.map((x, i) => {
    const ok = x.n1 && !x.n1Partiel && !x.partiel;
    return { lab: MC[x.mo], sep: i === 0 || x.mo === 1, sepLab: String(x.an), titre: ML[x.mo] + ' ' + x.an };
  });
  const chips = quoi => L.map(x => { const ok = x.n1 && !x.n1Partiel && !x.partiel; if (!ok) { return {}; }
    const e = pc(quoi === 'ca' ? x.ca : x.clients, quoi === 'ca' ? x.n1.ca : x.n1.clients); return { chip: sg(e).replace(' %', '') + (x.n1Court ? '*' : ''), chipCoul: col(e) }; });
  const serie = quoi => {
    const g = x => quoi === 'ca' ? x.ca : x.clients;
    const n = L.map(g), n1 = L.map(x => x.n1 && !x.n1Partiel ? (quoi === 'ca' ? x.n1.ca : x.n1.clients) : null);
    const part = L.map(x => x.partiel);
    const fmt = quoi === 'ca' ? fK : fI;
    return graphe({ W: 1000, H: 250, cats: cats.map((c, i) => Object.assign({}, c, chips(quoi)[i])), fmt, fmtAxe: quoi === 'ca' ? (v => Math.round(v / 1000) + ' k€') : fI,
      chipTitre: 'vs N-1',
      aire: { n: n.map((v, i) => part[i] ? null : v), n1 },
      series: [
        { nom: 'N-1', vals: n1, coul: GRIS, dash: '5 4', w: 2, pts: true, ptsVides: true, r: 2.6 },
        { nom: quoi === 'ca' ? 'CA' : 'clients', vals: n, coul: BX, w: 2.6, pts: true, partiels: part, labels: 'cles', contre: n1,
          notePartiel: i => fmt(n[i]) + ' · ' + L[i].jours + ' j' }
      ] });
  };
  const sans = !A.premN1;
  const n1Txt = sans ? `pas encore de N-1 : premières ventes en ${ML[A.prem.mo]} ${A.prem.an} — la ligne N-1 apparaîtra en ${ML[A.prem.mo]} ${A.prem.an + 1}`
    : `la même période un an plus tôt (pointillé) — dès ${ML[A.premN1.mo]} ${A.premN1.an}, premier mois qui a son N-1`;
  const e12c = A.r12n1 ? pc(A.r12.clients, A.r12n1.clients) : null, e12ca = A.r12n1 ? pc(A.r12.ca, A.r12n1.ca) : null;
  const p12 = A.r12.ca / A.r12.clients, p12n1 = A.r12n1 ? A.r12n1.ca / A.r12n1.clients : null;
  const d12lab = A.d12.length ? MC[A.d12[0].mo] + ' ' + String(A.d12[0].an).slice(2) + ' → ' + MC[A.d12[A.d12.length - 1].mo] + ' ' + String(A.d12[A.d12.length - 1].an).slice(2) : '';
  const nClos = L.filter(x => !x.partiel).length;
  const tuiles = [
    { k: 'Depuis', v: MC[A.prem.mo] + ' ' + A.prem.an, s: (A.prem.jours < 0.75 * A.prem.nj ? 'premières ventes, ' + A.prem.jours + ' j · ' : '') + nClos + ' mois pleins + ' + ML[A.cur.mo] + ' en cours' },
    { k: 'Clients depuis', v: fI(A.tot.clients), s: '≈ ' + fI(A.clos.reduce((a, x) => a + x.clients, 0) / A.clos.length) + ' par mois plein' },
    { k: 'CA depuis', v: fK(A.tot.ca), s: 'panier moyen ' + fE2(A.tot.ca / A.tot.clients) },
    A.r12n1 ? { k: '12 mois · clients', v: sg(e12c), s: fI(A.r12.clients) + ' contre ' + fI(A.r12n1.clients) + ' en N-1 · ' + d12lab, cls: e12c >= 0 ? 'good' : 'bad' }
      : { k: '12 mois · clients', v: '—', s: 'il faut 24 mois de ventes · N-1 dès ' + MC[A.prem.mo] + ' ' + (A.prem.an + 1) },
    A.r12n1 ? { k: '12 mois · CA', v: sg(e12ca), s: fK(A.r12.ca) + ' contre ' + fK(A.r12n1.ca), cls: e12ca >= 0 ? 'good' : 'bad' }
      : { k: '12 mois · CA', v: '—', s: fK(A.r12.ca) + ' sur les ' + A.d12.length + ' derniers mois pleins' },
    { k: 'Panier · 12 mois', v: fE2(p12), s: p12n1 ? 'N-1 : ' + fE2(p12n1) + ' · <b style="color:' + col(pc(p12, p12n1)) + '">' + sg(pc(p12, p12n1)) + '</b>' : 'pas de N-1' },
    tuileEnCours(M, A, 'clients')
  ];
  const tuiles3 = quoi => {
    const g = x => quoi === 'ca' ? x.ca : x.clients, fmt = quoi === 'ca' ? fK : fI;
    const avec = L.filter(x => x.n1 && !x.n1Partiel && !x.partiel);
    const dessus = avec.filter(x => g(x) >= (quoi === 'ca' ? x.n1.ca : x.n1.clients));
    const b = quoi === 'ca' ? A.bestCa : A.best;
    const t3 = A.clos.slice(-3), t3n1 = t3.every(x => x.n1 && !x.n1Partiel) ? t3.reduce((a, x) => a + (quoi === 'ca' ? x.n1.ca : x.n1.clients), 0) : null, t3v = t3.reduce((a, x) => a + g(x), 0);
    return [
      t3n1 ? { k: '3 derniers mois vs N-1', v: sg(pc(t3v, t3n1)), s: MC[t3[0].mo] + ' → ' + MC[t3[2].mo] + ' : ' + fmt(t3v) + ' contre ' + fmt(t3n1), cls: pc(t3v, t3n1) >= 0 ? 'good' : 'bad' }
        : { k: '3 derniers mois', v: fmt(t3v), s: 'vs les 3 d’avant : ' + sg(pc(t3v, A.clos.slice(-6, -3).reduce((a, x) => a + g(x), 0))) },
      avec.length ? { k: 'Mois au-dessus du N-1', v: dessus.length + ' / ' + avec.length, s: dessus.length ? dessus.map(x => MC[x.mo] + ' ' + String(x.an).slice(2)).join(', ') : 'aucun', cls: dessus.length * 2 >= avec.length ? 'good' : 'bad' }
        : { k: 'Mois au-dessus du N-1', v: '—', s: 'pas encore de N-1' },
      { k: quoi === 'ca' ? 'Meilleur mois' : 'Meilleur mois', v: fmt(g(b)), s: ML[b.mo] + ' ' + b.an }
    ];
  };
  const blocSerie = (quoi, titre) => bloc(titre, `<div class="rm-s6"><div>${serie(quoi)}
      ${leg([legN + (quoi === 'ca' ? 'chiffre d’affaires du mois' : 'clients du mois (tickets)'), (sans ? '' : legN1) + esc(n1Txt), sans ? '' : legAire + 'gagné / perdu face au N-1', '<span style="display:inline-block;width:9px;height:9px;border:1.6px solid ' + BX + ';border-radius:50%;margin-right:4px;vertical-align:-1px"></span>mois d’ouverture, incomplet : hors courbe'].filter(Boolean))}
    </div><div class="rm-s6t">${tuiles3(quoi).map(tuile).join('')}</div></div>`, sans ? '' : 'sous chaque mois : l’écart au N-1, en %' + (L.some(x => x.n1Court) ? ' · * N-1 avec des jours fermés' : ''), 'margin-top:0');
  // L'année par année : à périmètre comparable (les mois qui ont un N-1).
  const ans = [...new Set(M.mois.map(x => x.an))];
  const lignes = ans.map(an => {
    const ms = M.mois.filter(x => x.an === an), cmp = ms.filter(x => x.n1 && !x.n1Partiel && !x.partiel);
    const c = ms.reduce((a, x) => a + x.clients, 0), ca = ms.reduce((a, x) => a + x.ca, 0);
    const cC = cmp.reduce((a, x) => a + x.clients, 0), caC = cmp.reduce((a, x) => a + x.ca, 0);
    const c1 = cmp.reduce((a, x) => a + x.n1.clients, 0), ca1 = cmp.reduce((a, x) => a + x.n1.ca, 0);
    const cur = ms.find(x => x.enCours);
    const per = MC[ms[0].mo] + ' → ' + (cur ? cur.jours + ' ' + MC[cur.mo] : MC[ms[ms.length - 1].mo]);
    const eC = c1 ? pc(cC, c1) : null, eCa = ca1 ? pc(caC, ca1) : null, eP = c1 ? pc(caC / cC, ca1 / c1) : null;
    return `<tr><td><b>${an}</b> <span style="color:var(--color-text-muted)">${esc(per)}</span></td><td>${fI(c)}</td><td>${eC == null ? '<span style="color:var(--color-text-muted)">pas de N-1</span>' : pill(sg(eC), col(eC))}</td><td>${fK(ca)}</td><td>${eCa == null ? '—' : pill(sg(eCa), col(eCa))}</td><td>${fE2(ca / c)}</td><td>${eP == null ? '—' : pill(sg(eP), col(eP))}</td><td style="color:var(--color-text-muted)">${cmp.length ? cmp.length + ' mois : ' + MC[cmp[0].mo] + ' → ' + (cmp[cmp.length - 1].enCours ? cmp[cmp.length - 1].jours + ' ' : '') + MC[cmp[cmp.length - 1].mo] : '—'}</td></tr>`;
  }).join('');
  const table = bloc('Année par année', `<table class="rr-tb"><tr><th>Année</th><th>Clients</th><th>vs N-1</th><th>CA</th><th>vs N-1</th><th>Panier</th><th>vs N-1</th><th>Comparé sur</th></tr>${lignes}</table>`, 'les écarts ne comptent que les mois qui ont un N-1 — à périmètre comparable', 'margin-top:0');
  const corps = cadre('A', 'Évolution du magasin', esc(M.nom) + ' · depuis ' + ML[A.prem.mo] + ' ' + A.prem.an + ' · mois par mois, face au N-1',
    seg(['Mois', 'Semaine'], 'Mois') + seg(['Total', 'Par jour ouvert'], 'Total'),
    `${choixMag(M)}
    <div class="rm-kp sept" style="margin-bottom:0">${tuiles.map(tuile).join('')}</div>
    ${blocSerie('clients', 'Trafic clients — mois par mois depuis ' + ML[A.prem.mo] + ' ' + A.prem.an)}
    ${blocSerie('ca', 'Chiffre d’affaires du magasin — mois par mois')}
    ${table}`);
  page(corps, fichier);
}

/* ═══ B — une année contre l'autre : janvier à décembre, une courbe par année ═══ */
function maquetteB(M, fichier){
  const A = agregats(M);
  const ans = [...new Set(M.mois.map(x => x.an))];
  const an = ans[ans.length - 1], an1 = an - 1, an2 = an - 2;
  const val = (y, mo, quoi) => { const x = M.mois.find(z => z.an === y && z.mo === mo); if (!x || x.partiel || x.enCours) { return null; } return quoi === 'ca' ? x.ca : x.clients; };
  const cats = Array.from({ length: 12 }, (_, k) => ({ lab: MC[k + 1], titre: ML[k + 1] }));
  const courbe = quoi => {
    const fmt = quoi === 'ca' ? fK : fI;
    const n = cats.map((c, k) => val(an, k + 1, quoi)), n1 = cats.map((c, k) => val(an1, k + 1, quoi)), n2 = cats.map((c, k) => val(an2, k + 1, quoi));
    const chips = cats.map((c, k) => { const e = n[k] != null && n1[k] != null ? pc(n[k], n1[k]) : null; return e == null ? {} : { chip: sg(e), chipCoul: col(e) }; });
    // Le mois en cours : un point vide au rythme de N-1 aux mêmes jours (n1 × écart), dit comme tel.
    const cur = A.cur;
    return graphe({ W: 560, H: 270, ml: 46, cats: cats.map((c, k) => Object.assign({}, c, chips[k], cur && cur.mo === k + 1 ? { labCoul: BX, labFort: true } : {})), fmt, fmtAxe: quoi === 'ca' ? (v => Math.round(v / 1000) + ' k€') : fI, chipTitre: '',
      aire: { n, n1 },
      series: [
        n2.some(v => v != null) ? { nom: String(an2), vals: n2, coul: N2, w: 1.8, dash: '1.5 3.5', pts: false } : null,
        { nom: String(an1), vals: n1, coul: GRIS, dash: '5 4', w: 2, pts: true, ptsVides: true, r: 2.6 },
        { nom: String(an), vals: n, coul: BX, w: 2.6, pts: true, labels: 'tout', contre: n1, fsz: 9.5 }
      ].filter(Boolean) });
  };
  const cumul = quoi => {
    // Le cumul depuis le 1er janvier, mois après mois, les deux années ; le mois en cours à jours égaux.
    let a = 0, b = 0; const n = [], n1 = [];
    for (let k = 1; k <= 12; k++) {
      const x = M.mois.find(z => z.an === an && z.mo === k), y = M.mois.find(z => z.an === an1 && z.mo === k);
      if (x && y && !y.partiel && x.n1) { a += quoi === 'ca' ? x.ca : x.clients; b += quoi === 'ca' ? x.n1.ca : x.n1.clients; n.push(a); n1.push(b); } else { n.push(null); n1.push(null); }
    }
    return { n, n1 };
  };
  const cc = cumul('clients'), cca = cumul('ca');
  const der = arr => arr.filter(v => v != null).pop();
  const eCum = pc(der(cc.n), der(cc.n1)), eCumCa = pc(der(cca.n), der(cca.n1));
  const pan = der(cca.n) / der(cc.n), pan1 = der(cca.n1) / der(cc.n1);
  const dessus = q => { let d = 0, t = 0; for (let k = 1; k <= 12; k++) { const v = val(an, k, q), v1 = val(an1, k, q); if (v != null && v1 != null) { t++; if (v >= v1) { d++; } } } return [d, t]; };
  const [dc, tc] = dessus('clients'), [dca, tca] = dessus('ca');
  const cur = A.cur;
  const auJour = cur ? cur.jours + ' ' + MC[cur.mo] : '';
  const tuiles = [
    { k: an + ' à date vs ' + an1, v: sg(eCum), s: fI(der(cc.n)) + ' clients contre ' + fI(der(cc.n1)) + ' · 1er janv. → ' + auJour, cls: eCum >= 0 ? 'good' : 'bad' },
    { k: 'CA à date vs ' + an1, v: sg(eCumCa), s: fK(der(cca.n)) + ' contre ' + fK(der(cca.n1)), cls: eCumCa >= 0 ? 'good' : 'bad' },
    { k: 'Panier à date', v: fE2(pan), s: an1 + ' : ' + fE2(pan1) + ' · <b style="color:' + col(pc(pan, pan1)) + '">' + sg(pc(pan, pan1)) + '</b>' },
    { k: 'Mois > N-1 · clients', v: dc + ' / ' + tc, s: 'mois de ' + an + ' qui battent ' + an1, cls: dc * 2 >= tc ? 'good' : 'bad' },
    { k: 'Mois > N-1 · CA', v: dca + ' / ' + tca, s: dca > dc ? 'le CA tient mieux que le trafic' : 'mois de ' + an + ' qui battent ' + an1, cls: dca * 2 >= tca ? 'good' : 'bad' },
    tuileEnCours(M, A, 'clients')
  ];
  const legende = `${leg([`<i style="background:${BX};border-radius:50%"></i>${an}`, legN1 + an1 + ' — le N-1', `<svg width="20" height="9" style="vertical-align:-1px;margin-right:4px"><line x1="0" x2="20" y1="4.5" y2="4.5" stroke="${N2}" stroke-width="2" stroke-dasharray="1.5 3.5"/></svg>${an2} (N-2, depuis ${MC[A.prem.mo]})`, legAire + 'gagné / perdu face au N-1'])}`;
  // L'écart cumulé depuis le 1er janvier, en % : deux courbes posées sur le zéro — le trafic et le CA.
  const ecc = cc.n.map((v, k) => v == null ? null : pc(v, cc.n1[k])), ecca = cca.n.map((v, k) => v == null ? null : pc(v, cca.n1[k]));
  const fP = v => (v > 0.05 ? '+' : (v < -0.05 ? '−' : '')) + Math.abs(v).toFixed(1).replace('.', ',') + ' %';
  const cumG = graphe({ W: 1000, H: 190, ml: 52, cats: cats.map((c, k) => Object.assign({ lab: c.lab }, cur && cur.mo === k + 1 ? { labCoul: BX, labFort: true, lab: c.lab + ' (' + cur.jours + ' j)' } : {})), fmt: fP, fmtAxe: v => (v > 0 ? '+' : (v < 0 ? '−' : '')) + Math.abs(Math.round(v)) + ' %', zero: true,
    series: [{ nom: 'CA', vals: ecca, coul: '#1c1a17', w: 2.2, pts: true, r: 2.6, labels: 'tout', fsz: 9.5, labelsDessous: false },
      { nom: 'clients', vals: ecc, coul: BX, w: 2.6, pts: true, r: 2.8, labels: 'tout', fsz: 9.5, labelsDessous: true }] });
  // La table : les douze mois, trois années, pour les clients et le CA.
  const lig = Array.from({ length: 12 }, (_, k) => {
    const mo = k + 1, x = M.mois.find(z => z.an === an && z.mo === mo);
    const c = q => { const v = val(an, mo, q), v1 = val(an1, mo, q), v2 = val(an2, mo, q), e = v != null && v1 != null ? pc(v, v1) : null; const f = q === 'ca' ? fK : fI;
      const enc = x && x.enCours ? `<span style="color:${BX}">${f(q === 'ca' ? x.ca : x.clients)} <small>(${x.jours} j)</small></span>` : (v != null ? f(v) : '');
      return `<td style="color:var(--color-text-muted)">${v2 != null ? f(v2) : ''}</td><td>${v1 != null ? f(v1) : ''}</td><td><b>${enc}</b></td><td>${e != null ? pill(sg(e), col(e)) : (x && x.enCours && x.n1 ? pill(sg(pc(q === 'ca' ? x.ca : x.clients, q === 'ca' ? x.n1.ca : x.n1.clients)) + ' à j. égaux', col(pc(q === 'ca' ? x.ca : x.clients, q === 'ca' ? x.n1.ca : x.n1.clients))) : '')}</td>`; };
    return `<tr${x && x.enCours ? ' style="background:#fbf3f4"' : ''}><td>${ML[mo]}</td>${c('clients')}${c('ca')}</tr>`;
  }).join('');
  const table = bloc('Mois par mois, trois années', `<table class="rr-tb"><tr><th rowspan="2" style="vertical-align:bottom">Mois</th><th colspan="4" style="text-align:center;border-bottom:none">Clients</th><th colspan="4" style="text-align:center;border-bottom:none">Chiffre d’affaires</th></tr><tr><th>${an2}</th><th>${an1}</th><th>${an}</th><th>vs N-1</th><th>${an2}</th><th>${an1}</th><th>${an}</th><th>vs N-1</th></tr>${lig}</table>`, '', 'margin-top:0');
  const corps = cadre('B', 'Évolution du magasin', esc(M.nom) + ' · ' + an + ' posé sur ' + an1 + ' et ' + an2 + ', mois par mois',
    seg(['Mois', 'Semaine'], 'Mois') + seg(['Total', 'Par jour ouvert'], 'Total'),
    `${choixMag(M)}
    <div class="rm-kp" style="grid-template-columns:repeat(6,minmax(0,1fr));margin-bottom:0">${tuiles.map(tuile).join('')}</div>
    <div class="rm-3 deux" style="margin-top:0">
      ${bloc('Trafic clients — janvier à décembre', courbe('clients') + legende, 'sous chaque mois : ' + an + ' vs ' + an1, 'margin-top:0')}
      ${bloc('Chiffre d’affaires — janvier à décembre', courbe('ca') + legende, 'sous chaque mois : ' + an + ' vs ' + an1, 'margin-top:0')}
    </div>
    ${bloc('L’écart cumulé à ' + an1 + ', depuis le 1er janvier', cumG + leg([legN + 'clients : ' + fI(der(cc.n)) + ' contre ' + fI(der(cc.n1)) + ' · <b style="color:' + col(eCum) + '">' + sg(eCum) + '</b>', '<i style="background:#1c1a17;border-radius:50%"></i>chiffre d’affaires : ' + fK(der(cca.n)) + ' contre ' + fK(der(cca.n1)) + ' · <b style="color:' + col(eCumCa) + '">' + sg(eCumCa) + '</b>', 'l’écart entre les deux courbes, c’est le panier : ' + fE2(pan) + ' contre ' + fE2(pan1)]), 'l’écart qui se creuse ou se referme, mois après mois · ' + MC[cur ? cur.mo : 12] + ' à jours égaux', 'margin-top:0')}
    ${table}`);
  page(corps, fichier);
}

/* ═══ C — semaine par semaine : les magasins en vignettes, des barres, l'écart au N-1 ═══ */
function maquetteC(M, fichier){
  const A = agregats(M);
  const S = M.sem.filter(x => !x.enCours);
  const cats = S.map((x, i) => {
    const d = new Date(x.du + 'T12:00:00'), mo = d.getMonth() + 1, an = d.getFullYear();
    const prev = i > 0 ? new Date(S[i - 1].du + 'T12:00:00') : null;
    const nouveauMois = !prev || prev.getMonth() !== d.getMonth();
    const nouvelAn = !prev || prev.getFullYear() !== an;
    return { lab: nouveauMois && (mo % 2 === 1 || nouvelAn) ? MC[mo].replace('.', '') : '', labFort: nouvelAn, sep: i === 0 || nouvelAn, sepLab: String(an),
      titre: x.lab + ' · ' + x.du.slice(8, 10) + '/' + x.du.slice(5, 7) + '/' + x.du.slice(0, 4) };
  });
  const barres = quoi => {
    const g = x => quoi === 'ca' ? x.ca : x.clients, fmt = quoi === 'ca' ? fK : fI;
    const n = S.map(g), n1 = S.map(x => x.n1 && !x.n1Partiel ? (quoi === 'ca' ? x.n1.ca : x.n1.clients) : null);
    // La tendance : la moyenne des quatre dernières semaines pleines.
    const moy = S.map((x, i) => { const f = S.slice(Math.max(0, i - 3), i + 1).filter(z => !z.partiel); return i >= 3 && f.length === 4 ? f.reduce((a, z) => a + g(z), 0) / 4 : null; });
    return graphe({ W: 1000, H: 250, cats, fmt, fmtAxe: quoi === 'ca' ? (v => (v / 1000).toFixed(0) + ' k€') : fI, yMin0: true,
      barres: { vals: n, partiels: S.map(x => x.partiel), coul: '#cf9aa2' },
      series: [
        { nom: 'N-1', vals: n1, coul: '#8a8378', dash: '4 3', w: 1.6 },
        { nom: 'tendance 4 sem.', vals: moy, coul: BX, w: 2.4 }
      ] });
  };
  const ecart = quoi => {
    const g = x => quoi === 'ca' ? x.ca : x.clients;
    const v = S.map(x => x.n1 && !x.n1Partiel && !x.partiel ? pc(g(x), quoi === 'ca' ? x.n1.ca : x.n1.clients) : null);
    const moy = S.map((x, i) => { const f = S.slice(Math.max(0, i - 3), i + 1); if (i < 3 || f.some(z => !z.n1 || z.n1Partiel || z.partiel)) { return null; } return pc(f.reduce((a, z) => a + g(z), 0), f.reduce((a, z) => a + (quoi === 'ca' ? z.n1.ca : z.n1.clients), 0)); });
    return { svg: divergent({ W: 1000, H: 150, cats, vals: v, moy }), v, moy };
  };
  const eC = ecart('clients'), eCa = ecart('ca');
  const derM = a => a.filter(v => v != null).pop();
  // Les vignettes des magasins : 52 dernières semaines pleines, N et N-1.
  const vign = MAGS.map(m => {
    const s = m.sem.filter(x => !x.enCours && !x.partiel).slice(-52);
    const a = agregats(m);
    const avec = a.d12.filter(x => x.n1 && !x.n1Partiel), nm = avec.length;
    const e = nm ? pc(avec.reduce((t, x) => t + x.clients, 0), avec.reduce((t, x) => t + x.n1.clients, 0)) : null, eca = nm ? pc(avec.reduce((t, x) => t + x.ca, 0), avec.reduce((t, x) => t + x.n1.ca, 0)) : null;
    const on = m.id === M.id;
    return `<div style="background:var(--color-surface);border-radius:9px;padding:9px 11px 8px;border:1.5px solid ${on ? BX : 'transparent'};${on ? '' : 'opacity:.92'}">
      <div style="display:flex;justify-content:space-between;align-items:baseline;gap:8px"><b style="font-weight:400;font-size:12.5px;${on ? 'color:' + BX : ''}">${esc(m.court)}</b><span style="font-size:10px;color:var(--color-text-muted)">depuis ${MC[a.prem.mo]} ${String(a.prem.an).slice(2)}</span></div>
      ${spark(s.map(x => x.clients), s.map(x => x.n1 && !x.n1Partiel ? x.n1.clients : null), 240, 46)}
      <div style="display:flex;gap:6px;align-items:center;font-size:10.5px;color:var(--color-text-muted);margin-top:3px">${nm ? `vs N-1, ${nm} mois : clients ${pill(sg(e), col(e))} CA ${pill(sg(eca), col(eca))}` : `pas encore de N-1 — dès ${MC[a.prem.mo]} ${a.prem.an + 1}`}</div></div>`;
  }).join('');
  const sp = S.filter(x => !x.partiel);
  const best = sp.reduce((a, b) => b.clients > a.clients ? b : a, sp[0]);
  const dd = x => x.du.slice(8, 10) + '/' + x.du.slice(5, 7) + '/' + x.du.slice(2, 4);
  const d4 = S.slice(-4), d4c = d4.reduce((a, x) => a + x.clients, 0), d4ca = d4.reduce((a, x) => a + x.ca, 0);
  const d4n1 = d4.every(x => x.n1) ? d4.reduce((a, x) => a + x.n1.clients, 0) : null, d4n1ca = d4.every(x => x.n1) ? d4.reduce((a, x) => a + x.n1.ca, 0) : null;
  const encours = M.sem.find(x => x.enCours);
  const avecN1 = S.filter(x => x.n1 && !x.n1Partiel && !x.partiel), dessus = avecN1.filter(x => x.clients >= x.n1.clients);
  const tuiles = [
    { k: 'Semaines lues', v: String(S.length), s: 'depuis le ' + dd(S[0]) + (S[0].partiel ? ' (ouverture, ' + S[0].jours + ' j)' : '') },
    { k: 'Par semaine pleine', v: fI(sp.reduce((a, x) => a + x.clients, 0) / sp.length), s: fK(sp.reduce((a, x) => a + x.ca, 0) / sp.length) + ' de CA' },
    d4n1 ? { k: '4 sem. · clients', v: sg(pc(d4c, d4n1)), s: fI(d4c) + ' contre ' + fI(d4n1) + ' en N-1', cls: d4c >= d4n1 ? 'good' : 'bad' } : { k: '4 dernières semaines', v: fI(d4c), s: 'pas de N-1' },
    d4n1ca ? { k: '4 sem. · CA', v: sg(pc(d4ca, d4n1ca)), s: fK(d4ca) + ' contre ' + fK(d4n1ca), cls: d4ca >= d4n1ca ? 'good' : 'bad' } : { k: 'CA · 4 semaines', v: fK(d4ca), s: '' },
    { k: 'Semaines > N-1', v: avecN1.length ? dessus.length + ' / ' + avecN1.length : '—', s: avecN1.length ? 'en clients, depuis ' + avecN1[0].lab + ' ' + avecN1[0].du.slice(0, 4) : '', cls: avecN1.length ? (dessus.length * 2 >= avecN1.length ? 'good' : 'bad') : '' },
    { k: 'Meilleure semaine', v: fI(best.clients), s: best.lab + ' · ' + dd(best) + ' · ' + fK(best.ca) },
    encours ? { k: encours.lab + ' en cours · ' + encours.jours + ' j', v: fI(encours.clients), s: encours.n1 ? '<b style="color:' + col(pc(encours.clients, encours.n1.clients)) + '">' + sg(pc(encours.clients, encours.n1.clients)) + '</b> face aux mêmes jours en N-1' : '' , cls: encours.n1 ? (encours.clients >= encours.n1.clients ? 'good' : 'bad') : '' } : { k: 'En cours', v: '—', s: '' }
  ];
  const legB = q => leg([`<i style="background:#cf9aa2"></i>${q === 'ca' ? 'CA de la semaine' : 'clients de la semaine'}`, `<i style="background:#e3c2c7"></i>semaine incomplète (ouverture, fermeture)`, `<svg width="20" height="9" style="vertical-align:-1px;margin-right:4px"><line x1="0" x2="20" y1="4.5" y2="4.5" stroke="${BX}" stroke-width="2.4"/></svg>tendance : moyenne des 4 dernières semaines`, `<svg width="20" height="9" style="vertical-align:-1px;margin-right:4px"><line x1="0" x2="20" y1="4.5" y2="4.5" stroke="#8a8378" stroke-width="1.6" stroke-dasharray="4 3"/></svg>N-1 : la même semaine un an plus tôt (364 jours), dès qu’elle existe`]);
  const corps = cadre('C', 'Évolution du magasin', esc(M.nom) + ' · semaine par semaine depuis l’ouverture · ' + S.length + ' semaines',
    seg(['Mois', 'Semaine'], 'Semaine') + seg(['Depuis l’ouverture', '52 semaines', '13 semaines'], 'Depuis l’ouverture'),
    `<div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px">${vign}</div>
    <div class="rm-kp sept" style="margin-bottom:0">${tuiles.map(tuile).join('')}</div>
    ${bloc('Trafic clients — semaine par semaine', barres('clients') + legB('clients'), '', 'margin-top:0')}
    ${bloc('Chiffre d’affaires du magasin — semaine par semaine', barres('ca') + legB('ca'), '', 'margin-top:0')}
    ${bloc('L’écart au N-1, semaine par semaine', `<div style="${cap};font-size:10px;margin:2px 0 2px 52px">Clients</div>${eC.svg}<div style="${cap};font-size:10px;margin:8px 0 2px 52px">Chiffre d’affaires</div>${eCa.svg}
      ${leg([`<i style="background:#3f7a52"></i>au-dessus de la même semaine en N-1`, `<i style="background:#A32014"></i>en dessous`, `<svg width="20" height="9" style="vertical-align:-1px;margin-right:4px"><line x1="0" x2="20" y1="4.5" y2="4.5" stroke="#1c1a17" stroke-width="1.8"/></svg>l’écart sur 4 semaines glissantes · dernier : clients <b style="color:${col(derM(eC.moy))}">${sg(derM(eC.moy))}</b>, CA <b style="color:${col(derM(eCa.moy))}">${sg(derM(eCa.moy))}</b>`])}`,
      avecN1.length ? 'dès ' + avecN1[0].lab + ' ' + avecN1[0].du.slice(0, 4) + ', première semaine qui a son N-1 · écart plafonné à ±40 % à l’affichage' : 'pas encore de N-1', 'margin-top:0')}`);
  page(corps, fichier);
}

const CORBAIS = MAGS.find(m => m.id === '2'), HALLE = MAGS.find(m => m.id === '4');
maquetteA(CORBAIS, 'a.html');
maquetteA(HALLE, 'a-halle.html');
maquetteB(CORBAIS, 'b.html');
maquetteC(CORBAIS, 'c.html');
