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
- **Mode d'emploi** et les mêmes couleurs (abricot = un chiffre réel que toi seul connais, cadre = une
  prévision, pointillé = une estimation, orange = sous le coussin, rouge = découvert).

## Ce qui est repris tout seul (la demande)

| | d'où | ce qu'il fait dans le plan |
|---|---|---|
| **L'année passée** | le panel, jour par jour (la copie de caisse en repli) | le CA de la même semaine un an plus tôt (décalée de 364 jours) : ligne de repère, et base des ventes quand il n'y a pas de budget ou qu'on la choisit (+ une croissance ; la tendance des 13 dernières semaines est donnée en repère) |
| **Le budget validé** | cockpit › Budget : le CA de chaque mois (à défaut le CA théorique de l'étude), sa validation, les taux de Budget › Paramètres | le mois réparti sur ses jours au profil de l'an passé : ligne de repère et base des ventes ; le taux matière en plafond et en estimation des fournisseurs ; le taux personnel en repère ; un plan neuf propose un paiement mensuel par poste de charges du budget |
| **Ce qui a été fait** | le panel | une semaine close prend le CA réel de la caisse à la place de la prévision ; l'onglet « Budget & étude » donne chaque mois le réalisé, le budget, l'atteinte, le N-1, l'évolution et l'atterrissage (réalisé avant le plan, puis plan) |

Ce qui n'est pas inventé : le solde de départ, la TVA, l'ONSS, les dettes, les factures fournisseurs réellement payées
(elles ne sont pas encore lues) restent à saisir — le modèle le disait déjà : sans eux, le plan est trop optimiste.

## Enregistrement

Un plan par magasin, au serveur (`pil_tresorerie_plan`), avec une version : le franchisé et son consultant voient le
même plan ; si deux écrans le modifient en même temps, le second est prévenu (409) au lieu d'écraser le premier, et
peut reprendre le plan enregistré. Un plan neuf est seulement proposé : rien n'est écrit avant la première saisie.
L'entête ne montre plus l'état d'enregistrement ni les boutons « Repartir de zéro », « Importer un plan », « Exporter
CSV » (14/11/2026) ; un enregistrement qui échoue s'affiche, avec « Réessayer ».

## Budget & étude (14/11/2026)

Demande : « un sous-onglet avec les données du budget (qui viennent du cockpit), l'étude de marché… ». Deux maquettes,
faites sur les vrais chiffres de Sombreffe et gardées hors du dépôt (public) : A « les cartes », B « le tableau du budget ».
**B retenue**, les deux onglets fondus (« N-1, budget, réalisé » disparaît), **lecture seule** : on modifie dans le
cockpit (budget, étude) et dans le CRM (postes de charges), les liens y mènent. Le contenu : la base des ventes
prévues ; pour un exercice, mois par mois, l'étude, le budget, le réalisé et l'atterrissage, les charges du budget en
euros jusqu'au disponible pour la rémunération, le P&L réel face aux taux ; l'étude et les exercices en cartes.

## Les chiffres de Sombreffe du modèle

Ses ventes prévues, ses 5 fournisseurs, ses 15 paiements, ses dettes hors plan et son cash de départ (un prévu, pas un
solde constaté) ne sont pas versés ici : **le dépôt est public**. Un fichier au format du plan en a été tiré le
14/11/2026 et remis à part ; l'écran ne propose plus d'import depuis le 14/11/2026 : ces chiffres se tapent dans le plan.

## Captures de la version codée

Chiffres **simulés** (un magasin d'essai : ventes, budget et postes du budget fabriqués pour l'essai, passés par le vrai
calcul de `src/tresorerie.php`).

- `code-plan.jpg` : un plan neuf, proposé d'après le budget validé.
- `code-semaines-closes.jpg` : un plan ouvert deux semaines plus tôt — la semaine close au réel de la caisse, un
  dimanche oublié.
- `code-budget-etude.jpg` : l'onglet « Budget & étude » (la base des ventes, le budget mois par mois, l'étude, les exercices).
- `code-fournisseurs.jpg`, `code-paiements.jpg`.

## Limites connues

- Au téléphone, `?vue=tresorerie` retombe sur le jour, comme les autres onglets du bureau.
- Les factures fournisseurs réellement payées et les relevés bancaires ne sont pas lus : ils se tapent.
- Les données de Sombreffe du modèle ne sont pas chargées (dépôt public, plus d'import) : elles se tapent dans le plan.
