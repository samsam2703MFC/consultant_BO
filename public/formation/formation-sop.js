/* Module Formation › Standards (SOP) — les SOP du réseau, en français et en néerlandais (src/sop.php).
 *
 * Chargé AVANT formation.js, qui l'appelle par quelques accroches : la barre latérale (groupe « Standards (SOP) »),
 * ses écrans, ses lectures, et chaque partie du Manuel opératoire, où les SOP se rangent à côté des procédures et des
 * formations FO. Les SOP vivent dans nos tables : ces écrans s'affichent même quand le référentiel du panel est
 * illisible.
 *
 *   #/sop                 les SOP par famille (FR et NL, compétence du panel, partie du manuel, progression)
 *   #/sop/<id>            une SOP : FR et NL côte à côte, partie du manuel, compétence, PDF, équipe, quiz, versions
 *   #/sop/<id>/editer     modifier dans les deux langues, publier une nouvelle version
 *   #/sopcomp             compétences : réseau × compétence, magasin × personne, réglages de l'équipe, fil
 *   #/sopred              rédaction : langues à relire, compétence du panel, statut, nouvelle SOP
 *
 * MANUEL DU RÉSEAU (fusion du 06/11/2026, src/referentiel.php) — le groupe de la barre latérale s'appelle désormais
 * « Manuel du réseau » :
 *   #/rfclasseur[/D5]     le classeur par domaine du référentiel v25 : sous-catégories, chaîne standard → formation →
 *                         compétence → contrôle, trous ; reprise du Manuel opératoire du CRM
 *   #/rfphase/<phase>     par phase (calculée) · #/rfposte[/P3/<phase>] par poste × phase (calculée)
 *   #/sopcomp             compétences : correspondance C-… ↔ panel (un clic), puis l'onglet « Acquisition en magasin »
 *   la fiche d'une SOP gagne le bloc « Classement » (sous-catégorie, phases, postes, compétences, formations, checklists)
 *   #/rfpre               le Guide pré-ouverture (sections et points) · #/rfform[/<n>[/<id>]] le Guide des formations et
 *                         compétences (blocs, points, critères, 5 pôles) — lot 4b : le CRM les lit, il ne les édite plus
 *
 * La tablette du poste (lire pas à pas, quiz, « vu faire ») est une page à part : sop/tablette.html.
 */
