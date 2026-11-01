# Politique de confidentialité et registre des traitements (article 30 du RGPD) du réseau L'Atelier by, version du
# 1er novembre 2026 : deux pages A4 en français et les mêmes deux pages en néerlandais, deux PDF séparés, rendus par le
# Chromium de Playwright (rendre.js, à côté de ce fichier), qui refuse une page qui déborderait de sa feuille.
# Le contenu décrit ce que les outils du dépôt font réellement (cockpit, application employés, tablette, visites, newsletter,
# prospection, primes, contrôle Facebook, IA), d'après un inventaire du code fait le 1er novembre 2026. Les mesures de
# sécurité citées sont celles qui existent dans le code. Ce qui n'est pas dans le dépôt (dénomination sociale, adresse,
# numéro d'entreprise, contact vie privée, délégué, hébergeur et localisation des prestataires) est laissé entre crochets,
# à compléter. Les durées de conservation sont celles retenues par le responsable ; le code ne les applique pas encore.
#   python3 generer.py   → Confidentialite-registre-FR.pdf et Privacyverklaring-register-NL.pdf dans ce dossier
import base64, os, re, subprocess

ICI = os.path.dirname(os.path.abspath(__file__))
RACINE = os.path.abspath(os.path.join(ICI, '..', '..', '..'))
FONTES = 'file://' + RACINE + '/public/assets/ds/fonts/'
LOGO = 'data:image/png;base64,' + base64.b64encode(open(RACINE + '/public/assets/img/logo.png', 'rb').read()).decode()
TMP = '/tmp/confidentialite'


def insec(s):
    """Espaces insécables devant la ponctuation haute et dans « 1 000 », et champs à compléter mis en évidence."""
    s = re.sub(r'\[\[(.+?)\]\]', '<span class="ac">[\\1]</span>', s)
    s = re.sub(r'(\d) (?=(%|€|ans|an|jaar|jours|dagen|mois))', '\\1\u00a0', s)
    s = re.sub(r' ([:;?!»])', '\u00a0\\1', s)
    s = re.sub(r'(«) ', '\\1\u00a0', s)
    return s


