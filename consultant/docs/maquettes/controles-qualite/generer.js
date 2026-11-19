/* Quatre propositions pour que le franchisé voie les contrôles qualité quand
 * il n'est pas au magasin. Données RÉELLES : Gosselies (shop 3), jeudi
 * 24/09/2026 — les 16 tâches du panel, les 9 photos rendues, la note et le
 * repère posés par le consultant. Le haut de page est le DOM du dashboard en
 * ligne, recopié tel quel (contexte.json).
 *
 *   node docs/maquettes/controles-qualite/generer.js
 *   (serveur statique sur 8099 depuis la racine du dépôt)
 */
const fs = require('fs'), path = require('path');
const { chromium } = require('/opt/node22/lib/node_modules/playwright');
const OUT = __dirname;
const T = require('./donnees.json');
const CTX = require('./contexte.json');
const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const hh = v => v ? String(v).slice(11, 16) : '';
const NIV = { 1: 'critique', 2: 'majeure', 3: 'mineure' };

/* --- L'état d'une tâche, vu du franchisé ------------------------------------ */
function etat(t) {
  if (t.statut === 'notee') {
    return t.note >= 4 ? { c: 'ok', lib: 'Conforme', bd: t.note + '/5' }
      : { c: 'nc', lib: 'Non-conformité ' + (NIV[t.note] || ''), bd: t.note + '/5 · ' + (NIV[t.note] || 'écart') };
  }
  if (t.statut === 'aControler') { return { c: 'ctl', lib: 'Rendue, pas encore contrôlée', bd: 'à contrôler' }; }
  if (t.statut === 'sansPhoto') { return { c: 'mu', lib: 'Clôturée sans photo', bd: 'sans photo' }; }
  return { c: 'ko', lib: 'Non rendue', bd: 'non rendue' };
}
const court = t => /^Contrôle Qualité/i.test(t.tache) ? 'CQ · ' + t.court : (/^Photo du comptoir/i.test(t.tache) ? 'Comptoir · ' + t.court : t.court);
const clCourt = c => String(c || '').replace(/\.$/, '');
// Les écarts d'abord, puis l'attente, puis le conforme dans l'ordre de la journée, puis le manquant.
const RANG = { nc: 0, ctl: 1, ok: 2, ko: 3, mu: 4 };
const TRI = T.slice().sort((a, b) => (RANG[etat(a).c] - RANG[etat(b).c]) || String(a.faitLe || '9').localeCompare(String(b.faitLe || '9')) || a.court.localeCompare(b.court));
const nb = c => T.filter(t => etat(t).c === c).length;
const PH = T.filter(t => t.photo);
const moy = (PH.filter(t => t.note != null).reduce((a, t) => a + t.note, 0) / PH.filter(t => t.note != null).length).toFixed(1).replace('.', ',');
const TRAITEUR = T.find(t => t.id === '1210');

