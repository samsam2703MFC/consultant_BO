# Maquettes — montrer les données du module Formation (31/10/2026)

Proposition, à valider avant développement. Vraies données du panel (lues le 31/10/2026) ;
seules les personnes de `05-equipe` sont fictives. Les effectifs des cartes (01a-c) sont de
vrais comptes par niveau (aucun nom). Écrans 02 à 05 validés le 31/10/2026 ; la carte des
métiers est refaite en trois variantes, au choix.

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
| `01a-carte-metro.png` | Accueil, variante A | Plan de métro : trois lignes de métier (Vente, Boulangerie – Pâtisserie, Traiteur) qui rejoignent le tronc commun (Adjoint → Manager → Candidat → Franchisé → Multi-boutique) ; Locataire-gérant en embranchement. Chaque station : compétences cumulées, personnes à ce niveau. |
| `01b-carte-grille.png` | Accueil, variante B | Grille en escalier : une ligne par voie, une colonne par étape, une case par niveau (compétences cumulées, personnes, jauge « a une formation »). |
| `01c-carte-effectifs.png` | Accueil, variante C | Les personnes d'abord : 72 actives, 27 placées, 45 sans poste dans le panel ; chaque poste, ses niveaux et le nombre de personnes à chacun. |
| `02-fiche-niveau.png` | Un niveau | L'escalier du poste en haut ; à gauche ce qu'il faut savoir faire (nouveau / acquis avant / tout), en case à cocher, avec la FO qui l'enseigne ; à droite les formations dans l'ordre, l'accès, la suite. |
| `03-matrice-competences.png` | Compétences | Une matrice par poste : compétences × niveaux, un carré plein là où elle entre, clair ensuite ; la formation au bout. Remplace le tableau à étiquettes. |
| `04-matrice-formations.png` | Formations | « Qui doit suivre quoi » : formations × postes par famille, O plein / R contour — la matrice de l'Excel. |
| `05-equipe.png` | Équipes | Une ligne par personne : compétences tenues sur le total de SON niveau, ce qui manque, prochaine étape. |

Régénérer : relire les réponses du cockpit dans un dossier (ref.json, liens.json, v25.json, et
effectifs.json = personnes actives par niveau, sans nom), puis

    python3 -I docs/maquettes/formation-donnees/generer.py <dossier>
    node docs/maquettes/formation-donnees/captures.js
