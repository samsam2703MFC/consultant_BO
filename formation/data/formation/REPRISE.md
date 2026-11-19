# Reprise des SOP du Drive — 05/11/2026

Les 25 SOP du dossier Drive « L’Atelier Halle — SOP — dernières versions retrouvées » ont été relues en entier et
reprises dans `data/formation/sops/<id>.json` : le texte d’origine, tel quel (fautes de frappe évidentes corrigées), et sa
traduction dans l’autre langue. Au premier appel de l’API, le cockpit les range dans ses tables (`ceo_sop`, `ceo_sop_version`) :
**nos bases deviennent la source**, le Drive l’archive. Les traductions sont marquées « à relire » jusqu’à ce que Sam les relise
(Formation › Rédaction et versions).

Décisions appliquées dans le texte (05/11/2026) : monnayeur automatique, on ne compte pas la caisse · recuisson du matin pour
la catégorie pain seulement · la SOP Flans v1.1 fait foi (Fiche programmes corrigée) · nettoyage des fours après 16 h ·
checklist de fermeture alignée sur la v1.3 · français de la Fermeture refait · Recuisson de l’après-midi : une SOP, en 01.

| SOP | Code | Version | Langue d’origine | Poste | Sections | Étapes | Photos |
|---|---|---|---|---|---|---|---|
| Ouverture du magasin et des comptoirs | SOP-WKL-001 | 5 | NL | vente | 10 | 39 | 8 |
| Encodage des commandes clients en caisse | SOP-WKL-002 | 1.0 | NL | vente | 6 | 31 |  |
| Gestion des paniers Too Good To Go | SOP-VER-TGTG | 1.0 | NL | vente | 5 | 21 | 4 |
| Recuisson d'appoint – pains et baguettes (après-midi) | SOP-VEN-001 | 1.0 | FR-NL | vente | 5 | 18 |  |
| Ouverture du poste et premières cuissons | SOP-CUI-OUV-01 | 1.1 | FR | cuisson | 5 | 27 |  |
| Plaquage et programmation des pousses | SOP-CUI-POU-01 | 1.0 | FR | cuisson | 5 | 17 |  |
| Viennoiseries et sandwiches | SOP-CUI-VIE-01 | 1.0 | FR | cuisson | 5 | 25 |  |
| Cuisson des baguettes et des pains | SOP-CUI-001 | 1.0 | FR | cuisson | 9 | 49 | 4 |
| Fin de poste de cuisson — terminer son service | SOP-CUI-FIN-01 | 1.0 | FR | cuisson | 8 | 26 |  |
| Crème fraîche et crème pâtissière | SOP-CUI-CRE-01 | 1.1 | FR | production | 8 | 23 |  |
| Tartes aux fruits | SOP-CUI-TFR-01 | 1.0 | FR | production | 7 | 26 |  |
| Flans et tartes au sucre | SOP-CUI-FLA-01 | 1.1 | FR | production | 8 | 23 |  |
| Tartes classiques et Brookie | SOP-CUI-TAR-01 | 1.0 | FR | production | 7 | 26 |  |
| Tartes froides | SOP-CUI-FRO-01 | 1.0 | FR | production | 6 | 20 |  |
| Éclairs et choux | SOP-CUI-ECL-01 | 1.0 | FR | production | 5 | 21 |  |
| Fondants et nappage | SOP-CUI-NAP-01 | 1.0 | FR | production | 5 | 27 |  |
| Cookies, cakes et financiers | SOP-CUI-BIS-01 | 1.0 | FR | production | 12 | 38 |  |
| Cramiques et craquelins | SOP-CUI-CRA-01 | 1.0 | FR | production | 4 | 13 |  |
| Quiches et pommes parisiennes | SOP-CUI-QUI-01 | 1.0 | FR | production | 5 | 19 |  |
| Procédure de fermeture – de la dernière préparation au coffre à clés | SOP-WKL-003 | 1.3 | NL | vente | 18 | 122 |  |
| Moments calmes à l'Atelier Halle – tâches à faire pendant les périodes calmes | CHK-WKL-005 | 2.0 | NL | vente | 7 | 38 |  |
| Nettoyage des fours | SOP-VER-002 | 1.1 | NL | nettoyage | 4 | 17 |  |
| Checklist de fermeture quotidienne | CHK-WKL-004 | 1.3 | NL | vente | 4 | 42 |  |
| Organisation quotidienne du poste de cuisson | MEMO-CUI-01 | 1.0 | FR | cuisson | 7 | 33 |  |
| Programmes de cuisson et de pousse | FICHE-CUI-01 | 1.1 | FR | cuisson | 10 | 39 |  |

## 01 — Magasin et vente

