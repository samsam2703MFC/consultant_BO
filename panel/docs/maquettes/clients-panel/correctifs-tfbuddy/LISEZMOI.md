# Correctifs pour TF Buddy — le module « Klienci » du panel

**25/11/2026.** Demande de Sam : « fix all the little bugs », relevés en lisant le paquet `panel-klienci.rar` (README
polonais « Panel – menu Klienci », commit 772a05f). Le code est celui de TF Buddy : le cockpit ne peut pas le changer.
Ce dossier contient donc les corrections, prêtes à transmettre (Szymon) :

- `klienci-correctifs.patch` : un patch `git` sur le paquet (chemins `panel/…`, fins de ligne CRLF respectées).
  Vérifié : `git apply --check` passe sur le paquet tel que reçu, `php -l` passe sur le contrôleur modifié.

```bash
git apply klienci-correctifs.patch
```

Le module Clients du dashboard (`panel/src/clients_panel.php`, `panel/public/dashboard/clients.js`) est réécrit et
n'a aucun de ces défauts. Il ne dépend pas du patch.

## Ce que corrige le patch

| # | Fichier | Le défaut | La correction |
|---|---|---|---|
| 1 | `views/b2b/clients/index.twig:2455` | Enregistrer le profil commercial (score, type, mode, crédit) appelle `profileResp.json()` sur l'objet que `api()` (`modules/api.js`) a déjà lu : `TypeError`, l'écran affiche « erreur de connexion » et le cache local n'est pas mis à jour (la sauvegarde, elle, est partie). | Le résultat d'`api()` est utilisé tel quel (`{ok, status, data, ...payload}`). |
| 2 | `views/analysis/b2b_dashboard.twig:404` | Changer de métrique (ventes, tickets, clients, part) redessine les données du **premier affichage** (`B2B.data`), pas celles de l'année choisie ensuite ; « Appliquer » ne met pas `B2B.data` à jour non plus. | Le graphique garde les dernières données lues (`b2bChartData`) ; « Appliquer » met `B2B.data` à jour. |
| 3 | `views/analysis/b2b_dashboard.twig:167,169` | Montants formatés en `pl-PL` quelle que soit la langue du panel. | La langue de la page (`<html lang>`, posée par `layouts/base.twig`). |
| 4 | `views/client/offer/new.twig:301` | Créer une offre envoie `POST /ajax/client-offers` ; la route est `POST /ajax/client-offers/new` (`core/Routes/Client/OfferRoutes.php:26`) : 404. | La bonne route. |
| 5 | `views/client/offer/new.twig:306` | En cas d'erreur, `response.json()` sur l'objet d'`api()` : `TypeError`, l'erreur réelle est perdue. | L'erreur se lit dans `response.data`. |
| 6 | `controllers/Office/OfficeController.php` (`catalog`, `offers`, `members`) | Les pages Catalogue, Offres et Membres d'un bureau rendent `data-csrf=""` : le jeton n'est pas passé à la vue. | `'csrf' => FranchiseCsrf::token()` comme `index()`. |
| 7 | `controllers/Office/OfficeController.php` (`data()`, `brochure()`) | Les écritures des bureaux (POST, PATCH, PUT, DELETE) et la brochure ne vérifient pas le jeton CSRF, alors que les écrans l'envoient déjà (`_csrf`) et que l'affiche et les cartes le vérifient. | `FranchiseCsrf::validate($data['_csrf'])`, 403 sinon (`OfficeService::write` retire déjà `_csrf` avant l'API). |
| 8 | `public/assets/js/office-members.js:25` | **Retirer une personne d'un bureau ne marche pas** : le `DELETE` part sans corps ; `OfficeController::data()` décode le JSON de toute écriture (`JSON_THROW_ON_ERROR`) : 422. | Toute écriture porte un corps (au moins `_csrf`). Nécessaire aussi au n° 7. |
| 9 | `views/email/template/offer.twig:32-35` | Le modèle d'e-mail d'offre propose fr, nl, en, pl, mais pas l'italien, alors que le PDF de l'offre l'accepte (`OfferController::getOfferDocument` : `pl, fr, nl, en, it`). | L'option `it` (libellé `translations.italian`, « Italiano » par défaut). **À vérifier côté TF Buddy** : que l'API ait le texte italien du modèle. |

## Ce qui reste à faire chez TF Buddy (hors patch)

- **CSRF des AJAX B2B et des offres** (`/ajax/b2b/clients/*`, `/ajax/client-offers/*`) : `modules/api.js` n'envoie
  pas de jeton et les contrôleurs (`B2B/ClientController`, `Client/OfferController`) n'en vérifient pas. Il faut
  modifier les deux côtés ensemble. Côté navigateur, le jeton passe dans un en-tête (`X-CSRF-Token`, lu dans une
  `<meta>` du gabarit). Côté contrôleurs, il est vérifié par `FranchiseCsrf::validate()` sur chaque POST, PATCH et
  DELETE. Le patch ne le fait pas : `api.js` sert à tout le panel.
- **L'accès du cockpit (R1)** : les routes `/panel/shops/{shop}/b2b/*` et `/panel/shops/{shop}/offices*` refusent les
  comptes consultant et admin du cockpit (404 `ORDER_NOT_FOUND`, mesuré le 24/11/2026). Le module Clients du dashboard
  les tente à chaque lecture et dit à l'écran ce qui manque. Voir `panel/docs/livreur/CAHIER_DES_CHARGES_TFBUDDY.md`, R1.

## In short (for Szymon)

`klienci-correctifs.patch` fixes nine bugs in the Klienci package:
- the B2B profile save (`.json()` on `api()` result);
- the B2B dashboard chart, which kept the first data on metric change;
- hard-coded `pl-PL` formats;
- the new-offer route (`/ajax/client-offers/new`) and its error handling;
- the CSRF token missing from office catalog, offers and members pages, and not checked in office `data()` and
  `brochure()`;
- removing an office member, broken by a body-less DELETE (422);
- Italian missing in the offer e-mail template.

Two things are still to do on your side: CSRF on the B2B and offers AJAX calls (`api.js` plus the controllers), and a
service token for the cockpit on `/panel/shops/{shop}/…`.
