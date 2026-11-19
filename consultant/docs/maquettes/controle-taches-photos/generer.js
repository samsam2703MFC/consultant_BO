/* Maquette : dans le cockpit, page Tâches › Contrôle (#/controle-taches), les
 * tâches de chaque boutique en CARROUSEL DE PHOTOS — le même que « Les
 * contrôles en photo » du dashboard magasin — à la place du tableau. Un clic
 * sur une carte ouvre le volet de notation existant (photo + barème).
 *
 * Données RÉELLES, jeudi 01/10/2026 : les 64 tâches des 4 boutiques
 * (donnees.json, lues par /pwa/tasks?date=), les 19 photos rendues (photos/,
 * lues par /pwa/tasks/photos?shop=&date=). Le reste de la page est le DOM du
 * cockpit en ligne (contexte.html ; contexte-volet.html avec le volet ouvert
 * sur « Photo du comptoir - Viennoiseries » de Gosselies).
 *
 *   node docs/maquettes/controle-taches-photos/generer.js   (serveur statique sur 8099 depuis la racine)
 */
const fs = require('fs'), path = require('path');
const { chromium } = require('/opt/node22/lib/node_modules/playwright');
const OUT = __dirname;
const D = require('./donnees.json');
const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const hh = v => v ? String(v).slice(11, 16) : '';
const CQ_CSS = fs.readFileSync(path.join(__dirname, '../../../public/dashboard/dashboard.css'), 'utf8').split('\n')
  .filter(l => /^(\.db-cq|\.cqbd|\.db-cql|html\.db-cq-ouvert|@keyframes db-cqsk)/.test(l) || (/^@media/.test(l) && /db-cq/.test(l))).join('\n');

/* --- Les tâches, comme le dashboard les lit ------------------------------- */
const nom = t => String(t.tache).replace(/^Photo du comptoir\s*-\s*/i, 'Comptoir · ').replace(/^Contrôle Qualité\s*[–-]\s*/i, 'CQ · ');
const cl = t => String(t.checklist || '').replace(/\.$/, '').replace(/^[A-Z]{2}-?[A-Z0-9]+\s*[—–-]\s*/i, '');
const etat = t => t.note != null ? (t.note >= D.seuil ? { c: 'ok', bd: t.note + '/5' } : { c: 'nc', bd: t.note + '/5 · écart' })
  : t.statut === 'aControler' || t.statut === 'aValider' ? { c: 'ctl', bd: 'à contrôler' }
  : t.statut === 'sansPhoto' ? { c: 'mu', bd: 'sans photo' } : { c: 'ko', bd: 'non rendue' };
const RANG = { nc: 0, ctl: 1, ok: 2, ko: 3, mu: 4 };
const tri = L => L.slice().sort((a, b) => (RANG[a.e.c] - RANG[b.e.c]) || String(a.faitLe || '9').localeCompare(String(b.faitLe || '9')) || nom(a).localeCompare(nom(b)));

function photo(s, t, o = {}) {
  if (!t.photo) {
    return `<span class="db-cqph vide ${t.e.c}"><b>${t.e.c === 'ko' ? '✗' : '—'}</b>${esc(t.e.c === 'ko' ? 'pas encore rendue' : 'sans photo')}</span>`;
  }
  return `<span class="db-cqph"><img src="photos/${s.shopId}-${t.taskId}.jpg" alt=""><em class="h">${hh(t.faitLe)}</em><em class="cqbd ${t.e.c}">${esc(t.e.bd)}</em></span>`;
}
const meta = t => t.e.c === 'ko' ? 'pas encore rendue' : hh(t.faitLe) + (t.faitePar ? ' · ' + esc(t.faitePar) : '');
const constat = t => t.e.c === 'ctl' ? '<span class="c ctl">photo déposée, pas encore notée</span>'
  : t.e.c === 'ko' ? `<span class="c ko">${esc(cl(t) || 'non rendue')}</span>` : '';
const bouton = t => t.e.c === 'ctl' ? '<span class="cq-noter">Noter</span>' : (t.e.c === 'ok' || t.e.c === 'nc' ? '<span class="cq-noter re">Renoter</span>' : '');
const carte = (s, t) => `<button type="button" class="db-cqc" title="Voir la photo et noter">${photo(s, t)}<span class="n">${esc(nom(t))}</span><span class="m">${meta(t)}</span>${constat(t)}${bouton(t)}</button>`;

