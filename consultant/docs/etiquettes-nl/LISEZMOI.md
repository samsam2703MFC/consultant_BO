# Étiquettes boutique en néerlandais : corrections à envoyer à TF Buddy (21/11/2026)

Les 395 produits finis renommés par la migration 115 de l'ERP ont reçu un alias français simplifié, un nom néerlandais
de Flandre (relevé sur des sites belges néerlandophones) et une proposition d'allergènes. Le tableau complet, avec les
sources et la colonne « À vérifier », est dans `etiquettes_nl_allergenes.xlsx` (394 lignes : les deux soupes aux
courgettes n'en font plus qu'une).

## Ce que le script envoie

`consultant/bin/envoi_etiquettes_nl.php` lit `plan_envoi.json` et écrit dans l'API TF Buddy avec le compte admin du
cockpit (Paramètres › ERP, `ErpApi`). Périmètre validé le 21/11/2026 :

- **81 corrections d'allergènes sur 75 ingrédients** des fiches techniques (`GET /products/technical-sheets`), chacune
  évidente d'après le nom de l'ingrédient : « Oeufs » sans œufs, « Mozzarella base » sans lait, « Saumon fumé tranché »
  sans poisson, « Cookie Caramel & Noix de Pecan » avec un poisson à retirer… Elles s'écrivent sur les matières
  premières des fournisseurs : `POST` / `DELETE /material-suppliers/{fournisseur}/raw-materials/{id}/allergens`.
  Les allergènes proposés d'après les produits similaires en ligne ne sont **pas** envoyés : ils restent une
  proposition à valider avec les fiches techniques.
- **100 traductions FR / NL** des produits sans aucun point à vérifier : `PATCH /products/{id}/aliases` pour les 11
  produits du panel retrouvés sans ambiguïté par les fiches techniques, et `PATCH /materials/{id}/aliases` pour la
  matière qui porte exactement le nom du produit de l'ERP. Les 294 autres attendent une relecture.
- **Pas la soupe aux courgettes** : la fusion (1002223 garde ; 2580062 à désactiver ; recette eau, courgettes, sel,
  poivre ; aucun des 14 allergènes) se fait dans l'ERP de production. Le script la rappelle seulement.

## Lancer (sur le serveur, là où le cockpit est installé)

```bash
cd /var/www/consulant_bo
php consultant/bin/envoi_etiquettes_nl.php --rapport=/tmp/etiquettes-essai.json
```

C'est un **essai à blanc** : il lit tout et dit ce qu'il changerait, sans rien écrire. Une fois la sortie relue :

```bash
php consultant/bin/envoi_etiquettes_nl.php --appliquer --rapport=/tmp/etiquettes-envoi.json
```

Options : `--seulement=allergenes` ou `--seulement=traductions` ; `--sans-matieres` (traductions des produits du panel
seulement) ; `--plan=…` pour un autre plan.

Garde-fous : un nom introuvable ou présent plusieurs fois n'est jamais deviné (signalé, sauté ; un retrait d'allergène
sur un nom présent chez plusieurs fournisseurs est sauté) ; un allergène déjà en place n'est pas renvoyé ; chaque
écriture est relue ; si les 14 allergènes de l'API ne sont pas reconnus avec certitude, rien n'est envoyé (sortie 2).
Relancer le script ne réécrit rien de ce qui est déjà en place.

Sortie : 0 tout bon ; 1 au moins un refus, un introuvable, un nom ambigu ou une relecture qui ne montre pas
l'écriture (détail dans le rapport) ; 2 compte admin indisponible, plan illisible ou allergènes non reconnus.

Le contrat des réponses de l'API (listes de fournisseurs, de matières, de produits) n'est pas documenté au swagger : le
script lit les clés usuelles (`id`, `name`, `material.name`, `id_allergen`…). Si l'essai à blanc annonce beaucoup
d'introuvables, c'est le premier point à regarder, avant d'appliquer.
