# Les vidéos d'instruction du dashboard magasin — le tournage

Les vidéos que la bulle du dashboard ouvre (`panel/public/dashboard/tutos.js`, registre
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

Le script assemble le site public dans un dossier temporaire, le sert sur `127.0.0.1:8099`, lance les scénarios, puis
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
