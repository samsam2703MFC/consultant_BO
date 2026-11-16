# Dashboard magasin › Vendre › Clients : les clients et clients pro du magasin

**Statut : maquette du 16/11/2026, à valider. Rien n'est codé.**

Demande (16/11/2026) : « rajouter une maquette pour les clients et clients pro reliés au magasin (refaire l'entièreté
de leur carte, reliée aux données du Swagger) », en captures d'écran.

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
- **`profile.*`** (le client chez CE magasin) : `client_type`, `service_mode`, `credit_enabled`, `credit_limit`, `score`,
  `id_b2b_client_type`, `id_b2b_client_sector`, `status`, `joined_at`, `detached_at`.

Sur les fiches, chaque valeur porte en petit le champ d'où elle vient.

## Les trois écrans

- `a-clients.png` : **Vendre › Clients** (entrée nouvelle du rail). Les clients reliés au magasin, particuliers et pros :
  comment les joindre (e-mail, téléphone, langue), fidélité, app, webshop, dernière visite et fréquence, 12 mois, et pour
  un pro TVA, Peppol, différé, remise. Onglets Tous, Particuliers, Pros, À compléter, Doublons ; recherche par nom,
  société, e-mail, téléphone, n° de TVA.
- `b-fiche-client.png` : la fiche d'un particulier : identité et contact, adresse, le lien avec le magasin (profil),
  fidélité et comptes, notes et état de la fiche ; ce que le magasin en voit : ses achats (tickets), ses commandes, ses
  messages (e-mail, SMS) ; « Enregistrer au panel ».
- `c-fiche-pro.png` : la fiche d'un client pro : la société et sa TVA (contrôlée, clé modulo 97), Peppol, le contact, la
  facturation, les conditions (différé, remises, franco, crédit) ; l'encours du mois en différé face à la limite de
  crédit, ses factures en PDF, ses envois (relevé mensuel, rappels).

Noms, numéros et montants d'exemple.

## À trancher avant de coder

1. Le magasin modifie-t-il la fiche au panel depuis le dashboard (route d'écriture à confirmer dans le Swagger), ou la
   lit-il seulement ?
2. Les champs pro (crédit, plafond, remise, échéance) : modifiables par le magasin, ou réservés au réseau ?
3. Le choix e-mail / SMS pour les commandes : gardé au cockpit (table `pil_`), la fiche du panel n'ayant pas ce champ.
4. La liste : tous les clients venus au moins une fois au magasin, ou seulement ceux qui ont un `profile` chez lui ?

## Fichiers

`a-clients.html`, `b-fiche-client.html`, `c-fiche-pro.html`, `clients.css` (avec `../parametres-mails/mails.css` et
`../parametres-mails/coque.js`, le rail). Captures :

```
php -S 127.0.0.1:8099 -t pilotage-reseau
node pilotage-reseau/docs/maquettes/clients-magasin/capturer.cjs
```
