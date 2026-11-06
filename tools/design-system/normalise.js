// Normalisation des styles au design system « L'Atelier By » (DESIGN_SYSTEM.md).
// node normalise.js [--dry] fichier...
// - graisses : 500/600/700/800/bold → 400, sauf valeur chiffrée ≥ 20 px (KPI §4.3) → 700 ; 100–300 → 400
// - tailles : px hors échelle → valeur la plus proche de l'échelle §4.2
// - rayons : pilules (≥ 50 px) → 6 px ; 13–49 px → 12 px (§5)
// - filets 0.5 px → 1 px ; ombres portées retirées (§2) ; inset conservé
// - couleurs : teintes d'interface → jetons du même rôle (§3, §10.1)
const fs = require('fs');

const SCALE = [10, 10.5, 11, 11.5, 12, 12.5, 13, 14, 15, 16, 18, 21, 22, 24, 26];
function nearest(v) {
  if (v >= 26) return 26;
  if (v <= 10) return 10;
  let best = SCALE[0], d = Infinity;
  for (const s of SCALE) { const e = Math.abs(s - v); if (e < d - 1e-9) { d = e; best = s; } }
  return best;
}
const fmt = n => String(n);

// Teinte → [jeton pour une déclaration CSS, hex pour un littéral JS / attribut SVG]
const T = (v, h) => [v, h];
const ACC05 = T('rgba(141, 29, 44, 0.05)', '#F9F4F5');
const ACC08 = T('rgba(141, 29, 44, 0.08)', '#F6ECED');
const ACCLINE = T('rgba(141, 29, 44, 0.45)', '#C68E95');
const GRN12 = T('rgba(63, 122, 82, 0.12)', '#E8EFEA');
const GRNLINE = T('rgba(63, 122, 82, 0.3)', '#C5D7CB');
const MAP = {
  // Texte
  '222': T('var(--ink)', '#1c1a17'), '222222': T('var(--ink)', '#1c1a17'), '221e1a': T('var(--ink)', '#1c1a17'),
  '2b2b2b': T('var(--ink)', '#1c1a17'), '1c1a17': T('var(--ink)', '#1c1a17'), '1a1a1a': T('var(--ink)', '#1c1a17'),
  '241c1a': T('var(--ink)', '#1c1a17'), '2b2724': T('var(--ink)', '#1c1a17'), '000': T('var(--ink)', '#1c1a17'), '000000': T('var(--ink)', '#1c1a17'),
  '333': T('var(--muted-2)', '#5c5852'), '333333': T('var(--muted-2)', '#5c5852'), '444': T('var(--muted-2)', '#5c5852'),
  '444444': T('var(--muted-2)', '#5c5852'), '555': T('var(--muted-2)', '#5c5852'), '555555': T('var(--muted-2)', '#5c5852'),
  '5c5852': T('var(--muted-2)', '#5c5852'), '5b6b78': T('var(--muted-2)', '#5c5852'), '6b655e': T('var(--muted-2)', '#5c5852'),
  '666': T('var(--muted)', '#7a736a'), '666666': T('var(--muted)', '#7a736a'), '777': T('var(--muted)', '#7a736a'),
  '777777': T('var(--muted)', '#7a736a'), '888': T('var(--muted)', '#7a736a'), '888888': T('var(--muted)', '#7a736a'),
  '7a736a': T('var(--muted)', '#7a736a'), '8a847c': T('var(--muted)', '#7a736a'), '9a8f84': T('var(--muted)', '#7a736a'),
  '9a9186': T('var(--muted)', '#7a736a'), '6b6b6b': T('var(--muted)', '#7a736a'), '857c71': T('var(--muted)', '#7a736a'),
  '999': T('var(--faint)', '#B8B2A8'), '999999': T('var(--faint)', '#B8B2A8'), 'aaa': T('var(--faint)', '#B8B2A8'),
  'bbb': T('var(--faint)', '#B8B2A8'), 'b8b2a8': T('var(--faint)', '#B8B2A8'), 'b9b1a6': T('var(--faint)', '#B8B2A8'),
  'c9c2b8': T('var(--faint)', '#B8B2A8'), 'b8ad9f': T('var(--faint)', '#B8B2A8'), 'a39a8f': T('var(--faint)', '#B8B2A8'),
  'c9bfb8': T('var(--faint)', '#B8B2A8'), 'c9c0b4': T('var(--faint)', '#B8B2A8'),
  // Surfaces
  'faf7f2': T('var(--paper)', '#faf7f2'), 'f3efe9': T('var(--paper)', '#faf7f2'), 'f7f3ec': T('var(--paper)', '#faf7f2'),
  'fbf9f5': T('var(--table-head)', '#FBF9F5'), 'fbf8f4': T('var(--table-head)', '#FBF9F5'), 'fcfaf7': T('var(--table-head)', '#FBF9F5'),
  'fdfbf8': T('var(--table-head)', '#FBF9F5'), 'faf8f5': T('var(--table-head)', '#FBF9F5'),
  'f8f4ee': T('var(--total-line)', '#F8F4EE'), 'f4efe8': T('var(--total-line)', '#F8F4EE'), 'f5f1ea': T('var(--total-line)', '#F8F4EE'),
  'ede7de': T('var(--chip)', '#EDE7DE'), 'edeae5': T('var(--chip)', '#EDE7DE'), 'efe9e1': T('var(--chip)', '#EDE7DE'),
  'ede7e0': T('var(--chip)', '#EDE7DE'), 'eae4dc': T('var(--chip)', '#EDE7DE'), 'f2efe9': T('var(--chip)', '#EDE7DE'),
  'e8e3dd': T('var(--chip)', '#EDE7DE'), 'eae3d8': T('var(--chip)', '#EDE7DE'), 'f1ece5': T('var(--chip)', '#EDE7DE'),
  'f0ebe4': T('var(--chip)', '#EDE7DE'), 'efe3de': T('var(--chip)', '#EDE7DE'),
  'e6e0d8': T('var(--line)', '#e6e0d8'), 'ddd': T('var(--line)', '#e6e0d8'), 'dddddd': T('var(--line)', '#e6e0d8'),
  'd9d2c8': T('var(--line)', '#e6e0d8'), 'd8cec2': T('var(--line)', '#e6e0d8'), 'd8cfc3': T('var(--line)', '#e6e0d8'),
  'ded6c9': T('var(--line)', '#e6e0d8'), 'e0dad2': T('var(--line)', '#e6e0d8'), 'e5ded4': T('var(--line)', '#e6e0d8'),
  'eee': T('var(--line)', '#e6e0d8'), 'eeeeee': T('var(--line)', '#e6e0d8'), 'ccc': T('var(--line)', '#e6e0d8'),
  'cccccc': T('var(--line)', '#e6e0d8'), 'e2dcd3': T('var(--line)', '#e6e0d8'), 'e3ddd4': T('var(--line)', '#e6e0d8'),
  'd9cfc2': T('var(--line)', '#e6e0d8'), 'e7e1d8': T('var(--line)', '#e6e0d8'),
  // Marque
  '8d1d2c': T('var(--accent)', '#8D1D2C'), '6d1622': T('#6f1622', '#6f1622'), '6e1420': T('#6f1622', '#6f1622'),
  '5a1320': T('#6f1622', '#6f1622'), '6f1622': T('#6f1622', '#6f1622'), '7a1825': T('#6f1622', '#6f1622'),
  'fdf4f5': ACC05, 'fbf4f5': ACC05, 'fffafb': ACC05, 'fdf6f7': ACC05, 'fbf5f6': ACC05,
  'f7e4e6': ACC08, 'f6e4e7': ACC08, 'fbebed': ACC08, 'f5d5d8': ACC08, 'f6edee': ACC08, 'f9e8ea': ACC08, 'f3e1e3': ACC08,
  'e8b4bb': ACCLINE, 'd9b3b8': ACCLINE, 'd9bcbf': ACCLINE, 'e8c9cd': ACCLINE, 'e6c3c8': ACCLINE,
  // Réussite
  '2d7a3e': T('var(--green)', '#3f7a52'), '1b5e20': T('var(--green)', '#3f7a52'), '1f5a2e': T('var(--green)', '#3f7a52'),
  '1f5a2c': T('var(--green)', '#3f7a52'), '2e7d32': T('var(--green)', '#3f7a52'), '3f7a52': T('var(--green)', '#3f7a52'),
  '2f7d45': T('var(--green)', '#3f7a52'), '2a7a3b': T('var(--green)', '#3f7a52'), '2e7a3e': T('var(--green)', '#3f7a52'),
  'e6f2e9': GRN12, 'e3f0e5': GRN12, 'e3efe6': GRN12, 'e6f2e8': GRN12, 'e3f0e6': GRN12, 'dfeee0': GRN12, 'e9f0e6': GRN12,
  'eef5ef': GRN12, 'e8f3ea': GRN12, 'eaf4ec': GRN12, 'e5f1e7': GRN12, 'edf6ef': GRN12,
  'bfdcc6': GRNLINE, 'c9e3cd': GRNLINE, 'b9d8c0': GRNLINE,
  // Arrêt (valeur négative) et erreur
  'c0182b': T('var(--stop)', '#A32014'), '8a1b1b': T('var(--stop)', '#A32014'), 'a32014': T('var(--stop)', '#A32014'),
  'b71c1c': T('var(--stop)', '#A32014'), 'c62828': T('var(--stop)', '#A32014'),
  'f7dada': T('var(--stop-soft)', '#F7E0DC'), 'f7e0dc': T('var(--stop-soft)', '#F7E0DC'), 'fdecec': T('var(--stop-soft)', '#F7E0DC'),
  'dc3545': T('var(--danger)', '#b3261e'), 'b3261e': T('var(--danger)', '#b3261e'),
  // Attention (or)
  'c17a2a': T('var(--prize)', '#B0792A'), 'd97706': T('var(--prize)', '#B0792A'), 'c9a227': T('var(--prize)', '#B0792A'),
  'b87512': T('var(--prize)', '#B0792A'), 'e0a526': T('var(--prize)', '#B0792A'), 'e2b93b': T('var(--prize)', '#B0792A'),
  'c97b32': T('var(--prize)', '#B0792A'), 'b8671a': T('var(--prize)', '#B0792A'), 'b0792a': T('var(--prize)', '#B0792A'),
  'c9a06a': T('var(--prize)', '#B0792A'), 'b0821a': T('var(--prize)', '#B0792A'), 'e5a100': T('var(--prize)', '#B0792A'),
  '8a5a13': T('var(--prize-ink)', '#7A5207'), 'b26a00': T('var(--prize-ink)', '#7A5207'), '8a6d12': T('var(--prize-ink)', '#7A5207'),
  '8a5a1c': T('var(--prize-ink)', '#7A5207'), '6b4420': T('var(--prize-ink)', '#7A5207'), '8a6508': T('var(--prize-ink)', '#7A5207'),
  'a0620c': T('var(--prize-ink)', '#7A5207'), '8a5a00': T('var(--prize-ink)', '#7A5207'), '7a5207': T('var(--prize-ink)', '#7A5207'),
  '6b3d0a': T('var(--prize-ink)', '#7A5207'), '9a6a1a': T('var(--prize-ink)', '#7A5207'), '8b5a00': T('var(--prize-ink)', '#7A5207'),
  'fbefe0': T('var(--prize-soft)', '#F6E7CE'), 'e8c9a0': T('var(--prize-soft)', '#F6E7CE'), 'fbf3dc': T('var(--prize-soft)', '#F6E7CE'),
  'fff1d6': T('var(--prize-soft)', '#F6E7CE'), 'f1e7d6': T('var(--prize-soft)', '#F6E7CE'), 'fac775': T('var(--prize-soft)', '#F6E7CE'),
  'f5d58f': T('var(--prize-soft)', '#F6E7CE'), 'fdf2e5': T('var(--prize-soft)', '#F6E7CE'), 'f2c9a0': T('var(--prize-soft)', '#F6E7CE'),
  'f6e7ce': T('var(--prize-soft)', '#F6E7CE'), 'fff7e6': T('var(--prize-soft)', '#F6E7CE'), 'fdf3e2': T('var(--prize-soft)', '#F6E7CE'),
  'e5c9a4': T('var(--prize-soft)', '#F6E7CE'), 'fcf1e0': T('var(--prize-soft)', '#F6E7CE'), 'fbf0dc': T('var(--prize-soft)', '#F6E7CE'),
};
// rgba fréquentes → jetons
const RGBA = [
  [/rgba\(\s*217\s*,\s*119\s*,\s*6\s*,\s*0?\.1\d*\s*\)/g, 'var(--prize-soft)'],
  [/rgba\(\s*193\s*,\s*122\s*,\s*42\s*,\s*0?\.1\d*\s*\)/g, 'var(--prize-soft)'],
  [/rgba\(\s*45\s*,\s*122\s*,\s*62\s*,\s*(0?\.\d+)\s*\)/g, 'rgba(63, 122, 82, $1)'],
  [/rgba\(\s*192\s*,\s*24\s*,\s*43\s*,\s*0?\.(0\d|1\d)\d*\s*\)/g, 'var(--stop-soft)'],
];

