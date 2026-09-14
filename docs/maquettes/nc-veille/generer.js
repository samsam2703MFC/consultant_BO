/* Génère les trois maquettes « non-conformités de la veille » — dashboard › Jour.
 * Données FICTIVES, dans la charte du cockpit : magasin 4 (Waterloo — Centre),
 * jour du lundi 14 septembre 2026, veille du dimanche 13 septembre 2026. */
const fs = require('fs'), path = require('path');
const OUT = __dirname;

/* --- les non-conformités de la veille (démo) ---------------------------- */
const NC = [
  { n: 1, tache: 'CQ · Températures frigo vitrine', cl: 'CQ-03 — Contrôle qualité après-midi', h: '16:20', par: 'K. Moreau',
    txt: 'Frigo vitrine relevé à 9 °C à 16 h, produits frais laissés en place.', photo: 'frigo', pts: [[62, 30], [38, 62]],
    etat: 'ok', etatLib: '✓ Refaite à 07:12 · notée 4/5', etatSous: 'reprise par L. Simon, contrôlée par K. Moreau' },
  { n: 2, tache: 'Comptoir · Vitrine viennoiseries 15 h', cl: 'CO-04 — Photo du comptoir', h: '15:08', par: 'K. Moreau',
    txt: 'Trois emplacements vides depuis 15 h, ni recharge ni regroupement.', photo: 'vitrine', pts: [[22, 46], [49, 40], [74, 52]],
    etat: 'ctl', etatLib: '◻ Rendue à 15:05 · à contrôler', etatSous: 'photo déposée, sans note de consultant' },
  { n: 2, tache: 'CQ · Propreté zone cuisson', cl: 'CQ-05 — Fermeture', h: '19:42', par: 'K. Moreau', recid: '3e fois en 7 jours',
    txt: 'Plan de travail et sol du four non nettoyés en fin de service.', photo: 'four', pts: [[46, 34], [30, 68], [70, 72]],
    etat: 'ko', etatLib: '✗ Pas encore rendue', etatSous: 'attendue avant 19:30 · bloquante' },
  { n: 3, tache: 'Comptoir · Étiquettes prix', cl: 'CO-03 — Photo du comptoir', h: '11:15', par: 'K. Moreau',
    txt: 'Deux étiquettes prix manquantes sur les tartes.', photo: 'comptoir', pts: [[35, 44], [66, 58]],
    etat: 'ok', etatLib: '✓ Refaite à 08:40 · notée 5/5', etatSous: 'reprise par A. Denis' },
  { n: 3, tache: 'CQ · Tenue et badge', cl: 'CQ-01 — Ouverture', h: '07:30', par: 'K. Moreau',
    txt: 'Badge nominatif absent sur un équipier.', photo: 'comptoir', pts: [[52, 38]],
    etat: 'ok', etatLib: '✓ Refaite à 07:05 · notée 4/5', etatSous: 'reprise par L. Simon' },
];
const NIV = { 1: ['Critique', 'g1'], 2: ['Majeure', 'g2'], 3: ['Mineure', 'g3'] };
const nRepr = NC.filter(x => x.etat === 'ok').length;
const nCtrl = NC.filter(x => x.etat === 'ctl').length;
const nKo = NC.filter(x => x.etat === 'ko').length;

const esc = s => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
const photo = x => `<span class="db-ncph ${x.photo}" title="photo de la tâche, repères du consultant">${x.pts.map((p, i) => `<i style="left:${p[0]}%;top:${p[1]}%">${i + 1}</i>`).join('')}</span>`;
const grav = x => `<span class="db-ncg ${NIV[x.n][1]}">${NIV[x.n][0]} <small>${x.n}/5</small></span>`;

