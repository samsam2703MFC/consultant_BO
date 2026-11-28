/* Mon coffre-fort (vue=coffre) : les routes /coffre/magasin* aux formes de consultant/src/coffre.php et coffre_dates.php,
 * lues par panel/public/dashboard/coffre.js (et par le rail : le nombre de nouveaux, reperes.nouveaux).
 * Le coffre du magasin de démo : 16 pièces attendues, 14 reçues (le bail et le permis d'environnement manquent),
 * l'attestation d'assurance RC à renouveler (échéance le 31/12/2026, dans 42 jours), deux nouveautés (le dossier
 * d'analyse d'octobre, le dernier rapport de visite). Les écritures changent l'état en mémoire : déposer le bail, puis
 * ranger les grandes dates que l'assistant propose (calculées comme cofBailDates : prise de cours le 01/06/2024, 9 ans,
 * préavis de 6 mois), se relit. Tout est fictif : noms, adresses, documents.
 * Pour les vidéos par onglet (26/11/2026) : l'assistant lit aussi le contrat de franchise et les attestations RC (la
 * nouvelle de 2027, pour « Renouveler ») ; « Télécharger » arrive en fichier (la page reste) ; l'envoi d'un e-mail qui
 * joint un document du coffre y note « joint à un mail » (70_boite.js répond à l'envoi) ; un contrôle AFSCA passé ;
 * demo.vider() pour le coffre vide. */
'use strict';

const AUJ = '2026-11-19', MAINTENANT = '2026-11-19 15:40';
const ONGLETS = [['contrat', 'Contrat & DIP'], ['legal', 'Légal'], ['formation', 'Formation'], ['visites', 'Rapports de visite'], ['finances', 'Finances'], ['courriers', 'Courriers & réunions']];
const SORTE_ONGLET = { dip: 'contrat', contrat_franchise: 'contrat', avenant: 'contrat', implantation: 'contrat', contrat: 'contrat', legal: 'legal', formation: 'formation', finances: 'finances', reunion: 'courriers', courrier: 'courriers', autre: 'courriers' };
const SORTE_LIB = { dip: 'DIP (document d’information précontractuelle)', contrat_franchise: 'Contrat de franchise', avenant: 'Annexe ou avenant au contrat', implantation: 'Dossier d’implantation',
  legal: 'Pièce légale (statuts, BCE, assurance, AFSCA, bail…)', formation: 'Formation (attestation, bilan, certificat…)', finances: 'Finances (dossier d’analyse, budget, redevances…)', reunion: 'Rapport de réunion', courrier: 'Courrier envoyé', autre: 'Autre document' };
const PIECES = [
  ['dip', 'contrat', 'DIP et accusé de réception', 'siege'], ['contrat', 'contrat', 'Contrat de franchise signé', 'siege'], ['annexes', 'contrat', 'Annexes (zone, cahier des charges)', 'siege'],
  ['implantation', 'contrat', 'Dossier d’implantation', 'siege'], ['statuts', 'legal', 'Statuts de la société', 'franchise'], ['bce', 'legal', 'Extrait de la BCE', 'franchise'],
  ['assurance_rc', 'legal', 'Attestation d’assurance RC exploitation', 'franchise'], ['assurance_incendie', 'legal', 'Assurance incendie', 'franchise'], ['bail', 'legal', 'Bail commercial', 'franchise'],
  ['afsca', 'legal', 'Autorisation AFSCA', 'franchise'], ['permis_env', 'legal', 'Permis d’environnement', 'franchise'], ['formation_initiale', 'formation', 'Attestation de formation initiale', 'siege'],
  ['formation_continue', 'formation', 'Formations continues validées', 'auto'], ['analyse', 'finances', 'Dossier d’analyse du mois', 'siege'], ['budget', 'finances', 'Budget de l’année', 'siege'],
  ['redevances', 'finances', 'Relevé des redevances', 'siege'],
];
const DATE_SORTES = { bail_preavis: 'Dernier jour pour donner congé (bail)', bail_triennat: 'Fin d’un triennat du bail', bail_renouvellement: 'Demande de renouvellement du bail', bail_fin: 'Fin du bail',
  bail_debut: 'Prise de cours du bail', bail_indexation: 'Indexation ou révision du loyer', contrat_fin: 'Fin du contrat de franchise', contrat_preavis: 'Préavis du contrat', assurance: 'Échéance d’assurance',
  autorisation: 'Autorisation, permis, contrôle', echeance: 'Échéance d’un document', autre: 'Autre grande date' };
