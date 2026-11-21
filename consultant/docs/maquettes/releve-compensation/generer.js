/* Maquette « Relevé de compensation mensuel » — Halle, septembre 2026 (demandes du 21/11/2026).
 *
 * Le modèle de Żabka, version retenue le 21/11 : la centrale encaisse les recettes du magasin (cartes, titres-repas,
 * Payconiq versés directement ; espèces versées chaque jour). À la fin du mois, elle en déduit la facture de
 * marchandises (livraisons au tarif de la centrale, 40 % du chiffre d'affaires HT), les redevances et l'abonnement,
 * ajoute ce qu'elle doit au magasin, et lui verse le solde — sa marge — le 10 du mois suivant.
 *
 * Gabarit des documents de gestion, version A (docs/DESIGN_SYSTEM.md §13.4) ; mise en page « C — Cascade », choisie
 * le 21/11 parmi trois (les deux autres, « Pièces » et « Facture », sont dans l'historique Git). Trois pages :
 *   1. la compensation : des recettes du mois à la marge versée le 10 ;
 *   2. votre mois, en approximation : marge hors TVA − personnel − loyer − autres frais = résultat estimé ;
 *   3. les recettes par moyen de paiement et les espèces versées, semaine par semaine.
 *
 * Sorties : releve.html / releve-nl.html (les trois pages dans un cadre de présentation, capturées en PNG),
 *           document.html / document-nl.html (les pages seules, imprimées en PDF A4 par capturer.js).
 *
 * Chiffres : CA HT, jours, tickets, personnel et score T3 sont ceux de Halle (dossier investisseur halle-2026-10, lu
 * le 09/10/2026) ; les redevances, le loyer et les autres frais suivent le budget 2026 de Halle ; la facture de
 * marchandises est fixée à 40 % du CA HT (demande du 21/11). Le reste est d'ILLUSTRATION, marqué d'un astérisque :
 * TTC à 6 % sur tout le CA, répartition par moyen de paiement (celle de Corbais du 2 au 8/11/2026), abonnement,
 * avoir, versements d'espèces, numéros de pièce. Les mentions de l'émetteur restent entre crochets : elles se lisent
 * dans l'ERP.
 *
 *   node consultant/docs/maquettes/releve-compensation/generer.js
 *   node consultant/docs/maquettes/releve-compensation/capturer.js */
const fs = require('fs');
const path = require('path');
const D = __dirname;
const ASSETS = '../../../../shared/public/assets';

/* ---------- formats (DESIGN_SYSTEM §13.1) : 1 234,50 €, espace fine insécable pour les milliers ---------- */
const FINE = ' ', NBSP = ' ';
const groupe = s => s.replace(/\B(?=(\d{3})+(?!\d))/g, FINE);
const eur = c => { const n = Math.abs(Math.round(c)); return groupe(String(Math.floor(n / 100))) + ',' + String(n % 100).padStart(2, '0') + NBSP + '€'; };
const pct = (x, d = 1) => x.toFixed(d).replace('.', ',') + NBSP + '%';
const arr = x => Math.round(x);                                                 // au centime (montants en centimes)
const H = s => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;');

/* ---------- les chiffres de septembre 2026 (en centimes) ---------- */
const CA_HT = 5084975;              // mesuré : 50 849,75 € HT
const JOURS = 30, TICKETS = 4156;   // mesurés
const PERSONNEL = 947750;           // mesuré : coût du personnel encodé au panel, septembre
const TTC = arr(CA_HT * 1.06);      // illustration : 6 % sur tout le CA
const TVA_VENTES = TTC - CA_HT;

const MARCH = { ht: arr(CA_HT * 0.40) };  MARCH.tva = arr(MARCH.ht * 0.06); MARCH.ttc = MARCH.ht + MARCH.tva;
const grille = [['marque', 200], ['assistance', 150], ['marketing', 150]];       // budget 2026 de Halle, en points de base
const red = grille.map(([k, bp]) => { const ht = arr(CA_HT * bp / 10000); return { k, bp, ht, ttc: arr(ht * 1.21) }; });
const RED = { ht: red.reduce((s, r) => s + r.ht, 0), ttc: red.reduce((s, r) => s + r.ttc, 0) }; RED.tva = RED.ttc - RED.ht;
const ABO = { ht: 9900, tva: 2079 };   ABO.ttc = ABO.ht + ABO.tva;               // illustration
const AV = { ht: 4620 };               AV.tva = arr(AV.ht * 0.06);   AV.ttc = AV.ht + AV.tva;      // illustration
const PRIME = 0;                                                                // score T3 12,09 / 20, sous le seuil (à décider) de 14

const VERSE = TTC - MARCH.ttc - RED.ttc - ABO.ttc + AV.ttc + PRIME;            // la marge versée le 10
const CENTRALE = TTC - VERSE;
const TVA_PAYEE = MARCH.tva + RED.tva + ABO.tva - AV.tva;
const TVA_NETTE = TVA_VENTES - TVA_PAYEE;
const MARGE_HT = VERSE - TVA_NETTE;
if (MARGE_HT !== CA_HT - MARCH.ht - RED.ht - ABO.ht + AV.ht + PRIME) { throw new Error('le relevé ne boucle pas'); }

