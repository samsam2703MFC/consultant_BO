/* Maquette « Coffre-fort du franchisé » (22/11/2026) : une page, un tableau à onglets. L'onglet montré vient de
   <body data-onglet="…">. Données d'exemple : magasin réel (Gosselies), personnes et sociétés fictives. */
(function () {
  'use strict';
  const ONGLETS = [
    ['tout', 'Tout'], ['contrat', 'Contrat & DIP'], ['legal', 'Légal'], ['formation', 'Formation'],
    ['visites', 'Rapports de visite'], ['finances', 'Finances'], ['courriers', 'Courriers & réunions']];
  // [onglet, sous-groupe, titre, détail, date, état [classe, texte], source, taille, nouveau?]
  const D = [
    ['contrat', '', 'Document d’information précontractuelle (DIP) v29', 'remis le 12/09/2024 · accusé de réception signé', '12/09/2024', ['val', 'signé'], 'Nadia K.', '2,4 Mo'],
    ['contrat', '', 'Contrat de franchise', '9 ans · échéance 14/10/2033 · zone : Gosselies centre', '15/10/2024', ['val', 'en vigueur'], 'Nadia K.', '1,1 Mo'],
    ['contrat', '', 'Annexes du contrat (11 annexes)', 'manuel, redevances, plan d’implantation, enseigne…', '15/10/2024', ['val', 'en vigueur'], 'Nadia K.', '6,8 Mo'],
    ['contrat', '', 'Avenant n° 1 — zone d’exclusivité', 'ajoute Ransart et Jumet', '03/02/2026', ['val', 'signé'], 'Nadia K.', '0,3 Mo'],
    ['contrat', '', 'Contrat de mise à disposition du matériel', 'four ventilé, deux vitrines réfrigérées, trancheuse', '20/02/2025', ['val', 'en vigueur'], 'Nadia K.', '0,6 Mo'],
    ['contrat', '', 'Dossier d’implantation', 'étude de marché, budget validé, plan financier', '30/08/2024', ['brou', 'archive'], 'Nadia K.', '4,2 Mo'],
    ['legal', '', 'Statuts de Lambert Boulangerie SRL', 'publiés au Moniteur belge', '02/07/2024', ['brou', 'archive'], 'M. Lambert', '0,8 Mo'],
    ['legal', '', 'Extrait de la Banque-Carrefour des Entreprises', 'numéro d’entreprise, unité d’établissement', '05/01/2026', ['val', 'à jour'], 'M. Lambert', '0,1 Mo'],
    ['legal', '', 'Attestation d’assurance RC exploitation', 'échéance 31/12/2026', '01/01/2026', ['aval', 'à renouveler · 39 j'], 'M. Lambert', '0,2 Mo'],
    ['legal', '', 'Autorisation AFSCA', 'unité d’établissement · boulangerie, traiteur', '12/02/2025', ['val', 'valide'], 'M. Lambert', '0,2 Mo'],
    ['legal', '', 'Bail commercial', '9 ans · rue de la Station', '01/09/2024', ['val', 'en vigueur'], 'M. Lambert', '1,9 Mo'],
    ['legal', '', 'Attestation de la sécurité incendie', 'visite des pompiers', '20/02/2025', ['val', 'valide'], 'M. Lambert', '0,3 Mo'],
    ['legal', '', 'Registre des traitements (RGPD)', 'fidélité, caméras, fichiers du personnel', '10/03/2025', ['aval', 'à revoir'], 'Nadia K.', '0,1 Mo'],
    ['formation', 'Formation initiale', 'Attestation de formation initiale', '31 formations de pré-ouverture · 172 h', '14/03/2025', ['val', 'obtenue'], 'Nadia K.', '0,4 Mo'],
    ['formation', 'Formation initiale', 'Bilan de la période d’ouverture', 'six semaines avec le garant d’ouverture', '25/04/2025', ['val', 'clos'], 'Nadia K.', '0,9 Mo'],
    ['formation', 'Formation initiale', 'Plan de formation pré-ouverture', '11 blocs · formateurs désignés', '20/11/2024', ['brou', 'archive'], 'Nadia K.', '0,5 Mo'],
    ['formation', 'Formation continue', 'Document de formation (version 7)', 'chaque formation continue validée, qui, quand, les photos', '22/11/2026', ['val', 'à jour'], 'automatique', '3,6 Mo', 1],
    ['formation', 'Formation continue', 'FC-023 Pistolet poulet-curry · archive de validation', 'validée par Nadia K. · 14 photos qualité', '22/11/2026', ['val', 'validée'], 'automatique', '2,1 Mo', 1],
    ['formation', 'Formation continue', 'FC-011 Façonnage de la baguette tradition · archive', 'validée par Nadia K. · 14 photos', '31/10/2026', ['val', 'validée'], 'automatique', '1,8 Mo'],
    ['formation', 'Formation continue', 'FC-019 Tartelette tatin individuelle · archive', 'validée par Nadia K. · 14 photos', '20/10/2026', ['val', 'validée'], 'automatique', '1,7 Mo'],
    ['formation', 'Certificats', 'HACCP & hygiène (FO-02) — recyclage annuel', '6 personnes · prochain avant le 15/03/2027', '15/03/2026', ['val', 'valide'], 'automatique', '0,6 Mo'],
    ['formation', 'Certificats', 'Allergènes & FIC (FO-16)', '6 personnes', '15/03/2026', ['val', 'valide'], 'automatique', '0,4 Mo'],
    ['visites', '', 'Visite régulière — Nadia K.', 'checklist 41/44 · 3 points à travailler · lu le 20/11', '18/11/2026', ['val', 'lu'], 'automatique', '1,2 Mo'],
    ['visites', '', 'Visite hygiène — Nadia K.', 'checklist 28/30 · 1 point · lu le 05/11', '04/11/2026', ['val', 'lu'], 'automatique', '0,9 Mo'],
    ['visites', '', 'Client mystère — octobre', 'note 8,4 / 10 · accueil, vitrine, caisse', '22/10/2026', ['val', 'lu'], 'automatique', '0,7 Mo'],
    ['visites', '', 'Bilan du 3ᵉ trimestre — Nadia K.', 'scoring 2ᵉ sur 4 · plan d’action T4', '10/10/2026', ['val', 'lu'], 'automatique', '1,6 Mo'],
    ['visites', '', 'Visite régulière — Nadia K.', 'checklist 39/44 · 5 points · lu le 23/09', '21/09/2026', ['val', 'lu'], 'automatique', '1,1 Mo'],
    ['visites', '', 'Visite production — Youssef B.', 'checklist 22/24 · 2 points · pas encore lu', '22/11/2026', ['aval', 'nouveau'], 'automatique', '1,0 Mo', 1],
    ['finances', '', 'Dossier d’analyse — octobre 2026', 'synthèse, compte de résultat, produits, équipe', '05/11/2026', ['val', 'mensuel'], 'Nadia K.', '1,4 Mo'],
    ['finances', '', 'Dossier d’analyse — 3ᵉ trimestre 2026', 'avec les croisements', '08/10/2026', ['val', 'trimestriel'], 'Nadia K.', '2,2 Mo'],
    ['finances', '', 'Budget 2027 validé', 'ventes prévues 721 681 € · marchandises 40 %', '15/11/2026', ['val', 'validé'], 'Nadia K.', '0,5 Mo'],
    ['finances', '', 'Relevé des redevances — octobre 2026', 'marque, assistance, marketing', '03/11/2026', ['val', 'payé'], 'Nadia K.', '0,2 Mo'],
    ['courriers', '', 'Rapport de réunion — lancement des fêtes', 'Nadia K. et M. Lambert', '12/11/2026', ['val', 'lu'], 'Nadia K.', '0,3 Mo'],
    ['courriers', '', 'Courrier — nouvelle grille de prix 2027', 'envoyé par le siège', '30/10/2026', ['val', 'lu'], 'Nadia K.', '0,2 Mo'],
    ['courriers', '', 'Rapport de réunion — bilan à six mois', 'Sam V., Nadia K., M. Lambert', '28/10/2025', ['brou', 'archive'], 'Nadia K.', '0,4 Mo'],
    ['courriers', '', 'Courrier — rappel assurance RC', 'à renvoyer avant le 31/12', '20/11/2026', ['aval', 'à faire'], 'Nadia K.', '0,1 Mo', 1]];
  const ong = document.body.dataset.onglet || 'tout';
  const sel = document.body.dataset.sel || '';
  const n = k => (k === 'tout' ? D.length : D.filter(d => d[0] === k).length);
  const L = ong === 'tout' ? D.filter(d => d[8]).concat(D.filter(d => !d[8]).slice(0, 7)) : D.filter(d => d[0] === ong);
  const lib = Object.fromEntries(ONGLETS);
  let lignes = '', g = null;
  for (const d of L) {
    const grp = ong === 'tout' ? '' : d[1];
    if (grp && grp !== g) { lignes += `<div class="grp">${grp}<i>${L.filter(x => x[1] === grp).length}</i></div>`; g = grp; }
    lignes += `<div class="ligne${d[2] === sel ? ' sel' : ''}"><span class="ic-doc">PDF</span><span class="c-nom"><b>${d[2]}${d[8] ? ' <span class="nv">nouveau</span>' : ''}</b><small>${d[3]}</small></span>`
      + (ong === 'tout' ? `<span class="c-w" style="width:130px">${lib[d[0]]}</span>` : '')
      + `<span class="c-w" style="width:84px">${d[4]}</span><span style="width:150px"><span class="st ${d[5][0]}">${d[5][1]}</span></span><span class="c-w mut" style="width:96px">${d[6]}</span><span class="c-w mut" style="width:56px">${d[7]}</span><span class="act">Ouvrir · ⤓</span></div>`;
  }
  const s = D.find(d => d[2] === sel) || L[0];
  const tabs = ONGLETS.map(([k, t]) => `<span class="${k === ong ? 'on' : ''}">${t}<i>${n(k)}</i></span>`).join('');
  const sous = ong === 'formation' ? '<div class="sous"><span class="seg"><span class="on">Tout <i>9</i></span><span>Initiale <i>3</i></span><span>Continue <i>4</i></span><span>Certificats <i>2</i></span></span></div>'
    : ong === 'visites' ? '<div class="sous"><span class="seg"><span class="on">Toutes <i>6</i></span><span>Régulières</span><span>Hygiène</span><span>Production</span><span>Client mystère</span><span>Bilans</span></span><span class="sp"></span><span class="mut petit">les rapports en ligne restent dans « Mon suivi » ; ici, leur PDF</span></div>' : ong === 'legal' ? '<div class="sous"><span class="mut petit">Vos pièces légales : vous et votre consultante les déposez ici. Une pièce avec une échéance passe « à renouveler » 60 jours avant.</span><span class="sp"></span><span class="b p">＋ Déposer un document</span></div>' : '';
  document.getElementById('coffre').innerHTML = `
    <div class="kpis">
      <div class="kpi"><div class="l">Documents</div><div class="v">${D.length}</div><div class="s">boutique de Gosselies</div></div>
      <div class="kpi ac"><div class="l">Nouveaux</div><div class="v">4</div><div class="s">pas encore ouverts</div></div>
      <div class="kpi or"><div class="l">À renouveler</div><div class="v">1</div><div class="s">assurance RC · 31/12/2026</div></div>
      <div class="kpi"><div class="l">Contrat de franchise</div><div class="v" style="font-size:18px;margin-top:6px">jusqu’au 14/10/2033</div><div class="s">avenant n° 1 du 03/02/2026</div></div>
    </div>
    <div class="lay">
      <section class="carte ann"><span class="ann-n" style="left:-10px;top:-10px">1</span>
        <div class="onglets">${tabs}</div>${sous}
        <div class="tete"><span style="width:30px"></span><span class="c-nom">Document</span>${ong === 'tout' ? '<span style="width:130px">Onglet</span>' : ''}<span style="width:84px">Date</span><span style="width:150px">État</span><span style="width:96px">Déposé par</span><span style="width:56px">Taille</span><span class="act"></span></div>
        ${ong === 'tout' ? '<div class="grp">Les nouveaux d’abord, puis les derniers ajoutés<i>tout le coffre : choisir un onglet</i></div>' : ''}
        ${lignes}
      </section>
      <aside class="carte apercu ann"><span class="ann-n" style="left:-10px;top:-10px">2</span>
        <div class="feuille"><div class="fl1"></div><div class="fl2"></div><div class="fl3"></div><div class="fl3"></div><div class="fl3 c"></div><div class="fl3"></div><div class="fl2" style="margin-top:14px"></div><div class="fl3"></div><div class="fl3 c"></div></div>
        <div class="pad">
          <div class="h3">${lib[s[0]]}${s[1] ? ' · ' + s[1] : ''}</div>
          <div style="font-size:16px;line-height:1.3">${s[2]}</div>
          <div class="mut petit" style="margin:4px 0 10px">${s[3]}</div>
          <div class="kv"><span>Date</span><span>${s[4]}</span><span>État</span><span><span class="st ${s[5][0]}">${s[5][1]}</span></span><span>Déposé par</span><span>${s[6]}</span><span>Taille</span><span>${s[7]} · PDF</span></div>
          <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap"><span class="b p">Ouvrir</span><span class="b">Télécharger</span></div>
          <div class="h3" style="margin-top:16px">Qui l’a ouvert <i>noté à chaque ouverture</i></div>
          <div class="trace"><span class="d">22/11 09:40</span><span class="x">M. Lambert<small>dashboard · Gosselies</small></span></div>
          <div class="trace"><span class="d">22/11 09:15</span><span class="x">Déposé au coffre<small>${s[0] === 'formation' && s[6] === 'automatique' ? 'à la validation de Nadia K.' : s[0] === 'visites' ? 'à l’envoi du rapport' : 'par ' + s[6]}</small></span></div>
        </div>
      </aside>
    </div>`;
})();
