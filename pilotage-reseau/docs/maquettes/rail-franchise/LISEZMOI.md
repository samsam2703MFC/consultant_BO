# Le rail du dashboard franchisé

**Statut : trois maquettes à choisir, rien n'est codé.**

Demande du 15/11/2026 : « propose-moi un rail pour le dashboard franchisé (trois propositions) avec les assistants, la
revue… », puis : « la partie du haut doit reprendre la valeur, la note Google… ».

Aujourd'hui, au bureau, le dashboard magasin (`public/dashboard`) n'a pas de rail : un en-tête (logo, nom du magasin),
une rangée de **onze onglets** (Opérationnel, Tâches, Semaine, Mois, Trimestre, Année, Produits, Promotions, Primes,
Concurrence, Temps morts), puis la date, la pastille **Valeur**, le bouton **Revue du soir** et **Relire**, sur une seule
ligne. Le plan d'action et la campagne (le module des visites, vues `actions` et `campagne`) n'ont pas d'onglet.

Les trois maquettes posent le rail du §8 du design system commun (`docs/DESIGN_SYSTEM.md`) : 246 px, blanc, entrées en
trait normal, seule l'entrée active en gras, une seule active. La barre du haut garde la date et **Relire**. **Le
contenu des onglets ne change pas** : il est esquissé, gris, à droite.

## Les trois

| | forme | ce qu'elle apporte | ce qu'elle coûte |
|---|---|---|---|
| **A** | `a.png` — **les repères en liste**. En haut, cinq lignes : valeur, note Google, objectif du mois, résultat du mois, place au réseau. Les onze onglets deviennent onze entrées en trois groupes (La journée, Les chiffres, Vendre), les assistants en un quatrième groupe, en bas. | le moins de changement : chaque onglet garde son nom et sa place | **1 103 px de haut** : à 1 000 px, les assistants passent sous le pli ; onze entrées à lire |
| **B** | `b.png` — **le tableau de bord dans le rail**. Trois cartes en haut, chacune avec sa forme : la valeur et sa courbe sur six trimestres, la note Google sur son échelle (4,0 → 5,0, trait de la cible), l'objectif du mois en jauge avec le résultat. **Résultat** réunit Semaine, Mois, Trimestre et Année en onglets de page. | les repères se lisent sans lire ; huit entrées au lieu de onze ; tient dans 1 000 px | un onglet de plus à cliquer pour passer du mois à l'année ; trois repères seulement (la place au réseau reste dans la page) |
| **C** | `c.png` — **les assistants d'abord**. Quatre cases en haut (valeur, Google, objectif, résultat), puis la **revue du soir** en carte, avec son état et « Commencer la revue », puis un groupe **À faire** (tâches du jour, plan d'action, note du jour, réclamation fournisseur), puis le magasin et la vente. | le rail répond d'abord à « qu'est-ce que je dois faire ? » ; la revue ne s'oublie plus ; tient dans 1 000 px | la carte de la revue prend la place de deux entrées ; elle n'a de sens que le soir (le matin, elle dirait « faite hier ») |

`planche.jpg` met les trois côte à côte. `mesures.json` : la hauteur de chaque rail.

## La partie du haut : les repères

Les chiffres des maquettes sont des **exemples** (italique pointillé) : la valeur demande le compte consultant du
panel, la note Google la base du cockpit, ni l'un ni l'autre n'est lu ici. La valeur reprend celle affichée le
06/10/2026 sur la capture `../dashboard-operationnel/code-bureau.png` (100 328 €). Ce que chaque repère lirait :

| repère | lecture | dans le dashboard aujourd'hui |
|---|---|---|
| Valeur | `/ventes/mensuel?shop=&mois=30` : CA des 730 derniers jours ÷ 730 × 365 ÷ 6, deux mois de chiffre d'affaires (`valeurMagasin()`) | la pastille **Valeur** de la barre, et son tiroir (la formule, six trimestres) |
| Note Google | `/marketing/avis` : la note de la fiche, le nombre d'avis, la cible (`reputationCible`, 4,5 par défaut) | **non lue** ; la même note sert déjà la prime d'équipe Google (`/ventes/primes-equipe`) |
| Objectif du mois | `/exploitation/periode?vue=mois` : le CA face à l'objectif réparti, attendu à ce jour | vue Mois |
| Résultat du mois | la même lecture : le P&L du mois | vue Mois |
| Place au réseau (A) | la même lecture : le rang au CA, sans nommer les autres (`rendBench`) | le bandeau des vues Semaine et Mois |

Un clic sur un repère ouvre ce qui l'explique : la valeur, son tiroir ; l'objectif et le résultat, Résultat › Mois ;
la note Google, la page « Avis & fiches Google » du Marketing en Vue Franchisé (adresse dans la configuration).

## Les assistants

Ceux que le dashboard a déjà, aucun inventé :

| assistant | où il vit aujourd'hui | dans le rail |
|---|---|---|
| Revue du soir | bouton de la barre ; `/soir/revue`, une question à la fois | entrée avec son état (A, B) ou carte en tête (C) |
| Plan d'action | vue `actions`, le module des visites (`CockpitVisites`, rôle franchisé) — **sans onglet** | entrée, avec les points ouverts |
| Campagne | vue `campagne`, même module — **sans onglet** | entrée |
| Réclamation fournisseur | **au téléphone seulement** | entrée : l'ouvrir au bureau est **à décider** |
| Note du jour (C) | tiroir de l'Opérationnel ; `/exploitation/notes` | entrée du groupe À faire |

L'assistant des promotions reste au cockpit (Promotions › Édition) : l'onglet Promotions du dashboard se lit.

## Écarts au design system, à valider

- **Le bloc des repères** sous l'enseigne : le Marketing n'en a pas. Trait 400 partout, aucun gras ajouté (§4.3) : la
  hiérarchie tient à la taille (13 px en A, 20 px en B, 17 px en C) et aux points de couleur (8 px, §2).
- **Les compteurs et états** des entrées (« 4 », « 3 », « à faire ») : la forme du §8.3, un nombre en 11 px `--accent`
  poussé à droite, sans pastille. « à faire » est un mot, pas un nombre.
- **La carte de la revue du soir** (C) : le bouton d'action du §8 (« au plus un par rail ») posé dans un cadre teinté.
- **L'icône de la revue du soir** : la planchette et la lune du logo L2 du dashboard, redessinées au trait ; elle n'est
  pas au catalogue du §8.4.
- **Résultat** (B, C) : une entrée pour quatre périodes, en onglets de page — c'est la règle du §2 (« un écran à
  plusieurs vues = des onglets »), mais c'est un changement pour ceux qui ont l'habitude de la rangée d'onglets.

## Ce qui ne change pas

Le téléphone garde sa barre d'onglets du bas (le rail est pour le bureau, ≥ 1 024 px, §8.3). Le mode incorporé
(`?embed=1`, `?embed=promos`) n'a pas de rail. Les adresses (retour à l'ERP, Marketing) viennent de la configuration.

## Régénérer

```bash
node docs/maquettes/rail-franchise/generer.js
php -S 127.0.0.1:8099 -t .
node docs/maquettes/rail-franchise/capturer.js
# → http://127.0.0.1:8099/docs/maquettes/rail-franchise/planche.html
```
