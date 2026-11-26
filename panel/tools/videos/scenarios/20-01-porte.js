/* Boîte mail › la porte (?vue=boite, la boîte fermée sur cet appareil), et sa fonction en chapitre (24/11/2026) :
 *   onglet    boite-porte    le mot de passe de la boîte, une fois par appareil (30 jours) ; « fermer sur cet appareil »
 *   fonction  boite-relier   la première fois : l'adresse et le mot de passe de la boîte OVH, « Relier la boîte »
 * « Ouvrir la boîte » et « fermer sur cet appareil » ne posent ou n'effacent que le cookie de l'appareil : ils se voient
 * dans le chapitre de l'onglet. Données fictives : mocks/70_boite.js (magasin.demo@exemple.be, reliée et fermée au
 * départ ; « délier », sous la diapositive, la rend non reliée). Chaque phrase vient de panel/public/dashboard/boite.js
 * (vueOuvrir, vueRelier) ou de panel/src/boite_mail.php (bmRelier, bmOuvrir). */
const { Film } = require('../film');
const B = require('./boite');

module.exports = async function () {
  const f = await Film.ouvrir('20-01-porte', { vue: 'boite', serie: 'Boîte mail', parent: 'boite-mail',
    sources: ['panel/public/dashboard/boite.js', 'panel/src/boite_mail.php'], avant: B.marge });

  /* L'onglet : la porte */
  await f.onglet({
    id: 'boite-porte', titre: 'La porte de la boîte',
    resume: 'La boîte du magasin s’ouvre avec son mot de passe, une fois par appareil et pour 30 jours ; « fermer sur cet appareil » la referme.',
    accroche: 'Vos e-mails, sous clé', programme: ['Relier la boîte du magasin'],
  });
  await f.montrer('#db-rail .sidebar__item[data-vue=boite]', 'Boîte mail');
  await f.dire('La <b>Boîte mail</b>, en tête du rail : la boîte e-mail du magasin, dans le dashboard.', 4000);
  await f.montrer('.bx-porte-c', 'La porte');
  await f.dire('Sur un appareil où elle n’est pas ouverte, elle demande <b>son mot de passe</b>.', 4000);
  await f.montrer('.bx-porte-c h2', 'L’adresse');
  await f.dire('En titre, l’adresse de la boîte : ici, magasin.demo@exemple.be.', 3600);
  await f.dire('Une fois par appareil : elle reste ouverte <b>30 jours</b>.', 3200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#bx-o-mdp', { apres: 200 });
  await f.taper('demo-boite', 60);
  await f.cliquer('[data-bx=ouvrir]', { apres: 1600 });
  await B.cadre(f, '.bx', 'La boîte ouverte');
  await f.dire('<b>Ouvrir la boîte</b> : les dossiers, la liste des e-mails et, à droite, l’e-mail ouvert.', 4400);
  await f.montrer('#db-rail .sidebar__item[data-vue=boite]', 'Les non-lus');
  await f.dire('Le rail compte les <b>non-lus</b> de la réception.', 3000);
  await f.montrer('.bx-bas', 'Fermer sur cet appareil');
  await f.dire('Tout en bas, « <b>fermer sur cet appareil</b> » referme la boîte ici.', 3800);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('.bx-bas [data-bx=fermer]', { apres: 1400 });
  await B.enHaut(f); await f.pause(600);
  await f.montrer('.bx-porte-c', 'Fermée');
  await f.dire('La porte revient : sur cet appareil, il faudra de nouveau le mot de passe.', 4000);
  await f.cacher(300); await f.taire(200);

  /* Relier la boîte. Sous la diapositive : la boîte est déliée (comme dans un magasin qui ne l'a pas encore reliée) ;
   * l'exemple du champ d'adresse (« halle@… », une adresse réelle du réseau) est vidé pour le film. */
  await B.sousLaDiapo(f, { relire: true, ecritures: [['/dashboard/boite/ouvrir', { motDePasse: 'demo' }], ['/dashboard/boite/delier', {}]] });
  await f.p.evaluate(() => setTimeout(() => { const i = document.querySelector('#bx-r-adr'); if (i) { i.placeholder = ''; } }, 1600));
  await f.fonction({ id: 'boite-relier', titre: 'Relier la boîte du magasin',
    resume: 'La première fois : l’adresse e-mail du magasin et le mot de passe de sa boîte chez OVH. Le mot de passe est essayé, puis gardé côté serveur.',
    points: ['L’adresse e-mail du magasin', 'Le mot de passe de sa boîte', '« Relier la boîte »'] });
  await f.montrer('.bx-porte-c', 'Relier la boîte');
  await f.dire('La première fois, la boîte n’est pas encore <b>reliée</b> au dashboard.', 3600);
  await f.dire('Il faut l’adresse e-mail du magasin et le mot de passe de sa boîte, chez <b>OVH</b>.', 4200);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('#bx-r-adr', { apres: 200 });
  await f.taper('magasin.demo@exemple.be', 45);
  await f.cliquer('#bx-r-mdp', { apres: 200 });
  await f.taper('demo-boite', 60);
  await f.montrer('#bx-r-mdp', 'Le mot de passe');
  await f.dire('Il est essayé auprès du serveur, puis <b>gardé côté serveur</b> avec les clés de l’ERP : il ne s’affiche plus jamais.', 5600);
  await f.cacher(150); await f.taire(150);
  await f.cliquer('[data-bx=relier]', { apres: 1800 });
  await f.montrer('.bx-ok', 'Reliée');
  await f.dire('<b>Boîte reliée</b> : elle s’ouvre aussitôt sur cet appareil.', 3400);
  await f.cacher(300);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
