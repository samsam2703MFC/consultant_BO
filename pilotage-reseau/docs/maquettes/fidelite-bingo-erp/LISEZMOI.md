# Fidélité dans l'ERP : les écrans de l'admin habillés avec le handoff Claude Design

Demande du 18/11/2026 (Sam) : « le fichier rar (`loyalty-admin-views`) est fonctionnel dans l'ERP. Il faut donc
garder la structure et les variables, adapter avec ce qui vient de Claude Design (`Bingo_design_system_rebuild_2`),
faire une maquette en screenshot. » Précision dans la foulée : « n'utiliser que la structure disponible, ne pas
créer de table ou autre ».

**Règle suivie.** Aucune table, aucun champ de formulaire et aucune clé dans `points_config` ne sont ajoutés.
Chaque écran reprend son gabarit Twig de l'ERP (`admin/views/loyalty/*.twig`) : mêmes étapes, mêmes panneaux,
mêmes noms de champs, mêmes routes (`/loyalty/cards`, `/create`, `/{id}/edit`, `/simulate`). Le handoff Claude
Design ne fournit que **l'habillage**. Les étiquettes en pointillés sur les captures donnent le nom du champ
existant (`name`, `stamps_required`, `points_config.tiers`…). Les données sont des données de démonstration.

| Écran | Gabarit de l'ERP | Capture |
|---|---|---|
| 1 · Liste des cartes | `overview.twig` | `captures/1-liste.jpg` |
| 2 · Étape 1 · Informations générales | `form.twig` › `data-step="1"` | `captures/2-etape1-informations.jpg` |
| 3 · Étape 2 · Règles et récompense | `form.twig` › `data-step="2"` + `points.twig` | `captures/3-etape2-regles.jpg` |
| 4 · Étape 3 · Boutiques et produits | `form.twig` › `data-step="3"` | `captures/4-etape3-produits.jpg` |
| 5 · Étape 4 · Apparence et récapitulatif | `form.twig` › `data-step="4"` | `captures/5-etape4-apparence.jpg` |
| 6 · Aperçu d'une carte depuis la liste | `overview.twig` › `dialog.ll-card-dialog` | `captures/6-apercu-carte.jpg` |

## Ce qui vient de Claude Design

- **Jetons** : rubis `#8D1D2C`, abricot `#F2C9A0`, beige `#EAE4DC`, beige clair `#F4EFE8`, texte `#222` / `#666`,
  bordures `rgba(34,34,34,.10)` et `.18`. Ce sont déjà ceux de `franchise-ui/tokens.css` de l'ERP.
- **Polices** : Vank pour les titres et les chiffres, Gotham pour l'interface, Playwrite DEVA pour l'accent écrit.
- **Formes** : rayons de 6, 8 et 12 px, cartes blanches bordées sans ombre, étiquettes en majuscules espacées,
  réglages chiffrés sur fond beige avec la valeur en rubis.
- **« Type de carte »** : les deux cases à cocher existantes (`stamps_enabled`, `points_enabled`) présentées
  comme les deux moteurs du handoff (rubis plein / contour rubis).
- **Aperçu de la carte** : la carte imprimée du handoff (grille rubis, tampon rond incliné, cadeau en ticket
  rubis à encoches et cadre pointillé abricot). L'aperçu ne lit que des champs existants :
  - `name` : le premier mot en Vank, la suite en écriture (« BINGO *d'hiver* ») ;
  - `stamps_required` : le nombre de cases, la case du cadeau prend le reste de la dernière ligne ;
  - `reward_label` et `reward_validity_days` : le cadeau ;
  - `starts_at` / `ends_at` : les dates ;
  - `color_tint` / `color_fill` / `color_accent` : le fond, le rubis et l'abricot ;
  - `points_config` : la ligne « Et vos points ».

  Les illustrations des cases sont **décoratives** : des fichiers statiques, comme `pattern.svg` et `stamp.svg`
  aujourd'hui. Elles ne portent aucune donnée.
- **Aperçu « Carte à points »** : l'onglet Points du handoff. La jauge vient du solde du simulateur. Les pastilles
  Lun → Dim viennent de `bonuses` (jours, heures, coefficient). Le tableau des paliers de `loyalty-points.js`
  vient de `tiers`.

## Ce que le handoff propose et que la structure actuelle ne permet pas (non dessiné)

Ces règles demanderaient de nouveaux champs ou de nouvelles tables. Elles sont donc **laissées de côté** :

- une case par **semaine** dès un seuil de dépense (10 €). Dans l'ERP, un tampon = une unité payée d'un
  produit du programme ;
- un bon toutes les `x` cases et un **super bon** à 13 / 13. L'ERP connaît une seule récompense par
  `stamps_required` ;
- la valeur du bon bingo en euros. La récompense de l'ERP, ce sont des produits (`reward_product_ids`). Les
  bons en euros n'existent que par les paliers de points (`tiers`) ;
- le ×2 après la case cochée, les coefficients personnalisés du lundi, le non-cumul « le plus fort
  s'applique ». Le simulateur de l'ERP multiplie coefficient boutique × coefficient bonus ;
- les saisons et les thèmes des 13 semaines (fêtes, produit de la semaine), les noms des bons, les canaux de
  vente comptés.

Le « Bingo d'hiver » de la maquette est donc une carte de 13 tampons du 23/11/2026 au 21/02/2027, avec la carte
à points (1 point par euro, 100 points = 5 €) cochée à côté.

## Valeurs de la carte de démonstration

| Champ | Valeur |
|---|---|
| `name` | Bingo d'hiver |
| `stamps_required` | 13 |
| `reward_label` / `reward_validity_days` | Une pâtisserie offerte / 30 |
| `starts_at` / `ends_at` (UTC) | 2026-11-22 23:00:00 / 2027-02-21 23:00:00, soit du lundi 23/11 au dimanche 21/02, heure belge |
| `points_config` | `points_per_slice` 1, `slice_cents` 100, `voucher_validity_days` 30, `points_validity_days` 365, `tiers` 100 → 500, un bonus mercredi 14:00–17:00 ×2 |
| `color_tint` / `color_fill` / `color_accent` | `#F4EFE8` / `#8D1D2C` / `#F2C9A0` |

## Refaire les captures

```bash
node shot.js            # captures/*.jpg à l'échelle 2 (Playwright et Chromium déjà installés)
```

`index.html` charge `donnees.js` (la carte de démonstration, l'aperçu de la carte et l'aperçu des points) et
`ecrans.js` (les six écrans). Polices et illustrations : celles déjà dans le dépôt
(`public/assets/ds/fonts`, `public/fidelite-test/ill`, `public/assets/img/logo.png`).
