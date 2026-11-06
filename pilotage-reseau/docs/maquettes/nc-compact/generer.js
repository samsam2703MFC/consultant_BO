/* Trois propositions de repli pour le bloc des non-conformités.
 * Même jeu de données : le MOIS de septembre du magasin 4, huit écarts —
 * c'est là que la compacité se joue. Données fictives. */
const fs = require('fs'), path = require('path');
const OUT = __dirname;
const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

const NIV = { 1: ['Critique', 'g1'], 2: ['Majeure', 'g2'], 3: ['Mineure', 'g3'] };
const NC = [
  { j: '12/09', n: 1, t: 'CQ · Températures frigo vitrine', cl: 'CQ-03 — Contrôle qualité après-midi', h: '16:20', par: 'K. Moreau',
    c: 'Frigo vitrine relevé à 9 °C à 16 h, produits frais laissés en place.', ph: 'frigo', pts: [[55, 20, 30, 35], [20, 50, 25, 30]],
    s: { c: 'ok', l: '✓ Reprise 4/5 le 13/09', d: 'refaite par L. Simon, notée par K. Moreau' } },
  { j: '11/09', n: 2, t: 'CQ · Propreté zone cuisson', cl: 'CQ-05 — Fermeture', h: '19:42', par: 'K. Moreau', rec: '3e fois en 7 jours',
    c: 'Plan de travail et sol du four non nettoyés en fin de service.', ph: 'four', pts: [[35, 25, 30, 40]],
    s: { c: 'rec', l: '↻ Renotée 2/5 le 12/09', d: 'la reprise n’a pas tenu — toujours non conforme' } },
  { j: '09/09', n: 3, t: 'Comptoir · Étiquettes prix', cl: 'CO-03 — Photo du comptoir', h: '11:15', par: 'K. Moreau',
    c: 'Deux étiquettes prix manquantes sur les tartes.', ph: 'comptoir', pts: [[30, 35, 25, 30]],
    s: { c: 'ko', l: '✗ Jamais renotée', d: 'la tâche n’a pas été renotée depuis' } },
  { j: '08/09', n: 2, t: 'Comptoir · Vitrine viennoiseries 15 h', cl: 'CO-04 — Photo du comptoir', h: '15:08', par: 'K. Moreau',
    c: 'Trois emplacements vides depuis 15 h, ni recharge ni regroupement.', ph: 'vitrine', pts: [[12, 35, 22, 40], [42, 30, 20, 35]],
    s: { c: 'ok', l: '✓ Reprise 5/5 le 09/09', d: 'refaite par A. Denis' } },
  { j: '05/09', n: 3, t: 'CQ · Tenue et badge', cl: 'CQ-01 — Ouverture', h: '07:30', par: 'S. Peeters',
    c: 'Badge nominatif absent sur un équipier.', ph: 'comptoir', pts: [[45, 30, 22, 35]],
    s: { c: 'ok', l: '✓ Reprise 4/5 le 06/09', d: 'refaite par L. Simon' } },
  { j: '04/09', n: 1, t: 'CQ · Chaîne du froid à la livraison', cl: 'CQ-02 — Réception', h: '08:05', par: 'S. Peeters',
    c: 'Livraison acceptée à 11 °C, sans relevé consigné.', ph: 'frigo', pts: [[40, 28, 28, 34]],
    s: { c: 'ok', l: '✓ Reprise 4/5 le 05/09', d: 'refaite par A. Denis' } },
  { j: '03/09', n: 3, t: 'CQ · Affichage allergènes', cl: 'CQ-01 — Ouverture', h: '07:22', par: 'S. Peeters',
    c: 'Fiche allergènes absente du comptoir.', ph: 'comptoir', pts: [[50, 40, 20, 26]],
    s: { c: 'ok', l: '✓ Reprise 4/5 le 04/09', d: 'refaite par L. Simon' } },
  { j: '02/09', n: 2, t: 'CQ · Hotte et filtres', cl: 'CQ-05 — Fermeture', h: '19:10', par: 'K. Moreau', rec: '2e fois en 7 jours',
    c: 'Filtres encrassés, non changés depuis six semaines.', ph: 'four', pts: [[30, 30, 35, 35]],
    s: { c: 'ko', l: '✗ Jamais renotée', d: 'la tâche n’a pas été renotée depuis' } },
];
const ouverts = NC.filter(x => x.s.c !== 'ok');

