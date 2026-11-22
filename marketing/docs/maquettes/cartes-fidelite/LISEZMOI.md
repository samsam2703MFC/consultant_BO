# Cartes de fidélité : qui a quelle carte, et où en est le réseau

Demande du 13/11/2026 (Sam) : « utilise `/loyalty/customers` et `/loyalty/statistics` pour savoir quel client a quelle
carte de fidélité, et au niveau général où on en est avec les cartes de fidélité ; 2-3 maquettes en screenshots ».

**Données fictives.** Le compte API du cockpit répond 403 (Forbidden) sur les deux routes et sur `/admin/loyalty/cards`
dans les quatre magasins (sonde `tools/sonde_cartes_fidelite.php`, 13/11/2026) : il lui manque la permission
`LOYALTY.MANAGE`. Les maquettes suivent la forme exacte des réponses décrite dans `https://test.tfbuddy.com/docs/openapi.json`.

| Maquette | Où | Ce qu'elle lit | Capture |
|---|---|---|---|
| **A** | Dashboard magasin › Fidélité › **Cartes de fidélité** | `/loyalty/statistics` (participants, programs, recognitions, shop_activity) et la répartition des timbres et des points tirée de `/loyalty/customers` | `a-magasin-cartes.jpg` |
| **B** | Dashboard magasin › Fidélité › Cartes › **Qui a quelle carte** | `/loyalty/customers` : chaque client, ses cartes (`programs[].global_progress` : timbres, récompenses prêtes, points, paliers), son activité dans le magasin | `b-magasin-clients.jpg` |
| **C** | Cockpit › ERP franchisé › Fidélité › **Cartes** | `/loyalty/statistics` des quatre magasins côte à côte, et les cartes définies (`/admin/loyalty/cards`) | `c-cockpit-reseau.jpg` |

À retenir des routes (documentation de l'ERP) :
- les soldes de timbres et de points sont ceux de tout le réseau, à l'instant : ils ne s'additionnent pas d'un magasin à l'autre ;
- une « reconnaissance » est un scan réussi, pas un ticket ;
- aucune donnée de contact ni aucun code de carte n'est rendu ; les noms (prénom, nom) le sont.

`generer.py` refait les trois pages depuis `cartes.css`.

## Variantes de A (13/11/2026, « vers le A, refais plusieurs versions »)

Chaque magasin vérifie les cartes **en général** et **client par client** (Sam, 13/11/2026) : sous chaque variante,
le bloc « Par client » (recherche, filtre par carte, récompense prête, inactifs ; chaque client avec ses cartes et où il en est).

| Variante | L'idée | Capture |
|---|---|---|
| **A1 · Entonnoir** | Du client reconnu en caisse à la récompense : reconnus → inscrits → actifs → presque au cadeau → récompenses prêtes ; puis une ligne par carte | `a1-entonnoir.jpg` |
| **A2 · Les cartes** | Chaque carte dessinée comme une carte à tampons : sur chaque case, combien de clients en sont là ; les points par palier | `a2-cartes.jpg` |
| **A3 · À faire aujourd'hui** | Orientée caisse : récompenses à offrir, clients presque au cadeau, clients reconnus à inscrire (par profil) ; puis les chiffres | `a3-a-faire.jpg` |

`generer_a.py` refait les trois variantes (il relance `generer.py`).
