/* Offres › le formulaire de l'offre (nouvelle ou modifiée), et ses fonctions en chapitres (24/11/2026) :
 *   onglet    offres-formulaire    « Nouvelle offre » : le formulaire sur 2/3, la liste sur 1/3 ; les champs ; « Modifier »
 *   fonction  offres-creer         le nom, le client, le jour, les produits, « Créer l'offre dans TF Buddy »
 *   fonction  offres-livraison     « Livraison » : l'adresse et les frais ; le total les ajoute, la marge les laisse de côté
 * Données fictives : mocks/13_offres.js (clients inventés, catalogue de démo). Chaque phrase vient de
 * panel/public/dashboard/offres.js (formulaire, enregistrer, margeBloc : « livraison hors marge »). */
const { Film } = require('../film');

module.exports = async function () {
  const f = await Film.ouvrir('13-05-formulaire', {
    vue: 'offres', serie: 'Offres', parent: 'offres',
    sources: ['panel/public/dashboard/offres.js', 'panel/src/offres_client.php'],
  });

  /* L'onglet */
  await f.onglet({
    id: 'offres-formulaire', titre: 'Le formulaire de l’offre',
    resume: 'Nouvelle offre ou offre modifiée : le formulaire prend les deux tiers de l’écran, la liste se resserre, la marge se calcule pendant qu’on écrit.',
    accroche: 'Le devis, champ par champ',
    programme: ['Créer une offre', 'Prévoir une livraison'],
  });
  await f.montrer('[data-ofnouvelle]', 'Nouvelle offre');
  await f.dire('« <b>Nouvelle offre</b> » ouvre le formulaire ; « Modifier », sur une offre, ouvre le même, déjà rempli.', 5000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-ofnouvelle]', { apres: 1000 });
  await f.montrer('.of-g', 'La liste, sur 1/3');
  await f.dire('L’écran change : le formulaire prend les <b>deux tiers</b>, la liste se resserre à gauche.', 4600);
  await f.montrer('.of-f .of-ch2', 'Les champs');
  await f.dire('Le nom de l’offre, le client, le jour de l’événement, retrait ou livraison, les conditions, la description.', 5600);
  await f.montrer('.of-client', 'Le client');
  await f.dire('Le client se cherche dans TF Buddy, <b>particulier</b> ou <b>B2B</b>. Sans client, TF Buddy ne rend pas de PDF.', 5200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-oftype="b2b"]', { apres: 1100 });
  await f.montrer('.of-client', 'B2B');
  await f.dire('En B2B, le champ vide montre toutes les sociétés ; on tape pour réduire.', 4000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-oftype="particulier"]', { apres: 600 });
  await f.montrer('.of-lignes', 'Les produits');
  await f.dire('Les produits viennent du <b>catalogue du magasin</b> : quantité, prix TTC, remise, total, et la marge HT de la ligne.', 5800);
  await f.montrer('.of-f .of-actions', 'Enregistrer ou annuler');
  await f.dire('En bas : « Annuler » ferme le formulaire sans rien écrire ; l’autre bouton écrit l’offre dans TF Buddy.', 5200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-ofannuler]', { apres: 900 });
  await f.cliquer('[data-ofsel="416"]', { apres: 700 });
  await f.cliquer('[data-ofgeste="modifier"]', { apres: 1000 });
  await f.montrer('.of-f .of-d-h', 'Modifier');
  await f.dire('Sur une offre existante, le même formulaire arrive rempli ; il se termine par « <b>Enregistrer l’offre</b> ».', 5200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-ofannuler]', { apres: 900 });
  await f.defiler(-3000);

  /* Créer une offre */
  await f.fonction({ id: 'offres-creer', titre: 'Créer une offre',
    resume: 'Le nom, le client, le jour, les produits du catalogue : « Créer l’offre dans TF Buddy », elle y prend son numéro.',
    points: ['« Nouvelle offre »', 'Le nom et le client', 'Le jour et les produits', '« Créer l’offre dans TF Buddy »'], ms: 4800 });
  await f.cliquer('[data-ofnouvelle]', { apres: 900 });
  await f.cliquer('[data-off=nom]', { apres: 250 });
  await f.taper('Saint-Nicolas 06/12', 55);
  await f.cliquer('#of-cli-q', { apres: 250 });
  await f.taper('Delvaux', 110);
  await f.pause(900);
  await f.montrer('.of-client', 'Le client');
  await f.dire('Le client se cherche dans TF Buddy : un nom, un e-mail, une ville. Un clic le choisit.', 4000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-ofcli="7105"]', { apres: 700 });
  await f.survoler('[data-off=jour]', { pause: 300 });
  await f.p.locator('[data-off=jour]').fill('2026-12-06');
  await f.pause(600);
  await f.cliquer('[data-ofajout]', { apres: 400 });
  await f.taper('Paris-brest', 60);
  await f.p.keyboard.press('Tab'); await f.pause(600);
  await f.cliquer('[data-ofl="0"][data-ofc="qte"]', { apres: 200 });
  await f.p.keyboard.press('Control+A'); await f.taper('12', 120);
  await f.p.keyboard.press('Tab'); await f.pause(600);
  await f.cliquer('[data-ofajout]', { apres: 400 });
  await f.taper('Cookie chocolat', 60);
  await f.p.keyboard.press('Tab'); await f.pause(600);
  await f.cliquer('[data-ofl="1"][data-ofc="qte"]', { apres: 200 });
  await f.p.keyboard.press('Control+A'); await f.taper('30', 120);
  await f.p.keyboard.press('Tab'); await f.pause(700);
  await f.montrer('.of-f .of-marge', 'La marge');
  await f.dire('Chaque produit vient du catalogue, à son prix TTC ; la <b>marge</b> se calcule pendant qu’on écrit.', 4800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-ofenregistrer]', { apres: 1500 });
  await f.montrer('.of-ok', 'Créée');
  await f.dire('« <b>Créer l’offre dans TF Buddy</b> » : elle y est, en cours, avec son numéro.', 4000);
  await f.cacher(200);
  await f.montrer('[data-ofsel="419"]', 'Offre n° 419');
  await f.taire(150);
  await f.pause(1900);
  await f.cacher(300);

  /* Prévoir une livraison */
  await f.fonction({ id: 'offres-livraison', titre: 'Prévoir une livraison',
    resume: 'De « Retrait en magasin » à « Livraison » : l’adresse, le code postal, la ville et les frais. Le total TTC les ajoute, la marge les laisse de côté.',
    points: ['Choisir l’offre, « Modifier »', '« Livraison »', 'L’adresse et les frais', '« Enregistrer l’offre »'], ms: 4800 });
  await f.cliquer('[data-ofsel="418"]', { apres: 700 });
  await f.cliquer('[data-ofgeste="modifier"]', { apres: 1000 });
  await f.montrer('.of-f .of-ch2 .seg', 'Retrait ou livraison');
  await f.dire('L’offre n° 418 est à retirer en magasin. Le client préfère être livré.', 3600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-ofretrait="0"]', { apres: 700 });
  await f.cliquer('[data-ofliv="rue"]', { apres: 200 });
  await f.taper('Rue des Tilleuls 12', 40);
  await f.cliquer('[data-ofliv="cp"]', { apres: 200 });
  await f.taper('5000', 110);
  await f.cliquer('[data-ofliv="ville"]', { apres: 200 });
  await f.taper('Val-Fleuri', 50);
  await f.cliquer('[data-ofliv="frais"]', { apres: 200 });
  await f.taper('12', 140);
  await f.p.keyboard.press('Tab'); await f.pause(700);
  await f.montrer('.of-f .of-tot', 'Livraison 12 €');
  await f.dire('Le <b>total TTC</b> ajoute les frais de livraison…', 2800);
  await f.cacher(250);
  await f.montrer('.of-f .of-marge-n', 'Hors marge');
  await f.dire('…la <b>marge</b>, elle, les laisse de côté : « livraison hors marge ».', 3500);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-ofenregistrer]', { apres: 1500 });
  await f.montrer('.of-ok', 'Enregistrée');
  await f.dire('Enregistrée dans TF Buddy : l’offre dit maintenant « <b>livraison</b> », avec le code postal et la ville.', 4600);
  await f.cacher(200);
  await f.montrer('[data-ofsel="418"]', 'Livraison');
  await f.taire(150);
  await f.pause(1900);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
