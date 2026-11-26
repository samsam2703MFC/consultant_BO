/* Trésorerie › l'onglet « Frais » (5e onglet de la Trésorerie ; ?vue=frais y est redirigée), et ses fonctions en
 * chapitres (24/11/2026). La page Frais du rail n'a pas d'autre onglet : c'est la même vidéo (pas de 09-07) :
 *   onglet    frais-du-mois        les frais du mois au panel, changer de mois, les quatre tuiles, « TVA comprise ? » et
 *                                  le taux, un poste à indiquer, « Ajouter un frais », ce que ça change dans le P&L court
 *   fonction  frais-tva-comprise   Oui sur l'entretien : le hors TVA et les tuiles se recalculent
 *   fonction  frais-taux-tva       6 % sur l'eau : un clic sur le taux suffit
 *   fonction  frais-ajouter        un contrat annuel réparti sur l'année : 12 mois au panel
 *   fonction  frais-annuler-ajout  un ajout fait en double, annulé : ses lignes supprimées au panel
 * Données fictives : mocks/09_tresorerie.js ; avant de filmer, un ajout en double est créé (l'abonnement de l'alarme,
 * novembre et décembre). Chaque phrase vient de panel/public/dashboard/dashboard.js (rendFrais, frRendAjout, frEnvoyer,
 * frAnnuler) ou de panel/src/frais.php. */
const { Film } = require('../film');
const { fiabiliser, T, poster, recharger, placeSousLaPage, fonction } = require('./tresorerie');

const FX = T + '.fx ';
/** La ligne du tableau d'un poste, et son rang (data-frt / data-frx portent le rang de la ligne). */
const rangPoste = (f, poste) => f.p.evaluate(p => [...document.querySelectorAll('#db-tresorerie .fx-t tbody tr')].findIndex(tr => (tr.querySelector('td') || {}).firstChild && tr.querySelector('td').firstChild.textContent.trim() === p), poste);
const tr = i => FX + `.fx-t tbody tr:nth-child(${i + 1})`;

