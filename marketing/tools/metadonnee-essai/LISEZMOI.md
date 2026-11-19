# Kit d'essai MétaDonnée — tester les données et les connexions sans Meta, sans ERP, sans SMSAPI

Un faux serveur (`router.php`) joue les trois services extérieurs du module MétaDonnée :

| Service | Chemin du faux | Ce qu'il fait |
|---|---|---|
| Meta Graph | `/graph/v25.0/…` | « Connecter et tester » du CRM (`debug_token`, échange `tok-system` → `tok-page-501`, clé `secret-app`, application `4242`), UNE Page (`501`) et ses formulaires — bureaux et candidats —, leurs leads (`/{form}/leads`, `/{lead}`), l'abonnement (`subscribed_apps`), l'adresse de rappel de l'application (`4242/subscriptions` : d'office celle du CRM ; `etat/rappel-libre.flag` la libère) |
| Panel / ERP | `/panel/api/v1/…` | connexion consultant, `POST /consultant/leads` (idempotent, 422 sans téléphone, 409 en double), `GET /consultant/leads?ids=` |
| SMSAPI | `/smsapi/…` | `sms.do` (les SMS sont gardés dans `etat/sms.json`), `profile` (jeton `tok-sms`) |

Les données sont fictives (entreprises, contacts, numéros) ; les magasins, réels.

## Lancer

```bash
# 1. une base locale (MariaDB/MySQL) et config/config.php pointé dessus, avec :
#    'panelApi' => ['base' => 'http://127.0.0.1:8098/panel/api/v1', 'phone' => '0470000000', 'password' => 'x'],
#    'smsapi'   => ['token' => 'tok-sms', 'base' => 'http://127.0.0.1:8098/smsapi'],
# 2. les leads de Meta, datés par rapport à maintenant
php tools/metadonnee-essai/generer.php
# 3. le faux serveur, puis le cockpit avec Meta redirigé vers lui
php -S 127.0.0.1:8098 tools/metadonnee-essai/router.php &
META_GRAPH_BASE=http://127.0.0.1:8098/graph/v25.0 php -S 127.0.0.1:8090 -t public public/router.php &
```

Les clés Meta sont celles du CRM, lues dans sa table `crm_settings` : dans la base locale,

```sql
CREATE TABLE IF NOT EXISTS crm_settings (skey VARCHAR(64) PRIMARY KEY, svalue TEXT);
REPLACE INTO crm_settings VALUES ('meta_app_id','4242'),('meta_app_secret','secret-app'),('meta_page_id','501'),('meta_page_token','tok-system');
```

Puis http://127.0.0.1:8090/#/metadonnee-formulaires, carte « Connexion Meta » (« Clés du CRM » : application `4242`,
Page `501`), « 🔌 Connecter et tester » (`tok-system` échangé contre `tok-page-501`, gardé côté Cockpit ; l'application
étant celle du CRM : mode relève). « Clés propres » : les mêmes valeurs saisies à l'écran. Puis suivre les formulaires, enregistrer
les mappings, « Récupérer 30 jours », allumer l'envoi (étape 2) et les SMS (étape 4), « Lancer le cycle maintenant ».
`php tools/metadonnee-essai/franchises.php` fait « travailler » les franchisés dans le faux ERP (vu, contacté,
bureau créé, bureau connecté, perdu) ; le cycle suivant le relit.

« 🔍 Tester les connexions » et « Envoyer un lead d'essai » (étape 2) marchent contre le faux comme contre les vrais.
Un webhook signé se rejoue ainsi (clé `secret-app`) :

```bash
CORPS='{"object":"page","entry":[{"id":"501","changes":[{"field":"leadgen","value":{"leadgen_id":"7101","form_id":"9001","page_id":"501"}}]}]}'
SIG="sha256=$(printf '%s' "$CORPS" | openssl dgst -sha256 -hmac secret-app | awk '{print $2}')"
curl -X POST http://127.0.0.1:8090/api/cockpit/metadonnee/meta/leads -H "X-Hub-Signature-256: $SIG" -d "$CORPS"
```

Avec les VRAIS services, Meta fournit un outil qui crée un lead de test sur un formulaire :
developers.facebook.com/tools/lead-ads-testing (le formulaire suivi ; en mode relève, il arrive au Cockpit dans les 2 minutes).

À savoir : une base NEUVE ne s'installe pas d'un bloc — `sql/schema.sql` laisse la table
`ceo_scouting_population` sans parenthèse fermante. Pour un essai local, fermer cette table dans une copie du fichier
et la charger (`mysql … < schema-local.sql`) ; une base en service n'est pas concernée.

Tests de la logique, sans rien de tout cela : `php bin/tests_metadonnee.php`.
