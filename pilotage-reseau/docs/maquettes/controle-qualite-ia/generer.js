/* Maquette : l'IA PRÉ-NOTE les contrôles en photo, le consultant VALIDE.
 *
 * Page Tâches › Contrôle (#/controle-taches) du cockpit, en carrousels (voir
 * ../controle-taches-photos). Chaque photo rendue porte déjà la proposition de
 * l'IA : niveau du barème, confiance, premier constat. Une bande au-dessus des
 * boutiques résume le passage du matin et valide en un clic les conformes à
 * confiance haute ; les écarts et les photos à revoir s'ouvrent une à une dans
 * le volet de notation, où la proposition pré-remplit le niveau et le
 * commentaire. Rien ne part au panel sans le geste du consultant.
 *
 * Données RÉELLES du jeudi 01/10/2026 (tâches, photos, DOM du cockpit) reprises
 * de ../controle-taches-photos. Les propositions (propositions.json) sont
 * RÉDIGÉES pour la maquette en regardant les photos : ce ne sont pas des
 * réponses du modèle. Le taux d'accord est un chiffre d'exemple.
 *
 *   node docs/maquettes/controle-qualite-ia/generer.js   (serveur statique sur 8099 depuis pilotage-reseau/)
 */
const fs = require('fs'), path = require('path');
const { chromium } = require('/opt/node22/lib/node_modules/playwright');
const OUT = __dirname;
const SRC = path.join(__dirname, '../controle-taches-photos');
const D = require(path.join(SRC, 'donnees.json'));
const P = require('./propositions.json');
const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const hh = v => v ? String(v).slice(11, 16) : '';
const NIV = { 5: ['Exemplaire', '#C9A227'], 4: ['Conforme', '#2D7A3E'], 3: ['Non conforme — mineur', '#D97706'], 2: ['Non conforme — majeur', '#C0182B'], 1: ['Non conforme — critique', '#8D1D2C'] };
const COURT = { 5: 'Exemplaire', 4: 'Conforme', 3: 'mineur', 2: 'majeur', 1: 'critique' };

/* --- Les tâches et leur proposition --------------------------------------- */
const nom = t => String(t.tache).replace(/^Photo du comptoir\s*-\s*/i, 'Comptoir · ').replace(/^Contrôle Qualité\s*[–-]\s*/i, 'CQ · ');
const cl = t => String(t.checklist || '').replace(/\.$/, '').replace(/^[A-Z]{2}-?[A-Z0-9]+\s*[—–-]\s*/i, '');
/** L'état d'une carte : la proposition si la photo est rendue, sinon non rendue / sans photo. */
function etat(s, t) {
  if (!t.photo) { return t.statut === 'sansPhoto' ? { c: 'mu', r: 5 } : { c: 'ko', r: 4 }; }
  const p = P.p[s.shopId + '-' + t.taskId];
  if (!p) { return { c: 'ctl', r: 3 }; }
  if (p.n == null) { return { c: 'rv', r: 2, p }; }
  if (p.n < D.seuil) { return { c: 'nc', r: p.n <= 2 ? 0 : 1, p }; }
  return { c: 'ok', r: 3, p };
}
const tri = L => L.slice().sort((a, b) => (a.e.r - b.e.r) || String(a.faitLe || '9').localeCompare(String(b.faitLe || '9')) || nom(a).localeCompare(nom(b)));
const photoSrc = (s, t) => `../controle-taches-photos/photos/${s.shopId}-${t.taskId}.jpg`;

