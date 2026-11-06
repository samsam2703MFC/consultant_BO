# Objectifs de campagne dans le plan d'action — trois propositions

Dans le plan d'action du dashboard magasin (téléphone) : les objectifs de
campagne en tête, et sur chaque action liée à une campagne, un graphique du
nombre de clients face au N-1 et au nombre de clients visé.

Données réelles de Gosselies (shop 3), lues en ligne le 01/10/2026 (`donnees.json`) :

- campagne 18 « Réseaux Sociaux - Septembre 2026 », +10 % de clients sur le N-1 :
  131 clients/jour en N-1 → 4 323 visés, 3 933 l'an dernier, 3 866 faits
  (`GET /marketing/budget-campagnes?campagne=18`, ligne shopId 3) ;
- campagne 19 « Développement B2B - Lancement Webshop », 01/10 → 29/11, sans
  objectif chiffré dans le cockpit : N-1 8 377 clients, jour 1 : 102 ;
- les clients jour par jour (`/exploitation/periode?vue=mois`) et leur N-1
  aligné au même jour de semaine (−364 jours), dont la somme retombe
  exactement sur les 3 933 du calcul marketing.

Les trois ACTIONS du plan sont une illustration : Gosselies n'en a aucune en
ligne, et le lien action ↔ campagne n'existe pas encore (voir « avant de coder »).

| | Proposition | Planche |
|---|---|---|
| A | Jauge en tête (clients · N-1 · objectif), puis les semaines côte à côte sur chaque action | `planche-a.jpg` |
| B | Frise des campagnes, puis la trajectoire cumulée (réel / N-1 / objectif) sur chaque action | `planche-b.jpg` |
| C | Tuiles, puis le jour par jour (barres, ligne N-1, objectif du jour) sur chaque action | `planche-c.jpg` |
| D | A, avec la trajectoire de B sous les semaines, sur chaque action | `planche-d.jpg` |
| **E** | **Retenue : quatre sections — la trajectoire (B) dans la carte de campagne, les semaines (A) sur l'action** | `planche-e.jpg` |

Avant de coder, deux manques côté données :

1. **Le lien action ↔ campagne.** `ceo_visite_action` n'a pas de colonne campagne ;
   le champ `ref` désigne un point de checklist. Il faut une colonne `campagne_id`,
   posée par le consultant quand il rédige l'action (choix dans la liste des
   campagnes qui couvrent le magasin).
2. **L'objectif de clients d'une campagne** vient de `objective_coef_pct` (« + x %
   sur le N-1 ») de la campagne marketing. La campagne B2B d'octobre n'en a pas :
   l'écran le dit et propose un chiffre à partir du budget (budget ÷ panier),
   mais c'est au cockpit de le fixer.

Régénérer : serveur statique sur 8099 depuis la racine, puis
`node docs/maquettes/plan-campagne/generer.js`.
