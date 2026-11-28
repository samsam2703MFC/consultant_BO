# Clients › SMS : annoncer le webshop aux clients du magasin (maquettes)

**Statut : maquette, rien n'est codé (28/11/2026).** La demande, depuis
`dashboard/?shop=4&vue=clients` : « créer un SMS-letter : bonjour, notre webshop est online, encodez-vous via ce lien
(juste la récupération du mot de passe via le webshop, à vérifier) et vous pouvez utiliser votre bon de bienvenue
(50 % sur une tarte) ; analyse tout, fais le plan, puis une maquette, et on décide ».

| Capture | Ce qu'elle montre |
|---|---|
| `ecran-envoi.png` (`?v=envoi`) | l'onglet SMS de Clients : qui reçoit, le message FR / NL, le bon, l'essai et l'envoi, l'aperçu sur le téléphone |
| `ecran-parcours.png` (`?v=parcours`) | le client : le SMS, sa page à son nom, le code SMS du webshop, « Mes bons » au webshop |
| `ecran-suivi.png` (`?v=suivi`) | après l'envoi : remis, ouverts, comptes activés, bons utilisés ; la relance (chiffres fictifs) |

Régénérer : `node panel/docs/maquettes/sms-webshop/capturer.js` (aucun serveur).

## Ce qui a été vérifié le 28/11/2026

**Les clients de Halle** (`GET /shops/4/clients`, lu par le cockpit, compté sans rien garder) :

- 680 particuliers, 0 entreprise ;
- 612 GSM belges lisibles, environ 590 numéros différents ;
- 84 clients ont un e-mail ;
- environ 80 se sont déjà connectés au webshop (`last_login_at`) ;
- langue : 45 FR, 33 NL, 602 inconnue ;
- **598 clients n'ont pas `phone_e164`** : leur numéro n'existe que tel qu'il a été tapé, dans `phone`. Ce sont les clients créés en caisse, sans e-mail ni mot de passe.

**Le webshop en ligne** (`webshop.atelierby.be`, le bundle publié, et le dépôt `TFB-WebShop`) :

- Il parle à TF Buddy (`/api/v1/clients/auth/…`) ; `php-api/` n'est plus que l'ancien relais, et le `CLAUDE.md` du dépôt webshop est en retard.
- **Le « Mot de passe oublié » marche par SMS** : `POST /clients/auth/password/reset/sms/request {phone, locale}`, puis un code à 6 chiffres valable 3 minutes, puis `…/sms/confirm`. Il ne connecte pas le client : celui-ci se connecte ensuite avec son GSM et son nouveau mot de passe.
- **Il n'y a pas de lien d'invitation** pour un particulier, ni de lien qui ouvre l'inscription ou le « mot de passe oublié ».
- **`?voucher=CODE` ne sert à rien** : le webshop lit le paramètre puis l'efface au chargement.
- Un bon au nom du client apparaît dans « Mon compte › Mes bons », avec un bouton « Utiliser ». Un code commun se tape au panier.
- L'inscription demande un e-mail. Un client déjà connu (son GSM est dans TF Buddy) reçoit 409, et le webshop lui dit d'utiliser « mot de passe oublié ».

**Le bon de bienvenue** :

- Il n'existe pas tout seul : c'est une promotion du magasin « avec bon » (module Promotions, `marketing/src/promos_bons.php`), comme celui du questionnaire Bureaux (`bdvBon`).
- Il faut cibler les catégories du groupe Tartes (27) : 81125 Ø 28 cm, 11210 Fruits frais, 11220 Croûtes, 11101 et 11102 Tartissières, 11400 Tartocake.
- Il faut limiter le bon à la portion **entière**, sinon les 50 % passent sur un quart.
- On n'a pas pu vérifier depuis la session cloud si cette promotion existe : `/admin/*` répond 401.

**L'envoi des SMS** :

