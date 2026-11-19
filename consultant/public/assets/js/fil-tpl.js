/* Cockpit CEO — Franchisés › Suivi des franchisés › Fil du franchisé (19/11/2026) : le gabarit. Valeurs : fil-vals.js ;
 * style : fil.css (fil-). Maquettes : docs/maquettes/fil-franchise (A le fil, B le calendrier, en bascule). */

const P = {
  visite: 'M12 22s7-6.2 7-12a7 7 0 1 0-14 0c0 5.8 7 12 7 12zM12 12.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z',
  rapport: 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M8 13h8M8 17h5',
  note: 'M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z',
  photo: 'M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2zM12 17a4 4 0 1 0 0-8 4 4 0 0 0 0 8z',
  capture: 'M3 4h18v12H3zM8 20h8M12 16v4',
  appel: 'M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.8.7 2.7a2 2 0 0 1-.5 2.1L8 9.8a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.5c.9.3 1.8.6 2.7.7a2 2 0 0 1 1.7 2z',
  mystere: 'M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12zM12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z',
  demande: 'M4 5h16v11H8l-4 4zM8 9h8M8 12h5',
  epingle: 'M12 17v5M9 3h6l-1 6 3 3H7l3-3z',
  fil: 'M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7',
  cadenas: 'M7 11V7a5 5 0 0 1 10 0v4M5 11h14v10H5z',
  oeil: 'M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12zM12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z',
  plus: 'M12 5v14M5 12h14',
  trombone: 'M21.4 11.1l-9.2 9.2a6 6 0 0 1-8.5-8.5l9.2-9.2a4 4 0 0 1 5.7 5.7l-9.2 9.2a2 2 0 0 1-2.8-2.8l8.5-8.5',
  gauche: 'M15 18l-6-6 6-6', droite: 'M9 18l6-6-6-6'
};
const ico = (k, c) => `<svg class="${c || 'fil-ico'}" viewBox="0 0 24 24" aria-hidden="true"><path d="${P[k] || P.note}"></path></svg>`;
const COUL = { neg: 'var(--stop)', pos: 'var(--green)', att: 'var(--prize)' };

