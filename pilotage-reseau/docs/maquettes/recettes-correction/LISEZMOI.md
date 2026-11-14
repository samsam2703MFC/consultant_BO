# Vérification › Recettes à corriger : corriger d'ici, ou ouvrir au bon endroit (maquette)

**Statut : codé le 13/11/2026** (décision : « tout, CEO seul »). Captures de l'écran codé : `code-liste.png`,
`code-ligne.png`, `code-apres.png`, `code-volet.png`, `code-registre.png`. Les maquettes `a`, `b`, `c` restent pour mémoire.

## Ce qui est codé

- **Serveur** : `src/verification_correction.php` (préfixe `vrc`), routes dans `public/api/index.php`, contrat dans
  `docs/contrat-api.md` (« corriger d'ici »). Les règles (`src/verification.php`) portent sur chaque ligne ce qu'il faut
  pour proposer le geste (`corr`).
- **Écran** : `verification-correction-vals.js` et `verification-correction-tpl.js`, branchés dans `verification-vals.js`
  et `verification-tpl.js` ; style `rc-` dans `verification.css`.
- **Tests** : `php bin/tests_verification_correction.php` — un faux panel qui recalcule les coûts et applique les
  écritures ; chaque geste de bout en bout, l'annulation, le refus (422) et le « sans effet ».
- **Captures** : `capturer-code.js` ouvre le vrai cockpit avec l'API simulée par les réponses du test
  (`VRC_FIXTURES=dossier php bin/tests_verification_correction.php`, puis `node … capturer-code.js dossier`).

Écarts à la maquette : une correction ne part qu'après « Vérifier ce qui sera écrit » (le corps exact s'affiche) puis
« Appliquer au panel » ; une ligne lourde dans une **sous-recette** (qui peut servir à d'autres produits) passe par le
volet, jamais par un clic ; les corrections de la visite s'affichent en tête de la liste (« Corrigé depuis cette page »),
le registre complet se déplie sous la liste et règle les adresses du panel.

## La maquette (pour mémoire)

Demande du 13/11/2026, sur `#/verification/recettes` : « dans les problèmes de recette, qu'on puisse en direct,
si possible, corriger, ou un lien direct ».

## Aujourd'hui (en ligne)

- La liste et le volet de la recette sont **en lecture seule** : « Lecture seule : rien n'est écrit au panel »
  (décision du 31/10, `docs/plan-verification.md` § 3.5).
- **Un seul lien** par ligne, « Ouvrir au panel ↗ », vers la fiche du produit au panel
  (`vfLienPanelModele()`, réglage `verifLienRecette`, par défaut `…/panel/products/{pid}`). Pour une matière
  sans prix ou une sous-recette fausse, il faut ensuite chercher la bonne page soi-même.
- Le volet annonce déjà : « l'écriture depuis le cockpit viendra une fois son format mesuré »
  (`ep_verification_sonde` lit la forme de `GET /recipes/{id}` pour préparer `PATCH /recipes/{id}`).

## Les écrans

| Fichier | Écran | Ce qu'il montre |
|---|---|---|
| `a.png` | **La liste, un geste par ligne** | Sous « ce qui cloche », **ce que le cockpit propose** (en vert). Dernière colonne : le bouton qui corrige (Rattacher la 418, Recalculer à Halle, Saisir les prix, Appliquer ÷1000…), et dessous les **liens directs ↗** vers l'écran exact du panel (la recette, la sous-recette, la matière, la portion). Les cas simples se corrigent **dans la ligne** (rendement, fraction d'une portion, prix d'une matière). La ligne 4 est ouverte : l'avant et l'après, ligne par ligne et magasin par magasin, puis « Appliquer au panel ». Les lignes 7 et 13 sont corrigées : ✓, l'heure, l'avant et l'après, « Annuler ». Ce qui ne se fait pas d'ici garde un seul bouton, « Créer au panel ↗ ». En tête, la couverture si toutes les propositions étaient appliquées (hachuré), et un filtre *Toutes · Se corrigent ici · Au panel* |
| `b.png` | **Le volet de la recette, en correction** | Le volet d'aujourd'hui avec une bascule *Lire / Corriger* : quantités et unités modifiables, une ligne qui se retire (×), une matière qui s'ajoute, le rendement. La ligne fautive est en évidence avec sa raison. Le coût de chaque magasin avant / après, le coefficient, la part de la matière. Une barre en bas : le résumé des changements, « Annuler les changements », « Enregistrer au panel ». En tête, les liens directs : recette, sous-recette, produit, prix des matières du magasin |
| `c.png` | **Règle par règle, et le registre** | Pour chacune des dix règles : où elle se corrige (ici / au panel / ici sans écrire au panel), le geste, la route du panel qui l'écrit, les liens directs. Puis le **registre des corrections** : chaque écriture faite depuis cette page, avec l'avant, l'après, la route, le résultat (relue plausible, ou refusée par le panel) et « Annuler » |

## Ce qui se corrige d'ici, et par où

Les routes d'écriture sont **dans le swagger public du panel** (relevé le 13/11/2026,
`/api/swagger/openapi.json`). Leur **format n'est pas mesuré** : c'est la première chose à faire, sur une recette
de test, avant d'écrire une ligne de code.

