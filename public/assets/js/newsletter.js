/* Newsletter & campagnes — la marque et ses franchisés écrivent aux clients
 * captés en boutique : email + SMS, segments, modèles, vouchers, déclinaison
 * sur les réseaux, automatisations, droits marque / franchisé.
 *
 * Un seul module, deux vues : la MARQUE (rôle « brand » — tout, paramètres
 * compris) et le MAGASIN (rôle = id du magasin — ses segments, sa campagne,
 * son adresse ; ni segment, ni automatisation, ni paramètres). Le rôle est
 * donné par l'hôte : l'écran « Newsletter » du cockpit monte la marque,
 * « Newsletter magasin » le magasin choisi.
 *
 *   CockpitNewsletter.mount(host, { role, apiBase, imgDir, notify })
 *
 * Données : GET /newsletter?role=… ; écritures POST /newsletter/campagnes,
 * POST /newsletter/segments, PUT /newsletter/magasins/{id}, POST /newsletter/test.
 * MODE TEST : le serveur ne fait partir aucun envoi ; l'écran le dit.
 *
 * Porté de la référence de design « Newsletter Module » (copy, états et
 * tokens du design system L'Atelier : jamais une couleur en dur hors des
 * tokens et du vert succès #2d7a3e hérité de .vies-state--valid).
 */
