# Maquettes — montrer les données du module Formation (31/10/2026)

Proposition, à valider avant développement. Vraies données du panel (lues le 31/10/2026) ;
seules les personnes de `05-equipe` sont fictives.

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
| `01-carte-des-metiers.png` | Accueil | Le chemin de gauche à droite (Opérationnel → Cadre et direction → Devenir entrepreneur) ; chaque marche : +N nouvelles, total à maîtriser, jauge « a une formation ». En bas, trois manques. |
| `02-fiche-niveau.png` | Un niveau | L'escalier du poste en haut ; à gauche ce qu'il faut savoir faire (nouveau / acquis avant / tout), en case à cocher, avec la FO qui l'enseigne ; à droite les formations dans l'ordre, l'accès, la suite. |
| `03-matrice-competences.png` | Compétences | Une matrice par poste : compétences × niveaux, un carré plein là où elle entre, clair ensuite ; la formation au bout. Remplace le tableau à étiquettes. |
| `04-matrice-formations.png` | Formations | « Qui doit suivre quoi » : formations × postes par famille, O plein / R contour — la matrice de l'Excel. |
| `05-equipe.png` | Équipes | Une ligne par personne : compétences tenues sur le total de SON niveau, ce qui manque, prochaine étape. |

Régénérer : relire les trois réponses du cockpit dans un dossier, puis

    python3 -I docs/maquettes/formation-donnees/generer.py <dossier>
    node docs/maquettes/formation-donnees/captures.js
