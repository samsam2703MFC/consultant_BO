/* L'assistant de création des SOP (06/11/2026, client : « je dois surtout avoir dans l'édition des SOP un assistant de
 * création des SOP », « je dois pouvoir introduire des photos »).
 *
 *   #/sopnouvelle[/<moment>[/<titre>]]   cinq étapes : 1 Où et quand · 2 Le sujet · 3 Les étapes (texte, photo, vidéo,
 *                                        point critique) · 4 Points critiques et quiz · 5 Relire et créer.
 *
 * La SOP se crée d'un seul envoi (POST /formation/sop/fiche avec `contenu` et `moment`), « à valider » ; son classement
 * (compétences C-…, formations FO) part ensuite (PUT /formation/ref/standards/{id}). Les photos : réduites dans le
 * navigateur (1600 px, JPEG), envoyées par POST /formation/sop/photo, rangées « u/… » sur l'étape. Le brouillon de
 * l'assistant est gardé dans l'onglet (sessionStorage) jusqu'à la création. Le néerlandais est facultatif : la SOP
 * reste « NL à relire » et l'éditeur complet l'exige pour publier une nouvelle version.
 *
 * Branché sur window.FormationSop (écran, rail via portail.js) sans toucher à ses entrées ; styles dans
 * assistant-sop.css, sur les jetons du design system (ds.css).
 */
