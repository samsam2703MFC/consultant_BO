# Rappels SMS des contrôles qualité — maquette

**Statut : maquette du 17/11/2026, à valider. Rien n'est codé.**

Demande de Sam (17/11/2026) : « dans le système des tâches, si un franchisé n'a pas fait ses contrôles qualité 1 h après
l'heure demandée sur la checklist, on lui envoie un SMS de rappel. Vu qu'on ne commence pas avec tout le monde en même
temps, il me faut un toggle sur les franchisés qui font partie du programme, et me mettre en copie des SMS » ; puis :
« je parle des tâches et checklists du contrôle qualité (photos…) ».

Où : cockpit › Franchisés · évaluation et suivi › Suivi journalier et opérations › **Rappels SMS des contrôles**
(à côté de « Tâches et contrôles photo » et du « Reporting automatisé », qui envoie déjà l'e-mail de 19:00).

| Écran | Capture | Ce qu'il montre |
|---|---|---|
| A · Réglages | `a-reglages.png` | le programme franchisé par franchisé (bascule, GSM et langue de « Qui fait quoi », ce matin, 7 derniers jours), la règle, la copie, le texte du SMS |
| B · Journal | `b-journal.png` | le dimanche 15/11 heure par heure : l'état de chaque checklist au rappel, le SMS, la copie, et ce qui est arrivé ensuite |
| C · Les SMS | `c-sms.png` | ce que reçoivent le franchisé (FR), Halle (NL) et Sam en copie ; l'autre choix : un récapitulatif le soir |

## La règle proposée

- **Ce qui compte** : les checklists du contrôle qualité, c'est-à-dire les tâches **photo obligatoires** du panel
  (`/pwa/tasks`, comme le dashboard et l'e-mail de 19:00). Cette semaine : « 2.2.01 · Chambre froide et étiquetage »
  (06:00, 1 photo ; 11:00 le mercredi), « 1.1.02 · Photos des comptoirs » (07:00, 9 photos), « 1.3.02 · Photos des
  comptoirs (après-midi) » (14:30, 4 photos, le week-end). Chacune s'allume ou s'éteint.
- **Quand** : 60 minutes après l'heure de la checklist (réglable). Une checklist est faite quand **toutes** ses photos
  sont rendues ; **cochée sans photo, elle ne compte pas** (la règle de l'e-mail de 19:00).
- **Au plus 3 SMS par jour et par magasin**, de 06:00 à 21:00, jamais un jour sans tâche. Un SMS par checklist et par
  jour. Le SMS dans la langue du magasin, sans accent hors de l'alphabet SMS (160 caractères = 1 SMS).
- **Le programme** : une bascule par franchisé ; à l'entrée, un SMS de bienvenue. Hors programme, rien ne part.
- **La copie** : à Sam (et à d'autres numéros si on en ajoute) — chaque SMS au moment où il part, ou un récapitulatif à
  21:00.

## Ce que donnent les vraies tâches (11 → 17/11/2026)

| Magasin | Rappels sur 7 jours | Rattrapés | Pourquoi |
|---|---|---|---|
| Corbais | 17 | 0 | aucune photo rendue de la semaine |
| Gosselies | 17 | 0 | les tâches sont cochées sans photo (le 15/11 : tout à 16:57) |
| Halle | 16 | 3 | souvent une seule photo manque (Traiteur le 15/11) ; la chambre froide parfois faite quelques minutes après 07:00 |
| Sombreffe | 17 | 0 | aucune photo rendue de la semaine |

Avec Gosselies et Sombreffe dans le programme : **34 SMS par semaine, et 34 copies chez Sam** — d'où le choix du
récapitulatif du soir.

## Questions ouvertes

1. Le SMS va au **franchisé** (GSM de « Qui fait quoi ») : faut-il aussi, ou plutôt, l'envoyer au **responsable du
   magasin** présent le matin, celui qui prend les photos ?
2. La copie : chaque SMS (34 par semaine pour deux magasins) ou le récapitulatif du soir ?
3. Halle aurait reçu des rappels pour quelques minutes de retard (07:01–07:07) : faut-il une marge, ou décaler l'heure
   de la checklist « Chambre froide » ?

## Données

Tâches réelles du panel, lues en ligne le 17/11/2026 (`/pwa/tasks?date=` du 11 au 17/11). Les numéros de GSM sont
masqués ; les noms des franchisés sont ceux des magasins. Captures : `php -S 127.0.0.1:8099 -t pilotage-reseau` puis
`node pilotage-reseau/docs/maquettes/rappel-sms-controles/capturer.cjs` (`SEUL=a-reglages` pour une seule).
