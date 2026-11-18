# Contrôle des franchisés : l'audit des outils de gestion — maquette

Demande du 17/11/2026 : « Dans la tablette et le suivi des franchisés, il me faut un moment pour faire un audit sur le
respect des bons process de gestion : plan de trésorerie (utilisé ou non), les commandes dans l'ordinateur, les
étiquettes sur les commandes, étiquettes sur produits, suivi des tâches (tâches et checklists), revue du soir, …
commandes par le … Rappeler qu'en ne suivant pas les outils mis en place, aucun effort ne sera fait de la part de la
marque en cas de problèmes ou manquements de gestion — à mettre dans le mail de rapport et en (i) dans la tablette.
Faire une maquette (screenshot). »

## Sur la tablette

Une nouvelle étape, **Les outils de gestion**, en tête du temps **4 · Analyse** : on est assis avec le franchisé,
devant l'ordinateur du magasin, juste avant les chiffres. (Elle se déplace comme les autres étapes, Réglages ›
Contrôle guidé.)

| Élément | Ce qu'il fait |
|---|---|
| Les sept outils | Plan de trésorerie · Les commandes dans l'ordinateur · Les étiquettes sur les commandes · Les étiquettes sur les produits · Le suivi des tâches et des checklists · La revue du soir · Les commandes fournisseurs au panel. Chacun a une aide courte (ce qu'on regarde). La liste se règle comme la checklist (Réglages › Checklist) |
| « Lu tout seul » | ce que le cockpit sait déjà, posé sous l'outil avant que le consultant réponde (voir plus bas). Pour les étiquettes, rien à lire : « Vu sur place » |
| Le verdict | Suivi · En partie · Pas suivi · N/A. Vert, or, rouge |
| La preuve | une ou plusieurs photos (l'écran du plan, le carnet papier, une commande sans étiquette), annotables comme toutes les photos de la visite |
| La remarque | obligatoire pour « En partie » et « Pas suivi » ; elle part dans le rapport |
| Le point proposé | « Pas suivi » propose un point P1, « En partie » un P2, à l'échéance du réseau (Règles du réseau : P1 7 jours, P2 14 jours). Il rejoint les points à travailler, où le bureau le garde ou l'enlève |
| La synthèse (à gauche) | le score sur 100 (Suivi 1, En partie ½, Pas suivi 0, N/A hors calcul), la visite précédente et l'écart, une barre de sept cases, les compteurs |
| **Le (i)** | à côté du titre : un toucher ouvre « Pourquoi cet audit » et **le rappel** (texte ci-dessous). Le même rappel est en permanence dans la synthèse |
| « Rappel lu avec le franchisé » | une case à cocher sous le rappel, horodatée : la trace que le franchisé l'a entendu |
| L'étape | remplie quand les sept verdicts sont donnés, les remarques écrites et le rappel coché. Bloquante par défaut (Réglages › Contrôle guidé) |

**Le rappel** (validé tel quel avant le code, puis modifiable dans Suivi › Qui fait quoi › Les courriels) :

| Langue | Texte |
|---|---|
| FR | Les outils de gestion mis en place par la marque sont à suivre. **En ne les suivant pas, aucun effort ne sera fait de la part de la marque en cas de problème ou de manquement de gestion.** |
| NL | De beheertools die het merk heeft ingevoerd, moeten gevolgd worden. **Wie ze niet volgt, kan bij problemen of tekortkomingen in het beheer geen enkele inspanning van het merk verwachten.** |
| EN | The management tools put in place by the brand must be followed. **If they are not followed, the brand will make no effort in the event of management problems or failures.** |
| DE | Die von der Marke eingeführten Verwaltungswerkzeuge sind einzuhalten. **Werden sie nicht befolgt, wird die Marke bei Problemen oder Versäumnissen in der Geschäftsführung keinerlei Entgegenkommen zeigen.** |

## Ce que la tablette lit toute seule

Rien de neuf à brancher sauf la dernière ligne : ce sont des lectures que le cockpit fait déjà.

| Outil | Ce qui est lu | D'où |
|---|---|---|
| Plan de trésorerie | la date de la dernière mise à jour, les dimanches où le solde réel est saisi (4 dernières semaines) | `pil_tresorerie_plan`, `GET /tresorerie/plan?shop=` |
| Commandes dans l'ordinateur | les commandes encodées sur 30 jours, les commandes passées encore ouvertes | `/ventes/commandes?shop=` ← panel `/shops/{id}/client-orders` (la sonde `commandes` de la revue du soir) |
| Tâches et checklists | les tâches obligatoires faites, les checklists, les photos rendues sur 30 jours | `/pwa/tasks` (sondes `taches` et `photos`), `GET /visites/taches/{shop}` |
| Revue du soir | les soirs clôturés sur 30, la dernière | `ceo_revue_soir`, `GET /soir/revue` |
| Commandes fournisseurs | les commandes sur 30 jours, la dernière | `material_order` : **le panel n'a pas de route** (`/material-orders` répond 404, voir `src/commandes.php`) — à demander à TF Buddy, ou à lire en base comme aujourd'hui |

Panel muet : la ligne dit « pas de donnée » et le consultant répond seul, comme la revue du soir.

## Dans le rapport

| Où | Ce qu'on voit |
|---|---|
| **Le courriel du rapport** | après les points à travailler : « Le respect des outils de gestion : 57 / 100 (visite précédente : 71) », un tableau des sept outils (✓ suivi, ◐ en partie, ✗ pas suivi, la remarque), puis **le rappel (i)**, encadré. Dans la langue du magasin (les quatre langues du modèle « Rapport de visite ») |
| Le rappel seul | il est dans **chaque** courriel de rapport, même sans audit à cette visite (le tableau, lui, n'y est que si l'audit a été fait) |
| Le PDF, l'écran du bureau, la page du franchisé | le même bloc (proposé, à confirmer) |

Le bureau voit et peut corriger les verdicts dans « rapport à adapter », comme le reste, avant l'envoi.

## Captures

| Fichier | Ce que c'est |
|---|---|
| `a-tablette.png` | l'étape remplie, tablette couchée : la synthèse et le rappel à gauche, les outils à droite (`-page.png` : l'étape entière, les sept outils) |
| `b-tablette-info.png` | le (i) ouvert à côté du titre : « Pourquoi cet audit » et le rappel |
| `c-courriel-rapport.png` | le courriel du rapport, avec le nouveau bloc (encadré « nouveau ») |

