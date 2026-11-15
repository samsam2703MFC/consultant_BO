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
| **B** | `b.png` — **le tableau de bord dans le rail**. Deux cartes en haut du rail, chacune avec sa forme : la note Google sur son échelle (4,0 → 5,0, trait de la cible), l'objectif en jauge — **celui du jour**, celui de la période dans les onglets de Résultat. **La valeur, le coach et la revue du soir** sont trois avatars identiques en haut à droite (voir plus bas). **Résultat** réunit Semaine, Mois, Trimestre et Année en onglets de page. | les repères se lisent sans lire ; sept entrées au lieu de onze ; tient dans 1 000 px | un onglet de plus à cliquer pour passer du mois à l'année ; la place au réseau reste dans la page |
| **C** | `c.png` — **les assistants d'abord**. Quatre cases en haut (valeur, Google, objectif, résultat), puis la **revue du soir** en carte, avec son état et « Commencer la revue », puis un groupe **À faire** (tâches du jour, plan d'action, note du jour, réclamation fournisseur), puis le magasin et la vente. | le rail répond d'abord à « qu'est-ce que je dois faire ? » ; la revue ne s'oublie plus ; tient dans 1 000 px | la carte de la revue prend la place de deux entrées ; elle n'a de sens que le soir (le matin, elle dirait « faite hier ») |

`planche.jpg` met les trois côte à côte. `mesures.json` : la hauteur de chaque rail.

## B retouchée : la valeur, le coach et la revue en avatars

Demande du 15/11/2026 : « la valeur doit être en haut à droite avec l'avatar coach et l'avatar revue du jour ; les
trois avatars de taille et de forme identiques ». En haut à droite de la barre, après la date et « Relire », un filet,
puis trois avatars : **le même cercle de 36 px** pour les trois (fond blanc, filet `--line`, icône au trait de 18 px),
et à côté, en deux lignes, ce qu'il dit.

| avatar | icône | à côté | un clic ouvre | lecture |
|---|---|---|---|---|
| Valeur | l'euro | « Valeur · 100 328 € » | le tiroir de la valeur (la formule, six trimestres), comme la pastille d'aujourd'hui | `/ventes/mensuel?shop=&mois=30` |
| Coach | une personne | « Coach · 2 à regarder » | **l'assistant IA** : une conversation dans un tiroir à droite, sous la barre (`b-coach.png`) | les lectures du dashboard ; le modèle par le client du cockpit (`src/anthropic.php`), la clé IA commune côté serveur |
| Revue du soir | la planchette et la lune | « Revue du soir · à faire » | l'assistant du soir, une question à la fois | `/soir/revue` (`?leger=1` pour l'état) |

L'avatar qui attend un geste se teinte de bordeaux (la revue à faire) ; il redevient comme les deux autres une fois la
revue faite (« faite à 19 h 40 »). Chaque donnée à une seule place : la carte de la valeur et l'entrée « Revue du soir » quittent le rail ; le plan
d'action, la campagne et la réclamation fournisseur y restent, dans le groupe Assistants. Le nom « Revue du soir » est
celui de l'écran d'aujourd'hui ; « revue du jour » si on le renomme.

### Le coach, l'assistant IA

Demande du 15/11/2026 : « le coach, c'est l'assistant IA ». Un clic sur l'avatar ouvre une conversation dans un tiroir
de 420 px à droite, sous la barre, bordé, sans ombre (§2) ; le contenu de l'onglet reste derrière. `b-coach.png` montre
une conversation **d'exemple** :

- en ouvrant, le coach dit où en est la journée et ce qui est à regarder (« 2 à regarder » sous l'avatar) ;
- chaque chiffre cite l'écran d'où il vient (« Opérationnel › », « Résultat › Mois › ») : un clic l'ouvre ;
- il **propose et n'écrit rien** : « Préparer ce point du plan d'action » ouvre le plan d'action pré-rempli, le
  franchisé le relit et le note — la règle déjà tenue par l'IA du cockpit (`src/anthropic.php` : « Claude peut
  PROPOSER ; il ne note jamais ») ;
