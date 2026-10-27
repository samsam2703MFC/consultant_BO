/* La tablette en néerlandais (26/10/2026, demande de Sam : « traduis toute la tablette en néerlandais »).
 *
 * Une couche posée sur le rendu, pas un second jeu d'écrans : visites.js écrit ses écrans en français, puis,
 * quand la langue de la tablette est le néerlandais, `VI_NL.traduire(racine)` relit les textes affichés et
 * les attributs lus par l'utilisateur (placeholder, title, aria-label) et les remplace par leur traduction.
 * La logique, les données envoyées et les réponses ne changent pas ; ce qui vient du franchisé ou du
 * consultant (remarques, constats, noms) n'est jamais une clé du dictionnaire et reste tel quel.
 *
 * Comment un texte se traduit, dans cet ordre :
 *   1. tel quel dans NL ;
 *   2. sans ses ornements de bord (flèches, coches, émojis, ponctuation finale) ;
 *   3. majuscule initiale échangée (« en cours » trouve « En cours ») ;
 *   4. ses nombres remplacés par # et ses dates par § (« 3 actions ouvertes » → « # actions ouvertes ») :
 *      la traduction reçoit les mêmes nombres, et les dates traduites, dans l'ordre ;
 *   5. coupé à ses séparateurs « · », « — », « – », « : » et chaque morceau traduit à son tour.
 * Ce qui ne se traduit pas reste en français ; `VI_NL.manquants` les garde quand VI_NL.debug est vrai
 * (le parcours de contrôle s'en sert pour dire ce qu'il reste à traduire).
 *
 * Les mots du métier gardent les termes du réseau en Flandre : « winkel » (boutique), « afwijking » (écart),
 * « actiepunt » (point à travailler), « bezoek » (visite), « franchisenemer » (franchisé).
 */
