# CLAUDE.md — commercial/ (portail Commercial · tables `sm_`)

Lire d'abord le `CLAUDE.md` de la racine (règles des domaines, clés, migrations).

- **Périmètre** : l'outil du commercial B2B des magasins (19/11/2026). Bureaux · webshop : leads Meta par magasin,
  assistant « Nouveau bureau », bureaux et clients, offres par magasin, bons, catalogues, échantillons, tableau de bord.
  B2B : suivi des offres par secteur et par magasin, gains du commercial (commissions par secteur, plancher mensuel).
  Maquettes validées : `docs/maquettes/portail-commercial/`.
- **Ma semaine et le suivi de terrain** (`src/commercial_semaine.php`) : l'assistant horizontal de la semaine (magasins,
  sociétés et créneaux, confirmations par e-mail, échantillons, appel au franchisé, récapitulatif) et l'app mobile
  `public/commercial/mobile/` (la journée ; par client, un assistant : le contact, ce qui a été fait, l'offre, la
  prochaine action et sa date, le récapitulatif). La suite proposée (`smSuiteProposee`) est la même règle dans
  `mobile.js` : changer l'une, c'est changer l'autre. Le portail ouvert sur un téléphone bascule sur `mobile/`
  (`?bureau=1` pour la version bureau) ; le cockpit l'ouvre par « Commercial ↗ » (rail, Applications).
- **Tables** : `sm_*` (`sm_client`, `sm_offre`, `sm_bon`, `sm_echantillon`, `sm_envoi`, `sm_modele`, `sm_rdv`, `sm_cr`, `sm_appel_franchise`), créées au premier
  appel (`smTables`). Réglage : `smReglages` (`ceo_app_setting`, partagé) ; l'adresse e-mail des magasins lue au panel :
  `smEmailsMagasins` (un jour).
- **Code** : `src/commercial.php` (routes `/commercial/…`, `smRoute`), `src/commercial_gains.php` (le calcul des gains,
  sans base : `smGainsCalcul`). La page `public/commercial/` est servie à `/commercial/`, son API à
  `/commercial/api/cockpit/commercial/…` (alias Apache de `shared/bin/deploy.sh`, routeur `shared/public/api/index.php`).
- **Le magasin d'un bureau** : son code postal (`smCpDefaut`, réglable par magasin : `cps`) ; son nom, cherché dans Google
  Places (`GoogleApi::chercher`). Un lead Meta sans magasin prend celui de son code postal. Les formulaires Meta de
  recrutement (candidats, franchisés) sont signalés et ne se suivent pas d'ici.
- **Les gains** (`src/commercial_gains.php`) : trois paliers (20 % le 1er mois, 10 % jusqu'au 3e, 5 % jusqu'au 12e), une
  règle par secteur, commande minimum, plancher, objectif mensuel, coût et ROI ; le taux de départ payé par le franchisé
  avec ses royalties (`/commercial/commissions`) ; la simulation d'un client (`/commercial/simulation`, dans « Ma semaine »).
- **Le formulaire Meta des bureaux** (`src/commercial_meta.php`) : « Lunch au bureau » créé sur la Page par l'API Graph, avec
  le jeton de MétaDonnée ; ses fonctions seulement, jamais ses tables.
- **Lit ailleurs, jamais dans leurs tables** : les leads par MétaDonnée (`ep_md_leads`), la liste de démarchage par
  Prospection (`ep_prospection`), les types d'offre (`prOffres`), les catalogues par le relais du Marketing (`cmRoute`),
  les tickets pro par le relevé du panel (`vpLu` / `vpJour`), les magasins par `ep_stores`.
- **E-mails** : `Smtp::envoyer` du cockpit, en-tête From réglable (défaut : hello@atelierby.be). Un compte d'envoi propre
  à hello@ (SPF, DKIM) reste à régler.
- **À brancher** (dit tel quel à l'écran) : le compte du bureau au webshop et sa tournée (API TF Buddy, avec Szymon), la
  commande d'échantillon dans le système de commande (elle part au magasin par e-mail en attendant), les cartes de
  fidélité office, les bons en caisse (Promotions du magasin).
- **Tests** : `php commercial/bin/tests_commercial.php`.