| Règle | Où | Route d'écriture (swagger) |
|---|---|---|
| Pas de recette | ici, si une recette libre du même nom existe ; sinon au panel | `PATCH /products/{id}` (`id_recipe`) |
| Formule sans ses produits | au panel | — (le compte consultant reçoit 403 sur `/admin/promotions`, mesuré le 10/10) |
| Recette vide | ici, dans le volet (copier une recette proche, ajuster) | `PATCH /recipes/{id}` |
| Matière sans prix | ici, dans la ligne | `POST /shops/{id}/suppliers/{f}/materials/price` (ou `POST /materials/{id}/manual-price`) |
| Coût invraisemblable | ici, dans le volet | `PATCH /recipes/{id}`, `PATCH /subrecipes/{id}` |
| Hors norme du réseau | ici, un clic | `PATCH /shops/{id}/products/{pid}`, `POST /shops/{id}/recipes/recalculate` |
| Unité suspecte | ici, un clic | `PATCH /recipes/{id}` |
| Rendement absent | ici, dans la ligne | `PATCH /recipes/{id}` (`yield_quantity`) |
| Portion sans fraction | ici, dans la ligne | `PATCH /product-portions/{id}` |
| Matière jamais en recette | ici, sans écrire au panel (« hors recette ») | — |

## Les règles que la maquette pose

- **Rien ne part sans « Appliquer »** : chaque geste montre d'abord l'avant et l'après (la ligne, le coût de la
  pièce dans chaque magasin, le coefficient).
- **Écrit avec le compte consultant**, comme les tâches (`/consultant/tasks/…/mark-as-done`) et les réclamations
  (`/material-complaints`), que le cockpit écrit déjà.
- **Relu aussitôt** : après l'écriture, le cockpit relit la recette et rejuge la ligne. Elle sort de la liste si
  elle est devenue plausible.
- **Un registre** en base (`ceo_verif_correction`, `CREATE TABLE IF NOT EXISTS`) : l'heure, le produit, la route,
  l'avant, l'après, le résultat. **« Annuler »** réécrit l'avant par la même route.
- **Les gestes à surveiller** (§ 3.6 du plan : prix saisi à la main, recettes recalculées…) reconnaissent ces
  écritures par le registre : une correction du cockpit n'est pas signalée comme suspecte.
- **Les liens directs** : un modèle d'adresse par objet (produit, recette, sous-recette, matière, portion, prix
  d'un magasin) au lieu du seul `verifLienRecette`, réglés dans Paramètres. Les adresses des écrans du panel ne
  sont pas dans le swagger : elles sont à relever une fois.

## À décider

1. **Écrire au panel depuis cette page** : la décision du 31/10 disait « on n'écrit rien au panel ». La demande
   du 13/11 la change. À confirmer : tout (a + b), seulement les gestes simples dans la ligne (rendement,
   fraction, prix, unité), ou seulement les liens directs.
2. **Qui** : le CEO seul (comme la liste), ou aussi le consultant.
3. **Le volet en correction (b)** fait du cockpit un petit éditeur de recettes. S'il est de trop, on garde les
   gestes en ligne (a) et le volet reste en lecture, avec ses liens directs.

## Données

**Ce sont des chiffres d'illustration.** Les produits, les problèmes et les montants reprennent la maquette `e`
de [`docs/maquettes/verification`](../verification/LISEZMOI.md). Les recettes (lignes, quantités, prix), les
numéros de recette et les heures sont inventés pour montrer le geste. Les magasins et les produits sont ceux du
réseau. Aucun nom de personne.

## Régénérer

```
php -S 127.0.0.1:8099 -t .        # depuis pilotage-reseau/
node docs/maquettes/recettes-correction/generer.js
node docs/maquettes/recettes-correction/capturer.js
```

`chrome.html` est l'écran du cockpit rendu tel quel (repris de `evolution-magasin`). `generer.js` y ajoute la
section Vérification du rail telle que `app.js` la construit et remplace le contenu. Les styles sont ceux de la
page en ligne (`verification.css`, préfixe `vr-`) ; `rc.css` n'ajoute que les classes `rc-*`.
