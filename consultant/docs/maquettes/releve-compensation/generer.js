/* Maquette « Relevé de compensation mensuel » — Halle, septembre 2026 (demande du 21/11/2026).
 *
 * Le modèle Żabka adapté à la Belgique : les recettes restent sur un compte de recettes au nom du franchisé ;
 * le 10 de chaque mois, la centrale prélève (domiciliation SEPA B2B) ce que le magasin lui doit, diminué de ce
 * qu’elle lui doit. Le relevé, émis le 5, montre cette compensation, les recettes du mois par moyen de paiement
 * et le contrôle des espèces (encaissé face au déposé).
 *
 * Gabarit des documents de gestion, version A (docs/DESIGN_SYSTEM.md §13.4). Trois mises en page de la page 1,
 * comme le veut la règle des nouvelles pièces (§13, « montrer trois versions ») ; la page 2 est commune :
 *   a.html / a-nl.html   Pièces   : le relevé de compte, une pièce par ligne, débit et crédit
 *   b.html / b-nl.html   Facture  : HT, TVA et TTC par ligne, le récapitulatif de TVA pour le comptable
 *   c.html / c-nl.html   Cascade  : des recettes du mois à ce qui reste après la compensation
 *   planche.html         les trois pages 1 côte à côte
 *
 * Chiffres : CA HT, jours, tickets et score T3 sont ceux de Halle (dossier investisseur halle-2026-10, lu le
 * 09/10/2026) ; les redevances suivent la grille du budget 2026 de Halle (2 % marque, 1,5 % assistance, 1,5 %
 * marketing). Le reste est d’ILLUSTRATION, marqué d’un astérisque : TTC à 6 % sur tout le CA, répartition par
 * moyen de paiement (celle de Corbais du 2 au 8/11/2026), livraisons de la centrale, abonnement, avoir, dépôts
 * d’espèces, numéros de pièce. Les mentions de l’émetteur restent entre crochets : elles se lisent dans l’ERP.
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
const taux = bp => (bp / 100).toString().replace('.', ',') + NBSP + '%';        // 150 → « 1,5 % »
const arr = x => Math.round(x);                                                 // arrondi au centime (montants en centimes)
const H = s => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;');

/* ---------- les chiffres de septembre 2026 (en centimes) ---------- */
const CA_HT = 5084975;            // mesuré : 50 849,75 € HT (dossier investisseur)
const JOURS = 30, TICKETS = 4156; // mesurés
const TTC = arr(CA_HT * 1.06);    // illustration : 6 % sur tout le CA
const TVA_VENTES = TTC - CA_HT;

const grille = [['marque', 200], ['assistance', 150], ['marketing', 150]];       // budget 2026 de Halle, en points de base
const red = grille.map(([k, bp]) => { const ht = arr(CA_HT * bp / 10000); return { k, bp, ht, tva: arr(ht * 0.21), ttc: arr(ht * 1.21) }; });
const RED_HT = red.reduce((s, r) => s + r.ht, 0), RED_TTC = red.reduce((s, r) => s + r.ttc, 0);

const ABO = { ht: 9900, tva: 2079 };   ABO.ttc = ABO.ht + ABO.tva;               // illustration
const LIV = { ht: 341280 };            LIV.tva = arr(LIV.ht * 0.06); LIV.ttc = LIV.ht + LIV.tva;   // illustration
const AV = { ht: 4620 };               AV.tva = arr(AV.ht * 0.06);   AV.ttc = AV.ht + AV.tva;      // illustration
const PRIME = 0;                                                                // score T3 12,09 / 20 sous le seuil (à décider) de 14

const DEBIT_HT = RED_HT + ABO.ht + LIV.ht;
const DEBIT_TTC = RED_TTC + ABO.ttc + LIV.ttc;
const CREDIT_HT = AV.ht + PRIME, CREDIT_TTC = AV.ttc + PRIME;
const SOLDE = DEBIT_TTC - CREDIT_TTC;
const TVA21_BASE = RED_HT + ABO.ht, TVA21 = red.reduce((s, r) => s + r.ttc - r.ht, 0) + ABO.tva;
const TVA6_BASE = LIV.ht - AV.ht, TVA6 = LIV.tva - AV.tva;
if (DEBIT_HT - CREDIT_HT + TVA21 + TVA6 !== SOLDE) { throw new Error('le relevé ne boucle pas'); }
const RESTE = TTC - SOLDE;

