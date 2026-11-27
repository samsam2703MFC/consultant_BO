# Demandes hors zone : « Créer le bureau » et la poubelle — plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** sur chaque demande hors zone de Clients › Bureaux, deux actions : « Créer le bureau » (fenêtre « Nouveau bureau en développement » pré-remplie) et une poubelle (efface la copie du cockpit).

**Architecture:** côté serveur (`panel/src/bureaux_dev.php`), une route `POST /dashboard/bureaux-dev/effacer` et un marquage `reprise` de la demande hors zone quand `POST /dashboard/bureaux-dev` reçoit `hors_zone`. Côté écran (`panel/public/dashboard/clients.js`), les deux boutons, la fenêtre pré-remplie, et les demandes reprises repliées derrière un lien.

**Tech Stack:** PHP 8.1+ sans framework, JavaScript vanilla, tests PHP sans base (`panel/bin/tests_bureaux_dev.php`).

**Spec:** `docs/superpowers/specs/2026-11-27-demandes-hors-zone-actions-design.md`

## Global Constraints
- Tout en français : code commenté, interface, commits.
- La poubelle n'efface qu'une ligne `source = "hors_zone"` du magasin ; l'original reste dans TF Buddy.
- Texte de confirmation : « Effacer cette demande hors zone ? La copie du cockpit disparaît ; l’original reste dans TF Buddy. »
- Nouveau statut : `reprise` (colonne `statut` VARCHAR(20) : pas de migration) ; la purge des 90 jours le couvre déjà.
- Après une route : `python3 panel/tools/dashboard-api-index.py`, la route dans `shared/docs/contrat-api.md`, une ligne dans `shared/deploy.trigger`.

## Review Focus
- Une demande d'un autre magasin (id d'un autre `shop_id`) : ni effacée, ni marquée — `bdvDemande($shop, $id)` filtre déjà par magasin (test : ligne nulle → 404).
- Une inscription du formulaire (`source = questionnaire`) envoyée à `/effacer` : refusée, 404 (test).
- « Créer le bureau » sur une demande déjà reprise : la nouvelle demande se crée, l'ancienne ne bouge pas (test `bdvHorsZoneReprendre` → false).
- La création échoue (champ manquant, 422) : la demande hors zone reste ouverte (le marquage suit `bdvCreer`, jamais avant).
- Double clic sur la poubelle : bouton désactivé pendant l'envoi (`B.hzBusy`).

---

### Task 1: le serveur — effacer, et marquer « reprise »

**Files:**
- Modify: `panel/src/bureaux_dev.php` (`BDV_STATUTS`, bloc hors zone ~l. 444–630, `wr_bureaux_dev_creer`, `bdvRoute`)
- Test: `panel/bin/tests_bureaux_dev.php`

**Interfaces:**
- Produces: `bdvHorsZoneReprendre(string $shop, int $hzId, int $demandeId): bool` ; `wr_bureaux_dev_effacer(): array` → `['ok' => true]` ou 404 `['error' => 'demande hors zone introuvable']` ; `wr_bureaux_dev_creer()` lit `hors_zone` et ajoute `'reprise' => bool` à sa réponse ; route `POST /dashboard/bureaux-dev/effacer {shop, id}`.

- [ ] **Step 1: tests qui échouent.** Le faux `Db::row` rend `Db::$ligne` (nouvelle propriété statique, `null` par défaut : les tests existants ne changent pas). Section « Effacer et reprendre une demande hors zone » :
  - `effacer : inconnue → refusée, rien d’effacé` — `Db::$ligne = null`, corps `['shop' => '2', 'id' => 9]` → `error === 'demande hors zone introuvable'`, aucun `DELETE` dans `Db::$exec`.
  - `effacer : une inscription du formulaire ne s’efface pas d’ici` — ligne `source = questionnaire` → même refus, aucun `DELETE`.
  - `effacer : la copie hors zone disparaît` — ligne `['id' => 9, 'shop_id' => '2', 'source' => 'hors_zone', 'statut' => 'hors_zone', 'donnees' => '{"cp":"1370"}']` → `['ok' => true]`, un `DELETE` avec `[9, '2']`.
  - `reprise : passe « reprise », garde le n° de la nouvelle demande` — même ligne, `bdvHorsZoneReprendre('2', 9, 15) === true`, un `UPDATE` contenant `statut = "reprise"`, ses données contiennent `"_reprise":15`, id 9.
  - `reprise : déjà reprise ou absente, rien ne bouge` — ligne `statut = reprise` → `false` sans `UPDATE` ; ligne nulle → `false`.
