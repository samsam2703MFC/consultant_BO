/* Le film des bureaux (26/11/2026, Sam : « faire un petit film spécialisé (qui est disponible dans un coin dans bureau),
 * de façon très mimi et dynamique ») : le parcours d'un bureau en un peu plus d'une minute, d'un écran à l'autre —
 *   1. le lien et son QR (Clients › Bureaux, « Le formulaire d'inscription des bureaux ») ;
 *   2. le client s'inscrit (le formulaire public du magasin, …/bureau/?m=4, six étapes, son bon à la fin) ;
 *   3. vous validez (la demande « À valider », « Créer le bureau dans TF Buddy ») ;
 *   4. la tournée (Commandes › « Gérer les tournées », le bureau ajouté avec sa consigne) ;
 *   5. les commandes s'y rangent (la vue Tournées, puis « Demain » : qui n'a pas encore commandé).
 * Une vidéo de page, genre « nouveau » (vues clients et commandes) ; le petit bouton « Le film » de la carte « Bureaux en
 * développement » l'ouvre (clients.js, DashTutos.ouvrir). Des cartes d'étape courtes et animées entre les écrans ; la
 * page change sous la carte (f.changerDePage : le chargement ne dure pas dans le film).
 * Données fictives : mocks/26_clients.js (le formulaire public, les demandes), mocks/25_commandes.js (les tournées) ; la
 * société filmée, « Imprimerie du Val », est un client pro inventé du Magasin démo. Le compte TF Buddy du magasin est
 * connecté avant de filmer (sa connexion n'est pas filmée, décision du 26/11/2026). */
const { Film } = require('../film');
const { ecrire, choisir, heure, connecterTfb, recharger } = require('./commandes');

/* ── Les cartes d'étape : le papier, un grand numéro qui saute, le titre qui monte, qui et combien de temps ── */
const CROISSANT = '<svg class="fm-cr" viewBox="0 0 120 70" aria-hidden="true"><path d="M8 54 C 20 8, 100 8, 112 54 C 98 41, 84 37, 60 37 C 36 37, 22 41, 8 54 Z" fill="#E9AE6E" stroke="#7A3E12" stroke-width="3" stroke-linejoin="round"/>'
  + '<path d="M36 21 C 41 29 41 34 39 38 M60 15 L60 37 M84 21 C 79 29 79 34 81 38" stroke="#7A3E12" stroke-width="3" fill="none" stroke-linecap="round"/></svg>';
const CAMION = '<svg class="fm-van" viewBox="0 0 150 80" aria-hidden="true"><rect x="4" y="16" width="92" height="46" rx="6" fill="#8D1D2C"/><path d="M96 30 h28 l18 18 v14 h-46 z" fill="#8D1D2C"/>'
  + '<path d="M102 34 h19 l12 13 h-31 z" fill="#FFF8F1"/><circle cx="32" cy="64" r="10" fill="#1c1a17"/><circle cx="32" cy="64" r="4" fill="#F2C9A0"/><circle cx="118" cy="64" r="10" fill="#1c1a17"/>'
  + '<circle cx="118" cy="64" r="4" fill="#F2C9A0"/><text x="50" y="45" text-anchor="middle" font-size="15" fill="#F2C9A0" font-family="Gotham,system-ui,sans-serif">L’Atelier</text></svg>';
