# Prompt pour l'agent codeur — le Bingo de saison (carte de fidélité)

> À donner tel quel à l'agent, ouvert dans le dépôt `consultant_BO`. Il travaille dans `pilotage-reseau/`.
> Référence visuelle : `docs/maquettes/bingo-saison/edition-maquette.html` (l'écran à construire) et
> `docs/maquettes/bingo-saison/bingo-maquette.html` (ce que voit le client). Deux fichiers autonomes : ouvre-les dans
> un navigateur, tout est interactif.
> Rédigé le 13/11/2026 à partir des décisions de Sam du 12 et du 13/11/2026.

---

## 0. Ta mission

Construire le **Bingo de saison** dans le cockpit : une carte de fidélité par trimestre, une case par semaine qui se
coche **toute seule** quand le client a assez acheté dans la semaine, un bon **de plus en plus gros** toutes les x
cases, et à la carte pleine le **super bon cadeau** et le **tirage au sort d'1 an de pain**.

Ce lot couvre le cockpit : les données, le calcul des cases à partir des tickets, la création des bons au panel
TFBuddy, le tirage, l'API, l'écran **Édition** de la saison et l'écran **Suivi**. L'app du client (PWA, dépôt WebShop)
et la caisse (Szymon) ne sont **pas** dans ce lot : tu leur prépares la route qu'elles liront, et la consigne à leur
transmettre (§4).

**Tu ne tranches pas seul les questions du §7.** Quand l'une d'elles bloque, tu t'arrêtes, tu écris ce que tu as
vérifié, et tu proposes.

---

## 1. À lire avant d'écrire une ligne

1. `CLAUDE.md` (racine) et `pilotage-reseau/CLAUDE.md` : français partout (code commenté, interface, commits),
   aucune URL, clé ni identifiant en dur, `CREATE TABLE IF NOT EXISTS` au premier appel, commits atomiques, tests
   sans réseau.
2. `docs/PORTAILS.md` : la fidélité appartient au portail **Marketing** ; ses tables vivent aujourd'hui dans
   `pilotage-reseau` en `ceo_fid_*` et partiront en `mar_fid_*`. **Inscris chaque nouvelle table au registre**
   (section Marketing), comme `ceo_fid_carte`.
3. `docs/API_STANDARD.md` (checklist §19) et `docs/DESIGN_SYSTEM.md` (Gotham, rubis `#8D1D2C`, pas d'ombre, pas de
   pilule, pas de gras hors accents).
4. `pilotage-reseau/docs/maquettes/bingo-saison/LISEZMOI.md` : le produit, les chiffres, les décisions.
5. L'existant à **réutiliser, pas à dupliquer** :

| Fichier | Ce que tu y prends |
|---|---|
| `src/fidelite.php` | Le Programme de fidélité (`ceo_fid_regle`), l'écran `#/fidelity`, ses styles (`public/assets/css/fidelite.css`, préfixe `fi-`) et ses onglets. Le Bingo devient le 4e onglet. |
| `src/fidelite_caisse.php` | **Le modèle à copier pour les bons** : code tiré par le cockpit, créé au panel après coup (`fcaPousser`), repris au battement horaire, états `a_creer / cree / echec / a_retirer / annule`, essai sans `valid_from` si le panel le refuse. Et la carte QR donnée en caisse, qui devient la carte de la saison. |
| `src/recurrence_pwa.php` | **La source des achats** : le relevé quotidien des tickets (`/shops/{id}/transactions?date=`, `id_client` sur chaque ticket identifié). Chaque liste du jour passe par `rpGraveListe()` : c'est là que tu branches le relevé des montants (§3.2). |
| `src/promos_bons.php` | Les campagnes de bons du panel (`pbFiche`, `pbCodes`, `pbLire`) et l'assistant qui les crée. |
| `src/rapports.php` | `ep_rapports_cron()` : le battement horaire où brancher `fbgCron()`. |
| `src/erp_cles.php` | `erpRouteIntegration()` et `erpJetonServiceOk()` : la route que la PWA lira avec le jeton de service. |
| `bin/tests_fidelite_caisse.php` | Le modèle de tests : SQLite en mémoire, panel simulé (`ErpApi` doublé), aucune requête réseau. |
| `docs/maquettes/bingo-saison/ill/` | Les 20 dessins du design system (220 px) choisis par Sam. |

---

## 2. Le produit, tel que décidé

| Règle | Valeur | Réglable |
|---|---|---|
| Une carte par saison | 13 semaines, un trimestre ; la suivante démarre le lundi d'après | dates |
| Une case par semaine | lundi → dimanche, heure de Bruxelles ; une case au plus par semaine | — |
| Seuil de la case | **19,90 €** d'achats dans la semaine, en une ou plusieurs fois | oui |
| La case se coche | toute seule : tickets identifiés du client (app ou carte QR scannée), hors tickets pro | — |
| Thème de chaque semaine | une fête de la saison, ou le produit « à l'honneur » ; un titre, un sous-titre, un dessin | oui |
| Un bon toutes les x cases | x = **3** → 4 bons, à la 3e, 6e, 9e et 12e case cochée (le compte des cases, pas des semaines d'affilée) | x ∈ {2, 3, 4} |
| Valeur des bons | **4,90 · 9,90 · 19,90 · 29,90 €**, chacun **plus gros que le précédent** ; au-delà de la liste, la dernière marche se répète | oui (liste) |
| Validité d'un bon | 30 jours, dès le lendemain du déblocage | oui |
| Carte pleine (13 / 13) | **le super bon cadeau ET le tirage au sort** (décision de Sam : « les deux ») | — |
| Super bon | **49,90 € provisoire**, à confirmer ; doit dépasser le dernier bon | oui |
| Tirage au sort | toutes les cartes pleines ; le lundi où la carte suivante arrive (22/02/2027 pour l'hiver) ; **1 an de pain**, un pain par jour à 1,50 € (365 pains, 547,50 € au prix de vente), 1 gagnant pour le réseau | prix, gagnants, portée |
| Food cost | 40 % : le coût réel d'un cadeau = sa valeur de vente × 40 % ; jamais montré au client | oui |

Le coût, pour un client qui ne manque aucune semaine : 114,50 € de cadeaux en vente, **45,80 € de coût réel**, sur
258,70 € d'achats au moins (155,22 € de marge brute) : 17,7 % de ses achats minimum, 109,42 € de marge qui reste.
Le tirage coûte 219 € (547,50 € × 40 %). L'écran Édition montre ces chiffres et les recalcule.

La saison d'hiver est en annexe A ; les saisons de l'année en annexe B.

---

## 3. Ce que tu construis (lot 1, cockpit)

Fichier serveur : `src/fidelite_bingo.php` (préfixe de fonctions `fbg`), requis dans `public/api/index.php` après
`fidelite_caisse.php`. Écrans : `public/assets/js/fidelite-bingo-vals.js` et `-tpl.js`, styles dans `fidelite.css`
(préfixe `fi-` ou `fb-`), branchés sur l'onglet existant comme `En caisse`.

### 3.1 Les tables (MySQL, `CREATE TABLE IF NOT EXISTS`, InnoDB, utf8mb4)

| Table | Une ligne = | Colonnes |
|---|---|---|
| `ceo_fid_bingo_saison` | une saison | `id`, `nom`, `du` (le lundi de la 1re semaine), `semaines` (13), `seuil`, `tous_les` (x), `bons_json` (la liste des valeurs), `validite`, `super_bon`, `tirage_actif`, `tirage_le`, `tirage_prix`, `tirage_jours`, `tirage_gagnants`, `tirage_portee` (`reseau` \| `magasin`), `food_cost`, `etat` (`brouillon` \| `publiee` \| `close`), `publie_le`, `publie_par`, `maj_le`, `maj_par` |
| `ceo_fid_bingo_semaine` | une semaine d'une saison | `saison_id`, `n`, `du`, `au`, `titre`, `sous_titre`, `type` (`fete` \| `honneur`), `fete`, `jour_fete`, `dessin` (nom du design system), `seuil` (figé à l'ouverture de la semaine) ; clé (`saison_id`, `n`) |
| `ceo_fid_bingo_palier` | un bon de la saison, ou le super bon | `saison_id`, `rang` (1…n ; 0 = super bon), `nom`, `dessin`, `valeur` ; clé (`saison_id`, `rang`) |
| `ceo_fid_bingo_campagne` | la campagne du panel d'un palier | `saison_id`, `rang`, `shop_id` (0 = réseau), `campagne`, `campagne_nom`, `verifie_le` |
| `ceo_fid_bingo_achat` | les achats d'un client un jour dans un magasin | `shop_id`, `jour`, `client_id`, `montant`, `tickets`, `lu_le` ; clé (`shop_id`, `jour`, `client_id`) |
| `ceo_fid_bingo_case` | une case cochée | `saison_id`, `client_id`, `n`, `cochee_le`, `montant` (la semaine au moment du cochage), `shop_id` (le magasin du ticket qui l'a cochée) ; clé (`saison_id`, `client_id`, `n`) |
| `ceo_fid_bingo_gain` | un bon débloqué ou le super bon | `id`, `saison_id`, `client_id`, `rang`, `valeur`, `code`, `code_id`, `campagne`, `des`, `fin`, `etat` (`a_creer` \| `cree` \| `echec` \| `utilise` \| `expire` \| `annule`), `essais`, `erreur`, `cree_le` ; uniques (`saison_id`, `client_id`, `rang`) et (`code`) |
| `ceo_fid_bingo_tirage` | un tirage | `saison_id`, `shop_id` (0 = réseau), `tire_le`, `tire_par`, `participants`, `empreinte` (sha256 de la liste triée des participants), `rang_tire`, `gagnant_client_id`, `etat` (`tire` \| `annonce` \| `remis`) |

Une fois la saison publiée, **les semaines passées sont figées** (thème et seuil) ; la semaine en cours garde son
seuil d'ouverture. Changer un seuil, x ou la liste des bons ne s'applique qu'aux semaines et aux bons à venir.

### 3.2 Les achats et les cases

1. **Le relevé des montants.** Dans `rpGraveListe()`, en plus de ce qu'il fait déjà, cumule par client et par jour
   le montant des tickets identifiés (`id_client` > 0), **hors tickets pro** (`is_client_b2b`), avec
   `total_gross_amount_after_discount` (le champ que lit déjà `src/caisse.php`), et écris `ceo_fid_bingo_achat`
   (remplacer la ligne du jour : un jour relu se réécrit). Pas de deuxième appel au panel. Une fonction
   `fbgGraveAchats(int $shop, string $jour, array $liste)`, appelée par `rpGraveListe()`, garde les deux modules
   séparés.
2. **Le calcul d'une semaine.** Pour une saison publiée et une semaine n : la somme des achats du client du lundi au
   dimanche, **tous magasins confondus**. Au seuil (≥), la case se coche (`cochee_le` = le jour où la somme a passé
   le seuil). **Une case cochée ne se décoche jamais**, même si un ticket est corrigé ensuite.
3. **Les déblocages.** Après chaque case cochée : si le nombre de cases cochées du client atteint k·x (k ≤ nombre de
   bons), crée le gain du rang k ; à la 13e case, crée le super bon (rang 0). Un gain par client, par saison et par
   rang : l'unicité de la table le garantit, un recalcul ne double rien.
4. **La fraîcheur.** Le relevé lit le jour en cours toutes les 10 minutes à la demande et au battement horaire
   (`RP_TTL_JOUR`). La case se coche donc dans l'heure. Ne promets pas mieux dans les textes ; une notification « en
   direct » demanderait un relevé plus serré : à proposer, pas à faire.

### 3.3 Les bons et le super bon

- **Le même mécanisme que le bon du « déjà venu »** (`src/fidelite_caisse.php`) : le cockpit tire le code (`BGO` + 8
  signes, alphabet sans 0/O ni 1/I), l'enregistre en `a_creer`, puis le crée au panel :
  `POST /admin/voucher-campaigns/{campagne}/codes` avec `code`, `status: ACTIVE`, `usage_limit: 1`,
  `valid_from` = le lendemain 00:00, `valid_to` = fin 23:59:59 ; si le panel refuse `valid_from`, une seconde fois
  sans. Repris au battement horaire, 5 essais puis `echec` (relance à la main depuis le Suivi).
- **La campagne** : celle du palier pour le réseau (`shop_id = 0`), sinon celle du magasin principal du client (où il
  a coché le plus de cases). Une campagne se relie depuis l'écran Édition, vérifiée au panel avec `pbFiche()` :
  codes uniques (`UNIQUE`), sans client imposé, active (sinon un avertissement).
- **L'utilisation et l'expiration** : relis l'état des codes au panel (`pbCodes()`, `usage_count`) au battement,
  par campagne, et passe les gains en `utilise` ; après `fin`, en `expire`. Ces deux états nourrissent le Suivi.
- **Ne journalise jamais un code en clair** (masque : 3 signes puis `…`), comme `tools/essai_voucher.php`.

### 3.4 Le tirage

- Possible quand la dernière semaine est terminée, et pas avant le jour `tirage_le`. Participants : les clients qui
  ont les 13 cases de la saison (par magasin principal si la portée est `magasin`).
- `POST` idempotent : un tirage déjà fait se relit, il ne se refait pas. Tire avec `random_int()` sur la liste triée
  des identifiants ; garde `participants`, `empreinte`, `rang_tire`, le gagnant ; journalise (`journalAdd`).
- La remise d'1 an de pain : **question ouverte (§7)**. Dans ce lot : le tirage, l'enregistrement, l'affichage, et
  le gagnant rendu à la PWA. Pas de codes quotidiens tant que la question n'est pas tranchée.

### 3.5 L'API

Même style que les routes fidélité existantes (`docs/contrat-api.md`, section Fidélité) ; documente chaque route dans
`docs/contrat-api.md` avec son contrat, avant de la coder.

| Méthode | Route | Accès | Rôle |
|---|---|---|---|
| GET | `/fidelite/bingo/saisons` | session | les saisons : nom, dates, état, semaine en cours |
| GET | `/fidelite/bingo/saison?id=` | session | une saison complète : règles, 13 semaines, paliers, campagnes, contrôles, coût (vente, réel, part des achats minimum) |
| PUT | `/fidelite/bingo/saison` | session | enregistre le brouillon ; refuse (422) ce qui casse une règle du §2 ; refuse de toucher une semaine passée d'une saison publiée |
| POST | `/fidelite/bingo/saison/publier` | session | publie après les contrôles bloquants (§3.6) ; journalisé |
| POST | `/fidelite/bingo/campagne` | session | relie une campagne à un palier (et un magasin), vérifiée au panel |
| GET | `/fidelite/bingo/suivi?saison=&shop=` | session | le suivi (§3.7) |
| POST | `/fidelite/bingo/tirage` | session | tire au sort (idempotent) |
| POST | `/fidelite/bingo/pousser` | session | relance la création des codes en échec |
| GET | `/fidelite/bingo/carte?client=` | **jeton de service** (`X-Service-Token`, ajoute la route à `erpRouteIntegration()`) | l'état de la carte d'un client, pour la PWA (§4) |

Le contrat de `GET /fidelite/bingo/carte` (à figer avec le dépôt WebShop) :

```json
{"saison": {"id": 1, "nom": "Hiver 2026 – 2027", "du": "2026-11-23", "au": "2027-02-21", "seuil": 19.9, "tousLes": 3},
 "semaineEnCours": {"n": 8, "du": "2027-01-11", "au": "2027-01-17", "depense": 6.4, "reste": 13.5, "cochee": false},
 "semaines": [{"n": 1, "du": "2026-11-23", "au": "2026-11-29", "titre": "Le cougnou est là", "sousTitre": "1er dimanche de l’Avent",
               "type": "fete", "fete": "Avent", "jourFete": "29/11", "dessin": "bread-2", "etat": "cochee", "cocheeLe": "2026-11-27"}],
 "bons": [{"rang": 1, "case": 3, "nom": "Bon de l’Avent", "dessin": "cake", "valeur": 4.9, "etat": "utilise",
           "code": null, "des": "2026-12-10", "fin": "2027-01-08"}],
 "superBon": {"valeur": 49.9, "etat": "possible", "encore": 7},
 "tirage": {"actif": true, "le": "2027-02-22", "lot": "1 an de pain", "etat": "possible", "gagnant": false},
 "lu": "2027-01-14T10:42:00+01:00"}
```

États d'une semaine : `cochee`, `en_cours`, `manquee`, `a_venir`. D'un bon : `a_venir`, `proche`, `pris` (le code est
alors rendu), `utilise`, `expire`, `perdu` (plus atteignable cette saison). Du super bon et du tirage : `possible`,
`obtenu` / `inscrit`, `perdu`. Le code d'un bon n'est rendu que pour son propre client, en état `pris`.

### 3.6 L'écran Édition (Fidélité › Bingo)

**Reproduis `edition-maquette.html`** : l'onglet « Bingo » à côté de Programme, Clients et En caisse.

- **Les saisons** : quatre cartes (état : en cours, brouillon, à préparer) et « + Nouvelle saison », qui part de la
  précédente décalée de 13 semaines.
- **Les règles** écrites en phrases aux valeurs modifiables sur place : le seuil ; x (2, 3, 4) ; la liste des bons
  (ajouter, retirer, chacun > au précédent) et leur validité ; le super bon ; le tirage (interrupteur, prix du pain,
  gagnants, réseau ou magasin, date) ; le food cost.
- **Les 13 semaines** : une ligne par semaine (dessin, titre, sous-titre, Fête ou À l'honneur, passée/en cours/à
  venir) ; un clic l'ouvre : titre, sous-titre, type, nom et jour de la fête, et le **sélecteur de dessins** (les 20
  du design system, nommés). Une semaine passée s'ouvre en lecture seule.
- **Les bons** : rang, nom, dessin, case qui le débloque, valeur, coût réel, campagne du panel (reliée ou à relier).
- **À droite** : l'aperçu de la carte (la grille x + 1 colonnes, le super bon en bout), le coût (échelle des
  cadeaux, tableau vente / réel / achats minimum / marge qui reste), et « Avant de publier ».
- **Les contrôles** — bloquants : chaque bon > au précédent ; super bon > dernier bon ; 13 semaines avec un titre et
  un dessin ; chaque fête dans sa semaine. Non bloquants (avertissements) : campagnes à relier pour les bons à
  venir ; super bon encore à 49,90 € provisoire.
- **La barre de publication** collée en bas : « n réglages modifiés », Annuler, Publier (désactivé tant qu'un
  contrôle bloquant échoue).

### 3.7 L'écran Suivi

Sous l'Édition (ou un second sélecteur Édition / Suivi), pour la saison et le magasin choisis : clients participants
(au moins une case), cases cochées par semaine, répartition des clients par nombre de cases (0 à 13), bons débloqués
et utilisés par palier, cartes pleines encore possibles et obtenues, coût réel engagé (gains débloqués × food cost)
et consommé (gains utilisés × food cost), codes en échec avec « Relancer », et le tirage (participants, gagnant).
Chiffres réels uniquement ; sans donnée, l'écran le dit.

### 3.8 Le battement

`fbgCron()` dans `ep_rapports_cron()` (comme `fcaCron()`) : recalcule les semaines dont les achats ont bougé, crée les
gains, pousse les codes, relit utilisations et expirations, clôt une saison terminée. Budget en temps, comme
`rpCron()` ; une phrase de retour pour le journal du cron.

### 3.9 Les tests

`bin/tests_fidelite_bingo.php`, sur le modèle de `bin/tests_fidelite_caisse.php`. Au moins :

1. Une semaine à 6,40 € n'est pas cochée ; 6,40 + 13,50 = 19,90 € la coche (≥) ; deux magasins s'additionnent ; un
   ticket pro ne compte pas ; la semaine se borne du lundi 00:00 au dimanche 23:59, heure de Bruxelles.
2. Une case cochée ne se décoche pas quand un ticket corrigé fait repasser la semaine sous le seuil.
3. x = 3 : les bons tombent à la 3e, 6e, 9e et 12e case cochée, une semaine manquée ne perd pas de bon ; x = 2 : 6
   bons, la dernière marche se répète (39,90 et 49,90 € après 29,90 €) ; un recalcul ne double aucun gain.
4. La 13e case : le super bon ; une semaine manquée le rend « perdu », et le tirage aussi.
5. Les codes : création au panel (corps exact), refus de `valid_from` puis succès, 5 refus → `echec`, relance ;
   aucun code en clair dans le journal.
6. L'enregistrement : une liste de bons qui ne monte pas, un super bon sous le dernier bon, une semaine passée
   modifiée → 422 ; la publication refuse tant qu'un contrôle bloquant échoue.
7. Le tirage : refusé avant la fin de la dernière semaine, idempotent, empreinte stable, seuls les 13 / 13
   participent ; portée magasin.
8. La route de la PWA : refusée sans jeton de service, ne rend que la carte du client demandé, et le code d'un bon
   seulement en état `pris`.

### 3.10 La documentation

`docs/contrat-api.md` (les routes), `pilotage-reseau/README.md` (une section, comme « Fidélité : la carte QR donnée
en caisse »), `docs/PORTAILS.md` (les tables au registre), et la consigne de la PWA (§4).

---

## 4. Hors lot : la PWA et la caisse

- **L'app du client (dépôt WebShop)** : écris `docs/consignes/bingo-pwa.md`, sur le modèle de
  `docs/consignes/caisse-fidelite-szymon.md` : l'onglet Bingo comme dans `bingo-maquette.html` (la grille, la
  semaine en cours avec sa jauge, les tampons, le portefeuille, la carte du tirage), la route
  `GET /fidelite/bingo/carte` et son contrat, le jeton de service lu dans `erp_cles` (jamais en dur), les dessins (§6),
  la notification après un ticket (dans l'heure : §3.2.4), et le lien entre le numéro de la carte QR donnée en caisse
  (`ceo_fid_carte.carte`) et le compte du client.
- **La caisse** : rien de neuf. Les bons sont des codes du panel, que la caisse valide déjà (`/vouchers/validate`,
  `/vouchers/redeem`). La carte QR donnée en caisse (`POST /fidelite/caisse/carte`) devient la carte de la saison.

---

## 5. Règles de travail

- Lis le code existant avant d'ajouter ; une donnée déjà relevée ne se relit pas une deuxième fois.
- Aucune URL, clé, jeton, identifiant de campagne ou de magasin en dur ; les campagnes se relient depuis l'écran.
- Aucun appel réseau dans les tests ; aucun essai d'écriture sur le vrai panel sans l'accord explicite de Sam.
- Chiffres réels à l'écran, ou l'écran dit qu'il n'a pas la donnée. Jamais de valeur inventée.
- Français partout ; apostrophes typographiques ; pas d'emoji dans l'interface.
- Commits atomiques en français (le serveur, l'écran, les tests, la documentation), poussés sur la branche de
  travail. Pas de déploiement (`deploy.trigger`) sans l'accord de Sam.

---

## 6. Les dessins

Les 20 dessins sont dans `docs/maquettes/bingo-saison/ill/` (220 px, transparents, trait rubis) : `artboard-4`
(étincelle), `automne`, `bread-1` (baguette), `bread-2` (pain ovale), `cake-slice`, `cake` (gâteau sous cloche),
`chocolat`, `cookies`, `crepes`, `croissant`, `cupcake`, `ete` (soleil), `galette`, `hiver` (flocon), `noel` (sapin),
`printemps` (fleur), `saint-nicolas`, `tarte`, `tartelette`, `valentin` (cœurs).

- Sers-les depuis `public/assets/img/fidelite/` (copie-les, noms inchangés) ; l'écran et la PWA les désignent par leur
  nom.
- Affichés autour de 30 à 50 px, leur trait est très fin : épaissis-le légèrement en CSS
  (`filter: drop-shadow(.3px 0 0 #8D1D2C) …`, comme les maquettes) ; blancs sur le rubis des bons
  (`brightness(0) invert(1)`).
- Cinq n'existent pas encore en 560 px dans `public/tablette/img` (crêpes, étincelle, gâteau sous cloche, fleur,
  soleil) : signale-le, ne les redessine pas.

---

## 7. Questions ouvertes — ne pas trancher seul

1. **Le type de promotion d'un bon de X €.** Les modules du panel connus d'ici (`src/promos_doc.php`) sont « acheter X
   obtenir Y », lots, quantité, remise programmée sur un produit, et les campagnes de bons. **Vérifie d'abord (en
   lecture) qu'une promotion du panel peut retirer un montant fixe du ticket.** Sinon, le cadeau devient un produit
   offert de cette valeur (4,90 €, 9,90 €…) : demande à Sam lequel par palier.
