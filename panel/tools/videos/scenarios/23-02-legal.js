/* Mon coffre-fort › le dossier « Légal » (?vue=coffre, « Légal » à gauche, ou « … pièces légales à déposer » dans À corriger), et ses
 * fonctions en chapitres (26/11/2026) :
 *   onglet    coffre-legal        les pièces que le magasin dépose : reçue, à renouveler, à déposer ; ouvrir une pièce
 *   fonction  coffre-renouveler   « Déposer la nouvelle » sur l'assurance RC : le fichier, le titre, « Déposer »,
 *                                 l'assistant relève la nouvelle échéance, « Ranger »
 *   fonction  coffre-ecarter      « pas concernée ? » sous le permis d'environnement, confirmer : il ne compte plus
 *   fonction  coffre-reintegrer   « la réintégrer » sous la pièce écartée
 * Données fictives : mocks/23_coffre.js (l'assistant y lit l'attestation RC 2027). Chaque phrase vient de
 * panel/public/dashboard/coffre.js (vueDossier, fenetre, exclure, rangerDepot) ou de consultant/src/coffre.php. */
const { Film } = require('../film');
const C = require('./coffre');

module.exports = async function () {
  const f = await Film.ouvrir('23-02-legal', { vue: 'coffre', serie: 'Mon coffre-fort', parent: 'coffre-fort', sources: C.SOURCES,
    avant: async p => { await C.marge(p); await C.lenteur(p, 2600); } });

  /* L'onglet */
  await f.onglet({
    id: 'coffre-legal', titre: 'Le dossier Légal',
    resume: 'Les pièces que le magasin dépose lui-même : reçue, à renouveler, à déposer, ou écartée parce qu’elle ne le concerne pas.',
    accroche: 'Vos pièces, à jour',
    programme: ['Renouveler une pièce', 'Écarter une pièce qui ne vous concerne pas', 'Réintégrer une pièce écartée'],
  });
  await f.montrer('.cf2-rs-b[data-cf-faire="dos:legal"]', 'À corriger');
  await f.dire('Deux pièces légales manquent : dans « À corriger », leur bouton ouvre le dossier <b>Légal</b>.', 4400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.cf2-rs-b[data-cf-faire="dos:legal"]', { apres: 1000 });
  await f.montrer('.cf2-cont .cf2-ch', 'Légal');
  await f.dire('<b>Légal</b> : vos pièces. Vous les déposez ; votre consultant le peut aussi.', 4000);
  await f.montrer('.cf2-att', 'Pièces attendues · 5 sur 7');
  await f.dire('Sept <b>pièces attendues</b> : les statuts, la BCE, les assurances, le bail, l’AFSCA, le permis d’environnement.', 5400);
  await f.montrer('.cf2-p.p-ok', 'Reçue');
  await f.dire('Coche verte : la pièce est là, avec sa date et qui l’a déposée.', 3600);
  await f.montrer('.cf2-p.p-or', 'À renouveler');
  await f.dire('<b>À renouveler</b> : 60 jours avant son échéance. « Déposer la nouvelle » la remplace.', 4400);
  await f.montrer('.cf2-p.p-man:has([data-cf-depot="bail"])', 'À déposer');
  await f.dire('En pointillés, <b>à déposer</b> : « Déposer », ou « pas concernée ? ».', 3800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.cf2-p[data-cf-sel="d110"]', { apres: 1200 });
  await C.cadreFixe(f, '.cf2-tir', 'L’aperçu');
  await f.dire('Une pièce reçue s’ouvre d’un clic, en <b>aperçu</b> à droite.', 3400);
  await f.cacher(150); await f.taire(150);
  await C.cliquerFixe(f, '.cf2-tir [data-cf-ferme].x', 900);
  await f.montrer('.cf2-cont .cf2-ch [data-cf-depot]', 'Déposer une pièce légale');
  await f.dire('Et « <b>Déposer une pièce légale</b> » ajoute tout autre document légal.', 3800);
  await f.cacher(300); await f.taire(200);

  /* Renouveler une pièce */
  await f.fonction({ id: 'coffre-renouveler', titre: 'Renouveler une pièce',
    resume: '« Déposer la nouvelle » sur une pièce à renouveler : la fenêtre s’ouvre, la pièce choisie ; l’assistant relève la nouvelle échéance, puis « Ranger ».',
    points: ['« Déposer la nouvelle »', 'Le fichier et son titre', '« Déposer »', 'L’échéance relevée, puis « Ranger »'] });
  await f.montrer('.cf2-p.p-or', 'À renouveler');
  await f.dire('L’attestation RC expire le 31 décembre ; la nouvelle est arrivée.', 3200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.cf2-p.p-or [data-cf-depot]', { apres: 1000 });
  await C.cadreFixe(f, '.cf2-mod .cf2-chips', 'La pièce, déjà choisie');
  await f.dire('La pièce est <b>déjà choisie</b> : « (nouvelle) ».', 3000);
  await f.cacher(150); await f.taire(150);
  await C.choisirFichier(f, C.pdf('attestation-rc-2027.pdf', 240));
  await f.cliquer('[data-cf-dp="titre"]', { apres: 200 });
  await f.p.keyboard.press('End');
  await f.taper(' 2027', 110);
  await C.cadreFixe(f, '.cf2-mod .g', 'Le fichier, le titre');
  await f.dire('Le fichier, puis le titre : « … <b>2027</b> ».', 3000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-cf-envoi]', { apres: 3400 });
  await C.cadreFixe(f, '.cf2-mod .d', 'L’assistant a lu le document');
  await f.dire('L’assistant l’a lu : une attestation valable jusqu’au <b>31 décembre 2027</b>.', 4400);
  await C.cadreFixe(f, '.cf2-mod [data-cf-dp="echeance"]', 'Échéance');
  await f.dire('Il remplit l’<b>échéance</b>, et propose la grande date.', 3200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-cf-range]', { apres: 1500 });
  await f.montrer('.cf2-flash', 'Rangé');
  await f.dire('<b>Rangé</b>, avec sa grande date : l’assurance RC n’est plus à traiter.', 4200);
  await f.cacher(150); await C.apresFlash(f);
  await f.montrer('.cf2-p[data-cf-sel="d150"]', 'Reçue · jusqu’au 31/12/2027');
  await f.dire('La pièce est <b>reçue</b>, jusqu’au 31/12/2027 ; l’ancienne reste dans « Autres documents ».', 4600);
  await f.cacher(300); await f.taire(200);

  /* Écarter une pièce */
  await f.fonction({ id: 'coffre-ecarter', titre: 'Écarter une pièce qui ne vous concerne pas',
    resume: '« pas concernée ? » sous une pièce à déposer, puis confirmer : elle ne compte plus dans le coffre.',
    points: ['« pas concernée ? » sous la pièce', 'Confirmer'] });
  await f.montrer('.cf2-p.p-man:has([data-cf-exclure="permis_env"])', 'Permis d’environnement');
  await f.dire('Votre boutique n’a pas de <b>permis d’environnement</b> ?', 3000);
  await f.cacher(150); await f.taire(150);
  await f.cliquerConfirme('[data-cf-exclure="permis_env"]', '« Permis d’environnement » ne concerne pas votre boutique ?', 'Elle ne comptera plus dans votre coffre.', { apres: 1200 });
  await f.montrer('.cf2-flash', 'Écartée');
  await f.dire('<b>Pièce écartée</b> : elle ne compte plus dans votre coffre.', 3400);
  await f.cacher(150); await C.apresFlash(f);
  await f.montrer('.cf2-rs-v', 'Votre coffre');
  await f.dire('Le compte suit : 14 pièces sur 15 attendues.', 3000);
  await f.montrer('.cf2-p.p-exc', 'Pas concernée');
  await f.dire('Elle reste dans la liste : « pas concernée · écartée par Camille R. ».', 3800);
  await f.cacher(300); await f.taire(200);

  /* Réintégrer une pièce écartée */
  await f.fonction({ id: 'coffre-reintegrer', titre: 'Réintégrer une pièce écartée',
    resume: 'Une pièce écartée par erreur : « la réintégrer », et elle compte de nouveau.',
    points: ['« la réintégrer » sous la pièce écartée'] });
  await f.montrer('.cf2-p.p-exc', 'Pas concernée');
  await f.dire('Écartée par erreur ? Sous la pièce : « <b>la réintégrer</b> ».', 3400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-cf-reintegrer="permis_env"]', { apres: 1200 });
  await f.montrer('.cf2-flash', 'Réintégrée');
  await f.dire('<b>Pièce réintégrée</b> : elle est de nouveau attendue, à déposer.', 3600);
  await f.cacher(150); await C.apresFlash(f);
  await f.montrer('.cf2-p.p-man:has([data-cf-exclure="permis_env"])', 'À déposer');
  await f.pause(2000);
  await f.cacher(300); await f.taire(200);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