/* La liste commune aux variantes. `action` ajoute la colonne de validation (V4). */
function liste(avecEntete, action) {
  return (avecEntete ? `<div class="db-ncr hd"><span>Gravité</span><span>La tâche et le constat d’hier</span><span>Photo</span><span>Aujourd’hui</span>${action ? '<span>Validation</span>' : ''}</div>` : '')
    + NC.map(x => `<div class="db-ncr${action && x.etat === 'ok' && action === 'fait' ? ' val' : ''}">
      <span class="g">${grav(x)}${x.recid ? `<small class="ko"><b>↻ récidive</b><br>${esc(x.recid)}</small>` : ''}<small>relevée à ${x.h}<br>par ${esc(x.par)}</small></span>
      <span class="t"><b>${esc(x.tache)}</b><small>${esc(x.cl)}</small><q>${esc(x.txt)}</q></span>
      ${photo(x)}
      <span class="a"><span class="db-ncst ${x.etat}">${esc(x.etatLib)}</span><small>${esc(x.etatSous)}</small></span>
      ${action ? valider(x, action) : ''}
    </div>`).join('');
}

/* La colonne de validation : on ne valide QUE ce qui a été noté aujourd'hui.
 * Sans note du jour, il n'y a pas d'avis à contresigner — le bouton mentirait. */
function valider(x, etat) {
  if (x.etat === 'ok') {
    return etat === 'fait'
      ? `<span class="k"><span class="vd">✓ Reprise validée</span><small class="np">par S. Verhoeven à 16:42</small></span>`
      : `<span class="k"><label><input type="checkbox" checked><span>Valider la reprise</span></label><small>contresigne l’avis du ${esc(x.noteJour || '14/09')}</small></span>`;
  }
  if (x.etat === 'ctl') {
    return `<span class="k"><a href="#">Noter la photo ›</a><small class="np">à noter avant de pouvoir valider</small></span>`;
  }
  return `<span class="k"><a href="#">Relancer la boutique ›</a><small class="np">rien à valider : pas de rendu</small></span>`;
}

/* --- le décor : ce que la page montre déjà aujourd'hui -------------------- */
const HEAD = `<div class="db-hd"><img src="/public/assets/img/logo.png" alt=""><div><div class="db-titre">Waterloo — Centre</div><div class="db-sous">Dashboard magasin · lundi 14 septembre 2026 · en direct, relu toutes les 10 min</div></div><span style="flex:1"></span><a class="db-lien" href="#">Cockpit › Résultat ›</a></div>
<div class="db-nav"><div class="db-ong"><button class="on">Jour</button><button>Semaine</button><button>Mois</button><button>Trimestre</button><button>Année</button></div>
<span class="db-lab">Date</span><button class="db-btn">‹</button><input class="db-sel" type="date" value="2026-09-14"><button class="db-btn">›</button>
<span style="flex:1"></span><button class="db-btn">↻ Relire</button></div>`;

function bt(k, rang, cls, jauge, v, med, s) {
  return `<div class="db-bt"><div class="k">${k}</div><span class="rg${cls}">${cls === ' top' ? '🏆 ' : ''}${rang} <small>/ 9</small></span>
    <div class="jg"><i class="l"></i>${jauge.map(p => `<span class="pt" style="left:${p}%"></span>`).join('')}<span class="md" style="left:50%"></span><span class="mo${cls === ' top' ? ' top' : ''}" style="left:${jauge.moi || 68}%"></span></div>
    <div class="v">${v}<small>médiane ${med}</small></div><div class="s">${s}</div></div>`;
}
const j1 = Object.assign([12, 28, 41, 62, 79, 91], { moi: 72 });
const j2 = Object.assign([18, 33, 47, 58, 74, 88], { moi: 40 });
const j3 = Object.assign([9, 26, 44, 61, 77, 93], { moi: 86 });
const j4 = Object.assign([14, 31, 45, 63, 81, 95], { moi: 55 });
const BENCH = `<div class="db-bench"><div class="db-bt tit"><div class="k">Ta place dans le réseau</div><div class="s">9 magasins ouverts · la journée · anonyme · <b>1 × 🏆</b></div><div class="leg"><span><i style="background:var(--color-primary)"></i>toi</span><span><i style="background:#c9c2b8"></i>un autre</span><span><b></b>médiane</span></div></div>
${bt('Chiffre d’affaires', '3e', '', j1, '4,1 k€', '3,8 k€', '+ 312 € vs médiane · le 1er : 5,2 k€')}
${bt('Clients', '5e', '', j2, '341', '358', '−17 vs médiane · le 1er : 468')}
${bt('Panier moyen', '1er', ' top', j3, '12,03 €', '10,84 €', '+ 1,19 € vs médiane · le 2e : 11,70 €')}
${bt('Marge brute', '4e', '', j4, '68,4 %', '67,1 %', '+1,3 pts vs médiane · le 1er : 71,9 %')}
<div class="db-bt msg" ><span class="dr">détail ▾</span><div class="k">Messages du panel</div><div class="n"><span class="bell">🔔</span>3</div><div class="pri"><i class="warn">1 attention</i><i class="info">2 info</i></div><div class="last">Rappel : nouvelle gamme rentrée · 08:10</div></div></div>`;