const STYLE = `<style>
.fm{position:absolute;inset:0;background:#faf7f2;color:#1c1a17;display:flex;align-items:center;justify-content:center;gap:64px;font-family:'Gotham',system-ui,sans-serif;overflow:hidden}
.fm .fm-n{flex:none;width:200px;height:200px;border-radius:50%;background:#8D1D2C;color:#fff;font-size:104px;display:flex;align-items:center;justify-content:center;animation:fm-pop .75s cubic-bezier(.2,1.7,.45,1) both}
.fm .fm-t{max-width:760px;animation:fm-up .6s .12s cubic-bezier(.2,.8,.2,1) both}
.fm .fm-k{font-size:15px;letter-spacing:.2em;text-transform:uppercase;color:#8D1D2C;margin:0 0 14px}
.fm h1{font-size:66px;line-height:1.04;letter-spacing:-.01em;margin:0 0 20px;font-weight:400}
.fm .fm-q{display:inline-block;font-family:'Playwrite DEVA','Gotham',cursive;font-size:22px;line-height:1.5;color:#7A3E12;background:#FFF8F1;border:1px solid #F2C9A0;border-radius:8px;padding:6px 18px}
.fm .fm-pts{position:absolute;left:0;right:0;bottom:70px;display:flex;justify-content:center;gap:14px}
.fm .fm-pts i{width:14px;height:14px;border-radius:50%;background:#EAE4DC}
.fm .fm-pts i.fait{background:#F2C9A0}
.fm .fm-pts i.on{background:#8D1D2C;animation:fm-pop .5s .2s cubic-bezier(.2,1.7,.45,1) both}
.fm .fm-cr{position:absolute;width:120px;right:150px;top:110px;animation:fm-hop 1.1s .25s ease-in-out both}
.fm .fm-van{position:absolute;width:210px;bottom:120px;left:-240px;animation:fm-route 1.9s .1s cubic-bezier(.45,0,.3,1) both}
.fm .fm-sol{position:absolute;left:0;right:0;bottom:116px;border-top:2px dashed #E6E0D8}
@keyframes fm-pop{0%{transform:scale(.2) rotate(-14deg);opacity:0}100%{transform:none;opacity:1}}
@keyframes fm-up{0%{transform:translateY(28px);opacity:0}100%{transform:none;opacity:1}}
@keyframes fm-hop{0%{transform:translateY(40px) rotate(-20deg);opacity:0}45%{transform:translateY(-14px) rotate(8deg);opacity:1}70%{transform:translateY(0) rotate(-4deg)}100%{transform:none;opacity:1}}
@keyframes fm-route{0%{transform:translateX(0)}100%{transform:translateX(calc(100vw + 280px))}}
</style>`;
const ETAPES = 5;
function carte(n, titre, qui, { van } = {}) {
  const pts = Array.from({ length: ETAPES }, (_, i) => `<i class="${i + 1 < n ? 'fait' : i + 1 === n ? 'on' : ''}"></i>`).join('');
  return STYLE + `<div class="fm">${van ? '<div class="fm-sol"></div>' + CAMION : CROISSANT}<div class="fm-n">${n}</div><div class="fm-t"><p class="fm-k">Étape ${n} sur ${ETAPES}</p><h1>${titre}</h1><span class="fm-q">${qui}</span></div><div class="fm-pts">${pts}</div></div>`;
}
/** Une carte d'étape, gardée ms ; `page` : la page qui se charge sous elle, puis la carte s'efface. */
async function etape(f, html, ms, page) {
  await f.p.evaluate(h => { window.__film.st(''); window.__film.etape(''); window.__film.cadre(null); window.__film.curseur(false); window.__film.carton(h); }, html);
  await f.pause(ms);
  if (page) { await f.changerDePage(page.chemin, Object.assign({ carton: html }, page)); }
  await f.p.evaluate(() => { window.__film.carton(''); window.__film.curseur(true); });
  await f.pause(520);
}
/** Le sous-titre tout de suite, sans attendre : le geste se fait pendant qu'on le lit. */
const montrerSt = (f, html) => f.p.evaluate(h => window.__film.st(h), html);

