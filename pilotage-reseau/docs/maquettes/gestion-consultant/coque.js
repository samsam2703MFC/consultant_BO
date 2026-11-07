/* La coque du cockpit, partagée par les quatre maquettes : le rail avec l'entrée « Gestion consultant »,
   la barre, les onglets. Noms de consultants fictifs ; magasins réels. */
const GC = {
  profils: { strat: 'Stratégie et Développement', ops: 'Opérations et qualité', prod: 'Produit et production' },
  consultants: [
    { id: 'sam', nom: 'Sam V.', profil: 'strat', responsable: ['Halle', 'Gosselies'], agenda: 'ics' },
    { id: 'nadia', nom: 'Nadia K.', profil: 'ops', responsable: ['Corbais'], agenda: 'ics' },
    { id: 'youssef', nom: 'Youssef B.', profil: 'prod', responsable: ['Sombreffe'], agenda: null }
  ],
  // Les types de visite : la liste déroulante. Chaque type porte sa durée, le profil demandé,
  // sa checklist (modules et points) et ses tâches à faire (avant, pendant, après).
  types: [
    { code: 'reguliere', nom: 'Régulière', duree: 90, profil: 'celui du cadre', checklist: 'Standard · 23 points · 6 modules', taches: 6 },
    { code: 'production', nom: 'Production', duree: 180, profil: 'Produit et production', checklist: 'Production · 14 points · 4 modules', taches: 5 },
    { code: 'hygiene', nom: 'Hygiène et qualité', duree: 60, profil: 'Opérations et qualité', checklist: 'Hygiène · 12 points · 3 modules', taches: 4 },
    { code: 'msp', nom: 'Client mystère', duree: 45, profil: 'Opérations et qualité', checklist: 'Client mystère · 8 points', taches: 3 },
    { code: 'bilan', nom: 'Bilan trimestriel', duree: 120, profil: 'Stratégie et Développement', checklist: 'Bilan · 10 points · scoring, P&L, plan, objectifs', taches: 5 },
    { code: 'ouverture', nom: 'Ouverture / lancement', duree: 240, profil: 'Stratégie et Développement', checklist: 'Ouverture · 31 points · 7 modules', taches: 9 },
    { code: 'suivi', nom: 'Suivi plan d’action', duree: 45, profil: 'le consultant du plan', checklist: 'Les points des plans ouverts', taches: 2 },
    { code: 'revisite', nom: 'Revisite', duree: 60, profil: 'le même consultant', checklist: 'Les points non conformes de la dernière visite', taches: 2 }
  ],
  // Le cadre par magasin : combien de visites de chaque type, par mois, par quel profil, avec quelle checklist, par qui.
  cadre: [
    { magasin: 'Halle', note: 'ouverture mars 2026', parMois: 4, lignes: [
      ['Régulière', '2 / mois', 'strat', 'Standard · 23 pts', 'Sam V.', '2 / 2'],
      ['Production', '1 / mois', 'prod', 'Production · 14 pts', 'Youssef B.', '1 / 1'],
      ['Hygiène et qualité', '1 / mois', 'ops', 'Hygiène · 12 pts', 'Nadia K.', '1 / 1'],
      ['Bilan trimestriel', '1 / trim.', 'strat', 'Bilan · 10 pts', 'Sam V.', 'Q3 : le 27/10']] },
    { magasin: 'Gosselies', note: '', parMois: 2, lignes: [
      ['Régulière', '1 / mois', 'strat', 'Standard · 23 pts', 'Sam V.', '1 / 1'],
      ['Hygiène et qualité', '1 / mois', 'ops', 'Hygiène · 12 pts', 'Nadia K.', '1 / 1'],
      ['Bilan trimestriel', '1 / trim.', 'strat', 'Bilan · 10 pts', 'Sam V.', 'Q3 : fait le 02/10']] },
    { magasin: 'Corbais', note: '', parMois: 1, lignes: [
      ['Régulière', '1 / mois', 'ops', 'Standard · 23 pts', 'Nadia K.', '1 / 1'],
      ['Client mystère', '1 / trim.', 'ops', 'Client mystère · 8 pts', 'Nadia K.', 'Q3 : fait le 18/09'],
      ['Bilan trimestriel', '1 / trim.', 'strat', 'Bilan · 10 pts', 'Sam V.', 'Q3 : le 22/10']] },
    { magasin: 'Sombreffe', note: '', parMois: 1, lignes: [
      ['Régulière', '1 / mois', 'prod', 'Standard · 23 pts', 'Youssef B.', '0 / 1 · due'],
      ['Client mystère', '1 / trim.', 'ops', 'Client mystère · 8 pts', 'Nadia K.', 'Q3 : fait le 15/09'],
      ['Bilan trimestriel', '1 / trim.', 'strat', 'Bilan · 10 pts', 'Sam V.', 'Q3 : à planifier']] }
  ]
};
function coque(onglet, corps, titre, sous, consultant, opts) {
  opts = opts || {};
  const actif = opts.actif || 'gestion';
  const ongs = opts.tabs || [['planning', 'Mon planning'], ['taches', 'Tâches et contrôles', '14'], ['reseau', 'Réseau'], ['cadre', 'Cadre de visite']];
  const it = (k, lib, sub, neuf) => `<div class="it ${sub ? 'sub' : ''} ${actif === k ? 'on' : ''} ${neuf ? 'neuf' : ''}">${lib}</div>`;
  // Le rail : une section « Franchisés · évaluation et suivi » réunit le scoring, les visites et tout ce qui
  // évalue ou suit le franchisé, en deux volets — journalier et opérations (ce que les données disent chaque
  // jour) et terrain (ce que le consultant constate sur place). Les autres sections gardent le reste.
  const rail = `<aside class="rail"><div class="logo">L'ATELIER<small>SUCRÉ · SALÉ · À EMPORTER</small></div><div class="pr">PILOTAGE RÉSEAU</div>
    <div class="cherche">Rechercher partout…</div>
    <div class="sec">Pilotage</div>${it('resultat', 'Résultat')}${it('performance', 'Performance')}${it('dash', 'Dashboard magasin ↗')}
    <div class="sec">Franchisés · évaluation et suivi</div>${it('fiche', 'Fiche franchisé', false, true)}${it('scoring', 'Scoring du trimestre')}
    <div class="ssec">Suivi journalier et opérations</div>${it('taches', 'Tâches et contrôles photo', true)}${it('prod', 'Production et invendus', true)}${it('objectifs', 'Objectifs', true)}${it('remarques', 'Remarques opérateurs', true, true)}${it('google', 'Note Google et avis', true)}${it('reporting', 'Reporting automatisé', true)}
    <div class="ssec">Suivi de terrain</div>${it('gestion', 'Gestion consultant', true, true)}${it('visites', 'Visites ↗', true)}${it('plans', 'Plans d’action', true)}${it('msp', 'Client mystère', true)}${it('conformite', 'Conformité du comptoir', true)}
    <div class="sec">Magasins</div>${it('analyse', 'Analyse magasin')}${it('budget', 'Budget')}${it('creux', 'Jours creux')}${it('plan', 'Plan de développement')}${it('scouting', 'Scouting — où ouvrir')}
    <div class="sec">Produits</div>${it('catalogue', 'Catalogue')}${it('gamme', 'Gamme · scoring')}${it('ou', 'Où ça se vend')}${it('temps', 'Dans le temps')}
    <div class="sec">Centrale d'achat</div>${it('achats', 'Achats')}${it('factu', 'Facturation magasins')}
    <div class="sec">Marque &amp; marketing</div>${it('camp', 'Campagnes')}${it('offres', 'Offres et canaux')}</aside>`;
  const boutons = opts.boutons || ['Nouvelle tâche', 'Planifier une visite', 'Exporter'];
  const bar = `<div class="bar"><div class="et"><i></i>4 magasins · 3 consultants · visites et plans d'action relus à l'instant</div><span class="sp"></span>
    <select><option>${opts.select || (consultant ? consultant.nom : 'Tous les consultants')}</option></select>
    ${boutons.map((b, i) => `<span class="b ${i === 1 ? 'p' : ''}">${b}</span>`).join('')}</div>`;
  const tabs = onglet ? `<div class="tabs">${ongs.map(o => `<span class="${o[0] === onglet ? 'on' : ''}">${o[1]}${o[2] ? '<em>' + o[2] + '</em>' : ''}</span>`).join('')}</div>` : '<div style="height:14px"></div>';
  document.body.innerHTML = `<div class="app">${rail}<main>${bar}${tabs}<div class="titre"><h1>${titre}</h1><span class="ss">${sous}</span></div>${corps}
    <div class="pied">Maquette · consultants fictifs, magasins réels · les visites, points de contrôle et plans d'action sont ceux du module Visites (ceo_visite, ceo_visite_point, ceo_visite_action) ; le cadre de visite (types, nombre par magasin, profils, checklists) est un réglage du cockpit ; les tâches du panel viennent de /consultant/shops/{id}/tasks et du helpdesk ; l'agenda Google par flux ICS et invitations · le rail réunit l'évaluation et le suivi des franchisés en deux volets : journalier et opérations, terrain.</div></main></div>`;
}