const PROP = '(?:color|background(?:-color)?|border(?:-(?:top|bottom|left|right))?(?:-color)?|outline(?:-color)?|fill|stroke|text-decoration-color|caret-color|accent-color|column-rule(?:-color)?)';
const DECL_HEX = new RegExp('(' + PROP + '\\s*:\\s*)([^;"\'`}{<>]*?)(#[0-9a-fA-F]{6}|#[0-9a-fA-F]{3})(?![0-9a-fA-F])', 'g');

const stats = {};
const bump = k => { stats[k] = (stats[k] || 0) + 1; };

function sizeWindow(src, i) {
  // la « déclaration » autour de l'indice i : jusqu'au guillemet / accolade / chevron le plus proche
  let a = i, b = i;
  while (a > 0 && i - a < 400 && !'"{}<>`'.includes(src[a - 1])) a--;
  while (b < src.length && b - i < 400 && !'"{}<>`'.includes(src[b])) b++;
  return src.slice(a, b);
}
function maxSize(win) {
  let m = 0; const re = /font-size\s*:\s*(\d+(?:\.\d+)?)px|font\s*:\s*(?:italic\s+)?(?:\d{3}\s+|bold\s+)?(\d+(?:\.\d+)?)px/g; let r;
  while ((r = re.exec(win))) m = Math.max(m, parseFloat(r[1] || r[2]));
  return m;
}

