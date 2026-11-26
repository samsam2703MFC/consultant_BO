/* La demande au bureau (la fenêtre de l'assistant, troisième onglet : dashboard.js, hdVue), servie par
 * consultant/src/helpdesk.php (ep_helpdesk_magasin, wr_helpdesk_magasin) : les domaines du helpdesk, les demandes du
 * magasin de démo (statut, échéance fixée par la consultante, reprise dans une visite, réponse du bureau), et l'envoi
 * d'une nouvelle demande (elle rejoint la liste relue, « Nouvelle », sans réponse). Tout est fictif : la consultante porte
 * le prénom inventé du dossier de formation (72_formation.js), les références sont inventées.
 *
 * ATTENTION : 00_commun.js rend GET /helpdesk/magasin vide ({demandes: []}) et passe avant ce module (ordre des noms) ;
 * seul l'envoi (POST) arrive ici par la chaîne de mock.js. Un scénario qui montre « Mes demandes » fait passer la lecture
 * par ce module (scenarios/21-06-demande-bureau.js : ctx.route sur /helpdesk/magasin). */
'use strict';

const DOMAINES = { strategy: 'Stratégie', product: 'Produit', operational_activities: 'Activités opérationnelles', sales: 'Ventes', app_development: 'Application', social_media: 'Réseaux sociaux' };
const STATUTS = { NEW: 'Nouvelle', IN_PROGRESS: 'En cours', RESOLVED: 'Résolue', CLOSED: 'Fermée', CANCELLED: 'Annulée' };
const CONSULTANTE = 'Nathalie D.';

const d = (id, ref, dom, statut, titre, description, cree, o) => Object.assign({ id, ref, domaine: dom, domaineNom: DOMAINES[dom], statut, statutNom: STATUTS[statut],
  ouverte: statut === 'NEW' || statut === 'IN_PROGRESS', titre, description, shop: '4', magasin: 'Magasin démo', demandeur: '', reponse: '', reponseLe: null,
  cree, maj: cree, consultant: '', consultantId: null, reunion: null, echeance: null, urgent: false, visite: null }, o || {});

/* Les demandes du magasin, la plus récente d'abord. */
const S = { n: 1188, demandes: [
  d(1183, 'CASE-1183', 'product', 'IN_PROGRESS', 'Boîtes à bûche pour décembre', 'Il nous faudrait 60 boîtes à bûche de plus pour les commandes de Noël. Peut-on les ajouter à la livraison du 30/11 ?',
    '2026-11-17 09:42:00', { consultant: CONSULTANTE, echeance: '2026-11-24' }),
  d(1171, 'CASE-1171', 'app_development', 'NEW', 'La tablette du fournil reste sur le chargement', 'Depuis ce matin, l’application ne s’ouvre plus sur la tablette du fournil : elle reste sur l’écran de chargement.',
    '2026-11-16 16:20:00', { echeance: '2026-11-20' }),
  d(1176, 'CASE-1176', 'operational_activities', 'IN_PROGRESS', 'Former l’équipe au nouveau four', 'Deux personnes de l’équipe n’ont pas encore vu les programmes du nouveau four. Un passage pour les former ?',
    '2026-11-14 11:05:00', { consultant: CONSULTANTE, visite: { le: '2026-11-24', consultant: CONSULTANTE } }),
  d(1158, 'CASE-1158', 'social_media', 'RESOLVED', 'Affiche de Saint-Nicolas pour la vitrine', 'Avez-vous une affiche pour la vitrine du 6 décembre ?',
    '2026-11-09 10:15:00', { reponse: 'Deux affiches A2 partent avec votre livraison du 30/11.', reponseLe: '2026-11-12 16:20:00', consultant: CONSULTANTE }),
  d(1139, 'CASE-1139', 'sales', 'CLOSED', 'Le prix du pain aux noix', 'Le pain aux noix est plus cher chez nous que dans les autres magasins : est-ce voulu ?',
    '2026-11-02 14:30:00', { reponse: 'Oui : chaque magasin fixe son prix, sous le maximum du réseau.', reponseLe: '2026-11-04 09:10:00', consultant: CONSULTANTE }),
] };

module.exports = function (m, p, q, c) {
  if (p !== '/helpdesk/magasin') { return undefined; }
  c = c && typeof c === 'object' ? c : {};
  if (m === 'GET') { return { domaines: DOMAINES, demandes: S.demandes, lu: '2026-11-19T15:40:00+01:00' }; }
  if (m !== 'POST') { return undefined; }
  if (!DOMAINES[c.domaine]) { return { __status: 422, json: { ok: false, error: 'Choisissez le sujet de la demande.' } }; }
  if (!String(c.titre || '').trim() || !String(c.description || '').trim()) { return { __status: 422, json: { ok: false, error: 'Le titre et la demande sont requis.' } }; }
  const id = S.n++, ref = 'CASE-' + id;
  S.demandes.unshift(d(id, ref, c.domaine, 'NEW', String(c.titre).trim(), String(c.description).trim(), '2026-11-19 15:40:00', { urgent: !!c.urgent }));
  return { ok: true, id, ref };
};
