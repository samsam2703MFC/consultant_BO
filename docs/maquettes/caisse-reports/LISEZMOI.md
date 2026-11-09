# Caisse : ouvertures, fermetures et reports

> **En service** : la page `public/caisse/` (entrée de rail « Caisse ↗ ») fait
> la même chose sur les données vivantes, avec le choix du magasin et de la
> semaine. Ces pages restent la trace de la maquette.

Maquettes pour la demande : *reconstituer l'ouverture et la fermeture de caisse
avec les mouvements d'espèces, vérifier jour par jour et les reports sur une
semaine*. Deux écrans, une entrée de rail **Exploitation › Caisse**, un
sélecteur Semaine / Jour.

- `a-semaine.html` — **la chaîne des reports** : une rangée par caisse, un bloc
  par jour (fond, déclaré, laissé, qui a ouvert et fermé). Entre deux jours,
  ✓ si le fond du matin suit ce qui a été laissé la veille, ✗ sinon. En dessous,
  le magasin jour par jour : la recette, les moyens de paiement, puis le compte
  des espèces jusqu'à « sorti du tiroir sans trace ». Enfin la liste des points
  à vérifier.
- `b-jour.html` — **une journée** (dimanche 8 novembre) : les services sur une
  frise de 04 h à 08 h le lendemain, la fiche de chaque caisse (report de la
  veille → ouverture → fermeture → laissé → ouverture du lendemain), le compte
  des espèces et les moyens de paiement.

Les PNG du même nom sont les captures (Chromium, 1560 px, ×2).
`node generer.js --captures` régénère les pages et les captures depuis
`donnees.json`.

## Les données sont réelles

Corbais (magasin 2), du dimanche 1er au lundi 9 novembre 2026, lues le
09/11/2026 dans TFBuddy avec les comptes du cockpit. Les employés sont
remplacés par des repères (Employé·e A, B…), stables d'un jour à l'autre. Rien n'est inventé : ce que TFBuddy ne rend pas
(les retraits) est écrit « non lisibles ».

| Donnée | Route TFBuddy | Compte |
|---|---|---|
| Les services d'un jour : ouverture, fermeture, employés, `initial_cash`, `final_cash`, `left_cash_for_next_day`, `is_closed` | `GET /shops/{id}/owner/daily-shifts?date=` | consultant ou admin |
| La caisse d'un service (`id_device`) | `GET /daily-shifts/{id}` | consultant ou admin |
| Le dernier service d'une caisse | `GET /devices/{id}/daily-shifts/latest` | consultant ou admin |
| La recette et les moyens de paiement du jour (Bancontact, Espèce, Sodexo, Monizze, Payonic, Webshop, Versement espèces, Dépôts) | `GET /shops/{id}/reports/sales/daily/cash-flow/csv?date=` | — |
| Les motifs de retrait (Course, Caution, Remboursement client) | `GET /withdrawing-money-reasons` | consultant ou admin |
| Les tickets, avec leur caisse (`id_device`) et leur mode de paiement (`id_payment_type`) | `GET /shops/{id}/transactions?date=` | consultant |
| Les retraits eux-mêmes | `POST /devices/{id}/cash-withdrawals` en écriture seulement — **aucune route de lecture** | — |

Caisses de Corbais : 9 = Traiteur, 11 = Boulangerie.

## Les règles de contrôle

Une seule définition (`jourCaisse()` dans `generer.js`), à reprendre telle
quelle dans le code :

| Point | Règle | Gravité |
|---|---|---|
| Report rompu | fond du premier service du jour ≠ laissé du dernier service de la veille, sur la même caisse | à corriger |
| Montant impossible | laissé > 1 000 € ; « 100 × le total déclaré » quand c'est le cas | à corriger |
| Service jamais fermé | `is_closed = 0` sur un jour passé | à corriger |
| Fermeture sans montant | service fermé avec `final_cash = 0` | à vérifier |
| Fermée puis rouverte | deux services de jour sur la même caisse | à vérifier |
| Rouverte après la fermeture | service ouvert après 17 h et refermé le lendemain | à vérifier |

Le compte des espèces du magasin : fonds d'ouverture + espèces encaissées −
retraits − versements − laissé pour demain = **sorti du tiroir sans trace**.
Non calculable quand un « laissé » est impossible.

## Ce que la semaine du 2 au 8 novembre montre

- **19 points**, dont 9 à corriger : 7 reports rompus, 8 fermetures à 0,00 €,
  2 montants impossibles.
- **Le fond de caisse n'est pas tenu** : 12,00 € un jour, 0,00 € le lendemain.
- **`final_cash` n'est pas l'espèce comptée.** Quand il est saisi, il fait 89 à
  96 % de la recette, alors que les espèces n'en font que 7 %. Les caissières
  reportent un total de caisse.
- **2 231,01 € d'espèces sont sortis des tiroirs sur 6 jours** sans un versement
  ni un retrait enregistré.
- **Dimanche 8** : « laissé » 91 038,00 € et 547 469,00 €, soit 100 × le
  déclaré (virgule oubliée), et la Boulangerie rouverte de 18:18 à 05:50 le
  lendemain avec 2,00 €.

## À trancher avant de coder

1. **`final_cash`** : total de caisse ou comptage du tiroir ? Selon la réponse,
   l'écran l'appelle « total déclaré » et réclame un comptage à part, ou les
   équipes sont formées à y mettre l'espèce comptée.
2. **Les retraits** : à demander à TFBuddy, une route de lecture
   (`GET /devices/{id}/cash-withdrawals` ou les retraits dans `GET /daily-shifts/{id}`).
3. **`id_payment_type`** : la correspondance avec les libellés (2 ≈ carte,
   78 % du CA) n'est exposée nulle part ; l'export comptable donne les totaux
   par moyen, pas par ticket.
4. **Où vit l'écran** : le cockpit (vue réseau, ici) ou le dashboard magasin
   (onglet de plus), ou les deux.