/* --- le bandeau, identique aux trois propositions ------------------------ */
function BANDEAU(ouvert) {
  const ch = (g, t, em, cls, ko) => `<span class="ch${ko ? ' ko' : ''}"><i class="${g}"></i>${t} <em class="${cls}">${em}</em></span>`;
  return `<div class="db-ncbar${ouvert ? ' ouv' : ''}"><span class="ic">⚠️</span>
    <span class="t">8 non-conformités ce mois<small>septembre 2026 · 214 tâches notées</small></span>
    <span class="chips">
      ${ch('g2', 'majeure · propreté cuisson', 'encore non conforme ↻ 3e fois', 'ko', true)}
      ${ch('g3', 'mineure · étiquettes prix', 'jamais renotée', 'ko', true)}
      ${ch('g2', 'majeure · hotte et filtres', 'jamais renotée ↻ 2e fois', 'ko', true)}
      ${ch('g3', '5 reprises', 'notées ≥ 4/5', 'v')}
    </span>
    <span class="act"><span class="dr">${ouvert ? 'replier ▴' : 'détail ▾'}</span></span></div>`;
}
const CT = extra => `<div class="ct"><span class="db-lab">8 non-conformités sur 214 tâches notées</span><span class="db-mini">${extra || 'relevées par K. Moreau, S. Peeters · seuil de conformité 4/5'}</span><a class="db-lien" href="#">Contrôle des tâches ›</a></div>`;

/* --- la ligne dense, et son détail --------------------------------------- */
const ligne = (x, on) => `<div class="cp-l${on ? ' on' : ''}">
  <span><span class="cp-g ${NIV[x.n][1]}">${NIV[x.n][0]} ${x.n}/5</span></span>
  <span class="d">${x.j}${x.rec ? '<br><span class="rc">↻ ' + x.rec.slice(0, 2) + '</span>' : ''}</span>
  <span class="n">${esc(x.t)}</span>
  <span class="c">${esc(x.c)}</span>
  <span class="v"><span class="cp-s ${x.s.c}">${esc(x.s.l)}</span></span>
  <span class="ch">${on ? '▴' : '▾'}</span></div>`;

const detail = x => `<div class="cp-d">
  <span class="ph ${x.ph}">${x.pts.map((p, i) => `<i style="left:${p[0]}%;top:${p[1]}%;width:${p[2]}%;height:${p[3]}%"><u>${i + 1}</u></i>`).join('')}</span>
  <div><q>${esc(x.c)}</q>
    <dl><dt>Checklist</dt><dd>${esc(x.cl)}</dd>
      <dt>Relevée</dt><dd>le ${x.j} à ${x.h} par ${esc(x.par)}${x.rec ? ' · <b class="ko">↻ récidive, ' + esc(x.rec) + '</b>' : ''}</dd>
      <dt>Depuis</dt><dd>${esc(x.s.l.replace(/^[✓✗↻] /, ''))} — ${esc(x.s.d)}</dd>
      <dt>Photo</dt><dd>${x.pts.length} repère(s) posé(s) au contrôle · cliquer pour l’agrandir</dd></dl>
    <div class="a"><a href="#">Ouvrir la tâche dans Contrôle des tâches ›</a></div></div></div>`;