2. **Le montant du super bon** : 49,90 € est provisoire.
3. **Le tirage** : un gagnant pour le réseau ou un par magasin ; le règlement (participation sans obligation
   d'achat ?) à faire valider ; « un pain par jour » d'ouverture ou calendaire ; comment le remettre (365 codes d'un
   jour, ou une carte « 1 an de pain » contrôlée à la caisse).
4. **L'identité du client** : vérifie que `id_client` est le même dans tous les magasins (une personne = un
   identifiant réseau). Si un client a une fiche par magasin, les achats ne s'additionnent pas : signale-le.
5. **Le webshop** : vérifie que les commandes click & collect et livraison apparaissent dans
   `/shops/{id}/transactions` avec leur `id_client`. Sinon, elles ne cochent rien : signale-le.
6. **Les campagnes** : une campagne réseau par palier est-elle possible au panel, ou faut-il une campagne par
   magasin ?
7. Les semaines de fête : 19,90 € suffisent, ou faut-il le produit de la fête (la galette à l'Épiphanie) ?

---

## 8. Critères d'acceptation

- [ ] L'onglet Bingo reproduit `edition-maquette.html` ; publier est impossible tant qu'un contrôle bloquant échoue.
- [ ] Les achats d'un client se relèvent sans appel supplémentaire au panel, hors tickets pro, tous magasins.
- [ ] À 19,90 € dans la semaine, la case se coche au battement suivant ; jamais deux cases la même semaine.
- [ ] Les bons tombent à la bonne case, avec la bonne valeur, et leur code existe au panel, valable du lendemain à
      J + 30.
- [ ] La 13e case donne le super bon et inscrit au tirage ; le tirage est idempotent et traçable.
- [ ] `GET /fidelite/bingo/carte` rend le contrat du §3.5, refuse sans jeton de service, ne divulgue aucun code
      d'un autre client.
- [ ] `php bin/tests_fidelite_bingo.php` passe ; les tests existants passent toujours
      (`tests_fidelite_caisse`, `tests_bons`, `tests_pointage_app`, `tests_newsletter`).
- [ ] `docs/contrat-api.md`, `README.md`, `docs/PORTAILS.md` et `docs/consignes/bingo-pwa.md` sont à jour.
- [ ] La checklist §19 de `docs/API_STANDARD.md` est déroulée ; les écarts sont écrits, pas cachés.

---

## Annexe A — La saison d'hiver 2026 – 2027

Du lundi 23/11/2026 au dimanche 21/02/2027. Dates des fêtes vérifiées.

| S | Du – au | Titre | Sous-titre | Type | Fête · jour | Dessin |
|---|---|---|---|---|---|---|
| 1 | 23/11 – 29/11 | Le cougnou est là | 1er dimanche de l’Avent | Fête | Avent · 29/11 | `bread-2` |
| 2 | 30/11 – 06/12 | Saint-Nicolas | spéculoos et massepain | Fête | Saint-Nicolas · 06/12 | `saint-nicolas` |
| 3 | 07/12 – 13/12 | Le chocolat chaud | produit de la semaine | À l’honneur | | `chocolat` |
| 4 | 14/12 – 20/12 | Le pain d’épices | produit de la semaine | À l’honneur | | `cookies` |
| 5 | 21/12 – 27/12 | Noël | la bûche | Fête | Noël · 25/12 | `noel` |
| 6 | 28/12 – 03/01 | Nouvel An | les petits fours | Fête | Nouvel An · 01/01 | `artboard-4` |
| 7 | 04/01 – 10/01 | Épiphanie | la galette des rois | Fête | Épiphanie · 06/01 | `galette` |
| 8 | 11/01 – 17/01 | Le bon pain | produit de la semaine | À l’honneur | | `bread-1` |
| 9 | 18/01 – 24/01 | Blue Monday | un éclair pour le moral | À l’honneur | | `cake-slice` |
| 10 | 25/01 – 31/01 | Le croissant | produit de la semaine | À l’honneur | | `croissant` |
| 11 | 01/02 – 07/02 | Chandeleur | les crêpes | Fête | Chandeleur · 02/02 | `crepes` |
| 12 | 08/02 – 14/02 | Saint-Valentin | beignets de Mardi gras | Fête | Mardi gras · Saint-Valentin · 09 et 14/02 | `valentin` |
| 13 | 15/02 – 21/02 | La tarte du dimanche | produit de la semaine | À l’honneur | | `tarte` |

Les bons (x = 3) : Bon de l’Avent `cake` 4,90 € (3e case) · Bon des Rois `tartelette` 9,90 € (6e) · Bon de l’hiver
`hiver` 19,90 € (9e) · Bon carte pleine `cupcake` 29,90 € (12e) · Super bon cadeau `cake` 49,90 € (13e, provisoire).

## Annexe B — Les saisons de l'année

| Saison | Du | Au | Fêtes | Dessin |
|---|---|---|---|---|
| Hiver | 23/11/2026 | 21/02/2027 | Avent, Saint-Nicolas, Noël, Nouvel An, Épiphanie, Chandeleur, Mardi gras, Saint-Valentin | `hiver` |
| Printemps | 22/02/2027 | 23/05/2027 | Pâques 28/03, 1er avril, fête du travail, Ascension, fête des mères 09/05 | `printemps` |
| Été | 24/05/2027 | 22/08/2027 | fête des pères 13/06, fête de la musique, fête nationale 21/07, 15 août | `ete` |
| Automne | 23/08/2027 | 21/11/2027 | la rentrée, fête de la FWB 27/09, Halloween, Toussaint, Saint-Martin 11/11 | `automne` |

## Annexe C — Les fichiers de référence

| Fichier | Contenu |
|---|---|
| `docs/maquettes/bingo-saison/edition-maquette.html` | **L'écran à construire** (autonome, interactif) ; capture `edition.jpg` |
| `docs/maquettes/bingo-saison/bingo-maquette.html` | Ce que voit le client : la carte imprimée, l'app, les règles (autonome, avec une simulation) ; captures `planche.jpg`, `app*.jpg`, `carte.jpg` |
| `docs/maquettes/bingo-saison/claude-design.html` | La version de Sam dans Claude Design, gardée telle quelle |
| `docs/maquettes/bingo-saison/edition.html`, `planche.html`, `bingo.js`, `ill/`, `generer.js` | Les sources des maquettes ; `node docs/maquettes/bingo-saison/generer.js` (depuis `pilotage-reseau/`) refait les fichiers autonomes et les captures, et vérifie les parcours |
| `docs/maquettes/bingo-saison/LISEZMOI.md` | Le produit, les coûts, les décisions |
| `docs/consignes/caisse-fidelite-szymon.md` | La consigne de la caisse (le modèle pour celle de la PWA) |

La simulation des maquettes (tickets, semaine suivante) sert à montrer le produit : **ne la code pas**.
