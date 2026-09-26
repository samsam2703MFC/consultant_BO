# Brand Guard — exploitation

Contrôle de marque des posts Facebook du réseau : avant publication (la
demande du franchisé), après publication (les pages), détection des posts
sauvages. Code : `src/brand_guard.php` (métier), `src/meta_graph.php`
(Meta), `Anthropic::verdict()` (`src/anthropic.php`). Lecture seule côté
Meta : le module ne publie ni ne supprime jamais rien.

## Routes

| Méthode | Route (`/api/cockpit` + …) | Usage |
|---|---|---|
| POST | `/marketing/brand-guard/checks` | Contrôle d'une demande (`{ postId }`) ou d'un texte libre (`{ texte, images[], magasinId }`) |
| GET | `/marketing/brand-guard/checks?shop=&from=&to=&statut=&sauvage=&source=` | Les contrôles |
| GET / PUT | `/marketing/brand-guard/rules` | La charte (règles, gravités, actives) — chaque changement crée une nouvelle version |
| GET / PUT | `/marketing/brand-guard/pages` | Les pages (URL, connexion) ; `PUT { resoudre: true }` relance la résolution URL → id ; `mailAlerte` = adresse des alertes |
| GET | `/marketing/brand-guard/stats` | Tableau de bord 7 / 30 / 90 jours |
| GET / POST | `/marketing/brand-guard/webhook/meta` | **Public** — vérification (GET) et événements `feed` (POST, signature vérifiée) |
| GET | `/marketing/brand-guard/cron?jeton=` | Appelé chaque heure ; agit lundi 7 h (Europe/Brussels) ; `&forcer=1` à la main |

Le contrôle « avant » est aussi appelé automatiquement par `POST /facebook/posts`
et `POST /facebook/posts/{id}/controle` (le module de demandes existant).

## Secrets — variables d'environnement, jamais la base

| Variable | Rôle |
|---|---|
| `ANTHROPIC_API_KEY` | Clé Claude (ou le réglage `anthropic.cle`, saisi dans Paramètres). Sans clé : règles mécaniques seules, dit au journal. |
| `META_SYSTEM_TOKEN` | Token longue durée du **System User** du Business Manager (ASIMA). |
| `META_APP_SECRET` | Secret de l'app Meta — vérifie `X-Hub-Signature-256` sur chaque webhook. |
| `META_VERIFY_TOKEN` | Chaîne libre que vous choisissez ; Meta la renvoie à l'abonnement (GET). |

Réglages en base (`ceo_app_setting.brandGuard`, sans secret) : `graphVersion`
(défaut `v21.0`), `mailAlerte` (adresse des alertes et du rapport du lundi).

## 1. Créer le System User Meta

1. Business Manager (business.facebook.com) → Paramètres → Utilisateurs → **Utilisateurs système** → Ajouter (rôle Employé suffit).
2. Rattacher les six pages au Business Manager si ce n'est pas fait (Comptes → Pages → Ajouter), puis **attribuer les pages** au System User (accès « Contenu » en lecture).
3. Générer un token pour l'app du réseau avec les permissions :
   `pages_show_list`, `pages_read_engagement`, `pages_read_user_content`, `pages_manage_metadata`.
   Choisir « jamais » pour l'expiration (token longue durée).
4. Poser le token dans l'environnement du serveur (`META_SYSTEM_TOKEN`), redémarrer PHP-FPM.
5. Dans le cockpit, écran Contrôle posts Facebook → panneau Brand Guard → « Résoudre les pages » : chaque URL devient un `page_id`. Une page en `a_connecter` porte son motif (URL fausse, page non rattachée).

Les tokens de page ne sont **jamais stockés** : ils se lisent à chaque
audit par `GET /me/accounts` avec le token du System User.

## 2. Abonner les pages au webhook

1. App Meta → Webhooks → Page → champ **`feed`**.
2. URL de rappel : `https://<hôte>/consulant_bo/api/cockpit/marketing/brand-guard/webhook/meta`
   — Meta exige HTTPS. Verify token : la valeur de `META_VERIFY_TOKEN`.
