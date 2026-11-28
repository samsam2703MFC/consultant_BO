/* Réseaux sociaux (vue=social) : GET /dashboard/social/ouvrir, à la forme de dsRoute (panel/src/dashboard_marketing.php) :
 * {url, page, expire}, le lien court qui ouvre l'espace boutique du Marketing dans le cadre de panel/public/dashboard/reseaux.js.
 * Les pages de l'espace (calendrier, création d'un post, commentaires, avis…) sont servies par l'application Marketing,
 * qui n'est pas dans ce dépôt : rien n'en est inventé. Le lien mène ici à une page vide (/film/social/espace), et le cadre
 * reste vide : la vidéo 17 montre l'entrée du dashboard, pas l'espace. */
'use strict';
const PAGES = ['calendrier', 'publier', 'commentaires', 'messages', 'avis-google', 'operations', 'instagram'];

module.exports = function (m, p, q) {
  if (m !== 'GET') { return undefined; }
  if (p === '/dashboard/social/ouvrir') {
    const page = PAGES.includes(q.get('page')) ? q.get('page') : 'calendrier';
    return { url: '/api/cockpit/film/social/espace?page=' + page, page, expire: '2026-11-19T15:50:00+01:00' };
  }
  if (p === '/film/social/espace') {
    return { __body: '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Espace boutique</title></head><body style="margin:0;background:transparent"></body></html>', type: 'text/html' };
  }
  return undefined;
};
