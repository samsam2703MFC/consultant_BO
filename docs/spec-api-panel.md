# Spécification des endpoints à ouvrir côté panel

Destinataire : équipe de développement du panel `atelierby.tfbuddy.com`.
Demandeur : Cockpit CEO / Pilotage réseau (back-office consultants).
Base d'URL : `https://atelierby.tfbuddy.com/api/v1`

Ce document couvre **deux chantiers en une seule passe** — les pièces jointes
d'un avis de tâche, et le planogramme avec ses notes de rappel — plus, en
annexe, les manques déjà mesurés sur les autres écrans. L'intention est
explicite : livrer un contrat complet, pour ne pas rouvrir les mêmes endpoints
dix fois.

Tout ce qui est écrit « mesuré » ci-dessous l'a été sur l'API de production, à
la date indiquée. Rien n'est supposé.

---

## 0. Conventions communes

**Authentification.** Identique au reste de l'API consultant :
`POST /consultant/auth/login` avec `{ "phone": "...", "password": "..." }`,
puis `Authorization: Bearer <token>` sur chaque appel.

**Bornes de période.** Les endpoints existants attendent `date_from` et
`date_to` **seuls**. Mesuré : ajouter `from` / `to` en parallèle fait retomber
`/consultant/shops/{id}/pnl` sur sa période par défaut au lieu de la période
demandée. Merci de conserver `date_from` / `date_to` sur les nouveaux endpoints,
et d'ignorer proprement les alias inconnus plutôt que de changer de
comportement.

**Enveloppe d'erreur.** Une forme unique, sur tous les endpoints :

```json
{ "error": { "code": "attachment_too_large", "message": "…", "field": "file" } }
```