T = {
 'fr': dict(
  lang='fr', fichier='Confidentialite-registre-FR.pdf', titreDoc='L’Atelier by · Politique de confidentialité et registre des traitements',
  hd='Protection des données · novembre 2026', code='FR',
  h1='Politique de confidentialité',
  sous='Comment le réseau L’Atelier by utilise vos données personnelles, et ce que vous pouvez demander. Version du 1<sup>er</sup> novembre 2026.',
  sections=[
   ('Qui est responsable', [
    '[[dénomination sociale]], qui exploite la marque L’Atelier by, [[adresse du siège]], numéro d’entreprise [[BCE]], est responsable des traitements décrits ici. Pour toute question sur vos données, écrivez à [[adresse e-mail vie privée]].',
    'Nos franchisés restent les employeurs de leur personnel. Pour la paie et les contrats de travail, c’est le franchisé qui est responsable.']),
   ('Ce que nous utilisons, et pourquoi', [
    ('Équipes des magasins.', 'Les plannings et les ventes de chaque vendeuse servent aux classements et au calcul des primes. Les photos des tâches servent au contrôle de la qualité. L’application employés affiche votre profil (nom, téléphone, e-mail), vos horaires et vos primes.'),
    ('Franchisés et consultants.', 'Les visites de magasin (notes, photos avec leur position GPS), le suivi du franchisé, les rapports de client mystère, les comptes d’accès et le journal des actions.'),
    ('Clients.', 'La newsletter (e-mail, nom, langue, date d’anniversaire, achats), avec le suivi des ouvertures et des clics. Les remarques laissées sur la tablette en magasin. Les commandes des clients professionnels.'),
    ('Prospects et candidats franchisés.', 'Coordonnées professionnelles et notes de rencontre.'),
    ('Avis et réseaux sociaux.', 'Les avis Google publics sur nos magasins et sur ceux de concurrents (nom affiché, note, texte), et les publications Facebook de nos magasins.')]),
   ('D’où viennent les données', [
    'La plupart viennent de vous ou de votre magasin. D’autres viennent de notre logiciel de caisse (TFBuddy), de sources publiques (avis Google, OpenStreetMap pour les entreprises de la zone) ou de fichiers de clients importés.']),
   ('Sur quelle base', [
    'Le contrat, pour la franchise, les clients professionnels et les fournisseurs. Notre intérêt légitime, pour piloter le réseau, contrôler la qualité, protéger la marque, sécuriser nos outils et prospecter des entreprises. Votre consentement, pour la newsletter et les notifications. Vous pouvez le retirer à tout moment, par exemple avec le lien de désinscription en bas de chaque e-mail.']),
   ('L’intelligence artificielle', [
    'Pour les photos de tâches et les publications Facebook, un outil d’IA (Anthropic) propose une note ou un avis. Il ne reçoit aucun nom. La décision revient toujours à une personne.']),
   ('Qui reçoit vos données', [
    'Uniquement nos prestataires, pour ce dont ils ont besoin : l’éditeur de notre logiciel de caisse et de gestion (TFBuddy), notre hébergeur, notre service d’envoi d’e-mails, Anthropic pour l’IA, Google pour les avis et l’agenda, Meta pour Facebook, les services de notification des navigateurs, et l’agence Collectif Marketing pour les publications. Nous ne vendons jamais de données.']),
   ('Hors de l’Union européenne', [
    'Anthropic, Google, Meta et les services de notification sont américains. Ces transferts reposent sur le cadre UE–États-Unis de protection des données quand le prestataire y est certifié, sinon sur les clauses contractuelles types de la Commission européenne.']),
   ('Combien de temps', [
    'Le moins longtemps possible. La durée de chaque traitement figure dans le registre, en page 2. Par exemple : la newsletter jusqu’à votre désinscription, les remarques en magasin un an, les prospects trois ans sans contact.']),
   ('Sécurité', [
    'Les mots de passe ne sont jamais stockés en clair, les sessions sont signées, les notifications sont chiffrées de bout en bout et les fichiers déposés ne peuvent pas s’exécuter sur le serveur. Seules les personnes qui en ont besoin pour leur travail doivent avoir accès aux données.']),
   ('Cookies', [
    'Nos outils n’utilisent que des cookies techniques, pour garder votre session ouverte, et la mémoire du navigateur pour vos réglages. Pas de publicité, pas de mesure d’audience.']),
   ('Vos droits', [
    'Vous pouvez demander à voir vos données, les corriger, les effacer, en limiter l’usage, vous opposer à leur utilisation (toujours, pour la prospection) ou les recevoir dans un format lisible. Écrivez à [[adresse e-mail vie privée]] : nous répondons dans le mois.',
    'Si notre réponse ne vous satisfait pas, vous pouvez porter plainte auprès de l’Autorité de protection des données, rue de la Presse 35, 1000 Bruxelles, contact@apd-gba.be, www.autoriteprotectiondonnees.be.']),
   ('Modifications', [
    'Nous mettons cette politique à jour quand nos outils changent. La date en haut de la page indique la dernière version.']),
  ],
  h1b='Registre des traitements',
  sousb='Article 30 du RGPD. Tenu par [[dénomination sociale]], mis à jour le 1<sup>er</sup> novembre 2026.',
  meta=[('Responsable du traitement', '[[dénomination sociale]], [[adresse du siège]], [[BCE]]'),
        ('Contact vie privée', '[[adresse e-mail vie privée]]'),
        ('Délégué (DPO)', '[[nom, ou « non désigné »]]'),
        ('Hébergement', '[[hébergeur, pays]]')],
  th=['Traitement et finalité', 'Personnes', 'Données', 'Base légale', 'Destinataires', 'Conservation'],
  reg=[
   ('Planning et ventes des vendeuses', 'classements, pilotage des magasins', 'Personnel des magasins', 'Nom, téléphone, langue, horaires, ventes par jour', 'Intérêt légitime', 'TFBuddy ; magasins par e-mail', '2 ans'),
   ('Primes', 'calcul et versement en bons', 'Personnel des magasins', 'Nom, magasin, ventes par heure, montants', 'Intérêt légitime (programme de primes)', 'TFBuddy', '5 ans'),
   ('Application employés', 'horaires, tâches, primes, profil', 'Personnel des magasins', 'Identifiant, nom, téléphone, e-mail, horaires, photos de tâches', 'Contrat', 'TFBuddy', 'Durée de l’emploi'),
   ('Contrôle des tâches en photo', 'qualité ; note proposée par l’IA, validée par une personne', 'Personnel des magasins', 'Photos, heure, auteur de la tâche, note, commentaire', 'Intérêt légitime', 'TFBuddy ; Anthropic, photos sans nom (USA)', '2 ans'),
   ('Visites des consultants', 'contrôle des standards, plans d’action, rappels', 'Consultants, franchisés', 'Notes, photos et leur position GPS, actions, effectifs du magasin', 'Contrat de franchise, intérêt légitime', 'Services de notification (USA) ; Google Agenda sur abonnement (USA)', '3 ans'),
   ('Suivi des franchisés', 'évaluations, clients mystères, notes, plan annuel', 'Franchisés et leurs équipes', 'Appréciations, commentaires, rapports, engagements', 'Contrat de franchise', 'Le magasin, par e-mail', 'Fin du contrat + 5 ans'),
   ('Remarques sur la production', 'qualité des produits', 'Opérateurs de production', 'Nom, date, produit, motif, commentaire', 'Intérêt légitime', 'Aucun', '1 an'),
   ('Consultants et utilisateurs des outils', 'comptes, tâches, rapports, journal', 'Consultants, personnel du siège', 'Nom, e-mail, tarif journalier, tâches et notes, actions dans les outils', 'Contrat, intérêt légitime', 'TFBuddy ; service d’e-mail', 'Collaboration + 1 an ; journal 1 an'),
   ('Newsletter', 'offres, anniversaire, relances', 'Clients, candidats franchisés', 'E-mail, téléphone, nom, langue, anniversaire, achats, ouvertures et clics', 'Consentement', 'Service d’e-mail', 'Jusqu’à la désinscription ; 3 ans sans achat'),
   ('Remarques des clients', 'tablette en magasin', 'Clients', 'Texte libre, langue, date, adresse IP de la tablette', 'Intérêt légitime', 'Aucun', '1 an'),
   ('Clients professionnels', 'commandes, factures', 'Clients professionnels', 'Nom de la société ou de la personne, montants, factures', 'Contrat, obligation légale', 'TFBuddy', 'Délai légal comptable'),
   ('Prospection et candidats franchisés', 'entreprises de la zone, suivi des rencontres', 'Entreprises, indépendants, candidats', 'Coordonnées professionnelles, notes, prochaine action', 'Intérêt légitime ; mesures précontractuelles', 'OpenStreetMap, coordonnées géographiques seulement', '3 ans sans contact'),
   ('Avis Google', 'réputation de nos magasins, étude de la concurrence', 'Auteurs d’avis', 'Nom affiché, note, texte, date, photo', 'Intérêt légitime', 'Google (USA)', '2 ans ; concurrents 30 jours'),
   ('Contrôle des publications Facebook', 'respect de la charte de marque', 'Franchisés, personnes visibles sur les images', 'Texte, images, auteur, décision', 'Intérêt légitime, contrat de franchise', 'Meta (USA) ; Anthropic (USA) ; Collectif Marketing', '2 ans'),
   ('Notifications push', 'alertes de stock, rappels de visite', 'Utilisateurs des outils', 'Adresse d’abonnement, clés, navigateur', 'Consentement', 'Services de notification des navigateurs (USA)', 'Jusqu’au désabonnement'),
   ('Fournisseurs', 'commandes, réclamations', 'Contacts chez les fournisseurs', 'Nom, e-mail, photos de réclamation', 'Contrat', 'Service d’e-mail', 'Relation + délai légal comptable'),
  ],
  notes=[
   'Les durées courent à partir de la fin de la relation ou du dernier contact, sauf mention contraire.',
   'Transferts hors de l’Union européenne : cadre UE–États-Unis de protection des données, ou clauses contractuelles types de la Commission européenne.',
   'Mesures de sécurité communes : mots de passe hachés, sessions signées, requêtes préparées, notifications chiffrées de bout en bout, fichiers déposés non exécutables, jetons pour les tâches automatiques.',
   'Localisation de TFBuddy, de l’hébergeur et du service d’e-mail : [[à compléter]].',
  ],
  pied='L’Atelier by · politique de confidentialité et registre des traitements · version du 1<sup>er</sup> novembre 2026',
 ),
 'nl': dict(
  lang='nl', fichier='Privacyverklaring-register-NL.pdf', titreDoc='L’Atelier by · Privacyverklaring en register van de verwerkingen',
  hd='Gegevensbescherming · november 2026', code='NL',
  h1='Privacyverklaring',
  sous='Hoe het net van L’Atelier by uw persoonsgegevens gebruikt, en wat u kan vragen. Versie van 1 november 2026.',
  sections=[
   ('Wie is verantwoordelijk', [
    '[[vennootschapsnaam]], die het merk L’Atelier by uitbaat, [[adres van de zetel]], ondernemingsnummer [[KBO]], is verantwoordelijk voor de verwerkingen in deze verklaring. Voor vragen over uw gegevens: [[e-mailadres privacy]].',
    'Onze franchisenemers blijven de werkgever van hun personeel. Voor loon en arbeidscontracten is de franchisenemer verantwoordelijk.']),
   ('Wat we gebruiken, en waarom', [
    ('Winkelteams.', 'De planning en de verkoop van elke verkoopster dienen voor de rangschikkingen en de berekening van de premies. Foto’s van taken dienen voor de kwaliteitscontrole. De medewerkersapp toont uw profiel (naam, telefoon, e-mail), uw uren en uw premies.'),
    ('Franchisenemers en consultants.', 'Winkelbezoeken (notities, foto’s met hun gps-positie), de opvolging van de franchisenemer, mystery-shopperrapporten, toegangsaccounts en het logboek van acties.'),
    ('Klanten.', 'De nieuwsbrief (e-mail, naam, taal, verjaardag, aankopen), met opvolging van openingen en klikken. Opmerkingen op de tablet in de winkel. Bestellingen van zakelijke klanten.'),
    ('Prospecten en kandidaat-franchisenemers.', 'Professionele contactgegevens en notities van gesprekken.'),
    ('Reviews en sociale media.', 'Openbare Google-reviews over onze winkels en die van concurrenten (getoonde naam, score, tekst), en de Facebookberichten van onze winkels.')]),
   ('Waar de gegevens vandaan komen', [
    'De meeste komen van uzelf of van uw winkel. Andere komen uit onze kassasoftware (TFBuddy), uit openbare bronnen (Google-reviews, OpenStreetMap voor bedrijven in de buurt) of uit geïmporteerde klantenbestanden.']),
   ('Op welke basis', [
    'Het contract, voor de franchise, zakelijke klanten en leveranciers. Ons gerechtvaardigd belang, om het net te sturen, de kwaliteit te controleren, het merk te beschermen, onze tools te beveiligen en bedrijven te prospecteren. Uw toestemming, voor de nieuwsbrief en de meldingen. U kan die altijd intrekken, bijvoorbeeld met de uitschrijflink onderaan elke e-mail.']),
   ('Artificiële intelligentie', [
    'Voor foto’s van taken en Facebookberichten stelt een AI-tool (Anthropic) een score of een advies voor. Die krijgt geen namen. De beslissing ligt altijd bij een mens.']),
   ('Wie uw gegevens krijgt', [
    'Alleen onze dienstverleners, voor wat ze nodig hebben: de leverancier van onze kassa- en beheersoftware (TFBuddy), onze hostingpartner, onze e-maildienst, Anthropic voor AI, Google voor reviews en agenda, Meta voor Facebook, de meldingsdiensten van de browsers, en het bureau Collectif Marketing voor de berichten. We verkopen nooit gegevens.']),
   ('Buiten de Europese Unie', [
    'Anthropic, Google, Meta en de meldingsdiensten zijn Amerikaans. Die doorgiften steunen op het EU-VS-kader voor gegevensbescherming als de dienstverlener gecertificeerd is, en anders op de standaardcontractbepalingen van de Europese Commissie.']),
   ('Hoe lang', [
    'Zo kort mogelijk. De termijn van elke verwerking staat in het register, op pagina 2. Bijvoorbeeld: de nieuwsbrief tot u zich uitschrijft, opmerkingen in de winkel één jaar, prospecten drie jaar zonder contact.']),
   ('Beveiliging', [
    'Wachtwoorden worden nooit leesbaar bewaard, sessies zijn ondertekend, meldingen zijn end-to-end versleuteld en geüploade bestanden kunnen niet op de server worden uitgevoerd. Alleen wie de gegevens nodig heeft voor zijn werk, mag er toegang toe hebben.']),
   ('Cookies', [
    'Onze tools gebruiken alleen technische cookies, om uw sessie open te houden, en het geheugen van de browser voor uw instellingen. Geen reclame, geen bezoekersmeting.']),
   ('Uw rechten', [
    'U kan vragen om uw gegevens in te zien, te verbeteren, te wissen, het gebruik ervan te beperken, u ertegen te verzetten (altijd, voor prospectie) of ze te krijgen in een leesbaar formaat. Schrijf naar [[e-mailadres privacy]]: we antwoorden binnen de maand.',
    'Bent u niet tevreden met ons antwoord, dan kan u klacht indienen bij de Gegevensbeschermingsautoriteit, Drukpersstraat 35, 1000 Brussel, contact@apd-gba.be, www.gegevensbeschermingsautoriteit.be.']),
   ('Wijzigingen', [
    'We passen deze verklaring aan wanneer onze tools veranderen. De datum bovenaan de pagina toont de laatste versie.']),
  ],
  h1b='Register van de verwerkingen',
  sousb='Artikel 30 van de AVG. Bijgehouden door [[vennootschapsnaam]], bijgewerkt op 1 november 2026.',
  meta=[('Verwerkingsverantwoordelijke', '[[vennootschapsnaam]], [[adres van de zetel]], [[KBO]]'),
        ('Contact privacy', '[[e-mailadres privacy]]'),
        ('Functionaris (DPO)', '[[naam, of ‘niet aangesteld’]]'),
        ('Hosting', '[[hostingpartner, land]]')],
  th=['Verwerking en doel', 'Betrokkenen', 'Gegevens', 'Rechtsgrond', 'Ontvangers', 'Bewaartermijn'],
  reg=[
   ('Planning en verkoop van de verkoopsters', 'rangschikkingen, sturing van de winkels', 'Winkelpersoneel', 'Naam, telefoon, taal, uren, verkoop per dag', 'Gerechtvaardigd belang', 'TFBuddy; winkels per e-mail', '2 jaar'),
   ('Premies', 'berekening en uitbetaling in bonnen', 'Winkelpersoneel', 'Naam, winkel, verkoop per uur, bedragen', 'Gerechtvaardigd belang (premieprogramma)', 'TFBuddy', '5 jaar'),
   ('Medewerkersapp', 'uren, taken, premies, profiel', 'Winkelpersoneel', 'Login, naam, telefoon, e-mail, uren, foto’s van taken', 'Contract', 'TFBuddy', 'Duur van de tewerkstelling'),
   ('Controle van taken met foto’s', 'kwaliteit; score voorgesteld door AI, goedgekeurd door een mens', 'Winkelpersoneel', 'Foto’s, tijdstip, uitvoerder, score, commentaar', 'Gerechtvaardigd belang', 'TFBuddy; Anthropic, foto’s zonder naam (VS)', '2 jaar'),
   ('Bezoeken van de consultants', 'controle van de standaarden, actieplannen, herinneringen', 'Consultants, franchisenemers', 'Notities, foto’s en hun gps-positie, acties, personeelsbezetting', 'Franchisecontract, gerechtvaardigd belang', 'Meldingsdiensten (VS); Google Agenda bij abonnement (VS)', '3 jaar'),
   ('Opvolging van de franchisenemers', 'evaluaties, mystery shoppers, notities, jaarplan', 'Franchisenemers en hun teams', 'Beoordelingen, commentaren, rapporten, engagementen', 'Franchisecontract', 'De winkel, per e-mail', 'Einde contract + 5 jaar'),
   ('Opmerkingen over de productie', 'kwaliteit van de producten', 'Productieoperatoren', 'Naam, datum, product, reden, commentaar', 'Gerechtvaardigd belang', 'Geen', '1 jaar'),
   ('Consultants en gebruikers van de tools', 'accounts, taken, rapporten, logboek', 'Consultants, personeel van de hoofdzetel', 'Naam, e-mail, dagtarief, taken en notities, acties in de tools', 'Contract, gerechtvaardigd belang', 'TFBuddy; e-maildienst', 'Samenwerking + 1 jaar; logboek 1 jaar'),
   ('Nieuwsbrief', 'aanbiedingen, verjaardag, herinneringen', 'Klanten, kandidaat-franchisenemers', 'E-mail, telefoon, naam, taal, verjaardag, aankopen, openingen en klikken', 'Toestemming', 'E-maildienst', 'Tot uitschrijving; 3 jaar zonder aankoop'),
   ('Opmerkingen van klanten', 'tablet in de winkel', 'Klanten', 'Vrije tekst, taal, datum, IP-adres van de tablet', 'Gerechtvaardigd belang', 'Geen', '1 jaar'),
   ('Zakelijke klanten', 'bestellingen, facturen', 'Zakelijke klanten', 'Naam van het bedrijf of de persoon, bedragen, facturen', 'Contract, wettelijke verplichting', 'TFBuddy', 'Wettelijke boekhoudtermijn'),
   ('Prospectie en kandidaat-franchisenemers', 'bedrijven in de buurt, opvolging van gesprekken', 'Bedrijven, zelfstandigen, kandidaten', 'Professionele contactgegevens, notities, volgende stap', 'Gerechtvaardigd belang; precontractuele maatregelen', 'OpenStreetMap, alleen geografische coördinaten', '3 jaar zonder contact'),
   ('Google-reviews', 'reputatie van onze winkels, studie van de concurrentie', 'Auteurs van reviews', 'Getoonde naam, score, tekst, datum, foto', 'Gerechtvaardigd belang', 'Google (VS)', '2 jaar; concurrenten 30 dagen'),
   ('Controle van Facebookberichten', 'naleving van de merkrichtlijnen', 'Franchisenemers, personen op de beelden', 'Tekst, beelden, auteur, beslissing', 'Gerechtvaardigd belang, franchisecontract', 'Meta (VS); Anthropic (VS); Collectif Marketing', '2 jaar'),
   ('Pushmeldingen', 'voorraadalarmen, herinneringen aan bezoeken', 'Gebruikers van de tools', 'Abonnementsadres, sleutels, browser', 'Toestemming', 'Meldingsdiensten van de browsers (VS)', 'Tot uitschrijving'),
   ('Leveranciers', 'bestellingen, klachten', 'Contactpersonen bij leveranciers', 'Naam, e-mail, foto’s bij klachten', 'Contract', 'E-maildienst', 'Relatie + wettelijke boekhoudtermijn'),
  ],
  notes=[
   'De termijnen lopen vanaf het einde van de relatie of het laatste contact, tenzij anders vermeld.',
   'Doorgiften buiten de Europese Unie: EU-VS-kader voor gegevensbescherming, of standaardcontractbepalingen van de Europese Commissie.',
   'Gemeenschappelijke beveiliging: gehashte wachtwoorden, ondertekende sessies, voorbereide queries, end-to-end versleutelde meldingen, geüploade bestanden niet uitvoerbaar, tokens voor automatische taken.',
   'Locatie van TFBuddy, de hostingpartner en de e-maildienst: [[in te vullen]].',
  ],
  pied='L’Atelier by · privacyverklaring en register van de verwerkingen · versie van 1 november 2026',
 ),
}

