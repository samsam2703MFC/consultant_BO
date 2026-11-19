/* Données d'EXEMPLE des maquettes « Fil du franchisé » (19/11/2026). Les trois maquettes lisent les mêmes entrées :
 * seule la mise en page change. Magasins, consultants, dates des visites et demandes du helpdesk de Sombreffe sont
 * réels (helpdesk lu le 15/11/2026, visite régulière du 26/10 de Loic V.) ; les notes, photos et étiquettes sont
 * inventées pour l'exemple. Les photos viennent du dépôt : contrôles photo du dashboard magasin et SOP de la caisse. */

const AUJ = '2026-11-19';

/* Les étiquettes : trois disent le SENS (rouge, vert, or), les autres le THÈME (neutres). Réglables dans
 * Suivi des franchisés › Qui fait quoi. */
const TAGS = {
  gestion: { nom: 'Problème de gestion', sens: 'neg' },
  bonne: { nom: 'Bonne action', sens: 'pos' },
  surveiller: { nom: 'À surveiller', sens: 'att' },
  equipe: { nom: 'Équipe', sens: '' },
  produit: { nom: 'Produit', sens: '' },
  hygiene: { nom: 'Hygiène', sens: '' },
  finances: { nom: 'Finances', sens: '' },
  client: { nom: 'Client', sens: '' }
};

const TYPES = {
  visite: 'Visite', rapport: 'Rapport', note: 'Note', photo: 'Photo', capture: 'Capture', appel: 'Appel',
  point: 'Point décidé', mystere: 'Client mystère', demande: 'Demande du franchisé'
};

/* Les fils : un problème, ce qui l'a suivi, et sa fin. */
const FILS = {
  froid: { titre: 'Bacs de la chambre froide sans date', fin: '2026-02-10' },
  planning: { titre: 'Trop de monde à l’ouverture le lundi', fin: '2026-04-02' },
  inventaire: { titre: 'Inventaire du mois pas encodé', fin: '2026-06-03' },
  centrale: { titre: 'Commandes passées hors centrale d’achat', fin: '2026-10-07' },
  etiquettes: { titre: 'Prix écrits à la main en vitrine', fin: '2026-11-19' },
  caisse: { titre: 'Fond de caisse encodé à 0 € à l’ouverture', fin: null },
  production: { titre: 'Production du soir jetée, vitrine vide l’après-midi', fin: null, echeance: '2026-11-19' }
};

/* Les magasins : la carte de chaque franchisé. Sombreffe est calculé sur ses entrées ; les trois autres portent
 * des valeurs d'exemple (12 semaines : g problème, b bonne action, s à surveiller, n autre, '' rien ; v = visite). */
const MAGASINS = [
  { id: 'cor', ville: 'Corbais', nom: 'Atelier by Berlo', consultant: 'Sam V.', dernier: '12/11', visite: 'mar. 01/12',
    mois: { gestion: 1, bonne: 3, ouverts: 0 }, semaines: ['n', 'b', '', 'n', 'bv', 'n', 'g', 'b', 'nv', '', 'b', 'n'] },
  { id: 'gos', ville: 'Gosselies', nom: 'Atelier by Max & Sandra', consultant: 'Sam V.', dernier: 'hier', visite: 'mar. 24/11',
    mois: { gestion: 4, bonne: 1, ouverts: 2 }, semaines: ['g', 'n', 'gv', 's', 'n', 'g', 'b', 'gv', 'g', 's', 'g', 'n'] },
  { id: 'som', ville: 'Sombreffe', nom: 'Atelier by Harmonie', consultant: 'Loic V.', calc: true },
  { id: 'hal', ville: 'Halle', nom: 'Atelier by Halle', consultant: 'Szymon M.', dernier: '16/11', visite: 'jeu. 03/12',
    mois: { gestion: 0, bonne: 2, ouverts: 0 }, semaines: ['n', '', 'nv', 'b', 'n', '', 'n', 'bv', 'n', 'b', '', 'b'] }
];

/* Le prochain rendez-vous de Sombreffe. */
const PROCHAINE = { d: '2026-11-26', h: '09:30', titre: 'Visite régulière · annoncée', qui: 'Loic V.' };