(function (FS) {
  'use strict';
  if (!FS) { return; }
  let C = null;
  const CLE = 'fm.sop.assistant';
  const MOMENTS = [['ouverture', 'Ouverture'], ['journee', 'Journée'], ['apres-midi', 'Après-midi'], ['fermeture', 'Fermeture'], ['', 'Au besoin']];
  const POSTES = [['vente', 'Vente'], ['production', 'Production'], ['cuisson', 'Cuisson'], ['nettoyage', 'Nettoyage']];
  const TYPES = ['SOP', 'Checklist', 'Mémo', 'Fiche'];
  const ETAPES = [['ou', 'Où et quand'], ['sujet', 'Le sujet'], ['etapes', 'Les étapes'], ['quiz', 'Points critiques et quiz'], ['fin', 'Relire et créer']];
  const esc = v => (C ? C.esc(v) : String(v == null ? '' : v));
  const vide = () => ({ etape: 1, moment: 'journee', poste: 'vente', type: 'SOP', dossier: '', code: '', titreFr: '', titreNl: '', objectifFr: '', pourFr: '', duree: '',
    competences: [], formations: [], etapes: [{ titre: '', texte: '', critique: false, photo: null, video: '' }], points: [''], quiz: [] });
  // A : le brouillon ; R : le référentiel (compétences, formations) et les familles des SOP ; X : l'état de l'écran.
  let A = null;
  const R = { ref: null, dossiers: null, charge: false, err: null };
  const X = { occupe: false, envoi: null, err: null, fait: null };
  try { A = JSON.parse(sessionStorage.getItem(CLE) || 'null'); } catch (e) { A = null; }
  if (!A || typeof A !== 'object' || !Array.isArray(A.etapes)) { A = vide(); }
  function garder() { try { sessionStorage.setItem(CLE, JSON.stringify(A)); } catch (e) { /* stockage indisponible : le brouillon vit le temps de l'onglet */ } }
  const photoUrl = p => String(p).startsWith('u/') ? C.API + '/formation/sop/photo/' + encodeURIComponent(String(p).slice(2)) : 'sop/photos/' + p;
  function toast(m) { const el = document.createElement('div'); el.className = 'sop-toast'; el.textContent = m; document.body.appendChild(el); setTimeout(() => el.remove(), 3200); }

  /* ── Lectures ── */
  function charger() {
    if ((R.ref && R.dossiers) || R.charge) { return; }
    R.charge = true;
    Promise.all([C.lire('/formation/ref?detail=1', 30000), C.lire('/formation/sop/liste', 30000)])
      .then(([ref, l]) => { R.ref = ref; R.dossiers = l.dossiers || []; R.charge = false; if (!A.dossier && R.dossiers[0]) { A.dossier = R.dossiers[0].id; } C.rendre(true); },
        e => { R.err = e.message; R.charge = false; C.rendre(true); });
  }
  /** Une adresse #/sopnouvelle/<moment>/<titre> préremplit le brouillon (depuis « à rédiger » de l'écran Opérationnel). */
  let adresseLue = '';
  function depuisAdresse() {
    const a = C.S.route.a;
    if (!a.length || location.hash === adresseLue) { return; }
    adresseLue = location.hash;   // une seule fois par adresse : l'écran se redessine souvent
    const m = MOMENTS.some(x => x[0] === a[0]) ? a[0] : null;
    const titre = a[1] ? decodeURIComponent(a[1]) : '';
    // Un brouillon déjà commencé n'est pas écrasé : seulement un brouillon vide prend le moment et le titre proposés.
    if (m !== null && !A.titreFr && !A.etapes.some(e => e.texte)) { A.moment = m; if (titre) { A.titreFr = titre; } A.etape = 1; garder(); C.rendre(true); }
  }

  /* ── L'écran ── */
  function ecran() {
    const K = 'Rédaction des SOP';
    if (X.fait) {
      const f = X.fait;
      return { titre: 'SOP créée', kicker: K, corps: `<section class="card as-fin"><div class="as-fin__t">${esc(f.titre)}</div>
        <p class="muted">Créée « à valider » ${f.moment ? 'pour l’onglet « ' + esc(f.onglet) + ' » de la tablette' : ''} · ${f.etapes} étape(s)${f.photos ? ' · ' + f.photos + ' photo(s)' : ''}.
        Le néerlandais et la publication se font dans l’éditeur de la SOP.</p>
        <div class="as-act"><a class="btn btn--primary" href="#/sop/${encodeURIComponent(f.id)}">Ouvrir la SOP</a><a class="btn" href="#/sop/${encodeURIComponent(f.id)}/editer">Traduire et compléter</a>
        <button type="button" class="btn btn--ghost" data-as="nouvelle">Créer une autre SOP</button></div></section>` };
    }
    if (!R.ref || !R.dossiers) { return { titre: 'Nouvelle SOP', kicker: K, corps: R.err ? `<p class="error">${esc(R.err)}</p>` : '<p class="muted">Lecture du référentiel…</p>' }; }
    const n = A.etape;
    const rail = `<ol class="as-rail">${ETAPES.map(([k, l], i) => `<li><button type="button" data-as="aller" data-v="${i + 1}" class="${i + 1 === n ? 'is-on' : i + 1 < n ? 'is-fait' : ''}">
      <span class="as-rail__n">${i + 1 < n ? '✓' : i + 1}</span>${esc(l)}</button></li>`).join('')}</ol>`;
    const vues = [vueOu, vueSujet, vueEtapes, vueQuiz, vueFin];
    const pied = `<div class="as-pied">${n > 1 ? '<button type="button" class="btn" data-as="prec">← Précédent</button>' : '<span></span>'}
      <span class="muted fm-small">Brouillon gardé dans cet onglet · <button type="button" class="linklike" data-as="effacer">tout effacer</button></span>
      ${n < 5 ? '<button type="button" class="btn btn--primary" data-as="suiv">Suivant →</button>' : `<button type="button" class="btn btn--primary" data-as="creer" ${X.occupe ? 'disabled' : ''}>${X.occupe ? 'Création…' : 'Créer la SOP'}</button>`}</div>`;
    const corps = `<div class="as-lay">${rail}<section class="card as-form"><h2 class="as-titre">${n} · ${esc(ETAPES[n - 1][1])}</h2>${X.err ? `<p class="error" role="alert">${esc(X.err)}</p>` : ''}${vues[n - 1]()}${pied}</section>${apercu()}</div>`;
    return { titre: 'Nouvelle SOP', kicker: K, corps, large: true };
  }
  const pill = (k, v, l, on) => `<button type="button" class="choice-pill as-pill${on ? ' is-on' : ''}" data-as="choix" data-k="${k}" data-v="${esc(v)}">${esc(l)}</button>`;
  const champ = (k, lib, ph, opt) => `<label class="as-champ"><span>${esc(lib)}${opt && opt.requis ? ' *' : ''}</span>${opt && opt.zone
    ? `<textarea class="sop-in${opt.requis && !A[k] ? ' is-vide' : ''}" data-asin="${k}" placeholder="${esc(ph)}" rows="${opt.rows || 3}">${esc(A[k])}</textarea>`
    : `<input class="sop-in${opt && opt.requis && !A[k] ? ' is-vide' : ''}" data-asin="${k}" placeholder="${esc(ph)}" value="${esc(A[k])}">`}${opt && opt.aide ? `<small>${esc(opt.aide)}</small>` : ''}</label>`;

  function vueOu() {
    return `<div class="as-bloc"><h3>Le moment</h3><p class="muted fm-small">L’onglet de la tablette où la SOP sera rangée.</p><div class="as-pills">${MOMENTS.map(([v, l]) => pill('moment', v, l, A.moment === v)).join('')}</div></div>
      <div class="as-bloc"><h3>Le poste</h3><div class="as-pills">${POSTES.map(([v, l]) => pill('poste', v, l, A.poste === v)).join('')}</div></div>
      <div class="as-bloc"><h3>Le type</h3><div class="as-pills">${TYPES.map(v => pill('type', v, v, A.type === v)).join('')}</div></div>
      <div class="as-deux"><label class="as-champ"><span>Famille</span><select class="fm-select" data-asch="dossier">${R.dossiers.map(d => `<option value="${esc(d.id)}"${A.dossier === d.id ? ' selected' : ''}>${esc(d.id + ' · ' + ((d.nom && d.nom.fr) || ''))}</option>`).join('')}</select></label>
        ${champ('code', 'Code', 'ex. SOP-WKL-006', { aide: 'Facultatif : il sert aussi d’identifiant.' })}</div>`;
  }
  function vueSujet() {
    const comp = R.ref.competences || [], fos = R.ref.formations || [];
    const toks = (k, liste, nom) => `<div class="rf-toks as-toks">${A[k].map(c => `<span class="rf-tok" title="${esc(nom(c))}">${esc(c)}<button type="button" class="btn btn--icon" data-as="tokDel" data-k="${k}" data-v="${esc(c)}" aria-label="Retirer ${esc(c)}">×</button></span>`).join('')}
      <select class="fm-select as-sel" data-asch="tok" data-k="${k}"><option value="">Ajouter…</option>${liste.filter(x => !A[k].includes(x.code)).map(x => `<option value="${esc(x.code)}">${esc(x.code + ' · ' + x.fr)}</option>`).join('')}</select></div>`;
    return `${champ('titreFr', 'Titre', 'ex. Réception de marchandise', { requis: true })}
      ${champ('titreNl', 'Titre en néerlandais', 'facultatif — sinon « à traduire »')}
      ${champ('objectifFr', 'Objectif', 'Ce que la SOP permet de réussir, en une ou deux phrases.', { zone: true })}
      <div class="as-deux">${champ('pourFr', 'Pour qui', 'ex. Vendeurs, responsable du magasin')}${champ('duree', 'Durée', 'ex. 15 minutes')}</div>
      <div class="as-bloc"><h3>Compétences évaluées <i>codes C-… du référentiel</i></h3>${toks('competences', comp, c => (comp.find(x => x.code === c) || {}).fr || '')}</div>
      <div class="as-bloc"><h3>Formations <i>FO-…</i></h3>${toks('formations', fos, c => (fos.find(x => x.code === c) || {}).fr || '')}</div>`;
  }
  function vueEtapes() {
    return A.etapes.map((e, i) => `<div class="as-et${e.critique ? ' is-crit' : ''}">
      <div class="as-et__h"><span class="as-et__n">${i + 1}</span><input class="sop-in as-et__t" data-asin="etapes.${i}.titre" placeholder="Titre de l’étape (facultatif)" value="${esc(e.titre)}">
        <span class="as-et__ac"><button type="button" class="btn btn--icon" data-as="etMove" data-i="${i}" data-d="-1" aria-label="Monter l’étape" ${i === 0 ? 'disabled' : ''}>↑</button>
        <button type="button" class="btn btn--icon" data-as="etMove" data-i="${i}" data-d="1" aria-label="Descendre l’étape" ${i === A.etapes.length - 1 ? 'disabled' : ''}>↓</button>
        <button type="button" class="btn btn--icon btn--danger" data-as="etDel" data-i="${i}" aria-label="Retirer l’étape" ${A.etapes.length === 1 ? 'disabled' : ''}>×</button></span></div>
      <textarea class="sop-in${e.texte ? '' : ' is-vide'}" data-asin="etapes.${i}.texte" rows="3" placeholder="Ce qu’il faut faire, simplement.">${esc(e.texte)}</textarea>
      <div class="as-et__med">${e.photo ? `<span class="as-photo"><img src="${esc(photoUrl(e.photo))}" alt=""><button type="button" class="btn btn--icon" data-as="photoDel" data-i="${i}" aria-label="Retirer la photo">×</button></span>`
        : X.envoi === i ? '<span class="as-photo as-photo--envoi">Envoi de la photo…</span>' : ''}
        <label class="btn btn--sm as-photo__btn">${e.photo ? '↻ Changer la photo' : '＋ Photo'}<input type="file" accept="image/jpeg,image/png,image/webp" data-asfile="${i}" hidden></label>
        <input class="sop-in as-video" data-asin="etapes.${i}.video" placeholder="Vidéo : lien https (YouTube, Vimeo…)" value="${esc(e.video)}">
        <button type="button" class="btn btn--sm as-crit${e.critique ? ' is-on' : ''}" data-as="crit" data-i="${i}">! Point critique</button></div></div>`).join('')
      + '<button type="button" class="btn as-ajout" data-as="etAdd">＋ Étape</button>';
  }
  function vueQuiz() {
    const pts = A.points.map((p, i) => `<div class="as-ligne"><input class="sop-in" data-asin="points.${i}" placeholder="Un point à ne jamais manquer" value="${esc(p)}">
      <button type="button" class="btn btn--icon btn--danger" data-as="ptDel" data-i="${i}" aria-label="Retirer">×</button></div>`).join('');
    const qz = A.quiz.map((q, i) => `<div class="as-q"><div class="as-ligne"><input class="sop-in" data-asin="quiz.${i}.question" placeholder="Question ${i + 1}" value="${esc(q.question)}">
        <button type="button" class="btn btn--icon btn--danger" data-as="qDel" data-i="${i}" aria-label="Retirer la question">×</button></div>
      ${q.choix.map((c, j) => `<label class="as-choix"><input type="radio" name="as-bonne-${i}" data-asch="bonne" data-i="${i}" value="${j}" ${q.bonne === j ? 'checked' : ''}>
        <input class="sop-in" data-asin="quiz.${i}.choix.${j}" placeholder="Réponse ${j + 1}${j === 0 ? '' : ''}" value="${esc(c)}"></label>`).join('')}
      <p class="muted fm-small">La bonne réponse : le rond coché.</p></div>`).join('');
    return `<div class="as-bloc"><h3>Points critiques <i>en rouge sur la tablette</i></h3>${pts}<button type="button" class="btn btn--sm" data-as="ptAdd">＋ Point critique</button></div>
      <div class="as-bloc"><h3>Quiz <i>à la fin de la lecture, un exercice</i></h3>${qz || '<p class="muted">Pas de quiz : la SOP se lit seulement.</p>'}<button type="button" class="btn btn--sm" data-as="qAdd">＋ Question</button></div>`;
  }
  function manques() {
    const m = [];
    if (!A.titreFr.trim()) { m.push(['sujet', 'le titre']); }
    if (!A.etapes.some(e => e.texte.trim())) { m.push(['etapes', 'au moins une étape avec son texte']); }
    A.quiz.forEach((q, i) => { if (q.question.trim() && q.choix.filter(c => c.trim()).length < 2) { m.push(['quiz', 'deux réponses à la question ' + (i + 1)]); } });
    return m;
  }
  function vueFin() {
    const m = manques();
    const mom = (MOMENTS.find(x => x[0] === A.moment) || [0, 'Au besoin'])[1];
    return `${m.length ? `<div class="fm-banner fm-banner--warn"><span>Il manque : ${m.map(x => esc(x[1])).join(', ')}.</span></div>` : ''}
      <dl class="as-recap"><dt>Moment</dt><dd>${esc(mom)}</dd><dt>Poste</dt><dd>${esc((POSTES.find(x => x[0] === A.poste) || [0, A.poste])[1])}</dd><dt>Type</dt><dd>${esc(A.type)}</dd>
        <dt>Titre</dt><dd>${esc(A.titreFr || '—')}${A.titreNl ? ' · ' + esc(A.titreNl) : ' <span class="muted">· NL à traduire</span>'}</dd>
        <dt>Étapes</dt><dd>${A.etapes.filter(e => e.texte.trim()).length} · ${A.etapes.filter(e => e.photo).length} photo(s) · ${A.etapes.filter(e => e.video.trim()).length} vidéo(s) · ${A.etapes.filter(e => e.critique).length} critique(s)</dd>
        <dt>Quiz</dt><dd>${A.quiz.filter(q => q.question.trim()).length} question(s)</dd>
        <dt>Classement</dt><dd>${esc(A.competences.concat(A.formations).join(', ') || '—')}</dd></dl>
      <p class="muted fm-small">La SOP est créée « à valider » : elle se lit déjà sur la tablette ; le néerlandais et la publication se font ensuite dans son éditeur.</p>`;
  }
  /** À droite : la SOP telle que la tablette la montrera. */
  function apercu() {
    const et = A.etapes.filter(e => e.texte.trim() || e.photo);
    return `<aside class="card as-apercu"><div class="as-apercu__k">Sur la tablette · ${esc((MOMENTS.find(x => x[0] === A.moment) || [0, 'Au besoin'])[1])}</div>
      <div class="as-apercu__t">${esc(A.titreFr || 'Titre de la SOP')}</div>${A.objectifFr ? `<p class="as-apercu__o">${esc(A.objectifFr)}</p>` : ''}
      ${et.map((e, i) => `<div class="as-apercu__e${e.critique ? ' is-crit' : ''}"><span>${i + 1}</span><div><b>${esc(e.titre || '')}</b>${esc(e.texte)}${e.video.trim() ? '<small>▶ vidéo</small>' : ''}</div>${e.photo ? `<img src="${esc(photoUrl(e.photo))}" alt="">` : ''}</div>`).join('') || '<p class="muted fm-small">Les étapes apparaîtront ici.</p>'}
      ${A.points.filter(p => p.trim()).length ? `<div class="as-apercu__pc">${A.points.filter(p => p.trim()).map(p => `<div>! ${esc(p)}</div>`).join('')}</div>` : ''}</aside>`;
  }

  /* ── Les gestes ── */
  function suivant() {
    X.err = null;
    if (A.etape === 2 && !A.titreFr.trim()) { X.err = 'Le titre est attendu.'; return C.rendre(true); }
    if (A.etape === 3 && !A.etapes.some(e => e.texte.trim())) { X.err = 'Au moins une étape, avec son texte.'; return C.rendre(true); }
    A.etape = Math.min(5, A.etape + 1); garder(); C.rendre(true); window.scrollTo(0, 0);
  }
  async function photo(i, fichier) {
    if (!fichier) { return; }
    if (!/^image\/(jpeg|png|webp)$/.test(fichier.type)) { return toast('Une photo JPEG, PNG ou WebP.'); }
    X.envoi = i; C.rendre(true);
    try {
      const data = await reduire(fichier);
      const r = await C.appel('POST', '/formation/sop/photo', { data }, 60000);
      A.etapes[i].photo = r.photo; garder();
    } catch (e) { toast('Photo non envoyée : ' + e.message); }
    X.envoi = null; C.rendre(true);
  }
  /** La photo réduite dans le navigateur (1600 px de grand côté, JPEG) : légère à envoyer, le serveur la relit. */
  function reduire(f) {
    return new Promise((ok, ko) => {
      const url = URL.createObjectURL(f), im = new Image();
      im.onload = () => {
        const k = Math.min(1, 1600 / Math.max(im.naturalWidth, im.naturalHeight));
        const c = document.createElement('canvas'); c.width = Math.round(im.naturalWidth * k); c.height = Math.round(im.naturalHeight * k);
        const g = c.getContext('2d'); g.fillStyle = '#fff'; g.fillRect(0, 0, c.width, c.height); g.drawImage(im, 0, 0, c.width, c.height);
        URL.revokeObjectURL(url); ok(c.toDataURL('image/jpeg', 0.82));
      };
      im.onerror = () => { URL.revokeObjectURL(url); ko(new Error('photo illisible')); };
      im.src = url;
    });
  }
  async function creer() {
    const m = manques();
    if (m.length) { X.err = 'Il manque : ' + m.map(x => x[1]).join(', ') + '.'; return C.rendre(true); }
    X.occupe = true; X.err = null; C.rendre(true);
    const bi = v => ({ fr: String(v || '').trim(), nl: '' });
    const etapes = A.etapes.filter(e => e.texte.trim()).map((e, i) => ({ numero: String(i + 1), titre: e.titre.trim() ? bi(e.titre) : null, texte: bi(e.texte),
      critique: !!e.critique, photo: e.photo || null, video: e.video.trim() || null }));
    const quiz = A.quiz.filter(q => q.question.trim() && q.choix.filter(c => c.trim()).length >= 2).map(q => {
      const choix = q.choix.map(c => c.trim()).filter(Boolean);
      const bonne = Math.max(0, choix.indexOf(String(q.choix[q.bonne] || '').trim()));
      return { question: bi(q.question), choix: { fr: choix, nl: [] }, bonne };
    });
    const corps = { code: A.code.trim() || null, dossier: A.dossier, type: A.type, poste: A.poste, moment: A.moment || null,
      titre: { fr: A.titreFr.trim(), nl: A.titreNl.trim() }, objectif: bi(A.objectifFr),
      contenu: { pour: bi(A.pourFr), duree: A.duree.trim() || null, sections: [{ titre: { fr: 'Marche à suivre', nl: 'Werkwijze' }, etapes }],
        points_critiques: A.points.map(p => p.trim()).filter(Boolean).map(bi), quiz } };
    try {
      const r = await C.appel('POST', '/formation/sop/fiche', corps, 60000);
      if (A.competences.length || A.formations.length) {
        try { await C.appel('PUT', '/formation/ref/standards/' + encodeURIComponent(r.id), { competences: A.competences, formations: A.formations }); }
        catch (e) { toast('SOP créée, mais le classement n’a pas été enregistré : ' + e.message); }
      }
      const onglet = { ouverture: 'Ouverture', fermeture: 'Fermeture' }[A.moment] || 'Journée de travail';
      X.fait = { id: r.id, titre: A.titreFr.trim(), moment: A.moment, onglet, etapes: etapes.length, photos: etapes.filter(e => e.photo).length };
      A = vide(); garder();
    } catch (e) { X.err = 'SOP non créée : ' + e.message; }
    X.occupe = false; C.rendre(true); window.scrollTo(0, 0);
  }
  const chemin = (k, v) => { const p = k.split('.'); let o = A; while (p.length > 1) { o = o[p.shift()]; } o[p[0]] = v; };

  function brancher(root) {
    root.addEventListener('click', ev => {
      const b = ev.target.closest && ev.target.closest('[data-as]');
      if (!b) { return; }
      const d = b.dataset, i = +d.i;
      switch (d.as) {
        case 'aller': X.err = null; if (+d.v <= A.etape || (A.titreFr.trim() && +d.v <= 4) || !manques().length) { A.etape = +d.v; } garder(); return C.rendre(true);
        case 'suiv': return suivant();
        case 'prec': X.err = null; A.etape = Math.max(1, A.etape - 1); garder(); return C.rendre(true);
        case 'choix': A[d.k] = d.v; garder(); return C.rendre(true);
        case 'tokDel': A[d.k] = A[d.k].filter(x => x !== d.v); garder(); return C.rendre(true);
        case 'etAdd': A.etapes.push({ titre: '', texte: '', critique: false, photo: null, video: '' }); garder(); return C.rendre(true);
        case 'etDel': if (A.etapes.length > 1 && (!A.etapes[i].texte || confirm('Retirer l’étape ' + (i + 1) + ' ?'))) { A.etapes.splice(i, 1); garder(); } return C.rendre(true);
        case 'etMove': { const j = i + +d.d; if (j >= 0 && j < A.etapes.length) { const x = A.etapes[i]; A.etapes[i] = A.etapes[j]; A.etapes[j] = x; garder(); } return C.rendre(true); }
        case 'crit': A.etapes[i].critique = !A.etapes[i].critique; garder(); return C.rendre(true);
        case 'photoDel': A.etapes[i].photo = null; garder(); return C.rendre(true);
        case 'ptAdd': A.points.push(''); garder(); return C.rendre(true);
        case 'ptDel': A.points.splice(i, 1); garder(); return C.rendre(true);
        case 'qAdd': A.quiz.push({ question: '', choix: ['', '', ''], bonne: 0 }); garder(); return C.rendre(true);
        case 'qDel': A.quiz.splice(i, 1); garder(); return C.rendre(true);
        case 'creer': return creer();
        case 'nouvelle': X.fait = null; A = vide(); if (R.dossiers && R.dossiers[0]) { A.dossier = R.dossiers[0].id; } garder(); return C.rendre(true);
        case 'effacer': if (confirm('Effacer tout le brouillon de cette SOP ?')) { A = vide(); if (R.dossiers && R.dossiers[0]) { A.dossier = R.dossiers[0].id; } X.err = null; garder(); } return C.rendre(true);
        default:
      }
    });
    // La frappe met le brouillon à jour sans redessiner (le curseur reste) ; quitter le champ met l'aperçu à jour.
    root.addEventListener('input', ev => { const t = ev.target, k = t.getAttribute && t.getAttribute('data-asin'); if (k) { chemin(k, t.value); garder(); } });
    root.addEventListener('change', ev => {
      const t = ev.target;
      // Quitter un champ ne redessine que l'aperçu : redessiner tout l'écran perdrait le clic qui a fait quitter le champ.
      if (t.getAttribute && t.getAttribute('data-asin')) { const ap = root.querySelector('.as-apercu'); if (ap) { ap.outerHTML = apercu(); } return; }
      if (t.hasAttribute && t.hasAttribute('data-asfile')) { return photo(+t.getAttribute('data-asfile'), t.files && t.files[0]); }
      const k = t.getAttribute && t.getAttribute('data-asch');
      if (!k) { return; }
      if (k === 'dossier') { A.dossier = t.value; }
      if (k === 'tok' && t.value) { const l = A[t.dataset.k]; if (!l.includes(t.value)) { l.push(t.value); } }
      if (k === 'bonne') { A.quiz[+t.dataset.i].bonne = +t.value; }
      garder(); C.rendre(true);
    });
  }

  FS.ecrans.sopnouvelle = ecran;
  FS.icones.sopnouvelle = 'M12 5v14M5 12h14';
  const initAvant = FS.init, apresAvant = FS.apres;
  FS.init = function (ctx, root) { initAvant.call(FS, ctx, root); C = ctx; brancher(root); };
  FS.apres = function (e, a) { apresAvant.call(FS, e, a); if (e === 'sopnouvelle') { depuisAdresse(); charger(); } };
})(window.FormationSop);
