# Deux articles du Manuel pour la partie 4 « Formation continue » (22/11/2026)

Demande (plan Formation continue, ajout en cours de demande) : « rajouter dans le manuel opératoire la procédure lors
d’un nouveau produit, comment fonctionnent les formations ».

| Fichier | Code proposé | Source | Parties |
|---|---|---|---|
| `art-nouveau-produit.json` | 0.0.61 | maquette `06-manuel-nouveau-produit.html` : « Un nouveau produit arrive : la procédure » | 5 sections, 10 parties, 4 questions, 2 points critiques |
| `art-fonctionnement-formations.json` | 0.0.62 | maquette `07-manuel-comment-fonctionnent-les-formations.html` : « Comment fonctionnent les formations » | 5 sections, 21 parties, 3 questions, 2 points critiques |

Sources : les deux maquettes (contenu validé), dans `formation/docs/maquettes/formation-continue/`, et le plan
`formation/docs/maquettes/formation-continue/PLAN.md` (la validation par les 14 jours de photos, décision 7). Les
écrans 02, 04, 05 et 08 du même dossier ont servi pour le vocabulaire (« Formations et suivi », le Professeur, le
document de formation). Le plan du coffre-fort (`consultant/docs/maquettes/coffre-fort/PLAN.md`) a servi pour
l’onglet Formation. Les textes sont rédigés à la forme impersonnelle (« on… »), comme le reste du Manuel. FR :
rédaction d’après les maquettes. NL : traduction de travail, **à relire**.

**Pas encore encodés.** Ils seront encodés sur demande, en v1.0 « à valider », accès `tous` (bouton « Manuel
opératoire » de la tablette). Publication ensuite, aussi sur demande (« publie 0.0.61 », « publie 0.0.62 »).

## Les règles écrites (celles en vigueur)

- Une formation continue est validée pour un magasin quand l’équipe a lu, réussi le quiz et été « vu faire ». Pour un
  **nouveau produit**, et pour une **technique** ou **nouvelle technique reliée à un produit**, il faut en plus que le
  magasin envoie **une photo qualité du produit par jour pendant 14 jours**. La photo est obligatoire et s’envoie dans
  son dossier de formation (page « Formations et suivi » du dashboard).
- Le consultant relit chaque photo : conforme ou à refaire. Un jour sans photo, ou une photo refusée, **ajoute un
  jour**. Une photo refusée le jour même peut encore être refaite ce jour-là. Les formations « connaissance » n’ont
  pas de photo.
- Recoupé avec `formation/src/formation_continue.php` (fusionné par la PR #236). Il faut 14 photos conformes
  (`fcPhotosGrille`), et la photo du jour se remplace tant qu’elle n’est pas conforme (`fcGarderPhoto`). Les jours de
  photo valent 14 d’office pour un nouveau produit et 0 sinon ; le siège les règle sur la fiche (0 à 30), par exemple
  pour une technique reliée à un produit (`fcPhotosDefaut`, `photosJours`). Les jours comptent à partir de la date
  « Photos à partir du (mise en vente) » choisie à la diffusion, sinon du jour de la diffusion (`wr_fc_diffuser`) ;
  la première photo envoyée ne fixe le départ que si aucune date n’a été posée. Les articles disent « à partir de la
  mise en vente ».
- À la validation, le nom du consultant et la date sont enregistrés. L’archive (les 14 photos, qui, quand) entre dans
  le **document de formation** du franchisé, rangé dans son **coffre-fort**, onglet Formation.
- Le franchisé accède à tout cela par le bouton abricot « Mon dossier de formation », qui ouvre « Formations et
  suivi » ; depuis la PR #238, la page s’ouvre sur l’accueil de la formation et le dossier est à un clic (tuile « Mon
  dossier de formation … Ouvrir › »). Le Professeur répond à partir
  des formations du magasin, adaptations comprises, puis du Manuel.

## Le classement

- **0.0.61** : sous-catégorie D5.3 Production. Phase Formation continue, postes P1 à P12 (« P1 à P12 » de la
  maquette). Formations FO-08 (pâtisserie technique), FO-26 (réception et ProdAtelier) et FO-16 (allergènes et FIC),
  celles de la maquette. Compétences : les trois de ces FO (C-PROD.Pâtisserie, C-STOCK.Réception, C-TRANS.Conformité).