/* Les entrées détaillées : du 26/10 (S44) au 19/11 (S47). */
const RECENTES = [
  { d: '2026-11-19', h: '09:40', type: 'note', qui: 'Loic V.', tags: ['bonne', 'produit'], fil: 'etiquettes', partage: true,
    txt: 'Harmonie a refait l’étiquetage de la vitrine des tartes : prix imprimés au format du réseau, plus un seul prix écrit à la main. Fait avant l’échéance du point.',
    photos: ['vitrine-3', 'vitrine-4'] },
  { d: '2026-11-18', h: '16:05', type: 'capture', qui: 'Sam V.', tags: ['gestion', 'finances'], fil: 'production', epingle: true,
    txt: 'Invendus de la semaine passée : 164 pièces jetées, 115 € de coût de production, toutes en fin de journée. La production du soir n’a pas encore baissé.',
    capture: 'capture-invendus' },
  { d: '2026-11-17', h: '07:12', type: 'photo', qui: 'Loic V.', tags: ['gestion'], fil: 'caisse', epingle: true,
    txt: 'Passage à l’ouverture : fond de caisse encodé à 0 €. Troisième fois depuis le 3 novembre.',
    photos: ['caisse-ouverture'] },
  { d: '2026-11-16', h: '11:30', type: 'appel', qui: 'Loic V.', tags: ['surveiller', 'equipe'],
    txt: 'Harmonie appelle : une vendeuse du week-end part fin décembre. Elle cherche un étudiant ; je lui envoie le lien du Recrutement.' },
  { d: '2026-11-13', h: '10:02', type: 'rapport', qui: 'Loic V.', titre: 'Rapport de la visite du 10/11',
    det: '4 points à travailler · 12 photos envoyées · lu par le franchisé le 13/11 à 21:14' },
  { d: '2026-11-12', h: '14:00', type: 'point', qui: 'Loic V.', tags: ['produit'], fil: 'production',
    titre: 'Réduire de 20 % la production du soir (viennoiseries)', det: 'P1 · échéance 19/11 · ouvert' },
  { d: '2026-11-10', h: '11:20', type: 'note', qui: 'Loic V.', tags: ['bonne', 'client'],
    txt: 'Nouvelle ardoise du jour en français et en néerlandais, posée par l’équipe sans qu’on le demande. Deux clients l’ont lue devant moi.',
    photos: ['vitrine-1'] },
  { d: '2026-11-10', h: '09:30', type: 'visite', qui: 'Loic V.', titre: 'Visite régulière · annoncée',
    det: '09:30 – 12:10 · 18 photos · 4 points décidés · évaluation rapide 3,6 / 5', score: 72, delta: 6 },
  { d: '2026-11-07', h: '18:40', type: 'capture', qui: 'Sam V.', tags: ['surveiller', 'finances'],
    txt: 'La semaine finit à 70 % de l’objectif : 8 172 € sur 11 722 € attendus. Mardi et jeudi sous 75 %.',
    capture: 'capture-ca' },
  { d: '2026-11-05', h: '15:10', type: 'photo', qui: 'Loic V.', tags: ['gestion', 'produit'], fil: 'production',
    txt: 'Vitrine basse à 15 h : plus de quiches ni de viennoiseries, alors que la production du soir finit à la poubelle.',
    photos: ['vitrine-6', 'vitrine-2'] },
  { d: '2026-11-03', h: '14:30', type: 'mystere', qui: 'Client mystère', titre: 'Passage client mystère · 78 / 100',
    det: 'accueil souriant · tenue OK · vitrine incomplète à 14 h 30' },
  { d: '2026-11-03', h: '06:58', type: 'note', qui: 'Loic V.', tags: ['gestion', 'finances'], fil: 'caisse',
    txt: 'Fond de caisse encodé à 0 € à l’ouverture (lu dans le rapport de caisse).' },
  { d: '2026-10-28', h: '17:20', type: 'rapport', qui: 'Loic V.', titre: 'Rapport de la visite du 26/10',
    det: '5 points à travailler · 9 photos envoyées · lu par le franchisé le 29/10' },
  { d: '2026-10-26', h: '10:45', type: 'photo', qui: 'Loic V.', tags: ['gestion', 'produit'], fil: 'etiquettes',
    txt: 'Prix écrits à la main sur six tartes de la vitrine. Point décidé avec Harmonie : étiquettes imprimées d’ici le 23/11.',
    photos: ['vitrine-5'] },
  { d: '2026-10-26', h: '09:00', type: 'visite', qui: 'Loic V.', titre: 'Visite régulière · annoncée',
    det: '09:00 – 11:40 · 15 photos · 5 points décidés · évaluation rapide 3,3 / 5', score: 66, delta: -3 }
];

