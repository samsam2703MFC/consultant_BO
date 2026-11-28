/* Paramètres › la fenêtre des Paramètres (la roue, en bas du rail) (26/11/2026) :
 *   onglet    param-fenetre   tous les réglages rangés (Boîte mail, Automatisations, Alertes SMS, Cet appareil, Assistant IA), l'état
 *                             de chacun, la recherche (Entrée ouvre le premier trouvé), le ton de l'assistant, les
 *                             réglages de la boîte qui ouvrent la Boîte mail ; ×, Échap ou un clic à côté la ferment
 * Pas de fonction à part : ouvrir, fermer, chercher et aller à la Boîte mail sont des gestes de lecture ; le ton se garde
 * sur l'appareil (rien n'est écrit au serveur) ; « Alertes de stock » passe par le service de notifications du
 * navigateur (permission, abonnement), hors d'atteinte du tournage : on le montre sans le basculer. Données fictives :
 * mocks/24_parametres.js, 28_sms_formulaires.js, 70_boite.js, 71_assistant.js. Chaque phrase vient de panel/public/dashboard/dashboard.js
 * (parItems, parListe, parBrancher, parChoisir). */
const { Film } = require('../film');
const P = require('./parametres');

module.exports = async function () {
  const f = await Film.ouvrir('24-01-fenetre', { vue: 'mails', serie: 'Paramètres', parent: 'mails-automatisations', sources: P.SOURCES, avant: P.depart() });

  await f.onglet({
    id: 'param-fenetre', titre: 'La fenêtre des Paramètres',
    resume: 'La roue, en bas du rail : tous les réglages du magasin rangés dans une fenêtre, l’état de chacun, et une recherche.',
    accroche: 'Tous les réglages, rangés',
  });
  await P.cadreFixe(f, '#db-rail [data-par]', 'Paramètres');
  await f.dire('La <b>roue</b>, en bas du rail, à gauche de Mon coffre-fort : les paramètres du magasin.', 4400);
  await f.cacher(150); await f.taire(150);
  await P.ouvrirFenetre(f);
  await P.cadreFixe(f, '#db-par', 'Paramètres');
  await f.dire('Une fenêtre, tous les réglages <b>rangés</b> : Boîte mail, Automatisations, Alertes SMS, Cet appareil, Assistant IA.', 5600);
  await P.cadreTout(f, '#db-par .par-et', 'L’état');
  await f.dire('À droite, l’<b>état</b> de chacun : la boîte reliée, le rappel à 17 h, trois personnes dans l’équipe.', 5000);
  await f.cacher(150); await f.taire(150);
  await P.cliquerFixe(f, '#db-par [data-parq]', 200);
  await f.taper('sms', 140);
  await P.cadreFixe(f, '#db-par', 'Chercher un réglage');
  await f.dire('« <b>Chercher un réglage</b> » : « sms » garde le rappel et les SMS des formulaires bureaux.', 4600);
  await f.cacher(150); await f.taire(150);
  await f.p.keyboard.press('Enter');
  await f.pause(900);
  await P.cadreFixe(f, '#db-par', 'Rappel la veille du retrait');
  await f.dire('<b>Entrée</b> ouvre le premier réglage trouvé : le rappel, qui se règle sur place.', 4400);
  await f.cacher(150); await f.taire(150);
  await P.cliquerFixe(f, '#db-par [data-parret]', 700);
  await P.cliquerFixe(f, '#db-par [data-parq]', 150);
  await f.p.keyboard.press('Control+A'); await f.p.keyboard.press('Backspace');
  await f.pause(500);
  await P.cadreFixe(f, '#db-par [data-pari="ton"]', 'Ton de l’assistant');
  await f.dire('Le <b>ton de l’assistant</b> : qui vous répond quand vous l’ouvrez. Gardé sur cet appareil.', 4800);
  await f.cacher(150);
  await P.choisir(f, '#db-par [data-parton]', 'coach', 1200, true);
  await f.taire(150);
  // Les SMS des formulaires bureaux (28/11/2026) : la ligne, sans l'ouvrir (son détail se règle au magasin).
  await P.cadreFixe(f, '#db-par [data-pari="smsf"]', 'SMS des formulaires bureaux');
  await f.dire('<b>Alertes SMS</b> : un SMS à chaque inscription ou demande hors zone, au GSM du franchisé ; le siège reçoit aussi.', 6000);
  await f.cacher(150); await f.taire(150);
  await P.cadreFixe(f, '#db-par [data-pari="alertes"]', 'Alertes de stock');
  await f.dire('<b>Cet appareil</b> : une notification quand une référence passe sous son minimum. Le navigateur demande la permission.', 6000);
  await P.cadreTout(f, '#db-par [data-pari="boite"], #db-par [data-pari="modeles"]', 'Boîte mail');
  await f.dire('Les réglages de la <b>boîte</b> ouvrent la Boîte mail, au bon endroit.', 3800);
  await f.cacher(150); await f.taire(150);
  await P.cliquerFixe(f, '#db-par [data-parx]', 900);
  await f.dire('×, <b>Échap</b> ou un clic à côté referment la fenêtre.', 3200);
  await f.taire(150);
  await P.ouvrirFenetre(f);
  await P.cliquerFixe(f, '#db-par [data-pari="modeles"]', 2000);
  await P.cadreVu(f, '.bx-plein', 'Modèles d’e-mails');
  await f.dire('« Modèles d’e-mails » : la <b>Boîte mail</b> s’ouvre sur ses modèles. La suite, dans les vidéos de la Boîte mail.', 5600);
  await f.cacher(300); await f.taire(200);

  return f.fin();
};

if (require.main === module) { module.exports().catch(e => { console.error(e); process.exit(1); }); }
