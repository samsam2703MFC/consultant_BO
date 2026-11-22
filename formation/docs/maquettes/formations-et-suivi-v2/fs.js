/* Maquettes « Formations et suivi » v2 : les pièces communes — l'en-tête, l'accueil du module Formation (sans rail),
   la méga-barre et ses résultats. Les titres et codes sont ceux du catalogue en ligne (lus le 22/11/2026). */
(function () {
  'use strict';
  const F = {};
  const esc = v => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  F.CATS = [['fo', 'Formations', 54], ['man', 'Manuel opératoire', 59], ['std', 'Standards (SOP)', 35], ['chk', 'Checklists', 11], ['res', 'Manuel du réseau', '8 domaines'], ['fc', 'Formation continue', 2]];
  F.tg = (k, t) => `<span class="tg c-${k}">${esc(t || (F.CATS.find(c => c[0] === k) || [])[1])}</span>`;
  F.loupe = s => `<svg width="${s || 22}" height="${s || 22}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-5.2-5.2"/></svg>`;
  F.tete = () => `<div class="fs-tete"><div><div class="kk">Atelier by - Halle</div><h1>Formations et suivi</h1></div><span class="sp"></span>
      <div class="fs-mini"><span>🎓 <b>Mon dossier de formation</b></span><span class="k"><b>0</b><small>en retard</small></span><span class="k"><b>2</b><small>en cours</small></span><span class="k"><b>0</b><small>à valider</small></span><span class="k"><b>1</b><small>validée</small></span><span class="o">Ouvrir ›</span></div>
      <span class="fs-prof">🎩 Demander au Professeur</span></div>`;
  F.barre = (o) => `<div class="ms${o && o.v ? ' ouv' : ''}"><div class="ms-in">${F.loupe()}${o && o.v ? `<span class="v">${esc(o.v)}</span><span class="n">${o.n}</span>` : '<span class="ph">Chercher dans toute la formation : un produit, un geste, une règle, une checklist, un article…</span>'}<kbd>/</kbd></div>
      ${o && o.v ? F.panneau() : `<div class="ms-cats">Dans ${F.CATS.map(c => `<span class="tg c-${c[0]}">${esc(c[1])} <i>· ${c[2]}</i></span>`).join('')}</div>`}</div>`;
  /* L'accueil du module, tel qu'en ligne (portail.js › ecranAccueil), sans le rail du module. */
  F.accueil = () => {
    const A = window.ACCUEIL, COLS = A.COLS;
    const ic = x => `<span class="ac-ic" style="--c:${x.coul}"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="${A.AC_ICO[x.k]}"/></svg></span>`;
    const tete = COLS.map(x => `<th style="--c:${x.coul}">${ic(x)}<span class="ac-mot">${esc(x.mot)}</span><a class="ac-nom">${esc(x.nom)}</a><small>${esc(x.n)}</small></th>`).join('');
    const lignes = A.AC_LIGNES.map(([k, l]) => `<tr class="ac-${k}"><th scope="row">${esc(l)}</th>${COLS.map(x => k === 'ex' ? `<td><a class="ac-ex">${esc(x.ex[0])}</a></td>` : `<td>${esc(x[k])}</td>`).join('')}</tr>`).join('');
    return `<div class="ac-phrase">En une phrase : la <b style="color:#8D1D2C">formation</b> apprend le métier, le <b style="color:#B0792A">manuel</b> explique pourquoi,
      la <b style="color:#3f7a52">SOP</b> montre comment faire, la <b style="color:#1f5f8b">checklist</b> vérifie chaque jour que c’est fait.</div>
      <div class="card ac-tab"><table><colgroup><col class="ac-c0"><col><col><col><col></colgroup><thead><tr><th></th>${tete}</tr></thead><tbody>${lignes}</tbody></table></div>`;
  };
  /* Les résultats de « cuisson » : titres et codes réels ; les extraits illustrent l'endroit trouvé. */
  F.RES = [
    { k: 'std', t: 'SOP', code: '0.2.05', titre: 'Cuisson des baguettes et des pains', x: '« Lancer le programme de <mark>cuisson</mark> du four selon la fiche des programmes… »', go: 'Ouvrir la SOP', ou: 'tablette du poste · Journée de travail', ph: '/formation/public/formation/sop/photos/baguettes-1.jpg', sel: 1 },
    { k: 'fo', t: 'Formations', code: 'FO-12', titre: 'Formation <mark>Cuisson</mark> & gestion four', x: 'Famille production · les compétences du poste cuisson, les SOP à lire et à faire', go: 'Ouvrir la formation', ou: 'Formation › Formations' },
    { k: 'std', t: 'Fiche', code: '0.0.02', titre: 'Programmes de <mark>cuisson</mark> et de pousse', x: 'Le tableau des programmes du four et de la chambre de pousse, produit par produit', go: 'Feuilleter', ou: 'catalogue › Standards' },
    { k: 'std', t: 'Mémo', code: '0.0.01', titre: 'Organisation quotidienne du poste de <mark>cuisson</mark>', x: 'La journée du poste, de l’ouverture à la fin de service', go: 'Feuilleter', ou: 'catalogue › Standards' },
    { k: 'std', t: 'SOP', code: '0.1.02', titre: 'Ouverture du poste et premières <mark>cuisson</mark>s', x: 'Ouverture · les premières fournées avant l’ouverture du magasin', go: 'Ouvrir la SOP', ou: 'tablette du poste · Ouverture', ph: '/formation/public/formation/sop/photos/ouv-mag-3.jpg' },
    { k: 'std', t: 'SOP', code: '0.3.01', titre: 'Re<mark>cuisson</mark> d’appoint – pains et baguettes (après-midi)', x: 'Après-midi · quand et combien recuire', go: 'Ouvrir la SOP', ou: 'tablette du poste · Journée de travail', ph: '/formation/public/formation/sop/photos/baguettes-3.jpg' },
    { k: 'std', t: 'SOP', code: '0.4.02', titre: 'Fin de poste de <mark>cuisson</mark> — terminer son service', x: 'Fermeture · le four, les plaques, le poste propre', go: 'Ouvrir la SOP', ou: 'tablette du poste · Fermeture' },
    { k: 'fo', t: 'Formations', code: 'FO-07', titre: 'Formation Boulangerie technique', x: 'Famille production · la <mark>cuisson</mark> des pains et des viennoiseries', go: 'Ouvrir la formation', ou: 'Formation › Formations' },
    { k: 'res', t: 'Manuel du réseau', code: 'D5', titre: 'Opérations & Production', x: '5 sous-catégories · les standards de production et de <mark>cuisson</mark> rangés dans chacune', go: 'Feuilleter le domaine', ou: 'catalogue › Manuel du réseau' },
    { k: 'man', t: 'Manuel opératoire', code: '0.0.13', titre: 'HACCP & Hygiène : la sécurité alimentaire au quotidien', x: '« … les températures de <mark>cuisson</mark> et de remise en température … »', go: 'Lire l’article', ou: 'tablette · Manuel' },
    { k: 'chk', t: 'Checklists', code: '1.1.01', titre: 'Ouverture de la boutique', x: '« Premières <mark>cuisson</mark>s lancées, vitrine du pain remplie » · photo obligatoire', go: 'Voir la checklist', ou: 'application du magasin' },
    { k: 'fc', t: 'Formation continue', code: 'FC', titre: 'Un nouveau produit arrive : la procédure', x: '… la fiche technique, les programmes de <mark>cuisson</mark>, la photo qualité de chaque jour …', go: 'Ouvrir', ou: 'Formations et suivi' },
  ];
  F.ligne = r => `<div class="ms-l${r.sel ? ' sel' : ''}"><div class="c"><div class="t">${F.tg(r.k, r.t)}<code>${esc(r.code)}</code><b>${r.titre}</b></div><span class="x">${r.x}</span>
      <span class="go">${esc(r.go)} → <small>${esc(r.ou)}</small></span></div>${r.ph ? `<img class="ph" src="${r.ph}" alt="">` : ''}</div>`;
  F.panneau = () => {
    const n = k => F.RES.filter(r => r.k === k).length;
    return `<div class="ms-pan"><div class="ms-f"><div class="h">Catalogues</div><div class="it on"><b style="font-weight:400">Tout</b><span class="nb">${F.RES.length}</span></div>
        ${F.CATS.map(c => `<div class="it">${F.tg(c[0])}<span class="nb">${n(c[0])}</span></div>`).join('')}
        <div class="sep"></div><div class="h">Pour</div><div class="it">Tous les postes</div><div class="it">Mon poste : production</div>
        <div class="sep"></div><div class="pq">Rien ne répond ? Posez la question au <b style="font-weight:400;color:#7A3E12">Professeur</b> : il lit le manuel et votre dossier de formation.</div></div>
      <div class="ms-r"><div class="ms-g">Le plus pertinent</div>${F.RES.slice(0, 2).map(F.ligne).join('')}<div class="ms-g">Les autres résultats</div>${F.RES.slice(2, 9).map(F.ligne).join('')}</div>
      <div class="ms-ap">${F.tg('std', 'Standards (SOP)')} <span style="font-size:11.5px;color:var(--muted);margin-left:6px">0.2.05 · poste cuisson · 6 étapes · 4 photos</span>
        <h3>Cuisson des baguettes et des pains</h3><div class="m">Le geste exact, étape par étape, avec la photo de chaque étape.</div>
        <img src="/formation/public/formation/sop/photos/baguettes-1.jpg" alt="">
        <ol><li>Vérifier le programme du four (fiche « Programmes de cuisson et de pousse »).</li><li class="crit">Préchauffer : ne jamais enfourner avant la température.</li><li>Enfourner, lancer le programme de <mark style="background:#FBE6A9">cuisson</mark>.</li><li>… 3 étapes encore</li></ol>
        <div class="bts"><span class="bt prim">Ouvrir la SOP</span><span class="bt">📖 Feuilleter dans le catalogue</span><span class="bt abr">🎩 Demander au Professeur</span></div></div>
      <div class="ms-pied"><span><kbd>↑</kbd><kbd>↓</kbd>choisir</span><span><kbd>Entrée</kbd>ouvrir</span><span><kbd>Échap</kbd>fermer</span><span style="margin-left:auto">${F.RES.length} résultats dans 6 catalogues · titres, codes, étapes, articles, checklists et formations</span></div></div>`;
  };
  window.FS = F;
})();
