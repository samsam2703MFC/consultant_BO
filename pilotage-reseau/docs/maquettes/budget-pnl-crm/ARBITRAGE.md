# Répartition des charges (cockpit) ↔ Compte de résultat réseau (CRM) — arbitrage (07/11/2026)

Demande : « dans Répartition des charges, il faut se coller au Compte de résultat réseau (crm_settings · impl_pnl) qui est
dans le CRM ; il faut que les deux soient identiques, ou, suivant ce qu'on veut montrer, qu'ils aient les mêmes données.
Maquette et analyse (arbitrage), ensuite on décide. » **Rien n'est codé ni mis en ligne.**

Écrans : `cockpit-actuel.png` (la carte en ligne) · `analyse-ecarts.png` (poste par poste, options) ·
`a-alignee-sur-crm.png` (maquette A). Sources : `generer.py` (HTML régénérables).

## Constat

| | CRM — Compte de résultat réseau | Cockpit — Répartition des charges |
|---|---|---|
| Où | `crm_settings.impl_pnl` (Implantations › Référentiel, `PnlEditor.tsx`) | `ceo_app_setting.budgetCharges` (Budget › Paramètres) |
| Sert à | dossiers d'implantation, DIP (annexe 11), simulateur, publié vers l'espace candidat | budget des magasins (théorique / validé / réel), seuil matière du dashboard |
| Structure | rubriques → postes à **clé stable** (`royalties_pct`…), sous-totaux « a − b », loyer lu du bail | catégories → lignes (id interne), paliers |
| Taux | **un** % réseau | **deux** % (théorique, validé) + € réel |
| Total charges | 67,5 % | 64,5 % théorique · 25,5 % validé |

Écarts (rouge dans `analyse-ecarts.png`) : loyer 6 / 5 / 7 · maintenance 0,5 / 0,5 / 1 · redevances : CRM 4 % + 2,5 %
(= le contrat, DIP v29 : marque 2 · assistance 2 · marketing 2,5) ; cockpit 2 + 2 + 2 théorique et 2 + 1,5 + 1,5 validé
· amortissements 7 / 5 / 5 · autres 1,5 / 2 / 1,5 · une catégorie « Rémunération » vide au cockpit · sous-totaux
différents (« Marge Brutte » seule au cockpit). Identiques : matière 38 + emballage 2 (le seuil matière du dashboard,
40 %, est le même), énergie 5, assurances 1.

## Options

**A · Le CRM fait foi, le cockpit lit (recommandée).**
- Structure, libellés, clés, sous-totaux et **% réseau** = `impl_pnl`, lus par une **route d'intégration du CRM**
  (`GET /crm/api/v1/integration/recrutement/compte-resultat`, jeton de service `erp_jeton_service` — le chemin déjà pris
  par `scouting-reseau`). Aucune lecture de table d'un autre portail (règle des portails).
- Le cockpit garde ce qui est à lui : **% validé** par poste (réseau), € théorique / validé / réel, écart validé − réseau.
- Reprise UNE fois des % validés du cockpit sur les clés du CRM (Royalties + Redevance assistance → `royalties_pct`,
  3,5 %) ; matière et emballage « à saisir » (0 aujourd'hui).
- « + Catégorie », « + poste », « + étape » quittent le cockpit ; « Modifier dans le CRM ↗ ». Le seuil matière
  (`seuilsCharges`) lit la rubrique « matières » du CRM.
- Pour : une vérité, celle du DIP, du contrat et des dossiers ; redevances justes. Contre : une route au CRM, une reprise.

**B · Le cockpit fait foi, le CRM lit.** Écarté : le DIP, le contrat et les réels des dossiers (rattachés aux clés) vivent
dans le CRM ; le cockpit n'a ni clés stables, ni loyer lu du bail, et ses redevances ne sont pas celles du contrat.

**C · Deux saisies, écart signalé.** Chacun garde la sienne ; le cockpit montre l'écart au CRM ligne par ligne et
« ↙ Reprendre du CRM ». Le moins de travail, mais deux vérités durables.

## À décider

1. A, B ou C.
2. (A) Le « % validé » reste-t-il un taux réseau (un seul pour tous les magasins, comme aujourd'hui) ou par magasin ?
3. (A) Garder une colonne « % théorique » propre au cockpit, ou le théorique = le % réseau du CRM (maquette) ?
4. Corriger au passage les fautes des deux côtés : « Marge Brutte » (cockpit), « Disponible pour rémunartion » (CRM).
