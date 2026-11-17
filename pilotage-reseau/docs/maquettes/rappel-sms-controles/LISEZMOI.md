# Rappels SMS des contrôles qualité — maquette

**Statut : maquette v2 du 17/11/2026 (destinataires et marge réglés par Sam), à valider. Rien n'est codé.**

Demande de Sam (17/11/2026) : « dans le système des tâches, si un franchisé n'a pas fait ses contrôles qualité 1 h après
l'heure demandée sur la checklist, on lui envoie un SMS de rappel. Vu qu'on ne commence pas avec tout le monde en même
temps, il me faut un toggle sur les franchisés qui font partie du programme, et me mettre en copie des SMS » ; puis :
« je parle des tâches et checklists du contrôle qualité (photos…) ».

Où : cockpit › Franchisés · évaluation et suivi › Suivi journalier et opérations › **Rappels SMS des contrôles**
(à côté de « Tâches et contrôles photo » et du « Reporting automatisé », qui envoie déjà l'e-mail de 19:00).

| Écran | Capture | Ce qu'il montre |
|---|---|---|
| A · Réglages | `a-reglages.png` | le programme franchisé par franchisé (bascule, la personne sur place, le franchisé, ce matin, 7 derniers jours), la règle des trois rappels, la copie, le texte des SMS |
| B · Journal | `b-journal.png` | le dimanche 15/11, rappel par rappel, à Sombreffe et à Gosselies : les checklists en retard, à qui le SMS est parti, la copie, ce qui est arrivé ensuite |
| C · Les SMS | `c-sms.png` | ce que reçoivent la personne sur place, la franchisée (3ᵉ rappel) et Sam en copie ; l'autre choix : un récapitulatif le soir |

## La règle (v2, réponses de Sam du 17/11/2026)

Réponses : « le 1er et 2e rappel à la personne sur place, le 3e aux deux » ; « marge de 15 min (variable) ».

- **Ce qui compte** : les checklists du contrôle qualité, c'est-à-dire les tâches **photo obligatoires** du panel
  (`/pwa/tasks`, comme le dashboard et l'e-mail de 19:00) : « 2.2.01 · Chambre froide et étiquetage » (06:00, 1 photo ;
  11:00 le mercredi), « 1.1.02 · Photos des comptoirs » (07:00, 9 photos), « 1.3.02 · Photos des comptoirs
  (après-midi) » (14:30, 4 photos, le week-end). Chacune s'allume ou s'éteint. Une checklist est faite quand **toutes**
  ses photos sont rendues ; **cochée sans photo, elle ne compte pas**.
- **Trois rappels par checklist**, chacun réglable :
  1. **1ᵉʳ rappel** : 60 min après l'heure de la checklist **+ marge de 15 min** (07:15 pour une checklist de 06:00) →
     à la **personne sur place** ;
  2. **2ᵉ rappel** : 60 min plus tard → à la personne sur place ;
  3. **3ᵉ rappel** : 60 min plus tard → à la **personne sur place et au franchisé**. C'est le dernier.
  Une photo arrivée entre deux rappels arrête la suite.
- **La personne sur place** : celle du planning du jour, en poste à l'heure du rappel ; si plusieurs, celle qui a ouvert ;
  son GSM est celui de sa fiche employé (celui du SMS des primes). Personne au planning, ou pas de GSM : le franchisé
  reçoit à sa place. Quand le franchisé ouvre lui-même (Gosselies), il reçoit les trois.
- **Plusieurs checklists en retard au même moment : un seul SMS** qui les cite. **Au plus 8 SMS par personne et par
  jour**, de 06:00 à 21:00, jamais un jour sans tâche. Le SMS dans la langue du magasin, sans accent hors de l'alphabet
  SMS (160 caractères = 1 SMS).
- **Le programme** : une bascule par franchisé ; à l'entrée, un SMS de bienvenue au franchisé et à son équipe. Hors
  programme, rien ne part.
- **La copie** : à Sam (et à d'autres numéros si on en ajoute) — chaque SMS au moment où il part (par défaut, comme
  demandé), ou un récapitulatif à 21:00.

## Ce que donnent les vraies tâches (11 → 17/11/2026), avec la règle v2

| Magasin | Sur place | Au franchisé (3ᵉ rappel) | Checklists rattrapées | Pourquoi |
|---|---|---|---|---|
| Corbais | 37 | 17 | 0 | aucune photo rendue de la semaine |
| Gosselies | 37 | 17 | 0 | les tâches sont cochées sans photo (le 15/11 : tout à 16:57) ; les franchisés ouvrent eux-mêmes |
| Halle | 33 | 15 | 3 | souvent une seule photo manque ; la marge de 15 min évite le rappel du 17/11 (photo à 07:07) |
| Sombreffe | 37 | 17 | 0 | aucune photo rendue de la semaine |

Avec Gosselies et Sombreffe dans le programme : **jusqu'à 108 SMS par semaine (74 sur place, 34 aux franchisés), et
autant de copies chez Sam**. Le dimanche 15/11 : 10 SMS par magasin (journal, écran B).

## Questions ouvertes

1. « 1ᵉʳ, 2ᵉ, 3ᵉ rappel » s'entend ici **par checklist** (trois relances de la même checklist). L'autre lecture — le 1ᵉʳ
   et le 2ᵉ SMS de la journée à la personne sur place, le 3ᵉ de la journée aux deux, un seul rappel par checklist —
   enverrait trois fois moins de SMS (17 par magasin et par semaine) mais ne préviendrait le franchisé que le week-end.
2. La copie : chaque SMS (jusqu'à 108 par semaine pour deux magasins) ou le récapitulatif du soir ?
3. Le GSM des employés : il doit être rempli dans leur fiche (sinon, le franchisé reçoit à leur place).

## Données

Tâches réelles du panel, lues en ligne le 17/11/2026 (`/pwa/tasks?date=` du 11 au 17/11), et les horaires réels du
planning du 15/11 (`/exploitation/jour`). Les prénoms de l'équipe sont d'exemple ; ceux des franchisés sont ceux des
magasins ; les numéros sont masqués. Captures : `php -S 127.0.0.1:8099 -t pilotage-reseau` puis
`node pilotage-reseau/docs/maquettes/rappel-sms-controles/capturer.cjs` (`SEUL=a-reglages` pour une seule).
