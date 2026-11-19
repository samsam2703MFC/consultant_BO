# Fiche d'une formation dans le manuel, sur tablette — 4 propositions (1er nov. 2026)

Demande : « refaire la vue `#/manuel/formation/FO-29/partie/preouverture` — 4 propositions en
screenshot ». iPad paysage (1180 × 820), mode tablette, avec les VRAIES données de FO-29
(`src/data/referentiel_v25.json`) ; les personnes sont nommées par leur rôle.

| Proposition | Idée |
|---|---|
| **A · Une fiche à lire** (`A-fiche-a-lire.png`) | Une colonne de lecture : en-tête, 4 repères (fréquence, étapes, qui la suit, qui la valide), bouton vers la procédure, puis 5 sections numérotées : ce que vous saurez faire · au programme · comment on la valide (frise verticale) · en boutique, ensuite (cases) · pour qui. |
| **B · Les cinq étapes** (`B-cinq-etapes.png`) | Les 5 étapes de validation en grandes tuiles en tête (« Commencer › » sur la première), puis compétence et programme · checklist boutique, puis pour qui · à suivre avec (formations du même bloc). |
| **C · Tableau de bord** (`C-tableau-de-bord.png`) | Bandeau bordeaux (code, partie, procédure), 4 chiffres (fréquence, étapes, postes, indicateurs), 3 cartes (programme · checklist · ce qu'on mesure), pour qui (obligatoire / recommandée) et le plan de formation. |
| **D · Quatre onglets** (`D-onglets.png`) | En-tête et fiche d'identité, puis 4 onglets Comprendre · Se former · Valider · Appliquer ; un écran par onglet, « Suite » en bas. Le moins de défilement. |

D'où viennent les données : la formation (fréquence, contrôleur, exécutant, procédure MO §7.13,
postes attendus O / R) ; sa compétence `C-RH.Recrutement` ; la rubrique du manuel D6 › Recrutement
(la phrase d'introduction) ; le bloc B5 « Management & leadership » du Plan Gérant (programme,
checklist, indicateurs — **commun à FO-06, FO-29 et FO-30** : rien de propre au recrutement dans le
référentiel). Les textes des 5 étapes sont **illustratifs** : FO-29 n'a pas encore d'évaluations
« [FO-29] » dans le panel (FO-01 à FO-06 en ont) ; en ligne, ce seront celles du panel.

    python3 docs/maquettes/manuel-formation/generer.py
    node docs/maquettes/manuel-formation/captures.js