// Documents PHP (PDF serveur, e-mails — §13) : la taille reste celle du
// papier, le gras ne reste qu'aux titres et grands chiffres (≥ 15 px), les
// teintes prennent la valeur hexadécimale du jeton (ni PDF ni messagerie ne
// connaissent les variables CSS), le papier est blanc.
const DOC_FONT = 'Gotham,Helvetica,Arial,sans-serif';
// Gris et beiges propres aux documents, ramenés au jeton du même rôle.
const DOC_MAP = { '8b8177': '#7a736a', '6e645a': '#7a736a', '7a7268': '#7a736a', '9a938a': '#7a736a', '6b6259': '#7a736a',
  'a89f93': '#b8b2a8', 'b5aea4': '#b8b2a8', 'c9c1b5': '#b8b2a8', 'c9c3b8': '#b8b2a8',
  '5d564e': '#5c5852', '4a443c': '#5c5852', '3d382f': '#1c1a17',
  'e5e0d8': '#e6e0d8', 'e6e0d6': '#e6e0d8', 'd9d2c6': '#e6e0d8', 'e7e0d6': '#e6e0d8', 'd8d2c8': '#e6e0d8', 'e4dcd0': '#e6e0d8',
  'f0ede7': '#e6e0d8', 'f0eae1': '#e6e0d8', 'f5f0e8': '#e6e0d8', 'cfc6b8': '#e6e0d8',
  'f4f1eb': '#FBF9F5', 'faf8f4': '#FBF9F5', 'fbf6f1': '#FBF9F5', 'efe9e0': '#FBF9F5', 'fdf6f4': '#F9F4F5',
  'fff9ec': '#F6E7CE', 'fff3d6': '#F6E7CE', 'e0261a': '#A32014', 'a91325': '#A32014', '6b3a03': '#7A5207',
  '3d8b44': '#3f7a52', '1c4527': '#3f7a52', 'bfdcc5': '#C5D7CB', 'a8c8ae': '#C5D7CB' };