/** La photo, ses repères numérotés, la pastille d'état ; ou la case vide. */
function photo(t, o = {}) {
  const e = etat(t);
  if (!t.photo) {
    return `<div class="cq-ph vide ${e.c === 'ko' ? 'ko' : ''}"${o.style ? ` style="${o.style}"` : ''}><span class="i">${e.c === 'ko' ? '✗' : '—'}</span>${o.mini ? '' : esc(e.c === 'ko' ? 'pas de photo' : 'clôturée sans photo')}${o.mini ? '' : `<span style="font-weight:500;font-size:9.5px">${esc(e.c === 'ko' ? 'non rendue' : 'le 26/09 à 05:03')}</span>`}</div>`;
  }
  const rp = (t.reperes || []).map(r => `<i class="rp" style="left:${(r.x * 100).toFixed(1)}%;top:${(r.y * 100).toFixed(1)}%;width:${(r.l * 100).toFixed(1)}%;height:${(r.h * 100).toFixed(1)}%">${o.mini ? '' : `<u>${r.n}. ${esc(r.txt)}</u>`}</i>`).join('');
  return `<div class="cq-ph${o.cls ? ' ' + o.cls : ''}"${o.style ? ` style="${o.style}"` : ''}><img src="photos/${t.id}.jpg" alt="">${rp}${o.heure === false ? '' : `<span class="h">${hh(t.faitLe)}</span>`}${o.badge === false ? '' : `<span class="cq-bd ${e.c}">${esc(o.bdCourt ? (t.note != null ? t.note + '/5' : e.bd) : e.bd)}</span>`}</div>`;
}
const meta = t => t.faitLe ? (t.statut === 'sansPhoto' ? 'clôturée le 26/09 à 05:03' : hh(t.faitLe) + ' · ' + esc(t.faitePar || '')) : 'pas rendue ce jour';
const constat = t => {
  const e = etat(t);
  if (e.c === 'nc') { return `<div class="c cq-t-nc">${esc((t.reperes[0] || {}).txt || t.comment || 'écart')} · noté ${hh(t.valideeLe)}</div>`; }
  if (e.c === 'ok') { return `<div class="c cq-t-ok">conforme · noté ${hh(t.valideeLe)}</div>`; }
  if (e.c === 'ctl') { return `<div class="c cq-t-ctl">photo déposée, pas encore notée</div>`; }
  if (e.c === 'mu') { return `<div class="c cq-t-mu">clôturée automatiquement</div>`; }
  return `<div class="c cq-t-ko">${esc(clCourt(t.checklist).replace(/^[A-Z]{2}-?[A-Z0-9]+\s*[—–-]\s*/i, ''))}</div>`;
};
const filtres = sel => `<span class="cq-f">
  <span class="${sel === 'tout' ? 'on' : ''}">Tout<b>${T.length}</b></span>
  <span><i style="background:#D97706"></i>Écarts<b>${nb('nc')}</b></span>
  <span><i style="background:#2F5D8A"></i>À contrôler<b>${nb('ctl')}</b></span>
  <span><i style="background:#2d7a3e"></i>Conformes<b>${nb('ok')}</b></span>
  <span><i style="background:#C0182B"></i>Non rendues<b>${nb('ko')}</b></span>
  <span><i style="background:#a59d93"></i>Sans photo<b>${nb('mu')}</b></span></span>`;

/* --- Les pages ------------------------------------------------------------- */
const page = (titre, corps) => `<!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>${esc(titre)}</title>
<link rel="stylesheet" href="/public/assets/ds/global.css">
<link rel="stylesheet" href="/public/dashboard/dashboard.css">
<link rel="stylesheet" href="cq.css"></head>
<body>${corps}</body></html>`;
const [HD, NAV, NCBAR, BENCH, TACHES, STBAR, SEC, TUILES, SPLIT] = CTX.bureau;
const bureau = (avant, apres, o = {}) => `<div id="dash">${HD}${NAV}${o.sansNc ? '' : NCBAR}${avant}${o.sansBench ? '' : BENCH}${o.taches || TACHES}${apres}${o.sansSuite ? '' : STBAR + SEC + TUILES + SPLIT}</div>`;
const mobile = (avant, o = {}) => `<div id="dash" class="mob">${CTX.mobile.hd}<div class="mb-sc">${o.page || (CTX.mobile.rangs[0] + avant + '<div class="mb-mur">' + CTX.mobile.rangs.slice(1).join('') + '</div>')}</div>${o.tabs || CTX.mobile.tabs}</div>${o.dessus || ''}`;

