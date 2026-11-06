/* Maquette : dans le CONTRÔLE GUIDÉ du consultant (application visites, au
 * téléphone), une étape « Les contrôles en photo » qui reprend le carrousel du
 * dashboard magasin — les photos que l'équipe a rendues dans la journée, avec
 * leur état, et la photo en grand d'un toucher.
 *
 * Données RÉELLES : Gosselies (shop 3), jeudi 01/10/2026 — les 16 tâches du
 * panel (donnees.json), les 11 photos rendues (photos/). Rien n'est inventé,
 * sauf la visite du consultant elle-même (il n'y en a pas ce jour-là).
 *
 *   node docs/maquettes/controle-photos/generer.js   (serveur statique sur 8099 depuis la racine)
 */
const fs = require('fs'), path = require('path');
const { chromium } = require('/opt/node22/lib/node_modules/playwright');
const OUT = __dirname;
const D = require('./donnees.json');
const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const hh = v => v ? String(v).slice(11, 16) : '';
// Le CSS du module visites vit dans visites.js ; celui du carrousel dans dashboard.css. Repris tels quels.
const VI = fs.readFileSync(path.join(__dirname, '../../../public/assets/js/visites.js'), 'utf8');
const VI_CSS = VI.slice(VI.indexOf('const CSS = `') + 13, VI.indexOf('\n`;', VI.indexOf('const CSS = `')));
const CQ_CSS = fs.readFileSync(path.join(__dirname, '../../../public/dashboard/dashboard.css'), 'utf8').split('\n')
  .filter(l => /^(\.db-cq|\.cqbd|\.db-cql|html\.db-cq-ouvert|@keyframes db-cqsk)/.test(l) || (/^@media/.test(l) && /db-cq/.test(l))).join('\n');

/* --- Les tâches, comme le dashboard les lit ------------------------------- */
const nom = t => String(t.tache).replace(/^Photo du comptoir\s*-\s*/i, 'Comptoir · ').replace(/^Contrôle Qualité\s*[–-]\s*/i, 'CQ · ');
const cl = t => String(t.checklist || '').replace(/\.$/, '').replace(/^[A-Z]{2}-?[A-Z0-9]+\s*[—–-]\s*/i, '');
const etat = t => t.note != null ? (t.note >= D.seuil ? { c: 'ok', bd: t.note + '/5' } : { c: 'nc', bd: t.note + '/5 · écart' })
  : t.statut === 'aControler' || t.statut === 'aValider' ? { c: 'ctl', bd: 'à contrôler' }
  : t.statut === 'sansPhoto' ? { c: 'mu', bd: 'sans photo' } : { c: 'ko', bd: 'non rendue' };
const RANG = { nc: 0, ctl: 1, ok: 2, ko: 3, mu: 4 };
const T = D.taches.map(t => Object.assign({}, t, { e: etat(t) }))
  .sort((a, b) => (RANG[a.e.c] - RANG[b.e.c]) || String(a.faitLe || '9').localeCompare(String(b.faitLe || '9')) || nom(a).localeCompare(nom(b)));
const nb = c => T.filter(t => t.e.c === c).length;
const PH = T.filter(t => t.photo);
const heures = PH.map(t => hh(t.faitLe)).sort();
const qui = [...new Set(PH.map(t => t.faitePar).filter(Boolean))].join(', ');

function photo(t, o = {}) {
  if (!t.photo) {
    return `<span class="db-cqph vide ${t.e.c}${o.cls ? ' ' + o.cls : ''}"><b>${t.e.c === 'ko' ? '✗' : '—'}</b>${o.mini ? '' : esc(t.e.c === 'ko' ? 'pas encore rendue' : 'sans photo')}</span>`;
  }
  return `<span class="db-cqph${o.cls ? ' ' + o.cls : ''}"><img src="photos/${t.taskId}.jpg" alt="">${o.heure === false ? '' : `<em class="h">${hh(t.faitLe)}</em>`}${o.badge === false ? '' : `<em class="cqbd ${t.e.c}">${esc(t.e.bd)}</em>`}</span>`;
}
const meta = t => t.e.c === 'ko' ? 'pas encore rendue' : hh(t.faitLe) + (t.faitePar ? ' · ' + esc(t.faitePar) : '');
const constat = t => t.e.c === 'ctl' ? '<span class="c ctl">photo déposée, pas encore notée</span>'
  : t.e.c === 'ko' ? `<span class="c ko">${esc(cl(t) || 'non rendue')}</span>` : '';
const carte = t => `<button type="button" class="db-cqc">${photo(t)}<span class="n">${esc(nom(t))}</span><span class="m">${meta(t)}</span>${constat(t)}</button>`;

