# Vue Jour du dashboard magasin en une page A4 — trois maquettes

**Statut : maquettes à valider, rien n'est codé dans le dashboard.**

La demande : que la vue Jour (`/dashboard/?shop=4&vue=jour&date=2026-10-03`) tienne dans
l'équivalent d'une page A4, chaque section devenant une **question**, avec la section complète
dans une **liste déroulante** sous la question.

Aujourd'hui la vue Jour de Halle fait **4 555 px** de haut à 1 348 px de large : 21 blocs,
dont le carrousel des photos (423 px), les ventes par catégorie (568 px) et les deux graphiques
des heures (995 px). Une page A4 à 150 dpi fait 1 240 × 1 754 px.

## Les seize questions (communes aux trois maquettes)

| Chapitre | Question | Section d'aujourd'hui dans la liste déroulante |
|---|---|---|
| Le magasin est-il prêt ? | Hier, une non-conformité à reprendre ? | bandeau des non-conformités d'hier |
| | Les tâches du jour sont-elles faites ? | Les tâches du jour + le fil de la journée |
| | Les contrôles en photo sont-ils conformes ? | Les contrôles en photo (carrousel) |
| | Le stock est-il à jour ? | bandeau du stock |
| Combien la journée rapporte-t-elle ? | Combien ai-je vendu ? | tuiles CA, clients, panier, projection |
| | L'objectif du jour est-il atteint ? | Objectif du jour |
| | Combien me reste-t-il ? | Le P&L court de la journée |
| | Où suis-je dans le réseau ? | Ta place dans le réseau (anonyme) |
| D'où vient le chiffre ? | Par où passent les ventes ? | Commandes et canaux (+ clients pro) |
| | Qu'est-ce qui se vend ? | Ventes par catégorie (liste / treemap) |
| | Mes promotions rapportent-elles ? | Promotions et bundles |
| | Qu'est-ce qui part à la poubelle ? | Invendus et poubelle |
| Comment la journée s'est-elle passée ? | Quelles heures rapportent ? | tuiles des heures, Ce que chaque heure rapporte, Heure par heure |
| | L'équipe est-elle bien dimensionnée ? | Qui est en poste (+ planning) |
| | Comment se place la journée dans le mois ? | Le jour dans le mois |
| | Qu'est-ce qui explique la journée ? | La note du jour |

Chaque question porte une pastille de verdict : rouge (à reprendre), orange (à surveiller),
vert (en ordre), or (record ou objectif dépassé), gris (pour information). Le décompte des
verdicts s'affiche sous l'en-tête.

## Les trois maquettes

- **A — Une ligne par question** (`a.html`, `a.jpg`, `a-replie.jpg`) : quatre chapitres, une
  ligne de 47 px par question : pastille, question, réponse chiffrée, phrase, mini-graphique,
  flèche. La section se déroule sous sa ligne. Ouverte sur « Quelles heures rapportent ? ».
- **B — L'essentiel, puis quatre chapitres** (`b.html`, `b.jpg`, `b-replie.jpg`) : la journée
  en une phrase et quatre chiffres en tête, puis quatre bandes de quatre questions en colonnes.
  La section s'ouvre sous sa bande, avec une encoche sous la question. Ouverte sur
  « Qu'est-ce qui se vend ? ».
- **C — Les vignettes, rangées par urgence** (`c.html`, `c.jpg`, `c-replie.jpg`) : seize
  vignettes à mini-graphique en quatre rangées : à faire maintenant, le chiffre, pourquoi, le
  contexte. La section s'ouvre sous la rangée. Ouverte sur « Combien me reste-t-il ? ».

`planche.html` / `planche.jpg` : les trois côte à côte, avec leurs apports et leurs limites.

## Mesures (generer.js les refait à chaque génération, `mesures.json`)

| Maquette | Repliée | Section ouverte par défaut | Pire cas (chaque section ouverte tour à tour) | Feuille |
|---|---|---|---|---|
| A | 1 034 px | 1 342 px | 1 426 px (catégories) | 1 754 px |
| B | 1 185 px | 1 591 px | 1 591 px (catégories) | 1 754 px |
| C | 1 044 px | 1 407 px | 1 447 px (catégories) | 1 754 px |

Les trois tiennent dans la feuille repliées et avec n'importe quelle section ouverte. Une seule
section est ouverte à la fois : en ouvrir une referme la précédente. Les pages s'impriment sur
une feuille A4 (`@page A4`, mise à l'échelle 64 %).

## Données

**Réel** : Halle (shop 4), samedi 3 octobre 2026, relu en lecture seule vers 17 h 15 sur le
serveur (`reel-halle-0310.json`) : `/ventes/stats`, `/exploitation/jour`, `/exploitation/canaux`,
`/exploitation/pro`, `/exploitation/invendus`, `/pwa/tasks/nc`, `/pwa/tasks/photos`,
`/ventes/record`. Le réseau reste anonyme ; aucun nom de client.

**Pas reproduit** : les photos des contrôles (liens signés de 20 minutes), hachurées ici ; le
détail dépliable de chaque section (le treemap, le tableau heure par heure, le planning par
personne, la liste des commandes), simplement annoncé.

## Deux écarts relevés dans les données en préparant les maquettes

- **Le jour dans le mois** et **le P&L court** ne donnent pas la même marge nette pour le
  03/10 : 1 090 € (41,2 %) d'un côté, 741 € (28,0 %) de l'autre. La série du mois répartit la
  main-d'œuvre et les frais généraux à la moyenne du mois, le P&L court prend le planning du
  jour. Une page qui pose les deux questions côte à côte doit dire le même chiffre.
- **Les ventes par heure de la vue réseau** (`/exploitation/jour`, lues sur
  `margin-heatmap`) sont décalées de deux heures par rapport à `/ventes/stats`
  (hourly-distribution) : 43,1 € à 8 h d'un côté, à 6 h de l'autre. Les tickets pro de 09:04 et
  09:19 tombent bien à 9 h dans `/ventes/stats`.

## Régénérer

```
npx http-server . -p 8099 -c-1   # depuis la racine du dépôt
node docs/maquettes/vue-jour-a4/generer.js
```