/* A — le carrousel ------------------------------------------------------------ */
const carte = (t, sel, sansConstat) => `<div class="cq-carte${sel ? ' sel' : ''}">${photo(t, { bdCourt: !!sansConstat, heure: !sansConstat })}<div class="n">${esc(sansConstat ? t.court : court(t))}</div><div class="m">${meta(t)}</div>${sansConstat ? '' : constat(t)}</div>`;
const A_BLOC = `<div class="db-card cq-car">
  <div class="ct"><span class="db-lab">Les contrôles en photo — jeudi 24/09</span>${filtres('tout')}
    <span class="db-mini">9 photos rendues de 04:09 à 04:11 par Obchette S. · notées de 13:54 à 13:56 par Sam Verheyden · moyenne ${moy} / 5</span></div>
  <div class="cq-rail"><span class="cq-fl g">‹</span>
    <div class="cq-piste">${TRI.map(t => carte(t, false)).join('')}</div>
    <span class="cq-fl d">›</span></div>
  <div class="cq-pts"><i class="on"></i><i></i><i></i><span style="margin-left:6px">1 – 6 sur 16 · les écarts d’abord · un clic ouvre la photo en grand</span></div>
</div>`;
const idx = TRI.indexOf(TRAITEUR);
const A_LOUPE = `<div class="cq-loupe">
  <div class="hd">${idx + 1} / ${T.length}<small>les écarts d’abord</small><span style="margin-left:18px">Comptoir · Traiteur</span><small>${esc(clCourt(TRAITEUR.checklist))}</small><span class="x">✕</span></div>
  <div class="mi"><span class="fl">‹</span>${photo(TRAITEUR, { heure: false, badge: false })}
    <div class="fi"><span class="cq-bd nc">3/5 · mineure</span><h3>Comptoir · Traiteur</h3><div class="q">${esc(clCourt(TRAITEUR.checklist))}</div>
      <q><b>1. Assortiment</b> — repère posé sur la vitrine</q>
      <dl class="cq-dl"><dt>Rendue</dt><dd>à 04:11 par Obchette S.</dd>
        <dt>Contrôlée</dt><dd>à 13:55 par Sam Verheyden</dd>
        <dt>Maîtrise</dt><dd>3,8 / 5 sur les 5 derniers contrôles<div class="cq-hist"><i style="height:16px"></i><i style="height:16px"></i><i class="nc" style="height:12px"></i><i style="height:16px"></i><i class="nc" style="height:12px"></i><span>sous le seuil de 4,2 : reste contrôlée chaque jour</span></div></dd>
        <dt>Aujourd’hui</dt><dd class="cq-t-ko">pas encore reprise</dd></dl></div>
    <span class="fl">›</span></div>
  <div class="ba">${TRI.map(t => `<div style="width:44px">${photo(t, { mini: true, heure: false, badge: false, cls: t === TRAITEUR ? 'on' : '' })}</div>`).join('')}</div>
</div>`;
const A_MOB = `<div class="cq-mcar"><div class="k">Les contrôles en photo · <em>1 écart · 1 à contrôler</em></div>
  <div class="s">9 photos à 04:09–04:11 · notées à 13:55 · moyenne ${moy} / 5 · glisser →</div>
  <div class="piste">${TRI.map(t => carte(t, false, true)).join('')}</div></div>`;

/* B — la deuxième page ---------------------------------------------------------- */
const BASCULE = (o = '') => `<div class="cq-bascule"><span class="sw"><span>📊 Les chiffres</span><span class="on">📷 Les contrôles <em>1 écart</em></span></span><span class="db-mini">${o || 'la même journée, vue par ce que l’équipe a montré · la bascule reste sur la page choisie'}</span></div>`;
const GROUPES = (() => {
  const ord = ['CO-01', 'CQ-02', 'CO-10', 'CQ-F1'];
  const g = {};
  T.forEach(t => { const k = String(t.checklist).slice(0, 5); (g[k] = g[k] || { nom: clCourt(t.checklist), L: [] }).L.push(t); });
  return ord.filter(k => g[k]).map(k => g[k]);
})();
const B_TUILES = `<div class="db-tuiles">
  <div class="db-tui"><div class="k">Rendues</div><div class="v">11 <small style="font-size:13px">/ 16</small></div><div class="s">dernière à 04:11 · Obchette S.</div></div>
  <div class="db-tui"><div class="k">Photos du jour</div><div class="v">9</div><div class="s">+ 2 clôturées sans photo le 26/09</div></div>
  <div class="db-tui bon"><div class="k">Note moyenne</div><div class="v">${moy} / 5</div><div class="s">8 photos notées par Sam Verheyden à 13:55</div></div>
  <div class="db-tui vif"><div class="k">Écarts</div><div class="v">1</div><div class="s">mineur · Comptoir Traiteur — Assortiment</div></div>
  <div class="db-tui"><div class="k">À contrôler</div><div class="v" style="color:#2F5D8A">1</div><div class="s">Comptoir Épicerie, rendue à 04:10</div></div>
  <div class="db-tui"><div class="k">Non rendues</div><div class="v ko">5</div><div class="s">Biscuiterie, Pâtisseries · 3 CQ Formation Cuisine</div></div></div>`;
