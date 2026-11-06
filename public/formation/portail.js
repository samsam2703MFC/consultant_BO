/*
 * Module Formation — le PORTAIL DE FORMATION restructuré (06/11/2026).
 *
 * Client : « le portail est très compliqué à comprendre ; le restructurer : 1. les formations (pré-ouverture,
 * ouverture, continue) ; les métiers sont des postes ; les compétences sont liées aux formations ; les SOP sont pour
 * le manuel opérationnel » ; maquette validée (docs/maquettes/portail-formation/), puis « 21 formations sans phase :
 * mets-les dans les phases nécessaires · Ouverture = la période de lancement, avec le suivi des KPI et le
 * développement commercial · le guide pré-ouverture en bloc Démarches ».
 *
 * Une seule chaîne, lue toujours dans le même ordre : Postes › Formations › Compétences › Manuel opérationnel.
 * Écrans (ancres) :
 *   #/rfformations[/pre|ouv|con][/FO-xx]   Formations par phase ; #/rfformations/pre/demarches  les démarches
 *   #/rfmetiers[/P3]                       Postes : plan de formation en trois phases, compétences, manuel du poste
 *   #/rfcompetences[/<code C-…>]           Compétences (#/rfcompetences/produit : compétences produit du panel)
 *   #/rfdonnees                            Réglages › Données du panel (les écrans d'avant, inchangés)
 * Le Manuel opérationnel = le Classeur du Manuel du réseau (#/rfclasseur, formation-sop.js).
 *
 * Données : GET /formation/ref?detail=1 (src/referentiel.php) — formations (phases, postes O/R, famille,
 * compétences), compétences (formation, standards, panel), standards (postes, moment), guides (pré-ouverture, blocs).
 * La phase d'une formation se change ici (PUT /formation/ref/formations/{code}). Le panel (C.S.ref) donne les
 * évaluations [FO-xx] et les compétences produit, quand il est lu.
 */
