/* Maquettes : la fiche d'un bureau (Vendre › Clients › Bureaux), 26/11/2026. Sam : « retravailler car non visible pour
 * toutes les informations, faire une drop list ou autre, faire 2-3 maquettes en screenshots, ensuite je décide ».
 * Données fictives (les bureaux du banc, magasin de Gosselies).  ?v=a · ?v=b · ?v=c */
(() => {
  const V = (new URLSearchParams(location.search).get('v') || 'a').toLowerCase();
  const esc = s => String(s == null ? '' : s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const SVG = d => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="${d}"/></svg>`;
  const I = { camion: 'M1 5h13v11H1zM14 9h4l4 4v3h-8M5.5 18.5a1.8 1.8 0 1 0 0-.01M17.5 18.5a1.8 1.8 0 1 0 0-.01', doc: 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6',
    alerte: 'M12 9v4M12 17h.01M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z', plus: 'M12 5v14M5 12h14', x: 'M18 6L6 18M6 6l12 12', g: 'M15 18l-6-6 6-6', d: 'M9 18l6-6-6-6', bas: 'M6 9l6 6 6-6', haut: 'M18 15l-6-6-6 6' };

  /* Les bureaux : nom, société, statut, catalogue, offres, départements, personnes, sites, livraison (null = pas de site). */
  const B = [
    { nom: 'Lunchroom Hainaut Assurances', soc: 'Hainaut Assurances', ville: 'Charleroi', cat: 'Réduit · 38 produits', off: 3, dep: 4, pers: 40, fact: 'facturé à la société', contact: 'Office manager',
      sites: [['Lunchroom', 'bd Tirou 45, 6000 Charleroi', 5]], liv: { t: 'Midi · Gosselies – Charleroi', cr: '12:00 – 12:30', lim: '16:00 la veille', rem: '5 min · QR', j: [1, 2, 3, 4, 5] },
      offres: [['Lunch du vendredi', '−10 % · jusqu’au 31/12'], ['Plateau réunion', '8 pers. min.'], ['Petit-déjeuner', 'mardi et jeudi']], deps: ['Direction', 'Comptabilité', 'RH', 'Sinistres'], invite: '23 inscrits · lien actif jusqu’au 31/12', cmd: 46, ca: '5 040 €' },
    { nom: 'Horizon · Jumet', soc: 'Centre de formation Horizon', ville: 'Jumet', cat: 'Complet', off: 2, dep: 3, pers: 18, fact: 'chaque personne paie', contact: 'Accueil',
      sites: [['Zoning, salle 2', 'rue de la Station 14, 6040 Jumet', 5]], liv: { t: 'Matin · Jumet', cr: '08:45 – 09:15', lim: '18:00 la veille', rem: '5 min · dépôt', j: [1, 2, 3, 4, 5] },
      offres: [['Pause café', '−5 %'], ['Formation du jeudi', 'lunch box']], deps: ['Formateurs', 'Administration', 'Stagiaires'], invite: '12 inscrits', cmd: 61, ca: '7 980 €' },
    { nom: 'EVS · bureaux de Gosselies', soc: 'Atelier numérique Charleroi', ville: 'Gosselies', cat: 'Complet', off: 0, dep: 0, pers: 0, fact: 'chaque personne paie', contact: '—',
      sites: [], liv: null, offres: [], deps: [], invite: null, cmd: 0, ca: '—' },
    { nom: 'Fiduciaire Delvaux', soc: 'Fiduciaire Delvaux & associés', ville: 'Gosselies', cat: 'Complet', off: 1, dep: 2, pers: 11, fact: 'facturé à la société', contact: 'Assistante de direction',
      sites: [['Siège', 'rue Circulaire 2, 6041 Gosselies', 3]], liv: { t: 'Midi · Gosselies – Charleroi', cr: '12:15 – 12:45', lim: '16:00 la veille', rem: '5 min · signature', j: [1, 2, 4, 5] },
      offres: [['Réunion du lundi', '6 pers.']], deps: ['Associés', 'Collaborateurs'], invite: '9 inscrits', cmd: 52, ca: '6 120 €' },
    { nom: 'Techni-Sol · atelier', soc: 'Techni-Sol SRL', ville: 'Jumet', cat: 'Réduit · 22 produits', off: 0, dep: 1, pers: 7, fact: 'chaque personne paie', contact: 'Chef d’atelier',
      sites: [['Atelier', 'chaussée de Bruxelles 210, 6040 Jumet', 2]], liv: { t: 'Matin · Jumet', cr: '09:30 – 10:00', lim: '18:00 la veille', rem: '5 min · dépôt', j: [1, 3, 5] }, offres: [], deps: ['Atelier'], invite: '4 inscrits', cmd: 34, ca: '2 410 €' },
    { nom: 'Bureau Lambot', soc: 'Bureau d’études Lambot', ville: 'Courcelles', cat: 'Complet', off: 0, dep: 1, pers: 0, fact: 'chaque personne paie', contact: '—', sites: [], liv: null, offres: [], deps: ['Études'], invite: null, cmd: 0, ca: '—' },
    { nom: 'Biolab · labo 2', soc: 'Biolab Hainaut', ville: 'Gosselies', cat: 'Réduit · 30 produits', off: 2, dep: 3, pers: 15, fact: 'facturé à la société', contact: 'Responsable labo',
      sites: [['Labo 2', 'avenue Jean Mermoz 18, 6041 Gosselies', 6]], liv: { t: 'Midi · Gosselies – Charleroi', cr: '11:45 – 12:15', lim: '16:00 la veille', rem: '10 min · QR', j: [1, 2, 3, 4, 5] },
      offres: [['Lunch d’équipe', '−8 %'], ['Anniversaires', 'gâteau offert dès 20 €']], deps: ['Labo', 'Qualité', 'Logistique'], invite: '15 inscrits', cmd: 9, ca: '420 €' },
  ];
  const SEL = 2;   // EVS : le bureau de la capture de Sam, sans site
  const statut = () => '<span class="chip chip--ok">Actif</span>';
  const jours = j => `<span class="fb-jours">${['L', 'M', 'M', 'J', 'V', 'S', 'D'].map((x, i) => `<i class="${j.includes(i + 1) ? 'on' : ''}">${x}</i>`).join('')}</span>`;
  const lf = (k, v) => `<div class="pcl-lf"><span>${esc(k)}</span><span>${v}</span></div>`;
  const manque = (t, b) => `<div class="fb-manque">${SVG(I.alerte)}<div>${t}${b ? `<div style="margin-top:8px"><span class="btn btn--sm btn--primary">${SVG(I.plus)}${b}</span></div>` : ''}</div></div>`;

  /* Les sections d'une fiche, partagées par les trois maquettes. */
  const sLivraison = b => `<div class="fb-sec"><h4>Livraison${b.liv ? '<a>modifier</a>' : ''}</h4>${b.liv ? lf('Tournée', `<span class="pcl-tchip">${SVG(I.camion)}${esc(b.liv.t)}</span>`) + lf('Créneau', esc(b.liv.cr)) + lf('Commandes jusqu’à', esc(b.liv.lim)) + lf('Remise sur place', esc(b.liv.rem)) + lf('Jours', jours(b.liv.j))
    : manque('<b style="font-weight:400">Pas de site de livraison.</b> Aucune tournée ne peut livrer ce bureau tant qu’il n’a pas d’adresse (TF Buddy : OFFICE_DELIVERY_SITE_REQUIRED).', 'Ajouter un site')}</div>`;
  const sSites = b => `<div class="fb-sec"><h4>Sites<a>${b.sites.length ? 'gérer' : ''}</a></h4>${b.sites.length ? b.sites.map(s => lf(s[0], `${esc(s[1])} <span class="muted">· accès ${s[2]} min</span>`)).join('') : '<div class="pcl-aide">Aucun site.</div>'}</div>`;
  const sCatalogue = b => `<div class="fb-sec"><h4>Catalogue<a>régler</a></h4>${lf('Assortiment', esc(b.cat))}${lf('Prix affichés', 'oui')}${lf('Facturation', esc(b.fact))}</div>`;
  const sOffres = b => `<div class="fb-sec"><h4>Offres<a>${b.offres.length ? 'gérer' : 'créer'}</a></h4>${b.offres.length ? b.offres.map(o => lf(o[0], esc(o[1]))).join('') : '<div class="pcl-aide">Aucune offre pour ce bureau.</div>'}</div>`;
  const sPersonnes = b => `<div class="fb-sec"><h4>Personnes<a>inviter</a></h4>${b.pers ? lf('Inscrites', b.pers) + `<div class="fb-pers">${b.deps.map(d => `<span>${esc(d)}</span>`).join('')}</div>` : '<div class="pcl-aide">Personne encore.</div>'}${lf('Lien d’invitation', b.invite ? esc(b.invite) : '<span class="muted">aucun</span>')}</div>`;
  const sDocs = () => `<div class="fb-sec"><h4>Documents</h4><div class="fb-docs"><span class="btn btn--sm">${SVG(I.doc)}Affiche A2</span><span class="btn btn--sm">${SVG(I.doc)}Cartes de bienvenue</span><span class="btn btn--sm">${SVG(I.doc)}Brochure</span></div></div>`;

  const tete = () => `<p class="fb-kicker">Atelier by — Gosselies</p><h1 class="fb-h1">Clients</h1>
    <nav class="page-tabs pcl-tabs"><button class="page-tabs__tab">Particuliers</button><button class="page-tabs__tab">Entreprises B2B</button><button class="page-tabs__tab is-on">Bureaux <span class="page-tabs__count">${B.length}</span></button><button class="page-tabs__tab">Analyse B2B</button>
      <span class="pcl-sp"></span><span class="fb-tfb">TF Buddy : compte du magasin connecté · <u>déconnecter</u></span><span class="linklike">Les offres : Vendre › Offres</span></nav>`;
  const filtres = () => `<div class="pcl-filtres"><input type="search" placeholder="Bureau, société, TVA, ville, contact"><span class="pcl-grp">Statut <span class="seg"><button class="is-on">Tous ${B.length}</button><button>Actifs ${B.length}</button><button>En attente 0</button></span></span>
    <span class="pcl-sp"></span><span class="btn btn--primary btn--sm">${SVG(I.plus)}Ajouter un bureau</span></div>`;
  const ligne = (b, i, ch) => `<tr class="pcl-ligne${ch ? '' : ''}"><td>${ch || ''}${esc(b.nom)}<span class="sub">${esc(b.soc)} · ${esc(b.ville)}</span></td><td>${statut()}</td><td>${esc(b.cat.split(' ·')[0])}</td><td class="num">${b.off}</td><td class="num">${b.pers}</td>
    <td>${b.liv ? `<span class="pcl-tchip">${SVG(I.camion)}${esc(b.liv.t.split(' ·')[0])} ${esc(b.liv.cr.split(' ')[0])}</span>` : '<span class="tag-warn">pas de site</span>'}</td></tr>`;
  const thead = (ch) => `<thead><tr><th>${ch ? '<span class="fbB-chev"></span>' : ''}Bureau</th><th>Statut</th><th>Catalogue</th><th class="num">Offres</th><th class="num">Personnes</th><th>Livraison</th></tr></thead>`;
  const legende = (t, L) => `<div class="legende"><h3>${t}</h3><ol>${L.map(x => `<li>${x}</li>`).join('')}</ol></div>`;

  function vueA() {
    const b = B[SEL];
    const lignes = B.map((x, i) => ligne(x, i)).join('');
    return tete() + `<div class="fb-bandeau"><b>A</b> Le tiroir : la liste garde toute la largeur ; un clic ouvre la fiche dans un panneau large, tout visible en défilant, sans onglets.</div>
      <div class="fbA"><section class="card pcl-carte">${filtres()}<div class="table-scroll"><table class="ds-table pcl-table">${thead()}<tbody>${lignes}</tbody></table></div></section>
        <div class="fbA-voile"></div>
        <aside class="fbA-tiroir"><span class="btn btn--sm btn--icon fbA-x">${SVG(I.x)}</span>
          <div class="fbA-tete"><div class="n">${esc(b.nom)} ${statut()}</div><div class="m">${esc(b.soc)} · ${esc(b.ville)} · ${esc(b.fact)} · contact : ${esc(b.contact)}</div></div>
          <div class="fbA-nav"><span class="on">Livraison</span><span>Catalogue et offres</span><span>Personnes</span><span>Documents</span><span style="margin-left:auto" class="muted">‹ 3 / 7 ›</span></div>
          <div class="fbA-corps">${sLivraison(b)}${sSites(b)}<div class="fbA-2">${sCatalogue(b)}${sOffres(b)}</div>${sPersonnes(b)}${sDocs()}</div>
          <div class="fbA-pied"><span class="btn btn--primary btn--sm">${SVG(I.plus)}Ajouter un site</span><span class="btn btn--sm">Inviter des personnes</span><span class="pcl-sp" style="flex:1"></span><span class="btn btn--sm btn--ghost">Bureau précédent</span><span class="btn btn--sm btn--ghost">Bureau suivant</span></div></aside></div>
      ${legende('Ce que fait le tiroir', ['Un panneau de 640 px glisse sur la droite : le nom, la société, le contact et la facturation en tête, puis <b>toutes les informations l’une sous l’autre</b> — livraison, sites, catalogue, offres, personnes, documents — sans onglet à cliquer.', 'Les onglets en haut du tiroir ne cachent rien : ils <b>font défiler</b> jusqu’à la section.', 'Ce qui manque est dit à sa place, avec le bouton pour le faire (ici : pas de site → « Ajouter un site »), au lieu de « HTTP 422 : OFFICE_DELIVERY_SITE_REQUIRED ».', 'Bureau précédent / suivant, sans refermer.'])}`;
  }

  function vueB() {
    const lignes = B.map((x, i) => {
      if (i !== SEL) { return ligne(x, i, `<span class="fbB-chev">${SVG(I.d).replace('<svg', '<svg width="14" height="14"')}</span>`); }
      const b = x;
      return `<tr class="pcl-ligne fbB-ouvert"><td><span class="fbB-chev">${SVG(I.bas).replace('<svg', '<svg width="14" height="14"')}</span>${esc(b.nom)}<span class="sub">${esc(b.soc)} · ${esc(b.ville)} · ${esc(b.fact)}</span></td><td>${statut()}</td><td>${esc(b.cat)}</td><td class="num">${b.off}</td><td class="num">${b.pers}</td><td><span class="tag-warn">pas de site</span></td></tr>
        <tr class="fbB-detail"><td colspan="6"><div class="fbB-grille"><div>${sLivraison(b)}<div style="height:12px"></div>${sSites(b)}</div><div>${sCatalogue(b)}</div><div>${sOffres(b)}</div><div>${sPersonnes(b)}</div>
          <div class="fbB-actions"><span class="btn btn--primary btn--sm">${SVG(I.plus)}Ajouter un site</span><span class="btn btn--sm">Inviter des personnes</span><span class="btn btn--sm">${SVG(I.doc)}Affiche A2</span><span class="btn btn--sm">${SVG(I.doc)}Cartes</span><span class="btn btn--sm">${SVG(I.doc)}Brochure</span><span style="flex:1"></span><span class="linklike">replier ▴</span></div></div></td></tr>`;
    }).join('');
    return tete() + `<div class="fb-bandeau"><b>B</b> La ligne qui se déplie : un clic ouvre le bureau sous sa ligne, sur toute la largeur, en quatre colonnes ; un autre clic le replie.</div>
      <section class="card pcl-carte">${filtres()}<div class="table-scroll"><table class="ds-table pcl-table">${thead(true)}<tbody>${lignes}</tbody></table></div></section>
      ${legende('Ce que fait la ligne dépliée', ['La « drop list » : la flèche de la ligne déplie le bureau <b>sous lui-même</b>, sur toute la largeur de la page — plus de colonne étroite à droite.', 'Quatre colonnes visibles d’un coup : <b>Livraison et sites</b> · <b>Catalogue</b> · <b>Offres</b> · <b>Personnes et invitation</b> ; les documents et les gestes sur une barre en dessous.', 'On compare facilement : plusieurs bureaux peuvent rester dépliés, la liste reste la liste.', 'Ce qui manque est dit à sa place (pas de site → « Ajouter un site »).'])}`;
  }

  function vueC() {
    const b = B[0];
    const kp = (v, k) => `<div class="card"><div class="v">${v}</div><div class="k">${k}</div></div>`;
    return tete() + `<div class="fb-bandeau"><b>C</b> La page du bureau : la liste s’efface, le bureau prend la page ; une liste déroulante en haut passe d’un bureau à l’autre.</div>
      <div class="fbC-fil"><span class="lien">‹ Tous les bureaux</span><span class="fbC-select"><span>${esc(b.nom)} <small>· ${esc(b.soc)}</small></span>${SVG(I.bas).replace('<svg', '<svg width="16" height="16"')}</span>
        <span class="btn btn--icon btn--sm">${SVG(I.g)}</span><span class="btn btn--icon btn--sm">${SVG(I.d)}</span>${statut()}<span style="flex:1"></span><span class="btn btn--sm">Inviter des personnes</span><span class="btn btn--primary btn--sm">Modifier la livraison</span></div>
      <div class="fbC-kpis">${kp(b.sites.length, 'Site')}${kp(b.dep, 'Départements')}${kp(b.pers, 'Personnes')}${kp(b.off, 'Offres')}${kp(b.cmd, 'Commandes 12 mois')}${kp(b.ca, 'CA 12 mois')}</div>
      <div class="fbC-grille"><div class="card">${sLivraison(b)}<div style="height:14px"></div>${sSites(b)}</div><div class="card">${sCatalogue(b)}<div style="height:14px"></div>${sOffres(b)}</div><div class="card">${sPersonnes(b)}<div style="height:14px"></div>${sDocs()}</div></div>
      <div class="fbC-zoom"><div><div class="titre">La liste déroulante, ouverte (un clic sur le nom du bureau) :</div>
        <div class="fbC-liste" style="box-shadow:0 12px 30px rgba(0,0,0,0.12);border:1px solid var(--line);border-radius:10px;background:#fff;padding:6px"><input placeholder="Chercher un bureau…">${B.map((x, i) => `<div class="${i === 0 ? 'on' : ''}"><span>${esc(x.nom)}</span><small>${x.liv ? esc(x.liv.t.split(' ·')[0]) : 'pas de site'}</small></div>`).join('')}</div></div>
      <div style="flex:1">${legende('Ce que fait la page du bureau', ['Un clic sur un bureau ouvre <b>sa page</b> : toute la largeur, trois colonnes de cartes, rien de caché.', 'En haut, <b>la liste déroulante</b> des bureaux (avec recherche) et les flèches ‹ › pour passer au suivant sans revenir à la liste ; « Tous les bureaux » y ramène.', 'Six chiffres du bureau : sites, départements, personnes, offres, commandes et CA sur 12 mois (lus dans TF Buddy).', 'Les gestes du bureau (modifier la livraison, inviter) en haut à droite.'])}</div></div>`;
  }

  document.getElementById('app').innerHTML = V === 'b' ? vueB() : V === 'c' ? vueC() : vueA();
})();