function grp(G, c3) {
  const f = G.L.filter(t => t.faitLe && t.statut !== 'sansPhoto');
  const hs = f.map(t => hh(t.faitLe)).sort();
  return `<div class="db-card cq-grp"><div class="ct"><span class="db-lab">${esc(G.nom)}</span><span class="db-mini">${f.length} / ${G.L.length} rendue${f.length > 1 ? 's' : ''}${hs.length ? ' · ' + hs[0] + (hs.length > 1 && hs[hs.length - 1] !== hs[0] ? ' → ' + hs[hs.length - 1] : '') + ' · Obchette S.' : ''}</span></div>
    <div class="cq-grille${c3 ? ' c3' : ''}">${G.L.slice().sort((a, b) => RANG[etat(a).c] - RANG[etat(b).c]).map(t => `<div class="cq-tuile${t === TRAITEUR ? ' sel' : ''}">${photo(t, { bdCourt: true })}<div class="n">${esc(t.court)}</div><div class="m">${meta(t)}</div></div>`).join('')}</div></div>`;
}
// Les checklists d'une seule tâche se partagent une rangée : pas de carte à moitié vide.
const petits = GROUPES.filter(G => G.L.length <= 2), grands = GROUPES.filter(G => G.L.length > 2);
const B_GRILLE = (petits.length ? `<div class="cq-duo">${petits.map(G => grp(G, true)).join('')}</div>` : '') + grands.map(G => grp(G, false)).join('');
const B_DET = `<div class="db-card cq-det"><div class="in">${photo(TRAITEUR, { heure: false })}
  <h3>Comptoir · Traiteur</h3><div class="db-mini">${esc(clCourt(TRAITEUR.checklist))}</div>
  <q><b>1. Assortiment</b> — écart mineur relevé sur la vitrine</q>
  <dl class="cq-dl"><dt>Rendue</dt><dd>à 04:11 par Obchette S.</dd><dt>Contrôlée</dt><dd>3/5 à 13:55 par Sam Verheyden</dd>
    <dt>Maîtrise</dt><dd>3,8 / 5 sur 5 contrôles<div class="cq-hist"><i style="height:16px"></i><i style="height:16px"></i><i class="nc" style="height:12px"></i><i style="height:16px"></i><i class="nc" style="height:12px"></i></div></dd>
    <dt>La veille</dt><dd class="db-mini">aucune tâche notée le 23/09</dd></dl>
  <div class="nav"><span>‹ Quiches</span><b>1 / 16 · les écarts d’abord</b><span>Épicerie ›</span></div></div></div>`;
const B_BUREAU = `<div id="dash">${HD}${NAV}${BASCULE()}${B_TUILES}<div class="cq-p2"><div>${B_GRILLE}</div>${B_DET}</div></div>`;
const B_MOB = `<div class="cq-mp">
  <div class="som"><div><div class="k">Rendues</div><div class="v">11<small style="font:500 11px var(--font-ui);color:var(--color-text-muted)"> / 16</small></div><div class="s">04:09 → 04:11</div></div>
    <div><div class="k">Écart</div><div class="v cq-t-nc">1</div><div class="s">Traiteur · mineur</div></div>
    <div><div class="k">Non rendues</div><div class="v cq-t-ko">5</div><div class="s">dont 2 obligatoires</div></div></div>
  ${GROUPES.map(G => `<div class="gt">${esc(G.nom)}<small>${G.L.filter(t => t.faitLe && t.statut !== 'sansPhoto').length} / ${G.L.length}</small></div>
    <div class="gr">${G.L.slice().sort((a, b) => RANG[etat(a).c] - RANG[etat(b).c]).map(t => `<div>${photo(t, { bdCourt: true, mini: !t.photo, heure: false })}<div class="n">${esc(t.court)}</div></div>`).join('')}</div>`).join('')}</div>`;
const B_TABS = `<div class="mb-tabs mb-tabs4"><button><i>◉</i>Le jour</button><button><i>▤</i>La semaine</button><button class="on"><i>▣</i>Contrôles<sup>1</sup></button><button><i>✓</i>Plan d’action</button></div>`;

