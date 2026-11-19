/* Maquettes « Fil du franchisé » (demande du 19/11/2026 : « créer dans le rail, pour le suivi des franchisés, notes,
 * captures, photos, pour voir un fil d'Ariane sur la semaine ou l'année ; le tag problème de gestion, bonne action… ;
 * quand on prend la carte du franchisé, on voit sur un calendrier ou un fil vertical les visites, les rapports, les
 * notes ; fais 3 maquettes en captures, puis on décide »).
 *
 *   node docs/maquettes/fil-franchise/generer.js     → a.html (le fil vertical), b.html (le calendrier), c.html (la frise)
 *   node docs/maquettes/fil-franchise/capturer.js    → a.png, b.png, c.png
 *
 * Les trois pages lisent les mêmes entrées (donnees.js) ; le rail est celui du cockpit relevé le 19/11/2026
 * (rail.html), avec l'entrée « Fil du franchisé » ajoutée sous Suivi des franchisés. */
const fs = require('fs');
const path = require('path');
const { AUJ, TAGS, TYPES, FILS, MAGASINS, PROCHAINE, ENTREES, RECENTES_DEPUIS } = require('./donnees.js');
const D = __dirname;

const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const dt = d => new Date(d + 'T12:00:00Z');
const JC = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'];
const MC = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
const ML = ['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
const jm = d => d.slice(8, 10) + '/' + d.slice(5, 7);
const jourCourt = d => JC[dt(d).getUTCDay()] + ' ' + jm(d);
const ecart = (a, b) => Math.round((dt(b) - dt(a)) / 864e5);
function semaine(d) {
  const t = dt(d); const j = t.getUTCDay() || 7; t.setUTCDate(t.getUTCDate() + 4 - j);
  const y0 = new Date(Date.UTC(t.getUTCFullYear(), 0, 1));
  return Math.ceil(((t - y0) / 864e5 + 1) / 7);
}
const lundi = s => { const x = new Date(Date.UTC(2025, 11, 29 + (s - 1) * 7, 12)); return x.toISOString().slice(0, 10); };
const plusJ = (d, n) => { const x = dt(d); x.setUTCDate(x.getUTCDate() + n); return x.toISOString().slice(0, 10); };
const bornesSem = s => { const l = lundi(s), di = plusJ(l, 6); return l.slice(8, 10).replace(/^0/, '') + (l.slice(5, 7) !== di.slice(5, 7) ? ' ' + MC[+l.slice(5, 7) - 1] : '') + ' – ' + di.slice(8, 10).replace(/^0/, '') + ' ' + MC[+di.slice(5, 7) - 1]; };

/* ── Icônes linéaires (design system §7.12) ── */
const P = {
  visite: 'M12 22s7-6.2 7-12a7 7 0 1 0-14 0c0 5.8 7 12 7 12zM12 12.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z',
  rapport: 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M8 13h8M8 17h5',
  note: 'M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z',
  photo: 'M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2zM12 17a4 4 0 1 0 0-8 4 4 0 0 0 0 8z',
  capture: 'M3 4h18v12H3zM8 20h8M12 16v4',
  appel: 'M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.8.7 2.7a2 2 0 0 1-.5 2.1L8 9.8a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.5c.9.3 1.8.6 2.7.7a2 2 0 0 1 1.7 2z',
  point: 'M9 11l3 3L22 4M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11',
  mystere: 'M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12zM12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z',
  demande: 'M4 5h16v11H8l-4 4zM8 9h8M8 12h5',
  epingle: 'M12 17v5M9 3h6l-1 6 3 3H7l3-3z',
  fil: 'M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7',
  cadenas: 'M7 11V7a5 5 0 0 1 10 0v4M5 11h14v10H5z',
  oeil: 'M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12zM12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z',
  plus: 'M12 5v14M5 12h14',
  trombone: 'M21.4 11.1l-9.2 9.2a6 6 0 0 1-8.5-8.5l9.2-9.2a4 4 0 0 1 5.7 5.7l-9.2 9.2a2 2 0 0 1-2.8-2.8l8.5-8.5',
  gauche: 'M15 18l-6-6 6-6', droite: 'M9 18l6-6-6-6', pdf: 'M12 3v12M7 10l5 5 5-5M5 21h14'
};
const ico = (k, c = 'fil-ico') => `<svg class="${c}" viewBox="0 0 24 24" aria-hidden="true"><path d="${P[k]}"></path></svg>`;

/* ── Étiquettes, sens, fils ── */
const SENS_ORDRE = ['gestion', 'surveiller', 'bonne'];
const sensDe = e => { const t = (e.tags || []).find(k => SENS_ORDRE.includes(k)); return t ? TAGS[t].sens : ''; };
const tag = (k, n) => { const t = TAGS[k]; return `<span class="fil-tag${t.sens ? ' fil-tag--' + t.sens : ''}">${t.sens ? '<i class="dot"></i>' : ''}${esc(t.nom)}${n != null ? ` <b>${n}</b>` : ''}</span>`; };
const tags = e => (e.tags || []).length ? `<span class="fil-tags">${e.tags.map(k => tag(k)).join('')}</span>` : '';
const DANS = (a, b) => ENTREES.filter(e => e.d >= a && e.d <= b);
const ANNEE = DANS('2026-01-01', AUJ);
const compteTag = (l, k) => l.filter(e => (e.tags || []).includes(k)).length;
const compteType = (l, t) => l.filter(e => e.type === t).length;
const filEntrees = f => ENTREES.filter(e => e.fil === f).sort((a, b) => (a.d + a.h).localeCompare(b.d + b.h));
const filDebut = f => filEntrees(f)[0].d;
const filEtat = f => {
  const F = FILS[f]; const deb = filDebut(f);
  return F.fin ? { ouvert: false, txt: `résolu le ${jm(F.fin)}, en ${ecart(deb, F.fin)} j`, j: ecart(deb, F.fin) }
    : { ouvert: true, txt: `ouvert depuis ${ecart(deb, AUJ)} j${F.echeance ? ' · échéance ' + (F.echeance === AUJ ? 'aujourd’hui' : jm(F.echeance)) : ''}`, j: ecart(deb, AUJ) };
};
const FILS_OUVERTS = Object.keys(FILS).filter(f => !FILS[f].fin);
const FILS_RESOLUS = Object.keys(FILS).filter(f => FILS[f].fin);
const EPINGLES = ENTREES.filter(e => e.epingle);
const nbPhotos = l => l.reduce((n, e) => n + (e.photos || []).length, 0);

/* Statistiques de Sombreffe (calculées) pour sa carte. */
const SOM = MAGASINS.find(m => m.calc);
const moisCourant = DANS('2026-11-01', AUJ);
SOM.mois = { gestion: compteTag(moisCourant, 'gestion'), bonne: compteTag(moisCourant, 'bonne'), ouverts: FILS_OUVERTS.length };
SOM.dernier = 'aujourd’hui';
SOM.visite = jourCourt(PROCHAINE.d);
SOM.semaines = [];
for (let s = 36; s <= 47; s++) {
  const l = DANS(lundi(s), plusJ(lundi(s), 6));
  let c = l.some(e => (e.tags || []).includes('gestion')) ? 'g' : l.some(e => (e.tags || []).includes('surveiller')) ? 's' : l.some(e => (e.tags || []).includes('bonne')) ? 'b' : l.length ? 'n' : '';
  if (l.some(e => e.type === 'visite')) { c += 'v'; }
  SOM.semaines.push(c);
}

/* ── La coque : le rail du cockpit et la barre du haut ── */
function rail() {
  let r = fs.readFileSync(path.join(D, 'rail.html'), 'utf8');
  r = r.replace('src="/public/assets/img/logo.png"', 'src="../../../public/assets/img/logo.png"');
  r = r.replace(/<!-- Recherche globale[\s\S]*?-->/, '');
  r = r.replace(/class="sidebar__item sidebar__item--child is-active" aria-current="page">(\s*<span class="sidebar__item-label">Visites<\/span>\s*<\/button>)/,
    'class="sidebar__item sidebar__item--child">$1\n        <button class="sidebar__item sidebar__item--child is-active" aria-current="page"><span class="sidebar__item-label">Fil du franchisé</span></button>');
  if (!r.includes('Fil du franchisé')) { throw new Error('rail : entrée Visites introuvable'); }
  return r;
}
function page(lettre, titreMaq, intro, corps, actions) {
  return `<!DOCTYPE html>
<html lang="fr"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Fil du franchisé — maquette ${lettre} — Cockpit CEO</title>
<!-- Maquette ${lettre} (19/11/2026) · ${esc(titreMaq)}. Générée par generer.js : ne pas modifier à la main. -->
<link rel="icon" href="../../../public/assets/img/logo.png">
<link rel="stylesheet" href="../../../public/assets/ds/global.css">
<link rel="stylesheet" href="../../../public/assets/css/app.css">
<link rel="stylesheet" href="fil.css">
</head>
<body>
<div id="app"><div class="shell workspace" data-ecran="filFranchise">
${rail()}
  <div class="workspace__main">
    <header class="topbar shell-head">
      <div><p class="topbar__kicker">Vue Réseau</p><h1>Fil du franchisé</h1></div>
      <div class="topbar__actions"><span class="fil-maq">Maquette ${lettre} · ${esc(titreMaq)} · données d’exemple</span>${actions || ''}</div>
    </header>
    <main class="app__body app__body--wide"><div class="shell-page fil">
      <p class="shell-sub page-intro" style="margin:0;max-width:880px">${intro}</p>
${corps}
    </div></main>
  </div>
</div></div>
</body></html>
`;
}

/* ── La carte du franchisé (A et B) ── */
function carte(m) {
  const on = m.calc;
  const sem = m.semaines.map(c => `<i class="${c.split('').join(' ')}"></i>`).join('');
  return `<button class="fil-carte${on ? ' is-on' : ''}">
    <div class="fil-carte__tete"><span class="fil-carte__ville">${esc(m.ville)}</span><span class="fil-carte__cons">${esc(m.consultant)}</span></div>
    <div class="fil-carte__nom">${esc(m.nom)}</div>
    <div class="fil-carte__ligne"><small class="fil-carte__cons">novembre</small>${tag('gestion', m.mois.gestion)}${tag('bonne', m.mois.bonne)}</div>
    <div><div class="fil-sem12">${sem}</div><div class="fil-sem12-leg"><span>S36</span><span>S47</span></div></div>
    <div class="fil-carte__bas"><span>${m.mois.ouverts ? `<span class="fil-ko">${m.mois.ouverts} fil${m.mois.ouverts > 1 ? 's' : ''} ouvert${m.mois.ouverts > 1 ? 's' : ''}</span>` : 'aucun fil ouvert'}</span><span>visite ${esc(m.visite)}</span></div>
    <div class="fil-carte__bas"><span>dernier ajout ${esc(m.dernier)}</span></div>
  </button>`;
}
const cartes = () => `<div class="fil-cartes">${MAGASINS.map(carte).join('')}</div>`;

/* ── Une entrée du fil vertical (A, et la semaine de B) ── */
function pieces(e, cls) {
  if (e.capture) { return `<div class="fil-ent__pieces"><img class="fil-cap" src="img/${e.capture}.jpg" alt=""></div>`; }
  if (e.photos) { return `<div class="fil-ent__pieces">${e.photos.map(p => `<img class="${cls || 'fil-ph'}" src="img/${p}.jpg" alt="">`).join('')}</div>`; }
  return '';
}
function piedFil(e) {
  const b = [];
  if (e.fil) {
    const st = filEtat(e.fil); const n = filEntrees(e.fil).length;
    b.push(`<span>${ico('fil')}<a class="fil-lien">${esc(FILS[e.fil].titre)}</a> · ${n} entrées · <span class="${st.ouvert ? 'fil-ko' : 'fil-ok'}">${st.txt}</span></span>`);
  }
  if (e.epingle) { b.push(`<span class="fil-or">${ico('epingle')}épinglé pour la visite du ${jm(PROCHAINE.d)}</span>`); }
  if (['note', 'photo', 'capture', 'appel'].includes(e.type)) {
    b.push(`<span class="fil-droite">${e.partage ? ico('oeil') + 'le franchisé le voit sur sa page' : ico('cadenas') + 'interne'}</span>`);
  }
  return b.length ? `<div class="fil-ent__pied">${b.join('')}</div>` : '';
}
function entree(e, o = {}) {
  const sens = sensDe(e);
  const rc = e.type === 'visite' ? 'visite' : sens;
  const quand = `<div class="fil-ent__quand"><b>${o.court ? JC[dt(e.d).getUTCDay()] : jourCourt(e.d)}</b>${o.court ? jm(e.d) : e.h || ''}</div>`;
  const axe = `<div class="fil-ent__axe"><span class="fil-rond${rc ? ' fil-rond--' + rc : ''}">${ico(e.type)}</span></div>`;
  let c;
  if (e.type === 'visite') {
    c = `<div class="fil-ent__corps fil-ent__corps--visite"><div class="fil-visite"><div>
      <div class="fil-ent__tete"><span class="fil-ent__type">Visite</span><span class="fil-ent__qui">${esc(e.qui)} · ${e.h}</span></div>
      <div class="fil-ent__titre">${esc(e.titre)}</div><div class="fil-ent__det">${esc(e.det)}</div></div>
      <div class="fil-score"><b>${e.score}</b> <small>score / 100</small><em class="${e.delta >= 0 ? 'fil-ok' : 'fil-ko'}">${e.delta >= 0 ? '+' : '−'}${Math.abs(e.delta)} face à la précédente</em></div></div>
      <div class="fil-ent__pied"><a class="fil-lien">Ouvrir la visite</a><a class="fil-lien">Le rapport</a></div></div>`;
  } else if (['rapport', 'mystere', 'demande', 'point'].includes(e.type)) {
    c = `<div class="fil-ent__corps fil-ent__corps--sys"><div class="fil-ent__tete"><span class="fil-ent__type">${esc(TYPES[e.type])}</span><span class="fil-ent__qui">${esc(e.qui)}</span>${tags(e)}</div>
      <div class="fil-ent__titre">${esc(e.titre)}</div><div class="fil-ent__det">${esc(e.det)}</div>${piedFil(e)}</div>`;
  } else {
    c = `<div class="fil-ent__corps"><div class="fil-ent__tete"><span class="fil-ent__type">${esc(TYPES[e.type])}</span><span class="fil-ent__qui">${esc(e.qui)}</span>${tags(e)}</div>
      <div class="fil-ent__txt">${esc(e.txt)}</div>${pieces(e)}${piedFil(e)}</div>`;
  }
  return `<div class="fil-ent${o.on ? ' fil-jour-on' : ''}">${quand}${axe}${c}</div>`;
}
const tagsResume = l => {
  const t = ['gestion', 'surveiller', 'bonne'].map(k => [k, compteTag(l, k)]).filter(x => x[1]);
  return t.length ? `<span class="fil-tags">${t.map(([k, n]) => tag(k, n)).join('')}</span>` : '';
};
const cptResume = l => `<span class="fil-cpts">${[['gestion', 'fil-ko'], ['surveiller', 'fil-or'], ['bonne', 'fil-ok']].map(([k, c]) => { const n = compteTag(l, k); return `<span class="fil-cpt ${c}${n ? '' : ' zero'}"><i></i>${n}</span>`; }).join('')}</span>`;
const pluriel = (n, s, p) => n + ' ' + (n > 1 ? (p || s + 's') : s);
function resumeTypes(l) {
  const x = [];
  const v = compteType(l, 'visite'); if (v) { x.push(pluriel(v, 'visite')); }
  const r = compteType(l, 'rapport'); if (r) { x.push(pluriel(r, 'rapport')); }
  const n = l.filter(e => ['note', 'photo', 'capture', 'appel'].includes(e.type)).length; if (n) { x.push(pluriel(n, 'note')); }
  const p = nbPhotos(l); if (p) { x.push(pluriel(p, 'photo')); }
  const d = compteType(l, 'demande'); if (d) { x.push(pluriel(d, 'demande')); }
  const m = compteType(l, 'mystere'); if (m) { x.push(pluriel(m, 'client mystère', 'clients mystère')); }
  return x.join(' · ');
}

/* ── Le composeur (A) ── */
function composeur() {
  const choix = ['gestion', 'bonne', 'surveiller', 'equipe', 'produit', 'hygiene', 'finances', 'client'].map(k => {
    const t = TAGS[k]; const on = ['gestion', 'produit'].includes(k);
    const col = t.sens === 'neg' ? 'var(--stop)' : t.sens === 'pos' ? 'var(--green)' : t.sens === 'att' ? 'var(--prize)' : '';
    return `<button class="fil-choix${on ? ' is-on' : ''}">${col ? `<i class="dot" style="background:${col}"></i>` : ''}${esc(t.nom)}</button>`;
  }).join('');
  return `<div class="fil-compo">
    <div class="fil-compo__zone"><span class="fil-av">LV</span><textarea class="fil-compo__txt">Encore la vitrine des quiches vide à 14 h : deux clients repartent sans rien.</textarea></div>
    <div class="fil-compo__pieces"><div class="fil-piece"><img src="img/capture-vitrine.jpg" alt=""><span>capture collée</span></div><div class="fil-piece"><img src="img/vitrine-4.jpg" alt=""><span>photo</span></div>
      <div class="fil-piece fil-piece--ajout">${ico('trombone')}Photo, capture<br>ou fichier</div>
      <span class="fil-compo__aide">Ctrl+V colle une capture d’écran ; on dépose aussi les photos du téléphone ici.</span></div>
    <div class="fil-compo__ligne"><small>Étiquettes</small>${choix}</div>
    <div class="fil-compo__ligne"><small>Suite du fil</small><span class="fil-select">${ico('fil')}${esc(FILS.production.titre)} <small>· ouvert ▾</small></span><small style="margin-left:10px">Date</small><span class="fil-select">aujourd’hui, 14:05 ▾</span></div>
    <div class="fil-compo__pied"><span class="fil-sw is-on"><i></i>Épingler pour la visite du ${jm(PROCHAINE.d)}</span><span class="fil-sw"><i></i>Le franchisé le voit</span>
      <button class="btn btn--primary">Ajouter au fil</button></div>
  </div>`;
}

/* ═════════════════════════ A — LE FIL VERTICAL ═════════════════════════ */
function maquetteA() {
  let fil = '';
  for (let s = 47; s >= 44; s--) {
    const l = DANS(lundi(s), plusJ(lundi(s), 6));
    fil += `<div class="fil-sem"><h3>Semaine ${s}</h3><small>${bornesSem(s)}${s === 47 ? ' · cette semaine' : ''} · ${resumeTypes(l)}</small>${tagsResume(l)}</div>`;
    fil += l.map(e => entree(e)).join('');
  }
  fil += `<div class="fil-sem"><h3>Plus tôt en 2026</h3><small>un mois par ligne, un clic le déplie</small><span class="fil-cpts"><span class="fil-cpt fil-ko"><i></i>problèmes de gestion</span><span class="fil-cpt fil-or"><i></i>à surveiller</span><span class="fil-cpt fil-ok"><i></i>bonnes actions</span></span></div>`;
  for (let m = 9; m >= 0; m--) {
    const a = '2026-' + String(m + 1).padStart(2, '0') + '-01';
    const b = m === 9 ? plusJ(RECENTES_DEPUIS, -1) : '2026-' + String(m + 1).padStart(2, '0') + '-31';
    const l = DANS(a, b);
    fil += `<div class="fil-mois"><div class="fil-ent__quand"></div><div class="fil-ent__axe"><span class="fil-rond"></span></div>
      <div class="fil-mois__corps"><span class="fil-mois__nom">${ML[m]}${m === 9 ? ' <small class="fil-mois__nb">1 – 25</small>' : ''}</span><span class="fil-mois__nb">${resumeTypes(l) || 'rien de noté'}</span>${cptResume(l)}<span class="fil-chev">▸</span></div></div>`;
  }
  const filtreTag = k => `<label class="fil-filtre"><input type="checkbox" checked>${tag(k)}<span class="n">${compteTag(ANNEE, k)}</span></label>`;
  const filtreType = (t, n, lib) => `<label class="fil-filtre"><input type="checkbox" checked>${ico(t)}${esc(lib || TYPES[t] + 's')}<span class="n">${n}</span></label>`;
  const ouverts = FILS_OUVERTS.map(f => { const st = filEtat(f); const l = filEntrees(f);
    return `<div class="fil-ouvert"><span>${esc(FILS[f].titre)}</span><small>${l.map(e => jm(e.d)).join(' → ')} · <span class="fil-ko">${st.txt}</span></small></div>`; }).join('');
  const moyResolus = Math.round(FILS_RESOLUS.reduce((n, f) => n + filEtat(f).j, 0) / FILS_RESOLUS.length);
  const notes = ANNEE.filter(e => ['note', 'photo', 'capture', 'appel'].includes(e.type));
  const cote = `<aside class="fil-cote">
    <section class="card"><h2>Lire le fil</h2>
      <div class="seg" role="tablist"><button class="is-on">Semaine</button><button>Mois</button><button>Année</button></div>
      <p class="fil-compo__aide" style="margin-top:8px">Semaine : les quatre dernières ouvertes, le reste replié par mois. Année : douze lignes, une par mois.</p></section>
    <section class="card"><h2>Étiquettes · 2026</h2><div class="fil-filtres">${Object.keys(TAGS).map(filtreTag).join('')}</div></section>
    <section class="card"><h2>Ce que montre le fil</h2><div class="fil-filtres">
      ${filtreType('visite', compteType(ANNEE, 'visite'), 'Visites et points décidés')}${filtreType('rapport', compteType(ANNEE, 'rapport'))}
      ${filtreType('note', notes.length, 'Notes, photos, captures, appels')}
      ${filtreType('demande', compteType(ANNEE, 'demande'), 'Demandes du franchisé')}${filtreType('mystere', compteType(ANNEE, 'mystere'), 'Client mystère')}</div></section>
    <section class="card"><h2>Fils ouverts · ${FILS_OUVERTS.length}</h2>${ouverts}
      <div class="fil-sep"></div><p class="fil-compo__aide">${FILS_RESOLUS.length} fils résolus cette année, en ${moyResolus} jours en moyenne.</p></section>
    <section class="card"><h2>Prochaine visite</h2><p style="margin:0;font-size:13px">${jourCourt(PROCHAINE.d)} · ${PROCHAINE.h} · ${esc(PROCHAINE.qui)}</p>
      <p class="fil-compo__aide" style="margin-top:4px">${EPINGLES.length} entrées épinglées partent dans son dossier (étape 3 de Visites).</p></section>
  </aside>`;
  const corps = `${cartes()}
    <div class="fil-deux"><div>${composeur()}<div style="margin-top:14px">${fil}</div></div>${cote}</div>
    <p class="fil-pied">Maquette A — le fil vertical. La carte du franchisé ouvre son fil : tout ce qui s’est passé chez lui, du plus récent au plus ancien, semaine par semaine — visites et rapports (lus dans Visites), points décidés, client mystère, demandes du helpdesk, et les notes, photos et captures que le bureau et les consultants y déposent. Une note porte des étiquettes ; « Problème de gestion » ouvre un fil, que les entrées suivantes prolongent jusqu’à la bonne action qui le ferme. Données d’exemple, magasins et consultants réels.</p>`;
  return page('A', 'le fil vertical', 'Les visites, les rapports et les notes de chaque franchisé, sur un seul fil. Une note, une photo ou une capture s’y ajoute en un geste, avec ses étiquettes — « Problème de gestion », « Bonne action », « À surveiller »… — pour relire la semaine ou l’année d’un coup d’œil.', corps, '<button class="btn">' + ico('pdf') + 'Exporter le fil</button>');
}

/* ═════════════════════════ B — LE CALENDRIER ═════════════════════════ */
function evCal(e) {
  const sens = sensDe(e);
  if (e.type === 'visite') { return `<span class="fil-ev fil-ev--visite">${ico('visite')}<span>Visite · ${e.score}</span></span>`; }
  if (['rapport', 'mystere', 'demande', 'point'].includes(e.type)) {
    const lib = { rapport: 'Rapport', mystere: 'Mystère · ' + (e.titre.match(/(\d+) \//) || [])[1], demande: 'Demande', point: 'Point P1' }[e.type];
    return `<span class="fil-ev fil-ev--sys">${ico(e.type)}<span>${lib}</span></span>`;
  }
  const COURT = { gestion: 'Problème', bonne: 'Bonne action', surveiller: 'À surveiller' };
  const k = (e.tags || []).find(t => SENS_ORDRE.includes(t)) || (e.tags || [])[0];
  const lib = k ? (COURT[k] || TAGS[k].nom) : TYPES[e.type];
  return `<span class="fil-ev${sens ? ' fil-ev--' + sens : ''}">${ico(e.type)}<span>${esc(lib)}</span></span>`;
}
function maquetteB() {
  const SEL = AUJ;
  let cal = '<div class="fil-cal"><div class="fil-cal__jour"></div>' + ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'].map(j => `<div class="fil-cal__jour">${j}</div>`).join('');
  for (let s = 44; s <= 49; s++) {
    cal += `<div class="fil-cal__s${s === 47 ? ' is-on' : ''}">S${s}</div>`;
    for (let j = 0; j < 7; j++) {
      const d = plusJ(lundi(s), j);
      const l = DANS(d, d).sort((a, b) => (a.d + a.h).localeCompare(b.d + b.h));
      const hors = d.slice(5, 7) !== '11';
      const cls = ['fil-cal__c', hors ? 'hors' : '', s === 47 ? 'sem' : '', d === AUJ ? 'auj' : '', d === SEL ? 'is-on' : ''].filter(Boolean).join(' ');
      let ev = l.slice(0, 3).map(evCal).join('');
      if (l.length > 3) { ev += `<span class="fil-ev">+ ${l.length - 3} autres</span>`; }
      const ph = l.flatMap(e => e.photos || (e.capture ? [e.capture] : [])).slice(0, 3);
      if (ph.length) { ev += `<span class="fil-ev-ph">${ph.map(p => `<img src="img/${p}.jpg" alt="">`).join('')}</span>`; }
      if (d === PROCHAINE.d) { ev += `<span class="fil-ev fil-ev--prevue">${ico('visite')}<span>Visite ${PROCHAINE.h}</span></span>`; }
      cal += `<div class="${cls}"><div class="fil-cal__n"><i>${+d.slice(8, 10)}</i>${d === SEL ? '<span class="fil-cal__plus">+</span>' : ''}</div>${ev}</div>`;
    }
  }
  cal += '</div>';

  const s47 = DANS(lundi(47), plusJ(lundi(47), 6));
  const semPanel = `<aside class="card" style="padding:14px 16px">
    <div style="display:flex;align-items:baseline;justify-content:space-between;gap:8px"><h2 style="margin:0">Semaine 47 · ${bornesSem(47)}</h2></div>
    <p class="fil-compo__aide" style="margin:4px 0 12px">${resumeTypes(s47)} — le jeudi 19 ouvert, la semaine autour.</p>
    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px">${tagsResume(s47)}</div>
    <div class="fil-semaine">${s47.map(e => entree(e, { court: true, on: e.d === SEL })).join('')}</div>
    <div style="display:flex;gap:8px;margin-top:6px"><button class="btn btn--sm">${ico('plus')}Ajouter au jeudi 19</button><button class="btn btn--sm btn--ghost">Ouvrir la semaine en fil</button></div>
  </aside>`;

  // L'année : douze mois, un carré par jour.
  let annee = '<div class="fil-annee">';
  for (let m = 0; m < 12; m++) {
    const a = '2026-' + String(m + 1).padStart(2, '0') + '-01';
    const nbJ = new Date(Date.UTC(2026, m + 1, 0)).getUTCDate();
    const dec = (dt(a).getUTCDay() + 6) % 7;
    let g = '';
    for (let i = 0; i < dec; i++) { g += '<i class="o"></i>'; }
    for (let j = 1; j <= nbJ; j++) {
      const d = a.slice(0, 8) + String(j).padStart(2, '0');
      const l = DANS(d, d);
      let c = d > AUJ ? 'f' : l.some(e => e.type === 'visite') ? 'v' : l.some(e => (e.tags || []).includes('gestion')) ? 'g'
        : l.some(e => (e.tags || []).includes('surveiller')) ? 's' : l.some(e => (e.tags || []).includes('bonne')) ? 'b' : l.length ? 'n' : '';
      if (d === PROCHAINE.d) { c = 'v'; }
      g += `<i class="${c}${d === AUJ ? ' auj' : ''}"${d === PROCHAINE.d ? ' style="opacity:.45"' : ''}></i>`;
    }
    const l = DANS(a, a.slice(0, 8) + nbJ);
    const ng = compteTag(l, 'gestion'), nb = compteTag(l, 'bonne');
    annee += `<div class="fil-mini${m === 10 ? ' is-on' : ''}"><h4>${ML[m]}<small>${compteType(l, 'visite') ? pluriel(compteType(l, 'visite'), 'visite') : ''}</small></h4><div class="fil-mini__g">${g}</div>
      <div class="fil-mini__c">${ng ? `<span class="fil-ko">${ng} probl.</span>` : ''}${nb ? `<span class="fil-ok">${nb} bonne${nb > 1 ? 's' : ''}</span>` : ''}${!ng && !nb ? '<span>—</span>' : ''}</div></div>`;
  }
  annee += '</div>';
  const leg = `<div class="fil-leg"><span><i style="background:#fff;border:2px solid var(--accent)"></i>visite</span><span><i style="background:var(--stop)"></i>problème de gestion</span><span><i style="background:var(--prize)"></i>à surveiller</span><span><i style="background:var(--green)"></i>bonne action</span><span><i style="background:var(--chip)"></i>autre note</span><span><i style="background:var(--paper);border:1px solid var(--line)"></i>rien</span><span>un jour = un carré · la couleur la plus forte du jour l’emporte</span></div>`;
  const lignes = [['gestion', 'Problèmes de gestion', 'var(--stop)'], ['surveiller', 'À surveiller', 'var(--prize)'], ['bonne', 'Bonnes actions', 'var(--green)']];
  let barres = '<div class="fil-barres"><span></span>' + MC.map(m => `<span style="font-size:10.5px;color:var(--muted);text-align:center">${m}</span>`).join('');
  lignes.forEach(([k, lib, col]) => {
    barres += `<span>${lib}</span>`;
    for (let m = 0; m < 12; m++) {
      const a = '2026-' + String(m + 1).padStart(2, '0') + '-01';
      const n = compteTag(DANS(a, a.slice(0, 8) + '31'), k);
      barres += `<div class="b">${Array.from({ length: n }, () => `<i style="background:${col}"></i>`).join('')}${n ? `<em>${n}</em>` : '<em class="z">—</em>'}</div>`;
    }
  });
  barres += '</div>';

  const filtres = ['gestion', 'bonne', 'surveiller', 'equipe', 'produit'].map(k => {
    const t = TAGS[k]; const col = t.sens === 'neg' ? 'var(--stop)' : t.sens === 'pos' ? 'var(--green)' : t.sens === 'att' ? 'var(--prize)' : '';
    return `<button class="fil-choix">${col ? `<i class="dot" style="background:${col}"></i>` : ''}${esc(t.nom)}</button>`;
  }).join('');
  const corps = `${cartes()}
    <div class="fil-bandeau"><span class="fil-nav"><button class="btn btn--icon">${ico('gauche')}</button><strong>Novembre 2026</strong><button class="btn btn--icon">${ico('droite')}</button></span><button class="btn btn--sm">Aujourd’hui</button>
      <span style="display:inline-flex;gap:6px;flex-wrap:wrap;margin-left:10px">${filtres}<button class="fil-choix">+ 3</button></span>
      <div class="seg"><button>Semaine</button><button class="is-on">Mois</button><button>Année</button></div></div>
    <div class="fil-trois">${cal}${semPanel}</div>
    <p class="section-label" style="margin-top:12px">La même page en vue Année <span class="section-label__aside">un clic sur un mois l’ouvre en vue Mois</span></p>
    <section class="card" style="display:grid;gap:18px">${annee}${leg}<div class="fil-sep" style="margin:0"></div>${barres}</section>
    <p class="fil-pied">Maquette B — le calendrier. La carte du franchisé ouvre son mois : chaque jour porte ses visites, rapports et notes, en couleur selon l’étiquette ; la colonne de droite déroule la semaine choisie en petit fil, photos comprises ; un clic sur un jour vide y ajoute une note. La vue Année montre les 365 jours d’un coup — où se groupent les problèmes de gestion, où arrivent les bonnes actions —, et les barres comptent les étiquettes mois par mois. Données d’exemple, magasins et consultants réels.</p>`;
  return page('B', 'le calendrier', 'Le calendrier de chaque franchisé : les visites, les rapports et les notes jour par jour, en couleur selon leur étiquette. La semaine choisie se lit à droite ; la vue Année montre toute l’année sur une page.', corps, '<button class="btn btn--primary">' + ico('plus') + 'Ajouter au fil</button>');
}

/* ═════════════════════════ C — LA FRISE ET SES FILS ═════════════════════════ */
function frise() {
  const W = 1120, x0 = 178, x1 = 1108, NS = 53, pas = (x1 - x0) / NS;
  const xS = s => x0 + (s - 1) * pas; const xc = s => xS(s) + pas / 2;
  const xD = d => { const s = semaine(d); const j = (dt(d).getUTCDay() + 6) % 7; return xS(s) + pas * (j + 0.5) / 7; };
  const LANES = [['visite', 'Visites · score'], ['gestion', 'Problèmes de gestion'], ['surveiller', 'À surveiller'], ['bonne', 'Bonnes actions'], ['notes', 'Notes, photos, captures'], ['autres', 'Demandes · client mystère']];
  const y0 = 34, H = 36; const yL = i => y0 + i * H + H / 2; const yK = k => yL(LANES.findIndex(l => l[0] === k));
  const Ht = y0 + LANES.length * H + 26;
  let g = '';
  LANES.forEach((l, i) => {
    if (i % 2 === 0) { g += `<rect x="${x0}" y="${y0 + i * H}" width="${x1 - x0}" height="${H}" fill="#FBF9F5"/>`; }
    g += `<text x="0" y="${yL(i) + 4}" font-size="11.5" fill="#5c5852">${esc(l[1])}</text>`;
  });
  // mois
  for (let m = 0; m < 12; m++) {
    const d = '2026-' + String(m + 1).padStart(2, '0') + '-01'; const x = xD(d) - pas * 0.5 / 7;
    g += `<line x1="${x}" y1="${y0 - 6}" x2="${x}" y2="${y0 + LANES.length * H}" stroke="#e6e0d8"/><text x="${x + 4}" y="${y0 - 12}" font-size="10.5" fill="#7a736a" letter-spacing="0.6">${MC[m].toUpperCase()}</text>`;
  }
  // futur, fenêtre détaillée, aujourd'hui
  const xa = xD(AUJ);
  g += `<defs><pattern id="hach" width="6" height="6" patternUnits="userSpaceOnUse" patternTransform="rotate(45)"><rect width="3" height="6" fill="#F3EEE7"/></pattern></defs>`;
  g += `<rect x="${xa}" y="${y0}" width="${x1 - xa}" height="${LANES.length * H}" fill="url(#hach)" opacity="0.9"/>`;
  g += `<rect x="${xS(44)}" y="${y0 - 4}" width="${xS(48) - xS(44)}" height="${LANES.length * H + 8}" rx="6" fill="rgba(141,29,44,0.06)" stroke="#8D1D2C" stroke-width="1.5"/>`;
  g += `<line x1="${xa}" y1="${y0 - 6}" x2="${xa}" y2="${y0 + LANES.length * H + 6}" stroke="#8D1D2C" stroke-width="1.5" stroke-dasharray="3 3"/>`;
  g += `<text x="${xa}" y="${Ht - 4}" font-size="10.5" fill="#8D1D2C" text-anchor="middle">aujourd’hui · S47</text>`;
  g += `<text x="${(xS(44) + xS(48)) / 2}" y="${y0 - 12}" font-size="10.5" fill="#8D1D2C" text-anchor="middle"></text>`;
  for (let s = 1; s <= NS; s += 4) { g += `<text x="${xc(s)}" y="${Ht - 4}" font-size="9.5" fill="#B8B2A8" text-anchor="middle">${s === 45 || s === 49 ? '' : 'S' + s}</text>`; }
  // les fils
  const posE = e => {
    const sens = sensDe(e);
    const k = sens === 'neg' ? 'gestion' : sens === 'pos' ? 'bonne' : sens === 'att' ? 'surveiller' : 'visite';
    return [xD(e.d), yK(k)];
  };
  Object.keys(FILS).forEach(f => {
    const l = filEntrees(f); const ouvert = !FILS[f].fin;
    for (let i = 1; i < l.length; i++) {
      const [ax, ay] = posE(l[i - 1]); const [bx, by] = posE(l[i]);
      const mx = (ax + bx) / 2;
      g += `<path d="M${ax},${ay} C${mx},${ay} ${mx},${by} ${bx},${by}" fill="none" stroke="${ouvert ? '#A32014' : '#3f7a52'}" stroke-width="1.6" opacity="0.75"/>`;
    }
    if (ouvert) { const [bx, by] = posE(l[l.length - 1]); g += `<path d="M${bx},${by} L${xa + 14},${by}" stroke="#A32014" stroke-width="1.6" stroke-dasharray="3 3" fill="none"/><path d="M${xa + 14},${by - 4} l6,4 -6,4" fill="none" stroke="#A32014" stroke-width="1.6"/>`; }
  });
  // marqueurs
  const parSem = {};
  ANNEE.forEach(e => { const s = semaine(e.d); (parSem[s] = parSem[s] || []).push(e); });
  Object.keys(parSem).forEach(sk => {
    const s = +sk; const l = parSem[s];
    ['gestion', 'surveiller', 'bonne'].forEach(k => {
      l.filter(e => (e.tags || []).includes(k)).forEach((e, i) => {
        const col = k === 'gestion' ? '#A32014' : k === 'bonne' ? '#3f7a52' : '#B0792A';
        g += `<circle cx="${xD(e.d)}" cy="${yK(k) + (i ? (i % 2 ? -5 : 5) : 0)}" r="5.2" fill="${col}" stroke="#fff" stroke-width="1.5"/>`;
      });
    });
    const n = l.filter(e => ['note', 'photo', 'capture', 'appel'].includes(e.type) && !SENS_ORDRE.some(k => (e.tags || []).includes(k))).length;
    if (n) { const h = Math.min(24, 7 * n); g += `<rect x="${xS(s) + 3}" y="${yK('notes') + 12 - h}" width="${pas - 6}" height="${h}" rx="2" fill="#CFC6BA"/>`; }
    l.filter(e => e.type === 'demande').forEach((e, i) => { const x = xD(e.d) + i * 4, y = yK('autres'); g += `<path d="M${x},${y - 5} l5,5 -5,5 -5,-5z" fill="#5c5852"/>`; });
    l.filter(e => e.type === 'mystere').forEach(e => { g += `<circle cx="${xD(e.d)}" cy="${yK('autres')}" r="4.5" fill="#fff" stroke="#5c5852" stroke-width="1.6"/>`; });
    l.filter(e => e.type === 'visite').forEach(e => {
      g += `<circle cx="${xD(e.d)}" cy="${yK('visite')}" r="7" fill="#8D1D2C"/><text x="${xD(e.d)}" y="${yK('visite') - 10}" font-size="9.5" fill="#5c5852" text-anchor="middle">${e.score}</text>`;
    });
    l.filter(e => e.type === 'point').forEach(e => { g += `<rect x="${xD(e.d) - 4}" y="${yK('visite') - 4}" width="8" height="8" rx="1.5" fill="#fff" stroke="#5c5852" stroke-width="1.5"/>`; });
  });
  const xp = xD(PROCHAINE.d);
  g += `<circle cx="${xp}" cy="${yK('visite')}" r="6.5" fill="#fff" stroke="#8D1D2C" stroke-width="1.6" stroke-dasharray="2.5 2"/>`;
  return `<svg viewBox="0 0 ${W} ${Ht}" role="img" aria-label="La frise de l’année">${g}</svg>`;
}
function miniCarte(e) {
  const sens = sensDe(e);
  const cl = e.type === 'visite' ? 'visite' : ['rapport', 'mystere', 'demande', 'point'].includes(e.type) ? 'sys' : sens;
  const titre = e.type === 'visite' ? `${esc(e.titre)} · <b style="font-weight:700">${e.score}</b> / 100` : esc(e.titre || e.txt);
  let ph = '';
  if (e.capture) { ph = `<div class="fil-mc__ph"><img class="cap" src="img/${e.capture}.jpg" alt=""></div>`; }
  else if (e.photos) { ph = `<div class="fil-mc__ph">${e.photos.slice(0, 3).map(p => `<img src="img/${p}.jpg" alt="">`).join('')}</div>`; }
  const fl = e.fil ? `<div class="fil-mc__fil">${ico('fil')}<span class="${filEtat(e.fil).ouvert ? 'fil-ko' : 'fil-ok'}">${esc(FILS[e.fil].titre)}</span></div>` : '';
  const t = (e.tags || []).filter(k => !SENS_ORDRE.includes(k));
  return `<div class="fil-mc${cl ? ' fil-mc--' + cl : ''}"><div class="fil-mc__t">${ico(e.type)}${esc(TYPES[e.type])}<em>${JC[dt(e.d).getUTCDay()]} ${jm(e.d)}</em></div>
    <p>${titre}</p>${ph}${(e.tags || []).length ? `<span class="fil-tags">${e.tags.map(k => tag(k)).join('')}</span>` : ''}${fl}${e.epingle ? `<div class="fil-mc__fil fil-or">${ico('epingle')}épinglé pour le ${jm(PROCHAINE.d)}</div>` : ''}</div>`;
}
function maquetteC() {
  const autres = MAGASINS.map(m => `<button class="filter${m.calc ? ' is-on' : ''}">${esc(m.ville)}${m.mois.ouverts ? ` · <span style="color:${m.calc ? '#fff' : 'var(--stop)'}">${m.mois.ouverts}</span>` : ''}</button>`).join('');
  const notes = ANNEE.filter(e => ['note', 'photo', 'capture', 'appel'].includes(e.type));
  const visites = ANNEE.filter(e => e.type === 'visite');
  const heros = `<section class="fil-heros">
    <div class="fil-heros__id"><h3>Sombreffe</h3><p>Atelier by Harmonie · consultant Loic V. · prochaine visite ${jourCourt(PROCHAINE.d)} ${PROCHAINE.h}</p>
      <div class="fil-tags">${tag('gestion', compteTag(ANNEE, 'gestion'))}${tag('surveiller', compteTag(ANNEE, 'surveiller'))}${tag('bonne', compteTag(ANNEE, 'bonne'))}</div></div>
    <div class="kpi-row">
      <div class="kpi"><div class="kpi__value" style="color:var(--stop)">${FILS_OUVERTS.length}</div><div class="kpi__label">Fils ouverts</div><div class="kpi__note">${FILS_RESOLUS.length} résolus en 2026</div></div>
      <div class="kpi"><div class="kpi__value">${visites.length}</div><div class="kpi__label">Visites en 2026</div><div class="kpi__note">dernier score ${visites[0].score} (+${visites[0].delta})</div></div>
      <div class="kpi"><div class="kpi__value">${notes.length}</div><div class="kpi__label">Notes et photos</div><div class="kpi__note">${nbPhotos(ANNEE)} photos · ${compteType(ANNEE, 'capture')} captures</div></div>
      <div class="kpi"><div class="kpi__value">${compteType(ANNEE, 'demande')}</div><div class="kpi__label">Demandes</div><div class="kpi__note">du helpdesk du panel</div></div>
    </div>
    <div class="fil-heros__act"><button class="btn btn--primary">${ico('plus')}Ajouter au fil</button><button class="btn btn--sm">Fiche &amp; scoring</button></div>
  </section>`;
  const cols = [44, 45, 46, 47].map(s => {
    const l = DANS(lundi(s), plusJ(lundi(s), 6)).sort((a, b) => (a.d + a.h).localeCompare(b.d + b.h));
    return `<div class="fil-col"><div class="fil-col__t${s === 47 ? ' is-on' : ''}"><h3>Semaine ${s}</h3><small>${bornesSem(s)}</small></div>${l.map(miniCarte).join('')}</div>`;
  }).join('');
  const etapes = f => filEntrees(f).map(e => { const s = sensDe(e); return `<i class="${s || 'sys'}" title="${jm(e.d)}"></i>`; }).join('<b></b>');
  const fils = `<aside class="fil-fils"><section class="card"><h2>Les fils de Sombreffe</h2>
    ${FILS_OUVERTS.concat(FILS_RESOLUS.slice().reverse()).map(f => { const st = filEtat(f); const l = filEntrees(f);
      return `<div class="fil-fl"><div class="fil-fl__t"><span>${esc(FILS[f].titre)}</span>${st.ouvert ? '<span class="tag-stop">ouvert</span>' : '<span class="badge badge--ok">résolu</span>'}</div>
        <div class="fil-fl__etapes">${etapes(f)}${st.ouvert ? '<b class="tir"></b>' : ''}</div>
        <small>${l.map(e => jm(e.d)).join(' → ')} · <span class="${st.ouvert ? 'fil-ko' : ''}">${st.txt}</span></small></div>`; }).join('')}
  </section></aside>`;
  const corps = `<div class="fil-autres"><small>Franchisé</small>${autres}<small style="margin-left:6px">le chiffre : ses fils ouverts</small></div>
    ${heros}
    <section class="card fil-frise"><div style="display:flex;align-items:center;gap:12px;margin-bottom:6px"><h2 style="margin:0">La frise 2026</h2>
      <span class="fil-leg" style="margin-left:6px"><span><i style="background:#3f7a52;height:2px;width:18px;border-radius:0"></i>fil résolu</span><span><i style="height:0;width:18px;border-top:2px dashed #A32014;border-radius:0"></i>fil ouvert</span><span><i style="background:#8D1D2C;border-radius:50%"></i>visite</span><span><i style="border:1.5px dashed #8D1D2C;border-radius:50%;background:#fff"></i>visite prévue</span><span><i style="border:1.5px solid #5c5852;background:#fff"></i>point décidé</span></span>
      <span style="margin-left:auto" class="seg"><button>Semaine</button><button class="is-on">4 semaines</button><button>Trimestre</button><button>Année</button></span></div>
      ${frise()}</section>
    <p class="section-label" style="margin:0">Le cadre bordeaux : les quatre semaines détaillées dessous <span class="section-label__aside">on le glisse sur la frise pour changer de période</span></p>
    <div class="fil-quatre">${cols}${fils}</div>
    <p class="fil-pied">Maquette C — la frise et ses fils. La carte du franchisé, en tête, ouvre son année sur une frise : une ligne par étiquette, une colonne par semaine. Un problème de gestion est relié à ce qui l’a suivi — point décidé, nouvelle note, bonne action — : vert quand il est résolu, rouge pointillé jusqu’à aujourd’hui tant qu’il est ouvert. Le cadre posé sur la frise choisit la période détaillée dessous, une colonne par semaine. Données d’exemple, magasins et consultants réels.</p>`;
  return page('C', 'la frise et ses fils', 'L’année d’un franchisé sur une frise : ses visites, ses problèmes de gestion, ses bonnes actions et ses notes, semaine par semaine, et le fil qui relie chaque problème à sa solution. Le cadre choisit la période qu’on lit en détail.', corps, '');
}

fs.writeFileSync(path.join(D, 'a.html'), maquetteA());
fs.writeFileSync(path.join(D, 'b.html'), maquetteB());
fs.writeFileSync(path.join(D, 'c.html'), maquetteC());
console.log('a.html, b.html, c.html ·', ENTREES.length, 'entrées ·', ANNEE.length, 'en 2026 ·',
  Object.keys(TAGS).map(k => TAGS[k].nom + ' ' + compteTag(ANNEE, k)).join(', '));