- trois questions toutes prêtes, puis le champ libre et « Envoyer ».

Ce qu'il faut pour le coder : un point d'API serveur (la clé ne quitte jamais le serveur ; elle est dans la table des
clés commune, portail `commun`), qui passe au modèle les lectures du magasin déjà servies au dashboard, et seulement
celles de ce magasin ; une réponse contrainte à citer ses chiffres ; un journal des conversations. Rien de cela n'existe
aujourd'hui : l'IA du cockpit ne sert qu'à proposer une note de photo et à relire la charte.

## B retouchée : l'objectif du jour

Demande du 15/11/2026 : « le premier B avec l'objectif du jour ; les objectifs de la semaine et de l'année ne se
voient que sur les onglets Semaine, Mois et Année ». La carte de l'objectif suit l'écran ouvert :

| écran ouvert | la carte | la jauge | le trait | capture |
|---|---|---|---|---|
| Opérationnel, Tâches, Produits, Promotions… (tout écran hors Résultat) | **Objectif du jour** : « 68 % de 2 380 € », à l'heure de la lecture | le CA de la journée face à son objectif entier | J−7 à la même heure ; verte en avance sur J−7, or en retard | `b.png` |
| Résultat › Semaine | **Objectif semaine** : « 88 %, − 1 430 € », du lundi au dimanche | le CA face à l'objectif attendu à ce jour | 100 % ; verte atteint, or en dessous | `b-semaine.png` |
| Résultat › Mois | **Objectif du mois** : « 92 %, − 2 690 € » | idem | idem | `b-mois.png` |
| Résultat › Trimestre | **Objectif T4** : « 96 %, − 2 140 € » | idem | idem | `b-trimestre.png` |
| Résultat › Année | **Objectif 2026** : « 97 %, − 9 870 € » | idem | idem | `b-annee.png` |

