# Plan de fusion : Manuel opératoire, SOP, guides formations et pré-ouverture

06/11/2026. Ce document va avec les maquettes du dossier (`p1-phases`, `p2-domaines`, `p3-postes`, `plan`). Rien n'est
encore codé : c'est une proposition à valider.

## 1. Ce qui existe aujourd'hui

Trois endroits décrivent le même métier, chacun avec son propre classement.

| Où | Ce qu'on y trouve | Classement |
|---|---|---|
| **Cockpit › Formation** (`consultant_BO`, `/formation/`) | 25 SOP en FR et en NL (780 étapes, versions, quiz), tablette du poste (PIN de l'employé : lu, quiz, vu faire → compétence cochée dans le panel), Manuel en ligne (procédures du panel), référentiel v25 (`src/data/referentiel_v25.json`) | 4 parties (pré-ouverture, ouverture, opérationnel, formation) ; v25 : 8 domaines D1–D8 |
| **CRM › Exploitation et Suivi candidat franchisé** (`atelier-espace-candidat`) | Manuel opératoire (`crm_mo_*` : 13 chapitres, routines AC-01…03 en brouillon, éditeur, tablette avec le PIN du magasin) ; Guides (pré-ouverture : 5 sections, 38 points ; formations et compétences : 11 blocs, 45 compétences, 5 pôles, « Réussi si… » ; examen) ; app des formateurs, attestation ; documents du DIP (Guide pré-ouverture, Guide des formations) | 13 chapitres tirés de D1–D8 ; 11 blocs par formateur ; 5 sections de pré-ouverture |
| **Panel TF Buddy** (ERP tiers, lu par API) | 107 compétences et l'état de chaque employé, procédures opérationnelles, postes | ses catégories |

Doublons constatés :

- **3 manuels** : le Manuel en ligne du Cockpit, le Manuel opératoire du CRM, les procédures du panel.
- **2 éditeurs de procédures** : la rédaction des SOP (Cockpit) et l'éditeur du Manuel opératoire (CRM).
- **3 listes de compétences** : 107 dans le panel, 56 codes `C-…` dans le v25, 45 points dans les blocs du CRM (qui
  reprennent déjà les codes `C-…` et `FO-…` du v25).
- **2 tablettes** : celle du Manuel du CRM (PIN du magasin) et celle des SOP du Cockpit (PIN de l'employé).
- **Un même standard écrit deux fois** : « Ouverture du magasin » = SOP-WKL-001 (Cockpit, validée) et AC-01 (CRM,
  brouillon). Même chose pour la fermeture (SOP-WKL-003, CHK-WKL-004 et AC-02, AC-03).
- **Un standard local** : « Moments calmes à l'Atelier Halle » (CHK-WKL-005).

## 2. Les trois propositions de regroupement

1. **Par phase** (`p1-phases.png`) : le manuel se lit dans le temps, de J-120 à la formation continue. Chaque phase
   montre ce qu'on fait (points du guide), ce qu'on apprend (blocs, FO, compétences bloquantes) et ce qu'on applique
   (standards), puis sa porte (journée test et examen → attestation). Bon pour le candidat franchisé ; peu lisible
   pour l'équipe après l'ouverture.
2. **Par domaine** (`p2-domaines.png`) : le classeur du référentiel v25, 8 domaines et 36 sous-catégories. Dans chaque
   sous-catégorie la même chaîne : standard → formation → compétence → checklist de contrôle. Bon pour le franchiseur
   et le consultant ; les trous et les doublons se voient.
3. **Par poste** (`p3-postes.png`) : les 12 postes du v25 regroupés, croisés avec les phases ; le parcours d'une
   personne (lire → quiz → vu faire → compétence acquise). Bon pour l'équipe en magasin et la tablette.

**Recommandation** : la proposition 2 comme **structure unique** (c'est celle du référentiel v25, que le client a déjà
établi), et les propositions 1 et 3 comme **deux lectures calculées** de la même base. Rien ne se range deux fois.

## 3. La structure cible

**Un référentiel**, dans la base commune (le CRM et le Cockpit partagent déjà `atelierby_db`) :

- colonne vertébrale : les 8 domaines D1–D8 et leurs 36 sous-catégories du v25 ;
- la **phase** (pré-ouverture, ouverture, opérationnel, continu) et le **poste** (P1–P12) sont des étiquettes ;
- six objets, un code chacun :

| Objet | Code | Rôle | Vient de |
|---|---|---|---|
| Point à faire | `PO-…` (nouveau) | démarche, preuve, J-, qui | Guide pré-ouverture (CRM), countdown |
| Standard | `SOP-…`, `CHK-…`, `FICHE-…` | comment faire : étapes, photos, points critiques, quiz | 25 SOP (Cockpit), procédures du CRM et du panel |
| Checklist | `AC-…`, `CP-…`, `CL-…`, `CS-…` | contrôle du standard | référentiel v25, annexe 18 du DIP |
| Formation | `FO-01`…`FO-53` | qui forme, quand, durée | référentiel v25, blocs du CRM |
| Compétence | `C-…` (56) | « réussi si… », bloquante, 5 pôles, renouvellement | v25 ; critères et réglages du CRM |
| Plan | `PF-ETU`, `PF-VEND`, `PF-GER`, `PF-MULTI` | parcours par profil | référentiel v25 |

**Un éditeur** : Cockpit › Formation devient le « Manuel du réseau ». Le franchiseur y écrit, traduit, relit et
publie (brouillon → publié, FR + NL), avec les vues par domaine, par phase et par poste.

**Trois lectures** :

- **Candidat franchisé (CRM)** : Guide pré-ouverture, Guide des formations et compétences, annexes 9, 17 et 18 du DIP,
  app des formateurs, attestation. Le CRM lit le référentiel par l'API du Cockpit (comme il lit déjà
  `/formation/v25` et `/formation/procedure`) et garde ce qui est propre au dossier : dates, validations des
  formateurs, signatures, attestation.
- **Magasin (une seule tablette)** : « Ma journée » par poste et moment (vient du CRM) et le pas à pas des standards
  avec lu, quiz et vu faire (vient du Cockpit). Le PIN du magasin ouvre l'appareil, le PIN de l'employé signe. L'app
  Cuisine (`pwa_kitchen`, entrée « Standards ») y mène.
- **Panel TF Buddy** : reçoit la compétence acquise au « vu faire » et reste la source des employés et des postes.

## 4. Six lots

Chaque lot se met en ligne seul ; rien n'est retiré avant que son remplaçant tourne.

1. **Décider et geler** : valider la structure v25, l'éditeur unique et les codes. Plus de nouvelle procédure dans
   le Manuel opératoire du CRM en attendant. Rien de visible.
2. **Table de correspondance** : `C-…` du v25 ↔ 107 compétences du panel ↔ 45 points des blocs du CRM ; chaque SOP ↔
   sa sous-catégorie, ses FO, sa checklist ; chaque point du guide ↔ son domaine. La table est proposée
   automatiquement ; seules les correspondances certaines sont écrites, le reste est listé pour arbitrage (même règle
   que « Rattacher les fiches à leur emplacement » dans le CRM).
3. **Référentiel commun** : tables et API de lecture dans la base commune ; reprise des 25 SOP, du v25, des blocs et
   de leurs « réussi si… », des 38 points du guide, des routines du CRM (AC-01…03 deviennent les checklists de
   contrôle des SOP d'ouverture et de fermeture). Visible : le classeur dans le Cockpit.
4. **Un seul éditeur** : le Cockpit gagne les vues domaine, phase et poste ; dans le CRM, le Manuel opératoire passe
   en lecture avec « Modifier dans le Cockpit ↗ », et les Guides lisent le référentiel (sections et points de
   pré-ouverture, blocs, critères). Visible : les deux écrans disent la même chose.
5. **Une seule tablette** : fusion des deux apps (PIN du magasin + PIN de l'employé) ; l'app Cuisine et les anciens
   liens redirigés. Visible : une app par magasin.
6. **Documents et contrôles** : Guide pré-ouverture, Guide des formations et compétences et annexes du DIP générés
   depuis le référentiel ; la grille des contrôles (annexe 18) devient la liste des checklists des standards ;
   tableau de suivi par magasin ; retrait des doublons (Manuel en ligne du panel, éditeur du CRM, seconde tablette).

## 5. Règles de la structure uniforme

- Un objet = un code = une place (sa sous-catégorie). On relie, on ne recopie jamais.
- Toujours FR et NL ; une traduction est relue avant publication.
- Une version publiée par objet, la même pour tous les magasins. Pas de standard local : « Moments calmes à
  l'Atelier Halle » devient un standard du réseau.
- Phase et poste sont des étiquettes : les vues se calculent.
- Chaque compétence dit « réussi si… » et renvoie à au moins un standard et une formation ; chaque standard a sa
  checklist de contrôle. Ce qui manque se voit (compétence sans standard, standard sans compétence).

## 6. Trois décisions à prendre

1. **Colonne vertébrale** : les 8 domaines du v25 (recommandé) ou un autre classement.
2. **Éditeur unique** : Cockpit › Formation (recommandé : outil de la marque pour tout le réseau, déjà les 25 SOP en
   FR + NL, les versions et le lien au panel) ou CRM › Manuel opératoire (déjà un éditeur, des photos, « Ma journée »).
3. **Clé des compétences** : le code `C-…` du v25, le panel gardant l'état par employé (recommandé), ou les 107
   compétences du panel comme liste maîtresse.

## Sources des chiffres

- 25 SOP : `data/formation/sops/*.json` (codes, postes, moments).
- Référentiel v25 : `src/data/referentiel_v25.json` (8 domaines, 36 sous-catégories, 53 FO, 56 compétences, 4 plans,
  checklists AC / CP / CL / CS).
- CRM : `app/crm/api/lib/manuel.php` (13 chapitres, AC-01…03), `lib/formation_blocs.php` (11 blocs, 45 points),
  `lib/guides.php` (`GD_SECTIONS_DEFAUT` : 38 points).
- Les avancements de magasins et l'équipe de Halle des maquettes sont fictifs. Les nombres de la vue « par poste »
  sont indicatifs.