module.exports = async function () {
  const f = await Film.ouvrir('26-film-bureaux', {
    vue: 'clients', serie: 'Le film des bureaux', duree: '1 min 40', vitesse: 0.5,
    sources: ['panel/public/bureau/bureau.js', 'panel/public/dashboard/clients.js', 'panel/public/dashboard/commandes.js', 'panel/src/bureaux_dev.php', 'panel/src/commandes_tournees.php'],
    avant: async p => { await connecterTfb(p); await p.evaluate(() => localStorage.setItem('pcl-onglet:4', 'bur')); await recharger(p); },
  });

  await f.couverture({ kicker: 'Nouveau · le film', titre: 'Un bureau,<br>du QR à la tournée', accroche: 'Cinq gestes, et le petit-déjeuner part au bureau.',
    programme: ['Le lien et son QR', 'Le client s’inscrit', 'Vous validez', 'La tournée', 'Les commandes s’y rangent'], ms: 3600, garder: true });

  /* 1. Le lien et son QR */
  await etape(f, carte(1, 'Le lien et son QR', 'vous · dix secondes'), 1400);
  await f.zoom('.pcl-lien', 1.28);
  await f.montrer('.pcl-lien', 'Votre lien');
  await f.dire('Dans <b>Clients › Bureaux</b> : le lien de votre magasin et son <b>QR</b>.', 2300);
  await montrerSt(f, 'Copiez-le, ou téléchargez le QR : affiche, carte de visite, e-mail.');
  await f.cliquer('.pcl-lien [data-act="dev-copier"]', { apres: 1600 });
  await f.cacher(80); await f.taire(80);
  await f.dezoom();

  /* 2. Le client s'inscrit : le formulaire public du magasin */
  // Le formulaire un peu réduit (le corps de la page, pas le calque) : chaque étape tient dans l'écran, sans défiler.
  await etape(f, carte(2, 'Le client s’inscrit', 'votre client · trois minutes'), 1300, { chemin: '/bureau/', query: { m: '4' }, chargement: 1800,
    avant: async p => { await p.evaluate(() => { document.body.style.zoom = '0.78'; }); } });
  await montrerSt(f, 'Six petites étapes, sur son téléphone ou son ordinateur.');
  await ecrire(f, '[data-k="prenom"]', 'Camille', { delai: 30, apres: 80 });
  await ecrire(f, '[data-k="nom"]', 'Lambert', { delai: 30, apres: 80 });
  await ecrire(f, '[data-k="tel"]', '+32 400 55 12 34', { delai: 22, apres: 120 });
  await f.cliquer('[data-act="suite"]', { apres: 350 });
  await ecrire(f, '[data-k="email"]', 'camille.lambert@exemple.be', { delai: 18, apres: 150 });
  await f.dire('<b>Pas de mot de passe</b> à inventer : le webshop lui écrit pour le choisir.', 2300);
  await f.cliquer('[data-act="suite"]', { apres: 350 });
  await ecrire(f, '[data-k="tva"]', 'BE0000123456', { delai: 28, apres: 100 });
  await f.cliquer('[data-act="vies"]', { apres: 600 });
  await f.montrer('.bf-carte .vies', 'TVA vérifiée');
  await f.dire('Le numéro de <b>TVA</b> suffit : la société se remplit toute seule.', 2300);
  await f.cacher(80);
  await f.cliquer('[data-act="suite"]', { apres: 350 });
  await montrerSt(f, 'Où livrer, quels jours, à quelle heure.');
  await f.cliquer('[data-act="meme-adresse"]', { apres: 250 });
  for (const j of [1, 2, 3, 4, 5]) { await f.cliquer('[data-jour="' + j + '"]', { apres: 90 }); }
  await heure(f, '[data-k="de"]', '07:30', 150);
  await heure(f, '[data-k="a"]', '09:00', 150);
  await f.cliquer('[data-act="suite"]', { apres: 350 });
  await montrerSt(f, 'Son équipe ? Facultatif. Puis son accord, et « Envoyer ».');
  await f.cliquer('[data-act="suite"]', { apres: 400 });
  await f.cliquer('[data-c="accord"]', { apres: 200 });
  await f.cliquer('[data-act="envoyer"]', { apres: 1200 });
  await f.montrer('.merci .bon', 'Son bon');
  await f.dire('Envoyé ! Son compte est créé, et son <b>bon de bienvenue</b> l’attend déjà.', 2600);
  await f.cacher(100); await f.taire(100);

  /* 3. Vous validez */
  await etape(f, carte(3, 'Vous validez', 'vous · une minute'), 1300, { chemin: '/dashboard/', query: { vue: 'clients' },
    avant: async p => { await p.evaluate(() => { localStorage.setItem('pcl-onglet:4', 'bur'); window.scrollTo(0, 0); }); } });
  await f.montrer('.pcl-dev .pcl-ligne[data-id="14"]', 'À valider');
  await f.dire('Sa demande arrive ici, <b>à valider</b>.', 2100);
  await f.cacher(100);
  await f.cliquer('.pcl-dev .pcl-ligne[data-id="14"]', { apres: 900 });
  await montrerSt(f, 'Tout y est : la personne, la société, la livraison, son bon.');
  await f.pause(1100);
  await f.defilerVers(f.p.locator('.pcl-tir .pcl-dev-valider'));
  await choisir(f, '#pcl-dv-route', '31', 400);
  await f.montrer('.pcl-tir .pcl-dev-valider', 'Valider');
  await f.dire('Sa <b>tournée</b>, et « Actif ». Le compte TF Buddy du magasin est connecté.', 2700);
  await f.cacher(100);
  await f.cliquer('[data-act="dev-valider"]', { apres: 1300 });
  await f.dire('Un clic : le bureau est créé dans <b>TF Buddy</b>. Appelez-le pour la première livraison !', 2800);
  await f.taire(100);

  /* 4. La tournée */
  await etape(f, carte(4, 'La tournée', 'vous · une fois', { van: true }), 1800, { chemin: '/dashboard/', query: { vue: 'commandes' },
    avant: async p => { await p.evaluate(() => { localStorage.setItem('pco-vue:4', 'liste'); localStorage.setItem('pco-per:4', 'jour'); }); await p.reload(); await p.waitForTimeout(2200); await p.evaluate(() => window.__film.prets()); } });
  await f.cliquer('[data-act="gerer"]', { apres: 600 });
  await f.cliquer('.pco-gerer [data-act="t-modifier"][data-id="1"]', { apres: 700 });
  await montrerSt(f, 'Dans <b>Commandes › Gérer les tournées</b> : ajoutez le bureau à sa tournée.');
  await ecrire(f, '#pco-bq', 'Imprimerie', { delai: 40, apres: 600 });
  await f.cliquer('[data-act="b-ajouter"][data-cli="7221"]', { apres: 400 });
  await ecrire(f, '[data-bc="3"]', 'Accueil, 1er étage', { delai: 30, apres: 200 });
  await f.montrer('.pco-fen .confirm__box', 'Dans l’ordre de passage');
  await f.dire('À sa place dans l’ordre de passage, avec sa <b>consigne</b>.', 2400);
  await f.cacher(100);
  await f.cliquer('[data-act="t-ok"]', { apres: 900 });
  await f.taire(100);

  /* 5. Les commandes s'y rangent */
  await etape(f, carte(5, 'Les commandes s’y rangent', 'tout seul · chaque jour'), 1300);
  await f.cliquer('[data-act="vue"][data-v="tournees"]', { apres: 1000 });
  await f.montrer('#pco-col-1', 'Matin · Val-Fleuri');
  await f.dire('Le bureau est sur sa tournée : chacune de ses commandes s’y range, <b>à son arrêt</b>.', 2800);
  await f.cacher(100);
  await f.cliquer('[data-act="vue"][data-v="liste"]', { apres: 900 });
  await f.montrer('.pco-demain', 'Demain');
  await f.dire('Et chaque jour, avant <b>16:00</b> : qui n’a pas encore commandé pour demain.', 2800);
  await f.cacher(100);

  return f.fin({ titre: 'À vous de jouer', accroche: 'Un QR, un clic, une tournée.',
    retenir: ['Partagez le lien ou le QR', 'Validez le jour même, puis appelez', 'Mettez le bureau dans sa tournée', 'Avant 16:00 : qui n’a pas commandé ?'], ms: 5000 });
};
