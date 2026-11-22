# CLAUDE.md — commercial/ (portail Commercial · tables `sm_`)

Lire d'abord le `CLAUDE.md` de la racine (règles des domaines, clés, migrations).

- **Périmètre** : l'outil du commercial B2B des magasins (19/11/2026). Bureaux · webshop : leads Meta par magasin,
  assistant « Nouveau bureau », bureaux et clients, offres par magasin, bons, catalogues, échantillons, tableau de bord.
  B2B : suivi des offres par secteur et par magasin, gains du commercial (commissions par secteur, plancher mensuel).
  Maquettes validées : `docs/maquettes/portail-commercial/`.
- **La Prospection** (venue du Marketing le 19/11/2026, Sam : « transvaser tout le /prospection/ dans le /commercial ») :
  la liste de démarchage d'un magasin, la carte, le calcul du CA, les types d'offre. Page
  `public/commercial/prospection/` (servie à `/commercial/prospection/`, bureau et téléphone ; « Prospection » du rail
  l'affiche à côté du rail, vue `#prospection`, dans un cadre `?embed=1`, sans nouvel onglet),
  qui lit les fichiers communs du cockpit sous `../assets/` (alias `/commercial/assets/` de `shared/bin/deploy.sh`) ;
  l'ancienne adresse `/consulant_bo/prospection/` renvoie ici (`public/prospection/index.html`). Code :
  `src/prospection.php` (routes `/prospection/{shop}`, `/prospection/offres`, `prOffres`) et `src/scouting_osm.php` (le
  relevé OpenStreetMap de `/scouting/demarchage`, appelé par `shared/src/endpoints.php`).
- **Ma semaine et le suivi de terrain** (`src/commercial_semaine.php`) : l'assistant horizontal de la semaine (magasins,
  sociétés et créneaux, confirmations par e-mail, échantillons, appel au franchisé, récapitulatif) et l'app mobile
  `public/commercial/mobile/` (la journée ; par client, un assistant : le contact, ce qui a été fait, l'offre, la
  prochaine action et sa date, le récapitulatif). La suite proposée (`smSuiteProposee`) est la même règle dans
  `mobile.js` : changer l'une, c'est changer l'autre. Le portail ouvert sur un téléphone bascule sur `mobile/`
  (`?bureau=1` pour la version bureau) ; le cockpit l'ouvre par « Commercial ↗ » (rail, Applications).
- **Tables** : `sm_*` (`sm_prospect` pour la Prospection, `sm_client`, `sm_offre`, `sm_bon`, `sm_echantillon`, `sm_echantillon_photo`, `sm_envoi`, `sm_modele`, `sm_rdv`, `sm_cr`, `sm_appel_franchise`), créées au premier
  appel (`smTables`). Réglage : `smReglages` (`ceo_app_setting`, partagé) ; l'adresse e-mail des magasins lue au panel :
  `smEmailsMagasins` (un jour).
- **La garde** (`garde.js` du cockpit) : chaque page la charge à côté d'elle, par l'alias `/commercial/assets/`
  (`assets/js/garde.js` depuis le portail, `../assets/js/garde.js` depuis `mobile/` et `prospection/`) ; sans session,
  elle renvoie à la connexion du cockpit (`/acces/moi` rend son adresse, `cockpitUrl`), qui rouvre la page.
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
  le jeton de MétaDonnée ; ses fonctions seulement, jamais ses tables. Il se met en inactif (archivé chez Meta) et se
  réactive d'ici (`POST /commercial/formulaires/{id}/actif`) ; seuls les formulaires « Lunch au bureau » se touchent.
  La publicité qui va avec : la vidéo (15 s, 9:16 et 4:5, `tools/video-lunch/`, rendue dans `public/commercial/meta/`),
  le texte du post (`smPubLunch`) et l'aperçu du formulaire (`smApercuFormulaire` : accueil, champs, remerciement),
  `GET /commercial/formulaires/pub?langue=`, montrés dans Leads Meta. La version NL est celle de Halle (formulaire
  « LUNCH OP KANTOOR - NL », vidéo et post qui nomment L’Atelier By Halle).
- **La commande d'échantillon** (`src/commercial_commande.php`) : comme une commande du magasin, avec ses produits et ses
  catégories (TF Buddy, `GET /shops/{id}/products/available`), postée dans son système de commande (`POST /client-orders`,
  en formulaire) : commentaire « ÉCHANTILLON COMMERCIAL », lignes offertes (total 0 €), statut « nouvelle ». TF Buddy exige
  un client et un employé qui accepte (20/11/2026, décision de Sam) : le client « ÉCHANTILLONS COMMERCIAL » du magasin,
  créé une fois (`POST /clients`, réglage `smClientsEchantillons`), et l'employé choisi à la commande dans l'équipe du
  magasin (`GET /commercial/equipe-magasin`), retenu (`smEmployesEchantillons`, `sm_echantillon.employe_id`). Le numéro de
  la commande TF Buddy est gardé (`sm_echantillon.commande_id`) ; elle s'annule d'ici (`DELETE
  /commercial/echantillons/{id}/commande`) ; l'e-mail au magasin reste possible en plus.
- **La photo des échantillons reçus** (`src/commercial_photos.php`) : prise à la réception (app mobile ou version bureau),
  réduite par le navigateur, rangée dans le dossier privé (`…/prive/commercial_echantillons`), lue par l'API seulement ;
  l'échantillon passe à « reçu ».
- **Lit ailleurs, jamais dans leurs tables** : les leads par MétaDonnée (`ep_md_leads`), les catalogues par le relais du Marketing (`cmRoute`),
  les tickets pro par le relevé du panel (`vpLu` / `vpJour`), les magasins par `ep_stores`.
- **E-mails** : `Smtp::envoyer` du cockpit, en-tête From réglable (défaut : hello@atelierby.be). Un compte d'envoi propre
  à hello@ (SPF, DKIM) reste à régler.
- **À brancher** (dit tel quel à l'écran) : le compte du bureau au webshop et sa tournée (API TF Buddy, avec Szymon), les
  cartes de fidélité office, les bons en caisse (Promotions du magasin).
- **Tests** : `php commercial/bin/tests_commercial.php`, `php commercial/bin/tests_prospection_offres.php`.
