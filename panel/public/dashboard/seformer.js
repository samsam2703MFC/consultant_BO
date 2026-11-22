/* Dashboard magasin › Se former › Formations et suivi — l'accueil et la méga-barre (22/11/2026, maquettes
 * formation/docs/maquettes/formations-et-suivi-v2/).
 *
 * Sam : « dans Formations et suivi, j'aurais préféré qu'on tombe sur l'accueil de la formation (formation/#/accueil),
 * avec ses données mais pas le rail ; et qu'il ait accès ensuite, via une méga search bar, à tous les renseignements :
 * tout le catalogue et son contenu — un badge rectangle aux coins arrondis au nom et au code couleur du catalogue, ce qui
 * est trouvé, le lien vers où il mène, et la photo si nécessaire ».
 *   - l'ACCUEIL du module Formation : la phrase et le tableau Formation · Manuel · SOP · Checklist (contenu repris de
 *     formation/public/formation/portail.js, ecranAccueil), les nombres lus dans l'index ;
 *   - la MÉGA-BARRE : une recherche sur tout le catalogue — formations FO-xx, manuel opératoire, standards (SOP, mémos,
 *     fiches), checklists, manuel du réseau, formation continue — dans les titres, les codes, les objectifs et le texte des
 *     étapes, sans tenir compte des accents ; les résultats avec l'étiquette du catalogue (sa couleur de couverture), le
 *     mot trouvé dans son extrait, où mène le lien, la photo ; les catalogues à gauche ; l'aperçu à droite ;
 *   - le LECTEUR : un document s'ouvre en tiroir, ici, par la route publique de la tablette (étapes, photos, points
 *     critiques) ; « Feuilleter dans le catalogue » mène au catalogue des manuels.
 *   Route : GET …/formation/recherche (formation/src/formation_recherche.php), lue une fois.
 *
 * `window.DashSeFormer.racine(shop, {apiBase, professeur, formationContinue})` rend l'élément, gardé d'un rendu à
 * l'autre ; `formationContinue(code)` ouvre une formation continue du dossier du magasin (formation.js), sinon false.
 */