(function () {
  'use strict';
  const esc = v => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const fmt = n => Number(n || 0).toLocaleString('fr-BE').replace(/ | /g, ' ');
  const P = 'var(--color-primary)', MUTED = 'var(--color-text-muted)', SEC = 'var(--color-secondary)', ON = 'var(--color-on-abricot)', SURF = 'var(--color-surface)', BG2 = 'var(--color-background-secondary)', GREEN = '#2d7a3e';
  const LANG_KEYS = ['fr', 'nl', 'en'];
  const TRIGGERS = ['dormant45', 'third', 'birthday', 'firstOffice', 'season'];
  const MOIS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

  const I18N = {
    fr: {
      newCampaign: 'Nouvelle campagne', campaigns: 'Campagnes', templates: 'Templates', landing: 'Landing voucher',
      campaignsSub: 'Envois en cours, programmés et passés, tous magasins.', optinContacts: 'Contacts opt-in', sentThisMonth: 'Envois ce mois', vouchersUsed: 'Vouchers utilisés', optin: 'opt-in',
      colName: 'Campagne', colDate: 'Envoi', colSegment: 'Segment', colStatus: 'Statut', colOpen: 'Ouverture', colClick: 'Clic', colVouchers: 'Vouchers',
      dashboardHint: 'Cliquez une campagne envoyée pour voir sa rétrospective.',
      st_sent: 'Envoyé', st_live: 'En cours', st_sched: 'Programmé', st_draft: 'Brouillon',
      s1: 'Segment', s2: 'Template', s3: 'Texte', s4: 'Envoi',
      s1Title: 'À qui écrit-on ?', s1Sub: 'Choisissez la base, puis le segment. Le compteur ne compte que les contacts opt-in.',
      database: 'Base de données', segment: 'Segment', recipients: 'destinataires', newSegment: 'Créer un segment',
      s2Title: 'Quel type de mail ?', s2Sub: 'Cinq modèles prêts, ou une page blanche.',
      s3Title: 'Le texte', s3Sub: 'Court, parlé, comme au comptoir. Un seul appel à l\'action.',
      subject: 'Objet', subjectGood: 'Bonne longueur, ça tient sur mobile.', subjectLong: 'Trop long pour un écran de téléphone : visez moins de 45 caractères.',
      insert: 'Insérer…', insFirstname: 'Prénom', insVoucher: 'Code voucher', insWebshop: 'Lien webshop', insShop: 'Lien boutique',
      words: 'mots', oneCta: 'Un seul lien primaire par mail.',
      s4Title: 'Quand part-il ?', s4Sub: 'Le mail part par lots de 100 par minute. Un mail par client par jour maximum.',
      date: 'Date', time: 'Heure', bestTime: 'Ce segment ouvre le plus entre 7h30 et 9h en semaine.',
      maxVouchers: 'Vouchers max', optional: 'facultatif', voucherHintOn: 'vouchers Stripe uniques générés au départ. Le premier arrivé, premier servi.', voucherHintOff: 'Pas de voucher dans ce mail.',
      template: 'Template', sendTest: 'Test', testSend: 'Envoyer un test à 3 adresses', testDone: 'Test envoyé à 3 adresses ✓',
      back: 'Retour', next: 'Continuer', schedule: 'Programmer l\'envoi',
      preview: 'Aperçu', mobile: 'Mobile', subjectPlaceholder: '(objet du mail)',
      badge3: 'Votre 3e mail · fidélité débloquée',
      footWebshop: 'Webshop', footShop: 'Votre boutique', footSupport: '02 345 67 89', footHours: 'Ouvert du mardi au dimanche, 7h – 18h30',
      unsubscribe: 'Se désinscrire',
      templatesSub: 'Chaque modèle : un visuel, un texte court, un seul lien. Le pied de page est commun.', useTemplate: 'Utiliser',
      duplicateAB: 'Dupliquer pour test A/B', viewLanding: 'Voir la landing voucher',
      kSent: 'Envois', kOpen: 'Ouvertures', kClick: 'Clics', kVouchers: 'Vouchers activés', kRevenue: 'Revenu attribué',
      vsPrev: 'vs', funnel: 'Parcours', funnelLegend: 'Le trait noir marque la campagne précédente du même type.',
      landingTitle: 'Landing voucher', landingSub: 'Ce que le client voit après le clic depuis le mail. URL courte et unique par destinataire ; le code est aussi envoyé par mail pour la boutique.',
      redirect: 'Redirection', validity: 'Validité', days: 'jours', perCustomer: 'par client',
      knownOn: 'Simuler : client connu', knownOff: 'Simuler : client inconnu', reset: 'Réinitialiser',
      landingHeadline: 'Vous avez gagné une tarte diamant', landingGain: 'À retirer à votre prochaine visite en boutique ou sur le webshop, dans les 30 jours.',
      landingFormHint: 'Laissez-nous votre prénom et votre email, on vous renvoie le code.', firstname: 'Prénom', email: 'Email',
      codeSentTo: 'Code envoyé à', yourCode: 'Votre code', copy: 'Copier le code', copied: 'Code copié ✓', useWebshop: 'Utiliser sur le webshop',
      landingFoot: 'L\'Atelier By · Halle · 02 345 67 89\nValable une fois, jusqu\'au 24 octobre 2026.',
      duplicatedName: 'Copie B',
      segName: 'Nom du segment', segShop: 'Magasin', segAllShops: 'Tous les magasins', segProduct: 'Produit acheté', segAnyProduct: 'Peu importe', segPeriod: 'Période', p30: '30 derniers jours', p90: '90 derniers jours', p365: '12 derniers mois', pDormant: 'Aucun achat depuis 45 jours',
      segMinBasket: 'Panier min. (€)', segEstimated: 'estimés', segSave: 'Créer le segment', cancel: 'Annuler', segNamePlaceholder: 'ex. Clients Halle qui ont acheté une tartine', segRuleShop: 'magasin', segRuleProduct: 'produit', segRuleBasket: 'panier ≥',
      sendLangs: 'Langues d\'envoi', sendLangsSub: 'Chaque client reçoit le mail dans la langue de sa fiche. Décochez une langue pour exclure ces clients.',
      lang_fr: 'Français', lang_nl: 'Néerlandais', lang_en: 'Anglais', version: 'Version', editLangHint: 'Une version par langue cochée à l\'étape 1.', langNone: 'Aucune langue sélectionnée',
      channel: 'Canal', chEmail: 'Email', chSms: 'SMS', chBoth: 'Email + SMS', smsOptin: 'numéros opt-in SMS', smsText: 'Texte du SMS', smsHint: 'Un SMS = 160 caractères. Prénom et lien court remplacés à l\'envoi. STOP obligatoire.',
      sendMode: 'Mode d\'envoi', modeManual: 'Envoi unique', modeManualDesc: 'Part une fois, à la date choisie.', modeAuto: 'Automatisé', modeAutoDesc: 'Part à chaque client qui remplit la règle.',
      trigger: 'Déclencheur', triggerHint: 'Vérifié chaque nuit. 1 mail par client par jour, jamais deux automatisations le même jour.',
      tr_dormant45: 'Pas d\'achat depuis 45 jours', tr_third: '3e mail reçu (palier fidélité)', tr_birthday: 'Anniversaire du client (J-3)', tr_firstOffice: 'Première commande office livrée (J+2)', tr_season: 'J-7 avant un temps fort (cougnou, galette)',
      s5: 'Envoi', soShort: 'Réseaux', soTitle: 'Décliner sur les réseaux', soSub: 'Le mail est découpé en blocs (hook, insight, CTA, image). Trois brouillons sont générés en un clic ; une personne relit avant publication.',
      soImageNote: 'Reprise telle quelle sur Instagram. LinkedIn et Slack : facultatif.', soGenerated: 'Brouillons générés depuis les blocs du mail. À relire, jamais publiés sans validation.', soRegen: 'Régénérer depuis le mail',
      so_linkedin: 'Accroche + insight + lien', so_instagram: 'Hook court + CTA · image obligatoire', so_slack: 'Teaser interne pour l\'équipe des boutiques',
      soReview: 'À relire', soReviewed: 'Relu', soOff: 'Non publié', soNoteLi: 'Ton : un vendeur qui écrit, pas un communiqué.', soNoteIg: 'Première ligne visible avant « plus ». Pas de lien cliquable : renvoyer à la bio.', soNoteSlack: 'Posté dans #boutiques le matin de l\'envoi.',
      soNone: 'Aucune déclinaison',
      settings: 'Paramètres', settingsSub: 'Chaque magasin envoie depuis sa propre adresse. La marque crée les segments et les modèles ; le franchisé envoie, ou pas.',
      shop: 'Magasin', shopSender: 'Expéditeur (nom · adresse)', domain: 'Domaine', canSendCol: 'Envoi', kindBrand: 'Marque · admin', kindFranchise: 'Franchisé',
      canSendOn: 'Autorisé', canSendOff: 'Bloqué', rights: 'Droits', roleBrand: 'Vue : Marque (admin)', roleBrandShort: 'Marque', roleFranchiseShort: 'Franchisé', viewShop: 'Vue : ',
      r_seg: 'Créer / modifier les segments', r_tpl: 'Créer / modifier les modèles', r_auto: 'Créer une automatisation', r_send: 'Envoyer une campagne (son magasin)', r_sender: 'Modifier son adresse d\'expéditeur', r_stats: 'Voir ses statistiques',
      rightsNote: 'Un franchisé n\'écrit qu\'à ses propres clients, depuis l\'adresse de son magasin. Si la marque bloque l\'envoi, il garde l\'accès en lecture.',
      sendDisabled: 'Envoi bloqué par la marque', segBrandOnly: 'Seule la marque peut créer un segment.',
      fAll: 'Toutes', fAuto: 'Automatisées', fManual: 'Manuelles', pillAuto: 'Auto', pillManual: 'Manuel',
      sender: 'Expéditeur', replyTo: 'réponses vers', verified: 'Domaine vérifié', unverified: 'Non vérifié', warmup: 'En chauffe',
      spamCheck: 'Contrôle anti-spam', spamOk: 'Prêt à partir', spamWarn: 'À corriger avant envoi',
      ck_auth: 'SPF, DKIM et DMARC valides sur le domaine expéditeur', ck_reply: 'Adresse de réponse lue par une vraie personne', ck_unsub: 'Lien de désinscription en un clic dans le pied de page',
      ck_optin: 'Destinataires opt-in uniquement, 1 mail par client par jour', ck_subject: 'Objet sans majuscules criées ni « GRATUIT !!! »', ck_ratio: 'Texte suffisant par rapport à l\'image (1 visuel)', ck_test: 'Test envoyé et rendu vérifié sur Gmail, Outlook, Apple Mail',
    },
    nl: {
      newCampaign: 'Nieuwe campagne', campaigns: 'Campagnes', templates: 'Sjablonen', landing: 'Voucherpagina',
      campaignsSub: 'Lopende, geplande en verzonden campagnes, alle winkels.', optinContacts: 'Opt-in contacten', sentThisMonth: 'Verzonden deze maand', vouchersUsed: 'Vouchers gebruikt', optin: 'opt-in',
      colName: 'Campagne', colDate: 'Verzending', colSegment: 'Segment', colStatus: 'Status', colOpen: 'Geopend', colClick: 'Geklikt', colVouchers: 'Vouchers',
      dashboardHint: 'Klik op een verzonden campagne voor het overzicht.',
      st_sent: 'Verzonden', st_live: 'Bezig', st_sched: 'Gepland', st_draft: 'Concept',
      s1: 'Segment', s2: 'Sjabloon', s3: 'Tekst', s4: 'Verzending',
      s1Title: 'Aan wie schrijven we?', s1Sub: 'Kies de database, dan het segment. De teller telt enkel opt-in contacten.',
      database: 'Database', segment: 'Segment', recipients: 'ontvangers', newSegment: 'Segment aanmaken',
      s2Title: 'Welk soort mail?', s2Sub: 'Vijf kant-en-klare modellen, of een leeg blad.',
      s3Title: 'De tekst', s3Sub: 'Kort, gesproken, zoals aan de toonbank. Eén oproep tot actie.',
      subject: 'Onderwerp', subjectGood: 'Goede lengte, past op mobiel.', subjectLong: 'Te lang voor een telefoonscherm: hou het onder 45 tekens.',
      insert: 'Invoegen…', insFirstname: 'Voornaam', insVoucher: 'Vouchercode', insWebshop: 'Link webshop', insShop: 'Link winkel',
      words: 'woorden', oneCta: 'Eén primaire link per mail.',
      s4Title: 'Wanneer vertrekt hij?', s4Sub: 'De mail vertrekt in groepen van 100 per minuut. Maximaal één mail per klant per dag.',
      date: 'Datum', time: 'Uur', bestTime: 'Dit segment opent het meest tussen 7u30 en 9u op weekdagen.',
      maxVouchers: 'Max. vouchers', optional: 'optioneel', voucherHintOn: 'unieke Stripe-vouchers aangemaakt bij verzending. Wie eerst komt, eerst maalt.', voucherHintOff: 'Geen voucher in deze mail.',
      template: 'Sjabloon', sendTest: 'Test', testSend: 'Test sturen naar 3 adressen', testDone: 'Test verzonden naar 3 adressen ✓',
      back: 'Terug', next: 'Verder', schedule: 'Verzending plannen',
      preview: 'Voorbeeld', mobile: 'Mobiel', subjectPlaceholder: '(onderwerp)',
      badge3: 'Uw 3e mail · trouwvoordeel ontgrendeld',
      footWebshop: 'Webshop', footShop: 'Uw winkel', footSupport: '02 345 67 89', footHours: 'Open van dinsdag tot zondag, 7u – 18u30',
      unsubscribe: 'Uitschrijven',
      templatesSub: 'Elk model: één beeld, korte tekst, één link. De voettekst is gemeenschappelijk.', useTemplate: 'Gebruiken',
      duplicateAB: 'Dupliceren voor A/B-test', viewLanding: 'Voucherpagina bekijken',
      kSent: 'Verzonden', kOpen: 'Geopend', kClick: 'Geklikt', kVouchers: 'Vouchers geactiveerd', kRevenue: 'Toegekende omzet',
      vsPrev: 'vs', funnel: 'Traject', funnelLegend: 'De zwarte streep markeert de vorige campagne van hetzelfde type.',
      landingTitle: 'Voucherpagina', landingSub: 'Wat de klant ziet na de klik in de mail. Korte, unieke URL per ontvanger; de code wordt ook gemaild voor gebruik in de winkel.',
      redirect: 'Doorverwijzing', validity: 'Geldigheid', days: 'dagen', perCustomer: 'per klant',
      knownOn: 'Simuleer: gekende klant', knownOff: 'Simuleer: onbekende klant', reset: 'Herstellen',
      landingHeadline: 'U hebt een diamanttaart gewonnen', landingGain: 'Af te halen bij uw volgend bezoek in de winkel of via de webshop, binnen 30 dagen.',
      landingFormHint: 'Laat uw voornaam en e-mail achter, we sturen u de code.', firstname: 'Voornaam', email: 'E-mail',
      codeSentTo: 'Code verzonden naar', yourCode: 'Uw code', copy: 'Code kopiëren', copied: 'Code gekopieerd ✓', useWebshop: 'Gebruiken op de webshop',
      landingFoot: 'L\'Atelier By · Halle · 02 345 67 89\nEénmalig geldig, tot 24 oktober 2026.',
      duplicatedName: 'Kopie B',
      segName: 'Naam van het segment', segShop: 'Winkel', segAllShops: 'Alle winkels', segProduct: 'Gekocht product', segAnyProduct: 'Om het even', segPeriod: 'Periode', p30: 'Laatste 30 dagen', p90: 'Laatste 90 dagen', p365: 'Laatste 12 maanden', pDormant: 'Geen aankoop sinds 45 dagen',
      segMinBasket: 'Min. mandje (€)', segEstimated: 'geschat', segSave: 'Segment aanmaken', cancel: 'Annuleren', segNamePlaceholder: 'bv. Klanten Halle die een tartine kochten', segRuleShop: 'winkel', segRuleProduct: 'product', segRuleBasket: 'mandje ≥',
      sendLangs: 'Verzendtalen', sendLangsSub: 'Elke klant krijgt de mail in de taal van zijn fiche. Vink een taal uit om die klanten uit te sluiten.',
      lang_fr: 'Frans', lang_nl: 'Nederlands', lang_en: 'Engels', version: 'Versie', editLangHint: 'Eén versie per taal aangevinkt in stap 1.', langNone: 'Geen taal geselecteerd',
      channel: 'Kanaal', chEmail: 'E-mail', chSms: 'SMS', chBoth: 'E-mail + SMS', smsOptin: 'nummers opt-in SMS', smsText: 'Tekst van de SMS', smsHint: 'Eén SMS = 160 tekens. Voornaam en korte link ingevuld bij verzending. STOP verplicht.',
      sendMode: 'Verzendmodus', modeManual: 'Eenmalig', modeManualDesc: 'Vertrekt één keer, op de gekozen datum.', modeAuto: 'Geautomatiseerd', modeAutoDesc: 'Vertrekt naar elke klant die aan de regel voldoet.',
      trigger: 'Trigger', triggerHint: 'Elke nacht gecontroleerd. 1 mail per klant per dag, nooit twee automatiseringen op dezelfde dag.',
      tr_dormant45: 'Geen aankoop sinds 45 dagen', tr_third: '3e mail ontvangen (trouwniveau)', tr_birthday: 'Verjaardag van de klant (D-3)', tr_firstOffice: 'Eerste office-bestelling geleverd (D+2)', tr_season: 'D-7 vóór een hoogtepunt (cougnou, koningentaart)',
      s5: 'Verzending', soShort: 'Sociale media', soTitle: 'Doorvertalen naar sociale media', soSub: 'De mail wordt opgedeeld in blokken (hook, insight, CTA, beeld). Drie ontwerpen in één klik; iemand leest na vóór publicatie.',
      soImageNote: 'Ongewijzigd op Instagram. LinkedIn en Slack: optioneel.', soGenerated: 'Ontwerpen gegenereerd uit de blokken van de mail. Na te lezen, nooit gepubliceerd zonder goedkeuring.', soRegen: 'Opnieuw genereren uit de mail',
      so_linkedin: 'Aanhef + insight + link', so_instagram: 'Korte hook + CTA · beeld verplicht', so_slack: 'Interne teaser voor het winkelteam',
      soReview: 'Na te lezen', soReviewed: 'Nagelezen', soOff: 'Niet gepubliceerd', soNoteLi: 'Toon: een verkoper die schrijft, geen persbericht.', soNoteIg: 'Eerste regel zichtbaar vóór « meer ». Geen klikbare link: verwijs naar de bio.', soNoteSlack: 'Gepost in #winkels de ochtend van de verzending.',
      soNone: 'Geen doorvertaling',
      settings: 'Instellingen', settingsSub: 'Elke winkel verzendt vanaf zijn eigen adres. Het merk maakt de segmenten en sjablonen; de franchisenemer verzendt, of niet.',
      shop: 'Winkel', shopSender: 'Afzender (naam · adres)', domain: 'Domein', canSendCol: 'Verzending', kindBrand: 'Merk · admin', kindFranchise: 'Franchisenemer',
      canSendOn: 'Toegestaan', canSendOff: 'Geblokkeerd', rights: 'Rechten', roleBrand: 'Weergave: Merk (admin)', roleBrandShort: 'Merk', roleFranchiseShort: 'Franchise', viewShop: 'Weergave: ',
      r_seg: 'Segmenten aanmaken / wijzigen', r_tpl: 'Sjablonen aanmaken / wijzigen', r_auto: 'Automatisering aanmaken', r_send: 'Campagne verzenden (eigen winkel)', r_sender: 'Eigen afzenderadres wijzigen', r_stats: 'Eigen statistieken bekijken',
      rightsNote: 'Een franchisenemer schrijft enkel naar zijn eigen klanten, vanaf het adres van zijn winkel. Blokkeert het merk de verzending, dan behoudt hij leestoegang.',
      sendDisabled: 'Verzending geblokkeerd door het merk', segBrandOnly: 'Enkel het merk kan een segment aanmaken.',
      fAll: 'Alle', fAuto: 'Geautomatiseerd', fManual: 'Manueel', pillAuto: 'Auto', pillManual: 'Manueel',
      sender: 'Afzender', replyTo: 'antwoorden naar', verified: 'Domein geverifieerd', unverified: 'Niet geverifieerd', warmup: 'Opwarming',
      spamCheck: 'Anti-spamcontrole', spamOk: 'Klaar om te vertrekken', spamWarn: 'Te corrigeren vóór verzending',
      ck_auth: 'SPF, DKIM en DMARC geldig op het afzenderdomein', ck_reply: 'Antwoordadres gelezen door een echte persoon', ck_unsub: 'Uitschrijflink in één klik in de voettekst',
      ck_optin: 'Enkel opt-in ontvangers, 1 mail per klant per dag', ck_subject: 'Onderwerp zonder hoofdletters of « GRATIS !!! »', ck_ratio: 'Voldoende tekst t.o.v. beeld (1 visual)', ck_test: 'Test verzonden en weergave gecontroleerd op Gmail, Outlook, Apple Mail',
    },
    en: {
      newCampaign: 'New campaign', campaigns: 'Campaigns', templates: 'Templates', landing: 'Voucher landing',
      campaignsSub: 'Live, scheduled and past sends, all shops.', optinContacts: 'Opt-in contacts', sentThisMonth: 'Sent this month', vouchersUsed: 'Vouchers used', optin: 'opt-in',
      colName: 'Campaign', colDate: 'Send', colSegment: 'Segment', colStatus: 'Status', colOpen: 'Open', colClick: 'Click', colVouchers: 'Vouchers',
      dashboardHint: 'Click a sent campaign to see its review.',
      st_sent: 'Sent', st_live: 'Sending', st_sched: 'Scheduled', st_draft: 'Draft',
      s1: 'Segment', s2: 'Template', s3: 'Copy', s4: 'Send',
      s1Title: 'Who are we writing to?', s1Sub: 'Pick the database, then the segment. The counter only counts opt-in contacts.',
      database: 'Database', segment: 'Segment', recipients: 'recipients', newSegment: 'Create a segment',
      s2Title: 'What kind of email?', s2Sub: 'Five ready-made models, or a blank page.',
      s3Title: 'The copy', s3Sub: 'Short and spoken, like at the counter. One call to action.',
      subject: 'Subject', subjectGood: 'Good length, fits on mobile.', subjectLong: 'Too long for a phone screen: aim under 45 characters.',
      insert: 'Insert…', insFirstname: 'First name', insVoucher: 'Voucher code', insWebshop: 'Webshop link', insShop: 'Shop link',
      words: 'words', oneCta: 'One primary link per email.',
      s4Title: 'When does it go?', s4Sub: 'Sent in batches of 100 per minute. One email per customer per day, max.',
      date: 'Date', time: 'Time', bestTime: 'This segment opens most between 7:30 and 9:00 on weekdays.',
      maxVouchers: 'Max vouchers', optional: 'optional', voucherHintOn: 'unique Stripe vouchers generated at send. First come, first served.', voucherHintOff: 'No voucher in this email.',
      template: 'Template', sendTest: 'Test', testSend: 'Send a test to 3 addresses', testDone: 'Test sent to 3 addresses ✓',
      back: 'Back', next: 'Continue', schedule: 'Schedule send',
      preview: 'Preview', mobile: 'Mobile', subjectPlaceholder: '(email subject)',
      badge3: 'Your 3rd email · loyalty unlocked',
      footWebshop: 'Webshop', footShop: 'Your shop', footSupport: '02 345 67 89', footHours: 'Open Tuesday to Sunday, 7am – 6:30pm',
      unsubscribe: 'Unsubscribe',
      templatesSub: 'Each model: one visual, short copy, one link. The footer is shared.', useTemplate: 'Use',
      duplicateAB: 'Duplicate for A/B test', viewLanding: 'View voucher landing',
      kSent: 'Sent', kOpen: 'Opens', kClick: 'Clicks', kVouchers: 'Vouchers activated', kRevenue: 'Attributed revenue',
      vsPrev: 'vs', funnel: 'Journey', funnelLegend: 'The black tick marks the previous campaign of the same type.',
      landingTitle: 'Voucher landing', landingSub: 'What the customer sees after clicking from the email. Short, unique URL per recipient; the code is also emailed for in-shop use.',
      redirect: 'Redirect', validity: 'Validity', days: 'days', perCustomer: 'per customer',
      knownOn: 'Simulate: known customer', knownOff: 'Simulate: unknown customer', reset: 'Reset',
      landingHeadline: 'You won a diamond tart', landingGain: 'Collect on your next shop visit or on the webshop, within 30 days.',
      landingFormHint: 'Leave your first name and email, we send you the code.', firstname: 'First name', email: 'Email',
      codeSentTo: 'Code sent to', yourCode: 'Your code', copy: 'Copy code', copied: 'Code copied ✓', useWebshop: 'Use on the webshop',
      landingFoot: 'L\'Atelier By · Halle · 02 345 67 89\nValid once, until 24 October 2026.',
      duplicatedName: 'Copy B',
      segName: 'Segment name', segShop: 'Shop', segAllShops: 'All shops', segProduct: 'Product bought', segAnyProduct: 'Any', segPeriod: 'Period', p30: 'Last 30 days', p90: 'Last 90 days', p365: 'Last 12 months', pDormant: 'No purchase in 45 days',
      segMinBasket: 'Min. basket (€)', segEstimated: 'estimated', segSave: 'Create segment', cancel: 'Cancel', segNamePlaceholder: 'e.g. Halle customers who bought a tartine', segRuleShop: 'shop', segRuleProduct: 'product', segRuleBasket: 'basket ≥',
      sendLangs: 'Send languages', sendLangsSub: 'Each customer gets the email in the language on their record. Untick a language to exclude those customers.',
      lang_fr: 'French', lang_nl: 'Dutch', lang_en: 'English', version: 'Version', editLangHint: 'One version per language ticked in step 1.', langNone: 'No language selected',
      channel: 'Channel', chEmail: 'Email', chSms: 'SMS', chBoth: 'Email + SMS', smsOptin: 'SMS opt-in numbers', smsText: 'SMS text', smsHint: 'One SMS = 160 characters. First name and short link filled at send. STOP required.',
      sendMode: 'Send mode', modeManual: 'One-off', modeManualDesc: 'Goes once, on the chosen date.', modeAuto: 'Automated', modeAutoDesc: 'Goes to every customer who meets the rule.',
      trigger: 'Trigger', triggerHint: 'Checked nightly. 1 email per customer per day, never two automations on the same day.',
      tr_dormant45: 'No purchase in 45 days', tr_third: '3rd email received (loyalty tier)', tr_birthday: 'Customer birthday (D-3)', tr_firstOffice: 'First office order delivered (D+2)', tr_season: 'D-7 before a seasonal peak (cougnou, galette)',
      s5: 'Send', soShort: 'Social', soTitle: 'Adapt for social', soSub: 'The email is split into blocks (hook, insight, CTA, image). Three drafts are generated in one click; a person reviews before publishing.',
      soImageNote: 'Used as is on Instagram. LinkedIn and Slack: optional.', soGenerated: 'Drafts generated from the email blocks. To review, never published without approval.', soRegen: 'Regenerate from the email',
      so_linkedin: 'Opener + insight + link', so_instagram: 'Short hook + CTA · image required', so_slack: 'Internal teaser for the shop team',
      soReview: 'To review', soReviewed: 'Reviewed', soOff: 'Not published', soNoteLi: 'Tone: a shop assistant writing, not a press release.', soNoteIg: 'First line shows before "more". No clickable link: point to the bio.', soNoteSlack: 'Posted in #shops the morning of the send.',
      soNone: 'No social adaptation',
      settings: 'Settings', settingsSub: 'Each shop sends from its own address. The brand creates segments and templates; the franchisee sends, or not.',
      shop: 'Shop', shopSender: 'Sender (name · address)', domain: 'Domain', canSendCol: 'Sending', kindBrand: 'Brand · admin', kindFranchise: 'Franchisee',
      canSendOn: 'Allowed', canSendOff: 'Blocked', rights: 'Rights', roleBrand: 'View: Brand (admin)', roleBrandShort: 'Brand', roleFranchiseShort: 'Franchisee', viewShop: 'View: ',
      r_seg: 'Create / edit segments', r_tpl: 'Create / edit templates', r_auto: 'Create an automation', r_send: 'Send a campaign (own shop)', r_sender: 'Edit own sender address', r_stats: 'See own statistics',
      rightsNote: 'A franchisee only writes to their own customers, from their shop address. If the brand blocks sending, they keep read access.',
      sendDisabled: 'Sending blocked by the brand', segBrandOnly: 'Only the brand can create a segment.',
      fAll: 'All', fAuto: 'Automated', fManual: 'Manual', pillAuto: 'Auto', pillManual: 'Manual',
      sender: 'Sender', replyTo: 'replies to', verified: 'Verified domain', unverified: 'Unverified', warmup: 'Warming up',
      spamCheck: 'Anti-spam check', spamOk: 'Ready to send', spamWarn: 'Fix before sending',
      ck_auth: 'SPF, DKIM and DMARC valid on the sender domain', ck_reply: 'Reply address read by a real person', ck_unsub: 'One-click unsubscribe link in the footer',
      ck_optin: 'Opt-in recipients only, 1 email per customer per day', ck_subject: 'Subject without shouting caps or "FREE!!!"', ck_ratio: 'Enough text relative to image (1 visual)', ck_test: 'Test sent and rendering checked on Gmail, Outlook, Apple Mail',
    }
  };
  const TEMPLATES = [
    { id: 'produit', sms: {"fr":"L'Atelier By : le cougnou est sorti du four, {{prénom}}. Jusqu'au 24 déc. Réservez : {{lien}} STOP au 3630","nl":"L'Atelier By: de cougnou is uit de oven, {{prénom}}. Tot 24 dec. Reserveer: {{lien}} STOP naar 3630","en":"L'Atelier By: the cougnou is out of the oven, {{prénom}}. Until 24 Dec. Reserve: {{lien}} STOP to 3630"}, img: 'bread.png', voucher: false, badge: false,
      name: { fr: 'Annonce produit saisonnier', nl: 'Seizoensproduct', en: 'Seasonal product' }, hint: { fr: 'Cougnou, galette…', nl: 'Cougnou, koningentaart…', en: 'Cougnou, galette…' },
      subject: { fr: 'Le cougnou est de retour', nl: 'De cougnou is terug', en: 'The cougnou is back' },
      headline: { fr: 'Le cougnou est sorti du four', nl: 'De cougnou is uit de oven', en: 'The cougnou is out of the oven' },
      body: { fr: 'Bonjour {{prénom}},\n\nOn a lancé la première fournée ce matin. Pâte briochée, sucre perlé, comme chaque décembre. Il y en a jusqu\'au 24.', nl: 'Dag {{prénom}},\n\nDe eerste lading is vanmorgen uit de oven gekomen. Briochedeeg, parelsuiker, zoals elke december. Tot de 24e.', en: 'Hello {{prénom}},\n\nFirst batch came out this morning. Brioche dough, pearl sugar, same as every December. Available until the 24th.' },
      cta: { fr: 'Réserver le mien', nl: 'De mijne reserveren', en: 'Reserve mine' } },
    { id: 'happy', sms: {"fr":"L'Atelier By : -30 % sur les viennoiseries après 17h, tous les jours sauf dimanche. Dites-le en caisse. STOP au 3630","nl":"L'Atelier By: -30% op koffiekoeken na 17u, elke dag behalve zondag. Zeg het aan de kassa. STOP naar 3630","en":"L'Atelier By: -30% on pastries after 5pm, every day but Sunday. Just say so at the till. STOP to 3630"}, img: 'croissant.png', voucher: false, badge: false,
      name: { fr: 'Happy hour', nl: 'Happy hour', en: 'Happy hour' }, hint: { fr: 'Fin de journée, -30 %', nl: 'Einde dag, -30%', en: 'End of day, -30%' },
      subject: { fr: 'Après 17h, c\'est moins cher', nl: 'Na 17u is het goedkoper', en: 'After 5pm, it\'s cheaper' },
      headline: { fr: '-30 % sur les viennoiseries après 17h', nl: '-30% op koffiekoeken na 17u', en: '-30% on pastries after 5pm' },
      body: { fr: 'Bonjour {{prénom}},\n\nCe qui reste en vitrine à 17h passe à -30 %. Tous les jours, sauf le dimanche. Pas besoin de code, dites-le en caisse.', nl: 'Dag {{prénom}},\n\nWat om 17u nog in de toog ligt, gaat naar -30%. Elke dag behalve zondag. Geen code nodig, zeg het aan de kassa.', en: 'Hello {{prénom}},\n\nWhatever is left in the window at 5pm goes to -30%. Every day except Sunday. No code needed, just say so at the till.' },
      cta: { fr: 'Voir les horaires', nl: 'Openingsuren bekijken', en: 'See opening hours' } },
    { id: 'fidelite', sms: {"fr":"{{prénom}}, une part de gâteau vous attend chez L'Atelier By avec le code {{code}}. Valable 30 jours. STOP au 3630","nl":"{{prénom}}, een stuk taart wacht op u bij L'Atelier By met de code {{code}}. 30 dagen geldig. STOP naar 3630","en":"{{prénom}}, a slice of cake is waiting at L'Atelier By with code {{code}}. Valid 30 days. STOP to 3630"}, img: 'cake.png', voucher: true, badge: true,
      name: { fr: 'Fidélité progressive', nl: 'Progressieve trouw', en: 'Progressive loyalty' }, hint: { fr: '3e mail = palier', nl: '3e mail = niveau', en: '3rd email = tier' },
      subject: { fr: 'Votre troisième mail, votre première part', nl: 'Uw derde mail, uw eerste stuk', en: 'Your third email, your first slice' },
      headline: { fr: 'Une part de gâteau offerte', nl: 'Een stuk taart cadeau', en: 'A slice of cake, on us' },
      body: { fr: 'Bonjour {{prénom}},\n\nC\'est le troisième mail que vous recevez de nous, et vous les ouvrez tous. Merci. Une part de gâteau vous attend avec le code {{code_promo}}.', nl: 'Dag {{prénom}},\n\nDit is de derde mail die u van ons krijgt, en u opent ze allemaal. Bedankt. Een stuk taart wacht op u met de code {{code_promo}}.', en: 'Hello {{prénom}},\n\nThis is the third email you get from us, and you open every one. Thank you. A slice of cake is waiting with code {{code_promo}}.' },
      cta: { fr: 'Récupérer ma part', nl: 'Mijn stuk ophalen', en: 'Claim my slice' } },
    { id: 'gagne', sms: {"fr":"{{prénom}}, vous avez gagné une tarte diamant chez L'Atelier By Halle. Votre code : {{lien}} STOP au 3630","nl":"{{prénom}}, u hebt een diamanttaart gewonnen bij L'Atelier By Halle. Uw code: {{lien}} STOP naar 3630","en":"{{prénom}}, you won a diamond tart at L'Atelier By Halle. Your code: {{lien}} STOP to 3630"}, img: 'tart.png', voucher: true, badge: false,
      name: { fr: 'Vous avez gagné', nl: 'U hebt gewonnen', en: 'You won' }, hint: { fr: '30 vouchers, premiers servis', nl: '30 vouchers, eerst komt', en: '30 vouchers, first served' },
      subject: { fr: 'Vous avez une tarte qui vous attend', nl: 'Er wacht een taart op u', en: 'There\'s a tart waiting for you' },
      headline: { fr: 'Une tarte diamant, pour vous', nl: 'Een diamanttaart, voor u', en: 'A diamond tart, for you' },
      body: { fr: 'Bonjour {{prénom}},\n\nOn a mis 30 tartes diamant de côté pour nos clients de Halle. La vôtre est réservée à votre nom pendant 30 jours. Un clic, et le code est à vous.', nl: 'Dag {{prénom}},\n\nWe hebben 30 diamanttaarten opzijgezet voor onze klanten in Halle. De uwe staat 30 dagen op uw naam. Eén klik, en de code is van u.', en: 'Hello {{prénom}},\n\nWe set aside 30 diamond tarts for our Halle customers. Yours is reserved in your name for 30 days. One click and the code is yours.' },
      cta: { fr: 'Je prends la mienne', nl: 'Ik neem de mijne', en: 'I\'ll take mine' } },
    { id: 'office', sms: {"fr":"L'Atelier By : plateau sandwiches livré au bureau vendredi 11h30. Commande jusqu'à jeudi 16h : {{lien}} STOP au 3630","nl":"L'Atelier By: sandwichschotel geleverd op kantoor vrijdag 11u30. Bestellen tot donderdag 16u: {{lien}} STOP naar 3630","en":"L'Atelier By: sandwich platter delivered to the office Friday 11:30. Order by Thursday 4pm: {{lien}} STOP to 3630"}, img: 'sandwiches.png', voucher: false, badge: false,
      name: { fr: 'Plateau bureau', nl: 'Kantoorschotel', en: 'Office platter' }, hint: { fr: 'Office & B2B', nl: 'Office & B2B', en: 'Office & B2B' },
      subject: { fr: 'Le plateau du vendredi, livré à 11h30', nl: 'De vrijdagschotel, geleverd om 11u30', en: 'The Friday platter, delivered 11:30' },
      headline: { fr: 'Le vendredi, on livre le plateau', nl: 'Op vrijdag leveren we de schotel', en: 'On Fridays, we deliver the platter' },
      body: { fr: 'Bonjour {{prénom}},\n\nPlateau de sandwiches pour 8 à 12 personnes, livré au bureau avant midi. Commande jusqu\'au jeudi 16h.', nl: 'Dag {{prénom}},\n\nSandwichschotel voor 8 tot 12 personen, geleverd op kantoor vóór de middag. Bestellen tot donderdag 16u.', en: 'Hello {{prénom}},\n\nSandwich platter for 8 to 12 people, delivered to the office before noon. Order by Thursday 4pm.' },
      cta: { fr: 'Commander pour vendredi', nl: 'Bestellen voor vrijdag', en: 'Order for Friday' } },
    { id: 'blank', sms: {"fr":"L'Atelier By : {{prénom}}, … {{lien}} STOP au 3630","nl":"L'Atelier By: {{prénom}}, … {{lien}} STOP naar 3630","en":"L'Atelier By: {{prénom}}, … {{lien}} STOP to 3630"}, img: '', voucher: false, badge: false, blank: true,
      name: { fr: 'Page blanche', nl: 'Leeg blad', en: 'Blank page' }, hint: { fr: 'Header et footer seuls', nl: 'Enkel header en footer', en: 'Header and footer only' },
      subject: { fr: '', nl: '', en: '' }, headline: { fr: '', nl: '', en: '' }, body: { fr: 'Bonjour {{prénom}},\n\n', nl: 'Dag {{prénom}},\n\n', en: 'Hello {{prénom}},\n\n' }, cta: { fr: 'Voir', nl: 'Bekijken', en: 'View' } },
  ];

  /* --- styles : ce que l'inline ne sait pas faire (réactif, survol) --------- */
  const CSS = `
.nl{font-family:var(--font-ui);color:var(--color-text);font-size:14px;line-height:1.5}
.nl input,.nl select,.nl textarea{outline:none;box-sizing:border-box;color:var(--color-text)}
.nl input:focus,.nl select:focus,.nl textarea:focus{border-color:var(--color-primary)!important}
.nl a{color:var(--color-primary)}
.nl-top{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:12px 20px;background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;flex-wrap:wrap}
.nl-body{max-width:1180px;margin:0 auto;padding:22px 0 40px}
.nl-row:hover{background:var(--color-background-secondary)}
.nl-wiz{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(300px,0.8fr);gap:28px;align-items:start}
.nl-set{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(280px,0.8fr);gap:24px;align-items:start}
.nl-sticky{position:sticky;top:24px}
.nl-tbl{display:grid;grid-template-columns:minmax(180px,2.2fr) minmax(120px,1.4fr) minmax(150px,1.8fr) minmax(100px,1fr) minmax(90px,0.9fr) minmax(90px,0.9fr) minmax(130px,1.3fr);gap:16px;align-items:center;padding:14px 20px;border-bottom:0.5px solid var(--color-border-tertiary)}
.nl-tbl.h{padding:10px 20px;font-size:11px;text-transform:uppercase;letter-spacing:0.08em;color:var(--color-text-muted)}
.nl-shop{display:grid;grid-template-columns:minmax(110px,1fr) minmax(200px,2fr) minmax(120px,1fr) 110px;gap:16px;align-items:center;padding:12px 20px;border-bottom:0.5px solid var(--color-border-tertiary)}
.nl-shop.h{padding:10px 20px;font-size:11px;text-transform:uppercase;letter-spacing:0.08em;color:var(--color-text-muted)}
.nl-scroll{overflow-x:auto}
.nl-scroll>div{min-width:860px}
.nl-2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.nl-tb button:hover{background:var(--color-surface)}
@media (max-width:900px){.nl-wiz,.nl-set{grid-template-columns:1fr}.nl-sticky{position:static}.nl-body{padding-top:14px}}
@media (max-width:560px){.nl-top{padding:10px 12px}.nl-2{grid-template-columns:1fr}}
`;
  function styles() { if (document.getElementById('nl-css')) return; const st = document.createElement('style'); st.id = 'nl-css'; st.textContent = CSS; document.head.appendChild(st); }

  /* --- accès API ------------------------------------------------------------ */
  function lire(base, path) {
    return fetch(base + path, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(r => r.ok ? r.json() : r.json().then(j => Promise.reject(new Error((j && j.error) || ('HTTP ' + r.status))), () => Promise.reject(new Error('HTTP ' + r.status))));
  }
  function ecrire(base, method, path, body) {
    return fetch(base + path, { method, credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: body === undefined ? undefined : JSON.stringify(body) })
      .then(r => r.json().catch(() => ({})).then(j => r.ok ? j : Promise.reject(new Error((j && j.error) || ('HTTP ' + r.status)))));
  }

  const ETAT0 = () => ({
    step: 1, sourceId: 'indiv', segmentId: '', templateId: 'gagne', subjects: {}, bodies: {}, smsTexts: {}, sendLangs: ['fr', 'nl'], editLang: 'fr',
    date: '', time: '08:30', maxVouchers: '30', testSent: false, senderId: '',
    segForm: false, segName: '', segShop: 'all', segProduct: 'all', segPeriod: '365', segMinBasket: '',
    channel: 'email', socialOn: { linkedin: true, instagram: true, slack: true }, socialTexts: {}, socialReviewed: {}, sendMode: 'manual', trigger: 'dormant45',
  });

  class NL {
    constructor(host, opts) {
      this.host = host;
      this.opts = Object.assign({ role: 'brand', imgDir: 'assets/img/newsletter/' }, opts || {});
      this.base = this.opts.apiBase || ((window.COCKPIT_API_BASE) || (location.pathname.replace(/[^/]*$/, '') + 'api/cockpit'));
      this.el = document.createElement('div'); this.el.className = 'nl';
      this.s = Object.assign({ view: 'dashboard', lang: 'fr', modeFilter: 'all', retroId: null, data: null, err: '', busy: false }, ETAT0());
      const d = new Date(); d.setDate(d.getDate() + 5); this.s.date = d.toISOString().slice(0, 10);
      try { const saved = JSON.parse(localStorage.getItem('fb-newsletter-state') || '{}'); if (LANG_KEYS.includes(saved.lang)) this.s.lang = saved.lang; } catch (e) { /* rien */ }
      this.el.addEventListener('click', e => this.onClick(e));
      this.el.addEventListener('input', e => this.onInput(e));
      this.el.addEventListener('change', e => this.onChange(e));
    }
    role() { return String(this.opts.role || 'brand'); }
    isBrand() { return this.role() === 'brand'; }
    setRole(role) { if (String(role) !== this.role()) { this.opts.role = String(role); this.s.data = null; this.s.err = ''; Object.assign(this.s, ETAT0(), { view: 'dashboard' }); this.charger(); } }
    mount(host) {
      if (host && this.host !== host) this.host = host;
      if (this.host && this.el.parentNode !== this.host) this.host.appendChild(this.el);
      if (!this.s.data && !this.s.chargement) this.charger();
      this.render();
    }
    charger() {
      const s = this.s; s.chargement = true; s.err = '';
      return lire(this.base, '/newsletter?role=' + encodeURIComponent(this.role())).then(d => {
        s.data = d || {}; s.chargement = false;
        const segs = this.segmentsVisibles();
        if (!segs.find(x => x.id === s.segmentId)) { const first = segs.find(x => x.src === s.sourceId) || segs[0]; if (first) { s.segmentId = first.id; s.sourceId = first.src; } }
        if (!s.senderId) s.senderId = this.isBrand() ? 'brand' : this.role();
      }).catch(e => { s.err = e.message; s.chargement = false; }).finally(() => this.render());
    }
    persist() { try { localStorage.setItem('fb-newsletter-state', JSON.stringify({ lang: this.s.lang })); } catch (e) { /* rien */ } }
    notify(msg) { if (this.opts.notify) { this.opts.notify(msg); } else { this.s.toast = msg; clearTimeout(this._tt); this._tt = setTimeout(() => { this.s.toast = null; this.render(); }, 3600); } }

    /* --- lectures dérivées --------------------------------------------------- */
    t() { return I18N[this.s.lang] || I18N.fr; }
    L(o) { return (o && typeof o === 'object') ? (o[this.s.lang] ?? o.fr ?? '') : (o == null ? '' : o); }
    data() { return this.s.data || { magasins: [], sources: [], segments: [], campagnes: [], chiffres: { optin: 0, envoisMois: 0, vouchers: [0, 0] } }; }
    magasins() { return this.data().magasins || []; }
    moi() { return this.magasins().find(m => m.id === this.role()) || null; }
    nomShop(id) { const m = this.magasins().find(x => x.id === id); return m ? m.nom : (id === 'brand' ? "L'Atelier By" : ('Magasin ' + id)); }
    canSend() { return this.isBrand() || !!(this.data().canSend); }
    segmentsVisibles() {
      const segs = this.data().segments || [];
      return this.isBrand() ? segs : segs.filter(x => x.shop === '' || x.shop === this.role());
    }
    segById(id) { return (this.data().segments || []).find(x => x.id === id) || this.segmentsVisibles()[0] || { id: '', src: 'indiv', shop: '', name: '', rule: '', count: 0, split: [0, 0, 0] }; }
    tpl() { return TEMPLATES.find(x => x.id === this.s.templateId) || TEMPLATES[3]; }
    editLang() { const s = this.s; return s.sendLangs.includes(s.editLang) ? s.editLang : (s.sendLangs[0] || 'fr'); }
    LL(o) { const l = this.editLang(); return (o && typeof o === 'object') ? (o[l] ?? o.fr ?? '') : (o == null ? '' : o); }
    subject() { return this.s.subjects[this.editLang()] ?? this.LL(this.tpl().subject); }
    body() { return this.s.bodies[this.editLang()] ?? this.LL(this.tpl().body); }
    sms() { const tp = this.tpl(); return this.s.smsTexts[this.editLang()] ?? (tp.sms[this.editLang()] ?? tp.sms.fr); }
    img(t) { return t.img ? this.opts.imgDir + t.img : ''; }
    senders() {
      const ms = this.magasins();
      const liste = this.isBrand() ? ms : ms.filter(x => x.id === this.role());
      return liste.map(x => ({ id: x.id, name: x.senderName, email: x.email || '(adresse à compléter)', reply: x.email || '—', status: x.status }));
    }
    sender() { const S = this.senders(); return S.find(x => x.id === this.s.senderId) || S[0] || { id: '', name: '', email: '', reply: '', status: 'unverified' }; }
    langTotal() { const seg = this.segById(this.s.segmentId); return LANG_KEYS.reduce((a, k, i) => a + (this.s.sendLangs.includes(k) ? (seg.split[i] || 0) : 0), 0); }
    fDate(sendAt, mode, trig) {
      const t = this.t();
      if (!sendAt) return '—';
      const d = String(sendAt);
      const jour = parseInt(d.slice(8, 10), 10) + ' ' + MOIS[parseInt(d.slice(5, 7), 10) - 1] + ' ' + d.slice(0, 4);
      return jour + (d.length > 10 && mode !== 'auto' ? ' · ' + d.slice(11, 16) : '');
    }
    stats(c) { const st = c.stats || {}; const sent = +st.sent || 0; return { sent, open: +st.open || 0, click: +st.click || 0, vouchers: +st.vouchers || 0, revenue: +st.revenue || 0, openPct: sent ? Math.round(100 * st.open / sent) : null, clickPct: sent ? Math.round(100 * st.click / sent) : null }; }
    blocks() {
      const body = this.body().replace(/\{\{prénom\}\}/g, 'Marie').replace(/\{\{code_promo\}\}/g, 'K7M2DIAM').replace(/\{\{lien_webshop\}\}/g, 'latelier.by/shop').replace(/\{\{lien_boutique\}\}/g, 'latelier.by/boutique');
      const paras = body.split(/\n\s*\n/).map(x => x.trim()).filter(Boolean);
      const insight = (paras.find(p => !/^(bonjour|dag|hello)/i.test(p)) || paras[0] || '').replace(/\s+/g, ' ');
      const tp = this.tpl();
      return { hook: this.subject(), insight, cta: this.LL(tp.cta), link: 'latelier.by/' + (tp.voucher ? 'v/K7M2DIAM' : 'shop'), previewBody: body };
    }
    gen() {
      const s = this.s, b = this.blocks(), seg = this.segById(s.segmentId), isAuto = s.sendMode === 'auto';
      const shopTag = (seg.shop ? this.nomShop(seg.shop) : 'reseau').toLowerCase().replace(/[^a-z0-9]+/g, '');
      return {
        linkedin: `${b.hook}.\n\n${b.insight}\n\n${b.cta} → ${b.link}`,
        instagram: `${b.hook} ✦\n${b.cta.replace(/^(Je |Ik |I'll |I )/, '')} — lien en bio.\n#latelierby #${shopTag} #boulangerie`,
        slack: `📣 Newsletter « ${b.hook} » part ${isAuto ? 'en automatique' : 'le ' + s.date.split('-').reverse().join('/') + ' à ' + s.time} vers ${fmt(this.langTotal())} clients (${this.L(seg.name)}). Offre : ${b.insight.slice(0, 110)}${b.insight.length > 110 ? '…' : ''}`,
      };
    }
    checks() {
      const t = this.t(), s = this.s, subject = this.subject(), body = this.body(), sd = this.sender();
      const shouty = /[A-Z]{4,}|!!/.test(subject) || /gratuit|free|gratis/i.test(subject);
      return [[t.ck_auth, sd.status === 'verified'], [t.ck_reply, sd.status !== 'unverified'], [t.ck_unsub, true], [t.ck_optin, true],
        [t.ck_subject, !shouty && subject.length <= 45], [t.ck_ratio, s.channel === 'sms' || body.trim().split(/\s+/).length >= 20], [t.ck_test, s.testSent]];
    }

    /* --- rendu --------------------------------------------------------------- */
    render() {
      const foc = document.activeElement, focK = foc && this.el.contains(foc) ? foc.getAttribute('data-f') : null;
      const sel = focK && foc.selectionStart != null ? [foc.selectionStart, foc.selectionEnd] : null;
      const sc = this.el.querySelector('.nl-cand'); const scT = sc ? sc.scrollTop : 0;
      this.el.innerHTML = this.html();
      if (focK) { const e = this.el.querySelector('[data-f="' + focK + '"]'); if (e) { e.focus(); if (sel && e.setSelectionRange) { try { e.setSelectionRange(sel[0], sel[1]); } catch (x) { /* type sans sélection */ } } } }
      if (scT) { const s2 = this.el.querySelector('.nl-cand'); if (s2) s2.scrollTop = scT; }
    }
    pill(label, bg, color, border) { return `<span style="display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:500;padding:4px 10px;border-radius:999px;white-space:nowrap;background:${bg};color:${color};border:0.5px solid ${border}">${esc(label)}</span>`; }
    statusPill(status) {
      const t = this.t();
      const S = { sent: [t.st_sent, BG2, 'var(--color-text)', 'var(--color-border-tertiary)'], live: [t.st_live, SEC, ON, SEC], sched: [t.st_sched, 'transparent', P, P], draft: [t.st_draft, 'transparent', MUTED, 'var(--color-border-secondary)'] }[status] || [status, 'transparent', MUTED, 'var(--color-border-secondary)'];
      return this.pill(S[0], S[1], S[2], S[3]);
    }
    domainPill(status) {
      const t = this.t();
      const S = status === 'verified' ? [t.verified, BG2, GREEN, 'var(--color-border-tertiary)'] : status === 'warmup' ? [t.warmup, SEC, ON, SEC] : [t.unverified, 'transparent', P, P];
      return this.pill(S[0], S[1], S[2], S[3]);
    }
    cap(txt, extra) { return `<div style="font-size:11px;text-transform:uppercase;letter-spacing:0.08em;color:${MUTED};${extra || ''}">${txt}</div>`; }
    btnP(a, label, v, extra) { return `<button data-a="${a}"${v != null ? ` data-v="${esc(v)}"` : ''} style="background:${P};color:#fff;border:none;border-radius:8px;padding:9px 16px;font-family:var(--font-ui);font-size:13px;font-weight:500;cursor:pointer;${extra || ''}">${label}</button>`; }
    btnS(a, label, v, extra) { return `<button data-a="${a}"${v != null ? ` data-v="${esc(v)}"` : ''} style="background:none;border:0.5px solid var(--color-border-secondary);color:var(--color-text);border-radius:8px;padding:9px 14px;font-family:var(--font-ui);font-size:13px;font-weight:500;cursor:pointer;${extra || ''}">${label}</button>`; }
    link(a, label, v, extra) { return `<button data-a="${a}"${v != null ? ` data-v="${esc(v)}"` : ''} style="background:none;border:none;padding:0;font-family:var(--font-ui);font-size:13px;color:${P};cursor:pointer;${extra || ''}">${label}</button>`; }
    input(f, value, extra, attrs) { return `<input data-f="${f}" value="${esc(value)}" ${attrs || ''} style="border:0.5px solid var(--color-border-secondary);border-radius:8px;padding:10px 12px;font-size:14px;font-family:var(--font-ui);background:${SURF};width:100%;${extra || ''}">`; }
    html() {
      const s = this.s, t = this.t(), d = this.data();
      if (s.err && !s.data) return `<div style="padding:16px;border-radius:9px;background:#F6E4E7;border:1px solid #e8b4bb;color:${P};font-size:12px">Newsletter : ${esc(s.err)}</div>`;
      if (!s.data) return `<div style="padding:60px 0;color:${MUTED};font-size:13px;text-align:center">Lecture des campagnes…</div>`;
      const view = s.view, isBrand = this.isBrand();
      const tabs = [['dashboard', t.campaigns], ['templates', t.templates], ...(isBrand ? [['settings', t.settings]] : [['settings', t.sender]])];
      const tabOn = id => view === id || (id === 'dashboard' && (view === 'retro' || view === 'wizard'));
      const canSend = this.canSend();
      return `
      <div class="nl-top">
        <div style="display:flex;align-items:center;gap:20px;flex-wrap:wrap">
          <div style="display:flex;align-items:baseline;gap:10px">
            <span style="font-family:var(--font-display);font-size:16px;color:${P}">L'Atelier By</span>
            <span style="font-size:11px;text-transform:uppercase;letter-spacing:0.08em;color:${MUTED}">Newsletter · ${isBrand ? esc(t.roleBrandShort) : esc(this.nomShop(this.role()))}</span>
          </div>
          <div style="display:flex;gap:4px">${tabs.map(([id, label]) => `<button data-a="view" data-v="${id}" style="border:none;background:${tabOn(id) ? BG2 : 'transparent'};color:${tabOn(id) ? P : 'var(--color-text)'};font-family:var(--font-ui);font-size:13px;font-weight:500;padding:7px 12px;border-radius:999px;cursor:pointer">${esc(label)}</button>`).join('')}</div>
        </div>
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
          <span style="font-size:12px;color:${MUTED};border:0.5px solid var(--color-border-secondary);border-radius:999px;padding:5px 10px">${isBrand ? esc(t.roleBrand) : esc(t.viewShop + this.nomShop(this.role()))}</span>
          <div style="display:flex;gap:2px;border:0.5px solid var(--color-border-secondary);border-radius:999px;padding:2px">${LANG_KEYS.map(k => `<button data-a="lang" data-v="${k}" style="border:none;background:${s.lang === k ? P : 'transparent'};color:${s.lang === k ? '#fff' : MUTED};font-family:var(--font-ui);font-size:11px;font-weight:500;padding:4px 9px;border-radius:999px;cursor:pointer;letter-spacing:0.04em">${k.toUpperCase()}</button>`).join('')}</div>
          ${canSend ? this.btnP('wizard', esc(t.newCampaign)) : `<span style="font-size:12px;color:${MUTED};border:0.5px solid var(--color-border-secondary);border-radius:8px;padding:8px 12px">${esc(t.sendDisabled)}</span>`}
        </div>
      </div>
      ${d.test ? `<div style="margin-top:8px;font-size:11.5px;color:${ON};background:${SEC};border-radius:8px;padding:6px 12px;display:inline-block">Mode test — aucun envoi ne part ; les bases et leurs comptes sont un jeu d’essai tant que les vraies bases clients ne sont pas raccordées.</div>` : ''}
      ${s.toast ? `<div style="position:fixed;bottom:22px;left:50%;transform:translateX(-50%);background:var(--color-text);color:#fff;border-radius:999px;padding:9px 16px;font-size:12.5px;z-index:50">${esc(s.toast)}</div>` : ''}
      <div class="nl-body">
        ${view === 'dashboard' ? this.vDashboard() : view === 'wizard' ? this.vWizard() : view === 'templates' ? this.vTemplates() : view === 'retro' ? this.vRetro() : this.vSettings()}
      </div>`;
    }

    /* --- 1. tableau de bord --------------------------------------------------- */
    vDashboard() {
      const s = this.s, t = this.t(), d = this.data(), ch = d.chiffres || {};
      const camps = d.campagnes || [];
      const filtres = [['all', t.fAll, camps.length], ['auto', t.fAuto, camps.filter(c => c.sendMode === 'auto').length], ['manual', t.fManual, camps.filter(c => c.sendMode !== 'auto').length]];
      const rows = camps.filter(c => s.modeFilter === 'all' || (s.modeFilter === 'auto' ? c.sendMode === 'auto' : c.sendMode !== 'auto'));
      const kpi = (lab, val, extra) => `<div>${this.cap(esc(lab))}<div style="font-size:22px;font-weight:300;margin-top:2px;${extra || ''}">${val}</div></div>`;
      const v = ch.vouchers || [0, 0];
      return `
      <div style="display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:24px">
        <div><h1 style="font-family:var(--font-display);font-size:32px;font-weight:400;line-height:1.2;margin:0">${esc(t.campaigns)}</h1><p style="margin:6px 0 0;color:${MUTED}">${esc(t.campaignsSub)}</p></div>
        <div style="display:flex;gap:28px">${kpi(t.optinContacts, fmt(ch.optin))}${kpi(t.sentThisMonth, fmt(ch.envoisMois))}${kpi(t.vouchersUsed, `${fmt(v[0])} <span style="font-size:13px;color:${MUTED}">/ ${fmt(v[1])}</span>`, `color:${P}`)}</div>
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:20px">${(d.sources || []).map(src => `
        <div style="background:${SURF};border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:14px 16px;display:flex;justify-content:space-between;align-items:center;gap:12px">
          <div><div style="font-size:13px;font-weight:500">${esc(this.L(src.name))}</div><div style="font-size:11px;color:${MUTED};margin-top:1px">${esc(src.table)}</div></div>
          <div style="text-align:right"><div style="font-size:18px;font-weight:300">${fmt(src.count)}</div><div style="font-size:11px;color:${MUTED}">${fmt(src.optin)} ${esc(t.optin)}</div></div>
        </div>`).join('')}</div>
      <div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap">${filtres.map(([id, label, n]) => { const on = s.modeFilter === id; return `<button data-a="filtre" data-v="${id}" style="display:inline-flex;align-items:center;gap:8px;border-radius:999px;padding:6px 14px;font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:pointer;background:${on ? P : 'transparent'};color:${on ? '#fff' : 'var(--color-text)'};border:0.5px solid ${on ? P : 'var(--color-border-secondary)'}">${esc(label)} <span style="font-weight:400;opacity:0.7">${n}</span></button>`; }).join('')}</div>
      <div class="nl-scroll" style="background:${SURF};border:0.5px solid var(--color-border-tertiary);border-radius:12px"><div>
        <div class="nl-tbl h"><div>${esc(t.colName)}</div><div>${esc(t.colDate)}</div><div>${esc(t.colSegment)}</div><div>${esc(t.colStatus)}</div><div>${esc(t.colOpen)}</div><div>${esc(t.colClick)}</div><div>${esc(t.colVouchers)}</div></div>
        ${rows.length ? rows.map(c => this.rowCampagne(c)).join('') : `<div style="padding:22px 20px;font-size:13px;color:${MUTED}">Aucune campagne.</div>`}
      </div></div>
      <p style="margin:14px 4px 0;font-size:12px;color:${MUTED}">${esc(t.dashboardHint)}</p>`;
    }
    rowCampagne(c) {
      const t = this.t(), st = this.stats(c), seg = this.segById(c.segment);
      const pills = [];
      const pillM = (label, bg, color, border) => `<span style="display:inline-flex;align-items:center;gap:5px;font-size:10px;font-weight:500;letter-spacing:0.04em;padding:2px 8px;border-radius:999px;background:${bg};color:${color};border:0.5px solid ${border}">${esc(label)}</span>`;
      if (c.channel === 'email' || c.channel === 'both') pills.push(pillM(t.chEmail, 'transparent', MUTED, 'var(--color-border-secondary)'));
      if (c.channel === 'sms' || c.channel === 'both') pills.push(pillM('SMS', 'transparent', MUTED, 'var(--color-border-secondary)'));
      const soc = Object.entries(c.social || {}).filter(e => e[1] && e[1].on).map(e => ({ linkedin: 'LinkedIn', instagram: 'Instagram', slack: 'Slack' })[e[0]] || e[0]);
      if (soc.length) pills.push(pillM(soc.join(' · '), 'transparent', MUTED, 'var(--color-border-secondary)'));
      pills.push(c.sendMode === 'auto' ? pillM(`${t.pillAuto} · ${t['tr_' + c.trigger] || c.trigger}`, SEC, ON, SEC) : pillM(t.pillManual, BG2, MUTED, 'var(--color-border-tertiary)'));
      const retro = c.status === 'sent' && st.sent > 0;
      const peutRetirer = c.status !== 'sent' && (this.isBrand() || c.createdBy === this.role());
      const subject = (c.subjects && (c.subjects.fr || c.subjects[Object.keys(c.subjects)[0]])) || '';
      return `
        <div class="nl-row nl-tbl" ${retro ? `data-a="retro" data-v="${c.id}"` : ''} style="cursor:${retro ? 'pointer' : 'default'}">
          <div><div style="font-weight:500">${esc(c.name)}${c.exemple ? ` <span title="jeu d’essai" style="font-size:10px;color:${MUTED};font-weight:400">· essai</span>` : ''}</div><div style="font-size:12px;color:${MUTED};margin-top:1px">${esc(subject)}</div>
            <div style="display:flex;gap:6px;margin-top:6px;flex-wrap:wrap;align-items:center">${pills.join('')}${peutRetirer ? `<button data-a="retirer" data-v="${c.id}" title="Retirer" style="border:none;background:none;color:${MUTED};font-size:11px;cursor:pointer;padding:0 4px;font-family:var(--font-ui)">retirer ×</button>` : ''}</div></div>
          <div style="font-size:13px">${esc(this.fDate(c.sendAt, c.sendMode))}</div>
          <div style="font-size:13px;color:${MUTED}">${esc(this.L(seg.name))}</div>
          <div>${this.statusPill(c.status)}</div>
          <div style="font-size:13px">${st.openPct != null ? st.openPct + ' %' : '—'}</div>
          <div style="font-size:13px">${st.clickPct != null ? st.clickPct + ' %' : '—'}</div>
          <div>${c.maxVouchers ? `<div style="font-size:12px;margin-bottom:4px">${st.vouchers} / ${c.maxVouchers}</div><div style="height:4px;border-radius:999px;background:${BG2};overflow:hidden"><div style="height:100%;width:${Math.min(100, Math.round(100 * st.vouchers / c.maxVouchers))}%;background:${P}"></div></div>` : `<span style="color:${MUTED}">—</span>`}</div>
        </div>`;
    }

    /* --- 2. assistant nouvelle campagne -------------------------------------- */
    vWizard() {
      const s = this.s, t = this.t();
      const steps = [t.s1, t.s2, t.s3, t.soShort, t.s5].map((label, i) => { const n = i + 1, active = n === s.step, done = n < s.step; return `<div style="display:flex;align-items:center;gap:8px"><span style="width:24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:11px;font-weight:500;background:${active ? SEC : done ? P : BG2};color:${active ? ON : done ? '#fff' : MUTED}">${n}</span><span style="font-size:13px;color:${active ? 'var(--color-text)' : MUTED}">${esc(label)}</span></div>`; }).join('');
      const corps = [this.step1, this.step2, this.step3, this.step4, this.step5][s.step - 1].call(this);
      return `
      <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:28px">
        <h1 style="font-family:var(--font-display);font-size:32px;font-weight:400;line-height:1.2;margin:0">${esc(t.newCampaign)}</h1>
        <div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap">${steps}</div>
      </div>
      <div class="nl-wiz">
        <div style="background:${SURF};border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:28px;display:flex;flex-direction:column;gap:22px;min-height:420px">
          ${corps}
          <div style="display:flex;justify-content:space-between;align-items:center;margin-top:auto;padding-top:8px">
            ${this.btnS('prev', esc(t.back), null, 'padding:10px 16px;visibility:' + (s.step === 1 ? 'hidden' : 'visible'))}
            ${this.btnP('next', esc(s.step === 5 ? t.schedule : t.next), null, 'padding:10px 18px;' + (s.busy ? 'opacity:.6' : ''))}
          </div>
        </div>
        ${this.preview()}
      </div>`;
    }
    h2(titre, sous) { return `<div><h2 style="font-family:var(--font-display);font-size:20px;font-weight:400;margin:0 0 4px">${esc(titre)}</h2><p style="margin:0;color:${MUTED}">${esc(sous)}</p></div>`; }
    step1() {
      const s = this.s, t = this.t(), d = this.data();
      const segs = this.segmentsVisibles();
      const chips = (d.sources || []).map(x => { const on = x.id === s.sourceId; return `<button data-a="source" data-v="${esc(x.id)}" style="display:inline-flex;align-items:center;gap:8px;border-radius:999px;padding:7px 14px;font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:pointer;background:${on ? P : 'transparent'};color:${on ? '#fff' : 'var(--color-text)'};border:0.5px solid ${on ? P : 'var(--color-border-secondary)'}">${esc(this.L(x.name))} <span style="font-weight:400;opacity:0.75">${fmt(x.optin)}</span></button>`; }).join('');
      const rows = segs.filter(x => x.src === s.sourceId).map(x => { const on = x.id === s.segmentId; return `<button data-a="segment" data-v="${esc(x.id)}" style="display:flex;align-items:center;justify-content:space-between;gap:16px;text-align:left;background:${on ? BG2 : SURF};border:${on ? `1.5px solid ${P}` : '0.5px solid var(--color-border-tertiary)'};border-radius:8px;padding:12px 16px;cursor:pointer;font-family:var(--font-ui)"><span><span style="display:block;font-size:14px;font-weight:500;color:var(--color-text)">${esc(this.L(x.name))}</span><span style="display:block;font-size:12px;color:${MUTED};margin-top:1px">${esc(this.L(x.rule))}</span></span><span style="font-size:14px;font-weight:500;color:${P};white-space:nowrap">${fmt(x.count)} <span style="font-weight:400;color:${MUTED};font-size:12px">${esc(t.recipients)}</span></span></button>`; }).join('');
      const seg = this.segById(s.segmentId), total = seg.split.reduce((a, b) => a + b, 0) || 1;
      const langRows = LANG_KEYS.map((k, i) => { const on = s.sendLangs.includes(k), n = seg.split[i] || 0, pct = Math.round(100 * n / total) + '%'; return `<button data-a="lang-toggle" data-v="${k}" style="display:flex;flex-direction:column;gap:6px;text-align:left;background:${on ? BG2 : SURF};border:${on ? `1.5px solid ${P}` : '0.5px solid var(--color-border-tertiary)'};border-radius:8px;padding:12px 14px;cursor:pointer;font-family:var(--font-ui)"><span style="display:flex;justify-content:space-between;align-items:center;gap:8px"><span style="font-size:13px;font-weight:500;color:var(--color-text)">${esc(t['lang_' + k])}</span><span style="width:16px;height:16px;border-radius:50%;border:1.5px solid ${on ? P : 'var(--color-border-secondary)'};background:${on ? P : 'transparent'};display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:10px">${on ? '✓' : ''}</span></span><span style="font-size:18px;font-weight:300;color:${P}">${fmt(n)} <span style="font-size:12px;color:${MUTED}">· ${pct}</span></span><span style="height:4px;border-radius:999px;background:${BG2};overflow:hidden;display:block"><span style="display:block;height:100%;width:${pct};background:${P}"></span></span></button>`; }).join('');
      const langSummary = s.sendLangs.length ? `${fmt(this.langTotal())} ${t.recipients} · ${LANG_KEYS.filter(k => s.sendLangs.includes(k)).map(k => k.toUpperCase()).join(' + ')}` : t.langNone;
      return `
      ${this.h2(t.s1Title, t.s1Sub)}
      <div>${this.cap(esc(t.database), 'margin-bottom:8px')}<div style="display:flex;gap:8px;flex-wrap:wrap">${chips}</div></div>
      <div>${this.cap(esc(t.segment), 'margin-bottom:8px')}<div style="display:flex;flex-direction:column;gap:8px">${rows || `<span style="font-size:12px;color:${MUTED}">Aucun segment sur cette base.</span>`}</div></div>
      ${s.segForm ? this.segForm() : (this.isBrand() ? this.link('segform', '+ ' + esc(t.newSegment), null, 'align-self:flex-start') : `<span style="font-size:12px;color:${MUTED}">${esc(t.segBrandOnly)}</span>`)}
      <div style="border-top:0.5px solid var(--color-border-tertiary);padding-top:18px">
        ${this.cap(esc(t.sendLangs), 'margin-bottom:4px')}<p style="margin:0 0 10px;font-size:12px;color:${MUTED}">${esc(t.sendLangsSub)}</p>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px">${langRows}</div>
        <p style="margin:10px 0 0;font-size:12px;color:${MUTED}">${esc(langSummary)}</p>
      </div>`;
    }
    segEstimate() {
      const s = this.s, src = (this.data().sources || []).find(x => x.id === s.sourceId);
      let est = src ? src.optin : 3480;
      if (s.segShop !== 'all') est *= 0.34;
      if (s.segProduct !== 'all') est *= 0.27;
      est *= { '30': 0.38, '90': 0.62, '365': 1, dormant45: 0.75 }[s.segPeriod] || 1;
      const mb = parseFloat(s.segMinBasket);
      if (mb > 0) est *= Math.max(0.05, 1 - mb / 40);
      return Math.max(1, Math.round(est));
    }
    segForm() {
      const s = this.s, t = this.t(), src = (this.data().sources || []).find(x => x.id === s.sourceId);
      const SEL = `border:0.5px solid var(--color-border-secondary);border-radius:8px;padding:10px 12px;font-size:14px;font-family:var(--font-ui);background:${SURF};width:100%`;
      const lab = (k, inner) => `<label style="display:flex;flex-direction:column;gap:6px">${this.cap(esc(k))}${inner}</label>`;
      const shops = this.magasins().filter(m => m.kind === 'franchise');
      return `
      <div style="border:1.5px solid ${P};border-radius:10px;padding:18px;display:flex;flex-direction:column;gap:14px;background:${SURF}">
        <div style="display:flex;justify-content:space-between;align-items:baseline;gap:12px"><div style="font-size:14px;font-weight:500">${esc(t.newSegment)}</div><div style="font-size:12px;color:${MUTED}">${esc(src ? this.L(src.name) : '')}</div></div>
        ${lab(t.segName, this.input('segName', s.segName, '', `placeholder="${esc(t.segNamePlaceholder)}"`))}
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px">
          ${lab(t.segShop, `<select data-f="segShop" style="${SEL}"><option value="all"${s.segShop === 'all' ? ' selected' : ''}>${esc(t.segAllShops)}</option>${shops.map(m => `<option value="${esc(m.id)}"${s.segShop === m.id ? ' selected' : ''}>${esc(m.nom)}</option>`).join('')}</select>`)}
          ${lab(t.segProduct, `<select data-f="segProduct" style="${SEL}">${[['all', t.segAnyProduct], ['tartine', 'Tartine'], ['cougnou', 'Cougnou'], ['galette', 'Galette des rois'], ['tarte', 'Tarte diamant'], ['plateau', 'Plateau sandwiches']].map(o => `<option value="${o[0]}"${s.segProduct === o[0] ? ' selected' : ''}>${esc(o[1])}</option>`).join('')}</select>`)}
          ${lab(t.segPeriod, `<select data-f="segPeriod" style="${SEL}">${[['30', t.p30], ['90', t.p90], ['365', t.p365], ['dormant45', t.pDormant]].map(o => `<option value="${o[0]}"${s.segPeriod === o[0] ? ' selected' : ''}>${esc(o[1])}</option>`).join('')}</select>`)}
          ${lab(t.segMinBasket, this.input('segMinBasket', s.segMinBasket, '', 'type="number" placeholder="0"'))}
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;border-top:0.5px solid var(--color-border-tertiary);padding-top:14px">
          <div style="font-size:13px"><span style="font-size:18px;font-weight:300;color:${P}">${fmt(this.segEstimate())}</span> <span style="color:${MUTED}">${esc(t.recipients)} · ${esc(t.segEstimated)}</span></div>
          <div style="display:flex;gap:8px">${this.btnS('segclose', esc(t.cancel))}${this.btnP('segsave', esc(t.segSave), null, 'padding:9px 14px;opacity:' + (s.segName.trim() ? 1 : 0.5))}</div>
        </div>
      </div>`;
    }
    step2() {
      const s = this.s, t = this.t();
      return `${this.h2(t.s2Title, t.s2Sub)}
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px">${TEMPLATES.map(x => { const on = x.id === s.templateId; return `<button data-a="template" data-v="${x.id}" style="display:flex;flex-direction:column;gap:10px;text-align:left;background:${on ? BG2 : SURF};border:${on ? `1.5px solid ${P}` : '0.5px solid var(--color-border-tertiary)'};border-radius:10px;padding:12px;cursor:pointer;font-family:var(--font-ui)"><div style="height:84px;border-radius:6px;background:${BG2};display:flex;align-items:center;justify-content:center;overflow:hidden;width:100%">${x.blank ? `<span style="font-size:24px;color:${MUTED};font-weight:300">+</span>` : `<img src="${esc(this.img(x))}" alt="" style="height:64px;width:auto;object-fit:contain">`}</div><div><div style="font-size:13px;font-weight:500;color:var(--color-text)">${esc(this.L(x.name))}</div><div style="font-size:11px;color:${MUTED};margin-top:2px">${esc(this.L(x.hint))}</div></div></button>`; }).join('')}</div>`;
    }
    step3() {
      const s = this.s, t = this.t(), el = this.editLang(), seg = this.segById(s.segmentId);
      const subject = this.subject(), body = this.body(), sms = this.sms();
      const tabBtn = (a, v, on, label) => `<button data-a="${a}" data-v="${v}" style="border:0.5px solid ${on ? P : 'var(--color-border-secondary)'};background:${on ? P : 'transparent'};color:${on ? '#fff' : MUTED};font-family:var(--font-ui);font-size:12px;font-weight:500;padding:5px 12px;border-radius:999px;cursor:pointer">${label}</button>`;
      const hasSms = s.channel !== 'email';
      const smsOptin = Math.round(this.langTotal() * 0.61);
      return `${this.h2(t.s3Title, t.s3Sub)}
      <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">${this.cap(esc(t.channel))}<div style="display:flex;gap:4px">${[['email', t.chEmail], ['sms', t.chSms], ['both', t.chBoth]].map(o => tabBtn('channel', o[0], s.channel === o[0], esc(o[1]))).join('')}</div><span style="font-size:12px;color:${MUTED}">${hasSms ? fmt(smsOptin) + ' ' + esc(t.smsOptin) : ''}</span></div>
      <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">${this.cap(esc(t.version))}<div style="display:flex;gap:4px">${LANG_KEYS.filter(k => s.sendLangs.includes(k)).map(k => tabBtn('editlang', k, k === el, k.toUpperCase() + ` <span style="font-weight:400;opacity:0.7">${fmt(seg.split[LANG_KEYS.indexOf(k)] || 0)}</span>`)).join('')}</div><span style="font-size:12px;color:${MUTED}">${esc(t.editLangHint)}</span></div>
      <label style="display:flex;flex-direction:column;gap:6px">${this.cap(esc(t.subject))}${this.input('subject', subject)}<span style="font-size:12px;color:${subject.length > 45 ? P : MUTED}">${esc(subject.length > 45 ? t.subjectLong : t.subjectGood)}</span></label>
      <div style="display:flex;flex-direction:column;border:0.5px solid var(--color-border-secondary);border-radius:8px;overflow:hidden">
        <div class="nl-tb" style="display:flex;align-items:center;gap:6px;padding:6px 8px;border-bottom:0.5px solid var(--color-border-tertiary);background:${BG2};flex-wrap:wrap">
          <select style="border:0.5px solid var(--color-border-tertiary);border-radius:6px;background:${SURF};font-size:12px;padding:4px 6px;font-family:var(--font-ui)"><option>Gotham</option><option>Vank</option></select>
          <button data-a="wrap" data-v="**" style="width:28px;height:28px;border:none;background:none;border-radius:6px;font-weight:700;cursor:pointer;font-family:var(--font-ui)">B</button>
          <button data-a="wrap" data-v="_" style="width:28px;height:28px;border:none;background:none;border-radius:6px;font-style:italic;cursor:pointer;font-family:var(--font-ui)">I</button>
          <span style="width:16px;height:16px;border-radius:50%;background:${P};margin:0 4px;border:2px solid ${SURF};box-shadow:0 0 0 0.5px var(--color-border-secondary)"></span><span style="width:16px;height:16px;border-radius:50%;background:${SEC};border:2px solid ${SURF};box-shadow:0 0 0 0.5px var(--color-border-secondary)"></span><span style="width:16px;height:16px;border-radius:50%;background:var(--color-text);border:2px solid ${SURF};box-shadow:0 0 0 0.5px var(--color-border-secondary)"></span>
          <span style="width:0.5px;height:20px;background:var(--color-border-secondary);margin:0 6px"></span>
          <select data-f="insert" style="border:0.5px solid var(--color-border-tertiary);border-radius:6px;background:${SURF};font-size:12px;padding:4px 6px;color:${P};font-family:var(--font-ui)"><option value="">${esc(t.insert)}</option><option value="prenom">${esc(t.insFirstname)}</option><option value="code">${esc(t.insVoucher)}</option><option value="webshop">${esc(t.insWebshop)}</option><option value="boutique">${esc(t.insShop)}</option></select>
        </div>
        <textarea data-f="body" rows="8" style="border:none;padding:14px;font-size:14px;line-height:1.6;font-family:var(--font-ui);resize:vertical;background:${SURF};width:100%">${esc(body)}</textarea>
      </div>
      <div style="display:flex;gap:16px;font-size:12px;color:${MUTED}"><span>${body.trim() ? body.trim().split(/\s+/).length : 0} ${esc(t.words)}</span><span>·</span><span>${esc(t.oneCta)}</span></div>
      ${hasSms ? `<label style="display:flex;flex-direction:column;gap:6px;border-top:0.5px solid var(--color-border-tertiary);padding-top:18px"><span style="display:flex;justify-content:space-between;gap:12px">${this.cap(esc(t.smsText))}<span style="font-size:12px;color:${sms.length > 160 ? P : MUTED}">${sms.length} / 160</span></span><textarea data-f="sms" rows="3" style="border:0.5px solid var(--color-border-secondary);border-radius:8px;padding:12px;font-size:14px;line-height:1.5;font-family:var(--font-ui);resize:vertical;background:${SURF};width:100%">${esc(sms)}</textarea><span style="font-size:12px;color:${MUTED}">${esc(t.smsHint)}</span></label>` : ''}`;
    }
    step4() {
      const s = this.s, t = this.t(), b = this.blocks(), gen = this.gen(), tp = this.tpl();
      const meta = { linkedin: { name: 'LinkedIn', max: 3000, rows: 5, note: t.soNoteLi }, instagram: { name: 'Instagram', max: 2200, rows: 4, note: t.soNoteIg }, slack: { name: 'Slack · #boutiques', max: 500, rows: 3, note: t.soNoteSlack } };
      const cards = ['linkedin', 'instagram', 'slack'].map(k => {
        const on = !!s.socialOn[k], text = s.socialTexts[k] ?? gen[k], reviewed = !!s.socialReviewed[k];
        const st = !on ? [t.soOff, 'transparent', MUTED, 'var(--color-border-secondary)'] : reviewed ? [t.soReviewed, BG2, GREEN, 'var(--color-border-tertiary)'] : [t.soReview, SEC, ON, SEC];
        return `<div style="border:${on ? '0.5px solid var(--color-border-tertiary)' : '0.5px solid transparent'};border-radius:10px;padding:14px 16px;display:flex;flex-direction:column;gap:10px;background:${on ? SURF : BG2}">
          <div style="display:flex;justify-content:space-between;align-items:center;gap:12px">
            <button data-a="social-toggle" data-v="${k}" style="display:flex;align-items:center;gap:10px;background:none;border:none;padding:0;cursor:pointer;font-family:var(--font-ui);text-align:left"><span style="width:16px;height:16px;border-radius:4px;border:1.5px solid ${on ? P : 'var(--color-border-secondary)'};background:${on ? P : 'transparent'};display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:10px">${on ? '✓' : ''}</span><span style="font-size:14px;font-weight:500;color:var(--color-text)">${meta[k].name}</span><span style="font-size:12px;color:${MUTED}">${esc(t['so_' + k])}</span></button>
            ${this.pill(st[0], st[1], st[2], st[3])}
          </div>
          ${on ? `<textarea data-f="social-${k}" rows="${meta[k].rows}" style="border:0.5px solid var(--color-border-secondary);border-radius:8px;padding:12px;font-size:14px;line-height:1.5;font-family:var(--font-ui);resize:vertical;background:${SURF};width:100%">${esc(text)}</textarea><div style="display:flex;justify-content:space-between;gap:12px;font-size:12px;color:${MUTED}"><span>${esc(meta[k].note)}</span><span style="color:${text.length > meta[k].max ? P : MUTED}">${text.length} / ${meta[k].max}</span></div>` : ''}
        </div>`;
      }).join('');
      return `${this.h2(t.soTitle, t.soSub)}
      <div style="background:${BG2};border-radius:8px;padding:14px 16px;display:grid;grid-template-columns:90px 1fr;gap:8px 16px;font-size:13px;align-items:baseline">
        ${this.cap('Hook')}<div>${esc(b.hook)}</div>${this.cap('Insight')}<div>${esc(b.insight)}</div>${this.cap('CTA')}<div>${esc(b.cta)} → ${esc(b.link)}</div>
        ${this.cap('Image')}<div style="display:flex;align-items:center;gap:10px">${tp.img ? `<img src="${esc(this.img(tp))}" alt="" style="height:28px;width:auto">` : ''}<span style="color:${MUTED}">${esc(t.soImageNote)}</span></div>
      </div>
      <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap"><span style="font-size:12px;color:${MUTED}">${esc(t.soGenerated)}</span>${this.btnS('regen', esc(t.soRegen), null, 'padding:8px 14px')}</div>
      <div style="display:flex;flex-direction:column;gap:12px">${cards}</div>`;
    }
    step5() {
      const s = this.s, t = this.t(), isAuto = s.sendMode === 'auto', seg = this.segById(s.segmentId), src = (this.data().sources || []).find(x => x.id === s.sourceId), tp = this.tpl(), sd = this.sender();
      const SEL = `border:0.5px solid var(--color-border-secondary);border-radius:8px;padding:10px 12px;font-size:14px;font-family:var(--font-ui);background:${SURF};width:100%`;
      const modes = [['manual', t.modeManual, t.modeManualDesc], ...(this.isBrand() ? [['auto', t.modeAuto, t.modeAutoDesc]] : [])].map(o => { const on = s.sendMode === o[0]; return `<button data-a="mode" data-v="${o[0]}" style="text-align:left;background:${on ? BG2 : SURF};border:${on ? `1.5px solid ${P}` : '0.5px solid var(--color-border-tertiary)'};border-radius:8px;padding:12px 14px;cursor:pointer;font-family:var(--font-ui)"><span style="display:block;font-size:13px;font-weight:500;color:var(--color-text)">${esc(o[1])}</span><span style="display:block;font-size:12px;color:${MUTED};margin-top:2px">${esc(o[2])}</span></button>`; }).join('');
      const senders = this.senders().map(x => { const on = x.id === sd.id; return `<button data-a="sender" data-v="${esc(x.id)}" style="display:flex;align-items:center;justify-content:space-between;gap:16px;text-align:left;background:${on ? BG2 : SURF};border:${on ? `1.5px solid ${P}` : '0.5px solid var(--color-border-tertiary)'};border-radius:8px;padding:11px 14px;cursor:pointer;font-family:var(--font-ui)"><span><span style="display:block;font-size:13px;font-weight:500;color:var(--color-text)">${esc(x.name)}</span><span style="display:block;font-size:12px;color:${MUTED};margin-top:1px">${esc(x.email)} · ${esc(t.replyTo)} ${esc(x.reply)}</span></span>${this.domainPill(x.status)}</button>`; }).join('');
      const checks = this.checks(), fails = checks.filter(c => !c[1]).length;
      const langSummary = s.sendLangs.length ? `${fmt(this.langTotal())} ${t.recipients} · ${LANG_KEYS.filter(k => s.sendLangs.includes(k)).map(k => k.toUpperCase()).join(' + ')}` : t.langNone;
      const modeSummary = isAuto ? `${t.modeAuto} · ${t['tr_' + s.trigger]}` : `${t.modeManual} · ${s.date.split('-').reverse().join('/')} ${s.time}`;
      const socialOn = ['linkedin', 'instagram', 'slack'].filter(k => s.socialOn[k]).map(k => ({ linkedin: 'LinkedIn', instagram: 'Instagram', slack: 'Slack' })[k]);
      const rec = (k, v) => `<div style="color:${MUTED}">${esc(k)}</div><div>${v}</div>`;
      return `${this.h2(t.s4Title, t.s4Sub)}
      <div>${this.cap(esc(t.sendMode), 'margin-bottom:8px')}<div class="nl-2" style="gap:8px">${modes}</div></div>
      ${isAuto ? `<label style="display:flex;flex-direction:column;gap:6px">${this.cap(esc(t.trigger))}<select data-f="trigger" style="${SEL}">${TRIGGERS.map(id => `<option value="${id}"${s.trigger === id ? ' selected' : ''}>${esc(t['tr_' + id])}</option>`).join('')}</select><span style="font-size:12px;color:${MUTED}">${esc(t.triggerHint)}</span></label>`
        : `<div class="nl-2"><label style="display:flex;flex-direction:column;gap:6px">${this.cap(esc(t.date))}${this.input('date', s.date, '', 'type="date"')}</label><label style="display:flex;flex-direction:column;gap:6px">${this.cap(esc(t.time))}${this.input('time', s.time, '', 'type="time"')}</label></div><p style="margin:-8px 0 0;font-size:12px;color:${MUTED}">${esc(t.bestTime)}</p>`}
      <div>${this.cap(esc(t.sender), 'margin-bottom:8px')}<div style="display:flex;flex-direction:column;gap:8px">${senders}</div></div>
      <div style="background:${BG2};border-radius:8px;padding:14px 16px">
        <div style="display:flex;justify-content:space-between;align-items:baseline;gap:12px;margin-bottom:10px">${this.cap(esc(t.spamCheck))}<span style="font-size:13px;font-weight:500;color:${fails ? P : GREEN}">${fails ? fails + ' · ' + esc(t.spamWarn) : esc(t.spamOk)}</span></div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:6px 20px">${checks.map(c => `<div style="display:flex;gap:8px;align-items:flex-start;font-size:12px;color:${c[1] ? 'var(--color-text)' : P}"><span style="flex:none;width:14px;text-align:center;font-weight:500">${c[1] ? '✓' : '!'}</span><span>${esc(c[0])}</span></div>`).join('')}</div>
      </div>
      <label style="display:flex;flex-direction:column;gap:6px;max-width:320px">${this.cap(esc(t.maxVouchers) + ` <span style="text-transform:none;letter-spacing:0">(${esc(t.optional)})</span>`)}${this.input('maxVouchers', s.maxVouchers, '', 'type="number" placeholder="30"')}<span style="font-size:12px;color:${MUTED}">${esc(s.maxVouchers ? s.maxVouchers + ' ' + t.voucherHintOn : t.voucherHintOff)}</span></label>
      <div style="border-top:0.5px solid var(--color-border-tertiary);padding-top:18px;display:grid;grid-template-columns:1fr 1fr;gap:10px 24px;font-size:13px">
        ${rec(t.database, esc(src ? this.L(src.name) : ''))}${rec(t.segment, esc(this.L(seg.name)) + ' · ' + fmt(seg.count))}${rec(t.sendLangs, esc(langSummary))}${rec(t.channel, esc({ email: t.chEmail, sms: t.chSms, both: t.chBoth }[s.channel]))}
        ${rec(t.sendMode, esc(modeSummary))}${rec(t.soShort, esc(socialOn.length ? socialOn.join(' · ') : t.soNone))}${rec(t.template, esc(this.L(tp.name)))}${rec(t.sender, esc(sd.name) + ' · ' + esc(sd.email))}
        ${rec(t.subject, esc(this.subject()))}${rec(t.sendTest, this.link('test', esc(s.testSent ? t.testDone : t.testSend)))}
      </div>`;
    }
    preview() {
      const s = this.s, t = this.t(), tp = this.tpl(), sd = this.sender(), b = this.blocks(), el = this.editLang().toUpperCase();
      const subject = this.subject(), hasSms = s.channel !== 'email';
      const smsPreview = this.sms().replace(/\{\{prénom\}\}/g, 'Marie').replace(/\{\{code\}\}/g, 'K7M2DIAM').replace(/\{\{lien\}\}/g, 'latelier.by/v/K7M2DIAM');
      return `
      <div class="nl-sticky">
        ${this.cap(`<span>${esc(t.preview)} · ${el}</span><span>${esc(t.mobile)} · 360px</span>`, 'margin-bottom:10px;display:flex;justify-content:space-between')}
        <div style="max-width:360px;margin:0 auto;background:${SURF};border:0.5px solid var(--color-border-tertiary);border-radius:14px;overflow:hidden;box-shadow:0 8px 24px rgba(34,34,34,0.06)">
          <div style="padding:10px 14px;border-bottom:0.5px solid var(--color-border-tertiary);font-size:12px"><div style="color:${MUTED}">${esc(sd.name)} · ${esc(sd.email)}</div><div style="font-weight:500;margin-top:2px">${esc(subject || t.subjectPlaceholder)}</div></div>
          <div style="background:${P};padding:16px;text-align:center"><img src="${esc(this.opts.imgDir + 'logo-white.png')}" alt="L'Atelier By" style="height:28px;width:auto"></div>
          ${tp.img ? `<div style="padding:22px 22px 8px;text-align:center"><img src="${esc(this.img(tp))}" alt="" style="height:110px;width:auto;object-fit:contain"></div>` : ''}
          <div style="padding:6px 24px 20px">
            ${this.LL(tp.headline) ? `<div style="font-family:var(--font-display);font-size:22px;line-height:1.2;color:${P};margin-bottom:10px">${esc(this.LL(tp.headline))}</div>` : ''}
            <div style="font-size:14px;line-height:1.6;white-space:pre-wrap">${esc(b.previewBody)}</div>
            <div style="margin-top:18px"><span style="display:inline-block;background:${P};color:#fff;border-radius:8px;padding:11px 18px;font-size:13px;font-weight:500">${esc(this.LL(tp.cta))}</span></div>
            ${tp.badge ? `<div style="margin-top:18px;display:inline-flex;align-items:center;gap:8px;background:${SEC};color:${ON};border-radius:999px;padding:5px 12px;font-size:11px;font-weight:500"><span style="width:6px;height:6px;border-radius:50%;background:${ON}"></span>${esc(t.badge3)}</div>` : ''}
          </div>
          <div style="padding:14px 24px 18px;border-top:0.5px solid var(--color-border-tertiary);font-size:11px;color:${MUTED};line-height:1.7"><div>${esc(t.footWebshop)} · ${esc(t.footShop)} · ${esc(t.footSupport)}</div><div>${esc(t.footHours)}</div><div style="margin-top:4px;text-decoration:underline">${esc(t.unsubscribe)}</div></div>
        </div>
        ${hasSms ? `<div style="max-width:360px;margin:18px auto 0">${this.cap('SMS · ' + el, 'margin-bottom:10px')}<div style="display:flex;flex-direction:column;gap:6px;align-items:flex-start"><div style="font-size:11px;color:${MUTED};padding-left:4px">${esc(sd.name)} · +32 460 12 34 56</div><div style="background:${SURF};border:0.5px solid var(--color-border-tertiary);border-radius:16px 16px 16px 4px;padding:10px 14px;font-size:14px;line-height:1.5;max-width:290px;white-space:pre-wrap">${esc(smsPreview)}</div></div></div>` : ''}
      </div>`;
    }

    /* --- 3. galerie de modèles --------------------------------------------- */
    vTemplates() {
      const t = this.t(), exp = this.isBrand() ? t.roleBrandShort : this.nomShop(this.role());
      return `
      <div style="margin-bottom:24px"><h1 style="font-family:var(--font-display);font-size:32px;font-weight:400;line-height:1.2;margin:0">${esc(t.templates)}</h1><p style="margin:6px 0 0;color:${MUTED}">${esc(t.templatesSub)}</p></div>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:20px">${TEMPLATES.filter(x => !x.blank).map(x => `
        <div style="display:flex;flex-direction:column;gap:12px">
          <div style="display:flex;justify-content:space-between;align-items:baseline;gap:8px"><div style="font-weight:500">${esc(this.L(x.name))}</div>${this.link('use', esc(t.useTemplate), x.id, 'font-size:12px')}</div>
          <div style="background:${SURF};border:0.5px solid var(--color-border-tertiary);border-radius:14px;overflow:hidden">
            <div style="padding:10px 14px;border-bottom:0.5px solid var(--color-border-tertiary);font-size:12px"><div style="color:${MUTED}">L'Atelier By · ${esc(exp)}</div><div style="font-weight:500;margin-top:2px">${esc(this.L(x.subject))}</div></div>
            <div style="background:${P};padding:14px;text-align:center"><img src="${esc(this.opts.imgDir + 'logo-white.png')}" alt="" style="height:24px;width:auto"></div>
            <div style="padding:18px 18px 6px;text-align:center"><img src="${esc(this.img(x))}" alt="" style="height:96px;width:auto;object-fit:contain"></div>
            <div style="padding:6px 20px 18px">
              <div style="font-family:var(--font-display);font-size:20px;line-height:1.2;color:${P};margin-bottom:8px">${esc(this.L(x.headline))}</div>
              <div style="font-size:13px;line-height:1.6;white-space:pre-wrap">${esc(this.L(x.body).replace('{{prénom}}', 'Marie').replace('{{code_promo}}', 'K7M2DIAM'))}</div>
              <div style="margin-top:16px"><span style="display:inline-block;background:${P};color:#fff;border-radius:8px;padding:10px 16px;font-size:12px;font-weight:500">${esc(this.L(x.cta))}</span></div>
              ${x.badge ? `<div style="margin-top:16px;display:inline-flex;align-items:center;gap:8px;background:${SEC};color:${ON};border-radius:999px;padding:5px 12px;font-size:11px;font-weight:500"><span style="width:6px;height:6px;border-radius:50%;background:${ON}"></span>${esc(t.badge3)}</div>` : ''}
            </div>
            <div style="padding:12px 20px 16px;border-top:0.5px solid var(--color-border-tertiary);font-size:11px;color:${MUTED};line-height:1.7"><div>${esc(t.footWebshop)} · ${esc(t.footShop)} · ${esc(t.footSupport)}</div><div>${esc(t.footHours)}</div><div style="margin-top:4px;text-decoration:underline">${esc(t.unsubscribe)}</div></div>
          </div>
        </div>`).join('')}</div>`;
    }

    /* --- 4. rétrospective ------------------------------------------------- */
    vRetro() {
      const s = this.s, t = this.t(), camps = this.data().campagnes || [];
      const c = camps.find(x => String(x.id) === String(s.retroId)) || camps.find(x => x.status === 'sent');
      if (!c) return `<div style="color:${MUTED}">Aucune campagne envoyée.</div>`;
      const st = this.stats(c), seg = this.segById(c.segment);
      const prev = camps.find(x => x.id !== c.id && x.status === 'sent' && x.templateId === c.templateId && this.stats(x).sent > 0) || camps.find(x => x.id !== c.id && x.status === 'sent' && this.stats(x).sent > 0) || null;
      const ps = prev ? this.stats(prev) : null;
      const pct = (a, b) => b ? 100 * a / b : 0;
      const delta = (v, unit) => v == null ? ['—', MUTED] : [(v > 0 ? '+' : (v < 0 ? '−' : '')) + fmt(Math.abs(Math.round(v * 10) / 10)) + unit, v > 0 ? GREEN : v < 0 ? P : MUTED];
      const dOpen = ps ? delta(pct(st.open, st.sent) - pct(ps.open, ps.sent), ' pts') : ['—', MUTED];
      const dClick = ps ? delta(pct(st.click, st.sent) - pct(ps.click, ps.sent), ' pts') : ['—', MUTED];
      const dV = ps ? delta(st.vouchers - ps.vouchers, '') : ['—', MUTED];
      const dR = ps ? delta(st.revenue - ps.revenue, ' €') : ['—', MUTED];
      const vPct = pct(st.vouchers, st.sent);
      const kpis = [
        [t.kSent, fmt(st.sent), this.L(seg.name), ['', MUTED], 'var(--color-text)'],
        [t.kOpen, (st.openPct != null ? st.openPct + ' %' : '—'), fmt(st.open), dOpen, 'var(--color-text)'],
        [t.kClick, (st.clickPct != null ? st.clickPct + ' %' : '—'), fmt(st.click), dClick, 'var(--color-text)'],
        [t.kVouchers, c.maxVouchers ? `${st.vouchers} / ${c.maxVouchers}` : fmt(st.vouchers), (Math.round(vPct * 10) / 10) + ' % ' + t.kSent.toLowerCase(), dV, P],
        [t.kRevenue, fmt(st.revenue) + ' €', st.vouchers ? 'env. ' + fmt(Math.round(st.revenue / st.vouchers * 10) / 10) + ' € / voucher' : '', dR, P],
      ];
      const funnel = [[t.kSent, fmt(st.sent), 100, ps ? 100 : null, 'var(--color-text)'], [t.kOpen, st.openPct != null ? st.openPct + ' %' : '—', pct(st.open, st.sent), ps ? pct(ps.open, ps.sent) : null, SEC],
        [t.kClick, st.clickPct != null ? st.clickPct + ' %' : '—', pct(st.click, st.sent), ps ? pct(ps.click, ps.sent) : null, P], [t.kVouchers, c.maxVouchers ? `${st.vouchers} / ${c.maxVouchers}` : fmt(st.vouchers), Math.max(vPct, 1.5), ps ? Math.max(pct(ps.vouchers, ps.sent), 1.5) : null, P]];
      const subject = (c.subjects && (c.subjects.fr || c.subjects[Object.keys(c.subjects)[0]])) || '';
      return `
      ${this.link('view', '← ' + esc(t.campaigns), 'dashboard', 'margin-bottom:14px')}
      <div style="display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:28px">
        <div><h1 style="font-family:var(--font-display);font-size:32px;font-weight:400;line-height:1.2;margin:0">${esc(c.name)}</h1><p style="margin:6px 0 0;color:${MUTED}">${esc(this.fDate(c.sendAt, c.sendMode))} · ${esc(this.L(seg.name))} · ${esc(subject)}</p></div>
        <div style="display:flex;gap:10px">${this.canSend() ? this.btnS('dupAB', esc(t.duplicateAB), c.id) : ''}</div>
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:16px">${kpis.map(k => `<div style="background:${SURF};border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:18px 20px">${this.cap(esc(k[0]))}<div style="font-size:30px;font-weight:300;margin-top:6px;color:${k[4]}">${k[1]}</div><div style="font-size:12px;color:${MUTED};margin-top:2px">${esc(k[2])}</div><div style="font-size:12px;margin-top:10px;color:${k[3][1]}">${esc(k[3][0])}</div></div>`).join('')}</div>
      <div style="margin-top:20px;background:${SURF};border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:22px 24px">
        ${this.cap(esc(t.funnel) + (prev ? ' · ' + esc(t.vsPrev) + ' ' + esc(prev.name) : ''), 'margin-bottom:14px')}
        <div style="display:flex;flex-direction:column;gap:10px">${funnel.map(f => `<div style="display:grid;grid-template-columns:130px 1fr 60px;gap:14px;align-items:center;font-size:13px"><div style="color:${MUTED}">${esc(f[0])}</div><div style="height:10px;border-radius:999px;background:${BG2};overflow:hidden;position:relative"><div style="height:100%;width:${f[2]}%;background:${f[4]};border-radius:999px"></div>${f[3] != null ? `<div style="position:absolute;top:0;bottom:0;left:${f[3]}%;width:1.5px;background:var(--color-text)"></div>` : ''}</div><div style="text-align:right;font-weight:500">${f[1]}</div></div>`).join('')}</div>
        <div style="font-size:11px;color:${MUTED};margin-top:12px">${esc(t.funnelLegend)}</div>
      </div>`;
    }

    /* --- 5. paramètres (marque) / mon expéditeur (magasin) ------------------ */
    vSettings() {
      const t = this.t(), isBrand = this.isBrand(), ms = isBrand ? this.magasins() : this.magasins().filter(m => m.id === this.role());
      const INP = `border:0.5px solid var(--color-border-secondary);border-radius:6px;padding:7px 10px;font-size:13px;font-family:var(--font-ui);background:${SURF};width:100%`;
      const rows = ms.map(x => {
        const isB = x.kind === 'brand', locked = !isBrand && x.id !== this.role();
        return `<div class="nl-shop">
          <div><div style="font-weight:500">${esc(x.nom)}</div><div style="font-size:11px;color:${MUTED}">${esc(isB ? t.kindBrand : t.kindFranchise)}</div></div>
          <div style="display:flex;flex-direction:column;gap:6px"><input data-f="shopname-${esc(x.id)}" value="${esc(x.senderName)}" ${locked ? 'disabled' : ''} style="${INP}"><input data-f="shopmail-${esc(x.id)}" value="${esc(x.email)}" placeholder="adresse@${esc(this.data().domaine || 'latelier.by')}" ${locked ? 'disabled' : ''} style="${INP}"></div>
          <div style="display:flex;flex-direction:column;gap:6px;align-items:flex-start">${this.domainPill(x.status)}${isBrand ? `<select data-f="shopstatus-${esc(x.id)}" style="border:0.5px solid var(--color-border-tertiary);border-radius:6px;background:${SURF};font-size:11px;padding:3px 6px;font-family:var(--font-ui)">${[['verified', t.verified], ['warmup', t.warmup], ['unverified', t.unverified]].map(o => `<option value="${o[0]}"${x.status === o[0] ? ' selected' : ''}>${esc(o[1])}</option>`).join('')}</select>` : ''}</div>
          <div><button data-a="cansend" data-v="${esc(x.id)}" ${isB || !isBrand ? 'disabled' : ''} style="display:inline-flex;align-items:center;gap:8px;background:none;border:none;padding:0;cursor:${isB || !isBrand ? 'default' : 'pointer'};font-family:var(--font-ui);font-size:12px;color:var(--color-text)"><span style="width:32px;height:18px;border-radius:999px;background:${x.canSend ? P : 'var(--color-border-secondary)'};position:relative;display:inline-block;transition:background 120ms"><span style="position:absolute;top:2px;left:${x.canSend ? '16px' : '2px'};width:14px;height:14px;border-radius:50%;background:#fff;transition:left 120ms"></span></span>${esc(x.canSend ? t.canSendOn : t.canSendOff)}</button></div>
        </div>`;
      }).join('');
      const rights = [['r_seg', true, false], ['r_tpl', true, false], ['r_auto', true, false], ['r_send', true, 'cond'], ['r_sender', true, true], ['r_stats', true, true]].map(([k, b, f]) => `<div style="color:var(--color-text)">${esc(t[k])}</div><div style="text-align:center;color:${GREEN};font-weight:500">${b ? '✓' : '—'}</div><div style="text-align:center;color:${f ? GREEN : MUTED};font-weight:500">${f === 'cond' ? '✓*' : f ? '✓' : '—'}</div>`).join('');
      return `
      <div style="margin-bottom:24px"><h1 style="font-family:var(--font-display);font-size:32px;font-weight:400;line-height:1.2;margin:0">${esc(isBrand ? t.settings : t.sender)}</h1><p style="margin:6px 0 0;color:${MUTED}">${esc(t.settingsSub)}</p></div>
      <div class="nl-set">
        <div class="nl-scroll" style="background:${SURF};border:0.5px solid var(--color-border-tertiary);border-radius:12px"><div style="min-width:640px">
          <div class="nl-shop h"><div>${esc(t.shop)}</div><div>${esc(t.shopSender)}</div><div>${esc(t.domain)}</div><div>${esc(t.canSendCol)}</div></div>${rows}
        </div></div>
        <div style="display:flex;flex-direction:column;gap:16px">
          <div style="background:${SURF};border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:18px 20px">${this.cap(esc(t.rights), 'margin-bottom:12px')}
            <div style="display:grid;grid-template-columns:1fr 60px 70px;gap:8px 12px;font-size:13px;align-items:center"><div></div>${this.cap(esc(t.roleBrandShort), 'text-align:center')}${this.cap(esc(t.roleFranchiseShort), 'text-align:center')}${rights}</div></div>
          <p style="margin:0;font-size:12px;color:${MUTED};line-height:1.6">${esc(t.rightsNote)}</p>
        </div>
      </div>`;
    }

    /* --- gestes -------------------------------------------------------------- */
    go(view, patch) { Object.assign(this.s, patch || {}, { view }); this.render(); window.scrollTo({ top: 0 }); }
    onClick(e) {
      const b = e.target.closest('[data-a]'); if (!b || !this.el.contains(b)) return;
      if (e.target.closest('input,select,textarea,a')) return;
      const a = b.dataset.a, v = b.dataset.v, s = this.s, t = this.t();
      if (a === 'view') this.go(v);
      else if (a === 'lang') { s.lang = v; this.persist(); this.render(); }
      else if (a === 'wizard') this.go('wizard', { step: 1 });
      else if (a === 'filtre') { s.modeFilter = v; this.render(); }
      else if (a === 'retro') { if (e.target.closest('[data-a="retirer"]')) return; this.go('retro', { retroId: v }); }
      else if (a === 'retirer') { if (!confirm('Retirer cette campagne ?')) return; ecrire(this.base, 'DELETE', '/newsletter/campagnes/' + v + '?role=' + encodeURIComponent(this.role())).then(() => this.charger()).catch(err => this.notify(err.message)); }
      else if (a === 'source') { const first = this.segmentsVisibles().find(x => x.src === v); s.sourceId = v; if (first) s.segmentId = first.id; this.render(); }
      else if (a === 'segment') { s.segmentId = v; this.render(); }
      else if (a === 'segform') { s.segForm = true; this.render(); }
      else if (a === 'segclose') { s.segForm = false; this.render(); }
      else if (a === 'segsave') this.saveSegment();
      else if (a === 'lang-toggle') { s.sendLangs = s.sendLangs.includes(v) ? s.sendLangs.filter(x => x !== v) : s.sendLangs.concat([v]); this.render(); }
      else if (a === 'template') { const x = TEMPLATES.find(z => z.id === v); s.templateId = v; s.subjects = {}; s.bodies = {}; s.smsTexts = {}; s.maxVouchers = x && x.voucher ? '30' : ''; this.render(); }
      else if (a === 'use') { const x = TEMPLATES.find(z => z.id === v); this.go('wizard', { step: 3, templateId: v, subjects: {}, bodies: {}, smsTexts: {}, maxVouchers: x && x.voucher ? '30' : '' }); }
      else if (a === 'channel') { s.channel = v; this.render(); }
      else if (a === 'editlang') { s.editLang = v; this.render(); }
      else if (a === 'wrap') { const el = this.editLang(); const body = this.body(); s.bodies[el] = body + (v === '**' ? ' **gras**' : ' _italique_'); this.render(); }
      else if (a === 'social-toggle') { s.socialOn = Object.assign({}, s.socialOn); s.socialOn[v] = !s.socialOn[v]; this.render(); }
      else if (a === 'regen') { s.socialTexts = {}; s.socialReviewed = {}; this.render(); }
      else if (a === 'mode') { s.sendMode = v; this.render(); }
      else if (a === 'sender') { s.senderId = v; this.render(); }
      else if (a === 'test') { ecrire(this.base, 'POST', '/newsletter/test', { role: this.role(), subject: this.subject() }).then(r => { s.testSent = true; this.notify(r && r.simule ? 'Test enregistré — mode test, rien n’est parti.' : t.testDone); this.render(); }).catch(err => this.notify(err.message)); }
      else if (a === 'prev') { s.step = Math.max(1, s.step - 1); this.render(); }
      else if (a === 'next') this.nextStep();
      else if (a === 'dupAB') { const c = (this.data().campagnes || []).find(x => String(x.id) === String(v)); if (!c) return; const subj = (c.subjects && (c.subjects.fr || '')) || ''; this.go('wizard', { step: 3, templateId: TEMPLATES.some(z => z.id === c.templateId) ? c.templateId : 'gagne', segmentId: c.segment, sourceId: c.src, subjects: { fr: subj + ' (B)' }, bodies: {}, channel: c.channel === 'sms' ? 'email' : c.channel }); }
      else if (a === 'cansend') { const m = this.magasins().find(x => x.id === v); if (!m) return; this.putShop(v, { canSend: !m.canSend }); }
    }
    onInput(e) {
      const f = e.target.getAttribute && e.target.getAttribute('data-f'); if (!f) return;
      const s = this.s, v = e.target.value, el = this.editLang();
      if (f === 'subject') { s.subjects = Object.assign({}, s.subjects); s.subjects[el] = v; }
      else if (f === 'body') { s.bodies = Object.assign({}, s.bodies); s.bodies[el] = v; }
      else if (f === 'sms') { s.smsTexts = Object.assign({}, s.smsTexts); s.smsTexts[el] = v; }
      else if (f.indexOf('social-') === 0) { const k = f.slice(7); s.socialTexts = Object.assign({}, s.socialTexts); s.socialTexts[k] = v; s.socialReviewed = Object.assign({}, s.socialReviewed); s.socialReviewed[k] = true; }
      else if (f === 'segName') s.segName = v;
      else if (f === 'segMinBasket') s.segMinBasket = v;
      else if (f === 'maxVouchers') s.maxVouchers = v;
      else if (f === 'date') s.date = v;
      else if (f === 'time') s.time = v;
      else return;
      this.render();
    }
    onChange(e) {
      const f = e.target.getAttribute && e.target.getAttribute('data-f'); if (!f) return;
      const s = this.s, v = e.target.value;
      if (f === 'segShop') s.segShop = v;
      else if (f === 'segProduct') s.segProduct = v;
      else if (f === 'segPeriod') s.segPeriod = v;
      else if (f === 'trigger') s.trigger = v;
      else if (f === 'insert') { const tok = { prenom: '{{prénom}}', code: '{{code_promo}}', webshop: '{{lien_webshop}}', boutique: '{{lien_boutique}}' }[v]; if (tok) { const el = this.editLang(); s.bodies = Object.assign({}, s.bodies); s.bodies[el] = this.body() + tok; } }
      else if (f.indexOf('shopname-') === 0) { this.putShop(f.slice(9), { senderName: v }); return; }
      else if (f.indexOf('shopmail-') === 0) { this.putShop(f.slice(9), { email: v }); return; }
      else if (f.indexOf('shopstatus-') === 0) { this.putShop(f.slice(11), { status: v }); return; }
      else if (['date', 'time', 'maxVouchers', 'subject', 'body', 'sms', 'segName', 'segMinBasket'].includes(f) || f.indexOf('social-') === 0) return;
      this.render();
    }
    putShop(id, patch) {
      ecrire(this.base, 'PUT', '/newsletter/magasins/' + encodeURIComponent(id), Object.assign({ role: this.role() }, patch)).then(r => {
        if (r && r.magasin) { const ms = this.magasins(); const i = ms.findIndex(x => x.id === id); if (i >= 0) ms[i] = r.magasin; if (this.data().moi && this.data().moi.id === id) { this.data().moi = r.magasin; this.data().canSend = r.magasin.canSend; } }
        this.render();
      }).catch(err => { this.notify(err.message); this.charger(); });
    }
    saveSegment() {
      const s = this.s, t = this.t();
      if (!s.segName.trim()) return;
      const parts = [];
      if (s.segShop !== 'all') parts.push(`${t.segRuleShop} = ${this.nomShop(s.segShop)}`);
      if (s.segProduct !== 'all') parts.push(`${t.segRuleProduct} = ${s.segProduct}`);
      parts.push(String(t['p' + s.segPeriod.replace('dormant45', 'Dormant')] || '').toLowerCase());
      const mb = parseFloat(s.segMinBasket); if (mb > 0) parts.push(`${t.segRuleBasket} ${mb} €`);
      ecrire(this.base, 'POST', '/newsletter/segments', { role: this.role(), name: s.segName.trim(), src: s.sourceId, shop: s.segShop === 'all' ? '' : s.segShop, product: s.segProduct === 'all' ? '' : s.segProduct, period: s.segPeriod, minBasket: mb > 0 ? mb : '', count: this.segEstimate(), rule: parts.join(' · ') })
        .then(r => { if (r && r.segment) { this.data().segments.unshift(r.segment); s.segmentId = r.segment.id; } Object.assign(s, { segForm: false, segName: '', segShop: 'all', segProduct: 'all', segPeriod: '365', segMinBasket: '' }); this.render(); })
        .catch(err => this.notify(err.message));
    }
    nextStep() {
      const s = this.s, t = this.t();
      if (s.step === 1 && !s.sendLangs.length) { this.notify(t.langNone); return; }
      if (s.step < 5) { s.step++; this.render(); window.scrollTo({ top: 0 }); return; }
      if (s.busy) return;
      const seg = this.segById(s.segmentId), tp = this.tpl();
      const par = (obj, dflt) => { const o = {}; s.sendLangs.forEach(l => { o[l] = obj[l] ?? (dflt ? (dflt[l] ?? dflt.fr ?? '') : ''); }); return o; };
      const social = {}; ['linkedin', 'instagram', 'slack'].forEach(k => { social[k] = { on: !!s.socialOn[k], text: s.socialTexts[k] ?? this.gen()[k], reviewed: !!s.socialReviewed[k] }; });
      const nom = this.L(tp.name) + ' — ' + (seg.shop ? this.nomShop(seg.shop) : 'Réseau');
      s.busy = true; this.render();
      ecrire(this.base, 'POST', '/newsletter/campagnes', { role: this.role(), name: nom, segment: seg.id, langs: s.sendLangs, templateId: tp.id, channel: s.channel,
        subjects: par(s.subjects, tp.subject), bodies: par(s.bodies, tp.body), sms: s.channel === 'email' ? {} : par(s.smsTexts, tp.sms), social,
        sendMode: s.sendMode, trigger: s.trigger, date: s.date, time: s.time, maxVouchers: s.maxVouchers, sender: this.sender().id, testSent: s.testSent })
        .then(r => {
          s.busy = false;
          this.notify(s.sendMode === 'auto' ? 'Automatisation créée — mode test, rien ne part.' : 'Campagne programmée — mode test, rien ne part.');
          Object.assign(s, ETAT0(), { view: 'dashboard', date: s.date, segmentId: seg.id, sourceId: seg.src, senderId: s.senderId });
          return this.charger();
        })
        .catch(err => { s.busy = false; this.notify(err.message); this.render(); });
    }
  }

  const hotes = new Map();
  window.CockpitNewsletter = {
    /** Un hôte par nœud d'accueil ; le rôle peut changer d'un montage à l'autre (vue magasin). */
    mount(host, opts) {
      if (!host) return null;
      styles();
      let h = hotes.get(host);
      if (!h) { h = new NL(host, opts); hotes.set(host, h); }
      else if (opts && opts.role != null) h.setRole(opts.role);
      h.mount(host);
      return h;
    },
    TEMPLATES, I18N,
  };
})();
