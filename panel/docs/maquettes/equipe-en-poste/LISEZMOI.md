# Contrôle des franchisés : l'équipe en poste — maquette

Demande du 05/11/2026 : « Rajouter, via les API planning, le personnel travaillant. Une note rapide de 1 à 5, avec une
note (comme expérience client). Fast check oui / non : en habit de travail ? SBAM ? XP client ? Faire une maquette. »

## Sur la tablette

Une nouvelle étape, **L'équipe en poste**, dans le temps 2 · Intérieur, juste après l'expérience client.

| Élément | Ce qu'il fait |
|---|---|
| Le planning du jour | lu au panel par la route du planning (`/shops/{id}/schedule?date=`, la même que l'Opérationnel du dashboard), avec les postes de chacun (`/shops/{id}/employees`). Si le panel ne répond pas, la table synchronisée sert de repli, et l'écran le dit. Gardé hors ligne |
| En poste / pas en poste | à l'heure de la visite : « en poste », « arrive à 13:00 » ou « partie à 09:30 ». Une personne à deux services n'a qu'une ligne |
| La synthèse | combien sont en poste, la moyenne des notes, et les contrôles réussis : tenue 1 / 2, SBAM 2 / 2, XP client 1 / 2 |
| Chaque personne en poste | la **note rapide** de 1 à 5 (Insuffisant → Très bien), le **contrôle éclair** Oui / Non (en tenue de travail, SBAM, expérience client), une **remarque** |
| Pas vue | quelqu'un en production ou en pause : un toucher, et la personne ne compte plus dans ce qui reste à noter |
| Aussi au planning aujourd'hui | les personnes pas en poste à cette heure, repliées, avec « Noter quand même » |
| L'étape | elle est remplie quand chaque personne en poste est notée ou marquée « pas vue ». Elle **ne bloque pas** par défaut ; le réglage est dans Réglages › Contrôle guidé |

Un second toucher sur un Oui ou un Non l'efface. L'écran est traduit en néerlandais.

**Ce qui part.** La note de l'équipe part avec « Terminer », sur la visite. Pour chaque personne : son nom, ses heures,
vue ou pas, sa note, ses trois contrôles et sa remarque. Elle est **interne** : le bureau la voit dans le rapport à
adapter, sur la carte « L'équipe en poste — interne · ne part pas au franchisé ». Elle n'est ni dans le rapport PDF, ni
sur la page du franchisé, ni lue par lui.

## Captures

| Fichier | Ce que c'est |
|---|---|
| `a-equipe-a-noter.png` | l'étape à l'ouverture, à 10:20 : deux personnes en poste, une qui arrive à 13:00 |
| `b-equipe-notee.png` (et `-page.png`, l'étape entière) | les deux personnes notées |
| `c-portrait-nl.png` | la tablette à la verticale, en néerlandais |
| `d-bureau-carte.png` | la carte interne du rapport, au bureau |

**Comment elles sont faites.**

- **L'application** : celle en ligne, ouverte en lecture seule (tout appel autre que GET est intercepté), avec les
  fichiers de ce dépôt à la place des fichiers servis. Visite n° 6 : Halle, en cours.
- **L'horloge** est fixée au 05/11/2026 à 10:20, heure de Bruxelles.
- **Le planning** : la route `/visites/equipe/{shop}` n'est pas encore en ligne. Sa réponse est construite avec les
  heures et les postes réels du planning de Halle ce jour-là (lus à `/exploitation/jour`).
- **Des prénoms fictifs** (Lina V., Emma D., Tom C.) remplacent les vrais. **Aucune personne réelle n'est notée** ; les
  notes et remarques sont des exemples.
- Pour aller directement à l'étape, les étapes bloquantes sont coupées le temps de la capture.

Régénérer : `node docs/maquettes/equipe-en-poste/generer.js`

## Statut

**Codée et mise en ligne le 05/11/2026.**

- **Tablette** : l'étape `equipe` dans `public/assets/js/visites.js` (`chargerEquipe`, `equipeListe`, `equipeHtml`,
  `equipePersonneHtml`, `equipeCorps`) ; traductions dans `visites-nl.js`.
- **Serveur** (`src/visites_equipe.php`) :
  - la lecture du planning : `GET /visites/equipe/{shop}`, avec un cache de cinq minutes ;
  - le contrôle de ce qu'envoie la tablette : `viPersonnel` ;
  - la colonne `con_visite.personnel` (JSON, ajoutée au premier appel). Cachée au franchisé.
- **Bureau** : la carte interne dans `suivi-tpl.js`, `suivi-vals.js` et `app.css`.
- **Tests** : `php bin/tests_suivi.php`, section « L'équipe en poste ».