- [ ] **Step 2:** `php panel/bin/tests_bureaux_dev.php` → échoue (fonctions inconnues).
- [ ] **Step 3: le code.** `'reprise'` dans `BDV_STATUTS` ; `bdvHorsZoneReprendre` (lit par `bdvDemande`, n'agit que sur `source` et `statut` `hors_zone`, `traite_le = NOW()`) ; `wr_bureaux_dev_effacer` (`DELETE … WHERE id = ? AND shop_id = ? AND source = "hors_zone"`, `journalAdd` si présent) ; `wr_bureaux_dev_creer` appelle `bdvHorsZoneReprendre` après `bdvCreer` si `(int) $b['hors_zone'] > 0` ; le `case` dans `bdvRoute` ; l'en-tête du bloc hors zone documente les deux routes.
- [ ] **Step 4:** `php panel/bin/tests_bureaux_dev.php` → `0 KO` ; `php shared/bin/tests_noms_globaux.php` → « aucune en double » ; `php shared/bin/test_chargement.php` → « tous les points d'entrée se chargent ».
- [ ] **Step 5:** commit « Bureaux › demandes hors zone : effacer la copie, marquer « reprise » une demande reprise en bureau ».

### Task 2: l'écran, la doc, la livraison

**Files:**
- Modify: `panel/public/dashboard/clients.js` (`ICO`, `blocHorsZone`, fenêtre `dev-nouveau`, actions `hz-creer`, `hz-effacer`, `hz-reprises`, `dev-nouveau-ok`)
- Modify: `panel/public/dashboard/clients.css` (près de la l. 316), `panel/public/dashboard/api-index.json` (régénéré), `shared/docs/contrat-api.md`, `panel/src/LISEZMOI.md`, `panel/CLAUDE.md`, `shared/deploy.trigger`

**Interfaces:**
- Consumes: Task 1 — `POST /dashboard/bureaux-dev/effacer {id}`, `POST /dashboard/bureaux-dev {…, hors_zone}`, `statut === 'reprise'`, `d._reprise` (id de la demande créée).

- [ ] **Step 1: les boutons.** `ICO.corbeille = 'M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14'` (celui de `boite.js`). Dans `blocHorsZone` : le compteur et le tableau ne prennent que les demandes non reprises ; dernière cellule : « Renvoyer » (si pas reçue), « Créer le bureau » (`data-act="hz-creer"`), poubelle (`btn--icon`, `aria-label` et `title` « Effacer la demande », `data-act="hz-effacer"`), désactivés si `B.hzBusy === x.id`. Sous le tableau, `voir/masquer les N demande(s) reprise(s)` (`data-act="hz-reprises"`, `B.hzReprises`) ; une ligne reprise montre `reprise` et « Ouvrir la demande » (`data-act="dev-sel"`, `data-id = d._reprise`) et garde sa poubelle.
- [ ] **Step 2: la fenêtre pré-remplie.** `hz-creer` ouvre `dev-nouveau` avec `email`, `societe`, `postal_code = x.cp`, `city = x.ville`, `personnes`, `remarque` « Demande hors zone reçue le JJ/MM/AAAA (page atelierby.be | page du webshop) », `bon: true`, `horsZone: x.id`. Avec `horsZone`, titre « Créer le bureau depuis une demande hors zone » et aide « Complétez après avoir appelé le prospect : la demande ne donne ni nom, ni téléphone, ni adresse. » `dev-nouveau-ok` envoie `hors_zone: f.horsZone || null`.
- [ ] **Step 3: la poubelle.** `hz-effacer` : `confirm` (texte des contraintes), `B.hzBusy`, `envoyer('/dashboard/bureaux-dev/effacer', { id })`, `B.hzMsg` « Demande effacée du cockpit. » ou l'erreur, `lireDev()`.
- [ ] **Step 4: vérifier.** `node --check panel/public/dashboard/clients.js` ; un rendu dans Chromium (Playwright) de `blocHorsZone` avec une demande ouverte et une reprise : les trois boutons, le lien des reprises, la fenêtre pré-remplie au clic.
- [ ] **Step 5: doc et index.** Les deux routes dans `shared/docs/contrat-api.md` (bloc hors zone) ; une phrase dans `panel/src/LISEZMOI.md` et `panel/CLAUDE.md` ; `python3 panel/tools/dashboard-api-index.py` ; une ligne datée dans `shared/deploy.trigger`.
- [ ] **Step 6:** les quatre commandes de test de la Task 1 → vertes ; commit « Bureaux › demandes hors zone : « Créer le bureau » (fenêtre pré-remplie) et la poubelle ».