/* Le mois du franchisé : le loyer et les autres frais suivent le modèle du budget 2026 de Halle (% du CA HT). */
const LOYER = arr(CA_HT * 0.07);
const autres = [['energie', 500], ['amortissements', 500], ['comptable', 150], ['entretien', 100], ['assurances', 100]];
const AUTRES = autres.reduce((s, [, bp]) => s + arr(CA_HT * bp / 10000), 0);
const RESTE_PL = MARGE_HT - PERSONNEL - LOYER;
const RESULTAT = RESTE_PL - AUTRES;
const sur100 = c => 100 * c / CA_HT;

/* Moyens de paiement : la répartition de Corbais (semaine du 2 au 8/11/2026, export comptable du panel). */
const corbais = [['cartes', 2921436], ['pluxee', 165163], ['monizze', 48395], ['payconiq', 3350], ['especes', 241971]];
const totC = corbais.reduce((s, [, v]) => s + v, 0);
const moyens = []; let reste = TTC;
corbais.forEach(([k, v], i) => { const m = i === corbais.length - 1 ? reste : arr(TTC * v / totC); reste -= m; moyens.push({ k, m }); });
const ESPECES = moyens.find(x => x.k === 'especes').m;

/* Espèces par semaine (lundi → dimanche), versées chaque jour : le poids de chaque jour vient de la semaine type de Halle. */
const poids = [1239.90, 1271.79, 1339.02, 1221.37, 1491.06, 2492.35, 2807.97];   // lundi … dimanche
const jourSem = d => d % 7;                // index du jour (lundi = 0) : le 1er septembre 2026 est un mardi
const semaines = [[1, 6], [7, 13], [14, 20], [21, 27], [28, 30]];
const poidsMois = Array.from({ length: 30 }, (_, i) => poids[jourSem(i + 1)]).reduce((a, b) => a + b, 0);
let resteE = ESPECES;
const esp = semaines.map(([a, b], i) => {
  let w = 0; for (let d = a; d <= b; d++) { w += poids[jourSem(d)]; }
  const enc = i === semaines.length - 1 ? resteE : arr(ESPECES * w / poidsMois); resteE -= enc;
  const j = b - a + 1;
  return { a, b, j, enc, dep: enc, n: i === 2 ? j - 1 : j, flag: i === 2 ? 'groupe' : (i === 4 ? 'octobre' : null) };
});
const espTot = esp.reduce((s, x) => ({ enc: s.enc + x.enc, dep: s.dep + x.dep, n: s.n + x.n, j: s.j + x.j }), { enc: 0, dep: 0, n: 0, j: 0 });
const jj = d => String(d).padStart(2, '0') + '/09';

