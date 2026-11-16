# Dashboard magasin › Vendre › Clients : les clients et clients pro du magasin

**Statut : maquette v2 du 16/11/2026, à valider. Rien n'est codé.**

Demande (16/11/2026) : « rajouter une maquette pour les clients et clients pro reliés au magasin (refaire l'entièreté
de leur carte, reliée aux données du Swagger) », en captures d'écran. Puis, sur la v1 : « retravailler la vue du client
(inspire-toi de la fidélité) ; il faut aussi voir si le client a sa carte de fidélité et, dans la liste de clients,
quel est leur niveau de fidélité ».

## Les champs : la fiche client du panel

La fiche se lit (et s'écrit) au panel : `/shops/{id}/clients/{id}`. Ses champs, relevés le 16/11/2026 sur la réponse
réelle (`GET /ventes/tickets?client=1` › `champsFiche`, les noms seuls) :

- **`client.*`** — identité : `name`, `surname`, `company_name`, `client_code`, `public_id` ; contact : `email`,
  `email_norm`, `phone`, `phone_prefix`, `phone_e164`, `locale`, `preferred_auth_method`, `verified_at` ; adresse :
  `street`, `street_number`, `zip`, `city`, `locality` ; facturation : `invoice_name`, `invoice_address`,
  `invoice_postal_code`, `invoice_city`, `invoice_country`, `iban`, `billing_lines`, `payment_terms` ; TVA et Peppol :
  `tax_number`, `peppol_identifier`, `peppol_verified` ; pro : `is_b2b`, `can_deferral`, `personal_discount_percent`,
  `b2b_segment`, `b2b_payment_terms`, `b2b_credit_ceiling`, `b2b_web_discount`, `b2b_franco`, `company_client_id`,
  `department_id`, `office_id`, `office_delivery` ; fidélité et comptes : `fidelity_active`, `fidelity_linked_at`,
  `member_since`, `webshop_user`, `pwa_user`, `last_login_at`, `source_channel`, `needs_profile_completion` ; magasins :
  `id_main_shop`, `preferred_shop_id` ; état : `active`, `blocked`, `status`, `merged_into_id`, `create_timestamp`,
  `updated_at` ; note : `comment`.
- **`profile.*`** (le client chez CE magasin) : `client_type`, `service_mode## Les trois écrans (v2)

- `a-clients.png` : **Vendre › Clients** (entrée nouvelle du rail). En tête, **les niveaux de fidélité** de l'onglet
  Fidélité (Fanbase, Fidèle, Hebdo, Régulier, Occasionnel, Découverte : le rythme des 30 derniers jours, mêmes couleurs),
  chacun avec son nombre de clients et **la part qui a une carte de fidélité**, et une tuile « Carte de fidélité » (62 %
  des clients en ont une ; ceux qui viennent chaque semaine sans carte, à qui la proposer en caisse). La liste : pour
  chaque client **son niveau** (et s'il monte ou descend : « ↑ était Hebdo en octobre »), **sa carte** (timbres 7 / 10,
  points et palier, « récompense prête ») ou « ✕ pas de carte · à lui proposer », comment le joindre (e-mail, SMS,
  langue), son dernier passage et son rythme, ses 12 mois, et pour un pro TVA, Peppol, différé, remise. Onglets Tous,
  Particuliers, Pros, Avec une carte, Sans carte, À compléter, Doublons ; filtres par niveau et par carte.
- `b-fiche-client.png` : la fiche d'un particulier, dans l'esprit de la fiche Fidélité : son niveau sur 30 jours, ses
  passages sur 180 jours, ce qu'il dépense, commande ou comptoir ; **sa carte de fidélité** (la carte à timbres et la
  carte à points, telles que l'ERP les donne, avec ce qu'il lui manque, ses récompenses reçues et la part des tickets
  où la carte a été scannée) ; **son niveau** sur l'échelle des profils et mois par mois (« encore 1 passage par semaine
  pour passer Fanbase ») ; comment il achète ; ses habitudes (jour, moment, ce qu'il prend) ; les radars Récurrence et
  Type d'achat face à la moyenne du magasin ; ses commandes, ses derniers tickets, ses messages. En bas, **sa fiche,
  modifiable par le magasin et enregistrée au panel** (identité, e-mail, téléphone, langue, service, adresse, note, et
  le choix e-mail / SMS pour ses commandes) — un champ modifié se voit (« modifié · était … ») — et ce que le panel garde
  en lecture seule (code, membre depuis, origine, comptes, score, état, dates).
- `c-fiche-pro.png` : la fiche d'un client pro, même tête (niveau Fanbase, passages, 12 mois, encours du mois en
  différé) ; comment il achète, son niveau, son type d'achat face à la moyenne des pros ; **pas de carte de fidélité**
  (sa remise en tient lieu). Puis **ses conditions, modifiables par le magasin et enregistrées au panel** : paiement
  différé, échéance, remise personnelle, remise webshop, franco, livraison au bureau, crédit et sa limite, lignes de
  facture — seul le plafond de crédit du réseau reste en lecture ; la société et sa TVA (contrôlée, clé modulo 97), le
  contact, la facturation, modifiables aussi (la vérification Peppol se lit) ; à droite l'encours du mois en différé
  face à la limite, ses factures en PDF, ses envois.

Noms, numéros et montants d'exemple.

## D'où viennent le niveau et la carte

- **Le niveau** : le profil de l'onglet Fidélité (`src/fidelite_profils.php`) : les seuils et couleurs se règlent au
  cockpit pour tout le réseau (profils de départ : Fanbase 3 fois par semaine, Fidèle 2, Hebdo 1, Régulier 2 à 3 fois
  par mois, Occasionnel 1, Découverte en dessous), calculé sur les tickets reconnus du magasin. Les radars et les
  habitudes sont ceux de la fiche Fidélité (`/fidelite/client`).
- **La carte** : l'inscription et le solde lus dans l'ERP (`src/fidelite_cartes.php`, comme l'onglet Fidélité ›
  Cartes : timbres ou points, palier, récompense prête), et au panel `client.fidelity_active` /
  `client.fidelity_linked_at`.

## Réponses du 16/11/2026

1. Le magasin modifie la fiche du client depuis le dashboard : **oui**, enregistrée au panel.
2. Les conditions d'un client pro : **oui, modifiables** par le magasin (sauf le plafond de crédit fixé par le réseau).
3. « Commande prête » : la commande se marque prête **sur la tablette, pour le moment** : l'automatisation est en gris
   dans la maquette Mails et automatisations.
4. Les automatisations à faire d'abord : dossier comptable du mois, rappel la veille du retrait, commande prête.

## Reste à vérifier avant de coder

- La route d'écriture de la fiche client au panel (`PUT`/`PATCH /shops/{id}/clients/{id}`) et les champs qu'elle
  accepte.
- Le choix e-mail / SMS pour les commandes : gardé au cockpit (table `pil_`), la fiche du panel n'ayant pas ce champ.
- La liste : tous les clients venus au moins une fois au magasin, ou seulement ceux qui ont un `profile` chez lui.

s commandes : gardé au cockpit (table `pil_`), la fiche du panel n'ayant pas ce champ.
4. La liste : tous les clients venus au moins une fois au magasin, ou seulement ceux qui ont un `profile` chez lui ?

## Fichiers

`a-clients.html`, `b-fiche-client.html`, `c-fiche-pro.html`, `clients.css`, `clients.js` (les niveaux, les cartes,
le radar et « Comment il achète » repris du dashboard ; avec `../parametres-mails/mails.css` et
`../parametres-mails/coque.js`, le rail). Captures :

```
php -S 127.0.0.1:8099 -t pilotage-reseau
node pilotage-reseau/docs/maquettes/clients-magasin/capturer.cjs
```