/* Moyens de paiement : la répartition de Corbais (semaine du 2 au 8/11/2026, export comptable du panel). */
const corbais = [['cartes', 2921436], ['pluxee', 165163], ['monizze', 48395], ['payconiq', 3350], ['especes', 241971]];
const totC = corbais.reduce((s, [, v]) => s + v, 0);
const moyens = []; let reste = TTC;
corbais.forEach(([k, v], i) => { const m = i === corbais.length - 1 ? reste : arr(TTC * v / totC); reste -= m; moyens.push({ k, m }); });
const ESPECES = moyens.find(x => x.k === 'especes').m;

/* Espèces par semaine (lundi → dimanche) : le poids de chaque jour vient de la semaine type de Halle. */
const poids = [1239.90, 1271.79, 1339.02, 1221.37, 1491.06, 2492.35, 2807.97];   // lundi … dimanche
const jourSem = d => d % 7;               // index du jour (lundi = 0) : le 1er septembre 2026 est un mardi
const semaines = [[1, 6], [7, 13], [14, 20], [21, 27], [28, 30]];
const poidsMois = Array.from({ length: 30 }, (_, i) => poids[jourSem(i + 1)]).reduce((a, b) => a + b, 0);
let resteE = ESPECES;
const esp = semaines.map(([a, b], i) => {
  let w = 0; for (let d = a; d <= b; d++) { w += poids[jourSem(d)]; }
  const enc = i === semaines.length - 1 ? resteE : arr(ESPECES * w / poidsMois); resteE -= enc;
  let dep = Math.floor(enc / 1000) * 1000;              // des billets : la monnaie reste au fond de caisse
  if (i === 2) { dep -= 15000; }                         // la semaine à justifier
  return { a, b, j: b - a + 1, enc, dep, n: i === 4 ? 1 : 2, ecart: enc - dep, flag: i === 2 ? 'justifier' : (i === 4 ? 'octobre' : null) };
});
const espTot = esp.reduce((s, x) => ({ enc: s.enc + x.enc, dep: s.dep + x.dep, n: s.n + x.n, ecart: s.ecart + x.ecart, j: s.j + x.j }), { enc: 0, dep: 0, n: 0, ecart: 0, j: 0 });
const jj = d => String(d).padStart(2, '0') + '/09';

