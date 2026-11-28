# Fidélité › Cartes de fidélité : la carte à points et le bingo, particuliers et entreprises — maquettes

**Statut : C retenue et codée le 28/11/2026** (Sam : « C, code-le »). Sam, sur `dashboard/?shop=4&vue=fidelite` :
« réorganiser la vision des cartes des points et bingo par rapport aux clients et aux firmes, faire une maquette ».

## Ce qui est codé (C)

- **Dashboard › Fidélité › Cartes de fidélité** (`panel/public/dashboard/dashboard.js` : `rendCartes`, `fdTuile`,
  `fdBloc`, `fdBlocBingo`, `fdGrille` ; styles `.fdp-*` de `dashboard.css`) : l'interrupteur abricot Particuliers ·
  Entreprises (gardé dans l'adresse : `&pub=p|e`). Particuliers : le résumé et « À corriger » (récompenses prêtes, clients
  à 1 ou 2 timbres, clients à moins de 3 € de leur case), une carte dessinée par carte de l'ERP (à timbres ou à points ;
  un clic ouvre son détail) et le bingo de la saison (13 semaines, combien ont coché chacune), puis la liste (ses
  cartes, sa grille de 13 cases ; filtres Récompense prête, À moins de 3 €, Inactifs ici). Entreprises : le résumé, une
  tuile par société (son compte, ses personnes : récompenses, case de la semaine, noms qui ouvrent la fiche), le nombre
  de sociétés sans carte. Le réseau reste en bas, tous publics.
- **GET /fidelite/cartes** (`marketing/src/fidelite_cartes.php`) range chaque inscrit : `type` (particulier, societe,
  personne), `societe`, et rend `entreprises` et `liens`. La société est un client B2B de TF Buddy ; une personne lui est
  rattachée par `company_client_id`, ou par son bureau quand le compte TF Buddy du magasin est connecté (`office_id` → la
  société du bureau, `/panel/shops/{s}/offices`). Les listes de clients sont celles de Vendre › Clients
  (`pclListe`, gardées un quart d'heure ; `pclCourt` garde désormais `societeId` et `bureauId`).
- **GET /fidelite/cartes/bingo?shop=** (nouveau) : la saison du jour (`shared/src/data/fidelite_bingo.json`), ses règles,
  et pour chaque client du magasin ses achats semaine après semaine, tous magasins additionnés, et ses cases. Lecture
  seule (aucun bon émis) ; une semaine close se garde un jour, la semaine en cours dix minutes (fichiers temporaires).
- Tests : `php marketing/bin/tests_fidelite_cartes.php` (ERP et TF Buddy simulés). Captures du vrai écran, données
  fictives des vidéos : `code-particuliers.png`, `code-entreprises.png` (`capturer-code.js`).

Écarts avec la maquette : le réseau garde ses colonnes d'aujourd'hui (inscrits, actifs, timbres et points, ventes) — le
séparer par public demanderait les clients de TF Buddy de chaque magasin ; « Proposer la carte au bureau » n'est pas un
bouton (aucune route ne l'inscrit) mais une phrase dans la tuile et dans « À corriger ».

Le 28/11/2026, à Halle, aucun client de TF Buddy n'a de `company_client_id` ni d'`office_id` : l'onglet Entreprises y
montre les comptes société inscrits (par exemple une société B2B qui a la carte à points), sans personne rattachée.

## Les maquettes d'origine

### Ce qui n'allait pas (Halle, onglet Cartes de fidélité, relevé le 28/11/2026)

- Une seule liste « Par client » : les sociétés y sont mêlées aux particuliers (un compte société y apparaît comme un
  client parmi d'autres), et rien ne relie une personne à la société de son bureau.
- Chaque carte est une ligne (entonnoir, répartition des timbres) : la carte à points et le bingo de la saison
  (`shared/src/data/fidelite_bingo.json` : la case de la semaine dès 10 €, un bon de 2 € toutes les 3 cases) ne sont
  pas présentés pour ce qu'ils font — les points font consommer, le bingo fait revenir.
- Le réseau compte des timbres ; il ne sépare ni les publics ni les cartes.

### Les trois propositions

| Fichier | Proposition |
|---|---|
| `a-particuliers.png`, `a-entreprises.png` | **A — le tableau croisé.** Le résumé, puis un tableau 2 × 2 : en lignes les deux cartes (points · bingo), en colonnes les deux publics (particuliers · entreprises). Dessous, la liste « Par client » sur l'interrupteur abricot Particuliers / Entreprises : un particulier = sa carte à points et sa grille de 13 cases ; une société = son compte, ses personnes inscrites (9 sur 23), ses points et son bingo, et elle s'ouvre sur ses personnes. |
| `b-bingo.png`, `b-points.png` | **B — la carte d'abord.** L'interrupteur choisit la carte. Bingo : la saison en 13 semaines (thèmes, bons), puis particuliers et entreprises côte à côte (qui a coché, qui relancer avant dimanche, société par société). Points : les soldes par tranche, les bons prêts, les proches du bon, côte à côte. |
| `c-particuliers.png`, `c-entreprises.png` | **C — le public d'abord.** L'interrupteur choisit le public, tout l'onglet suit (résumé, « À corriger »). Particuliers : les deux cartes dessinées, puis la liste. Entreprises : une tuile par société — en haut le compte de la société, en bas ses personnes (points, case de la semaine, noms), « Proposer la carte au bureau ». |

Dans les trois : « À corriger » compte les bons de 5 € prêts (particuliers / entreprises), les clients à moins de 3 € de
leur case avant dimanche, les sociétés dont aucune personne n'a la carte ; le réseau sépare particuliers, sociétés et
personnes d'entreprise, points gagnés et cases cochées.

### Ce que la maquette supposait

- **Une entreprise a deux niveaux** : le compte de la société (s'il a une carte : ce qui est facturé à la société) et
  ses personnes (chacune sa carte). Les personnes d'un bureau sont rangées sous leur société (le lien `company_client_id`
  de TF Buddy), pas dans les particuliers.
- **Le bingo d'une société** se coche au même seuil (10 € la semaine) : une société l'atteint presque toujours. Un seuil
  propre aux sociétés, ou pas de bingo au compte société, est à trancher.
- Le ×2 sur les points une fois la case cochée et l'échange automatique au palier n'existent pas dans l'API de l'ERP
  (voir `marketing/src/fidelite_bingo.php`) : la maquette montre les soldes et les bons prêts tels quels.

### Données des maquettes

**Fictives** (`donnees.js` : noms de clients et de sociétés inventés), à la forme de l'écran réel de Halle et des règles
du bingo ; samedi 28/11/2026, semaine 1 sur 13 de l'hiver. Les routes de fidélité de l'ERP ne répondent pas depuis une
session cloud (401). À brancher : `/shops/{id}/loyalty/customers` (cartes, soldes, activité), `/shops/{id}/clients`
(`is_b2b`, `company_client_id`), le calendrier de la saison (`fidelite_bingo.json`).

Régénérer : `node panel/docs/maquettes/fidelite-particuliers-entreprises/capturer.js`
(`index.html?v=a&l=p|e`, `?v=b&c=bingo|points`, `?v=c&l=p|e`).