/* --- Une boutique : l'en-tête, les filtres, le rail, le pied ---------------- */
const CARD = 'background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;overflow:hidden';
function boutique(s) {
  const T = tri(s.taches.map(t => Object.assign({}, t, { e: etat(t) })));
  const nb = c => T.filter(t => t.e.c === c).length;
  const PH = T.filter(t => t.photo);
  const notees = T.filter(t => t.note != null).length;
  const heures = PH.map(t => hh(t.faitLe)).sort();
  const qui = [...new Set(PH.map(t => t.faitePar).filter(Boolean))].join(', ');
  const resume = PH.length ? `${PH.length} photo${PH.length > 1 ? 's' : ''} rendue${PH.length > 1 ? 's' : ''} ${heures[0] === heures[heures.length - 1] ? 'à ' + heures[0] : 'de ' + heures[0] + ' à ' + heures[heures.length - 1]} par ${esc(qui)}` : 'aucune photo rendue aujourd’hui';
  const puces = [['tout', 'Tout', null, T.length], ['nc', 'Écarts', '#D97706', nb('nc')], ['ctl', 'À contrôler', '#2F5D8A', nb('ctl')], ['ok', 'Conformes', '#2d7a3e', nb('ok')], ['ko', 'Non rendues', '#C0182B', nb('ko')], ['mu', 'Sans photo', '#a59d93', nb('mu')]]
    .filter(f => f[3]).map(f => `<button type="button" class="${f[0] === 'tout' ? 'on' : ''}">${f[2] ? `<i style="background:${f[2]}"></i>` : ''}${f[1]}<b>${f[3]}</b></button>`).join('');
  const tete = `<div style="padding:13px 18px;border-bottom:0.5px solid var(--color-border-tertiary);display:flex;align-items:center;gap:12px;flex-wrap:wrap">
      <div style="font-size:13.5px;font-weight:600">${esc(s.shop)}</div><span class="db-cqf">${puces}</span>
      <div style="font-size:11.5px;color:var(--color-text-muted);margin-left:auto;text-align:right">${resume} · ${notees} / ${T.length} notée${notees > 1 ? 's' : ''}</div></div>`;
  if (!PH.length) {
    return `<div style="${CARD}">${tete}<div style="padding:14px 18px;font-size:12.5px;color:var(--color-text-muted)">${nb('ko')} tâche${nb('ko') > 1 ? 's' : ''} pas encore rendue${nb('ko') > 1 ? 's' : ''}${nb('mu') ? ' · ' + nb('mu') + ' rendue' + (nb('mu') > 1 ? 's' : '') + ' sans photo' : ''} — rien à noter pour l’instant.</div></div>`;
  }
  return `<div style="${CARD}">${tete}
    <div class="db-cqrail"><button type="button" class="db-cqfl g" disabled aria-label="précédentes">‹</button>
      <div class="db-cqpiste">${T.map(t => carte(s, t)).join('')}</div>
      <button type="button" class="db-cqfl d" aria-label="suivantes">›</button></div>
    <div class="db-cqpied">${T.length} contrôles · à contrôler d’abord, non rendues en fin de piste · un clic ouvre la photo et la notation</div></div>`;
}