/* C — le fil de la journée, en photos ------------------------------------------ */
const parMinute = {};
T.filter(t => t.faitLe && t.statut !== 'sansPhoto').forEach(t => { (parMinute[hh(t.faitLe)] = parMinute[hh(t.faitLe)] || []).push(t); });
const vign = (t, o = {}) => `<div>${photo(t, Object.assign({ heure: false, bdCourt: true, mini: true }, o))}<div class="n">${esc(t.court)}</div></div>`;
function journal(mob) {
  const lignes = [];
  Object.keys(parMinute).sort().forEach(m => {
    const L = parMinute[m];
    const cl = [...new Set(L.map(t => String(t.checklist).slice(0, 5)))];
    lignes.push(`<div class="cq-e"><div class="t">${m}${mob ? '' : '<small>ouverture</small>'}</div><div class="ax"><i></i></div><div class="bd">
      <div class="l"><b>Obchette S.</b> rend ${L.length} contrôle${L.length > 1 ? 's' : ''} photo</div><div class="q">${esc(cl.join(' · '))} — ${L.map(t => esc(t.court)).join(', ')}</div>
      <div class="ph">${L.map(t => vign(t, { badge: false }).replace('<div>', t === TRAITEUR && !mob ? '<div data-zoom="1">' : '<div>')).join('')}</div></div></div>`);
  });
  lignes.push(`<div class="cq-e creux"><div class="t"></div><div class="ax"><i></i></div><div class="bd">9 h 43 sans nouveau contrôle</div></div>`);
  const notees = T.filter(t => t.statut === 'notee');
  lignes.push(`<div class="cq-e"><div class="t">13:54${mob ? '' : ' → 13:56<small>contrôle</small>'}</div><div class="ax"><i class="nc"></i></div><div class="bd">
    <div class="l"><b>Sam Verheyden</b> note ${notees.length} photos : <b class="cq-t-ok">${notees.filter(t => t.note >= 4).length} conformes</b>, <b class="cq-t-nc">1 écart mineur</b> — Traiteur, « Assortiment »</div>
    <div class="q">moyenne ${moy} / 5 · Épicerie reste à contrôler</div>
    <div class="ph">${notees.slice().sort((a, b) => a.note - b.note).map(t => vign(t)).join('')}${vign(T.find(t => t.id === '1214'))}</div></div></div>`);
  const nr = T.filter(t => t.statut === 'nonRendue');
  lignes.push(`<div class="cq-e"><div class="t">${mob ? '—' : 'fin de journée<small>jamais rendues</small>'}</div><div class="ax"><i class="ko"></i></div><div class="bd">
    <div class="l"><b class="cq-t-ko">${nr.length} contrôles jamais rendus</b> — dont 2 obligatoires à l’ouverture (Biscuiterie, Pâtisseries)</div>
    <div class="q">CQ-F1 Formation Cuisine 1 : ${nr.filter(t => /^CQ-F1/.test(t.checklist)).map(t => esc(t.court)).join(', ')}</div>
    <div class="ph">${nr.map(t => vign(t)).join('')}</div></div></div>`);
  const sp = T.filter(t => t.statut === 'sansPhoto');
  lignes.push(`<div class="cq-e"><div class="t">26/09 05:03${mob ? '' : '<small>clôture auto</small>'}</div><div class="ax"><i class="mu"></i></div><div class="bd">
    <div class="l">${sp.length} contrôles clôturés automatiquement, sans photo</div><div class="q">${sp.map(t => esc(t.court)).join(', ')} — rien à noter, donc rien à valider</div></div></div>`);
  return lignes.join('');
}
const TACHES_OUV = TACHES.replace('db-bt fil"', 'db-bt fil ouvert"').replace('détail ▾', 'replier ▴');
const C_BLOC = `<div class="db-card cq-jr" style="position:relative"><div class="ct"><span class="db-lab">Le fil de la journée — en photos</span><span class="db-mini">qui a rendu quoi, à quelle heure · le passage du consultant · ce qui n’est jamais venu · survoler une vignette pour l’agrandir</span></div>
  <div class="in">${journal(false)}</div>
  <div class="cq-zoom" id="zoom">${photo(TRAITEUR, { heure: false })}<div class="l">Comptoir · Traiteur — 3/5, mineur</div><div class="q">rendue 04:11 par Obchette S. · notée 13:55 par Sam Verheyden · « Assortiment »</div></div></div>`;
