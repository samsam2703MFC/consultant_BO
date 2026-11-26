/* Offres › l'onglet « En cours » (?vue=offres), et ses fonctions en chapitres (24/11/2026) :
 *   onglet    offres-en-cours      la liste, les chiffres, chercher, une offre et sa marge
 *   fonction  offres-modifier      changer une quantité, enregistrer
 *   fonction  offres-envoyer       « Envoyer par e-mail » : la Boîte mail s'ouvre sur l'e-mail prêt
 *   fonction  offres-convertir     l'heure du retrait, qui prépare, convertir
 *   fonction  offres-archiver      l'offre quitte la liste, va dans Archivées
 *   fonction  offres-supprimer     l'avertissement, puis supprimer (une offre en double)
 * Données fictives : mocks/13_offres.js ; avant de filmer, on ouvre la boîte (70_boite.js) et on crée l'offre en double
 * n° 419. Chaque phrase vient de panel/public/dashboard/offres.js ou de panel/src/offres_client.php. */
const { Film } = require('../film');

const API = '../api/cockpit';
const poster = (p, chemin, corps) => p.evaluate(([u, b]) => fetch(u, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(b) }), [API + chemin, corps]);

module.exports = async function () {
  const f = await Film.ouvrir('13-01-en-cours', {
    vue: 'offres', serie: 'Offres', parent: 'offres',
    sources: ['panel/public/dashboard/offres.js', 'panel/src/offres_client.php', 'panel/public/dashboard/boite.js'],
    avant: async p => {
      await poster(p, '/dashboard/boite/ouvrir', { shop: '4', motDePasse: 'demo' });
      await poster(p, '/exploitation/offres-client', { action: 'creer', offre: { nom: 'Communion 06/12 (en double)', clientId: 7102, jour: '2026-12-06', retrait: true,
        lignes: [{ nom: 'Millefeuille', qte: 10 }, { nom: 'Fraisier 6 parts', qte: 2 }] } });
      await p.reload(); await p.waitForTimeout(2500);
      await p.evaluate(() => window.__film.prets());
    },
  });

  /* L'onglet */
  await f.onglet({
    id: 'offres-en-cours', titre: 'Les offres en cours',
    resume: 'Les offres envoyées ou à envoyer : les chiffres du haut, la recherche, une offre et sa marge.',
    accroche: 'Vos devis, d’un coup d’œil',
    programme: ['Modifier une offre', 'L’envoyer par e-mail', 'La convertir en commande', 'L’archiver', 'La supprimer'],
  });
  await f.montrer('.of-onglets .page-tabs', 'En cours');
  await f.dire('« <b>En cours</b> » : les offres envoyées ou à envoyer. Elles s’ouvrent ici par défaut.', 4200);
  await f.montrer('.of-kpis', 'Les chiffres');
  await f.dire('En tête : les offres en cours, les converties, le <b>taux de conversion</b> et le prochain événement.', 4600);
  await f.cacher(300);
  await f.cliquer('#of-q', { apres: 250 });
  await f.taper('Lambert', 110);
  await f.pause(700);
  await f.montrer('.of-g', 'Chercher');
  await f.dire('La recherche réduit la liste à chaque lettre : un numéro, un client ou un produit.', 4000);
  await f.cacher(200);
  await f.p.locator('#of-q').fill(''); await f.p.locator('#of-q').dispatchEvent('input');
  await f.pause(500);
  await f.cliquer('[data-ofsel="418"]', { apres: 700 });
  await f.montrer('.of-gestes', 'Les gestes');
  await f.dire('Un clic ouvre l’offre à droite, avec ses gestes : modifier, le PDF, l’e-mail, convertir, archiver, supprimer.', 5400);
  await f.montrer('.of-d .of-marge-k', 'La marge');
  await f.dire('Dessous, sa <b>marge</b> : vert à 60 % et plus, orange entre 40 et 60 %, rouge en dessous.', 4600);
  await f.taire(150); await f.cacher(300);
  await f.defiler(-3000);

  /* Modifier */
  await f.fonction({ id: 'offres-modifier', titre: 'Modifier une offre', resume: 'Changer une quantité ou un prix, puis enregistrer : l’offre change dans TF Buddy.',
    points: ['Choisir l’offre', '« Modifier »', 'Changer la ligne', '« Enregistrer l’offre »'] });
  await f.cliquer('[data-ofsel="416"]', { apres: 700 });
  await f.cliquer('[data-ofgeste="modifier"]', { apres: 900 });
  await f.dire('Le formulaire prend l’écran ; la marge se recalcule pendant qu’on écrit.', 3600);
  const qte = f.p.locator('[data-ofc="qte"]').nth(3);
  await f.cliquer(qte, { apres: 200 });
  await f.p.keyboard.press('Control+A'); await f.taper('14', 140);
  await f.p.keyboard.press('Tab'); await f.pause(700);
  await f.montrer(qte, '10 → 14');
  await f.dire('Quatre millefeuilles de plus : le total et la marge suivent.', 3400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-ofenregistrer]', { apres: 1500 });
  await f.montrer('.of-ok', 'Enregistrée');
  await f.dire('<b>Enregistrer l’offre</b> : elle est à jour dans TF Buddy. Une offre convertie, elle, ne se modifie plus.', 4800);
  await f.cacher(300); await f.taire(200);

  /* Envoyer par e-mail */
  await f.fonction({ id: 'offres-envoyer', titre: 'Envoyer une offre par e-mail', resume: '« Envoyer par e-mail » ouvre la Boîte mail sur l’e-mail au client, l’offre jointe en PDF.',
    points: ['Choisir l’offre', '« Envoyer par e-mail »', 'Relire', '« Envoyer »'] });
  await f.cliquer('[data-ofsel="416"]', { apres: 700 });
  await f.cliquer('[data-ofmail]', { apres: 2200 });
  await f.dire('La <b>Boîte mail</b> s’ouvre sur l’e-mail au client : son adresse, l’objet « Notre offre n° 416 », l’offre jointe en PDF.', 5600);
  await f.montrer('#bx-texte', 'Le modèle « Envoi de l’offre »');
  await f.dire('Le texte vient du modèle « <b>Envoi de l’offre</b> », déjà rempli : on le relit, on le change si besoin.', 4800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-bx="envoyer"]', { apres: 1600 });
  await f.dire('On relit, puis <b>Envoyer</b> : l’e-mail part de l’adresse du magasin.', 3600);
  await f.taire(150);
  await f.cliquer(f.p.locator('.sidebar__item[data-vue="offres"]').first(), { apres: 1600 });

  /* Convertir */
  await f.fonction({ id: 'offres-convertir', titre: 'Convertir une offre en commande', resume: 'Le client dit oui : l’heure du retrait, qui prépare, et la commande se crée dans TF Buddy.',
    points: ['Choisir l’offre', '« Convertir en commande »', 'L’heure et qui prépare', 'Confirmer'] });
  await f.cliquer('[data-ofsel="418"]', { apres: 700 });
  await f.cliquer('[data-ofgeste="convertir"]', { apres: 1000 });
  await f.montrer('.of-panneau', 'Convertir en commande');
  await f.dire('On donne l’heure du retrait, ou du départ de la livraison, et qui prépare la commande.', 4200);
  await f.cacher(150); await f.taire(150);
  await f.survoler('#of-conv-emp', { pause: 400 });
  await f.p.locator('#of-conv-emp').selectOption('205');
  await f.pause(700);
  await f.cliquer('[data-ofconvertir]', { apres: 1500 });
  await f.montrer('.of-ok', 'Convertie');
  await f.dire('La commande est dans TF Buddy ; l’offre passe dans « <b>Converties</b> ».', 4000);
  await f.cacher(300); await f.taire(200);
  await f.cliquer('[data-ofong="cours"]', { apres: 900 });

  /* Archiver */
  await f.fonction({ id: 'offres-archiver', titre: 'Archiver une offre', resume: 'Une offre restée sans suite quitte la liste et se range dans « Archivées » ; elle peut revenir.',
    points: ['Choisir l’offre', '« Archiver »'] });
  await f.cliquer('[data-ofsel="417"]', { apres: 700 });
  await f.cliquer('[data-ofgeste="archiver"]', { apres: 1500 });
  await f.montrer('.of-ok', 'Archivée');
  await f.dire('L’offre quitte « En cours » et se range dans « <b>Archivées</b> », d’où « Remettre en cours » la fait revenir.', 5000);
  await f.cacher(300); await f.taire(200);

  /* Supprimer */
  await f.fonction({ id: 'offres-supprimer', titre: 'Supprimer une offre', resume: 'Pour une offre faite en double : elle disparaît de TF Buddy. Sinon, archivez-la plutôt.',
    points: ['Choisir l’offre', '« Supprimer »', 'Lire l’avertissement', 'Confirmer'] });
  await f.cliquer('[data-ofsel="419"]', { apres: 700 });
  await f.dire('Une offre faite <b>en double</b>.', 2400);
  await f.cliquer('[data-ofgeste="supprimer"]', { apres: 900 });
  await f.montrer('.of-panneau', 'L’avertissement');
  await f.dire('Elle disparaît de TF Buddy avec ses lignes. Pour la garder sans la voir, on l’<b>archive</b> plutôt.', 4800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-ofsupprimer]', { apres: 1500 });
  await f.montrer('.of-ok', 'Supprimée');
  await f.dire('Supprimée. Une offre convertie, elle, ne se supprime pas : elle s’archive.', 4000);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