/* ---------- les textes, FR et NL ---------- */
const T = {
  fr: {
    lang: 'fr', type: 'Relevé de compensation', num: 'N° RC-2026-09-04 · 05/10/2026', suite: 'N° RC-2026-09-04 · suite',
    emetteur: 'Émetteur', dest: 'Destinataire',
    em: ['L’Atelier By — [raison sociale]', '[Rue et numéro]', '[Code postal] [Ville]', 'TVA BE 0XXX.XXX.XXX'],
    de: ['L’Atelier by Halle, magasin n° 4', '[Rue et numéro]', '1500 Halle', 'TVA BE 0XXX.XXX.XXX'],
    refsL: ['Période', 'Émis le', 'Versement le', 'Base des redevances'],
    refsV: ['01/09 → 30/09/2026', '05/10/2026', '10/10/2026', eur(CA_HT) + ' HT'],
    secC: 'Vos recettes et la compensation', colsC: ['', 'Désignation', 'Montant', 'Part des recettes'],
    cRec: 'Recettes du mois, encaissées par la centrale', cRecSub: 'TVA comprise ; lues dans la caisse (TFBuddy) : ' + JOURS + ' jours, ' + groupe(String(TICKETS)) + ' tickets ; détail page 3',
    cMarch: 'Facture de marchandises', cMarchSub: 'livraisons du mois au tarif de la centrale : ' + eur(MARCH.ht) + ' HT, soit 40 % du CA, + TVA 6 %',
    cRed: 'Redevances', cRedSub: '5 % de ' + eur(CA_HT) + ' HT (marque 2 %, assistance 1,5 %, marketing 1,5 %) + TVA 21 %',
    cAbo: 'Abonnement TFBuddy, septembre', cAboSub: eur(ABO.ht) + ' HT + TVA 21 %',
    cAv: 'Avoir — réclamation du 17/09', cAvSub: 'livraison non conforme, photo et code-barres',
    cPrime: 'Prime standards, T3 2026', cPrimeSub: 'score 12,09 / 20, rang 2 sur 4 ; prime dès 14 / 20',
    cReste: 'Votre marge, versée le 10/10/2026',
    barC: 'Pour la centrale', barV: 'Pour vous',
    due: 'Versé le 10/10/2026', dontTva: 'dont ' + eur(TVA_NETTE) + ' de TVA à reverser à l’État (estimée, page 2)',
    box: 'Versement', boxK: ['Mode', 'Compte crédité', 'Date', 'Communication'],
    boxV: ['Virement SEPA de la centrale', 'BEXX XXXX XXXX XXXX — Neutralle SRL', '10/10/2026', 'RC-2026-09-04'],
    explique: 'La centrale encaisse vos recettes (cartes, titres-repas et Payconiq directement, espèces chaque jour), en déduit votre facture de marchandises, les redevances et l’abonnement, ajoute ce qu’elle vous doit et vous verse le solde le 10 du mois suivant. Un solde négatif fait l’objet d’une facture de régularisation. Une remarque : avant le 8/10, à [adresse de la centrale].',
    foot: 'L’Atelier By — [raison sociale] · [adresse du siège] · N° d’entreprise BE 0XXX.XXX.XXX · RPM [ville] · IBAN BEXX XXXX XXXX XXXX',
    page: (n, N) => 'Page ' + n + ' / ' + N, illu: '* Maquette : montant d’illustration.',
    mois: 'Votre mois, en approximation',
    moisSub: 'Ce que devient la marge versée une fois la TVA, le personnel et le loyer payés. Une estimation : le personnel est celui encodé au panel pour septembre, le loyer et les autres frais suivent le modèle du budget de Halle. Votre comptable fait foi.',
    colsM: ['', 'Désignation', 'Montant', 'Part du CA HT'],
    mVerse: 'Versé le 10/10/2026', mVerseSub: 'page 1, TVA comprise',
    mTva: 'TVA à reverser à l’État, estimée', mTvaSub: 'perçue sur vos ventes ' + eur(TVA_VENTES) + ' − payée à la centrale ' + eur(TVA_PAYEE),
    mMarge: 'Votre marge hors TVA', mMargeSub: 'marge sur marchandises ' + eur(CA_HT - MARCH.ht) + ' (60 % du CA), moins les redevances et l’abonnement, plus l’avoir',
    mPers: 'Personnel', mPersSub: 'coût de septembre encodé au panel',
    mLoyer: 'Loyer et charges locatives', mLoyerSub: '7 % du CA, modèle du budget ; le montant réel vient du bail',
    mReste: 'Reste après personnel et loyer',
    mAutres: 'Autres frais', mAutresSub: 'modèle du budget : énergie 5 %, amortissements et leasing 5 %, comptabilité et banque 1,5 %, entretien 1 %, assurances 1 %',
    mRes: 'Résultat estimé, avant impôts',
    cent: 'Sur 100 € de ventes hors TVA',
    centL: ['Marchandises', 'Redevances et logiciel', 'Personnel', 'Loyer', 'Autres frais', 'Pour vous'],
    rec: 'Les recettes du mois', recSub: JOURS + ' jours de vente, ' + groupe(String(TICKETS)) + ' tickets, lus dans la caisse (TFBuddy)',
    colsR: ['Moyen de paiement', 'Versé à la centrale', 'Encaissé TTC', 'Part'],
    moy: { cartes: ['Cartes (Bancontact, Visa, Mastercard)', 'par le terminal de paiement'], pluxee: ['Pluxee, titres-repas', 'par Pluxee'],
      monizze: ['Monizze, titres-repas', 'par Monizze'], payconiq: ['Payconiq', 'par Payconiq'], especes: ['Espèces', 'chaque jour, voir ci-dessous'] },
    recTTC: 'Recettes TTC', recTVA: 'TVA 6 %', recHT: 'Chiffre d’affaires HT, base des redevances',
    esp: 'Les espèces : encaissé et versé chaque jour', colsE: ['Semaine', 'Jours', 'Encaissé', 'Versé', 'Versements', 'Écart'],
    sem: (a, b) => 'du ' + jj(a) + ' au ' + jj(b), espGroupe: 'les espèces du dimanche 20/09 versées avec celles du lundi 21/09', espOct: 'le versement du 30/09 reçu le 01/10',
    espTot: 'Septembre', espTxt: 'L’encaissé vient de la caisse, ticket par ticket ; le versé, des versements reçus par la centrale. Des espèces non versées ne vous sont pas reversées : elles restent chez vous et apparaissent en écart.',
  },
  nl: {
    lang: 'nl', type: 'Verrekeningsoverzicht', num: 'Nr. RC-2026-09-04 · 05/10/2026', suite: 'Nr. RC-2026-09-04 · vervolg',
    emetteur: 'Afzender', dest: 'Bestemmeling',
    em: ['L’Atelier By — [handelsnaam]', '[Straat en nummer]', '[Postcode] [Gemeente]', 'btw BE 0XXX.XXX.XXX'],
    de: ['L’Atelier by Halle, winkel nr. 4', '[Straat en nummer]', '1500 Halle', 'btw BE 0XXX.XXX.XXX'],
    refsL: ['Periode', 'Uitgegeven op', 'Storting op', 'Basis van de vergoedingen'],
    refsV: ['01/09 → 30/09/2026', '05/10/2026', '10/10/2026', eur(CA_HT) + ' excl. btw'],
    secC: 'Uw ontvangsten en de verrekening', colsC: ['', 'Omschrijving', 'Bedrag', 'Aandeel ontvangsten'],
    cRec: 'Ontvangsten van de maand, geïnd door de centrale', cRecSub: 'incl. btw; gelezen in de kassa (TFBuddy): ' + JOURS + ' dagen, ' + groupe(String(TICKETS)) + ' tickets; detail pagina 3',
    cMarch: 'Goederenfactuur', cMarchSub: 'leveringen van de maand tegen het tarief van de centrale: ' + eur(MARCH.ht) + ' excl. btw, of 40 % van de omzet, + btw 6 %',
    cRed: 'Vergoedingen', cRedSub: '5 % van ' + eur(CA_HT) + ' excl. btw (merk 2 %, bijstand 1,5 %, marketing 1,5 %) + btw 21 %',
    cAbo: 'Abonnement TFBuddy, september', cAboSub: eur(ABO.ht) + ' excl. btw + btw 21 %',
    cAv: 'Creditnota — klacht van 17/09', cAvSub: 'niet-conforme levering, foto en barcode',
    cPrime: 'Premie standaarden, K3 2026', cPrimeSub: 'score 12,09 / 20, rang 2 van 4; premie vanaf 14 / 20',
    cReste: 'Uw marge, gestort op 10/10/2026',
    barC: 'Voor de centrale', barV: 'Voor u',
    due: 'Gestort op 10/10/2026', dontTva: 'waarvan ' + eur(TVA_NETTE) + ' btw door te storten aan de Staat (geraamd, pagina 2)',
    box: 'Storting', boxK: ['Wijze', 'Rekening', 'Datum', 'Mededeling'],
    boxV: ['SEPA-overschrijving door de centrale', 'BEXX XXXX XXXX XXXX — Neutralle SRL', '10/10/2026', 'RC-2026-09-04'],
    explique: 'De centrale int uw ontvangsten (kaarten, maaltijdcheques en Payconiq rechtstreeks, contanten elke dag), trekt er uw goederenfactuur, de vergoedingen en het abonnement van af, telt erbij wat zij u verschuldigd is en stort u het saldo op de 10e van de volgende maand. Een negatief saldo geeft aanleiding tot een regularisatiefactuur. Een opmerking: vóór 8/10, aan [adres van de centrale].',
    foot: 'L’Atelier By — [handelsnaam] · [adres van de zetel] · Ondernemingsnr. BE 0XXX.XXX.XXX · RPR [stad] · IBAN BEXX XXXX XXXX XXXX',
    page: (n, N) => 'Pagina ' + n + ' / ' + N, illu: '* Maquette: illustratief bedrag.',
    mois: 'Uw maand, bij benadering',
    moisSub: 'Wat er van de gestorte marge overblijft na btw, personeel en huur. Een raming: het personeel is dat van het panel voor september, de huur en de andere kosten volgen het budgetmodel van Halle. Uw boekhouder is bepalend.',
    colsM: ['', 'Omschrijving', 'Bedrag', 'Aandeel omzet excl. btw'],
    mVerse: 'Gestort op 10/10/2026', mVerseSub: 'pagina 1, incl. btw',
    mTva: 'Btw door te storten aan de Staat, geraamd', mTvaSub: 'geïnd op uw verkoop ' + eur(TVA_VENTES) + ' − betaald aan de centrale ' + eur(TVA_PAYEE),
    mMarge: 'Uw marge excl. btw', mMargeSub: 'marge op goederen ' + eur(CA_HT - MARCH.ht) + ' (60 % van de omzet), min de vergoedingen en het abonnement, plus de creditnota',
    mPers: 'Personeel', mPersSub: 'kost van september, ingegeven in het panel',
    mLoyer: 'Huur en huurlasten', mLoyerSub: '7 % van de omzet, budgetmodel; het werkelijke bedrag staat in het huurcontract',
    mReste: 'Saldo na personeel en huur',
    mAutres: 'Andere kosten', mAutresSub: 'budgetmodel: energie 5 %, afschrijvingen en leasing 5 %, boekhouding en bank 1,5 %, onderhoud 1 %, verzekeringen 1 %',
    mRes: 'Geraamd resultaat, vóór belastingen',
    cent: 'Op 100 € omzet excl. btw',
    centL: ['Goederen', 'Vergoedingen en software', 'Personeel', 'Huur', 'Andere kosten', 'Voor u'],
    rec: 'De ontvangsten van de maand', recSub: JOURS + ' verkoopdagen, ' + groupe(String(TICKETS)) + ' tickets, gelezen in de kassa (TFBuddy)',
    colsR: ['Betaalmiddel', 'Betaald aan de centrale', 'Ontvangen incl. btw', 'Aandeel'],
    moy: { cartes: ['Kaarten (Bancontact, Visa, Mastercard)', 'via de betaalterminal'], pluxee: ['Pluxee, maaltijdcheques', 'via Pluxee'],
      monizze: ['Monizze, maaltijdcheques', 'via Monizze'], payconiq: ['Payconiq', 'via Payconiq'], especes: ['Contanten', 'elke dag, zie hieronder'] },
    recTTC: 'Ontvangsten incl. btw', recTVA: 'Btw 6 %', recHT: 'Omzet excl. btw, basis van de vergoedingen',
    esp: 'De contanten: elke dag ontvangen en gestort', colsE: ['Week', 'Dagen', 'Ontvangen', 'Gestort', 'Stortingen', 'Verschil'],
    sem: (a, b) => 'van ' + jj(a) + ' tot ' + jj(b), espGroupe: 'de contanten van zondag 20/09 samen gestort met die van maandag 21/09', espOct: 'de storting van 30/09 ontvangen op 01/10',
    espTot: 'September', espTxt: 'Het ontvangen bedrag komt uit de kassa, ticket per ticket; het gestorte bedrag uit de stortingen die de centrale ontving. Niet-gestorte contanten worden u niet doorgestort: ze blijven bij u en verschijnen als verschil.',
  },
};