(function () {
  'use strict';
  const esc = v => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  /** Sans accents ni capitales, CARACTÈRE PAR CARACTÈRE : les positions restent celles du texte d'origine (surlignage). */
  const norm = s => Array.from(String(s || '')).map(c => { const b = c.normalize('NFD').replace(/[̀-ͯ]/g, ''); return (b.length === 1 ? b : c).toLowerCase(); }).join('');
  const MOMENT = { ouverture: 'Ouverture', journee: 'Journée de travail', 'apres-midi': 'Après-midi', fermeture: 'Fermeture' };
  const MANUEL = { man: 'operatoire', std: 'standards', chk: 'checklists', res: 'reseau' };
  const AC_ICO = { fo: 'M22 10L12 5 2 10l10 5 10-5zM6 12v5c3 2 9 2 12 0v-5', man: 'M2 4h7a3 3 0 0 1 3 3v14a2 2 0 0 0-2-2H2zM22 4h-7a3 3 0 0 0-3 3v14a2 2 0 0 1 2-2h8z',
    sop: 'M7 2.5h10a2 2 0 0 1 2 2v15a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-15a2 2 0 0 1 2-2zM9 8h6M9 12h6M9 16h3', chk: 'M4 6l2 2 3-3M4 13l2 2 3-3M4 20l2 2 3-3M12 7h8M12 14h8M12 21h8' };
  const AC_LIGNES = [['quoi', 'C’est quoi ?'], ['qui', 'Pour qui ?'], ['quand', 'Quand ?'], ['ou', 'Où ?'], ['comment', 'Comment ?'], ['pourquoi', 'Pourquoi ?'], ['valide', 'Comment c’est validé ?'], ['code', 'Le code'], ['ex', 'Exemple']];
  /** Les quatre colonnes de l'accueil du module (portail.js › ecranAccueil) ; `cat` : le catalogue que la colonne ouvre.
   * Les couleurs : celles des couvertures du catalogue (Sam, 22/11/2026 : « gardons les codes couleur des catalogues ») ;
   * les formations, hors catalogue, en contour Ruby, comme leur étiquette. */
  const COLS = [
    { k: 'fo', cat: 'fo', nom: 'Formations', mot: 'Apprendre', coul: '#8D1D2C', fd: '#FFFFFF', en: '#8D1D2C', ct: true, n: i => nb(i.catalogues.fo, 'formation', 'formations'),
      quoi: 'Un parcours pour acquérir les compétences d’un poste.', qui: 'Chaque poste, de l’opérateur au franchisé : chaque formation dit pour quel poste elle est obligatoire ou recommandée.',
      quand: 'À une phase : avant l’ouverture, pendant les 6 semaines de lancement, puis en formation continue (recyclages).', ou: 'Ce portail, menu Formation : Formations, Postes, Compétences.',
      comment: 'Un formateur ou le business coach la donne ; elle renvoie aux articles du manuel et aux SOP à lire et à faire.', pourquoi: 'Pour que chacun sache faire son métier selon les standards du réseau.',
      valide: 'Quand ses compétences sont acquises, elles sont cochées au panel pour la personne.', code: 'FO-01, FO-02…', ex: ['FO-01 · Vendeur (initiale + recyclage)', 'fo:FO-01'] },
    { k: 'man', cat: 'man', nom: 'Manuel opératoire', mot: 'Comprendre', coul: '#8D1D2C', fd: '#8D1D2C', en: '#F5EFE4', n: i => nb(i.types.Article, 'article', 'articles'),
      quoi: 'Les articles qui expliquent le pourquoi : la marque, les règles du réseau, les outils, les chiffres.', qui: 'Toute l’équipe ; certains articles sont réservés au franchisé et au responsable (cadenas or, PIN).',
      quand: 'Avant la première journée, puis à relire quand une règle change (nouvelle version).', ou: 'La tablette du magasin, onglet Manuel ; le portail, fiche de chaque formation.',
      comment: 'Il se lit partie par partie (« Partie 3 sur 9 »), avec une photo par partie et parfois un petit quiz.', pourquoi: 'Pour comprendre avant d’agir : on applique mieux une règle dont on connaît la raison.',
      valide: '« n sur N parties lues » ; le quiz, s’il y en a un.', code: '0.0.01, 0.0.02… (0 : le manuel, .0 : sans moment)', ex: ['0.0.14 · Les checklists du magasin : la structure', 'doc:art-chk-01'] },
    { k: 'sop', cat: 'std', nom: 'SOP', mot: 'Faire', coul: '#222222', fd: '#222222', en: '#F5EFE4', n: i => nb(i.types.SOP, 'SOP', 'SOP'),
      quoi: 'Le geste exact, étape par étape : comment on fait, ici, chez L’Atelier By.', qui: 'La personne du poste concerné (vente, cuisson, production, nettoyage).',
      quand: 'Au moment du geste : à l’ouverture (0.1), en journée (0.2), l’après-midi (0.3), à la fermeture (0.4).', ou: 'La tablette du poste, onglets Ouverture, Journée de travail, Fermeture ; en FR et en NL.',
      comment: 'Une étape à la fois, avec sa photo ; les points critiques en rouge ; un quiz à la fin.', pourquoi: 'Pour que le geste soit le même dans chaque magasin, quel que soit celui qui le fait.',
      valide: 'Le responsable l’a « vu faire » et valide avec son PIN ; toutes les SOP d’une compétence vues → compétence cochée au panel.', code: '0.1.01 (0 : SOP · 1 : ouverture · 01 : numéro)', ex: ['0.1.01 · Ouverture du magasin et des comptoirs', 'doc:ouv-mag'] },
    { k: 'chk', cat: 'chk', nom: 'Checklists', mot: 'Vérifier', coul: '#78554B', fd: '#78554B', en: '#F5EFE4', n: i => nb(i.types.Checklist, 'checklist', 'checklists'),
      quoi: 'La liste des tâches à cocher chaque jour, à heure fixe.', qui: 'L’équipe du magasin, selon son poste (« Fait par ») ; le contrôleur note les photos.',
      quand: 'Chaque jour, ou chaque semaine, ou chaque mois, à l’heure de la checklist.', ou: 'L’application du magasin ; le suivi dans le dashboard magasin, onglet Tâches.',
      comment: 'On coche chaque tâche ; une tâche à photo n’est faite que si la photo est rendue.', pourquoi: 'Pour vérifier chaque jour que la SOP a bien été appliquée.',
      valide: 'Les photos sont notées de 1 à 5 ; sous 4, c’est une non-conformité à corriger.', code: '1.1.01 (1 : qui, de 1 Vente à 5 Gestion · 1 : quand · 01 : numéro)', ex: ['1.1.01 · Ouverture de la boutique', 'doc:chk-ac-01'] },
  ];
  function nb(n, un, plus) { return n == null ? '' : n + ' ' + (n > 1 ? plus : un); }

  const st = { shop: null, api: '../api/cockpit', el: null, idx: null, err: null, q: '', cat: '', sel: 0, ouvert: false, lect: null, o: {} };

  /* --- l'index ------------------------------------------------------------------------------------------------------ */
  function charger() {
    fetch(st.api + '/formation/recherche?shop=' + encodeURIComponent(st.shop), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(r => r.json().catch(() => ({})).then(j => (r.ok ? j : Promise.reject(new Error((j && j.error) || ('HTTP ' + r.status))))))
      .then(d => { st.idx = preparer(d); st.err = null; }).catch(e => { st.err = e.message; }).finally(() => { rendreHaut(); rendreAccueil(); });
  }
  /** Les documents, les formations et les domaines en une seule liste ; chaque champ cherché est gardé normalisé. */
  function preparer(d) {
    const cats = {}; (d.catalogues || []).forEach(c => { cats[c.cle] = c; });
    const L = [];
    (d.docs || []).forEach(x => L.push(Object.assign({ k: 'doc' }, x)));
    (d.formations || []).forEach(f => L.push({ k: 'fo', id: f.code, cat: 'fo', type: 'Formation', code: f.code, titre: f.titre,
      objectif: [f.famille ? 'Famille ' + f.famille : '', f.competences ? f.competences + ' compétence' + (f.competences > 1 ? 's' : '') + ' à acquérir' : ''].filter(Boolean).join(' · '),
      texte: '', etapes: [], formation: f }));
    (d.domaines || []).forEach(x => L.push({ k: 'res', id: x.code, cat: 'res', type: 'Domaine', code: x.code, titre: x.titre,
      objectif: (x.souscats || []).length + ' sous-catégorie' + ((x.souscats || []).length > 1 ? 's' : ''), texte: (x.souscats || []).join(' · '), etapes: [], domaine: x }));
    L.forEach(x => { x.n = { titre: norm(x.titre), code: norm(x.code), obj: norm(x.objectif), txt: norm(x.texte) }; });
    return { items: L, cats, catalogues: Object.fromEntries((d.catalogues || []).map(c => [c.cle, c.n])), types: d.types || {}, liste: d.catalogues || [] };
  }
  /** Les résultats : chaque mot cherché doit être quelque part ; le titre compte plus que le code, l'objectif, le texte. */
  function chercher() {
    if (!st.idx) { return []; }
    const mots = norm(st.q).split(/[\s,;:'’()«»·-]+/).filter(m => m.length >= 2);
    const L = st.idx.items.filter(x => !st.cat || x.cat === st.cat);
    if (!mots.length) { return st.cat ? L.slice().sort((a, b) => String(a.code).localeCompare(String(b.code), 'fr', { numeric: true })).map(x => ({ x, s: 0, mots })) : []; }
    const phrase = norm(st.q).trim();
    const R = [];
    L.forEach(x => {
      let s = 0;
      for (const m of mots) {
        const t = x.n.titre.indexOf(m), c = x.n.code.indexOf(m), o = x.n.obj.indexOf(m), z = x.n.txt.indexOf(m);
        if (t < 0 && c < 0 && o < 0 && z < 0) { return; }
        // Le titre d'abord (au début du titre, ou une formation, un domaine : une porte d'entrée), puis le code, l'objectif, le texte.
        if (t >= 0) { s += 10 + (t === 0 ? 9 : /[\s'’(«-]/.test(x.n.titre[t - 1]) ? 5 : 0) + (x.k !== 'doc' ? 6 : 0); }
        if (c >= 0) { s += 8; }
        if (o >= 0) { s += 3; }
        if (z >= 0) { s += 1 + Math.min(2, x.n.txt.split(m).length - 1); }
      }
      if (mots.length > 1 && x.n.titre.includes(phrase)) { s += 12; }
      R.push({ x, s, mots });
    });
    return R.sort((a, b) => b.s - a.s || String(a.x.code).localeCompare(String(b.x.code), 'fr', { numeric: true }));
  }
  /** Le texte d'origine, les mots cherchés surlignés (les positions viennent du texte normalisé, de même longueur). */
  function surligne(orig, mots) {
    const s = String(orig || ''), n = norm(s), marques = [];
    mots.forEach(m => { let i = n.indexOf(m); while (i >= 0) { marques.push([i, i + m.length]); i = n.indexOf(m, i + m.length); } });
    if (!marques.length) { return esc(s); }
    marques.sort((a, b) => a[0] - b[0]);
    let h = '', p = 0;
    marques.forEach(([a, b]) => { if (a < p) { return; } h += esc(s.slice(p, a)) + '<mark>' + esc(s.slice(a, b)) + '</mark>'; p = b; });
    return h + esc(s.slice(p));
  }
  /** L'extrait où le mot est trouvé (l'objectif, sinon le texte des étapes) ; à défaut, l'objectif. */
  function extrait(x, mots) {
    for (const [o, n] of [[x.objectif, x.n.obj], [x.texte, x.n.txt]]) {
      const i = mots.length ? Math.min(...mots.map(m => { const k = n.indexOf(m); return k < 0 ? Infinity : k; })) : Infinity;
      if (i !== Infinity) {
        const a = Math.max(0, i - 70), b = Math.min(o.length, i + 120);
        return (a > 0 ? '… ' : '') + surligne(o.slice(a, b), mots) + (b < o.length ? ' …' : '');
      }
    }
    return esc(x.objectif || '');
  }

  /* --- où mène un résultat ------------------------------------------------------------------------------------------ */
  const etapePhoto = p => String(p).startsWith('u/') ? st.api + '/formation/sop/photo/' + encodeURIComponent(String(p).slice(2)) : '../formation/sop/photos/' + encodeURIComponent(String(p));
  const versCatalogue = cat => MANUEL[cat] ? '../formation/catalogue/#/m/' + MANUEL[cat] : '../formation/catalogue/';
  function lien(x) {
    if (x.k === 'fo') { return ['Ouvrir la formation', 'module Formation › Formations'] ; }
    if (x.k === 'res') { return ['Voir le domaine', 'catalogue › Manuel du réseau']; }
    const ou = x.cat === 'man' ? 'tablette · Manuel' : x.cat === 'chk' ? 'les checklists du magasin' : x.cat === 'fc' ? 'Formations et suivi' : 'tablette du poste' + (MOMENT[x.moment] ? ' · ' + MOMENT[x.moment] : '');
    const quoi = { Article: 'Lire l’article', SOP: 'Ouvrir la SOP', Mémo: 'Lire le mémo', Fiche: 'Ouvrir la fiche', Checklist: 'Voir la checklist', FC: 'Ouvrir la formation' }[x.type] || 'Ouvrir';
    return [quoi, ou];
  }
  const tg = (cat, texte) => { const c = st.idx && st.idx.cats[cat]; return `<span class="sf-tg${c && c.contour ? ' ct' : ''}" style="--fd:${c ? c.couleur : '#eee'};--en:${c ? c.encre : '#222'}">${esc(texte || (c ? c.nom : cat))}</span>`; };
  const tgType = x => tg(x.cat, x.k === 'doc' && x.cat === 'std' ? x.type : x.k === 'doc' && x.cat === 'fc' ? 'Formation continue' : null);

  /* --- le rendu : la barre et son panneau ----------------------------------------------------------------------------- */
  function ligne(r, i) {
    const x = r.x, [go, ou] = lien(x);
    return `<button type="button" class="sf-l${i === st.sel ? ' sel' : ''}" data-sf-i="${i}"><span class="c"><span class="t">${tgType(x)}<code>${esc(x.code)}</code><b>${surligne(x.titre, r.mots)}</b>${x.acces === 'responsable' ? '<span class="sf-res" title="rangé à part : franchisé et responsable">🔒</span>' : ''}</span>
      <span class="x">${extrait(x, r.mots)}</span><span class="go">${esc(go)} → <small>${esc(ou)}</small></span></span>${x.photo ? `<img class="ph" src="${esc(etapePhoto(x.photo))}" alt="" loading="lazy">` : ''}</button>`;
  }
  function apercu(r) {
    if (!r) { return '<div class="sf-ap"><p class="sf-n">Choisissez un résultat pour le voir ici.</p></div>'; }
    const x = r.x, [go] = lien(x);
    const meta = x.k === 'doc' ? [x.code, x.poste ? 'poste ' + x.poste : '', x.nbEtapes ? x.nbEtapes + ' étape' + (x.nbEtapes > 1 ? 's' : '') : ''].filter(Boolean).join(' · ') : x.code;
    const corps = x.k === 'fo' ? `<p class="sf-n">${esc(x.objectif)}</p>${x.formation.phases && x.formation.phases.length ? `<p class="sf-n">Phase : ${esc(x.formation.phases.join(', '))}</p>` : ''}`
      : x.k === 'res' ? `<ol>${(x.domaine.souscats || []).map(s => `<li>${esc(s)}</li>`).join('')}</ol>`
      : `${x.objectif ? `<p class="sf-n">${surligne(x.objectif, r.mots)}</p>` : ''}${x.photo ? `<img class="sf-ap-ph" src="${esc(etapePhoto(x.photo))}" alt="">` : ''}
        ${x.etapes.length ? `<ol>${x.etapes.map(e => `<li${e.critique ? ' class="crit"' : ''}>${surligne(e.titre, r.mots)}</li>`).join('')}${x.nbEtapes > x.etapes.length ? `<li class="plus">… ${x.nbEtapes - x.etapes.length} étape${x.nbEtapes - x.etapes.length > 1 ? 's' : ''} encore</li>` : ''}</ol>` : ''}`;
    return `<div class="sf-ap">${tgType(x)}<span class="sf-meta">${esc(meta)}</span><h3>${esc(x.titre)}</h3>${corps}
      <div class="sf-bts"><button type="button" class="db-btn sf-b prim" data-sf-ouvrir="${st.sel}">${esc(go)}</button>
        ${x.k === 'fo' ? `<a class="db-btn sf-b" href="../formation/#/rfformations/pre/${encodeURIComponent(x.code)}" target="_blank" rel="noopener">Dans le module Formation ↗</a>` : MANUEL[x.cat] ? `<a class="db-btn sf-b" href="${versCatalogue(x.cat)}" target="_blank" rel="noopener">📖 Feuilleter dans le catalogue ↗</a>` : ''}
        ${st.o.professeur ? `<button type="button" class="db-btn sf-b abr" data-sf-prof="1">🎩 Demander au Professeur</button>` : ''}</div></div>`;
  }
  function panneau(R) {
    const tous = st.cat ? (() => { const c = st.cat; st.cat = ''; const T = chercher(); st.cat = c; return T; })() : R;
    const n = c => tous.filter(r => r.x.cat === c).length;
    const vide = !R.length ? `<div class="sf-vide">${st.q.trim() ? 'Rien ne répond à « ' + esc(st.q) + ' »' + (st.cat ? ' dans ce catalogue' : '') + '.' : 'Tapez un mot : un produit, un geste, une règle…'}${st.o.professeur && st.q.trim() ? ' <button type="button" class="sf-lien" data-sf-prof="q">Posez la question au Professeur ›</button>' : ''}</div>` : '';
    const L = R.slice(0, 60);
    return `<div class="sf-pan"><div class="sf-f"><div class="h">Catalogues</div><button type="button" class="it${!st.cat ? ' on' : ''}" data-sf-cat=""><b>Tout</b><span class="nb">${tous.length}</span></button>
        ${st.idx.liste.map(c => `<button type="button" class="it${st.cat === c.cle ? ' on' : ''}" data-sf-cat="${c.cle}">${tg(c.cle)}<span class="nb">${st.q.trim() ? n(c.cle) : c.n}</span></button>`).join('')}
        <div class="sep"></div><p class="pq">Rien ne répond ? Le <b>Professeur</b> lit le manuel et votre dossier de formation.</p></div>
      <div class="sf-r">${vide}${L.length ? (st.q.trim() && L.length > 3 ? `<div class="sf-g">Le plus pertinent</div>${L.slice(0, 2).map(ligne).join('')}<div class="sf-g">Les autres résultats</div>${L.slice(2).map((r, i) => ligne(r, i + 2)).join('')}` : L.map(ligne).join('')) : ''}
        ${R.length > 60 ? `<div class="sf-g">… et ${R.length - 60} autres : précisez la recherche</div>` : ''}</div>
      ${apercu(L[st.sel])}
      <div class="sf-pied"><span><kbd>↑</kbd><kbd>↓</kbd>choisir</span><span><kbd>Entrée</kbd>ouvrir</span><span><kbd>Échap</kbd>fermer</span><span class="d">${R.length} résultat${R.length > 1 ? 's' : ''}${st.q.trim() ? ' · titres, codes, objectifs et étapes' : ''}</span></div></div>`;
  }
  function rendreHaut() {
    const H = st.el && st.el.querySelector('.sf-haut'); if (!H) { return; }
    const foc = document.activeElement && document.activeElement.dataset && document.activeElement.dataset.sfQ ? document.activeElement.selectionStart : null;
    const R = st.ouvert && st.idx ? chercher() : [];
    if (st.sel >= Math.min(R.length, 60)) { st.sel = Math.max(0, Math.min(R.length, 60) - 1); }
    const cats = st.idx ? st.idx.liste.map(c => `<button type="button" class="sf-cb" data-sf-cat="${c.cle}" data-sf-ouvre="1">${tg(c.cle, c.nom + ' · ' + (c.cle === 'res' ? c.n + ' domaines' : c.n))}</button>`).join('') : '';
    H.innerHTML = `<div class="sf-ms${st.ouvert ? ' ouv' : ''}"><label class="sf-in"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-5.2-5.2"/></svg>
        <input data-sf-q="1" value="${esc(st.q)}" placeholder="${st.err ? 'La recherche ne se lit pas : ' + esc(st.err) : (window.innerWidth < 1024 ? 'Chercher dans la formation…' : 'Chercher dans toute la formation : un produit, un geste, une règle, une checklist, un article…')}" autocomplete="off" aria-label="Chercher dans toute la formation"${st.idx ? '' : ' disabled'}>
        ${st.ouvert ? `<span class="n">${st.cat ? esc(st.idx.cats[st.cat].nom) + ' · ' : ''}${R.length} résultat${R.length > 1 ? 's' : ''}</span><button type="button" class="sf-x" data-sf-fermer="1" aria-label="Fermer">×</button>` : '<kbd>/</kbd>'}</label>
      ${st.ouvert && st.idx ? panneau(R) : `<div class="sf-cats">${st.idx ? 'Dans ' + cats : (st.err ? '' : 'Lecture du catalogue…')}</div>`}</div>${st.ouvert ? '<div class="sf-voile" data-sf-fermer="1"></div>' : ''}`;
    brancherHaut();
    if (foc !== null) { const i = H.querySelector('[data-sf-q]'); if (i) { i.focus(); try { i.setSelectionRange(foc, foc); } catch (e) { /* sans curseur */ } } }
    const s = H.querySelector('.sf-l.sel'); if (s && s.scrollIntoView) { s.scrollIntoView({ block: 'nearest' }); }
  }
  /** L'accueil du module : la phrase et le tableau des quatre colonnes. */
  function rendreAccueil() {
    const A = st.el && st.el.querySelector('.sf-acc'); if (!A) { return; }
    const I = st.idx || { catalogues: {}, types: {} };
    const ic = x => `<span class="sf-ac-ic${x.ct ? ' ct' : ''}" style="--c:${x.coul};--fd:${x.fd};--en:${x.en}"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="${AC_ICO[x.k]}"/></svg></span>`;
    const tete = COLS.map(x => `<th${x.ct ? ' class="ct"' : ''} style="--c:${x.coul}">${ic(x)}<span class="mot">${esc(x.mot)}</span><button type="button" class="nom" data-sf-cat="${x.cat}" data-sf-ouvre="1">${esc(x.nom)}</button><small>${esc(x.n(I))}</small></th>`).join('');
    const lignes = AC_LIGNES.map(([k, l]) => `<tr class="sf-ac-${k}"><th scope="row">${esc(l)}</th>${COLS.map(x => k === 'ex' ? `<td><button type="button" class="ex" data-sf-ex="${esc(x.ex[1])}">${esc(x.ex[0])}</button></td>` : `<td>${esc(x[k])}</td>`).join('')}</tr>`).join('');
    const mt = (x, t) => `<span class="sf-tg${x.ct ? ' ct' : ''}" style="--fd:${x.fd};--en:${x.en}">${t}</span>`;
    A.innerHTML = `<div class="sf-phrase">En une phrase : la ${mt(COLS[0], 'formation')} apprend le métier, le ${mt(COLS[1], 'manuel')} explique pourquoi,
        la ${mt(COLS[2], 'SOP')} montre comment faire, la ${mt(COLS[3], 'checklist')} vérifie chaque jour que c’est fait.</div>
      <div class="sf-ac"><table><colgroup><col class="c0"><col><col><col><col></colgroup><thead><tr><th></th>${tete}</tr></thead><tbody>${lignes}</tbody></table></div>
      <div class="sf-cartes">${COLS.map(x => `<button type="button" class="sf-carte${x.ct ? ' ct' : ''}" style="--c:${x.coul}" data-sf-cat="${x.cat}" data-sf-ouvre="1"><span class="mot">${esc(x.mot)}</span><b>${esc(x.nom)}</b><span>${esc(x.quoi)}</span><small>${esc(x.n(I))}</small></button>`).join('')}</div>`;
    A.querySelectorAll('[data-sf-cat]').forEach(b => b.addEventListener('click', () => { st.cat = b.dataset.sfCat; st.q = ''; st.sel = 0; st.ouvert = true; rendreHaut(); focus(); window.scrollTo(0, 0); }));
    A.querySelectorAll('[data-sf-ex]').forEach(b => b.addEventListener('click', () => ouvrirRef(b.dataset.sfEx)));
  }

  /* --- le lecteur : un document, une formation, un domaine, en tiroir -------------------------------------------------- */
  function ouvrirRef(ref) {
    const [k, id] = ref.split(':');
    const x = st.idx && st.idx.items.find(y => (k === 'doc' ? y.k === 'doc' : y.k === k) && String(y.id) === id);
    if (x) { ouvrirX(x); }
  }
  function ouvrirX(x) {
    // Une formation continue du dossier du magasin s'ouvre dans son écran (formation.js), avec ce qu'il reste à faire.
    if (x.cat === 'fc' && st.o.formationContinue && st.o.formationContinue(x.code) !== false) { st.ouvert = false; rendreHaut(); return; }
    st.lect = { x, d: null, err: null }; rendreLect();
    if (x.k === 'doc') {
      fetch(st.api + '/formation/sop/tablette/sop/' + encodeURIComponent(x.id), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        .then(r => r.json().catch(() => ({})).then(j => (r.ok ? j : Promise.reject(new Error((j && j.error) || ('HTTP ' + r.status))))))
        .then(d => { if (st.lect && st.lect.x === x) { st.lect.d = d; } }).catch(e => { if (st.lect && st.lect.x === x) { st.lect.err = e.message; } }).finally(rendreLect);
    }
  }
  function rendreLect() {
    const D = st.el && st.el.querySelector('.sf-lect'); if (!D) { return; }
    const L = st.lect; if (!L) { D.innerHTML = ''; return; }
    const x = L.x, t = v => v && typeof v === 'object' ? (v.fr || v.nl || '') : String(v || '');
    let corps;
    if (x.k === 'fo') {
      const f = x.formation;
      corps = `<p class="sf-n">${esc(x.objectif)}</p>${f.phases && f.phases.length ? `<p class="sf-n">Phase : ${esc(f.phases.join(', '))}</p>` : ''}
        <p class="sf-n">La formation renvoie aux articles du manuel et aux SOP à lire et à faire ; ses compétences sont cochées au panel quand elles sont acquises.</p>
        <a class="db-btn sf-b prim" href="../formation/#/rfformations/pre/${encodeURIComponent(x.code)}" target="_blank" rel="noopener">Ouvrir dans le module Formation ↗</a>`;
    } else if (x.k === 'res') {
      corps = `<p class="sf-n">Le domaine ${esc(x.code)} du manuel du réseau et ses sous-catégories ; les standards y sont rangés.</p><ol class="sf-sc">${(x.domaine.souscats || []).map(s => `<li>${esc(s)}</li>`).join('')}</ol>
        <a class="db-btn sf-b prim" href="${versCatalogue('res')}" target="_blank" rel="noopener">📖 Feuilleter le manuel du réseau ↗</a>`;
    } else if (L.err) {
      corps = `<div class="sf-err">Le document ne s’ouvre pas : ${esc(L.err)}</div>`;
    } else if (!L.d) {
      corps = '<div class="sf-n">Ouverture du document…</div>';
    } else {
      const C = L.d.contenu || {};
      const etapes = (C.sections || []).map(s => `${(C.sections || []).length > 1 && t(s.titre) ? `<div class="sf-sec">${esc(t(s.titre))}</div>` : ''}${(s.etapes || []).map(e => `<div class="sf-et${e.critique ? ' crit' : ''}"><span class="n">${esc(e.numero)}</span><div class="tx">${t(e.titre) ? `<b>${esc(t(e.titre))}</b>` : ''}${esc(t(e.texte)).replace(/\n/g, '<br>')}${e.critique ? '<em>point critique</em>' : ''}${e.video ? `<a class="sf-lien" href="${esc(e.video)}" target="_blank" rel="noopener">▶ la vidéo</a>` : ''}</div>${e.photo ? `<img src="${esc(etapePhoto(e.photo))}" alt="" loading="lazy">` : ''}</div>`).join('')}`).join('');
      const crit = (C.points_critiques || []).length ? `<div class="sf-crit">${C.points_critiques.map(p => `<div><b>Point critique</b>${esc(t(p))}</div>`).join('')}</div>` : '';
      corps = `${t(C.objectif) ? `<p class="sf-obj">${esc(t(C.objectif))}</p>` : ''}${t(C.pour) ? `<p class="sf-n">Pour : ${esc(t(C.pour))}${C.duree ? ' · ' + esc(C.duree) : ''}</p>` : ''}${etapes || '<p class="sf-n">Pas encore d’étape.</p>'}${crit}
        <div class="sf-bts">${MANUEL[x.cat] ? `<a class="db-btn sf-b" href="${versCatalogue(x.cat)}" target="_blank" rel="noopener">📖 Feuilleter dans le catalogue ↗</a>` : ''}${st.o.professeur ? '<button type="button" class="db-btn sf-b abr" data-sf-prof="l">🎩 Demander au Professeur</button>' : ''}</div>`;
    }
    D.innerHTML = `<div class="sf-ombre" data-sf-lferme="1"></div><aside class="sf-tir" role="dialog" aria-label="${esc(x.titre)}"><div class="h"><span>${tgType(x)} <code>${esc(x.code)}</code><b>${esc(x.titre)}</b></span><button type="button" class="x" data-sf-lferme="1" aria-label="Fermer">×</button></div><div class="c">${corps}</div></aside>`;
    D.querySelectorAll('[data-sf-lferme]').forEach(b => b.addEventListener('click', () => { st.lect = null; rendreLect(); }));
    D.querySelectorAll('[data-sf-prof]').forEach(b => b.addEventListener('click', () => { if (st.o.professeur) { st.o.professeur('Explique-moi « ' + x.titre + ' » (' + x.code + ')'); } }));
  }

  /* --- les événements ----------------------------------------------------------------------------------------------- */
  function focus() { const i = st.el && st.el.querySelector('[data-sf-q]'); if (i) { i.focus(); const n = i.value.length; try { i.setSelectionRange(n, n); } catch (e) { /* sans curseur */ } } }
  function brancherHaut() {
    const H = st.el.querySelector('.sf-haut'), on = (s, ev, fn) => H.querySelectorAll(s).forEach(n => n.addEventListener(ev, fn));
    const i = H.querySelector('[data-sf-q]');
    if (i) {
      i.addEventListener('input', () => { st.q = i.value; st.sel = 0; st.ouvert = st.q.trim().length >= 2 || !!st.cat; rendreHaut(); });
      i.addEventListener('focus', () => { if (!st.ouvert && (st.q.trim().length >= 2 || st.cat)) { st.ouvert = true; rendreHaut(); } });
      i.addEventListener('keydown', e => {
        const n = st.ouvert ? Math.min(chercher().length, 60) : 0;
        if (e.key === 'ArrowDown' && n) { e.preventDefault(); st.sel = Math.min(n - 1, st.sel + 1); rendreHaut(); }
        else if (e.key === 'ArrowUp' && n) { e.preventDefault(); st.sel = Math.max(0, st.sel - 1); rendreHaut(); }
        else if (e.key === 'Enter' && n) { e.preventDefault(); const r = chercher()[st.sel]; if (r) { ouvrirX(r.x); } }
        else if (e.key === 'Escape') { st.ouvert = false; st.cat = ''; rendreHaut(); }
      });
    }
    on('[data-sf-fermer]', 'click', () => { st.ouvert = false; st.cat = ''; rendreHaut(); });
    on('[data-sf-cat]', 'click', e => { const c = e.currentTarget.dataset.sfCat; st.cat = st.cat === c && !e.currentTarget.dataset.sfOuvre ? '' : c; st.sel = 0; st.ouvert = true; rendreHaut(); focus(); });
    on('[data-sf-i]', 'click', e => { const k = +e.currentTarget.dataset.sfI; if (k === st.sel) { const r = chercher()[k]; if (r) { ouvrirX(r.x); } } else { st.sel = k; rendreHaut(); } });
    on('[data-sf-i]', 'dblclick', e => { const r = chercher()[+e.currentTarget.dataset.sfI]; if (r) { ouvrirX(r.x); } });
    on('[data-sf-ouvrir]', 'click', () => { const r = chercher()[st.sel]; if (r) { ouvrirX(r.x); } });
    on('[data-sf-prof]', 'click', e => { if (!st.o.professeur) { return; } const r = chercher()[st.sel];
      st.o.professeur(e.currentTarget.dataset.sfProf === 'q' || !r ? st.q : 'Explique-moi « ' + r.x.titre + ' » (' + r.x.code + ')'); });
  }
  // « / » place le curseur dans la barre (hors d'un champ) ; Échap ferme le lecteur, puis le panneau.
  document.addEventListener('keydown', e => {
    if (!st.el || !st.el.isConnected) { return; }
    const champ = /^(INPUT|TEXTAREA|SELECT)$/.test((document.activeElement || {}).tagName || '');
    if (e.key === '/' && !champ) { e.preventDefault(); focus(); }
    else if (e.key === 'Escape' && st.lect) { st.lect = null; rendreLect(); }
    else if (e.key === 'Escape' && st.ouvert && !champ) { st.ouvert = false; st.cat = ''; rendreHaut(); }
  });

  window.DashSeFormer = {
    racine(shop, o) {
      o = o || {};
      if (o.apiBase) { st.api = o.apiBase; }
      st.o = Object.assign({}, st.o, o);
      if (!st.el || String(st.shop) !== String(shop)) {
        st.shop = String(shop); st.el = document.createElement('div'); st.el.className = 'sf';
        st.el.innerHTML = '<div class="sf-haut"></div><div class="sf-acc"></div><div class="sf-lect"></div>';
        Object.assign(st, { idx: null, err: null, q: '', cat: '', sel: 0, ouvert: false, lect: null });
        rendreHaut(); rendreAccueil(); charger();
      }
      return st.el;
    },
    /** Ouvre la recherche sur un mot (le Professeur, un lien) ou sur un catalogue. */
    chercher(q, cat) { st.q = q || ''; st.cat = cat || ''; st.sel = 0; st.ouvert = true; rendreHaut(); focus(); },
  };
})();
