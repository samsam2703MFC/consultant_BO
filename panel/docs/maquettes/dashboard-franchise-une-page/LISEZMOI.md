# Espace franchisé · Tableau de bord, tout sur une page

Demande du 12/11/2026 : « une maquette avec tout sur une page ». Le franchisé ouvre une seule page et voit
son magasin entier, de haut en bas, du plus urgent au plus lent :

1. **Ce qui demande un geste aujourd'hui** : cinq cases (contrôles, commande, pertes, planning, fidélité).
2. **Les chiffres clés** : CA, tickets et panier d'hier, la semaine, le mois.
3. **Le mois jour par jour** et **le réseau hier** (le magasin du franchisé en couleur).
4. **Les ratios du mois face aux cibles**, **ce qui se vend**, **les pertes**.
5. **Les contrôles du jour** (et les 7 derniers jours), **les commandes clients**, **l'équipe**.
6. **La fidélité** en une ligne (l'écran détaillé : `../fidelite-franchise/`).

`index.html` (1440 de large, design system commun `docs/DESIGN_SYSTEM.md`), capture `dashboard-franchise.jpg`.

## Les chiffres sont réels

L'Atelier by Halle (magasin 4), lus le jeudi 12/11/2026 vers midi par l'API du panel, sans aucune table
locale, par `tools/sonde_dashboard_halle.php` (et `tools/sonde_fidelite_halle.php` pour la fidélité) :

| Bloc | Route |
|---|---|
| CA, tickets, panier | `/shops/4/statistics/sales/kpis`, `/consultant/shops/sales-kpis` (réseau) |
| Cibles, salaires, frais fixes | `/consultant/shops/4/targets`, `/consultant/shops/4/pnl/daily` |
| Catégories | `/consultant/shops/category-sales` |
| Pertes | `/shops/4/products/waste` |
| Commandes | `/shops/4/client-orders` |
| Contrôles | `/consultant/shops/4/tasks` |
| Planning | `/shops/4/schedule` |

## Ce que les chiffres ont montré

- Le **coût matière n'est pas renseigné** (`material` à 0, `cost_unknown` dans la heatmap) : ni marge ni
  résultat ce mois. La maquette le dit au lieu d'afficher un résultat à zéro.
- Les **cibles** sont celles du réseau par défaut : aucune cible propre à Halle.
- Le **planning d'aujourd'hui** totalise 72 h pour 3 postes : un horaire est sans doute mal saisi.
- Les **contrôles** tournent entre 0 et 34 % sur sept jours ; à midi, aucune tâche du jour n'est cochée.

## À trancher

- Pas d'objectif de CA en euros dans l'API (les cibles sont des ratios) : la page n'en invente pas.
- Le bouton principal ouvre les contrôles du jour : c'est le geste le plus en retard ce jour-là. Il pourrait
  suivre la première case de « Ce qui demande un geste ».