const C_PLACE = `<script>addEventListener('load', () => { const v = document.querySelector('[data-zoom]'), z = document.getElementById('zoom'), c = z.parentNode.getBoundingClientRect(), r = v.getBoundingClientRect();
  z.style.left = (r.right - c.left + 230) + 'px'; z.style.top = (r.top - c.top - 200) + 'px'; v.querySelector('.cq-ph').style.outline = '2.5px solid var(--color-primary)'; v.querySelector('.cq-ph').style.outlineOffset = '2px'; });</script>`;
const C_SHEET = `<div class="cq-voile"></div><div class="cq-sheet"><div class="poi"></div><div class="hd"><span class="t">Le fil de la journée</span><span class="db-mini">jeu. 24/09</span><span class="x">✕</span></div>
  <div class="s0">11 / 16 rendues · 1 écart · 5 jamais rendues · ouvert depuis la case « Tâches »</div>${journal(true)}</div>`;

/* D — « Pendant votre absence » ------------------------------------------------ */
// Une bulle par contrôle : l'écart d'abord, puis l'attente, puis le conforme ;
// les bulles déjà vues sur cet appareil passent en gris, en fin de rang.
const BUL = TRI.filter(t => t.photo || t.statut === 'nonRendue');
const VUS = new Set(['1216', '1213']);
const bulle = t => {
  const e = etat(t), vu = VUS.has(t.id);
  return `<div><div class="o ${vu ? 'vu' : e.c}"><span>${t.photo ? `<img src="photos/${t.id}.jpg" alt="">` : '<span class="vid">✗</span>'}</span>${e.c === 'nc' ? '<em class="nc">3/5</em>' : (e.c === 'ctl' ? '<em class="ctl">?</em>' : '')}</div><div class="l${vu ? ' vu' : ''}">${esc(t.court.replace(/ &.*$/, '').replace(/ prix.*$/, ''))}</div></div>`;
};
const BULLES = BUL.filter(t => !VUS.has(t.id)).concat(BUL.filter(t => VUS.has(t.id))).map(bulle).join('');
const D_MOB = `<div class="cq-abs"><div class="k">Pendant votre absence</div><div class="s">depuis votre dernière visite, mer. 23/09 à 21:12 · sur ce téléphone</div>
  <div class="cpt"><span><b>9</b>photos</span><span class="cq-t-nc"><b>1</b>écart</span><span class="cq-t-ctl"><b>1</b>à contrôler</span><span class="cq-t-ko"><b>5</b>non rendues</span></div>
  <div class="cq-bul">${BULLES}</div>
  <div class="pied"><span class="db-mini">7 pas encore vues · touchez une bulle</span><b>Tout voir ▸</b></div></div>`;
const D_STORY = `<div class="cq-story">${photo(TRAITEUR, { heure: false, badge: false })}
  <div class="bar"><i class="c"></i>${Array.from({ length: BUL.length - 1 }, () => '<i></i>').join('')}</div>
  <div class="top"><span class="av">OS</span><div><b>Comptoir · Traiteur</b><small>04:11 · Obchette S. · contrôle d’ouverture</small></div><span class="x">✕</span></div>
  <div class="bas"><span class="cq-bd nc">3/5 · écart mineur</span><div class="c">1. Assortiment</div><div class="q">repère posé par Sam Verheyden à 13:55 · maîtrise 3,8 / 5 sur 5 contrôles</div>
    <div class="act"><span>‹ Précédente</span><span class="p">Vu · suivante ›</span></div></div></div>`;
const D_BAND = `<div class="cq-absb"><div><div class="k">Pendant votre absence</div><div class="s">depuis votre dernière visite, mer. 23/09 à 21:12</div>
  <div class="cpt"><span><b>9</b>photos</span><span class="cq-t-nc"><b>1</b>écart</span><span class="cq-t-ctl"><b>1</b>à contrôler</span><span class="cq-t-ko"><b>5</b>non rendues</span></div></div>
  <div class="cq-bul">${BULLES}</div>
  <div class="go"><span class="p">▶ Voir en diaporama (7)</span><span class="s2">Tout marquer comme vu</span></div></div>`;