(function (FS) {
  'use strict';
  if (!FS) { return; }

  let C = null;   // les outils de formation.js (esc, lire, appel, rendre, S, bandeau…)
  const P = { ref: null, err: null, charge: false, occupe: null, msg: null };
  const esc = s => C.esc(s);
  const K = 'Portail de formation';
  const PH = ['pre', 'ouv', 'con'];
  const GROUPES_POSTES = [['Production', ['P1', 'P5']], ['Vente', ['P3', 'P4']], ['Traiteur', ['P2', 'P6']], ['Encadrement', ['P7', 'P8']],
    ['Franchisé', ['P9', 'P10', 'P11', 'P12']]];
  const FAMILLES_COMP = [['C-VENTE', 'Vente'], ['C-PROD', 'Production'], ['C-TRAITEUR', 'Traiteur'], ['C-SANDWICH', 'Sandwicherie'],
    ['C-SALADE', 'Salades & bowls'], ['C-FROMAGE', 'Fromages'], ['C-BARISTA', 'Barista'], ['C-TRANS', 'Hygiène, sécurité & conformité'],
    ['C-STOCK', 'Stock'], ['C-PLAN', 'Planning & procédures'], ['C-CC', 'Click & Collect'], ['C-WEB', 'Web'], ['C-RH', 'Équipe & RH'],
    ['C-PILOTAGE', 'Pilotage'], ['C-MULTI', 'Multi-boutique']];
  const MOMENTS = [['ouverture', 'Ouverture du magasin'], ['journee', 'Pendant la journée'], ['apres-midi', 'Après-midi'], ['fermeture', 'Fermeture']];
  const QUI_PO = { franchise: 'Franchisé', atelier: 'L’Atelier By', ensemble: 'Ensemble', franchiseur: 'L’Atelier By', formateurs: 'Formateurs', consultant: 'Consultant' };

  /* ── Index ────────────────────────────────────────────────────────────── */

  let X = null, XDe = null;
  function ix() {
    const R = P.ref;
    if (!R) { return null; }
    if (XDe === R) { return X; }
    const par = (l, k) => Object.fromEntries((l || []).map(x => [x[k], x]));
    const items = {};
    (R.blocs || []).forEach(b => (b.items || []).forEach(i => { items[i.id] = Object.assign({ bloc: b.n }, i); }));
    X = { R, fo: par(R.formations, 'code'), comp: par(R.competences, 'code'), std: par(R.standards, 'id'), poste: par(R.postes, 'code'),
      phase: par(R.phasesFormation, 'cle'), famille: par(R.famillesFormation, 'cle'), items };
    XDe = R;
    return X;
  }
  const nomPh = k => ((X && X.phase[k]) || { fr: k }).fr;
  const courtPh = k => ((X && X.phase[k]) || { court: k }).court;
  const stdsDeFo = f => [...new Set(f.competences.flatMap(c => ((X.comp[c] || {}).standards || [])))].map(id => X.std[id]).filter(Boolean);
  const evaluations = code => ((C.S.ref && C.S.ref.formations) || []).filter(e => String(e.nom || '').indexOf('[' + code + ']') === 0);
  const postesTries = att => Object.keys(att || {}).sort((a, b) => (+a.slice(1)) - (+b.slice(1)));
  const enc = s => encodeURIComponent(s);

  /* ── Pièces ───────────────────────────────────────────────────────────── */

  function chaine(on) {
    const R = X.R;
    const t = [['rfmetiers', 'Postes', 'qui', R.postes.length], ['rfformations', 'Formations', 'quand on apprend', R.formations.length],
      ['rfcompetences', 'Compétences', 'ce qu’on sait faire', R.competences.length], ['rfclasseur', 'Manuel opérationnel', 'comment on le fait (SOP)', R.standards.length]];
    return `<nav class="pt-chaine" aria-label="La chaîne du portail">${t.map(([k, l, s, n]) => `<a href="#/${k}" class="${k === on ? 'is-on' : ''}"${k === on ? ' aria-current="page"' : ''}><span class="pt-chaine__n">${n}</span><span class="pt-chaine__l">${l}</span><span class="pt-chaine__s">${s}</span></a>`).join('')}</nav>`;
  }
  function attente(titre) {
    const corps = P.err ? `<p class="error">Référentiel illisible : ${esc(P.err)}</p><p><button type="button" class="btn" data-pa="relire">Réessayer</button></p>`
      : '<p class="muted">Lecture du référentiel…</p>';
    return { titre, kicker: K, corps, large: true };
  }
  const bd = (txt, cls, titre) => `<span class="pt-bd${cls ? ' pt-bd--' + cls : ''}"${titre ? ` title="${esc(titre)}"` : ''}>${esc(txt)}</span>`;
  const bdPh = k => bd(courtPh(k), k);
  function carres(att) {
    return `<span class="pt-qui" aria-hidden="true">${X.R.postes.map(p => `<span class="${att[p.code] === 'O' ? 'o' : att[p.code] === 'R' ? 'r' : ''}" title="${esc(p.code + ' · ' + p.fr + (att[p.code] === 'O' ? ' · obligatoire' : att[p.code] === 'R' ? ' · recommandée' : ''))}"></span>`).join('')}</span>`;
  }
  const legende = () => '<span class="pt-leg"><span class="pt-pt"></span>obligatoire<span class="pt-pt r"></span>recommandée</span>';
  const message = () => P.msg ? `<div class="fm-banner${P.msg.genre ? ' fm-banner--' + P.msg.genre : ''}"><span>${esc(P.msg.txt)}</span></div>` : '';

  /* ── 1. Formations ────────────────────────────────────────────────────── */

  function ecranFormations() {
    if (!ix()) { return attente('Formations'); }
    const a = C.S.route.a, R = X.R;
    const ph = PH.includes(a[0]) ? a[0] : 'pre';
    const demarches = ph === 'pre' && a[1] === 'demarches';
    const sel = !demarches && a[1] && X.fo[a[1]] ? X.fo[a[1]] : null;
    const cnt = k => R.formations.filter(f => f.phases.includes(k)).length;
    const nPts = (R.preouverture && R.preouverture.sections || []).reduce((s, x) => s + (x.points || []).length, 0);
    const sous = { pre: `${nPts} démarches`, ouv: 'période de lancement · 6 semaines', con: 'recyclages et progression' };
    let h = chaine('rfformations') + message();
    h += `<div class="pt-phases">${PH.map(k => `<a class="pt-phase${k === ph ? ' is-on' : ''}" href="#/rfformations/${k}" title="${esc((X.phase[k].description || {}).fr || '')}">
      <span class="pt-phase__n">${cnt(k)}</span><span><span class="pt-phase__t">${esc(nomPh(k))}</span><span class="pt-phase__s">${esc(sous[k])}</span></span></a>`).join('')}</div>`;
    if (ph === 'pre') {
      h += `<div class="page-tabs" role="tablist"><a class="page-tabs__tab${demarches ? '' : ' is-on'}" href="#/rfformations/pre">Formations · ${cnt('pre')}</a>
        <a class="page-tabs__tab${demarches ? ' is-on' : ''}" href="#/rfformations/pre/demarches">Démarches · ${nPts}</a></div>`;
    }
    if (demarches) { return { titre: 'Formations', kicker: K, corps: h + vueDemarches(), large: true }; }
    if (ph === 'ouv') {
      h += `<section class="card pt-lancement"><div class="pt-lancement__t">Période de lancement</div><p>${esc((X.phase.ouv.description || {}).fr || '')}</p>
        <div class="pt-axes"><span>Mise en route de la boutique</span><span>Suivi des KPI</span><span>Développement commercial</span></div></section>`;
    }
    h += `<div class="pt-lay"><div>${listeFormations(ph, sel)}</div><div>${sel ? ficheFormation(sel, ph) : resumePhase(ph)}</div></div>`;
    return { titre: 'Formations', kicker: K, corps: h, large: true };
  }

  function filtrePoste(f) {
    const g = C.S.f.ptPoste || '';
    if (!g) { return true; }
    const ps = (GROUPES_POSTES.find(x => x[0] === g) || [null, []])[1];
    return ps.some(p => f.postes[p]);
  }

  function listeFormations(ph, sel) {
    const q = String(C.S.f.ptq || '').trim().toLowerCase();
    const g = C.S.f.ptPoste || '';
    const liste = X.R.formations.filter(f => f.phases.includes(ph) && filtrePoste(f)
      && (!q || (f.code + ' ' + f.fr + ' ' + (f.controleur || '')).toLowerCase().includes(q)));
    const puce = (v, l) => `<button type="button" class="filter${g === v ? ' is-on' : ''}" data-pa="poste" data-v="${esc(v)}">${esc(l)}</button>`;
    let h = `<section class="card table-card pt-table"><div class="pt-filtres"><span class="muted fm-small">Pour le poste</span>${puce('', 'Tous')}
      ${GROUPES_POSTES.map(x => puce(x[0], x[0])).join('')}<input type="search" placeholder="Rechercher une formation…" data-input="ptq" value="${esc(C.S.f.ptq || '')}">${legende()}</div>
      <div class="pt-tete"><span class="pt-c-code">Code</span><span class="pt-c-nom">Formation</span><span class="pt-qui pt-qui--tete">${X.R.postes.map(p => `<span>${esc(p.code.slice(1))}</span>`).join('')}</span>
      <span class="pt-c-nb">Compét.</span><span class="pt-c-nb">Manuel</span><span class="pt-c-freq">Fréquence</span></div>`;
    const fams = X.R.famillesFormation.map(x => x.cle).concat([null]);
    let vu = 0;
    fams.forEach(fam => {
      const fs = liste.filter(f => (f.famille || null) === fam);
      if (!fs.length) { return; }
      vu += fs.length;
      h += `<div class="pt-grp">${esc(fam ? X.famille[fam].fr : 'Sans famille')} <i>${fs.length}</i></div>` + fs.map(f => {
        const ns = stdsDeFo(f).length;
        const autres = f.phases.filter(k => k !== ph);
        return `<a class="pt-ligne${sel && sel.code === f.code ? ' is-sel' : ''}" href="#/rfformations/${ph}/${enc(f.code)}"><span class="pt-c-code">${esc(f.code)}</span>
          <span class="pt-c-nom">${esc(f.fr.replace(/^Formation /, ''))}${autres.length ? `<span class="pt-aussi">aussi ${autres.map(k => esc(courtPh(k).toLowerCase())).join(', ')}</span>` : ''}</span>
          ${carres(f.postes)}<span class="pt-c-nb${f.competences.length ? '' : ' is-vide'}">${f.competences.length || '—'}</span>
          <span class="pt-c-nb${ns ? '' : ' is-vide'}">${ns ? ns + ' SOP' : '—'}</span><span class="pt-c-freq">${esc(f.frequence || '')}</span></a>`;
      }).join('');
    });
    if (!vu) { h += '<p class="muted pt-vide">Aucune formation dans ce filtre.</p>'; }
    return h + '</section>';
  }

  function resumePhase(ph) {
    const fs = X.R.formations.filter(f => f.phases.includes(ph));
    const comp = new Set(fs.flatMap(f => f.competences));
    const std = new Set(fs.flatMap(f => stdsDeFo(f).map(s => s.id)));
    const parGroupe = GROUPES_POSTES.map(([g, ps]) => [g, fs.filter(f => ps.some(p => f.postes[p] === 'O')).length]);
    return `<section class="card pt-fiche"><div class="pt-kicker">Phase</div><div class="pt-titre">${esc(nomPh(ph))}</div>
      ${ph === 'ouv' ? '' : `<p class="pt-desc">${esc((X.phase[ph].description || {}).fr || '')}</p>`}
      <div class="pt-mini"><span><b>${fs.length}</b>formations</span><span><b>${comp.size}</b>compétences</span><span><b>${std.size}</b>SOP</span></div>
      <div class="pt-bloc"><h3>Formations obligatoires par poste</h3>${parGroupe.map(([g, n]) => `<div class="pt-it"><span class="pt-it__cd">${esc(g)}</span><span class="pt-it__tx">${n} formation${n > 1 ? 's' : ''}</span></div>`).join('')}</div></section>`;
  }

  function ficheFormation(f, ph) {
    const comps = f.competences.map(c => X.comp[c]).filter(Boolean);
    const stds = stdsDeFo(f);
    const evs = evaluations(f.code);
    const oc = P.occupe === 'fo:' + f.code;
    const togg = PH.map(k => `<button type="button" class="pt-tog pt-tog--${k}${f.phases.includes(k) ? ' is-on' : ''}" data-pa="phase" data-code="${esc(f.code)}" data-ph="${k}" ${oc ? 'disabled' : ''} aria-pressed="${f.phases.includes(k)}">${esc(courtPh(k))}</button>`).join('');
    return `<section class="card pt-fiche"><div class="pt-kicker">${esc(f.code)} · formation</div><div class="pt-titre">${esc(f.fr)}</div>
      <div class="pt-meta">${f.controleur ? bd(f.controleur) : ''}${f.frequence ? bd('↻ ' + f.frequence.toLowerCase(), 'r', 'Fréquence de recyclage') : ''}</div>
      <div class="pt-bloc"><h3>Phases <i>un clic ajoute ou retire</i></h3><div class="pt-togs">${togg}</div></div>
      <div class="pt-bloc"><h3>Pour qui <i>${postesTries(f.postes).length} postes</i></h3><div class="pt-postes">${postesTries(f.postes).map(p => `<a href="#/rfmetiers/${esc(p)}" class="pt-bd ${f.postes[p] === 'O' ? 'pt-bd--pre' : 'pt-bd--r'}" title="${f.postes[p] === 'O' ? 'obligatoire' : 'recommandée'}">${esc(p)} · ${esc((X.poste[p] || {}).fr || '')}</a>`).join('') || '<span class="muted">—</span>'}</div></div>
      <div class="pt-bloc"><h3>Ce qu’on apprend <i>${comps.length} compétence${comps.length > 1 ? 's' : ''}</i></h3>${comps.map(c => `<a class="pt-it" href="#/rfcompetences/${enc(c.code)}"><span class="pt-it__cd">${esc(c.code)}</span><span class="pt-it__tx">${esc(c.fr)}<small>${c.panel && c.panel.nom && ['valide', 'certain'].includes(c.panel.etat) ? 'Panel : ' + esc(c.panel.nom) : '<span class="pt-ambre">pas de compétence au panel</span>'}</small></span></a>`).join('') || '<p class="muted">Aucune compétence du v25 n’est rattachée à cette formation.</p>'}</div>
      <div class="pt-bloc"><h3>Dans le manuel <i>à lire avant, « vu faire » après</i></h3>${stds.map(s => `<a class="pt-it" href="#/sop/${enc(s.id)}"><span class="pt-it__cd">${esc(s.code)}</span><span class="pt-it__tx">${esc(s.fr)}</span></a>`).join('') || '<p class="muted">Aucune SOP ne s’y rattache encore.</p>'}</div>
      <div class="pt-bloc"><h3>Évaluation au panel</h3>${evs.map(e => `<div class="pt-it"><span class="pt-it__cd">#${esc(e.id)}</span><span class="pt-it__tx">${esc(e.nom)}</span></div>`).join('') || `<p class="muted">${C.S.ref ? 'Aucune évaluation [' + esc(f.code) + '] au panel.' : 'Panel en cours de lecture…'}</p>`}</div>
    </section>`;
  }

  function vueDemarches() {
    const secs = (X.R.preouverture && X.R.preouverture.sections) || [];
    if (!secs.length) { return '<section class="card"><p class="muted">Le guide pré-ouverture n’est pas encore lu.</p></section>'; }
    return `<div class="pt-dem-bar"><span class="muted fm-small">Le Guide pré-ouverture : ce qu’il faut faire avant l’ouverture, qui le fait et quand.</span><a class="btn btn--sm" href="#/rfpre">Modifier le guide</a></div>
      <div class="pt-dem">${secs.map(s => `<section class="card pt-fiche"><div class="pt-titre pt-titre--s">${esc(s.fr)}</div>
        ${(s.points || []).length ? `<div class="pt-tete pt-tete--dem"><span class="pt-c-j">J-</span><span class="pt-c-nom">Point</span><span class="pt-c-qui">Qui</span></div>` + s.points.map(p => `<div class="pt-ligne pt-ligne--dem"><span class="pt-c-j">${p.j != null && p.j !== '' ? 'J-' + esc(p.j) : '—'}</span>
          <span class="pt-c-nom">${esc(p.fr)}${p.preuve_fr ? `<small>Preuve : ${esc(p.preuve_fr)}</small>` : ''}</span><span class="pt-c-qui">${esc(QUI_PO[p.qui] || p.qui || '')}</span></div>`).join('')
          : `<p class="muted fm-small">${esc(s.intro_fr || '')}</p>`}</section>`).join('')}</div>`;
  }

  /* ── 2. Postes ────────────────────────────────────────────────────────── */

  function ecranPostes() {
    if (!ix()) { return attente('Postes'); }
    const R = X.R, code = X.poste[C.S.route.a[0]] ? C.S.route.a[0] : 'P1';
    const p = X.poste[code];
    const fos = R.formations.filter(f => f.postes[code]);
    const col = k => {
      const fs = fos.filter(f => f.phases.includes(k)).sort((a, b) => (a.postes[code] === 'O' ? 0 : 1) - (b.postes[code] === 'O' ? 0 : 1) || a.code.localeCompare(b.code));
      const o = fs.filter(f => f.postes[code] === 'O').length;
      return `<section class="card pt-col"><h3>${bdPh(k)}<i>${o} obligatoire${o > 1 ? 's' : ''} · ${fs.length - o} recomm.</i></h3>
        ${fs.map(f => `<a class="pt-fo" href="#/rfformations/${k}/${enc(f.code)}"><span class="pt-pt${f.postes[code] === 'R' ? ' r' : ''}"></span><span class="pt-it__cd">${esc(f.code)}</span><span>${esc(f.fr.replace(/^Formation /, ''))}</span></a>`).join('') || '<p class="muted">—</p>'}</section>`;
    };
    const comps = [...new Set(fos.filter(f => f.postes[code] === 'O').flatMap(f => f.competences))].map(c => X.comp[c]).filter(Boolean);
    const stds = R.standards.filter(s => (s.postes || []).includes(code) && s.statut !== 'brouillon');
    const moms = MOMENTS.map(([m, l]) => [l, stds.filter(s => s.moment === m)]).concat([['Au besoin', stds.filter(s => !MOMENTS.some(x => x[0] === s.moment))]]).filter(x => x[1].length);
    const liste = GROUPES_POSTES.map(([g, ps]) => `<div class="pt-grp">${esc(g)}</div>` + ps.filter(x => X.poste[x]).map(x => `<a class="pt-lp${x === code ? ' is-sel' : ''}" href="#/rfmetiers/${x}"><span class="pt-it__cd">${x}</span>${esc(X.poste[x].fr)}<span class="pt-lp__n" title="formations obligatoires">${R.formations.filter(f => f.postes[x] === 'O').length}</span></a>`).join('')).join('');
    const h = chaine('rfmetiers') + `<div class="pt-pl"><section class="card pt-liste">${liste}</section><div>
      <div class="pt-hdp"><div><div class="pt-kicker">${esc(code)} · poste</div><div class="pt-titre pt-titre--l">${esc(p.fr)}</div>${p.nl ? `<span class="sub">${esc(p.nl)}</span>` : ''}</div>${legende()}</div>
      <div class="pt-cols">${PH.map(col).join('')}</div>
      <div class="pt-deux"><section class="card pt-fiche"><div class="pt-bloc pt-bloc--1"><h3>Ses compétences <i>${comps.length} · des formations obligatoires</i></h3>
        ${comps.map(c => `<a class="pt-it" href="#/rfcompetences/${enc(c.code)}"><span class="pt-it__cd">${esc(c.code)}</span><span class="pt-it__tx">${esc(c.fr)}</span>${c.panel && ['valide', 'certain'].includes(c.panel.etat) ? '<span class="pt-ok">● panel</span>' : '<span class="pt-non">○ hors panel</span>'}</a>`).join('') || '<p class="muted">—</p>'}</div></section>
        <section class="card pt-fiche"><div class="pt-bloc pt-bloc--1"><h3>Son manuel <i>${stds.length} SOP · « Ma journée » sur la tablette</i></h3>
        ${moms.map(([l, ss]) => `<div class="pt-mom">${esc(l)}</div>` + ss.map(s => `<a class="pt-it" href="#/sop/${enc(s.id)}"><span class="pt-it__cd">${esc(s.code)}</span><span class="pt-it__tx">${esc(s.fr)}</span></a>`).join('')).join('') || '<p class="muted">Aucune SOP pour ce poste.</p>'}</div></section></div>
    </div></div>`;
    return { titre: 'Postes', kicker: K, corps: h, large: true };
  }

  /* ── 3. Compétences ───────────────────────────────────────────────────── */

  function ecranCompetences() {
    if (!ix()) { return attente('Compétences'); }
    const a = C.S.route.a, R = X.R;
    const produit = a[0] === 'produit';
    const sel = !produit && a[0] ? X.comp[a[0]] : null;
    const nProd = ((C.S.ref && C.S.ref.competencesProduit) || []).length;
    let h = chaine('rfcompetences') + `<div class="page-tabs" role="tablist"><a class="page-tabs__tab${produit ? '' : ' is-on'}" href="#/rfcompetences">Compétences du réseau · ${R.competences.length}</a>
      <a class="page-tabs__tab${produit ? ' is-on' : ''}" href="#/rfcompetences/produit">Compétences produit${nProd ? ' · ' + nProd : ''}</a></div>`;
    if (produit) { return { titre: 'Compétences', kicker: K, corps: h + vueProduit(), large: true }; }
    h += `<div class="pt-lay"><div>${listeCompetences(sel)}</div><div>${sel ? ficheCompetence(sel) : resumeCompetences()}</div></div>`;
    return { titre: 'Compétences', kicker: K, corps: h, large: true };
  }

  const relieePanel = c => c.panel && ['valide', 'certain'].includes(c.panel.etat);
  function listeCompetences(sel) {
    const q = String(C.S.f.pcq || '').trim().toLowerCase(), f = C.S.f.pcF || '';
    const R = X.R;
    const ok = c => (!q || (c.code + ' ' + c.fr + ' ' + (c.description || '')).toLowerCase().includes(q))
      && (!f || (f === 'sansSop' && !c.standards.length) || (f === 'horsPanel' && !relieePanel(c)));
    const puce = (v, l, n) => `<button type="button" class="filter${f === v ? ' is-on' : ''}" data-pa="compF" data-v="${v}">${l} · ${n}</button>`;
    let h = `<section class="card table-card pt-table"><div class="pt-filtres">${puce('', 'Toutes', R.competences.length)}${puce('sansSop', 'Sans SOP', R.competences.filter(c => !c.standards.length).length)}
      ${puce('horsPanel', 'Hors panel', R.competences.filter(c => !relieePanel(c)).length)}<input type="search" placeholder="Rechercher une compétence…" data-input="pcq" value="${esc(C.S.f.pcq || '')}"></div>
      <div class="pt-tete"><span class="pt-c-cc">Code</span><span class="pt-c-nom">Compétence</span><span class="pt-c-code">Formation</span><span class="pt-c-phs">Phases</span><span class="pt-c-nb">Manuel</span><span class="pt-c-pan">Panel</span></div>`;
    const connues = FAMILLES_COMP.map(x => x[0]);
    const groupes = FAMILLES_COMP.concat([[null, 'Autres']]);
    let vu = 0;
    groupes.forEach(([fam, lib]) => {
      const cs = R.competences.filter(c => (fam ? c.famille === fam : !connues.includes(c.famille)) && ok(c));
      if (!cs.length) { return; }
      vu += cs.length;
      h += `<div class="pt-grp">${esc(lib)} <i>${cs.length}</i></div>` + cs.map(c => {
        const fo = X.fo[c.formation];
        return `<a class="pt-ligne${sel && sel.code === c.code ? ' is-sel' : ''}" href="#/rfcompetences/${enc(c.code)}"><span class="pt-c-cc">${esc(c.code)}</span><span class="pt-c-nom">${esc(c.fr)}</span>
          <span class="pt-c-code">${esc(c.formation || '—')}</span><span class="pt-c-phs">${fo ? fo.phases.map(bdPh).join('') : ''}</span>
          <span class="pt-c-nb${c.standards.length ? '' : ' is-vide'}">${c.standards.length ? c.standards.length + ' SOP' : '—'}</span>
          <span class="pt-c-pan">${relieePanel(c) ? '<span class="pt-ok">● relié</span>' : '<span class="pt-non">○ hors panel</span>'}</span></a>`;
      }).join('');
    });
    if (!vu) { h += '<p class="muted pt-vide">Aucune compétence dans ce filtre.</p>'; }
    return h + '</section>';
  }

  function resumeCompetences() {
    const R = X.R, n = R.competences.length;
    const rel = R.competences.filter(relieePanel).length, sop = R.competences.filter(c => c.standards.length).length;
    const crit = R.competences.filter(c => X.items[c.code] && X.items[c.code].critere_fr).length;
    return `<section class="card pt-fiche"><div class="pt-kicker">Les compétences du réseau</div><div class="pt-titre">${n} compétences</div>
      <p class="pt-desc">Chaque compétence est enseignée par une formation, montrée par des SOP du manuel, validée sur un critère « réussi si », et cochée au panel TF Buddy par le « vu faire » de la tablette.</p>
      <div class="pt-mini"><span><b>${rel}</b>reliées au panel</span><span><b>${sop}</b>avec une SOP</span><span><b>${crit}</b>avec un critère</span></div>
      <div class="pt-bloc"><h3>Réglages</h3><a class="pt-it" href="#/sopcomp"><span class="pt-it__tx">Correspondance avec le panel</span></a><a class="pt-it" href="#/rfform"><span class="pt-it__tx">Critères « réussi si » et blocs du guide</span></a></div></section>`;
  }

  function ficheCompetence(c) {
    const fo = X.fo[c.formation], it = X.items[c.code];
    const stds = c.standards.map(id => X.std[id]).filter(Boolean);
    const postes = fo ? postesTries(fo.postes) : [];
    const poles = X.R.poles && X.R.poles.fr ? X.R.poles.fr : [];
    return `<section class="card pt-fiche"><div class="pt-kicker">${esc(c.code)} · compétence</div><div class="pt-titre">${esc(c.fr)}</div>
      ${c.description ? `<p class="pt-desc">${esc(c.description)}</p>` : ''}
      <div class="pt-bloc"><h3>Réussi si${it ? ` <i>bloc ${esc(it.bloc)} du guide</i>` : ''}</h3>${it && it.critere_fr ? `<p class="pt-crit">${esc(it.critere_fr)}</p>
        <div class="pt-meta">${it.bloq ? bd('Bloquante', 'pre') : bd('Non bloquante', 'r')}${it.poles ? bd('Notée sur les 5 pôles', 'r', poles.join(' · ')) : ''}${it.renouvellement ? bd('↻ tous les ' + it.renouvellement + ' mois', 'r') : ''}</div>`
        : '<p class="muted">Pas encore de critère.</p>'}${it ? `<a class="linklike" href="#/rfform/${esc(it.bloc)}/${enc(c.code)}">Modifier le critère</a>` : ''}</div>
      <div class="pt-bloc"><h3>Formation qui l’enseigne</h3>${fo ? `<a class="pt-it" href="#/rfformations/${fo.phases[0] || 'pre'}/${enc(fo.code)}"><span class="pt-it__cd">${esc(fo.code)}</span><span class="pt-it__tx">${esc(fo.fr)}<small>${fo.phases.map(k => esc(nomPh(k))).join(' · ')}</small></span></a>` : '<p class="muted">Aucune formation.</p>'}</div>
      <div class="pt-bloc"><h3>Pour qui <i>d’après la formation</i></h3><div class="pt-postes">${postes.map(p => `<a href="#/rfmetiers/${esc(p)}" class="pt-bd ${fo.postes[p] === 'O' ? 'pt-bd--pre' : 'pt-bd--r'}">${esc(p)} · ${esc((X.poste[p] || {}).fr || '')}</a>`).join('') || '<span class="muted">—</span>'}</div></div>
      <div class="pt-bloc"><h3>Dans le manuel <i>${stds.length} SOP</i></h3>${stds.map(s => `<a class="pt-it" href="#/sop/${enc(s.id)}"><span class="pt-it__cd">${esc(s.code)}</span><span class="pt-it__tx">${esc(s.fr)}</span></a>`).join('') || '<p class="muted">Aucune SOP ne montre encore cette compétence.</p>'}</div>
      <div class="pt-bloc"><h3>Au panel TF Buddy</h3>${relieePanel(c) ? `<div class="pt-it"><span class="pt-it__cd">#${esc(c.panel.id)}</span><span class="pt-it__tx">${esc(c.panel.nom)}<small>cochée par le « vu faire » de la tablette</small></span></div>`
        : '<p class="muted">Aucune compétence du panel : le « vu faire » ne coche rien.</p>'}<a class="linklike" href="#/sopcomp">Modifier la correspondance</a></div>
    </section>`;
  }

  function vueProduit() {
    const L = (C.S.ref && C.S.ref.competencesProduit) || null;
    if (!L) { return `<section class="card"><p class="muted">${C.S.refErreur ? 'Panel illisible : les compétences produit ne se lisent pas.' : 'Lecture du panel…'}</p></section>`; }
    return `<section class="card table-card pt-table"><div class="pt-tete"><span class="pt-c-code">#</span><span class="pt-c-nom">Compétence produit (panel)</span></div>
      ${L.map(x => `<div class="pt-ligne"><span class="pt-c-code">${esc(x.id)}</span><span class="pt-c-nom">${esc(x.nom)}${x.description ? `<small>${esc(x.description)}</small>` : ''}</span></div>`).join('')}</section>`;
  }

  /* ── Réglages › Données du panel ──────────────────────────────────────── */

  function ecranDonnees() {
    const R = C.S.ref;
    const n = k => R && Array.isArray(R[k]) ? R[k].length : null;
    const cartes = [['tableau', 'Carte des métiers', 'Les postes du panel et leurs effectifs', null], ['postes', 'Postes & sous-postes', 'Les postes du panel et leurs niveaux', n('postes')],
      ['competences', 'Compétences du panel', 'Les compétences de TF Buddy', n('competences')], ['formations', 'Évaluations du panel', 'Les évaluations [FO-xx]', n('formations')],
      ['procedures', 'Procédures du panel', 'Les procédures opératoires de TF Buddy', n('procedures')], ['ressources', 'Base de connaissances', 'Les documents de référence', n('ressources')],
      ['produit', 'Compétences produit', 'Les compétences produit du panel', n('competencesProduit')], ['equipes', 'Compétences des équipes', 'L’état de chaque personne, par magasin', null],
      ['api', 'Routes API', 'La documentation des routes du panel', null]];
    const h = `<div class="pt-donnees">${cartes.map(([k, t, s, nb]) => `<a class="card pt-carte" href="#/${k}"><span class="pt-carte__t">${esc(t)}</span><span class="pt-carte__s">${esc(s)}</span>${nb != null ? `<span class="pt-carte__n">${nb}</span>` : ''}</a>`).join('')}</div>`;
    return { titre: 'Données du panel', kicker: 'Réglages', corps: h, large: true };
  }

  /* ── Gestes ───────────────────────────────────────────────────────────── */

  async function basculerPhase(code, ph) {
    const f = X && X.fo[code];
    if (!f || P.occupe) { return; }
    const nouv = f.phases.includes(ph) ? f.phases.filter(k => k !== ph) : PH.filter(k => k === ph || f.phases.includes(k));
    if (!nouv.length && !confirm(`Retirer ${code} de toutes les phases ? Elle n’apparaîtra plus dans les formations.`)) { return; }
    P.occupe = 'fo:' + code; P.msg = null; C.rendre(true);
    try {
      const r = await C.appel('PUT', '/formation/ref/formations/' + enc(code), { phases: nouv });
      f.phases = r.phases;
      P.msg = { txt: `${code} : ${r.phases.length ? r.phases.map(nomPh).join(', ') : 'aucune phase'}.`, genre: '' };
      // La formation quitte la phase affichée : on y reste, la fiche se ferme.
      const a = C.S.route.a;
      if (a[1] === code && !r.phases.includes(a[0])) { location.hash = '#/rfformations/' + (r.phases[0] || a[0]) + (r.phases[0] ? '/' + enc(code) : ''); }
    } catch (e) {
      P.msg = { txt: 'Phases non enregistrées : ' + e.message, genre: 'warn' };
    }
    P.occupe = null; C.rendre(true);
  }

  function charger(forcer) {
    if ((P.ref && !forcer) || P.charge) { return; }
    P.charge = true; P.err = null;
    C.lire('/formation/ref?detail=1', 30000).then(d => { P.ref = d; P.charge = false; C.rendre(true); },
      e => { P.err = e.message; P.charge = false; C.rendre(true); });
  }

  function brancher(root) {
    root.addEventListener('click', ev => {
      const t = ev.target.closest && ev.target.closest('[data-pa]');
      if (!t) { return; }
      const k = t.getAttribute('data-pa');
      if (k === 'phase') { ev.preventDefault(); basculerPhase(t.getAttribute('data-code'), t.getAttribute('data-ph')); }
      if (k === 'poste') { C.S.f.ptPoste = t.getAttribute('data-v') || ''; C.rendre(true); }
      if (k === 'compF') { C.S.f.pcF = t.getAttribute('data-v') || ''; C.rendre(true); }
      if (k === 'relire') { charger(true); }
    });
  }

  /* ── Branchement sur formation-sop.js et formation.js ─────────────────── */

  const ECRANS = { rfformations: ecranFormations, rfmetiers: ecranPostes, rfcompetences: ecranCompetences, rfdonnees: ecranDonnees };
  Object.keys(ECRANS).forEach(k => { FS.ecrans[k] = ECRANS[k]; });
  FS.icones.rfdonnees = 'M4 6c0-1.7 3.6-3 8-3s8 1.3 8 3-3.6 3-8 3-8-1.3-8-3zM4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6';
  const initAvant = FS.init, apresAvant = FS.apres;
  FS.init = function (ctx, root) { initAvant.call(FS, ctx, root); C = ctx; brancher(root); };
  FS.apres = function (e, a) { apresAvant.call(FS, e, a); if (e === 'rfformations' || e === 'rfmetiers' || e === 'rfcompetences') { charger(false); } };

  /**
   * Le rail du portail (lu par formation.js) : [libellé du groupe, entrées, numéroté ?] ; une entrée = [écran, libellé,
   * écrans où elle reste allumée]. Les écrans d'avant restent à leur adresse, rangés sous Réglages › Données du panel.
   */
  window.FormationPortail = {
    defaut: 'rfformations',
    titres: { rfformations: 'Formations', rfmetiers: 'Postes', rfcompetences: 'Compétences', rfdonnees: 'Données du panel' },
    rail: [
      ['Portail de formation', [['rfformations', 'Formations', ['rfpre']], ['rfmetiers', 'Postes', ['rfposte']], ['rfcompetences', 'Compétences', ['rfform']],
        ['rfclasseur', 'Manuel opérationnel', ['rfphase', 'sop', 'manuel']]], true],
      ['Suivi', [['parcours', 'Suivi des équipes', ['equipes']]], false],
      ['Réglages', [['sopcomp', 'Correspondance panel', []], ['tablettes', 'Tablettes', []], ['sopred', 'Rédaction des SOP', []],
        ['rfdonnees', 'Données du panel', ['tableau', 'postes', 'competences', 'formations', 'procedures', 'ressources', 'produit', 'api']]], false],
    ],
  };
})(window.FormationSop);
