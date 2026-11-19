# CLAUDE.md — commercial/ (portail Commercial · tables `sm_`)

Lire d'abord le `CLAUDE.md` de la racine (règles des domaines, clés, migrations).

- **Périmètre** : l'outil du commercial B2B des magasins (19/11/2026). Bureaux · webshop : leads Meta par magasin,
  assistant « Nouveau bureau », bureaux et clients, offres par magasin, bons, catalogues, échantillons, tableau de bord.
  B2B : suivi des offres par secteur et par magasin, gains du commercial (commissions par secteur, plancher mensuel).
  Maquettes validées : `docs/maquettes/portail-commercial/`.
- **Tables** : `sm_*` (`sm_client`, `sm_offre`, `sm_bon`, `sm_echantillon`, `sm_envoi`, `sm_modele`), créées au premier
  appel (`smTables`). Réglage : `smReglages` (`ceo_app_setting`, partagé) ; l'adresse e-mail des magasins lue au panel :
  `smEmailsMagasins` (un jour).
- **Code** : `src/commercial.php` (routes `/commercial/…`, `smRoute`), `src/commercial_gains.php` (le calcul des gains,
  sans base : `smGainsCalcul`). La page `public/commercial/` est servie à `/commercial/`, son API à
  `/commercial/api/cockpit/commercial/…` (alias Apache de `shared/bin/deploy.sh`, routeur `shared/public/api/index.php`).
- **Lit ailleurs, jamais dans leurs tables** : les leads par MétaDonnée (`ep_md_leads`), la liste de démarchage par
  Prospection (`ep_prospection`), les types d'offre (`prOffres`), les catalogues par le relais du Marketing (`cmRoute`),
  les tickets pro par le relevé du panel (`vpLu` / `vpJour`), les magasins par `ep_stores`.
- **E-mails** : `Smtp::envoyer` du cockpit, en-tête From réglable (défaut : hello@atelierby.be). Un compte d'envoi propre
  à hello@ (SPF, DKIM) reste à régler.
- **À brancher** (dit tel quel à l'écran) : le compte du bureau au webshop et sa tournée (API TF Buddy, avec Szymon), la
  commande d'échantillon dans le système de commande (elle part au magasin par e-mail en attendant), les cartes de
  fidélité office, les bons en caisse (Promotions du magasin).
- **Tests** : `php commercial/bin/tests_commercial.php`.
