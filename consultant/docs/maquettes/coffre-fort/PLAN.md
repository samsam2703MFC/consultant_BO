# Coffre-fort du franchisé — plan et maquettes (22/11/2026)

Demande : chaque franchisé a un coffre-fort avec ses documents : DIP, contrat, légal, formation (initiale, continue),
rapports de visite… Le tout sur **une seule page, un tableau à onglets**, avec un **lien direct dans son dashboard,
tout en bas**.

**Rien n'est codé.** Les maquettes sont des pages HTML sur le design system, avec leurs captures PNG. Pour refaire les
captures : `php -S 127.0.0.1:8099 -t .` à la racine, puis `node consultant/docs/maquettes/coffre-fort/capturer.cjs`.

| Écran | Fichier |
|---|---|
| Le lien : tout en bas du rail (bureau), tout en bas de chaque onglet (téléphone) | `01-dashboard-lien-coffre.png` |
| Onglet Tout : les nouveaux d'abord, l'aperçu, qui a ouvert | `02-coffre-tout.png` |
| Onglet Contrat & DIP | `03-coffre-contrat.png` |
| Onglet Légal (avec « à renouveler ») | `04-coffre-legal.png` |
| Onglet Formation : initiale, continue, certificats | `05-coffre-formation.png` |
| Onglet Rapports de visite | `06-coffre-visites.png` |

## Ce qui existe

| Onglet | Source existante | Où |
|---|---|---|
| Contrat & DIP | DIP, contrat de franchise, annexes, dossier d'implantation : au **CRM** (portail Recrutement & Développement), qui reste leur source | lu par sa route d'intégration (jeton de service), comme le compte de résultat (`budget_crm.php`) ; **à vérifier** : qu'elle rende ces fichiers |
| Contrat & DIP, Courriers & réunions | `con_doc` : rapports de réunion, contrats de mise à disposition, courriers. Fichiers privés (`uploads/documents/{magasin}/`, `.htaccess` fermé), servis par l'API seulement | `consultant/src/documents.php`, onglet Documents de la fiche franchisé, au siège seulement |
| Rapports de visite | `con_visite` : rapport, PDF, envoyé le, lu le | `consultant/src/suivi.php` (`ep_suivi_franchise`) |
| Finances | le dossier d'analyse mensuel ou trimestriel (PDF), le budget validé | `consultant/src/dossier.php` |
| Formation | l'attestation et le bilan d'ouverture (CRM) ; le document de formation et les archives de validation | plan « Formation continue » (`formation/docs/maquettes/formation-continue/`) |
| Légal | rien : statuts, BCE, assurance, AFSCA, bail… ne sont gardés nulle part | à créer |

## Ce que je propose

- **Une page**, `?vue=coffre` du dashboard (`panel/`).
  - Sept onglets : Tout · Contrat & DIP · Légal · Formation · Rapports de visite · Finances · Courriers & réunions.
  - Un tableau, avec des sous-filtres dans Formation et dans Rapports de visite.
  - L'aperçu du document choisi, avec « qui l'a ouvert ».
  - En tête : le nombre de documents, les nouveaux, ceux à renouveler, l'échéance du contrat.
- **Le lien** « 🔒 Mon coffre-fort » est épinglé tout en bas du rail, sur tous les onglets. Au téléphone, il est le
  dernier bloc de chaque onglet. Son chiffre compte les documents pas encore ouverts.
- **Le serveur**, domaine consultant (`consultant/src/coffre.php`). Il **rassemble** les sources sans les recopier,
  sauf ce qui n'a pas de source :
  - `GET /coffre/magasin?shop=` : la liste, par onglet ;
  - `GET /coffre/magasin/fichier?id=` : le fichier, après contrôle du magasin, et l'ouverture est notée.
  - `con_doc` reçoit de nouvelles sortes : `dip`, `contrat_franchise`, `avenant`, `legal`, `finances`. Deux colonnes
    s'ajoutent : `echeance`, qui fait passer une pièce « à renouveler » 60 jours avant, et `visible_franchise`.
  - Une table `con_coffre_acces` (document, qui, quand, d'où) pour « qui l'a ouvert ».
  - Le préfixe `/coffre/magasin` s'ajoute à `ACCES_FRANCHISE`.
- **Côté cockpit** : l'onglet Documents de la fiche franchisé montre le même tableau. Le siège et le consultant y
  déposent, et voient ce que le franchisé a ouvert.
- **Formation continue** : à la validation du consultant, le document de formation et l'archive (les 14 photos) sont
  déposés dans l'onglet Formation (écran 08 du plan Formation continue).

## Droits

- Ce sont des pièces légales. Le coffre **exige une session identifiée, même en accès ouvert** : la session du
  magasin pour le franchisé, celle du siège ou du consultant au cockpit.
- Un franchisé ne voit que son magasin. Un consultant ne voit que ses franchisés (cadre de visite).
- Les fichiers ne sont jamais servis par une adresse directe.

## Décisions à prendre

1. **DIP et contrat** : les lire au CRM, s'il rend les fichiers (recommandé, une seule source), ou les déposer une fois
   dans le coffre à la signature ?
2. **Session obligatoire** pour le coffre, même en accès ouvert ? Recommandé : oui.
3. **Le franchisé dépose-t-il lui-même** ses pièces légales (assurance, AFSCA, bail…) ? Proposé : oui, dans l'onglet
   Légal seulement.
4. **Un coffre par magasin ou par société** ? Pour un multi-boutique, proposé : un coffre par société, avec un filtre
   par magasin.
5. **« Envoyer à mon comptable »** : le garder ? Il faudrait l'adresse du comptable dans le profil du franchisé.
