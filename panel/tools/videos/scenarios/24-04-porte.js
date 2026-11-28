/* Paramètres › la porte de Mails et automatisations (?vue=mails, tant que la boîte n'est pas ouverte sur l'appareil, ou
 * pas encore reliée) (26/11/2026) :
 *   onglet    param-porte   pourquoi rien ne se règle (« Ouvrez la boîte mail sur cet appareil », ou la boîte pas encore
 *                           reliée), la fenêtre des Paramètres qui le dit aussi, et « Aller à la boîte mail »
 * Pas de fonction : « Aller à la boîte mail » ouvre la Boîte mail (sa porte : les vidéos de la Boîte mail). Données
 * fictives : mocks/70_boite.js (la boîte reliée, fermée au départ ; « délier », hors champ, la rend non reliée).
 * Chaque phrase vient de panel/public/dashboard/automatisations.js (porte, html) ou de dashboard.js (parItems, parRappel). */
const { Film } = require('../film');
const P = require('./parametres');

module.exports = async function () {
  const f = await Film.ouvrir('24-04-porte', { vue: 'mails', serie: 'Paramètres', parent: 'mails-automatisations', sources: P.SOURCES, avant: P.depart({ ouverte: false }) });

  await f.onglet({
    id: 'param-porte', titre: 'Boîte fermée : la porte',
    resume: 'Mails et automatisations se règlent boîte ouverte : tant qu’elle ne l’est pas sur cet appareil, ou pas encore reliée, la page le dit et mène à la Boîte mail.',
    accroche: 'D’abord la boîte',
  });
  await f.montrer('.au-porte', 'Boîte fermée');
  await f.dire('Sur un appareil où la boîte n’est pas ouverte : « <b>Ouvrez la boîte mail sur cet appareil</b> ».', 4800);
  await f.dire('Les envois se règlent boîte ouverte, avec son mot de passe : personne d’autre ne change ce qui part aux clients.', 6000);
  await f.cacher(150); await f.taire(150);
  await P.ouvrirFenetre(f);
  await P.cadreTout(f, '#db-par .par-et', 'Boîte fermée ici');
  await f.dire('La fenêtre des Paramètres le dit aussi : « <b>à ouvrir ici</b> », « boîte fermée ici ».', 4400);
  await f.cacher(150); await f.taire(150);
  await P.cliquerFixe(f, '#db-par [data-pari="rappel"]', 900);
  await P.cadreFixe(f, '#db-par .par-avis', 'Ouvrir la boîte');
  await f.dire('Le rappel ne se règle pas non plus : « <b>Ouvrir la boîte ›</b> ».', 3800);
  await f.cacher(150); await f.taire(150);
  await P.cliquerFixe(f, '#db-par [data-parx]', 800);
  // Hors champ : la boîte du magasin déliée, comme dans un magasin qui ne l'a pas encore reliée ; le module relu.
  await f.p.evaluate(async api => {
    const post = (c, b) => fetch(api + c, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(Object.assign({ shop: '4' }, b)) });
    await post('/dashboard/boite/ouvrir', { motDePasse: 'demo' }); await post('/dashboard/boite/delier', {});
    if (window.DashAuto) { window.DashAuto.relire(); }
  }, P.API);
  await f.pause(900);
  await f.montrer('.au-porte', 'Pas encore reliée');
  await f.dire('Boîte <b>pas encore reliée</b> : les e-mails automatiques en partent ; « reliez-la d’abord », dans la Boîte mail.', 5400);
  await f.cacher(150); await f.taire(150);
  // L'exemple du champ d'adresse de la Boîte mail (une adresse du réseau) est vidé pour le film, comme dans 20-01-porte.
  await f.p.evaluate(() => { const t0 = Date.now(); const vider = () => { const i = document.querySelector('#bx-r-adr'); if (i) { i.placeholder = ''; } if (Date.now() - t0 < 6000) { requestAnimationFrame(vider); } }; vider(); });
  await f.cliquer('.au-porte [data-au="boite"]', { apres: 1800 });
  await f.montrer('.bx-porte-c', 'Boîte mail');
  await f.dire('« <b>Aller à la boîte mail</b> » : la Boîte mail, à relier ou à ouvrir. Puis on revient régler les envois.', 5400);
  await f.cacher(300); await f.taire(200);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