- `shared/src/sms.php` (`smsPartir`) envoie déjà, par SMSAPI (la clé `smsapi_token` d'`erp_cles`), sinon par Twilio.
- Le module Newsletter (`marketing/src/newsletter*.php`) ne fait partir aucun SMS (mode test). C'est un outil de la marque, pas du magasin.
- Le compte SMSAPI est polonais. L'envoi vers la Belgique peut devoir y être activé (erreur 112).

**Le cadre légal** : TF Buddy n'a pas de case « accepte les SMS ». Un SMS publicitaire n'est admis qu'à un client qui a
déjà acheté, pour des produits semblables, avec un moyen gratuit de se désinscrire dans **chaque** SMS. D'où le lien
STOP et la liste des STOP.

**À signaler à TF Buddy** : `GET /shops/{s}/clients` renvoie `password_hash` et `iban` au jeton du cockpit. Le cockpit
les retire (`pclSecret`), mais TF Buddy ne devrait pas les envoyer.

## Deux façons de faire

| | A · Lien direct au webshop | B · Lien court personnel (conseillé) |
|---|---|---|
| Le lien | `webshop.atelierby.be/?shop=4` | `atelierby.be/w/7KQ2MP` → une page à son nom |
| Le mot de passe | le client trouve lui-même « Mot de passe oublié › SMS » et retape son GSM | « Créer mon mot de passe » : son GSM est déjà connu, le code part tout de suite |
| Le bon | code commun tapé au panier, ou code personnel dans « Mes bons » | code personnel affiché sur sa page et dans « Mes bons » |
| Le SMS | 183 caractères : 2 SMS par client | 158 (FR) / 144 (NL) : 1 SMS |
| Le suivi | envoyé, remis, bon utilisé | en plus : lien ouvert, compte activé ; relance ciblée |
| À construire | l'onglet SMS, l'envoi par lots, le journal, la page STOP | en plus : la page publique, le relais du code SMS, le lien court à la racine d'atelierby.be |

## Le plan (pour B ; A s'arrête à l'étape 3 sans la page)

0. **Essayer avant de coder** (30 minutes, avec un GSM du magasin) :
   - créer en caisse un client avec ce seul GSM ;
   - au webshop `?shop=4`, faire « Mot de passe oublié › SMS » : le code arrive-t-il ? la connexion marche-t-elle ? est-ce la même fiche client ?
   - si le code ne part pas parce que `phone_e164` est vide, regarder si un `PATCH /clients/{id} {phone}` le remplit ;
   - envoyer un SMS SMSAPI vers un numéro belge ;
   - créer la promotion « 50 % sur une tarte entière » avec bon, puis vérifier qu'un code personnel s'affiche dans « Mes bons » et s'applique au panier.
1. **Le bon** : la promotion se crée dans Promotions. Les codes personnels se créent à l'envoi, un par client (`POST /admin/voucher-campaigns/{id}/codes`, comme `bdvBon`). La cible « clients » de Promotions s'arrête aujourd'hui à 100 clients (`PB_MAX_CLIENTS`).
2. **Le serveur** `panel/src/sms_clients.php` :
   - les tables `panel_sms_campagne`, `panel_sms_envoi` (une ligne par client : numéro, langue, lien, code, état, identifiant SMSAPI) et `panel_sms_stop` ;
   - les destinataires : `pclListe(shop, 'b2c')`, numéro normalisé (`smsNorme`), doublons retirés, STOP exclus ;
   - l'envoi par le cron, 60 par minute, entre 9:00 et 20:00, jamais deux fois au même numéro dans la même campagne ;
   - l'essai à son propre GSM.
3. **L'onglet SMS** dans `clients.js`, en cinq étapes : Qui · Le message · Le bon · Essai et envoi · Suivi.
4. **La page publique** (B) :
   - `/w/{code}` affiche la page du client ;
   - « Créer mon mot de passe » passe par le cockpit vers `…/reset/sms/request` puis `…/confirm`, avec des limites par code et par adresse IP ;
   - `/stop/{code}` (ou `/stop` avec le numéro) désinscrit ;
   - les règles `/w/` et `/stop` à la racine d'atelierby.be. Sans elles, le lien passe par `/consulant_bo/…` et le SMS fait 2 SMS.
5. **Tests** : `panel/bin/tests_sms_clients.php` (normalisation, doublons, longueur GSM-7, STOP, fenêtre horaire), puis l'index de l'API (`dashboard-api-index.py`).

## À décider

- A ou B.
- La langue des clients sans langue connue (602 à Halle) : NL, FR, ou les deux (2 SMS).
- Code personnel ou code commun ; la date de fin du bon.
- Halle d'abord, puis l'outil pour tous les magasins ?
