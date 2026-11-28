/* Vidéo 23 — « Mon coffre-fort » (mode d'emploi de la page Mon coffre-fort, ?vue=coffre, en bas du rail). Environ 3 min.
 * Données fictives : mocks/23_coffre.js (16 pièces attendues, 14 reçues ; l'assurance RC à renouveler ; le bail manque).
 * Le récit : l'état du coffre, le dossier Légal, l'aperçu de l'attestation d'assurance, puis le dépôt du bail : l'assistant
 * le lit et propose ses grandes dates (calculées depuis la prise de cours), on les range. */
const { Film } = require('../film');

/** Un cadre sans défiler : le bas du rail est épinglé ; le faire défiler jusque-là cacherait le haut du rail. */
async function cadreFixe(f, sel, etiq) {
  const r = await f.rect(sel, false);
  await f.p.evaluate(([r, e]) => window.__film.cadre(r, e), [{ x: r.x, y: r.y, w: r.w, h: r.h }, etiq]);
}

module.exports = async function () {
  const f = await Film.ouvrir('23-coffre-fort', { vue: 'coffre', serie: 'Mode d’emploi', titreSerie: 'Mon coffre-fort', duree: 'env. 3 min' });
  // L'assistant prend quelques secondes pour lire un document : la fausse API répond tout de suite, on la fait attendre.
  await f.p.route('**/api/cockpit/coffre/magasin/assistant', async r => { await new Promise(x => setTimeout(x, 2800)); await r.fallback(); });
  await f.couverture({
    kicker: 'Mode d’emploi', titre: 'Mon coffre-fort', accroche: 'Vos documents, en lieu sûr',
    programme: ['L’état du coffre', 'Les dossiers et leurs pièces', 'Ouvrir un document', 'Déposer avec l’assistant'],
  });

  await f.chapitre({ n: 1, total: 4, titre: 'L’état du coffre', texte: 'Ce qui est là, ce qui manque, ce qui arrive.' });
  await f.etape('1 · L’état du coffre');
  await cadreFixe(f, '#db-rail .db-rail-coffre .sidebar__item', 'Mon coffre-fort');
  await f.dire('<b>Mon coffre-fort</b> est tout en bas du rail. Le chiffre : les nouveaux documents.', 4200);
  await f.montrer('.cf2-rs-v', 'Votre coffre');
  await f.dire('Les <b>pièces attendues</b> : 14 sur 16 sont là, le coffre est incomplet. Il manque 2 pièces à déposer par vous.', 5000);
  await f.montrer('.cf2-rs-l2', 'À corriger');
  await f.dire('<b>À corriger</b> : l’assurance RC à renouveler, les nouveaux documents, les pièces légales à déposer.', 5000);
  await f.dire('Une pièce passe « à renouveler » <b>60 jours</b> avant son échéance.', 3800);
  await f.montrer('.cf2-rs-pr', 'Prochaine grande date');
  await f.montrer('.cf2-frise', 'Les 12 prochains mois');
  await f.dire('La <b>prochaine grande date</b>, et la frise des 12 prochains mois.', 3800);
  await f.taire(150); await f.cacher();

  await f.chapitre({ n: 2, total: 4, titre: 'Les dossiers et leurs pièces', texte: 'Six dossiers, et ce que chacun doit contenir.' });
  await f.etape('2 · Les dossiers');
  await f.montrer('.cf2-dos', 'Les dossiers');
  await f.dire('Contrat et DIP, légal, formation, rapports de visite, finances, courriers : <b>qui les remplit</b> est écrit dessous.', 5400);
  await f.taire(150); await f.cacher(150);
  await f.cliquer('[data-cf-dos="legal"]', { apres: 1000 });
  await f.montrer('.cf2-att', 'Pièces attendues');
  await f.dire('Le dossier <b>Légal</b> : vos pièces. Reçue, à renouveler, ou à déposer.', 4200);
  await f.montrer('.cf2-p.p-man:has([data-cf-exclure="permis_env"])', 'Permis d’environnement');
  await f.dire('Une pièce qui ne vous concerne pas ? « <b>Pas concernée ?</b> » l’écarte : elle ne compte plus.', 4600);
  await f.taire(150); await f.cacher();

  await f.chapitre({ n: 3, total: 4, titre: 'Ouvrir un document', texte: 'Un clic, et l’aperçu s’ouvre à droite.' });
  await f.etape('3 · Ouvrir un document');
  await f.cliquer('.cf2-p[data-cf-sel="d112"]', { apres: 1400 });
  await f.montrer('.cf2-tir .cf2-acts', 'Les gestes');
  await f.dire('L’aperçu à droite. <b>Ouvrir</b>, <b>télécharger</b>, la <b>joindre à un e-mail</b> de la boîte du magasin, ou la <b>remplacer</b>.', 4800);
  await f.montrer('.cf2-tir .cf2-kv', 'Les repères');
  await f.dire('Sa date, son échéance — à renouveler dans 42 jours —, la pièce, qui l’a déposée.', 4400);
  await f.montrerTout('.cf2-tir .cf2-tr', 'Qui l’a ouvert');
  await f.dire('Et <b>qui l’a ouvert</b> : chaque ouverture, chaque téléchargement, chaque envoi est noté.', 4600);
  await f.taire(150); await f.cacher(150);
  await f.cliquer('.cf2-tir [data-cf-ferme]', { apres: 900 });

  await f.chapitre({ n: 4, total: 4, titre: 'Déposer avec l’assistant', texte: 'L’assistant lit le document et propose ses grandes dates.' });
  await f.etape('4 · Déposer');
  await f.cliquer('.cf2-p [data-cf-depot="bail"]', { apres: 1100 });
  await f.montrer('.cf2-mod .cf2-chips', 'C’est quelle pièce ?');
  await f.dire('Le bail manquait : <b>Déposer</b> ouvre la fenêtre, la pièce est déjà choisie.', 4200);
  await f.taire(150); await f.cacher(150);
  const [choix] = await Promise.all([f.p.waitForEvent('filechooser'), f.cliquer('[data-cf-drop]', { apres: 300 })]);
  await choix.setFiles({ name: 'bail-commercial-boutique.pdf', mimeType: 'application/pdf', buffer: Buffer.concat([Buffer.from('%PDF-1.4\n% bail fictif\n'), Buffer.alloc(1265000, 32)]) });
  await f.pause(700);
  await f.p.locator('[data-cf-dp="date"]').fill('2024-05-15');
  await f.dire('On choisit le fichier — au téléphone, une photo —, puis <b>Déposer</b>.', 3800);
  await f.cliquer('[data-cf-envoi]', { apres: 600 });
  await f.montrer('.cf2-mod .d', 'L’assistant');
  await f.dire('Le bail est rangé. <b>L’assistant le lit</b>…', 2600);
  await f.pause(600);
  await f.montrerTout('.cf2-mod .d .cf2-res, .cf2-mod .d .cf2-ex', 'L’assistant a lu le bail');
  await f.dire('Il dit ce que c’est — un <b>bail commercial</b> de neuf ans — et cite la <b>phrase</b> d’où viennent les dates.', 5000);
  await f.montrerTout('.cf2-mod .d label.cf2-pp:nth-of-type(-n+2)', 'Les grandes dates');
  await f.dire('Pour un bail, les <b>triennats</b>, le <b>dernier jour pour donner congé</b> et le <b>renouvellement</b> sont calculés depuis la prise de cours.', 6000);
  await f.dire('Les dates passées restent décochées. On décoche ce qu’on ne garde pas.', 3400);
  await f.taire(150); await f.cacher(150);
  await f.cliquer('[data-cf-range]', { apres: 1500 });
  await f.montrer('.cf2-flash', 'Rangé');
  await f.dire('<b>Rangé</b>, avec ses grandes dates : le coffre a maintenant 15 pièces sur 16.', 4200);
  await f.montrer('.cf2-rs-pr', 'Prochaine grande date');
  await f.dire('La prochaine grande date : le <b>dernier jour pour donner congé</b> du 1er triennat, dans 11 jours.', 5000);
  await f.taire(150); await f.cacher(150);
  await f.cliquer('.cf2-barre [data-cf-dos="dates"]', { apres: 1200 });
  await f.montrerTout('.cf2-cont .cf2-gd.bientot', 'Grandes dates');
  await f.dire('<b>Grandes dates</b> : toutes, par année, avec le document d’où elles viennent.', 4400);
  await f.dire('En or, celles des quatre prochains mois.', 3000);
  await f.taire(150); await f.cacher();

  return f.fin({
    titre: 'À vous de jouer', accroche: 'Tout est rangé, rien n’échappe',
    retenir: ['Le coffre dit ce qui manque et ce qui expire', 'Six dossiers, chacun avec ses pièces', 'Chaque ouverture est notée', 'L’assistant relève les grandes dates'],
  });
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