const DOC_SURF = { efe9df: '#ffffff', eae4dc: '#ffffff', f9f6f0: '#ffffff', f3efe9: '#ffffff', faf7f2: '#ffffff',
  f7f3ec: '#FBF9F5', f4efe8: '#FBF9F5', f7ecea: '#F9F4F5' };
function taillePx(win) {
  let m = 0; const re = /font-size\s*:\s*(\d+(?:\.\d+)?)(px|pt)|font\s*:\s*(?:italic\s+)?(?:\d{3}\s+|bold\s+)?(\d+(?:\.\d+)?)(px|pt)/g; let r;
  while ((r = re.exec(win))) { const v = parseFloat(r[1] || r[3]), u = r[2] || r[4]; m = Math.max(m, u === 'pt' ? v * 4 / 3 : v); }
  return m;
}
function normalisePhp(src) {
  let s = src;
  // Polices : Gotham, repli Helvetica / Arial (§13.2) ; ni Georgia, ni Segoe, ni la police système seule.
  s = s.replace(/Georgia,\s*(?:\\?["']DejaVu Serif\\?["'],\s*)?(?:\\?["']Times New Roman\\?["'],\s*)?serif/g, () => { bump('police→Gotham'); return DOC_FONT; });
  s = s.replace(/\\?'Segoe UI\\?',\s*Arial,\s*sans-serif/g, () => { bump('police→Gotham'); return DOC_FONT; });
  s = s.replace(/\\?'Helvetica Neue\\?',\s*Helvetica,\s*Arial,\s*sans-serif/g, () => { bump('police→Gotham'); return DOC_FONT; });
  s = s.replace(/(?<!Gotham,|Gotham',|Gotham\\',)Helvetica,\s*Arial,\s*sans-serif/g, () => { bump('police→Gotham'); return DOC_FONT; });
  s = s.replace(/Arial,\s*Helvetica,\s*sans-serif/g, () => { bump('police→Gotham'); return DOC_FONT; });
  s = s.replace(/font-family:\s*sans-serif/g, () => { bump('police→Gotham'); return 'font-family:' + DOC_FONT; });
  // Graisses
  s = s.replace(/font-weight\s*:\s*(100|200|300|500|600|700|800|900|bold|bolder)\b/g, (m, w, off, all) => {
    const n = /bold/.test(w) ? 700 : +w;
    const big = n >= 600 && taillePx(sizeWindow(all, off)) >= 15;
    bump(big ? 'graisse→700 (titre, chiffre)' : 'graisse→400');
    return 'font-weight:' + (big ? 700 : 400);
  });
  s = s.replace(/(\bfont\s*:\s*(?:italic\s+)?)(500|600|700|800|bold)(\s+)(\d+(?:\.\d+)?)(px|pt)/g, (m, pre, w, sp, v, u) => {
    const n = w === 'bold' ? 700 : +w; const px = u === 'pt' ? parseFloat(v) * 4 / 3 : parseFloat(v);
    bump('font:graisse'); return pre + (n >= 600 && px >= 15 ? 700 : 400) + sp + v + u;
  });
  // Pilules, coins de carte, ombres, filets d'un demi-pixel
  s = s.replace(/border-radius\s*:\s*([0-9.]+px(?:\s+[0-9.]+px){0,3})/g, (m, v) => 'border-radius:' + v.split(/\s+/).map(x => {
    const n = parseFloat(x); if (n >= 50) { bump('pilule→6'); return '6px'; } if (n > 12) { bump('rayon→8'); return '8px'; } return x; }).join(' '));
  s = s.replace(/box-shadow\s*:\s*(?!\s*(?:inset|none|\$))[^;"'`}{]*;?/g, () => { bump('ombre retirée'); return ''; });
  s = s.replace(/(^|[^0-9.])0?\.5px solid/g, (m, p) => { bump('filet 0.5→1'); return p + '1px solid'; });
  // Teintes → valeur du jeton (fond de page et blocs beiges → papier blanc)
  s = s.replace(/#([0-9a-fA-F]{6}|[0-9a-fA-F]{3})(?![0-9a-fA-F])/g, (m, h) => {
    const k = h.toLowerCase();
    if (DOC_SURF[k]) { bump('fond→blanc'); return DOC_SURF[k]; }
    if (DOC_MAP[k]) { bump('teinte→jeton'); return DOC_MAP[k]; }
    const t = MAP[k]; if (!t) return m;
    bump('teinte→jeton'); return t[1];
  });
  return s;
}

function normalise(src, kind) {
  if (kind === 'php') return normalisePhp(src);
  let s = src;
  // 1. Graisses longues : font-weight:NNN
  s = s.replace(/font-weight\s*:\s*(100|200|300|500|600|700|800|900|bold|bolder|lighter)\b/g, (m, w, off, all) => {
    const n = w === 'bold' || w === 'bolder' ? 700 : w === 'lighter' ? 300 : +w;
    if (n < 400) { bump('graisse<400'); return 'font-weight:400'; }
    const big = maxSize(sizeWindow(all, off)) >= 20;
    bump(big ? 'graisse→700 (KPI)' : 'graisse→400');
    return 'font-weight:' + (big ? 700 : 400);
  });
  // 1b. Raccourci font: NNN SIZEpx
  s = s.replace(/(\bfont\s*:\s*(?:italic\s+)?)(100|200|300|500|600|700|800|900|bold)(\s+)(\d+(?:\.\d+)?)px/g, (m, pre, w, sp, size) => {
    const n = w === 'bold' ? 700 : +w;
    const big = parseFloat(size) >= 20 && n >= 600;
    bump('font:graisse');
    return pre + (big ? 700 : 400) + sp + fmt(nearest(parseFloat(size))) + 'px';
  });
  // 1c. font: SIZEpx (sans graisse) et font: 400 SIZEpx
  s = s.replace(/(\bfont\s*:\s*(?:italic\s+)?(?:400\s+)?)(\d+(?:\.\d+)?)px/g, (m, pre, size) => {
    const v = parseFloat(size), n = nearest(v);
    if (n !== v) bump('font:taille');
    return pre + fmt(n) + 'px';
  });
  // 2. Tailles
  s = s.replace(/font-size\s*:\s*(\d+(?:\.\d+)?)px/g, (m, v) => {
    const n = nearest(parseFloat(v));
    if (n !== parseFloat(v)) bump('taille');
    return 'font-size:' + fmt(n) + 'px';
  });
  // 3. Rayons
  s = s.replace(/border-radius\s*:\s*([0-9.]+px(?:\s+[0-9.]+px){0,3})/g, (m, v) => {
    const out = v.split(/\s+/).map(x => {
      const n = parseFloat(x);
      if (n >= 50) { bump('pilule→6'); return '6px'; }
      if (n > 12) { bump('rayon→12'); return '12px'; }
      return x;
    }).join(' ');
    return 'border-radius:' + out;
  });
  // 4. Filets d'un demi-pixel
  s = s.replace(/(^|[^0-9.])0?\.5px solid/g, (m, p) => { bump('filet 0.5→1'); return p + '1px solid'; });
  s = s.replace(/(border(?:-(?:top|bottom|left|right))?-width\s*:\s*)0?\.5px/g, (m, p) => { bump('filet 0.5→1'); return p + '1px'; });
  // 5. Ombres portées (inset et none conservés ; expression JS laissée)
  s = s.replace(/box-shadow\s*:\s*(?!\s*(?:inset|none|var|\$))[^;"'`}{]*;?/g, m => { bump('ombre retirée'); return ''; });
  // 6. rgba fréquentes
  if (kind !== 'doc') for (const [re, to] of RGBA) s = s.replace(re, (...g) => { bump('rgba→jeton'); return to.replace('$1', g[1]); });
  // 7. Couleurs dans une déclaration → jeton
  let prev;
  do {
    prev = s;
    s = s.replace(DECL_HEX, (m, prop, mid, hex) => {
      const k = hex.slice(1).toLowerCase(); const t = MAP[k];
      if (!t) return m;
      bump('hex→jeton');
      return prop + mid + (kind === 'doc' ? t[1] : t[0]);
    });
  } while (s !== prev);
  // 8. Autres littéraux (JS, attributs SVG) → valeur hexadécimale du système
  if (kind === 'doc') {
    s = s.replace(/var\(--font-(?:display|ui),\s*[^)]*\)/g, "'Gotham',Helvetica,Arial,sans-serif");
    s = s.replace(/Georgia,\s*"DejaVu Serif",\s*serif/g, "'Gotham',Helvetica,Arial,sans-serif");
  }
  if (kind === 'js') {
    s = s.replace(/(['"])#([0-9a-fA-F]{6}|[0-9a-fA-F]{3})\1/g, (m, q, h) => {
      const t = MAP[h.toLowerCase()];
      if (!t) return m;
      bump('littéral→hex système');
      return q + t[1] + q;
    });
    s = s.replace(/((?:fill|stroke|stop-color|color)=)(["'])#([0-9a-fA-F]{6}|[0-9a-fA-F]{3})\2/g, (m, a, q, h) => {
      const t = MAP[h.toLowerCase()];
      if (!t) return m;
      bump('attribut SVG→hex système');
      return a + q + t[1] + q;
    });
  }
  return s;
}

const args = process.argv.slice(2);
const dry = args[0] === '--dry'; if (dry) args.shift();
for (const f of args) {
  const kind = /\.(css)$/.test(f) ? 'css' : /\.php$/.test(f) ? 'php' : 'js';
  const src = fs.readFileSync(f, 'utf8');
  // Zones exclues : documents qui ont leur propre mise en page (§13) — marqueurs
  let out = '';
  const re = /\/\*\s*ds:doc\b[^*]*\*\/([\s\S]*?)\/\*\s*ds:fin-doc\s*\*\//g;
  let last = 0, r;
  while ((r = re.exec(src))) { out += normalise(src.slice(last, r.index), kind) + normalise(r[0], 'doc'); last = r.index + r[0].length; }
  out += normalise(src.slice(last), kind);
  if (!dry) fs.writeFileSync(f, out);
  console.log(f, out === src ? '(inchangé)' : 'modifié');
}
console.log(JSON.stringify(stats, null, 1));
