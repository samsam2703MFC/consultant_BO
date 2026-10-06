# L'app employés (« App worker »)

L'application mobile des employés du réseau (TFB-Employee, livrée en zip le 06/10/2026), servie par
le cockpit à l'adresse `/consulant_bo/employee/`, et liée depuis le rail du cockpit, ERP franchisé ›
**App worker ↗**, vers sa page de connexion. Horaire, tâches à faire (avec photo), primes, profil.

## Où vit quoi

| Dossier | Contenu |
|---|---|
| `apps/employee/src` | le code PHP de l'app (contrôleurs, services, dépôts, vues Twig, traductions fr/en/nl/it/pl) |
| `apps/employee/config/app.php` | les réglages, réécrits pour cet hébergement (voir plus bas) |
| `apps/employee/vendor` | ses dépendances Composer, allégées et **commises** (le déploiement ne lance pas Composer) |
| `apps/employee/marque/teinter.py` | teint le thème Mazer aux couleurs de la marque |
| `public/employee` | ce que le serveur sert : `index.php`, `.htaccess`, `sw.js`, `manifest.webmanifest`, `assets/` |

Rien de l'app n'est exécutable depuis `assets/` ; les gabarits Twig restent hors du dossier public.

## Ce qui a changé par rapport au zip

- **L'adresse** : l'app supposait vivre à `/employee` à la racine d'un domaine ; `ROOT` se déduit
  maintenant de l'adresse appelée (`EMPLOYEE_BASE_PATH`), le manifeste et le service worker sont
  relatifs à elle.
- **L'API** : celle du panel, la même que le cockpit (`config/config.php` › `panelApi.base`,
  sinon `EMPLOYEE_API_BASE`, sinon `https://atelierby.tfbuddy.com/api/v1`). Les employés se
  connectent avec leurs identifiants du panel (`POST /employees/authenticate`) ; le cockpit ne
  garde aucun mot de passe.
- **Les cookies** de session suivent le schéma (`secure` seulement en https) et le chemin de l'app.
- **Pas de `.env` obligatoire** : chaque réglage a sa valeur par défaut ; un `apps/employee/.env`
  (hors dépôt) ou des variables d'environnement les surchargent (`EMPLOYEE_API_BASE`,
  `EMPLOYEE_DEBUG=1` pour voir les erreurs, `DEFAULT_LANGUAGE`…).
- **La marque** : plus de Nunito ni de bleu Mazer. `assets/atelier/app.css` est le thème Mazer teint
  (rubis `#8D1D2C`, beige `#EAE4DC`, Gotham), `assets/atelier/marque.css` pose les polices de la
  marque (Gotham, Vank, partagées avec le cockpit) et le style des pièces propres à l'app ; les
  icônes Bootstrap restent. Les 86 Mo d'assets Mazer du zip ne sont pas repris : seuls les trois
  CSS utilisés, les icônes, les illustrations d'erreur et les icônes PWA.

## Régénérer le thème teint

```
python3 apps/employee/marque/teinter.py
```

## Tester en local, sans toucher au panel

Un serveur PHP avec un routeur qui rejoue le `.htaccess` (`php -S 127.0.0.1:8098 -t public
router.php`) et une API simulée (`demo` / `demo`) : connexion, accueil, planning, tâches, profil,
déconnexion. Aucune écriture vers le panel.

## L'onglet Primes (06/10/2026, maquette B)

`/primes` : deux onglets, **Moi** (le compteur de ventes croisées du jour, les trois primes
individuelles, les semaines, les mois passés) et **Mon magasin** (l'objectif du mois, jour par jour,
ce qu'il manque par palier, l'équipe au prorata des heures). La page ne calcule rien : `PrimesService`
appelle le cockpit, `GET {COCKPIT_API_URL}/ventes/moi?m=`, avec le jeton de la session ; le cockpit
le fait confirmer par le panel et ne rend que la fiche de la personne connectée (voir
`docs/contrat-api.md`, « Les primes dans l'app worker »). `COCKPIT_API_URL` se déduit de l'adresse
de l'app (`…/consulant_bo/api/cockpit`), sinon `EMPLOYEE_COCKPIT_API`. Styles :
`public/employee/assets/atelier/primes.css` ; textes : `translations/page/{fr,en}/primes.json`
(les autres langues retombent sur l'anglais).