function carte(s, t) {
  const e = t.e;
  if (!t.photo) {
    return `<button type="button" class="db-cqc"><span class="db-cqph vide ${e.c}"><b>${e.c === 'ko' ? '✗' : '—'}</b>${e.c === 'ko' ? 'pas encore rendue' : 'sans photo'}</span>`
      + `<span class="n">${esc(nom(t))}</span><span class="m">pas encore rendue</span><span class="c ko">${esc(cl(t) || 'non rendue')}</span></button>`;
  }
  const p = e.p;
  const bd = e.c === 'rv' ? '<em class="cqbd iarv">IA · à revoir</em>' : `<em class="cqbd ia${p.n}">IA · ${COURT[p.n]}</em>`;
  const cls = e.c === 'rv' ? 'rv' : 'n' + p.n;
  const cf = e.c === 'rv' ? 'confiance faible — à juger vous-même' : 'confiance ' + p.conf;
  const btn = e.c === 'ok' ? '<span class="cq-btns"><span class="cq-noter">Valider</span><span class="cq-noter re">Corriger</span></span>'
    : e.c === 'nc' ? '<span class="cq-btns"><span class="cq-noter">Vérifier l’écart</span></span>'
    : '<span class="cq-btns"><span class="cq-noter">Noter</span></span>';
  return `<button type="button" class="db-cqc" title="Voir la photo, la proposition et noter">`
    + `<span class="db-cqph"><img src="${photoSrc(s, t)}" alt=""><em class="h">${hh(t.faitLe)}</em>${bd}</span>`
    + `<span class="n">${esc(nom(t))}</span><span class="m">${hh(t.faitLe)}${t.faitePar ? ' · ' + esc(t.faitePar) : ''}</span>`
    + `<span class="c ia ${cls}">${esc(p.c)}</span><span class="cf">${cf}</span>${btn}</button>`;
}

/* --- Une boutique : l'en-tête, les filtres, le rail, le pied ---------------- */
const CARD = 'background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;overflow:hidden';
const avecEtats = s => tri(s.taches.map(t => Object.assign({}, t, { e: etat(s, t) })));
function boutique(s) {
  const T = avecEtats(s);
  const nb = c => T.filter(t => t.e.c === c).length;
  const PH = T.filter(t => t.photo);
  const heures = PH.map(t => hh(t.faitLe)).sort();
  const qui = [...new Set(PH.map(t => t.faitePar).filter(Boolean))].join(', ');
  const resume = PH.length ? `${PH.length} photos rendues de ${heures[0]} à ${heures[heures.length - 1]} par ${esc(qui)} · ${PH.length} lues par l’IA` : 'aucune photo rendue aujourd’hui';
  const puces = [['tout', 'Tout', null, T.length], ['nc', 'Écarts proposés', '#C0182B', nb('nc')], ['rv', 'À revoir', '#6b645b', nb('rv')], ['ok', 'Conformes proposés', '#2d7a3e', nb('ok')], ['ko', 'Non rendues', '#A32014', nb('ko')], ['mu', 'Sans photo', '#a59d93', nb('mu')]]
    .filter(f => f[3]).map(f => `<button type="button" class="${f[0] === 'tout' ? 'on' : ''}">${f[2] ? `<i style="background:${f[2]}"></i>` : ''}${f[1]}<b>${f[3]}</b></button>`).join('');
  const tete = `<div style="padding:13px 18px;border-bottom:0.5px solid var(--color-border-tertiary);display:flex;align-items:center;gap:12px;flex-wrap:wrap">
      <div style="font-size:13.5px;font-weight:600">${esc(s.shop)}</div><span class="db-cqf">${puces}</span>
      <div style="font-size:11.5px;color:var(--color-text-muted);margin-left:auto;text-align:right">${resume} · 0 / ${T.length} notée</div></div>`;
  if (!PH.length) {
    return `<div style="${CARD}">${tete}<div style="padding:14px 18px;font-size:12.5px;color:var(--color-text-muted)">${nb('ko')} tâches pas encore rendues — rien à proposer pour l’instant.</div></div>`;
  }
  return `<div style="${CARD}">${tete}
    <div class="db-cqrail"><button type="button" class="db-cqfl g" disabled aria-label="précédentes">‹</button>
      <div class="db-cqpiste">${T.map(t => carte(s, t)).join('')}</div>
      <button type="button" class="db-cqfl d" aria-label="suivantes">›</button></div>
    <div class="db-cqpied">${T.length} contrôles · écarts proposés d’abord, puis à revoir, puis conformes · un clic ouvre la photo, la proposition et la notation</div></div>`;
}
/** Les boutiques qui ont des écarts proposés passent devant. */
const poids = s => { const T = avecEtats(s); return T.filter(t => t.e.c === 'nc').length * 100 + T.filter(t => t.e.c === 'rv').length * 10 + T.filter(t => t.photo).length; };
const SHOPS = D.shops.slice().sort((a, b) => poids(b) - poids(a));

