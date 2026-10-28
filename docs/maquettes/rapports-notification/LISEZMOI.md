# Rapports automatisés : par mail ou par notification

**Statut : retour du 28/10/2026 maquetté (ci-dessous) ; le codage attend la lecture de l'API
des notifications du panel.**

## Retour du 28/10/2026

« Rajouter l'option SMS. Multibouton : Notification, SMS, Mail. C'est seulement une notification.
À valider et écrire dans le log (voir API notification atelierby.tfbuddy.com). »

| Capture | Ce qu'elle montre |
|---|---|
| `retour-reporting.png` | le compositeur : **Envoyer par** en multibouton (🔔 Notification, 💬 SMS, ✉ Mail, plusieurs à la fois) ; la priorité de la notification ; **le magasin doit la valider** ; le texte du SMS (116 / 160 caractères, un SMS par magasin) ; la copie email du consultant |
| `retour-liste.png` | la liste des rapports : le multibouton sur chaque rapport, et sous les rapports notifiés, **Validé** : chaque magasin, validé (quand) ou à valider, lu dans le log de la dernière notification |
| `retour-dashboard.png` | le magasin : seulement une notification, un bandeau en tête de l'onglet Opérationnel, « À valider », avec « Ouvrir le rapport » et **✓ Valider** ; elle reste affichée tant qu'elle n'est pas validée |
| `retour-dashboard-valide.png` | après « Valider » : la notification se referme sur l'heure de la validation, écrite dans le log |

Les heures de validation de Corbais et Gosselies sont des exemples.

Ce qui reste à lire dans l'API du panel (atelierby.tfbuddy.com) avant de coder — l'hôte n'est
pas joignable depuis l'environnement où les maquettes ont été faites, et le dépôt ne documente
que `POST /notifications` et `GET /shops/{id}/notifications` :

- la route qui **valide** une notification pour un magasin, et celle qui **lit son log** ;
- l'envoi des **SMS** : le panel en envoie-t-il (route, fournisseur) ? Le cockpit n'a aucun
  fournisseur SMS branché (`src/newsletter_envoi.php`) ;
- le **numéro** du magasin à utiliser.

## Les quatre premières variantes

Demande du 28/10/2026 : dans les rapports automatisés, ajouter un interrupteur **Mail /
Notification**. En notification, l'envoi passe par l'API existante et la notification se montre
sur le **dashboard opérationnel** du magasin.

L'API existante, c'est celle du panel : `POST /notifications` (titre, message, priorité, magasins
visés, période de visibilité, lien d'action, objet source), déjà utilisée par les relances de
commandes (`src/ca_mail.php`, `wr_ca_relance_franchise`). Le dashboard lit déjà les messages d'un
magasin par `GET /ventes/notifications?shop=` (`src/stats_ventes.php`), qui relit
`/shops/{id}/notifications` du panel. Une notification de rapport y passe donc d'elle-même, avec
`source_type = rapport`.

## Les quatre

| | Côté cockpit (Reporting) | Côté magasin (dashboard opérationnel) | Ce que ça coûte |
|---|---|---|---|
| **A** un bandeau | `a-reporting.png` : « Envoyer par » Mail / Notification dans le compositeur ; en notification, les adresses par magasin laissent la place aux magasins notifiés, à la durée de visibilité et à la priorité ; le consultant garde sa copie par email | `a-dashboard.png` : un bandeau en tête de l'onglet, entre la semaine et l'objectif du jour : le rapport, ses points par levier, « Ouvrir le rapport », « Lu » | un rapport à la fois ; pas d'historique ; le détail reste dans le PDF |
| **B** une boîte « Rapports reçus » | `b-reporting.png` : l'interrupteur sur chaque ligne de la liste des rapports récurrents ; « Notifier » à la place d'« Envoyer » | `b-dashboard.png` : une carte après le duel chiffre par chiffre, les rapports des 30 derniers jours, nouveaux en tête, lus et non lus | une carte de plus ; les points restent dans le PDF |
| **C** une liste à cocher | `c-reporting.png` : Mail, Notification ou Les deux ; relance à J+2 si rien n'est coché ; l'aperçu de ce que reçoit le magasin | `c-dashboard.png` : sous les tuiles du moment, chaque point du rapport devient une ligne à cocher « Fait », par levier, avec l'avancement (2 / 7) | le plus de développement ; prend de la place en haut du dashboard |
| **D** une cloche | `d-reporting.png` : l'interrupteur, et « Où » : cloche du dashboard, cloche du panel, téléphone (push) | `d-dashboard-ferme.png` : une cloche « Messages » et ses non lus à côté de « Relire » ; `d-dashboard.png` : le tiroir, avec les rapports et les autres messages du panel | se voit moins ; le push dépend des appareils abonnés |

`planche.html` / `planche.jpg` : les quatre côte à côte. Ce qui est nouveau porte un liseré doré
pointillé ; le reste est l'écran du jour.

## Ce qui est commun aux quatre

- Le canal se garde sur le rapport (`ceo_rapport`, une colonne de plus), avec ses réglages de
  notification (visibilité, priorité, et pour D les écrans).
- À l'envoi, en mode « un par magasin », chaque magasin qui a de la matière reçoit **sa**
  notification (`shops: [id]`), qui pointe sa génération (`source_type: rapport`,
  `source_id: run`) et son PDF. Un rapport sans matière ne part pas, comme aujourd'hui par mail.
- La version complète part toujours par email au consultant (le champ « destinataires réseau »
  d'aujourd'hui).
- `/ventes/notifications` rend aujourd'hui `source` mais pas `source_id` : à ajouter pour que le
  dashboard retrouve la génération.

## À trancher en plus du choix

- **Le lien du PDF** : `/api/cockpit/rapports/run/{id}/pdf` passe par l'authentification du
  cockpit. Depuis la cloche du panel, un franchisé sans accès au cockpit ne l'ouvrira pas ; il
  faudra un lien signé par génération.
- **« Lu »** : rien dans ce qui est lu du panel ne dit si une notification a été lue. Le « Lu »
  du dashboard se garderait au cockpit, par magasin et par génération.
- **Le téléphone** : le dashboard a sa version téléphone ; les maquettes ne montrent que le
  bureau.

## Données

- Le magasin : Atelier by - Halle (shop 4), le mardi 6 octobre 2026 à 10:45. La vitrine est le
  suivi de production réel de ce moment (`../suivi-duree-de-vie/reel-suivi-halle-0610.json`) ;
  l'équipe, les commandes, le stock, la veille et les photos du matin sont ceux relevés le même
  jour (`../dashboard-operationnel/reel-halle-0610.json`). Le chiffre d'affaires de 08:00 à
  10:45 et celui du lundi 5 sont reconstitués : le relevé s'arrête à 08:14. La valeur du magasin
  n'est pas relevée (« — »).
- Les rapports : les quatre rapports récurrents de la liste et leurs blocs sont écrits pour la
  maquette (`donnees.js`) ; les sept points de la notification de Halle aussi, dans la forme des
  blocs du rapport franchisé. La commande #4821 du tiroir (D) est un exemple. Aucun nom de
  client ni de membre de l'équipe.
- Les écrans sont ceux du dépôt, servis tels quels ; seule l'API est simulée.

## Régénérer

```
node docs/maquettes/rapports-notification/capturer.js
```

Le script sert le dépôt lui-même sur `127.0.0.1:8099`, met le navigateur à l'heure du magasin,
simule l'API (`donnees.js`, `banc.js`) et greffe les variantes (`variantes.js`) une fois l'écran
rendu.