### Ouverture du magasin et des comptoirs · Openingsprocedure Winkel & Toonbanken (SOP-WKL-001)
- Corrigé : Décision 1 : le document était déjà conforme (code 12, valeur d'ouverture 0, pas de comptage). Ajout dans l'étape 1.3 d'un renvoi à l'addendum « Kassa openen » (code 12, valeur 0, on ne compte pas) et, dans l'encadré d'introduction de la partie 2, de la mention « de automatische monnayeur beheert het cash ».
- Corrigé : Grille horaire de la page 1 (AFVINKLIJST OPENINGSWINKEL) non dupliquée : ses repères horaires (06:00 … 06:56, 07:00, Continu, Ochtend) sont reportés entre parenthèses dans le titre des étapes correspondantes ; son encadré « Opmerking organisatie » est gardé (étape 0.3).
- Corrigé : Fautes de frappe corrigées : « broedsnijmachine » → « broodsnijmachine » (Fase 2, 3.2) ; « Continuiteit » → « Continuïteit ».
- Corrigé : Titres des sections 2 et 3 de chaque phase préfixés par « Fase 1 — » / « Fase 2 — » pour lever l'ambiguïté (la numérotation 1.1, 2.1, 3.1… recommence dans chaque phase, comme dans le document).
- Corrigé : Encadrés sans numéro numérotés : 0.1–0.3 (sujet, contexte, remarque organisation), 3.0 (norme d'hygiène), 0 / 4a / 0 / 8a / 8b (introductions et encadrés de l'addendum caisse) ; les 3 règles d'or sont numérotées 1 à 3.
- À confirmer : Les pages 1 à 3 du PDF sont des pages scannées (image pleine page) : texte transcrit depuis l'image, recoupé avec l'extraction précédente de sops.json.
- À confirmer : Aucun numéro de version dans le document ; « 5 » vient du nom du fichier Drive (v5).
- À confirmer : Pagination incohérente dans le PDF : « Pagina 1–3 van 4 » puis « Pagina 4 van 5 » et « 5 van 5 » (addendum caisse ajouté après coup).
- À confirmer : Champs de la grille de la page 1 non repris comme étapes : Datum, Medewerker, Shift 06:00–14:00, Paraaf, « Handtekening medewerker na afloop dienst », « Controle Verantwoordelijke ».
- À confirmer : Étape 2.2 : « afgehaalde bestellingen » (commandes déjà retirées) est sans doute à lire « af te halen bestellingen » (commandes à retirer) ; traduit dans ce sens en français.
- À confirmer : Photos : les 8 photos de l'addendum sont rattachées aux étapes 1 à 8 dans l'ordre du PDF. La photo 1 montre le bureau Windows déjà allumé (résultat attendu de l'étape 1) ; la photo 2 montre l'écran de chargement du logiciel de caisse « The Franchise Buddy » ; la photo 6 est un recadrage du bouton « Dag openen/sluiten ».
- À confirmer : Terme néerlandais choisi pour le monnayeur automatique : « automatische monnayeur » ; à harmoniser avec les autres SOP si un autre terme est retenu.

### Encodage des commandes clients en caisse · Bestelling encoderen in de kassa (SOP-WKL-002)
- Corrigé : Aucune modification du texte néerlandais. Décision 1 sans objet ici (cette SOP ne parle pas du comptage de la caisse).
- Corrigé : Encadrés sans numéro numérotés : généralités 1–3, contrôle rapide 0 (consigne) et 1–10, erreurs fréquentes 1–6 ; noms des boutons de caisse gardés en néerlandais (traduction entre parenthèses en français).
- À confirmer : Étape 8 : « Contant in kassa voor cash die werkelijk in de kassalade ligt » — avec le monnayeur automatique (décision 1), le cash ne va plus dans un tiroir-caisse classique ; formulation à confirmer.
- À confirmer : L'« accord interne » pour « Contant niet in kassa » / « Waardebonnen » et la « procédure interne » d'impression/conservation du bon de commande ne sont décrits nulle part dans ce document.
- À confirmer : Étape 5 : le nom exact du bouton « bestelling of latere afhaling » n'est pas donné.
- À confirmer : PDF lu via la conversion texte de Google Drive (2 pages, 68 Ko) ; pas de photo apparente, PDF non téléchargé pour vérification visuelle.

### Gestion des paniers Too Good To Go · Too Good To Go-mandjes beheren (SOP-VER-TGTG)
- Corrigé : Aucune modification du texte néerlandais (aucune décision du brief ne s'applique à cette SOP).
- Corrigé : Encadrés sans numéro numérotés : horaire/valeur 1–2, cas particuliers 1–3, contrôle rapide 1–4, aide visuelle 0 (consigne) puis 1–4 (photos) et 5 (note sur le prix) ; titres « Start en afhalen », « Waarde », « Waarde en klantprijs » ajoutés car l'encadré n'en a pas.
- À confirmer : DOCX (≈ 40 Mo) non téléchargé : son texte a été lu via la conversion texte de Google Drive et il est identique au PDF ; les 4 photos viennent du PDF (page 2).
- À confirmer : Les 4 photos sont des photos de la tablette (pas des captures d'écran) ; extraites en entier alors que le PDF les affiche recadrées (photo 1 : écran d'accueil de la tablette, l'icône Too Good To Go est dans la barre du bas).
- À confirmer : Fautes de genre dans l'original, laissées telles quelles : « de mandje », « elke verkochte mandje », « geef de mandje mee », « annuleer de mandje » → correct : « het mandje », « elk verkocht mandje ». À corriger lors de la prochaine révision.
- À confirmer : Étape 3 : « Zie de foto’s op pagina 2 » renvoie à la section « Mandjes in de app te koop zetten — visuele hulp ».
- À confirmer : La checklist de fermeture / Fermeture v1.3 mentionne des « photos Too Good To Go de 17 h » (décision 5) qui ne figurent pas dans cette SOP : à aligner.
- À confirmer : « Klant komt niet afhalen » : le personnel peut emporter les produits après 18 h — règle à confirmer par la direction si elle doit figurer telle quelle dans la formation.

### Recuisson d'appoint – pains et baguettes (après-midi) · Bijbakken – broden en stokbroden (namiddag) (SOP-VEN-001)
- Corrigé : Décision 7 : une seule SOP réunissant le PDF FR (texte français) et le PDF NL (texte néerlandais) ; aucun texte traduit, les deux versions sont recopiées telles quelles.
- Corrigé : Code SOP-VEN-001 retenu (décision 7) ; le PDF NL porte le code SOP-VER-001.
- Corrigé : Titres des sections « Repères » / « Kerngegevens » et du tableau (reprenant ses en-têtes) ajoutés ; tableau rendu en texte, une ligne par produit.
- À confirmer : FR et NL disent la même chose : mêmes chiffres (seuil de 4 pièces, 1 grille de 5 ou 6 pains, 1 ou 2 grilles de 4 baguettes, refroidissement 10 à 15 min), mêmes programmes (GRANDS PAINS, BAGUETTE, START / STOP), même ordre. Écarts de formulation seulement : titre FR « Recuisson d'appoint » / NL « Bijbakken » (cuisson d'appoint, sans idée de « re- ») ; étape 1 FR « Contrôler le rayon » / NL « Controleer de winkelvoorraad » ; contrôle FR « Préchauffage terminé avant enfournement » / NL « Oven volledig voorverwarmd vóór het laden » ; en-tête FR « Vendeurs – après-midi » / NL « Verkoopmedewerkers – namiddag ».
- À confirmer : Codes différents dans les deux PDF : FR SOP-VEN-001, NL SOP-VER-001.
- À confirmer : À ne pas confondre avec la recuisson du matin des pains de la veille (+4 min, 20 % d'humidité, même four, sans relancer de programme, catégorie pain uniquement — décision 2) : ici, les produits sortent du congélateur et passent un programme complet GRANDS PAINS ou BAGUETTE.
- À confirmer : Le temps et la température des programmes GRANDS PAINS / BAGUETTE ne sont pas donnés dans ce document (voir la Fiche programmes).
- À confirmer : PDF FR et NL lus via la conversion texte de Google Drive (1 page chacun, ≈ 67 Ko) ; pas de photo apparente, PDF non téléchargés pour vérification visuelle.

## 02 — Cuisson et production

### Ouverture du poste et premières cuissons · Opening van de bakpost en eerste bakbeurten (SOP-CUI-OUV-01)
- Corrigé : Étape 7 (pains de la veille) : ajout de la phrase « Cette recuisson ne vaut que pour la catégorie pain. » — décision 2 : la recuisson du matin (+4 min, 20 % d’humidité, même four, sans relancer de programme) ne vaut que pour la catégorie pain. Le point critique correspondant le précise aussi.
- À confirmer : Le PDF v1.1 a été relu : son texte est identique au DOCX.
- À confirmer : « J’appelle » : la personne à appeler n’est pas nommée dans le document.
- À confirmer : Le libellé d’écran « ajouter du temps » est gardé en français dans la version néerlandaise, avec la traduction entre parenthèses.
- À confirmer : Le programme des baguettes s’appelle « baguettes » ici, mais « BAGUETTE » dans la SOP-CUI-001 et sur la photo de l’écran du four : libellé exact à vérifier.
- À confirmer : La phrase « les charger » de l’étape 7 renvoie aux pains de la veille (titre de l’étape) ; c’est explicité dans la traduction néerlandaise.

### Plaquage et programmation des pousses · Uitleggen en programmeren van de rijskasten (SOP-CUI-POU-01)
- À confirmer : Moment de la journée non précisé (« préparation du lendemain ») : champ moment laissé à null.
- À confirmer : P1, P2, P3 désignent les programmes de pousse ; « choco et beurre » est gardé tel quel (vraisemblablement pains au chocolat et croissants au beurre, à confirmer).
- À confirmer : Dans le tableau du programme 4, « 2 h 30 », « 3 h » et « 3 h 15 » sont des durées écoulées depuis le lancement, pas des heures de l’horloge : rendu par « na 3 u » en néerlandais.

### Viennoiseries et sandwiches · Koffiekoeken en sandwiches (SOP-CUI-VIE-01)
- À confirmer : Le programme des sandwiches est nommé « sandwich » (singulier) dans le tableau, alors que le texte parle des sandwiches : libellé gardé tel quel, à vérifier sur le four.
- À confirmer : « J’appelle » : la personne à appeler n’est pas nommée dans le document.

### Cuisson des baguettes et des pains · Bakken van baguettes en broden (SOP-CUI-001)
- **Statut : à valider** (lisible sur la tablette, sans quiz ni validation).
- Corrigé : Tableau de réglage, ligne « Pains de la veille » (R6), et point à confirmer sur la « recuisson de 5 min des pains de la veille » (Q5) : le temps de 5 min est gardé, avec une précision indiquant qu’il ne s’agit pas de la recuisson du matin des pains de la veille — décision 2 : celle-ci se fait dans le même four, sans relancer de programme, +4 min et 20 % d’humidité, et ne vaut que pour la catégorie pain.
- À confirmer : Document au statut « version 1.0 à valider » : toutes les mentions « À confirmer » sont gardées telles quelles en français et rendues par « te bevestigen » en néerlandais ; rien n’a été complété.
- À confirmer : Le produit auquel s’applique le temps de 5 min n’est pas nommé dans le document : à identifier avec le responsable.
- À confirmer : L’étape 8 interdit « Ajouter du temps » sans consigne validée ; la recuisson des pains de la veille (décision 2, SOP-CUI-OUV-01) utilise justement cette fonction et vaut consigne validée : à harmoniser lors de la validation.
- À confirmer : Le programme s’appelle « BAGUETTE » ici et sur l’écran du four, mais « baguettes » dans la SOP-CUI-OUV-01 : libellé à harmoniser.
- À confirmer : RINÇAGE 00:07 : moment d’utilisation non établi. La décision 4 place le nettoyage des fours après 16 h, mais le document ne dit pas si le rinçage en fait partie ; texte laissé tel quel.
- À confirmer : Moment de la journée non indiqué dans le document : champ moment laissé à null.
- À confirmer : Les libellés d’écran du four (PROGRAMMES, MES PROGRAMMES, START / STOP, SMART.Preheating, Ajouter du temps, Déplacer la sonde, Ajouter température, RINÇAGE) sont gardés en français dans la version néerlandaise, avec une traduction entre parenthèses quand c’est utile.
- À confirmer : Photos : 4 photos d’écran (page 2 du PDF) copiées en photos/baguettes-1.jpg à baguettes-4.jpg et rattachées aux étapes 4 (écran GRANDS PAINS), 5 (préchauffage 83 °C vers 205 °C, 20 %), 7 (cycle 0:07:59, étape 0:03:59) et D3 (programme BAGUETTE actif, avec l’écran de chargement porte et flèche verte).

### Fin de poste de cuisson — terminer son service · Einde van de dienst aan de bakpost (SOP-CUI-FIN-01)
- Corrigé : Décision 4 : ajout de la précision « nettoyage des fours après 16 h » à l’étape qui renvoie le nettoyage extérieur des fours à l’équipe de vente.
- Corrigé : Numérotation 1 à 10 du document conservée pour les deux blocs « Clôturer la production » et « Nettoyer dans cet ordre » ; les autres blocs sont numérotés section.étape.
- À confirmer : Shyfter, groupe « production » : nom du groupe gardé tel quel en NL.
- À confirmer : Aucune photo dans le PDF (pdfimages : 0 image).

### Crème fraîche et crème pâtissière · Crème fraîche en banketbakkersroom (SOP-CUI-CRE-01)
- Corrigé : Aucune correction de contenu. Le document n’a pas de bloc « Objectif » : l’objectif est reformulé à partir des titres et des tableaux de rendement.
- À confirmer : Traduction : « crème fraîche » est gardée comme nom de produit ; il s’agit de crème entière battue avec du sucre (précisé dans l’objectif NL pour éviter la confusion avec la crème fraîche aigre).
- À confirmer : Aucune photo dans le PDF (pdfimages : 0 image).

### Tartes aux fruits · Fruittaarten (SOP-CUI-TFR-01)
- Corrigé : Aucune correction de contenu. Titre ramené à « Tartes aux fruits » (sans « SOP — … — FR — v1.0 ») ; en-têtes et pieds de page répétés non repris.
- À confirmer : Jelfix après usage : « Durée d’utilisation : indéterminée selon la consigne actuelle » — durée de conservation à fixer.
- À confirmer : La Fiche « Programmes de cuisson » indique « +5 min, 1 fois » pour les tartes aux fruits ; cette SOP ne limite pas explicitement le nombre de prolongations — à confirmer.
- À confirmer : Aucune photo dans le PDF (pdfimages : 0 image).

### Flans et tartes au sucre · Flans en suikertaarten (SOP-CUI-FLA-01)
- Corrigé : Aucune correction de contenu : cette SOP v1.1 fait foi pour les critères flan / tarte au sucre (décision 3) et a servi de référence pour corriger la Fiche « Programmes de cuisson et de pousse ».
- Corrigé : Le document n’a pas de bloc « Objectif » : l’objectif est reformulé à partir des titres « Préparer et cuire les flans » / « Préparer et cuire les tartes au sucre ».
- À confirmer : « Programme instantané, minuteur 1 h 15 » pour les fonds correspond au « Programme 4 · pousse immédiate — fonds de tarte 1 h 15 fixe » de la Fiche programmes (cohérent).
- À confirmer : Aucune photo dans le PDF (pdfimages : 0 image).

### Tartes classiques et Brookie · Klassieke taarten en Brookie (SOP-CUI-TAR-01)
- Corrigé : Aucune correction de contenu. Pied de page répété (« SOP-CUI-TAR-01 · TARTES ET BROOKIE ») non repris.
- À confirmer : « Charger le Brookie dans le haut du four avec le Brownie » : on comprend Brownie et Brookie dans le même four, Brookie en haut ; la position du Brownie n’est pas précisée — à confirmer.
- À confirmer : Aucun contrôle de cuisson n’est prévu pour le Brownie (seul le Brookie est contrôlé au couteau).
- À confirmer : Aucune photo dans le PDF (pdfimages : 0 image).

### Tartes froides · Koude taarten (SOP-CUI-FRO-01)
- À confirmer : Poste « production » selon la liste reçue, alors que l’en-tête du document indique « Cuiseur · production du matin ».
- À confirmer : L’encadré « Fromage spéculoos » n’a pas de numéro dans le document : numéroté 4.3 ici pour garder l’ordre.
- À confirmer : Les noms de produits (fromage spéculoos, duo chocolat, framboise passion, citron meringue, merveilleux) sont gardés tels quels en néerlandais ; seuls les formats « petit / grand » sont traduits (klein / groot).

### Éclairs et choux · Eclairs en soezen (SOP-CUI-ECL-01)
- À confirmer : Poste « production » selon la liste reçue, alors que l’en-tête du document indique « Cuiseur · production du matin ».
- À confirmer : Le nappage « au doigt » ne mentionne ni gants ni lavage des mains : à préciser si une règle d’hygiène s’applique.

### Fondants et nappage · Fondants en glazuren (SOP-CUI-NAP-01)
- À confirmer : Poste « production » selon la liste reçue, alors que l’en-tête du document indique « Cuiseur · vers 5 h 45 ».
- À confirmer : Seule la réserve blanche hors bain-marie est décrite (armoire, film, date) ; la gestion d’une réserve chocolat hors bain-marie n’est pas précisée.

### Cookies, cakes et financiers · Cookies, cakes en financiers (SOP-CUI-BIS-01)
- Corrigé : Aucune correction de contenu.
- À confirmer : Financiers : aucune durée de décongélation indiquée (contrôle au toucher uniquement).
- À confirmer : Aucune photo dans le PDF (pdfimages : 0 image).

### Cramiques et craquelins · Cramiques en craquelins (SOP-CUI-CRA-01)
- Corrigé : Aucune correction de contenu.
- À confirmer : Noms de produits « cramique » et « craquelin » gardés tels quels en NL (équivalents flamands « kramiek » et « suikerbrood » ajoutés une fois dans l’objectif).
- À confirmer : Aucune photo dans le PDF (pdfimages : 0 image).

### Quiches et pommes parisiennes · Quiches en pommes parisiennes (SOP-CUI-QUI-01)
- À confirmer : Poste « production » selon la liste reçue, alors que l’en-tête du document indique « Cuiseur · dernière cuisson longue ».
- À confirmer : Moment « ouverture » déduit : le document parle de la « dernière cuisson longue » de la production du jour, et la SOP-CUI-OUV-01 place la préparation des quiches avant 6 h. Aucune heure n’est donnée dans ce document.
- À confirmer : Le document ne donne ni la durée ni la température du programme « quiche ».

## 03 — Nettoyage et fermeture

### Procédure de fermeture – de la dernière préparation au coffre à clés · Sluitingsprocedure – van laatste voorbereiding tot sleutelkluis (SOP-WKL-003)
- Corrigé : Décision 6 : toute la partie française a été refaite. Le document était surtout en néerlandais (phases 1 à 8 et checklist sans français) avec une traduction partielle et fautive : « Hou koude producten gekoeld » avait été traduit « Gardez les produits meublés au frais », corrigé en « Gardez les produits froids au frais » ; « Nieuwe en ervaren medewerkers » rendu par « Nouveaux collaborateurs et collaborateurs expérimentés » (et non « anciens ») ; « sleutelkluis » rendu par « coffre à clés ».
- Corrigé : Passages mélangés FR/NL (Too Good To Go, 3.2 Fours, 5.2 Bestellijst, lignes de checklist correspondantes) séparés par langue : le néerlandais d'origine est gardé, le français réécrit ; la ligne 17 de la checklist, qui n'existait qu'en français (« Commande morgen : Imprimer la feuille de commande… »), a été traduite en néerlandais.
- Corrigé : Décision 1 (phase 5.3) : l'étape « Tel de cash rustig en buiten het zicht van klanten volgens het interne telprotocol » est remplacée par « De muntautomaat beheert de cash: we tellen de kassa niet » (5.3.1) ; « Voer het werkelijk getelde cashbedrag in het juiste veld in » devient « Voer de waarde 0 in. Er wordt geen geteld cashbedrag ingevoerd » (5.3.3) ; « cashbedrag invoeren » retiré du chemin écran (Beheer > Dag openen/sluiten > 12 > 0) ; « Nodig: cash, intern telprotocol » supprimé ; « Klaar wanneer » adapté ; le reste de la clôture (récapitulatif, différence signalée, contrôle de la clôture) est gardé.
- Corrigé : Décisions 1 et 6 : les mentions « TE BEVESTIGEN » sur la signification du code 12 et sur le champ de la valeur 0 sont supprimées (5.3.2 et 5.3.3).
- Corrigé : Décision 1 (checklist, ligne 18) : « Na laatste betaling: cash exact geteld; Beheer > Dag openen/sluiten > 12 > cash > 0 » devient « Na laatste betaling: kassa niet tellen (de muntautomaat beheert de cash); Beheer > Dag openen/sluiten > 12 > 0 ».
- Corrigé : Décision 4 : nettoyage des fours « vers 16h / rond 16u / rond 16:00 » remplacé par « après 16 h / na 16:00 » en 3.2 (quand + étape 3.2.1) et dans la checklist (ligne 4, repère « 16:00 » devenu « Na 16:00 »).
- Corrigé : Faute de frappe corrigée en 7.6 : « Geen vuil op nat gevaar » lu comme « Geen vuil of nat gevaar ».
- Corrigé : Astérisques et barres obliques inverses Markdown parasites supprimés ; tableaux (8 phases, matrice de conservation, zones de la phase 4, checklist) rendus en texte, une ligne par étape ; encadrés « Wanneer / Waar / Nodig », « Klaar wanneer », « Referentiefoto's », « Niet doen » et « Schermpad » rendus comme étapes à numéro lettré (ex. 1.a, 1.b) pour garder la numérotation du document.
- À confirmer : Le signal visuel/sonore exact de l'alarme reste à confirmer (8.3, 8.b, checklist ligne 25).
- À confirmer : « Goedgekeurd door / Approuvé par » est vide dans l'en-tête : approbation à confirmer.
- À confirmer : 5.3.5 : le message de confirmation exact de la clôture du jour (capture d'écran) est encore marqué « TE BEVESTIGEN » ; ce point n'est pas couvert par les décisions et reste à confirmer.
- À confirmer : 5.3.4 « Noteer en meld elk verschil » est gardé alors que la caisse n'est plus comptée : préciser de quelle différence il s'agit.
- À confirmer : Chemin écran de clôture Beheer > Dag openen/sluiten > 12 > 0 : dans l'addendum caisse de la SOP Ouverture, le code 12 est saisi après Beheer et avant « Dag openen/sluiten ». Vérifier l'ordre réel à l'écran.
- À confirmer : Les « Referentiefoto's » sont annoncées (phases 1, 2 et 3.3) mais aucune photo n'est intégrée au Google Doc (export PDF vérifié : 0 image).
- À confirmer : La phase 3.2 ne nomme pas le programme de nettoyage ; la SOP Nettoyage des fours (SOP-VER-002) précise RAPIDE.
- À confirmer : Zone café : « à partir de 16 h 30 » ici, contre « pas avant 16 h » dans la checklist Moments calmes (CHK-WKL-005) et la checklist Fermeture quotidienne (CHK-WKL-004). À harmoniser.
- À confirmer : En-tête : « SUCRE SALE A EMPORTER » (ligne d'enseigne) et date de mise à jour 31/07/2026, non repris en étapes.
- À confirmer : Le document est bilingue NL-FR, mais seul le néerlandais est complet : langue d'origine notée « NL », français entièrement refait (décision 6).

### Moments calmes à l'Atelier Halle – tâches à faire pendant les périodes calmes · Rustige momenten bij Atelier Halle – taken uit te voeren tijdens rustige periodes (CHK-WKL-005)
- Corrigé : Décision 4 (nettoyage des fours après 16 h, partout) : « OM 16.00 UUR: start het reinigingsprogramma van de ovens » devient « NA 16.00 UUR » (étape 36), en français « APRÈS 16 H ».
- Corrigé : Astérisques et crochets Markdown parasites (« \[ \] », « ** ») supprimés ; les cases à cocher sont rendues par des étapes numérotées 1 à 37 dans l'ordre du document.
- Corrigé : Titre de la page 2 « RUSTIGE MOMENTEN - VERVOLGOPRUIMEN, AANVULLEN EN VOORRAAD » lu comme « Rustige momenten – vervolg: opruimen, aanvullen en voorraad » (mots collés).
- Corrigé : En-têtes de page répétés (« L'ATELIER HALLE | CHECKLIST A4 Versie 2.0 ») non dupliqués ; l'encadré d'introduction est repris dans « objectif ».
- Corrigé : Traduction française complète réalisée à partir du néerlandais.
- À confirmer : « broodmachine » (étapes 12 et 13 : brosse, bac de récupération) est traduit par « trancheuse à pain » : à confirmer.
- À confirmer : Écart avec la SOP Fermeture v1.3 : ici la machine à café se nettoie « pas avant 16 h », la Fermeture v1.3 (phase 3.1) dit « à partir de 16 h 30, quand on ne prend plus de commandes de café ». À harmoniser.
- À confirmer : Écart avec la SOP Fermeture v1.3 : ici le sol de la cuisine à l'autolaveuse « uniquement après 14 h », la Fermeture v1.3 (phase 6.2, partie A) le place vers 16 h. À confirmer.
- À confirmer : Étape 14 (intérieur et vitres des fours pendant les moments calmes) : le document ne dit pas si le four doit être éteint ou refroidi. À préciser.
- À confirmer : Le public visé n'est pas indiqué dans le document ; « équipe de vente » d'après le poste donné dans la liste de répartition.
- À confirmer : Aucune photo dans le document (export PDF vérifié : 0 image).

### Nettoyage des fours · Ovens reinigen (SOP-VER-002)
- Corrigé : Décision 4 (nettoyage des fours après 16 h) : aucune modification nécessaire, le document indique déjà « na 16.00 uur » (en-tête, « Wanneer starten » et étape 1).
- Corrigé : Traduction française complète réalisée à partir du néerlandais ; le tableau des interfaces est rendu en deux sous-étapes 2.1 et 2.2 de l'étape 2 ; l'encadré « Nooit doen » est rendu comme une section à part.
- Corrigé : Retour à la ligne parasite de l'encadré « Nooit doen » (« ... nog bezig / is. ») recollé.
- À confirmer : Les durées affichées (00:34 et 00:22) sont recopiées telles quelles : le document ne précise pas s'il s'agit d'heures:minutes ou de minutes:secondes.
- À confirmer : Le document ne dit pas quel four porte quelle interface (MIND.Maps ONE ou PLUS) : le choix se fait d'après l'écran d'accueil.
- À confirmer : Le document s'adresse aux vendeurs (« Verkoopmedewerkers ») ; le poste « nettoyage » est celui donné dans la liste de répartition.
- À confirmer : La SOP Fermeture v1.3 (phase 3.2) ne nomme pas le programme RAPIDE : la présente SOP le précise.
- À confirmer : Aucune photo dans le PDF (pdfimages : 0 image).

### Checklist de fermeture quotidienne · Dagelijkse sluitingschecklist (CHK-WKL-004)
- Corrigé : Décision 5 : version passée de 1.2 à 1.3 ; « Gebaseerd op sluitingsprocedure versie 1.2 » remplacé par « versie 1.3 » (objectif) ; en-tête « Versie 1.2 » non repris.
- Corrigé : Décision 5 : ajout de la ligne 5 « OM 17.00 UUR: verstuur de foto's van de overgebleven producten voor Too Good To Go » (repris de la Fermeture v1.3), placée après les tâches à heure fixe ; les lignes suivantes sont renumérotées en conséquence.
- Corrigé : Décision 4 : « OM 16.00 UUR: start het reinigingsprogramma van de ovens » devient « NA 16.00 UUR » (ligne 3).
- Corrigé : Décision 1 : la ligne « Tel de cash pas na de allerlaatste betaling exact en sluit de dag via Beheer > Dag openen/sluiten > 12 > cashbedrag invoeren > 0. Betekenis '12' en veld '0': TE BEVESTIGEN. » devient « Sluit de dag pas na de allerlaatste betaling af via Beheer > Dag openen/sluiten > 12 > 0. De muntautomaat beheert de cash: we tellen de kassa niet. » (ligne 24) ; les mentions TE BEVESTIGEN sur 12 et 0 sont supprimées.
- Corrigé : Texte lu dans le DOCX (le PDF contient des doublons de lettres dus à la police : « baguettees », « koffieemachine », « portafillters », « plaquettees », « blijft. licht », « binnen blijft.. ») ; contenu vérifié identique sur le PDF.
- Corrigé : Traduction française complète réalisée à partir du néerlandais ; lignes de signature et pied de page rendus en étapes de la section « Fin de la checklist ».
- À confirmer : Le signal visuel/sonore exact de l'alarme reste à confirmer (ligne 34).
- À confirmer : Écarts restants avec la Fermeture v1.3, non modifiés car hors décisions (à confirmer) : pas de ligne « éteindre les fours quand le programme de nettoyage est terminé » ; machine à café « pas avant 16 h » ici contre « à partir de 16 h 30 » dans la Fermeture ; « Druk de bestelbon van morgen af » sans le chemin détaillé de la Fermeture (bestellingen > date de demain > bestellijst, feuille posée sur la table de la cuisine) ; parties A et B du sol sans les repères horaires de la Fermeture (vers 16 h / vers 17 h 30).
- À confirmer : Lignes présentes ici mais absentes de la Fermeture v1.3 : baguettes supplémentaires au plus tard à 14 h 30, pains supplémentaires au plus tard à 16 h, grilles garnies de papier pour demain, petite poubelle de table.
- À confirmer : Ligne 24 : le chemin écran Beheer > Dag openen/sluiten > 12 > 0 est repris de la Fermeture ; dans l'addendum caisse de la SOP Ouverture, le code 12 est saisi après Beheer et avant « Dag openen/sluiten ». Vérifier l'ordre réel à l'écran.
- À confirmer : Emplacement de la ligne Too Good To Go (après les tâches à heure fixe) choisi par nous : à valider.
- À confirmer : Le pied de page du DOCX indique « Pagina van 1 » (champ vide) ; le PDF compte 2 pages.
- À confirmer : Aucune photo dans le DOCX ni dans le PDF (pdfimages : 0 image).

## 04 — Fiches de référence

### Organisation quotidienne du poste de cuisson · Dagelijkse organisatie van de bakpost (MEMO-CUI-01)
- Corrigé : Décision 2 : à la ligne « Après grands pains » (pains de la veille), ajout de « Cette recuisson vaut uniquement pour la catégorie pain ».
- À confirmer : Version laissée à 1.0 (seule une précision a été ajoutée) — à incrémenter si la base de données l’exige.
- À confirmer : « Bain-marie : allumer sur 1,5 » : réglage recopié tel quel, sans unité.
- À confirmer : Aucune photo dans le PDF (pdfimages : 0 image).

### Programmes de cuisson et de pousse · Bak- en rijsprogramma’s (FICHE-CUI-01)
- Corrigé : Décision 3 — ligne Flan : « Dessus doré ; centre mobile, entre flan et liquide ; +5 min, 1 fois » remplacé par « dessus doré ; centre ferme au toucher ; +5 min, 1 fois, puis demander un avis » (SOP Flans v1.1 fait foi).
- Corrigé : Décision 3 — ligne Tarte au sucre : « Dessus bien doré, fermé au toucher ; +7 min, 2 fois max. » remplacé par « croûte bien dorée et régulière ; centre entre flan et liquide ; +7 min, 2 fois max., puis demander un avis » (SOP Flans v1.1 fait foi). Les critères des deux produits étaient inversés.
- Corrigé : Décision 2 — « Pains de la veille » : ajout de « (catégorie pain uniquement) ».
- Corrigé : Version passée de 1.0 à 1.1.
- À confirmer : Code FICHE-CUI-01 attribué : le document ne porte pas de code (pied de page « L’ATELIER · CUISSON · version 1.0 »).
- À confirmer : Tartes aux fruits : la fiche dit « +5 min, 1 fois », la SOP Tartes aux fruits dit « ajouter 5 minutes uniquement aux tartes concernées » sans limiter le nombre de prolongations — à aligner.
- À confirmer : « Programme sablé » (sablés / fonds Plougastel) : écrit sans guillemets dans la fiche ; libellé exact du programme à confirmer.
- À confirmer : Libellé de touche « ajouter du temps » gardé en français en NL, avec traduction entre parenthèses.
- À confirmer : Aucune photo dans le PDF (pdfimages : 0 image).