export function tplFil(c, x){
  const { esc } = x;
  const V = c.fl;
  if (!V) { return ''; }
  if (V.chargement) { return `<div data-screen="fil" class="fil"><div class="card fil-etat">Lecture des franchisés…</div></div>`; }
  if (V.erreur) { return `<div data-screen="fil" class="fil"><div class="card fil-etat error">Les franchisés ne se lisent pas : ${esc(V.erreur)}</div></div>`; }
  const tag = (nom, sens, n) => `<span class="fil-tag${sens ? ' fil-tag--' + sens : ''}">${sens ? '<i class="dot"></i>' : ''}${esc(nom)}${n != null ? ` <b>${n}</b>` : ''}</span>`;
  const cartes = `<div class="fil-cartes">${(V.cartes || []).map(k => `<button class="fil-carte${k.on ? ' is-on' : ''}" ${x.A(k.choisir)}>
      <span class="fil-carte__tete"><span class="fil-carte__ville">${esc(k.ville)}</span><span class="fil-carte__cons">${esc(k.consultant)}</span></span>
      <span class="fil-carte__nom">${esc(k.nom)}</span>
      <span class="fil-carte__ligne"><small class="fil-carte__cons">${esc(V.mois)}</small>${tag('Problème de gestion', 'neg', k.gestion)}${tag('Bonne action', 'pos', k.bonne)}</span>
      <span><span class="fil-sem12">${k.semaines.map(s => `<i class="${[s.g ? 'g' : s.s ? 's' : s.b ? 'b' : s.n ? 'n' : '', s.v ? 'v' : ''].join(' ')}"></i>`).join('')}</span>
        <span class="fil-sem12-leg"><span>S${esc(String(k.sDe))}</span><span>S${esc(String(k.sA))}</span></span></span>
      <span class="fil-carte__bas"><span>${k.ouverts ? `<span class="fil-ko">${k.ouverts} fil${k.ouverts > 1 ? 's' : ''} ouvert${k.ouverts > 1 ? 's' : ''}</span>` : 'aucun fil ouvert'}</span><span>visite ${esc(k.visite)}</span></span>
      <span class="fil-carte__bas"><span>dernier ajout ${esc(k.dernier)}</span></span>
    </button>`).join('')}</div>`;
  if (!V.cartes || !V.cartes.length) { return `<div data-screen="fil" class="fil"><div class="card fil-etat">Aucun magasin ouvert.</div></div>`; }
  const M = V.mag || {};
  const tete = `<div class="fil-tete"><div><h2 class="fil-tete__nom">${esc(M.court || '')}</h2><p class="muted">${esc(M.nom || '')}${M.consultant ? ' · consultant ' + esc(M.consultant) : ''}${V.relecture ? ' · relecture…' : ''}</p></div>
    ${V.vues ? `<div class="seg" role="tablist" aria-label="Lire le fil">${V.vues.map(b => `<button class="${b.on ? 'is-on' : ''}" ${x.A(b.choisir)}>${esc(b.nom)}</button>`).join('')}</div>` : ''}</div>`;
  if (V.filChargement) { return `<div data-screen="fil" class="fil">${cartes}${tete}<div class="card fil-etat">Lecture du fil…</div></div>`; }
  if (V.filErreur) { return `<div data-screen="fil" class="fil">${cartes}${tete}<div class="card fil-etat error">Le fil ne se lit pas : ${esc(V.filErreur)}</div></div>`; }
  if (!V.vue) { return `<div data-screen="fil" class="fil">${cartes}</div>`; }
  const filtre = V.filtres ? `<div class="fil-bandeau-filtre">${V.filtreFil ? `${ico('fil')}<span>Le fil « ${esc(V.filtreFil.titre)} »</span>` : '<span>Filtré</span>'} · ${V.filtres.n} entrée${V.filtres.n > 1 ? 's' : ''} sur ${V.filtres.sur}<button class="linklike" ${x.A(V.filtres.effacer)}>tout montrer</button></div>` : '';
  const corps = V.vue === 'fil'
    ? `<div class="fil-deux"><div>${compo(V.form, x, tag)}${filtre}<div class="fil-fil">${(V.sections || []).map(s => section(s, V, x, tag)).join('')}</div></div>${cote(V, x, tag)}</div>`
    : calendrier(V, x, tag, filtre);
  return `<div data-screen="fil" class="fil">${cartes}${tete}${corps}
    <p class="fil-pied">Le fil mêle ce que le cockpit sait déjà — visites, score, points décidés, rapports, client mystère, demandes du helpdesk — et ce que le bureau y dépose : notes, photos, captures, appels. Le fil est interne : il ne se lit que dans le cockpit, et le franchisé n’en voit rien — ni sur sa page, ni dans ses courriels, ni dans son rapport, ni sur la tablette de visite. Une entrée épinglée se rappelle au bureau dans le dossier de la prochaine visite (Visites, étape 3), sans partir sur la tablette. Les photos et captures sont gardées dans le dossier privé du serveur.${V.demandesNote ? ' <span class="fil-or">' + esc(V.demandesNote) + '</span>' : ''}</p></div>`;
}

/* --- le formulaire -------------------------------------------------------------------- */

