# Parcours compétences — maquettes (31/10/2026)

Pour chaque personne (employé ou candidat franchisé) : un **assistant** qui lui trace le chemin vers le
poste qu'elle vise, et une **frise horizontale** pour suivre ce chemin (module de l'application mobile).
Pour l'instant, une seule entrée dans le rail du cockpit, *Formation › Parcours compétences ↗* ; le module
passera dans l'application employé ensuite.

À valider avant de développer. Les personnes et les dates sont des exemples ; les postes, niveaux,
compétences, évaluations et formations sont les vraies données (panel + référentiel v25).

| Capture | Écran |
|---|---|
| `01-rail.png` | L'entrée dans le rail du cockpit |
| `02-assistant-1-destination.png` | Assistant 1/3 — Où veux-tu aller ? (postes visés, ce que ça demande) |
| `03-assistant-2-acquis.png` | Assistant 2/3 — Ce que tu sais déjà (les compétences du niveau, à cocher) |
| `04-assistant-3-parcours.png` | Assistant 3/3 — Ton parcours est prêt (nombre de formations, rythme, qui valide) |
| `05-frise-suivi.png` | La frise de suivi, dans l'application : fait, en cours, à venir, l'objectif |
| `06-parcours-equipes.png` | Cockpit, module Formation : les parcours de l'équipe, une frise par personne |
| `07-fiche-formation.png` | Cockpit : une formation = une seule fiche (fusion panel + v25) |
| `planche.png` | Les quatre écrans du téléphone côte à côte |

Régénérer : `python3 -I docs/maquettes/parcours-competences/generer.py` puis
`node docs/maquettes/parcours-competences/captures.js`. Le référentiel Excel se relit avec
`python3 -I tools/formation/referentiel_excel.py <fichier.xlsx> src/data/referentiel_v25.json`.

## Intégrer le référentiel v25 — simple, sans doublon

**Règle : les données des API (panel) ont toujours priorité.** L'Excel ne fait que compléter ce que le
panel n'a pas.

| Dans l'Excel | Dans le panel (prioritaire) | Ce qu'on garde |
|---|---|---|
| 12 postes P1…P12 | 16 postes et leurs niveaux | Les **postes et niveaux du panel**. « Chef de section Vente / Boul-Pât / Traiteur » = le niveau *Responsable d'équipe* du même poste, pas un poste de plus. |
| 53 formations FO-01…FO-53 | 30 « formations » nommées *[FO-01] Évaluation…* (5 par FO, FO-01 à FO-06) | **Une formation = une fiche** : le nom FO-xx vient de l'Excel, ses **5 évaluations** du panel. Les FO-07…FO-53 attendent leurs évaluations dans le panel. |
| 56 compétences « C-VENTE.Accueil »… | 107 compétences, reliées aux niveaux et aux évaluations | Les **compétences du panel**. Celles de l'Excel ne servent que de description de la formation quand le panel n'en a pas. |
| Matrice O / R formation × poste | Les compétences exigées par chaque niveau | Ce qu'un niveau exige = **ses compétences dans le panel** ; les formations qui y mènent = celles que le panel y rattache, **complétées** par les « O » de la matrice. Les « R » = « pour aller plus loin », facultatif. |
| 4 plans (Étudiant, Vendeur, Gérant, Multi) en 21 blocs | — | Ils donnent **l'ordre** des formations dans le parcours. Pas d'écran à part. |
| Priorités business (Trafic, Récurrence, XP, coûts) | — | Une petite étiquette sur la formation. |
| Manuel opératoire (8 domaines, 36 sous-catégories) | Les procédures opératoires | Les **procédures du panel** ; la référence MO § de l'Excel s'affiche sur la fiche formation. |

Ce que la personne voit : **un objectif** (le niveau visé), **des formations** à suivre dans l'ordre, chacune
validée par ses évaluations. Valider une formation coche en même temps, dans le panel, les compétences qui y
sont rattachées : une seule action, pas deux.

## Ce qu'il faudra stocker en plus (cockpit)

Le panel n'a ni parcours ni suivi de formation par personne. Deux tables suffisent :

- `ceo_parcours` — personne (id panel), niveau visé, rythme, date de départ, statut ;
- `ceo_parcours_etape` — une ligne par formation du parcours : code FO, ordre, date prévue, statut
  (à venir, en cours, validée), évaluations faites, validée par, le.

Et le référentiel v25 lu dans `src/data/referentiel_v25.json` pour ce que le panel n'a pas : noms des
FO, matrice O/R, ordre des plans, priorités.

## À trancher

1. **Postes absents du panel** : *Locataire-gérant* (P9) et *Franchisé multi-boutique* (P12). Proposition :
   deux niveaux du poste *Franchisé* (il n'en a qu'un, « Franchise »).
2. **Qui valide** une étape : le manager du magasin pour les employés, le consultant pour les candidats
   franchisés ? (maquette : la manager.)
3. **Rythme par défaut** : une formation toutes les 3 semaines (« Normal »), 1 par mois (« Tranquille »),
   1 toutes les 2 semaines (« Rapide »).
4. Les **niveaux sans compétences** dans le panel (11 sur 25, dont *Franchisé*) : le parcours se fait alors
   sur la matrice v25 seule, en attendant que le panel soit complété.
