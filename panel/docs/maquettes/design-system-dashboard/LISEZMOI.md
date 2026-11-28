# Le dashboard magasin au design system — maquette de la vue Opérationnel

Demande du 28/11/2026 : « comment appliquer le design system au dashboard des franchisés ? fais-moi une maquette ».

- `index.html` : la vue **Opérationnel** (l'écran d'ouverture du franchisé), refaite au design system
  (`docs/DESIGN_SYSTEM.md`). Elle charge **la feuille commune** `shared/public/assets/ds/global.css` (jetons, Gotham,
  rail, barre, cartes, boutons, `.seg`, `.filter`, tableaux, `.chip`, `.tag-warn`…), puis `dashboard-ds.css` : les
  seuls composants propres au dashboard (jours, résumé, barres d'objectif, courbe, vitrine, contrôles), écrits avec
  les jetons du §3 et rien d'autre : pas de couleur en dur, pas d'ombre, pas de dégradé, pas de pilule, gras réservé
  aux valeurs clés.
- `avant.png` / `apres.png` : l'écran à l'ouverture (1440 × 900) ; `avant-plein.png` / `apres-plein.png` : la page
  entière ; `avant-apres.png` : les deux côte à côte.
- `capturer.cjs` : refait les captures. L'avant est **le vrai dashboard**, servi en local avec la fausse API du
  tournage (`panel/tools/videos/mock.js`).

```
node panel/docs/maquettes/design-system-dashboard/capturer.cjs
```

## Les chiffres

Ceux du **Magasin démo** (données fictives du tournage, horloge figée au jeudi 19/11/2026 à 15 h 40), repris de
l'avant. Seule retouche : les canaux (comptoir, commandes, webshop) sont recalculés pour que leur somme fasse le
comptoir (1 542 €), ce que la fausse API ne fait pas (1 475 + 66,81 + 97,63 €). Les photos des contrôles sont des
cases vides.

## Où en est le dashboard

Les fondations sont **déjà posées** : `global.css` est chargé en premier, les anciens jetons (`--color-*`,
`--font-display`) y pointent sur les jetons du §3, Gotham est la seule police, le rail (§8), la barre supérieure (§7.1)
et le résumé « À corriger » (§7.13, Mon équipe puis Opérationnel) sont en place. Ce qui reste est **dans les
feuilles du dashboard**, relevé le 28/11/2026 (`panel/public/dashboard/*.css`) :

| Écart (§ du design system) | Où | Combien |
|---|---|---|
| Couleurs écrites en dur au lieu des jetons (§3) | `dashboard.css` | ≈ 600 (dont des bleus `#1f5f8b`, `#2F6EA5`, `#2a78d6`, l'orange `#B45309`, le noir `#1d1d1b`) |
| | `catalogue.css`, `equipe.css`, `formation.css`, `offres.css`, `commandes.css` | 105, 57, 20, 18, 15 |
| Graisses 500 / 600 interdites (§4.1) | `equipe.css` 40, `catalogue.css` 24, `dashboard.css` 4 | 68 |
| Dégradés (§11 : hors système ; les hachures « en cours » restent) | `dashboard.css` 27 (dont 16 hachures), `equipe.css` 19, `boite.css` 8 | ≈ 70 |
| Ombres (§2 : seulement l'interrupteur et le `.seg`) | `dashboard.css` 40, `catalogue.css` 27, `equipe.css` 22 | ≈ 105 |
| Filets en pointillé entre les lignes (§7.8 : `--row-line` plein) | `dashboard.css` 51, `equipe.css` 18 | ≈ 90 |
| Pilules `999px` (§5) | `catalogue.css` 6, `dashboard.css` 5, `clients.css` 3 | 17 |
| Tailles hors échelle (§4.2 : 15,5 px, 17 px, 9 px…) | `dashboard.css` 56, `tresorerie.css` 11 | ≈ 130 |
| Styles en ligne calculés en JavaScript | `dashboard.js` | ≈ 400 `style=` |

## Ce que la maquette change, bloc par bloc

| Bloc | Avant | Après | Règle |
|---|---|---|---|
| Rail | Assistant IA sur fond abricot, carré bordeaux plein et pastille « 1 » pour les vidéos | trois entrées au repos (Boîte mail, Assistant IA, Vidéos), compteurs en nombre bordeaux 11 px | §8.1, §8.3 |
| Rail | objectif du jour en barre or, sans mot ; étoiles pleines | bloc bordé, barre fine, point et mot (« en avance », « au-dessus de la cible ») | §3 : jamais la couleur seule |
| Barre supérieure | « Relire » est une carte parmi cinq ; jour choisi dans la frise seulement | « Relu à 15 h 40 » et la navigation des jours dans la barre ; kicker « Vue Franchisé · Magasin démo » | §7.1 |
| État du magasin | cinq cartes à icône | une carte, quatre cellules séparées par un filet | §2 |
| Jours | « L 16 », point de couleur sans mot, jours à venir en pointillé | « lun. 16 », « 108 % · atteint », sélection en liseré bordeaux sur fond teinté | §7.6, §7.13 |
| Résumé | la valeur et une ligne | + la jauge (attendu à l'heure, objectif), le geste (« Reste 237 € en 3 h 20 ») | §7.13 |
| Ta place dans le réseau | cinq tuiles, rangs en étiquettes noires pleines, titre en bordeaux, textes coupés « … » | un tableau ; le rang en mots (« 4e sur 9 ») et en neuf points | §7.8, §2 (bordeaux jamais pour un titre) |
| Objectif comptoir et pro | jeudi dernier en bleu plein (le bleu de l'**objectif**), étiquettes dans les barres, barre des canaux or / bordeaux / bleu sans légende ; un second bloc pour le pro | réalisé `--chart-real`, jeudi dernier `--chart-prev`, objectif `--chart-goal`, projection hachurée ; les quatre canaux (pro compris) en chiffres clés | §3 (couleurs de graphique) |
| Heure par heure | les vues se choisissent sur des boutons bordeaux pleins | deux sélecteurs segmentés | §2 : un bouton FAIT, une pastille CHOISIT |
| Le duel | valeurs en bordeaux et en bleu | valeurs à l'encre ; l'écart en vert ou en or **avec son mot** (« devant », « derrière ») | §2, §3 |
| P&L court | barres noires, or et vertes, sans mot | tableau ; une étiquette par seuil (« au-dessus du seuil », « au seuil ») | §7.9 |
| Ventes par catégorie | barres vertes et orange (l'orange vif est retiré le 26/11) | poids sur une seule teinte ; la marge brute sur l'échelle unique : faible / moyenne / bonne | §7.13 |
| Vitrine | lettres S, M, L sur fonds rouge, or et bleu | case neutre, l'état en étiquette | §3 |
| Contrôles en photo | en-têtes de carte pleins (vert, bordeaux, bleu), heures en pilules, « Tout » noir | cartes blanches, filtres du §7.6, l'état par une étiquette | §2, §5, §7.6 |

## Comment l'appliquer au vrai dashboard

Page par page (méthode du §10), **en changeant l'apparence, pas le fonctionnement** : mêmes routes, mêmes données,
mêmes libellés.

1. **Les jetons d'abord** : dans `dashboard.css` puis les autres feuilles, chaque couleur en dur devient le jeton du
   même rôle (texte secondaire → `--muted` ; bleu « jeudi dernier » → `--chart-prev` ; bleu d'objectif →
   `--chart-goal` ; orange → l'échelle `--stop` / `--prize` / `--green`). Même chose dans les `style=` de `dashboard.js`.
2. **Les composants communs** : `.db-ong` (vues d'une même liste) → `.seg` ; boutons de choix bordeaux pleins →
   `.seg` ou `.filter` ; `table.db-t` → `.ds-table` ; étiquettes noires → `.badge` / `.chip` ; pointillés → `--row-line`.
3. **Les tuiles qui se répètent** deviennent un résumé (§7.13) ou un tableau (§7.8).
4. **Les feuilles les plus éloignées** ensuite : `equipe.css` (graisses 600), `catalogue.css`.
5. **Vérifier** : capture avant / après à 1440 × 900 de chaque vue, et la checklist du §12.

L'ordre conseillé des vues : Opérationnel (cette maquette), Semaine, Mois, Année (mêmes composants), puis Commandes,
Tâches, Temps morts, Trésorerie, Vendre, Marketing.

## À décider (écarts que la maquette ne tranche pas seule)

- **La fête de l'objectif atteint** (`.db-or`, `.db-rec` : carte dorée en dégradé, barre qui brille, confettis, feux
  d'artifice pour un record) : hors système (pas de dégradé, pas d'ombre). Proposition : la pastille verte
  « atteint » et le mot, sans animation. C'est un choix de produit, pas une correction.
- **Les deux repères du rail** (objectif du jour, note Google) : le rail du §8 n'en a pas. Gardés ici en bloc bordé.
- **Les compteurs du rail** (« 3 », « 1 nouvelle », « 2 ») : le Marketing n'en a pas ; forme du §8.3 en attendant.
- **Le kicker** « Vue Franchisé · Magasin démo » : le §7.1 demande le périmètre ; aujourd'hui seul le magasin est écrit.
