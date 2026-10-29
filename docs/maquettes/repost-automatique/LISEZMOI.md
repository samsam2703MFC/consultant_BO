# Repost automatique — trois maquettes

> **Retenue : B** (29/10/2026), **codée** dans le module Social : `src/repost.php`
> (guet de la Page de la marque, file, validation du franchisé), écran
> `public/SA Repost.dc.html` (onglets Suivi, Réglage, Côté franchisé), carte
> « Le réseau vous propose » du Calendrier franchisé ; entrée « Repost
> automatique » du menu du Marketing (`src/lib/navigation.ts`). Dépôts `social`
> et `marketing`, branche `claude/inspiring-lovelace-g6goqf`. Écarts avec la
> maquette : la sixième tuile (portée) n'est pas là — Meta ne la donne pas sans
> lire chaque repost un par un ; Instagram ne compte que si les boutiques ont
> leur propre compte (le réseau est en Instagram unique).

Demande du 29/10/2026 : dans le Marketing (`/marketing/`), **une gestion du
repost automatique** — quand la marque publie sur sa Page Facebook ou son
Instagram, le post est repris par les Pages des boutiques.

La première maquette existe déjà dans le module Social (`social`,
`public/SA Repost.dc.html`, 19/10) : un seul bloc qui règle *quoi* reposter
(les Pages cochées, partager ou copier, la validation du franchisé, activé ou
en pause). Elle n'a pas d'entrée dans le menu du Marketing. Les trois
maquettes ci-dessous ajoutent la **gestion** : ce qui est parti, ce qui attend
le franchisé, ce qui a été refusé ou a échoué, et ce qui partira. Toutes les
trois s'ouvrent depuis une entrée nouvelle, **Réseaux sociaux › Repost
automatique**, dans la coque du Marketing.

| | Maquette | Fichiers |
|---|---|---|
| A | **Le réglage et le fil** — le bloc du 19/10 sans changement, et à côté le fil des 7 derniers posts de la marque avec, sous chacun, le statut dans chaque boutique (publié, à valider, refusé avec le motif du franchisé, échec, exclu) et les relances | `a.html`, `a.jpg` |
| B | **Le tableau posts × boutiques** — un onglet « Suivi » : six chiffres, l'alerte de connexion (jeton Instagram expiré), un post par ligne et une boutique par colonne ; l'en-tête de chaque colonne dit si la boutique est en automatique ou si son franchisé valide ; le refus s'ouvre dans une bulle | `b.html`, `b.jpg` |
| C | **Les règles et la semaine à venir** — plusieurs règles nommées (nouveautés et gammes partout ; offres d'une opération seulement chez les boutiques de l'opération, avec le texte de la boutique ; recyclage des meilleurs posts, en pause), les garde-fous communs, et à droite ce qui partira cette semaine et ce qui attend les franchisés | `c.html`, `c.jpg` |
| | La planche : les trois, ce que chacune apporte, ses limites, ce que Meta impose | `planche.html`, `planche.jpg` |

## Réel et illustré

**Réel** : les six boutiques du module Social (Halle en NL + FR, Corbais,
Gosselies, Sombreffe, Gembloux, Wavre), leurs Pages, le jeton Instagram
expiré de Gosselies (`social/public/social-data.js`), les produits et leurs
illustrations, le menu du Marketing (`marketing/src/lib/navigation.ts`) et
ses jetons de style.

**Illustré, et dit sur chaque écran** : les 7 posts de la marque du 20 au
29/10, les heures, les statuts (31 reposts publiés sur 36 attendus, 1 à
valider, 1 refusé, 3 échecs Instagram), la portée et la semaine à venir.
Le serveur (`185.180.206.46`) n'est pas joignable depuis l'environnement de
travail : rien n'a été lu chez Meta.

## Ce que Meta impose (les trois maquettes)

- **Facebook** : « partager » publie sur la Page de la boutique un lien vers
  le post de la marque (rendu exact à confirmer sur la Page test) ; « copier »
  publie le même visuel et le même texte comme post de la boutique.
- **Instagram** n'a pas de repost par l'API : c'est toujours une copie (ou un
  post « collab » que la boutique accepte dans l'appli).
- Un jeton expiré bloque une Page sans bloquer les autres : d'où l'alerte et
  la relance.

## Ce qu'il faudrait pour coder

Le code vit dans deux dépôts, `social` (l'écran et l'API) et `marketing` (le
menu) :

- **Le menu** — `marketing/src/lib/navigation.ts` : une route `social-repost`,
  `SOCIAL_PAGES['social-repost'] = { key: 'repost', file: 'SA Repost.dc.html' }`,
  et l'entrée sous « Réseaux sociaux ». Le module Social connaît déjà la clé
  `repost` (`public/social-data.js`, `PAGES`).
- **La file** — la table `social_publication` (post × boutique × canal, heure
  prévue, résultat Meta) porte déjà la publication ; il lui faut l'origine
  (`repost`), la règle, le post source, le statut (`a_valider`, `refuse`,
  `publie`, `echec`, `exclu`) et le motif.
- **Les règles** — une table `social_repost_regle` (C) ou une seule ligne de
  réglage (A, B) : source, filtres (mots, #, opération), Pages cibles, partage
  ou copie, texte de la boutique (`{ville}`, `{adresse}`, `{horaires}`,
  `{lien}`), délai, plage horaire, plafond par jour, mode de validation.
- **Le guet** — une tâche toutes les 15 minutes (comme le cron de Brand
  Guard) lit les nouveaux posts de la Page et de l'Instagram de la marque,
  passe chacun par les règles et écrit les lignes de la file.
- **Le franchisé** — dans son espace (`/marketing/franchise`) : la carte
  « À valider » avec Accepter / Refuser (motif), et les routes
  `GET /franchise/reposts`, `POST /franchise/reposts/{id}/valider|refuser`.
- **Le réseau** — `GET /reposts?du=&au=` (le fil ou le tableau),
  `POST /reposts/{id}/relancer`, `DELETE /reposts/{id}` (retirer des Pages),
  `GET|PUT /repost/regles`.

Régénérer : `node docs/maquettes/repost-automatique/generer.js` (depuis la
racine du dépôt ; Playwright, aucun serveur nécessaire). `rp.css` reprend la
coque du Marketing ; le corps est en Gotham Light, comme le cockpit.
