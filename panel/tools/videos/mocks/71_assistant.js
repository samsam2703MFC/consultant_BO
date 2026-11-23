/* L'assistant IA (?vue=assistant, la fenêtre de l'assistant, le Professeur du dossier de formation) : dashboard.js,
 * servi par panel/src/assistant_magasin.php, assistant_ia.php et assistant_vente.php.
 * Les réponses sont FICTIVES, à la forme exacte que rend le serveur (titre, résumé, chiffres, points, visuels relus,
 * priorités, sources, suites) ; leurs chiffres sont tirés du générateur du magasin de démo (lib/demo.js), pour rester
 * d'accord avec les pages Semaine et Mois. Les concurrents et les documents du manuel sont inventés (lib/manuel.js).
 * Le nom des coachs se règle au cockpit : le coach bienveillant porte ici un nom de démonstration. */
const D = require('./lib/demo');
const M = require('./lib/manuel');
const { r2 } = D;

/* Les questions toutes prêtes (AM_QUESTIONS, AIV_QUESTIONS, aiaQuestionsProfesseur) et les quatre assistants. */
const AM_QUESTIONS = {
  tuiles: [
    ['Comment s’est passée ma semaine ?', 'ventes, équipe, contrôles, clients'],
    ['Que puis-je améliorer ?', 'les trois priorités, chiffrées'],
    ['Qu’est-ce qui marche le mieux ?', 'jours, produits, moments'],
    ['Comment va ma journée ?', 'à cette heure, face à hier et à la semaine'],
  ],
  groupes: [
    ['Ma semaine', ['Comment s’est passée ma semaine ?', 'Que puis-je améliorer ?', 'Qu’est-ce qui a le mieux marché ?']],
    ['Mes ventes', ['Quel est mon jour le plus faible, et pourquoi ?', 'Mon panier moyen est-il bon ?', 'Comment je me situe dans le réseau ?']],
    ['Mon équipe', ['Mes salaires collent-ils à mes ventes ?', 'Où en sont mes contrôles ?', 'Mes photos de contrôle sont-elles conformes ?']],
    ['Mes clients', ['Qui sont mes clients fidèles ?', 'Combien de clients sont reconnus en caisse ?']],
    ['Ma production', ['Qu’est-ce que je jette le plus ?', 'Quelles commandes clients arrivent ?']],
    ['Le réseau', ['Qui fait le meilleur panier aujourd’hui, et avec quoi ?', 'Qu’est-ce que je vends le plus le matin ?', 'Comment ont évolué mes samedis sur un an ?']],
  ],
};
const VENTE = {
  tuiles: [
    ['Mes prix sont-ils bien placés face à la concurrence ?', 'prix relevés, écarts, marge'],
    ['Quel bundle créer cette semaine ?', 'sur vos points forts et faibles'],
    ['Quels sont mes points forts et mes points faibles ?', 'face au réseau, avec la marge'],
    ['Comment faire monter mon panier ?', 'ventes additionnelles, heures, produits'],
  ],
  groupes: [
    ['Les prix', ['Où suis-je plus cher que mes concurrents ?', 'Quel prix adapter sur la viennoiserie ?', 'Mon croissant face aux boulangeries proches ?']],
    ['Les bundles', ['Quel bundle pour le petit-déjeuner ?', 'Quel bundle pour faire découvrir mes points faibles ?', 'Mes bundles en cours marchent-ils ?']],
    ['Les ventes', ['Qu’est-ce que je vends le plus le matin ?', 'Quels produits portent mon panier ?', 'Comment ont évolué mes samedis sur un an ?']],
    ['Les objectifs 2026', ['Où en sont mes objectifs 2026 ?', 'Quels prix monter pour baisser mon food cost ?', 'Comment faire scanner plus de clients ?']],
  ],
};
const famille = { ouverture: 'Ouverture', journee: 'Journée de travail', fermeture: 'Fermeture' };
const PROF = {
  tuiles: [
    ['Comment ouvrir le magasin ?', 'les étapes, dans l’ordre du manuel'],
    ['Que faut-il vérifier à la fermeture ?', 'la checklist et ses points critiques'],
    ['Quelles règles d’hygiène au comptoir ?', 'les gestes à ne jamais oublier'],
    ['Comment présenter la vitrine ?', 'le standard du réseau'],
  ],
  groupes: [['Mes contrôles photo', ['Mes photos de contrôle sont-elles conformes ?', 'Pourquoi les contrôles photo sont-ils importants ?', 'Comment corriger ma dernière photo non conforme ?']]]
    .concat(Object.entries(M.DOCS.filter(d => d.cat === 'std').reduce((g, d) => { const f = famille[d.moment] || 'Le manuel'; (g[f] = g[f] || []).length < 3 && g[f].push('Explique-moi « ' + d.titre + ' »'); return g; }, {}))),
};
const TONS = [
  { cle: 'pierre', nom: 'Mr. Basile', dit: 'bienveillant, motivateur', avatar: 'avatars/pierre.svg', manuel: false },
  { cle: 'coach', nom: 'Le Coach', dit: 'à la dure : ici, on joue pour gagner', avatar: 'avatars/coach.svg', manuel: false },
  { cle: 'vente', nom: 'Coach Vente', dit: 'expert en vente : prix face à la concurrence, bundles, panier', avatar: 'avatars/vente.png', manuel: false },
  { cle: 'professeur', nom: 'Le Professeur', dit: 'le manuel opératoire : gestes, recettes, standards', avatar: 'avatars/professeur.svg', manuel: true },
];
const questions = () => Object.assign({}, AM_QUESTIONS, { tons: TONS, tonDefaut: 'pierre', professeur: PROF, parTon: { professeur: PROF, vente: VENTE }, actif: true });

