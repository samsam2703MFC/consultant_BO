# Dashboard magasin › Trésorerie : le plan de trésorerie

**Statut : codé le 14/11/2026**, d'après le modèle joint à la demande, « Plan de trésorerie — L'Atelier By » (une page
autonome faite pour Sombreffe à partir de son fichier Excel). Le modèle n'est pas versé ici : il porte les chiffres de
Sombreffe et les polices de la marque. Code : `src/tresorerie.php`, `public/dashboard/tresorerie.js` et
`tresorerie.css` ; routes : `docs/contrat-api.md` (« `/tresorerie/*` ») ; tests : `php bin/tests_tresorerie.php`.

Demande : « crée un module dans le dashboard shop (trésorerie) basé sur le modèle en annexe, avec une reprise des
chiffres de l'année passée, du budget validé et de ce qui a été fait ».

## Ce qui est repris du modèle, à l'identique

Une question : est-ce que le magasin peut payer tout le monde dans les semaines qui viennent ? Semaine par semaine, en
€ TTC, du premier lundi du plan à la fin de l'année suivante (ouvert le 16/11/2026 : jusqu'au dimanche 02/01/2028).

- **Plan** : la routine du dimanche (le solde réel lu dans la banque, l'écart au prévu, les dimanches oubliés), la
  décision des 4 semaines à venir, les repères (point bas, semaines sous le coussin, marchandises et personnel en % des
  ventes), le cash de fin de semaine en barres, la grille semaine par semaine (8, 13 semaines ou tout), le pilotage
  (cash de départ, coussin) et les dettes hors plan.
- **Fournisseurs** : un montant par fournisseur et par semaine ; une case vide vaut ventes prévues × % du fournisseur.
- **Paiements** : les paiements récurrents (chaque semaine, chaque mois la semaine 1 à 4, chaque trimestre), avec dates
  de début et de fin, rangés dans les lignes du plan par leur catégorie.
- **Mode d'emploi**, l'export CSV, les mêmes couleurs (abricot = un chiffre réel que toi seul connais, cadre = une
  prévision, pointillé = une estimation, orange = sous le coussin, rouge = découvert).

## Ce qui est repris tout seul (la demande)

| | d'où | ce qu'il fait dans le plan |
|---|---|---|
| **L'année passée** | le panel, jour par jour (la copie de caisse en repli) | le CA de la même semaine un an plus tôt (décalée de 364 jours) : ligne de repère, et base des ventes quand il n'y a pas de budget ou qu'on la choisit (+ une croissance ; la tendance des 13 dernières semaines est donnée en repère) |
| **Le budget validé** | cockpit › Budget : le CA de chaque mois (à défaut le CA théorique de l'étude), sa validation, les taux de Budget › Paramètres | le mois réparti sur ses jours au profil de l'an passé : ligne de repère et base des ventes ; le taux matière en plafond et en estimation des fournisseurs ; le taux personnel en repère ; un plan neuf propose un paiement mensuel par poste de charges du budget |
| **Ce qui a été fait** | le panel | une semaine close prend le CA réel de la caisse à la place de la prévision ; l'onglet « N-1, budget, réalisé » donne chaque mois le réalisé, le budget, l'atteinte, le N-1, l'évolution et l'atterrissage (réalisé avant le plan, puis plan) |

Ce qui n'est pas inventé : le solde de départ, la TVA, l'ONSS, les dettes, les factures fournisseurs réellement payées
(elles ne sont pas encore lues) restent à saisir — le modèle le disait déjà : sans eux, le plan est trop optimiste.

## Enregistrement

Un plan par magasin, au serveur (`pil_tresorerie_plan`), avec une version : le franchisé et son consultant voient le
même plan ; si deux écrans le modifient en même temps, le second est prévenu (409) au lieu d'écraser le premier, et
peut reprendre le plan enregistré. Un plan neuf est seulement proposé : rien n'est écrit avant la première saisie.
« Repartir de zéro » efface le plan et en propose un neuf à partir de la semaine en cours.

## Importer un plan (les chiffres de Sombreffe)

« Importer un plan », dans l'entête du module, charge un plan au format du module (`v: 2`, voir `docs/contrat-api.md`) :
la reprise d'un ancien fichier, ou une sauvegarde. Le plan enregistré du magasin n'est remplacé qu'après confirmation,
et l'import s'enregistre comme une saisie (version, conflit). Un plan qui dit pour quel magasin il a été fait
(`magasin`) ne s'importe ailleurs qu'après confirmation. `source` s'affiche après l'import ; `startCashNote` remplace
l'aide du cash de départ tant que le solde n'a pas été retapé.

Les chiffres de Sombreffe du modèle (ses ventes prévues, ses 5 fournisseurs, ses 15 paiements, ses dettes hors plan, le
cash de départ — un prévu, pas un solde constaté) passent par ce chemin : **le dépôt est public**, ils ne
sont pas versés ici. Le fichier `plan_tresorerie_sombreffe.json` a été tiré des données d'origine du modèle (son
`defaults()`), le 14/11/2026, et remis à part ; il s'importe depuis le dashboard de Sombreffe, onglet Trésorerie.

## Captures de la version codée

Chiffres **simulés** (un magasin d'essai : ventes, budget et postes du budget fabriqués pour l'essai, passés par le vrai
calcul de `src/tresorerie.php`).

- `code-plan.jpg` : un plan neuf, proposé d'après le budget validé.
- `code-semaines-closes.jpg` : un plan ouvert deux semaines plus tôt — la semaine close au réel de la caisse, un
  dimanche oublié.
- `code-n1-budget-realise.jpg` : la base des ventes, l'état du budget, les mois.
- `code-fournisseurs.jpg`, `code-paiements.jpg`.

## Limites connues

- Au téléphone, `?vue=tresorerie` retombe sur le jour, comme les autres onglets du bureau.
- Les factures fournisseurs réellement payées et les relevés bancaires ne sont pas lus : ils se tapent.
- Les données de Sombreffe du modèle ne sont pas chargées d'office (dépôt public) : elles s'importent (voir plus haut).