CSS = '''
@font-face{font-family:'Gotham';src:url('%(f)sGotham_Light.otf');font-weight:400}
@font-face{font-family:'Gotham';src:url('%(f)sGotham_Medium.otf');font-weight:500}
@font-face{font-family:'Vank';src:url('%(f)sGC_Vank.ttf')}
@page{size:A4;margin:0}
*{box-sizing:border-box}
html,body{margin:0;padding:0}
body{font-family:Gotham,Helvetica,Arial,sans-serif;color:#221E1A;font-size:7.6pt;line-height:1.45;background:#fff;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.page{width:210mm;height:297mm;padding:9mm 13mm 0;position:relative;overflow:hidden;display:flex;flex-direction:column;break-after:page}
.page:last-child{break-after:auto}
.hd{display:flex;justify-content:space-between;align-items:center;border-bottom:2px solid #8D1D2C;padding-bottom:1.6mm;margin-bottom:4mm;font-size:6.8pt;letter-spacing:.08em;text-transform:uppercase;color:#7a736a}
.hd .logo{height:4.4mm;width:auto;display:block}
h1{font-family:Vank,Georgia,serif;font-weight:400;font-size:24pt;margin:0;letter-spacing:-.01em;line-height:1.05}
.sous{font-size:8.4pt;color:#7a736a;margin:1.4mm 0 4mm}
.flux{column-count:2;column-gap:7mm;font-size:8.1pt;line-height:1.5}
.bloc{break-inside:avoid;margin-bottom:3.6mm}
.bloc h3{font-family:Vank,Georgia,serif;font-weight:400;font-size:12.5pt;color:#8D1D2C;margin:0 0 1mm;padding-bottom:.6mm;border-bottom:.8pt solid #e6d6d2;line-height:1.15}
.bloc p{margin:0 0 1.3mm}
.bloc ul{margin:0;padding:0;list-style:none}
.bloc li{position:relative;padding-left:3.2mm;margin-bottom:1.1mm}
.bloc li:before{content:'';position:absolute;left:0;top:1.4mm;width:1.4mm;height:1.4mm;border-radius:50%%;background:#8D1D2C}
.ld{color:#8D1D2C;font-weight:500}
sup{font-size:.7em;line-height:0}
.ac{color:#8D1D2C;background:#f6e9ea;border-bottom:.6pt dashed #8D1D2C;padding:0 .4mm;white-space:nowrap}
.meta{display:grid;grid-template-columns:1.7fr 1fr 1fr 1fr;gap:2.4mm;margin-bottom:3mm}
.meta div{border:1px solid #e6e0d8;border-radius:6px;background:#fbf9f5;padding:1.5mm 2.4mm;font-size:6.8pt;line-height:1.4}
.meta .k{display:block;font-size:6pt;letter-spacing:.08em;text-transform:uppercase;color:#7a736a;margin-bottom:.6mm}
.meta .ac{white-space:normal}
table.reg{width:100%%;border-collapse:collapse;font-size:6.5pt;line-height:1.35}
.reg th{font-size:5.9pt;letter-spacing:.06em;text-transform:uppercase;color:#7a736a;font-weight:500;text-align:left;padding:1.2mm 1.4mm;border-bottom:1pt solid #221E1A;vertical-align:bottom}
.reg td{padding:.95mm 1.3mm;border-bottom:.5pt solid #EAE3D8;vertical-align:top}
.reg tr:nth-child(even) td{background:#fcfaf7}
.reg td.t .n{color:#8D1D2C;font-weight:500;display:block}
.reg td.t small{color:#7a736a;font-size:6.2pt}
.reg td.num{font-family:Vank,Georgia,serif;font-size:8.5pt;color:#8D1D2C;padding-right:0}
.notes{margin-top:3mm;font-size:6.6pt;color:#5a534b;border-left:3px solid #8D1D2C;background:#fbf9f5;padding:1.8mm 3mm}
.notes p{margin:0 0 .8mm}
.bas{margin-top:auto;padding:2mm 0 6mm}
.pied{display:flex;justify-content:space-between;font-size:6.2pt;color:#9a938a;border-top:.5pt solid #e6e0d8;padding-top:1.4mm}
'''


