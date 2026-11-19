# Règlement de travail : le modèle standard du réseau (v1.0, 19/11/2026)

Sam : « éditer un règlement de travail standardisé pour les futurs magasins : analyse, et regarde ce qu'il faudra rajouter
pour coller au plus à la franchise ».

Le modèle donne à chaque magasin le même règlement de travail : un tronc commun propre au réseau, et des champs à compléter
par chaque société franchisée.

| Fichier | Contenu |
|---|---|
| `Reglement-de-travail-modele-reseau-LAtelierBy-v1.0.docx` | Le modèle à remettre au franchisé (37 pages). |
| `Reglement-de-travail-modele-reseau-LAtelierBy-v1.0.pdf` | Son aperçu. |
| `modele.txt` | La source du texte, à modifier pour une nouvelle version. |
| `construire.py` | Régénère le Word à partir de `modele.txt` (python-docx). |

## Comment le franchisé s'en sert

- **Le surligné jaune** se complète, ou se choisit entre deux options : société, adresses, commissions paritaires,
  horaires, caméras, contacts.
- **Les encadrés gris** sont des notes pour lui, à retirer avant l'affichage.
- **La procédure légale** est rappelée en page 2 : affichage de 15 jours avec le registre des observations, signature,
  enregistrement dans les 8 jours (reglementdetravail.belgique.be), un exemplaire à chaque travailleur contre accusé de
  réception.

## Modifier le modèle

1. Changer `modele.txt`. Le balisage est décrit en tête de `construire.py`. Les chapitres et les articles sont numérotés
   dans l'ordre du texte, et les renvois `{{art:clé}}` et `{{ch:clé}}` suivent.
2. Régénérer le Word :
   ```bash
   python3 construire.py modele.txt Reglement-de-travail-modele-reseau-LAtelierBy-v1.0.docx
   ```
3. Régénérer l'aperçu PDF :
   ```bash
   soffice --headless --convert-to pdf Reglement-de-travail-modele-reseau-LAtelierBy-v1.0.docx
   ```
4. Changer le numéro de version dans le texte (page de titre et pied de page) et dans le nom des fichiers.

## D'où il vient

Le modèle part du règlement du magasin pilote (BERDIFF, modèle Go HR de juin 2026). Ce règlement **n'est pas versionné** :
il contient les coordonnées de personnes et les numéros de la société. Les passages légaux génériques en ont été repris tels
quels, nettoyés :
- l'incapacité de travail ;
- la fin du contrat ;
- les risques psychosociaux ;
- la déconnexion ;
- l'alcool et les drogues ;
- l'annexe RGPD ;
- la CCT n° 25.

### Corrigé par rapport au règlement BERDIFF

- **Commission paritaire des employés :** l'en-tête citait la CP 201 et le titre de l'article la CP 200. C'est devenu un
  champ à confirmer.
- **Numérotation et renvois :** la table des matières ne suivait pas le texte, et le chapitre sur le harcèlement renvoyait
  aux sanctions « au chapitre 20 ». Tout est renuméroté, et les renvois sont calculés.
- **Responsabilité du matériel :** la phrase confuse est remplacée par l'article 18 de la loi du 3 juillet 1978 (dol, faute
  lourde, faute légère habituelle).
- **Tenue :** « pas de mini-jupe » visait une partie du personnel seulement. Elle est remplacée par des règles d'hygiène
  qui valent pour tous.
- **Champs vides et exemples laissés dans le texte :** boîte de secours, caméras, contrôle du temps (« Exemple :
  visuellement par le manager »), contact RGPD.

### Ajouté pour la franchise

- **Les standards du réseau comme instructions de travail :** manuel opératoire, SOP, checklists, photos de contrôle prises
  sur place au moment de la tâche. Ils ne font pas partie du règlement et peuvent donc évoluer sans nouvel affichage.
- **Des articles nouveaux :** hygiène et sécurité alimentaire (allergènes, maladies transmissibles), tenue, téléphone et
  tablette (code PIN personnel, « lu », « vu faire »), caisse, invendus et consommation du personnel, savoir-faire du réseau,
  image de l'enseigne et réseaux sociaux, risques propres au magasin.
- **Les intervenants du réseau :** consultants, formateurs, contrôleurs qualité, clients mystères. Ils constatent, mais ne
  donnent ni ordres ni sanctions. Le franchisé est le seul employeur.
- **La formation au poste :** niveaux du référentiel, formations obligatoires (HACCP, allergènes), fiches et quiz sur la
  tablette, validation « vu faire ». Ce temps est du temps de travail.
- **Les horaires du réseau :** production dès 4:00, ouverture le dimanche et des jours fériés. S'y ajoutent les étudiants
  et flexi-jobs, les périodes de forte activité où les congés sont limités, et les groupes de messagerie du magasin dans le
  droit à la déconnexion.
- **Le RGPD :** les données des outils du magasin (tablette, checklists, caisse, planning, Dimona) et leur partage avec le
  franchiseur.
- **Annexes :** les horaires du magasin et l'avis de remplacement des jours fériés.

## À valider avant de le remettre aux magasins

- **Le secrétariat social :**
  - la commission paritaire des employés ;
  - le travail avant 6:00 en production (travail de nuit, loi du 16 mars 1971) ;
  - le repos compensatoire du dimanche ;
  - les flexi-jobs ;
  - les réformes de 2026 : certificat médical, rechute, politique d'absentéisme, trajet de réintégration ;
  - la transparence salariale (directive 2023/970) ;
  - les avantages en nature (consommation du personnel).
- **Un avocat ou un délégué à la protection des données :**
  - la clause « le franchiseur n'est pas l'employeur » ;
  - le rôle du franchiseur dans le traitement des données du personnel (sous-traitant ou responsable conjoint) ;
  - les durées de conservation.
- **Le SEPPT :** les risques propres au magasin et les équipements de protection.
