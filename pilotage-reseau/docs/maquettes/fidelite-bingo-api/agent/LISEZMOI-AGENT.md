# Fidélité L'Atelier by : paquet pour l'agent codeur

18/11/2026. Ce paquet contient tout ce qu'il faut pour coder la fidélité « bingo + points » : l'admin Fidelity de l'ERP
modifié (Twig, CSS, JS, PHP, traductions), la spécification de l'API à compléter, le module du cockpit déjà écrit,
le handoff Claude Design et les maquettes.

## 0. Les décisions (Sam), dans l'ordre

1. **Garder la structure et les variables** de l'admin Fidelity existant (`erp-admin/`, copie du `.rar` « fonctionnel
   dans l'ERP ») et l'**habiller** avec le handoff Claude Design (`design/`).
2. **N'utiliser que la structure disponible, ne pas créer de table.**
3. (13/11) Dans l'ERP, **une seule carte à points**, permanente. Le **bingo de saison** se calcule à côté, par l'API
   de l'ERP (module du cockpit, `cockpit/`). Les cartes à tampons s'arrêtent.
4. (18/11) Une carte à points peut être **liée à des sociétés** (liste des bureaux) : **les points des salariés vont
   au nom de la société**.
5. (18/11) **Dès que la carte liée à un bureau est créée**, le bureau reçoit par e-mail un **PDF de QR codes**, ou
   **chaque salarié** reçoit le sien. Chaque QR code est à **usage unique**.
6. (18/11) **Typographie sans repli** : Vank et Gotham ne se mélangent jamais dans un même mot.

Les points 4 et 5 sont une **demande explicite** qui dépasse la structure actuelle : ce sont les seuls ajouts. Ils
sont marqués « à créer » partout. Tout le reste utilise les champs, routes et tables existants.

## 1. Contenu du paquet

```
LISEZMOI-AGENT.md        ce fichier
SPEC-API-ERP.md          ce que l'API de l'ERP doit ajouter (sociétés liées, crédit société, envoi des QR codes)
erp-admin/               l'admin Fidelity (même arborescence que le .rar), MODIFIÉ
  admin/views/loyalty/   form.twig, points.twig, overview.twig
  admin/controllers/…    LoyaltyCardController.php
  admin/services/…       LoyaltyCardService.php
  admin/public/assets/css/loyalty-claude-design.css     NOUVEAU : le thème
  admin/public/assets/images/fidelity/ill/*.png         NOUVEAU : les illustrations du handoff
  admin/public/assets/fonts/franchise-ui/PlaywriteDEVA-Regular.ttf   NOUVEAU
  admin/public/resources/lang/page/{fr,en,nl,it,pl}/loyalty.json     11 clés ajoutées
erp-admin.diff           toutes les modifications texte, en diff unifié (original du .rar → paquet)
cockpit/                 le module bingo du cockpit (déjà en place) et la page « app client en direct »
design/                  le handoff Claude Design tel que reçu (README, écrans HTML, source, illustrations, logos)
maquettes/               le PDF (synthèse + 6 écrans), les captures des maquettes, et les captures des VRAIS
                         gabarits Twig avant / après (rendus avec Twig 3 et des données fictives)
banc/                    le banc qui rend les vrais gabarits (render.php) et les capture (shot.js)
```

## 2. Travail A : l'habillage de l'admin Fidelity (prêt, front seulement)

**Rien ne change dans le contrat** : mêmes noms de champs, mêmes `data-*`, mêmes routes, même CSRF, même révision.

| Fichier | Modification |
|---|---|
| `admin/public/assets/css/loyalty-claude-design.css` | Nouveau. Le thème, chargé **après** `loyalty-wizard.css` et `loyalty-overview.css`, limité à `body.loyalty-wizard-page` et `body.loyalty-overview-page`. |
| `admin/views/loyalty/form.twig`, `overview.twig` | Une balise `<link>` de plus dans `{% block head %}` (`?v=20261118-1`). |
| `admin/public/assets/images/fidelity/ill/` | Les illustrations rouges du handoff (fond transparent). Le thème les pose en décor sur les cases de tampons, sans donnée. |
| `admin/public/assets/fonts/franchise-ui/PlaywriteDEVA-Regular.ttf` | La police d'accent du handoff (« Merci de votre fidélité »). |

Ce que fait le thème :
- jetons du handoff, déjà ceux de `franchise-ui/tokens.css` : rubis `#8D1D2C`, abricot `#F2C9A0`, beige `#EAE4DC`,
  beige clair `#F4EFE8`, bordures `rgba(34,34,34,.10 / .18)` ; rayons 6, 8, 12 ; pas d'ombre ;
