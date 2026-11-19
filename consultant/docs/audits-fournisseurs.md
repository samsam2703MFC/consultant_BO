# Audit des fournisseurs et prestataires — Pilotage › Audit fournisseurs

Demande de Sam (12/11/2026) : deux fois par an, les franchisés notent les fournisseurs et prestataires
référencés de la marque — Glace Rawette (glaces), TFBuddy (IT et développement numérique), SLFood
(marchandise), Bruyerre (matières premières), le collectif marketing… — et chaque fournisseur reçoit une
copie de ses résultats avec la note du franchiseur.

Maquettes validées : dépôt `webshop-mrszoko-backoffice`, dossier
`mrszoko/design-system/maquettes/audit-fournisseurs/` (maquette A retenue).

## Décisions

| Sujet | Décision |
|---|---|
| Forme | **Un seul audit** pour tous les fournisseurs : un **tronc commun** posé à chacun (comparable entre eux) + les **questions propres** à chaque fournisseur. |
| Fournisseurs | **Tous les fournisseurs référencés** : le référentiel `ceo_supplier`, colonne `audit_ref`. Un fournisseur référencé reçoit le tronc commun sans autre action. On le **choisit dans une liste déroulante** (13/11/2026) : les fournisseurs de l'ERP (`/material-suppliers`, gardés une heure dans `auditsErpFournisseurs`, e-mail pré-rempli), puis ceux du cockpit, ou « Autre » pour saisir un nom (agence, collectif). `GET /audits/catalogue[?frais=1]`. |
| Qui répond | **Le franchisé seul**, par un **lien personnel** (sans compte) reçu par **e-mail** et par **notification push** (appareils du magasin abonnés depuis le dashboard ou l'app visites). |
| Copie | **Chaque fournisseur reçoit une copie** de ses résultats avec la **note du franchiseur**, relue et envoyée à la main. Nom des boutiques masqué par défaut. |
| Points | Non montrés au franchisé. Score d'un fournisseur = points obtenus / maximum, en %. Seuils réglables : Bon ≥ 80 %, À surveiller ≥ 60 %, sinon « Revoir le contrat ». |

## Où

| Quoi | Où |
|---|---|
| Écran du cockpit | Pilotage › **Audit fournisseurs** (`#/audits`) : la page `public/audits/` intégrée (`?embed=1`), comme le Cadre des tâches. Onglets Formulaire · Campagnes · Résultats. |
| Page du franchisé | `public/audit/?d=&jeton=` — un écran par fournisseur, enregistré à chaque « Suivant », reprise possible jusqu'à la clôture. Langue du magasin (fr, nl, en, de) pour l'interface ; les questions restent dans la langue où le siège les écrit. `?apercu=1[&c=]` : la vue du bureau, rien n'est enregistré. |
| Code serveur | `src/audits.php` (préfixe `au`). Routes du cockpit `/audits/*` (`auRoute`), routes publiques `/audits/public*` avant l'authentification (`auRoutePublique`). |
| Horloge | `auHorloge()`, appelée par `viHorloge()` (cron des visites, toutes les 5 min) : relances (J+5, J+10, la veille de la clôture par défaut, en journée seulement, aux magasins qui n'ont pas fini) et clôture à la date dite. |
| Tests | `php bin/tests_audits.php` (fonctions sans base : types, points, règles, sous-questions, score, relances, jeton). |

## Types de réponse et points

| Type | Points |
|---|---|
| Note 1 à 5 | note × coefficient (1 par défaut) ; libellés du 1 et du 5 réglables |
| Oui / Non | points du Oui et du Non (5 / 0 par défaut) |
| Choix unique | points par choix |
| Nombre | paliers « jusqu'à N → points », et un palier « au-delà » |
| Choix multiple, texte libre, photo, date | non notés (texte et commentaires remontent dans les résultats et peuvent être joints à la copie) |

**Sous-question** : affichée selon la réponse de la question mère (choix cochés, ou seuil de note / nombre).
**Règle** : sur une condition (choix, ≤ N, ≥ N), exige un commentaire, ouvre une action (`{fournisseur}` prend le
nom) et/ou envoie une alerte push au siège. Les actions sont listées par fournisseur dans les résultats
(« marquer traitée »). Une réponse modifiée avant la clôture retire l'action ouverte qui n'a plus lieu d'être.

## Données

- `ceo_supplier` : + `audit_ref`, `audit_cat`, `audit_genre` (pas de seconde table de fournisseurs). L'adresse de
  la copie : la fiche, sinon le carnet de la centrale d'achat (`caMailAdresse`).
- `ceo_audit_question` : le formulaire courant (`fournisseur_id` NULL = tronc commun, `parent_id` = sous-question,
  `reglage` JSON : choix, coef, paliers, condition, règle). Une question retirée devient inactive.
- `ceo_audit_campagne` : nom, statut (brouillon → envoyee → cloturee), clôture, relances, push, message,
  **formulaire figé à l'envoi** (fournisseurs + questions + seuils), adresse publique pour les liens du cron.
- `ceo_audit_destinataire` : un par magasin ouvert ; e-mail (Qui fait quoi, sinon carnet du reporting), envoi,
  ouverture, fin, relances. Le jeton du lien : HMAC du destinataire avec le secret `auditsJeton`.
- `ceo_audit_reponse`, `ceo_audit_alerte`, `ceo_audit_copie`.

## Points d'attention

- Sans SMTP configuré, l'invitation ne part que par push ; l'écran le dit, et chaque destinataire porte le motif.
- Une adresse de franchisé manquante (« Qui fait quoi ») est signalée sur sa ligne.
- Le push n'atteint que les appareils déjà abonnés pour le magasin (dashboard, app visites).