function compo(f, x, tag){
  const { esc } = x;
  if (!f) { return ''; }
  const choix = f.tags.map(t => `<button class="fil-choix${t.on ? ' is-on' : ''}" ${x.A(t.basculer)}>${t.sens ? `<i class="dot" style="background:${COUL[t.sens]}"></i>` : ''}${esc(t.nom)}</button>`).join('');
  const pieces = f.pieces.map(p => `<span class="fil-piece"><img src="${esc(p.src)}" alt=""><span>${p.genre === 'capture' ? 'capture' : 'photo'}</span><button class="fil-piece__x" title="Retirer" aria-label="Retirer" ${x.A(p.retirer)}>×</button></span>`).join('');
  const msg = f.envoi && !f.envoi.enCours && f.envoi.msg ? `<span class="error">${esc(f.envoi.msg)}</span>` : '';
  return `<div class="fil-compo${f.edition ? ' fil-compo--edit' : ''}" ${x.DP(f.deposer)}>
    <textarea id="${f.idTexte}" rows="${f.edition ? 3 : 2}" placeholder="${f.edition ? 'Le texte de l’entrée…' : 'Une note, un constat, un appel… Ctrl+V colle une capture d’écran ; on dépose aussi des photos ici.'}" ${x.I(f.poserTexte)}>${esc(f.texte)}</textarea>
    ${pieces ? `<div class="fil-compo__pieces">${pieces}</div>` : ''}
    <div class="fil-compo__ligne"><small>Étiquettes</small>${choix}</div>
    <div class="fil-compo__ligne"><span class="seg seg--sm">${f.types.map(t => `<button class="${t.on ? 'is-on' : ''}" ${x.A(t.choisir)}>${esc(t.nom)}</button>`).join('')}</span>
      <small style="margin-left:8px">Suite du fil</small><select class="fil-select" ${x.C(f.poserFil)}>${f.fils.map(o => `<option value="${esc(o.id)}"${o.on ? ' selected' : ''}>${esc(o.nom)}</option>`).join('')}</select>
      ${f.ferme ? `<label class="fil-coche"><input type="checkbox"${f.ferme.on ? ' checked' : ''} ${x.C(f.ferme.basculer)}>cette bonne action ferme le fil</label>` : ''}
      <small style="margin-left:8px">Le</small><input id="${f.idLe}" class="fil-select" type="datetime-local" max="${esc(f.leMax)}" value="${esc(f.le)}" ${x.C(f.poserLe)} title="Vide : maintenant"></div>
    <div class="fil-compo__pied"><label class="fil-joindre">${ico('trombone')}Photo ou capture<input type="file" accept="image/*" multiple ${x.C(f.choisirFichiers)} style="display:none"></label><button class="fil-sw${f.epingle.on ? ' is-on' : ''}" ${x.A(f.epingle.basculer)}><i></i>${esc(f.epingle.nom)}</button>
      <span class="fil-interne">${ico('cadenas')}interne : au cockpit seulement</span>
      <span class="fil-compo__fin">${msg}${f.annuler ? `<button class="btn btn--ghost btn--sm" ${x.A(f.annuler)}>Annuler</button>` : ''}${f.vider ? `<button class="btn btn--ghost btn--sm" ${x.A(f.vider)}>Vider</button>` : ''}
      <button class="btn ${f.edition ? 'btn--sm' : 'btn--primary'}" ${f.envoyer ? x.A(f.envoyer) : 'disabled'}>${f.envoi && f.envoi.enCours ? 'Envoi…' : (f.edition ? 'Enregistrer' : 'Ajouter au fil')}</button></span></div>
  </div>`;
}

/* --- une entrée ------------------------------------------------------------------------- */

