# Dashboard magasin · l'assistant IA

Demande du 12/11/2026 (Sam) : « dans le cockpit du franchisé shop, une IA, avec des questions qu'il peut poser, et
des questions préétablies — comment s'est passée ma semaine ? que puis-je améliorer ? … ; un bouton qui ouvre une
modale ; une ou deux maquettes en capture, ensuite on décide ».

Un bouton **Assistant** dans l'entête du dashboard ouvre une modale. Deux formes :

| | Maquette | Ce qu'elle privilégie | Capture |
|---|---|---|---|
| **A** | **Conversation** : les questions toutes prêtes à gauche, rangées (ma semaine, mes ventes, mon équipe, mes clients) ; à droite l'échange, des chiffres clés en tête de réponse, les questions de suite, un champ libre en bas | poser une question puis creuser ; on garde le fil | `a-conversation.jpg` |
| **B** | **Bilan** : un champ libre en haut, quatre questions en tuiles, la réponse rangée en trois priorités chiffrées, chacune avec un bouton vers l'écran qui agit | lire un verdict et passer à l'action | `b-bilan.jpg` |

`generer.py` refait les deux pages (`assistant.css` pour le style).

## Les chiffres des réponses sont réels

L'Atelier by Halle, lus par l'API du panel le 12/11/2026 : semaine du 2 au 8 novembre (CA 14 255 €, 1 086 tickets,
panier 13,13 €, salaires 18,9 % du CA), contrôles des sept derniers jours, pertes de la semaine en cours, tickets
rattachés à un client. Le texte autour est une rédaction d'exemple.

## Pour construire

- Le cockpit sait déjà appeler l'API Claude (`src/anthropic.php`). L'assistant recevrait les chiffres du magasin
  déjà lus par le dashboard (ventes, P&L, contrôles, pertes, fidélité), jamais d'accès libre à la base.
- Il ne répond que sur le magasin ouvert ; il cite ce qu'il a lu et l'heure de lecture.

## À trancher

- La forme : A (conversation), B (bilan), ou B à l'ouverture et A pour creuser.
- La liste des questions toutes prêtes, et qui la tient (le réseau, au cockpit ?).
- Le franchisé seul, ou aussi le consultant en visite.