/* --- L'étape « Les contrôles en photo » ------------------------------------ */
const FILTRES = [['tout', 'Tout', null, T.length], ['ctl', 'À contrôler', '#2F5D8A', nb('ctl')], ['ko', 'Non rendues', '#C0182B', nb('ko')]].filter(f => f[3]);
const ETAPE = `<div class="cp-res"><em>${PH.length} photo${PH.length > 1 ? 's' : ''}</em> rendue${PH.length > 1 ? 's' : ''} de ${heures[0]} à ${heures[heures.length - 1]} par ${esc(qui)} · pas encore notées · ${nb('ko')} tâche${nb('ko') > 1 ? 's' : ''} non rendue${nb('ko') > 1 ? 's' : ''}</div>
  <div class="cp-f">${FILTRES.map(f => `<button class="chip ${f[0] === 'tout' ? 'on' : ''}">${f[2] ? `<i style="background:${f[2]}"></i>` : ''}${f[1]}<b>${f[3]}</b></button>`).join('')}</div>
  <div class="db-cqpiste">${T.map(carte).join('')}</div>
  <div class="cp-pied"><span class="pts"><i class="on"></i><i></i><i></i><i></i><i></i><i></i><i></i></span><span>${T.length} contrôles · les écarts d’abord · toucher une photo l’ouvre en grand</span></div>
  <div class="btns"><button class="btn p w">Étape suivante ›</button></div>`;

/* --- Le contrôle guidé : la liste des étapes, la 2e ouverte ------------------ */
const ETAPES = [['Photo du jour', '3 / 3', true], ['Les contrôles en photo', PH.length + ' / ' + T.length, false, true], ['Les chiffres et les alertes', 'à lire'],
  ['Produit', '0 / 5'], ['Hygiène', '0 / 5'], ['Visuel', '0 / 4'], ['Planogramme', '0 / 3'], ['Assortiment', '0 / 3'], ['Mystery shopper', '0 / 3'],
  ['Vu sur place et recommandation', 'à écrire'], ['Plan d’action et fin de visite', '0 action']];
const faits = ETAPES.filter(e => e[2]).length;
const CONTROLE = `<div class="hd"><button class="retour">‹</button><div><h2>Contrôle — Gosselies</h2><div class="d">jeu. 1 oct. · depuis 09:05 · Sam V.</div></div><span class="sp"></span><span class="pill">2 / ${ETAPES.length}</span></div>
  <div class="bar" style="margin:2px 0 12px"><i style="width:${Math.round(faits / ETAPES.length * 100)}%"></i></div>`
  + ETAPES.map((e, i) => `<div class="et ${e[3] ? 'on' : ''} ${e[2] ? 'ok' : ''}"><div class="et-h"><span class="num">${e[2] ? '✓' : i + 1}</span><b>${esc(e[0])}</b><span class="sp"></span><span class="xs mu">${esc(e[1])}</span></div>${e[3] ? `<div class="et-c">${ETAPE}</div>` : ''}</div>`).join('');

/* --- La photo en grand (Viennoiseries, 7e des 16) ---------------------------- */
const V = T.find(t => /Viennoiseries/.test(t.tache)); const iV = T.indexOf(V);
const LOUPE = `<div class="db-cql mob" role="dialog"><div class="hd"><span>${iV + 1} / ${T.length}</span><button type="button" class="x">✕</button></div>
  <div class="ph">${photo(V, { heure: false, badge: false, cls: 'max' })}</div>
  <div class="bas"><div class="fi"><em class="cqbd ${V.e.c}">${esc(V.e.bd)}</em><h3>${esc(nom(V))}</h3><div class="q">${esc(cl(V))}</div>
    <dl><dt>Rendue</dt><dd>à ${hh(V.faitLe)} par ${esc(V.faitePar)}</dd><dt>Notée</dt><dd>pas encore notée</dd>${V.maitrise && V.maitrise.moyenne != null ? `<dt>Tenue</dt><dd>${String(Number(V.maitrise.moyenne).toFixed(1)).replace('.', ',')} / 5 sur ${V.maitrise.nb} contrôle${V.maitrise.nb > 1 ? 's' : ''}</dd>` : ''}</dl></div>
    <div class="act"><button type="button">‹ Précédente</button><button type="button" class="p">Suivante ›</button></div></div></div>`;

const page = (titre, corps, extra) => `<!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>${esc(titre)}</title>
<link rel="stylesheet" href="/public/assets/ds/global.css">
<style>${VI_CSS}</style><style>${CQ_CSS}</style>
<link rel="stylesheet" href="cp.css"></head>
<body><div class="en-tete"><img src="/public/assets/img/logo.png" alt=""><div><div class="t">Visites terrain</div><div class="d">Sam V.</div></div><span class="sp"></span><div class="av">SV</div></div>
<div class="vi"><div class="sc">${corps}</div></div>${extra || ''}</body></html>`;