3. Abonner chaque page : `POST /{page_id}/subscribed_apps?subscribed_fields=feed` avec le token de page (ou depuis l'interface de l'app). Le cockpit marque `webhook_abonne` à la réception du premier événement.
4. Vérifier : publier un post de test sur une page → dans la minute, une ligne « Audit » ou « Post sauvage » au journal.

Sans webhook, rien n'est perdu : le cron du lundi relit sept jours.

## 3. Rotation des tokens

- **System User** : générer un nouveau token (étape 1.3), le poser dans l'environnement, redémarrer PHP-FPM, puis `GET …/brand-guard/cron?jeton=…&forcer=1` pour vérifier. L'ancien token se révoque ensuite dans le Business Manager.
- Un token expiré ou révoqué se voit : Meta répond code **190**, le module marque la page `erreur` avec le motif, l'écrit au journal, et n'insiste pas.
- **App secret** : changer `META_APP_SECRET` **avant** de faire tourner le secret côté Meta — entre les deux, les webhooks sont refusés (403), le cron rattrape.
- **Clé Anthropic** : Paramètres → Assistance IA, ou `ANTHROPIC_API_KEY`.

## Cron — chaque jour à 7 h

`bin/rapports_cron.sh` (déjà appelé chaque heure) appelle
`/marketing/brand-guard/cron`. L'endpoint agit **tous les jours à 7 h**
(heure de Bruxelles) :

1. **lecture des pages** connectées — les deux derniers jours (pour couvrir
   un webhook manqué et une publication tardive ; sept jours le lundi) ;
2. **copie des visuels** de chaque post lu sous
   `public/assistant/uploads/brand-guard/<contrôle>/` — la photo du jour,
   qui survit à l'expiration des liens Facebook ;
3. **passage de l'agent** sur tout post pas encore contrôlé : verdict,
   sauvage ou non ;
4. **rapport de contrôle du jour** par mail à `mailAlerte` — sauvages et
   non-conformes d'abord, conformes en une ligne ; un jour sans post ne fait
   pas de mail, seulement une ligne au journal ;
5. le **lundi**, en plus : le rapport de la semaine, du pire au meilleur.

Un rapport ne part qu'une fois par jour. `?forcer=1` rejoue tout à la main.
Le webhook reste le chemin rapide : un post publié est contrôlé dans la
minute, le cron du matin est le filet.

## Ce qu'un verdict enregistre

`brand_guard_checks` : la source (avant / après), le post ou la demande, la
boutique, **sauvage** ou non, statut (`conforme | a_corriger | bloque`),
score 0-100, les écarts (règle, gravité, constat, correction, moteur), le
message au franchisé, la **version de charte** (empreinte des règles
actives au moment du contrôle), le modèle, les liens directs des visuels.

La règle de décision est appliquée dans le code, après le modèle : un
bloquant ⇒ bloqué ; un majeur sans bloquant ⇒ à corriger ; sinon conforme.
Un post bloqué est **refusé au nom de Brand Guard** dans le module de
demandes : il ne part pas, le franchisé voit chaque écart et sa correction,
corrige et resoumet ; le franchiseur peut forcer la validation (tracée : qui,
quand, pourquoi).

## Tests

```
php bin/tests.php
```

Sans base ni réseau : règle de décision, signature des webhooks, résolution
URL → id contre un Graph simulé (dont 190 et rate limit), post sauvage,
lecture d'un événement `feed`, version de charte.

## Limites connues

- Les visuels d'une **demande** ne sont envoyés au modèle que s'ils portent
  une `url` (absolue, ou relative aux uploads du cockpit) : le formulaire
  actuel enregistre un nom et des dimensions, pas le fichier.
- Les copies de visuels vivent sur le disque du serveur ; les déplacer vers
  un stockage objet (R2) est un changement d'une fonction (`bgArchiverImages`).