- titres en Vank rubis, étapes rubis et abricot, panneaux bordés, champs chiffrés sur fond beige ;
- « Type de carte » : les deux cases deviennent les deux moteurs du handoff (rubis plein quand cochée, `:has()`) ;
- l'aperçu de la carte devient la carte imprimée du handoff : grille rubis de 4 colonnes, une illustration par case,
  tampon rond incliné sur les cases remplies, récompense en ticket rubis à encoches et cadre pointillé abricot ;
- l'aperçu « Carte à points », le simulateur, les paliers, la liste, les filtres et la fenêtre d'aperçu.

**Typographie sans repli (décision 6).** Dans `tokens.css` de l'ERP, `FB Vank` est limité à l'ASCII (`unicode-range`),
donc les lettres accentuées tombaient en Gotham au milieu des titres. Et `Gotham_Regular_New.otf`, le 400 de l'ERP, n'a ni
« € » ni « × ». Mesuré dans Chromium : `GC_Vank.ttf` dessine bien ses accents ; `Gotham_Medium.otf` a tout. Le thème
déclare donc `FB Vank Plein` (sans `unicode-range`) et `FB Gotham Plein` (`Gotham_Medium.otf`, une seule graisse, sans
gras simulé), et les met dans `--fb-display` / `--fb-font` sur les deux pages Fidelity. **Option** : faire la même chose
dans `tokens.css` pour tout l'ERP. Ce n'est pas fait ici, parce que tous les écrans en dépendent.

Critères d'acceptation :
- les captures `maquettes/captures-erp-reel/apres/` sont reproduites (`banc/`) ;
- aucune erreur JavaScript ; les quatre étapes, la validation, le simulateur, l'ajout de bonus et de paliers, la
  recherche des boutiques et des produits et la fenêtre d'aperçu marchent comme avant ;
- une carte figée (`terms_locked`) reste figée ;
- sur 1150, 900 et 520 px de large, les règles responsives d'origine restent valables.

Limite connue : la récompense est dans `.lw-reward-band`, sous la grille. Le handoff la met dans la dernière ligne de
la grille, mais il faudrait changer le balisage pour ça. Ce n'est pas fait.

## 3. Travail B : les sociétés liées, avec les points des salariés au nom de la société

### B1. L'admin (prêt dans le paquet)