(function () {
  'use strict';

  const JOURS = { dim: 'zo', lun: 'ma', mar: 'di', mer: 'wo', jeu: 'do', ven: 'vr', sam: 'za',
    dimanche: 'zondag', lundi: 'maandag', mardi: 'dinsdag', mercredi: 'woensdag', jeudi: 'donderdag', vendredi: 'vrijdag', samedi: 'zaterdag' };
  const MOIS = { 'janv.': 'jan.', 'févr.': 'feb.', mars: 'maart', 'avr.': 'apr.', mai: 'mei', juin: 'juni', 'juil.': 'jul.', 'août': 'aug.', 'sept.': 'sep.', 'oct.': 'okt.', 'nov.': 'nov.', 'déc.': 'dec.',
    janvier: 'januari', 'février': 'februari', avril: 'april', juillet: 'juli', septembre: 'september', octobre: 'oktober', novembre: 'november', 'décembre': 'december',
    janv: 'jan', 'févr': 'feb', avr: 'apr', juil: 'jul', sept: 'sep', oct: 'okt', nov: 'nov', 'déc': 'dec' };
  const RX_MOIS = 'janv\\.?|févr\\.?|mars|avr\\.?|mai|juin|juil\\.?|août|sept\\.?|oct\\.?|nov\\.?|déc\\.?|janvier|février|avril|juillet|septembre|octobre|novembre|décembre';
  // « lun. 26 oct. », « 26 oct. 2026 », « 1er mars », « octobre 2026 ».
  const RX_DATE = new RegExp('(?:\\b(dimanche|lundi|mardi|mercredi|jeudi|vendredi|samedi|dim|lun|mar|mer|jeu|ven|sam)\\.? )?\\b(\\d{1,2})(?:er)? (' + RX_MOIS + ')(?= |$|[,;)·—])(?: (\\d{4}))?|\\b(' + RX_MOIS + ') (\\d{4})\\b', 'g');
  // Les nombres : « 12 345 », « 3,2 », « 9:00 » (deux nombres), « 12/10 » (deux nombres).
  const RX_NB = /\d+(?:[   ]\d{3}(?!\d))*(?:[.,]\d+)?/g;
  // Les ornements de bord : flèches, coches, puces, émojis, ponctuation finale.
  const RX_BORD = /^([\s‹›«»→←↑↓↻▶◀▸▾▴✓✔✕✗×•·+\-–—:;,.!?…()[\]"“”'’\u{1F000}-\u{1FFFF}\u{2600}-\u{27BF}\u{2B00}-\u{2BFF}️‍]*)([\s\S]*?)([\s‹›«»→←↑↓↻▶◀▸▾▴✓✔✕✗×•·+\-–—:;,.!?…()[\]"“”'’\u{1F000}-\u{1FFFF}\u{2600}-\u{27BF}\u{2B00}-\u{2BFF}️‍]*)$/u;
  const RX_LETTRE = /[A-Za-zÀ-ÿ]/;
  const PREFIXES = [['Franchisé ', 'Franchisenemer '], ['Contrôle · ', 'Controle · '], ['Visite — ', 'Bezoek — '], ['Review — ', 'Review — '], ['Historique — ', 'Geschiedenis — '], ['MSP et équipe — ', 'MSP en team — ']];
  const SEPARATEURS = [' · ', ' — ', ' – ', ' : ', ' | ', ' / ', ', ', ' ('];
  // Un émoji au milieu d'un texte (« météo du franchisé 🌤️ Éclaircies ») sépare deux morceaux.
  const RX_EMOJI = /(\s*[\u{1F000}-\u{1FFFF}\u{2600}-\u{27BF}]\uFE0F?\s*)/u;

  const dateNl = (m, j, d, mo, an, mo2, an2) => {
    if (mo2) { return (MOIS[mo2] || mo2) + ' ' + an2; }
    return (j ? (JOURS[j] || j) + ' ' : '') + d + ' ' + (MOIS[mo] || mo) + (an ? ' ' + an : '');
  };

  /* Le dictionnaire : le français tel qu'il s'affiche → le néerlandais. # : un nombre, § : une date. */
  const NL = {
    // Le cadre de la tablette : en-tête, barre du bas, navigation, état du réseau.
    'Contrôles franchisés': 'Franchisecontroles', 'Contrôles': 'Controles', 'Contrôle': 'Controle', 'Réglages': 'Instellingen',
    'en ligne': 'online', 'Hors ligne': 'Offline', 'hors ligne': 'offline', '# à envoyer': '# te versturen', 'Synchroniser': 'Synchroniseren',
    '# écriture(s) en attente': '# wijziging(en) in de wachtrij', 'dernière sync #-#-# #:#': 'laatste sync #-#-# #:#', '# en attente d’envoi': '# wachten op verzending',
    'Extérieur': 'Buitenkant', 'Intérieur': 'Binnenkant', 'Suivi de projet': 'Projectopvolging', 'Analyse': 'Analyse', 'Développement': 'Ontwikkeling', 'Météo et fin': 'Weerbericht en afsluiting',
    'étape # sur #': 'stap # van #', '# / # étapes': '# / # stappen', '# étapes': '# stappen', 'checklist': 'checklist', 'Checklist': 'Checklist',
    '# / # vus': '# / # bekeken', '# vus': '# bekeken', '# écarts': '# afwijkingen', '# écart': '# afwijking', '# à voir': '# te bekijken',
    'Suivant': 'Volgende', 'Début': 'Begin', 'Reste': 'Nog te doen', 'Fin': 'Einde',
    'Avant de continuer': 'Voor je verdergaat', 'cette étape n’est pas remplie': 'deze stap is niet ingevuld', 'Étape remplie : la suite.': 'Stap ingevuld: verder.',
    'Visite pas encore démarrée': 'Bezoek nog niet gestart', 'prévue § à #:#': 'gepland op § om #:#', 'ouverte depuis le cockpit ou son QR code': 'geopend vanuit de cockpit of via de QR-code',
    'Démarrer la visite': 'Bezoek starten', 'commencé à #:#': 'gestart om #:#',
    // L'accueil : les boutiques et l'agenda.
    'Boutiques': 'Winkels', 'Agenda': 'Agenda', 'Les boutiques': 'De winkels', 'Agenda des contrôles': 'Controle-agenda', '# boutiques': '# winkels', '# boutique': '# winkel',
    '# en cours': '# bezig', '# cette semaine': '# deze week', 'tous les consultants': 'alle consultants', 'Aucune boutique à contrôler': 'Geen winkel te controleren',
    'à fixer': 'te kiezen', 'Contrôle en cours': 'Controle bezig', 'Aujourd’hui': 'Vandaag', 'aujourd’hui': 'vandaag', 'Pas de contrôle planifié': 'Geen controle gepland',
    'commencé le §': 'gestart op §', 'commencé le § à #:#': 'gestart op § om #:#', 'confirmé': 'bevestigd', 'planifié': 'gepland', 'à planifier': 'in te plannen', 'À planifier': 'In te plannen',
    'dernier contrôle §': 'laatste controle §', '# action ouverte': '# open actie', '# actions ouvertes': '# open acties', 'Google #': 'Google #', 'météo': 'weerbericht',
    'Reprendre le contrôle': 'Controle hervatten', 'Fiche': 'Fiche', 'Démarrer le contrôle': 'Controle starten', 'Planifier': 'Plannen', 'Démarrer maintenant': 'Nu starten',
    'Sans contrôle planifié': 'Zonder geplande controle', 'jamais visitée': 'nooit bezocht', 'en cours': 'bezig', 'fait': 'gedaan',
    'lun': 'ma', 'mar': 'di', 'mer': 'wo', 'jeu': 'do', 'ven': 'vr', 'sam': 'za', 'dim': 'zo',
    'lun #': 'ma #', 'mar #': 'di #', 'mer #': 'wo #', 'jeu #': 'do #', 'ven #': 'vr #', 'sam #': 'za #', 'dim #': 'zo #',
    'janv.': 'jan.', 'févr.': 'feb.', 'mars': 'maart', 'avr.': 'apr.', 'mai': 'mei', 'juin': 'juni', 'juil.': 'jul.', 'août': 'aug.', 'sept.': 'sep.', 'oct.': 'okt.', 'nov.': 'nov.', 'déc.': 'dec.',
    'janv': 'jan', 'févr': 'feb', 'avr': 'apr', 'juil': 'jul', 'sept': 'sep', 'oct': 'okt', 'nov': 'nov', 'déc': 'dec',
    'janvier': 'januari', 'février': 'februari', 'avril': 'april', 'juillet': 'juli', 'septembre': 'september', 'octobre': 'oktober', 'novembre': 'november', 'décembre': 'december',
    'Janvier #': 'Januari #', 'Février #': 'Februari #', 'Mars #': 'Maart #', 'Avril #': 'April #', 'Mai #': 'Mei #', 'Juin #': 'Juni #', 'Juillet #': 'Juli #', 'Août #': 'Augustus #',
    'Septembre #': 'September #', 'Octobre #': 'Oktober #', 'Novembre #': 'November #', 'Décembre #': 'December #',
    'Brabant wallon': 'Waals-Brabant', 'Hainaut': 'Henegouwen', 'Namur': 'Namen', 'Liège': 'Luik', 'Bruxelles': 'Brussel', 'Luxembourg': 'Luxemburg',
    'Franchisé': 'Franchisenemer', 'Franchisé #': 'Franchisenemer #',
    // Les visites : types, statuts, motifs.
    'Visite': 'Bezoek', 'Régulière': 'Regulier', 'régulière': 'regulier', 'Hygiène et qualité': 'Hygiëne en kwaliteit', 'Bilan trimestriel': 'Kwartaalbalans', 'Ouverture / lancement': 'Opening / lancering',
    'Ouverture': 'Opening', 'lancement': 'lancering', 'Revisite': 'Herbezoek', 'revisite': 'herbezoek', 'ASAP': 'ASAP', 'due': 'vervallen', 'Hygiène': 'Hygiëne',
    'planifiée': 'gepland', 'confirmée': 'bevestigd', 'terminée': 'afgerond', 'annulée': 'geannuleerd', 'Visite non planifiée': 'Niet-gepland bezoek', 'Type de visite': 'Soort bezoek',
    'Planifier une visite': 'Een bezoek plannen', 'Reprogrammer': 'Herplannen', 'Confirmer': 'Bevestigen', 'Annuler cette visite ?': 'Dit bezoek annuleren?',
    'Visite confirmée.': 'Bezoek bevestigd.', 'Visite ouverte.': 'Bezoek geopend.', 'Visite reprogrammée.': 'Bezoek herpland.', 'Visite planifiée §': 'Bezoek gepland §', 'Visite planifiée § #:#': 'Bezoek gepland § #:#',
    'Visite introuvable': 'Bezoek niet gevonden', 'visite annoncée': 'aangekondigd bezoek', 'visite du §': 'bezoek van §', 'Visite — #': 'Bezoek — #',
    'Date': 'Datum', 'Heure': 'Uur', 'Durée (min)': 'Duur (min)', 'Durée (min': 'Duur (min', 'Motif': 'Reden', 'Consultant': 'Consultant', 'Libre': 'Vrij',
    'Ajouter à Google Agenda': 'Toevoegen aan Google Agenda', 'Y aller': 'Ga erheen', 'GPS': 'GPS', 'Ouvrir la fiche': 'Fiche openen',
    '# min': '# min', '# h': '# u', '# j': '# d', '# jours': '# dagen', '# mois': '# maanden', '# sem': '# wk',
    'Avant la visite': 'Vóór het bezoek', 'Pendant la visite': 'Tijdens het bezoek', 'Après la visite': 'Na het bezoek',
    'Tâches à faire': 'Te doen', '# tâches à faire': '# taken te doen', 'checklist # points': 'checklist # punten', 'Aucune tâche dans ce filtre': 'Geen taak in deze filter',
    'Ils ouvrent la checklist': 'Zij openen de checklist', 'La checklist, les photos, la review avec le franchisé': 'De checklist, de foto’s, de review met de franchisenemer',
    'Le bilan avec le franchisé, les objectifs du trimestre suivant': 'De balans met de franchisenemer, de doelstellingen voor het volgende kwartaal',
    'La fin du trimestre': 'Het einde van het kwartaal', 'Récurrence': 'Klantentrouw',
    // Les tâches du consultant tirées des types de visite (les modèles livrés).
    'Relire la dernière review et les plans d’action ouverts': 'De laatste review en de openstaande actieplannen herlezen',
    'Préparer les chiffres': 'De cijfers voorbereiden', 'CA, food cost, invendus du mois': 'omzet, food cost, onverkochte producten van de maand',
    'Calculer le scoring du trimestre et préparer le rapport A#': 'De scoring van het kwartaal berekenen en het A#-verslag voorbereiden',
    'Relire le P&L des trois mois et le plan de développement': 'De P&L van de drie maanden en het ontwikkelingsplan herlezen',
    'Relire les points hygiène des dernières visites et les remarques du client mystère': 'De hygiënepunten van de laatste bezoeken en de opmerkingen van de mystery shopper herlezen',
    'Revérifier les corrections P# sur photo': 'De P#-correcties op foto opnieuw nakijken',
    'Review hygiène et plan d’action (P# si température ou propreté)': 'Hygiënereview en actieplan (P# bij temperatuur of netheid)',
    'Review hygiène et plan d’action (P# si température ou propreté': 'Hygiënereview en actieplan (P# bij temperatuur of netheid',
    'Poser les objectifs dans le cockpit (budget, objectifs produits)': 'De doelstellingen in de cockpit vastleggen (budget, productdoelstellingen)',
    'Poser les objectifs dans le cockpit (budget, objectifs produits': 'De doelstellingen in de cockpit vastleggen (budget, productdoelstellingen',
    'Noter la visite dans le panel (note de type VISIT)': 'Het bezoek in het panel noteren (notitie van het type VISIT)', 'Noter la visite dans le panel (note de type VISIT': 'Het bezoek in het panel noteren (notitie van het type VISIT',
    'Lire les tâches sautées de la semaine dans le panel': 'De overgeslagen taken van de week in het panel lezen', 'Faire compléter leur fiche au panel': 'Hun fiche in het panel laten aanvullen',
    'Relever l’effectif': 'Het personeelsbestand noteren', 'Parler du recrutement': 'Over de aanwerving praten',
    // Les étapes du contrôle.
    'Photos': 'Foto’s', 'Photo': 'Foto', 'L’extérieur en photo': 'De buitenkant in beeld', 'L’intérieur en photo': 'De binnenkant in beeld', 'La photo du jour': 'De foto van de dag',
    'Les photos du jour': 'De foto’s van de dag', 'Photos du jour': 'Foto’s van de dag', 'Pas encore de photo du jour': 'Nog geen foto van de dag', 'Façade': 'Gevel', 'Arrière': 'Achterkant',
    '# photos à prendre': '# foto’s te nemen', '# photo à prendre': '# foto te nemen', 'la photo à prendre': 'de foto te nemen', 'à prendre': 'te nemen', 'Photo du § #:#': 'Foto van § #:#',
    'Horodatée, position si consentie, EXIF retiré à la réduction': 'Met tijdstempel, locatie indien toegestaan, EXIF verwijderd bij het verkleinen', 'première photo': 'eerste foto',
    'Photo prise': 'Foto genomen', 'envoyée au retour du réseau': 'verstuurd zodra er weer netwerk is', 'Photo illisible.': 'Foto onleesbaar.', 'Réduction de la photo…': 'Foto verkleinen…',
    'Photo envoyée : en attente de validation.': 'Foto verstuurd: wacht op validatie.', 'lecture de la photo': 'foto wordt gelezen', 'aucune photo rendue pour l’instant': 'nog geen foto ingediend',
    'Contrôles du jour': 'Controles van de dag', 'Les contrôles en photo du jour': 'De fotocontroles van de dag', '# photo': '# foto', '# photos': '# foto’s', '# photo / #': '# foto / #', '# photos / #': '# foto’s / #',
    '# à noter': '# te beoordelen', 'à noter': 'te beoordelen', '# contrôle à noter': '# controle te beoordelen', '# contrôles à noter': '# controles te beoordelen', 'indisponible': 'niet beschikbaar', 'lecture…': 'laden…', 'lecture': 'laden',
    'Contrôle qualité': 'Kwaliteitscontrole', 'contrôle qualité des tâches': 'kwaliteitscontrole van de taken', 'pas encore re-notée': 'nog niet opnieuw beoordeeld', 're-notée #/# le §': 'opnieuw beoordeeld #/# op §',
    'le § #:#': 'op § #:#', 'Note #/# enregistrée.': 'Score #/# opgeslagen.', 'Les tâches notées sous #': 'De taken met een score onder #', 'Touchez un mois pour voir ses tâches sous #': 'Tik op een maand om de taken onder # te zien',
    'Lecture des tâches des derniers mois': 'Taken van de laatste maanden laden', 'Les tâches des derniers mois': 'De taken van de laatste maanden', 'Les derniers mois': 'De laatste maanden',
    'tâches faites sur demandées': 'uitgevoerde taken op gevraagde', 'Faites': 'Gedaan', 'Faites / demandées': 'Gedaan / gevraagd', 'demandées': 'gevraagd', 'Notées': 'Beoordeeld', 'Sous #': 'Onder #',
    'Corrections à valider': 'Correcties te valideren', 'Tâches et contrôles photo': 'Taken en fotocontroles', 'Tâches': 'Taken', 'tâches': 'taken',
    'tâches : relevé quotidien du panel (journées révolues, le mois en cours jusqu’à hier)': 'taken: dagelijks overzicht van het panel (afgelopen dagen, de lopende maand tot gisteren)',
    'relevé quotidien du panel (journées révolues, le mois en cours jusqu’à hier': 'dagelijks overzicht van het panel (afgelopen dagen, de lopende maand tot gisteren',
    'relevé quotidien du panel (journées révolues, le mois en cours jusqu’à hier)': 'dagelijks overzicht van het panel (afgelopen dagen, de lopende maand tot gisteren)',
    'notes': 'scores', 'Priorités du jour': 'Prioriteiten van de dag',
    'Dossier': 'Dossier', 'Dossier du bureau': 'Dossier van het kantoor', '# point demandé': '# gevraagd punt', '# points demandés': '# gevraagde punten', 'lu': 'gelezen', 'à lire': 'te lezen', 'non lue': 'niet gelezen',
    'Points demandés par le bureau': 'Punten gevraagd door het kantoor', 'Préparé le § #:#': 'Voorbereid op § #:#', 'Bureau le §': 'Kantoor op §', 'Consultant le §': 'Consultant op §',
    'Points décidés': 'Afgesproken punten', 'Les points décidés aux visites précédentes': 'De punten afgesproken bij de vorige bezoeken',
    'Ce qui avait été décidé et envoyé au franchisé, le plus en retard d’abord. Sur place': 'Wat afgesproken en naar de franchisenemer gestuurd was, de grootste achterstand eerst. Ter plaatse',
    'photographiez la correction, confirmez ce qui est fait, escaladez ce qui bloque': 'fotografeer de correctie, bevestig wat gedaan is, escaleer wat vastzit',
    '# ouverts': '# open', '# en retard': '# te laat', 'aucun point ouvert': 'geen open punt', 'Points ouverts': 'Open punten',
    'Confirmé sur place': 'Ter plaatse bevestigd', 'fermer': 'afsluiten', 'Escalader': 'Escaleren', 'Corrigé devant moi': 'Voor mijn ogen gecorrigeerd', 'pas de photo': 'geen foto',
    'ouvert le §': 'geopend op §', 'délai dépassé de # j': 'termijn # d overschreden', 'délai §': 'termijn §', 'retard # j': '# d te laat', 'à reprendre': 'te herdoen', 'À reprendre': 'Te herdoen',
    'Équipe': 'Team', 'Admin': 'Admin', 'Ouvert': 'Open', 'En attente de validation': 'Wacht op validatie', 'Validé': 'Gevalideerd', 'Fermé': 'Afgesloten', 'Escaladé': 'Geëscaleerd',
    'Proposé · rapport au bureau': 'Voorgesteld · verslag naar het kantoor', 'Proposé': 'Voorgesteld', 'rapport au bureau': 'verslag naar het kantoor', 'Interne': 'Intern', 'interne': 'intern',
    'Correction acceptée.': 'Correctie aanvaard.', 'Renvoyé au franchisé.': 'Teruggestuurd naar de franchisenemer.', 'Fermé.': 'Afgesloten.', 'Escaladé à Sam.': 'Geëscaleerd naar Sam.',
    'Rouvert.': 'Heropend.', 'En attente.': 'In afwachting.', 'Enregistré.': 'Opgeslagen.', 'Rien de rempli.': 'Niets ingevuld.',
    'Checklist du consultant': 'Checklist van de consultant', 'la checklist du consultant': 'de checklist van de consultant', 'La checklist du consultant se lit dans le panel': 'De checklist van de consultant staat in het panel',
    'compte consultant du panel non configuré': 'consultantaccount van het panel niet ingesteld', '# tâche(s) de la checklist enregistrée(s).': '# taak/taken van de checklist opgeslagen.',
    // La checklist.
    'Les points que le bureau a ajoutés en préparant la visite': 'De punten die het kantoor heeft toegevoegd bij de voorbereiding van het bezoek', 'bureau': 'kantoor',
    '# point à remplir': '# punt in te vullen', '# points à remplir': '# punten in te vullen', 'OK': 'OK', 'Écart': 'Afwijking', 'N/A': 'N.v.t.', 'OK / Écart / N/A': 'OK / Afwijking / N.v.t.',
    'Remarque…': 'Opmerking…', 'Remarque': 'Opmerking', 'Cause': 'Oorzaak', 'choisir': 'kiezen', 'effacer': 'wissen', 'retirer': 'verwijderen', 'Autre': 'Andere',
    'Rupture': 'Tekort', 'Surstock': 'Overstock', 'Production mal synchronisée': 'Productie slecht afgestemd', 'Produits déplacés': 'Producten verplaatst', 'Prix mal positionnés': 'Prijzen slecht geplaatst',
    'Emplacements vides': 'Lege plaatsen', 'ERP absent': 'ERP afwezig', 'Promo en cours': 'Lopende promo', 'Produits obligatoires': 'Verplichte producten', 'Assortiment obligatoire': 'Verplicht assortiment',
    'aucune référence n’est déclarée obligatoire pour le réseau': 'geen enkele referentie is verplicht voor het netwerk', '# obligatoires absentes du comptoir': '# verplichte producten ontbreken in de toonbank',
    'Le comptoir d’après le cockpit': 'De toonbank volgens de cockpit', 'aucun produit n’est encore posé sur le comptoir standard': 'er staat nog geen product op de standaardtoonbank',
    'planogramme tenu à # %': 'planogram gerespecteerd voor # %', 'comptoir dessiné dans le cockpit. Assortiment': 'toonbank getekend in de cockpit. Assortiment',
    'références obligatoires × lignes de ticket du magasin, §': 'verplichte referenties × ticketlijnen van de winkel, §', 'Planogramme': 'Planogram', 'Assortiment': 'Assortiment',
    'API': 'API', 'API + local': 'API + lokaal', 'Local': 'Lokaal', 'local': 'lokaal', 'Module': 'Module', 'Points': 'Punten', 'Total': 'Totaal',
    '# non conforme': '# niet conform', '# non conformes': '# niet conform', 'non conforme': 'niet conform', 'Écarts relevés': 'Vastgestelde afwijkingen',
    // KPI et santé du magasin, le CA face au budget, le mini P&L.
    'KPI': 'KPI', 'KPI et santé du magasin': 'KPI’s en gezondheid van de winkel', 'Santé du magasin': 'Gezondheid van de winkel', 'Analyse · KPI': 'Analyse · KPI',
    'CA': 'Omzet', 'CA semaine': 'Omzet week', 'CA par ETP': 'Omzet per VTE', 'CA / ETP': 'Omzet / VTE', 'ETP': 'VTE', 'par ETP': 'per VTE', '# ETP planifiés': '# VTE gepland',
    'Clients': 'Klanten', '# clients': '# klanten', '# avis': '# reviews', 'Actions ouvertes': 'Open acties', 'aucun P0': 'geen P0', '# P#': '# P#', 'Effectif': 'Personeelsbestand',
    '# départ(s)': '# vertrek(ken)', 'relevé §': 'genoteerd §', 'à relever': 'te noteren', 'pas d’objectif': 'geen doelstelling', 'objectif': 'doelstelling', 'ERP absent': 'ERP afwezig',
    'Google': 'Google', 'MSP': 'MSP', 'note Google': 'Google-score', 'réseau': 'netwerk', 'Réseau': 'Netwerk', 'réseau #': 'netwerk #', 'réseau # %': 'netwerk # %', 'seuil': 'drempel', 'seuil #': 'drempel #', 'seuil # %': 'drempel # %',
    'réseau # ★': 'netwerk # ★', 'Réseau # €': 'Netwerk # €', '#e du réseau': '#e van het netwerk', '#e sur #': '#e van #', '#er sur #': '#e van #', '#e sur # magasins': '#e van # winkels', '#er sur # magasins': '#e van # winkels',
    'Lecture du CA par ETP et des ventes par catégorie au panel…': 'Omzet per VTE en verkoop per categorie worden uit het panel gelezen…',
    'Le CA par ETP et les ventes par catégorie n’ont pas pu être lus.': 'De omzet per VTE en de verkoop per categorie konden niet gelezen worden.', 'Relire': 'Opnieuw lezen', 'Recharger': 'Herladen',
    'L’année face à l’an passé': 'Het jaar t.o.v. vorig jaar', 'Structure des ventes face au réseau': 'Verkoopstructuur t.o.v. het netwerk',
    'Structure du CA : comptoir, B#B, click & collect, livraison bureau': 'Omzetstructuur: toonbank, B#B, click & collect, kantoorlevering', 'Structure du CA': 'Omzetstructuur',
    'comptoir, B#B, click & collect, livraison bureau': 'toonbank, B#B, click & collect, kantoorlevering', 'Lu le § à #:#': 'Gelezen op § om #:#', 'relu dans quelques minutes': 'opnieuw gelezen binnen enkele minuten', 'incomplet': 'onvolledig',
    'Le CA face au budget': 'De omzet t.o.v. het budget', 'calculé à #:#': 'berekend om #:#', 'Lecture du CA et du budget de la semaine, du mois, du trimestre et de l’année…': 'Omzet en budget van de week, de maand, het kwartaal en het jaar worden gelezen…',
    'Le CA face au budget n’a pas pu être lu.': 'De omzet t.o.v. het budget kon niet gelezen worden.', 'Jour': 'Dag', 'Semaine': 'Week', 'Mois': 'Maand', 'Trimestre': 'Kwartaal', 'Année': 'Jaar',
    'semaine': 'week', 'mois': 'maand', 'trimestre': 'kwartaal', 'année': 'jaar', 'jour': 'dag', 'Le mois': 'De maand', 'Le mois prochain': 'Volgende maand', 'le mois prochain': 'volgende maand',
    'budget validé': 'gevalideerd budget', 'CA théorique de l’étude': 'theoretische omzet uit de studie', 'budget validé et CA théorique': 'gevalideerd budget en theoretische omzet', 'Budget': 'Budget', 'budget': 'budget',
    'arrêté au §': 'afgesloten op §', 'arrêté au §, jour clos': 'afgesloten op §, dag afgesloten', 'Arrêté au §': 'Afgesloten op §', 'Pas d’écart à montrer': 'Geen verschil te tonen', 'pas de budget': 'geen budget',
    'Réalisé': 'Gerealiseerd', 'Réalisé #': 'Gerealiseerd #', 'Attendu à date': 'Verwacht tot nu', 'il manque # €': 'er ontbreekt # €', 'd’avance de # €': '# € voorsprong', '# % de l’objectif': '# % van de doelstelling',
    '┃ l’attendu à date': '┃ het verwachte tot nu', 'objectif # €': 'doelstelling # €', 'réalisé # € sur un objectif de # €': 'gerealiseerd # € op een doelstelling van # €', 'le réseau −# %': 'het netwerk −# %', 'le réseau +# %': 'het netwerk +# %',
    '# mois sans budget ou sans vente hors comparaison': '# maand(en) zonder budget of verkoop buiten vergelijking',
    'CA à plus de # % sous le budget': 'Omzet meer dan # % onder het budget', 'le pourquoi et les trois pistes sont notés': 'het waarom en de drie pistes zijn genoteerd',
    'le pourquoi et les trois pistes du franchisé se notent ensemble au développement': 'het waarom en de drie pistes van de franchisenemer worden samen genoteerd bij de ontwikkeling',
    'le pourquoi, les trois pistes du franchisé et l’horizon restent à noter au développement': 'het waarom, de drie pistes van de franchisenemer en de horizon moeten nog genoteerd worden bij de ontwikkeling',
    'le détail est à l’analyse, étape KPI': 'het detail staat bij de analyse, stap KPI',
    'Réalisé : CA TTC encaissé jusqu’au #/#/# inclus (la veille)': 'Gerealiseerd: omzet incl. btw ontvangen tot en met #/#/# (de dag ervoor)', 'CA TTC encaissé jusqu’au #/#/# inclus (la veille': 'omzet incl. btw ontvangen tot en met #/#/# (de dag ervoor',
    'CA TTC encaissé jusqu’au #/#/# inclus (la veille)': 'omzet incl. btw ontvangen tot en met #/#/# (de dag ervoor)',
    'semaine et mois tels que Résultat, mois clos au relevé des ventes du panel': 'week en maand zoals in Resultaat, afgesloten maanden volgens het verkoopoverzicht van het panel',
    'le budget validé du mois, à défaut le CA théorique de l’étude ; attendu = la part du budget des jours écoulés (pondération réseau des jours d’ouverture), les mois clos en entier': 'het gevalideerde budget van de maand, anders de theoretische omzet uit de studie; verwacht = het deel van het budget voor de verstreken dagen (netwerkweging van de openingsdagen), afgesloten maanden volledig',
    'Seuil': 'Drempel', '# % sous l’attendu (réglage caOrange des visites)': '# % onder het verwachte (instelling caOrange van de bezoeken)', '# % sous l’attendu (réglage caOrange des visites': '# % onder het verwachte (instelling caOrange van de bezoeken',
    'Mini P&L': 'Mini-P&L', 'Mini P&L indisponible sur cette étendue.': 'Mini-P&L niet beschikbaar voor deze periode.', 'du § au §': 'van § tot §', 'Du § au §': 'Van § tot §', '€': '€', '% du CA': '% van de omzet', 'Coût matière': 'Grondstofkost',
    'Marge brute': 'Brutomarge', 'Invendus': 'Onverkocht', 'Personnel': 'Personeel', 'Frais généraux': 'Algemene kosten', 'Résultat net': 'Nettoresultaat',
    'Tel que Résultat le calcule': 'Zoals Resultaat het berekent', 'net = marge brute − invendus − personnel − frais généraux': 'netto = brutomarge − onverkocht − personeel − algemene kosten',
    'Mois clos au P&L mensuel du panel (sans les invendus, qu’il ne porte pas), le mois en cours à Résultat': 'Afgesloten maanden volgens de maandelijkse P&L van het panel (zonder onverkochte producten, die het niet bevat), de lopende maand volgens Resultaat',
    'à date, aujourd’hui compris (le budget s’arrête à la veille)': 'tot nu, vandaag inbegrepen (het budget stopt de dag ervoor)', 'à date, aujourd’hui compris (le budget s’arrête à la veille': 'tot nu, vandaag inbegrepen (het budget stopt de dag ervoor',
    'Pastille': 'Bolletje', 'seuils de la Table KPI': 'drempels van de KPI-tabel', 'entre alerte et critique': 'tussen alarm en kritiek', 'sous l’alerte': 'onder het alarm', 'au-delà': 'daarboven', 'sous le plafond': 'onder het plafond',
    '# % au plus': 'maximaal # %', '┃ le plafond de # % du CA': '┃ het plafond van # % van de omzet', 'au-dessus du N-#': 'boven N-#', 'en dessous': 'eronder', 'Moyenne': 'Gemiddelde',
    'CA : relevé des ventes du panel (mois clos)': 'Omzet: verkoopoverzicht van het panel (afgesloten maanden)', 'relevé des ventes du panel (mois clos': 'verkoopoverzicht van het panel (afgesloten maanden',
    'relevé des ventes du panel (mois clos)': 'verkoopoverzicht van het panel (afgesloten maanden)', 'ETP : heures du planning ÷ # h': 'VTE: uren in de planning ÷ # u', 'heures du planning ÷ # h': 'uren in de planning ÷ # u',
    '# derniers mois': '# laatste maanden', 'mois clos': 'afgesloten maand', 'Meilleur mois': 'Beste maand', '# — le mois à battre': '# — de te verslaan maand', 'le mois à battre': 'de te verslaan maand',
    '#, mois par mois': '#, maand per maand', 'clients, face à #': 'klanten, t.o.v. #', 'même mois en # (pointillé, mois entier)': 'zelfde maand in # (stippellijn, volledige maand)', 'même mois en # (pointillé, mois entier': 'zelfde maand in # (stippellijn, volledige maand',
    'N-# dès §': 'N-# vanaf §', 'pas de N-#': 'geen N-#', 'clients du mois (le mois en cours prolongé au rythme de ses jours)': 'klanten van de maand (de lopende maand doorgetrokken aan het ritme van haar dagen)',
    'clients du mois (le mois en cours prolongé au rythme de ses jours': 'klanten van de maand (de lopende maand doorgetrokken aan het ritme van haar dagen', 'oct en cours': 'okt bezig', '# en cours · # à valider': '# bezig · # te valideren',
    '# contre #': '# tegen #', 'janv. → oct': 'jan. → okt', '# vs #': '# vs #', '# % vs # aux mêmes jours': '# % vs # op dezelfde dagen', '# aux mêmes jours': '# op dezelfde dagen', '# jours servis': '# dagen bediend',
    'en # j': 'in # d', '# k€': '# k€',
    // La structure des ventes, les canaux.
    'Part de chaque catégorie dans le CA': 'Aandeel van elke categorie in de omzet', 'le magasin (barre pleine) face au reste du réseau, # magasins (barre grise)': 'de winkel (volle balk) t.o.v. de rest van het netwerk, # winkels (grijze balk)',
    'le magasin (barre pleine) face au reste du réseau, # magasins (barre grise': 'de winkel (volle balk) t.o.v. de rest van het netwerk, # winkels (grijze balk', 'CA du magasin # €, du reste # €': 'omzet van de winkel # €, van de rest # €',
    'Le magasin': 'De winkel', 'Le reste du réseau': 'De rest van het netwerk', '# magasins': '# winkels', 'Les # catégories': 'De # categorieën', 'Écart': 'Afwijking', 'Part': 'Aandeel',
    'Boulangerie': 'Bakkerij', 'Viennoiserie': 'Viennoiserie', 'Pâtisserie': 'Patisserie', 'Traiteur': 'Traiteur', 'Biscuiterie': 'Koekjes', 'Boissons': 'Dranken', 'Tartes': 'Taarten', 'Quiches': 'Quiches', 'Croissants': 'Croissants',
    'Comptoir': 'Toonbank', 'B#B': 'B#B', 'Click & collect': 'Click & collect', 'Livraison bureau': 'Kantoorlevering', 'clients pro': 'professionele klanten', 'Canal': 'Kanaal', 'Part B#B': 'Aandeel B#B',
    'Comptoir # %': 'Toonbank # %', 'B#B · clients pro # %': 'B#B · professionele klanten # %', 'Click & collect # %': 'Click & collect # %', 'Livraison bureau # %': 'Kantoorlevering # %',
    'caisse': 'kassa', 'relevé des tickets du panel par jour (B#B = clients pro à TVA valide)': 'overzicht van de tickets van het panel per dag (B#B = professionele klanten met geldig btw-nummer)',
    'relevé des tickets du panel par jour (B#B = clients pro à TVA valide': 'overzicht van de tickets van het panel per dag (B#B = professionele klanten met geldig btw-nummer', 'webshop': 'webshop',
    'commandes du panel, retrait (click & collect) ou livraison': 'bestellingen van het panel, afhalen (click & collect) of levering', '# jours lus sur #': '# dagen gelezen van #', '# jours de caisse lus sur #': '# kassadagen gelezen van #',
    'Clients marqués pro sans TVA valide': 'Klanten gemarkeerd als professioneel zonder geldig btw-nummer', 'comptés au comptoir. S’ils comptaient, la part B#B serait de': 'meegeteld bij de toonbank. Als ze meetelden, zou het aandeel B#B zijn',
    'PWA': 'PWA', 'aucun client identifié': 'geen klant geïdentificeerd', '# % des tickets': '# % van de tickets', 'taux de retour des clients identifiés': 'terugkeerpercentage van de geïdentificeerde klanten',
    '# sur # clients': '# van # klanten', 'clients par jour': 'klanten per dag', 'Articles par client': 'Artikelen per klant', 'Panier moyen': 'Gemiddeld ticket',
    // Les six leviers (cartes-magasin.js, partagées avec le dashboard magasin).
    'Les six leviers': 'De zes hefbomen', 'Sur place, levier par levier': 'Ter plaatse, hefboom per hefboom',
    'chaque point se note, se photographie et part dans le rapport s’il n’est pas conforme': 'elk punt wordt beoordeeld, gefotografeerd en komt in het verslag als het niet conform is',
    'Trafic': 'Traffic', 'trafic': 'traffic', 'Food Cost': 'Food cost', 'Labour Cost': 'Labour cost', 'Overhead Cost': 'Overhead cost', 'Expérience Client': 'Klantbeleving', 'Google-score': 'Google-score',
    'Accueil': 'Onthaal', 'Ambiance': 'Sfeer', 'Produits': 'Producten', 'Production': 'Productie', 'Hygiène, propreté': 'Hygiëne, netheid', 'Accueil, vente': 'Onthaal, verkoop', 'Approvisionnement, ruptures': 'Bevoorrading, tekorten',
    'L’évolution de l’année': 'De evolutie van het jaar', 'Écart vert': 'Groene afwijking', 'mieux qu’en #': 'beter dan in #', 'Pastille — seuils de la Table KPI': 'Bolletje — drempels van de KPI-tabel',
    'panel (margin-heatmap, par jour)': 'panel (margin-heatmap, per dag)', 'panel (margin-heatmap, par jour': 'panel (margin-heatmap, per dag', 'écart du mois en cours aux mêmes jours de #': 'verschil van de lopende maand op dezelfde dagen van #',
    'l’écart au réseau': 'het verschil met het netwerk', 'à date (# jours), face à § et au réseau': 'tot nu (# dagen), t.o.v. § en het netwerk', 'vs seuil # €': 'vs drempel # €',
    'En avance': 'Voorsprong', 'En retrait': 'Achterstand', 'Prévu': 'Gepland', 'à venir': 'komt nog', 'à hier': 'tot gisteren', 'hors seuil': 'buiten drempel', 'sous le seuil': 'onder de drempel', 'sous le réseau': 'onder het netwerk',
    'au niveau du réseau': 'op het niveau van het netwerk', '# % sous le réseau': '# % onder het netwerk', 'face au réseau': 't.o.v. het netwerk', 'Face au réseau (# boutiques)': 'T.o.v. het netwerk (# winkels)', 'Face au réseau (# boutiques': 'T.o.v. het netwerk (# winkels',
    'Mois en cours': 'Lopende maand', 'Mois le plus haut': 'Hoogste maand', 'Dernier mois': 'Laatste maand', 'Aucun mois lu pour ce magasin.': 'Geen enkele maand gelezen voor deze winkel.', 'Lecture des douze mois…': 'De twaalf maanden worden gelezen…',
    'Le panel ne rend pas les articles vendus pour ce magasin sur ces mois.': 'Het panel geeft de verkochte artikelen voor deze winkel over deze maanden niet.', 'mois d’ouverture': 'openingsmaand', 'mois d’ouverture, pas d’écart': 'openingsmaand, geen verschil',
    'ouvert en §': 'geopend in §', 'relevé mensuel depuis §': 'maandelijks overzicht sinds §', 'la note de la fiche, aujourd’hui': 'de score op de fiche, vandaag', 'pas de vente lue': 'geen verkoop gelezen', 'de l’année': 'van het jaar',
    'articles par client du mois': 'artikelen per klant van de maand', 'articles par client': 'artikelen per klant', 'panier moyen du mois': 'gemiddeld ticket van de maand', 'panier moyen': 'gemiddeld ticket', 'clients du mois': 'klanten van de maand',
    '# mois lus': '# maanden gelezen', '# pt': '# pt', '# pts': '# ptn', '−# pt': '−# pt', '−# pts': '−# ptn', '+# pts': '+# ptn',
    // Le client mystère, le scoring, l'équipe.
    'Client mystère': 'Mystery shopper', 'Client mystère du #/#/#': 'Mystery shopper van #/#/#', 'Client mystère et comptoir': 'Mystery shopper en toonbank', 'Rapport mystery shopper': 'Verslag mystery shopper', 'Mystery shopper': 'Mystery shopper',
    'Pas de rapport mystery shopper pour cette boutique (à saisir dans MSP et équipe)': 'Geen verslag van de mystery shopper voor deze winkel (in te voeren bij MSP en team)',
    'Pas de rapport mystery shopper pour cette boutique (à saisir dans MSP et équipe': 'Geen verslag van de mystery shopper voor deze winkel (in te voeren bij MSP en team',
    'MSP et équipe': 'MSP en team', 'MSP Accueil #/#': 'MSP Onthaal #/#', 'Scoring du trimestre': 'Scoring van het kwartaal', 'Scoring T# #': 'Scoring K# #', 'trimestre précédent': 'vorig kwartaal', '#e trimestre #': '#e kwartaal #',
    'Rapport PDF (facultatif, # Mo max)': 'PDF-verslag (optioneel, max. # MB)', 'Rapport PDF (facultatif, # Mo max': 'PDF-verslag (optioneel, max. # MB', 'Saisissez au moins une rubrique.': 'Vul minstens één rubriek in.', 'Rapport MSP enregistré.': 'MSP-verslag opgeslagen.',
    'Effectif relevé.': 'Personeelsbestand genoteerd.', 'Départs (mois)': 'Vertrekken (maand)', 'Départs (mois': 'Vertrekken (maand', 'Pas encore de relevé': 'Nog geen meting', 'Aucun rapport': 'Geen verslag', 'aucun rapport': 'geen verslag', 'pas de rapport': 'geen verslag',
    'rapport mystery shopper, relevé d’effectif': 'verslag mystery shopper, personeelsbestand', 'Hygiène et qualité · #': 'Hygiëne en kwaliteit · #', 'Qualité': 'Kwaliteit',
    // Le CA sous le budget : le pourquoi et les trois pistes ; les points proposés.
    'Pistes': 'Pistes', 'Développement · Pistes': 'Ontwikkeling · Pistes', 'pourquoi et trois pistes': 'waarom en drie pistes', 'CA sous le budget': 'Omzet onder het budget', 'notées': 'genoteerd',
    'Le CA sous le budget': 'De omzet onder het budget', 'Le CA sous le budget : pourquoi et trois pistes': 'De omzet onder het budget: waarom en drie pistes', 'Trois pistes de développement': 'Drie ontwikkelingspistes',
    'Sur le mois −# %, le trimestre −# %. À remplir ensemble': 'Op de maand −# %, het kwartaal −# %. Samen in te vullen', 'Sur le mois −# %. À remplir ensemble': 'Op de maand −# %. Samen in te vullen',
    'le consultant note pourquoi avec le franchisé ; le franchisé donne trois pistes de développement': 'de consultant noteert het waarom samen met de franchisenemer; de franchisenemer geeft drie ontwikkelingspistes',
    'Pourquoi': 'Waarom', 'pourquoi': 'waarom', 'noté par le consultant avec le franchisé': 'genoteerd door de consultant met de franchisenemer', 'Ce qui explique l’écart': 'Wat het verschil verklaart',
    'trafic, panier, ruptures, équipe, travaux, météo': 'traffic, ticket, tekorten, team, werken, weer', 'données par le franchisé': 'gegeven door de franchisenemer', 'À mener pour': 'Uit te voeren tegen', 'la fin du trimestre': 'het einde van het kwartaal',
    'Ex. : relancer la carte de fidélité en caisse': 'Bv.: de klantenkaart aan de kassa opnieuw lanceren', 'Ex. : une dégustation le samedi matin': 'Bv.: een proeverij op zaterdagochtend', 'Ex. : vitrine traiteur pleine dès # h': 'Bv.: traiteurvitrine vol vanaf # u',
    'Ex': 'Bv', 'relancer la carte de fidélité en caisse': 'de klantenkaart aan de kassa opnieuw lanceren', 'une dégustation le samedi matin': 'een proeverij op zaterdagochtend', 'vitrine traiteur pleine dès # h': 'traiteurvitrine vol vanaf # u',
    'Enregistrer': 'Opslaan', 'les pistes passent dans « Points à travailler »': 'de pistes gaan naar « Werkpunten »', 'les pistes passent dans « Points à travailler': 'de pistes gaan naar « Werkpunten',
    'À compléter': 'Aan te vullen', 'le pourquoi': 'het waarom', '# piste': '# piste', '# pistes': '# pistes', 'l’horizon': 'de horizon', 'le pourquoi, # pistes, l’horizon': 'het waarom, # pistes, de horizon', 'le pourquoi, # piste, l’horizon': 'het waarom, # piste, de horizon',
    'le pourquoi, les trois pistes et l’horizon': 'het waarom, de drie pistes en de horizon', 'Enregistré le § à #:#': 'Opgeslagen op § om #:#', 'Enregistré — le formulaire reste à compléter.': 'Opgeslagen — het formulier moet nog aangevuld worden.',
    'Enregistré : les trois pistes sont proposées dans « Points à travailler », à mener d’ici le §.': 'Opgeslagen: de drie pistes zijn voorgesteld bij « Werkpunten », uit te voeren tegen §.',
    'Le CA est à plus de # % sous le budget : notez d’abord pourquoi, les trois pistes du franchisé et l’horizon.': 'De omzet ligt meer dan # % onder het budget: noteer eerst het waarom, de drie pistes van de franchisenemer en de horizon.',
    'Piste de développement du franchisé': 'Ontwikkelingspiste van de franchisenemer', 'CA sous le budget (mois −# %)': 'Omzet onder het budget (maand −# %)', 'CA sous le budget (mois −# %': 'Omzet onder het budget (maand −# %',
    'CA sous le budget (mois −# %, trimestre −# %)': 'Omzet onder het budget (maand −# %, kwartaal −# %)', 'CA sous le budget (mois −# %, trimestre −# %': 'Omzet onder het budget (maand −# %, kwartaal −# %', 'd’ici le §': 'tegen §',
    'Points à travailler': 'Werkpunten', 'Points proposés': 'Voorgestelde punten', '# proposé': '# voorgesteld', '# proposés': '# voorgesteld', 'Ajouter une action': 'Een actie toevoegen', 'Action': 'Actie', 'Aucune action': 'Geen actie',
    'Proposés d’après les non-conformes et les pistes du franchisé. Ils partent chez lui avec le rapport, si le bureau les garde': 'Voorgesteld op basis van de niet-conforme punten en de pistes van de franchisenemer. Ze gaan met het verslag naar hem, als het kantoor ze behoudt',
    'Plan d’action': 'Actieplan', 'Plans d’action': 'Actieplannen', 'Plan d’action de cette visite': 'Actieplan van dit bezoek', 'Suivi plan d’action': 'Opvolging actieplan', 'prioritaire': 'prioritair', 'Priorité': 'Prioriteit', 'Échéance': 'Termijn', 'Qui': 'Wie',
    'Franchisé · pas de photo': 'Franchisenemer · geen foto', 'Équipe · pas de photo': 'Team · geen foto',
    // Le constat, la météo du franchisé, la fin de visite.
    'Constat': 'Vaststelling', 'Vu sur place': 'Ter plaatse gezien', 'Vu sur place et recommandation': 'Ter plaatse gezien en aanbeveling', 'Vu sur place, visite après visite': 'Ter plaatse gezien, bezoek na bezoek',
    'ce que vous avez vu et la recommandation': 'wat je gezien hebt en de aanbeveling', 'Ce qui va bien': 'Wat goed gaat', 'dit au franchisé aussi': 'ook aan de franchisenemer gezegd', 'Le vrai problème': 'Het echte probleem',
    'Ce qui ne marche pas, pourquoi, et ce qu’on fait': 'Wat niet werkt, waarom, en wat we doen', 'ce que j’ai vu, mesuré, touché': 'wat ik gezien, gemeten, aangeraakt heb', 'Recommandation': 'Aanbeveling',
    'Ce que j’en retiens': 'Wat ik onthoud', 'pour le consultant seulement': 'enkel voor de consultant', 'Ce que je vois tourner réellement': 'Wat ik echt zie draaien', 'notes du consultant (historique)': 'notities van de consultant (geschiedenis)', 'notes du consultant (historique': 'notities van de consultant (geschiedenis',
    'Si quelque chose cloche, nommez la cause': 'Als er iets schort, benoem de oorzaak', 'pas la même analyse depuis le bureau': 'niet dezelfde analyse vanuit het kantoor', 'Observé positif': 'Positief vastgesteld',
    'Exécution face au protocole': 'Uitvoering t.o.v. het protocol', 'Standards appliqués': 'Standaarden toegepast', 'Quelques raccourcis': 'Enkele kortere wegen', 'Écarts fréquents': 'Frequente afwijkingen',
    'Satisfaits': 'Tevreden', 'Mitigés': 'Gemengd', 'Insatisfaits': 'Ontevreden', 'Énergie de l’équipe': 'Energie van het team', 'Équipe stable': 'Stabiel team', 'Équipe démotivée': 'Gedemotiveerd team',
    'Les clients sortent': 'De klanten vertrekken', 'Décor, ambiance qui n’invite pas': 'Decor, sfeer die niet uitnodigt', 'Note interne': 'Interne notitie', 'Commentaires du rapport': 'Opmerkingen in het verslag', 'Détail pour le franchisé': 'Detail voor de franchisenemer',
    'Le détail, si besoin': 'Het detail, indien nodig', 'Voilà ce qui ne marche pas et voilà pourquoi. » Le franchisé la lit ; Sam la retrouve dans la synthèse': 'Dit werkt niet en dit is waarom. » De franchisenemer leest het; Sam vindt het terug in de synthese',
    'Météo': 'Weerbericht', 'Météo du franchisé': 'Weerbericht van de franchisenemer', 'la météo du franchisé': 'het weerbericht van de franchisenemer', 'Dernière météo du franchisé': 'Laatste weerbericht van de franchisenemer', 'météo du franchisé': 'weerbericht van de franchisenemer',
    'Grand soleil': 'Stralende zon', 'Éclaircies': 'Opklaringen', 'Nuageux': 'Bewolkt', 'Pluie': 'Regen', 'Orage': 'Onweer',
    'motivé, moteur': 'gemotiveerd, drijvende kracht', 'plutôt confiant': 'eerder vol vertrouwen', 'mitigé': 'gemengd', 'découragé': 'ontmoedigd', 'tendu, en conflit': 'gespannen, in conflict',
    'Moral': 'Moreel', 'moral #': 'moreel #', 'Envie, motivation': 'Zin, motivatie', 'Climat de l’équipe': 'Sfeer in het team', 'Relation avec le réseau': 'Relatie met het netwerk', 'équipe': 'team', '#, équipe': '#, team',
    'Ses envies, ses projets': 'Zijn wensen, zijn projecten', 'Ses inquiétudes, ce qui bloque': 'Zijn zorgen, wat vastzit', 'Ses demandes': 'Zijn vragen', 'une par ligne': 'één per lijn', 'chacune devient une tâche': 'elke vraag wordt een taak',
    'Reste entre le consultant et le bureau, jamais dans le rapport ; chaque demande devient une tâche du consultant à J+#': 'Blijft tussen de consultant en het kantoor, nooit in het verslag; elke vraag wordt een taak van de consultant op D+#',
    'Enregistrer la météo': 'Weerbericht opslaan', 'Météo enregistrée.': 'Weerbericht opgeslagen.', 'La météo est vide.': 'Het weerbericht is leeg.', 'éclaircies (fin de contrôle)': 'opklaringen (einde controle)', 'éclaircies (fin de contrôle': 'opklaringen (einde controle',
    'Fin de visite': 'Einde van het bezoek', 'signatures, envoi au bureau': 'handtekeningen, verzending naar het kantoor', 'Signature du consultant': 'Handtekening van de consultant', 'Signature du franchisé': 'Handtekening van de franchisenemer',
    'Le consultant': 'De consultant', 'Le franchisé a vu le résumé': 'De franchisenemer heeft de samenvatting gezien', 'Terminer': 'Afronden', 'le brouillon part au bureau': 'het ontwerp gaat naar het kantoor',
    'Terminer n’envoie rien au franchisé': 'Afronden stuurt niets naar de franchisenemer',
    'le brouillon du rapport part au bureau, qui le relit et l’envoie sous # h. Les points proposés ne s’ouvrent chez lui qu’à ce moment-là': 'het ontwerpverslag gaat naar het kantoor, dat het naleest en binnen # u verstuurt. De voorgestelde punten openen pas op dat moment bij hem',
    'Visite terminée · le brouillon part au bureau': 'Bezoek afgerond · het ontwerp gaat naar het kantoor', 'avec # point proposé': 'met # voorgesteld punt', 'avec # points proposés': 'met # voorgestelde punten',
    'Visite terminée · le brouillon part au bureau avec # point proposé': 'Bezoek afgerond · het ontwerp gaat naar het kantoor met # voorgesteld punt', 'Visite terminée · le brouillon part au bureau avec # points proposés': 'Bezoek afgerond · het ontwerp gaat naar het kantoor met # voorgestelde punten',
    'effacer la signature': 'handtekening wissen', 'Signez ici': 'Hier tekenen',
    // La fiche, la review, l'historique, le client mystère, le tableau de bord.
    'Review': 'Review', 'Review — #': 'Review — #', 'Historique': 'Geschiedenis', 'Historique # mois': 'Geschiedenis # maanden', 'Historique — #': 'Geschiedenis — #', 'MSP et équipe — #': 'MSP en team — #',
    'Envoyer la review et ouvrir les plans d’action': 'De review versturen en de actieplannen openen', 'Envoyer le rapport et le compte rendu': 'Het rapport en de samenvatting versturen', 'Enregistrer le rapport': 'Het verslag opslaan',
    'Tableau de bord': 'Dashboard', 'Tableau de bord — aperçu': 'Dashboard — overzicht', 'aperçu': 'overzicht', 'Alertes': 'Waarschuwingen', 'Notifications': 'Meldingen', 'Notifications activées.': 'Meldingen geactiveerd.', 'Notifications désactivées.': 'Meldingen uitgeschakeld.', 'Abonnement refusé.': 'Abonnement geweigerd.',
    'À activer sur le téléphone (page Application mobile)': 'Te activeren op de telefoon (pagina Mobiele app)', 'À activer sur le téléphone (page Application mobile': 'Te activeren op de telefoon (pagina Mobiele app',
    'Note interne : magasin pilote': 'Interne notitie: pilootwinkel', 'magasin pilote': 'pilootwinkel', 'Boutique': 'Winkel', 'Magasin': 'Winkel', 'Le franchisé': 'De franchisenemer',
    'Qui suis-je': 'Wie ben ik', 'Dites qui vous êtes dans Réglages': 'Zeg wie je bent bij Instellingen', 'l’agenda montrera vos visites': 'de agenda toont dan je bezoeken',
    'L’agenda montre les visites de ce consultant ; les visites planifiées ici lui sont attribuées': 'De agenda toont de bezoeken van deze consultant; de hier geplande bezoeken worden aan hem toegewezen',
    'Réglages enregistrés.': 'Instellingen opgeslagen.', 'Horloge injoignable.': 'Klok onbereikbaar.', 'Synthèse indisponible hors ligne.': 'Synthese niet beschikbaar offline.', 'PDF prêt à envoyer.': 'PDF klaar om te versturen.',
    'Refusé par le serveur': 'Geweigerd door de server', 'Lecture…': 'Laden…', 'Ouvrir': 'Openen', 'Fermer': 'Sluiten', 'Annuler': 'Annuleren', 'Retour': 'Terug', 'Modifier': 'Wijzigen', 'Supprimer': 'Verwijderen', 'Ajouter': 'Toevoegen',
    'Oui': 'Ja', 'Non': 'Nee', 'aucun': 'geen', 'aucune': 'geen', 'sans notes': 'zonder notities', 'Tâches à faire — #': 'Te doen — #',
    'Pierre B. · le § #:# · re-notée #/# le §': 'Pierre B. · op § #:# · opnieuw beoordeeld #/# op §',
    'Franchisé · ouvert le § · délai dépassé de # j · pas de photo': 'Franchisenemer · geopend op § · termijn # d overschreden · geen foto',
    'P# ouvert': 'P# open',
    'Préparé le § #:# · visite annoncée': 'Voorbereid op § #:# · aangekondigd bezoek',
    'Durée': 'Duur', 'heure': 'uur', 'Notes': 'Notities', 'note': 'score', 'Note': 'Score', 'Statut': 'Status', 'Commentaire': 'Opmerking', 'Retour du franchisé': 'Reactie van de franchisenemer',
    // Deuxième passage du parcours (les compteurs, les états, les repères).
    '# chiffres clés': '# kerncijfers', '# créés': '# aangemaakt', '# créé': '# aangemaakt', '# fermé': '# afgesloten', '# fermés': '# afgesloten', '# visites': '# bezoeken', '# visite': '# bezoek',
    '# non-conformité(s) du panel non corrigée(s) sur # j': '# non-conformiteit(en) van het panel niet gecorrigeerd op # d', 'Non-conformités du panel (# j)': 'Non-conformiteiten van het panel (# d)', 'Non-conformités du panel (# j': 'Non-conformiteiten van het panel (# d',
    '# non corrigée(s)': '# niet gecorrigeerd', '# non corrigée(s': '# niet gecorrigeerd', '# relevée(s)': '# vastgesteld', '# relevée(s': '# vastgesteld', '# ouvert(s) aujourd’hui': '# vandaag geopend',
    '°C': '°C', '# °C': '# °C', 'P#': 'P#', 'AAAA-MM': 'JJJJ-MM', 'Avant d’entrer': 'Vóór het binnengaan', 'CA de la semaine vs objectif': 'Omzet van de week vs doelstelling', 'CA vs objectif': 'Omzet vs doelstelling',
    'Les # points, le thermomètre en photo': 'De # punten, de thermometer op foto', 'pas dans l’ordre du jour': 'niet op de agenda', 'semaine du § au §': 'week van § tot §', 'à écrire': 'te schrijven',
    'N.v.t.': 'N.v.t.', 'N.v.t': 'N.v.t', 'Ja': 'Ja', 'Nee': 'Nee', 'Afwijking': 'Afwijking', 'Opmerking': 'Opmerking', 'Foto': 'Foto', 'Het antwoord': 'Het antwoord', 'Traffic': 'Traffic', 'Planogram': 'Planogram',
    'point': 'punt', 'signature': 'handtekening', 'optionnel': 'optioneel', 'facultatif': 'optioneel', 'Annuler la visite': 'Bezoek annuleren', 'Escalader à Sam': 'Escaleren naar Sam', 'Pourquoi escalader': 'Waarom escaleren', 'Précédente': 'Vorige', 'Suivante': 'Volgende', 'pas de photo pour cette tâche': 'geen foto voor deze taak',
    'Lundi #/#': 'Maandag #/#', 'Mardi #/#': 'Dinsdag #/#', 'Mercredi #/#': 'Woensdag #/#', 'Jeudi #/#': 'Donderdag #/#', 'Vendredi #/#': 'Vrijdag #/#', 'Samedi #/#': 'Zaterdag #/#', 'Dimanche #/#': 'Zondag #/#',
  };

  // Une clé écrite avec sa ponctuation (« Photo illisible. ») se trouve aussi par son cœur (« Photo illisible ») : le texte
  // affiché garde ses propres ornements.
  function noyaux(o) {
    Object.keys(o).forEach(k => { const [, c, ap] = bords(k); if (c && c !== k && !Object.prototype.hasOwnProperty.call(NL, c)) { const v = o[k]; NL[c] = ap && v.endsWith(ap.trim()) ? v.slice(0, v.length - ap.trim().length).trimEnd() : bords(v)[1]; } });
  }
  noyaux(NL);
  const cache = new Map();
  const manquants = new Set();
  const majIni = s => s.charAt(0).toUpperCase() + s.slice(1);
  const minIni = s => s.charAt(0).toLowerCase() + s.slice(1);

  /** Un texte coupé en [ornements avant, cœur, ornements après] ; une parenthèse ouverte dans le cœur garde sa fermante. */
  function bords(s) {
    const b = RX_BORD.exec(s); if (!b) { return ['', s, '']; }
    let [, av, coeur, ap] = b;
    const ouv = (coeur.match(/\(/g) || []).length - (coeur.match(/\)/g) || []).length;
    if (ouv > 0 && ap.includes(')')) { const i = ap.indexOf(')') + 1; coeur += ap.slice(0, i); ap = ap.slice(i); }
    const fer = (coeur.match(/\)/g) || []).length - (coeur.match(/\(/g) || []).length;
    if (fer > 0 && av.includes('(')) { const i = av.lastIndexOf('('); coeur = av.slice(i) + coeur; av = av.slice(0, i); }
    return [av, coeur, ap];
  }
  /** Un texte sans espaces de bord, ou null s'il n'est pas au dictionnaire. */
  function chercher(s) {
    if (Object.prototype.hasOwnProperty.call(NL, s)) { return NL[s]; }
    const c = s.charAt(0);
    if (c !== c.toLowerCase()) { const k = minIni(s); if (Object.prototype.hasOwnProperty.call(NL, k)) { return majIni(NL[k]); } }
    else if (c !== c.toUpperCase()) { const k = majIni(s); if (Object.prototype.hasOwnProperty.call(NL, k)) { return minIni(NL[k]); } }
    return null;
  }
  /** Avec ses nombres et ses dates comme des trous. */
  function avecTrous(s) {
    const dates = []; const nbs = [];
    let k = s.replace(RX_DATE, (...a) => { dates.push(dateNl(...a)); return '§'; });
    k = k.replace(RX_NB, x => { nbs.push(x); return '#'; });
    if (!dates.length && !nbs.length) { return null; }
    const t = chercher(k);
    if (t == null) { return { cle: k, t: null }; }
    let i = 0; let j = 0;
    return { cle: k, t: t.replace(/§/g, () => dates[i++] ?? '§').replace(/#/g, () => nbs[j++] ?? '#') };
  }
  /** Un texte (sans espaces de bord) traduit, ou null. */
  function un(s, prof) {
    if (!RX_LETTRE.test(s)) { return s; }
    let t = chercher(s); if (t != null) { return t; }
    const [av, coeur, ap] = bords(s);
    // Le néerlandais colle « ? », « ! », « : » et « ; » au mot qui précède.
    const apNl = ap.replace(/^\s+(?=[?!:;])/, '');
    if (coeur !== s && coeur) { t = chercher(coeur); if (t != null) { return av + t + apNl; } }
    const tr = avecTrous(coeur);
    if (tr && tr.t != null) { return av + tr.t + apNl; }
    // Les dates seules (« lun. 26 oct. ») se traduisent sans dictionnaire.
    if (tr && /^[§#\s,./:-]+$/.test(tr.cle)) { return av + coeur.replace(RX_DATE, (...a) => dateNl(...a)) + ap; }
    if ((prof || 0) < 3) {
      // Chaque morceau se traduit à son tour ; le texte est rendu dès qu'un morceau a changé ou que tous sont connus.
      const morceaux = (liste, joint) => { let change = false; let connus = true;
        const r = liste.map((m, i) => { if (joint == null && i % 2) { return m; } const x = m.trim(); if (!x) { return m; } const y = un(x, (prof || 0) + 1); if (y == null) { connus = false; return m; } if (y !== x) { change = true; } return m.replace(x, y); });
        return change || connus ? av + r.join(joint == null ? '' : joint) + ap : null; };
      if (RX_EMOJI.test(coeur)) { const r = morceaux(coeur.split(RX_EMOJI), null); if (r != null) { return r; } }
      for (const sep of SEPARATEURS) {
        if (!coeur.includes(sep)) { continue; }
        // Le néerlandais ne met pas d'espace avant les deux-points.
        const r = morceaux(coeur.split(sep), sep === ' : ' ? ': ' : sep); if (r != null) { return r; }
      }
    }
    // Un libellé suivi d'un nom (« Franchisé Halle », le nom par défaut du franchisé) : le libellé seul se traduit.
    for (const [fr, nl] of PREFIXES) { if (coeur.startsWith(fr) && coeur.length > fr.length) { return av + nl + coeur.slice(fr.length) + ap; } }
    if (VI_NL.debug && coeur.length > 1) { manquants.add(tr ? tr.cle : coeur); }
    return null;
  }
  /** Un texte affiché → sa traduction, ses espaces de bord gardés. */
  function t(v) {
    if (v == null) { return v; }
    const s = String(v);
    if (!RX_LETTRE.test(s)) { return s; }
    if (cache.has(s)) { return cache.get(s); }
    const m = /^(\s*)([\s\S]*?)(\s*)$/.exec(s);
    const r = un(m[2], 0);
    const res = r == null ? s : m[1] + r + m[3];
    if (cache.size > 6000) { cache.clear(); }
    cache.set(s, res);
    return res;
  }
  const ATTRS = ['placeholder', 'title', 'aria-label'];
  /** Les textes d'un écran rendu, et les attributs qui se lisent. `data-nt` : un bloc à ne pas traduire. */
  function traduire(racine) {
    if (!racine) { return; }
    const w = document.createTreeWalker(racine, NodeFilter.SHOW_TEXT, { acceptNode: n => {
      const p = n.parentNode; if (!p) { return NodeFilter.FILTER_REJECT; }
      const tag = p.nodeName; if (tag === 'SCRIPT' || tag === 'STYLE' || tag === 'TEXTAREA') { return NodeFilter.FILTER_REJECT; }
      if (p.closest && p.closest('[data-nt]')) { return NodeFilter.FILTER_REJECT; }
      return RX_LETTRE.test(n.nodeValue) ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT;
    } });
    const noeuds = []; while (w.nextNode()) { noeuds.push(w.currentNode); }
    noeuds.forEach(n => { const v = n.nodeValue; const r = t(v); if (r !== v) { n.nodeValue = r; } });
    racine.querySelectorAll('[placeholder],[title],[aria-label]').forEach(el => {
      if (el.closest('[data-nt]')) { return; }
      ATTRS.forEach(a => { const v = el.getAttribute(a); if (v && RX_LETTRE.test(v)) { const r = t(v); if (r !== v) { el.setAttribute(a, r); } } });
    });
  }

  const VI_NL = { NL, t, traduire, manquants, debug: false, ajouter(o) { Object.assign(NL, o); noyaux(o); cache.clear(); } };
  window.VI_NL = VI_NL;
})();
