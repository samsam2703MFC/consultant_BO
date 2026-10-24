/* La page du franchisé — le volet « chaque jour » et le journal (24/10/2026), repris de l'ancienne page « Mon suivi »
 * (dashboard/suivi.html) : ce que les données disent sans aller sur place, en tuiles qui se déplient sur leur détail,
 * « Tout voir » ouvrant la liste complète ; ce qui est à surveiller ; le journal des 60 jours. Les libellés sont dans la
 * langue du magasin ; ce qui vient des données (noms de tâches, motifs, commentaires) reste tel quel.
 * window.SuiviJour.rendre(D, lg, outils) rend { jour, fin } : le volet (après les points) et le journal (en bas de page) ;
 * .brancher(racine) ouvre et ferme la liste complète. */
(function () {
  'use strict';
  const T = {
    fr: { chaque: 'Chaque jour', chaqueDef: 'Ce que les données disent, sans aller sur place.', surveiller: 'À surveiller',
      a_oblig: ['Une tâche obligatoire a manqué 1 jour sur 30 : ce jour vaut 0 au scoring.', 'Une tâche obligatoire a manqué {n} jours sur 30 : ces jours valent 0 au scoring.'],
      a_majeur: ['1 contrôle noté majeur ou critique : {liste}. À corriger et rephotographier.', '{n} contrôles notés majeurs ou critiques : {liste}. À corriger et rephotographier.'],
      a_mineur: ['1 infraction mineure sur 30 jours : voir « Infractions ».', '{n} infractions mineures sur 30 jours : voir « Infractions ».'],
      a_invendus: 'Invendus à {p} % du CA sur 7 jours, au-dessus des {c} % : ajustez la production.',
      a_reclam: ['1 réclamation fournisseur sans réponse : relancez le fournisseur.', '{n} réclamations fournisseur sans réponse : relancez les fournisseurs.'],
      a_avis: ['1 avis de 2 étoiles ou moins sur 30 jours : répondez-y sur votre fiche Google.', '{n} avis de 2 étoiles ou moins sur 30 jours : répondez-y sur votre fiche Google.'],
      t_taches: 'Tâches faites · 30 j', oblig_manq: ['obligatoire manquée 1 jour', 'obligatoire manquée {n} jours'], oblig_ok: 'obligatoires tenues',
      tj: 'Le {d} : {x} / {y} photos notées par le consultant', aNoter: '{n} en attente', leg: ['tout rendu', 'partiel', 'obligatoire manquée', 'non relevé'],
      m_taches: 'Vos tâches jour par jour · 30 jours', cols_taches: ['Jour', 'Rendues', 'Attendues', 'Obligatoire'], manquee: 'manquée', tenue: 'tenue',
      t_notes: 'Notes du consultant', notees: '{n} notées', rienNote: 'rien de noté sur 30 jours', aucuneNote: 'Aucune tâche notée sur 30 jours.',
      notes: { 5: 'Exemplaire', 4: 'Conforme', 3: 'Non conforme — mineur', 2: 'Non conforme — majeur', 1: 'Non conforme — critique' },
      t_infr: 'Infractions · 30 j', niv: { mineure: 'mineure', majeure: 'majeure', critique: 'critique' }, nivs: { mineure: 'mineures', majeure: 'majeures', critique: 'critiques' }, aucune: 'aucune',
      aucuneInfr: 'Aucune infraction sur 30 jours.', recid: '{n} j sur 7', m_infr: 'Vos infractions · 30 jours', cols_infr: ['Jour', 'Tâche', 'Note', 'Gravité', 'Commentaire', 'Récidive', 'Suite'],
      suite_ok: 'conforme', suite_ko: 'toujours non conforme', suite_att: 'pas encore renotée',
      t_inv: 'Invendus · 7 j', inv_sous: 'du CA · cible sous {c} %', inv_det: 'Du {du} au {au} : {cout} € d’invendus', inv_ca: ' pour {ca} € de CA',
      t_rec: 'Réclamations · 30 j', rec_ouv: '{n} sans réponse', rec_ok: 'toutes traitées', aucuneRec: 'Aucune réclamation sur 30 jours.',
      st_rec: { ouverte: 'sans réponse', refusee: 'refusée', acceptee: 'acceptée' }, m_rec: 'Vos réclamations fournisseur · 30 jours', cols_rec: ['Date', 'Fournisseur', 'Référence', 'Qté', 'Motif', 'Statut', 'Réponse', 'Montant'],
      t_obj: 'Objectifs', obj_aucun: 'aucun objectif en cours', obj_clients: 'clients · {nom}', obj_prod: '« {nom} » : {pct} · {v} / {o} pièces · jusqu’au {fin}', obj_aucunProd: 'Pas d’objectif produits en cours.',
      obj_camp: 'Campagne « {nom} » : {r} clients', obj_jour: 'jour {j} / {n}',
      t_rem: 'Remarques · 30 j', aucuneRem: 'Aucune remarque à votre équipe sur 30 jours.',
      t_google: 'Note Google', g_avis: '{n} avis', g_faibles: '{n} ≤ 2 sur 30 j', g_aucunFaible: 'aucun avis faible', g_cible: 'cible {c}', g_sans: 'pas de fiche reliée', g_aucunAvis: 'Pas d’avis récent.',
      t_ca: 'CA de la semaine', ca_obj: 'objectif {o} €', ca_sans: 'ne se lit pas', ca_jours: ['1 jour vu cette semaine.', '{n} jours vus cette semaine.'], ca_sansDet: 'Le CA de la semaine ne se lit pas.',
      t_comptoir: 'Votre comptoir', plano_tenu: 'planogramme tenu', oblig: 'obligatoires {p} / {o}', plano_det: 'Planogramme : {pct} % ({t} / {e} emplacements)',
      asso_det: 'Références obligatoires vues en caisse sur {j} j : {p} / {o}', manquantes: ['1 manquante', '{n} manquantes'], toutes: 'toutes passées en caisse',
      toutVoir: 'Tout voir ›', fermer: 'Fermer', nonLu: 'ne se lit pas', journal: 'Le journal · 60 jours', journalDef: 'Ce qui a été constaté et décidé.',
      volet: { journalier: 'chaque jour', terrain: 'sur place' }, rienJournal: 'Rien sur la période.' },
    nl: { chaque: 'Elke dag', chaqueDef: 'Wat de cijfers zeggen, zonder ter plaatse te gaan.', surveiller: 'Op te volgen',
      a_oblig: ['Een verplichte taak werd 1 dag op 30 gemist: die dag telt als 0 in de score.', 'Een verplichte taak werd {n} dagen op 30 gemist: die dagen tellen als 0 in de score.'],
      a_majeur: ['1 controle beoordeeld als groot of kritiek: {liste}. Verbeteren en opnieuw fotograferen.', '{n} controles beoordeeld als groot of kritiek: {liste}. Verbeteren en opnieuw fotograferen.'],
      a_mineur: ['1 kleine overtreding op 30 dagen: zie « Overtredingen ».', '{n} kleine overtredingen op 30 dagen: zie « Overtredingen ».'],
      a_invendus: 'Onverkochte waren op {p} % van de omzet over 7 dagen, boven de {c} %: stem de productie bij.',
      a_reclam: ['1 klacht bij een leverancier zonder antwoord: herinner de leverancier.', '{n} klachten bij leveranciers zonder antwoord: herinner de leveranciers.'],
      a_avis: ['1 beoordeling van 2 sterren of minder op 30 dagen: beantwoord ze op uw Google-fiche.', '{n} beoordelingen van 2 sterren of minder op 30 dagen: beantwoord ze op uw Google-fiche.'],
      t_taches: 'Uitgevoerde taken · 30 d', oblig_manq: ['verplichte taak 1 dag gemist', 'verplichte taak {n} dagen gemist'], oblig_ok: 'verplichte taken uitgevoerd',
      tj: 'Op {d}: {x} / {y} foto’s beoordeeld door de consultant', aNoter: '{n} wachten', leg: ['alles ingeleverd', 'gedeeltelijk', 'verplichte taak gemist', 'niet opgenomen'],
      m_taches: 'Uw taken dag per dag · 30 dagen', cols_taches: ['Dag', 'Ingeleverd', 'Verwacht', 'Verplicht'], manquee: 'gemist', tenue: 'uitgevoerd',
      t_notes: 'Beoordeling consultant', notees: '{n} beoordeeld', rienNote: 'niets beoordeeld op 30 dagen', aucuneNote: 'Geen taak beoordeeld op 30 dagen.',
      notes: { 5: 'Voorbeeldig', 4: 'In orde', 3: 'Niet in orde — klein', 2: 'Niet in orde — groot', 1: 'Niet in orde — kritiek' },
      t_infr: 'Overtredingen · 30 d', niv: { mineure: 'klein', majeure: 'groot', critique: 'kritiek' }, nivs: { mineure: 'klein', majeure: 'groot', critique: 'kritiek' }, aucune: 'geen',
      aucuneInfr: 'Geen overtreding op 30 dagen.', recid: '{n} d op 7', m_infr: 'Uw overtredingen · 30 dagen', cols_infr: ['Dag', 'Taak', 'Score', 'Ernst', 'Commentaar', 'Herhaling', 'Opvolging'],
      suite_ok: 'in orde', suite_ko: 'nog steeds niet in orde', suite_att: 'nog niet opnieuw beoordeeld',
      t_inv: 'Onverkocht · 7 d', inv_sous: 'van de omzet · doel onder {c} %', inv_det: 'Van {du} tot {au}: {cout} € onverkocht', inv_ca: ' voor {ca} € omzet',
      t_rec: 'Klachten · 30 d', rec_ouv: '{n} zonder antwoord', rec_ok: 'allemaal behandeld', aucuneRec: 'Geen klacht op 30 dagen.',
      st_rec: { ouverte: 'zonder antwoord', refusee: 'geweigerd', acceptee: 'aanvaard' }, m_rec: 'Uw klachten bij leveranciers · 30 dagen', cols_rec: ['Datum', 'Leverancier', 'Referentie', 'Aantal', 'Reden', 'Status', 'Antwoord', 'Bedrag'],
      t_obj: 'Doelstellingen', obj_aucun: 'geen lopende doelstelling', obj_clients: 'klanten · {nom}', obj_prod: '« {nom} »: {pct} · {v} / {o} stuks · tot {fin}', obj_aucunProd: 'Geen lopende productdoelstelling.',
      obj_camp: 'Campagne « {nom} »: {r} klanten', obj_jour: 'dag {j} / {n}',
      t_rem: 'Opmerkingen · 30 d', aucuneRem: 'Geen opmerking aan uw team op 30 dagen.',
      t_google: 'Google-score', g_avis: '{n} beoordelingen', g_faibles: '{n} ≤ 2 op 30 d', g_aucunFaible: 'geen zwakke beoordeling', g_cible: 'doel {c}', g_sans: 'geen fiche gekoppeld', g_aucunAvis: 'Geen recente beoordeling.',
      t_ca: 'Omzet deze week', ca_obj: 'doel {o} €', ca_sans: 'niet leesbaar', ca_jours: ['1 dag bekeken deze week.', '{n} dagen bekeken deze week.'], ca_sansDet: 'De omzet van deze week is niet leesbaar.',
      t_comptoir: 'Uw toonbank', plano_tenu: 'planogram gevolgd', oblig: 'verplicht {p} / {o}', plano_det: 'Planogram: {pct} % ({t} / {e} plaatsen)',
      asso_det: 'Verplichte referenties aan de kassa gezien op {j} d: {p} / {o}', manquantes: ['1 ontbreekt', '{n} ontbreken'], toutes: 'allemaal aan de kassa gezien',
      toutVoir: 'Alles bekijken ›', fermer: 'Sluiten', nonLu: 'niet leesbaar', journal: 'Het logboek · 60 dagen', journalDef: 'Wat werd vastgesteld en beslist.',
      volet: { journalier: 'elke dag', terrain: 'ter plaatse' }, rienJournal: 'Niets in deze periode.' },
    en: { chaque: 'Every day', chaqueDef: 'What the figures say, without going on site.', surveiller: 'To keep an eye on',
      a_oblig: ['A mandatory task was missed 1 day out of 30: that day counts as 0 in the score.', 'A mandatory task was missed {n} days out of 30: those days count as 0 in the score.'],
      a_majeur: ['1 check rated major or critical: {liste}. Fix it and take a new photo.', '{n} checks rated major or critical: {liste}. Fix them and take new photos.'],
      a_mineur: ['1 minor breach in 30 days: see “Breaches”.', '{n} minor breaches in 30 days: see “Breaches”.'],
      a_invendus: 'Unsold goods at {p} % of sales over 7 days, above {c} %: adjust production.',
      a_reclam: ['1 supplier claim without an answer: chase the supplier.', '{n} supplier claims without an answer: chase the suppliers.'],
      a_avis: ['1 review of 2 stars or less in 30 days: reply to it on your Google listing.', '{n} reviews of 2 stars or less in 30 days: reply to them on your Google listing.'],
      t_taches: 'Tasks done · 30 d', oblig_manq: ['mandatory task missed 1 day', 'mandatory task missed {n} days'], oblig_ok: 'mandatory tasks done',
      tj: 'On {d}: {x} / {y} photos rated by the consultant', aNoter: '{n} pending', leg: ['all handed in', 'partial', 'mandatory missed', 'not recorded'],
      m_taches: 'Your tasks day by day · 30 days', cols_taches: ['Day', 'Handed in', 'Expected', 'Mandatory'], manquee: 'missed', tenue: 'done',
      t_notes: 'Consultant ratings', notees: '{n} rated', rienNote: 'nothing rated in 30 days', aucuneNote: 'No task rated in 30 days.',
      notes: { 5: 'Exemplary', 4: 'Compliant', 3: 'Non-compliant — minor', 2: 'Non-compliant — major', 1: 'Non-compliant — critical' },
      t_infr: 'Breaches · 30 d', niv: { mineure: 'minor', majeure: 'major', critique: 'critical' }, nivs: { mineure: 'minor', majeure: 'major', critique: 'critical' }, aucune: 'none',
      aucuneInfr: 'No breach in 30 days.', recid: '{n} d out of 7', m_infr: 'Your breaches · 30 days', cols_infr: ['Day', 'Task', 'Rating', 'Severity', 'Comment', 'Repeat', 'Follow-up'],
      suite_ok: 'compliant', suite_ko: 'still non-compliant', suite_att: 'not rated again yet',
      t_inv: 'Unsold · 7 d', inv_sous: 'of sales · target below {c} %', inv_det: 'From {du} to {au}: €{cout} of unsold goods', inv_ca: ' for €{ca} of sales',
      t_rec: 'Claims · 30 d', rec_ouv: '{n} without an answer', rec_ok: 'all handled', aucuneRec: 'No claim in 30 days.',
      st_rec: { ouverte: 'no answer', refusee: 'refused', acceptee: 'accepted' }, m_rec: 'Your supplier claims · 30 days', cols_rec: ['Date', 'Supplier', 'Item', 'Qty', 'Reason', 'Status', 'Answer', 'Amount'],
      t_obj: 'Targets', obj_aucun: 'no current target', obj_clients: 'customers · {nom}', obj_prod: '“{nom}”: {pct} · {v} / {o} pieces · until {fin}', obj_aucunProd: 'No current product target.',
      obj_camp: 'Campaign “{nom}”: {r} customers', obj_jour: 'day {j} / {n}',
      t_rem: 'Remarks · 30 d', aucuneRem: 'No remark to your team in 30 days.',
      t_google: 'Google rating', g_avis: '{n} reviews', g_faibles: '{n} ≤ 2 in 30 d', g_aucunFaible: 'no low review', g_cible: 'target {c}', g_sans: 'no listing linked', g_aucunAvis: 'No recent review.',
      t_ca: 'Sales this week', ca_obj: 'target €{o}', ca_sans: 'not readable', ca_jours: ['1 day seen this week.', '{n} days seen this week.'], ca_sansDet: 'This week’s sales are not readable.',
      t_comptoir: 'Your counter', plano_tenu: 'planogram followed', oblig: 'mandatory {p} / {o}', plano_det: 'Planogram: {pct} % ({t} / {e} slots)',
      asso_det: 'Mandatory items seen at the till over {j} d: {p} / {o}', manquantes: ['1 missing', '{n} missing'], toutes: 'all seen at the till',
      toutVoir: 'See all ›', fermer: 'Close', nonLu: 'not readable', journal: 'The log · 60 days', journalDef: 'What was observed and decided.',
      volet: { journalier: 'every day', terrain: 'on site' }, rienJournal: 'Nothing over the period.' },
    de: { chaque: 'Jeden Tag', chaqueDef: 'Was die Zahlen sagen, ohne vor Ort zu sein.', surveiller: 'Im Blick behalten',
      a_oblig: ['Eine Pflichtaufgabe wurde an 1 von 30 Tagen versäumt: dieser Tag zählt 0 in der Bewertung.', 'Eine Pflichtaufgabe wurde an {n} von 30 Tagen versäumt: diese Tage zählen 0 in der Bewertung.'],
      a_majeur: ['1 Kontrolle als schwer oder kritisch bewertet: {liste}. Beheben und neu fotografieren.', '{n} Kontrollen als schwer oder kritisch bewertet: {liste}. Beheben und neu fotografieren.'],
      a_mineur: ['1 leichter Verstoß in 30 Tagen: siehe „Verstöße“.', '{n} leichte Verstöße in 30 Tagen: siehe „Verstöße“.'],
      a_invendus: 'Unverkaufte Ware bei {p} % des Umsatzes über 7 Tage, über {c} %: Produktion anpassen.',
      a_reclam: ['1 Lieferantenreklamation ohne Antwort: beim Lieferanten nachhaken.', '{n} Lieferantenreklamationen ohne Antwort: bei den Lieferanten nachhaken.'],
      a_avis: ['1 Bewertung mit 2 Sternen oder weniger in 30 Tagen: antworten Sie in Ihrem Google-Profil.', '{n} Bewertungen mit 2 Sternen oder weniger in 30 Tagen: antworten Sie in Ihrem Google-Profil.'],
      t_taches: 'Erledigte Aufgaben · 30 T.', oblig_manq: ['Pflichtaufgabe an 1 Tag versäumt', 'Pflichtaufgabe an {n} Tagen versäumt'], oblig_ok: 'Pflichtaufgaben erledigt',
      tj: 'Am {d}: {x} / {y} Fotos vom Berater bewertet', aNoter: '{n} ausstehend', leg: ['alles abgegeben', 'teilweise', 'Pflicht versäumt', 'nicht erfasst'],
      m_taches: 'Ihre Aufgaben Tag für Tag · 30 Tage', cols_taches: ['Tag', 'Abgegeben', 'Erwartet', 'Pflicht'], manquee: 'versäumt', tenue: 'erledigt',
      t_notes: 'Bewertungen des Beraters', notees: '{n} bewertet', rienNote: 'nichts bewertet in 30 Tagen', aucuneNote: 'Keine Aufgabe bewertet in 30 Tagen.',
      notes: { 5: 'Vorbildlich', 4: 'In Ordnung', 3: 'Nicht konform — leicht', 2: 'Nicht konform — schwer', 1: 'Nicht konform — kritisch' },
      t_infr: 'Verstöße · 30 T.', niv: { mineure: 'leicht', majeure: 'schwer', critique: 'kritisch' }, nivs: { mineure: 'leicht', majeure: 'schwer', critique: 'kritisch' }, aucune: 'keine',
      aucuneInfr: 'Kein Verstoß in 30 Tagen.', recid: '{n} T. von 7', m_infr: 'Ihre Verstöße · 30 Tage', cols_infr: ['Tag', 'Aufgabe', 'Note', 'Schwere', 'Kommentar', 'Wiederholung', 'Folge'],
      suite_ok: 'in Ordnung', suite_ko: 'weiterhin nicht konform', suite_att: 'noch nicht neu bewertet',
      t_inv: 'Unverkauft · 7 T.', inv_sous: 'des Umsatzes · Ziel unter {c} %', inv_det: 'Vom {du} bis {au}: {cout} € unverkaufte Ware', inv_ca: ' bei {ca} € Umsatz',
      t_rec: 'Reklamationen · 30 T.', rec_ouv: '{n} ohne Antwort', rec_ok: 'alle bearbeitet', aucuneRec: 'Keine Reklamation in 30 Tagen.',
      st_rec: { ouverte: 'ohne Antwort', refusee: 'abgelehnt', acceptee: 'angenommen' }, m_rec: 'Ihre Lieferantenreklamationen · 30 Tage', cols_rec: ['Datum', 'Lieferant', 'Artikel', 'Menge', 'Grund', 'Status', 'Antwort', 'Betrag'],
      t_obj: 'Ziele', obj_aucun: 'kein laufendes Ziel', obj_clients: 'Kunden · {nom}', obj_prod: '„{nom}“: {pct} · {v} / {o} Stück · bis {fin}', obj_aucunProd: 'Kein laufendes Produktziel.',
      obj_camp: 'Kampagne „{nom}“: {r} Kunden', obj_jour: 'Tag {j} / {n}',
      t_rem: 'Hinweise · 30 T.', aucuneRem: 'Kein Hinweis an Ihr Team in 30 Tagen.',
      t_google: 'Google-Bewertung', g_avis: '{n} Bewertungen', g_faibles: '{n} ≤ 2 in 30 T.', g_aucunFaible: 'keine schwache Bewertung', g_cible: 'Ziel {c}', g_sans: 'kein Profil verknüpft', g_aucunAvis: 'Keine aktuelle Bewertung.',
      t_ca: 'Umsatz dieser Woche', ca_obj: 'Ziel {o} €', ca_sans: 'nicht lesbar', ca_jours: ['1 Tag diese Woche erfasst.', '{n} Tage diese Woche erfasst.'], ca_sansDet: 'Der Umsatz dieser Woche ist nicht lesbar.',
      t_comptoir: 'Ihre Theke', plano_tenu: 'Planogramm eingehalten', oblig: 'Pflicht {p} / {o}', plano_det: 'Planogramm: {pct} % ({t} / {e} Plätze)',
      asso_det: 'Pflichtartikel an der Kasse gesehen in {j} T.: {p} / {o}', manquantes: ['1 fehlt', '{n} fehlen'], toutes: 'alle an der Kasse gesehen',
      toutVoir: 'Alles ansehen ›', fermer: 'Schließen', nonLu: 'nicht lesbar', journal: 'Das Protokoll · 60 Tage', journalDef: 'Was festgestellt und entschieden wurde.',
      volet: { journalier: 'jeden Tag', terrain: 'vor Ort' }, rienJournal: 'Nichts in diesem Zeitraum.' }
  };

  let MOD = {};
  function rendre(D, lg, o) {
    const L = T[lg] || T.fr; const { esc, nf, fD, fDL } = o; const J = D.journalier; MOD = {};
    const tr = (k, n, v) => { let s = L[k]; if (Array.isArray(s)) { s = s[n === 1 ? 0 : 1]; } s = String(s).replace('{n}', n == null ? '' : n); Object.keys(v || {}).forEach(x => { s = s.split('{' + x + '}').join(v[x]); }); return s; };
    const mu = s => '<span class="fr-mu">' + esc(s) + '</span>';
    const tab = (cols, rows) => '<div class="fr-tabw"><table class="fr-tab"><thead><tr>' + cols.map(c => '<th>' + esc(c) + '</th>').join('') + '</tr></thead><tbody>' + rows.map(r => '<tr>' + r.map(c => '<td>' + c + '</td>').join('') + '</tr>').join('') + '</tbody></table></div>';
    const tuile = (k, lab, v, sous, cls, html, modal) => { if (modal) { MOD[k] = modal; } return `<details class="fr-k${cls ? ' k-' + cls : ''}"><summary><small>${esc(lab)}</small><b>${esc(v)}</b><span>${esc(sous)}</span></summary><div class="d">${html}${modal ? `<div><button class="fr-lienb" type="button" data-modal="${k}">${esc(L.toutVoir)}</button></div>` : ''}</div></details>`; };
    let h = '';
    if (J && !J.indispo) {
      const TA = J.taches || {}, tj = J.tachesJour || {}, RV = J.revues || {}, IV = J.invendus || {}, RC = J.reclamations || {}, OB = J.objectifs || { produits: [], campagnes: [] };
      const RM = J.remarques || { n: 0, parOperateur: [], dernieres: [] }, G = J.google, CA = J.ca, CF = J.comptoir || {}, PL = CF.planogramme || {}, AS = CF.assortiment || {};
      // À surveiller : ce que les données demandent, en plus des points du consultant.
      const al = [];
      if (TA.joursObligManques) { al.push(['p1', tr('a_oblig', TA.joursObligManques)]); }
      const graves = (RV.majeures || 0) + (RV.critiques || 0);
      if (RV.lu && graves) { al.push(['p0', tr('a_majeur', graves, { liste: (RV.infractions || []).filter(i => i.niveau !== 'mineure').slice(0, 3).map(i => i.tache).join(', ') })]); }
      if (RV.lu && RV.mineures) { al.push(['p1', tr('a_mineur', RV.mineures)]); }
      if (IV.lu && IV.part != null && IV.part > (IV.cible || 4)) { al.push(['p1', tr('a_invendus', null, { p: nf(IV.part, 1), c: nf(IV.cible || 4) })]); }
      if (RC.lu && RC.ouvertes) { al.push(['', tr('a_reclam', RC.ouvertes)]); }
      if (G && G.faibles) { al.push(['p1', tr('a_avis', G.faibles)]); }
      if (al.length) { h += `<div class="fr-carte fr-surv"><h2>${esc(L.surveiller)}</h2>${al.map(a => `<div class="it"><span class="fr-p ${a[0]}">!</span><div>${esc(a[1])}</div></div>`).join('')}</div>`; }
      // Les tuiles.
      const pctCls = p => p == null ? 'mu' : (p < 70 ? 'ko' : p < 85 ? 'att' : 'ok');
      const plusJours = (d, n) => { const t = new Date(d + 'T12:00:00'); t.setDate(t.getDate() + n); return t.getFullYear() + '-' + String(t.getMonth() + 1).padStart(2, '0') + '-' + String(t.getDate()).padStart(2, '0'); };
      const heat = () => { const parJ = {}; (TA.jours || []).forEach(x => { parJ[x.jour] = x; }); let s = ''; for (let i = 29; i >= 0; i--) { const d = plusJours(D.aujourdhui, -i); const x = parJ[d]; s += '<i class="' + (!x ? 'x' : (x.oblig ? 'm' : (x.f < x.t ? 'p' : ''))) + '" title="' + fD(d) + (x ? ' : ' + x.f + ' / ' + x.t : '') + '"></i>'; }
        return '<div class="fr-heat">' + s + '</div><div class="fr-leg">' + ['#C9E3CD', '#F5D58F', '#E8A0A0', '#EDE7E0'].map((c, i) => '<span><i style="background:' + c + '"></i>' + esc(L.leg[i]) + '</span>').join('') + '</div>'; };
      const niv = i => '<span class="fr-niv ' + esc(i.niveau) + '">' + esc(L.niv[i.niveau] || i.niveau) + '</span>';
      const suite = i => i.suite ? fD(i.suite.jour) + ' : ' + (i.suite.conforme ? '<span class="ok">' + esc(L.suite_ok) + '</span>' : '<span class="ko">' + esc(L.suite_ko) + '</span>') : mu(L.suite_att);
      const stRec = r => r.ouverte ? '<span class="ko">' + esc(L.st_rec.ouverte) + '</span>' : esc(r.statut === 'REJECTED' ? L.st_rec.refusee : r.statut === 'ACCEPTED' ? L.st_rec.acceptee : String(r.statut || '').toLowerCase());
      const o1 = (OB.produits || [])[0], c1 = (OB.campagnes || [])[0];
      const plano = PL.pct != null && !PL.motif; const asso = AS.obligatoires != null && !AS.motif;
      h += `<div class="fr-carte"><h2>${esc(L.chaque)}</h2><div class="fr-mu" style="margin:-4px 0 10px">${esc(L.chaqueDef)}</div><div class="fr-grille">`
        + tuile('taches', L.t_taches, TA.pct != null ? TA.pct + ' %' : '—', TA.pct != null ? TA.rendues + ' / ' + TA.attendues + ' · ' + (TA.joursObligManques ? tr('oblig_manq', TA.joursObligManques) : L.oblig_ok) : (TA.motif || L.nonLu), pctCls(TA.pct),
          (tj.lu ? esc(tr('tj', null, { d: fD(tj.date), x: tj.notees, y: tj.taches })) + (tj.aNoter ? ' · ' + esc(tr('aNoter', tj.aNoter)) : '') : '') + heat(),
          (TA.jours || []).length ? { titre: L.m_taches, html: tab(L.cols_taches, TA.jours.slice().reverse().map(x => [esc(fD(x.jour)), String(x.f), String(x.t), x.oblig ? '<span class="ko">' + esc(L.manquee) + '</span>' : esc(L.tenue)])) } : null)
        + tuile('notes', L.t_notes, RV.lu && RV.moyenne != null ? nf(RV.moyenne, 1) + ' / 5' : '—', RV.lu ? (RV.notees ? tr('notees', RV.notees) + ' · ' + (RV.parNote || []).filter(p => p.n).map(p => p.note + '★ ' + p.n).join(' · ') : L.rienNote) : (RV.motif || L.nonLu),
          RV.lu && RV.moyenne != null ? (RV.moyenne >= 4 ? 'ok' : RV.moyenne >= 3 ? 'att' : 'ko') : 'mu',
          RV.lu && RV.notees ? '<div class="fr-rep">' + (RV.parNote || []).map(p => '<div><span>' + p.note + ' ★ ' + esc(L.notes[p.note] || p.nom) + '</span><i><b style="width:' + Math.round(100 * p.n / RV.notees) + '%"></b></i><span>' + p.n + '</span></div>').join('') + '</div>' : mu(RV.lu ? L.aucuneNote : (RV.motif || L.nonLu)))
        + tuile('infractions', L.t_infr, RV.lu ? String(RV.nc || 0) : '—', RV.lu ? (['mineure', 'majeure', 'critique'].map(k => RV[k + 's'] ? RV[k + 's'] + ' ' + (RV[k + 's'] > 1 ? L.nivs[k] : L.niv[k]) : '').filter(Boolean).join(' · ') || L.aucune) : (RV.motif || L.nonLu),
          RV.lu ? (RV.nc ? (graves ? 'ko' : 'att') : 'ok') : 'mu',
          RV.lu && RV.nc ? '<div class="fr-nc">' + (RV.infractions || []).map(i => '<div>' + niv(i) + '<span>' + esc(fD(i.jour)) + ' · <b>' + esc(i.tache) + '</b>' + (i.note != null ? ' · ' + i.note + ' / 5' : '') + (i.comment ? ' — ' + esc(i.comment) : '') + (i.recidive ? ' · <span class="ko">' + esc(tr('recid', i.recidive)) + '</span>' : '') + '</span></div>').join('') + '</div>' : mu(RV.lu ? L.aucuneInfr : (RV.motif || L.nonLu)),
          RV.lu && RV.nc ? { titre: L.m_infr, html: tab(L.cols_infr, (RV.infractions || []).map(i => [esc(fD(i.jour)), '<b>' + esc(i.tache) + '</b>', i.note != null ? i.note + ' / 5' : '—', niv(i), esc(i.comment || ''), i.recidive ? '<span class="ko">' + esc(tr('recid', i.recidive)) + '</span>' : '—', suite(i)])) } : null)
        + tuile('invendus', L.t_inv, IV.lu && IV.part != null ? nf(IV.part, 1) + ' %' : '—', IV.lu && IV.part != null ? tr('inv_sous', null, { c: nf(IV.cible || 4) }) : (IV.motif || L.nonLu), IV.lu && IV.part != null ? (IV.part > (IV.cible || 4) ? 'ko' : 'ok') : 'mu',
          IV.lu ? esc(tr('inv_det', null, { du: fD(IV.du), au: fD(IV.au), cout: nf(IV.cout, 2) }) + (IV.ca != null ? tr('inv_ca', null, { ca: nf(IV.ca) }) : '') + '.') : mu(IV.motif || L.nonLu))
        + tuile('reclamations', L.t_rec, RC.lu ? String(RC.n) : '—', RC.lu ? ((RC.ouvertes ? tr('rec_ouv', RC.ouvertes) : (RC.n ? L.rec_ok : L.aucune)) + (RC.montant ? ' · ' + nf(RC.montant, 2) + ' €' : '')) : (RC.motif || L.nonLu), RC.lu ? (RC.ouvertes ? 'att' : '') : 'mu',
          RC.lu && RC.n ? '<span class="fr-mu">' + (RC.dernieres || []).map(r => esc(fD(r.le)) + ' ' + esc(r.fournisseur) + ' — ' + esc(r.reference) + (r.qte != null ? ' ' + nf(r.qte) + ' ' + esc(r.unite || '') : '') + (r.motif ? ' · ' + esc(r.motif) : '') + ' · ' + stRec(r)).join('<br>') + '</span>' : mu(RC.lu ? L.aucuneRec : (RC.motif || L.nonLu)),
          RC.lu && RC.n ? { titre: L.m_rec, html: tab(L.cols_rec, (RC.lignes || RC.dernieres || []).map(r => [esc(fD(r.le)), esc(r.fournisseur), '<b>' + esc(r.reference) + '</b>', r.qte != null ? nf(r.qte) + ' ' + esc(r.unite || '') : '—', esc(r.motif || ''), stRec(r), esc(r.reponse || ''), r.montant != null ? nf(r.montant, 2) + ' €' : '—'])) } : null)
        + tuile('objectifs', L.t_obj, o1 ? (o1.pct != null ? o1.pct + ' %' : '—') : (c1 ? nf(c1.reel) : '—'), o1 ? o1.nom + (OB.produits.length > 1 ? ' · + ' + (OB.produits.length - 1) : '') : (c1 ? tr('obj_clients', null, { nom: c1.nom }) : L.obj_aucun),
          o1 && o1.pct != null ? (o1.pct >= 100 ? 'ok' : o1.pct >= 70 ? 'att' : 'ko') : 'mu',
          ((OB.produits || []).length ? OB.produits.map(c => esc(tr('obj_prod', null, { nom: c.nom, pct: c.pct != null ? c.pct + ' %' : '—', v: nf(c.vendu), o: nf(c.objectif), fin: fD(c.fin) }))).join('<br>') : mu(L.obj_aucunProd))
          + ((OB.campagnes || []).length ? '<br>' + OB.campagnes.map(c => esc(tr('obj_camp', null, { nom: c.nom, r: nf(c.reel) })) + (c.clientsPrevus != null ? ' / ' + nf(c.clientsPrevus) : '') + (c.jourCourant ? ' · ' + esc(tr('obj_jour', c.nbJours, { j: c.jourCourant })) : '')).join('<br>') : ''))
        + tuile('remarques', L.t_rem, String(RM.n || 0), RM.n ? RM.parOperateur.map(x => x.employe + ' ' + x.n).join(' · ') : L.aucune, RM.n ? 'att' : 'ok',
          RM.n ? '<span class="fr-mu">' + RM.dernieres.map(r => esc(fD(r.le)) + ' ' + esc(r.employe) + ' — ' + esc(r.produit) + (r.motifLib ? ' · ' + esc(r.motifLib) : '') + (r.texte ? ' : ' + esc(r.texte) : '')).join('<br>') + '</span>' : mu(L.aucuneRem))
        + tuile('google', L.t_google, G && G.note != null ? nf(G.note, 1) : '—', G && G.note != null ? [tr('g_avis', nf(G.avis)), G.faibles ? tr('g_faibles', G.faibles) : L.g_aucunFaible, G.cible ? tr('g_cible', null, { c: nf(G.cible, 1) }) : ''].filter(Boolean).join(' · ') : L.g_sans,
          G && G.note != null ? (G.faibles || (G.cible && G.note < G.cible) ? 'att' : 'ok') : 'mu',
          G && (G.derniers || []).length ? '<span class="fr-mu">' + G.derniers.slice(0, 5).map(a => esc(fD(a.le)) + ' ' + a.note + '/5' + (a.extrait ? ' « ' + esc(a.extrait) + ' »' : '')).join('<br>') + '</span>' : mu(L.g_aucunAvis))
        + tuile('ca', L.t_ca, CA ? nf(CA.ca) + ' €' : '—', CA ? [CA.objectif ? tr('ca_obj', null, { o: nf(CA.objectif) }) : '', CA.pct != null ? (CA.pct >= 0 ? '+ ' : '− ') + Math.abs(CA.pct) + ' %' : ''].filter(Boolean).join(' · ') : L.ca_sans,
          CA && CA.pct != null ? (CA.pct < -10 ? 'ko' : CA.pct < 0 ? 'att' : 'ok') : 'mu', CA ? esc(tr('ca_jours', CA.jours)) : mu(L.ca_sansDet))
        + tuile('comptoir', L.t_comptoir, plano ? PL.pct + ' %' : '—', (plano ? L.plano_tenu : (PL.motif || CF.motif || L.nonLu)) + (asso ? ' · ' + tr('oblig', null, { p: AS.presentes, o: AS.obligatoires }) : ''),
          plano ? ((AS.manquantes || PL.pct < 80) ? 'att' : 'ok') : 'mu',
          (plano ? esc(tr('plano_det', null, { pct: PL.pct, t: PL.tenus, e: PL.emplacements })) : mu(PL.motif || CF.motif || L.nonLu)) + '<br>'
          + (asso ? esc(tr('asso_det', null, { j: AS.jours || 30, p: AS.presentes, o: AS.obligatoires })) + (AS.manquantes ? ' · <span class="ko">' + esc(tr('manquantes', AS.manquantes)) + '</span>' + ((AS.liste || []).length ? ' : ' + AS.liste.slice(0, 6).map(x => esc(x.nom)).join(', ') : '') : ' · ' + esc(L.toutes)) : mu(AS.motif || L.nonLu)))
        + '</div></div>';
    }
    const jour = h; h = '';
    if (D.journal) {
      h += `<div class="fr-carte"><h2>${esc(L.journal)}</h2><div class="fr-mu" style="margin:-4px 0 8px">${esc(L.journalDef)}</div>${D.journal.length ? D.journal.map(e => `<div class="fr-jl"><span class="d">${esc(fD(e.le))}</span><span class="fr-src ${e.volet === 'terrain' ? 't' : ''}">${esc(L.volet[e.volet] || e.volet)}</span><span>${esc(e.texte)}</span></div>`).join('') : `<div class="fr-mu">${esc(L.rienJournal)}</div>`}</div>`;
    }
    h += `<div class="fr-modal" id="frModal" hidden><div class="c"><button class="x" type="button" aria-label="${esc(L.fermer)}">✕</button><h3></h3><div class="b"></div></div></div>`;
    return { jour, fin: h };
  }

  function brancher(racine) {
    const modal = racine.querySelector('#frModal');
    if (!modal) { return; }
    racine.querySelectorAll('[data-modal]').forEach(b => b.addEventListener('click', () => {
      const m = MOD[b.getAttribute('data-modal')]; if (!m) { return; }
      modal.querySelector('h3').textContent = m.titre; modal.querySelector('.b').innerHTML = m.html; modal.hidden = false;
    }));
    modal.addEventListener('click', e => { if (e.target === modal || e.target.closest('.x')) { modal.hidden = true; } });
  }

  window.SuiviJour = { rendre, brancher };
})();