| Fichier | Modification |
|---|---|
| `points.twig` | Dans le fieldset des points (`data-points-fields`, actif seulement si « Carte à points » est cochée), un bloc **Sociétés liées** : une case `office_ids[]` par bureau d'`options.offices` (société, bureau, ville, magasin, nombre de salariés), puis le choix d'envoi `office_qr_delivery` (`OFFICE` : un PDF au bureau ; `MEMBERS` : un PDF à chaque salarié). |
| `overview.twig` | Dans la fenêtre d'aperçu, section des points : les sociétés liées et le mode d'envoi. |
| `LoyaltyCardService.php` | `office_ids` (entiers uniques, vidé si la carte n'est pas à points) et `office_qr_delivery` (`OFFICE` par défaut) dans la charge utile. |
| `LoyaltyCardController.php` | Valeurs par défaut ; les deux champs font partie des **conditions figées** (`terms_locked` : on relit ceux de l'API, jamais ceux du POST) ; ils sont remis dans le formulaire après une erreur. |
| `loyalty.json` ×5 | `points_offices`, `points_offices_help`, `points_office_shop`, `points_office_members`, `points_offices_empty`, `points_office_send`, `points_office_send_office`, `points_office_send_members`, `points_office_send_help`, et les erreurs `UNKNOWN_OFFICE`, `OFFICES_REQUIRE_POINTS`. |

Tant que l'API ne rend pas `options.offices`, le bloc affiche « Aucun bureau dans les boutiques choisies. » et
n'envoie rien : l'admin modifié reste compatible avec l'API actuelle.

### B2 à B4. L'API, le moteur de points, l'envoi des QR codes (à faire)

Voir `SPEC-API-ERP.md`. En bref :
- `GET /admin/loyalty/options` rend `offices[]` ;
- la carte accepte et rend `office_ids` et `office_qr_delivery`, gardés **dans le stockage existant de la carte** ;
- le moteur crédite le **client société du bureau** (`office.id_company_client`) quand le client du ticket est
  **membre** d'un bureau lié (`office.members[].client_id`). Le bon du palier est émis au nom de la société ;
- à l'activation, l'ERP envoie les QR codes à usage unique en s'appuyant sur ce qu'il a déjà : les cartes PDF d'un
  bureau (`POST /panel/shops/{shop}/offices/{office}/cards`, un code unique par personne) et la file d'e-mails
  (celle de `…/members/invite`).

## 4. Travail C : le bingo de saison (cockpit, déjà écrit, rien à coder dans l'ERP)

`cockpit/src/fidelite_bingo.php` (lu par la page de test `cockpit/public/fidelite-test/`) :
- la **case de la semaine** est cochée dès 10 € d'achats du lundi au dimanche, tous magasins :
  `items[].shop_activity.gross_sales_before_corrections` de `GET /shops/{id}/loyalty/customers?card_id=&from=&to=` ;
- **un bon toutes les 3 cases**, super bon à 13 / 13 : des codes au nom du client
  (`POST /admin/voucher-campaigns/{id}/codes` avec `id_customer`, `usage_limit` 1) dans les campagnes
  « Bingo hiver · 2 € » et « · 10 € », créées chaque lundi ;
- les saisons, les 13 thèmes et les valeurs sont dans `cockpit/src/data/fidelite_bingo.json` : un fichier, pas une table.

`cockpit/public/fidelite-app/` (+ `cockpit/src/fidelite_app.php`) : la vue app client, **en direct**, en lecture seule
(`http://185.180.206.46/consulant_bo/fidelite-app/`). Avant le 23/11, la grille de l'hiver est rejouée sur les 13
dernières semaines.

Reste à faire (sans code ERP) : créer et activer les deux campagnes dans l'ERP ; passer le module en production
(la tâche du lundi) ; dans l'app client, calculer la grille (aucune route de l'ERP ne la rend).

**Question ouverte** : pour un salarié d'une société liée, la case du bingo reste-t-elle personnelle ? Le bingo lit
les achats par client, donc oui aujourd'hui, et ses bons partiraient au nom du salarié. À trancher avec Sam.

## 5. Impossible sans changer le moteur de points (non fait, non simulé)

- ×2 sur les tickets suivants une fois la case cochée, par client, jusqu'au dimanche ;
- produit de la semaine ×2 (pas de coefficient par produit) ;
- échange automatique au palier (le client échange lui-même, `POST /webshop/loyalty/points/redeem`) ;
- coefficients personnels (jour perso, offre perso).

## 6. Vérifié dans l'ERP le 18/11/2026

Spécification de `test.tfbuddy.com/docs/openapi.json` ; les routes de fidélité de la production répondent 401
(protégées), pas 404 (absentes).

| Pièce | Route | Constat |
|---|---|---|
| Carte à points | `/admin/loyalty/cards` | « Carte L'Atelier », `points-atelier`, id 2, active depuis le 13/11 ; bonus ×2 lun–mer 14:00–17:00 ; 100 pts → 5 € |
| Achats par client et période | `/shops/{id}/loyalty/customers` (`from`, `to`, `card_id`, `search`) | `shop_activity.gross_sales_before_corrections`, `programs[].shop_activity.points_earned`, `global_progress.points` |
| Bon à un client | `/admin/voucher-campaigns/{id}/codes` | corps `code`, `status`, `id_customer`, `valid_from`, `valid_to`, `usage_limit` |
| Bureaux | `/panel/shops/{shop}/offices`, `/{office}` | `id_company_client`, `company{…}`, `members[]{client_id, email, linked_at}`, `contact_email`, `billing_mode` |
| Cartes PDF d'un bureau | `POST /panel/shops/{shop}/offices/{office}/cards` | PDF, mode `personalized` / `blank`, `cards[]{name, code}` uniques, crée un lot d'impression et une campagne |
| E-mails | `POST …/offices/{office}/members/invite` | message mis en file (outbox), pas d'envoi synchrone |
| App client | `/webshop/loyalty/points`, `…/redeem`, `/webshop/vouchers`, `/webshop/loyalty/history` | jeton client |
| Simulation | `/admin/loyalty/simulate` | 22,40 € un mercredi 15:10 → 44 points (bonus ×2) |

Essai en direct (Halle, « vincent ») : un membre trouvé, sa carte lue en 2,4 s (52 appels). Un ticket de 35,60 € le
14/11 coche la case de la semaine, mais il a rapporté **0 point** sur la carte à points pourtant active depuis le 13/11.
À vérifier dans l'ERP : produits admissibles, paiement confirmé, ticket rattaché après coup ?

## 7. Le banc

`banc/render.php` rend les **vrais** gabarits (`form.twig`, `overview.twig`) avec Twig 3 et des données fictives
(4 magasins, catalogue, 3 bureaux), sans réseau : `php render.php <dossier admin> <sortie>`. Il a besoin de
`vendor/autoload.php` de Twig 3 (à adapter en tête du fichier). `banc/shot.js` (Playwright) capture la liste, les
quatre étapes et la fenêtre d'aperçu : `node shot.js <sortie> <préfixe> [échelle]`.