function entree(e, V, x, tag, compact){
  const { esc } = x;
  const quand = compact ? `<div class="fil-ent__quand"><b>${esc(e.jourSeul)}</b>${esc(e.date)}</div>` : `<div class="fil-ent__quand"><b>${esc(e.jour)}</b>${esc(e.heure)}</div>`;
  const axe = `<div class="fil-ent__axe"><span class="fil-rond${e.rond ? ' fil-rond--' + e.rond : ''}">${ico(e.ico)}</span></div>`;
  const tags = e.tags && e.tags.length ? `<span class="fil-tags">${e.tags.map(t => tag(t.nom, t.sens)).join('')}</span>` : '';
  let corps;
  if (e.famille === 'note') {
    if (e.enEdition && V.edit) { return `<div class="fil-ent">${quand}${axe}<div class="fil-ent__corps fil-ent__corps--edit">${compo(V.edit, x, tag)}</div></div>`; }
    const pieces = e.pieces.length ? `<div class="fil-ent__pieces">${e.pieces.map(p => `<a href="${esc(p.src)}" target="_blank" rel="noopener"><img class="${p.genre === 'capture' ? 'fil-cap' : 'fil-ph'}" src="${esc(p.src)}" alt="${p.genre === 'capture' ? 'capture' : 'photo'}" loading="lazy"></a>`).join('')}</div>` : '';
    const pied = [];
    if (e.fil) { pied.push(`<span>${ico('fil')}<button class="linklike" ${x.A(e.fil.voir)}>${esc(e.fil.titre)}</button> · ${e.fil.n} entrée${e.fil.n > 1 ? 's' : ''} · <span class="${e.fil.ouvert ? 'fil-ko' : 'fil-ok'}">${esc(e.fil.etat)}</span>${e.fil.ferme ? ' · <span class="fil-ok">cette entrée le ferme</span>' : ''}</span>`); }
    if (e.epingle) { pied.push(`<span class="fil-or">${ico('epingle')}${esc(e.epingle)}</span>`); }
    pied.push(`<span class="fil-droite">${ico('cadenas')}interne</span>`);
    pied.push(`<span class="fil-actions"><button class="linklike" ${x.A(e.modifier)}>Modifier</button><button class="linklike" ${x.A(e.effacer)}>Effacer</button></span>`);
    corps = `<div class="fil-ent__corps"><div class="fil-ent__tete"><span class="fil-ent__type">${esc(e.typeNom)}</span><span class="fil-ent__qui">${esc(e.qui || '')}</span>${tags}</div>
      ${e.texte ? `<div class="fil-ent__txt">${esc(e.texte)}</div>` : ''}${pieces}<div class="fil-ent__pied">${pied.join('')}</div></div>`;
  } else if (e.type === 'visite') {
    const pts = e.points && e.points.length ? `<ul class="fil-pts">${e.points.map(p => `<li><span class="fil-prio fil-prio--${esc(String(p.prio).toLowerCase())}">${esc(p.prio)}</span>${esc(p.titre)}<small class="${p.ouvert ? 'fil-ko' : ''}">${esc(p.statut)}</small></li>`).join('')}${e.autres ? `<li class="muted">et ${e.autres} autre${e.autres > 1 ? 's' : ''}</li>` : ''}</ul>` : '';
    const score = e.score != null ? `<div class="fil-score"><b>${esc(String(Math.round(e.score)))}</b> <small>score / 100</small>${e.delta != null ? `<em class="${e.delta >= 0 ? 'fil-ok' : 'fil-ko'}">${e.delta >= 0 ? '+' : '−'}${esc(String(Math.abs(Math.round(e.delta))))} face à la précédente</em>` : ''}</div>` : '';
    corps = `<div class="fil-ent__corps fil-ent__corps--visite${e.prevue ? ' fil-ent__corps--prevue' : ''}${e.annulee ? ' fil-ent__corps--annulee' : ''}"><div class="fil-visite"><div>
      <div class="fil-ent__tete"><span class="fil-ent__type">Visite · ${esc(e.statut)}</span><span class="fil-ent__qui">${esc(e.qui || '')}</span></div>
      <div class="fil-ent__titre">${esc(e.titre)}</div><div class="fil-ent__det">${esc(e.det)}</div></div>${score}</div>${pts}
      <div class="fil-ent__pied"><button class="linklike" ${x.A(e.ouvrir)}>${e.fait ? 'Ouvrir la visite et son rapport' : 'Ouvrir la visite et son dossier'}</button></div></div>`;
  } else {
    corps = `<div class="fil-ent__corps fil-ent__corps--sys"><div class="fil-ent__tete"><span class="fil-ent__type">${esc(e.typeNom)}</span><span class="fil-ent__qui">${esc(e.qui || '')}</span>${tags}</div>
      <div class="fil-ent__titre">${esc(e.titre || '')}</div>${e.det ? `<div class="fil-ent__det">${esc(e.det)}</div>` : ''}${e.ouvrir ? `<div class="fil-ent__pied"><button class="linklike" ${x.A(e.ouvrir)}>Ouvrir</button></div>` : ''}</div>`;
  }
  return `<div class="fil-ent${e.on ? ' fil-jour-on' : ''}">${quand}${axe}${corps}</div>`;
}

