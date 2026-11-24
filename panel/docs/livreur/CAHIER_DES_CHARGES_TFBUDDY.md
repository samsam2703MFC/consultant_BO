# Appli du livreur — le maximum dans TF Buddy

**24/11/2026.** Demande de Sam : une appli (PWA) pour le chauffeur des livraisons webshop aux bureaux. Feuille de tournée
avec un QR de connexion ; le chauffeur, du personnel actif du magasin, scanne et voit ses points ; **avant de partir, il
scanne chaque colis** (départ bloqué sauf PIN du responsable du magasin) ; sur place, **« Validé » + photos** envoie un SMS
au client (« votre paquet est livré »). Ordre des arrêts : celui de la tournée, modifiable par exception. Les étiquettes
QR des colis sont imprimées par la caisse (POS) quand l'opérateur a fini un paquet : on n'y touche pas.

Décision de Sam : **« le maximum sur TF Buddy »**. TF Buddy garde et décide tout : tournées, commandes, colis,
chargement, livraison, preuves, SMS. Le cockpit fait les écrans (l'appli du livreur, la page Commandes du dashboard)
et un relais sans stockage.

Source : la spécification de l'API (`https://test.tfbuddy.com/docs/`, `openapi.json`, 1 293 chemins), lue le 24/11/2026.

## 1. Ce que TF Buddy a déjà

| Besoin | Route | Ce qu'elle donne |
|---|---|---|
| Les tournées d'un jour | `GET /panel/shops/{shop}/franchise/delivery-routes?date=` | `routes`, `offices`, `sites`, `assignments`, `orders`, `depot_address` |
| Une tournée | `POST …/delivery-routes/save` | `name`, `vehicle`, `depot_address`, `start_time`, `weekdays`, `driver`, `site_ids`, `max_orders`, `max_offices`, `return_to_depot`, `color`, `short_name` (verrou `version`) |
| L'itinéraire | `POST …/delivery-routes/calculate` `{route_id, date, optimize}` | `stops`, `site_ids` dans l'ordre, km, minutes, attente, `encoded_polyline` (Google Routes) |
| Les bureaux et leurs adresses | `GET …/delivery-hub`, `POST …/delivery-sites/save` | `offices`, `office_sites`, `members`, `clients`, `departments` ; un site : adresse, `access_minutes`, coordonnées |
| L'heure limite de commande | `delivery-hub` › `schedule-save` `{route_id, weekday, start_time, end_time, cutoff_time, cutoff_days_before, capacity}` ; `GET|POST …/preparation-settings` `{cutoff_time, timezone}` | la « validation à 11:30 » |
| Une commande dans une tournée | `POST …/delivery-assignments` `{id_order, route_id, site_id, date}` | contrôle l'éligibilité, la date, la capacité |
| La préparation | `GET /panel/shops/{shopId}/franchise/orders?date=`, `…/orders/{id}`, `POST …/orders/{id}/preparation-status` `{expected_status, target_status}` | les commandes et leurs produits ; 1 nouvelle → 5 en production → 2 prête |
| Le PIN d'un employé | `POST /kitchen/employees/verify-pin` `{employee_id, pin}` | `success`, `name` — **réservé à une tablette cuisine enregistrée** |
| Les SMS | `GET|PUT /admin/smsapi-settings` | le compte SMSAPI de TF Buddy |
| Les pièces jointes | `GET /attachments/{id}/presigned-url` | lire un fichier |

## 2. Ce qu'il faut ajouter dans TF Buddy

**R1 — Un accès de service pour le cockpit.** Les routes `franchise/*` demandent un jeton du panel lié au magasin
(mesuré : 401 au compte consultant du cockpit). La connexion au panel est téléphone + PIN + SMS, session de 12 h : un
serveur ne peut pas la tenir. Demande : un jeton de service (ou client OAuth) avec le droit `requireShopAccess` sur
tous les magasins, rangé dans `erp_cles` comme `erp_jeton_service`.

**R2 — Les colis.** Un colis par paquet fini, créé quand la caisse imprime l'étiquette : `parcel_id`, `id_order`, n°
dans la commande / total, `route_id`, `site_id`, `date`, `printed_at`, `loaded_at`, `delivered_at`. Le **contenu du QR**
documenté (par exemple `TFB:P:{parcel_id}:{contrôle}`), pour que l'appli le lise sans appel réseau. Lecture :
`GET /panel/shops/{shop}/franchise/delivery-routes/{route}/parcels?date=`.

**R3 — La tournée du jour (un « run »).** `POST /panel/shops/{shop}/franchise/delivery-runs` `{route_id, date,
employee_id}` → `run_id` et un **jeton de run** (portée : ce run seulement, jusqu'à minuit) : c'est ce que contient le QR de
la feuille de tournée. Le chauffeur est un employé actif du magasin (`members` ou les employés du magasin).
`GET …/delivery-runs/{run}` : le chauffeur, les arrêts dans l'ordre (site, bureau, consigne, commandes, colis
attendus), l'état de chaque colis et de chaque arrêt.

**R4 — Le chargement.** `POST …/delivery-runs/{run}/scans` `{qr}` → `ok` | `deja_scanne` | `autre_tournee` |
`inconnu`, et le compteur de l'arrêt. `POST …/delivery-runs/{run}/depart` : refusé (409) tant qu'un colis manque,
**sauf** `{manager_employee_id, pin, motif}` — le PIN vérifié par TF Buddy (même règle que `verify-pin` : hachage,
limite d'essais), un rôle de responsable du magasin exigé, le passe-droit gardé (qui, quand, colis manquants, motif).

**R5 — L'ordre par exception.** `POST …/delivery-runs/{run}/order` `{site_ids, motif}` : l'ordre change pour ce run
seulement, la tournée ne bouge pas.

**R6 — Livré.** `POST …/delivery-runs/{run}/stops/{site}/delivered` `{at, photos: [attachment_id…], lat, lng}` : les
commandes de l'arrêt passent livrées (un nouvel état, ou `picked_up` avec la marque « livrée ») ; **TF Buddy envoie le
SMS** au client de la commande par son compte SMSAPI (modèle FR / NL réglable, une fois par commande) et rend le statut
du SMS. `POST …/stops/{site}/problem` `{type: absent|refus|manque, note, photos}`.

**R7 — Les photos.** `POST /attachments` (téléversement, ou URL pré-signée en écriture) → `attachment_id`.

**R8 — Le suivi.** `GET /panel/shops/{shop}/franchise/delivery-runs?date=` : chaque run, son état (préparation,
chargement, en route, rentré), ses arrêts livrés, ses passe-droits. La page Commandes du dashboard le lit.

**À confirmer par TF Buddy :** à l'heure limite (`cutoff_time`), les affectations du jour sont-elles figées ? Une
commande peut-elle être sur deux sites ? Un colis peut-il être réimprimé (même `parcel_id`) ?

## 3. Ce que fait le cockpit

- **L'appli du livreur** : `panel/public/livreur/` (PWA, sans installation). Le QR de la feuille l'ouvre sur le run ; le
  chauffeur choisit son nom ; le scan par la caméra (`BarcodeDetector` sur Android, une petite librairie de lecture sur
  iPhone, Safari n'ayant pas le lecteur) ; une file d'attente hors ligne (scans, « Validé », photos) renvoyée quand le
  réseau revient. **Aucune donnée de livraison gardée au cockpit** : tout part à TF Buddy.
- **Un relais** `panel/src/livreur.php` (`/livreur/…`) : vérifie le jeton de run, appelle TF Buddy avec le jeton de
  service (R1), ne garde rien.
- **La feuille de tournée** : imprimée depuis Commandes, avec le QR du run (la librairie QR du dépôt).
- **La page Commandes** : lit les tournées, les affectations et les runs de TF Buddy (R8) ; les tables `panel_tournee*`
  et « Gérer les tournées » sont retirées (aucune tournée n'y a été créée : 0 à Gosselies, 0 à Corbais le 24/11/2026).

## 4. L'ordre

1. TF Buddy : R1 (sans lui, rien ne se lit), puis R2 et R3.
2. Cockpit : la page Commandes sur les tournées de TF Buddy ; l'appli du livreur en lecture (le run, les arrêts).
3. TF Buddy R4 + cockpit : le scan au chargement, le PIN du responsable.
4. TF Buddy R6, R7 + cockpit : « Validé », les photos, le SMS.
5. R5, R8 : l'ordre par exception, le suivi en direct au dashboard.