/* --- La bande de la pré-notation ------------------------------------------ */
function bande() {
  const L = D.shops.flatMap(s => avecEtats(s).filter(t => t.photo));
  const ok = L.filter(t => t.e.c === 'ok'), sur = ok.filter(t => t.e.p.conf === 'haute');
  const nc = L.filter(t => t.e.c === 'nc'), rv = L.filter(t => t.e.c === 'rv');
  return `<div class="ia-band">
    <div class="t"><span class="tag">Pré-notation IA</span><span class="s">${L.length} photos rendues aujourd’hui, toutes lues · passage de ${P.passage}, prochain à ${P.prochain}</span>
      <span class="acc">Accord avec vos notes sur ${P.accord.jours} jours : <b>${P.accord.pct} %</b> (${P.accord.photos} photos)</span></div>
    <div class="k">
      <div class="ia-k nc"><b>${nc.length}</b><span>écarts proposés</span></div>
      <div class="ia-k rv"><b>${rv.length}</b><span>à revoir · photo insuffisante</span></div>
      <div class="ia-k ok"><b>${ok.length}</b><span>conformes proposés · ${sur.length} sûrs</span></div>
      <div class="ia-act"><button type="button" class="ia-btn">Valider les ${sur.length} conformes sûrs</button>
        <span class="ia-sw"><i></i>Noter seul les conformes sûrs · éteint</span></div>
    </div>
    <div class="note">« Sûr » : proposé conforme avec une confiance haute. Rien n’est noté sans votre clic : les propositions attendent ici. Un écart ne se valide qu’en l’ouvrant, commentaire au magasin compris, et ce commentaire reste modifiable. L’interrupteur (Paramètres › Assistance IA) laisse l’IA noter seule les conformes sûrs ; il est éteint.</div>
  </div>`;
}

/* --- La page : le DOM du cockpit, le tableau remplacé par les carrousels ---- */
const W = '<div style="display:flex;flex-direction:column;gap:14px">';
/** L'index juste après la balise fermante du <div> qui commence à `i`. */
function finDiv(h, i) {
  let prof = 0;
  const re = /<div\b|<\/div>/g; re.lastIndex = i;
  let m;
  while ((m = re.exec(h))) { if (m[0] === '<div') { prof++; } else { prof--; if (prof === 0) { return m.index + 6; } } }
  return -1;
}
const TOGGLE = `<span class="cq-vue"><button type="button" class="on">▦ Photos</button><button type="button">☰ Liste</button></span>`;
function page(src) {
  let h = fs.readFileSync(path.join(SRC, src), 'utf8');
  h = h.replace(/<script[\s\S]*?<\/script>/g, '').replace(/(src|href)="assets\//g, '$1="/public/assets/').replace(/(src|href)="\/consulant_bo\/assets\//g, '$1="/public/assets/');
  const i = h.indexOf(W); if (i < 0) { throw new Error('conteneur des boutiques introuvable'); }
  const j = finDiv(h, i);
  h = h.slice(0, i) + W + bande() + SHOPS.map(boutique).join('') + '</div>' + h.slice(j);
  const k = h.indexOf('>Journée<'); const r0 = h.lastIndexOf('<div ', k); const r1 = finDiv(h, r0);
  h = h.slice(0, r1 - 6) + TOGGLE + h.slice(r1 - 6);
  h = h.replace('</head>', '<link rel="stylesheet" href="/public/assets/css/controles-photo.css"><link rel="stylesheet" href="../controle-taches-photos/ct.css"><link rel="stylesheet" href="cia.css"></head>');
  return h;
}
/** Remplace la première occurrence, et casse si elle manque : une maquette qui rate en silence ment. */
function sub(h, a, b) { const i = h.indexOf(a); if (i < 0) { throw new Error('introuvable : ' + a.slice(0, 60)); } return h.slice(0, i) + b + h.slice(i + a.length); }

/* --- Le volet : « Photo du comptoir - Tartes » de Gosselies, la proposition remplie */
function volet() {
  const p = P.p['3-1209'], [nomN, coul] = NIV[p.n], r = p.repere;
  let h = page('contexte-volet.html');
  h = h.replace(/(src|href)="https:\/\/[^"]*cloudflarestorage[^"]*"/g, '$1="../controle-taches-photos/photos/3-1209.jpg"');
  h = sub(h, 'margin:4px 0 0;line-height:1.2">Photo du comptoir - Viennoiseries</h2>', 'margin:4px 0 0;line-height:1.2">Photo du comptoir - Tartes</h2>');
  h = h.replace(/(alt="Photo de réalisation"[^>]*>)/, `$1<span class="ia-rep" style="left:${r.x}%;top:${r.y}%;width:${r.w}%;height:${r.h}%"><u>${esc(r.lib)}</u></span>`);
  const a = h.indexOf('<!-- Assistance IA'); const b = h.indexOf('<div style="display:flex;align-items:baseline;justify-content:space-between;gap:10px;margin:22px 0 8px">', a);
  if (a < 0 || b < 0) { throw new Error('bloc assistance introuvable'); }
  const prop = `<div class="ia-prop">
        <div class="hd"><div class="ti">Proposition de l’IA</div><span class="pi">confiance ${p.conf}</span></div>
        <div class="nv"><i style="background:${coul}"></i>${nomN}<small>${p.n} / 5 · sous le seuil (${D.seuil})</small></div>
        <ul>${p.constats.map(c => `<li>${esc(c)}</li>`).join('')}</ul>
        <div class="src">Lue à ${P.passage} par la pré-notation, contre le barème du réseau et la photo de référence quand la tâche en a une. Le repère orange sur la photo vient de l’IA. Rien ne part au magasin avant votre validation.</div>
      </div>

      `;
  h = h.slice(0, a) + prop + h.slice(b);
  h = sub(h, 'color:var(--color-text-muted)">Votre évaluation</div>', 'color:var(--color-text-muted)">Votre évaluation</div><span class="ia-pre">pré-remplie par la proposition · un autre niveau la corrige</span>');
  h = h.replace(/(<button data-h="\d+" style="[^"]*")(>\s*<span[^>]*background:#D97706"><\/span>\s*<span style="flex:1">Non conforme — mineur)(<\/span>)/, '$1 class="ia-sel"$2<span class="ia-pp">proposé</span>$3');
  if (!h.includes('class="ia-sel"')) { throw new Error('niveau proposé introuvable'); }
  h = h.replace(/(<textarea data-c="\d+"[^>]*>)(<\/textarea>)/, `$1${esc(p.commentaire)}$2`);
  h = sub(h, '>Envoyer la note</button>', '>Valider et envoyer</button>');
  h = sub(h, 'est recopiée dans le journal des avis.', 'est recopiée dans le journal des avis, avec « proposée par l’IA, validée par vous ».');
  return h;
}

