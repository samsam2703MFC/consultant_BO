# Opérationnel : le chiffre face au budget, sur le mois, le trimestre et l'année — maquette

Demande du 05/11/2026 : « Rajoute une jauge mensuelle / trimestrielle et annuelle de la jauge de CA par rapport au
budget. »

Dans le dashboard magasin, onglet **Opérationnel**, une carte **Le chiffre face au budget** se pose sous les jauges du
jour (comptoir et B2B), au-dessus de l'heure par heure. Elle est aussi au téléphone.

## Ce qu'elle montre

Trois pistes : le mois en cours, le trimestre, l'année. Chacune va de 0 au budget de sa période.

| Élément | Ce qu'il dit |
|---|---|
| La barre pleine | le **réalisé** : les jours passés du mois, aujourd'hui en direct (le même chiffre que la jauge du jour), les mois clos de l'année |
| Sa couleur | l'atteinte de l'attendu, comme le calendrier : rouge sous 80 %, orange sous 95, vert sous 105, doré au-delà |
| Le trait pointillé | l'**attendu à cette heure**, détaillé sous ce tableau |
| Le hachuré | la **projection au rythme actuel** : le budget de la période × réalisé ÷ attendu. Absente quand la période est complète |
| À droite | le réalisé, son pourcentage du budget, l'écart à l'attendu en euros et en pourcentage, la projection |
| Sous le nom | où on en est : jour 5 sur 30, jour 36 sur 92, jour 309 sur 365 |

**L'attendu à cette heure** additionne trois choses :
- le budget des mois clos ;
- l'objectif de chaque jour passé du mois (le budget du mois réparti selon le profil des jours, celui de la jauge du
  jour et du calendrier) ;
- la part de l'objectif du jour que le magasin fait d'habitude à cette heure-ci (la même que la projection de la jauge
  du jour).

Sur une journée passée, la journée compte entière.

Le chiffre est le **CA total, pro compris**, face au budget de la période.

**Le budget d'un mois** est celui du cockpit (05/11/2026, Sam : « budget année ») : le budget encodé, à défaut le
budget théorique. C'est la même règle que le suivi budget, les vues Trimestre et Année et le plan de développement. La
ligne le dit quand des mois y comptent au théorique (« dont janv., févr. au budget théorique »).

## Les chiffres de Halle le 05/11/2026 au matin

| Période | Réalisé | Attendu à cette heure | Budget | Atteinte | Au rythme actuel |
|---|---|---|---|---|---|
| Novembre (jour 5 sur 30) | 8 017 € | 8 346 € | 61 000 € | 96 % | 58 595 € |
| T4 2026 (jour 36 sur 92) | 66 900 € | 69 346 € | 183 000 € | 96 % | 176 546 € |
| Année 2026 (jour 309 sur 365) | 434 674 € | 635 746 € | 749 400 € | 68 % | 512 382 € |

**Recoupements.**
- Le réalisé du T4 (octobre 58 883 € + novembre à ce jour) est celui du plan de développement (vue Trimestre).
- Le budget de l'année (749 400 €) est le « budget mensuel total » du plan : 615 000 € encodés de mars à décembre,
  plus janvier (75 600 €) et février (58 800 €) au théorique. L'objectif engagé de l'année est un autre chiffre
  (840 000 €, vue Année) ; ce n'est pas le budget.

## Captures

| Fichier | Ce que c'est |
|---|---|
| `a-carte.png` | la carte |
| `b-a-sa-place.png` | la carte à sa place, sous les jauges du jour |
| `c-journee-passee-31-10.png` | une journée passée, le 31/10 : octobre complet, sans projection |
| `d-telephone.png` | au téléphone |

**Comment elles sont faites.** La page en ligne du dashboard, ouverte en lecture seule (tout appel autre que GET est
intercepté), avec `dashboard.js` et `dashboard.css` de ce dépôt. Chiffres réels.

Régénérer : `node docs/maquettes/budget-periodes/generer.js`

## Statut

**Codée le 05/11/2026 sur la branche, à valider avant la mise en ligne.**

- **Calcul et rendu** : `opBudgetPeriodes()` dans `public/dashboard/dashboard.js`, appelée sous `opDuelSplit()` au
  bureau et au téléphone. Styles `.op-bud` et `.op-bp` dans `dashboard.css`.
- **Lectures** : rien de nouveau côté serveur.
  - `/exploitation/periode?vue=mois`, déjà lue pour la frise des jours ;
  - `/stores/perf` (budget et CA de chaque mois), déjà lue par la vue Trimestre.

  L'Opérationnel les lit désormais aussi.
