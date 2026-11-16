# Dashboard magasin › Paramètres › Mails et automatisations

**Statut : maquette v3.1 du 16/11/2026, à valider. Rien n'est codé.**

Demandes (16/11/2026) : « Rajouter dans le rail : paramètre. Mail / automatisation (envoi des rapports à qui / template /
fichier PDF / …), faire maquette en screenshot », puis, sur la v1 : « c'est aussi pour envoyer les rapports aux comptables
en fin de mois, ou les commandes aux clients le jour avant… refaire la maquette ».

La v1 montrait les rapports que le réseau envoie au magasin. La v2 montre ce que **le magasin envoie tout seul vers
l'extérieur** : son comptable, ses clients, ses clients pro, son équipe, lui-même. Les rapports reçus du réseau restent
dans un onglet à part (« Reçus du réseau »).

## Les trois écrans

- `a-automatisations.png` : le rail gagne un groupe **Paramètres** › **Mails et automatisations**. La liste des
  automatisations, rangées par destinataire, avec pour chacune quand elle part, à qui, ses pièces jointes (PDF, Excel),
  son état et son dernier envoi :
  - **Comptable** : dossier comptable du mois (le 1er jour ouvré) ; relevé des encaissements de la semaine ;
  - **Clients** : confirmation de commande, rappel la veille du retrait, merci et avis Google le lendemain ;
  - **Clients pro** : relevé mensuel des achats différés ;
  - **Équipe** : planning de la semaine prochaine ;
  - **Moi** : résumé de la journée.

  En dessous : les prochains envois (ce qui partira tout seul, et à qui), et « Nouvelle automatisation » à partir d'un
  modèle (comptable, clients d'une commande, clients pro, équipe, fournisseur, moi).
- `b-comptable.png` : le dossier comptable du mois ouvert (v3) : il part dès que les factures du mois sont faites ;
  les revenus du mois en PDF et les factures du mois en PDF ; la liste des factures du mois (faites, à faire) ;
  l'aperçu, un essai, « Envoyer maintenant » pour un mois passé, le journal.
- `c-rappel-commande.png` : le rappel la veille du retrait. Quand (la veille à 17:00, seulement les commandes
  confirmées), à qui (le client de la commande, l'e-mail de sa fiche ; prévenir s'il n'en a pas), l'e-mail avec ses
  variables (client, date et heure de retrait, articles, acompte, reste à payer, adresse), le **bon de commande en PDF**,
  l'aperçu tel que le client le recevra, et la liste des envois du soir (les commandes à retirer demain).

Noms, adresses, commandes et chiffres d'exemple.

## Ce qui existe déjà au cockpit, et ce qui manque

| Envoi | Les données | Ce qu'il faut ajouter |
|---|---|---|
| Dossier comptable du mois | CA hors TVA et TVA ticket par ticket, TVA par taux (P&L hors TVA, 16/11), frais et leur TVA (onglet Frais), ventes pro différées (B2B), corrections (Vérification), le CA net du mois (`cnMois`) | le PDF du dossier, l'Excel, l'envoi |
| Confirmation, rappel, merci | les commandes clients du panel (jour et heure de retrait, client, acompte) ; la boîte mail du magasin envoie déjà le « rappel la veille » (règle `rappel`, pour les offres converties) | le bon de commande en PDF, l'élargir à toutes les commandes |
| Relevé des achats différés | les tickets pro en paiement différé et la remise personnelle (`vpFiltreTva`) | le PDF du relevé, l'envoi par client |
| Planning de la semaine | le planning du panel (`/shops/{id}/schedule`) | le PDF, l'envoi à chaque personne |
| Résumé de la journée | la revue du soir, le Résultat du jour | l'e-mail |

L'envoi part de la **boîte mail du magasin** (src/franchise/boite_mail.php, son SMTP) : les réponses des clients et du
comptable y arrivent.

## v3 (16/11/2026) : les réponses

- « Le dossier compta, ce sont les revenus mensuels et le fichier PDF, disponible parce que les factures ont été
  faites » : le dossier part **dès que les factures du mois sont faites** (au plus tard le 5, en le disant s'il en
  manque), avec **les revenus du mois** (TTC, hors TVA, TVA par taux, jour par jour, comptoir et pro séparés) et **les
  factures du mois en PDF** (un seul PDF ou un par facture). Plus d'Excel.
- « Choix un ou l'autre ou les deux » : chaque envoi aux clients part **par e-mail, par SMS ou les deux** ; un client
  sans e-mail reçoit le SMS seul, et l'inverse.
- « Envoyer un SMS quand la commande est prête (relié avec ses clients) » : nouvelle automatisation **Commande prête**,
  un SMS au téléphone de la fiche du client dès que la commande est marquée prête.
- « SMS par l'API SMS » : les SMS partent par l'API SMS du cockpit (`src/sms.php`, Twilio, déjà utilisée pour les primes
  des vendeuses ; réglée dans `config.php`).

## v3.1 (16/11/2026) : les réponses

- Les automatisations à faire d'abord : **dossier comptable du mois, rappel la veille du retrait, commande prête**.
- « Commande prête » : la commande se marque prête **via la tablette pour le moment** ; l'automatisation est en gris
  (« à venir ») dans la liste et dans l'aperçu SMS, en attendant.

## Reste à trancher

- Qui peut régler ces envois : le franchisé seul, ou aussi le consultant ?

## Fichiers

`coque.js` (le rail du 16/11/2026 et le groupe Paramètres), `mails.css`, `a-automatisations.html`, `b-comptable.html`,
`c-rappel-commande.html`. Captures :

```
php -S 127.0.0.1:8099 -t pilotage-reseau
node pilotage-reseau/docs/maquettes/parametres-mails/capturer.cjs
```
