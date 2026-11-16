/* Les chiffres des deux maquettes.
   - Les frais : le total est celui que le panel rend pour Halle (/shops/4/owner/costs, septembre 2026 : 13 042,65 €,
     docs/contrat-api.md) ; les LIGNES sont des exemples, la vraie liste se lit au panel.
   - La journée : la carte « Le P&L court de la journée » envoyée le 16/11/2026 (CA 2 628 €, 187 clients). La TVA des
     tickets est prise à 6 % pour l'exemple : en vrai, elle se lit ticket par ticket (tax_amount_after_discount). */
const FRAIS = [
  // [poste, type au panel, montant saisi du mois, TVA comprise ? (true | false | null = à indiquer), taux %, note]
  ['Loyer', 'Loyer', 4800.00, false, 0, 'non soumis à la TVA'],
  ['Charges locatives', 'Loyer', 650.00, false, 0, ''],
  ['Électricité et gaz', 'Énergie', 1850.00, true, 21, ''],
  ['Eau', 'Énergie', 120.00, true, 6, ''],
  ['Assurances', 'Assurances', 410.00, false, 0, 'exonérées'],
  ['Comptable et secrétariat social', 'Honoraires', 780.00, true, 21, ''],
  ['Leasing four et vitrines', 'Leasing', 1640.00, false, 21, 'saisi hors TVA, comme le contrat'],
  ['Téléphone, internet, caisse', 'Télécom', 285.00, true, 21, ''],
  ['Entretien et nettoyage', 'Entretien', 520.00, null, null, ''],
  ['Publicité locale', 'Marketing', 300.00, true, 21, ''],
  ['Frais bancaires et terminaux', 'Banque', 187.65, false, 0, 'exonérés'],
  ['Cotisation marketing réseau', 'Marketing', 1500.00, true, 21, '']
];
/** Une ligne de frais : hors TVA et TVA. TVA comprise : montant ÷ (1 + taux) ; sinon le montant tel quel. */
const fraisHt = l => l[3] === true ? l[2] / (1 + l[4] / 100) : l[2];
const FRAIS_TOT = FRAIS.reduce((t, l) => ({ saisi: t.saisi + l[2], ht: t.ht + fraisHt(l), aIndiquer: t.aIndiquer + (l[3] === null ? 1 : 0), aIndiquerEur: t.aIndiquerEur + (l[3] === null ? l[2] : 0) }), { saisi: 0, ht: 0, aIndiquer: 0, aIndiquerEur: 0 });
FRAIS_TOT.tva = FRAIS_TOT.saisi - FRAIS_TOT.ht;
FRAIS_TOT.part = FRAIS_TOT.ht / FRAIS_TOT.saisi;   // ce qui reste des frais une fois la TVA retirée

const JOUR = { ttc: 2628, tauxTva: 6, clients: 187, panier: 14.05, matiere: 1035, invendus: 77, pieces: 111, labour: 567, fraisSaisis: 447,
  seuils: { food: 40, labour: 20, overhead: 13.5 } };
JOUR.ht = JOUR.ttc / (1 + JOUR.tauxTva / 100);
JOUR.tva = JOUR.ttc - JOUR.ht;
JOUR.fraisHt = JOUR.fraisSaisis * FRAIS_TOT.part;
JOUR.mb = JOUR.ht - JOUR.matiere;
JOUR.net = JOUR.mb - JOUR.invendus - JOUR.labour - JOUR.fraisHt;
// Avant : la carte telle qu'elle s'affiche aujourd'hui, tout rapporté au CA TTC, les frais tels que saisis.
JOUR.avant = { ca: 2628, mat: 39.4, mb: 1593, mbPct: 60.6, inv: 2.9, lab: 21.6, oh: 17.0, net: 501, netPct: 19.1 };
