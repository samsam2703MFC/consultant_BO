# BO consultant — mise au design system « L'Atelier By »

Compte rendu de l'adaptation des écrans du BO consultant (cockpit) au design
system commun, `docs/design-system/DESIGN_SYSTEM.md` (référence relevée dans le
module Marketing, état du 06/11/2026). Périmètre : **les écrans** (§0–§12). Les
documents produits par le serveur (§13) ne sont pas traités ici, voir « Écarts
restants ».

Captures avant / après (1440 × 1000, jeu de démonstration) :
`docs/design-system/captures/`.

---

## 1. Inventaire de départ

| Domaine | Ce qu'on trouvait |
|---|---|
| Feuilles | `assets/ds/global.css` (design system **webshop**, docx du 09/04/2026), `assets/css/app.css`, `assets/css/controles-photo.css`, `dashboard/dashboard.css`, `production/production.css`, `planogramme/plano.css`, blocs `<style>` de `visites/`, `prospection/`, `newsletter/`, `dashboard/suivi.html`, et des feuilles injectées par `visites.js`, `newsletter.js`, `prospection.js`, `scouting-tpl.js`. |
| Styles en ligne | ≈ 6 750 attributs `style` dans `templates.js`, ≈ 430 dans `scouting-tpl.js`, ≈ 310 dans `dashboard.js`, ≈ 280 dans `newsletter.js` ; des styles calculés dans `app.js` (rail, pastilles d'état, barres). |
| Police | Gotham déclarée sur 18 graisses : 400 = `Gotham_Regular_New`, 500 = `Gotham_Medium`, 600 = `Gotham_Regular`, 700 = `Gotham_Black`. Titres d'écran en **Vank** (`--font-display`). Texte courant presque gras. |
| Graisses | ≈ 1 600 `font-weight` 500 / 600 / 700 (ou `font: 600 …`) en dur. |
| Tailles | 30 tailles distinctes, de 8 px à 40 px (9 / 9,5 / 13,5 / 17 / 19 / 20 / 23 / 25 / 27 / 30 / 32 px… hors échelle). |
| Couleurs | ≈ 120 teintes en dur, dont trois verts, quatre rouges, six ambres, des gris neutres ou bleutés (`#666`, `#888`, `#5B6B78`) ; fond beige `#EAE4DC` ; abricot `#F2C9A0` pour les pastilles. |
| Formes | ≈ 500 pilules (`border-radius: 999px`), cartes à 14–16 px, filets de 0,5 px (≈ 1 200), ≈ 90 ombres portées. |
| Rail | 236 px, logo de 176 px de large, libellés de groupe en 11 px **gras noir**, entrées en 300 / active en 500, compteurs en pastille pleine bordeaux, avatar rond. |
| Titre d'écran | `h1` Vank 30 px dans la page, sous-titre gris, pastille abricot de période. |
| Emoji | ≈ 120 dans l'interface (🏆 🎯 📷 📅 ✅ ⚠️…), dont la barre d'onglets de Visites. |

## 2. Table de correspondance

### 2.1 Anciens jetons → jetons du système (alias posés dans `global.css`)

Les gabarits lisent encore les anciens noms : chacun pointe désormais sur le
jeton **du même rôle** (§10.1).

| Ancien jeton | Valeur avant | Jeton du système |
|---|---|---|
| `--color-primary` | `#8D1D2C` | `--accent` |
| `--color-bg` | `#EAE4DC` | `--paper` `#faf7f2` |
| `--color-surface` | `#FFFFFF` | `#fff` |
| `--color-text`, `--color-text-primary`, `--color-ink` | `#222222` | `--ink` `#1c1a17` |
| `--color-text-muted`, `--color-text-secondary` | `#666666` | `--muted` `#7a736a` |
| `--color-background-secondary` | `#F4EFE8` | `--table-head` `#FBF9F5` |
| `--color-border-secondary` | `rgba(34,34,34,.18)` | `--line` `#e6e0d8` |
| `--color-border-tertiary` | `rgba(34,34,34,.10)` | `--line` `#e6e0d8` |
| `--color-secondary`, `--color-linked` (Abricot) | `#F2C9A0` | `--prize-soft` `#F6E7CE` |
| `--color-on-abricot` | `#6b4420` | `--prize-ink` `#7A5207` |
| `--color-collection-*` | ambres | `--prize`, `--prize-soft`, `--prize-ink` |
| `--font-display` (Vank) | `'Vank', 'Gotham'` | `'Gotham'` — Vank et Playwrite restent déclarées, réservées au contenu (`--font-content-display`, `--font-content-script`) |
| `--radius-pill` | `999px` | `6px` |
| `--weight-medium` | `500` | `400` |
| `--font-size-*` | 32 / 20 / 16 / 13 / 11 px | 26 / 15 / 13 / 12,5 / 11 px |

### 2.2 Teintes en dur → jetons (par rôle)

Dans une déclaration CSS (style en ligne ou feuille), la teinte devient le
jeton (`var(--…)`). Dans un littéral JavaScript ou un attribut SVG (barres,
graphiques, carte Leaflet, canevas), elle devient la **valeur hexadécimale du
jeton** : un `var()` n'y est pas toujours compris.

| Rôle | Teintes d'origine | Jeton |
|---|---|---|
| Texte principal | `#222`, `#222222`, `#221E1A`, `#2B2B2B`, `#000` | `--ink` |
| Texte secondaire actif | `#333`, `#444`, `#555`, `#5B6B78` (gris bleuté) | `--muted-2` |
| Texte secondaire | `#666`, `#777`, `#888`, `#7A736A`, `#9A8F84`, `#9A9186` | `--muted` |
| Libellé de rangement, désactivé | `#999`, `#BBB`, `#B8B2A8`, `#C9C2B8`, `#B9B1A6` | `--faint` |
| Fond secondaire, survol | `#FBF8F4`, `#FCFAF7`, `#FBF9F5` | `--table-head` |
| Ligne de total | `#F4EFE8` | `--total-line` |
| Pastille neutre, piste | `#EDEAE5`, `#EFE9E1`, `#EDE7E0`, `#EAE4DC`, `#F2EFE9`, `#E8E3DD` | `--chip` |
| Filets | `#DDD`, `#CCC`, `#EEE`, `#D9D2C8`, `#D8CEC2`, `#DED6C9`, `#E6E0D8` | `--line` |
| Marque | `#8D1D2C` | `--accent` ; survol `#6d1622`, `#6E1420`, `#5A1320` → `#6f1622` |
| Sélection | `#FDF4F5`, `#FBF4F5` / `#F7E4E6`, `#F6E4E7`, `#FBEBED` | `rgba(141,29,44,.05)` / `.08` |
| Réussite | `#2D7A3E` (320 fois), `#1B5E20`, `#1F5A2E` | `--green` `#3f7a52` |
| Fond réussite | `#E6F2E9`, `#E3F0E5`, `rgba(45,122,62,α)` | `rgba(63,122,82,.12)` (α conservé) |
| Valeur négative (arrêt) | `#C0182B` (117 fois), `#8A1B1B` | `--stop` `#A32014` |
| Fond d'arrêt | `#F7DADA` | `--stop-soft` |
| Erreur | `#DC3545` | `--danger` |
| Attention | `#C17A2A`, `#D97706`, `#C9A227`, `#B87512`, `#E0A526` | `--prize` `#B0792A` |
| Texte d'attention | `#8A5A13`, `#B26A00`, `#8A6D12`, `#6B4420` | `--prize-ink` |
| Fond d'attention | `#FBEFE0`, `#E8C9A0`, `#FBF3DC`, `#FFF1D6`, `#FAC775`, `rgba(217,119,6,.14)` | `--prize-soft` |

**Teintes laissées telles quelles, ce sont des couleurs d'identification** :
leviers of_tag (`--lev-*`), séries des graphiques et heatmaps (`#F08A2C`,
`#6AA84F`, `#E69F00`…), canal B2B / « pro » (`#1f5f8b`), Pop-Up (`#78554B`), CA
théorique (`--pkg-abricot`), marqueurs de la carte du scouting.

### 2.3 Graisses, tailles, formes

| Ancien | Nouveau |
|---|---|
| `font-weight` 500 / 600 / 700 / bold, `font: 600 …` | **400** ; **700** gardé seulement sur les valeurs chiffrées de 20 px et plus (KPI, §4.3), ramenées à 24 px |
| `font-weight` 100–300 | 400 |
| `<b>`, `<strong>`, `<th>`, `h1`–`h6` | trait du parent (400) |
| Tailles hors échelle | taille la plus proche de l'échelle §4.2 (8–9,5 → 10 ; 13,5 → 13 ; 17 → 16 ; 19 → 18 ; 20 → 21 ; 23 → 22 ; 25 → 24 ; ≥ 27 → 26) |
| `border-radius` ≥ 50 px (pilule) | 6 px |
| `border-radius` 13–49 px | 12 px |
| filet 0,5 px | 1 px |
| `box-shadow` portée | retirée (les `inset` qui dessinent un liseré restent) |

Le script qui applique ces règles est versionné : `tools/design-system/normalise.js`
(`node tools/design-system/normalise.js fichier…`). Il est idempotent : on peut le
repasser sur un fichier après une modification.

## 3. Ce qui a été fait

**Fondations** — `public/assets/ds/global.css` réécrite : les deux `@font-face`
du §4.1 (400 = `Gotham_Medium.otf`, 700 = `Gotham_Regular.otf`), les jetons du
§3 copiés tels quels, les alias du tableau 2.1, la base (papier, 13 px, focus
bordeaux 2 px partout, `b` / `strong` / `th` / titres en trait normal), tous les
composants du §7 (`.topbar`, `.card`, `.btn…`, `.field`, `.toolbar`, `.filter`,
`.choice-pill`, `.seg`, `.page-tabs`, `.table-card`, `.chip`, `.badge`,
`.tag-warn` / `.tag-stop`, `.kpi…`, `.switch`, `.confirm`, `.ico`), la coquille
et le rail du §6 / §8, le mode incorporé du §9, les leviers. Les classes encore
servies par le scouting (`.btn-primary`, `.btn-secondary`, `.icon-circle`,
`.t-admin-label`, `.t-section-title`) sont ramenées au §7.4. Les composants du
webshop (navbar, panier, badges de collection, sections de 48 px) sont retirés :
aucun écran du BO ne s'en servait.

**Coquille du cockpit** (`templates.js` › `render()`, `app.js`) :
- rail du §8 : 246 px, logo de 26 px de haut, nom du module en capitales
  bordeaux, groupes en 10 px `--faint`, entrées 13 px `--muted-2` avec une icône
  linéaire du catalogue §8.4, **seule l'entrée active en 700**, `aria-current` ;
- barre supérieure du §7.1, blanche, pleine largeur : kicker « Vue Réseau »
  (« Vue Franchisé » pour les outils de l'ERP franchisé), titre 26 px en 400,
  actions à droite ;
- contenu sur papier, `22px 24px 40px`, largeur de lecture 1 180 px, surface de
  travail pleine largeur et pleine hauteur pour le scouting ;
- onglets des écrans regroupés en onglets de page (§7.7) ;
- mode incorporé (§9) : `?embed=1` dans un cadre pose `is-embedded`, masque le
  rail, garde `embed=1` sur les liens internes au moment du clic (les écrans sont
  dessinés après le chargement). Le dashboard magasin garde sa propre convention
  `?embed=1`, comme le demande le §9.

**Normalisation** des styles en ligne et des feuilles de tous les écrans : SPA
(`templates.js`, `app.js`, `scouting-tpl.js`, `scouting.js`, `franchises-tpl.js`,
`franchises-vals.js`, `visites.js`, `newsletter.js`, `prospection.js`,
`app.css`, `controles-photo.css`) et pages à part (`dashboard/`, `production/`,
`planogramme/`, `visites/`, `prospection/`, `newsletter/`).

**Corrections de rôle** :
- pastilles d'état des projets et des tâches : « En retard » n'est plus en
  bordeaux plein (couleur de l'action) mais en `--stop-soft` / `--stop` ;
  « Terminé » en fond réussite ; « En pause » en attention ; « À lancer »,
  « En cours », « Abandonné » en neutre ;
- titres et grands chiffres en bordeaux → encre (le bordeaux ne décore pas un
  titre) ;
- **une seule action principale bordeaux par écran** : « Envoyer » de chaque
  ligne du Reporting, « Valider » / « Traiter » / « Relancer » des listes,
  « Enregistrer » de chaque carte des Paramètres, « + Alimentation » (vert plein)
  et « + Dépense » du Fonds passent en `.btn` ; boutons à liseré vert ou bordeaux
  du scouting en bouton neutre ;
- barre d'outils du scouting : elle passe à la ligne au lieu de déborder.

**Emoji retirés** de l'interface : libellés débarrassés de leur emoji, repli en
texte là où l'emoji était seul (« Relancer », « Plan ↗ », « (photo) »,
« (en attente) »), icônes SVG linéaires pour la barre d'onglets et les lignes de
Visites. Les glyphes typographiques (✓ ✕ ★ ▾) restent.

**Libellés** : rail sur une ligne (§8.1) — « Franchisés · évaluation et suivi »
→ « Franchisés », « Suivi journalier et opérations » → « Suivi journalier »,
« Projets de développement » → « Projets », « Développement commercial » →
« Démarchage » (les titres d'écran gardent le nom complet) ; anglais :
« App worker ↗ » → « App employés ↗ ».

**Configuration** : « Retour à l’ERP » n'avait pas d'adresse. Nouvelle clé
`erpUrl` (`config/config.php`, ou `COCKPIT_ERP_URL`, ou le réglage `erpUrl`),
exposée par `GET /meta`. **Sans valeur, le lien n'est pas affiché** — rien n'est
écrit en dur.

### Fichiers touchés

`public/assets/ds/global.css`, `public/assets/css/app.css`,
`public/assets/css/controles-photo.css`, `public/index.html`,
`public/assets/js/{templates,app,scouting-tpl,scouting,franchises-tpl,franchises-vals,visites,newsletter,prospection}.js`,
`public/dashboard/{dashboard.css,dashboard.js,suivi.html,suivi.js,mq_reseau.html}`,
`public/production/{production.css,production.js}`,
`public/planogramme/{plano.css,plano.js}`, `public/{visites,prospection,newsletter}/index.html`,
`src/endpoints.php`, `config/config.example.php` ; ajoutés :
`docs/design-system/`, `tools/design-system/normalise.js`.

Aucune route, aucun appel d'API, aucune donnée ni aucun texte métier n'a changé,
hors les libellés listés ci-dessus.

## 4. Écarts restants et décisions à valider

**Ajouts propres au BO, à valider** (signalés comme le demande le §8.3) :
1. **Compteurs dans le rail** (posts à contrôler, projets en retard, tâches à
   valider…) : gardés, sous la forme du §8.3 — un nombre en 11 px `--accent`
   poussé à droite, sans pastille.
2. **Recherche globale** sous l'en-tête du rail : fonction du cockpit, gardée,
   au gabarit des champs de la barre d'outils.
3. **Sous-menus repliables** (« Suivi journalier », « Suivi de terrain »,
   « Gestion de production ») : chevron à droite, enfants décalés de 35 px.
4. **Bloc compte** en bas du rail (`.sidebar__account`) : nom, rôle, version
   servie, « Quitter » ; l'avatar rond a disparu (forme ronde non admise).
5. **Intro d'écran** : la phrase qui explique chaque écran est gardée, en
   12,5 px `--muted` sous la barre supérieure (`.page-intro`). Le Marketing n'en
   a pas : à garder ou retirer.
6. **Kicker** : « Vue Réseau » partout, « Vue Franchisé » pour le groupe « ERP
   franchisé ».

**Restent à faire** :
- **Documents (§13)** : PDF et e-mails générés côté serveur (`src/rapports.php`,
  `dossier.php`, `mkt_brief.php`, `plan_pdf.php`, `resultat_pdf.php`,
  `ca_mail.php`, `newsletter_envoi.php`, `scouting_dossier.php`…) — ≈ 1 000
  teintes, titres en Georgia, `sans-serif` système. Ils partent chez les
  franchisés : à aligner sur la version A dans un chantier à part, pièce par
  pièce, avec une capture de validation. Le dossier d'implantation du scouting
  (imprimé depuis l'écran) est déjà ramené aux valeurs du système, en
  hexadécimal (sa fenêtre d'impression n'a pas la feuille commune).
- **Styles en ligne** : leurs valeurs sont celles du système, mais la plupart
  des boutons, champs et tableaux du cockpit restent écrits en ligne. Les
  remplacer par les classes du §7 (`.btn`, `.table-card`, `.field`…) se fera
  écran par écran ; la coquille, les onglets et les boutons repris ci-dessus
  utilisent déjà les classes.
- **Pages à part ouvertes seules** (dashboard magasin, production, visites,
  prospection mobile, planogramme) : jetons, graisses, tailles et formes du
  système, mais **pas le rail du §8** — ce sont des outils de terrain, souvent
  sur téléphone ou tablette, avec leur propre en-tête. Le §8.3 interdit
  d'inventer un tiroir mobile : à décider.
- **Fonds bordeaux pâle** (`rgba(141,29,44,.1x)`) encore employés comme fond
  d'alerte à une vingtaine d'endroits : à revoir au cas par cas (alerte →
  `--stop-soft` ou `--prize-soft`, sélection → reste bordeaux).
- **Filtres** à fond bordeaux plein quand ils sont actifs : conformes au
  `.filter.is-on` du §7.6 ; quelques-uns seraient mieux en sélecteur segmenté
  (`.seg`, « Mois clos / Année / Marge »).
- **Italique** : aucun fichier Gotham italique n'est déclaré (§4.1) ; les
  rares italiques sont synthétisés par le navigateur.
- **Hors périmètre** : `public/tablette/` (compilation du dépôt
  `pwa_sales_tablet`), `public/assistant/` (compilation de
  `tools/assistant-src`, copie ancienne du Marketing), `public/employee/` (app
  des employés, Bootstrap / Mazer) — à aligner dans leurs sources.

## 5. Checklist d'acceptation (§12)

- [x] Gotham partout, deux `@font-face` du §4.1, aucune autre police d'interface (seul `monospace` reste pour les URL et codes affichés).
- [x] Aucun gras hors de la liste du §4.3 : 0 `font-weight` 500 / 600 / bold restant ; 700 seulement sur les chiffres clés ; vérifié en mode incorporé sur 19 écrans (0 texte en gras sous 20 px).
- [x] Jetons du §3 posés ; teintes d'interface remplacées (tableau 2.2) ; restent les couleurs d'identification listées.
- [x] Tailles de texte dans l'échelle du §4.2.
- [x] Fond papier, blocs blancs bordés `#e6e0d8`, aucune ombre hors interrupteur et sélecteur segmenté.
- [~] Une seule action principale bordeaux par écran : corrigé sur les écrans à listes et sur les Paramètres ; à vérifier sur les modales et assistants.
- [~] Champs, filtres, pastilles, onglets, tableaux, cartes, badges : valeurs conformes ; passage aux classes du §7 à poursuivre (voir § 4).
- [x] Focus clavier visible partout (contour bordeaux 2 px).
- [x] Aucune pilule : 0 `999px` restant hors interrupteur et point de couleur.
- [x] Page seule : rail du §8 (246 px, entrées 400 / active 700, une seule active) et barre supérieure.
- [x] Page incorporée : pas de rail, barre supérieure propre, liens qui gardent `embed=1`, aucun défilement horizontal à 1 194 px (mesuré sur 19 écrans).
- [x] Aucune route cassée, aucun comportement changé, aucune donnée inventée ; libellé anglais corrigé.
- [x] Captures avant / après : `docs/design-system/captures/`.
- [x] Compte rendu : ce fichier.

## 6. Vérifier en local

```bash
cp config/config.example.php config/config.php   # identifiants MySQL, 'seed' => true
PHP_CLI_SERVER_WORKERS=8 php -S 0.0.0.0:8080 -t public public/router.php
```

Page seule : `http://localhost:8080/#/performance`. Page incorporée : ouvrir
`http://localhost:8080/?embed=1#/performance` dans un `iframe` de 1 194 px.
