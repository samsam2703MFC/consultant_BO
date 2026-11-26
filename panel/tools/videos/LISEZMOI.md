# Les vidéos d'instruction du dashboard magasin — le tournage

Les vidéos que le carré du rail ouvre, à côté d'Assistant IA (`panel/public/dashboard/tutos.js`, registre
`panel/public/dashboard/videos/videos.json`) se tournent ici, sur le **vrai** dashboard, dans Chromium. Seule l'API est
fausse : `mocks/` rend des données **fictives** (« Magasin démo », prénoms inventés, chiffres générés), l'horloge est
figée au jeudi 19/11/2026 à 15 h 40. Aucune donnée réelle n'entre dans une vidéo.

## Tourner

```bash
node panel/tools/videos/tourner.js
```

```bash
node panel/tools/videos/tourner.js 02-mon-equipe
```

Le script assemble le site public dans un dossier temporaire, le sert sur `127.0.0.1:8099` (`FILM_PORT=8101` pour un autre port : plusieurs tournages en parallèle), lance les scénarios, puis
range `NN-nom.mp4` et son affiche `NN-nom.jpg` dans `panel/public/dashboard/videos/` et écrit la durée dans
`videos.json`. Ensuite : `php panel/bin/tests_videos.php`.

Prérequis : `php`, `ffmpeg` (libx264), Node 18 ou plus, et `playwright` avec son Chromium (`npm i playwright` puis
`npx playwright install chromium`, si la machine ne l'a pas déjà).

## Les pièces

| Fichier | Rôle |
|---|---|
| `film.js` | Le moteur : Chromium à 1440 × 810 (sortie 1920 × 1080), le screencast en JPEG, ffmpeg en H.264 (CRF 28, 25 i/s). Dessine dans la page le curseur, les sous-titres, le cadre de mise en avant, la confirmation (`window.confirm` ne se filme pas) et les diapositives. |
| `mock.js` | La fausse API : chaque appel à `api/cockpit/…` passe par les modules de `mocks/` ; date et fuseau figés. |
| `mocks/` | `00_commun.js` (accès, magasins, réseau…), `10_ops.js` (Opérationnel), `15_chiffres.js` (Semaine, Mois), `20_equipe.js` (Mon équipe : 10 personnes, planning de novembre ; les écritures changent le planning relu), `lib/` (le générateur). |
| `scenarios/` | Une vidéo par fichier `NN-nom.js` ; `equipe.js` : les gestes de Mon équipe, partagés par 02 et 03. |
| `capture.js` | Une capture d'une page avec la fausse API : `node panel/tools/videos/capture.js equipe shots/equipe.png --plein`. Liste les appels non servis. |

## Les diapositives (design system)

`docs/DESIGN_SYSTEM.md` : Gotham 400 (texte) et 700 (accents seuls), aucune pilule, pas d'ombre.

- **Couverture** et **fin** (`couverture`, `fin`) : la couverture de marque — Ruby `#8D1D2C` plein, logo blanc
  (`shared/public/assets/img/newsletter/logo-white.png`), filet Abricot, accroche en Playwrite, le programme ou
  « À retenir » numérotés dans des cercles.
- **Intercalaire** (`chapitre`) : le papier `#faf7f2`, le logo (`shared/public/assets/img/logo.png`), le filet Ruby 2 px
  des documents (version A), le numéro dans un cercle Ruby.
- **Formation** : `objectifs`, `exercice` (« À vous », la barre or dit le temps de la pause), `quiz` (la question, puis
  la bonne réponse en vert et son explication).

## Écrire une vidéo

```js
const { Film } = require('../film');
module.exports = async function () {
  const f = await Film.ouvrir('04-ma-page', { vue: 'tresorerie', titreSerie: 'Ma page', duree: '1 min' });
  await f.couverture({ kicker: 'Mode d’emploi', titre: 'Ma page', programme: ['…'] });
  await f.chapitre({ n: 1, total: 2, titre: '…', texte: '…' });
  await f.montrer('.un-bloc', 'Étiquette');          // cadre Ruby, le reste voilé
  await f.dire('Le sous-titre, avec un <b>mot</b> en Abricot.');
  await f.cliquer('[data-un-bouton]');               // le curseur y va et clique
  return f.fin({ titre: 'À vous', retenir: ['…'] });
};
```

Puis l'entrée dans `videos.json` (`id`, `genre`, `titre`, `resume`, `vues`, `fichier`, `affiche`, `depuis`), les
routes manquantes dans `mocks/` (`capture.js` les liste), et `node tourner.js 04-ma-page`. Pour annoncer une nouvelle
fonction : `genre: "nouveau"` et `depuis` à la date de mise en ligne ; la carte « Nouveau » s'efface après 60 jours ou
une fois la vidéo ouverte.

## Un film qui change de page (26/11/2026)

Le film des bureaux (`scenarios/26-film-bureaux.js`, Sam : « un petit film spécialisé, disponible dans un coin dans
bureau, très mimi et dynamique ») passe du dashboard au formulaire public du magasin (`/bureau/?m=4`), puis à Commandes.
`f.changerDePage(chemin, { query, carton, avant })` charge l'autre page sous une diapositive : la diapositive est reposée
sur la nouvelle page, et les images du chargement sont retirées du montage (le temps qui suit est avancé d'autant).
`f.zoom(cible, k)` et `f.dezoom()` grossissent la page autour d'un geste (le calque garde sa taille) ;
`Film.ouvrir(…, { vitesse: 0.5 })` rend la main plus vive ; `f.couverture({ …, garder: true })` laisse la couverture à
l'écran jusqu'à la diapositive suivante. Les cartes d'étape animées du film sont dans son scénario.

## Une vidéo par onglet, ses fonctions en chapitres (24/11/2026)

Sam : « chaque vidéo dans le rail, ensuite une vidéo par onglet interne, ensuite une vidéo spécifique à chaque
fonction ». Validé le 24/11 : **un fichier par onglet**, ses fonctions en chapitres ; une vidéo de fonction pour ce qui
**agit** (crée, modifie, envoie, range, supprime) — les gestes de lecture (ouvrir, chercher, filtrer, déplier, changer
de période) se montrent dans la vidéo de l'onglet ; ni les gestes impossibles à filmer (nouvel onglet du navigateur,
caméra, micro), ni les écrans propres au téléphone ; les fusions de la liste appliquées.

```js
const { Film } = require('../film');
module.exports = async function () {
  const f = await Film.ouvrir('13-01-en-cours', { vue: 'offres', serie: 'Offres', parent: 'offres',
    sources: ['panel/public/dashboard/offres.js'], avant: async p => { /* l'état de départ, hors film */ } });
  await f.onglet({ id: 'offres-en-cours', titre: 'Les offres en cours', resume: '…', accroche: '…',
    programme: ['Modifier une offre', '…'] });            // couverture courte : le chapitre de l'onglet
  // … l'onglet, ses gestes de lecture …
  await f.fonction({ id: 'offres-modifier', titre: 'Modifier une offre', resume: '…',
    points: ['Choisir l’offre', '« Modifier »', '…'] });  // intercalaire « Pas à pas » : le chapitre de la fonction
  // … le geste, de bout en bout : il peut se jouer seul (on resélectionne ce qu'il faut) …
  return f.fin();                                          // pas de diapositive finale
};
```

- **Nom du fichier** : `NN-MM-court` — `NN` le numéro de la vidéo de la page (13 pour Offres), `MM` le rang de
  l'onglet. **Identifiants** : préfixe de la page (`offres-`, `boite-`, `equipe-`…), en minuscules et tirets, uniques.
- `tourner.js` lit `sortie/<nom>.chapitres.json` (écrit par `fin()`) et crée ou met à jour **une entrée du registre par
  chapitre** : `niveau` (`onglet`, `fonction`), `parent` (la page pour un onglet, l'onglet pour une fonction), `debut`
  et `fin` (secondes dans le MP4), `duree`, l'affiche `<id>.jpg` (l'écran, juste après l'intercalaire), `sources`,
  `tourne_sur`, `tourne_le`. Un chapitre retiré du scénario sort du registre au tournage suivant.
- Ces fichiers sortent en **1600 × 900, CRF 34** (≈ 1,3 Mo la minute ; le texte reste net), une image clé au début de
  chaque chapitre : le lecteur y saute (`#t=debut,fin`) et s'arrête à la fin du passage. Il faut un serveur qui
  accepte les requêtes partielles (Apache en production ; `php -S` ne le fait pas : le saut ne marche pas en local).
- Le lecteur du dashboard (`tutos.js`) et la médiathèque (`#/videos/<page>`) montrent la page onglet par onglet.

## La médiathèque (26/11/2026)

Sam : « une vraie médiathèque de vidéos, avec une barre de recherche intelligente, par section, catégorie, onglet et
titre ». Le catalogue de formation (`formation/public/formation/catalogue/`, `#/videos`) range tout le registre : la
**section** du rail (`VSECTIONS` : En haut du rail, La journée, Les chiffres, Vendre, Marketing, Se former, En bas du
rail), la **catégorie** (la page : la première `vues` de la vidéo de page, `VALIAS` pour `jour`, `trimestre`, `frais`),
l'**onglet**, le **titre**. La recherche ignore accents et majuscules, connaît des synonymes (`VSYN` : « mail »,
« horaire », « TVA »…), accepte un début de mot et une faute de frappe, et classe titre, onglet et page, section,
résumé ; « / » y va, Entrée ouvre le premier résultat. Une nouvelle page du rail : l'ajouter à `VSECTIONS`
(`php panel/bin/tests_videos.php` le vérifie).

Ces vidéos forment la collection « Utilisation du dashboard » ; les autres collections (Production, Vente et comptoir,
Hygiène et sécurité, Ouverture et fermeture) sont des vidéos filmées, ajoutées dans le portail de formation (rail ›
Médiathèque, `formation/src/mediatheque.php`). Le lecteur du dashboard porte « Voir dans la médiathèque ↗ »
(`#/videos/v/<id>`).

## La routine : adapter et faire les nouvelles vidéos

```bash
node panel/tools/videos/a_refaire.js
```

dit, sans rien modifier :

1. **À adapter** : pour chaque vidéo, les commits qui ont touché ses `sources` (les fichiers dont l'écran est filmé,
   dans `videos.json`) depuis son tournage (`tourne_sur`, écrit par `tourner.js`). On relit : l'écran filmé a-t-il
   changé (libellés, mise en page, gestes) ? Le scénario tourne-t-il encore (un sélecteur disparu le fait échouer) ? Si
   oui : adapter le scénario et `mocks/`, retourner, relire la planche de contrôle.
2. **Nouveautés** : les mises en ligne (`shared/deploy.trigger`) postérieures au dernier tournage qui parlent du
   dashboard franchisé : chacune peut demander une vidéo « nouveau » (courte, la bulle de sa page) ou un module de
   formation (`genre: "formation"`, objectifs, chapitres, « À vous », quiz, récapitulatif).
3. **Sans vidéo** : les pages du dashboard qu'aucune vidéo ne couvre — les prochains modules de formation.

Une tâche planifiée (la « routine des vidéos ») lance cette revue chaque semaine dans une nouvelle session : elle
adapte ce qui doit l'être, ajoute au plus deux nouvelles vidéos, vérifie les planches (une image toutes les 4 s),
passe `php panel/bin/tests_videos.php` et ouvre une PR vers la branche par défaut. La mise en ligne reste une
décision : la PR fusionnée, la branche de déploiement avance comme pour toute livraison (`shared/CLAUDE.md`).

Planche de contrôle d'une vidéo (à relire avant toute PR) :

```bash
ffmpeg -i panel/public/dashboard/videos/02-mon-equipe.mp4 -vf "fps=1/4,scale=640:-1,tile=3x4" planche%d.png
```
