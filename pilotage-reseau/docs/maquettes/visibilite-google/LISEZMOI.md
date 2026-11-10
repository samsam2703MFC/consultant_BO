# Maquettes « Visibilité Google » (08/11/2026)

Une entrée **Visibilité Google** sous *Marque & marketing* (maquettes, puis l'écran en ligne : voir plus bas). Elle montre ce que la **clé Places déjà enregistrée
dans le cockpit** (Paramètres, réglage `google`, lue par `src/google_api.php`) permet de faire pour le
référencement local des magasins, sans nouvelle API ni nouvelle autorisation. Elle fait suite à l'audit SEO
du 08/11/2026 (boulangerie, sandwiches garnis, cougnous, galettes des rois).

| Fichier | Écran | Ce qu'il montre |
|---|---|---|
| `a-fiches-google.html` · `.png` | A — Fiches Google | Les 4 fiches Google comparées donnée par donnée (nom, catégorie, horaires, horaires des fêtes, lien du site, téléphone, note, photos, options, statut) à ce que le site publie, et les écarts à corriger, du plus urgent au moins urgent. |
| `b-rang-local.html` · `.png` | B — Rang local | La place de chaque magasin sur 7 recherches × 4 magasins (boulangerie, sandwiches, cougnous, galettes ; en néerlandais à Halle), l'évolution sur 7 jours, le détail d'une recherche (« cougnou Gosselies ») et ce qui ferait monter le magasin. |

Les onglets « Pages du site » et « Réglages » sont seulement nommés.

## Ce qui est réel, ce qui ne l'est pas

- **Réels** :
  - le rail du cockpit (relevé sur l'application le 08/11/2026, `rail.js`) ;
  - les 4 magasins ;
  - les colonnes « site » de l'écran A : ce sont les données structurées d'atelierby.be relues le 08/11/2026. Corbais ouvert 7 j/7, Gosselies « dimanche seul, 8:00–15:00 », aucun horaire pour Sombreffe et Halle, et les téléphones.
- **Exemples** : toutes les valeurs lues chez Google (nom, catégorie, horaires, note, avis, rang, concurrents). La clé n'a pas été interrogée pour faire ces maquettes. Les concurrents sont anonymisés (« Concurrent A… »).

## Ce que la clé permet, et ce qu'elle ne permet pas

Une clé d'API ne lit que les données publiques de Google.

| Possible avec la clé Places (New) | Impossible avec une clé |
|---|---|
| **Place Details** : horaires habituels et horaires spéciaux, catégorie principale, lien du site, téléphone, note, nombre d'avis, photos, options (à emporter, livraison, petit-déjeuner), statut | Modifier une fiche, publier un post, répondre à un avis : API Business Profile, avec connexion du compte propriétaire et accès validé par Google |
| **Text Search** depuis la position du magasin : la place de la fiche dans les 20 résultats, et les concurrents qui la précèdent | Les requêtes tapées et les positions du site lui-même : Search Console, avec connexion du compte propriétaire |

Le rang mesuré par Text Search est proche du classement de Google Maps, sans être exactement ce que voit un
client : son résultat dépend de l'endroit où il est et de son historique.

Coût : environ 28 recherches et 4 fiches par semaine, soit 130 appels par mois. À vérifier dans la console
de facturation Google Cloud : les champs horaires, lien du site et note sont facturés au niveau supérieur
(Enterprise) de Places.

## L'écran en ligne (09/11/2026)

L'écran est développé : **Marque & marketing › Visibilité Google** (`#/visibilite`).

| Où | Quoi |
|---|---|
| `src/google_api.php` | `ficheVisibilite()`, `rechercheLocale()`, `googleFicheVisibilite()` (fonction pure) |
| `src/visibilite_google.php` | les recherches suivies, la lecture du site, la relecture, les écarts ; `GET /visibilite`, `POST /visibilite/sync {magasinId}` |
| `public/assets/js/visibilite-vals.js`, `visibilite-tpl.js`, `assets/css/visibilite.css` | l'écran, ses deux onglets et le badge du rail |
| `tests/visibilite/run.php` | les fonctions pures, sur les données structurées réelles d'atelierby.be |

Les écarts avec les maquettes :
- les horaires des fêtes ne se vérifient qu'à 7 jours, car Google ne publie pas plus tôt les horaires exceptionnels ;
- la relecture se lance à la main, magasin après magasin, avec le bouton « Relire Google maintenant ». Il n'y a pas de relecture automatique chaque lundi : pas de cron pour l'instant.

Le relevé est gardé dans `ceo_app_setting.visibiliteGoogle`, sans nouvelle table. Il contient le dernier relevé de chaque magasin, plus les rangs du relevé précédent pour calculer l'évolution.

## Regénérer les captures

À la racine du dépôt :

```bash
php -S 127.0.0.1:8099 -t pilotage-reseau
```

Puis, dans un autre terminal :

```bash
node pilotage-reseau/docs/maquettes/visibilite-google/capturer.cjs
```