/* Avant le 26/10 : les visites, rapports, demandes (réelles), client mystère, et les notes marquantes. */
const VISITES = [['2026-01-13', 61], ['2026-02-10', 63], ['2026-03-17', 66], ['2026-04-21', 64], ['2026-05-18', 68],
  ['2026-06-16', 70], ['2026-09-08', 71], ['2026-10-06', 69]];
const ANCIENNES = [];
VISITES.forEach(([d, s], i) => {
  ANCIENNES.push({ d, h: '09:30', type: 'visite', qui: 'Loic V.', titre: 'Visite régulière · annoncée', score: s, delta: i ? s - VISITES[i - 1][1] : null, det: '' });
  const r = new Date(d); r.setDate(r.getDate() + 2);
  ANCIENNES.push({ d: r.toISOString().slice(0, 10), h: '10:00', type: 'rapport', qui: 'Loic V.', titre: 'Rapport de la visite du ' + d.slice(8, 10) + '/' + d.slice(5, 7), det: '' });
});
[['2026-05-19', 'Verrière cuisine, réagencement, étalement et cadrage de l’affichage'],
 ['2026-09-15', 'Mise en avant des quiches et tartes en grand, plats chauds'],
 ['2026-09-15', 'Campagne de démarchage pour les lunchs en entreprise'],
 ['2026-10-07', 'Démarchage sur Nivelles Sud'],
 ['2026-10-12', 'Changement de prix'], ['2026-10-12', 'Changement dans la caisse']]
  .forEach(([d, t]) => ANCIENNES.push({ d, h: '12:00', type: 'demande', qui: 'Harmonie', titre: t, det: '' }));
[['2026-03-04', 74], ['2026-06-10', 81]].forEach(([d, s]) => ANCIENNES.push({ d, h: '13:00', type: 'mystere', qui: 'Client mystère', titre: 'Passage client mystère · ' + s + ' / 100', det: '' }));
const MARQUANTES = [
  ['2026-01-20', 'note', ['gestion', 'finances'], 'Pas de comptage de caisse le soir, deux jours de suite.'],
  ['2026-02-03', 'photo', ['gestion', 'hygiene'], 'Bacs de la chambre froide sans date d’ouverture.', 'froid'],
  ['2026-02-10', 'note', ['bonne', 'hygiene'], 'Dates posées sur tous les bacs ; une étiqueteuse achetée.', 'froid'],
  ['2026-02-24', 'capture', ['surveiller', 'finances'], 'CA de février 8 % sous le budget.'],
  ['2026-03-09', 'note', ['gestion', 'equipe'], 'Trois personnes à l’ouverture le lundi pour deux nécessaires : 14 h de trop sur le mois.', 'planning'],
  ['2026-03-21', 'photo', ['bonne', 'produit'], 'Vitrine de Pâques montée deux jours avant le réseau.'],
  ['2026-04-02', 'note', ['bonne', 'equipe'], 'Planning du lundi revu : une personne de moins à l’ouverture.', 'planning'],
  ['2026-04-28', 'capture', ['gestion', 'finances'], 'Coût matière à 41 % sur avril.'],
  ['2026-05-05', 'appel', ['surveiller', 'equipe'], 'Harmonie fatiguée, parle de réduire ses heures.'],
  ['2026-05-13', 'capture', ['gestion', 'finances'], 'Inventaire d’avril pas encodé dans la caisse.', 'inventaire'],
  ['2026-05-26', 'note', ['bonne', 'equipe'], 'Harmonie forme elle-même la nouvelle vendeuse avec le Module Formation.'],
  ['2026-06-03', 'note', ['bonne', 'finances'], 'Inventaire encodé le 1er du mois, sans rappel.', 'inventaire'],
  ['2026-06-23', 'note', ['gestion', 'equipe'], 'Le responsable du samedi ne fait pas la checklist de fermeture.'],
  ['2026-07-08', 'photo', ['bonne', 'client'], 'Terrasse installée, ardoise lunch dehors.'],
  ['2026-08-04', 'note', ['surveiller', 'client'], 'Travaux dans la rue en septembre : moins de passage attendu.'],
  ['2026-08-18', 'photo', ['gestion'], 'Frigo boissons en panne depuis une semaine, pas signalé.'],
  ['2026-08-27', 'photo', ['bonne', 'hygiene'], 'Cuisine nettoyée à fond pendant les congés, photos envoyées.'],
  ['2026-09-09', 'note', ['gestion'], 'Commandes de farine passées par téléphone, hors centrale d’achat.', 'centrale'],
  ['2026-09-22', 'note', ['bonne', 'client'], 'Démarchage lunchs : trois sociétés de Sombreffe contactées.'],
  ['2026-10-07', 'note', ['bonne'], 'Toutes les commandes passent par la centrale depuis trois semaines.', 'centrale'],
  ['2026-10-14', 'capture', ['gestion', 'finances', 'equipe'], 'Heures prestées 12 % au-dessus du budget en septembre.'],
  ['2026-10-20', 'capture', ['surveiller', 'client'], 'Note Google passée de 4,5 à 4,3 en un mois.']
];
MARQUANTES.forEach(([d, type, tags, txt, fil]) => ANCIENNES.push({ d, h: '10:00', type, qui: 'Loic V.', tags, txt, fil,
  photos: type === 'photo' ? ['vitrine-' + (1 + (parseInt(d.slice(8, 10), 10) % 6))] : undefined }));

