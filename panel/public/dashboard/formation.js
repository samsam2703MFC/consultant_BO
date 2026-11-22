/* Dashboard magasin › Se former › Formations et suivi (22/11/2026, maquettes formation/docs/maquettes/formation-continue/,
 * écrans 03 à 05). La page Formation du franchisé, où mène le bouton abricot « Mon dossier de formation » :
 *   - les NOUVEAUTÉS : les formations continues reçues ces deux derniers mois, la plus récente d'abord — titre, catégorie,
 *     date, lien direct ; « nouveau » tant que le magasin ne l'a pas ouverte ;
 *   - le DOSSIER DE FORMATION : ses formations continues (sa version, adaptée par le consultant), statut, avancement,
 *     échéance ; une formation s'ouvre ici (étapes, points critiques, ce qu'il reste à faire) ;
 *   - le SUIVI DE L'ÉQUIPE : qui a lu, réussi le quiz, été vu faire (déclaré par le franchisé) ;
 *   - la PHOTO DU JOUR : pour un nouveau produit, une photo qualité par jour pendant 14 jours, relue par le consultant ;
 *   - le PROFESSEUR, relié au dossier : ses questions toutes prêtes viennent des formations du magasin ; il répond à
 *     partir d'elles (outils dossier_formation, formation_lire, panel/src/assistant_ia.php).
 *   Routes (formation/src/formation_continue.php) : GET …/formation/continue/magasin, …/magasin/formation,
 *   POST …/magasin/avancement, …/magasin/mise-en-place, …/magasin/photo, GET …/magasin/photo.
 *
 * `window.DashFormation.racine(shop, {apiBase, aller, professeur, fc})` rend l'élément du module, gardé d'un rendu à
 * l'autre ; `professeur(question)` ouvre l'assistant sur le Professeur (dashboard.js) ; `fc` : un dossier à ouvrir.
 */