`code` stable et machine-lisible (le cockpit s'en sert pour décider),
`message` en clair et affichable tel quel à l'utilisateur, `field` présent
uniquement pour les erreurs de validation.

**Codes HTTP attendus.** `200` lecture, `201` création, `204` suppression,
`400` requête malformée, `401` jeton absent/expiré, `403` la boutique n'est pas
dans le périmètre du consultant, `404` ressource inconnue, `409` conflit
(voir idempotence), `413` charge trop lourde, `415` type non accepté,
`422` validation métier, `429` débit dépassé avec `Retry-After`.

**Idempotence.** Tout `POST` accepte un champ `client_ref` (chaîne libre,
≤ 64 caractères, fournie par le cockpit). Un second `POST` portant le même
`client_ref` sur la même ressource parente ne crée rien et **rend la ressource
déjà créée** avec `200` (pas `409`). C'est ce qui permet de réémettre sans
risque après un réseau coupé.

**Horodatage.** ISO 8601 avec fuseau (`2026-08-17T18:54:52+02:00`). Les dates
seules restent `YYYY-MM-DD`.

---

## 1. Pièces jointes d'un avis de tâche

### Pourquoi

Le consultant annote la photo rendue par la boutique : il pose des cadres
numérotés sur les zones à reprendre, chacun avec sa gravité (le barème existant
mineur / majeur / critique) et sa remarque. Le résultat doit **arriver au
franchisé**.

État mesuré : `POST /consultant/shops/{shopId}/task-reviews` n'accepte que
`rating`, `is_accepted`, `comment`, `checklist_id`, `completion_id`. Aucune
route de dépôt de pièce jointe n'est exposée. La photo annotée reste donc
aujourd'hui dans le cockpit, et seul le commentaire texte parvient au magasin.

### 1.1 Dépôt autonome, avant l'avis

```
POST /consultant/shops/{shopId}/attachments
Content-Type: multipart/form-data
```

| Champ | Type | Obl. | Contraintes |
|---|---|---|---|
| `file` | fichier | oui | `image/jpeg`, `image/png`, `image/webp`, `application/pdf`. 12 Mio max. |
| `kind` | énum | oui | `annotated_photo`, `reedited_photo`, `reference_photo`, `document` |
| `caption` | texte | non | ≤ 400 caractères |
| `overlay` | texte (JSON) | non | Le calque vectoriel — voir §2 |
| `client_ref` | texte | non | Idempotence |

Réponse `201` :

```json
{
  "id": 91234,
  "kind": "annotated_photo",
  "mime": "image/jpeg",
  "bytes": 248113,
  "width": 1600,
  "height": 1200,
  "url": "https://…?X-Amz-…",
  "url_expires_at": "2026-08-17T19:54:52+02:00",
  "shop_id": 4,
  "created_at": "2026-08-17T18:54:52+02:00",
  "client_ref": "cockpit-4-1213-20260817-1"
}
```

L'`id` rendu doit être utilisable tel quel par
`GET /attachments/{id}/presigned-url`, qui existe déjà et que le cockpit
utilise pour les photos de réalisation.

**Pourquoi ce dépôt séparé.** Un consultant annote *avant* de choisir sa note.
Déposer d'abord, rattacher ensuite, évite de perdre le travail d'annotation
quand la notation est refusée en validation (commentaire obligatoire sous le
seuil, par exemple). Une pièce jointe non rattachée au bout de 24 h peut être
purgée : le cockpit ne s'appuie pas sur sa persistance longue.

### 1.2 Dépôt rattaché à un avis existant

```
POST /consultant/shops/{shopId}/task-reviews/{reviewId}/attachments
Content-Type: multipart/form-data
```

Mêmes champs et mêmes contraintes qu'en §1.1. La réponse porte en plus
`review_id` et `task_id`.

C'est l'endpoint demandé nommément. Il ne suffit pas seul : voir §1.3.

### 1.3 Rattacher à la création de l'avis

`POST /consultant/shops/{shopId}/task-reviews` doit accepter un champ
supplémentaire :

```json
{
  "task_id": 1213,
  "review_date": "2026-08-17",
  "rating": 2,
  "is_accepted": false,
  "comment": "1. [majeur] Étagère haute : trou de 40 cm…",
  "checklist_id": 57,
  "completion_id": 561,
  "attachment_ids": [91234]
}
```

**Pourquoi les deux chemins.** Sans `attachment_ids` à la création, une
notation avec photo annotée demande deux appels dont le second peut échouer :
l'avis est parti, la photo non, et le franchisé reçoit un commentaire qui
renvoie à des numéros qu'il ne voit pas. Avec les deux chemins disponibles, le
cockpit choisit selon l'ordre réel du geste de l'utilisateur.

Un `attachment_id` déjà rattaché à un autre avis ⇒ `422`,
code `attachment_already_linked`.

### 1.4 Lire les pièces jointes d'un avis

```
GET /consultant/shops/{shopId}/task-reviews/{reviewId}/attachments
```

```json
{ "attachments": [ { "id": 91234, "kind": "annotated_photo", "caption": "…",
  "mime": "image/jpeg", "bytes": 248113, "width": 1600, "height": 1200,
  "url": "https://…", "url_expires_at": "…",
  "overlay": { "…": "voir §2" },
  "created_by": { "id": 12, "name": "…" }, "created_at": "…" } ] }
```

Ces pièces jointes doivent aussi apparaître dans
`GET /consultant/shops/{shopId}/checklists/{checklistId}/progress?date=…`,
qui est le flux dont le cockpit se sert déjà pour lire l'état d'une tâche —
sinon il faut un appel de plus par tâche pour savoir si une annotation existe.
Un simple `review_attachment_count` sur chaque ligne d'avancement suffit.

### 1.5 Supprimer

```
DELETE /consultant/shops/{shopId}/task-reviews/{reviewId}/attachments/{attachmentId}
→ 204
```

Réservé à l'auteur de l'avis et à la direction. Une pièce jointe déjà vue par
la boutique n'est pas supprimée physiquement mais marquée
`deleted_at` — la traçabilité d'un contrôle qualité ne doit pas pouvoir
disparaître.

### 1.6 Lecture depuis un navigateur — CORS

**Point technique décisif, à ne pas manquer.**

Les photos sont servies par une URL signée sur le stockage
(`…r2.cloudflarestorage.com/…`). Ce stockage ne renvoie aujourd'hui **aucun
en-tête CORS**. Conséquence : un navigateur peut *afficher* l'image, mais tout
traitement en JavaScript est interdit — dessiner la photo dans un `canvas` le
« teinte » et l'export échoue. C'est la raison pour laquelle le cockpit stocke
ses annotations en vectoriel plutôt que d'aplatir l'image côté navigateur.

Une des deux réponses suffit :

1. `Access-Control-Allow-Origin` sur les réponses du stockage (idéalement
   restreint aux origines du panel et du cockpit), **ou**
2. une route de contenu servie par le panel lui-même :
   `GET /attachments/{id}/content` qui renvoie les octets avec
   `Content-Type` correct et `Access-Control-Allow-Origin`.

La seconde est préférable : elle survit à un changement de fournisseur de
stockage et évite d'exposer la configuration du bucket.

### 1.7 Visibilité côté franchisé

À confirmer explicitement, car c'est le but de tout le chantier : une pièce
jointe d'avis doit être **visible par la boutique** dans l'application
terrain, à côté du commentaire de l'avis. Si ce n'est pas le cas aujourd'hui,
il faut l'ajouter — sans quoi l'endpoint de dépôt ne sert à rien.

Idéalement, une notification à la boutique lorsqu'un avis porte une pièce
jointe.

---

## 2. Le calque d'annotation, en vectoriel

L'annotation n'est pas qu'une image : c'est une liste de repères numérotés,
chacun avec une zone, une gravité et un texte. La garder structurée permet de
la corriger un mois plus tard, de la rendre nette à toute taille, et surtout de
**compter** — combien de non-conformités majeures sur le facing ce trimestre.

Format, transmis dans le champ `overlay` (§1.1) et rendu en lecture (§1.4) :

```json
{
  "version": 1,
  "shape": "rect",
  "coords": "relative",
  "markers": [
    { "n": 1, "x": 0.5805, "y": 0.3392, "w": 0.1233, "h": 0.1111,
      "severity": 2, "text": "Étagère haute : trou de 40 cm à droite des bouteilles." },
    { "n": 2, "x": 0.3389, "y": 0.7548, "w": 0.1183, "h": 0.1089,
      "severity": 1, "text": "Bas de meuble vide sur toute la largeur." }
  ]
}
```

- `x`, `y`, `w`, `h` : **relatifs à la photo**, entre 0 et 1, quatre décimales.
  Relatifs et non en pixels, pour rester justes quelle que soit la taille
  d'affichage et si la photo est redimensionnée à l'archivage.
- `severity` : l'échelle **déjà partagée** par la notation —
  `5` exemplaire, `4` conforme, `3` non conforme mineur, `2` non conforme
  majeur, `1` non conforme critique. Ne pas créer une seconde échelle : deux
  barèmes pour la même chose divergent au premier réglage modifié.
- `n` : numéro affiché, contigu à partir de 1, dans l'ordre de la liste. Le
  serveur peut le recalculer ; il ne doit jamais avoir de trou.
- `text` : ≤ 400 caractères.

Contrainte de validation demandée : borner `x`,`y` à `[0,1]`, imposer
`w`,`h ≥ 0.015` (en dessous, le repère ne désigne plus rien) et
`x+w ≤ 1`, `y+h ≤ 1`. Plafonner à 40 repères par photo.

Mise à jour sans re-téléverser l'image :

```
PUT /consultant/shops/{shopId}/task-reviews/{reviewId}/attachments/{attachmentId}/overlay
```

Corps : l'objet `overlay` ci-dessus. Remplacement complet de la liste ; un
`markers` vide efface le calque.

---

## 3. Planogramme

### Pourquoi, et l'état mesuré

Le référentiel du cockpit porte pour chaque référence un emplacement de
comptoir (zone / meuble / niveau / emplacement) — mais **aucune source ne le
fournit** : mesuré le 17/08/2026, `0 référence sur 710` porte un emplacement, et
aucune zone n'est définie. Il n'existe donc ni structure de comptoir, ni
placement, ni note de présentation. Tout est à ouvrir.

Le besoin métier : depuis une référence sans emplacement, choisir un slot libre
dans une liste tenue par le planogramme ; le planogramme dit alors **où** le
produit doit être présenté, **comment** (note de rappel + photo de
présentation) et **avec quelles informations de vente**.

### 3.1 Structure du comptoir

Quatre niveaux hiérarchiques, chacun avec son identité stable :

```
zone (vitrine réfrigérée, comptoir sec, îlot boissons…)
 └── fixture / meuble (vitrine 1, gondole A…)
      └── shelf / niveau (haut, médian, bas — ou 1..n)
           └── slot / emplacement (position numérotée sur le niveau)
```

```
GET    /consultant/planogram/zones
POST   /consultant/planogram/zones
PATCH  /consultant/planogram/zones/{id}
DELETE /consultant/planogram/zones/{id}

… idem pour /fixtures, /shelves, /slots
```

Un `GET /consultant/planogram/slots` doit pouvoir rendre l'arbre complet en un
appel, avec l'occupation :

```json
{ "slots": [
  { "id": 3101, "zone": { "id": 31, "name": "Vitrine réfrigérée" },
    "fixture": { "id": 310, "name": "Vitrine 1" },
    "shelf": { "id": 3100, "name": "Niveau haut", "rank": 1 },
    "position": 4,
    "width_mm": 320, "depth_mm": 400, "capacity": 12,
    "occupied_by": { "product_id": 1110103, "name": "Fromage & Spéculoos - 120⌀" },
    "shop_id": null }
] }
```

`occupied_by` à `null` = slot libre. C'est **exactement** ce dont l'écran a
besoin pour proposer une liste de slots libres sans calcul côté client.

Paramètres de filtre attendus sur `GET /consultant/planogram/slots` :
`zone_id`, `fixture_id`, `free=1` (slots libres uniquement), `shop_id`.

### 3.2 Placement d'une référence

```
PUT /consultant/planogram/placements/{productId}
```

```json
{
  "slot_id": 3101,
  "facings": 3,
  "min_qty": 6,
  "sequence": 1,
  "valid_from": "2026-09-01",
  "valid_to": null,
  "client_ref": "cockpit-place-1110103-1"
}
```

- `facings` : nombre de fronts (largeur occupée en unités de produit).
- `min_qty` : quantité minimale à tenir. Le cockpit tient déjà cette valeur
  pour l'assortiment obligatoire ; si le panel la porte, elle devient commune
  aux deux, ce qui est préférable.
- `sequence` : ordre du produit dans le slot quand plusieurs s'y partagent la
  place.
- `valid_from` / `valid_to` : les gammes sont saisonnières. Le panel expose
  déjà `product_availability_period` ; un placement doit pouvoir suivre.

Un slot déjà occupé et sans place restante ⇒ `409`, code `slot_occupied`, avec
dans le corps l'occupant actuel — l'écran propose alors l'échange plutôt que de
laisser l'utilisateur deviner.

`DELETE /consultant/planogram/placements/{productId}` retire la référence du
comptoir (`204`).

### 3.3 Notes de rappel — le cœur de la demande

Une note de rappel dit **comment présenter**. Elle vit à deux niveaux, et les
deux sont nécessaires :

**Sur le placement** (« ce produit, à cet endroit ») :

```
GET / PUT /consultant/planogram/placements/{productId}/note
```

```json
{
  "text": "Présenter en pyramide, 3 rangées de 4, étiquette face client. Ne jamais descendre sous 6 pièces avant 11 h.",
  "photo_attachment_id": 91240,
  "pinned": true,
  "severity": 3,
  "valid_from": "2026-09-01",
  "valid_to": "2026-12-31",
  "updated_by": { "id": 12, "name": "…" },
  "updated_at": "2026-08-17T18:54:52+02:00"
}
```

**Sur la zone ou le meuble** (« cette vitrine, en général ») :

```
GET / PUT /consultant/planogram/zones/{id}/note
GET / PUT /consultant/planogram/fixtures/{id}/note
```

Même forme. Une note de meuble s'applique à tout ce qu'il contient.

Champs, et pourquoi chacun :

| Champ | Rôle |
|---|---|
| `text` | La consigne, ≤ 2000 caractères. C'est ce que lit la boutique. |
| `photo_attachment_id` | La photo de présentation attendue (voir §3.4). Une consigne de présentation sans image se discute ; avec image, elle se constate. |
| `pinned` | Épingler la note en tête de la checklist d'ouverture de la boutique. |
| `severity` | Même barème qu'au §2. Une consigne critique ne se lit pas comme un conseil. |
| `valid_from` / `valid_to` | Une consigne de Noël ne doit pas rester affichée en février. |
| `updated_by` / `updated_at` | Qui a changé la consigne, et quand. Non modifiables par le client. |

Historique demandé : `GET …/note/history` rendant les versions précédentes.
Une consigne de présentation qui change sans trace est une source de litige
entre le réseau et le franchisé.

**Lecture côté boutique.** Les notes épinglées doivent être lisibles depuis
l'application terrain, sur la tâche de comptoir correspondante. Sans cela la
consigne ne circule pas et le planogramme reste un document mort.

### 3.4 Photo de présentation — n'existe pas aujourd'hui

Mesuré le 17/08/2026 sur `GET /products` : **579 produits, aucune clé d'image**.
Aucune des clés `url`, `image_url`, `photo_url`, `picture`, `image`,
`thumbnail`, `photo`, `attachment_id`, `image_id`, `media`, `images` n'est
remplie sur un seul produit. Le champ `photoRef` que le cockpit affiche dans le
contrôle des tâches rend donc toujours `null`.

Demande :

```
POST   /consultant/products/{productId}/photos      (multipart, mêmes règles qu'en §1.1)
GET    /consultant/products/{productId}/photos
DELETE /consultant/products/{productId}/photos/{id}
```

Avec `kind` parmi :

- `presentation` — le produit **présenté au comptoir**, tel qu'attendu. C'est
  celle qui sert de référence à un contrôle qualité par comparaison.
- `pack` — le produit seul, sur fond neutre (fiche de vente, catalogue).
- `technical` — schéma de montage / découpe / dressage.

Et, pour que le catalogue soit lisible en un appel, un champ
`photos: [{ id, kind, url, url_expires_at }]` ajouté à `GET /products` et à
`GET /product-categories/{id}/products`.

### 3.5 Réseau contre magasin

Le planogramme est un référentiel **réseau** : une seule vérité, imposée aux
boutiques. Mais les meubles diffèrent d'un magasin à l'autre.

Demande : les mêmes routes préfixées, pour une déclinaison par magasin —

```
GET /consultant/shops/{shopId}/planogram/slots
GET /consultant/shops/{shopId}/planogram/placements
```

en lecture au minimum, avec un champ `source: "network" | "shop"` sur chaque
ligne pour distinguer ce qui est imposé de ce qui est local. L'écriture par
magasin peut venir dans un second temps ; la lecture, non — sans elle on ne
peut pas dire à un franchisé si son comptoir suit le planogramme.

---

## 4. Fiche technique de vente

`GET /products` porte déjà beaucoup de ce qu'il faut, et c'est une bonne
nouvelle. Champs mesurés comme présents et exploitables : `name`,
`id_category`, `category_name`, `single_weight`, `nutriscore`, `allergene`,
`is_vegetarian`, `shelf_life_minutes`, `shelf_life_category`,
`storage_name`, `storage_description`, `storage_temperature`,
`reheating_time_minutes`, `reheating_temperature_celsius`,
`preparation_lead_time_hours`, `is_prepared_before_sales`, `is_divisible`,
`is_piece_based`, `quantity_per_label`, `label_size`, `id_package`,
`positioning_name`, `positioning_description`, `sector_name`,
`expected_margin`, `id_recipe`.

Manquent, pour une vraie fiche de vente :

| Champ demandé | Pourquoi |
|---|---|
| `photos[]` | Voir §3.4. Sans visuel, ce n'est pas une fiche de vente. |
| `sale_price` | `suggested_sale_price` vaut `1.00` sur l'essentiel du catalogue — inutilisable. Il faut le prix réellement pratiqué, réseau et/ou par magasin. |
| `material_cost` | Le coût matière. Il existe dans les recettes mais n'est pas exposé produit par produit ; le cockpit le reconstitue et ne couvre donc pas tout le catalogue. |
| `argumentaire` / `selling_points[]` | Deux ou trois phrases de vente par référence, pour le comptoir. Aucun champ n'en tient lieu aujourd'hui. |
| `ingredients` | `allergene` existe, la liste d'ingrédients non. Obligation d'affichage dans plusieurs cas. |

**Écart de périmètre à trancher.** `GET /products` rend **579** produits actifs,
là où la base partagée en compte **711**. Merci de préciser laquelle des deux
est la référence, et ce qui explique l'écart (produits d'une autre marque ?
filtre implicite ?). Le cockpit affiche aujourd'hui les 711 et devra s'aligner.