const compteurs = (k, x) => k ? `<span class="fil-cpts">${[['gestion', 'fil-ko'], ['surveiller', 'fil-or'], ['bonne', 'fil-ok']].map(([n, cl]) => `<span class="fil-cpt ${cl}${k[n] ? '' : ' zero'}" title="${n === 'gestion' ? 'problèmes de gestion' : n === 'bonne' ? 'bonnes actions' : 'à surveiller'}"><i></i>${k[n]}</span>`).join('')}</span>` : '';

function section(s, V, x, tag){
  const { esc } = x;
  if (s.mois) {
    return `<div class="fil-mois"><div class="fil-ent__quand"></div><div class="fil-ent__axe"><span class="fil-rond"></span></div>
      <button class="fil-mois__corps${s.ouvert ? ' is-on' : ''}" ${x.A(s.basculer)}><span class="fil-mois__nom">${esc(s.titre)}${s.debut ? ` <small class="fil-mois__nb">${esc(s.debut)}</small>` : ''}</span><span class="fil-mois__nb">${esc(s.sous)}</span>${compteurs(s.compte, x)}<span class="fil-chev">${s.ouvert ? '▾' : '▸'}</span></button></div>
      ${s.ouvert ? (s.semaines.length ? s.semaines.map(w => section(w, V, x, tag)).join('') : '<p class="fil-vide">Rien dans ce mois.</p>') : ''}`;
  }
  const leg = s.legende ? `<span class="fil-cpts"><span class="fil-cpt fil-ko"><i></i>problèmes de gestion</span><span class="fil-cpt fil-or"><i></i>à surveiller</span><span class="fil-cpt fil-ok"><i></i>bonnes actions</span></span>` : compteurs(s.compte, x);
  return `<div class="fil-sem${s.avenir ? ' fil-sem--avenir' : ''}"><h3>${esc(s.titre)}</h3><small>${esc(s.sous || '')}</small>${leg}</div>
    ${(s.entrees || []).map(e => entree(e, V, x, tag)).join('')}${s.vide ? '<p class="fil-vide">Rien de noté cette semaine.</p>' : ''}`;
}

/* --- la colonne de droite --------------------------------------------------------------- */

