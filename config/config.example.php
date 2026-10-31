<?php
/**
 * Configuration — copiez ce fichier vers config/config.php SUR LE SERVEUR
 * (hors Git, comme le config/db.local.php du panel consultant) et renseignez
 * les identifiants. Les variables d'environnement, si présentes, priment.
 *
 * En production, pointez sur la base du panel consultant (atelierby_db) :
 * le cockpit y lit of_tag / kpi / position et mac_report_share, et y crée
 * ses propres tables préfixées ceo_.
 */
return [
    'db' => [
        'host'     => getenv('COCKPIT_DB_HOST') ?: '127.0.0.1',
        'port'     => getenv('COCKPIT_DB_PORT') ?: '3306',
        'name'     => getenv('COCKPIT_DB_NAME') ?: 'atelierby_db',
        'user'     => getenv('COCKPIT_DB_USER') ?: 'REMPLACER_USER',
        'password' => getenv('COCKPIT_DB_PASSWORD') ?: 'REMPLACER_PASS',
        'charset'  => 'utf8mb4',
    ],
    // Base d'URL du panel consultant (rapports) — prime sur le paramètre
    // pwaBase de ceo_app_setting. Ex. : http://185.180.206.46/pwa_consultant
    'pwaBase' => getenv('COCKPIT_PWA_BASE') ?: null,

    // Écran de connexion intégré. false (défaut) : accès ouvert — les
    // utilisateurs arrivent redirigés depuis l'ERP, qui porte l'auth.
    // true : mot de passe défini au premier lancement, session 30 jours.
    'auth' => (getenv('COCKPIT_AUTH') ?: '0') === '1',

    // Clé Google (Places API New) pour la Réputation digitale — notes, nombre
    // d'avis et cinq derniers avis des fiches magasin. Elle se saisit
    // normalement dans Paramètres (elle vit alors dans ceo_app_setting) ; la
    // poser ici sert quand on n'a pas encore accès à l'écran, ou quand on
    // préfère garder les secrets hors base. Ordre de priorité :
    // réglage Paramètres, puis cette valeur, puis GOOGLE_API_KEY.
    'googleApiKey' => getenv('GOOGLE_API_KEY') ?: null,

    // Brand Guard — accès Meta (lecture seule des pages Facebook du réseau).
    // Les trois valeurs viennent du Business Manager et de l'app Meta (voir
    // docs/brand-guard.md). Les variables d'environnement priment.
    'meta' => [
        'systemToken'  => getenv('META_SYSTEM_TOKEN') ?: null,   // token longue durée du System User
        'appSecret'    => getenv('META_APP_SECRET') ?: null,     // Paramètres de l'app → De base → Clé secrète
        'verifyToken'  => getenv('META_VERIFY_TOKEN') ?: null,   // chaîne libre, la même dans l'abonnement webhook
        'graphVersion' => 'v21.0',
    ],

    // MétaDonnée — SMS au gérant du magasin à chaque lead Meta (SMSAPI, comme le CRM).
    // Jeton OAuth avec la permission « sms » ; plateforme .com (internationale) ou .pl.
    // Il peut aussi se saisir à l'écran (MétaDonnée → 4. Alertes SMS), qui prime.
    'smsapi' => [
        'token' => getenv('SMSAPI_TOKEN') ?: null,
        'base'  => getenv('SMSAPI_BASE') ?: 'https://api.smsapi.com',
    ],
    // SMS (14/10/2026) : la vendeuse reçoit un SMS quand elle gagne une prime (Équipe & ventes ›
    // Paramètres, « SMS à la réussite »). Un compte Twilio : SID, jeton, et l'expéditeur (un numéro
    // Twilio au format +32…, ou un nom alphanumérique de 11 caractères au plus). Sans ces trois valeurs,
    // aucun SMS ne part. Les variables d'environnement priment.
    'sms' => [
        'fournisseur' => 'twilio',
        'sid'         => getenv('TWILIO_SID') ?: null,
        'token'       => getenv('TWILIO_TOKEN') ?: null,
        'expediteur'  => getenv('SMS_EXPEDITEUR') ?: null,
    ],

    // Jeu de démonstration. false (défaut) : base vide, prête pour les vraies
    // données — aucune donnée fictive n'est chargée. true (ou COCKPIT_SEED=1) :
    // charge sql/seed.sql (réseau belge de démo) si la base est vide.
    'seed' => (getenv('COCKPIT_SEED') ?: '0') === '1',
];