window.FormationSop = (function () {
  'use strict';
  let C = null;   // le contexte de formation.js : esc, lire, appel, rendre, S, filAriane, bandeau
  const POSTES = ['vente', 'cuisson', 'production', 'nettoyage'];
  const POSTE_NOM = { vente: 'Vente', cuisson: 'Cuisson', production: 'Production', nettoyage: 'Nettoyage' };
  const TRAD_NOM = { origine: 'origine', relue: 'relue', a_relire: 'à relire' };
  const ST_NOM = { non: 'pas commencée', lu: 'lue · quiz à faire', quiz: 'quiz réussi · à voir faire', vu: 'vue faire', a_revoir: 'à revoir (nouvelle version)' };
  const ST_CHIP = { non: 'chip--mute', lu: 'chip--soft', quiz: 'chip--warn', vu: 'chip--ok', a_revoir: 'chip--ko' };
  const PARTIES = [['preouverture', 'Pré-ouverture'], ['ouverture', 'Ouverture'], ['operationnel', 'Opérationnel'], ['formation', 'Formation continue']];
  const NOM_PARTIE = Object.fromEntries(PARTIES);

  const D = {
    liste: null, totaux: null, dossiers: [], erreur: null, charge: false,
    fiche: {}, archive: null, comp: null, compCharge: false, fil: null, magasins: null,
    shop: '', vue: 'deux', f: { poste: '', relire: false, valider: false, sansComp: false },
    ed: null, edMeta: null, edErr: null, occupe: false, nouv: null,
    // Manuel du réseau : référentiel, correspondance, classements en cours d'édition, sous-catégorie éditée, reprise du CRM
    ref: null, refErr: null, refCharge: false, cor: null, corErr: null, corCharge: false, corF: 'propose', corBilan: null,
    cl: {}, scEd: null, imp: null, domSel: 'D5'
  };
  try { D.shop = localStorage.getItem('fm.sop.shop') || ''; D.vue = localStorage.getItem('fm.sop.vue') || 'deux'; } catch (e) { /* stockage indisponible */ }

  const esc = v => C.esc(v);
  const L = (v, l) => { if (!v) { return ''; } if (typeof v === 'string') { return v; } l = l || 'fr'; return v[l] || v[l === 'nl' ? 'fr' : 'nl'] || ''; };
  const dt = iso => iso ? new Date(iso).toLocaleDateString('fr-BE', { day: '2-digit', month: '2-digit' }) : '';
  const dth = iso => iso ? new Date(iso).toLocaleString('fr-BE', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '';
  const tablette = () => !!(C && C.S.tablette);
  function toast(m) { const el = document.createElement('div'); el.className = 'sop-toast'; el.textContent = m; document.body.appendChild(el); setTimeout(() => el.remove(), 3000); }

  /* ── Pièces ── */
  const trad = s => ['fr', 'nl'].map(l => { const t = s.trad[l]; return `<span class="sop-lg ${t === 'a_relire' ? 'is-ar' : 'is-on'}" title="${l.toUpperCase()} · ${TRAD_NOM[t]}">${l.toUpperCase()}${t === 'a_relire' ? ' ·' : ''}</span>`; }).join(' ');
  // La compétence d'une SOP : ses codes C-… du Manuel du réseau ; à défaut l'ancienne compétence du panel (repli).
  const codesC = s => (s.classement && s.classement.competences) || [];
  const comp = s => codesC(s).length ? codesC(s).map(c => `<span class="sop-comp" title="code du référentiel v25">${esc(c)}</span>`).join(' ')
    : s.competence ? `<span class="sop-comp" title="compétence du panel (ancienne règle)">${esc(s.competence.nom)}</span>` : '<span class="sop-comp is-sans">sans compétence</span>';
  const famNom = d => L((D.dossiers.find(x => x.id === d) || {}).nom);
  const pdf = (chemin, l) => `${C.API}${chemin}${chemin.includes('?') ? '&' : '?'}langue=${l}`;
  // La part acquise : du gris du système au vert (--green, rgb 63 122 82) en quatre teintes de ce même vert.
  const heat = v => v == null ? '<span class="muted">—</span>' : `<span class="sop-heat" style="background:${v < 20 ? 'var(--chip)' : v < 40 ? 'rgba(63, 122, 82, 0.12)' : v < 60 ? 'rgba(63, 122, 82, 0.3)' : v < 80 ? 'rgba(63, 122, 82, 0.6)' : 'var(--green)'};color:${v < 20 ? 'var(--muted)' : v < 60 ? 'var(--green)' : '#fff'}">${v} %</span>`;
  const tabletteLien = (poste) => `sop/tablette.html?${new URLSearchParams(Object.assign(D.shop ? { shop: D.shop } : {}, poste ? { poste } : {})).toString()}`;
  const choixMagasin = () => `<select class="fm-select" data-sch="shop" style="max-width:240px"><option value="">Choisir un magasin…</option>${(D.magasins || []).map(m => `<option value="${esc(m.id)}" ${m.id === D.shop ? 'selected' : ''}>${esc(m.nom)}</option>`).join('')}</select>`;
  function filtrer(l) {
    const q = String(C.S.f.sopq || '').trim().toLowerCase();
    return l.filter(s => (!D.f.poste || s.poste === D.f.poste) && (!D.f.relire || s.trad.fr === 'a_relire' || s.trad.nl === 'a_relire')
      && (!D.f.valider || s.statut === 'a_valider') && (!D.f.sansComp || (!s.competence && !codesC(s).length))
      && (!q || [s.titre.fr, s.titre.nl, s.code, s.objectif.fr, s.objectif.nl, s.competence && s.competence.nom, codesC(s).join(' ')].join(' ').toLowerCase().includes(q)));
  }
  const attente = t => `<p class="muted">${esc(t)}</p>`;
  function erreurOuAttente() { return D.erreur ? `<p class="error">${esc(D.erreur)}</p><p><button type="button" class="btn btn--sm" data-sa="relireListe">Réessayer</button></p>` : attente('Lecture des SOP…'); }

  /* ═══ Les SOP (liste) ═══ */
  function ecranListe() {
    if (!D.liste) { return { titre: 'Standards', kicker: K, corps: erreurOuAttente() }; }
    const T = D.totaux, l = filtrer(D.liste);
    const vus = D.liste.reduce((a, s) => a + s.progression.vu, 0), quiz = D.liste.reduce((a, s) => a + s.progression.quiz, 0);
    let h = `<div class="fm-kpis">
      <a class="fm-kpi" href="#/sop"><span class="fm-kpi__n">${T.sops - T.aValider - (T.brouillons || 0)}</span><span class="fm-kpi__l">SOP en vigueur</span><span class="fm-kpi__s">+ ${T.aValider} à valider${T.brouillons ? ' · ' + T.brouillons + ' brouillon(s)' : ''} · ${T.etapes} étapes · FR et NL</span></a>
      <a class="fm-kpi" href="#/sopcomp"><span class="fm-kpi__n">${vus}</span><span class="fm-kpi__l">vues faire · réseau</span><span class="fm-kpi__s">${quiz} quiz réussis en attente de la responsable</span></a>
      <a class="fm-kpi" href="#/sopred"><span class="fm-kpi__n">${T.tradARelire}</span><span class="fm-kpi__l">traductions à relire</span><span class="fm-kpi__s">la langue d’origine fait foi</span></a>
      <a class="fm-kpi" href="#/rfclasseur"><span class="fm-kpi__n">${D.liste.filter(s => codesC(s).length).length} / ${T.sops}</span><span class="fm-kpi__l">reliées à une compétence</span><span class="fm-kpi__s">codes C-… du référentiel v25</span></a></div>`;
    const f = (k, v, lib, on) => `<button type="button" class="filter${on ? ' is-on' : ''}" data-sa="filtre" data-k="${k}" data-v="${v}">${lib}</button>`;
    h += `<div class="filters"><div class="filters__row"><input class="fm-search" type="search" placeholder="Rechercher une SOP, une compétence…" data-input="sopq" value="${esc(C.S.f.sopq || '')}">
      ${f('poste', '', 'Tous les postes', !D.f.poste)}${POSTES.map(p => f('poste', p, POSTE_NOM[p], D.f.poste === p)).join('')}
      ${f('relire', !D.f.relire, 'Traduction à relire', D.f.relire)}${f('valider', !D.f.valider, 'À valider', D.f.valider)}${f('sansComp', !D.f.sansComp, 'Sans compétence', D.f.sansComp)}
      <a class="btn btn--sm" href="${tabletteLien('')}" target="_blank" rel="noopener">Tablette du poste ↗</a></div></div>`;
    h += `<p class="muted fm-small"><span class="sop-lg is-on">FR</span> <span class="sop-lg is-on">NL</span> langue d’origine ou relue · <span class="sop-lg is-ar">NL ·</span> traduite à la reprise, à relire</p>`;
    D.dossiers.forEach(d => {
      const g = l.filter(s => s.dossier === d.id);
      if (!g.length) { return; }
      h += `<div class="section-label">${esc(d.id)} · ${esc(L(d.nom))} · ${g.length} SOP</div><ul class="sop-cartes">${g.map(carte).join('')}</ul>`;
    });
    if (!l.length) { h += '<p class="muted">Aucune SOP ne correspond.</p>'; }
    return { titre: 'Standards', kicker: K, corps: h, large: true };
  }
  function carte(s) {
    const p = s.progression;
    return `<li><a class="sop-carte${s.statut === 'a_valider' ? ' is-av' : ''}" href="#/sop/${esc(s.id)}">
      <span class="sop-tags"><span class="chip chip--soft">${esc(s.type)}</span>${trad(s)}<span class="sop-lg">v${esc(s.version)}</span>${s.statut === 'a_valider' ? '<span class="chip chip--warn">À valider</span>' : ''}${s.statut === 'brouillon' ? '<span class="chip chip--mute">Brouillon</span>' : ''}${(phasesDe(s) || [s.partie]).map(p => `<span class="chip chip--mute">${esc(NOM_PARTIE[p] || '')}</span>`).join('')}${s.classement && s.classement.souscat ? `<span class="chip chip--mute" title="sous-catégorie du référentiel v25">${esc(s.classement.souscat)}</span>` : '<span class="chip chip--warn">à classer</span>'}</span>
      <strong>${esc(s.titre.fr)}</strong><span class="sop-ob">${esc(L(s.objectif))}</span><span>${comp(s)}</span>
      <span class="muted fm-small">${p.vu} vu(s) faire · ${p.quiz} quiz · ${p.lu} lu(s)${p.aRevoir ? ` · ${p.aRevoir} à revoir` : ''}</span>
      <span class="sop-pied"><span><b>${s.nbEtapes}</b> étapes</span>${s.nbPhotos ? `<span>${s.nbPhotos} photo${s.nbPhotos > 1 ? 's' : ''}</span>` : ''}<span>${esc(POSTE_NOM[s.poste] || s.poste)}</span><span class="sop-code">${esc(s.code || '')}</span></span></a></li>`;
  }

  /* ═══ Une SOP ═══ */
  function ecranFiche(id) {
    const s = D.fiche[id];
    const fil = C.filAriane([['Standards', '#/sop'], [s && !s.error ? s.titre.fr : id, null]]);
    if (!s) { return { titre: 'SOP', kicker: K, corps: fil + attente('Lecture de la SOP…') }; }
    if (s.error) { return { titre: 'SOP', kicker: K, corps: fil + `<p class="error">${esc(s.error)}</p>` }; }
    const c = D.archive && D.archive.id === id ? D.archive.contenu : s.contenu;
    const deux = D.vue === 'deux', l1 = D.vue === 'nl' ? 'nl' : 'fr';
    let etapes = deux ? `<div class="sop-bil" style="border-bottom:1px solid var(--line)"><span></span><span class="muted fm-small">Français · ${TRAD_NOM[s.trad.fr]}</span><span class="muted fm-small">Nederlands · ${TRAD_NOM[s.trad.nl]}</span><span></span></div>` : '';
    (c.sections || []).forEach(sec => {
      etapes += `<div class="sop-sec">${esc(L(sec.titre, l1))}${deux && sec.titre.nl ? `<small>${esc(sec.titre.nl)}</small>` : ''}</div>`;
      sec.etapes.forEach(e => {
        etapes += `<div class="sop-bil${deux ? '' : ' is-une'}${e.critique ? ' is-crit' : ''}"><span class="sop-bil__n">${esc(e.numero)}</span>
          <div class="sop-bil__tx">${e.titre && L(e.titre, l1) ? `<b>${esc(L(e.titre, l1))}</b>` : ''}${esc(L(e.texte, l1))}</div>
          ${deux ? `<div class="sop-bil__tx is-nl">${e.titre && e.titre.nl ? `<b>${esc(e.titre.nl)}</b>` : ''}${esc(e.texte.nl || '')}</div>` : ''}
          ${e.photo ? `<img src="sop/photos/${esc(e.photo)}" data-sa="zoom" data-v="sop/photos/${esc(e.photo)}" alt="">` : '<span></span>'}</div>`;
      });
    });
    const vb = (v, lib) => `<button type="button" data-sa="vue" data-v="${v}"${D.vue === v ? ' class="is-on"' : ''}>${lib}</button>`;
    const outils = `<div class="filters"><div class="filters__row"><div class="seg" role="group">${vb('fr', 'FR')}${vb('nl', 'NL')}${vb('deux', 'FR et NL côte à côte')}</div>
      <a class="btn btn--sm" href="${pdf('/formation/sop/fiche/' + s.id + '.pdf', 'fr')}" target="_blank">PDF FR</a><a class="btn btn--sm" href="${pdf('/formation/sop/fiche/' + s.id + '.pdf', 'nl')}" target="_blank">PDF NL</a>
      <a class="btn btn--sm" href="${tabletteLien(s.poste)}" target="_blank" rel="noopener">Tablette du poste ↗</a>
      ${tablette() ? '' : `<a class="btn btn--primary" href="#/sop/${esc(s.id)}/editer" style="margin-left:auto">Modifier</a>`}</div></div>`;
    const partie = phasesDe(s) ? `<p><span class="muted">Parties du manuel</span> ${phasesDe(s).map(p => `<a class="chip chip--accent" href="#/manuel/partie/${esc(p)}">${esc(NOM_PARTIE[p] || p)}</a>`).join(' ')} <span class="muted fm-small">les phases du classement</span></p>` : `<p><span class="muted">Partie du manuel</span> <a class="chip chip--accent" href="#/manuel/partie/${esc(s.partie)}">${esc(NOM_PARTIE[s.partie])}</a>${s.partieReglee || tablette() ? '' : ' <span class="muted fm-small">déduite du moment</span>'}</p>
      ${tablette() ? '' : `<div class="seg fm-seg--petit" role="group">${PARTIES.map(p => `<button type="button" data-sa="partie" data-id="${esc(s.id)}" data-v="${p[0]}"${p[0] === s.partie ? ' class="is-on"' : ''}>${esc(p[1])}</button>`).join('')}</div>${s.partieReglee ? ` <button type="button" class="btn btn--sm" data-sa="partie" data-id="${esc(s.id)}" data-v="">↺ Partie déduite</button>` : ''}`}`;
    const infos = `<section class="card"><h2>La SOP</h2><dl class="sop-dl">
      <dt>Code</dt><dd>${esc(s.code || '—')} · ${esc(s.dossier)} ${esc(famNom(s.dossier))}</dd><dt>Poste</dt><dd>${esc(POSTE_NOM[s.poste] || s.poste)}${s.moment ? ' · ' + esc(s.moment) : ''}</dd>
      <dt>Langues</dt><dd>${trad(s)} origine : ${esc(s.langueOrigine)}</dd><dt>Version</dt><dd>v${esc(s.version)} · ${esc(dt(s.majLe))}${s.majPar ? ' · ' + esc(s.majPar) : ''}</dd>
      <dt>Étapes</dt><dd>${s.nbEtapes}${s.nbPhotos ? ' · ' + s.nbPhotos + ' photo' + (s.nbPhotos > 1 ? 's' : '') : ''}${c.duree ? ' · ' + esc(c.duree) : ''}</dd><dt>Compétence</dt><dd>${comp(s)}</dd>
      ${s.statut === 'a_valider' ? '<dt>Statut</dt><dd><span class="chip chip--warn">À valider</span> lisible, sans quiz ni validation</dd>' : ''}</dl><div style="margin-top:12px">${partie}</div></section>`;
    const eq = s.equipe ? `<section class="card"><h2>${esc(s.magasin.nom)} face à cette SOP</h2><p class="muted fm-small">${s.equipe.filter(p => p.statut === 'vu').length} / ${s.equipe.length} vus faire par la responsable</p>
      ${s.equipe.map(p => `<div class="sop-ligne"><div class="sop-ligne__x"><b>${esc(p.nom)}</b><small>${p.valideur ? 'responsable · ' : ''}lit en ${esc(p.langue.toUpperCase())}${p.vuPar ? ' · vu faire par ' + esc(p.vuPar) + ' le ' + esc(dt(p.vuLe)) : ''}</small></div><span class="chip ${ST_CHIP[p.statut]}">${esc(ST_NOM[p.statut])}</span></div>`).join('') || '<p class="muted">Personne de ce poste dans ce magasin.</p>'}
      <p style="margin-top:8px">${choixMagasin()}</p></section>`
      : `<section class="card"><h2>L’équipe</h2><p class="muted">Qui a lu, réussi le quiz, été vu faire : choisir un magasin.</p><p>${choixMagasin()}</p></section>`;
    const crit = (c.points_critiques || []).length ? `<section class="card"><h2>Points critiques</h2><div class="sop-crit"><ul>${c.points_critiques.map(x => `<li>${esc(L(x, l1))}${deux && x.nl ? `<br><span class="muted">${esc(x.nl)}</span>` : ''}</li>`).join('')}</ul></div></section>` : '';
    const quiz = (c.quiz || []).length ? `<section class="card"><h2>Le quiz</h2><p class="muted fm-small">${c.quiz.length} questions · la bonne réponse en couleur</p>${c.quiz.map((q, i) => `<div class="sop-q"><b>${i + 1}. ${esc(L(q.question, l1))}</b>${deux && q.question.nl ? `<span class="muted fm-small">${esc(q.question.nl)}</span>` : ''}
      <ul class="chips">${(q.choix[l1] && q.choix[l1].some(x => x) ? q.choix[l1] : q.choix.fr).map((x, j) => `<li class="chip ${j === q.bonne ? 'chip--accent' : 'chip--soft'}">${esc(x)}</li>`).join('')}</ul></div>`).join('')}</section>` : '';
    const versions = `<section class="card"><h2>Versions</h2>${s.versions.map(v => `<div class="sop-ligne"><span class="sop-lg${v.version === s.version ? ' is-on' : ''}">v${esc(v.version)}</span><div class="sop-ligne__x">${esc(v.note || '—')}<small>${esc(dth(v.publieLe))}${v.publiePar ? ' · ' + esc(v.publiePar) : ''}${v.revalider ? ' · à revalider' : ''}</small></div>
      ${v.version !== s.version ? `<button type="button" class="btn btn--sm" data-sa="archive" data-id="${esc(s.id)}" data-v="${esc(v.version)}">Voir</button>` : ''}</div>`).join('')}</section>`;
    const rel = ((c.corrections || []).length || (c.remarques || []).length) && !tablette() ? `<section class="card"><h2>Relevé à la reprise</h2>
      ${(c.corrections || []).map(x => `<div class="sop-ligne"><span class="chip chip--ok">corrigé</span><div class="sop-ligne__x fm-small">${esc(x)}</div></div>`).join('')}
      ${(c.remarques || []).map(x => `<div class="sop-ligne"><span class="chip chip--warn">à confirmer</span><div class="sop-ligne__x fm-small">${esc(x)}</div></div>`).join('')}</section>` : '';
    const arch = D.archive && D.archive.id === id ? C.bandeau(`Version archivée v${esc(D.archive.version)} (${esc(dth(D.archive.publieLe))}) — <button type="button" class="btn btn--sm" data-sa="archive" data-id="${esc(id)}" data-v="">Revenir à la version en vigueur</button>`, 'warn') : '';
    const corps = fil + `<p class="muted">${trad(s)} <span class="sop-lg is-on">v${esc(D.archive && D.archive.id === id ? D.archive.version : s.version)}</span> ${esc(L(s.objectif))}</p>` + arch + outils +
      `<div class="sop-deux"><section class="card">${etapes || '<p class="muted">Aucune étape.</p>'}</section><div>${infos}${carteClassement(s)}${eq}${crit}${quiz}${versions}${rel}</div></div>`;
    return { titre: s.titre.fr, kicker: K + ' · ' + (s.code || ''), corps, large: true };
  }

  /* ═══ Compétences ═══ */
  function ecranCompetences() {
    const X = D.comp;
    if (!X) { return { titre: 'Compétences et SOP', kicker: K, corps: attente('Lecture des compétences…') }; }
    const comps = X.competences;
    let h = X.panel.indispo ? C.bandeau('Compétences du panel : ' + esc(X.panel.motif || 'indisponibles') + (X.panel.liste.length ? ' — ' + X.panel.liste.length + ' gardées de la dernière lecture.' : '') + ' Les SOP se rattachent dans Rédaction et versions.', 'warn')
      : `<p class="muted fm-small">${X.panel.liste.length} compétences lues au panel (${esc(X.panel.source)}) · <button type="button" class="btn btn--sm" data-sa="relirePanel">Relire le panel</button></p>`;
    if (X.sansCompetence.length) { h += C.bandeau(`${X.sansCompetence.length} SOP sans compétence du panel — <a href="#/sopred">les rattacher</a>.`, 'warn'); }
    h += `<p class="muted">Une compétence est acquise quand la responsable du magasin a vu faire chacune de ses SOP ; elle est alors cochée au panel pour la personne.</p>`;
    const tete = `<tr><th></th>${comps.map(c => `<th>${esc(c.nom)}<br><span class="muted fm-small">${c.sops.length} SOP</span></th>`).join('')}<th>Acquises</th></tr>`;
    h += `<section class="card table-card"><h2>Le réseau</h2><p class="muted fm-small">part des personnes concernées qui ont acquis chaque compétence</p>${comps.length ? `<div class="table-scroll"><table class="sop-mat"><thead>${tete}</thead><tbody>${X.magasins.map(m => `<tr><td><b>${esc(m.nom)}</b><br><span class="muted fm-small">${m.equipe ? m.equipe + ' personnes · ' + m.aVoirFaire + ' à voir faire' : 'équipe pas encore lue'}</span></td>${comps.map(c => `<td>${heat(m.parCompetence[c.cle])}</td>`).join('')}<td><b>${m.taux == null ? '—' : m.taux + ' %'}</b></td></tr>`).join('')}</tbody></table></div>`
      : '<p class="muted">Aucune SOP n’est encore rattachée à une compétence du panel.</p>'}</section>`;
    h += `<div class="filters"><div class="filters__row">${choixMagasin()}</div></div>`;
    if (X.magasin) {
      const M = X.magasin;
      const cell = x => !x || x.statut === 'na' ? '' : `<span class="sop-cp ${x.statut}">${x.statut === 'acquise' ? '✓' : x.statut === 'a_revoir' ? '! à revoir' : x.vues + ' / ' + x.total}</span>`;
      h += `<section class="card table-card"><h2>${esc(M.nom)} · qui a quoi</h2>${comps.length ? `<div class="table-scroll"><table class="sop-mat"><thead>${tete}</thead><tbody>${M.equipe.map(p => { const e = Object.values(p.competences).filter(x => x.statut !== 'na');
        return `<tr><td><b>${esc(p.nom)}</b><br><span class="muted fm-small">${p.valideur ? 'responsable · ' : ''}${esc(p.postes.map(x => POSTE_NOM[x] || x).join(', '))} · ${esc(p.langue.toUpperCase())}</span></td>${comps.map(c => `<td>${cell(p.competences[c.cle])}</td>`).join('')}<td><b>${e.length ? e.filter(x => x.statut === 'acquise').length + ' / ' + e.length : '—'}</b></td></tr>`; }).join('')}</tbody></table></div>` : ''}</section>`;
      h += `<div class="sop-deux"><div><section class="card"><h2>À voir faire</h2><p class="muted fm-small">quiz réussi · la responsable valide sur la tablette, avec son PIN</p>
        ${M.aVoirFaire.map(a => `<div class="sop-ligne"><span class="sop-ligne__d">${esc(dt(a.quizLe))}</span><div class="sop-ligne__x"><b>${esc(a.nom)}</b> · <a href="#/sop/${esc(a.sop)}">${esc(a.titre)}</a></div><span class="chip chip--warn">à voir faire</span></div>`).join('') || '<p class="muted">Rien en attente.</p>'}</section>
        <section class="card"><h2>L’équipe</h2><p class="muted fm-small">noms et rôles du panel · postes, langue et responsable réglables ici</p>
        ${M.equipe.map(p => `<div class="sop-ligne" style="flex-wrap:wrap"><div class="sop-ligne__x" style="min-width:150px"><b>${esc(p.nom)}</b><small>${esc(p.role || '—')}</small></div>
          <span class="sop-puces">${POSTES.map(x => `<label class="${p.postes.includes(x) ? 'is-on' : ''}"><input type="checkbox" data-sch="poste" data-id="${p.id}" value="${x}" ${p.postes.includes(x) ? 'checked' : ''}>${POSTE_NOM[x]}</label>`).join('')}</span>
          <span class="sop-puces"><label class="${p.valideur ? 'is-on' : ''}"><input type="checkbox" data-sch="valideur" data-id="${p.id}" ${p.valideur ? 'checked' : ''}>valide « vu faire »</label></span>
          <select class="fm-select" data-sch="langue" data-id="${p.id}" style="width:auto"><option value="fr" ${p.langue === 'fr' ? 'selected' : ''}>FR</option><option value="nl" ${p.langue === 'nl' ? 'selected' : ''}>NL</option></select></div>`).join('') || '<p class="muted">Équipe introuvable au panel pour ce magasin.</p>'}</section></div>
        <section class="card"><h2>Le fil · 14 jours</h2>${(D.fil || []).map(f => `<div class="sop-ligne"><span class="sop-ligne__d">${esc(dt(f.le))}</span><div class="sop-ligne__x"><b>${esc(f.nom)}</b> · ${esc(f.titre)}<small>${f.type === 'vu' ? 'vu faire par ' + esc(f.par || '') : f.type === 'quiz' ? 'quiz réussi' : 'lue en entier'}</small></div></div>`).join('') || '<p class="muted">Rien ces 14 derniers jours.</p>'}</section></div>`;
    }
    return { titre: 'Compétences et SOP', kicker: K, corps: h, large: true };
  }

  /* ═══ Rédaction et versions ═══ */
  function ecranRedaction() {
    if (!D.liste) { return { titre: 'Rédaction et versions', kicker: K, corps: erreurOuAttente() }; }
    const liste = (D.comp && D.comp.panel.liste) || [];
    const opts = s => `<option value="">— aucune —</option>${liste.map(c => `<option value="${esc(c.id || '')}|${esc(c.nom)}" ${s.competence && s.competence.nom === c.nom ? 'selected' : ''}>${esc((c.categorie ? c.categorie + ' › ' : '') + c.nom)}</option>`).join('')}
      ${s.competence && !liste.some(c => c.nom === s.competence.nom) ? `<option value="${esc(s.competence.id || '')}|${esc(s.competence.nom)}" selected>${esc(s.competence.nom)} (hors liste)</option>` : ''}<option value="*">Autre…</option>`;
    const tr = (s, l) => { const t = s.trad[l]; return t === 'origine' ? '<span class="chip chip--mute">origine</span>' : `<button type="button" class="filter fm-small${t === 'relue' ? ' is-on' : ''}" data-sa="trad" data-id="${esc(s.id)}" data-l="${l}" data-v="${t === 'relue' ? 'a_relire' : 'relue'}">${t === 'relue' ? '✓ relue' : 'à relire → relue'}</button>`; };
    let h = '';
    if (D.comp && D.comp.panel.indispo) { h += C.bandeau('Compétences du panel : ' + esc(D.comp.panel.motif || '') + '. « Autre… » permet d’écrire le nom exact d’une compétence du panel en attendant.', 'warn'); }
    const N = D.nouv;
    h += `<div class="filters"><div class="filters__row"><input class="fm-search" type="search" placeholder="Rechercher…" data-input="sopq" value="${esc(C.S.f.sopq || '')}"><button type="button" class="btn${N ? '' : ' btn--primary'}" data-sa="nouv" style="margin-left:auto">Nouvelle SOP</button></div></div>`;
    if (N) {
      h += `<section class="card"><h2>Nouvelle SOP</h2><div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px">
        <input class="sop-in" placeholder="Code (ex. SOP-CUI-XYZ-01)" data-sin="nouv.code" value="${esc(N.code)}">
        <select class="fm-select" data-sch="nouv.dossier">${D.dossiers.map(d => `<option value="${d.id}" ${N.dossier === d.id ? 'selected' : ''}>${d.id} · ${esc(d.nom.fr)}</option>`).join('')}</select>
        <select class="fm-select" data-sch="nouv.type">${['SOP', 'Checklist', 'Mémo', 'Fiche'].map(x => `<option ${N.type === x ? 'selected' : ''}>${x}</option>`).join('')}</select>
        <select class="fm-select" data-sch="nouv.poste">${POSTES.map(x => `<option value="${x}" ${N.poste === x ? 'selected' : ''}>${POSTE_NOM[x]}</option>`).join('')}</select>
        <input class="sop-in" style="grid-column:span 2" placeholder="Titre FR" data-sin="nouv.fr" value="${esc(N.fr)}"><input class="sop-in" style="grid-column:span 2" placeholder="Titel NL" data-sin="nouv.nl" value="${esc(N.nl)}"></div>
        <p style="margin-top:10px"><button type="button" class="btn btn--primary" data-sa="creer">Créer (à valider)</button> <button type="button" class="btn btn--ghost" data-sa="annulerNouv">Annuler</button></p></section>`;
    }
    h += `<section class="card table-card"><div class="table-scroll"><table><thead><tr><th>Famille</th><th>SOP</th><th>FR</th><th>NL</th><th>Version</th><th class="num">Étapes</th><th title="la compétence du panel rattachée à la SOP : gardée en repli quand aucun code C-… n’est relié au panel">Compétence du panel (repli)</th><th>Partie</th><th>Statut</th><th></th></tr></thead><tbody>
      ${filtrer(D.liste).map(s => `<tr><td class="fm-small">${esc(s.dossier)} · ${esc(famNom(s.dossier))}</td><td><a href="#/sop/${esc(s.id)}"><b>${esc(s.titre.fr)}</b></a><br><span class="muted fm-small">${esc(s.code || '')} · ${esc(s.titre.nl)}</span></td>
        <td>${tr(s, 'fr')}</td><td>${tr(s, 'nl')}</td><td><span class="sop-lg is-on">v${esc(s.version)}</span><br><span class="muted fm-small">${esc(dt(s.majLe))}</span></td><td class="num">${s.nbEtapes}</td>
        <td><select class="fm-select" style="max-width:240px" data-sch="comp" data-id="${esc(s.id)}">${opts(s)}</select></td>
        <td class="fm-small">${esc(NOM_PARTIE[s.partie])}${s.partieReglee ? '' : '<br><span class="muted">déduite</span>'}</td>
        <td><select class="fm-select" style="width:auto" data-sch="statut" data-id="${esc(s.id)}"><option value="publiee" ${s.statut === 'publiee' ? 'selected' : ''}>publiée</option><option value="a_valider" ${s.statut === 'a_valider' ? 'selected' : ''}>à valider</option><option value="brouillon" ${s.statut === 'brouillon' ? 'selected' : ''}>brouillon</option></select></td>
        <td><a class="btn btn--sm" href="#/sop/${esc(s.id)}/editer">Modifier</a></td></tr>`).join('')}</tbody></table></div></section>
      <p class="muted fm-small">Reprise du Drive du 05/11/2026 (44 fichiers → 25 SOP) : corrections et points à confirmer dans <code>data/formation/REPRISE.md</code> et sur chaque fiche (« Relevé à la reprise »).</p>`;
    return { titre: 'Rédaction et versions', kicker: K, corps: h, large: true };
  }

  /* ═══ Éditeur ═══ */
  const get = (o, p) => p.split('.').reduce((a, k) => a == null ? a : a[k], o);
  function setP(o, p, v) { const k = p.split('.'); const last = k.pop(); const t = k.reduce((a, x) => a[x], o); t[last] = v; }
  const champ = (p, ph, area) => { const v = get(D.ed, p) || ''; return area ? `<textarea class="sop-in${v ? '' : ' is-vide'}" data-sin="ed.${p}" placeholder="${esc(ph)}">${esc(v)}</textarea>` : `<input class="sop-in${v ? '' : ' is-vide'}" data-sin="ed.${p}" placeholder="${esc(ph)}" value="${esc(v)}">`; };
  function ecranEditer(id) {
    const fil = C.filAriane([['Standards', '#/sop'], [D.edMeta ? D.edMeta.titre.fr : id, '#/sop/' + id], ['Modifier', null]]);
    if (tablette()) { return { titre: 'Modifier', kicker: K, corps: fil + '<p class="muted">La rédaction ne se fait pas en mode tablette.</p>' }; }
    if (!D.ed || !D.edMeta || D.edMeta.id !== id) { return { titre: 'Modifier', kicker: K, corps: fil + attente('Lecture de la SOP…') }; }
    const s = D.edMeta, c = D.ed;
    const bi = (p, lib, area) => `<div style="display:grid;grid-template-columns:120px 1fr 1fr;gap:8px;align-items:start;margin-bottom:8px"><span class="muted" style="padding-top:6px">${lib}</span>${champ(p + '.fr', 'FR', area)}${champ(p + '.nl', 'NL', area)}</div>`;
    const secs = c.sections.map((sec, si) => `<div class="sop-ed-sec"><div class="sop-ed-sec__h">${champ('sections.' + si + '.titre.fr', 'Titre de la section (FR)')}${champ('sections.' + si + '.titre.nl', 'Titel van de sectie (NL)')}
      <span><button type="button" class="btn btn--icon" data-sa="secMove" data-i="${si}" data-d="-1" aria-label="Monter la section">↑</button><button type="button" class="btn btn--icon" data-sa="secMove" data-i="${si}" data-d="1" aria-label="Descendre la section">↓</button><button type="button" class="btn btn--icon btn--danger" data-sa="secSuppr" data-i="${si}" aria-label="Retirer la section">✕</button></span></div>
      ${sec.etapes.map((e, ei) => { const p = 'sections.' + si + '.etapes.' + ei; if (!e.titre) { e.titre = { fr: '', nl: '' }; }
        return `<div class="sop-ed-et"><div class="sop-ed-et__c"><input class="sop-in" data-sin="ed.${p}.numero" value="${esc(e.numero)}" style="text-align:center"><label class="fm-small"><input type="checkbox" data-sch="ed.${p}.critique" ${e.critique ? 'checked' : ''}> critique</label></div>
          <div class="sop-ed-et__c">${champ(p + '.titre.fr', 'Titre (FR, facultatif)')}${champ(p + '.texte.fr', 'Texte (FR)', true)}</div>
          <div class="sop-ed-et__c">${champ(p + '.titre.nl', 'Titel (NL, facultatief)')}${champ(p + '.texte.nl', 'Tekst (NL)', true)}</div>
          <div class="sop-ed-et__c"><input class="sop-in" data-sin="ed.${p}.photo" placeholder="photo (fichier)" value="${esc(e.photo || '')}">${e.photo ? `<img src="sop/photos/${esc(e.photo)}" style="width:100%;border-radius:6px" alt="">` : ''}
            <span><button type="button" class="btn btn--icon" data-sa="etMove" data-i="${si}.${ei}" data-d="-1" aria-label="Monter l’étape">↑</button><button type="button" class="btn btn--icon" data-sa="etMove" data-i="${si}.${ei}" data-d="1" aria-label="Descendre l’étape">↓</button><button type="button" class="btn btn--icon btn--danger" data-sa="etSuppr" data-i="${si}.${ei}" aria-label="Retirer l’étape">✕</button></span></div></div>`; }).join('')}
      <button type="button" class="btn btn--sm" data-sa="etAjout" data-i="${si}">+ étape</button></div>`).join('');
    const crit = c.points_critiques.map((x, i) => `<div style="display:grid;grid-template-columns:1fr 1fr auto;gap:8px;margin-bottom:6px">${champ('points_critiques.' + i + '.fr', 'FR')}${champ('points_critiques.' + i + '.nl', 'NL')}<button type="button" class="btn btn--icon btn--danger" data-sa="critSuppr" data-i="${i}" aria-label="Retirer le point critique">✕</button></div>`).join('');
    const quiz = c.quiz.map((q, i) => `<div class="sop-q"><div style="display:grid;grid-template-columns:1fr 1fr auto;gap:8px">${champ('quiz.' + i + '.question.fr', 'Question (FR)')}${champ('quiz.' + i + '.question.nl', 'Vraag (NL)')}<button type="button" class="btn btn--icon btn--danger" data-sa="quizSuppr" data-i="${i}" aria-label="Retirer la question">✕</button></div>
      ${q.choix.fr.map((x, j) => `<div style="display:grid;grid-template-columns:auto 1fr 1fr;gap:8px;margin-top:6px;align-items:center"><label class="fm-small"><input type="radio" name="sopbonne${i}" data-sch="bonne" data-i="${i}" value="${j}" ${q.bonne === j ? 'checked' : ''}> bonne</label>${champ('quiz.' + i + '.choix.fr.' + j, 'Choix ' + (j + 1) + ' (FR)')}${champ('quiz.' + i + '.choix.nl.' + j, 'Keuze ' + (j + 1) + ' (NL)')}</div>`).join('')}</div>`).join('');
    const pv = (() => { const p = s.version.split('.'); if (p.length === 1) { return p[0] + '.1'; } p[p.length - 1] = String(+p[p.length - 1] + 1); return p.join('.'); })();
    const trSel = l => `<select class="fm-select" style="width:auto" data-sch="edtrad" data-l="${l}">${['origine', 'relue', 'a_relire'].map(t => `<option value="${t}" ${D.edMeta.tradApres[l] === t ? 'selected' : ''}>${l.toUpperCase()} ${TRAD_NOM[t]}</option>`).join('')}</select>`;
    const corps = fil + `<p class="muted">${esc(s.code || '')} · v${esc(s.version)} en vigueur · chaque texte en FR et en NL (en orange : vide)</p>
      <section class="card"><h2>En-tête</h2>${bi('titre', 'Titre')}${bi('objectif', 'Objectif', true)}${bi('pour', 'Pour')}<div style="display:grid;grid-template-columns:120px 1fr;gap:8px"><span class="muted" style="padding-top:6px">Durée</span><input class="sop-in" data-sin="ed.duree" value="${esc(c.duree || '')}"></div></section>
      <section class="card"><h2>Les étapes · ${c.sections.reduce((a, x) => a + x.etapes.length, 0)}</h2>${secs}<button type="button" class="btn" data-sa="secAjout">+ section</button></section>
      <section class="card"><h2>Points critiques</h2>${crit}<button type="button" class="btn btn--sm" data-sa="critAjout">+ point critique</button></section>
      <section class="card"><h2>Quiz</h2><p class="muted fm-small">3 questions conseillées · une bonne réponse par question</p>${quiz}<button type="button" class="btn btn--sm" data-sa="quizAjout">+ question</button></section>
      ${D.edErr ? `<p class="error">${esc(D.edErr)}</p>` : ''}
      <div class="sop-ed-barre"><input class="sop-in" style="max-width:340px" placeholder="Ce qui change (note de version)" data-sin="edMeta.note" value="${esc(D.edMeta.note || '')}">
        <label class="fm-small"><input type="radio" name="sopreval" data-sch="reval" value="0" ${!D.edMeta.revalider ? 'checked' : ''}> Précision : ceux qui l’ont validée restent validés</label>
        <label class="fm-small"><input type="radio" name="sopreval" data-sch="reval" value="1" ${D.edMeta.revalider ? 'checked' : ''}> Le geste change : ils repassent « à revoir »</label>
        ${trSel('fr')}${trSel('nl')}<span style="flex:1"></span><a class="btn btn--ghost" href="#/sop/${esc(s.id)}">Annuler</a><button type="button" class="btn btn--primary" data-sa="publier" ${D.occupe ? 'disabled' : ''}>Publier la v${esc(pv)}</button></div>`;
    return { titre: 'Modifier · ' + s.titre.fr, kicker: K, corps, large: true };
  }

  /* ═══ Le Manuel opératoire : les SOP de chaque partie ═══ */
  /** Les SOP d'une partie du manuel : ses phases du Manuel du réseau quand elles sont posées, sinon la partie réglée ou déduite. */
  const phasesDe = s => s.classement && s.classement.phases && s.classement.phases.length ? s.classement.phases : null;
  function dePartie(cle) { return D.liste ? D.liste.filter(s => s.statut !== 'brouillon' && (phasesDe(s) ? phasesDe(s).includes(cle) : s.partie === cle)) : null; }
  function sectionPartie(cle) {
    const l = dePartie(cle);
    let h = `<div class="section-label">Standards (SOP) · ${l ? C.n(l.length) : '…'}</div>`;
    if (!l) { return h + erreurOuAttente(); }
    if (!l.length) { return h + `<p class="muted" style="margin-top:8px">Aucune SOP dans cette partie.</p>`; }
    h += `<p class="muted fm-small"><a href="${tabletteLien('')}" target="_blank" rel="noopener">Lire pas à pas sur la tablette du poste ↗</a> · nom, PIN, quiz, « vu faire » par la responsable</p>`;
    let fam = null, ouvert = false;
    l.forEach(s => {
      if (s.dossier !== fam) { if (ouvert) { h += '</ul>'; } fam = s.dossier; ouvert = true; h += `<div class="fm-part-cat">${esc(famNom(s.dossier))}</div><ul class="type-grid">`; }
      h += `<li><a class="choice-card" href="#/sop/${esc(s.id)}"><strong><span class="fm-code">${esc(s.code || '')}</span> ${esc(s.titre.fr)}</strong><span class="muted">${esc(s.titre.nl)} · ${s.nbEtapes} étapes${s.statut === 'a_valider' ? ' · à valider' : ''}</span></a></li>`;
    });
    return h + (ouvert ? '</ul>' : '');
  }
  function nbPartie(cle) { const l = dePartie(cle); return l ? l.length : null; }

  /* ═══ Manuel du réseau : le référentiel commun (src/referentiel.php, fusion du 06/11/2026) ═══
   *
   * Une seule base, trois lectures : le Classeur (domaines D1–D8 du v25 et leurs sous-catégories, la chaîne
   * standard → formation → compétence → contrôle, les trous), Par phase et Par poste (calculées, rien n'y est saisi).
   * La fiche d'une SOP gagne son bloc « Classement » ; l'écran Compétences tranche la correspondance C-… ↔ panel.
   */
  const K = 'Manuel du réseau';
  const PHASE_NOM = { preouverture: 'Pré-ouverture', ouverture: 'Ouverture', operationnel: 'Opérationnel', formation: 'Formation continue' };
  const PHASES = Object.keys(PHASE_NOM);
  const ST_STD = { a_valider: 'À valider', brouillon: 'Brouillon' };
  const ETAT_COR = { valide: 'Validée', certain: 'Certaine', propose: 'À trancher', aucun: 'Aucune' };
  const CL_LISTES = ['competences', 'formations', 'checklists'];
  const CL_NOM = { competences: 'Compétences', formations: 'Formations', checklists: 'Checklists de contrôle' };

  /** L'index du référentiel lu (domaines, sous-catégories, standards, formations, compétences), refait à chaque lecture. */
  function rx() {
    const R = D.ref;
    if (!R) { return null; }
    if (D._rx && D._rx.R === R) { return D._rx; }
    const x = { R, dom: {}, sc: {}, std: {}, parSc: {}, fo: {}, comp: {}, poste: {}, sansStd: new Set(R.manques.competencesSansStandard), nd: new Set(R.competencesNonDecrites || []) };
    R.domaines.forEach(d => d.souscats.forEach(s => { x.sc[s.cle] = Object.assign({ domaine: d.code }, s); x.parSc[s.cle] = []; }));
    R.domaines.forEach(d => { x.dom[d.code] = d; });
    R.standards.forEach(s => { x.std[s.id] = s; if (s.souscat && x.parSc[s.souscat]) { x.parSc[s.souscat].push(s); } });
    R.formations.forEach(f => { x.fo[f.code] = f; });
    R.competences.forEach(c => { x.comp[c.code] = c; });
    R.postes.forEach(p => { x.poste[p.code] = p; });
    D._rx = x;
    return x;
  }
  const uniq = l => [...new Set(l)];
  const plus = (...ls) => uniq([].concat(...ls));
  const rcode = (c, titre, cls) => `<span class="rf-code${cls ? ' ' + cls : ''}"${titre ? ` title="${esc(titre)}"` : ''}>${esc(c)}</span>`;
  const rfFo = (X, l) => l.map(c => rcode(c, (X.fo[c] || {}).fr)).join(' ');
  const rfComp = (X, l) => l.map(c => X.nd.has(c) ? rcode(c, 'Citée par le v25, absente de sa feuille des compétences', 'is-nd')
    : rcode(c, X.comp[c] ? X.comp[c].fr + (X.sansStd.has(c) ? ' — aucun standard ne la prouve encore' : '') : '', X.sansStd.has(c) ? 'is-trou' : '')).join(' ');
  const rfChk = l => l.map(c => rcode(c)).join(' ');
  const trou = t => `<span class="tag-warn">${esc(t)}</span>`;
  const rfPhases = l => l.map(p => `<span class="chip chip--neutral">${esc(PHASE_NOM[p] || p)}</span>`).join(' ');
  const rfStatut = s => ST_STD[s.statut] ? (s.statut === 'brouillon' ? `<span class="chip chip--neutral">${ST_STD[s.statut]}</span>` : trou(ST_STD[s.statut])) : '';
  const kpi = (v, l, note) => `<div class="kpi"><div class="kpi__value">${esc(v)}</div><div class="kpi__label">${esc(l)}</div>${note ? `<div class="kpi__note">${esc(note)}</div>` : ''}</div>`;
  const lienStd = s => `<a class="rf-std" href="#/sop/${esc(s.id)}">${s.code ? rcode(s.code) + ' ' : ''}<span>${esc(s.fr)}</span></a>`;
  function refAttente(titre) {
    return { titre, kicker: K, corps: D.refErr ? `<p class="error">${esc(D.refErr)}</p>` : attente('Lecture du référentiel…'), large: true };
  }
  /** Ce qu'un domaine réunit : ses sous-catégories et les standards qui y sont rangés. */
  function contenuDomaine(X, code) {
    const d = X.dom[code], stds = [].concat(...d.souscats.map(s => X.parSc[s.cle] || []));
    return { d, stds,
      fo: plus(...d.souscats.map(s => s.formations), ...stds.map(s => s.formations)),
      comp: plus(...d.souscats.map(s => s.competences), ...stds.map(s => s.competences)),
      chk: plus(...d.souscats.map(s => s.checklists), ...stds.map(s => s.checklists)) };
  }

  /* ── Le Classeur ── */
  function ecranClasseur() {
    const X = rx();
    if (!X) { return refAttente('Classeur'); }
    const R = X.R, a0 = C.S.route.a[0];
    const dom = X.dom[a0] ? a0 : (X.dom[D.domSel] ? D.domSel : 'D5');
    D.domSel = dom;
    const scVides = Object.keys(X.parSc).filter(k => !X.parSc[k].length).length;
    let h = `<div class="toolbar rf-barre"><p class="muted">Les 8 domaines du référentiel v25. Dans chaque sous-catégorie, la même chaîne : standard → formation → compétence → contrôle.</p>
      <span class="rf-sp"></span><button type="button" class="btn" data-sa="rfImporter" ${D.occupe ? 'disabled' : ''} title="Les procédures du Manuel opératoire du CRM deviennent des standards en brouillon (sauf AC-01, AC-02, AC-03)">Reprendre le Manuel du CRM</button></div>`;
    if (D.imp) {
      const I = D.imp;
      h += C.bandeau(!I.disponible ? esc(I.message) : `Reprise du Manuel du CRM : ${I.reprises.length} procédure(s) reprise(s) en brouillon, ${I.dejaLa.length} déjà là, ${I.ignorees.length} ignorée(s)`
        + (I.photosNonReprises ? `, ${I.photosNonReprises} photo(s) restée(s) dans le CRM` : '') + '.' + (I.ignorees.length ? ' Ignorées : ' + I.ignorees.map(x => esc(x.code + ' (' + x.raison + ')')).join(' · ') : ''), I.disponible ? '' : 'warn');
    }
    h += `<section class="card rf-kpis"><div class="kpi-row">
      ${kpi(`${R.standards.length - R.manques.standardsSansSouscat.length} / ${R.standards.length}`, 'standards classés', R.manques.standardsSansSouscat.length ? R.manques.standardsSansSouscat.length + ' à classer' : 'tous rangés')}
      ${kpi(`${R.competences.length - R.manques.competencesSansStandard.length} / ${R.competences.length}`, 'compétences prouvées', R.manques.competencesSansStandard.length + ' sans standard')}
      ${kpi(R.manques.standardsSansCompetence.length, 'standards sans compétence', 'rien à valider au « vu faire »')}
      ${kpi(`${Object.keys(X.sc).length - scVides} / ${Object.keys(X.sc).length}`, 'sous-catégories couvertes', scVides + ' sans standard')}</div></section>`;
    h += '<div class="rf-doms">' + R.domaines.map(d => {
      const c = contenuDomaine(X, d.code);
      const n = (v, l) => `<span class="rf-n"><span class="rf-n__v">${v}</span><span class="rf-n__l">${l}</span></span>`;
      return `<a class="rf-dom${d.code === dom ? ' is-on' : ''}" href="#/rfclasseur/${esc(d.code)}"><span class="rf-dom__k">${esc(d.code)} · ${d.souscats.length} sous-catégories</span>
        <span class="rf-dom__t">${esc(d.fr)}</span><span class="rf-dom__nl">${esc(d.nl || '')}</span>
        <span class="rf-dom__ns">${n(c.stds.length, 'standards')}${n(c.fo.length, 'FO')}${n(c.comp.length, 'comp.')}${n(c.chk.length, 'chk')}</span></a>`;
    }).join('') + '</div>';
    const c = contenuDomaine(X, dom);
    h += `<section class="card table-card"><div class="rf-tete"><h2>${esc(dom)} · ${esc(c.d.fr)}</h2><span class="muted fm-small">${c.d.souscats.length} sous-catégories · ${c.stds.length} standards · ${c.fo.length} formations · ${c.comp.length} compétences · ${c.chk.length} checklists</span></div>
      <div class="table-scroll"><table class="rf-table"><thead><tr><th style="width:18%">Sous-catégorie</th><th style="width:27%">Standards</th><th>Formations</th><th>Compétences</th><th>Checklists</th><th>Phases</th></tr></thead><tbody>
      ${c.d.souscats.map(s => ligneSouscat(X, s)).join('')}</tbody></table></div>
      <p class="muted fm-small rf-legende">${rcode('C-…', '', 'is-trou')} compétence qu’aucun standard ne prouve encore · ${rcode('C-…', '', 'is-nd')} code cité par le v25 mais absent de sa feuille des compétences</p></section>`;
    h += chaineDomaine(X, c);
    const aClasser = R.manques.standardsSansSouscat.map(id => X.std[id]).filter(Boolean);
    if (aClasser.length) {
      h += `<section class="card table-card"><h2>Standards à classer · ${aClasser.length}</h2><p class="muted fm-small">sans sous-catégorie : ouvrir la fiche, bloc « Classement »</p>
        <ul class="rf-liste">${aClasser.map(s => `<li>${lienStd(s)} ${rfStatut(s)}</li>`).join('')}</ul></section>`;
    }
    return { titre: 'Classeur', kicker: K, corps: h, large: true };
  }
  function ligneSouscat(X, s) {
    const stds = X.parSc[s.cle] || [];
    const fo = plus(s.formations, ...stds.map(x => x.formations)), comp = plus(s.competences, ...stds.map(x => x.competences));
    const chk = plus(s.checklists, ...stds.map(x => x.checklists)), ph = plus(...stds.map(x => x.phases)).sort((a, b) => PHASES.indexOf(a) - PHASES.indexOf(b));
    const E = D.scEd && D.scEd.cle === s.cle ? D.scEd : null;
    const tete = E ? `<div class="rf-scform"><label class="rf-lab">Libellé NL<input class="rf-in" data-sin="scEd.nl" value="${esc(E.nl)}"></label>
        <label class="rf-lab">Description<textarea class="rf-in" rows="3" data-sin="scEd.description">${esc(E.description)}</textarea></label>
        ${E.err ? `<p class="error">${esc(E.err)}</p>` : ''}<span class="rf-actions"><button type="button" class="btn btn--sm btn--primary" data-sa="scSave" ${D.occupe ? 'disabled' : ''}>Enregistrer</button><button type="button" class="btn btn--sm btn--ghost" data-sa="scAnnuler">Annuler</button></span></div>`
      : `<span class="rf-sc">${esc(s.cle)} · ${esc(s.fr)}</span><span class="sub">${esc(s.nl || '—')}${s.nlRelu ? '' : ' · NL à relire'}</span>
        ${s.description ? `<span class="sub" title="${esc(s.description)}">${esc(s.description.length > 90 ? s.description.slice(0, 89) + '…' : s.description)}</span>` : ''}
        ${tablette() ? '' : `<button type="button" class="linklike" data-sa="scEd" data-v="${esc(s.cle)}">Modifier</button>`}`;
    return `<tr><td>${tete}</td><td>${stds.length ? stds.map(x => `<div class="rf-ligne">${lienStd(x)} ${rfStatut(x)}</div>`).join('') : trou('aucun standard')}</td>
      <td>${rfFo(X, fo) || '<span class="muted">—</span>'}</td><td>${rfComp(X, comp) || '<span class="muted">—</span>'}</td><td>${rfChk(chk) || '<span class="muted">—</span>'}</td>
      <td>${rfPhases(ph) || '<span class="muted">—</span>'}</td></tr>`;
  }
  function chaineDomaine(X, c) {
    if (!c.stds.length) { return `<section class="card"><h2>La chaîne de chaque standard</h2><p class="muted">Aucun standard rangé dans ce domaine.</p></section>`; }
    const panel = code => { const k = X.comp[code]; if (!k) { return ''; } const p = k.panel;
      return p.etat === 'valide' || p.etat === 'certain' ? `<span class="sub">panel : ${esc(p.nom)}${p.etat === 'certain' ? ' (proposée)' : ''}</span>` : `<span class="sub">${p.etat === 'propose' ? 'panel : à trancher' : 'panel : aucune'}</span>`; };
    return `<section class="card table-card"><h2>La chaîne de chaque standard · ${c.stds.length}</h2><div class="table-scroll"><table class="rf-table rf-chaine"><thead><tr><th style="width:34%">Standard</th><th class="rf-fl"></th><th>Formation</th><th class="rf-fl"></th><th style="width:26%">Compétence</th><th class="rf-fl"></th><th>Contrôle</th></tr></thead><tbody>
      ${c.stds.map(s => `<tr><td>${lienStd(s)} ${rfStatut(s)}<span class="sub">${esc(X.sc[s.souscat] ? X.sc[s.souscat].fr : '')} · ${s.postes.length ? esc(s.postes.join(', ')) : 'postes à poser'}</span></td>
        <td class="rf-fl">→</td><td>${s.formations.length ? rfFo(X, s.formations) : trou('à relier')}</td>
        <td class="rf-fl">→</td><td>${s.competences.length ? s.competences.map(k => `<div>${rfComp(X, [k])}${panel(k)}</div>`).join('') : trou('à relier')}</td>
        <td class="rf-fl">→</td><td>${s.checklists.length ? rfChk(s.checklists) : trou('à relier')}</td></tr>`).join('')}</tbody></table></div></section>`;
  }

  /* ── Par phase : calculée depuis les étiquettes des standards ── */
  function ecranParPhase() {
    const X = rx();
    if (!X) { return refAttente('Par phase'); }
    const R = X.R, a0 = C.S.route.a[0], ph = PHASE_NOM[a0] ? a0 : 'preouverture';
    const parPh = p => R.standards.filter(s => s.phases.includes(p));
    let h = `<div class="page-tabs" role="tablist">${PHASES.map(p => `<a class="page-tabs__tab${p === ph ? ' is-on' : ''}" href="#/rfphase/${p}" role="tab">${esc(PHASE_NOM[p])} · ${parPh(p).length}</a>`).join('')}</div>`;
    const stds = parPh(ph);
    const foStd = plus(...stds.map(s => s.formations)), foPart = R.formations.filter(f => f.partie === ph).map(f => f.code);
    const fo = plus(foStd, foPart).sort((a, b) => a.localeCompare(b, 'fr', { numeric: true }));
    const comp = plus(...stds.map(s => s.competences));
    const sansPh = R.standards.filter(s => !s.phases.length);
    if (sansPh.length) { h += C.bandeau(`${sansPh.length} standard(s) sans phase, absents de cette lecture : ${sansPh.map(s => esc(s.code || s.id)).join(', ')} — les classer depuis leur fiche.`, 'warn'); }
    let app = '';
    R.domaines.forEach(d => {
      const l = stds.filter(s => X.sc[s.souscat] && X.sc[s.souscat].domaine === d.code);
      if (!l.length) { return; }
      app += `<div class="section-label">${esc(d.code)} · ${esc(d.fr)} · ${l.length}</div>` + l.map(s => `<div class="rf-ligne">${lienStd(s)} ${rfStatut(s)}<span class="sub">${esc(X.sc[s.souscat].fr)} · ${esc(s.postes.join(', ') || 'postes à poser')}</span></div>`).join('');
    });
    const horsSc = stds.filter(s => !X.sc[s.souscat]);
    if (horsSc.length) { app += `<div class="section-label">Sans sous-catégorie · ${horsSc.length}</div>` + horsSc.map(s => `<div class="rf-ligne">${lienStd(s)}</div>`).join(''); }
    const appr = fo.map(c => { const f = X.fo[c] || {}; const n = stds.filter(s => s.formations.includes(c)).length;
      return `<div class="rf-ligne">${rcode(c)} <span>${esc(f.fr || '')}</span><span class="sub">${esc([f.controleur, f.frequence].filter(Boolean).join(' · '))}${n ? ' · citée par ' + n + ' standard(s)' : ' · rangée dans cette partie du manuel'}</span></div>`; }).join('');
    const prouv = comp.map(c => { const k = X.comp[c] || {}; const p = k.panel || { etat: 'aucun' };
      return `<div class="rf-ligne">${rfComp(X, [c])} <span>${esc(k.fr || '')}</span><span class="sub">${p.etat === 'valide' || p.etat === 'certain' ? 'panel : ' + esc(p.nom) : 'panel : ' + ETAT_COR[p.etat].toLowerCase()}</span></div>`; }).join('');
    h += `<div class="rf-trois"><section class="card"><h2>À appliquer · ${stds.length} standards</h2>${app || '<p class="muted">Aucun standard dans cette phase.</p>'}</section>
      <section class="card"><h2>À apprendre · ${fo.length} formations</h2>${appr || '<p class="muted">Aucune formation.</p>'}</section>
      <section class="card"><h2>À prouver · ${comp.length} compétences</h2>${prouv || '<p class="muted">Aucune compétence reliée.</p>'}</section></div>
      <p class="muted fm-small">Calculé, rien n’est saisi ici : les standards portent leurs phases (fiche › Classement) ; les formations sont celles de ces standards et celles que le Manuel opératoire range dans cette partie.</p>`;
    return { titre: 'Par phase', kicker: K, corps: h, large: true };
  }

  /* ── Par poste : la matrice postes × phases, calculée ── */
  function cellule(X, p, ph) {
    const R = X.R;
    const stds = R.standards.filter(s => s.postes.includes(p) && s.phases.includes(ph));
    const foOblig = R.formations.filter(f => f.partie === ph && f.attendu && f.attendu[p] === 'O').map(f => f.code);
    const foRec = R.formations.filter(f => f.partie === ph && f.attendu && f.attendu[p] === 'R').map(f => f.code);
    const fo = plus(...stds.map(s => s.formations), foOblig, foRec);
    const comp = plus(...stds.map(s => s.competences), R.competences.filter(c => foOblig.includes(c.formation)).map(c => c.code));
    return { stds, fo, foOblig, comp };
  }
  function ecranParPoste() {
    const X = rx();
    if (!X) { return refAttente('Par poste'); }
    const R = X.R, a = C.S.route.a;
    const sp = X.poste[a[0]] ? a[0] : null, sph = PHASE_NOM[a[1]] ? a[1] : null;
    let h = `<section class="card table-card"><div class="table-scroll"><table class="rf-table rf-mat"><thead><tr><th style="width:22%">Poste (référentiel v25)</th>${PHASES.map(p => `<th>${esc(PHASE_NOM[p])}</th>`).join('')}</tr></thead><tbody>
      ${R.postes.map(p => `<tr><td><span class="rf-sc">${esc(p.code)} · ${esc(p.fr)}</span><span class="sub">${esc(p.nl || '')}</span></td>${PHASES.map(ph => {
        const c = cellule(X, p.code, ph), on = sp === p.code && sph === ph, vide = !c.stds.length && !c.fo.length;
        return `<td><a class="rf-cell${on ? ' is-on' : ''}${vide ? ' is-vide' : ''}" href="#/rfposte/${esc(p.code)}/${ph}"><span>${c.stds.length} standard${c.stds.length > 1 ? 's' : ''}</span><span class="sub">${c.fo.length} FO · ${c.comp.length} compétence${c.comp.length > 1 ? 's' : ''}</span></a></td>`;
      }).join('')}</tr>`).join('')}</tbody></table></div>
      <p class="muted fm-small rf-legende">Calculé : les standards qui portent ce poste et cette phase ; les formations de ces standards et celles que le v25 attend pour ce poste (obligatoires ou recommandées) dans cette partie du manuel ; les compétences de ces standards et celles des formations obligatoires.</p></section>`;
    if (sp && sph) {
      const c = cellule(X, sp, sph), p = X.poste[sp];
      h += `<h2 class="screen-subtitle">${esc(p.code)} · ${esc(p.fr)} · ${esc(PHASE_NOM[sph])}</h2><div class="rf-trois">
        <section class="card"><h2>Standards · ${c.stds.length}</h2>${c.stds.map(s => `<div class="rf-ligne">${lienStd(s)} ${rfStatut(s)}<span class="sub">${esc(s.moment ? 'moment : ' + s.moment : 'sans moment')}</span></div>`).join('') || '<p class="muted">Aucun standard.</p>'}</section>
        <section class="card"><h2>Formations · ${c.fo.length}</h2>${c.fo.map(k => `<div class="rf-ligne">${rcode(k)} <span>${esc((X.fo[k] || {}).fr || '')}</span><span class="sub">${c.foOblig.includes(k) ? 'obligatoire pour ce poste' : c.stds.some(s => s.formations.includes(k)) ? 'citée par un standard' : 'recommandée'}</span></div>`).join('') || '<p class="muted">Aucune formation.</p>'}</section>
        <section class="card"><h2>Compétences · ${c.comp.length}</h2>${c.comp.map(k => `<div class="rf-ligne">${rfComp(X, [k])} <span>${esc((X.comp[k] || {}).fr || '')}</span></div>`).join('') || '<p class="muted">Aucune compétence.</p>'}</section></div>`;
    }
    return { titre: 'Par poste', kicker: K, corps: h, large: true };
  }

  /* ── Compétences : la correspondance C-… ↔ panel, puis l'acquisition en magasin ── */
  function ecranCompetencesRef() {
    const onglets = `<div class="page-tabs" role="tablist"><a class="page-tabs__tab${C.S.route.a[0] !== 'magasins' ? ' is-on' : ''}" href="#/sopcomp">Correspondance avec le panel</a>
      <a class="page-tabs__tab${C.S.route.a[0] === 'magasins' ? ' is-on' : ''}" href="#/sopcomp/magasins">Acquisition en magasin</a></div>`;
    const mag = C.S.route.a[0] === 'magasins';
    const v = mag ? ecranCompetences() : ecranCorrespondance();
    const note = mag ? C.bandeau('Le « vu faire » coche désormais au panel les compétences reliées aux codes C-… de chaque SOP (onglet Correspondance). Ce tableau suit encore la compétence du panel rattachée à chaque SOP dans Rédaction, gardée en repli pour une SOP sans code relié.', '') : '';
    return { titre: 'Correspondance des compétences', kicker: K, corps: onglets + note + v.corps, large: true };
  }
  function ecranCorrespondance() {
    const X = D.cor;
    if (!X) { return { corps: D.corErr ? `<p class="error">${esc(D.corErr)}</p>` : attente('Lecture de la correspondance…') }; }
    const cv = X.couverture, f = D.corF, q = String(C.S.f.corq || '').trim().toLowerCase();
    let h = X.panel.indispo ? C.bandeau('Compétences du panel : ' + esc(X.panel.motif || 'indisponibles') + (X.panel.total ? ' — ' + X.panel.total + ' gardées de la dernière lecture.' : '.'), 'warn')
      : `<p class="muted fm-small">${X.panel.total} compétences lues au panel (${esc(X.panel.source)})${X.panel.lueLe ? ' le ' + esc(dth(X.panel.lueLe)) : ''} · <button type="button" class="linklike" data-sa="corRelire">Relire le panel</button></p>`;
    if (D.corBilan) { const b = D.corBilan; h += C.bandeau(`Proposition automatique : ${b.certain} certaine(s), ${b.propose} à trancher, ${b.aucun} sans proposition ; ${b.gardees} déjà tranchée(s), gardée(s) telles quelles.`, ''); }
    h += `<section class="card rf-kpis"><div class="kpi-row">${kpi(`${cv.valide + cv.certain} / ${cv.total}`, 'codes reliés au panel', cv.valide + ' validés · ' + cv.certain + ' certains')}
      ${kpi(cv.propose, 'à trancher', 'jamais utilisés pour cocher')}${kpi(cv.aucun, 'sans correspondance', '')}
      ${kpi(`${cv.panelRelies} / ${cv.panelTotal}`, 'compétences du panel reliées', '')}${kpi(cv.sansStandard, 'codes sans standard', 'aucune SOP ne les prouve')}</div></section>`;
    const fb = (v, l, n) => `<button type="button" class="filter${f === v ? ' is-on' : ''}" data-sa="corF" data-v="${v}">${l} · ${n}</button>`;
    h += `<div class="toolbar">${fb('propose', 'À trancher', cv.propose)}${fb('certain', 'Certaines', cv.certain)}${fb('valide', 'Validées', cv.valide)}${fb('aucun', 'Aucune', cv.aucun)}${fb('tous', 'Toutes', cv.total)}
      <input type="search" placeholder="Rechercher un code, une compétence…" data-input="corq" value="${esc(C.S.f.corq || '')}">
      <button type="button" class="btn btn--primary" data-sa="corProposer" ${D.occupe ? 'disabled' : ''} title="Relance la proposition automatique ; une correspondance tranchée par une personne n’est jamais réécrite">Proposer les correspondances</button></div>`;
    const lignes = X.lignes.filter(l => (f === 'tous' || l.etat === f) && (!q || [l.code, l.fr, l.panel && l.panel.nom].join(' ').toLowerCase().includes(q)));
    const opts = l => `<option value="">Autre compétence du panel…</option>${X.liste.map(p => `<option value="${p.id}"${l.panel && l.panel.id === p.id ? ' selected' : ''}>${esc((p.categorie ? p.categorie + ' › ' : '') + p.nom)}${p.codes.length && !(l.panel && l.panel.id === p.id) ? ' (déjà : ' + esc(p.codes.join(', ')) + ')' : ''}</option>`).join('')}`;
    const etat = l => l.etat === 'valide' ? `<span class="badge badge--ok">Validée</span>` : l.etat === 'certain' ? `<span class="chip chip--neutral">Certaine</span>` : l.etat === 'propose' ? trou('À trancher') : `<span class="chip chip--neutral">Aucune</span>`;
    h += `<section class="card table-card"><div class="table-scroll"><table class="rf-table"><thead><tr><th style="width:17%">Code</th><th style="width:23%">Compétence (v25)</th><th>Standards</th><th style="width:20%">Compétence du panel</th><th style="width:28%">Trancher</th></tr></thead><tbody>
      ${lignes.map(l => `<tr><td>${rcode(l.code)}<span class="sub">${esc(l.famille)}${l.formation ? ' · ' + esc(l.formation) : ''}</span></td>
        <td>${esc(l.fr)}${l.description ? `<span class="sub">${esc(l.description.length > 110 ? l.description.slice(0, 109) + '…' : l.description)}</span>` : ''}</td>
        <td>${l.standards.length ? l.standards.map(s => `<a class="rf-std" href="#/sop/${esc(s.id)}"><span>${esc(s.titre)}</span></a>`).join('<br>') : trou('aucun standard')}</td>
        <td>${etat(l)}${l.panel ? `<span class="sub">${esc(l.panel.nom)} (#${l.panel.id})</span>` : ''}${l.majPar ? `<span class="sub">${esc(l.majPar)}${l.majLe ? ' · ' + esc(dt(l.majLe)) : ''}</span>` : ''}</td>
        <td><div class="rf-props">${l.etat === 'certain' ? `<button type="button" class="btn btn--sm" data-sa="corTrancher" data-code="${esc(l.code)}" data-v="${l.panel.id}">Confirmer</button>` : ''}
          ${l.etat !== 'valide' && l.etat !== 'certain' ? l.propositions.map(p => `<button type="button" class="btn btn--sm" data-sa="corTrancher" data-code="${esc(l.code)}" data-v="${p.id}" title="score ${Math.round(p.score * 100)} %">${esc(p.nom)} · ${Math.round(p.score * 100)} %</button>`).join('') : ''}</div>
          <div class="rf-props"><select class="rf-sel" data-sch="corSel" data-code="${esc(l.code)}">${opts(l)}</select>${l.etat !== 'aucun' || !l.tranche ? `<button type="button" class="btn btn--sm btn--danger" data-sa="corTrancher" data-code="${esc(l.code)}" data-v="">Aucune</button>` : ''}</div></td></tr>`).join('')
        || `<tr><td colspan="5" class="muted">Rien dans ce filtre.</td></tr>`}</tbody></table></div></section>`;
    const libres = X.liste.filter(p => !p.codes.length);
    h += `<section class="card"><h2>Compétences du panel sans code C-… · ${libres.length} / ${X.liste.length}</h2><p class="muted fm-small">le « vu faire » ne les cochera jamais tant qu’aucun code n’y est relié</p>
      <div class="rf-chips">${libres.map(p => `<span class="chip chip--neutral" title="${esc(p.categorie || '')}">${esc(p.nom)}</span>`).join('')}</div></section>`;
    return { corps: h };
  }

  /* ── Le bloc « Classement » de la fiche d'une SOP ── */
  function carteClassement(s) {
    const X = rx();
    if (!X) { return `<section class="card"><h2>Classement</h2>${D.refErr ? `<p class="error">${esc(D.refErr)}</p>` : attente('Lecture du référentiel…')}</section>`; }
    const cur = s.classement || { souscat: null, phases: [], postes: [], competences: [], formations: [], checklists: [] };
    const E = D.cl[s.id], v = E || cur, sc = X.sc[v.souscat];
    if (tablette()) {
      return `<section class="card"><h2>Classement</h2><dl class="sop-dl"><dt>Sous-catégorie</dt><dd>${sc ? esc(v.souscat + ' · ' + sc.fr) : '—'}</dd><dt>Phases</dt><dd>${rfPhases(v.phases) || '—'}</dd>
        <dt>Postes</dt><dd>${esc(v.postes.join(', ') || '—')}</dd><dt>Compétences</dt><dd>${rfComp(X, v.competences) || '—'}</dd><dt>Formations</dt><dd>${rfFo(X, v.formations) || '—'}</dd></dl></section>`;
    }
    const pill = (k, val, lib, titre) => `<button type="button" class="choice-pill rf-pill${v[k].includes(val) ? ' is-on' : ''}" data-sa="clTog" data-id="${esc(s.id)}" data-k="${k}" data-v="${esc(val)}"${titre ? ` title="${esc(titre)}"` : ''}>${esc(lib)}</button>`;
    const optsListe = k => {
      if (k === 'competences') {
        const fams = {};
        X.R.competences.forEach(c => { (fams[c.famille] = fams[c.famille] || []).push(c); });
        return Object.keys(fams).map(fa => `<optgroup label="${esc(fa)}">${fams[fa].filter(c => !v[k].includes(c.code)).map(c => `<option value="${esc(c.code)}">${esc(c.code)} · ${esc(c.fr)}</option>`).join('')}</optgroup>`).join('');
      }
      if (k === 'formations') { return X.R.formations.filter(f => !v[k].includes(f.code)).map(f => `<option value="${esc(f.code)}">${esc(f.code)} · ${esc(f.fr)}</option>`).join(''); }
      return X.R.checklistsConnues.filter(c => !v[k].includes(c)).map(c => `<option value="${esc(c)}">${esc(c)}</option>`).join('');
    };
    const liste = k => {
      const sug = sc ? (sc[k] || []).filter(c => !v[k].includes(c) && (k !== 'competences' || X.comp[c])) : [];
      const nom = c => k === 'competences' ? (X.comp[c] || {}).fr : k === 'formations' ? (X.fo[c] || {}).fr : '';
      return `<div class="rf-champ"><span class="rf-lab">${CL_NOM[k]}</span><div class="rf-toks">${v[k].map(c => `<span class="rf-tok" title="${esc(nom(c) || '')}">${esc(c)}<button type="button" class="btn btn--icon btn--danger" data-sa="clDel" data-id="${esc(s.id)}" data-k="${k}" data-v="${esc(c)}" aria-label="Retirer ${esc(c)}">✕</button></span>`).join('')}
        <select class="rf-sel" data-sch="clAdd" data-id="${esc(s.id)}" data-k="${k}"><option value="">Ajouter…</option>${optsListe(k)}</select></div>
        ${sug.length ? `<div class="rf-sug">Proposés par ${esc(v.souscat)} : ${sug.map(c => `<button type="button" class="linklike" data-sa="clSug" data-id="${esc(s.id)}" data-k="${k}" data-v="${esc(c)}">+ ${esc(c)}</button>`).join(' ')}</div>` : ''}</div>`;
    };
    const opts = X.R.domaines.map(d => `<optgroup label="${esc(d.code + ' · ' + d.fr)}">${d.souscats.map(x => `<option value="${esc(x.cle)}"${x.cle === v.souscat ? ' selected' : ''}>${esc(x.cle + ' · ' + x.fr)}</option>`).join('')}</optgroup>`).join('');
    return `<section class="card rf-classement"><div class="rf-tete"><h2>Classement</h2><span class="muted fm-small">Manuel du réseau · référentiel v25</span></div>
      <div class="rf-champ"><span class="rf-lab">Sous-catégorie</span><select class="rf-sel rf-sel--large" data-sch="clSc" data-id="${esc(s.id)}"><option value="">À classer…</option>${opts}</select>${sc ? `<span class="sub">${esc(X.dom[sc.domaine].fr)}${sc.description ? ' · ' + esc(sc.description) : ''}</span>` : ''}</div>
      <div class="rf-champ"><span class="rf-lab">Phases</span><div class="rf-pills">${PHASES.map(p => pill('phases', p, PHASE_NOM[p])).join('')}</div></div>
      <div class="rf-champ"><span class="rf-lab">Postes</span><div class="rf-pills">${X.R.postes.map(p => pill('postes', p.code, p.code, p.fr)).join('')}</div></div>
      ${CL_LISTES.map(liste).join('')}
      ${E && E.err ? `<p class="error">${esc(E.err)}</p>` : ''}
      <div class="rf-actions"><button type="button" class="btn" data-sa="clSave" data-id="${esc(s.id)}" ${!E || D.occupe ? 'disabled' : ''}>Enregistrer le classement</button>${E ? `<button type="button" class="btn btn--ghost" data-sa="clAnnuler" data-id="${esc(s.id)}">Annuler</button><span class="muted fm-small">modifications non enregistrées</span>` : ''}</div></section>`;
  }
  /** Le classement en cours d'édition d'une SOP (copie de celui enregistré au premier geste). */
  function clEd(id) {
    if (!D.cl[id]) { const s = D.fiche[id], c = (s && s.classement) || {}; D.cl[id] = { souscat: c.souscat || null, phases: (c.phases || []).slice(), postes: (c.postes || []).slice(), competences: (c.competences || []).slice(), formations: (c.formations || []).slice(), checklists: (c.checklists || []).slice() }; }
    return D.cl[id];
  }

  /* ── Lectures du référentiel ── */
  function chargerRef(forcer) {
    if ((D.ref && !forcer) || D.refCharge) { return; }
    D.refCharge = true;
    C.lire('/formation/ref?detail=1', 30000).then(d => { D.ref = d; D.refErr = null; D.refCharge = false; C.rendre(true); }, er => { D.refErr = er.message; D.refCharge = false; C.rendre(true); });
  }
  function chargerCor(forcer, rafraichir) {
    if ((D.cor && !forcer) || D.corCharge) { return; }
    D.corCharge = true;
    C.lire('/formation/ref/correspondance' + (rafraichir ? '?rafraichir=1' : ''), 120000).then(d => { D.cor = d; D.corErr = null; D.corCharge = false; if (d.auto) { D.corBilan = d.auto; } C.rendre(true); },
      er => { D.corErr = er.message; D.corCharge = false; C.rendre(true); });
  }

  /** Les gestes du Manuel du réseau ; vrai quand le geste était le sien. */
  async function gesteRef(d, t) {
    switch (d.sa) {
      case 'rfImporter':
        D.occupe = true; C.rendre(true);
        try { D.imp = await C.appel('POST', '/formation/ref/importer-crm', {}); toast(D.imp.disponible ? D.imp.reprises.length + ' procédure(s) reprise(s)' : 'Manuel du CRM absent de cette base'); chargerRef(true); chargerListe(true); }
        catch (x) { toast(x.message); }
        D.occupe = false; C.rendre(true); return true;
      case 'scEd': { const s = rx().sc[d.v]; D.scEd = { cle: d.v, nl: s.nl || '', description: s.description || '', err: null }; C.rendre(true); return true; }
      case 'scAnnuler': D.scEd = null; C.rendre(true); return true;
      case 'scSave':
        D.occupe = true; C.rendre(true);
        try { await C.appel('PUT', '/formation/ref/souscats/' + encodeURIComponent(D.scEd.cle), { nl: D.scEd.nl, description: D.scEd.description }); toast('Sous-catégorie enregistrée'); D.scEd = null; chargerRef(true); }
        catch (x) { D.scEd.err = x.message; }
        D.occupe = false; C.rendre(true); return true;
      case 'clTog': { const e = clEd(d.id), l = e[d.k], i = l.indexOf(d.v); if (i >= 0) { l.splice(i, 1); } else { l.push(d.v); } e.err = null; C.rendre(true); return true; }
      case 'clDel': { const e = clEd(d.id); e[d.k] = e[d.k].filter(x => x !== d.v); C.rendre(true); return true; }
      case 'clSug': { const e = clEd(d.id); if (!e[d.k].includes(d.v)) { e[d.k].push(d.v); } C.rendre(true); return true; }
      case 'clAnnuler': delete D.cl[d.id]; C.rendre(true); return true;
      case 'clSave': {
        const e = D.cl[d.id];
        if (!e) { return true; }
        D.occupe = true; C.rendre(true);
        try {
          const r = await C.appel('PUT', '/formation/ref/standards/' + encodeURIComponent(d.id), { souscat: e.souscat, phases: e.phases, postes: e.postes, competences: e.competences, formations: e.formations, checklists: e.checklists });
          delete D.cl[d.id]; toast('Classement enregistré');
          if (D.fiche[d.id]) { D.fiche[d.id].classement = { souscat: r.standard.souscat, phases: r.standard.phases, postes: r.standard.postes, competences: r.standard.competences, formations: r.standard.formations, checklists: r.standard.checklists }; }
          chargerRef(true); chargerListe(true); if (D.cor) { chargerCor(true); }
        } catch (x) { e.err = x.message + (x.data && x.data.invalides ? ' — ' + Object.keys(x.data.invalides).map(k => k + ' : ' + x.data.invalides[k].join(', ')).join(' · ') : ''); }
        D.occupe = false; C.rendre(true); return true;
      }
      case 'corF': D.corF = d.v; C.rendre(true); return true;
      case 'corRelire': D.cor = null; chargerCor(true, true); C.rendre(true); return true;
      case 'corProposer':
        D.occupe = true; C.rendre(true);
        try { D.corBilan = await C.appel('POST', '/formation/ref/correspondance/proposer', {}, 120000); chargerCor(true); chargerRef(true); }
        catch (x) { toast(x.message); }
        D.occupe = false; C.rendre(true); return true;
      case 'corTrancher': return trancher(d.code, d.v);
    }
    return false;
  }
  async function trancher(code, v) {
    try {
      await C.appel('PUT', '/formation/ref/correspondance/' + encodeURIComponent(code), { panelId: v ? +v : null });
      toast(v ? code + ' relié au panel' : code + ' : aucune compétence du panel');
      chargerCor(true); chargerRef(true);
    } catch (x) { toast(x.message); }
    return true;
  }
  /** Les choix (listes déroulantes) du Manuel du réseau ; vrai quand le choix était le sien. */
  function choixRef(k, t) {
    const d = t.dataset;
    if (k === 'clSc') { const e = clEd(d.id); e.souscat = t.value || null; e.err = null; C.rendre(true); return true; }
    if (k === 'clAdd') { if (t.value) { const e = clEd(d.id); if (!e[d.k].includes(t.value)) { e[d.k].push(t.value); } } C.rendre(true); return true; }
    if (k === 'corSel') { if (t.value) { trancher(d.code, t.value); } return true; }
    return false;
  }

  /* ═══ Manuel du réseau : les deux guides que le CRM éditait (lot 4b, src/referentiel_guides.php) ═══
   *
   *   #/rfpre                  le Guide pré-ouverture : sections et points (qui, échéance J-, preuve), FR | NL,
   *                            glisser pour ordonner ; tout le guide part d'un bloc (PUT /formation/ref/preouverture)
   *   #/rfform[/<n>[/<id>]]    le Guide des formations et compétences : les 11 blocs, leurs points, le critère
   *                            « Réussi si… » (proposé puis accepté), phase, bloquante, 5 pôles, renouvellement, fiche,
   *                            vidéo, profils ; les noms des 5 pôles et le repère
   * Lus dans le référentiel (GET /formation/ref : preouverture, blocs, poles) ; le CRM lit les mêmes pour son écran
   * Guides, l'application des formateurs, l'attestation et les documents qu'il génère.
   */
  const QUI_NOM = { franchise: 'Franchisé', atelier: 'L’Atelier By', ensemble: 'Ensemble', formateurs: 'Formateurs' };
  const QUIS = Object.keys(QUI_NOM);
  const PH_FMT = { pre: 'Pré-ouverture', ouv: 'Ouverture', con: 'Continu' };
  const PROFIL_NOM = { gerant: 'Gérant', vendeur: 'Vendeur', etudiant: 'Étudiant', multi: 'Plusieurs boutiques' };
  const RENOUV = [0, 6, 12, 18, 24, 36];
  const REPERES = [0, 3, 3.5, 4, 4.5];
  // po : le guide pré-ouverture en cours d'édition (copie du référentiel) ; fm* : le point de formation édité.
  const G = { po: null, poMod: false, poLang: 'fr', poSel: '', poErr: null, glisse: null, n: 0,
    fmN: 0, fmSel: '', fmEd: null, fmErr: null, blocEd: null, ajout: null, poles: null, occupe: false };
  const virg = v => String(v).replace('.', ',');
  const clone = o => JSON.parse(JSON.stringify(o));
  const seg = (sa, v, choix, extra) => `<div class="rf-seg" role="group">${choix.map(([k, lib]) => `<button type="button" data-rg="${sa}" data-v="${esc(k)}"${extra || ''}${String(k) === String(v) ? ' class="is-on" aria-pressed="true"' : ' aria-pressed="false"'}>${esc(lib)}</button>`).join('')}</div>`;
  const bascule = (sa, on, lib, extra) => `<button type="button" class="rf-switch${on ? ' is-on' : ''}" role="switch" aria-checked="${on}" data-rg="${sa}"${extra || ''}><span class="rf-switch__track"></span><span>${esc(lib)}</span></button>`;
  const pret = R => !!(R && R.preouverture && R.blocs && R.poles);
  function ancienApi(titre) { return { titre, kicker: K, corps: '<p class="error">Le référentiel lu ne porte pas encore les guides : l’API du Cockpit est à mettre à jour.</p>', large: true }; }
  /** Le bouton « Enregistrer » et la mention « Modifié » suivent la saisie sans redessiner l'écran (le focus reste). */
  function marquerModifie(sel) { document.querySelectorAll(sel).forEach(b => { b.disabled = false; }); document.querySelectorAll('[data-rg-mod]').forEach(x => { x.hidden = false; }); }

  /* ── Le Guide pré-ouverture ── */
  function poModele() {
    // Une copie sans modification suit le référentiel relu (une écriture d'un autre poste la remplace).
    if (G.po && (G.poMod || G.po.version === D.ref.version)) { return G.po; }
    const S = clone(D.ref.preouverture.sections);
    S.forEach(s => { s._id = 's:' + s.cle; s.points.forEach(p => { p._id = 'p:' + p.cle; }); });
    G.po = { sections: S, version: D.ref.version }; G.poMod = false;
    if (!S.some(s => s._id === G.poSel)) { G.poSel = S[0] ? S[0]._id : ''; }
    return G.po;
  }
  const poTxt = (o, champ, l) => champ === 'lib' ? o[l] : champ === 'titre' ? o[l] : champ === 'intro' ? o['intro_' + l] : champ === 'preuve' ? o['preuve_' + l] : '';
  const poCle = (champ, l) => champ === 'lib' || champ === 'titre' ? l : champ === 'intro' ? 'intro_' + l : 'preuve_' + l;
  function sansNl(P) { let n = 0; P.sections.forEach(s => { if (!s.nl) { n++; } s.points.forEach(p => { if (!p.nl) { n++; } }); }); return n; }

  function ecranPreouverture() {
    const X = rx();
    if (!X) { return refAttente('Pré-ouverture'); }
    if (!pret(X.R)) { return ancienApi('Pré-ouverture'); }
    const P = poModele(), l = G.poLang, s = P.sections.find(x => x._id === G.poSel) || P.sections[0];
    const nPts = P.sections.reduce((a, x) => a + x.points.length, 0), nNl = sansNl(P);
    let h = `<div class="toolbar">${seg('poLang', l, [['fr', 'FR'], ['nl', 'NL']])}
      <span class="muted fm-small">${P.sections.length} sections · ${nPts} points</span>${nNl ? `<span class="tag-warn" title="Sections et points sans libellé néerlandais">${nNl} sans NL</span>` : ''}
      <span class="rf-sp"></span><span class="tag-warn" data-rg-mod${G.poMod ? '' : ' hidden'}>Modifié</span>
      ${G.poMod ? '<button type="button" class="btn btn--ghost" data-rg="poAnnuler">Annuler</button>' : ''}
      <button type="button" class="btn btn--primary" data-rg="poSave" data-rg-save="po"${!G.poMod || G.occupe ? ' disabled' : ''}>Enregistrer le guide</button></div>`;
    if (G.poErr) { h += C.bandeau(esc(G.poErr), 'warn'); }
    h += `<div class="rf-g2"><section class="card rf-gcol"><h2>Sections du guide</h2><ol class="rf-gliste">${P.sections.map((x, i) => `<li class="rf-grow rf-gdrag${x === s ? ' is-on' : ''}" draggable="true" data-rgdrag="sec" data-i="${i}">
        <span class="rf-ghandle" aria-hidden="true" title="Glisser pour ordonner">⋮⋮</span>
        <button type="button" class="rf-grow__t" data-rg="poSel" data-v="${esc(x._id)}"${x === s ? ' aria-current="true"' : ''}><span>${i + 1}. ${esc(x[l] || x.fr || 'Sans titre')}</span>${l === 'nl' && !x.nl ? '<span class="sub">NL à écrire</span>' : ''}</button>
        <span class="chip chip--neutral rf-gn" title="${x.type === 'contacts' ? 'contacts' : 'points'}">${x.points.length}</span>
        <span class="rf-gmv"><button type="button" class="btn btn--icon btn--ghost" data-rg="poSecMv" data-i="${i}" data-d="-1" aria-label="Monter"${i ? '' : ' disabled'}>↑</button><button type="button" class="btn btn--icon btn--ghost" data-rg="poSecMv" data-i="${i}" data-d="1" aria-label="Descendre"${i < P.sections.length - 1 ? '' : ' disabled'}>↓</button></span></li>`).join('')}</ol>
      <div class="rf-actions"><button type="button" class="btn btn--sm" data-rg="poSecAjout">Ajouter une section</button></div></section>`;
    h += s ? sectionPo(s, l) : '<section class="card"><p class="muted">Aucune section.</p></section>';
    return { titre: 'Pré-ouverture', kicker: K, corps: h + '</div>', large: true };
  }
  function sectionPo(s, l) {
    const pts = s.type !== 'contacts';
    const ph = (o, champ) => l === 'nl' && poTxt(o, champ, 'fr') ? ` placeholder="${esc(poTxt(o, champ, 'fr'))}"` : '';
    const inp = (o, pid, champ, cls) => `<input class="rf-in${cls ? ' ' + cls : ''}" data-rgin="po|${esc(s._id)}|${esc(pid)}|${champ}" value="${esc(poTxt(o, champ, l) || '')}"${ph(o, champ)}>`;
    let h = `<section class="card rf-gcol"><div class="rf-tete"><h2>Section</h2><span class="muted fm-small">${esc(s.cle || 'nouvelle')}</span><span class="rf-sp"></span>
      <button type="button" class="btn btn--sm btn--danger" data-rg="poSecSuppr">Supprimer la section</button></div>
      <div class="rf-gform"><label class="rf-lab">Titre${l === 'nl' ? ' (néerlandais)' : ''}${inp(s, '', 'titre')}</label>
      <label class="rf-lab">Type<select class="rf-sel rf-sel--large" data-rgch="po|${esc(s._id)}||type"><option value="points"${pts ? ' selected' : ''}>Points datés (qui, échéance, preuve)</option><option value="contacts"${pts ? '' : ' selected'}>Contacts (rôle, contact)</option></select></label></div>
      <label class="rf-lab">Introduction${l === 'nl' ? ' (néerlandais)' : ''}<textarea class="rf-in" rows="3" data-rgin="po|${esc(s._id)}||intro"${ph(s, 'intro')}>${esc(poTxt(s, 'intro', l) || '')}</textarea></label>
      <div class="table-scroll rf-gtab"><table class="rf-table rf-gpts${pts ? '' : ' rf-gpts--contacts'}"><thead><tr><th class="rf-gc-h"></th><th>${pts ? 'Point' : 'Rôle'}</th>${pts ? '<th class="rf-gc-qui">Qui</th><th class="rf-gc-j">Échéance</th>' : ''}<th class="rf-gc-pr">${pts ? 'Preuve' : 'Contact'}</th><th class="rf-gc-mv"></th></tr></thead><tbody>
      ${s.points.map((p, i) => `<tr class="rf-gdrag" draggable="true" data-rgdrag="pt" data-i="${i}"><td class="rf-gc-h"><span class="rf-ghandle" aria-hidden="true" title="Glisser pour ordonner">⋮⋮</span></td>
        <td>${inp(p, p._id, 'lib')}</td>
        ${pts ? `<td class="rf-gc-qui"><select class="rf-sel" data-rgch="po|${esc(s._id)}|${esc(p._id)}|qui">${QUIS.map(q => `<option value="${q}"${q === p.qui ? ' selected' : ''}>${esc(QUI_NOM[q])}</option>`).join('')}</select></td>
        <td class="rf-gc-j"><span class="rf-gj"><span class="muted">J-</span><input class="rf-in" inputmode="numeric" data-rgin="po|${esc(s._id)}|${esc(p._id)}|j" value="${p.j === null ? '' : esc(p.j)}" aria-label="Jours avant l’ouverture"></span></td>` : ''}
        <td class="rf-gc-pr">${inp(p, p._id, 'preuve')}</td>
        <td class="rf-gc-mv"><button type="button" class="btn btn--icon btn--ghost" data-rg="poPtMv" data-i="${i}" data-d="-1" aria-label="Monter"${i ? '' : ' disabled'}>↑</button><button type="button" class="btn btn--icon btn--ghost" data-rg="poPtMv" data-i="${i}" data-d="1" aria-label="Descendre"${i < s.points.length - 1 ? '' : ' disabled'}>↓</button><button type="button" class="btn btn--icon btn--danger" data-rg="poPtSuppr" data-i="${i}" aria-label="Retirer le point">✕</button></td></tr>`).join('')
        || `<tr><td colspan="${pts ? 6 : 4}" class="muted">Aucun point.</td></tr>`}</tbody></table></div>
      <div class="rf-actions"><button type="button" class="btn btn--sm" data-rg="poPtAjout">Ajouter un point</button></div></section>`;
    return h;
  }
  function poSection() { const P = G.po; return P && (P.sections.find(x => x._id === G.poSel) || P.sections[0]); }
  function poModif() { G.poMod = true; G.poErr = null; }
  function poCorps() {
    return G.po.sections.map(s => ({ cle: s._id.startsWith('n:') ? '' : s.cle, type: s.type, fr: s.fr, nl: s.nl, intro_fr: s.intro_fr, intro_nl: s.intro_nl,
      points: s.points.map(p => ({ cle: p._id.startsWith('n:') ? '' : p.cle, fr: p.fr, nl: p.nl, qui: p.qui, j: p.j, preuve_fr: p.preuve_fr, preuve_nl: p.preuve_nl })) }));
  }
  const deplacer = (l, de, vers) => { if (vers < 0 || vers >= l.length || de === vers) { return; } const [x] = l.splice(de, 1); l.splice(vers, 0, x); };

  /* ── Le Guide des formations et compétences ── */
  function blocDe(R, n) { return R.blocs.find(b => b.n === n) || R.blocs[0]; }
  const nbProp = b => b.items.filter(it => it.critere_propose).length;
  function fmEdition(it, b) {
    if (G.fmEd && G.fmEd.id === it.id) { return G.fmEd; }
    G.fmEd = Object.assign(clone(it), { bloc: b.n, _orig: clone(Object.assign({}, it, { bloc: b.n })), _mod: false }); G.fmErr = null;
    return G.fmEd;
  }
  function ecranFormations() {
    const X = rx();
    if (!X) { return refAttente('Formations et compétences'); }
    const R = X.R;
    if (!pret(R)) { return ancienApi('Formations et compétences'); }
    const a = C.S.route.a, q = String(C.S.f.rgq || '').trim().toLowerCase();
    const premier = (R.blocs.find(b => nbProp(b)) || R.blocs[0]).n;
    G.fmN = +a[0] && R.blocs.some(b => b.n === +a[0]) ? +a[0] : (G.fmN || premier);
    const b = blocDe(R, G.fmN);
    const tous = [].concat(...R.blocs.map(x => x.items.map(it => ({ it, b: x }))));
    const liste = q ? tous.filter(x => [x.it.id, x.it.fr, x.it.nl, x.it.fo, x.it.critere_fr].join(' ').toLowerCase().includes(q)) : b.items.map(it => ({ it, b }));
    const selId = a[1] || G.fmSel;
    const sel = tous.find(x => x.it.id === selId) || liste[0] || null;
    if (sel) { G.fmSel = sel.it.id; }
    const nIt = tous.length, nPr = tous.filter(x => x.it.critere_propose).length, nBl = tous.filter(x => x.it.bloq).length;
    const heures = R.blocs.reduce((s, x) => s + (+x.heures || 0), 0);
    let h = `<div class="toolbar"><input type="search" placeholder="Rechercher dans les ${nIt} points (code, libellé, critère)…" data-input="rgq" value="${esc(C.S.f.rgq || '')}">
      <button type="button" class="btn rf-gtog" data-rg="polesOuvrir" aria-expanded="${!!G.poles}">Les 5 pôles</button></div>`;
    h += `<section class="card rf-kpis"><div class="kpi-row">${kpi(R.blocs.length, 'blocs', virg(heures) + ' h prévues')}${kpi(nIt, 'points de formation', nBl + ' bloquants pour l’attestation')}
      ${kpi(nPr, 'critères proposés', nPr ? 'à accepter ou réécrire' : 'tous acceptés')}${kpi(R.poles.repere ? virg(R.poles.repere) + ' / 5' : '—', 'repère du réseau', R.poles.fr.join(' · '))}</div></section>`;
    if (G.poles) { h += cartePoles(R); }
    if (G.fmErr) { h += C.bandeau(esc(G.fmErr), 'warn'); }
    h += `<div class="rf-g3"><section class="card rf-gcol rf-gblocs"><h2>Les ${R.blocs.length} blocs</h2><ol class="rf-gliste">${R.blocs.map(x => `<li class="rf-grow${x.n === b.n && !q ? ' is-on' : ''}">
        <span class="chip chip--neutral rf-gn">${x.n}</span>
        <button type="button" class="rf-grow__t" data-rg="fmBloc" data-v="${x.n}"${x.n === b.n && !q ? ' aria-current="true"' : ''}><span>${esc(x.fr)}</span><span class="sub">${esc(x.role)}</span></button>
        <span class="rf-gh">${x.heures ? virg(x.heures) + ' h' : '—'}</span>
        <span class="rf-gprop">${nbProp(x) ? `<span class="tag-warn">${nbProp(x)} proposé${nbProp(x) > 1 ? 's' : ''}</span>` : '<span class="badge badge--ok">acceptés</span>'}</span></li>`).join('')}</ol></section>`;
    h += carteBloc(R, b, liste, q, sel);
    h += sel ? ficheItem(R, sel.it, sel.b) : '<section class="card rf-gfiche"><p class="muted">Aucun point.</p></section>';
    return { titre: 'Formations et compétences', kicker: K, corps: h + '</div>', large: true };
  }
  function carteBloc(R, b, liste, q, sel) {
    const E = G.blocEd && G.blocEd.n === b.n ? G.blocEd : null;
    let h = '<section class="card rf-gcol rf-gtable">';
    if (q) { h += `<div class="rf-tete"><h2>Recherche · ${liste.length} point${liste.length > 1 ? 's' : ''}</h2></div>`; }
    else if (E) {
      const ch = (k, lib) => `<label class="rf-lab">${lib}<input class="rf-in" data-rgin="bloc|${k}" value="${esc(E[k])}"></label>`;
      h += `<div class="rf-tete"><h2>Bloc ${b.n}</h2></div><div class="rf-gform">${ch('fr', 'Titre')}${ch('nl', 'Titre (néerlandais)')}${ch('role', 'Rôle du formateur')}${ch('role_nl', 'Rôle (néerlandais)')}${ch('fo', 'Formations (FO)')}${ch('heures', 'Heures prévues')}</div>
        <div class="rf-actions"><button type="button" class="btn" data-rg="blocSave"${G.occupe ? ' disabled' : ''}>Enregistrer le bloc</button><button type="button" class="btn btn--ghost" data-rg="blocAnnuler">Annuler</button></div>`;
    } else {
      h += `<div class="rf-tete"><h2>Bloc ${b.n} · ${esc(b.fr)}</h2><span class="rf-sp"></span><button type="button" class="btn btn--sm" data-rg="blocEd">Modifier le bloc</button></div>
        <p class="muted fm-small">${esc(b.nl || '—')} · ${esc(b.role)}${b.role_nl ? ' / ' + esc(b.role_nl) : ''} · ${esc(b.fo || '—')} · ${b.heures ? virg(b.heures) + ' h prévues' : 'sans heures prévues'}</p>
        ${nbProp(b) ? `<div class="rf-actions"><button type="button" class="btn btn--sm" data-rg="fmAccepterTout"${G.occupe ? ' disabled' : ''}>Accepter les ${nbProp(b)} critères proposés</button></div>` : ''}`;
    }
    h += `<div class="table-scroll rf-gtab"><table class="rf-table rf-gits"><thead><tr>${q ? '' : '<th class="rf-gc-h"></th>'}<th>Point</th><th>Réussi si…</th><th class="rf-gc-ph">Phase</th><th class="rf-gc-on">Bloq.</th><th class="rf-gc-on">Pôles</th><th class="rf-gc-rn">Renouv.</th>${q ? '' : '<th class="rf-gc-mv2"></th>'}</tr></thead><tbody>
      ${liste.map((x, i) => { const it = x.it; return `<tr class="row-link rf-gdrag${sel && sel.it.id === it.id ? ' is-on' : ''}"${q ? '' : ` draggable="true" data-rgdrag="it" data-i="${i}"`} data-rg="fmSel" data-v="${esc(it.id)}" data-n="${x.b.n}">
        ${q ? '' : '<td class="rf-gc-h"><span class="rf-ghandle" aria-hidden="true" title="Glisser pour ordonner">⋮⋮</span></td>'}
        <td><button type="button" class="rf-glien" data-rg="fmSel" data-v="${esc(it.id)}" data-n="${x.b.n}">${esc(it.fr)}</button><span class="sub">${esc(it.id)}${it.fo && it.fo !== it.id ? ' · ' + esc(it.fo) : ''}${q ? ' · bloc ' + x.b.n : ''}</span></td>
        <td class="rf-gcrit${it.critere_propose ? ' is-prop' : ''}">${it.critere_propose ? '<span class="tag-warn">proposé</span> ' : ''}${it.critere_fr ? esc(it.critere_fr) : '<span class="tag-warn">à écrire</span>'}</td>
        <td class="rf-gc-ph"><span class="rf-gph rf-gph--${esc(it.phase)}">${esc(PH_FMT[it.phase] || it.phase)}</span></td>
        <td class="rf-gc-on"><span class="chip ${it.bloq ? 'rf-goui' : 'chip--neutral'}">${it.bloq ? 'oui' : 'non'}</span></td>
        <td class="rf-gc-on"><span class="chip ${it.poles ? 'rf-goui' : 'chip--neutral'}">${it.poles ? 'oui' : 'non'}</span></td>
        <td class="rf-gc-rn">${it.renouvellement ? it.renouvellement + ' mois' : '<span class="muted">—</span>'}</td>
        ${q ? '' : `<td class="rf-gc-mv2"><button type="button" class="btn btn--icon btn--ghost" data-rg="fmMv" data-i="${i}" data-d="-1" aria-label="Monter"${i ? '' : ' disabled'}>↑</button><button type="button" class="btn btn--icon btn--ghost" data-rg="fmMv" data-i="${i}" data-d="1" aria-label="Descendre"${i < liste.length - 1 ? '' : ' disabled'}>↓</button></td>`}</tr>`; }).join('')
        || `<tr><td colspan="8" class="muted">Aucun point.</td></tr>`}</tbody></table></div>`;
    if (!q) {
      const A = G.ajout && G.ajout.n === b.n ? G.ajout : null;
      h += A ? `<div class="rf-gform rf-gajout"><label class="rf-lab">Code (C-… ou FO-…)<input class="rf-in" data-rgin="ajout|id" value="${esc(A.id)}"></label><label class="rf-lab">Libellé<input class="rf-in" data-rgin="ajout|fr" value="${esc(A.fr)}"></label></div>
          <div class="rf-actions"><button type="button" class="btn btn--sm" data-rg="ajoutSave"${G.occupe ? ' disabled' : ''}>Ajouter au bloc ${b.n}</button><button type="button" class="btn btn--sm btn--ghost" data-rg="ajoutAnnuler">Annuler</button></div>`
        : `<div class="rf-actions"><button type="button" class="btn btn--sm" data-rg="ajoutOuvrir">Ajouter un point</button></div>`;
    }
    return h + '</section>';
  }
  function ficheItem(R, it, b) {
    const E = fmEdition(it, b);
    const t = (k, lib, n) => `<label class="rf-lab">${lib}<textarea class="rf-in" rows="${n || 2}" data-rgin="it|${k}">${esc(E[k] || '')}</textarea></label>`;
    const i = (k, lib, extra) => `<label class="rf-lab">${lib}<input class="rf-in" data-rgin="it|${k}" value="${esc(E[k] || '')}"${extra || ''}></label>`;
    const sem = it.semis, ecart = sem && (E.phase !== sem.phase || E.bloq !== sem.bloq || E.poles !== sem.poles || E.renouvellement !== sem.renouvellement || [...E.profils].sort().join() !== [...sem.profils].sort().join());
    const httpsKo = k => E[k] && !/^https:\/\//i.test(String(E[k]).trim());
    return `<section class="card rf-gfiche"><div class="rf-tete"><h2>${esc(it.id)} · bloc ${b.n}</h2><span class="rf-sp"></span><span class="tag-warn" data-rg-mod${E._mod ? '' : ' hidden'}>Modifié</span></div>
      <div class="rf-gform">${i('fr', 'Libellé')}${i('nl', 'Libellé (néerlandais)')}</div>
      ${t('dfr', 'Ce que le franchisé apprend', 3)}${t('dnl', 'Ce que le franchisé apprend (néerlandais)', 3)}
      <div class="rf-gcritlab"><span class="rf-lab">Réussi si…</span>${it.critere_propose ? '<span class="tag-warn">proposé</span>' : ''}<span class="rf-sp"></span>
        ${it.critere_propose ? `<button type="button" class="linklike" data-rg="fmAccepter"${G.occupe ? ' disabled' : ''}>Accepter la proposition</button>` : ''}
        ${!it.critere_propose && it.critere_prop_fr && it.critere_fr !== it.critere_prop_fr ? `<button type="button" class="linklike" data-rg="fmProposition" title="${esc(it.critere_prop_fr)}">Revenir à la proposition</button>` : ''}</div>
      <textarea class="rf-in" rows="3" data-rgin="it|critere_fr" aria-label="Réussi si…">${esc(E.critere_fr || '')}</textarea>
      ${t('critere_nl', 'Geslaagd als… (néerlandais)')}
      <span class="rf-lab">Phase</span>${seg('fmPhase', E.phase, Object.keys(PH_FMT).map(k => [k, PH_FMT[k]]))}
      <div class="rf-gsw">${bascule('fmBloq', E.bloq, 'Bloquante pour l’attestation')}${bascule('fmPoles', E.poles, 'Notée sur les 5 pôles')}</div>
      <div class="rf-gform rf-gform--2"><label class="rf-lab">Renouvellement<select class="rf-sel rf-sel--large" data-rgch="it|renouvellement">${(RENOUV.includes(E.renouvellement) ? RENOUV : RENOUV.concat([E.renouvellement])).map(m => `<option value="${m}"${m === E.renouvellement ? ' selected' : ''}>${m ? 'tous les ' + m + ' mois' : 'aucun'}</option>`).join('')}</select></label>
        ${i('heures', 'Heures', ' inputmode="decimal"')}${i('fo', 'Formation (FO)')}
        <label class="rf-lab">Bloc<select class="rf-sel rf-sel--large" data-rgch="it|bloc">${R.blocs.map(x => `<option value="${x.n}"${x.n === E.bloc ? ' selected' : ''}>${x.n}. ${esc(x.fr)}</option>`).join('')}</select></label></div>
      <div class="rf-gform">${i('fiche', 'Fiche technique (https://…)')}${i('fiche_nom', 'Nom de la fiche')}</div>
      ${httpsKo('fiche') ? '<p class="error">Adresse https:// seulement</p>' : ''}${i('video', 'Vidéo (https://…)')}${httpsKo('video') ? '<p class="error">Adresse https:// seulement</p>' : ''}
      <span class="rf-lab">Profils (plans de formation)</span><div class="rf-pills">${Object.keys(PROFIL_NOM).map(p => `<button type="button" class="choice-pill rf-pill${E.profils.includes(p) ? ' is-on' : ''}" data-rg="fmProfil" data-v="${p}" aria-pressed="${E.profils.includes(p)}">${esc(PROFIL_NOM[p])}</button>`).join('')}</div>
      ${ecart ? `<div class="rf-sug"><button type="button" class="linklike" data-rg="fmSemis" title="Phase, bloquante, pôles, renouvellement et profils du guide de départ">Valeurs de départ</button></div>` : ''}
      <div class="rf-actions"><button type="button" class="btn btn--primary" data-rg="fmSave" data-rg-save="it"${!E._mod || G.occupe ? ' disabled' : ''}>Enregistrer</button>
        ${E._mod ? '<button type="button" class="btn btn--ghost" data-rg="fmAnnuler">Annuler</button>' : ''}<span class="rf-sp"></span>
        <button type="button" class="btn btn--sm btn--danger" data-rg="fmSuppr">Retirer le point</button></div>
      ${it.majPar ? `<p class="muted fm-small">${esc(it.majPar)}${it.majLe ? ' · ' + esc(dth(it.majLe)) : ''}</p>` : ''}</section>`;
  }
  function cartePoles(R) {
    const P = G.poles;
    return `<section class="card rf-gpoles"><div class="rf-tete"><h2>Les 5 pôles</h2><span class="muted fm-small">notés de 1 à 5 sur les formations qui le demandent</span></div>
      <div class="rf-gpolesg"><span></span><span class="rf-lab">Français</span><span class="rf-lab">Néerlandais</span>
      ${P.fr.map((v, i) => `<span class="muted">${i + 1}.</span><input class="rf-in" data-rgin="poles|fr|${i}" value="${esc(v)}"><input class="rf-in" data-rgin="poles|nl|${i}" value="${esc(P.nl[i])}">`).join('')}</div>
      <div class="rf-actions"><label class="field">Repère du réseau <select data-rgch="poles|repere">${(REPERES.includes(P.repere) ? REPERES : REPERES.concat([P.repere])).map(v => `<option value="${v}"${v === P.repere ? ' selected' : ''}>${v ? virg(v) + ' / 5' : 'aucun'}</option>`).join('')}</select></label>
        <span class="rf-sp"></span><button type="button" class="btn" data-rg="polesSave"${G.occupe ? ' disabled' : ''}>Enregistrer les pôles</button><button type="button" class="btn btn--ghost" data-rg="polesFermer">Fermer</button></div></section>`;
  }
  /** Le corps d'écriture d'un point : les seuls champs changés (le critère suit sa règle : réécrit = accepté). */
  function fmCorps(E) {
    const O = E._orig, out = {};
    ['fr', 'nl', 'dfr', 'dnl', 'fo', 'phase', 'bloq', 'poles', 'renouvellement', 'heures', 'fiche', 'fiche_nom', 'video', 'bloc'].forEach(k => { if (E[k] !== O[k]) { out[k] = E[k]; } });
    if ([...E.profils].sort().join() !== [...O.profils].sort().join()) { out.profils = E.profils; }
    const crFr = (E.critere_fr || '').trim(), crNl = (E.critere_nl || '').trim();
    if (crFr !== (O.critere_fr || '').trim()) { out.critere_fr = crFr; out.critere_nl = crNl; }
    else if (crNl !== (O.critere_nl || '').trim()) { if (O.critere_propose) { out.critere_fr = crFr; } out.critere_nl = crNl; }
    return out;
  }
  const refusTexte = x => x.message + (x.data && x.data.invalides ? ' — ' + (Array.isArray(x.data.invalides) ? x.data.invalides.join(' · ') : Object.keys(x.data.invalides).map(k => k + ' : ' + x.data.invalides[k].join(', ')).join(' · ')) : '');
  function quitterItem() { return !(G.fmEd && G.fmEd._mod) || confirm('Le point en cours a des modifications non enregistrées. Les abandonner ?'); }

  /** Les gestes des deux guides ([data-rg]). */
  async function gesteGuides(d) {
    const R = D.ref;
    switch (d.rg) {
      case 'poLang': G.poLang = d.v; return C.rendre(true);
      case 'poSel': G.poSel = d.v; return C.rendre(true);
      case 'poSecMv': deplacer(G.po.sections, +d.i, +d.i + (+d.d)); poModif(); return C.rendre(true);
      case 'poSecAjout': { const s = { _id: 'n:' + (++G.n), cle: '', type: 'points', fr: 'Nouvelle section', nl: '', intro_fr: '', intro_nl: '', points: [] }; G.po.sections.push(s); G.poSel = s._id; poModif(); return C.rendre(true); }
      case 'poSecSuppr': { const s = poSection(); if (!s || !confirm(`Supprimer la section « ${s.fr} » et ses ${s.points.length} point(s) ?`)) { return; } G.po.sections = G.po.sections.filter(x => x !== s); G.poSel = G.po.sections[0] ? G.po.sections[0]._id : ''; poModif(); return C.rendre(true); }
      case 'poPtMv': deplacer(poSection().points, +d.i, +d.i + (+d.d)); poModif(); return C.rendre(true);
      case 'poPtSuppr': poSection().points.splice(+d.i, 1); poModif(); return C.rendre(true);
      case 'poPtAjout': { const s = poSection(); s.points.push({ _id: 'n:' + (++G.n), cle: '', fr: '', nl: '', qui: 'franchise', j: s.type === 'contacts' ? null : 30, preuve_fr: '', preuve_nl: '' }); poModif(); return C.rendre(true); }
      case 'poAnnuler': G.po = null; G.poErr = null; return C.rendre(true);
      case 'poSave': {
        G.occupe = true; C.rendre(true);
        try {
          const r = await C.appel('PUT', '/formation/ref/preouverture', { sections: poCorps() });
          const i = Math.max(0, G.po.sections.findIndex(x => x._id === G.poSel));
          D.ref.preouverture.sections = r.sections; G.po = null; poModele(); G.poSel = (G.po.sections[i] || G.po.sections[0] || {})._id || '';
          toast('Guide pré-ouverture enregistré'); chargerRef(true);
        } catch (x) { G.poErr = 'Enregistrement refusé : ' + refusTexte(x); }
        G.occupe = false; return C.rendre(true);
      }
      case 'fmBloc': if (!quitterItem()) { return; } G.fmEd = null; G.fmSel = ''; G.blocEd = null; G.ajout = null; C.S.f.rgq = ''; location.hash = '#/rfform/' + d.v; return;
      case 'fmSel': if (G.fmEd && G.fmEd.id === d.v) { return; } if (!quitterItem()) { return; } G.fmEd = null; G.fmSel = d.v; location.hash = '#/rfform/' + d.n + '/' + encodeURIComponent(d.v); return;
      case 'fmPhase': fmEdition0().phase = d.v; return fmModif(true);
      case 'fmBloq': { const E = fmEdition0(); E.bloq = !E.bloq; return fmModif(true); }
      case 'fmPoles': { const E = fmEdition0(); E.poles = !E.poles; return fmModif(true); }
      case 'fmProfil': { const E = fmEdition0(), l = E.profils, k = l.indexOf(d.v); if (k >= 0) { l.splice(k, 1); } else { l.push(d.v); } return fmModif(true); }
      case 'fmSemis': { const E = fmEdition0(), s = R && itemDe(E.id) && itemDe(E.id).semis; if (s) { Object.assign(E, { phase: s.phase, bloq: s.bloq, poles: s.poles, renouvellement: s.renouvellement, profils: s.profils.slice() }); } return fmModif(true); }
      case 'fmAnnuler': G.fmEd = null; G.fmErr = null; return C.rendre(true);
      case 'fmSave': {
        const E = G.fmEd; if (!E) { return; }
        const corps = fmCorps(E);
        if (!Object.keys(corps).length) { E._mod = false; return C.rendre(true); }
        return ecrireItem(E.id, corps, 'Point enregistré', true);
      }
      case 'fmAccepter': return ecrireItem(G.fmEd.id, { accepter: true }, 'Proposition acceptée', true);
      case 'fmProposition': if (!confirm('Revenir au critère proposé ? Le critère réécrit est retiré.')) { return; } return ecrireItem(G.fmEd.id, { critere_fr: '' }, 'Retour à la proposition', true);
      case 'fmSuppr': {
        const E = G.fmEd; if (!E || !confirm(`Retirer « ${E._orig.fr} » (${E.id}) du bloc ${E._orig.bloc} ? Les validations déjà faites dans le CRM le gardent.`)) { return; }
        G.occupe = true; C.rendre(true);
        try { await C.appel('DELETE', '/formation/ref/items/' + encodeURIComponent(E.id)); toast('Point retiré'); G.fmEd = null; G.fmSel = ''; await relireRef(); location.hash = '#/rfform/' + E._orig.bloc; }
        catch (x) { G.fmErr = 'Retrait refusé : ' + refusTexte(x); }
        G.occupe = false; return C.rendre(true);
      }
      case 'fmAccepterTout': {
        const b = blocDe(R, G.fmN);
        if (!confirm(`Accepter les ${nbProp(b)} critères proposés du bloc ${b.n} ? Ils restent modifiables.`)) { return; }
        G.occupe = true; C.rendre(true);
        try { const r = await C.appel('POST', '/formation/ref/blocs/' + b.n + '/accepter', {}); toast(r.acceptes + ' critère(s) accepté(s)'); G.fmEd = null; await relireRef(); }
        catch (x) { G.fmErr = refusTexte(x); }
        G.occupe = false; return C.rendre(true);
      }
      case 'fmMv': {
        const b = blocDe(R, G.fmN), ids = b.items.map(it => it.id);
        deplacer(ids, +d.i, +d.i + (+d.d));
        return ecrireOrdre(b.n, ids);
      }
      case 'blocEd': { const b = blocDe(R, G.fmN); G.blocEd = { n: b.n, fr: b.fr, nl: b.nl, role: b.role, role_nl: b.role_nl, fo: b.fo, heures: virg(b.heures) }; return C.rendre(true); }
      case 'blocAnnuler': G.blocEd = null; return C.rendre(true);
      case 'blocSave': {
        const E = G.blocEd; G.occupe = true; C.rendre(true);
        try { await C.appel('PUT', '/formation/ref/blocs/' + E.n, { fr: E.fr, nl: E.nl, role: E.role, role_nl: E.role_nl, fo: E.fo, heures: E.heures }); toast('Bloc ' + E.n + ' enregistré'); G.blocEd = null; await relireRef(); }
        catch (x) { G.fmErr = 'Bloc refusé : ' + refusTexte(x); }
        G.occupe = false; return C.rendre(true);
      }
      case 'ajoutOuvrir': G.ajout = { n: G.fmN, id: '', fr: '' }; return C.rendre(true);
      case 'ajoutAnnuler': G.ajout = null; return C.rendre(true);
      case 'ajoutSave': {
        const A = G.ajout; G.occupe = true; C.rendre(true);
        try {
          const r = await C.appel('POST', '/formation/ref/blocs/' + A.n + '/items', { id: A.id.trim(), fr: A.fr.trim() });
          toast('Point ajouté'); G.ajout = null; G.fmEd = null; G.fmSel = r.item.id; await relireRef(); location.hash = '#/rfform/' + A.n + '/' + encodeURIComponent(r.item.id);
        } catch (x) { G.fmErr = 'Ajout refusé : ' + refusTexte(x); }
        G.occupe = false; return C.rendre(true);
      }
      case 'polesOuvrir': G.poles = G.poles ? null : { fr: R.poles.fr.slice(), nl: R.poles.nl.slice(), repere: +R.poles.repere }; return C.rendre(true);
      case 'polesFermer': G.poles = null; return C.rendre(true);
      case 'polesSave': {
        const P = G.poles; G.occupe = true; C.rendre(true);
        try { const r = await C.appel('PUT', '/formation/ref/poles', { fr: P.fr, nl: P.nl, repere: P.repere }); D.ref.poles = r.poles; toast('Pôles enregistrés'); G.poles = null; chargerRef(true); }
        catch (x) { G.fmErr = 'Pôles refusés : ' + refusTexte(x); }
        G.occupe = false; return C.rendre(true);
      }
    }
  }
  function itemDe(id) { for (const b of D.ref.blocs) { const it = b.items.find(x => x.id === id); if (it) { return it; } } return null; }
  function fmEdition0() { if (!G.fmEd) { const it = itemDe(G.fmSel); const b = D.ref.blocs.find(x => x.items.includes(it)); fmEdition(it, b); } return G.fmEd; }
  function fmModif(redessiner) { G.fmEd._mod = true; G.fmErr = null; if (redessiner) { C.rendre(true); } else { marquerModifie('[data-rg-save="it"]'); } }
  /** Relit le référentiel et attend la réponse (après une écriture : l'écran repart des données du serveur). */
  function relireRef() {
    return C.lire('/formation/ref?detail=1', 30000).then(d => { D.ref = d; D.refErr = null; }, er => { D.refErr = er.message; });
  }
  async function ecrireItem(id, corps, msg, garder) {
    G.occupe = true; C.rendre(true);
    try {
      const r = await C.appel('PUT', '/formation/ref/items/' + encodeURIComponent(id), corps);
      toast(msg); G.fmEd = null; await relireRef();
      if (garder && corps.bloc) { location.hash = '#/rfform/' + corps.bloc + '/' + encodeURIComponent(r.item.id); }
    } catch (x) { G.fmErr = 'Enregistrement refusé : ' + refusTexte(x); }
    G.occupe = false; C.rendre(true);
  }
  async function ecrireOrdre(n, ids) {
    G.occupe = true;
    try { await C.appel('PUT', '/formation/ref/blocs/' + n, { ordre: ids }); await relireRef(); }
    catch (x) { G.fmErr = 'Ordre refusé : ' + refusTexte(x); }
    G.occupe = false; C.rendre(true);
  }

  /** Les écouteurs des deux guides : gestes, saisie (sans redessiner), choix, glisser-déposer. */
  function brancherGuides(root) {
    root.addEventListener('click', ev => {
      const b = ev.target.closest('[data-rg]');
      if (!b || b.disabled) { return; }
      // Un clic sur un champ d'une ligne cliquable n'est pas un choix de ligne.
      if (b.tagName === 'TR' && ev.target.closest('input, select, textarea, button:not([data-rg="fmSel"])')) { return; }
      gesteGuides(b.dataset);
    });
    root.addEventListener('input', ev => {
      const t = ev.target, k = t.getAttribute && t.getAttribute('data-rgin');
      if (!k) { return; }
      const p = k.split('|');
      if (p[0] === 'po' && G.po) {
        const s = G.po.sections.find(x => x._id === p[1]); if (!s) { return; }
        const o = p[2] ? s.points.find(x => x._id === p[2]) : s; if (!o) { return; }
        if (p[3] === 'j') { const v = t.value.trim(); o.j = v === '' ? null : (/^-?\d+$/.test(v) ? +v : v); }
        else { o[poCle(p[3], G.poLang)] = t.value; }
        poModif(); marquerModifie('[data-rg-save="po"]'); return;
      }
      if (p[0] === 'it' && G.fmEd) { G.fmEd[p[1]] = t.value; fmModif(false); return; }
      if (p[0] === 'bloc' && G.blocEd) { G.blocEd[p[1]] = t.value; return; }
      if (p[0] === 'ajout' && G.ajout) { G.ajout[p[1]] = t.value; return; }
      if (p[0] === 'poles' && G.poles) { G.poles[p[1]][+p[2]] = t.value; }
    });
    root.addEventListener('change', ev => {
      const t = ev.target, k = t.getAttribute && t.getAttribute('data-rgch');
      if (!k) { return; }
      const p = k.split('|');
      if (p[0] === 'po' && G.po) {
        const s = G.po.sections.find(x => x._id === p[1]); if (!s) { return; }
        const o = p[2] ? s.points.find(x => x._id === p[2]) : s; if (!o) { return; }
        o[p[3]] = t.value; poModif(); return C.rendre(true);
      }
      if (p[0] === 'it') { fmEdition0()[p[1]] = +t.value; return fmModif(true); }
      if (p[0] === 'poles' && G.poles) { G.poles.repere = +t.value; }
    });
    // Glisser pour ordonner : sections et points du guide pré-ouverture, points d'un bloc.
    root.addEventListener('dragstart', ev => {
      const r = ev.target.closest && ev.target.closest('[data-rgdrag]');
      if (!r) { return; }
      G.glisse = { type: r.dataset.rgdrag, i: +r.dataset.i };
      try { ev.dataTransfer.effectAllowed = 'move'; ev.dataTransfer.setData('text/plain', r.dataset.rgdrag); } catch (x) { /* */ }
      r.classList.add('is-glisse');
    });
    root.addEventListener('dragover', ev => {
      const r = ev.target.closest && ev.target.closest('[data-rgdrag]');
      if (!r || !G.glisse || r.dataset.rgdrag !== G.glisse.type) { return; }
      ev.preventDefault();
      root.querySelectorAll('.rf-gdrag.is-over').forEach(x => { if (x !== r) { x.classList.remove('is-over'); } });
      r.classList.add('is-over');
    });
    root.addEventListener('dragend', () => { G.glisse = null; root.querySelectorAll('.is-over, .is-glisse').forEach(x => x.classList.remove('is-over', 'is-glisse')); });
    root.addEventListener('drop', ev => {
      const r = ev.target.closest && ev.target.closest('[data-rgdrag]');
      const g = G.glisse;
      if (!r || !g || r.dataset.rgdrag !== g.type) { return; }
      ev.preventDefault();
      const vers = +r.dataset.i;
      G.glisse = null;
      if (vers === g.i) { return C.rendre(true); }
      if (g.type === 'sec') { deplacer(G.po.sections, g.i, vers); poModif(); return C.rendre(true); }
      if (g.type === 'pt') { deplacer(poSection().points, g.i, vers); poModif(); return C.rendre(true); }
      if (g.type === 'it') { const b = blocDe(D.ref, G.fmN), ids = b.items.map(it => it.id); deplacer(ids, g.i, vers); ecrireOrdre(b.n, ids); }
    });
  }

  /* ═══ Lectures ═══ */
  function chargerListe(forcer) {
    /* Une lecture ratée n'est pas relancée à chaque rendu (elle tournait en boucle) : « Réessayer » la force. */
    if ((D.liste && !forcer) || D.charge || (D.erreur && !forcer)) { return; }
    D.charge = true;
    C.lire('/formation/sop/liste', 30000).then(d => { D.liste = d.sops; D.totaux = d.totaux; D.dossiers = d.dossiers; D.erreur = null; D.charge = false; C.rendre(true); },
      er => { D.erreur = er.message; D.charge = false; C.rendre(true); });
  }
  function chargerMagasins() {
    if (D.magasins) { return; }
    D.magasins = [];
    C.lire('/stores', 30000).then(l => { D.magasins = (Array.isArray(l) ? l : []).filter(m => m.status !== 'Fermé').map(m => ({ id: String(m.id), nom: m.nom })); C.rendre(true); }, () => {});
  }
  function chargerFiche(id, forcer) {
    if (D.fiche[id] && !forcer) { return; }
    if (!forcer) { D.fiche[id] = null; }
    C.lire('/formation/sop/fiche/' + encodeURIComponent(id) + (D.shop ? '?shop=' + encodeURIComponent(D.shop) : ''), 30000)
      .then(d => { D.fiche[id] = d; C.rendre(true); }, er => { D.fiche[id] = { error: er.message }; C.rendre(true); });
  }
  function chargerComp(forcer, rafraichir) {
    if ((D.comp && !forcer) || D.compCharge) { return; }
    D.compCharge = true;
    const q = [];
    if (D.shop) { q.push('shop=' + encodeURIComponent(D.shop)); }
    if (rafraichir) { q.push('rafraichir=1'); }
    C.lire('/formation/sop/competences' + (q.length ? '?' + q.join('&') : ''), 120000).then(d => { D.comp = d; D.compCharge = false; C.rendre(true); }, er => { D.compCharge = false; toast(er.message); });
    if (D.shop) { C.lire('/formation/sop/fil?shop=' + encodeURIComponent(D.shop), 30000).then(d => { D.fil = d.fil; C.rendre(true); }, () => {}); } else { D.fil = null; }
  }
  function chargerEditeur(id) {
    if (D.edMeta && D.edMeta.id === id && D.ed) { return; }
    D.ed = null; D.edMeta = { id, titre: { fr: id } };
    C.lire('/formation/sop/fiche/' + encodeURIComponent(id), 30000).then(f => {
      D.ed = JSON.parse(JSON.stringify(f.contenu)); D.edErr = null;
      D.ed.sections.forEach(sec => sec.etapes.forEach(e => { if (!e.titre) { e.titre = { fr: '', nl: '' }; } }));
      D.edMeta = Object.assign({}, f, { note: '', revalider: false, tradApres: { fr: f.trad.fr, nl: f.trad.nl } });
      C.rendre(true);
    }, er => { D.edErr = er.message; C.rendre(true); });
  }

  /** Les lectures de l'écran affiché (appelé par apres() de formation.js). */
  function apres(e, a) {
    if (e === 'sop' || e === 'sopred' || e === 'manuel') { chargerListe(false); }
    if (e === 'sop' || e === 'sopcomp') { chargerMagasins(); }
    if (e === 'sop' && a[0] && a[1] === 'editer') { chargerEditeur(a[0]); }
    else if (e === 'sop' && a[0] && D.fiche[a[0]] === undefined) { chargerFiche(a[0]); }
    if (e === 'sopcomp' || e === 'sopred') { chargerComp(false); }
    if (e === 'rfclasseur' || e === 'rfphase' || e === 'rfposte' || e === 'sop' || e === 'sopcomp') { chargerRef(false); }
    if (e === 'sopcomp' && a[0] !== 'magasins') { chargerCor(false); }
    if (e === 'rfpre' || e === 'rfform') { chargerRef(false); }   // les deux guides (lot 4b)
  }

  const ECRANS = {
    sop: () => { const a = C.S.route.a; return a[0] ? (a[1] === 'editer' ? ecranEditer(a[0]) : ecranFiche(a[0])) : ecranListe(); },
    sopcomp: ecranCompetencesRef,
    sopred: ecranRedaction,
    rfclasseur: ecranClasseur,
    rfphase: ecranParPhase,
    rfposte: ecranParPoste,
    rfpre: ecranPreouverture,
    rfform: ecranFormations
  };

  /* ═══ Gestes (écouteurs propres aux SOP, sur #root) ═══ */
  async function patch(id, corps, msg) {
    try { await C.appel('PATCH', '/formation/sop/fiche/' + encodeURIComponent(id), corps); toast(msg || 'Enregistré'); D.fiche[id] = undefined; chargerListe(true); if (C.S.route.a[0] === id) { chargerFiche(id, true); } chargerCompSiOuvert(); }
    catch (e) { toast(e.message); }
  }
  function chargerCompSiOuvert() { if (D.comp) { chargerComp(true); } }
  async function personne(eid, corps) {
    try { await C.appel('PUT', '/formation/sop/equipe/' + encodeURIComponent(D.shop) + '/' + eid, corps); toast('Enregistré'); chargerComp(true); }
    catch (e) { toast(e.message); }
  }
  const vider = o => { const r = JSON.parse(JSON.stringify(o)); (function n(x) { if (x && typeof x === 'object') { Object.keys(x).forEach(k => { if (typeof x[k] === 'string') { x[k] = ''; } else { n(x[k]); } }); } })(r); return r; };

  function brancher(root) {
    root.addEventListener('click', async ev => {
      const b = ev.target.closest('[data-sa]');
      if (!b) { return; }
      const d = b.dataset, E = D.ed;
      if (await gesteRef(d, b)) { return; }
      switch (d.sa) {
        case 'zoom': { const z = document.createElement('div'); z.className = 'sop-zoom'; z.innerHTML = `<img src="${esc(d.v)}" alt="">`; z.onclick = () => z.remove(); document.body.appendChild(z); return; }
        case 'filtre': D.f[d.k] = d.k === 'poste' ? d.v : d.v === 'true'; return C.rendre(true);
        case 'vue': D.vue = d.v; try { localStorage.setItem('fm.sop.vue', d.v); } catch (x) { /* */ } return C.rendre(true);
        case 'partie': return patch(d.id, { partie: d.v || null }, d.v ? 'Rangée dans ' + NOM_PARTIE[d.v] : 'Partie déduite');
        case 'trad': return patch(d.id, { trad: { [d.l]: d.v } }, d.l.toUpperCase() + (d.v === 'relue' ? ' relue' : ' à relire'));
        case 'archive':
          if (!d.v) { D.archive = null; return C.rendre(true); }
          try { D.archive = await C.lire('/formation/sop/fiche/' + encodeURIComponent(d.id) + '/versions/' + encodeURIComponent(d.v)); } catch (x) { toast(x.message); }
          return C.rendre(true);
        case 'relirePanel': D.comp = null; return chargerComp(true, true);
        case 'relireListe': D.erreur = null; return chargerListe(true);
        case 'nouv': D.nouv = { code: '', dossier: '01', type: 'SOP', poste: 'vente', fr: '', nl: '' }; return C.rendre(true);
        case 'annulerNouv': D.nouv = null; return C.rendre(true);
        case 'creer':
          try { const r = await C.appel('POST', '/formation/sop/fiche', { code: D.nouv.code, dossier: D.nouv.dossier, type: D.nouv.type, poste: D.nouv.poste, titre: { fr: D.nouv.fr, nl: D.nouv.nl } }); D.nouv = null; chargerListe(true); location.hash = '#/sop/' + r.id + '/editer'; }
          catch (x) { toast(x.message); }
          return;
        case 'secMove': { const i = +d.i, j = i + (+d.d); if (j >= 0 && j < E.sections.length) { [E.sections[i], E.sections[j]] = [E.sections[j], E.sections[i]]; } return C.rendre(true); }
        case 'secSuppr': if (confirm('Supprimer cette section et ses étapes ?')) { E.sections.splice(+d.i, 1); } return C.rendre(true);
        case 'secAjout': E.sections.push({ titre: { fr: '', nl: '' }, etapes: [] }); return C.rendre(true);
        case 'etAjout': { const sec = E.sections[+d.i]; sec.etapes.push({ numero: String(sec.etapes.length + 1), titre: { fr: '', nl: '' }, texte: { fr: '', nl: '' }, critique: false, photo: null }); return C.rendre(true); }
        case 'etMove': { const [si, ei] = d.i.split('.').map(Number); const l = E.sections[si].etapes, j = ei + (+d.d); if (j >= 0 && j < l.length) { [l[ei], l[j]] = [l[j], l[ei]]; } return C.rendre(true); }
        case 'etSuppr': { const [si, ei] = d.i.split('.').map(Number); E.sections[si].etapes.splice(ei, 1); return C.rendre(true); }
        case 'critAjout': E.points_critiques.push({ fr: '', nl: '' }); return C.rendre(true);
        case 'critSuppr': E.points_critiques.splice(+d.i, 1); return C.rendre(true);
        case 'quizAjout': E.quiz.push(E.quiz[0] ? Object.assign(vider(E.quiz[0]), { bonne: 0 }) : { question: { fr: '', nl: '' }, choix: { fr: ['', '', ''], nl: ['', '', ''] }, bonne: 0 }); return C.rendre(true);
        case 'quizSuppr': E.quiz.splice(+d.i, 1); return C.rendre(true);
        case 'publier': {
          D.occupe = true; D.edErr = null; C.rendre(true);
          try {
            const r = await C.appel('PUT', '/formation/sop/fiche/' + encodeURIComponent(D.edMeta.id), { contenu: E, note: D.edMeta.note, revalider: D.edMeta.revalider, trad: D.edMeta.tradApres });
            toast('Version ' + r.version + ' publiée' + (r.aRevoir ? ' · ' + r.aRevoir + ' personne(s) à revoir' : ''));
            const id = D.edMeta.id; D.occupe = false; D.ed = null; D.edMeta = null; D.fiche[id] = undefined; chargerListe(true); location.hash = '#/sop/' + id; return;
          } catch (x) { D.edErr = x.message; }
          D.occupe = false; return C.rendre(true);
        }
      }
    });
    root.addEventListener('input', ev => {
      const t = ev.target, k = t.getAttribute && t.getAttribute('data-sin');
      if (!k) { return; }
      const [obj, ...reste] = k.split('.');
      const p = reste.join('.');
      if (obj === 'ed' && D.ed) { setP(D.ed, p, p.endsWith('.photo') && t.value.trim() === '' ? null : t.value); t.classList.toggle('is-vide', !t.value); }
      else if (obj === 'edMeta' && D.edMeta) { D.edMeta[p] = t.value; }
      else if (obj === 'nouv' && D.nouv) { D.nouv[p] = t.value; }
      else if (obj === 'scEd' && D.scEd) { D.scEd[p] = t.value; }
    });
    root.addEventListener('change', ev => {
      const t = ev.target, k = t.getAttribute && t.getAttribute('data-sch');
      if (!k) { return; }
      const d = t.dataset;
      if (choixRef(k, t)) { return; }
      if (k === 'shop') { D.shop = t.value; try { localStorage.setItem('fm.sop.shop', D.shop); } catch (x) { /* */ } D.fiche = {}; D.comp = null; return C.rendre(true); }
      if (k.startsWith('ed.')) { setP(D.ed, k.slice(3), t.checked); return; }
      if (k.startsWith('nouv.')) { D.nouv[k.slice(5)] = t.value; return; }
      if (k === 'bonne') { D.ed.quiz[+d.i].bonne = +t.value; return; }
      if (k === 'reval') { D.edMeta.revalider = t.value === '1'; return; }
      if (k === 'edtrad') { D.edMeta.tradApres[d.l] = t.value; return; }
      if (k === 'statut') { return patch(d.id, { statut: t.value }, { publiee: 'Publiée', a_valider: 'À valider', brouillon: 'Brouillon' }[t.value]); }
      if (k === 'comp') {
        let v = t.value;
        if (v === '*') { const nom = prompt('Nom exact de la compétence du panel :'); if (!nom) { return C.rendre(true); } v = '|' + nom.trim(); }
        if (!v) { return patch(d.id, { competence: null }, 'Compétence retirée'); }
        const i = v.indexOf('|');
        return patch(d.id, { competence: { id: v.slice(0, i) || null, nom: v.slice(i + 1) } }, 'Compétence rattachée');
      }
      if (k === 'poste') { const p = D.comp.magasin.equipe.find(x => String(x.id) === d.id); const s = new Set(p.postes); t.checked ? s.add(t.value) : s.delete(t.value); return personne(d.id, { postes: [...s] }); }
      if (k === 'valideur') { return personne(d.id, { valideur: t.checked }); }
      if (k === 'langue') { return personne(d.id, { langue: t.value }); }
    });
  }

  return {
    /** Le groupe de la barre latérale et ses entrées : [clé, libellé] (Manuel du réseau, 06/11/2026). */
    groupe: 'Manuel du réseau',
    nav: [['rfclasseur', 'Classeur'], ['rfphase', 'Par phase'], ['rfposte', 'Par poste'], ['sop', 'Standards'], ['sopcomp', 'Correspondance'], ['sopred', 'Rédaction'], ['rfpre', 'Pré-ouverture'], ['rfform', 'Formations et compétences']],
    icones: {
      rfclasseur: 'M3 3h18v18H3zM3 9h18M3 15h18M9 3v18M15 3v18',
      rfphase: 'M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z',
      rfposte: 'M17 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M22 21v-2a4 4 0 0 0-3-3.9',
      sop: 'M9 11l3 3 8-8M4 4h11v4M4 4v16h16v-6',
      sopcomp: 'M12 2l3 6.5 7 .9-5 4.8 1.2 7L12 18l-6.2 3.2 1.2-7-5-4.8 7-.9z',
      sopred: 'M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z',
      rfpre: 'M4 4h16v16H4zM8 8h8M8 12h8M8 16h5',
      rfform: 'M22 10L12 5 2 10l10 5 10-5zM6 12v5c3 2 9 2 12 0v-5'
    },
    ecrans: ECRANS,
    /** formation.js fournit ses outils une fois, au démarrage. */
    init(ctx, root) { C = ctx; brancher(root); brancherGuides(root); },
    apres,
    sectionPartie,
    nbPartie
  };
})();

/* Manuel du réseau › Tablettes des magasins (lot 5 de la fusion, 06/11/2026 ; src/tablettes.php).
 *
 *   #/tablettes      UNE tablette par magasin, celle du Cockpit (sop/tablette.html) : le PIN du MAGASIN (6 chiffres)
 *                    ouvre l'appareil, le PIN de l'employé signe ensuite. Ici : l'adresse de la tablette, chaque
 *                    tablette (nom, boutique du panel, PIN, ⧉ copier adresse + PIN, ↻ nouveau PIN qui déconnecte ses
 *                    appareils, appareils connectés, archiver), le bilan de la reprise des magasins du CRM.
 *
 * Un bloc à part, branché sur l'objet de formation-sop.js (entrée du menu, icône, écran, lectures, gestes) sans toucher
 * à ses entrées ; styles dans sop/tablettes.css (chargée à la demande, classes du design system de ref.css).
 */
(function (FS) {
  'use strict';
  if (!FS) { return; }
  let C = null;
  // code : le code de gestion (lot 6), gardé dans l'onglet seulement (sessionStorage) ; refus : ce que la garde du serveur
  // a répondu (code attendu, refusé, essais, gestion fermée) ; saisie : ce qui est tapé dans le champ du code.
  const T = { liste: null, erreur: null, refus: null, charge: false, occupe: false, nom: '', shop: '', code: '', saisie: '' };
  const CLE_CODE = 'fm.tablettes.code';
  try { T.code = sessionStorage.getItem(CLE_CODE) || ''; } catch (e) { /* stockage indisponible : le code se retape */ }
  /** Le code de gestion, envoyé à chaque route de gestion (src/tablettes.php, tabGardeGestion). */
  const entetes = () => (T.code ? { 'X-Code-Gestion': T.code } : null);
  function garderCode(c) { T.code = c; try { if (c) { sessionStorage.setItem(CLE_CODE, c); } else { sessionStorage.removeItem(CLE_CODE); } } catch (e) { /* */ } }
  /** Un refus de la garde du code : l'écran redemande le code (oublié s'il était faux) ; vrai quand c'en était un. */
  function refusGestion(x) {
    const c = x && x.data && x.data.code;
    if (!['gestion_fermee', 'code_attendu', 'code_gestion', 'essais', 'suspendu'].includes(c)) { return false; }
    garderCode('');   // à retaper (faux, trop d'essais, gestion fermée) : jamais de relecture en boucle avec un code refusé
    T.liste = null; T.refus = { code: c, message: x.message };
    return true;
  }
  const esc = v => (C ? C.esc(v) : String(v == null ? '' : v));
  const jour = iso => iso ? new Date(iso).toLocaleDateString('fr-BE', { day: '2-digit', month: '2-digit', year: 'numeric' }) : '';
  const heure = iso => iso ? new Date(iso).toLocaleString('fr-BE', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '';
  const pinLisible = p => String(p || '').replace(/^(\d{3})(\d{3})$/, '$1 $2');
  /** L'adresse de la tablette, à côté du module (formation/sop/tablette.html) ; ?shop= pré-choisit le magasin. */
  const urlTablette = shop => new URL('sop/tablette.html' + (shop ? '?shop=' + encodeURIComponent(shop) : ''), location.href).href;
  function toast(m) { const el = document.createElement('div'); el.className = 'sop-toast'; el.textContent = m; document.body.appendChild(el); setTimeout(() => el.remove(), 3000); }
  function feuille() {
    if (document.getElementById('tbg-css')) { return; }
    const l = document.createElement('link'); l.id = 'tbg-css'; l.rel = 'stylesheet'; l.href = 'sop/tablettes.css'; document.head.appendChild(l);
  }
  function copier(texte, ok) {
    const repli = () => {
      const z = document.createElement('textarea'); z.value = texte; z.setAttribute('readonly', ''); z.style.position = 'fixed'; z.style.opacity = '0';
      document.body.appendChild(z); z.select();
      let r = false; try { r = document.execCommand('copy'); } catch (e) { r = false; }
      z.remove(); toast(r ? ok : 'Copie impossible : sélectionner le texte à la main.');
    };
    if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(texte).then(() => toast(ok), repli); } else { repli(); }
  }

  /** La liste ; sans code, un premier appel sans code dit seulement si la gestion est ouverte (code attendu) ou fermée. */
  function charger(forcer) {
    // Une lecture ratée ou refusée n'est pas relancée à chaque rendu : « Réessayer » ou le code tapé la relancent.
    if ((T.liste && !forcer) || T.charge || (T.refus && !T.code && !forcer) || (T.erreur && !forcer)) { return; }
    T.charge = true;
    C.appel('GET', '/formation/tablettes', null, 30000, entetes()).then(d => { T.liste = d; T.erreur = null; T.refus = null; T.charge = false; C.rendre(true); },
      er => { T.charge = false; if (!refusGestion(er)) { T.erreur = er.message; } C.rendre(true); });
  }
  async function ecrire(methode, chemin, corps, msg) {
    T.occupe = true; C.rendre(true);
    try { const r = await C.appel(methode, chemin, corps || {}, 120000, entetes()); T.liste = r; if (msg) { toast(typeof msg === 'function' ? msg(r) : msg); } }
    catch (x) { if (!refusGestion(x)) { toast(x.message); } }
    T.occupe = false; C.rendre(true);
  }
  /** Le code tapé : gardé dans l'onglet, puis la liste relue avec lui. */
  function ouvrirGestion() {
    const v = T.saisie.trim();
    if (!v) { return toast('Taper le code de gestion'); }
    garderCode(v); T.saisie = ''; T.refus = null; T.liste = null; T.erreur = null;
    charger(true); C.rendre(true);
  }

  /* ── L'écran ── */
  function bilanReprise(L) {
    const b = L.reprise;
    if (!b || !b.reprises || !b.reprises.length) { return ''; }
    const gardes = b.reprises.filter(r => r.pinGarde), neufs = b.reprises.filter(r => !r.pinGarde);
    return `<section class="card tbg-bilan"><div class="tbg-bilan__t"><h2>Reprise du Manuel du CRM</h2><span class="muted fm-small">le ${esc(jour(b.le))}</span></div>
      <p class="tbg-ligne">${b.reprises.length} tablette(s) reprise(s) du CRM · <span class="badge badge--ok">PIN gardé</span> ${gardes.length}</p>
      ${neufs.map(r => `<p class="tbg-ligne"><span class="tag-warn">Nouveau PIN</span> ${esc(r.nom)} <span class="muted">· ${esc(r.raison || '')} — à communiquer au magasin</span></p>`).join('')}</section>`;
  }
  function carteMagasin(m, boutiques) {
    const opts = `<option value="">Aucune boutique…</option>${boutiques.map(b => `<option value="${esc(b.id)}"${b.id === m.shopId ? ' selected' : ''}>${esc(b.nom)}</option>`).join('')}`
      + (m.shopId && !boutiques.some(b => b.id === m.shopId) ? `<option value="${esc(m.shopId)}" selected>Boutique ${esc(m.shopId)} (fermée ou inconnue)</option>` : '');
    const tags = [m.reprisCrm ? '<span class="chip chip--neutral">Repris du CRM</span>' : '', !m.shopId ? '<span class="tag-warn" title="La tablette s’ouvre mais ne montre rien : choisir sa boutique du panel">Sans boutique</span>' : '',
      m.memeBoutique ? '<span class="tag-warn" title="Une autre tablette active a la même boutique">Même boutique qu’une autre tablette</span>' : ''].join('');
    const app = m.appareils.length ? m.appareils.map(a => `<div class="tbg-app"><span class="tbg-app__n">${esc(a.appareil)}</span><span class="tbg-app__d">ouvert le ${esc(jour(a.creeLe))}${a.vuLe ? ' · vu le ' + esc(heure(a.vuLe)) : ''}</span>
      <button type="button" class="btn btn--danger btn--sm" data-tb="deco" data-id="${a.id}" ${T.occupe ? 'disabled' : ''}>Déconnecter</button></div>`).join('')
      : '<p class="muted tbg-vide">Aucun appareil : taper le PIN sur la tablette du magasin.</p>';
    return `<section class="card tbg-mag">
      <div class="tbg-mag__id">
        <label class="tbg-lab" for="tbg-nom-${m.id}">Tablette</label>
        <input id="tbg-nom-${m.id}" class="tbg-nom" value="${esc(m.nom)}" data-tbch="nom" data-id="${m.id}" maxlength="120" ${T.occupe ? 'disabled' : ''}>
        <label class="tbg-lab" for="tbg-shop-${m.id}">Boutique du panel</label>
        <select id="tbg-shop-${m.id}" class="tbg-sel" data-tbch="shop" data-id="${m.id}" ${T.occupe ? 'disabled' : ''}>${opts}</select>
        <div class="tbg-tags">${tags}</div>
        <span class="sub">Créée le ${esc(jour(m.creeLe))}${m.creePar ? ' · ' + esc(m.creePar) : ''}${m.shopId ? ' · ' + m.aujourdhui + ' geste(s) sur la tablette aujourd’hui' : ''}</span>
      </div>
      <div class="tbg-mag__pin">
        <span class="tbg-lab">PIN du magasin</span>
        <span class="tbg-pin">${esc(pinLisible(m.pin))}</span>
        <span class="sub">${m.pinLe ? 'depuis le ' + esc(jour(m.pinLe)) : ''}</span>
        <div class="tbg-actions"><button type="button" class="btn btn--sm" data-tb="copier" data-id="${m.id}">⧉ Copier adresse + PIN</button>
          <button type="button" class="btn btn--sm" data-tb="pin" data-id="${m.id}" ${T.occupe ? 'disabled' : ''}>↻ Nouveau PIN</button></div>
      </div>
      <div class="tbg-mag__apps"><span class="tbg-lab">Appareils connectés · ${m.appareils.length}</span>${app}</div>
      <div class="tbg-mag__fin"><button type="button" class="btn btn--danger btn--sm" data-tb="archiver" data-id="${m.id}" ${T.occupe ? 'disabled' : ''}>Archiver</button></div>
    </section>`;
  }
  /** Le champ du code de gestion : la garde du serveur a refusé (code attendu, faux, trop d'essais) ou la gestion est fermée. */
  function carteCode() {
    const R = T.refus || {};
    const ferme = R.code === 'gestion_fermee';
    const msg = R.code === 'code_gestion' ? 'Code de gestion refusé.' : (R.code === 'essais' || R.code === 'suspendu' || ferme) ? R.message : '';
    return `<section class="card tbg-code"><h2>Code de gestion</h2>
      <p class="muted tbg-code__txt">La liste des tablettes porte le PIN de chaque magasin : la gérer demande le code de gestion du réseau. Il est gardé dans cet onglet jusqu’à sa fermeture.</p>
      ${ferme ? '' : `<form class="toolbar tbg-code__f" data-tbform="code"><label class="tbg-lab" for="tbg-code">Code de gestion</label>
        <input id="tbg-code" class="tbg-nouv" type="password" autocomplete="current-password" data-tbin="code" value="${esc(T.saisie)}" ${T.charge ? 'disabled' : ''}>
        <button type="submit" class="btn btn--primary" ${T.charge ? 'disabled' : ''}>Ouvrir la gestion</button></form>`}
      ${msg ? `<p class="error" role="alert">${esc(msg)}</p>` : ''}</section>`;
  }
  function ecran() {
    feuille();
    const K = FS.groupe || 'Manuel du réseau';
    if (C.S.tablette) { return { titre: 'Tablettes', kicker: K, corps: '<p class="muted">La gestion des tablettes ne se fait pas en mode tablette.</p>' }; }
    const L = T.liste;
    if (!L && T.refus) { return { titre: 'Tablettes', kicker: K, corps: carteCode() }; }
    if (!L) { return { titre: 'Tablettes', kicker: K, corps: T.erreur ? `<p class="error">${esc(T.erreur)}</p><p><button type="button" class="btn btn--sm" data-tb="relire">Réessayer</button></p>` : '<p class="muted">Lecture des tablettes…</p>' }; }
    const actifs = L.magasins.filter(m => !m.archive), archives = L.magasins.filter(m => m.archive);
    const url = urlTablette('');
    let h = `<section class="card tbg-tete">
      <div class="tbg-adr"><span class="tbg-lab">Adresse de la tablette</span><a class="tbg-url" href="${esc(url)}" target="_blank" rel="noopener">${esc(url)}</a>
        <span class="sub">Une seule tablette par magasin : le PIN du magasin ouvre l’appareil une fois, puis chaque employé s’identifie avec son PIN du panel. L’onglet SOP de l’app Cuisine y mène.</span></div>
      <div class="tbg-adr__act"><button type="button" class="btn btn--sm" data-tb="copierUrl">⧉ Copier l’adresse</button>${L.crmDisponible ? `<button type="button" class="btn btn--sm" data-tb="reprise" ${T.occupe ? 'disabled' : ''} title="Les magasins du Manuel du CRM qui ne sont pas encore ici (même PIN quand il est libre)">Reprendre les magasins du CRM</button>` : ''}
        <button type="button" class="btn btn--sm btn--ghost" data-tb="oublier" title="Le code de gestion est oublié dans cet onglet : il faudra le retaper">Fermer la gestion</button></div>
      <div class="toolbar tbg-ajout"><input class="tbg-nouv" placeholder="Nom de la tablette (ex. L’Atelier By Corbais)" maxlength="120" data-tbin="nom" value="${esc(T.nom)}">
        <select class="tbg-sel" data-tbin="shop"><option value="">Boutique du panel…</option>${L.boutiques.map(b => `<option value="${esc(b.id)}"${b.id === T.shop ? ' selected' : ''}>${esc(b.nom)}</option>`).join('')}</select>
        <button type="button" class="btn btn--primary" data-tb="creer" ${T.occupe ? 'disabled' : ''}>＋ Tablette de magasin</button></div>
    </section>`;
    h += bilanReprise(L);
    h += `<div class="section-label tbg-sl"><span>Tablettes actives · ${actifs.length}</span><span class="tbg-sl__aside">PIN du magasin : ${L.essais.ip15min} erreurs par appareil en 15 min, ${L.essais.heure} par heure et ${L.essais.jour} par jour pour tout le réseau</span></div>`;
    h += actifs.length ? `<div class="tbg-liste">${actifs.map(m => carteMagasin(m, L.boutiques)).join('')}</div>` : '<p class="muted">Aucune tablette active.</p>';
    if (archives.length) {
      h += `<div class="section-label">Archivées · ${archives.length}</div><div class="tbg-archives">${archives.map(m => `<span class="tbg-arch"><span>${esc(m.nom)}</span>${m.boutique ? `<span class="muted">· ${esc(m.boutique)}</span>` : ''}<button type="button" class="btn btn--sm" data-tb="reactiver" data-id="${m.id}" title="Réactiver : le PIN reste le même" ${T.occupe ? 'disabled' : ''}>↺ Réactiver</button></span>`).join('')}</div>`;
    }
    return { titre: 'Tablettes', kicker: K, corps: h, large: true };
  }

  /* ── Gestes ── */
  function brancher(root) {
    root.addEventListener('click', ev => {
      const b = ev.target.closest('[data-tb]');
      if (b && b.dataset.tb === 'relire') { T.erreur = null; charger(true); return C.rendre(true); }
      if (!b || !T.liste) { return; }
      const d = b.dataset, id = +d.id, m = T.liste.magasins.find(x => x.id === id);
      switch (d.tb) {
        case 'oublier': garderCode(''); T.liste = null; T.refus = { code: 'code_attendu' }; return C.rendre(true);
        case 'copierUrl': return copier(urlTablette(''), 'Adresse copiée');
        case 'copier': return copier(`Tablette du magasin — ${m.nom}\n${urlTablette(m.shopId)}\nPIN du magasin : ${pinLisible(m.pin)}`, 'Adresse et PIN copiés');
        case 'creer': {
          const nom = T.nom.trim();
          if (!nom) { return toast('Donner un nom à la tablette'); }
          return ecrire('POST', '/formation/tablettes', { nom, shopId: T.shop || null }, () => { T.nom = ''; T.shop = ''; return 'Tablette créée, avec son PIN'; });
        }
        case 'pin':
          if (!confirm(`Nouveau PIN pour « ${m.nom} » ? Ses ${m.appareils.length} appareil(s) connecté(s) seront déconnectés : il faudra taper le nouveau PIN sur la tablette.`)) { return; }
          return ecrire('POST', '/formation/tablettes/' + id + '/pin', {}, r => 'Nouveau PIN · ' + (r.deconnectes || 0) + ' appareil(s) déconnecté(s)');
        case 'deco':
          if (!confirm('Déconnecter cet appareil ? Il devra retaper le PIN du magasin.')) { return; }
          return ecrire('DELETE', '/formation/tablettes/appareils/' + id, null, 'Appareil déconnecté');
        case 'archiver':
          if (!confirm(`Archiver « ${m.nom} » ? Ses appareils sont déconnectés et son PIN n’ouvre plus rien.`)) { return; }
          return ecrire('PATCH', '/formation/tablettes/' + id, { archive: true }, 'Tablette archivée');
        case 'reactiver': return ecrire('PATCH', '/formation/tablettes/' + id, { archive: false }, 'Tablette réactivée (même PIN)');
        case 'reprise': return ecrire('POST', '/formation/tablettes/reprise-crm', {}, r => r.bilan && r.bilan.reprises && r.bilan.reprises.length ? r.bilan.reprises.length + ' tablette(s) reprise(s) du CRM' : 'Rien de nouveau au CRM');
      }
    });
    root.addEventListener('input', ev => {
      const t = ev.target, k = t.getAttribute && t.getAttribute('data-tbin');
      if (k === 'nom') { T.nom = t.value; }
      if (k === 'code') { T.saisie = t.value; }
    });
    root.addEventListener('submit', ev => {
      const f = ev.target.closest && ev.target.closest('[data-tbform="code"]');
      if (!f) { return; }
      ev.preventDefault();
      const i = f.querySelector('[data-tbin="code"]');
      if (i) { T.saisie = i.value; }
      ouvrirGestion();
    });
    root.addEventListener('change', ev => {
      const t = ev.target;
      const k = t.getAttribute && t.getAttribute('data-tbin');
      if (k === 'shop') {
        T.shop = t.value;
        const b = T.liste && T.liste.boutiques.find(x => x.id === t.value);
        if (b && !T.nom.trim()) { T.nom = 'L’Atelier By ' + b.nom; C.rendre(true); }
        return;
      }
      const c = t.getAttribute && t.getAttribute('data-tbch');
      if (!c || !T.liste) { return; }
      const id = +t.dataset.id, m = T.liste.magasins.find(x => x.id === id);
      if (c === 'nom') {
        const v = t.value.trim();
        if (!v) { t.value = m.nom; return toast('Le nom ne peut pas être vide'); }
        if (v !== m.nom) { ecrire('PATCH', '/formation/tablettes/' + id, { nom: v }, 'Tablette renommée'); }
      }
      if (c === 'shop' && t.value !== (m.shopId || '')) { ecrire('PATCH', '/formation/tablettes/' + id, { shopId: t.value || null }, t.value ? 'Boutique reliée' : 'Boutique retirée'); }
    });
  }

  // Branché sur formation-sop.js : une entrée de plus dans le groupe « Manuel du réseau », sans toucher aux autres.
  FS.nav.push(['tablettes', 'Tablettes']);
  FS.icones.tablettes = 'M6 2h12a2 2 0 0 1 2 2v16a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2zM11 18h2';
  FS.ecrans.tablettes = () => ecran();
  const initAvant = FS.init, apresAvant = FS.apres;
  FS.init = function (ctx, root) { initAvant.call(FS, ctx, root); C = ctx; brancher(root); };
  FS.apres = function (e, a) { apresAvant.call(FS, e, a); if (e === 'tablettes') { charger(false); } };
})(window.FormationSop);