const PLANCHE = { titre: 'Contrôle qualité : l’IA pré-note, le consultant valide',
  acc: 'Même page Tâches › Contrôle, en carrousels. Chaque matin, l’IA a déjà lu toutes les photos rendues : chaque carte porte sa proposition (niveau du barème, confiance, premier constat), les boutiques qui ont des écarts passent devant, et une bande résume le passage. Les conformes à confiance haute se valident en un clic ; les écarts et les photos à revoir s’ouvrent un par un, la proposition pré-remplissant le niveau et le commentaire. Rien ne part au panel sans le geste du consultant. Tâches et photos réelles du jeudi 1er octobre (Gosselies 11, Halle 8) ; les propositions sont rédigées pour la maquette et le taux d’accord est un exemple.',
  plus: ['Le consultant commence par les 4 écarts et les 4 photos à revoir, pas par 19 photos à ouvrir', 'Les conformes sûrs (confiance haute) se valident par lot : 7 clics de moins ce matin-là', 'La proposition reste dans le barème du réseau (5 niveaux, seuil 4) : comparable aux notes des consultants', 'Les photos inexploitables (le sol, un frigo trop loin) sont repérées le jour même : on peut demander de refaire la photo', 'Le taux d’accord avec vos notes dit quand on peut lui faire confiance, et quand allumer l’option « noter seul »'],
  moins: ['Sans photo de référence ni critère par tâche, l’IA juge sur une idée générale de la vitrine', 'Un appel par photo : la proposition est gardée en base pour ne jamais payer deux fois', 'Le magasin peut contester : le journal doit dire qui a proposé et qui a validé'],
  faire: ['Une tâche planifiée toutes les heures : les photos « à contrôler » passent par la notation existante (src/anthropic.php)', 'Une table pil_cq_ia : niveau, confiance, constats, commentaire, repère, modèle', 'Demander au modèle la zone de l’écart (le repère) en plus des constats', 'Les pastilles, la bande et « Valider les conformes sûrs » dans Tâches › Contrôle', 'La mesure de l’accord IA / consultants sur l’historique (mac_task_review)'] };