/* Les chiffres, tirés du magasin de démo : huit semaines closes (du lundi 21/09 au dimanche 15/11/2026). */
const NOMS = ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'], DOW = [1, 2, 3, 4, 5, 6, 0];
function parJour() {
  const s = {}, n = {};
  for (let i = 0; i < 56; i++) { const d = D.plus('2026-09-21', i), j = D.dow(d); s[j] = (s[j] || 0) + D.caJourPlein(d); n[j] = (n[j] || 0) + 1; }
  return DOW.map((j, i) => { const ca = s[j] / n[j]; return { nom: NOMS[i], ca: Math.round(ca), tickets: Math.round(ca / D.PANIER[j]), panier: D.PANIER[j], reseau: Math.round(ca * (j === 0 || j === 6 ? 0.96 : 1.09)) }; });
}
const fE = v => new Intl.NumberFormat('fr-BE', { maximumFractionDigits: 0 }).format(v).replace(/[  ]/g, ' ') + ' €';
const f2 = v => new Intl.NumberFormat('fr-BE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(v).replace(/[  ]/g, ' ') + ' €';
let VID = 9100;
const visuel = v => Object.assign({ id: ++VID, note: null, surligne: null, unite: '€' }, v);

function jourFaible() {
  const J = parJour(), lun = J[0], moy = J.reduce((a, x) => a + x.ca, 0) / 7;
  const creux = Math.round(100 * (D.poids('2026-11-16')[7] + D.poids('2026-11-16')[8] + D.poids('2026-11-16')[9]));
  return {
    titre: 'Votre jour le plus faible : le lundi',
    resume: `Sur les huit dernières semaines, le lundi est votre jour le plus faible : ${fE(lun.ca)} en moyenne, ${Math.round(100 * (1 - lun.ca / moy))} % sous la moyenne de vos jours. Le panier tient ; ce sont les clients qui manquent, surtout l’après-midi.`,
    chiffres: [{ valeur: fE(lun.ca), libelle: 'CA moyen du lundi' }, { valeur: '−' + Math.round(100 * (1 - lun.ca / moy)) + ' %', libelle: 'face à la moyenne de vos jours' },
      { valeur: String(lun.tickets), libelle: 'clients le lundi' }, { valeur: f2(lun.panier), libelle: 'panier du lundi' }],
    points: [{ titre: 'L’après-midi est creux.', texte: `De 14 h à 17 h, le lundi ne fait que ${creux} % de son chiffre.` },
      { titre: 'Le panier n’est pas en cause.', texte: `${f2(lun.panier)} le lundi, presque votre panier de la semaine : il manque du passage, pas du prix.` }],
    visuels: [visuel({ forme: 'colonnes', titre: 'Le chiffre d’affaires moyen, jour par jour', donnees: 'ventes_par_jour', mesure: 'ca', mesureNom: 'Chiffre d’affaires',
      periode: 'du 21/09/2026 au 15/11/2026 · moyenne par jour de la semaine', libelles: J.map(x => x.nom.slice(0, 3) + '.'),
      series: [{ nom: 'Mon magasin', role: 'moi', valeurs: J.map(x => x.ca) }, { nom: 'Le réseau (moyenne par magasin)', role: 'ref', valeurs: J.map(x => x.reseau) }],
      tableau: { entetes: ['Jour', 'Mon magasin', 'Le réseau (moyenne par magasin)', 'Clients'], lignes: J.map(x => [x.nom, x.ca, x.reseau, x.tickets]), mesures: [null, 'ca', 'ca', 'tickets'] },
      source: 'l’historique des ventes du magasin (tickets du panel) ; le réseau, sans nom' })],
    priorites: [
      { titre: 'Une offre goûter le lundi après-midi', constat: 'C’est le moment le plus creux de votre semaine : un bundle boisson + viennoiserie de 14 h à 17 h.', gain: `+15 clients le lundi, c’est environ +${Math.round(15 * lun.panier)} € par semaine.`, ecran: 'promos' },
      { titre: 'La vitrine pleine jusqu’à 17 h', constat: 'Le lundi, la vitrine se vide plus tôt : moins de choix, moins d’envie.', gain: '', ecran: 'ops' },
      { titre: 'Suivre le lundi, semaine après semaine', constat: 'Comparez chaque lundi au précédent dans la page Semaine.', gain: '', ecran: 'semaine' },
    ],
    conclusion: 'Commencez par une offre simple le lundi après-midi, et regardez son effet dès lundi prochain.',
    suites: ['Quel bundle pour le lundi après-midi ?', 'Comment ont évolué mes lundis sur un an ?', 'Que vendre de plus entre 14 h et 17 h ?'], sources: [],
  };
}
function bundleLundi() {
  const cafe = D.PRODUITS.find(p => p.nom === 'Café'), croi = D.PRODUITS.find(p => p.nom === 'Croissant au beurre'), cook = D.PRODUITS.find(p => p.nom === 'Cookie chocolat');
  const prix = r2((cafe.prix + cook.prix) * 0.9);
  return {
    titre: 'Un bundle pour le lundi après-midi',
    resume: `Le café attire l’après-midi ; le cookie, à forte marge, se vend surtout le matin. Ensemble à ${f2(prix)} au lieu de ${f2(cafe.prix + cook.prix)}, de 14 h à 17 h, le lundi.`,
    chiffres: [{ valeur: f2(prix), libelle: 'prix conseillé (−10 %)' }, { valeur: Math.round(100 * cook.taux) + ' %', libelle: 'marge du cookie' }, { valeur: '14 h – 17 h', libelle: 'le moment' }],
    points: [], visuels: [],
    priorites: [{ titre: 'Poser le bundle dans Promotions', constat: `Café + cookie chocolat à ${f2(prix)}, le lundi de 14 h à 17 h, pendant un mois.`, gain: '', ecran: 'promos' },
      { titre: 'L’annoncer au comptoir', constat: `Une ardoise près de la caisse, et une phrase à l’équipe : « avec votre café, un cookie ? ». Le croissant reste à ${f2(croi.prix)}.`, gain: '', ecran: 'aucun' }],
    conclusion: 'Un mois suffit pour voir si le lundi après-midi remonte.',
    suites: ['Quel bundle pour le petit-déjeuner ?', 'Mes bundles en cours marchent-ils ?'], sources: [],
  };
}
function croissant() {
  const p = D.PRODUITS.find(x => x.nom === 'Croissant au beurre');
  const C = [['Boulangerie A', 0.4, 1.45, 'Croissant au beurre', 'standard', '2026-11-12', 'bonne'], ['Boulangerie B', 0.9, 1.60, 'Croissant pur beurre', 'standard', '2026-11-12', 'très bonne'],
    ['Pâtisserie C', 1.2, 1.55, 'Croissant', 'grand', '2026-11-10', 'bonne'], ['Supermarché D', 1.5, 1.30, 'Croissant au beurre', 'standard', '2026-11-09', 'moyenne']];
  // La médiane des quatre relevés (1,30 · 1,45 · 1,55 · 1,60) : 1,50 €, le prix proposé.
  const med = r2((C[0][2] + C[2][2]) / 2), cible = 1.5, gain = r2(p.q * (cible - p.prix));
  return {
    titre: 'Votre croissant face aux boulangeries proches',
    resume: `Votre croissant au beurre est à ${f2(p.prix)} ; les quatre commerces relevés autour du magasin le vendent entre 1,30 € et 1,60 €, médiane ${f2(med)}. Vous êtes sous la médiane, avec ${Math.round(100 * p.taux)} % de marge.`,
    chiffres: [{ valeur: f2(p.prix), libelle: 'mon prix' }, { valeur: f2(med), libelle: 'médiane des concurrents' }, { valeur: Math.round(100 * p.taux) + ' %', libelle: 'ma marge brute' }, { valeur: p.q + ' / jour', libelle: 'croissants vendus' }],
    points: [{ titre: 'Seul le supermarché est moins cher.', texte: 'Les trois boulangeries et pâtisseries relevées sont toutes au-dessus de votre prix.' }],
    visuels: [visuel({ forme: 'barres', titre: 'Le croissant : mon prix et ceux des concurrents', donnees: 'prix_concurrence', mesure: 'prix', mesureNom: 'Prix TTC',
      periode: 'Croissant au beurre · relevés du 09/11/2026 au 12/11/2026', libelles: ['Mon magasin'].concat(C.map(c => c[0] + ' (' + String(c[1]).replace('.', ',') + ' km)')),
      series: [{ nom: 'Prix TTC', role: 'moi', valeurs: [p.prix].concat(C.map(c => c[2])) }], surligne: 0,
      tableau: { entetes: ['Commerce', 'Produit', 'Taille', 'Prix TTC', 'Vu le', 'Qualité notée'],
        lignes: [['Mon magasin', p.nom, '', p.prix, '', 'marge ' + Math.round(100 * p.taux) + ' %']].concat(C.map(c => [c[0], c[3], c[4], c[2], c[5].slice(8, 10) + '/' + c[5].slice(5, 7) + '/2026', c[6]])) },
      source: 'les relevés de la concurrence (onglet Concurrence) et le prix du magasin au panel', note: 'Prix payé pour l’article vu : les tailles peuvent différer (voir le tableau).' })],
    priorites: [
      { titre: 'Passer le croissant à 1,50 €', constat: `La médiane des commerces proches est ${f2(med)} ; vous êtes ${f2(cible - p.prix)} en dessous.`, gain: `jusqu’à +${f2(gain)} par jour, au volume d’aujourd’hui`, ecran: 'produits' },
      { titre: 'Un lot pour garder vos fidèles', constat: `« Le croissant passe à 1,50 €, et les 4 restent à ${f2(4 * p.prix)} » : le client qui prend plus garde son prix.`, gain: '', ecran: 'promos' },
    ],
    conclusion: 'Le prix se change au panel, dans l’écran Produits ; le lot se pose dans Promotions.',
    suites: ['Où suis-je plus cher que mes concurrents ?', 'Quel bundle pour le petit-déjeuner ?', 'Quels prix monter pour baisser mon food cost ?'], sources: [],
  };
}
function source(id) { const d = M.doc(id); return { id: d.id, code: d.code, type: d.type, titre: d.titre, version: '3', statut: 'publié' }; }
function fermeture() {
  return {
    titre: 'La fermeture : ce qu’il faut vérifier',
    resume: 'La fermeture se fait dans l’ordre de la SOP : la vitrine, le nettoyage, les frigos, puis la caisse. La checklist de fermeture se coche au fur et à mesure ; deux points sont critiques.',
    chiffres: [],
    points: [{ titre: 'La vitrine.', texte: 'Les invendus sortis, comptés et notés.' }, { titre: 'Le nettoyage.', texte: 'La vitrine, le comptoir et les plans de travail.' },
      { titre: 'Les frigos.', texte: 'Fermés et à bonne température : c’est un point critique.' }, { titre: 'La caisse.', texte: 'Clôturée et comptée à deux : l’autre point critique.' }],
    visuels: [visuel({ forme: 'tableau', titre: 'La fermeture, étape par étape', donnees: 'manuel', mesure: 'ca', mesureNom: '', periode: 'SOP 0.4.01 · checklist 1.4.01', libelles: [], series: [],
      tableau: { entetes: ['Étape', 'Ce qu’on vérifie', 'Point critique'], lignes: [['La vitrine', 'invendus comptés et notés', 'non'], ['Le nettoyage', 'vitrine, comptoir, plans de travail', 'non'], ['Les frigos', 'fermés, à température', 'oui'], ['La caisse', 'clôturée, comptée à deux', 'oui']] },
      source: 'le manuel opératoire du réseau' })],
    priorites: [], conclusion: 'Faites la checklist dans l’ordre de la SOP : rien ne s’oublie, et tout est prêt pour le lendemain.',
    suites: ['Explique-moi « Clôture de la caisse »', 'Comment ouvrir le magasin ?'], sources: [source('sop-ferm'), source('chk-ferm')],
  };
}
function professeurDossier(q) {
  const buche = /bûche/i.test(q);
  return {
    titre: buche ? 'La bûche de Noël 2026 : allergènes et points critiques' : 'Votre dossier de formation : par où commencer',
    resume: buche ? 'D’après votre formation FC-019 et la fiche produit : la bûche contient du gluten, des œufs et du lait, et des fruits à coque selon le décor. Deux points critiques : le roulage du biscuit et la tenue au froid.'
      : 'D’après les échéances de votre dossier : terminez d’abord « Le nouveau four : programmes de cuisson », en retard depuis le 15/11, puis la photo du jour de la bûche.',
    chiffres: [], points: buche ? [{ titre: 'Les allergènes.', texte: 'Gluten, œufs, lait ; fruits à coque selon le décor : à écrire sur l’étiquette.' }, { titre: 'Le roulage.', texte: 'Rouler le biscuit encore tiède, sans le casser.' }, { titre: 'Le froid.', texte: 'La bûche reste au froid jusqu’à la vitrine.' }] : [],
    visuels: [], priorites: [], conclusion: buche ? 'Votre consultant relit chaque jour la photo du jour : c’est elle qui valide la formation.' : '',
    suites: buche ? ['Prépare 3 questions pour vérifier l’équipe'] : [], sources: buche ? [source('fiche-buche')] : [],
  };
}
function generique(q, ton) {
  return { titre: q.replace(/\s*\?$/, ''), resume: ton === 'professeur' ? 'Le manuel opératoire répond à cette question dans les documents ci-dessous.' : 'Voici ce que disent les chiffres de votre magasin, lus à 15 h 40.',
    chiffres: [], points: [], visuels: [], priorites: [], conclusion: '', suites: [], sources: ton === 'professeur' ? [source('ouv-mag')] : [] };
}
function repondre(c) {
  const q = String(c.question || ''), ton = c.ton || 'pierre';
  let r;
  if (/croissant/i.test(q) && ton === 'vente') { r = croissant(); }
  else if (/fermeture/i.test(q) && ton === 'professeur') { r = fermeture(); }
  else if (ton === 'professeur' && /(dossier|bûche|terminer|formation)/i.test(q)) { r = professeurDossier(q); }
  else if (/jour le plus faible/i.test(q)) { r = jourFaible(); }
  else if (/bundle/i.test(q) && /lundi/i.test(q)) { r = bundleLundi(); }
  else { r = generique(q, ton); }
  return { reponse: r, question: 700 + (++VID % 100), luLe: ton === 'professeur' ? null : '2026-11-19 15:40', modele: ton === 'professeur' ? 'claude-haiku-5-5' : 'claude-opus-5-5', ton };
}

/* Mes modèles (panel_aia_modele) : la demande d'un visuel, gardée ; l'ouvrir la refait avec les chiffres du jour. */
const S = { modeles: [{ id: 3, nom: 'Mes ventes heure par heure', forme: 'colonnes', donnees: 'ventes_par_heure', creeLe: '2026-11-09', ouvertures: 4 }] };
function heureParHeure() {
  const ca = parJour().reduce((a, x) => a + x.ca, 0) / 7, P = D.poids('2026-11-18');
  return visuel({ forme: 'colonnes', titre: 'Mes ventes heure par heure', donnees: 'ventes_par_heure', mesure: 'ca', mesureNom: 'Chiffre d’affaires', periode: 'du 22/10/2026 au 18/11/2026 · moyenne par jour ouvert',
    libelles: D.HEURES.map(h => h + ' h'), series: [{ nom: 'Mon magasin', role: 'moi', valeurs: P.map(p => Math.round(ca * p)) }],
    tableau: { entetes: ['Heure', 'Chiffre d’affaires'], lignes: D.HEURES.map((h, i) => [h + ' h', Math.round(ca * P[i])]) }, source: 'l’historique des ventes du magasin (tickets du panel)' });
}

module.exports = function (m, p, q, c) {
  c = c && typeof c === 'object' ? c : {};
  if (!p.startsWith('/assistant/magasin')) { return undefined; }
  if (p === '/assistant/magasin/questions') { return questions(); }
  if (p === '/assistant/magasin' && m === 'POST') { return repondre(c); }
  if (p === '/assistant/magasin/modeles') {
    if (m === 'POST') {
      const deja = S.modeles.find(x => x.visuel === +c.visuel);
      if (deja) { return { ok: true, id: deja.id, deja: true }; }
      const id = 10 + S.modeles.length; S.modeles.unshift({ id, nom: c.nom, forme: 'colonnes', donnees: 'ventes_par_jour', creeLe: '2026-11-19', ouvertures: 0, visuel: +c.visuel });
      return { ok: true, id };
    }
    return { modeles: S.modeles.map(x => ({ id: x.id, nom: x.nom, forme: x.forme, donnees: x.donnees, creeLe: x.creeLe, ouvertures: x.ouvertures })) };
  }
  let x = p.match(/^\/assistant\/magasin\/modeles\/(\d+)\/supprimer$/);
  if (x) { S.modeles = S.modeles.filter(y => y.id !== +x[1]); return { ok: true }; }
  x = p.match(/^\/assistant\/magasin\/modeles\/(\d+)$/);
  if (x) { const mo = S.modeles.find(y => y.id === +x[1]); if (!mo) { return { __status: 404, json: { error: 'modèle inconnu pour ce magasin' } }; } return { modele: { id: mo.id, nom: mo.nom }, visuel: mo.id === 3 ? heureParHeure() : jourFaible().visuels[0] }; }
  return undefined;
};