/* --- A : une ligne par écart, le détail au clic -------------------------- */
const A = `
<div class="cp-t2">1 · Replié — le bloc tient sur une ligne</div>
<span class="cp-mes">env. 53 px</span>
${BANDEAU(false)}
<div class="cp-t2">2 · Ouvert — une ligne par écart, huit écarts tiennent dans un écran</div>
<span class="cp-mes">env. 53 + 250 px</span>
${BANDEAU(true)}
<div class="db-ncdl">${CT()}<div class="db-nclist" style="padding:2px 16px 8px">${NC.map(x => ligne(x, false)).join('')}</div></div>
<div class="cp-t2">3 · Une ligne dépliée — tout ce que l’écran sait de cet écart, et rien d’autre</div>
<span class="cp-mes">+ 120 px, pour la seule ligne ouverte</span>
${BANDEAU(true)}
<div class="db-ncdl">${CT()}<div class="db-nclist" style="padding:2px 16px 8px">
  ${ligne(NC[0], false)}${ligne(NC[1], true)}${detail(NC[1])}${NC.slice(2).map(x => ligne(x, false)).join('')}
</div></div>`;

/* --- B : repli par gravité ----------------------------------------------- */
const grp = (n, nb, ouvert, etats) => `<div class="cp-grp">
  <span class="cp-g ${NIV[n][1]}" style="width:78px">${NIV[n][0]} ${n}/5</span>
  <span class="k">${nb} écart${nb > 1 ? 's' : ''}</span>
  <span class="pt">${etats.map(e => `<i class="${e}"></i>`).join('')}</span>
  <span class="mu">${etats.filter(e => e === 'ok').length} repris · ${etats.filter(e => e !== 'ok').length} encore ouvert${etats.filter(e => e !== 'ok').length > 1 ? 's' : ''}</span>
  <span class="sp"></span><span class="ch">${ouvert ? 'replier ▴' : 'voir ▾'}</span></div>`;
const parN = n => NC.filter(x => x.n === n);
const B = `
<div class="cp-t2">1 · Replié — identique : une ligne</div>
<span class="cp-mes">env. 53 px</span>
${BANDEAU(false)}
<div class="cp-t2">2 · Ouvert — trois lignes de gravité, et rien d’autre. Le mois entier en trois lignes</div>
<span class="cp-mes">env. 53 + 120 px</span>
${BANDEAU(true)}
<div class="db-ncdl">${CT()}<div class="db-nclist" style="padding:2px 16px 8px">
  ${grp(1, 2, false, parN(1).map(x => x.s.c === 'ok' ? 'ok' : (x.s.c === 'ko' ? 'ko' : 'rec')))}
  ${grp(2, 3, false, parN(2).map(x => x.s.c === 'ok' ? 'ok' : (x.s.c === 'ko' ? 'ko' : 'rec')))}
  ${grp(3, 3, false, parN(3).map(x => x.s.c === 'ok' ? 'ok' : (x.s.c === 'ko' ? 'ko' : 'rec')))}
</div></div>
<div class="cp-t2">3 · Une gravité ouverte, puis une ligne ouverte — trois niveaux, on ne paie que ce qu’on regarde</div>
<span class="cp-mes">+ 90 px par gravité ouverte, + 120 px par ligne</span>
${BANDEAU(true)}
<div class="db-ncdl">${CT()}<div class="db-nclist" style="padding:2px 16px 8px">
  ${grp(1, 2, false, parN(1).map(x => x.s.c === 'ok' ? 'ok' : 'ko'))}
  ${grp(2, 3, true, parN(2).map(x => x.s.c === 'ok' ? 'ok' : (x.s.c === 'ko' ? 'ko' : 'rec')))}
  <div class="cp-sous">${ligne(parN(2)[0], true)}${detail(parN(2)[0])}${parN(2).slice(1).map(x => ligne(x, false)).join('')}</div>
  ${grp(3, 3, false, parN(3).map(x => x.s.c === 'ok' ? 'ok' : 'ko'))}
</div></div>`;