**Comment elles sont faites.** `maquette.html` est une page statique : le design system (`public/assets/ds/global.css`)
et le CSS de la tablette de la branche (la constante `CSS` de `public/assets/js/visites.js`, injectée à la capture).
Seul le CSS de l'étape (préfixe `og-`) est neuf. Rien n'est lu en ligne, rien n'est écrit en base. Halle, visite
régulière du 17/11/2026, Loic V. : **les chiffres « lus tout seuls », les verdicts et les remarques sont des exemples.**

Régénérer : `node docs/maquettes/audit-outils-gestion/generer.js`

## Les choix faits à la mise en ligne

La demande ne tranchait pas trois points ; « Push online » (18/11/2026) a validé les choix de la maquette :

1. « …commandes par le … » : **les commandes fournisseurs passées par le panel**.
2. La place : **en tête du temps 4 · Analyse**.
3. Le rappel : **dans chaque courriel de rapport**, même sans audit à cette visite (le tableau des outils, lui, n'y est que
   si l'audit a été fait).

## Statut

**Codée et mise en ligne le 18/11/2026.**

- **Tablette** : l'étape `outils` dans `public/assets/js/visites.js` (`OUTILS`, `chargerOutils`, `outilsHtml`,
  `outilsManque`, `ogVerdict`, `ogDansPlan`, `outilsCorps`, le (i) `ogPopHtml`) ; traductions dans `visites-nl.js` ; la
  ligne « Les outils de gestion » dans Réglages › Contrôle guidé (bloquante par défaut).
- **Serveur** (`src/visites_outils.php`) : la liste, le contrôle de ce qu'envoie la tablette (`viOutils`), le score, le
  rappel dans les quatre langues, la lecture `GET /visites/outils/{shop}`, la colonne `con_visite.outils` (JSON, ajoutée au
  premier appel, cachée au franchisé tant que le rapport est un brouillon).
- **Rapport** : le bloc dans le PDF et « Voir le rapport » (`suOutilsHtml`), le courriel (`{outils_titre}`, `{outils}`,
  `{rappel}` dans le modèle « Rapport de visite », `suModeleAvecRappel` pour un modèle déjà réécrit), la carte du bureau
  (`suivi-tpl.js`, `suivi-vals.js`, `app.css`), la page du franchisé (`public/suivi/`).
- **Tests** : `php bin/tests_suivi.php`, section « Les outils de gestion ».

| Capture du code | Ce que c'est |
|---|---|
| `d-tablette-codee.png` (`-page.png` : l'étape entière) | l'étape telle qu'elle est codée, remplie |
| `d-tablette-codee-info.png` | le (i) ouvert |
| `e-tablette-nl.png` | la même en néerlandais (Halle) |

Elles sont faites par `capturer-tablette.js` : la tablette de la branche servie en local, l'API simulée, rien d'écrit.