/* ---------- les textes, FR et NL ---------- */
const T = {
  fr: {
    lang: 'fr', type: 'Relevé de compensation', num: 'N° RC-2026-09-04 · 05/10/2026', suite: 'N° RC-2026-09-04 · suite',
    emetteur: 'Émetteur', dest: 'Destinataire',
    em: ['L’Atelier By — [raison sociale]', '[Rue et numéro]', '[Code postal] [Ville]', 'TVA BE 0XXX.XXX.XXX'],
    de: ['L’Atelier by Halle, magasin n° 4', '[Rue et numéro]', '1500 Halle', 'TVA BE 0XXX.XXX.XXX'],
    refsL: ['Période', 'Émis le', 'Prélèvement le', 'Base des redevances'],
    refsV: ['01/09 → 30/09/2026', '05/10/2026', '10/10/2026', eur(CA_HT) + ' HT'],
    ht: 'HT', tvaMot: 'TVA', plusTva: r => ' HT + TVA ' + r,
    red: { marque: 'Redevance de marque', assistance: 'Redevance d’assistance', marketing: 'Redevance marketing' },
    de2: (bp) => taux(bp) + ' de ' + eur(CA_HT) + ' HT',
    abo: 'Abonnement TFBuddy, septembre', liv: 'Livraisons de la centrale d’achat', livSub: '4 bons, semaines 36 à 39',
    av: 'Avoir — réclamation du 17/09', avSub: 'livraison non conforme, photo et code-barres',
    prime: 'Prime standards, T3 2026', primeSub: 'score 12,09 / 20, rang 2 sur 4 ; prime dès 14 / 20',
    colsA: ['Date', 'Pièce', 'Désignation', 'Débit', 'Crédit'],
    totDeb: 'Total des débits', totCred: 'Total des crédits', due: 'À prélever le 10/10/2026',
    secDoit: 'Ce que vous devez à la centrale', secDu: 'Ce que la centrale vous doit',
    colsB: ['Désignation', 'Base', 'Taux', 'HT', 'TVA', 'TTC'], sousTot: 'Sous-total',
    totHT: 'Total HT', tvaSur: (r, b) => 'TVA ' + r + ' sur ' + b,
    secC: 'Vos recettes et la compensation', colsC: ['', 'Désignation', 'Montant', 'Part des recettes'],
    cRec: 'Recettes du mois, TVA comprise', cRecSub: 'lues dans la caisse (TFBuddy) : ' + JOURS + ' jours, ' + groupe(String(TICKETS)) + ' tickets',
    cRed: 'Redevances, TVA comprise', cRedSub: '5 % de ' + eur(CA_HT) + ' HT : marque 2 %, assistance 1,5 %, marketing 1,5 %',
    cLiv: 'Livraisons de la centrale d’achat, TVA comprise', cAbo: 'Abonnement TFBuddy, TVA comprise',
    cAv: 'Avoir — réclamation du 17/09', cPrime: 'Prime standards, T3 2026', cReste: 'Recettes après compensation',
    barC: 'Pour la centrale', barV: 'Pour vous',
    cNote: 'Si la centrale encaissait vos recettes (modèle de reversement), elle vous verserait ' + eur(RESTE) + ' le 10/10/2026.',
    box: 'Prélèvement', boxK: ['Mode', 'Compte débité', 'Date', 'Communication'],
    boxV: ['Domiciliation SEPA B2B, mandat [n° de mandat]', 'BEXX XXXX XXXX XXXX — Neutralle SRL, compte de recettes', '10/10/2026', '+++XXX/XXXX/XXXXX+++'],
    explique: 'Vos recettes restent sur votre compte de recettes. Le 10 de chaque mois, la centrale prélève ce que vous lui devez, diminué de ce qu’elle vous doit ; un solde en votre faveur vous est versé le même jour. Une remarque sur ce relevé : avant le 8/10, à [adresse de la centrale].',
    foot: 'L’Atelier By — [raison sociale] · [adresse du siège] · N° d’entreprise BE 0XXX.XXX.XXX · RPM [ville] · IBAN BEXX XXXX XXXX XXXX',
    page: (n, N) => 'Page ' + n + ' / ' + N, illu: '* Maquette : montant d’illustration.',
    rec: 'Les recettes du mois', recSub: JOURS + ' jours de vente, ' + groupe(String(TICKETS)) + ' tickets, lus dans la caisse (TFBuddy)',
    colsR: ['Moyen de paiement', 'Arrive sur votre compte de recettes', 'Encaissé TTC', 'Part'],
    moy: { cartes: ['Cartes (Bancontact, Visa, Mastercard)', 'par le terminal de paiement'], pluxee: ['Pluxee, titres-repas', 'par Pluxee'],
      monizze: ['Monizze, titres-repas', 'par Monizze'], payconiq: ['Payconiq', 'par Payconiq'], especes: ['Espèces', 'par dépôt, voir ci-dessous'] },
    recTTC: 'Recettes TTC', recTVA: 'TVA 6 %', recHT: 'Chiffre d’affaires HT, base des redevances',
    esp: 'Les espèces : encaissé et déposé', colsE: ['Semaine', 'Jours', 'Encaissé', 'Déposé', 'Dépôts', 'Écart'],
    sem: (a, b) => 'du ' + jj(a) + ' au ' + jj(b), espJust: 'aucun retrait déclaré : à justifier avant le 10/10', espOct: 'déposé le 01/10',
    espTot: 'Septembre', espTxt: 'L’encaissé vient de la caisse, ticket par ticket ; le déposé, des dépôts déclarés sur votre compte de recettes. Un écart de moins de 10 € est la monnaie gardée au fond de caisse.',
  },
  nl: {
    lang: 'nl', type: 'Verrekeningsoverzicht', num: 'Nr. RC-2026-09-04 · 05/10/2026', suite: 'Nr. RC-2026-09-04 · vervolg',
    emetteur: 'Afzender', dest: 'Bestemmeling',
    em: ['L’Atelier By — [handelsnaam]', '[Straat en nummer]', '[Postcode] [Gemeente]', 'btw BE 0XXX.XXX.XXX'],
    de: ['L’Atelier by Halle, winkel nr. 4', '[Straat en nummer]', '1500 Halle', 'btw BE 0XXX.XXX.XXX'],
    refsL: ['Periode', 'Uitgegeven op', 'Domiciliëring op', 'Basis van de vergoedingen'],
    refsV: ['01/09 → 30/09/2026', '05/10/2026', '10/10/2026', eur(CA_HT) + ' excl. btw'],
    ht: 'excl. btw', tvaMot: 'btw', plusTva: r => ' excl. btw + btw ' + r,
    red: { marque: 'Merkvergoeding', assistance: 'Bijstandsvergoeding', marketing: 'Marketingvergoeding' },
    de2: (bp) => taux(bp) + ' van ' + eur(CA_HT) + ' excl. btw',
    abo: 'Abonnement TFBuddy, september', liv: 'Leveringen van de aankoopcentrale', livSub: '4 leveringsbonnen, weken 36 tot 39',
    av: 'Creditnota — klacht van 17/09', avSub: 'niet-conforme levering, foto en barcode',
    prime: 'Premie standaarden, K3 2026', primeSub: 'score 12,09 / 20, rang 2 van 4; premie vanaf 14 / 20',
    colsA: ['Datum', 'Stuk', 'Omschrijving', 'Debet', 'Credit'],
    totDeb: 'Totaal debet', totCred: 'Totaal credit', due: 'Te domiciliëren op 10/10/2026',
    secDoit: 'Wat u aan de centrale verschuldigd bent', secDu: 'Wat de centrale u verschuldigd is',
    colsB: ['Omschrijving', 'Basis', 'Tarief', 'Excl. btw', 'Btw', 'Incl. btw'], sousTot: 'Subtotaal',
    totHT: 'Totaal excl. btw', tvaSur: (r, b) => 'Btw ' + r + ' op ' + b,
    secC: 'Uw ontvangsten en de verrekening', colsC: ['', 'Omschrijving', 'Bedrag', 'Aandeel ontvangsten'],
    cRec: 'Ontvangsten van de maand, incl. btw', cRecSub: 'gelezen in de kassa (TFBuddy): ' + JOURS + ' dagen, ' + groupe(String(TICKETS)) + ' tickets',
    cRed: 'Vergoedingen, incl. btw', cRedSub: '5 % van ' + eur(CA_HT) + ' excl. btw: merk 2 %, bijstand 1,5 %, marketing 1,5 %',
    cLiv: 'Leveringen van de aankoopcentrale, incl. btw', cAbo: 'Abonnement TFBuddy, incl. btw',
    cAv: 'Creditnota — klacht van 17/09', cPrime: 'Premie standaarden, K3 2026', cReste: 'Ontvangsten na verrekening',
    barC: 'Voor de centrale', barV: 'Voor u',
    cNote: 'Als de centrale uw ontvangsten zou innen (doorstortingsmodel), zou ze u op 10/10/2026 ' + eur(RESTE) + ' storten.',
    box: 'Domiciliëring', boxK: ['Wijze', 'Rekening', 'Datum', 'Mededeling'],
    boxV: ['SEPA B2B-domiciliëring, mandaat [mandaatnummer]', 'BEXX XXXX XXXX XXXX — Neutralle SRL, ontvangstrekening', '10/10/2026', '+++XXX/XXXX/XXXXX+++'],
    explique: 'Uw ontvangsten blijven op uw ontvangstrekening. Op de 10e van elke maand domicilieert de centrale wat u haar verschuldigd bent, verminderd met wat zij u verschuldigd is; een saldo in uw voordeel wordt u dezelfde dag gestort. Een opmerking over dit overzicht: vóór 8/10, aan [adres van de centrale].',
    foot: 'L’Atelier By — [handelsnaam] · [adres van de zetel] · Ondernemingsnr. BE 0XXX.XXX.XXX · RPR [stad] · IBAN BEXX XXXX XXXX XXXX',
    page: (n, N) => 'Pagina ' + n + ' / ' + N, illu: '* Maquette: illustratief bedrag.',
    rec: 'De ontvangsten van de maand', recSub: JOURS + ' verkoopdagen, ' + groupe(String(TICKETS)) + ' tickets, gelezen in de kassa (TFBuddy)',
    colsR: ['Betaalmiddel', 'Komt op uw ontvangstrekening', 'Ontvangen incl. btw', 'Aandeel'],
    moy: { cartes: ['Kaarten (Bancontact, Visa, Mastercard)', 'via de betaalterminal'], pluxee: ['Pluxee, maaltijdcheques', 'via Pluxee'],
      monizze: ['Monizze, maaltijdcheques', 'via Monizze'], payconiq: ['Payconiq', 'via Payconiq'], especes: ['Contanten', 'via storting, zie hieronder'] },
    recTTC: 'Ontvangsten incl. btw', recTVA: 'Btw 6 %', recHT: 'Omzet excl. btw, basis van de vergoedingen',
    esp: 'De contanten: ontvangen en gestort', colsE: ['Week', 'Dagen', 'Ontvangen', 'Gestort', 'Stortingen', 'Verschil'],
    sem: (a, b) => 'van ' + jj(a) + ' tot ' + jj(b), espJust: 'geen opname aangegeven: te verantwoorden vóór 10/10', espOct: 'gestort op 01/10',
    espTot: 'September', espTxt: 'Het ontvangen bedrag komt uit de kassa, ticket per ticket; het gestorte bedrag uit de aangegeven stortingen op uw ontvangstrekening. Een verschil van minder dan 10 € is het wisselgeld dat in de kassa blijft.',
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
.cadre__d { font-size: 12.5px; color: #5c5852; margin: 0 0 14px; max-width: 794px; line-height: 1.5; }
.cadre__note { margin-top: 26px; font-size: 12px; color: #5c5852; line-height: 1.6; max-width: 1640px; }
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
.doc tr.sec td { padding: 14px 8px 4px; font-size: 8.5px; letter-spacing: .07em; text-transform: uppercase; color: #7a736a; border-bottom: 0; }
.doc__sec { font-size: 8.5px; letter-spacing: .07em; text-transform: uppercase; color: #7a736a; margin: 18px 0 2px; }
.doc__sec:first-child { margin-top: 0; }
.doc__totals { width: 46%; margin: 14px 0 0 auto; }
.doc__totals td { border: 0; padding: 4px 8px; }
.doc__totals .is-due td { padding-top: 9px; border-top: 1px solid #e6e0d8; font-size: 13px; font-weight: 700; color: #8D1D2C; }
.doc__box { border-left: 3px solid #8D1D2C; padding: 2px 0 2px 12px; margin: 24px 0 0; }
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
`;

const ast = '<span class="gris">*</span>';
const logo = `<img class="doc__logo" src="${ASSETS}/img/logo.png" alt="L’Atelier">`;
const tete = (t, sous) => `<div class="doc__head">${logo}<div class="doc__type"><b>${H(t.type)}</b>${H(sous)}</div></div>`;
const pied = (t, n) => `<div class="doc__illu">${H(t.illu)}</div><div class="doc__foot"><span>${H(t.foot)}</span><span>${H(t.page(n, 2))}</span></div>`;

function parties(t) {
  return `<div class="doc__parties">
    <div><div class="doc__label">${H(t.emetteur)}</div><p>${t.em.map(H).join('<br>')}</p></div>
    <div><div class="doc__label">${H(t.dest)}</div><p><b>Neutralle SRL</b><br>${t.de.map(H).join('<br>')}</p></div>
  </div>
  <div class="doc__refs">${t.refsL.map((l, i) => `<div><div class="doc__label">${H(l)}</div><div class="v">${H(t.refsV[i])}</div></div>`).join('')}</div>`;
}

function boite(t) {
  return `<div class="doc__box"><div class="doc__label">${H(t.box)}</div><table>${t.boxK.map((k, i) =>
    `<tr><td class="k">${H(k)}</td><td>${H(t.boxV[i])}</td></tr>`).join('')}</table></div>
  <p class="doc__texte">${H(t.explique)}</p>`;
}

/* Page 1, version A : le relevé de compte, une pièce par ligne. */
function pageA(t) {
  const L = [
    ...red.map((r, i) => ['30/09/2026', 'RD-2026-' + (412 + i) + ast, t.red[r.k], t.de2(r.bp) + ' · ' + eur(r.ht) + t.plusTva('21 %'), r.ttc, null]),
    ['01/09/2026', 'AB-2026-0904' + ast, t.abo + ast, eur(ABO.ht) + t.plusTva('21 %'), ABO.ttc, null],
    ['04 → 25/09', 'CA-2026-0388' + ast + '<span class="sub">→ 0391</span>', t.liv + ast, t.livSub + ' · ' + eur(LIV.ht) + t.plusTva('6 %'), LIV.ttc, null],
    ['19/09/2026', 'NC-2026-0057' + ast, t.av + ast, t.avSub + ' · ' + eur(AV.ht) + t.plusTva('6 %'), null, AV.ttc],
    ['30/09/2026', '—', t.prime, t.primeSub, null, PRIME],
  ];
  return `<div class="doc doc__page">${tete(t, t.num)}${parties(t)}
    <table><thead><tr><th style="width:80px">${t.colsA[0]}</th><th style="width:104px">${t.colsA[1]}</th><th>${t.colsA[2]}</th><th class="num">${t.colsA[3]}</th><th class="num">${t.colsA[4]}</th></tr></thead><tbody>
    ${L.map(([d, p, l, s, deb, cred]) => `<tr><td class="gris" style="white-space:nowrap">${H(d)}</td><td class="gris" style="white-space:nowrap">${p}</td><td><b>${l}</b><span class="sub">${H(s)}</span></td>
      <td class="num">${deb === null ? '' : eur(deb)}</td><td class="num">${cred === null ? '' : eur(cred)}</td></tr>`).join('')}
    </tbody></table>
    <table class="doc__totals">
      <tr><td>${H(t.totDeb)}</td><td class="num">${eur(DEBIT_TTC)}</td></tr>
      <tr><td>${H(t.totCred)}</td><td class="num">${eur(CREDIT_TTC)}</td></tr>
      <tr class="is-due"><td>${H(t.due)}</td><td class="num">${eur(SOLDE)}</td></tr>
    </table>
    ${boite(t)}${pied(t, 1)}</div>`;
}

/* Page 1, version B : la facture, HT, TVA et TTC par ligne. */
function pageB(t) {
  const ligne = (l, s, base, tx, ht, tva, ttc) => `<tr><td><b>${l}</b>${s ? `<span class="sub">${H(s)}</span>` : ''}</td>
    <td class="num gris">${base}</td><td class="num gris">${tx}</td><td class="num">${eur(ht)}</td><td class="num gris">${tva}</td><td class="num">${eur(ttc)}</td></tr>`;
  const tete2 = `<thead><tr><th>${t.colsB[0]}</th>${t.colsB.slice(1).map(c => `<th class="num">${c}</th>`).join('')}</tr></thead>`;
  return `<div class="doc doc__page">${tete(t, t.num)}${parties(t)}
    <table>${tete2}<tbody>
      <tr class="sec"><td colspan="6">${H(t.secDoit)}</td></tr>
      ${red.map(r => ligne(t.red[r.k], '', eur(CA_HT), taux(r.bp), r.ht, '21' + NBSP + '%', r.ttc)).join('')}
      ${ligne(t.abo + ast, '', '—', '—', ABO.ht, '21' + NBSP + '%', ABO.ttc)}
      ${ligne(t.liv + ast, t.livSub, '—', '—', LIV.ht, '6' + NBSP + '%', LIV.ttc)}
      <tr class="st"><td>${H(t.sousTot)}</td><td></td><td></td><td class="num">${eur(DEBIT_HT)}</td><td></td><td class="num">${eur(DEBIT_TTC)}</td></tr>
      <tr class="sec"><td colspan="6">${H(t.secDu)}</td></tr>
      ${ligne(t.av + ast, t.avSub, '—', '—', AV.ht, '6' + NBSP + '%', AV.ttc)}
      ${ligne(t.prime, '', '12,09 / 20', '≥' + NBSP + '14 / 20', PRIME, '—', PRIME)}
    </tbody></table>
    <table class="doc__totals">
      <tr><td>${H(t.totHT)}</td><td class="num">${eur(DEBIT_HT - CREDIT_HT)}</td></tr>
      <tr><td>${H(t.tvaSur('21' + NBSP + '%', eur(TVA21_BASE)))}</td><td class="num">${eur(TVA21)}</td></tr>
      <tr><td>${H(t.tvaSur('6' + NBSP + '%', eur(TVA6_BASE)))}</td><td class="num">${eur(TVA6)}</td></tr>
      <tr class="is-due"><td>${H(t.due)}</td><td class="num">${eur(SOLDE)}</td></tr>
    </table>
    ${boite(t)}${pied(t, 1)}</div>`;
}

/* Page 1, version C : la cascade, des recettes du mois à ce qui reste après la compensation. */
function pageC(t) {
  const part = c => pct(100 * c / TTC);
  const L = [
    ['', t.cRec + ast, t.cRecSub, TTC, '100' + NBSP + '%'],
    ['−', t.cRed, t.cRedSub, RED_TTC, part(RED_TTC)],
    ['−', t.cLiv + ast, t.livSub, LIV.ttc, part(LIV.ttc)],
    ['−', t.cAbo + ast, '', ABO.ttc, part(ABO.ttc)],
    ['+', t.cAv + ast, t.avSub, AV.ttc, part(AV.ttc)],
    ['+', t.cPrime, t.primeSub, PRIME, ''],
  ];
  const pc = 100 * SOLDE / TTC;
  return `<div class="doc doc__page">${tete(t, t.num)}${parties(t)}
    <div class="doc__sec">${H(t.secC)}</div>
    <table><thead><tr><th class="op"></th><th>${t.colsC[1]}</th><th class="num">${t.colsC[2]}</th><th class="num" style="width:120px">${t.colsC[3]}</th></tr></thead><tbody>
    ${L.map(([o, l, s, m, p]) => `<tr><td class="op">${o}</td><td><b>${l}</b>${s ? `<span class="sub">${H(s)}</span>` : ''}</td><td class="num">${eur(m)}</td><td class="num gris">${p}</td></tr>`).join('')}
    <tr class="reste"><td class="op">=</td><td><b>${H(t.cReste)}</b></td><td class="num">${eur(RESTE)}</td><td class="num gris">${part(RESTE)}</td></tr>
    </tbody></table>
    <div class="barre"><i style="width:${pc.toFixed(2)}%;background:#5c5852"></i><i style="flex:1;background:#EDE7DE"></i></div>
    <div class="barre__leg"><span>${H(t.barC)} · ${eur(SOLDE)} · ${pct(pc)}</span><span>${H(t.barV)} · ${eur(RESTE)} · ${pct(100 - pc)}</span></div>
    <table class="doc__totals">
      <tr class="is-due"><td>${H(t.due)}</td><td class="num">${eur(SOLDE)}</td></tr>
    </table>
    <p class="doc__texte" style="margin-left:auto;max-width:46%;text-align:right;margin-top:6px">${H(t.cNote)}</p>
    ${boite(t)}${pied(t, 1)}</div>`;
}

/* Page 2, commune : les recettes du mois et le contrôle des espèces. */
function page2(t) {
  return `<div class="doc doc__page">${tete(t, t.suite)}
    <div class="doc__sec">${H(t.rec)}</div>
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
    ${esp.map(x => `<tr><td><b>${H(t.sem(x.a, x.b))}</b>${x.flag === 'justifier' ? `<span class="sub">${H(t.espJust)}</span>` : x.flag === 'octobre' ? `<span class="sub">${H(t.espOct)}</span>` : ''}</td>
      <td class="num gris">${x.j}</td><td class="num">${eur(x.enc)}</td><td class="num">${eur(x.dep)}${ast}</td><td class="num gris">${x.n}</td><td class="num">${x.flag === 'justifier' ? `<b>${eur(x.ecart)}</b>` : `<span class="gris">${eur(x.ecart)}</span>`}</td></tr>`).join('')}
    <tr class="st"><td>${H(t.espTot)}</td><td class="num">${espTot.j}</td><td class="num">${eur(espTot.enc)}</td><td class="num">${eur(espTot.dep)}</td><td class="num">${espTot.n}</td><td class="num">${eur(espTot.ecart)}</td></tr>
    </tbody></table>
    <p class="doc__texte">${H(t.espTxt)}</p>
    ${pied(t, 2)}</div>`;
}

/* ---------- le cadre de présentation (comme docs/design-system/document-reference.png) ---------- */
const V = {
  a: { nom: 'Pièces', page: pageA, d: 'Le relevé de compte : chaque pièce sur une ligne, sa date, son numéro, au débit ou au crédit, puis le solde à prélever. Le plus proche d’un extrait bancaire.' },
  b: { nom: 'Facture', page: pageB, d: 'Ce que le magasin doit, puis ce que la centrale lui doit, avec HT, TVA et TTC par ligne et le récapitulatif de TVA : ce que demandera le comptable du franchisé.' },
  c: { nom: 'Cascade', page: pageC, d: 'Des recettes du mois à ce qui reste après la compensation, et la part de la centrale en barre : le relevé lu comme chez Żabka, en partant des recettes.' },
};
const NOTE = 'Ce qui est mesuré : le chiffre d’affaires HT (50 849,75 €), les jours et les tickets de Halle en septembre 2026 (dossier investisseur, lu le 09/10/2026), le score du T3 (12,09 / 20). '
  + 'Ce qui est calculé : les redevances, avec la grille du budget 2026 de Halle (2 % marque, 1,5 % assistance, 1,5 % marketing, sur le HT). '
  + 'Ce qui est d’illustration (*) : le TTC (6 % sur tout le chiffre), la répartition par moyen de paiement (celle de Corbais du 2 au 8/11/2026), les livraisons de la centrale, l’abonnement, l’avoir, les dépôts d’espèces et les numéros de pièce. '
  + 'Entre crochets : les mentions qui se lisent dans l’ERP (raison sociale, adresses, numéros, IBAN, mandat). Le seuil de la prime (14 / 20) est une hypothèse à décider.';

function cadre(v, lg) {
  const t = T[lg];
  return `<!doctype html><html lang="${t.lang}"><head><meta charset="utf-8"><title>Relevé de compensation — version ${v.toUpperCase()} (${lg.toUpperCase()})</title><style>${CSS}</style></head>
<body><div class="cadre">
  <div class="cadre__k">Version ${v.toUpperCase()} — ${V[v].nom} · ${lg === 'fr' ? 'français' : 'néerlandais'} · maquette, Halle, septembre 2026</div>
  <div class="cadre__rangee">
    <div><h3 class="cadre__t">Page 1 — la compensation</h3><p class="cadre__d">${H(V[v].d)}</p>${V[v].page(t)}</div>
    <div><h3 class="cadre__t">Page 2 — les recettes et les espèces</h3><p class="cadre__d">Commune aux trois versions : les recettes du mois par moyen de paiement, ce qui arrive sur le compte de recettes, et les espèces encaissées face aux dépôts, semaine par semaine.</p>${page2(t)}</div>
  </div>
  <p class="cadre__note">${H(NOTE)}</p>
</div></body></html>`;
}

for (const v of Object.keys(V)) {
  fs.writeFileSync(path.join(D, v + '.html'), cadre(v, 'fr'));
  fs.writeFileSync(path.join(D, v + '-nl.html'), cadre(v, 'nl'));
}

/* La planche : les trois pages 1 côte à côte. */
fs.writeFileSync(path.join(D, 'planche.html'), `<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Relevé de compensation — trois versions</title><style>${CSS}</style></head>
<body><div class="cadre">
  <div class="cadre__k">Relevé de compensation mensuel · Halle, septembre 2026 · trois versions de la page 1 · maquette</div>
  <div class="cadre__rangee">${Object.keys(V).map(v => `<div><h3 class="cadre__t">${v.toUpperCase()} — ${V[v].nom}</h3><p class="cadre__d">${H(V[v].d)}</p>${V[v].page(T.fr)}</div>`).join('')}</div>
  <p class="cadre__note">${H(NOTE)}</p>
</div></body></html>`);

console.log(JSON.stringify({
  ttc: eur(TTC), redevancesHT: eur(RED_HT), redevancesTTC: eur(RED_TTC), debits: eur(DEBIT_TTC), credits: eur(CREDIT_TTC),
  solde: eur(SOLDE), reste: eur(RESTE), partCentrale: pct(100 * SOLDE / TTC, 2),
  moyens: moyens.map(x => x.k + ' ' + eur(x.m)), especes: esp.map(x => [jj(x.a), eur(x.enc), eur(x.dep), eur(x.ecart)]),
}, null, 1));