const CONSULTANT = 'Le consultant', FRANCHISEE = 'Camille R.';
const EXT = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'jpg', 'jpeg', 'png', 'webp', 'heic', 'txt', 'csv', 'eml', 'msg'];

/* ── les dates ── */
const jours = (a, b) => Math.floor((Date.parse(b + 'T12:00:00Z') - Date.parse(a + 'T12:00:00Z')) / 864e5);
const dfr = d => d.slice(8, 10) + '/' + d.slice(5, 7) + '/' + d.slice(0, 4);
function mois(d, n) {   // cofMois : n mois plus tard, un 31 devient le dernier jour d'un mois plus court
  const [y, m, j] = d.split('-').map(Number), t = y * 12 + (m - 1) + n, y2 = Math.floor(t / 12), m2 = t % 12 + 1;
  const der = new Date(Date.UTC(y2, m2, 0)).getUTCDate();
  return y2 + '-' + String(m2).padStart(2, '0') + '-' + String(Math.min(j, der)).padStart(2, '0');
}
const jour = (d, n) => { const x = new Date(d + 'T12:00:00Z'); x.setUTCDate(x.getUTCDate() + n); return x.toISOString().slice(0, 10); };
/** cofEtat : l'état d'un document d'après son échéance (à renouveler 60 jours avant). */
function etatDoc(e) {
  if (!e) { return { cle: 'depose', texte: 'déposé', jours: null }; }
  const j = jours(AUJ, e);
  if (j < 0) { return { cle: 'expiree', texte: 'échue depuis ' + (-j) + ' j', jours: j }; }
  if (j <= 60) { return { cle: 'renouveler', texte: 'à renouveler · ' + j + ' j', jours: j }; }
  return { cle: 'valide', texte: 'valide', jours: j };
}
/** cofDateEtat : passée, en cours (dans sa fenêtre), bientôt (≤ 120 jours), à venir. */
function etatDate(le, fin) {
  const j = jours(AUJ, le);
  if ((fin || le) < AUJ) { return { cle: 'passee', jours: j }; }
  if (fin && le <= AUJ) { return { cle: 'encours', jours: jours(AUJ, fin) }; }
  return { cle: j <= 120 ? 'bientot' : 'avenir', jours: j };
}
/** cofBailDates : les grandes dates d'un bail commercial, calculées depuis sa prise de cours. */
function bailDates(debut, ans = 9, preavis = 6) {
  const base = 'Calculé depuis la prise de cours du ' + dfr(debut) + ' (' + ans + ' ans) ; règle générale des baux commerciaux, à vérifier dans le bail.';
  const apres = mois(debut, 12 * ans);
  const out = [{ sorte: 'bail_debut', titre: 'Prise de cours du bail', le: debut, fin: null, note: 'Durée : ' + ans + ' ans.' }];
  for (let k = 3, n = 1; k < ans; k += 3, n++) {
    const exp = mois(debut, 12 * k), finTri = jour(exp, -1), rang = n === 1 ? '1er' : n + 'e';
    out.push({ sorte: 'bail_preavis', titre: 'Dernier jour pour donner congé — ' + rang + ' triennat', le: jour(mois(exp, -preavis), -1), fin: null,
      note: 'Un congé de ' + preavis + ' mois (recommandé ou exploit d’huissier), donné au plus tard ce jour, met fin au bail le ' + dfr(finTri) + '. ' + base });
    out.push({ sorte: 'bail_triennat', titre: 'Fin du ' + rang + ' triennat', le: finTri, fin: null, note: base });
  }
  out.push({ sorte: 'bail_renouvellement', titre: 'Demande de renouvellement du bail', le: mois(apres, -18), fin: mois(apres, -15),
    note: 'Le preneur demande le renouvellement entre le 18e et le 15e mois avant la fin du bail (recommandé ou exploit d’huissier) ; sans demande, le bail prend fin à son terme. ' + base });
  out.push({ sorte: 'bail_fin', titre: 'Fin du bail', le: jour(apres, -1), fin: null, note: base });
  return out.sort((a, b) => a.le.localeCompare(b.le));
}

