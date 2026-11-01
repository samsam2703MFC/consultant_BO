# Module RH · Dimona

Les franchisés déclarent les Dimona de leur personnel (IN, OUT, UPDATE, CANCEL) à l’ONSS, par l’API REST
Dimona v2, sans passer par le portail. Chaque déclaration et son résultat restent tracés.

- **Page** : `https://185.180.206.46/hr/`. C’est une page autonome (`public/hr/`) dans le design system du
  module Marketing.
- **API** : `/hr/api/v1/erp/dimona/*`, qui répond aussi sous le cockpit à `/consulant_bo/api/v1/erp/dimona/*`.
- **Code** : `src/hr/` (namespace `Hr\`). Les tables sont en `hr_dimona_*` (`sql/hr_dimona.sql`).
- **Environnement** : simulation seule au départ. La production est fermée tant que
  `HR_DIMONA_PROD_ENABLED` ne vaut pas `1`.
- **Certificats** : un certificat par société franchisée (un compte REST Chaman par employeur ONSS).

## Sources officielles et ce qui en est tiré

| Source | Ce qu’on en tire |
|---|---|
| `docs/dimona/openapi_dimona_v2.yaml` (zip OpenAPI v2, version 2.0.0) | Les types générés (`src/hr/Dimona/Dto`), les URL SIM et PROD (bloc `servers`), l’URL du jeton (`securitySchemes`) et les chemins (`operationId`). |
| Developer guide REST, « Obtaining an access token… » | Le flux du jeton : assertion JWT, claims, `scope` omis, validité de 10 minutes, renouvellement sous la minute restante. |
| Documentation fonctionnelle v2 (PDF) | 201 et `Location` (p. 20) ; 404 tant que la déclaration n’est pas traitée (p. 5 et 24) ; exemples A, W, B et S (p. 22-24) ; blocs obligatoires (p. 10-11) ; heures planifiées d’un étudiant (p. 19). |
| Instructions ONSS « Dimona : données principales » (lien du YAML) | Les codes de type de travailleur ; la commission paritaire déclarée sous `XXX`, sauf 124, 140, 144, 145, 149, 302, 320 et 322. |

Pour régénérer les types, après un nouveau YAML :

```bash
python3 -I tools/hr-dimona/generate.py docs/dimona/openapi_dimona_v2.yaml
```

`php tests/hr/run.php` vérifie que les fichiers générés correspondent au YAML (empreinte SHA-256).

## Procédure Chaman (une fois par société)

1. Sur l’ordinateur du franchisé, créer un certificat auto-signé, RSA 2048, SHA-256 :
   ```bash
   openssl req -x509 -newkey rsa:2048 -sha256 -days 730 -nodes \
     -keyout societe-dimona.key -out societe-dimona.crt -subj "/CN=Ma société Dimona"
   ```
   Vérifiez dans Chaman la durée de validité qu’il accepte.
2. Dans Chaman (gestion des accès de la sécurité sociale), créer le compte REST de la société. Y téléverser
   **le certificat** (`.crt`), jamais la clé.
3. Noter le **Client ID** que Chaman attribue au compte (`self_service_expeditor_…`).
4. Dans `/hr/`, ouvrir Paramètres › **Connexion ONSS**. L’assistant demande successivement :
   1. la société : BCE, n° ONSS facultatif, commission paritaire, boutiques ;
   2. le certificat et la clé ;
   3. le Client ID et l’environnement ;
   4. **Tester la connexion**, qui obtient un jeton et ne déclare rien.

La clé privée est contrôlée au dépôt :
- elle doit être une clé RSA de 2048 bits au moins ;
- elle doit correspondre au certificat ;
- le certificat ne doit pas être expiré.

Elle est ensuite réexportée et chiffrée en AES-256-GCM, avec la clé maître et une donnée liée à la société
et au Client ID. Aucune API ne la renvoie.

Le cron alerte quand le certificat expire dans moins de 60 jours, une fois par jour, au journal du module.
L’écran affiche alors un badge. Pour renouveler : refaire l’assistant avec « Déposer un nouveau
certificat ».

## Identité : la console franchisé

La page se connecte avec le compte de la **console franchisé** (dépôt WebShop, comptes `bo_users`) :
- le **franchisé** voit les sociétés de ses boutiques et déclare ;
- le **siège** voit tout le réseau, en lecture seule.

La page appelle `POST {console}/bo/{franchisee|franchisor}/login`. La console pose son cookie de session
sur l’hôte. À chaque appel, l’API RH relaie ce cookie au serveur de la console
(`GET /bo/franchisee/me`, puis `/bo/franchisor/me`). C’est la console qui dit qui est connecté et quelles
boutiques lui appartiennent.

Le **jeton admin réseau** de la console (`X-Admin-Token`) n’est **pas** accepté. Il est le même pour tout le
réseau et ne dit pas qui est connecté : une Dimona et un NISS ne peuvent pas reposer dessus.

**Pour l’activer en production**, côté console :
- la section `bo` de la configuration de la console doit exister. Sans elle, `/bo/franchisee/me` répond 500
  « non configuré », et `/hr/` affiche « Les comptes de la console franchisé ne sont pas encore activés » ;
- des comptes `bo_users` doivent exister (rôle `franchise` avec leurs `bo_user_shops`, ou rôle `siege`) ;
- le cookie de session est `Secure` : ouvrir `/hr/` en **https**.

Les écritures exigent l’en-tête `X-HR-Request: 1` et une origine identique (protection CSRF). Chaque geste va
au journal `hr_dimona_audit` (qui, quand, quoi), sans donnée sensible.

## Modèle de données

| Table | Contenu |
|---|---|
| `hr_dimona_employers` | la société (employeur ONSS) : raison sociale, BCE, n° ONSS, commission paritaire par défaut |
| `hr_dimona_employer_shops` | ses boutiques (une boutique appartient à une seule société) |
| `hr_dimona_credentials` | le compte Chaman : `client_id`, `env` (SIM/PROD), `private_key_encrypted`, `certificate_pem`, `certificate_expires_at`, `is_active`, dernier test |
| `hr_dimona_tokens` | le jeton d’accès en cache, chiffré, avec son échéance |
| `hr_dimona_workers` | la personne : employé du panel ou embauche hors panel ; NISS chiffré et son empreinte HMAC, naissance, type, CP, heures |
| `hr_dimona_declarations` | chaque déclaration : type, `request_payload` (lisible, NISS masqué) et requête complète chiffrée, n° ONSS, statut, `result_payload`, anomalies FR/NL, essais, prochaine relecture, auteur |
| `hr_dimona_periods` | les périodes Dimona : n° ONSS, dates et heures, type, CP déclarée, heures, ACTIVE / CLOSED / CANCELED, déclarées ici ou importées |
| `hr_dimona_audit` | le journal |

Les champs d’un IN qui manquaient aux employés (NISS, naissance, type, CP, heures planifiées) vivent dans
`hr_dimona_workers`, car la table employés de l’ERP n’est pas à nous. À l’embauche, l’assistant reprend ce
que l’employé a encodé dans son profil de l’app employés (`mot_profil`, chiffré à part) : nom, naissance,
statut, et le NISS, que l’API ne renvoie jamais au navigateur.

## Suivi d’une déclaration

1. `POST /declarations` → 201. Le numéro est lu dans `Location`, le statut passe à `PENDING`.
2. Relecture `GET /declarations/{id}`. Un 404 veut dire que la déclaration n’est pas encore traitée.
   - **Rythme** : après 5 s, 15 s, 30 s, 1 min, 5 min, puis toutes les 15 minutes, pendant 24 h. Au-delà :
     `ERROR`.
   - **Qui relit** : le cron `bin/hr_dimona_cron.sh`, chaque minute, sous www-data. Il tourne 55 s pour
     tenir les premières échéances, qui tombent sous la minute. La page relit aussi tant qu’elle est
     ouverte, et le bouton « Actualiser » force la relecture.
3. Le résultat suit `A` → ACCEPTED, `W` → WARNING, `B` → REFUSED, `S` → WAITING_SIGEDIS (encore relue).
   Les anomalies sont gardées en FR et NL.
4. La période suit la déclaration acceptée : ouverte (IN), close (OUT), modifiée (UPDATE) ou annulée
   (CANCEL).
5. Si l’envoi est interrompu par le réseau, la déclaration est retrouvée par sa référence
   (`references.declaration`) avec `POST /declarations/search`.

## API

| Route | Rôle |
|---|---|
| `GET /health` · `GET /session` | publiques : santé ; identité, sociétés, environnements, types de travailleur |
| `POST /declarations` | `worker_id` (ou `societe_id` + `employee_id`), `type`, et selon le type : `start_date`, `start_hour`, `end_date`, `end_hour`, `worker_type`, `joint_commission`, `planned_hours`, `period_id` |
| `GET /declarations?societe_id=&employee_id=&worker_id=&status=` | liste |
| `GET /declarations/{id}` · `POST /declarations/{id}/refresh` | détail ; relecture ONSS forcée |
| `GET /periods?employee_id=` (ou `worker_id`) · `POST /periods/sync` | périodes ; import depuis l’ONSS (`POST /periods/search`) |
| `POST /credentials` · `GET /credentials?societe_id=` · `POST /credentials/{id}/test` | dépôt du certificat et de la clé ; liste sans la clé (expose l’expiration) ; test (un jeton, rien de déclaré) |
| `GET/POST /employers` · `PATCH /employers/{id}` | sociétés et leurs boutiques |
| `GET /workers?societe_id=` · `POST /workers` · `GET /workers/{id}` · `POST /workers/{id}/ssin` | équipe et état Dimona ; fiche ; NISS en clair (journalisé) |
| `GET /prefill?societe_id=&employee_id=` | préremplissage depuis le profil de l’employé (sans le NISS) |
| `GET /audit?societe_id=` | journal |

## Configuration

Chaque valeur se lit dans cet ordre : variable d’environnement, puis bloc `hr` de `config/config.php`. Ce
bloc est écrit par `bin/deploy.sh` à partir des variables du workflow (`vars.*`, `secrets.*`). Pour les
adresses ONSS seulement, le YAML sert de dernière source.

| Variable | Rôle | Défaut |
|---|---|---|
| `HR_DIMONA_PROD_ENABLED` | `1` ouvre la production | fermée |
| `HR_DIMONA_BASE_URL_SIM` / `_PROD` | URL de l’API Dimona | `servers` du YAML |
| `HR_DIMONA_TOKEN_URL_SIM` / `_PROD` | serveur de jetons (aussi l’`aud` du JWT) | `tokenUrl` du YAML |
| `HR_DIMONA_MASTER_KEY` | clé maître, 32 octets en base64 | `hr_dimona.key`, tirée une fois par `bin/deploy.sh` dans le dossier privé (`/var/lib/consulant_bo/prive`, 0600) et recopiée à chaque livraison dans `config.php` (bloc `hr`, `masterKey`). Sous Apache, PHP n’atteint pas le dossier privé (mesuré le 01/11/2026), mais il lit `config.php`. |
| `HR_CONSOLE_API_BASE` | API de la console vue du navigateur | `/webshop/api` (deploy) |
| `HR_CONSOLE_API_INTERNAL` | API de la console vue du serveur | `http://127.0.0.1/webshop/api` (deploy) |
| `HR_DIMONA_CERT_ALERT_DAYS` | seuil de l’alerte certificat | 60 |
| `HR_DIMONA_ASSERTION_TTL` | durée de vie du JWT d’assertion (s, ≤ 300) | 300 |

**La clé maître se sauvegarde avec la base.** La perdre rend illisibles les clés privées et les NISS : il
faudrait redéposer les certificats et ressaisir les NISS.

## Tests et vérification

```bash
php tests/hr/run.php                 # unitaires + intégration (faux OAuth, faux Dimona, fausse console)
php tests/hr/run.php --unit          # ce que le workflow de déploiement lance
```

Aucun test n’appelle l’ONSS réel.

Le module en local, sur une seule origine et sans réseau : voir l’en-tête de `tests/hr/dev-router.php`
(comptes `franchise@test.be` / `siege@test.be`, mot de passe `test1234`).

Vérification d’un vrai compte SIM, qui obtient un jeton puis fait une recherche en lecture seule, sans
rien déclarer :

```bash
HR_SMOKE_CLIENT_ID=self_service_expeditor_… HR_SMOKE_PRIVATE_KEY_FILE=societe-dimona.key \
HR_SMOKE_ENTERPRISE_NUMBER=0411702543 php scripts/dimona-smoke.php
```

## Limites connues

- **URL du jeton en simulation** : ni le YAML (qui donne seulement `services.socialsecurity.be/…/token`), ni
  la page officielle sur le jeton, ni le PDF ne la donnent. Le module utilise donc celle du YAML pour les deux
  environnements. Si le compte SIM est refusé pour cette raison, renseigner `HR_DIMONA_TOKEN_URL_SIM`.
- **Identité** : le module dépend des comptes de la console franchisé. Tant que sa section `bo` n’est pas
  configurée, la page s’ouvre mais personne ne peut s’y connecter.
- **Types de travailleur** : seuls OTH, STU, FLX et EXT sont proposés. Les autres (ALT, DWD, IVT…) se
  déclarent sur le portail.
- **Contrôles métier** : seuls ceux qu’une source cite sont appliqués avant l’envoi (heures planifiées d’un
  étudiant, contraintes du YAML). Le reste, c’est l’ONSS qui le dit, en anomalies affichées.
- **Personnes sans NISS** : une personne sans NISS ni numéro BIS (identification par les « MID-Data ») n’est
  pas prise en charge. Passez par le portail.
- **Enregistrement journalier** : les `dailyRegistrations` (horeca et similaires) ne sont pas couverts.
- **Alerte certificat** : elle va au journal du module et en badge à l’écran, pas par e-mail.