function cote(V, x, tag){
  const { esc } = x;
  const fil = f => `<div class="fil-ouvert${f.on ? ' is-on' : ''}"><button class="fil-ouvert__t" ${x.A(f.voir)}>${esc(f.titre)}</button>
    <span class="fil-fl__etapes">${f.etapes.map(s => `<i class="${s || 'sys'}"></i>`).join('<b></b>')}${f.ouvert ? '<b class="tir"></b>' : ''}</span>
    <small>${esc(f.dates)} · <span class="${f.ouvert ? 'fil-ko' : 'fil-ok'}">${esc(f.etat)}</span> · <button class="linklike" ${x.A(f.basculer)}>${f.ouvert ? 'résoudre' : 'rouvrir'}</button></small></div>`;
  return `<aside class="fil-cote">
    <section class="card"><h2>Lire le fil</h2><div class="fil-cote__l"><div class="seg">${V.groupes.map(g => `<button class="${g.on ? 'is-on' : ''}" ${x.A(g.choisir)}>${esc(g.nom)}</button>`).join('')}</div>
      <select class="fil-select" ${x.C(V.poserAnnee)}>${V.annees.map(a => `<option value="${a.id}"${a.on ? ' selected' : ''}>${a.id}</option>`).join('')}</select></div>
      <p class="fil-aide">Semaine : les quatre dernières ouvertes, le reste replié par mois. Année : douze lignes, une par mois.</p></section>
    <section class="card"><h2>Ne montrer que · ${esc(String(V.annee))}</h2><div class="fil-filtres">${V.filtresTags.map(t => `<label class="fil-filtre"><input type="checkbox"${t.on ? ' checked' : ''} ${x.C(t.basculer)}>${tag(t.nom, t.sens)}<span class="n">${t.n}</span></label>`).join('')}</div></section>
    <section class="card"><h2>Ce que montre le fil</h2><div class="fil-filtres">${V.familles.map(t => `<label class="fil-filtre"><input type="checkbox"${t.on ? ' checked' : ''} ${x.C(t.basculer)}>${ico(t.ico)}${esc(t.nom)}<span class="n">${t.n}</span></label>`).join('')}</div>
      <p class="fil-aide"><button class="linklike" ${x.A(V.demandesLien)}>Les demandes de ce magasin</button></p></section>
    <section class="card"><h2>Fils ouverts · ${V.filsOuverts.length}</h2>${V.filsOuverts.length ? V.filsOuverts.map(fil).join('') : '<p class="fil-aide">Aucun. Une note « Problème de gestion » en ouvre un.</p>'}
      ${V.filsResolus.length ? `<div class="fil-sep"></div><p class="fil-aide">${V.filsResolus.length} fil${V.filsResolus.length > 1 ? 's' : ''} résolu${V.filsResolus.length > 1 ? 's' : ''}${V.moyenneResolus != null ? ', en ' + V.moyenneResolus + ' jours en moyenne' : ''} · <button class="linklike" ${x.A(V.basculerResolus)}>${V.voirResolus ? 'replier' : 'les voir'}</button></p>
        ${V.voirResolus ? V.filsResolus.map(fil).join('') : ''}` : ''}</section>
    <section class="card"><h2>Prochaine visite</h2>${V.prochaine ? `<p class="fil-cote__p">${esc(V.prochaine.quand)} · ${esc(V.prochaine.qui || 'sans consultant')}</p>
      <p class="fil-aide">${V.prochaine.epingles ? V.prochaine.epingles + ' entrée' + (V.prochaine.epingles > 1 ? 's' : '') + ' épinglée' + (V.prochaine.epingles > 1 ? 's' : '') + ' : rappel au bureau dans son dossier (Visites, étape 3), jamais sur la tablette.' : 'Rien d’épinglé pour son dossier.'} <button class="linklike" ${x.A(V.prochaine.ouvrir)}>Ouvrir la visite</button></p>`
      : '<p class="fil-aide">Aucune visite planifiée.</p>'}</section>
  </aside>`;
}

/* --- B · le calendrier ------------------------------------------------------------------- */