def bloc(titre, contenu):
    parts = []
    for x in contenu:
        if isinstance(x, tuple):
            parts.append(f'<li><span class="ld">{x[0]}</span> {x[1]}</li>')
        else:
            parts.append(f'<p>{x}</p>')
    html = ''.join(parts)
    if any(isinstance(x, tuple) for x in contenu):
        html = '<ul>' + html + '</ul>'
    return f'<div class="bloc"><h3>{titre}</h3>{html}</div>'


def entete(t):
    return f'<div class="hd"><img class="logo" src="{LOGO}" alt="L’Atelier by"><span>{t["hd"]}</span><span>{t["code"]}</span></div>'


def pied(t, n):
    return f'<div class="bas"><div class="pied"><span>{t["pied"]}</span><span>{n} / 2</span></div></div>'


def pages(t):
    p1 = f'''<section class="page">{entete(t)}
<h1>{t['h1']}</h1><div class="sous">{t['sous']}</div>
<div class="flux">{''.join(bloc(a, b) for a, b in t['sections'])}</div>
{pied(t, 1)}</section>'''
    lignes = ''.join(f'<tr><td class="num">{i}</td><td class="t"><span class="n">{r[0]}</span><small>{r[1]}</small></td>'
                     + ''.join(f'<td>{c}</td>' for c in r[2:]) + '</tr>' for i, r in enumerate(t['reg'], 1))
    p2 = f'''<section class="page">{entete(t)}
<h1>{t['h1b']}</h1><div class="sous">{t['sousb']}</div>
<div class="meta">{''.join(f'<div><span class="k">{k}</span>{v}</div>' for k, v in t['meta'])}</div>
<table class="reg"><colgroup><col style="width:3%"><col style="width:22%"><col style="width:12%"><col style="width:21%"><col style="width:13%"><col style="width:16%"><col style="width:13%"></colgroup><thead><tr><th></th>{''.join(f'<th>{h}</th>' for h in t['th'])}</tr></thead><tbody>{lignes}</tbody></table>
<div class="notes">{''.join(f'<p>{x}</p>' for x in t['notes'])}</div>
{pied(t, 2)}</section>'''
    return insec(p1 + p2)


def html(L):
    t = T[L]
    doc = f'''<!doctype html><html lang="{t['lang']}"><head><meta charset="utf-8"><title>{t['titreDoc']}</title><style>{CSS % {'f': FONTES}}</style></head><body>{pages(t)}</body></html>'''
    assert '<b>' not in doc, 'du gras a échappé'
    return doc


if __name__ == '__main__':
    os.makedirs(TMP, exist_ok=True)
    jobs = []
    for L in ('fr', 'nl'):
        h = TMP + '/' + L + '.html'
        open(h, 'w', encoding='utf-8').write(html(L))
        jobs += [h, os.path.join(ICI, T[L]['fichier'])]
    subprocess.run(['node', os.path.join(ICI, 'rendre.js')] + jobs, check=True)
