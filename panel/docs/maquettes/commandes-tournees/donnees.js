/* Les données de la maquette « Commandes et tournées » — magasin 3, Gosselies, mardi 24/11/2026 à 10:40.
 *
 * Réel (lu au panel le 24/11/2026, /shops et /shops/3/client-orders) : le magasin, son adresse, ses heures (06:00 – 18:00),
 * l'ordre de grandeur des montants (petit-déjeuner de bureau 171,75 €, lunchs 68,90 € et 80,70 €, précommandes du
 * comptoir 15,90 € et 19,90 € retirées à 16:00), la règle « commander jusqu'à la veille 16 h » (modèles du portail
 * Commercial). Illustratif : les bureaux, les tournées, les livreurs et toutes les commandes du jour. Aucun client réel. */
window.MQ = (() => {
  const MAINTENANT = '10:40';
  const CANAUX = {
    webshop: { lib: 'Webshop', coul: '#1B6FA8' },
    comptoir: { lib: 'Comptoir', coul: '#8D1D2C' },
    bureau: { lib: 'Bureau', coul: '#3f7a52' },
  };
  // Statuts, dans l'ordre de la journée d'une commande.
  const STATUTS = {
    livree: { lib: 'Livrée', forme: 'ok' },
    remise: { lib: 'Remise', forme: 'ok' },
    prete: { lib: 'Prête', forme: 'pret' },
    prep: { lib: 'En préparation', forme: 'prep' },
    afaire: { lib: 'À préparer', forme: 'neutre' },
    avenir: { lib: 'À venir', forme: 'neutre' },
  };

  // Les bureaux livrés par le magasin (illustratifs). x, y : place sur le plan schématique (0–100).
  const BUREAUX = {
    delvaux: { nom: 'Fiduciaire Delvaux & associés', lieu: 'Aéropôle · av. Jean Mermoz', zone: 'Aéropôle', contact: 'Accueil, 2e étage', pers: 35, x: 70, y: 18 },
    aerotech: { nom: 'Aéro-Tech Services', lieu: 'Aéropôle', zone: 'Aéropôle', contact: 'Salle de réunion B', pers: 12, x: 82, y: 26 },
    renard: { nom: 'Cabinet Renard', lieu: 'Gosselies centre', zone: 'Gosselies', contact: 'Secrétariat', pers: 9, x: 52, y: 40 },
    biolab: { nom: 'Biolab Hainaut', lieu: 'Biopark', zone: 'Biopark', contact: 'Réception labo 4', pers: 22, x: 88, y: 44 },
    numerique: { nom: 'Atelier numérique Charleroi', lieu: 'Charleroi · Ville-Basse', zone: 'Charleroi', contact: '3e étage', pers: 14, x: 60, y: 84 },
    hainaut: { nom: 'Hainaut Assurances', lieu: 'Charleroi · bd Tirou', zone: 'Charleroi', contact: 'Lunchroom', pers: 40, x: 70, y: 76 },
    veto: { nom: 'Clinique vétérinaire du Pircha', lieu: 'Gosselies · rue du Pircha', zone: 'Gosselies', contact: 'Accueil', pers: 6, x: 40, y: 50 },
    horizon: { nom: 'Centre de formation Horizon', lieu: 'Jumet · zoning', zone: 'Jumet', contact: 'Salle 2', pers: 18, x: 46, y: 66 },
    urbanisme: { nom: 'Bureau Lefèvre Architectes', lieu: 'Gosselies · place', zone: 'Gosselies', contact: 'Rez', pers: 7, x: 56, y: 56 },
    technisol: { nom: 'Techni-Sol SRL', lieu: 'Jumet · rue de la Station', zone: 'Jumet', contact: 'Atelier, bureau du chef', pers: 7, x: 34, y: 70 },
    mutualis: { nom: 'Mutualis, agence de Jumet', lieu: 'Jumet · place', zone: 'Jumet', contact: 'Guichet', pers: 5, x: 26, y: 62 },
    lambot: { nom: 'Bureau d’études Lambot', lieu: 'Courcelles · rue de Trazegnies', zone: 'Courcelles', contact: '1er étage', pers: 11, x: 10, y: 54 },
    dumont: { nom: 'Garage Dumont', lieu: 'Courcelles · chaussée', zone: 'Courcelles', contact: 'Réception', pers: 8, x: 16, y: 42 },
  };

  const TOURNEES = [
    { id: 'matin', nom: 'Matin · Aéropôle', court: 'Aéropôle', depart: '07:15', retour: '08:35', livreur: 'Thomas D.', vehicule: 'Camionnette 1', etat: 'terminee',
      bureaux: ['delvaux', 'aerotech', 'renard', 'biolab'] },
    { id: 'midi-cha', nom: 'Midi · Gosselies – Charleroi', court: 'Charleroi', depart: '11:15', retour: '12:40', livreur: 'Nadia R.', vehicule: 'Camionnette 2', etat: 'prep',
      bureaux: ['veto', 'urbanisme', 'horizon', 'hainaut', 'numerique'] },
    { id: 'midi-cou', nom: 'Midi · Jumet – Courcelles', court: 'Courcelles', depart: '11:30', retour: '12:50', livreur: 'Thomas D.', vehicule: 'Camionnette 1', etat: 'afaire',
      bureaux: ['technisol', 'mutualis', 'lambot', 'dumont'] },
  ];

  // Les commandes du jour. h : heure de livraison ou de retrait ; t : tournée (null : retrait au magasin) ; arret : ordre dans la tournée.
  const COMMANDES = [
    // Tournée du matin, livrée.
    { n: 4521, canal: 'bureau', h: '07:30', t: 'matin', bureau: 'delvaux', quoi: 'Petit-déjeuner · 35 pers.', montant: 171.75, pay: 'facture', statut: 'livree', fait: '07:28', note: 'Livrer avant 8 h dans le lunchroom' },
    { n: 4522, canal: 'bureau', h: '07:45', t: 'matin', bureau: 'aerotech', quoi: 'Petit-déjeuner · 12 pers.', montant: 64.80, pay: 'facture', statut: 'livree', fait: '07:41' },
    { n: 4498, canal: 'comptoir', h: '08:00', t: 'matin', bureau: 'renard', quoi: '20 viennoiseries · pris au téléphone', montant: 38.00, pay: 'facture', statut: 'livree', fait: '08:06' },
    { n: 4524, canal: 'bureau', h: '08:15', t: 'matin', bureau: 'biolab', quoi: 'Réunion · 8 pers.', montant: 42.40, pay: 'facture', statut: 'livree', fait: '08:19' },
    // Retraits du matin.
    { n: 4507, canal: 'comptoir', h: '09:30', t: null, client: 'Précommande · M. G.', quoi: 'Tarte aux pommes · 8 parts', montant: 19.90, pay: 'caisse', statut: 'remise', fait: '09:34' },
    { n: 4530, canal: 'webshop', h: '10:15', t: null, client: 'Click & collect · S. V.', quoi: '4 croissants, 2 pains', montant: 11.60, pay: 'enligne', statut: 'remise', fait: '10:22' },
    // Tournée de midi, Gosselies – Charleroi.
    { n: 4511, canal: 'comptoir', h: '11:30', t: 'midi-cha', bureau: 'veto', quoi: '6 lunchs · pris par mail', montant: 52.20, pay: 'alivraison', statut: 'prep' },
    { n: 4533, canal: 'bureau', h: '11:55', t: 'midi-cha', bureau: 'horizon', quoi: '18 lunchs', montant: 151.20, pay: 'facture', statut: 'afaire' },
    { n: 4528, canal: 'bureau', h: '12:10', t: 'midi-cha', bureau: 'hainaut', quoi: '14 lunchs', montant: 118.30, pay: 'facture', statut: 'prete', note: 'Badge à l’accueil' },
    { n: 4526, canal: 'bureau', h: '12:20', t: 'midi-cha', bureau: 'numerique', quoi: '9 lunchs', montant: 80.70, pay: 'facture', statut: 'prete' },
    // Tournée de midi, Jumet – Courcelles.
    { n: 4529, canal: 'bureau', h: '11:45', t: 'midi-cou', bureau: 'technisol', quoi: '7 lunchs', montant: 68.90, pay: 'facture', statut: 'afaire' },
    { n: 4531, canal: 'bureau', h: '12:00', t: 'midi-cou', bureau: 'mutualis', quoi: '5 lunchs', montant: 41.50, pay: 'facture', statut: 'afaire' },
    { n: 4515, canal: 'comptoir', h: '12:25', t: 'midi-cou', bureau: 'lambot', quoi: '11 lunchs, 1 tarte · pris par mail', montant: 96.40, pay: 'facture', statut: 'afaire' },
    // Une livraison webshop pas encore placée dans une tournée.
    { n: 4535, canal: 'webshop', h: '12:00', t: null, livrer: true, client: 'Livraison à domicile · 6041 Gosselies', quoi: '2 lunch box', montant: 24.50, pay: 'enligne', statut: 'afaire', sansTournee: true, x: 58, y: 71, proposee: 'midi-cha', entre: [4533, 4528] },
    // Retraits de l'après-midi.
    { n: 4534, canal: 'webshop', h: '12:30', t: null, client: 'Click & collect · L. M.', quoi: '2 lunch box', montant: 23.80, pay: 'enligne', statut: 'afaire' },
    { n: 4519, canal: 'comptoir', h: '13:30', t: null, client: 'Précommande · C. D.', quoi: 'Gâteau d’anniversaire · 12 parts', montant: 32.50, pay: 'caisse', statut: 'prete' },
    { n: 4501, canal: 'comptoir', h: '16:00', t: null, client: 'Précommande · A. P.', quoi: 'Spécial du mois', montant: 15.90, pay: 'caisse', statut: 'avenir' },
    { n: 4503, canal: 'comptoir', h: '16:00', t: null, client: 'Précommande · J. R.', quoi: 'Spécial du mois', montant: 15.90, pay: 'caisse', statut: 'avenir' },
    { n: 4508, canal: 'comptoir', h: '16:00', t: null, client: 'Précommande · F. L.', quoi: 'Tarte au riz · 8 parts', montant: 19.90, pay: 'caisse', statut: 'avenir' },
    { n: 4536, canal: 'webshop', h: '17:00', t: null, client: 'Click & collect · N. B.', quoi: 'Pain, 6 pistolets, éclairs', montant: 18.40, pay: 'enligne', statut: 'avenir' },
  ];

  // Les bureaux de chaque tournée, leur semaine (L M M J V : 1 a commandé, 0 non, null pas encore) et demain (mercredi 25/11).
  // dern : la dernière commande ; panier : la moyenne des 8 dernières ; demain : 'oui' commandé, 'non' pas encore (avant 16:00), 'jamais' ne commande pas le mercredi.
  const RYTHME = {
    veto: { sem: [1, 1, null, null, null], dern: 'aujourd’hui', panier: 48.60, demain: 'oui', freq: '2 × / sem.' },
    urbanisme: { sem: [0, 0, null, null, null], dern: 'il y a 12 jours', panier: 39.10, demain: 'non', freq: 'irrégulier', relance: true },
    horizon: { sem: [1, 1, null, null, null], dern: 'aujourd’hui', panier: 142.00, demain: 'oui', freq: 'chaque jour' },
    hainaut: { sem: [0, 1, null, null, null], dern: 'aujourd’hui', panier: 109.80, demain: 'non', freq: 'mar. et jeu.', habituel: 'jamais' },
    numerique: { sem: [1, 1, null, null, null], dern: 'aujourd’hui', panier: 76.40, demain: 'non', freq: 'chaque jour', relance: true },
    delvaux: { sem: [1, 1, null, null, null], dern: 'aujourd’hui', panier: 158.30, demain: 'oui', freq: 'chaque jour' },
    aerotech: { sem: [0, 1, null, null, null], dern: 'aujourd’hui', panier: 61.20, demain: 'non', freq: 'mar. et ven.', habituel: 'jamais' },
    renard: { sem: [1, 1, null, null, null], dern: 'aujourd’hui', panier: 36.10, demain: 'oui', freq: 'chaque jour' },
    biolab: { sem: [0, 1, null, null, null], dern: 'aujourd’hui', panier: 44.80, demain: 'oui', freq: '3 × / sem.' },
    technisol: { sem: [1, 1, null, null, null], dern: 'aujourd’hui', panier: 64.20, demain: 'oui', freq: 'chaque jour' },
    mutualis: { sem: [0, 1, null, null, null], dern: 'aujourd’hui', panier: 38.90, demain: 'non', freq: 'mar. et jeu.', habituel: 'jamais' },
    lambot: { sem: [1, 1, null, null, null], dern: 'aujourd’hui', panier: 88.50, demain: 'oui', freq: 'chaque jour' },
    dumont: { sem: [0, 0, null, null, null], dern: 'il y a 3 semaines', panier: 52.00, demain: 'non', freq: 'irrégulier', relance: true },
  };

  const PAIEMENTS = { facture: 'Facture · 14 j', enligne: 'Payée en ligne', caisse: 'À la caisse', alivraison: 'À encaisser' };

  const JOUR = { long: 'Mardi 24 novembre 2026', court: 'mar. 24/11', demain: 'mercredi 25/11', coupure: '16:00' };
  const MAGASIN = { id: 3, nom: 'Atelier by Max & Sandra - Gosselies', adresse: 'Rue du Pircha 202, 6041 Gosselies', heures: '06:00 – 18:00', x: 46, y: 40 };

  const eur = v => v.toLocaleString('fr-BE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).replace(/ | /g, ' ') + ' €';
  const min = h => { const [a, b] = h.split(':').map(Number); return a * 60 + b; };
  const parCanal = k => COMMANDES.filter(c => c.canal === k);
  const somme = L => L.reduce((a, c) => a + c.montant, 0);
  const deTournee = id => COMMANDES.filter(c => c.t === id).sort((a, b) => min(a.h) - min(b.h));

  return { MAINTENANT, CANAUX, STATUTS, PAIEMENTS, BUREAUX, TOURNEES, COMMANDES, RYTHME, JOUR, MAGASIN, eur, min, parCanal, somme, deTournee };
})();
