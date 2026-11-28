# Maquette — le formulaire d'un bureau (26/11/2026)

Sam : « faire un formulaire pour les bureaux (tous les champs nécessaires du client), fais une maquette en screenshot ».
La page publique du magasin (`…/bureau/?m=2`), en six étapes, avec tout ce dont TF Buddy a besoin pour créer le compte
webshop, la société, le bureau, son site et son équipe. Données fictives. `index.html?ecran=societe|livraison|mobile|merci`,
captures : `node panel/docs/maquettes/bureau-formulaire/capturer.js`.

| Étape | Champs (* obligatoire) | Chez TF Buddy (doc test.tfbuddy.com/docs) |
|---|---|---|
| 1. Vous | prénom*, nom*, fonction, téléphone* (E.164), langue | `POST /clients/auth/register` (name, surname, phone, locale) ; contact du bureau |
| 2. Votre compte | e-mail*, mot de passe*, confirmation* ; « déjà un compte » → connexion | `POST /clients/auth/register {id_main_shop, …, password, password_confirm}` : compte actif tout de suite, sans session ; puis `POST /clients/auth/login`. Le mot de passe passe, il n'est ni gardé ni journalisé |
| 3. Votre société | TVA* + pays* (VIES), raison sociale*, secteur, nom du bureau*, e-mail de facturation, adresse de facturation* (rue, n°, CP, localité), bon de commande, qui paie (société / chacun / les deux), logo | `POST /panel/shops/{s}/offices/vies` ; `POST /shops/{s}/clients {vat_number}` (société B2B) ; `onboard.office {name, tax_number, segment, billing_mode, logo_data_url}`. Si un bureau actif a déjà cette TVA : `POST /webshop/offices/search` puis `/{id}/join` — la personne le rejoint aussitôt |
| 4. La livraison | adresse* (suggestions), bâtiment, étage, personnes, consignes d'accès, jours*, fenêtre horaire*, heure limite de commande, remise (QR / signature / dépôt) | `geo/autocomplete|place` ; `GET /webshop/shops/{s}/delivery-zones` (dans une tournée ?) ; hors zone : `POST /webshop/shops/{s}/delivery-interest` ; `onboard.sites`, `office.delivery_days`, `delivery_window_*`, `delivery_validation` |
| 5. Votre équipe | services (puces), collègues (nom + e-mail) | `onboard.departments`, `onboard.staff` ; à la validation `send_membership` ou `POST offices/{id}/members/invite` |
| 6. Récapitulatif | accords (conditions et vie privée*, être recontacté, nouvelles) ; le bon | `GET /webshop/privacy-config` ; le bon : code personnel de la campagne (`/admin/voucher-campaigns/{id}/codes`) |

Le bureau se crée à la validation du magasin (`onboard`, compte du magasin) ; la personne y est reliée (`members`), ses
collègues invités par e-mail.
