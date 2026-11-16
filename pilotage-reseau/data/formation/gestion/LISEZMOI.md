# FO-41 Gestion financière avancée, pôle vente — la source

| Fichier | Rôle |
|---|---|
| `art-fo41-vente.json` | Article du manuel « Pôle vente : les leviers du chiffre d'affaires » — la mini formation : 6 sections, 15 parties, 4 points critiques, quiz de 5 questions ; FR, NL de travail (à relire) |
| `photos/lev-*.jpg` | les six schémas de l'article, 1600 × 1200 (le cadre 4:3 de la tablette), libellés FR et NL — `tools/formation/schemas_leviers.js` les redessine |
| `photos.json` | (écrit à l'encodage) empreinte de chaque photo → son nom au serveur `u/…` : une photo inchangée n'est pas renvoyée |

Trois parties montrent des captures du dashboard déjà au serveur (« Le duel », « Les six leviers », « Ta place dans
le réseau ») : leur nom `u/…` vient de `data/formation/dashboard/photos.json`, rien n'est renvoyé.

## Le classement

- **Formation** FO-41 Gestion financière avancée (demandé le 16/11/2026 : « dans la formation gestion il y a le pôle
  achat et le pôle vente » ; cet article est le pôle vente). Sur la tablette, il se range dans l'onglet Manuel,
  bouton « Manuel opératoire » (accès `tous`), à sa formation.
- **Sous-catégorie** D7.4 Politique de prix (FO-41 et FO-05), **phase** Formation continue (FO-41 est en `con`),
  **postes** P7 à P12 (l'attendu de FO-41), **compétences** C-PILOTAGE.Finance et C-PILOTAGE.Vente.
- **Code** : laissé vide dans le fichier, la création donne le premier libre de la zone 0 sans moment
  (`foCodeAuto`, 0.0.52 si personne ne l'a pris entre-temps) ; l'encodeur l'affiche.

## L'exemple chiffré

Un magasin type, pas un magasin du réseau (le manuel est lu partout) : 150 clients par jour, panier de 12 €, 26 jours
ouverts par mois, matière à 40 % du prix de vente (le taux budget du réseau), 10 000 € de personnel par mois. Même
calcul que le dossier Sombreffe (`docs/dossiers/sombreffe-2026-11`, dernière page) : le trafic et le panier coûtent
leur matière, le prix (à volume égal) non ; l'équipe reste la même. Changer l'exemple, c'est changer `EX` dans
`schemas_leviers.js` **et** les chiffres du texte de l'article.

## Encoder

Depuis `pilotage-reseau/`, simulation d'abord, puis `--ecrire` :

```bash
python3 tools/formation/encoder_sop.py --api <adresse>/formation/api/cockpit data/formation/gestion/art-fo41-vente.json
python3 tools/formation/encoder_sop.py --api <adresse>/formation/api/cockpit --ecrire --par "…" data/formation/gestion/art-fo41-vente.json
```

## À confirmer

- Le pôle achat de FO-41 et l'article de la formation (ART-FO-41, 0.0.28) vivent dans la base du cockpit, pas dans le
  dépôt : ils n'ont pas été relus pour écrire celui-ci. Si le pôle vente y a déjà des parties, les accorder.
- « En parler à son consultant avant de changer un prix en caisse » (partie 10) : la règle du réseau sur les prix à
  confirmer.
- NL de travail : à relire (noms des écrans du dashboard traduits comme dans ART-PIL-01).