/* ---------- le gabarit (version A, §13.4) ---------- */
const CSS = `
@font-face { font-family: 'Gotham'; src: url('${ASSETS}/ds/fonts/Gotham_Medium.otf') format('opentype'); font-weight: 400; font-display: block; }
@font-face { font-family: 'Gotham'; src: url('${ASSETS}/ds/fonts/Gotham_Regular.otf') format('opentype'); font-weight: 700; font-display: block; }
* { box-sizing: border-box; }
body { margin: 0; background: #dad6cf; font-family: 'Gotham', Helvetica, Arial, sans-serif; color: #1c1a17; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
h1, h2, h3, h4, th, b, strong { font-weight: 400; }
.cadre { padding: 40px 48px 48px; }
.cadre__k { font-size: 13px; letter-spacing: .14em; text-transform: uppercase; color: #8D1D2C; margin-bottom: 26px; }
.cadre__rangee { display: flex; gap: 40px; align-items: flex-start; }
.cadre__t { font-size: 18px; margin: 0 0 4px; }
.cadre__d { font-size: 12.5px; color: #5c5852; margin: 0 0 14px; max-width: 794px; min-height: 38px; line-height: 1.5; }
.cadre__note { margin-top: 26px; font-size: 12px; color: #5c5852; line-height: 1.6; max-width: 2462px; }
.doc { font: 400 11px/1.5 'Gotham', Helvetica, Arial, sans-serif; color: #1c1a17; }
.doc__page { position: relative; width: 794px; height: 1123px; background: #fff; padding: 52px 54px 40px; overflow: hidden; }
.doc__head { display: flex; justify-content: space-between; align-items: flex-end; border-bottom: 2px solid #8D1D2C; padding-bottom: 12px; margin-bottom: 22px; }
.doc__logo { height: 34px; display: block; }
.doc__type { text-align: right; font-size: 10px; color: #7a736a; line-height: 1.5; }
.doc__type b { display: block; font-size: 15px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: #1c1a17; }
.doc__label { font-size: 8.5px; letter-spacing: .07em; text-transform: uppercase; color: #7a736a; margin-bottom: 5px; }
.doc__parties { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 18px; }
.doc__parties p { margin: 0; }
.doc__parties p b { font-weight: 700; }
.doc__refs { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; padding: 10px 0; border-top: 1px solid #e6e0d8; border-bottom: 1px solid #e6e0d8; margin-bottom: 18px; }
.doc__refs .v { font-size: 11px; font-weight: 700; }
.doc table { width: 100%; border-collapse: collapse; font-size: 11px; }
.doc th { padding: 6px 8px; border-bottom: 1px solid #e6e0d8; font-size: 8.5px; letter-spacing: .06em; text-transform: uppercase; color: #7a736a; font-weight: 400; text-align: left; }
.doc th.num { text-align: right; }
.doc td { padding: 8px; border-bottom: 1px solid rgba(34, 34, 34, .06); vertical-align: top; }
.doc td b { font-weight: 700; }
.doc .sub { display: block; font-size: 9.5px; color: #7a736a; margin-top: 1px; }
.doc .num { text-align: right; white-space: nowrap; }
.doc .gris { color: #7a736a; }
.doc tr { break-inside: avoid; }
.doc tr.st td { color: #7a736a; border-bottom: 1px solid #e6e0d8; }
.doc tr.palier td { border-top: 1px solid #e6e0d8; }
.doc tr.final td { border-top: 1px solid #e6e0d8; border-bottom: 0; padding-top: 10px; font-size: 13px; }
.doc__sec { font-size: 8.5px; letter-spacing: .07em; text-transform: uppercase; color: #7a736a; margin: 18px 0 2px; }
.doc__totals { width: 46%; margin: 14px 0 0 auto; }
.doc__totals td { border: 0; padding: 4px 8px; }
.doc__totals .is-due td { padding-top: 9px; border-top: 1px solid #e6e0d8; font-size: 13px; font-weight: 700; color: #8D1D2C; }
.doc__box { border-left: 3px solid #8D1D2C; padding: 2px 0 2px 12px; margin: 18px 0 0; }
.doc__box table { width: auto; }
.doc__box td { border: 0; padding: 2px 16px 2px 0; }
.doc__box .k { color: #7a736a; width: 130px; }
.doc__texte { margin: 14px 0 0; color: #5c5852; font-size: 10px; line-height: 1.6; }
.doc__illu { position: absolute; left: 54px; right: 54px; bottom: 74px; font-size: 8.5px; color: #7a736a; }
.doc__foot { position: absolute; left: 54px; right: 54px; bottom: 34px; padding-top: 9px; border-top: 1px solid #e6e0d8; font-size: 8.5px; color: #7a736a; line-height: 1.6; display: flex; justify-content: space-between; gap: 24px; }
.doc__foot span:last-child { white-space: nowrap; }
.op { width: 18px; color: #7a736a; text-align: center; padding-left: 0 !important; padding-right: 0 !important; }
.doc tr.reste td { border-top: 1px solid #e6e0d8; border-bottom: 0; padding-top: 10px; }
.barre { display: flex; height: 10px; margin: 16px 0 6px; border-radius: 2px; overflow: hidden; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
.barre i { display: block; height: 100%; }
.barre__leg { display: flex; justify-content: space-between; font-size: 9.5px; color: #7a736a; }
.cent { display: grid; grid-template-columns: repeat(6, 1fr); gap: 8px; margin-top: 4px; font-size: 9.5px; color: #7a736a; line-height: 1.4; }
.cent span { display: block; }
.cent i { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 5px; vertical-align: 0; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
.cent b { display: block; font-weight: 400; color: #1c1a17; font-size: 11px; }
@media print {
  @page { size: A4; margin: 0; }
  body { background: #fff; }
  .doc__page { width: 210mm; height: 297mm; break-after: page; }
  .doc__page:last-child { break-after: auto; }
}
`;