(async () => {
  fs.writeFileSync(path.join(OUT, 'a.html'), page('contexte.html'));
  fs.writeFileSync(path.join(OUT, 'b.html'), volet());
  const URL = 'http://127.0.0.1:8099/docs/maquettes/controle-qualite-ia/';
  const b = await chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost'] });
  const p = await b.newPage({ viewport: { width: 1400, height: 1260 }, deviceScaleFactor: 2 });
  await p.goto(URL + 'a.html', { waitUntil: 'load' }); await p.waitForTimeout(800);
  await p.evaluate(() => { const e = document.querySelector('.cq-vue').parentElement; e.scrollIntoView({ block: 'start' }); window.scrollBy(0, -22); });
  await p.waitForTimeout(300);
  await p.screenshot({ path: path.join(OUT, 'a.jpg'), type: 'jpeg', quality: 82 });
  console.log('✓ a · largeur', await p.evaluate(() => document.documentElement.scrollWidth), '· cartes', await p.$$eval('.db-cqc', l => l.length),
    '· photos', await p.$$eval('.db-cqph img', l => l.filter(i => i.naturalWidth > 0).length + '/' + l.length));
  await p.setViewportSize({ width: 1400, height: 1340 });
  await p.goto(URL + 'b.html', { waitUntil: 'load' }); await p.waitForTimeout(800);
  await p.evaluate(() => { const e = document.querySelector('.ia-band'); e.scrollIntoView({ block: 'start' }); window.scrollBy(0, -22); });
  await p.waitForTimeout(300);
  await p.screenshot({ path: path.join(OUT, 'b.jpg'), type: 'jpeg', quality: 82 });
  console.log('✓ b · photo du volet', await p.evaluate(() => { const i = [...document.images].find(x => /3-1209/.test(x.src) && x.alt === 'Photo de réalisation'); return i ? i.naturalWidth + 'x' + i.naturalHeight : 'absente'; }));
  const html = `<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>${esc(PLANCHE.titre)}</title><link rel="stylesheet" href="/public/assets/ds/global.css"><link rel="stylesheet" href="../controle-taches-photos/ct.css"><link rel="stylesheet" href="cia.css"></head>
  <body><div class="pl"><span class="u">Cockpit · Tâches › Contrôle · maquette · photos réelles du 01/10/2026, propositions rédigées pour la maquette</span><h1>${esc(PLANCHE.titre)}</h1><p class="acc">${esc(PLANCHE.acc)}</p>
  <div class="g"><div style="flex:1;min-width:0"><img src="a.jpg" style="width:100%"><div class="leg">La page : la bande de pré-notation, puis les boutiques, écarts proposés en tête</div><img src="b.jpg" style="width:100%;margin-top:18px"><div class="leg">Un écart ouvert : la proposition, le repère sur la photo, le niveau et le commentaire pré-remplis</div></div>
  <div class="col"><h4>Ce que ça apporte</h4><ul class="pl-p">${PLANCHE.plus.map(x => `<li>${esc(x)}</li>`).join('')}</ul><h4>Limites</h4><ul class="pl-m">${PLANCHE.moins.map(x => `<li>${esc(x)}</li>`).join('')}</ul><h4>À construire</h4><ul class="pl-c">${PLANCHE.faire.map(x => `<li>${esc(x)}</li>`).join('')}</ul></div></div></div></body></html>`;
  fs.writeFileSync(path.join(OUT, 'planche.html'), html);
  const q = await b.newPage({ viewport: { width: 1500, height: 900 }, deviceScaleFactor: 1.5 });
  await q.goto(URL + 'planche.html', { waitUntil: 'load' }); await q.waitForTimeout(600);
  await q.screenshot({ path: path.join(OUT, 'planche.jpg'), type: 'jpeg', quality: 82, fullPage: true });
  console.log('✓ planche');
  await b.close();
})().catch(e => { console.error(e); process.exit(1); });