---

## 5. Annexe — les autres manques déjà mesurés

Listés ici pour qu'ils soient traités dans la même passe, plutôt qu'un par un.
Chacun bloque un écran identifié du cockpit.

| # | Manque | Mesure | Écran bloqué |
|---|---|---|---|
| 1 | **Vente par magasin et par référence** | `GET /shops/{id}/products/waste` rend un `sold_qty` **réseau** : la même valeur `5165.000000000` sur les quatre boutiques. `waste_qty`, lui, est bien propre au magasin (47 / 34 / 5 / 30). | Scoring des références, Analyse dans le temps, Demande de prix (celui-ci ne peut pas fonctionner du tout) |
| 2 | **Budget de CA par magasin** | Aucun des 5 magasins n'a de budget exposé par l'API. | Objectifs de CA, P&L magasins, Dashboard consultant |
| 3 | **Food cost et valorisation** | Absents de `/pnl`, qui porte CA, marge nette, labour et overhead. | Marge & maîtrise des coûts, Tableau des magasins |
| 4 | **TJM / charge consultant** | Aucune source. | Tâches consultants, Projets |
| 5 | **Ventes du jour** | La dernière journée encodée lue par le cockpit a jusqu'à 34 jours de retard sur la date du jour ; les écrans qui lisent la base partagée montrent donc un « aujourd'hui » qui ne l'est pas. Une lecture API à J le corrige. | 5 écrans (scoring, production, pertes, magasins, P&L) |
| 6 | **`/consultant/shops/category-sales`** | Parle les 12 **groupes**, pas les 81 catégories du catalogue, et expire au-delà d'un mois de période. | Analyse dans le temps |
| 7 | **Débit des lectures** | `GET /products/scoring` : 3,4 s. `GET /pwa/tasks` : 2,6 s. Douze appels concurrents sur `/shops/{id}/products/waste` : 9 sur 12 sans réponse — d'où un plafond à 4 côté cockpit. | Tous |

---

## 6. Ordre de livraison suggéré

Par dépendance, pas par difficulté :

1. **§1.6 CORS ou route de contenu.** Débloque tout traitement d'image côté
   navigateur, y compris l'aplatissement de la photo annotée. Peu de code.
2. **§1.1 à §1.4 pièces jointes d'avis** + **§1.7 visibilité boutique.** C'est
   le chantier demandé ; il est autonome.
3. **§3.4 photos produit.** Débloque à la fois la fiche de vente et la
   comparaison au contrôle qualité.
4. **§3.1 et §3.2 structure et placements.** Le planogramme n'existe pas :
   commencer par la structure, sinon il n'y a rien à choisir.
5. **§3.3 notes de rappel.** Utile dès que les placements existent.
6. **§5.1 vente par magasin.** Le plus lourd, et le plus rentable : il débloque
   trois écrans et rend la demande de prix possible.

---

*Mesures effectuées le 17 août 2026 sur l'API de production du panel, avec le
compte consultant du cockpit. Les chiffres cités sont reproductibles via
`GET /api/cockpit/produits/analyse/sonde`, `GET /api/cockpit/lacunes` et
`GET /api/cockpit/audit/fraicheur` sur le cockpit.*