/* --- Écriture et captures ---------------------------------------------------- */
const ECRANS = [
  ['a-bureau', 'A — carrousel (bureau)', page('A — carrousel', bureau('', A_BLOC)), 'b'],
  ['a-loupe', 'A — la photo en grand', page('A — loupe', bureau('', A_BLOC) + A_LOUPE), 'b'],
  ['a-mobile', 'A — carrousel (téléphone)', page('A — téléphone', mobile(A_MOB)), 'm'],
  ['b-bureau', 'B — page Contrôles (bureau)', page('B — page Contrôles', B_BUREAU), 'b'],
  ['b-mobile', 'B — onglet Contrôles (téléphone)', page('B — téléphone', mobile('', { page: B_MOB, tabs: B_TABS })), 'm'],
  ['c-bureau', 'C — le fil en photos (bureau)', page('C — le fil en photos', bureau('', C_BLOC, { taches: TACHES_OUV, sansSuite: true }) + C_PLACE), 'b'],
  ['c-mobile', 'C — le fil en photos (téléphone)', page('C — téléphone', mobile('', { dessus: C_SHEET })), 'm'],
  ['d-mobile', 'D — pendant votre absence (téléphone)', page('D — téléphone', mobile(D_MOB)), 'm'],
  ['d-story', 'D — le diaporama (téléphone)', page('D — diaporama', mobile(D_MOB, { dessus: D_STORY })), 'm'],
  ['d-bureau', 'D — pendant votre absence (bureau)', page('D — bureau', bureau(D_BAND, '', { sansNc: true })), 'b'],
];

const PLANCHES = [
  { id: 'a', titre: 'A — Un carrousel « Les contrôles en photo »',
    acc: 'Une bande de photos sous « Les tâches du jour » : les écarts d’abord, puis ce qui attend le consultant, puis le conforme, et les contrôles jamais rendus en case vide. Un clic ouvre la photo en grand avec le repère, le constat et la maîtrise.',
    ecrans: [['a-bureau', 'bureau — la bande'], ['a-loupe', 'bureau — un clic : la photo en grand'], ['a-mobile', 'téléphone — sous le chiffre du jour']],
    plus: ['Rien ne change de place : la bande s’ajoute à la vue Jour.', 'Lisible en cinq secondes : l’écart orange saute aux yeux.', 'Même visionneuse au bureau et au téléphone.'],
    moins: ['16 cartes : il faut faire défiler pour tout voir.', 'Peu de place pour l’historique d’une tâche sans ouvrir la photo.'] },
  { id: 'b', titre: 'B — Une deuxième page « Les contrôles »',
    acc: 'Une bascule Les chiffres | Les contrôles en tête de la vue Jour (au téléphone : un quatrième onglet en bas). La page Contrôles range toutes les photos par checklist, dans l’ordre de la journée ; celle qu’on choisit s’affiche en grand à droite.',
    ecrans: [['b-bureau', 'bureau — la page Contrôles'], ['b-mobile', 'téléphone — l’onglet Contrôles']],
    plus: ['Tout tient sur un écran, rangé comme l’équipe travaille.', 'Place pour le détail : constat, maîtrise, reprise.', 'Les chiffres restent sobres ; les contrôles ont leur page.'],
    moins: ['Il faut penser à basculer : rien ne signale un écart côté chiffres (sauf la pastille).', 'Une page de plus à tenir.'] },
  { id: 'c', titre: 'C — « Le fil de la journée » raconté en photos',
    acc: 'Le tiroir du fil devient un journal horodaté : qui a rendu quoi et à quelle heure, le passage du consultant avec ses notes, puis ce qui n’est jamais venu. Au téléphone, le même journal monte depuis la case « Tâches ».',
    ecrans: [['c-bureau', 'bureau — le fil déplié'], ['c-mobile', 'téléphone — le tiroir']],
    plus: ['Répond à « quand ? et par qui ? » : tout rendu à 04:09–04:11, noté à 13:55.', 'Montre le creux (9 h 43) et les oublis.', 'S’appuie sur un bloc qui existe déjà.'],
    moins: ['Fermé par défaut : le franchisé doit ouvrir le fil.', 'Moins direct pour juger « le comptoir est-il beau ? ».'] },
  { id: 'd', titre: 'D — « Pendant votre absence » : résumé et diaporama',
    acc: 'En tête de page, ce qui s’est passé depuis la dernière visite du franchisé sur cet appareil : les compteurs et une bulle par contrôle (orange : écart, bleu : à contrôler, rouge hachuré : non rendu, gris : déjà vu). Une bulle ouvre un diaporama plein écran, photo par photo, note et constat en bas.',
    ecrans: [['d-mobile', 'téléphone — le résumé'], ['d-story', 'téléphone — le diaporama'], ['d-bureau', 'bureau — le même résumé en bandeau']],
    plus: ['Pensé pour le téléphone : se parcourt comme un fil social, au pouce.', 'Le franchisé sait ce qu’il n’a pas encore vu.', 'Le plus visible : c’est la première chose à l’ouverture.'],
    moins: ['« Déjà vu » est retenu par appareil (pas de compte franchisé).', 'Le plus de code : visionneuse plein écran et gestes.'] },
];