/* --- La page : le DOM du cockpit, le tableau remplacé par les carrousels ---- */
const W = '<div style="display:flex;flex-direction:column;gap:14px">';
/** L'index juste après la balise fermante du <div> qui commence à `i`. */
function finDiv(h, i) {
  let prof = 0, j = i;
  const re = /<div\b|<\/div>/g; re.lastIndex = i;
  let m;
  while ((m = re.exec(h))) { if (m[0] === '<div') { prof++; } else { prof--; if (prof === 0) { return m.index + 6; } } }
  return -1;
}
const TOGGLE = `<span class="cq-vue"><button type="button" class="on">▦ Photos</button><button type="button">☰ Liste</button></span>`;
function page(src, o = {}) {
  let h = fs.readFileSync(path.join(OUT, src), 'utf8');
  h = h.replace(/<script[\s\S]*?<\/script>/g, '').replace(/(src|href)="assets\//g, '$1="/public/assets/').replace(/(src|href)="\/consulant_bo\/assets\//g, '$1="/public/assets/');
  // Les cartes boutique.
  const i = h.indexOf(W); if (i < 0) { throw new Error('conteneur des boutiques introuvable'); }
  const j = finDiv(h, i);
  h = h.slice(0, i) + W + D.shops.map(boutique).join('') + '</div>' + h.slice(j);
  // La bascule Photos / Liste, au bout de la ligne des filtres.
  const k = h.indexOf('>Journée<'); const r0 = h.lastIndexOf('<div ', k); const r1 = finDiv(h, r0);
  h = h.slice(0, r1 - 6) + TOGGLE + h.slice(r1 - 6);
  // Le volet : la photo signée (expirée hors du cockpit) devient la photo locale.
  if (o.volet) { h = h.replace(/src="https:\/\/[^"]*cloudflarestorage[^"]*"/g, 'src="photos/3-1207.jpg"'); }
  h = h.replace('</head>', `<style>${CQ_CSS}</style><link rel="stylesheet" href="ct.css"></head>`);
  return h;
}
const PLANCHE = { titre: 'Contrôle des tâches : les photos en carrousel, la note au clic',
  acc: 'Même page, mêmes filtres, mêmes chiffres en tête. Sous les filtres, chaque boutique n’est plus un tableau de seize lignes mais le carrousel « Les contrôles en photo » du dashboard magasin : la photo, l’heure, l’état, qui l’a rendue, les écarts d’abord, les non rendues en fin de piste. Un clic sur une carte ouvre le volet de notation tel qu’il existe (photo en boutique, référence, barème, note au consultant). Données réelles du jeudi 1er octobre : quatre boutiques, 64 tâches, 19 photos rendues (Gosselies 11, Halle 8), aucune notée.',
  plus: ['On note en regardant la photo, pas un libellé : la liste devient ce qu’on juge', 'Le même carrousel que le franchisé voit sur son dashboard : une seule façon de lire les contrôles', 'Une seule lecture groupée par boutique (/pwa/tasks/photos), déjà en place — rien de nouveau au serveur', 'Les écarts notés viendraient en tête en orange, avec le repère dessiné sur la photo', 'Le tableau reste à un clic (« Liste ») pour les colonnes commentaire et consultant'],
  moins: ['Seize cartes par boutique : il faut faire défiler pour voir les non rendues', 'Une boutique sans photo rendue n’a rien à montrer : une ligne de texte, pas de carrousel', 'Les URL signées des photos expirent après 20 min : relecture silencieuse comme sur le dashboard'] };

(async () => {
  fs.writeFileSync(path.join(OUT, 'a.html'), page('contexte.html'));
  fs.writeFileSync(path.join(OUT, 'b.html'), page('contexte-volet.html', { volet: true }));
  const b = await chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost'] });
  const p = await b.newPage({ viewport: { width: 1400, height: 1180 }, deviceScaleFactor: 1.5 });
  await p.goto('http://127.0.0.1:8099/docs/maquettes/controle-taches-photos/a.html', { waitUntil: 'load' }); await p.waitForTimeout(800);
  await p.screenshot({ path: path.join(OUT, 'a-full.jpg'), type: 'jpeg', quality: 80, fullPage: true });
  await p.evaluate(() => { const e = document.querySelector('.cq-vue').parentElement; e.scrollIntoView({ block: 'start' }); window.scrollBy(0, -22); });
  await p.waitForTimeout(300);
  await p.screenshot({ path: path.join(OUT, 'a.jpg'), type: 'jpeg', quality: 86 });
  const gos = await p.evaluateHandle(() => [...document.querySelectorAll('[data-screen="controle"] .db-cqrail')][0].closest('div[style*="overflow:hidden"]'));
  await gos.asElement().screenshot({ path: path.join(OUT, 'a-gosselies.png') });
  console.log('✓ a · largeur', await p.evaluate(() => document.documentElement.scrollWidth), '· cartes', await p.$$eval('.db-cqc', l => l.length), '· rails', await p.$$eval('.db-cqrail', l => l.length));
  await p.setViewportSize({ width: 1400, height: 900 });
  await p.goto('http://127.0.0.1:8099/docs/maquettes/controle-taches-photos/b.html', { waitUntil: 'load' }); await p.waitForTimeout(800);
  await p.evaluate(() => { const e = [...document.querySelectorAll('[data-screen="controle"] .db-cqrail')][0].closest('div[style*="overflow:hidden"]'); e.scrollIntoView({ block: 'start' }); window.scrollBy(0, -80); });
  await p.waitForTimeout(300);
  await p.screenshot({ path: path.join(OUT, 'b.jpg'), type: 'jpeg', quality: 86 });
  console.log('✓ b · photo du volet', await p.evaluate(() => { const i = [...document.images].find(x => /photos\/3-1207/.test(x.src)); return i ? i.naturalWidth + 'x' + i.naturalHeight : 'absente'; }));
  const html = `<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>${esc(PLANCHE.titre)}</title><link rel="stylesheet" href="/public/assets/ds/global.css"><link rel="stylesheet" href="ct.css"></head>
  <body><div class="pl"><span class="u">Cockpit · Tâches › Contrôle · maquette · données réelles du 01/10/2026</span><h1>${esc(PLANCHE.titre)}</h1><p class="acc">${esc(PLANCHE.acc)}</p>
  <div class="g"><div style="flex:1;min-width:0"><img src="a.jpg" style="width:100%"><div class="leg">La page : un carrousel par boutique à la place du tableau</div><img src="b.jpg" style="width:100%;margin-top:18px"><div class="leg">Un clic sur une carte : le volet de notation existant, avec la photo</div></div>
  <div class="col"><h4>Ce que ça apporte</h4><ul class="pl-p">${PLANCHE.plus.map(x => `<li>${esc(x)}</li>`).join('')}</ul><h4>Limites</h4><ul class="pl-m">${PLANCHE.moins.map(x => `<li>${esc(x)}</li>`).join('')}</ul></div></div></div></body></html>`;
  fs.writeFileSync(path.join(OUT, 'planche-a.html'), html);
  const q = await b.newPage({ viewport: { width: 1500, height: 900 }, deviceScaleFactor: 1.4 });
  await q.goto('http://127.0.0.1:8099/docs/maquettes/controle-taches-photos/planche-a.html', { waitUntil: 'load' }); await q.waitForTimeout(600);
  await q.screenshot({ path: path.join(OUT, 'planche-a.jpg'), type: 'jpeg', quality: 84, fullPage: true });
  console.log('✓ planche');
  await b.close();
})().catch(e => { console.error(e); process.exit(1); });
