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
  sans poisson, « Cookie Caramel & Noix de Pecan » avec un poisson à retirer… Elles s'écrivent sur les **matières**
  (les ingrédients des fiches) : `PATCH /materials/{id}` `{allergens: [ids]}`, qui remplace la liste — le script relit
  l'actuelle et n'y change que ce que le plan ajoute ou retire. Les allergènes de l'API se reconnaissent à leur `code`
  (`eggs`, `milk`, `nuts`…). Sonde du 21/11 : 68 des 75 ingrédients sont des matières du même nom ; les 7 autres
  (crème Chibouste, crème pâtissière, ganache, crème fraîche sucrée, mayonnaise maison, salade de thon maison, sauce
  yaourt) sont des sous-recettes, à corriger à la main.
  Les allergènes proposés d'après les produits similaires en ligne ne sont **pas** envoyés : ils restent une
  proposition à valider avec les fiches techniques.
- **100 traductions FR / NL** des produits sans aucun point à vérifier : `PATCH /products/{id}/aliases` pour les 11
  produits du panel retrouvés sans ambiguïté par les fiches techniques, et `PATCH /materials/{id}/aliases` pour la
  matière qui porte exactement le nom du produit de l'ERP. Les 294 autres attendent une relecture.
  Les noms ne portent plus « ⌀ 22 cm » ni « ⌀ 26 cm » (demande du 21/11, plan `2026-11-21b`) : « Tarte au riz – ⌀ 22 cm »
  devient « Tarte au riz », « Quiche lorraine – ⌀ 26 cm » devient « Quiche lorraine », en français et en néerlandais. Les
  autres tailles (⌀ 19 cm…) restent, elles distinguent encore des produits. « TartoCake cerises-pistache » (1007209)
  porte maintenant le même nom que 2580064 : signalé dans le tableau, pas tranché.
- **Pas la soupe aux courgettes** : la fusion (1002223 garde ; 2580062 à désactiver ; recette eau, courgettes, sel,
  poivre ; aucun des 14 allergènes) se fait dans l'ERP de production. Le script la rappelle seulement.

## Lancer depuis GitHub (workflow d'envoi)

Actions › « Envoi — étiquettes NL à TF Buddy » (`.github/workflows/envoi-etiquettes-nl.yml`) › Run workflow. Le champ
« confirmer » vaut `essai` par défaut : essai à blanc. Taper **`ECRIRE`** pour écrire dans l'API (`--appliquer`). Le
workflow lance le script déployé sur le serveur du cockpit ; le rapport reste sur le serveur, dans
`/var/lib/consulant_bo/prive/etiquettes-nl/` (`essai_…json` ou `envoi_…json`). Une sortie 1 (introuvables, sous-recettes)
est un constat attendu et le workflow reste vert ; une sortie 2 le fait échouer, rien n'est envoyé.

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
seulement) ; `--plan=…` pour un autre plan ; `--racine=…` quand le script arrive par l'entrée standard (c'est ainsi que
`.github/workflows/sonde-etiquettes-nl.yml` lance l'essai à blanc sur le serveur, sans option d'écriture).

Garde-fous : un nom introuvable ou présent plusieurs fois n'est jamais deviné (signalé, sauté ; un retrait d'allergène
sur un nom porté par plusieurs matières est sauté) ; un allergène déjà en place n'est pas renvoyé ; chaque
écriture est relue ; si les 14 allergènes de l'API ne sont pas reconnus avec certitude, rien n'est envoyé (sortie 2).
Relancer le script ne réécrit rien de ce qui est déjà en place.

Sortie : 0 tout bon ; 1 au moins un refus, un introuvable, un nom ambigu ou une relecture qui ne montre pas
l'écriture (détail dans le rapport) ; 2 compte admin indisponible, plan illisible ou allergènes non reconnus.

Le contrat des réponses de l'API n'est pas documenté au swagger : il a été relevé par la sonde du 21/11
(`consultant/tools/sonde_etiquettes_nl.php`) — `/allergens` est un objet indexé par id, les listes sont à la racine, une
fiche matière porte `allergens`, les traductions se lisent `{fk_id, alias_value}`. `GET /…/aliases` ne rend qu'une
langue par appel (`?lang_code=…`, l'anglais par défaut ; une traduction absente revient avec `alias_value` vide) : le
script relit le français puis le néerlandais (`?lang_code=fr` / `nl&alias_type=default`). Les identifiants de l'ERP ne
sont pas ceux des produits de l'API : les traductions passent par le nom exact (27 matières et 9 produits sur 100).
