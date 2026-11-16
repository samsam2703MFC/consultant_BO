# Dashboard magasin › Paramètres › Mails et automatisations

**Statut : maquette v2 du 16/11/2026, à valider. Rien n'est codé.**

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
- `b-comptable.png` : le dossier comptable du mois ouvert. Quand (le 1er jour ouvré, 07:00, pour le mois clos, en
  attendant que le mois soit lu en caisse), à qui (le comptable, copie à moi), l'e-mail (objet et message avec
  variables), les fichiers joints : le **PDF** du dossier (CA TTC, hors TVA et TVA jour par jour, TVA par taux, frais avec
  leur TVA, ventes pro à facturer, corrections après encaissement) et l'**Excel** des ventes jour par jour. L'aperçu, un
  essai, « Envoyer maintenant » pour un mois passé, le journal.
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

## À trancher avant de coder

1. Les automatisations à faire d'abord (proposition : dossier comptable du mois, rappel la veille du retrait).
2. Le dossier comptable : son contenu exact avec le comptable (PDF, Excel, ou les deux ; le P&L dedans ou non).
3. Les clients sans e-mail : seulement prévenir le magasin, ou aussi un SMS ?
4. Qui peut régler ces envois : le franchisé seul, ou aussi le consultant ?

## Fichiers

`coque.js` (le rail du 16/11/2026 et le groupe Paramètres), `mails.css`, `a-automatisations.html`, `b-comptable.html`,
`c-rappel-commande.html`. Captures :

```
php -S 127.0.0.1:8099 -t pilotage-reseau
node pilotage-reseau/docs/maquettes/parametres-mails/capturer.cjs
```
