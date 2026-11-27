# Mon équipe : absences, congés et remplacements (maquette, 27/11/2026)

Demande de Sam : « dans la gestion du planning : lorsqu'une personne est engagée sous le régime d'un contrat de travail,
lorsqu'elle est absente ou en maladie, les frais doivent rester et on doit pouvoir, aux mêmes heures, rajouter une tierce
personne avec les frais supplémentaires que ça apporte. Et congé payé. Faire une maquette screenshots. » (« en makasin »
est lu « en maladie ».)

## Captures

| Fichier | Ce que c'est |
|---|---|
| `1-semaine-absences.png` | la semaine (Mon équipe › Planning › Semaine) : Camille malade du mercredi au vendredi (certificat), Inès la remplace mercredi, vendredi est encore à remplacer ; Fanny en congé payé jeudi, Lucie la remplace. Les services absents sont hachurés (le motif, qui remplace ou « À remplacer ») et gardent leur coût ; les remplaçants ont un liseré vert. Le résumé dit la part des absences (309 €, 2,1 pts) et le budget sans elles (16,4 %) ; chaque jour, « dont x pts d'absence » ; « À corriger » : « 15,9 % sans le congé », « Camille malade : comptoir vide de 13 h 45 à 14 h 45 · à remplacer » |
| `2-absente-et-remplacer.png` | un clic sur le service du vendredi de Camille : « Absente », le motif (chacun avec ce qu'il fait du coût), la période et ses jours, puis **remplacer aux mêmes heures** : six possibilités, chacune avec ce qu'elle ajoute (ne pas remplacer, étendre Inès déjà là, Lucie, Emma, David, quelqu'un d'extérieur au taux de l'agence) ; qui est là ce jour, le comptoir comblé ; l'effet : budget du vendredi 20,9 % → 25,1 %, de la semaine 18,5 % → 19,1 %, l'absence de Camille 373 € (201 € payés à Camille + 172 € de remplacements) |
| `3-conge-paye.png` | « Absence ou congé » (la barre du planning, ou la fiche de la personne) : Fanny en congé payé du 23 au 29 novembre ; chacun de ses jours de travail devient « Congé payé » (ses heures restent payées), un remplaçant est proposé pour chacun (le moins cher des libres au comptoir, modifiable) avec son coût et l'effet sur le budget du jour ; le total : 227,90 € qui restent, +180 € de remplacements, semaine 16,6 % → 17,8 % |

## Ce que propose la maquette

- **Une absence ne retire plus le coût.** Aujourd'hui, « Retirer de l'horaire de la semaine » supprime les services :
  le budget d'heures baisse alors que la personne est payée, et le franchisé croit avoir de la marge. Dans la maquette,
  le service devient absent : ses heures restent payées et restent au budget ; il sort de la présence (le comptoir, les
  linéaires, « À corriger »), si bien que le trou qu'il laisse se voit.
- **Le motif décide du coût.** Maladie (salaire garanti), Congé payé, Congé de circonstance : le coût reste. Absence non
  payée (sans solde, injustifiée) : le coût sort. Sans contrat de travail (intérim, B2B, contrat civil, stage, bénévole :
  le `employment_type` du panel, déjà dans la fiche), une absence ne coûte rien.
- **Remplacer aux mêmes heures**, chaque possibilité avec ce qu'elle ajoute avant de choisir (le motif des créneaux de la
  fenêtre d'un service, la proposition 2 bis) : le coût en plus, le budget du jour, le comptoir tenu ou non, ses heures
  de la semaine. L'équipe libre ces heures-là d'abord, la moins chère en premier ; « Étendre » quelqu'un déjà là quand
  ça suffit à combler le trou ; quelqu'un d'extérieur (intérim, extra) au taux saisi (la facture de l'agence).
- **Le budget reste honnête et dit pourquoi.** Le pourcentage compte l'absente et son remplaçant (c'est ce que le
  magasin paie) ; à côté : la part des absences (« dont 4,5 pts d'absence ») et le budget sans elles. Un jour au-dessus
  de la cible à cause d'un congé ne se « corrige » pas en retirant des heures : « À corriger » le dit (« 15,9 % sans le
  congé »).
- **Le congé payé sur une période**, posé d'avance : la personne, le motif, du … au … ; ses jours de repos ne changent
  rien ; chacun de ses jours de travail devient congé payé, avec son remplaçant.

## Les chiffres de la maquette (Magasin démo, semaine du 16 novembre)

| | Heures | Coût |
|---|---|---|
| Camille Roussel (15,80 €/h), malade mer. 18 (12:45–19:15) et ven. 20 (13:00–19:15) | 12,75 h payées | 201,45 € qui restent |
| Fanny Leroy (17,20 €/h), congé payé jeu. 19 (06:45–13:00) | 6,25 h payées | 107,50 € qui restent |
| Inès remplace Camille mercredi ; Lucie remplace Fanny jeudi | 12,75 h | +171,85 € |
| La semaine : 2 747 € de personnel pour 14 877 € de CA | 176,5 h payées, 157,5 h au magasin | **18,5 %** (16,4 % sans les absences) |
| Vendredi, Lucie remplace Camille (13:00–19:15) | +6,25 h | +91,25 € : 20,9 % → 25,1 % ; semaine 19,1 % |
| Congé de Fanny du 23 au 29 : jeu. 26 (6,25 h) et ven. 27 (7 h) | 13,25 h payées | 227,90 € ; Inès +78 €, Lucie +102 € ; semaine 16,6 % → 17,8 % |

## Comment elles sont faites

`capturer.cjs` : le vrai dashboard (site assemblé, servi en local) avec la fausse API des vidéos
(`panel/tools/videos/mock.js` : Magasin démo, horloge figée au jeudi 19/11/2026 à 15 h 40). Les remplaçants sont de vrais
services créés par la fausse API (le vrai code compte leur coût) ; le service de l'absente reste au planning (le vrai code
compte son coût) ; la maquette (`absences.js`, `absences.css`) le montre absent, recalcule la présence, les linéaires et
la part des absences depuis `/dashboard/equipe/planning`, et dessine les deux fenêtres. **Tout est fictif**, aucune
écriture hors de la fausse API.

    node panel/docs/maquettes/equipe-gestion/absences-remplacements/capturer.cjs [dossier de sortie]

## L'intégration prévue (après validation)

- **Le panel TF Buddy ne connaît pas les absences** (swagger du 27/11/2026, 1 308 chemins : aucune route d'absence, de
  congé ni de maladie ; `POST /schedule` ne garde que `id_employee`, `start_hour`, `end_hour`, `work_date`,
  `schedule_uuid` ; l'employé n'a pas d'heures de contrat). Elles vivraient au cockpit : une table `panel_equipe_absence`
  (magasin, employé, jour, de, à, motif, payée, certificat, service remplaçant, qui l'a posée), créée comme
  `panel_equipe_matrice` dans `panel/src/equipe.php`.
- **Le service de l'absente** (prévu ainsi, changé à la construction : voir « Construit et mis en ligne ») : retiré du panel
  (`DELETE /schedule/{id}`) et gardé au cockpit avec ses heures et son motif. Le remplaçant : un vrai service au panel
  (`POST /schedule`), lié à l'absence.
- **Le calcul** (`calculJour`, `equipe.js`) : les absences payées comptent dans le coût, pas dans la présence ; le résumé,
  la tête des jours et « À corriger » comme la maquette ; le jour, le mois et le planning imprimé montrent « Malade »,
  « Congé payé » dans la case.
- **« Retirer de l'horaire de la semaine »** devient « Absente toute la semaine » dans la même fenêtre (le coût reste).
- **Dans le module principal**, pas en option : c'est faire ses horaires (le but premier de Mon équipe).

## Construit et mis en ligne (27/11/2026)

Sam : « go construit et mets en ligne ». `panel/src/equipe_absences.php` (la table `panel_equipe_absence`, les routes
`POST|DELETE /dashboard/equipe/absence`), `panel/public/dashboard/equipe.js` et `equipe.css` (la semaine, le jour, le mois, la fenêtre
d'un service, la fenêtre « Absence ou congé »), le planning imprimé (`eqpPlanningPdfHtml`). Tests : `php panel/bin/tests_equipe.php`
(25 cas des absences) ; l'écran, joué de bout en bout et vérifié : `node …/absences-remplacements/ecran/capturer.cjs` (22 vérifications).

**Une décision, prise en construisant** : le coût du personnel du Résultat vient du panel TF Buddy, qui le calcule sur son propre
planning. Supprimer le service d'une personne absente l'aurait donc fait disparaître du Résultat. Une absence payée **garde son
service au panel** (son coût reste partout) et n'est notée qu'au cockpit ; seule l'absence non payée retire le service du panel.

**Ce qui diffère de la maquette** : une seule fenêtre pour une absence d'un jour et pour une période (le tableau jour par jour de la
maquette 3, avec « Remplacer par » dans chaque jour, chaque nom avec son coût, ceux du même rôle d'abord) ; « Étendre » quelqu'un déjà
là n'est pas construit (on étire son service dans la fenêtre d'un service, comme avant) ; le solde des congés n'est pas montré.

| Capture | L'écran construit (Magasin démo, données fictives) |
|---|---|
| `ecran-1-absente-et-remplacer.png` | « Absente ou en congé » depuis le service de Camille : maladie du mercredi au vendredi, certificat ; Inès proposée mercredi (+81 €) ; l'effet |
| `ecran-2-conge-paye.png` | « Absence ou congé » (la barre) : Fanny en congé payé jeudi, Lucie la remplace |
| `ecran-3-remplacer-par-un-exterieur.png` | l'absence posée se rouvre : mercredi déjà remplacé, vendredi un intérimaire à 24,50 €/h (+153 €) |
| `ecran-4-semaine.png` | la semaine : les absences hachurées, les remplaçants, la ligne des extérieurs, la part des absences (309 €) et le budget sans elles |
| `ecran-5-jour.png` | le vendredi : Camille hachurée, l'intérim parmi les extérieurs, le comptoir tenu |
| `ecran-6-mois.png` | le mois : les absences en lettres (M, CP) |

**Pas encore fait** : l'app employés (`/employee`) montre encore le service d'une personne absente comme un service ; l'Opérationnel
(qui est en poste) et la condition d'heures des primes lisent le planning du panel, où le service d'une absence payée reste ; les
vidéos de Mon équipe (la barre a un bouton de plus) sont à retourner.

## À vérifier ou à décider

1. **Ce que coûte vraiment une absence payée.** La maquette garde le coût du planning (heures × taux horaire du panel)
   pour toute absence payée. La part réellement à charge du magasin dépend du statut (employé, ouvrier), de la durée (le
   salaire garanti couvre les premières semaines de maladie, ensuite la mutuelle paie) et, pour les congés des ouvriers,
   de la caisse de vacances : règles à reprendre avec le secrétariat social avant de coder.
2. **Un jour de congé sans service prévu** (la semaine n'est pas encore faite) : combien d'heures payer ? Le panel ne
   donne pas d'heures de contrat ; il faudrait les saisir dans la fiche (heures par semaine, jours du régime).
3. **Le solde des congés payés** : ni le panel ni le cockpit ne le connaissent ; « 8 j posés au planning » est
   illustratif. Le solde légal viendrait du secrétariat social.
4. **Le taux de l'intérim** (24,50 €/h ici) : saisi à chaque fois, ou un réglage du magasin.
5. **Les droits** : le compte du cockpit doit pouvoir `DELETE` et `POST /schedule` au panel (déjà à vérifier pour Mon
   équipe).
