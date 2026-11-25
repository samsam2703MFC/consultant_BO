# Clients — le module « Klienci » du panel TF Buddy dans le dashboard franchisé (maquettes)

**Statut : codé le 25/11/2026** (« let's go and code it all ») : `panel/src/clients_panel.php`,
`panel/public/dashboard/clients.js`, `clients.css`, Vendre › Clients dans le rail. Par les seuls comptes du cockpit
(consultant, puis admin : « use either all token available from consultant or admin »). Les captures `code-*.png` sont
le vrai écran au banc (`banc/`, TF Buddy simulé, données fictives) :

| Capture | Ce qu'elle montre |
|---|---|
| `code-b2b.png`, `code-particuliers.png`, `code-analyse.png`, `code-bureaux-refus.png`, `code-mobile.png` | aujourd'hui : les routes `/panel/shops/{s}/…` refusées, l'écran se rabat (entreprises sur `/shops/{s}/clients` + relevé des tickets pro ; analyse sur le relevé) et dit ce qui manque |
| `code-b2b-r1.png`, `code-bureaux-r1.png`, `code-ajout-r1.png`, `code-analyse-r1.png` | avec l'accès de service (R1) : score, crédit, récurrence, les bureaux, l'assistant en sept étapes, le tableau de bord de TF Buddy |

Régénérer : `php -S 127.0.0.1:8098 panel/docs/maquettes/clients-panel/banc/banc.php &`,
`BANC_ACCES=panel php -S 127.0.0.1:8099 panel/docs/maquettes/clients-panel/banc/banc.php &`, puis
`node panel/docs/maquettes/clients-panel/banc/voir.cjs`.

Les petits bugs du paquet sont corrigés pour TF Buddy dans `correctifs-tfbuddy/` (un patch `git` et son mode d'emploi).

Ci-dessous, les maquettes d'origine (`ecran-*.png`).

Demande du 25/11/2026 : « fais les maquettes concernant le nouveau module à intégrer dans le panel dashboard des
franchisés », avec le paquet `panel-klienci.rar` (README polonais : « Panel – menu Klienci », commit 772a05f ; 204
fichiers, sommes SHA-256 vérifiées). Le paquet contient les écrans, routes, contrôleurs, services, dépôts, traductions et
gabarits du menu Klienci du panel : Klienci indywidualni (`/clients/b2c`), Firmy B2B (`/b2b/clients`), Biura
(`/offices`), Oferty (`/Offer/manage`), Analiza klientów B2B (`/analysis/b2b`).

## Ce que propose la maquette

- **Une entrée de rail, Vendre › Clients**, et quatre onglets : Particuliers · Entreprises B2B · Bureaux · Analyse B2B
  (le §2 du design system : un écran à plusieurs vues = des onglets, pas cinq entrées de menu).
- **Les offres restent la page Offres du dashboard** (`offres.js`, la même API « Client offers » de TF Buddy) :
  « Préparer une offre » y mène depuis chaque fiche, au lieu de refaire l'écran Oferty.
- **Le design system du cockpit** à la place de Bootstrap / Mazer / Choices / simple-datatables / Chart.js ; les mêmes
  routes de l'API et les mêmes noms de champs (le README du paquet le demande : « Zachowaj nazwy pól, trasy i CSRF »).

| Fichier | Écran |
|---|---|
| `ecran-b2b.png` | **Entreprises B2B** : filtres (scoring A–D, CA 12M, nombre de commandes), les entreprises à compléter en tête, la liste (score, type · mode, fréquence, CA 12M, crédit), la fiche à côté (KPI, crédit, la chaîne société › bureau › site › tournée, dernières commandes, « Préparer une offre ») |
| `ecran-bureaux.png` | **Bureaux** : une ligne par bureau (statut, catalogue, offres, personnes, tournée), la fiche en onglets Livraison · Catalogue · Offres · Personnes · Documents, la livraison d'abord (site, tournée, créneau, heure limite, remise, jours) |
| `ecran-ajout.png` | **Ajouter un bureau** : l'assistant en 7 étapes du panel (Société, Contacts, Sites, Départements, Webshop, Personnes, Récapitulatif), étape Sites ; à droite, ce que l'on crée |
| `ecran-analyse.png` | **Analyse B2B** : six chiffres du mois, chacun face au mois d'avant et au même mois l'an dernier, les ventes mois par mois (2026 face à 2025), la part B2B du chiffre |
| `ecran-particuliers.png` | **Particuliers** : la recherche d'abord, la fiche à côté (reliée à la Fidélité), « Passer en entreprise » (TVA vérifiée dans VIES) |

Données fictives (les bureaux de `../commandes-tournees/donnees.js`), magasin 3, Gosselies.

## Les routes de l'API reprises (relevées dans le paquet)

- **Particuliers** : `GET /shops/{shop}/clients?type=b2c`, `POST /shops/{shop}/clients` (nom et téléphone
  obligatoires), `PATCH|DELETE|GET /clients/{id}`, `GET /clients?vat_id=&vies_check=true` (passer en entreprise).
- **Entreprises B2B** : `GET /panel/shops/{shop}/b2b/clients?type=b2b&with_profile=1…`, `…/{id}`, `…/{id}/profile`,
  `…/{id}/orders`, `…/{id}/attributes`, `PATCH …/{id}`, `POST …/vat-lookup`, `…/vies-fetch`, `POST …/b2b/clients`,
  `GET /panel/shops/{shop}/b2b/dictionaries`.
- **Bureaux** : `/panel/shops/{shop}/offices{…}` (liste, options, onboard, vies, assortment, offers, members, sites,
  departments, contacts, vouchers, staff, invite, delivery-settings, print-batches, geo), les PDF poster / cards /
  brochure.
- **Analyse B2B** : `GET /panel/shops/{shop}/b2b/dashboard?year=&month=`.

**Accès** : ces routes sont, comme les livraisons, sous `/panel/shops/{shop}/…` ; mesuré le 24/11/2026 sur les
livraisons, le jeton consultant et le jeton admin du cockpit y sont refusés (404 `ORDER_NOT_FOUND`, pas de SHOP scope),
un jeton de membre du magasin passe. Coder ce module demande donc le même accès de service que l'appli du livreur
(R1 de `../../livreur/CAHIER_DES_CHARGES_TFBUDDY.md`).

## Relevé dans le paquet, à signaler à TF Buddy (corrigé dans `correctifs-tfbuddy/`)

- `views/b2b/clients/index.twig:2455` : l'enregistrement du profil commercial appelle `profileResp.json()` sur un objet
  déjà lu (`api()`) : erreur, « erreur de connexion ».
- `views/analysis/b2b_dashboard.twig:404` : changer de métrique réaffiche les données du premier affichage.
- `views/client/offer/new.twig:301,306` : envoi vers `/ajax/client-offers` (la route est `/ajax/client-offers/new`) et
  `response.json()` sur l'objet de `api()`.
- CSRF : vérifié pour la création d'un particulier et les PDF poster / cartes ; pas pour les AJAX B2B, les offres,
  `data()` et `brochure()` des bureaux ; `data-csrf` vide sur les pages catalogue, offres et membres d'un bureau.
- Formats `pl-PL` écrits en dur dans l'analyse B2B ; le modèle d'e-mail d'offre n'a pas l'italien alors que le PDF le propose.

## Régénérer

```
node panel/docs/maquettes/clients-panel/capturer.js
```

`index.html?v=b2b`, `?v=bureaux`, `?v=ajout`, `?v=analyse`, `?v=particuliers`.
