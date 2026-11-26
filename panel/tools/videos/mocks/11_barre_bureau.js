/* La barre du haut › « Demande au bureau » (vidéos 01-17 et 01-26) : les demandes du magasin de démo au bureau.
 *   POST /helpdesk/magasin   une demande envoyée (le formulaire, ou « Demander au réseau de relever le maximum » de la
 *                            fiche produit) : elle rejoint « Mes demandes », statut « Envoyée » ;
 *   GET  /helpdesk/magasin   « Mes demandes » : quatre demandes d'exemple et celles envoyées pendant le film.
 * 00_commun.js répond déjà au GET (une liste vide, pour toutes les autres vidéos) et passe avant ce fichier : les
 * scénarios de la barre installent ce GET sur leur page (prioritaires(page), une route de la page passe avant celles
 * du contexte). Le POST ne répond qu'une fois prioritaires() appelé : les vidéos des autres pages (l'assistant, 21-…)
 * gardent leurs propres réponses. Données fictives : sujets, textes, consultante (« Sophie ») inventés.
 * La forme : consultant/src/helpdesk.php ; ce que l'écran en lit : dashboard.js, hdVue() et hdCarte(). */
const DOMAINES = { strategy: 'Stratégie', product: 'Produit', operational_activities: 'Activités opérationnelles', sales: 'Ventes', app_development: 'Application', social_media: 'Réseaux sociaux' };

const EXEMPLES = () => [
  { id: 431, ref: 'CASE-2026-0431', statut: 'NEW', statutNom: 'Nouvelle', ouverte: true, urgent: false, domaine: 'social_media',
    titre: 'Les affiches de la promotion de décembre', description: 'Pouvez-vous nous envoyer les affiches A3 de la promotion des bûches pour la vitrine ?',
    cree: '2026-11-18 16:20:00', echeance: null, reponse: null, reponseLe: null, consultant: null, visite: null },
  { id: 425, ref: 'CASE-2026-0425', statut: 'IN_PROGRESS', statutNom: 'En cours', ouverte: true, urgent: false, domaine: 'operational_activities',
    titre: 'Une fuite sous le lave-vaisselle', description: 'Depuis lundi, une petite fuite sous le lave-vaisselle de la plonge. Faut-il appeler le technicien ?',
    cree: '2026-11-16 09:12:00', echeance: '2026-11-24', reponse: null, reponseLe: null, consultant: 'Sophie', visite: null },
  { id: 418, ref: 'CASE-2026-0418', statut: 'IN_PROGRESS', statutNom: 'En cours', ouverte: true, urgent: false, domaine: 'strategy',
    titre: 'Ouvrir le dimanche matin en décembre ?', description: 'Nous aimerions ouvrir les dimanches 13 et 20 décembre, de 7 h à 13 h. Qu’en pense le bureau ?',
    cree: '2026-11-12 11:40:00', echeance: null, reponse: null, reponseLe: null, consultant: 'Sophie', visite: { consultant: 'Sophie', le: '2026-11-26' } },
  { id: 402, ref: 'CASE-2026-0402', statut: 'RESOLVED', statutNom: 'Résolue', ouverte: false, urgent: false, domaine: 'app_development',
    titre: 'Ajouter le cookie praliné à la caisse', description: 'Le cookie praliné arrive lundi : il n’est pas encore dans la caisse.',
    cree: '2026-11-06 08:55:00', echeance: null, reponse: 'C’est fait : le cookie praliné est dans la caisse, à 2,40 €, dans « Biscuits et cookies ».', reponseLe: '2026-11-07 10:15:00', consultant: 'Sophie', visite: null },
];
let demandes = EXEMPLES(), suivant = 432, actif = false;   // actif : seulement sur la page d'un scénario de la barre

function lire() {
  return { shop: 4, domaines: DOMAINES, demandes: demandes.map(d => Object.assign({ domaineNom: DOMAINES[d.domaine] || d.domaine }, d)) };
}
function envoyer(c) {
  c = c || {};
  const titre = String(c.titre || '').trim(), desc = String(c.description || '').trim();
  if (!DOMAINES[c.domaine] || !titre || !desc) { return { __status: 422, json: { ok: false, error: 'le sujet, le titre et la demande sont requis' } }; }
  const ref = 'CASE-2026-0' + (suivant++);
  demandes.unshift({ id: suivant - 1, ref, statut: 'NEW', statutNom: 'Nouvelle', ouverte: true, urgent: !!c.urgent, domaine: c.domaine, titre: titre.slice(0, 190),
    description: desc, cree: '2026-11-19 15:41:00', echeance: null, reponse: null, reponseLe: null, consultant: null, visite: null,
    photos: Array.isArray(c.photos) ? c.photos.length : 0, passage: !!c.passage });
  return { ok: true, ref, id: suivant - 1 };
}

module.exports = function (m, p, q, c) {
  if (p !== '/helpdesk/magasin' || !actif) { return undefined; }
  if (m === 'POST') { return envoyer(c); }
  if (m === 'GET') { return lire(); }   // jamais atteint tant que 00_commun.js répond : voir prioritaires()
  return undefined;
};
/** Sur la page d'un scénario de la barre : GET /helpdesk/magasin répond d'ici (route de la page, avant celles du contexte).
 * `vide` : aucune demande d'exemple (le départ « nouvelle » de la vignette). */
module.exports.prioritaires = async (page, { vide = false } = {}) => {
  actif = true;
  if (vide) { demandes = []; }
  await page.route('**/api/cockpit/helpdesk/magasin*', r => (r.request().method() === 'GET' ? r.fulfill({ json: lire() }) : r.fallback()));
};
