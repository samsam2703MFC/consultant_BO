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
- **Le serveur**, domaine consultant (`consultant/src/coffre.php`). Il **rassemble** les sources sans les recopier
  (les dépôts de `con_doc`, les rapports de visite envoyés) :
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

- Le coffre suit le réglage d'accès du cockpit (décision 2). Avec la connexion, un magasin ne lit que son coffre : le
  `shop` de chaque route est vérifié contre la session, et un fichier n'est rendu que s'il appartient à ce `shop`.
- Un document déposé par le consultant n'entre au coffre que si « dans le coffre du franchisé » est coché (coché par
  défaut). Les documents déposés avant le coffre n'y entrent pas tant que le consultant ne les y met pas.
- Les fichiers ne sont jamais servis par une adresse directe (`.htaccess` fermé, lecture par l'API).

## Décisions (Sam, 22/11/2026)

1. **DIP, contrat, annexes, avenants, attestation de formation** : **déposés par le consultant** dans le coffre (pas lus
   au CRM).
2. **Pas de connexion imposée** : le coffre suit le réglage d'accès du cockpit, comme le reste du dashboard. En accès
   ouvert, qui connaît l'adresse d'un magasin peut lire son coffre ; avec la connexion, seul ce magasin le lit.
3. **Le franchisé dépose lui-même** ses pièces légales (onglet Légal), **le consultant aussi**.
4. **Un coffre par boutique.**
5. **Pas d'« Envoyer à mon comptable »**, mais dans les mails : un document du coffre se joint à un e-mail de la Boîte
   mail du magasin.

## Où on en est (22/11/2026) — codé sur la branche, pas encore en ligne

| Pièce | Fichiers |
|---|---|
| Serveur : les routes du coffre, les ouvertures notées, la pièce jointe des mails | `consultant/src/coffre.php`, `consultant/src/documents.php` (sortes, `echeance`, `visible_franchise`, `depose_par`, `depose_role`, `PATCH /franchises/documents`), `shared/src/acces.php` (`/coffre/magasin`), `panel/src/boite_mail.php` (`coffre` de l'envoi) |
| Dashboard : la page à onglets, le lien tout en bas | `panel/public/dashboard/coffre.js`, `coffre.css`, `dashboard.js` (vue `coffre`, `railCoffre`, `coffreBasMaj`), `boite.js` (« Joindre du coffre-fort… ») |
| Cockpit : le dépôt du consultant | `consultant/public/assets/js/franchises-vals.js`, `franchises-tpl.js`, `shared/public/assets/css/app.css` |
| Tests | `php consultant/bin/tests_coffre.php` (28 points), `tests_documents.php` (suit les 11 sortes) |
| Contrat | `shared/docs/contrat-api.md`, § « Le coffre-fort du franchisé » |

Essayé dans le vrai dashboard (site assemblé, API simulée) : bureau (onglet Tout, onglet Légal avec le dépôt), téléphone
(le lien en bas, au-dessus des onglets fixes). Non essayé en vrai : l'aperçu d'un PDF (Chromium sans tête n'affiche pas
les PDF), l'envoi d'un mail avec une pièce du coffre (il faut une boîte reliée).

## Les grandes dates et l'assistant (22/11/2026, demande de Sam)

« Rajouter dans le coffre-fort un bouton avec les grandes dates (triennats des contrats de bail et autres) ; faire un
assistant lorsque j'importe un document. »

- Le bouton **📅 Grandes dates** de la barre du coffre (le nombre de dates dans les 4 mois, la prochaine) ouvre leur
  frise : par année, la prochaine en tête, les passées repliées, le document d'origine en lien, l'ajout à la main.
- À chaque dépôt (le magasin dans Légal, le consultant dans l'onglet Documents), **l'assistant** lit le premier document
  lisible (PDF, photo, Word) et propose : le genre, un résumé, ses grandes dates avec l'extrait qui les justifie (cochées
  si elles sont à venir, l'urgence dite, les passées repliées), l'échéance et un titre lisible du document. Rien ne se
  range sans « Ranger ». « ✦ Relever les grandes dates » (aperçu du dashboard) et « ✦ Dates » (cockpit) le relancent.
- Bail commercial : le modèle relève la prise de cours, la durée et le préavis ; les **triennats, le dernier jour du
  congé, la fenêtre du renouvellement et la fin sont calculés** au serveur (règle générale des baux commerciaux, à
  vérifier dans le bail : la note de chaque date le dit).
- Fichiers : `consultant/src/coffre_dates.php`, `shared/src/anthropic.php` (`documentJson`), `coffre.js` / `coffre.css`,
  `franchises-vals.js` / `franchises-tpl.js` / `app.css`. Tests : `php consultant/bin/tests_coffre_dates.php`.
- Captures (vrai dashboard et vrai cockpit, réponses produites par les fonctions PHP, le modèle simulé) :
  `code-grandes-dates-bouton.png`, `code-grandes-dates-assistant.png`, `code-grandes-dates-frise.png`,
  `code-grandes-dates-telephone.png`, `code-grandes-dates-cockpit.png`.
- Non essayé en vrai : une lecture par le modèle (il faut la clé et un vrai bail).

À la mise en ligne, rien n'entre au coffre tout seul : les documents déjà déposés y restent hors (`visible_franchise` =
0), le consultant les y met d'un clic (colonne Coffre de l'onglet Documents).