- **0.0.62** : sous-catégorie D6.3 Ressources humaines › Formation. Phase Formation continue, postes P1 à P12 (« pour
  tous »). Formation FO-06 et compétence C-RH.Évaluation, celles de D6.3.
- Dossier `04`, poste `vente` et moment vide, comme les autres articles. Durées : celles des maquettes (12 et 8 min).
- **Code** : vide dans les fichiers. À la création, le serveur donne le premier code libre de la zone 0
  (`foCodeAuto`). 0.0.57 à 0.0.60 sont pris : on encode d’abord `art-nouveau-produit` (0.0.61), puis
  `art-fonctionnement-formations` (0.0.62). Un trou plus bas donnerait un autre code. On peut aussi écrire le code
  dans le fichier avant l’encodage. Une fois donné, le code se recopie dans le fichier, comme pour 0.0.54.

## Encoder

Depuis `formation/`, simulation d’abord, puis `--ecrire` :

```bash
python3 tools/formation/encoder_sop.py --api <adresse>/formation/api/cockpit data/formation/continue/art-nouveau-produit.json data/formation/continue/art-fonctionnement-formations.json
python3 tools/formation/encoder_sop.py --api <adresse>/formation/api/cockpit --ecrire --par "…" data/formation/continue/art-nouveau-produit.json data/formation/continue/art-fonctionnement-formations.json
```

Vérifié le 22/11/2026, sans réseau :

- `python3 -m json.tool` ;
- les mêmes clés que les autres articles, et les codes du référentiel v25 (`shared/src/data/referentiel_v25.json`) ;
- `canon()` d’`encoder_sop.py` ;
- la simulation d’`encoder_sop.py`, avec l’API remplacée par un bouchon qui répond 404. Elle donne « absent du
  module → création (v1.0, à valider) », 0 photo et le classement ci-dessus.

L’encodeur n’a pas de mode hors ligne : sa simulation lit l’API (GET).

## Écarts avec les maquettes

- **La photo du jour** : les maquettes 06 et 07 parlent d’une « tâche unique » du panel, désactivée et gardée à la
  validation. Les articles suivent la règle en vigueur : la photo s’envoie dans le dossier de formation. Ils ne parlent
  pas de tâche du panel.
- **Les renvois** entre les deux articles se font par leur titre, pas par leur code, puisque les codes ne sont pas
  encore donnés. On peut ajouter les codes une fois les articles encodés.
- Chaque partie a reçu un titre court (les maquettes n’en ont pas). Dans la partie 10 de 0.0.61, « adapte si besoin »
  devient une phrase sur l’adaptation (faite par le consultant, pour ce magasin seulement, tracée).
- Ajouts : 0.0.62 dit les trois statuts réseau (brouillon, à valider, validée) et quand il faut des photos (une partie
  « Quand faut-il des photos »). Il dit aussi que le franchisé veille à ce que la photo du jour soit envoyée.

## À confirmer

- **Les écrans décrits** (bouton abricot, « Formations et suivi », Nouveautés, photo du jour, Professeur relié au
  dossier, document de formation au coffre-fort) : vérifier qu’ils sont en ligne avant la publication.
- **Le calendrier** J-21 à J+14, la production d’essai « goûtée et pesée » et l’argumentaire « en trois phrases »
  viennent de la maquette 06. Ce sont des repères, à confirmer comme règles du réseau.
- **Le classement de 0.0.62** (D6.3, FO-06, C-RH.Évaluation) et la sous-catégorie D5.3 de 0.0.61 : choisis ici.
- **NL** : traduction de travail, à relire. Les choix de vocabulaire :
  - formation continue : « permanente opleiding », comme `guides/formations.json` ;
  - « vu faire » : « voorgedaan », comme la tablette ;
  - garant d’ouverture : « openingsbegeleider » ;
  - siège : « hoofdkantoor » ;
  - noms des écrans traduits : « Mijn opleidingsdossier », « Opleidingen en opvolging », « Nieuwigheden » ;
  - coffre-fort : « kluis » ; document de formation : « opleidingsdocument ».
- **Quiz** : les questions sont proposées par l’assistant, à relire avant publication.