/* ── l'état du coffre (en mémoire, le temps d'un tournage) ── */
const trace = (le, qui, role, comment) => ({ qui, role, comment, le });
function depart() {
  const d = (id, sorte, titre, date, o) => Object.assign({ id, sorte, titre, date, echeance: null, note: null, deposePar: CONSULTANT, deposeLe: date + ' 10:12', deposeRole: 'siege',
    nom: titre.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') + '.pdf', mime: 'application/pdf', octets: 420000, piece: null, vu: true, traces: [] }, o || {});
  const docs = [
    d(101, 'dip', 'DIP et accusé de réception', '2024-01-15', { piece: 'dip', octets: 2310000 }),
    d(102, 'contrat_franchise', 'Contrat de franchise signé', '2024-03-01', { piece: 'contrat', echeance: '2033-02-28', octets: 1860000 }),
    d(103, 'avenant', 'Annexes : zone et cahier des charges', '2024-03-01', { piece: 'annexes', octets: 980000 }),
    d(104, 'implantation', 'Dossier d’implantation', '2024-04-10', { piece: 'implantation', octets: 5400000 }),
    d(110, 'legal', 'Statuts de la société', '2023-11-20', { piece: 'statuts', deposePar: FRANCHISEE, deposeRole: 'franchise', octets: 640000 }),
    d(111, 'legal', 'Extrait de la BCE', '2024-02-05', { piece: 'bce', deposePar: FRANCHISEE, deposeRole: 'franchise', octets: 180000 }),
    d(112, 'legal', 'Attestation d’assurance RC exploitation 2026', '2025-12-15', { piece: 'assurance_rc', echeance: '2026-12-31', deposePar: FRANCHISEE, deposeRole: 'franchise', octets: 260000,
      deposeLe: '2025-12-16 08:47', traces: [trace('2026-11-12 09:31', CONSULTANT, 'siege', 'ouvert'), trace('2026-10-03 18:05', FRANCHISEE, 'franchise', 'joint à un mail'), trace('2026-06-22 11:14', FRANCHISEE, 'franchise', 'telecharge')] }),
    d(113, 'legal', 'Assurance incendie : police 2026-2027', '2026-03-28', { piece: 'assurance_incendie', echeance: '2027-03-31', deposePar: FRANCHISEE, deposeRole: 'franchise', octets: 1120000 }),
    d(114, 'legal', 'Autorisation AFSCA', '2024-05-28', { piece: 'afsca', deposePar: FRANCHISEE, deposeRole: 'franchise', octets: 310000 }),
    d(120, 'formation', 'Attestation de formation initiale', '2024-05-20', { piece: 'formation_initiale', octets: 230000 }),
    d(121, 'formation', 'Formation continue : hygiène et HACCP', '2026-10-14', { piece: 'formation_continue', deposePar: 'Formation continue', octets: 150000 }),
    d(130, 'finances', 'Dossier d’analyse : octobre 2026', '2026-11-12', { piece: 'analyse', vu: false, octets: 1480000, deposeLe: '2026-11-12 16:20' }),
    d(131, 'finances', 'Budget 2026', '2026-01-20', { piece: 'budget', mime: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', nom: 'budget-2026.xlsx', octets: 96000 }),
    d(132, 'finances', 'Relevé des redevances : octobre 2026', '2026-11-05', { piece: 'redevances', octets: 120000, deposeLe: '2026-11-05 11:02', traces: [trace('2026-11-06 07:58', FRANCHISEE, 'franchise', 'ouvert')] }),
    d(140, 'reunion', 'Compte rendu : réunion des franchisés d’octobre', '2026-10-22', { octets: 540000 }),
    d(141, 'courrier', 'Courrier : horaires de livraison de l’hiver', '2026-11-03', { octets: 90000, traces: [trace('2026-11-04 08:12', FRANCHISEE, 'franchise', 'ouvert')] }),
  ];
  const visites = [
    { id: 301, onglet: 'visites', le: '2026-11-17', type: 'Visite régulière', typeCode: 'reguliere', consultant: CONSULTANT, envoyeLe: '2026-11-18 09:12', envoyePar: CONSULTANT, luLe: null },
    { id: 300, onglet: 'visites', le: '2026-10-20', type: 'Visite régulière', typeCode: 'reguliere', consultant: CONSULTANT, envoyeLe: '2026-10-21 10:05', envoyePar: CONSULTANT, luLe: '2026-10-21 18:40' },
    { id: 299, onglet: 'visites', le: '2026-09-15', type: 'Audit qualité', typeCode: 'audit', consultant: CONSULTANT, envoyeLe: '2026-09-16 08:30', envoyePar: CONSULTANT, luLe: '2026-09-16 12:02' },
  ];
  // Les grandes dates rangées (con_coffre_date) ; les échéances des documents s'y ajoutent d'office. Le contrôle de
  // février 2026 est passé : il se replie sous « ▸ 1 date passée » (vidéos par onglet, 26/11/2026).
  const dates = [{ id: 50, docId: null, sorte: 'autorisation', titre: 'Contrôle AFSCA annuel', le: '2026-02-10', fin: null, note: 'Fait : aucune remarque.', source: 'manuel', par: FRANCHISEE, role: 'franchise' },
    { id: 51, docId: null, sorte: 'autorisation', titre: 'Contrôle AFSCA annuel', le: '2027-02-10', fin: null, note: 'Préparer le registre des températures et les fiches de traçabilité.', source: 'manuel', par: FRANCHISEE, role: 'franchise' }];
  return { docs, visites, dates, exclues: {}, prochainId: 150, prochaineDate: 60, tic: 1 };
}
let E = null;
const etat = () => E || (E = depart());
/** L'heure d'une trace du tournage : 15:40 à l'écran, des secondes qui avancent pour que la plus récente passe en tête. */
const maintenant = () => MAINTENANT + ':' + String(etat().tic++).padStart(2, '0');

/* ── les lectures, aux formes du serveur ── */
const pieceNom = k => { const p = PIECES.find(x => x[0] === k); return p ? p[2] : null; };
function ligne(x) {   // cofLigne
  const traces = x.traces.slice().sort((a, b) => b.le.localeCompare(a.le)).slice(0, 5).map(t => Object.assign({}, t));
  return { id: x.id, onglet: SORTE_ONGLET[x.sorte] || 'courriers', sorte: x.sorte, sorteLib: SORTE_LIB[x.sorte] || 'Autre document', titre: x.titre, note: x.note, date: x.date,
    echeance: x.echeance, etat: etatDoc(x.echeance), deposePar: x.deposePar, deposeLe: x.deposeLe, deposeRole: x.deposeRole, nom: x.nom, mime: x.mime, octets: x.octets,
    nouveau: !x.vu && x.deposeRole !== 'franchise', traces, piece: x.piece, pieceNom: x.piece ? pieceNom(x.piece) : null };
}
function pieces(docs) {   // cofPieces
  const S = etat(), par = {};
  docs.forEach(d => { if (d.piece) { (par[d.piece] = par[d.piece] || []).push(d); } });
  let a = 0, b = 0; const on = {};
  const liste = PIECES.map(([k, onglet, nom, qui]) => {
    const ds = (par[k] || []).slice().sort((x, y) => String(y.date || '').localeCompare(String(x.date || '')) || y.id - x.id), d = ds[0] || null;
    const ex = S.exclues[k] || null;
    const e = d ? ({ renouveler: 'renouveler', expiree: 'expiree' }[d.etat.cle] || 'recue') : (ex ? 'exclue' : 'manquante');
    if (e !== 'exclue') { b++; on[onglet] = on[onglet] || { a: 0, b: 0 }; on[onglet].b++; if (d) { a++; on[onglet].a++; } }
    return { cle: k, onglet, nom, qui, etat: e, n: ds.length, doc: d ? { id: d.id, titre: d.titre, date: d.date, echeance: d.echeance, etat: d.etat, deposePar: d.deposePar } : null, exclue: d ? null : ex };
  });
  return { liste, completude: { a, b }, parOnglet: on };
}
function datesListe() {   // cofDatesListe
  const S = etat(), out = [], reprises = {};
  S.dates.forEach(r => {
    const doc = r.docId ? S.docs.find(x => x.id === r.docId) : null;
    if (doc) { (reprises[doc.id] = reprises[doc.id] || []).push(r.le); if (r.fin) { reprises[doc.id].push(r.fin); } }
    out.push({ id: r.id, sorte: r.sorte, sorteLib: DATE_SORTES[r.sorte] || DATE_SORTES.autre, titre: r.titre, le: r.le, fin: r.fin, note: r.note, source: r.source,
      docId: doc ? doc.id : null, docTitre: doc ? doc.titre : null, docCoffre: !!doc, par: r.par, role: r.role, etat: etatDate(r.le, r.fin), modifiable: r.role === 'franchise' });
  });
  S.docs.forEach(d => {
    if (!d.echeance || (reprises[d.id] || []).includes(d.echeance)) { return; }
    const c = d.sorte === 'contrat_franchise';
    out.push({ id: null, sorte: c ? 'contrat_fin' : 'echeance', sorteLib: c ? DATE_SORTES.contrat_fin : DATE_SORTES.echeance, titre: 'Échéance — ' + d.titre, le: d.echeance, fin: null,
      note: 'L’échéance posée sur le document.', source: 'document', docId: d.id, docTitre: d.titre, docCoffre: true, par: '', role: '', etat: etatDate(d.echeance, null), modifiable: false });
  });
  return out.sort((a, b) => a.le.localeCompare(b.le) || String(a.fin || '').localeCompare(String(b.fin || '')));
}
function coffre() {   // ep_coffre_magasin
  const S = etat(), docs = S.docs.slice().sort((a, b) => String(b.date).localeCompare(String(a.date)) || b.id - a.id).map(ligne);
  const n = {}; ONGLETS.forEach(([k]) => { n[k] = 0; }); docs.forEach(d => { n[d.onglet]++; }); n.visites += S.visites.length;
  const onglets = [{ cle: 'tout', nom: 'Tout', n: docs.length + S.visites.length }].concat(ONGLETS.map(([k, nom]) => ({ cle: k, nom, n: n[k] })));
  const contrat = docs.find(d => d.sorte === 'contrat_franchise');
  const L = datesListe().filter(d => d.etat.cle !== 'passee'), p = L[0] || null;
  return { shop: '4', nom: 'Magasin démo', onglets, documents: docs, visites: S.visites.map(v => Object.assign({}, v)),
    reperes: { nouveaux: docs.filter(d => d.nouveau).length + S.visites.filter(v => v.luLe === null).length,
      aRenouveler: docs.filter(d => ['renouveler', 'expiree'].includes(d.etat.cle)).map(d => ({ id: d.id, titre: d.titre, echeance: d.echeance, etat: d.etat })),
      contrat: contrat ? { titre: contrat.titre, echeance: contrat.echeance, date: contrat.date } : null,
      dates: { n: L.length, bientot: L.filter(d => ['bientot', 'encours'].includes(d.etat.cle)).length, prochaine: p ? { titre: p.titre, le: p.le, fin: p.fin, etat: p.etat, sorteLib: p.sorteLib } : null } },
    pieces: pieces(docs), suivi: '../suivi/?magasin=demo', depot: { sorte: 'legal', maxOctets: 25 * 1048576, extensions: EXT } };
}

/* ── l'aperçu d'un document : une page fictive, dessinée ── */
function apercu(id) {
  const x = etat().docs.find(d => d.id === +id);
  if (!x) { return { __status: 404, json: { error: 'document introuvable' } }; }
  const lignes = (n, w) => Array.from({ length: n }, (_, i) => `<i style="width:${w - (i * 7) % 23}%"></i>`).join('');
  const corps = x.piece === 'assurance_rc'
    ? `<p class="t">Assurances Exemple SA · Responsabilité civile exploitation</p><h1>Attestation d’assurance</h1><p>Nous attestons que la société exploitant le <b>Magasin démo</b> est couverte en responsabilité civile exploitation par la police n° RC-000 000.</p><p>Période de validité : du <b>01/01/2026</b> au <b>31/12/2026</b>.</p>${lignes(6, 96)}<p>Fait pour servir et valoir ce que de droit.</p>`
    : `<p class="t">${x.titre}</p><h1>${x.titre}</h1>${lignes(14, 94)}`;
  const html = `<!doctype html><html><head><meta charset="utf-8"><style>
    body{margin:0;background:#e9e5df;font-family:Georgia,serif;color:#222}.pg{background:#fff;margin:14px auto;width:86%;min-height:120vh;padding:36px 40px;box-sizing:border-box}
    .t{font:12px system-ui,sans-serif;color:#777;letter-spacing:.04em;text-transform:uppercase;border-bottom:1px solid #ddd;padding-bottom:10px}h1{font-size:22px;margin:22px 0 16px}
    p{font-size:14px;line-height:1.6}i{display:block;height:8px;background:#ebe7e1;border-radius:3px;margin:11px 0}</style></head><body><div class="pg">${corps}</div></body></html>`;
  return { __body: html, type: 'text/html' };
}

/* ── les écritures ── */
function deposer(corps) {   // wr_coffre_document (multipart : le titre, la date, l'échéance, la pièce et le nom du fichier)
  const S = etat(), s = typeof corps === 'string' ? corps : '';
  const champ = k => { const m = s.match(new RegExp('name="' + k + '"\\r?\\n\\r?\\n([^\\r\\n]*)')); return m ? m[1] : ''; };
  const fm = s.match(/filename="([^"]+)"/), nom = fm ? fm[1] : 'document.pdf';
  const piece = PIECES.some(p => p[0] === champ('piece') && p[1] === 'legal') ? champ('piece') : null;
  const id = S.prochainId++;
  const x = { id, sorte: 'legal', titre: champ('titre') || nom.replace(/\.[^.]+$/, ''), date: /^\d{4}-\d{2}-\d{2}$/.test(champ('date')) ? champ('date') : AUJ,
    echeance: /^\d{4}-\d{2}-\d{2}$/.test(champ('echeance')) ? champ('echeance') : null, note: champ('note') || null, deposePar: FRANCHISEE, deposeLe: MAINTENANT, deposeRole: 'franchise',
    nom, mime: /\.pdf$/i.test(nom) ? 'application/pdf' : 'application/octet-stream', octets: 1265000, piece, vu: true, traces: [] };
  S.docs.push(x);
  return { ok: true, ajoutes: [ligne(x)], refuses: [] };
}
/** Ce que l'assistant lit dans un document de démo (la réponse du modèle, $j de cofAssistantPropositions) : le contrat
 * de franchise, une attestation d'assurance RC (celle de 2026, ou la nouvelle de 2027 déposée pour la renouveler), et
 * par défaut le bail commercial (ses dates calculées depuis la prise de cours, comme cofBailDates). */
function lecture(x) {
  const P = (sorte, titre, le, extrait, page, fin) => ({ sorte, titre, le, fin: fin || null, note: null, source: 'assistant', extrait, page });
  if (x.sorte === 'contrat_franchise') {
    return { genre: 'contrat_franchise', titre: 'Contrat de franchise L’Atelier By', piece: null, echeance: '2033-02-28',
      resume: 'Contrat de franchise de neuf ans, qui prend effet le 1er mars 2024 et prend fin le 28 février 2033. Il se reconduit sauf opposition notifiée au plus tard douze mois avant son terme.',
      remarques: 'La reconduction renvoie aux conditions de l’article 14 : à relire avec votre consultant.',
      dates: [P('autre', 'Prise d’effet du contrat', '2024-03-01', 'Le présent contrat prend effet le 1er mars 2024 pour une durée de neuf ans.', 2),
        P('contrat_preavis', 'Dernier jour pour s’opposer à la reconduction', '2032-02-28', 'Chaque partie peut s’opposer à sa reconduction par lettre recommandée, au plus tard douze mois avant son terme.', 11),
        P('contrat_fin', 'Fin du contrat de franchise', '2033-02-28', 'Le présent contrat prend effet le 1er mars 2024 pour une durée de neuf ans.', 2)] };
  }
  if (x.piece === 'assurance_rc') {
    const an = x.id === 112 ? '2026' : '2027', fin = an + '-12-31';
    return { genre: 'assurance', titre: 'Attestation d’assurance RC exploitation ' + an, piece: 'assurance_rc', echeance: fin,
      resume: 'Attestation de la responsabilité civile exploitation du Magasin démo (police n° RC-000 000), valable du 1er janvier au 31 décembre ' + an + '.', remarques: '',
      dates: [P('assurance', 'Échéance de l’assurance RC exploitation', fin, 'Période de validité : du 01/01/' + an + ' au 31/12/' + an + '.', 1)] };
  }
  const extrait = 'Le présent bail est consenti pour une durée de neuf années, prenant cours le 1er juin 2024. Le preneur pourra y mettre fin à l’expiration de chaque triennat, moyennant un préavis de six mois.';
  return { genre: 'bail_commercial', titre: 'Bail commercial de la boutique', piece: 'bail', echeance: '2033-05-31', remarques: '',
    resume: 'Bail commercial de neuf ans pour la boutique, qui prend cours le 1er juin 2024. Le preneur peut y mettre fin à chaque triennat avec un préavis de six mois ; le loyer est indexé chaque année.',
    calcul: bailDates('2024-06-01').map(d => Object.assign(d, { source: 'calcul', extrait, page: null })),
    dates: [P('bail_indexation', 'Indexation du loyer', '2027-06-01', 'Le loyer sera indexé chaque année à la date anniversaire de la prise de cours du bail.', 3)] };
}
const GENRES = { bail_commercial: 'Bail commercial', contrat_franchise: 'Contrat de franchise', assurance: 'Police d’assurance', autre: 'Autre document' };
function assistant(c) {   // wr_coffre_assistant puis cofAssistantPropositions, pour les documents du magasin de démo
  const S = etat(), x = S.docs.find(d => d.id === +(c && c.id));
  if (!x) { return { __status: 404, json: { error: 'document introuvable dans ce coffre' } }; }
  const deja = S.dates.filter(r => r.docId === x.id).map(r => r.sorte + '|' + r.le);
  const j = lecture(x), props = (j.calcul || []).concat(j.dates);
  const vus = {};
  const propositions = props.filter(p => { const k = p.sorte + '|' + p.le; if (vus[k]) { return false; } vus[k] = true; return true; }).map(p => {
    const k = p.sorte + '|' + p.le, dj = deja.includes(k);
    return Object.assign(p, { sorteLib: DATE_SORTES[p.sorte], deja: dj, coche: !dj && (p.fin || p.le) >= AUJ, cle: 'p' + p.sorte.replace(/[^a-z]/g, '').slice(0, 8) + p.le.replace(/-/g, ''), etat: etatDate(p.le, p.fin) });
  }).sort((a, b) => a.le.localeCompare(b.le));
  const piece = j.piece ? PIECES.find(p => p[0] === j.piece) : null;
  const sansExt = x.nom.replace(/\.[^.]+$/, '');
  return { ok: true, docId: x.id, nom: x.nom, docTitre: x.titre, genre: j.genre, genreLib: GENRES[j.genre], titre: j.titre, resume: j.resume,
    pieceSuggeree: piece ? { cle: piece[0], nom: piece[2], onglet: piece[1] } : null, remarques: j.remarques, propositions,
    echeanceDoc: j.echeance && j.echeance !== (x.echeance || '') ? { actuelle: x.echeance || null, proposee: j.echeance } : null,
    // Le titre : proposé quand le document porte le nom de son fichier (cofAssistantPropositions).
    titreDoc: j.titre !== x.titre && (x.titre === sansExt || !x.titre) ? { actuel: x.titre, propose: j.titre } : null, modele: 'démo' };
}
function ranger(c) {   // wr_coffre_dates
  const S = etat(); let n = 0; const maj = [];
  (c.dates || []).slice(0, 30).forEach(d => {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(d.le || '') || !String(d.titre || '').trim()) { return; }
    const docId = d.docId && S.docs.some(x => x.id === +d.docId) ? +d.docId : null, sorte = DATE_SORTES[d.sorte] ? d.sorte : 'autre';
    if (S.dates.some(r => r.sorte === sorte && r.le === d.le && r.docId === docId)) { return; }
    S.dates.push({ id: S.prochaineDate++, docId, sorte, titre: String(d.titre).trim(), le: d.le, fin: d.fin && d.fin >= d.le ? d.fin : null, note: d.note || null,
      source: ['assistant', 'calcul'].includes(d.source) ? d.source : 'manuel', par: FRANCHISEE, role: 'franchise' });
    n++;
  });
  const doc = id => S.docs.find(x => x.id === +id);
  if (c.echeanceDoc && doc(c.echeanceDoc.docId)) { doc(c.echeanceDoc.docId).echeance = c.echeanceDoc.le; maj.push('échéance'); }
  if (c.pieceDoc && doc(c.pieceDoc.docId)) { doc(c.pieceDoc.docId).piece = c.pieceDoc.piece || null; maj.push('pièce'); }
  if (c.titreDoc && doc(c.titreDoc.docId)) { doc(c.titreDoc.docId).titre = String(c.titreDoc.titre).trim(); maj.push('titre'); }
  return { ok: true, ajoutees: n, refusees: [], document: maj, dates: datesListe() };
}

module.exports = function (m, p, q, c) {
  // « Joindre à un e-mail » : l'e-mail part par la Boîte mail (70_boite.js répond) ; le coffre note « joint à un mail »
  // sur chaque document joint, comme le serveur (consultant/src/coffre.php). On ne répond pas : 70_boite.js le fait.
  if (m === 'POST' && p === '/dashboard/boite/envoyer' && c && Array.isArray(c.coffre) && c.coffre.length
    && String(c.a || '').split(',').every(x => /[^@\s<]+@[^@\s>]+\.[a-z]{2,}/i.test(x))) {
    c.coffre.forEach(id => { const x = etat().docs.find(d => d.id === +id); if (x) { x.traces.push(trace(maintenant(), FRANCHISEE, 'franchise', 'mail')); } });
    return undefined;
  }
  if (!p.startsWith('/coffre/magasin')) { return undefined; }
  const S = etat();
  if (m === 'GET' && p === '/coffre/magasin') { return coffre(); }
  if (m === 'GET' && p === '/coffre/magasin/dates') {
    return { shop: '4', aujourdhui: AUJ, bientotJours: 120, sortes: Object.entries(DATE_SORTES).map(([v, nom]) => ({ v, nom })), dates: datesListe() };
  }
  if (m === 'GET' && p === '/coffre/magasin/fichier') {
    const x = S.docs.find(d => d.id === +q.get('id'));
    if (x && !q.get('apercu')) { x.traces.push(trace(maintenant(), FRANCHISEE, 'franchise', q.get('telecharger') ? 'telecharge' : 'ouvert')); x.vu = true; }
    // « Télécharger » : le serveur l'envoie en pièce à enregistrer (Content-Disposition: attachment) ; la fausse API ne
    // pose pas d'en-tête, un type que le navigateur n'affiche pas fait le même effet : le fichier arrive, la page reste.
    if (x && q.get('telecharger')) { return { __body: '%PDF-1.4\n% ' + x.titre + ' (document fictif)\n', type: 'application/octet-stream' }; }
    return apercu(q.get('id'));
  }
  if (m === 'POST' && p === '/coffre/magasin/document') { return deposer(c); }
  if (m === 'POST' && p === '/coffre/magasin/assistant') { return assistant(c); }
  if (m === 'POST' && p === '/coffre/magasin/dates') { return ranger(c || {}); }
  if (m === 'DELETE' && p === '/coffre/magasin/dates') { S.dates = S.dates.filter(r => !(r.id === +q.get('id') && r.role === 'franchise')); return { ok: true, dates: datesListe() }; }
  if (m === 'POST' && p === '/coffre/magasin/piece') {
    if (c && c.exclue) { S.exclues[c.piece] = { par: FRANCHISEE, le: AUJ }; } else if (c) { delete S.exclues[c.piece]; }
    return { ok: true, pieces: pieces(S.docs.map(ligne)) };
  }
  return undefined;
};
/** Pour un scénario, hors film : le coffre d'un magasin qui n'y a encore rien (23-10-vide). Le module est celui que
 * mock.js vient de charger (require le garde en cache) : require(<ce fichier>).demo.vider(). */
module.exports.demo = { vider() { E = Object.assign(depart(), { docs: [], visites: [], dates: [] }); } };