const ast = '<span class="gris">*</span>';
const logo = `<img class="doc__logo" src="${ASSETS}/img/logo.png" alt="L’Atelier">`;
const N = 3;
const tete = (t, sous) => `<div class="doc__head">${logo}<div class="doc__type"><b>${H(t.type)}</b>${H(sous)}</div></div>`;
const pied = (t, n) => `<div class="doc__illu">${H(t.illu)}</div><div class="doc__foot"><span>${H(t.foot)}</span><span>${H(t.page(n, N))}</span></div>`;
const ligneOp = (o, l, s, m, p, cls = '') => `<tr class="${cls}"><td class="op">${o}</td><td><b>${l}</b>${s ? `<span class="sub">${H(s)}</span>` : ''}</td><td class="num">${m}</td><td class="num gris">${p}</td></tr>`;

function parties(t) {
  return `<div class="doc__parties">
    <div><div class="doc__label">${H(t.emetteur)}</div><p>${t.em.map(H).join('<br>')}</p></div>
    <div><div class="doc__label">${H(t.dest)}</div><p><b>Neutralle SRL</b><br>${t.de.map(H).join('<br>')}</p></div>
  </div>
  <div class="doc__refs">${t.refsL.map((l, i) => `<div><div class="doc__label">${H(l)}</div><div class="v">${H(t.refsV[i])}</div></div>`).join('')}</div>`;
}