/* Le quotidien : des notes neutres, tirées d'une suite fixe (même résultat à chaque génération). */
let graine = 11;
const hasard = () => (graine = (graine * 16807) % 2147483647) / 2147483647;
const BANQUE = {
  produit: ['Planogramme du comptoir respecté.', 'Rupture de baguettes tradition à 17 h.', 'Tartes de saison en vitrine dès l’ouverture.', 'Nouvelle gamme de quiches bien en place.'],
  client: ['File d’attente à midi : huit personnes à 12 h 15.', 'Réclamation d’un client traitée le jour même.', 'Bon retour des clients sur l’accueil.'],
  equipe: ['Équipe complète, tenues correctes.', 'Nouvelle étudiante le samedi.', 'Réunion d’équipe tenue lundi.'],
  hygiene: ['Plan de nettoyage signé chaque jour.', 'Contrôle hygiène du mois : rien à signaler.'],
  '': ['Passage rapide, rien à signaler.', 'Point téléphonique de la semaine.']
};
const THEMES = ['produit', 'client', 'equipe', 'produit', 'hygiene', '', 'client', ''];
for (let s = 2; s <= 43; s++) {
  const ete = s >= 28 && s <= 35;
  if (hasard() > (ete ? 0.3 : 0.62)) { continue; }
  const lundi = new Date(Date.UTC(2025, 11, 29 + (s - 1) * 7));
  lundi.setUTCDate(lundi.getUTCDate() + Math.floor(hasard() * 5));
  const d = lundi.toISOString().slice(0, 10);
  if (d >= '2026-10-26') { continue; }
  const th = THEMES[Math.floor(hasard() * THEMES.length)];
  const t = hasard() < 0.55 ? 'note' : (hasard() < 0.75 ? 'photo' : 'capture');
  const banque = BANQUE[th];
  ANCIENNES.push({ d, h: '11:00', type: t, qui: 'Loic V.', tags: th ? [th] : [], txt: banque[Math.floor(hasard() * banque.length)],
    photos: t === 'photo' ? ['vitrine-' + (1 + Math.floor(hasard() * 6))] : undefined });
}

const ENTREES = RECENTES.concat(ANCIENNES).sort((a, b) => (b.d + b.h).localeCompare(a.d + a.h));

module.exports = { AUJ, TAGS, TYPES, FILS, MAGASINS, PROCHAINE, ENTREES, RECENTES_DEPUIS: '2026-10-26' };