La ligne sous la jauge : au jour, ce qui reste et J−7 à cette heure (la tuile « Objectif du jour » de
l'Opérationnel) ; pour une période, son résultat. Le Trimestre suit la même règle que les trois onglets demandés.
`planche-objectif.jpg` met le haut des cinq écrans côte à côte. Lectures : `/exploitation/jour` (le jour, J−7) ;
`/exploitation/periode?vue=semaine|mois|trimestre|annee` (la période, son attendu, son P&L).

## Trois variantes de B

Demande du 15/11/2026 : « donne-moi trois autres propositions comme la B ». Même rail que B — les repères en cartes,
chacun avec sa forme ; **Résultat** réunit les quatre périodes ; les assistants en entrées, avec leur état. Ce qui
change : la question à laquelle le haut répond. Les trois tiennent dans 1 000 px. `planche-b.jpg` met B et ses trois
variantes côte à côte.

| | forme | ce qu'elle apporte | ce qu'elle coûte |
|---|---|---|---|
| **B1** | `b1.png` — **douze mois**. Quatre cartes en grille : la valeur et sa courbe, la note Google par trimestre face à la cible, l'objectif atteint mois par mois (barres, trait des 100 %), le résultat mois par mois (vert positif, rouge négatif). Le mois en cours est pâle. | d'où vient le magasin, d'un coup d'œil : « 4 mois sur 11 à 100 % », « positif 10 mois sur 11 » | des graphiques de 90 px de large : une tendance, pas des chiffres ; la note Google n'a d'historique que par trimestre (`con_scoring_google`, figée en fin de trimestre) |
| **B2** | `b2.png` — **les primes en jeu**. L'objectif du mois et la note Google disent ce qu'ils rapportent à l'équipe : « Prime à 100 % · 40 € chacune · + 180 € par jour d'ici le 30/11 », « Prime gagnée · 25 € chacune ». La valeur tient en une ligne. L'entrée Primes compte les primes d'équipe gagnées (1 / 2). | relie les repères à ce que l'équipe y gagne ; le franchisé sait quoi pousser ce mois-ci | ne parle que des deux primes d'équipe (objectif, Google) ; sans prime réglée pour le magasin, les cartes redeviennent celles de B |
| **B3** | `b3.png` — **le verdict**. Chaque carte commence par une phrase : « Il manque 2 690 € pour l'objectif », « Google tient la cible », « La valeur monte ». Une carte **À regarder** liste ce qui dépasse un seuil (matière au-dessus de 35 %, commandes en retard, contrôle sans photo) et mène à l'onglet qui l'explique. | répond à « qu'est-ce qui cloche ? » sans rien lire d'autre | les phrases et la liste doivent être écrites par des règles (les seuils `MA` du dashboard) ; une liste vide doit le dire (« Rien à signaler ») |

Les montants des primes (40 €, 25 €), les douze mois et les alertes sont des exemples, comme les autres chiffres du
rail. Les primes d'équipe se lisent par `/ventes/primes-equipe?shop=&m=` ; les alertes, dans les lectures que
l'Opérationnel fait déjà (`/exploitation/jour`, `/ventes/commandes`, `/pwa/tasks`).

## La partie du haut : les repères

Les chiffres des maquettes sont des **exemples** (italique pointillé) : la valeur demande le compte consultant du
panel, la note Google la base du cockpit, ni l'un ni l'autre n'est lu ici. La valeur reprend celle affichée le
06/10/2026 sur la capture `../dashboard-operationnel/code-bureau.png` (100 328 €). Ce que chaque repère lirait :

| repère | lecture | dans le dashboard aujourd'hui |
|---|---|---|
| Valeur | `/ventes/mensuel?shop=&mois=30` : CA des 730 derniers jours ÷ 730 × 365 ÷ 6, deux mois de chiffre d'affaires (`valeurMagasin()`) | la pastille **Valeur** de la barre, et son tiroir (la formule, six trimestres) |
| Note Google | `/marketing/avis` : la note de la fiche, le nombre d'avis, la cible (`reputationCible`, 4,5 par défaut) | **non lue** ; la même note sert déjà la prime d'équipe Google (`/ventes/primes-equipe`) |
| Objectif du jour (B : celui de la période dans Résultat) | `/exploitation/jour` : le CA face à l'objectif du jour, J−7 à la même heure | tuile « Objectif du jour » de l'Opérationnel |
| Objectif du mois | `/exploitation/periode?vue=mois` : le CA face à l'objectif réparti, attendu à ce jour | vue Mois |
| Résultat du mois | la même lecture : le P&L du mois | vue Mois |
| Place au réseau (A) | la même lecture : le rang au CA, sans nommer les autres (`rendBench`) | le bandeau des vues Semaine et Mois |

Un clic sur un repère ouvre ce qui l'explique : la valeur, son tiroir ; l'objectif et le résultat, Résultat › Mois ;
la note Google, la page « Avis & fiches Google » du Marketing en Vue Franchisé (adresse dans la configuration).

## Les assistants

Ceux que le dashboard a déjà, aucun inventé :

| assistant | où il vit aujourd'hui | dans le rail |
|---|---|---|
| Revue du soir | bouton de la barre ; `/soir/revue`, une question à la fois | entrée avec son état (A, variantes de B), carte en tête (C), avatar en haut à droite (B) |
| Plan d'action | vue `actions`, le module des visites (`CockpitVisites`, rôle franchisé) — **sans onglet** | entrée, avec les points ouverts ; le coach IA peut y préparer un point (B) |
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
- **Les avatars du haut à droite** (B) : un composant que le Marketing n'a pas ; le cercle est permis (§2 : même largeur et même hauteur). Deux tracés hors catalogue du §8.4 : l'euro (`M17.5 6.5A7 7 0 1 0 17.5 17.5M4 10h10M4 14h10`) et la personne seule (celle de « Droits » sans le plus).
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