/* --- C : le tableau nu, rien à déplier ----------------------------------- */
const C = `
<div class="cp-t2">1 · Replié — une ligne, comme les autres</div>
<span class="cp-mes">env. 53 px</span>
${BANDEAU(false)}
<div class="cp-t2">2 · Ouvert — tout est là, en colonnes. Aucun clic pour lire, la photo s’agrandit au survol</div>
<span class="cp-mes">env. 53 + 290 px · rien de caché</span>
${BANDEAU(true)}
<div class="db-ncdl">${CT('relevées par K. Moreau, S. Peeters · seuil 4/5 · survoler une vignette pour l’agrandir')}
<div style="padding:2px 16px 10px"><table class="cp-t">
<tr><th style="width:78px">Gravité</th><th style="width:44px">Jour</th><th>La tâche</th><th>Le constat</th><th style="width:40px">Photo</th><th style="width:160px">Depuis</th><th style="width:118px">Relevée par</th></tr>
${NC.map(x => `<tr>
  <td><span class="cp-g ${NIV[x.n][1]}">${NIV[x.n][0]} ${x.n}/5</span></td>
  <td class="d">${x.j}${x.rec ? ' <span class="rc">↻</span>' : ''}</td>
  <td class="n">${esc(x.t)}<br><span class="q">${esc(x.cl)}</span></td>
  <td class="c">${esc(x.c)}</td>
  <td><span class="mini ${x.ph}">${x.pts.map(p => `<i style="left:${p[0]}%;top:${p[1]}%;width:${p[2]}%;height:${p[3]}%"></i>`).join('')}</span></td>
  <td><span class="cp-s ${x.s.c}">${esc(x.s.l)}</span></td>
  <td class="q">${esc(x.par)}<br>${x.h}${x.rec ? ' · <b class="ko">↻ ' + esc(x.rec) + '</b>' : ''}</td></tr>`).join('')}
</table></div></div>`;

const PAGES = [
  ['a-ligne-detail.html', 'A — une ligne par écart, le détail au clic',
    'Deux niveaux. Le bloc replié tient sur une ligne ; ouvert, chaque écart occupe une seule ligne dense — gravité, jour, tâche, début du constat, ce qu’il est devenu. Un clic sur la ligne déplie la photo, le constat entier, la checklist, la récidive et la suite. On ne paie la place que de l’écart qu’on regarde.', A],
  ['b-repli-gravite.html', 'B — replié par gravité, puis par écart',
    'Trois niveaux, le plus compact sur un mois chargé : ouvert, le bloc ne montre que trois lignes, une par gravité, avec le compte et des pastilles d’état. On ouvre la gravité qui inquiète, puis l’écart qui inquiète. Huit écarts d’un mois tiennent en trois lignes tant qu’on ne demande rien.', B],
  ['c-tableau-nu.html', 'C — le tableau nu, rien à déplier',
    'Zéro niveau : tout est visible en colonnes, en petit. Aucun clic pour lire, la vignette s’agrandit au survol. Plus haut que A et B à l’ouverture, mais c’est le seul où l’œil compare huit écarts d’un coup, sans mémoire ni allers-retours.', C],
];
for (const [f, titre, sous, corps] of PAGES) {
  const html = `<!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>${titre}</title>
<link rel="stylesheet" href="/public/assets/ds/global.css">
<link rel="stylesheet" href="/public/dashboard/dashboard.css">
<link rel="stylesheet" href="compact.css">
<style>
body{padding:0}
.mq{max-width:1440px;margin:0 auto;padding:16px 22px 30px}
.mq h1{font:400 20px var(--font-display);margin:0 0 4px}
.mq p.s{margin:0;font-size:12px;line-height:1.5;color:var(--color-text-muted);max-width:1000px}
.mq .u{display:inline-block;font:500 11px var(--font-ui);color:var(--color-text-muted);background:var(--color-background-secondary);border-radius:999px;padding:3px 10px;margin-bottom:8px}
.mq hr{border:none;border-top:.5px solid var(--color-border-secondary);margin:14px 0 2px}
</style></head>
<body><div class="mq"><span class="u">maquette · dashboard magasin · vue Mois · septembre 2026 · 8 écarts</span>
<h1>${titre}</h1><p class="s">${sous}</p><hr>
${corps}</div></body></html>`;
  fs.writeFileSync(path.join(OUT, f), html);
  console.log('écrit', f, html.length, 'octets');
}
