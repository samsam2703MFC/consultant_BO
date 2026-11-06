/* La coque du cockpit, partagée par les trois maquettes MétaDonnée : le rail tel qu'il est
   (navDef d'app.js) avec le sous-menu « MétaDonnée » sous « ERP franchisé », la barre du haut.
   Magasins réels ; entreprises, contacts et numéros fictifs. Nous sommes le mardi 28/10/2026, 10:28. */
const MD = {
  // Les formulaires Meta (Facebook / Instagram) lus par /{page}/leadgen_forms.
  forms: [
    { nom: 'Lunch au bureau — Gosselies', page: 'L’Atelier by Gosselies', pf: ['fb', 'ig'], n: 45, dernier: 'il y a 4 min', vers: 'Gosselies', etat: ['ok', '✓ suivi'] },
    { nom: 'Lunch au bureau — Halle', page: 'L’Atelier by Halle', pf: ['fb', 'ig'], n: 31, dernier: 'hier 17:02', vers: 'Halle', etat: ['ok', '✓ suivi'] },
    { nom: 'Plateaux réunion — réseau', page: 'L’Atelier by', pf: ['ig'], n: 23, dernier: '09:51', vers: 'par code postal', etat: ['ok', '✓ suivi'] },
    { nom: 'Petit-déj d’équipe — Sombreffe', page: 'L’Atelier by Sombreffe', pf: ['fb'], n: 9, dernier: 'hier 14:02', vers: 'Sombreffe', etat: ['att', '1 champ neuf'] },
    { nom: 'Ouverture Knokke — liste d’attente', page: 'L’Atelier by Knokke', pf: ['ig'], n: 0, dernier: '—', vers: 'Knokke', etat: ['att', 'à valider'], off: true },
    { nom: 'Concours « La Fève d’Or »', page: 'L’Atelier by', pf: ['fb', 'ig'], n: 412, dernier: 'archivé', vers: '—', etat: ['gr', 'non suivi'], off: true }
  ]
};
const pf = l => `<span class="pfs">${l.map(p => `<span class="pf ${p}" title="${p === 'fb' ? 'Facebook' : 'Instagram'}">${p === 'fb' ? 'f' : 'IG'}</span>`).join('')}</span>`;
const pill = (cls, txt, w, tip) => `<span class="pill ${cls}" style="width:${w}px"${tip ? ` title="${tip}"` : ''}>${txt}</span>`;
const vide = w => `<span class="vide" style="width:${w}px">—</span>`;
/* La suite d'un lead : Meta · ERP · SMS · vu · contacté · bureau connecté.
   f = fait, c = en cours (l'étape qu'on attend), r = en retard, x = arrêté. */
const suite = etats => `<span class="suite" title="Meta · ERP · SMS · vu · contacté · bureau connecté">${etats.split('').map((e, i) => (i ? `<s class="${e === 'f' || e === 'c' || e === 'r' ? 'f' : ''}"></s>` : '') + `<i class="${e === '.' ? '' : e}"></i>`).join('')}</span>`;

function coque(corps, opts) {
  const it = (lib, on, badge) => `<div class="it ${on ? 'on' : ''}"><span>${lib}</span>${badge ? `<em>${badge}</em>` : ''}</div>`;
  const ferme = lib => `<div class="it par"><span><span class="ch">▸</span> ${lib}</span></div>`;
  const md = `<div class="it par ouvert"><span><span class="ch">▾</span> MétaDonnée</span><span class="neuf">nouveau</span></div>`
    + opts.enfants.map(e => `<div class="it sub ${e[1] ? 'on' : ''}"><span>${e[0]}</span>${e[2] ? `<em>${e[2]}</em>` : ''}</div>`).join('');
  const rail = `<aside class="rail"><div class="logo">L'ATELIER<small>SUCRÉ · SALÉ · À EMPORTER</small></div><div class="pr">PILOTAGE RÉSEAU</div>
    <div class="cherche">Rechercher partout…</div>
    <div class="sec">Pilotage</div>${it('Résultat')}${it('Performance')}${it('Dashboard magasin ↗')}
    <div class="sec">Franchisés · évaluation et suivi</div>${it('Fiche franchisé')}${it('Scoring du trimestre')}${ferme('Suivi journalier et opérations')}${ferme('Suivi de terrain')}
    <div class="sec">Magasins</div>${it('Analyse magasin')}${it('Budget')}${it('Jours creux')}${it('Invendus et poubelle')}${it('Plan de développement')}${it('Scouting — où ouvrir')}
    <div class="sec">Produits</div>${it('Catalogue')}${it('Gamme · scoring')}${it('Où ça se vend')}${it('Dans le temps')}
    <div class="sec">Centrale d'achat</div>${it('Achats')}${it('Facturation magasins')}
    <div class="sec">Marque &amp; marketing</div>${it('Campagnes')}${it('Offres et canaux')}${it('Projets de développement')}${it('Fonds &amp; Royalties')}
    <div class="sec">ERP franchisé</div>${it('Développement commercial')}${it('Prospection')}${it('Prospection mobile')}${md}${it('Tablette vendeuses')}${it('App worker ↗')}${it('Newsletter')}${it('Newsletter magasin')}${ferme('Gestion de production')}
    <div class="sec">Contrôle</div>${it('Contrôle posts Facebook')}
    <div class="sec">Administration</div>${it('Paramètres')}${it('Journal &amp; diagnostic')}</aside>`;
  // La barre dit l'état des deux bouts du tuyau, comme la sonde Meta du CRM : Meta et l'ERP.
  const bar = `<div class="bar"><div class="et"><i></i><b>Meta</b> répond · 5 pages · 2 comptes Instagram · webhook leadgen reçu à 10:24
      <span style="margin:0 4px;color:#CFC8BC">|</span><i></i><b>ERP</b> atelierby.tfbuddy.com répond · statuts relus à 10:20</div><span class="sp"></span>
    <select><option>Tous les magasins</option></select>
    ${(opts.boutons || []).map((b, i) => `<span class="b ${i === opts.boutons.length - 1 ? 'p' : ''}">${b}</span>`).join('')}</div>`;
  document.body.innerHTML = `<div class="app">${rail}<main>${bar}${corps}
    <div class="pied">Maquette ${opts.lettre} · magasins réels, entreprises, contacts et numéros fictifs · Meta : la lecture du CRM (Graph API v25.0, webhook <code>leadgen</code>, jeton d’utilisateur système, permission <code>leads_retrieval</code>, rattrapage horaire sur 3 jours) · ERP : <code>POST /api/v1/consultant/leads</code> et <code>GET /api/v1/consultant/leads?since=</code> sur atelierby.tfbuddy.com — routes à écrire avec l’équipe du panel · SMS : SMSAPI, comme le CRM.</div></main></div>`;
}
