# Les non-conformités de la veille, dans le Dashboard › Jour

Maquettes pour la demande : *dans la vue Jour, ajouter les non-conformités des
tâches du jour −1*. Quatre partis pris, mêmes données, même charte
(`public/assets/ds/global.css` + `public/dashboard/dashboard.css`).

> **La V4 est en service** depuis le commit « Dashboard › Jour : les
> non-conformités de la veille ». Ces pages restent la trace de l'arbitrage ;
> l'écran vivant, lui, est `public/dashboard/dashboard.js` (`rendNC`,
> `ncTiroir`) et l'endpoint `GET /pwa/tasks/nc`.
>
> **Sans la validation.** Les maquettes portent des cases et un bouton
> « Valider les reprises » ; ils ont été retirés à la demande : sur les tâches,
> le dashboard est en lecture seule. Noter, valider et relancer se font dans
> Contrôle des tâches. Une contresignature déjà posée s'affiche dans la colonne
> « Aujourd'hui », comme un fait.

- `v1-tuile-bandeau.html` — une 6ᵉ tuile dans le bandeau des tâches, tiroir au clic.
- `v2-carte-dediee.html` — une carte à part sous le bandeau : compte, semaine, liste.
- `v3-bandeau-alerte.html` — un bandeau d'alerte en tête de page, et des marques
  dans le fil de la journée existant.
- `v4a-bandeau-replie.html` / `v4b-droplist-ouverte.html` /
  `v4c-apres-validation.html` — **la retenue** : le bandeau de la V3 déplie le
  contenu de la V2, et le tiroir porte le bouton de validation. Trois états :
  replié, ouvert, validé.

Les PNG du même nom sont les captures (Chromium, 1560 px, ×2).
`generer.js` régénère les trois pages ; `maquette.css` porte les styles
candidats à verser dans `dashboard.css`.

Les chiffres et les cinq non-conformités sont **fictifs** : magasin 4
(Waterloo — Centre), lundi 14 septembre 2026, veille au dimanche 13.

## Ce qui a été branché

Un endpoint, `GET /pwa/tasks/nc?shop=&date=` : les avis de la veille pour UNE
boutique, lus en base, sans appel au panel. Le tableau ci-dessous était le
relevé d'avant-travaux ; la colonne de droite dit où chaque donnée est allée.

| Donnée de la maquette | Source |
| --- | --- |
| La non-conformité | `/pwa/tasks?date=J-1` → `shops[].taches[]`, `accepte === false` (ou `note < seuil`) |
| Gravité (mineure / majeure / critique) | `/meta` → `signalement.niveaux` (5 Exemplaire · 4 Conforme · 3/2/1 NC mineur/majeur/critique) |
| Le constat | `comment` de la tâche — obligatoire sous le seuil, donc toujours présent |
| Qui a relevé, à quelle heure | `consultant`, `valideeLe` / `majLe` |
| La checklist | `checklist` |
| La colonne « Aujourd'hui » | la lecture **déjà faite** du jour : même `taskId`, statut `aControler` / `sansPhoto` / `nonRendue`, et `note` si elle est retombée (`ncEtat`) |
| La photo et ses repères | `/pwa/tasks/detail?shop=&task=&date=` (`photo`, `reperes`) — au clic seulement |
| Les 7 derniers jours (V2) | `/pwa/tasks/heatmap/mois?du=&au=` — déjà lu par les vues Semaine et Mois |

## La validation (V4) — **non retenue**

> Ce chapitre décrit le bouton des maquettes, retiré de l'écran livré : sur les
> tâches, le dashboard montre et ne modifie pas. Il reste ici parce qu'il dit
> ce que coûterait la validation le jour où on la voudrait, et surtout la règle
> qui la contraint.

Le bouton ne ferait rien de neuf : il appelle `POST /pwa/tasks/validate`
(`wr_pwa_task_validate`), une fois par tâche cochée, avec `shopId`, `taskId`,
`date` et `validated: true`. Cela pose `owner_validated_at`, `id_owner` et
`owner_name` sur l'avis dans `mac_task_review`, la table partagée avec le
panel, et écrit une ligne au Journal du cockpit.

Une règle en découle, et c'est elle qui dessine la colonne de droite : **on ne
valide que ce qui a été noté**. L'endpoint ne crée jamais de ligne, il met à
jour un avis existant ; sans note, il répond 422. D'où les trois cas :

| La ligne | Ce que la colonne propose |
| --- | --- |
| Reprise refaite **et notée** aujourd'hui | une case cochée, prise dans le bouton « Valider les N reprises » |
| Photo rendue, **pas encore notée** | « Noter la photo › », qui ouvre la notation (`POST /pwa/tasks/review`) — la validation n'est pas possible avant |
| Rien de rendu | « Relancer la boutique › » — il n'y a aucun avis à contresigner |

La date envoyée est celle de l'avis que l'on contresigne, donc **le jour en
cours** pour une reprise, pas la veille. Retirer la validation, c'est le même
appel avec `validated: false`.

Points ouverts, à trancher avant de coder :

1. **La veille d'un jour fermé.** Tranché par le silence : sans tâche notée la
   veille, le bandeau le dit (« aucun contrôle consigné ce jour-là ») au lieu de
   remonter au dernier jour ouvert. Remonter reste à faire si vous le voulez.
2. **Le seuil.** Tranché : l'endpoint renvoie `seuil` et `niveaux` avec les
   données, donc pas d'appel à `/meta`, et aucun libellé recopié dans le
   JavaScript — seul un repli de secours y vit, si le barème était vide.
3. **La récidive.** Faite, et elle ne coûte rien : une seule requête groupée sur
   sept jours de `mac_task_review`.
4. **Qui valide.** `owner_name` prend le nom du réglage `utilisateur` du
   cockpit, à défaut « CEO ». **À vérifier** avant de mettre le bouton entre
   d'autres mains que les vôtres.