(function () {
  'use strict';
  const esc = v => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const dfr = s => s ? String(s).slice(8, 10) + '/' + String(s).slice(5, 7) + '/' + String(s).slice(0, 4) : '—';
  const dm = s => s ? String(s).slice(8, 10) + '/' + String(s).slice(5, 7) : '';
  const CAT = { technique: 'tech', connaissance: 'conn', nouveau_produit: 'prod', nouvelle_technique: 'ntech' };
  const ST = { en_cours: 'brou', a_valider: 'aval', validee: 'val' };
  const PH = { conforme: ['ok', 'conforme'], a_refaire: ['ko', 'à refaire'], en_attente: ['att', 'envoyée, pas encore relue'], manquante: ['manq', 'pas de photo'], a_faire: ['faire', 'à faire aujourd’hui'], a_venir: ['venir', 'à venir'] };

  const st = { shop: null, api: '../api/cockpit', el: null, aller: null, prof: null, d: null, err: null, fc: null, f: null, fErr: null, occupe: false, flash: null, personne: '', q: '' };

  /* --- lectures et écritures ------------------------------------------------------------------------------------ */
  const json = r => r.json().catch(() => ({})).then(j => (r.ok ? j : Promise.reject(new Error((j && j.error) || ('HTTP ' + r.status)))));
  const lire = (p, prm) => fetch(st.api + p + '?' + new URLSearchParams(Object.assign({ shop: st.shop }, prm || {})).toString(), { headers: { Accept: 'application/json' }, credentials: 'same-origin' }).then(json);
  const ecrire = (p, corps) => fetch(st.api + p, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    body: JSON.stringify(Object.assign({ shop: st.shop }, corps)) }).then(json);
  const photoUrl = id => st.api + '/formation/continue/magasin/photo?shop=' + encodeURIComponent(st.shop) + '&id=' + id;
  const etapePhoto = p => String(p).startsWith('u/') ? st.api + '/formation/sop/photo/' + encodeURIComponent(String(p).slice(2)) : '../formation/sop/photos/' + encodeURIComponent(String(p));
  function flash(ok, texte) { st.flash = { ok, texte }; rendre(); }
  function charger() { return lire('/formation/continue/magasin').then(d => { st.d = d; st.err = null; }).catch(e => { st.err = e.message; }).finally(rendre); }
  function ouvrir(id) {
    st.fc = id; st.f = null; st.fErr = null; st.flash = null; rendre(); window.scrollTo(0, 0);
    lire('/formation/continue/magasin/formation', { id }).then(f => { st.f = f; const n = (st.d && st.d.nouveautes || []).find(x => x.id === id); if (n) { n.nouveau = false; } })
      .catch(e => { st.fErr = e.message; }).finally(rendre);
    try { const u = new URL(location.href); u.searchParams.set('fc', id); history.replaceState(null, '', u.toString()); } catch (e) { /* adresse inchangée */ }
  }
  function fermer() {
    st.fc = null; st.f = null; st.flash = null; rendre(); charger();
    try { const u = new URL(location.href); u.searchParams.delete('fc'); history.replaceState(null, '', u.toString()); } catch (e) { /* adresse inchangée */ }
  }
  /** Une écriture sur la formation ouverte : la réponse rend le dossier à jour ; le contenu ne change pas. */
  function agir(p, corps, ok) {
    if (st.occupe) { return; }
    st.occupe = true; rendre();
    ecrire(p, Object.assign({ id: st.fc }, corps)).then(r => { if (st.f && r.dossier) { st.f = Object.assign({}, st.f, r.dossier, { contenu: st.f.contenu }); } if (ok) { flash(true, ok(r)); } })
      .catch(e => flash(false, e.message)).finally(() => { st.occupe = false; rendre(); });
  }
  function envoyerPhoto(fichier) {
    if (!fichier || st.occupe) { return; }
    if (fichier.size > 12 * 1048576) { return flash(false, 'Photo trop lourde : 12 Mo au plus.'); }
    const fd = new FormData(); fd.append('shop', st.shop); fd.append('id', st.fc); fd.append('photo', fichier, fichier.name || 'photo.jpg');
    st.occupe = true; rendre();
    fetch(st.api + '/formation/continue/magasin/photo', { method: 'POST', body: fd, credentials: 'same-origin' }).then(json)
      .then(r => { if (st.f && r.dossier) { st.f = Object.assign({}, st.f, r.dossier, { contenu: st.f.contenu }); } flash(true, 'Photo du jour envoyée : votre consultant la relit.'); })
      .catch(e => flash(false, 'La photo n’est pas partie : ' + e.message)).finally(() => { st.occupe = false; rendre(); });
  }

  /* --- morceaux communs ------------------------------------------------------------------------------------------ */
  const tagCat = x => `<span class="fs-tag ${CAT[x.categorie] || ''}"><i></i>${esc(x.categorieLib)}</span>`;
  const statut = x => `<span class="fs-st ${x.retard ? 'ret' : (ST[x.statut] || 'brou')}">${esc(x.statutLib)}</span>`;
  const barre = pct => `<span class="fs-av"><span class="b"><i class="${pct >= 100 ? '' : 'o'}" style="width:${Math.max(0, Math.min(100, pct || 0))}%"></i></span><span>${pct == null ? '—' : pct + ' %'}</span></span>`;
  function professeur(questions) {
    if (!st.prof) { return ''; }
    return `<aside class="fs-carte fs-prof"><div class="fs-prof-t"><img src="avatars/professeur.svg" alt=""><div><b>Le Professeur</b><span>Il répond à partir de <b>votre dossier de formation</b> — vos formations continues, avec les adaptations faites pour votre magasin — et du Manuel opératoire.</span></div></div>
      <div class="fs-k">Questions toutes prêtes</div>${questions.map((q, i) => `<button type="button" class="fs-qp" data-fs-prof="${i}">${esc(q[0])}<small>${esc(q[1] || '')}</small></button>`).join('')}
      <div class="fs-q"><input class="db-sel" data-fs-q="1" value="${esc(st.q)}" placeholder="Posez votre question au Professeur…"><button type="button" class="db-btn fs-abr" data-fs-prof="q">Demander</button></div>
      <p class="fs-n">Il ne voit que le dossier de votre magasin. Il cite la formation et l’étape qu’il a lues.</p></aside>`;
  }
  let questions = [];

  /* --- la page : Nouveautés, dossier, équipe ---------------------------------------------------------------------- */
  function page() {
    const D = st.d, R = D.reperes || {}, L = D.dossiers || [];
    const nouv = (D.nouveautes || []).length ? `<section class="fs-nouv"><div class="fs-nouv-h"><b>Nouveautés</b><span>les nouvelles formations continues de votre magasin, la plus récente d’abord</span></div>
      <div class="fs-cartes">${D.nouveautes.map(n => `<button type="button" class="fs-nc" data-fs-ouvrir="${n.id}"><span class="fs-nc-b ${CAT[n.categorie] || ''}">${esc(n.categorieLib)}${n.nouveau ? '<em>NOUVEAU</em>' : ''}</span>
        <span class="fs-nc-c">${tagCat(n)}<b>${esc(n.titre)}</b><span class="d">reçue le ${esc(dfr(n.date))}${n.code ? ' · ' + esc(n.code) : ''}</span><span class="go">Ouvrir la formation →</span></span></button>`).join('')}</div></section>` : '';
    const kpi = (cls, l, v, s) => `<div class="fs-kpi ${cls}"><span class="l">${l}</span><b>${v}</b><span class="s">${s}</span></div>`;
    const kpis = `<div class="fs-kpis">${kpi(R.enRetard ? 'ac' : '', 'En retard', R.enRetard || 0, 'échéance passée')}${kpi('', 'En cours', R.enCours || 0, R.photoDuJour ? R.photoDuJour + ' photo(s) du jour à faire' : 'à terminer')}${kpi(R.aValider ? 'or' : '', 'À valider', R.aValider || 0, 'terminées, chez votre consultant')}${kpi(R.validees ? 'ok' : '', 'Validées', R.validees || 0, 'archivées dans votre coffre-fort')}</div>`;
    const ligne = x => `<button type="button" class="fs-l" data-fs-ouvrir="${x.id}"><span class="c"><b>${esc(x.titre)}${x.nouveau ? ' <em class="fs-nv">nouveau</em>' : ''}</b>
        <small>${tagCat(x)}${x.adapte ? ` <span class="fs-tag adapt">adaptée pour votre magasin${x.adaptation.par ? ' par ' + esc(x.adaptation.par) : ''}</span>` : ''}${x.photos.jours ? ' · photos ' + x.photos.conformes + '/' + x.photos.jours : ''}${x.validation ? ' · validée par ' + esc(x.validation.par) + ' le ' + esc(dfr(x.validation.le)) : ''}</small></span>
        <span class="s">${statut(x)}</span>${barre(x.avancement.pct)}<span class="e${x.retard ? ' ret' : ''}">${esc(x.echeance ? dm(x.echeance) : '—')}</span><span class="o">Ouvrir</span></button>`;
    const dossier = `<section class="fs-carte"><div class="fs-tete-c"><b>Mon dossier de formation</b><span>formation continue · ${L.length}</span></div>
      <div class="fs-th"><span class="c">Formation</span><span class="s">Statut</span><span class="fs-av">Avancement</span><span class="e">Échéance</span><span class="o"></span></div>
      ${L.length ? L.map(ligne).join('') : '<div class="fs-vide">Aucune formation continue pour l’instant : elles arrivent ici dès que le réseau les diffuse.</div>'}</section>`;
    // Le suivi de l'équipe : une ligne par personne, une colonne par formation pas encore validée.
    const cols = L.filter(x => x.statut !== 'validee' && x.equipe.length);
    const pers = [...new Set(cols.flatMap(x => x.assignes))];
    const pt = (x, p) => { const e = x.equipe.find(q => q.personne === p); if (!e) { return '<i class="pt x" title="pas concerné"></i>'; } return `<i class="pt ${e.vu ? 'v' : (e.quiz ? 'q' : (e.lu ? 'l' : ''))}" title="${e.vu ? 'vu faire' : (e.quiz ? 'quiz réussi' : (e.lu ? 'lue' : 'pas commencé'))}"></i>`; };
    const equipe = cols.length ? `<section class="fs-carte fs-eq"><div class="fs-k">Le suivi de l’équipe <i><i class="pt v"></i> vu faire · <i class="pt q"></i> quiz · <i class="pt l"></i> lue · <i class="pt"></i> pas commencé</i></div>
      <table><tr><th></th>${cols.map(x => `<th title="${esc(x.titre)}">${esc(x.titre.length > 22 ? x.titre.slice(0, 21) + '…' : x.titre)}</th>`).join('')}</tr>
      ${pers.map(p => `<tr><td>${esc(p)}</td>${cols.map(x => `<td>${pt(x, p)}</td>`).join('')}</tr>`).join('')}</table></section>` : '';
    const coffre = st.aller ? `<button type="button" class="fs-coffre" data-fs-coffre="1">📄 <span><b>Mon document de formation</b> — l’archive de chaque formation validée (qui, quand, l’équipe, les photos), dans votre coffre-fort, onglet Formation</span><span class="go">Ouvrir →</span></button>` : '';
    // Les questions toutes prêtes du Professeur, tirées du dossier.
    const enCours = L.filter(x => x.statut === 'en_cours'), prod = L.find(x => x.categorie === 'nouveau_produit' && x.statut !== 'validee');
    questions = [['Qu’est-ce que je dois terminer en premier ?', 'd’après les échéances de mon dossier']];
    if (enCours[0]) { questions.push(['Résume-moi la formation « ' + enCours[0].titre + ' »', enCours[0].categorieLib]); }
    if (prod) { questions.push(['Quels allergènes et quels points critiques pour « ' + prod.titre + ' » ?', 'Nouveau produit']); }
    if (enCours[1] || enCours[0]) { const x = enCours[1] || enCours[0]; questions.push(['Prépare 3 questions pour vérifier l’équipe sur « ' + x.titre + ' »', x.categorieLib]); }
    return `${nouv}<div class="fs-lay"><div>${kpis}${dossier}${equipe}${coffre}</div>${professeur(questions)}</div>`;
  }

  /* --- une formation : sa version, ce qu'il reste à faire --------------------------------------------------------- */
  function fiche() {
    const f = st.f;
    if (st.fErr) { return `<div class="db-err">La formation ne s’ouvre pas : ${esc(st.fErr)}</div><button type="button" class="db-btn" data-fs-fermer="1">← Mon dossier</button>`; }
    if (!f) { return '<div class="db-attente">Ouverture de la formation…</div>'; }
    const t = v => v && typeof v === 'object' ? (v.fr || v.nl || '') : String(v || '');
    const C = f.contenu || {};
    const etapes = (C.sections || []).map(s => `${(C.sections || []).length > 1 ? `<div class="fs-sec">${esc(t(s.titre))}</div>` : ''}${(s.etapes || []).map(e => `<div class="fs-et${e.critique ? ' crit' : ''}"><span class="n">${esc(e.numero)}</span><div class="x">${e.titre ? `<b>${esc(t(e.titre))}</b>` : ''}${esc(t(e.texte)).replace(/\n/g, '<br>')}${e.critique ? '<em>point critique</em>' : ''}${e.video ? `<a class="fs-lien" href="${esc(e.video)}" target="_blank" rel="noopener">▶ la vidéo</a>` : ''}</div>${e.photo ? `<img src="${esc(etapePhoto(e.photo))}" alt="" loading="lazy">` : ''}</div>`).join('')}`).join('');
    const crit = (C.points_critiques || []).length ? `<div class="fs-crit">${C.points_critiques.map(p => `<div><b>Point critique</b>${esc(t(p))}</div>`).join('')}</div>` : '';
    const adapt = f.adapte ? `<div class="fs-adapt">Adaptée pour votre magasin${f.adaptation.par ? ' par ' + esc(f.adaptation.par) : ''}${f.adaptation.le ? ' le ' + esc(dfr(f.adaptation.le)) : ''}${f.adaptation.note ? ' : « ' + esc(f.adaptation.note) + ' »' : ''}. C’est cette version que vous suivez.</div>` : '';
    const fini = f.statut === 'validee';
    // La photo du jour.
    const P = f.photos;
    const photos = P.jours ? `<div class="fs-bloc"><div class="fs-k">Photo qualité du produit <i>${P.conformes}/${P.jours} conformes${P.fin ? ' · jusqu’au ' + esc(dm(P.fin)) : ''}</i></div>
      <div class="fs-grille">${P.grille.map(g => `<span class="fs-j ${PH[g.etat][0]}" title="${esc(dfr(g.jour) + ' · ' + PH[g.etat][1] + (g.commentaire ? ' · « ' + g.commentaire + ' »' : ''))}">${g.photo ? `<img src="${esc(photoUrl(g.photo))}" alt="" loading="lazy">` : ''}<small>${esc(dm(g.jour))}</small></span>`).join('')}</div>
      ${P.grille.some(g => g.etat === 'a_refaire') ? `<p class="fs-n ko">${P.grille.filter(g => g.etat === 'a_refaire').map(g => esc(dm(g.jour)) + (g.commentaire ? ' : « ' + esc(g.commentaire) + ' »' : '')).join(' · ')} — à refaire.</p>` : ''}
      ${!fini ? `<label class="db-btn fs-abr fs-photo${st.occupe ? ' occ' : ''}">📷 ${P.grille.some(g => g.etat === 'a_faire') ? 'Envoyer la photo du jour' : (P.grille.some(g => g.jour === new Date().toISOString().slice(0, 10) && g.etat !== 'conforme') ? 'Refaire la photo du jour' : 'Photo du jour envoyée')}<input type="file" accept="image/*" capture="environment" data-fs-photo="1" hidden${P.grille.some(g => g.jour === new Date().toISOString().slice(0, 10) && g.etat === 'conforme') ? ' disabled' : ''}></label>` : ''}
      <p class="fs-n">Une photo par jour, photo obligatoire ; un jour manqué ou une photo à refaire ajoute un jour.</p></div>` : '';
    // L'équipe et la mise en place.
    const eq = `<div class="fs-bloc"><div class="fs-k">Qui la suit <i>déclaré par vous</i></div>
      <div class="fs-pers fs-pers-h"><span></span><span>Lu</span><span>Quiz</span><span>Vu faire</span></div>
      ${f.equipe.map(p => `<div class="fs-pers"><span>${esc(p.personne)}</span>${['lu', 'quiz', 'vu'].map(k => `<label><input type="checkbox" data-fs-eq="${esc(p.personne)}" data-k="${k}"${p[k] ? ' checked' : ''}${fini ? ' disabled' : ''}></label>`).join('')}</div>`).join('') || '<p class="fs-n">Personne de nommé : ajoutez les personnes qui suivent la formation.</p>'}
      ${!fini ? `<div class="fs-q"><input class="db-sel" data-fs-personne="1" value="${esc(st.personne)}" placeholder="Ajouter une personne (prénom N.)"><button type="button" class="db-btn" data-fs-ajout="1">Ajouter</button></div>` : ''}
      <div class="fs-mep">${f.miseEnPlace ? `✓ Mise en place en magasin déclarée le ${esc(dfr(f.miseEnPlace.le))}${!fini ? ' <button type="button" class="fs-lien" data-fs-mep="0">annuler</button>' : ''}` : (!fini ? '<button type="button" class="db-btn" data-fs-mep="1">✓ Mise en place en magasin : c’est fait</button>' : '')}</div></div>`;
    const etat = fini ? `<div class="fs-ok">✓ Validée par ${esc(f.validation.par)} le ${esc(dfr(f.validation.le))} à ${esc(String(f.validation.le).slice(11, 16))}${f.validation.mot ? ' — « ' + esc(f.validation.mot) + ' »' : ''}. L’archive est dans votre coffre-fort, onglet Formation.</div>`
      : (f.statut === 'a_valider' ? '<div class="fs-att">Tout est fait : votre consultant relit et valide.</div>' : '');
    questions = [['Résume-moi cette formation en 5 lignes', f.titre], ['Quels sont les points critiques et pourquoi ?', f.titre], ['Prépare 3 questions pour vérifier l’équipe', f.titre]];
    if (f.categorie === 'nouveau_produit') { questions.push(['Quels allergènes mettre sur l’étiquette ?', f.titre]); }
    // Le titre part avec la question (le Professeur sait de quelle formation on parle) ; il n'est pas répété dessous.
    questions = questions.map(q => [q[0] + ' (« ' + f.titre + ' »)', '']);
    return `<div class="fs-fh"><button type="button" class="db-btn" data-fs-fermer="1">← Mon dossier</button><div><div class="fs-kk">${esc(f.code)} · ${esc(f.categorieLib)} · version ${esc(f.version)}</div><h2>${esc(f.titre)}</h2></div><span style="flex:1"></span>${statut(f)}${barre(f.avancement.pct)}</div>
      <div class="fs-lay"><section class="fs-carte fs-cont">${adapt}${t(C.objectif) ? `<p class="fs-obj">${esc(t(C.objectif))}</p>` : ''}${etapes || '<div class="fs-vide">Pas encore d’étape.</div>'}${crit}</section>
        <div class="fs-droite"><section class="fs-carte">${etat}${photos}${eq}${f.echeance ? `<p class="fs-n" style="padding:0 16px 12px">À terminer avant le ${esc(dfr(f.echeance))}.</p>` : ''}</section>${professeur(questions)}</div></div>`;
  }

  function rendre() {
    if (!st.el) { return; }
    const foc = document.activeElement && st.el.contains(document.activeElement) ? document.activeElement : null;
    const garde = foc && (foc.dataset.fsQ || foc.dataset.fsPersonne) ? [foc.dataset.fsQ ? 'fs-q' : 'fs-personne', foc.selectionStart] : null;
    let h = st.flash ? `<div class="fs-flash ${st.flash.ok ? 'ok' : 'ko'}">${esc(st.flash.texte)}</div>` : '';
    if (st.fc) { h += fiche(); }
    else if (st.err && !st.d) { h += `<div class="db-err">Le dossier de formation ne se lit pas : ${esc(st.err)}</div>`; }
    else if (!st.d) { h += '<div class="db-attente">Ouverture du dossier de formation…</div>'; }
    else { h += page(); }
    st.el.innerHTML = h;
    brancher();
    if (garde) { const i = st.el.querySelector(garde[0] === 'fs-q' ? '[data-fs-q]' : '[data-fs-personne]'); if (i) { i.focus(); try { i.setSelectionRange(garde[1], garde[1]); } catch (e) { /* sans curseur */ } } }
  }
  function brancher() {
    const E = st.el, on = (sel, ev, fn) => E.querySelectorAll(sel).forEach(n => n.addEventListener(ev, fn));
    on('[data-fs-ouvrir]', 'click', e => ouvrir(+e.currentTarget.dataset.fsOuvrir));
    on('[data-fs-fermer]', 'click', fermer);
    on('[data-fs-coffre]', 'click', () => { if (st.aller) { st.aller('coffre'); } });
    on('[data-fs-q]', 'input', e => { st.q = e.currentTarget.value; });
    on('[data-fs-q]', 'keydown', e => { if (e.key === 'Enter' && st.q.trim() && st.prof) { st.prof(st.q.trim(), 'libre'); st.q = ''; } });
    on('[data-fs-prof]', 'click', e => { const k = e.currentTarget.dataset.fsProf; if (!st.prof) { return; } if (k === 'q') { if (st.q.trim()) { st.prof(st.q.trim(), 'libre'); st.q = ''; } else { st.prof('', 'libre'); } } else if (questions[+k]) { st.prof(questions[+k][0], 'tuile'); } });
    on('[data-fs-personne]', 'input', e => { st.personne = e.currentTarget.value; });
    const ajouter = () => { const p = st.personne.trim(); if (!p) { return; } st.personne = ''; agir('/formation/continue/magasin/avancement', { personne: p }, () => p + ' suit la formation.'); };
    on('[data-fs-ajout]', 'click', ajouter);
    on('[data-fs-personne]', 'keydown', e => { if (e.key === 'Enter') { ajouter(); } });
    on('[data-fs-eq]', 'change', e => { const c = e.currentTarget; agir('/formation/continue/magasin/avancement', { personne: c.dataset.fsEq, [c.dataset.k]: c.checked }); });
    on('[data-fs-mep]', 'click', e => agir('/formation/continue/magasin/mise-en-place', { annuler: e.currentTarget.dataset.fsMep === '0' }, r => r.dossier && r.dossier.statut === 'a_valider' ? 'Tout est fait : votre consultant relit et valide.' : 'Noté.'));
    on('[data-fs-photo]', 'change', e => { const f = e.currentTarget.files && e.currentTarget.files[0]; if (f) { envoyerPhoto(f); } });
  }

  window.DashFormation = {
    racine(shop, o) {
      o = o || {};
      if (o.apiBase) { st.api = o.apiBase; }
      if (o.aller) { st.aller = o.aller; }
      if (o.professeur) { st.prof = o.professeur; }
      if (!st.el || String(st.shop) !== String(shop)) {
        st.shop = String(shop); st.el = document.createElement('div'); st.el.className = 'fs-racine';
        Object.assign(st, { d: null, err: null, fc: null, f: null, flash: null });
        rendre(); charger();
      }
      if (o.fc && +o.fc !== st.fc) { ouvrir(+o.fc); }
      return st.el;
    },
    relire() { if (!st.el) { return; } charger(); if (st.fc) { ouvrir(st.fc); } },
  };
})();