/* Le bandeau des tâches du jour — tel qu'il existe. */
const FIL = (marques) => {
  const mk = (c, n, t) => `<i class="${c}" title="${t}"></i>`;
  let h = '';
  [['CO-01 — Ouverture', 'ffffff'], ['CQ-01 — Ouverture', 'ffffn'], ['CO-03 — Photo du comptoir', 'ffnn'], ['CQ-03 — Contrôle qualité après-midi', 'fnnn'], ['CO-02 — Fermeture', 'nnb']]
    .forEach((c, i) => { h += (i ? '<span class="sep"></span>' : '') + [...c[1]].map(l => mk(l, '', c[0])).join(''); });
  return h;
};
function taches(v1) {
  const tuile = (k, v, s, cls) => `<div class="db-bt ${cls || ''}"><div class="k">${k}</div><div class="v">${v}</div><div class="s">${s || ''}</div></div>`;
  const ncTuile = v1 ? `<div class="db-bt ncv" title="les non-conformités relevées hier"><span class="dr">détail ▾</span><div class="k">Non-conformités d’hier</div><div class="v">5<small>/ 18 notées</small></div>
    <div class="gs"><span class="db-ncg g1">1 critique</span><span class="db-ncg g2">2 majeures</span><span class="db-ncg g3">2 mineures</span></div>
    <div class="s"><b class="ok">3 reprises</b> · 1 à contrôler · <b class="ko">1 non rendue</b></div></div>` : '';
  return `<div class="db-taches${v1 ? ' nc6' : ''}"><div class="db-bt tit"><div class="k">Les tâches du jour</div><div class="s">22 obligatoire(s) · 64 % faites · dernière rendue à 15:05 par L. Simon</div></div>
    ${tuile('Faites', '14<small>/ 22</small>', '4 à contrôler · 1 sans photo', 'ok')}
    ${tuile('Non faites', '8<small>/ 22</small>', '5 contrôle(s) qualité · 1 d’exploitation', 'wa')}
    ${tuile('Bloquantes', '1', 'exploitation non rendue', 'ko')}
    ${ncTuile}
    <div class="db-bt fil"><div class="k">Le fil de la journée <span class="dr">détail ▾</span></div><div class="mini">${FIL()}</div><div class="s">Ouverture · Ouverture · Photo du comptoir · Contrôle qualité après-midi · Fermeture</div></div></div>`;
}

/* Le début de la section Résultat, pour situer la page. */
const TUI = (k, v, s, cls) => `<div class="db-tui ${cls || ''}"><div class="k">${k}</div><div class="v">${v}</div><div class="s">${s}</div></div>`;
const RESULTAT = `<div class="db-sec">Résultat — la journée<small>budget du jour, référence des mêmes jours, P&amp;L court</small></div>
<div class="db-tuiles">
${TUI('CA du jour', '4,1 k€', 'objectif 4,4 k€ · 93,2 % atteint · <b class="ko">−25 clients</b> (301 € ÷ 12,03 €)')}
${TUI('Marge brute', '2,8 k€', '68,4 % des ventes · matière 1,3 k€')}
${TUI('Clients', '341', 'référence 358 · − 4,7 % · 612 produits vendus')}
${TUI('Panier moyen', '12,03 €', 'réseau 10,84 € · 1,79 produits / client')}
${TUI('Projection fin de journée', '5,0 k€', '82,0 % de la journée écoulée · au rythme : 5,1 k€')}
${TUI('Résultat net du jour', '+ 402 €', '9,8 % des ventes', 'bon')}
</div>`;