/* Page 1 : la cascade, des recettes du mois à la marge versée le 10. */
function page1(t) {
  const part = c => pct(100 * c / TTC);
  const pc = 100 * CENTRALE / TTC;
  return `<div class="doc doc__page">${tete(t, t.num)}${parties(t)}
    <div class="doc__sec">${H(t.secC)}</div>
    <table><thead><tr><th class="op"></th><th>${t.colsC[1]}</th><th class="num">${t.colsC[2]}</th><th class="num" style="width:120px">${t.colsC[3]}</th></tr></thead><tbody>
    ${ligneOp('', H(t.cRec) + ast, t.cRecSub, eur(TTC), '100' + NBSP + '%')}
    ${ligneOp('−', H(t.cMarch), t.cMarchSub, eur(MARCH.ttc), part(MARCH.ttc))}
    ${ligneOp('−', H(t.cRed), t.cRedSub, eur(RED.ttc), part(RED.ttc))}
    ${ligneOp('−', H(t.cAbo) + ast, t.cAboSub, eur(ABO.ttc), part(ABO.ttc))}
    ${ligneOp('+', H(t.cAv) + ast, t.cAvSub, eur(AV.ttc), part(AV.ttc))}
    ${ligneOp('+', H(t.cPrime), t.cPrimeSub, eur(PRIME), '')}
    ${ligneOp('=', H(t.cReste), '', eur(VERSE), part(VERSE), 'reste')}
    </tbody></table>
    <div class="barre"><i style="width:${pc.toFixed(2)}%;background:#5c5852"></i><i style="flex:1;background:#EDE7DE"></i></div>
    <div class="barre__leg"><span>${H(t.barC)} · ${eur(CENTRALE)} · ${pct(pc)}</span><span>${H(t.barV)} · ${eur(VERSE)} · ${pct(100 - pc)}</span></div>
    <table class="doc__totals"><tr class="is-due"><td>${H(t.due)}</td><td class="num">${eur(VERSE)}</td></tr></table>
    <p class="doc__texte" style="margin:4px 0 0 auto;max-width:62%;text-align:right">${H(t.dontTva)}</p>
    <div class="doc__box"><div class="doc__label">${H(t.box)}</div><table>${t.boxK.map((k, i) =>
      `<tr><td class="k">${H(k)}</td><td>${H(t.boxV[i])}</td></tr>`).join('')}</table></div>
    <p class="doc__texte">${H(t.explique)}</p>
    ${pied(t, 1)}</div>`;
}

