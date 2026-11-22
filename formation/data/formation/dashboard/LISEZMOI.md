# Le dashboard magasin dans le module Formation — la source

| Fichier | Rôle |
|---|---|
| `art-pil-01.json` | **ART-PIL-01**, Article du manuel « Comprendre le dashboard magasin » — FO-05, pré-ouverture (la compréhension) |
| `sop-pil-01.json` | **SOP-PIL-01**, SOP « Analyser le dashboard magasin : la routine » — FO-05, formation continue (l'utilisation), moment Ouverture |
| `photos/dash-*.jpg` | les captures du dashboard, 1600 × 1200 (le cadre 4:3 de la tablette), bloc décrit entouré en bordeaux — `tools/formation/captures_dashboard.js` |
| `photos.json` | (écrit à l'encodage) empreinte de chaque photo → son nom au serveur `u/…` : une photo inchangée n'est pas renvoyée |
| `ETAT.json` | le dernier commit du dashboard pris en compte, la date des captures, encodé ou non |

Même modèle que `data/formation/sops/*.json`, plus `classement` (sous-catégorie, phases, postes, compétences,
formations). Ces fichiers ne sont **pas** repris par `foImporter()` (qui ne lit que `sops/`, et seulement sur une
base vide) : ils entrent dans le module par l'API, avec `tools/formation/encoder_sop.py` (simulation par défaut,
`--ecrire` pour encoder). Le fichier est la source : une correction se fait ici, puis s'encode en nouvelle version.

Proposition, maquette et routine de mise à jour : `docs/maquettes/sop-dashboard/`.
