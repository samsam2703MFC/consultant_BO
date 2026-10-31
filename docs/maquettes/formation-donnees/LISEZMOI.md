# Maquettes — montrer les données du module Formation (31/10/2026)

Proposition, à valider avant développement. Vraies données du panel (lues le 31/10/2026) ;
seules les personnes de `05-equipe` sont fictives. Les effectifs des cartes (01a-c) sont de
vrais comptes par niveau (aucun nom). Écrans 02 à 05 validés le 31/10/2026 ; pour la carte des
métiers, les variantes A et C sont gardées, avec la règle : le gérant de magasin maîtrise les trois
métiers (vente, boulangerie-pâtisserie, traiteur). Maîtriser un métier = toutes ses compétences
jusqu'au niveau qui précède « Responsable d'équipe » (Équipier confirmé).

## Le constat

- **Les niveaux du panel ne sont pas cumulatifs** : chaque niveau ne liste que ses nouvelles
  compétences. « Responsable d'équipe » (boulangerie) affiche 4 compétences, alors qu'il en faut
  47 avec les niveaux d'avant. L'écran actuel montre le 4 : c'est faux pour qui le lit.
- **Aucune des 107 compétences n'a de procédure ni de ressource** reliée : ces colonnes sont
  toujours vides et ne font que du bruit.
- Des catégories « 1 », « 2 », « 3 » dans le panel : regroupées sous « À classer ».

## Les cinq écrans

| Fichier | Écran | L'idée |
|---|---|---|
| `01a-carte-metro.png` | Accueil, variante A (gardée) | Plan de métro. Les trois lignes de métier (Vente, Boulangerie – Pâtisserie, Traiteur) ne se remplacent pas : elles entrent **toutes** dans « Pour encadrer : maîtriser les trois métiers » (79 compétences, niveau de maîtrise de chaque ligne marqué ◎), puis le tronc Adjoint → Manager → Candidat → Franchisé → Multi-boutique ; Locataire-gérant en embranchement. |
| `01c-carte-effectifs.png` | Accueil, variante C (gardée) | Les personnes d'abord (72 / 27 placées / 45 sans poste), le **vivier de gérants** (combien maîtrisent 3, 2, 1, 0 métier — chiffres d'exemple : le panel n'a de compétences validées que pour 1 personne sur 72), puis chaque poste et ses niveaux ; Adjoint et Manager portent la condition « maîtriser les 3 métiers ». |
| `02-fiche-niveau.png` | Un niveau | L'escalier du poste en haut ; à gauche ce qu'il faut savoir faire (nouveau / acquis avant / tout), en case à cocher, avec la FO qui l'enseigne ; à droite les formations dans l'ordre, l'accès, la suite. |
| `03-matrice-competences.png` | Compétences | Une matrice par poste : compétences × niveaux, un carré plein là où elle entre, clair ensuite ; la formation au bout. Remplace le tableau à étiquettes. |
| `04-matrice-formations.png` | Formations | « Qui doit suivre quoi » : formations × postes par famille, O plein / R contour — la matrice de l'Excel. |
| `05-equipe.png` | Équipes | Une ligne par personne : compétences tenues sur le total de SON niveau, ce qui manque, prochaine étape. |

Régénérer : relire les réponses du cockpit dans un dossier (ref.json, liens.json, v25.json, et
effectifs.json = personnes actives par niveau, sans nom), puis

    python3 -I docs/maquettes/formation-donnees/generer.py <dossier>
    node docs/maquettes/formation-donnees/captures.js