/* Page 2 : le mois du franchisé, en approximation. */
function page2(t) {
  const p = c => pct(sur100(c));
  const segs = [[MARCH.ht, '#5c5852'], [RED.ht + ABO.ht - AV.ht, '#7a736a'], [PERSONNEL, '#9a938a'], [LOYER, '#B8B2A8'], [AUTRES, '#D9D3CA'], [RESULTAT, '#3f7a52']];
  return `<div class="doc doc__page">${tete(t, t.suite)}
    <div class="doc__sec" style="margin-top:0">${H(t.mois)}</div>
    <p class="doc__texte" style="margin:0 0 8px">${H(t.moisSub)}</p>
    <table><thead><tr><th class="op"></th><th>${t.colsM[1]}</th><th class="num">${t.colsM[2]}</th><th class="num" style="width:130px">${t.colsM[3]}</th></tr></thead><tbody>
    ${ligneOp('', H(t.mVerse), t.mVerseSub, eur(VERSE), '')}
    ${ligneOp('−', H(t.mTva) + ast, t.mTvaSub, eur(TVA_NETTE), '')}
    ${ligneOp('=', H(t.mMarge), t.mMargeSub, eur(MARGE_HT), p(MARGE_HT), 'palier')}
    ${ligneOp('−', H(t.mPers), t.mPersSub, eur(PERSONNEL), p(PERSONNEL))}
    ${ligneOp('−', H(t.mLoyer), t.mLoyerSub, eur(LOYER), p(LOYER))}
    ${ligneOp('=', H(t.mReste), '', eur(RESTE_PL), p(RESTE_PL), 'palier')}
    ${ligneOp('−', H(t.mAutres), t.mAutresSub, eur(AUTRES), p(AUTRES))}
    ${ligneOp('=', H(t.mRes), '', eur(RESULTAT), p(RESULTAT), 'final')}
    </tbody></table>
    <div class="doc__sec" style="margin-top:30px">${H(t.cent)}</div>
    <div class="barre" style="margin-top:8px">${segs.map(([c, col]) => `<i style="width:${sur100(c).toFixed(3)}%;background:${col}"></i>`).join('')}</div>
    <div class="cent">${segs.map(([c, col], i) => `<span><i style="background:${col}"></i>${H(t.centL[i])}<b>${pct(sur100(c))}</b></span>`).join('')}</div>
    ${pied(t, 2)}</div>`;
}

/* Page 3 : les recettes du mois et les espèces versées. */
function page3(t) {
  return `<div class="doc doc__page">${tete(t, t.suite)}
    <div class="doc__sec" style="margin-top:0">${H(t.rec)}</div>
    <p class="doc__texte" style="margin:0 0 6px">${H(t.recSub)}</p>
    <table><thead><tr><th>${t.colsR[0]}</th><th>${t.colsR[1]}</th><th class="num">${t.colsR[2]}</th><th class="num" style="width:70px">${t.colsR[3]}</th></tr></thead><tbody>
    ${moyens.map(x => `<tr><td><b>${H(t.moy[x.k][0])}</b>${ast}</td><td class="gris">${H(t.moy[x.k][1])}</td><td class="num">${eur(x.m)}</td><td class="num gris">${pct(100 * x.m / TTC)}</td></tr>`).join('')}
    </tbody></table>
    <table class="doc__totals" style="width:58%">
      <tr><td>${H(t.recTTC)}</td><td class="num">${eur(TTC)}</td></tr>
      <tr><td>${H(t.recTVA)}${ast}</td><td class="num">${eur(TVA_VENTES)}</td></tr>
      <tr><td style="border-top:1px solid #e6e0d8;padding-top:8px"><b>${H(t.recHT)}</b></td><td class="num" style="border-top:1px solid #e6e0d8;padding-top:8px"><b>${eur(CA_HT)}</b></td></tr>
    </table>
    <div class="doc__sec" style="margin-top:30px">${H(t.esp)}</div>
    <table><thead><tr><th>${t.colsE[0]}</th>${t.colsE.slice(1).map(c => `<th class="num">${c}</th>`).join('')}</tr></thead><tbody>
    ${esp.map(x => `<tr><td><b>${H(t.sem(x.a, x.b))}</b>${x.flag === 'groupe' ? `<span class="sub">${H(t.espGroupe)}</span>` : x.flag === 'octobre' ? `<span class="sub">${H(t.espOct)}</span>` : ''}</td>
      <td class="num gris">${x.j}</td><td class="num">${eur(x.enc)}</td><td class="num">${eur(x.dep)}${ast}</td><td class="num gris">${x.n}</td><td class="num gris">${eur(x.enc - x.dep)}</td></tr>`).join('')}
    <tr class="st"><td>${H(t.espTot)}</td><td class="num">${espTot.j}</td><td class="num">${eur(espTot.enc)}</td><td class="num">${eur(espTot.dep)}</td><td class="num">${espTot.n}</td><td class="num">${eur(espTot.enc - espTot.dep)}</td></tr>
    </tbody></table>
    <p class="doc__texte">${H(t.espTxt)}</p>
    ${pied(t, 3)}</div>`;
}

