# Dashboard magasin › Paramètres › Mails et automatisations

**Statut : maquette du 16/11/2026, à valider. Rien n'est codé.**

Demande (16/11/2026) : « Rajouter dans le rail : paramètre. Mail / automatisation (envoi des rapports à qui / template /
fichier PDF / …), faire maquette en screenshot ».

## Les deux écrans

- `a-envois.png` : le rail du dashboard magasin gagne un groupe **Paramètres**, avec **Mails et automatisations**. La page
  montre tout ce qui part tout seul :
  - **Les rapports que reçoit le magasin** : quand ils partent, sous quelle forme (e-mail, PDF joint), à qui, reçus ou
    non, le dernier envoi. Un rapport « 🔒 réseau » est imposé : le magasin choisit à qui il part, pas s'il part.
  - **Qui reçoit quoi** : une ligne par personne du magasin, une colonne par rapport.
  - **Les messages automatiques aux clients** : les trois règles de la boîte mail du magasin (accusé de réception, relance
    d'une offre, rappel la veille de la commande), avec leur modèle et leur délai.
  - Onglets : Rapports reçus, Messages aux clients, Destinataires, Modèles, Journal des envois.
- `b-rapport.png` : un rapport ouvert (Non-conformités de la semaine) :
  - **Quand** : fréquence, jour, heure ; ne pas envoyer s'il n'y a rien à signaler ;
  - **À qui** : les personnes du magasin ou une adresse, le consultant en copie, à qui répondre ;
  - **L'e-mail** : le modèle (le gabarit du réseau), l'objet et l'introduction avec des variables, la langue ;
  - **Le fichier PDF** : joint ou non, son nom (avec variables), son format, sa langue, ce qu'il contient ;
  - **L'aperçu** de l'e-mail tel qu'il partira, avec la pièce jointe ; « Envoyer un essai à moi » ;
  - **Le journal des envois** : envoyé, vide (pas envoyé), refusé puis renvoyé.

Les sept premiers rapports sont ceux que le cockpit envoie aujourd'hui aux magasins (Reporting automatisé, `ceo_rapport`,
lus le 16/11/2026). « Dossier du mois » (le PDF du Résultat, P&L hors TVA) et « CA net du mois » sont des propositions.
Adresses, prénom et contenu d'exemple.

## À trancher avant de coder

- Qui règle quoi : le magasin choisit les destinataires et les rapports non imposés ; le réseau (cockpit › Reporting)
  garde la définition des rapports (blocs, seuils) et ce qui est imposé. À confirmer.
- Le modèle de l'e-mail : un gabarit unique du réseau (logo, couleurs, mentions légales), et le magasin n'écrit que
  l'objet et l'introduction ? Ou des modèles propres au magasin (comme ceux de la boîte mail) ?
- Le PDF : pour quels rapports (aujourd'hui seuls l'affiche, le scoring et le dossier du mois en ont un) ?
- Les deux propositions (dossier du mois au franchisé et au comptable le 2 du mois, CA net au comptable le 1er) : à
  ajouter ?
- Les destinataires du magasin : une liste propre au magasin (`pil_`), ou les comptes de la console franchisé ?

## Fichiers

`coque.js` (le rail du 16/11/2026 et le groupe Paramètres), `mails.css`, `a-envois.html`, `b-rapport.html`. Captures :

```
php -S 127.0.0.1:8099 -t pilotage-reseau
node pilotage-reseau/docs/maquettes/parametres-mails/capturer.cjs
```