const ECRANS = [['a', 'A — le contrôle guidé, étape « Les contrôles en photo »', CONTROLE, ''], ['a-loupe', 'A — la photo en grand', CONTROLE, LOUPE]];
const PLANCHE = { id: 'a', titre: 'Les contrôles en photo, dans le contrôle guidé du consultant',
  acc: 'Pendant la visite, juste après la photo du jour, le consultant voit ce que l’équipe a rendu depuis l’ouverture : le même carrousel que le dashboard magasin (photo, heure, état, qui l’a rendue), les écarts d’abord, les non rendues en fin de piste. Un toucher ouvre la photo en grand avec sa fiche ; on passe de l’une à l’autre sans quitter la visite. Données réelles de Gosselies, jeudi 1er octobre : 16 tâches, 11 photos rendues entre 03:56 et 12:57, aucune encore notée.',
  plus: ['Le consultant contrôle sur place ce que la photo du matin montrait : même objet, même état, même heure', 'Une seule source : /pwa/tasks/photos, déjà lue par le dashboard — rien de nouveau au serveur', 'Les écarts (notés ≤ 3) viendraient en tête, en orange, avec le repère dessiné sur la photo', 'L’étape se replie comme les autres : « 11 / 16 » reste lisible dans la liste'],
  moins: ['Lecture seule : la note se pose toujours dans le panel, pas ici', 'Au téléphone, deux cartes et demie visibles : il faut glisser pour voir les 16', 'Les URL des photos expirent après 20 min : une relecture silencieuse quand l’étape se rouvre'] };

(async () => {
  for (const [id, titre, corps, extra] of ECRANS) { fs.writeFileSync(path.join(OUT, id + '.html'), page(titre, corps, extra)); }
  const b = await chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost'] });
  for (const [id] of ECRANS) {
    const p = await b.newPage({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2 });
    await p.goto('http://127.0.0.1:8099/docs/maquettes/controle-photos/' + id + '.html', { waitUntil: 'load' });
    await p.waitForTimeout(500);
    if (id === 'a') {
      const h = await p.evaluate(() => document.body.scrollHeight + 2);
      await p.setViewportSize({ width: 390, height: Math.max(844, h) }); await p.waitForTimeout(200);
      await p.screenshot({ path: path.join(OUT, id + '.jpg'), type: 'jpeg', quality: 88 });
      await p.setViewportSize({ width: 390, height: 844 }); await p.waitForTimeout(200);
      await p.screenshot({ path: path.join(OUT, id + '-ecran.jpg'), type: 'jpeg', quality: 88 });
      const et = await p.$('.et.on'); await et.screenshot({ path: path.join(OUT, id + '-etape.png') });
      console.log('✓', id, 'hauteur', h, '· débordement', await p.evaluate(() => document.documentElement.scrollWidth));
    } else {
      await p.screenshot({ path: path.join(OUT, id + '.jpg'), type: 'jpeg', quality: 88 });
      console.log('✓', id);
    }
    await p.close();
  }
  const html = `<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>${esc(PLANCHE.titre)}</title><link rel="stylesheet" href="/public/assets/ds/global.css"><link rel="stylesheet" href="cp.css"></head>
  <body><div class="pl"><span class="u">Contrôle guidé · maquette · données réelles de Gosselies, 01/10/2026</span><h1>${esc(PLANCHE.titre)}</h1><p class="acc">${esc(PLANCHE.acc)}</p>
  <div class="g"><div><img src="a-ecran.jpg" width="390"><div class="leg">L’étape ouverte, au téléphone</div></div><div><img src="a-loupe.jpg" width="390"><div class="leg">La photo en grand, sa fiche, ‹ ›</div></div>
  <div class="col"><h4>Ce que ça apporte</h4><ul class="pl-p">${PLANCHE.plus.map(x => `<li>${esc(x)}</li>`).join('')}</ul><h4>Limites</h4><ul class="pl-m">${PLANCHE.moins.map(x => `<li>${esc(x)}</li>`).join('')}</ul></div></div></div></body></html>`;
  fs.writeFileSync(path.join(OUT, 'planche-a.html'), html);
  const p = await b.newPage({ viewport: { width: 1240, height: 900 }, deviceScaleFactor: 1.5 });
  await p.goto('http://127.0.0.1:8099/docs/maquettes/controle-photos/planche-a.html', { waitUntil: 'load' }); await p.waitForTimeout(600);
  await p.screenshot({ path: path.join(OUT, 'planche-a.jpg'), type: 'jpeg', quality: 86, fullPage: true });
  console.log('✓ planche');
  await b.close();
})().catch(e => { console.error(e); process.exit(1); });