/* ---------- le cadre de présentation (comme docs/design-system/document-reference.png) ---------- */
const NOTE = 'Ce qui est mesuré : le chiffre d’affaires HT (50 849,75 €), les jours et les tickets de Halle en septembre 2026, le personnel encodé au panel (9 477,50 €), le score du T3 (12,09 / 20) — dossier investisseur, lu le 09/10/2026. '
  + 'Ce qui est posé : la facture de marchandises à 40 % du CA HT (le panel mesurait la matière à 36,2 % du CA en septembre) ; les redevances, le loyer et les autres frais suivent le budget 2026 de Halle. '
  + 'Ce qui est d’illustration (*) : le TTC (6 % sur tout le chiffre), la répartition par moyen de paiement (celle de Corbais du 2 au 8/11/2026), l’abonnement, l’avoir, les versements d’espèces et les numéros de pièce. '
  + 'Entre crochets : les mentions qui se lisent dans l’ERP. Le seuil de la prime (14 / 20) est une hypothèse à décider.';
const PAGES = [
  ['Page 1 — la compensation', 'La centrale encaisse les recettes, déduit la facture de marchandises (40 % du CA), les redevances et l’abonnement, et verse la marge le 10.', page1],
  ['Page 2 — votre mois, en approximation', 'Ce que devient la marge versée : TVA, personnel, loyer, autres frais, résultat estimé ; et sur 100 € de ventes, qui prend quoi.', page2],
  ['Page 3 — les recettes et les espèces', 'Les recettes par moyen de paiement, versées à la centrale, et les espèces encaissées face aux versements, semaine par semaine.', page3],
];

function cadre(lg) {
  const t = T[lg];
  return `<!doctype html><html lang="${t.lang}"><head><meta charset="utf-8"><title>Relevé de compensation — Halle, septembre 2026 (${lg.toUpperCase()})</title><style>${CSS}</style></head>
<body><div class="cadre">
  <div class="cadre__k">Relevé de compensation · version C — cascade · ${lg === 'fr' ? 'français' : 'néerlandais'} · maquette, Halle, septembre 2026</div>
  <div class="cadre__rangee">${PAGES.map(([titre, d, f]) => `<div><h3 class="cadre__t">${H(titre)}</h3><p class="cadre__d">${H(d)}</p>${f(t)}</div>`).join('')}</div>
  <p class="cadre__note">${H(NOTE)}</p>
</div></body></html>`;
}

function documentSeul(lg) {
  const t = T[lg];
  return `<!doctype html><html lang="${t.lang}"><head><meta charset="utf-8"><title>${H(t.type)} RC-2026-09-04 — Halle</title><style>${CSS}</style></head>
<body>${PAGES.map(([, , f]) => f(t)).join('')}</body></html>`;
}

for (const lg of ['fr', 'nl']) {
  const s = lg === 'fr' ? '' : '-nl';
  fs.writeFileSync(path.join(D, 'releve' + s + '.html'), cadre(lg));
  fs.writeFileSync(path.join(D, 'document' + s + '.html'), documentSeul(lg));
}

console.log(JSON.stringify({
  recettesTTC: eur(TTC), marchandisesTTC: eur(MARCH.ttc), redevancesTTC: eur(RED.ttc), verse: eur(VERSE), partCentrale: pct(100 * CENTRALE / TTC, 2),
  tvaNette: eur(TVA_NETTE), margeHT: eur(MARGE_HT), loyer: eur(LOYER), restePL: eur(RESTE_PL), autres: eur(AUTRES), resultat: eur(RESULTAT), resultatPct: pct(sur100(RESULTAT), 2),
}, null, 1));