function calendrier(V, x, tag, filtre){
  const { esc } = x;
  const bandeau = `<div class="fil-bandeau">${V.cal === 'mois' ? `<span class="fil-nav"><button class="btn btn--icon" aria-label="Mois précédent" ${x.A(V.precedent)}>${ico('gauche')}</button><strong>${esc(V.moisNom)}</strong><button class="btn btn--icon" aria-label="Mois suivant" ${x.A(V.suivant)}>${ico('droite')}</button></span>` : `<select class="fil-select fil-select--annee" aria-label="Année" ${x.C(V.poserAnnee)}>${V.annees.map(a => `<option value="${a.id}"${a.on ? ' selected' : ''}>${a.id}</option>`).join('')}</select>`}
    <button class="btn btn--sm" ${x.A(V.aujourdhui)}>Aujourd’hui</button>
    <span class="fil-bandeau__f">${V.filtresTags.filter(t => t.sens).map(t => `<button class="fil-choix${t.on ? ' is-on' : ''}" ${x.A(t.basculer)}><i class="dot" style="background:${COUL[t.sens]}"></i>${esc(t.nom)}</button>`).join('')}</span>
    <div class="seg">${V.cals.map(b => `<button class="${b.on ? 'is-on' : ''}" ${x.A(b.choisir)}>${esc(b.nom)}</button>`).join('')}</div></div>`;
  if (V.cal === 'annee') {
    const ms = V.moisAnnee.map(m => `<div class="fil-mini${m.on ? ' is-on' : ''}"><h4><button class="linklike" ${x.A(m.choisir)}>${esc(m.nom)}</button><small>${m.visites ? m.visites + ' visite' + (m.visites > 1 ? 's' : '') : ''}</small></h4>
      <div class="fil-mini__g">${m.jours.map(j => j.vide ? '<i class="o"></i>' : `<i class="${esc(j.cls)}${j.auj ? ' auj' : ''}" title="${esc(j.titre)}" ${x.A(j.choisir)}></i>`).join('')}</div>
      <div class="fil-mini__c">${m.gestion ? `<span class="fil-ko">${m.gestion} probl.</span>` : ''}${m.surveiller ? `<span class="fil-or">${m.surveiller} à surv.</span>` : ''}${m.bonne ? `<span class="fil-ok">${m.bonne} bonne${m.bonne > 1 ? 's' : ''}</span>` : ''}${!m.gestion && !m.bonne && !m.surveiller ? '<span>—</span>' : ''}</div></div>`).join('');
    const leg = `<div class="fil-leg"><span><i class="fil-leg__v"></i>visite faite</span><span><i class="fil-leg__p"></i>visite prévue</span><span><i style="background:var(--stop)"></i>problème de gestion</span><span><i style="background:var(--prize)"></i>à surveiller</span><span><i style="background:var(--green)"></i>bonne action</span><span><i style="background:var(--chip)"></i>autre</span><span>un jour = un carré · un clic l’ouvre</span></div>`;
    return `${bandeau}${filtre}<section class="card fil-annee-c"><div class="fil-annee">${ms}</div>${leg}</section>`;
  }
  const grille = `<div class="fil-cal"><div class="fil-cal__jour"></div>${['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'].map(j => `<div class="fil-cal__jour">${j}</div>`).join('')}
    ${V.semainesCal.map(s => `<button class="fil-cal__s${s.on ? ' is-on' : ''}" ${x.A(s.choisir)}>S${s.n}</button>${s.jours.map(j => `<div class="fil-cal__c${j.hors ? ' hors' : ''}${s.on ? ' sem' : ''}${j.auj ? ' auj' : ''}${j.on ? ' is-on' : ''}" ${x.A(j.choisir)}>
      <div class="fil-cal__n"><i>${j.n}</i></div>${j.puces.map(p => `<span class="fil-ev${p.cls ? ' fil-ev--' + p.cls : ''}" title="${esc(p.nom)}">${ico(p.ico)}<span>${esc(p.nom)}</span></span>`).join('')}${j.plus ? `<span class="fil-ev">+ ${j.plus}</span>` : ''}
      ${j.pieces.length ? `<span class="fil-ev-ph">${j.pieces.map(src => `<img src="${esc(src)}" alt="" loading="lazy">`).join('')}</span>` : ''}</div>`).join('')}`).join('')}</div>`;
  const W = V.semaineCal;
  const panneau = `<aside class="card fil-cal-p"><h2>${esc(W.titre)}</h2><p class="fil-aide">${esc(W.sous)}</p>${compteurs(W.compte, x)}
    ${W.compo ? `<div class="fil-cal-p__compo">${compo(V.form, x, tag)}<button class="linklike" ${x.A(W.fermer)}>fermer</button></div>` : `<p><button class="btn btn--sm" ${x.A(W.ajouter)}>${ico('plus')}Ajouter au ${esc(W.jourNom)}</button></p>`}
    <div class="fil-semaine">${W.entrees.length ? W.entrees.map(e => entree(e, V, x, tag, true)).join('') : '<p class="fil-vide">Rien de noté cette semaine.</p>'}</div></aside>`;
  return `${bandeau}${filtre}<div class="fil-trois">${grille}${panneau}</div>`;
}