(async () => {
  for (const [id, , html] of ECRANS) { fs.writeFileSync(path.join(OUT, id + '.html'), html); }
  const b = await chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost'] });
  const dims = {};
  for (const [id, lib, , k] of ECRANS) {
    const p = await b.newPage(k === 'b' ? { viewport: { width: 1348, height: 900 }, deviceScaleFactor: 1 } : { viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true });
    const err = []; p.on('pageerror', e => err.push(e.message)); p.on('requestfailed', r => err.push('échec ' + r.url()));
    await p.goto('http://127.0.0.1:8099/docs/maquettes/controles-qualite/' + id + '.html', { waitUntil: 'load' });
    await p.evaluate(() => document.fonts.ready); await p.waitForTimeout(400);
    const full = k === 'b' && id !== 'a-loupe';
    await p.screenshot({ path: path.join(OUT, id + '.jpg'), type: 'jpeg', quality: 84, fullPage: full });
    dims[id] = full ? await p.evaluate(() => document.documentElement.scrollHeight) : (k === 'b' ? 900 : 844);
    await p.close(); console.log('✓', id, lib, dims[id], err.length ? err : '');
  }
  // Les planches : une par proposition, le texte à gauche, les écrans à droite.
  for (const P of PLANCHES) {
    const imgs = P.ecrans.map(([id, leg]) => {
      const mob = /mobile|story/.test(id);
      const w = mob ? 300 : (P.ecrans.filter(e => !/mobile|story/.test(e[0])).length > 1 ? 760 : 1000);
      return `<div><img src="${id}.jpg" style="width:${w}px"><div class="leg">${esc(leg)}</div></div>`;
    }).join('');
    const html = page('Planche ' + P.id.toUpperCase(), `<div class="pl"><span class="u">maquette · dashboard magasin · vue Jour · Gosselies, jeudi 24/09/2026 · données réelles du panel</span>
      <h1>${esc(P.titre)}</h1><p class="acc">${esc(P.acc)}</p>
      <div class="g"><div class="col"><h4>Pour</h4><ul class="pl-p">${P.plus.map(x => `<li>${esc(x)}</li>`).join('')}</ul><h4>Contre</h4><ul class="pl-m">${P.moins.map(x => `<li>${esc(x)}</li>`).join('')}</ul></div>
      <div class="g" style="flex-wrap:wrap">${imgs}</div></div></div>`);
    fs.writeFileSync(path.join(OUT, 'planche-' + P.id + '.html'), html);
    const p = await b.newPage({ viewport: { width: 1900, height: 1000 }, deviceScaleFactor: 1 });
    await p.goto('http://127.0.0.1:8099/docs/maquettes/controles-qualite/planche-' + P.id + '.html', { waitUntil: 'load' });
    await p.evaluate(() => document.fonts.ready); await p.waitForTimeout(300);
    await p.screenshot({ path: path.join(OUT, 'planche-' + P.id + '.jpg'), type: 'jpeg', quality: 86, fullPage: true });
    await p.close(); console.log('✓ planche', P.id);
  }
  await b.close();
})();