/* Le sommaire — commun à la carte V2 et au tiroir V4. */
const SOMMAIRE = `  <div class="db-ncsum">
    <div class="db-bt ko"><div class="k">Non-conformités</div><div class="v">5<small>/ 18 notées</small></div><div class="s">1 critique · 2 majeures · 2 mineures</div></div>
    <div class="db-bt ok"><div class="k">Reprises aujourd’hui</div><div class="v">3<small>/ 5</small></div><div class="s">refaites et notées ≥ 4/5</div></div>
    <div class="db-bt ko"><div class="k">Encore ouvertes</div><div class="v">2</div><div class="s">1 à contrôler · <b>1 non rendue (bloquante)</b></div></div>
    <div class="db-bt"><div class="k">Les 7 derniers jours</div>
      <div class="db-nc7" style="grid-template-columns:repeat(7,1fr)">
        <div><i style="height:30%"></i><span>lu</span></div><div><i class="z" style="height:6%"></i><span>ma</span></div><div><i style="height:45%"></i><span>me</span></div>
        <div><i style="height:30%"></i><span>je</span></div><div><i class="z" style="height:6%"></i><span>ve</span></div><div><i style="height:60%"></i><span>sa</span></div>
        <div><i class="h" style="height:100%"></i><em>5</em><span>di</span></div></div>
      <div class="s">16 sur la semaine · <b class="ko">hier, le pire jour</b></div></div>
  </div>`;

/* Le bandeau de la V3, rendu dépliable. etat : 'ferme' · 'ouvert' · 'fait'. */
function NCBAR(etat) {
  const fait = etat === 'fait';
  const ch = (g, t, em, cls, ko) => `<span class="ch${ko ? ' ko' : ''}"><i class="${g}"></i>${t} <em class="${cls || ''}">${em}</em></span>`;
  return `<div class="db-ncbar${etat === 'ferme' ? '' : ' ouv'}"><span class="ic">${fait ? '✅' : '⚠️'}</span>
    <span class="t">5 non-conformités hier<small>dimanche 13 septembre · 18 tâches notées${fait ? ' · 3 reprises validées' : ''}</small></span>
    <span class="chips">
      ${ch('g1', '1 critique · frigo vitrine', fait ? 'validée 16:42' : 'reprise 07:12', fait ? 'v' : '')}
      ${ch('g2', '1 majeure · viennoiseries', 'à contrôler', 'ctl')}
      ${ch('g2', '1 majeure · propreté cuisson', 'non rendue ↻ 3e fois', 'ko', true)}
      ${ch('g3', '2 mineures', fait ? 'validées' : 'reprises', fait ? 'v' : '')}
    </span>
    <span class="act"><span class="dr">${etat === 'ferme' ? 'détail ▾' : 'replier ▴'}</span></span></div>`;
}

/* Le tiroir : le corps de la V2, plus la barre de validation. */
function NCDL(etat) {
  const fait = etat === 'fait';
  const foot = fait
    ? `<div class="db-ncfoot fait"><span class="t">✓ 3 reprises validées par S. Verhoeven à 16:42<small>contresignées dans le panel (mac_task_review) · une ligne au Journal du cockpit</small></span><span class="sp"></span>
        <button class="db-btn">Retirer la validation</button><a class="db-lien" href="#">Ouvrir Contrôle des tâches ›</a></div>`
    : `<div class="db-ncfoot"><span class="t">3 reprises cochées sur 3 validables<small>les deux autres ne sont pas validables : une photo rendue mais pas encore notée, une tâche pas rendue</small></span><span class="sp"></span>
        <button class="db-btn">Tout décocher</button><button class="db-btn pri">Valider les 3 reprises</button></div>`;
  return `<div class="db-ncdl">
    <div class="ct"><span class="db-lab">5 non-conformités sur 18 tâches notées</span><span class="db-mini">relevées par K. Moreau · barème : 3 mineure · 2 majeure · 1 critique</span></div>
    ${SOMMAIRE}
    <div class="db-nclist">${liste(true, fait ? 'fait' : 'ouvert')}</div>
    ${foot}</div>`;
}