module.exports = async function () {
  const f = await Film.ouvrir('09-05-frais', {
    vue: 'frais', serie: 'Frais', parent: 'tresorerie', chargement: 3500,
    sources: ['panel/public/dashboard/dashboard.js', 'panel/src/frais.php', 'shared/src/pnl_ht.php'],
    avant: async p => {
      await poster(p, '/exploitation/frais/ajout', { shop: '4', nom: 'Abonnement alarme (en double)', type: '7', montant: 39, base: 'mois', mois: '2026-11', repeter: 'fin', tva: true, taux: 21 });
      await recharger(p, 'frais');
    },
  });
  fiabiliser(f);

  /* L'onglet */
  await f.onglet({
    id: 'frais-du-mois', titre: 'Les frais du mois',
    resume: 'Les frais du magasin saisis au panel, mois par mois : « TVA comprise ? » et le taux de chaque poste, les quatre tuiles, ce que ça change dans le P&L court, et « Ajouter un frais ».',
    accroche: 'Des frais généraux hors TVA',
    programme: ['Indiquer si un poste contient la TVA', 'Choisir le taux de TVA', 'Ajouter un frais au panel', 'Annuler un ajout'],
  });
  await f.montrer(T + '.tr-ong', 'Frais');
  await f.dire('<b>Frais</b>, un onglet de la Trésorerie : les frais du magasin tels qu’ils sont saisis au panel, mois par mois.', 5600);
  await f.montrer(FX + '.fx-nav', 'Le mois');
  await f.dire('‹ et › changent de mois.', 2400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer(FX + '[data-frm="-1"]', { apres: 1500 });
  await f.montrer(FX + '.fx-nav', 'Octobre');
  await f.dire('Octobre 2026. « <b>Ce mois-ci</b> » ramène au mois en cours ; au mois en cours, › est grisé.', 5000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer(FX + '[data-frm="0"]', { apres: 1500 });
  await f.montrer(FX + '.fx-tuiles', 'Les tuiles');
  await f.dire('Quatre tuiles : le saisi au panel, le <b>hors TVA</b> que le P&L compte, la TVA comprise, et les postes à indiquer.', 6000);
  await f.montrer(FX + '.fx-t thead', 'TVA comprise ? · taux');
  await f.dire('Pour chaque poste : le montant saisi <b>contient-il la TVA</b>, et à quel taux ? Le P&L court la retire.', 5400);
  const ent = await rangPoste(f, 'Entretien et nettoyage');
  await f.montrer(tr(ent), 'À indiquer');
  await f.dire('Un poste pas encore indiqué compte tel que saisi, et reste en orange : ici, et sous « Frais généraux » du P&L.', 5800);
  await f.montrer(FX + '.fx-aj', 'Ajouter un frais');
  await f.dire('Un frais qui manque s’<b>ajoute au panel</b>, pour le mois ou toute l’année. Les ajouts faits d’ici restent listés.', 5600);
  await placeSousLaPage(f);   // la dernière rangée de cartes remonte au-dessus du sous-titre
  await f.montrer(FX + '.fx-g2 > .db-card:first-child', 'Le P&L court');
  await f.dire('Ce que ça change dans le <b>P&L court</b> : les frais généraux du jour et du mois, saisis et hors TVA.', 5200);
  await f.taire(150); await f.cacher(300);
  await f.defiler(-4000);

  /* TVA comprise ? */
  await fonction(f, { id: 'frais-tva-comprise', titre: 'Indiquer si un poste contient la TVA',
    resume: 'Oui ou Non sur la ligne : le hors TVA et les tuiles se recalculent ; le choix se garde pour le poste, d’un mois à l’autre.',
    points: ['« TVA comprise ? » sur la ligne', 'Oui : le P&L en retire la TVA', 'Recliquer le choix : à indiquer'] });
  await f.montrer(tr(ent), 'Entretien et nettoyage');
  await f.dire('L’entretien : 310 € saisis au panel, pas encore indiqué. La facture est TVA comprise.', 4400);
  await f.cacher(150); await f.taire(150);
  await f.cliquer(FX + `[data-frt="${ent}"][data-v="true"]`, { apres: 1600 });
  await f.montrer(tr(await rangPoste(f, 'Entretien et nettoyage')), 'Oui · 21 %');
  await f.dire('<b>Oui</b> : 21 % proposé d’office. Hors TVA, 256,20 € ; la TVA, 53,80 €, est retirée des frais généraux.', 6000);
  await f.montrer(FX + '.fx-tuiles', 'Les tuiles suivent');
  await f.dire('Les tuiles suivent : un poste de moins à indiquer. Le choix se garde pour le poste, d’un mois à l’autre.', 5400);
  await f.dire('<b>Non</b> : le montant est déjà hors TVA, ou sans TVA. Recliquer le même choix remet le poste à indiquer.', 5600);
  await f.taire(150); await f.cacher(300);

  /* Le taux */
  await fonction(f, { id: 'frais-taux-tva', titre: 'Choisir le taux de TVA d’un poste',
    resume: '0, 6, 12 ou 21 % sur la ligne : le hors TVA suit. Un clic sur un taux suffit à dire que la TVA est comprise.',
    points: ['Le taux sur la ligne', '0, 6, 12 ou 21 %', 'Le hors TVA suit'] });
  const eau = await rangPoste(f, 'Eau');
  await f.montrer(tr(eau), 'Eau');
  await f.dire('L’eau : 64 € saisis, pas encore indiquée. Sa TVA est de <b>6 %</b>.', 4000);
  await f.cacher(150); await f.taire(150);
  await f.cliquer(FX + `[data-frx="${eau}"][data-v="6"]`, { apres: 1600 });
  await f.montrer(tr(await rangPoste(f, 'Eau')), 'Oui · 6 %');
  await f.dire('Un clic sur <b>6 %</b> suffit : le poste passe à Oui, 6 %. Hors TVA : 60,38 €.', 4800);
  await f.montrer(FX + '.fx-tuiles', 'Plus rien à indiquer');
  await f.dire('Plus aucun poste à indiquer : les frais généraux du P&L se lisent entièrement hors TVA.', 4600);
  await f.taire(150); await f.cacher(300);

  /* Ajouter un frais */
  await fonction(f, { id: 'frais-ajouter', titre: 'Ajouter un frais au panel',
    resume: 'Libellé, type, montant par mois ou total à répartir, les mois, la TVA : le frais est créé au panel, une ligne par mois.',
    points: ['Libellé, type, montant', 'Par mois, ou un total à répartir', 'Répéter, et la TVA', '« Ajouter au panel », confirmer'] });
  await f.montrer(FX + '.fx-aj', 'Ajouter un frais');
  await f.dire('Un contrat d’entretien du four, payé une fois l’an : <b>1 440 €</b>, à répartir sur l’année.', 4200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer(FX + '[data-frin="nom"]', { apres: 200 });
  await f.taper('Contrat d’entretien du four', 45);
  await f.survoler(FX + '[data-frin="type"]', { pause: 250 });
  await f.p.evaluate(([x, y]) => window.__film.onde(x, y), [f.souris.x, f.souris.y]);
  await f.p.locator(FX + '[data-frin="type"]').selectOption({ label: 'Entretien' });
  await f.pause(500);
  await f.cliquer(FX + '[data-frin="montant"]', { apres: 200 });
  await f.taper('1440', 100);
  await f.cliquer(FX + '[data-frf="base"][data-v="total"]', { apres: 600 });
  await f.cliquer(FX + '[data-frf="tva"][data-v="true"]', { apres: 700 });
  await f.montrer(FX + '.fx-aj-f', 'Le formulaire');
  await f.dire('Libellé, type de frais du panel, montant : <b>un total à répartir</b>. Répéter : toute l’année 2026. TVA comprise, 21 %.', 6200);
  await f.montrer(FX + '.fx-aj-pied', 'L’aperçu');
  await f.dire('L’aperçu : 12 mois au panel, de janvier à décembre, <b>120 €</b> par mois.', 4200);
  await f.cacher(150); await f.taire(150);
  await f.cliquerConfirme(FX + '[data-fraj]', 'Créer « Contrat d’entretien du four » au panel pour 12 mois (janvier → décembre 2026) ?', '', { apres: 1600 });
  await f.montrer(FX + '.fx-aj-res', 'Créé au panel');
  await f.dire('Créé au panel pour 12 mois ; « Annuler cet ajout » le défait.', 3600);
  await f.cacher(150); await f.taire(150);
  await f.montrer(tr(await rangPoste(f, 'Contrat d’entretien du four')), 'Dans les frais du mois');
  await f.dire('Il compte déjà dans les frais du mois : 120 €, TVA à 21 %.', 3600);
  await f.taire(150); await f.cacher(300);

  /* Annuler un ajout */
  await fonction(f, { id: 'frais-annuler-ajout', titre: 'Annuler un ajout',
    resume: '« Annuler », puis confirmer : les lignes que l’ajout a créées au panel sont supprimées.',
    points: ['« Ajoutés d’ici »', '« Annuler »', 'Confirmer : supprimé au panel'] });
  await f.montrer(FX + '.fx-aj-l', 'Ajoutés d’ici');
  await f.dire('« Ajoutés d’ici » : un frais créé deux fois, l’abonnement de l’alarme, novembre et décembre.', 4800);
  await f.cacher(150); await f.taire(150);
  await f.cliquerConfirme(FX + '.fx-aj-l [data-fran]', 'Supprimer au panel les lignes créées par cet ajout ?', '', { apres: 1800 });
  await f.montrer(f.p.locator(FX + '.fx-aj-res').last(), 'Annulé');
  await f.dire('Ajout annulé : <b>2 mois supprimés</b> au panel. Le frais quitte les frais du mois.', 4800);
  await f.montrer(FX + '.fx-tuiles', 'Les tuiles suivent');
  await f.dire('Les tuiles se recalculent sans lui.', 3000);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