/* --- les variantes -------------------------------------------------- */
const V1 = `${HEAD}${BENCH}${taches(true)}
<div class="db-ncdrop">${liste(true)}
  <div class="db-note">Les cinq non-conformités du <b>dimanche 13 septembre</b> — note sous le seuil de conformité (4/5). « Aujourd’hui » suit la MÊME tâche sur la journée en cours : reprise et notée, rendue en attente de contrôle, ou toujours pas rendue.</div></div>
${RESULTAT}`;

const V2 = `${HEAD}${BENCH}${taches(false)}
<div class="db-sec">Les non-conformités d’hier — dimanche 13 septembre<small>notées sous le seuil de conformité (4/5), et ce que la journée en cours en a fait</small></div>
<div class="db-card db-nccard">
  <div class="ct"><span class="db-lab">5 non-conformités sur 18 tâches notées</span><span class="db-mini">relevées par K. Moreau · barème : 3 mineure · 2 majeure · 1 critique</span></div>
${SOMMAIRE}
  <div class="db-nclist">${liste(true)}</div>
  <div class="db-note">Une non-conformité reste ouverte tant que la même tâche n’a pas été refaite et notée au-dessus du seuil. <a href="#">Ouvrir Contrôle des tâches ›</a></div>
</div>
${RESULTAT}`;

const V3 = `${HEAD}
<div class="db-ncbar"><span class="ic">⚠️</span>
  <span class="t">5 non-conformités hier<small>dimanche 13 septembre · 18 tâches notées</small></span>
  <span class="chips">
    <span class="ch"><i class="g1"></i>1 critique · frigo vitrine <em>reprise 07:12</em></span>
    <span class="ch"><i class="g2"></i>1 majeure · viennoiseries <em class="ctl">à contrôler</em></span>
    <span class="ch ko"><i class="g2"></i>1 majeure · propreté cuisson <em class="ko">non rendue ↻ 3e fois</em></span>
    <span class="ch"><i class="g3"></i>2 mineures <em>reprises</em></span>
  </span>
  <span class="act"><a href="#">Voir les 5 ›</a></span></div>
${BENCH}${taches(false)}
<div class="db-tdrop" style="border-top:.5px solid var(--color-border-tertiary);border-radius:12px;margin-top:6px">
  <div class="r"><span class="n">CQ-05 — Fermeture<small>19:30 → 19:58 · L. Simon</small></span>
    <span class="pills">
      <span class="pl f"><i>✓</i>CQ · Relevé caisse<em>19:34</em></span>
      <span class="pl n h"><i>·</i>CQ · Propreté zone cuisson<u>NC hier 2/5</u></span>
      <span class="pl f"><i>✓</i>CQ · Fermeture chambre froide<em>19:58</em></span>
    </span><span class="c wa">2 / 3</span></div>
  <div class="r"><span class="n">CO-04 — Photo du comptoir<small>15:05 · L. Simon</small></span>
    <span class="pills">
      <span class="pl f h"><i>✓</i>Comptoir · Vitrine viennoiseries<em>15:05</em><u class="ctl">NC hier · à contrôler</u></span>
      <span class="pl f h"><i>✓</i>Comptoir · Étiquettes prix<em>08:40</em><u class="ok">NC hier · reprise 5/5</u></span>
    </span><span class="c ok">2 / 2</span></div>
  <div class="r"><span class="n">CQ-01 — Ouverture<small>07:02 → 07:30 · A. Denis, L. Simon</small></span>
    <span class="pills">
      <span class="pl f h"><i>✓</i>CQ · Températures frigo vitrine<em>07:12</em><u class="ok">NC hier · reprise 4/5</u></span>
      <span class="pl f h"><i>✓</i>CQ · Tenue et badge<em>07:05</em><u class="ok">NC hier · reprise 4/5</u></span>
      <span class="pl f"><i>✓</i>CQ · Affichage allergènes<em>07:30</em></span>
    </span><span class="c ok">3 / 3</span></div>
  <div class="leg"><span>✓ rendue · · non rendue</span><span><u>NC hier</u> non conforme la veille, à reprendre</span><span><u class="ok">reprise</u> refaite et notée ≥ 4/5</span><span><u class="ctl">à contrôler</u> refaite, pas encore notée</span></div>
</div>
${RESULTAT}`;


const V4A = `${HEAD}${NCBAR('ferme')}${BENCH}${taches(false)}${RESULTAT}`;
const V4B = `${HEAD}${NCBAR('ouvert')}${NCDL('ouvert')}${BENCH}${taches(false)}${RESULTAT}`;
const V4C = `${HEAD}${NCBAR('fait')}${NCDL('fait')}${BENCH}${taches(false)}${RESULTAT}`;

/* --- écriture ------------------------------------------------------------- */
const PAGES = [
  ['v1-tuile-bandeau.html', 'V1 — une tuile de plus dans le bandeau des tâches',
    'La non-conformité de la veille entre là où le pilotage des tâches se lit déjà : une 6ᵉ tuile, ouverte par clic sur le même tiroir que « le fil de la journée ». Aucun nouvel étage dans la page.', V1],
  ['v2-carte-dediee.html', 'V2 — une carte dédiée sous le bandeau des tâches',
    'La veille a sa propre carte : le compte par gravité, ce que la journée en cours a repris, la semaine en sept barres, puis les cinq lignes avec photo et repères. Le plus lisible, le plus haut dans la page.', V2],
  ['v3-bandeau-alerte.html', 'V3 — un bandeau d’alerte en tête, et des marques dans le fil',
    'Trois lignes en haut de page, avant même le réseau : ce qui reste ouvert saute aux yeux. Le détail ne fait pas de bloc à part — chaque tâche concernée est marquée dans le fil de la journée qui existe déjà.', V3],
  ['v4a-bandeau-replie.html', 'V4 · replié — le bandeau seul, en tête de page',
    'Au chargement, la journée tient en trois lignes : combien de non-conformités hier, lesquelles restent ouvertes. Le bandeau est cliquable, rien d’autre n’a bougé dans la page.', V4A],
  ['v4b-droplist-ouverte.html', 'V4 · déplié — la liste de la veille sort du bandeau',
    'Le clic déroule le contenu de la V2 sous le bandeau : le compte par gravité, la semaine, les cinq lignes avec photo et repères. La colonne de droite porte la validation, et le pied de tiroir le bouton qui la pose.', V4B],
  ['v4c-apres-validation.html', 'V4 · validé — après le bouton',
    'Les trois reprises sont contresignées : les lignes passent au vert, le bandeau le dit sans être déplié, et le pied de tiroir rappelle qui a validé et quand. Ce qui reste ouvert ne bouge pas.', V4C],
];
for (const [f, titre, sous, corps] of PAGES) {
  const html = `<!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>${titre} — Dashboard magasin</title>
<link rel="stylesheet" href="/public/assets/ds/global.css">
<link rel="stylesheet" href="/public/dashboard/dashboard.css">
<link rel="stylesheet" href="maquette.css">
<style>
.mq{max-width:1440px;margin:0 auto;padding:16px 22px 0}
.mq h1{font:400 20px var(--font-display);margin:0 0 4px;color:var(--color-text)}
.mq p{margin:0;font-size:12px;line-height:1.5;color:var(--color-text-muted);max-width:980px}
.mq .u{display:inline-block;font:500 11px var(--font-ui);color:var(--color-text-muted);background:var(--color-background-secondary);border-radius:999px;padding:3px 10px;margin-bottom:8px}
.mq hr{border:none;border-top:.5px solid var(--color-border-secondary);margin:14px 0 0}
</style></head>
<body><div class="mq"><span class="u">maquette · /consulant_bo/dashboard/?shop=4&amp;vue=jour&amp;date=2026-09-14</span><h1>${titre}</h1><p>${sous}</p><hr></div>
<div id="dash">${corps}</div></body></html>`;
  fs.writeFileSync(path.join(OUT, f), html);
  console.log('écrit', f, html.length, 'octets');
}
