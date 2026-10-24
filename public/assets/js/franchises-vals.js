/* Cockpit CEO — valeurs des écrans « Franchisés · évaluation et suivi » (09/10/2026).
 * Fiche franchisé (/franchises, /franchises/fiche), Contrôles qualité du mois (/controles/qualite).
 * Gestion consultant est retirée (18/10/2026).
 * Chaque fonction reçoit l'application (état, setState, api) et remplit `common`. */
import { readOne, write, API_BASE } from './api.js';

const MOIS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
const JC = ['dim', 'lun', 'mar', 'mer', 'jeu', 'ven', 'sam'];
const auj = () => { const d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); };
const fmtD = d => d ? d.slice(8, 10) + '/' + d.slice(5, 7) : '';
const fmtDJ = d => { if (!d) { return ''; } const t = new Date(d + 'T12:00:00'); return JC[t.getDay()] + ' ' + fmtD(d); };
const plusJours = (d, n) => { const t = new Date(d + 'T12:00:00'); t.setDate(t.getDate() + n); return t.getFullYear() + '-' + String(t.getMonth() + 1).padStart(2, '0') + '-' + String(t.getDate()).padStart(2, '0'); };
const decaleMois = (m, n) => { const t = new Date(+m.slice(0, 4), +m.slice(5, 7) - 1 + n, 1); return t.getFullYear() + '-' + String(t.getMonth() + 1).padStart(2, '0'); };
const nf = (n, dd) => n == null ? '—' : Number(n).toLocaleString('fr-BE', { minimumFractionDigits: dd || 0, maximumFractionDigits: dd || 0 });
const pl = (n, u, p) => nf(n) + ' ' + u + (n > 1 ? (p || 's') : '');
const racine = () => API_BASE.replace(/api\/cockpit\/?$/, '');

/* --- Fiche franchisé --------------------------------------------------------------- */
export function ffTableCharge(app, force){
  const b = app.state.ffTable;
  if (!force && b && (b.d || b.chargement)) { return; }
  if (app._ffTableEnCours && !force) { return; }
  app._ffTableEnCours = true;
  app.setState({ ffTable: { chargement: true, d: b ? b.d : null } });
  readOne('/franchises?_cache=600' + (force ? '&rafraichir=1' : '')).then(d => { app._ffTableEnCours = false; app.setState({ ffTable: { chargement: false, d: d || { error: 'injoignable' } } }); });
}
export function ffCharge(app, shop, force){
  const b = app.state.ff;
  if (!shop) { return; }
  if (!force && b && b.shop === shop && (b.d || b.chargement)) { return; }
  if (app._ffEnCours === shop && !force) { return; }
  app._ffEnCours = shop;
  app.setState({ ff: { shop, chargement: true, d: b && b.shop === shop ? b.d : null } });
  readOne('/franchises/fiche?shop=' + encodeURIComponent(shop) + '&_cache=300' + (force ? '&rafraichir=1' : '')).then(d => { app._ffEnCours = null; app.setState({ ff: { shop, chargement: false, d: d || { error: 'injoignable' } } }); });
}
/* L'onglet Documents (22/10/2026, Sam) : les documents du magasin — rapports de réunion, contrats de mise à
 * disposition, le reste — déposés ici, lus par l'API (le dossier des fichiers est fermé à l'accès direct). */
export function ffDocsCharge(app, shop, force){
  const b = app.state.ffDocs;
  if (!shop || (!force && b && b.shop === shop && (b.d || b.chargement))) { return; }
  app.setState({ ffDocs: { shop, chargement: true, d: b && b.shop === shop ? b.d : null } });
  readOne('/franchises/documents?shop=' + encodeURIComponent(shop)).then(d => app.setState({ ffDocs: { shop, chargement: false, d: d || { error: 'injoignable' } } }));
}
function valsFFDocs(app, f, shop){
  const S = app.state;
  ffDocsCharge(app, shop, false);
  const B = S.ffDocs || {}, D = B.shop === shop && B.d && !B.d.error ? B.d : null;
  const F = Object.assign({ sorte: 'reunion', titre: '', date: auj(), note: '' }, S.ffDocForm || {});
  const maj = k => e => app.setState({ ffDocForm: Object.assign({}, F, { [k]: e.target.value }) });
  const taille = o => o >= 1048576 ? nf(o / 1048576, 1) + ' Mo' : Math.max(1, Math.round(o / 1024)) + ' Ko';
  // Les fichiers choisis restent hors de l'état (des objets File) : un nouveau rendu ne les perd pas.
  const choisis = (app._ffDocFichiers && app._ffDocFichiers.shop === shop) ? app._ffDocFichiers.l : [];
  const fichier = id => API_BASE + '/franchises/documents/fichier?id=' + id;
  const deposer = () => {
    if (!D || !choisis.length || S.ffDocEnvoi) { return; }
    const lourds = choisis.filter(x => x.size > D.maxOctets);
    if (lourds.length) { app.setState({ ffDocMsg: { ko: true, txt: 'Trop lourd, ' + taille(D.maxOctets) + ' au plus par fichier : ' + lourds.map(x => x.name).join(', ') } }); return; }
    const fd = new FormData();
    [['shop', shop], ['sorte', F.sorte], ['titre', F.titre], ['date', F.date], ['note', F.note]].forEach(([k, v]) => fd.append(k, v || ''));
    choisis.forEach(x => fd.append('fichiers[]', x, x.name));
    app.setState({ ffDocEnvoi: true, ffDocMsg: null });
    fetch(API_BASE + '/franchises/documents', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(r => r.json().catch(() => ({ error: 'HTTP ' + r.status })))
      .then(j => {
        const n = (j.ajoutes || []).length, refus = j.refuses || [];
        if (n) { app._ffDocFichiers = null; }
        app.setState({ ffDocEnvoi: false, ffDocForm: n ? Object.assign({}, F, { titre: '', note: '' }) : F,
          ffDocMsg: { ko: !n, txt: (n ? pl(n, 'document') + ' rangé' + (n > 1 ? 's' : '') + '.' : (j.error || 'Rien n’a été rangé.')) + (refus.length ? ' Refusé : ' + refus.map(x => x.nom + ' (' + x.motif + ')').join(', ') + '.' : '') } });
        if (n) { ffDocsCharge(app, shop, true); }
      })
      .catch(e => app.setState({ ffDocEnvoi: false, ffDocMsg: { ko: true, txt: 'Envoi impossible : ' + e.message } }));
  };
  const docs = D ? D.documents : [];
  f.docs = {
    chargement: !!B.chargement && !D, erreur: B.d && B.d.error ? 'Les documents ne se lisent pas : ' + B.d.error : '',
    sortes: (D ? D.sortes : []).map(o => ({ v: o.v, nom: o.nom, on: o.v === F.sorte })),
    form: F, setSorte: maj('sorte'), setTitre: maj('titre'), setDate: maj('date'), setNote: maj('note'),
    max: D ? taille(D.maxOctets) : '', accept: D ? D.extensions.map(e => '.' + e).join(',') : '',
    choisis: choisis.map(x => x.name + ' · ' + taille(x.size)),
    setFichiers: e => { app._ffDocFichiers = { shop, l: Array.from(e.target.files || []) }; app.setState({ ffDocMsg: null }); },
    envoi: !!S.ffDocEnvoi, peutDeposer: !!D && choisis.length > 0 && !S.ffDocEnvoi, deposer, msg: S.ffDocMsg || null, n: docs.length,
    groupes: (D ? D.sortes : []).map(o => ({ nom: o.nom, docs: docs.filter(x => x.sorte === o.v).map(x => ({
      titre: x.titre, note: x.note || '', fichier: x.nom + ' · ' + taille(x.octets), date: x.date ? fmtD(x.date) + '/' + x.date.slice(0, 4) : '—',
      depose: 'déposé le ' + fmtD(x.deposeLe.slice(0, 10)) + '/' + x.deposeLe.slice(0, 4) + ' à ' + x.deposeLe.slice(11, 16),
      ouvrir: fichier(x.id), telecharger: fichier(x.id) + '&telecharger=1',
      retirer: () => { if (!window.confirm('Retirer « ' + x.titre + ' » des documents de ' + f.shopNom + ' ? Le fichier est effacé.')) { return; }
        write('api', 'DELETE', '/franchises/documents?id=' + x.id).then(r => { if (!r.ok) { app.setState({ ffDocMsg: { ko: true, txt: 'Retrait impossible : ' + (r.error || 'HTTP ' + r.status) } }); } ffDocsCharge(app, shop, true); }); } })) }))
      .filter(g => g.docs.length) };
  // Le mail « matériel mis à disposition » (22/10/2026, maquette validée par Sam) : la liste du matériel, le contrat
  // joint (le plus récent par défaut), le destinataire ; l'aperçu se relit à chaque changement, puis l'envoi.
  const contrats = docs.filter(x => x.sorte === 'contrat');
  const MT = S.ffMat && S.ffMat.shop === shop ? S.ffMat : { shop, ouvert: false, materiel: '', contrat: null, a: '', autre: '' };
  const cId = MT.contrat == null ? (contrats[0] ? contrats[0].id : 0) : MT.contrat;
  const apercu = o => {
    const p2 = Object.assign({}, MT, { contrat: cId }, o);
    const cle = shop + '|' + p2.materiel + '|' + p2.contrat;
    app.setState({ ffMat: Object.assign({}, p2, { apCle: cle, apChargement: true }) });
    readOne('/franchises/documents/mail-materiel?shop=' + encodeURIComponent(shop) + '&materiel=' + encodeURIComponent(p2.materiel || '') + '&contrat=' + (p2.contrat || 0))
      .then(r => { const m1 = app.state.ffMat || {}; if (m1.apCle !== cle) { return; } app.setState({ ffMat: Object.assign({}, m1, { apChargement: false, ap: r || { error: 'aperçu injoignable' } }) }); });
  };
  const AP = MT.ap && !MT.ap.error ? MT.ap : null;
  const connues = AP ? AP.adresses : [];
  const choix = MT.a || connues[0] || '__autre';
  const dest = choix === '__autre' ? String(MT.autre || '').trim() : choix;
  f.docs.mat = { ouvert: !!MT.ouvert, ouvrir: () => apercu({ ouvert: true, msg: null }), fermer: () => app.setState({ ffMat: Object.assign({}, MT, { ouvert: false }) }),
    materiel: MT.materiel || '', setMateriel: e => apercu({ materiel: e.target.value }),
    contrats: [{ v: 0, nom: 'Sans pièce jointe', on: !cId }].concat(contrats.map(x => ({ v: x.id, nom: x.titre + ' · ' + x.nom, on: x.id === cId }))),
    setContrat: e => apercu({ contrat: +e.target.value }),
    adresses: connues.map(a => ({ v: a, nom: a, on: a === choix })).concat([{ v: '__autre', nom: connues.length ? 'Autre adresse…' : 'Saisir l’adresse…', on: choix === '__autre' }]),
    setA: e => app.setState({ ffMat: Object.assign({}, MT, { a: e.target.value }) }), autreVisible: choix === '__autre', autre: MT.autre || '',
    setAutre: e => app.setState({ ffMat: Object.assign({}, MT, { autre: e.target.value }) }),
    html: AP ? AP.html : '', sujet: AP ? AP.sujet : '', apChargement: !!MT.apChargement, apErreur: MT.ap && MT.ap.error ? MT.ap.error : '', smtp: AP ? AP.smtp : true,
    envoi: !!MT.envoi, msg: MT.msg || null, pret: !!AP && AP.smtp && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(dest) && !MT.envoi && !MT.apChargement,
    envoyer: () => {
      if (MT.envoi) { return; }
      app.setState({ ffMat: Object.assign({}, MT, { envoi: true, msg: null }) });
      write('api', 'POST', '/franchises/documents/mail-materiel', { shop, a: dest, materiel: MT.materiel || '', contrat: cId || 0 }).then(r => {
        const m1 = app.state.ffMat || {};
        app.setState({ ffMat: Object.assign({}, m1, { envoi: false, ouvert: !r.ok, msg: r.ok ? { ko: false, txt: 'Envoyé à ' + r.a + ' ; la copie est rangée dans « Courrier envoyé ».' } : { ko: true, txt: 'Pas envoyé : ' + (r.error || 'HTTP ' + r.status) } }) });
        if (r.ok) { ffDocsCharge(app, shop, true); }
      });
    } };
}
export function valsFF(app, common){
  const S = app.state; const auj0 = auj();
  ffTableCharge(app, false);
  const TB = S.ffTable || {}, T = TB.d && !TB.d.error ? TB.d : null;
  const mags = T ? T.magasins : app.open().map(s => ({ id: String(s.id), court: s.nom.split(' - ').pop(), nom: s.nom }));
  const shop = String(S.ffShop || (mags[0] || {}).id || '');
  // Fiche et scoring ne font qu'une entrée (B2) ; la visite et le client mystère en sont sortis (16/10/2026) :
  // deux lectures, ce magasin et le réseau. Un ancien onglet (agenda, checklist, météo, client mystère) ouvre ce magasin.
  const onglet = ({ tableau: 'reseau', scoring: 'reseau', reseau: 'reseau', docs: 'docs' })[S.ffOnglet] || 'sante';
  if (shop) { ffCharge(app, shop, false); }
  const B = S.ff || {}, d = B.shop === shop && B.d && !B.d.error ? B.d : null;
  const go = (screen, extra) => () => app.setState(Object.assign({ screen }, extra || {}));
  const VUES = [['sante', 'Ce magasin'], ['docs', 'Documents'], ['reseau', 'Le réseau']].map(([v, nom]) => ({ v, nom, on: onglet === v, choisir: () => app.setState({ ffOnglet: v }) }));
  const f = { onglet, onglets: VUES,
    magasins: mags.map(m => ({ v: m.id, nom: m.court, on: String(m.id) === shop })), setShop: e => app.setState({ ffShop: e.target.value }),
    menu: () => app.setState({ railOuvert: !S.railOuvert }),
    rafraichir: () => { ffTableCharge(app, true); ffCharge(app, shop, true); },
    pageFranchise: shop ? racine() + 'dashboard/suivi.html?shop=' + encodeURIComponent(shop) : '',
    shopNom: (mags.find(m => String(m.id) === shop) || {}).court || shop, tableau: null, tableauChargement: !!TB.chargement && !T,
    chargement: !!B.chargement && !d, indispo: !B.chargement && B.d && !d ? 'Lecture impossible — API injoignable.' : '', kpis: [], journalier: [], terrain: [], journal: [], sous: '' };
  common.ff = f;
  // Documents ne lit que les documents : la fiche du magasin (lente, parfois muette) ne le retient pas.
  if (onglet === 'docs') { if (shop) { valsFFDocs(app, f, shop); } return; }
  if (T) {
    f.tableau = { mini: (T.trimestre ? 'scoring ' + T.trimestre.lib + ' · ' : '') + 'CA et B2B du mois · tâches, notes et réclamations sur 30 jours · invendus en % du CA sur 7 jours · relu ' + (T.lu || ''),
      rows: T.magasins.map(m => { const j = m.journalier, t = m.terrain; return { id: String(m.id), on: String(m.id) === shop, choisir: () => app.setState({ ffShop: m.id, ffOnglet: 'fiche' }), feu: m.feu, court: m.court, sous: (m.responsable ? m.responsable + ' · ' : '') + ((m.motifs || [])[0] || m.due || 'aucune alerte'),
        scoring: j.scoring ? nf(j.scoring.total, 1) + ' / 20' : '—', scoringSous: j.scoring ? (j.scoring.etoiles != null ? nf(j.scoring.etoiles, 1) + ' ★ · ' : '') + j.scoring.rang + 'ᵉ' + (j.scoring.n < 4 ? ' · ' + (4 - j.scoring.n) + ' poste(s) sans donnée' : '') : 'pas de scoring',
        taches: j.taches.pct != null ? j.taches.pct + ' %' : '—', tachesSous: j.taches.pct != null ? (j.taches.joursObligManques ? pl(j.taches.joursObligManques, 'jour') + ' obligatoire manquée' : 'obligatoires tenues') : (j.taches.motif || ''), tachesCls: j.taches.pct == null ? 'mu' : (j.taches.pct < 70 ? 'ko' : j.taches.pct < 85 ? 'att' : 'ok'),
        invendus: j.invendus.lu && j.invendus.part != null ? nf(j.invendus.part, 1) + ' %' : '—', invendusCls: j.invendus.lu && j.invendus.part != null ? (j.invendus.part > (j.invendus.cible || 4) ? 'ko' : 'ok') : 'mu',
        revues: j.revues && j.revues.lu && j.revues.moyenne != null ? nf(j.revues.moyenne, 1) + ' / 5' : '—', revuesSous: j.revues && j.revues.lu ? (j.revues.nc ? pl(j.revues.nc, 'infraction') + (j.revues.mineures ? ' · ' + j.revues.mineures + ' mineure' + (j.revues.mineures > 1 ? 's' : '') : '') : (j.revues.notees ? 'aucune infraction' : 'rien de noté')) : ((j.revues && j.revues.motif) || ''), revuesCls: j.revues && j.revues.lu ? ((j.revues.majeures || j.revues.critiques) ? 'ko' : (j.revues.mineures ? 'att' : (j.revues.notees ? 'ok' : 'mu'))) : 'mu',
        reclamations: j.reclamations && j.reclamations.lu ? String(j.reclamations.n) : '—', reclamationsSous: j.reclamations && j.reclamations.lu ? (j.reclamations.ouvertes ? j.reclamations.ouvertes + ' sans réponse' : (j.reclamations.n ? 'toutes traitées' : '')) : '', reclamationsCls: j.reclamations && j.reclamations.ouvertes ? 'att' : '', google: j.google && j.google.note != null ? nf(j.google.note, 1) + ' (' + j.google.avis + ')' + (j.google.faibles ? ' · ' + j.google.faibles + ' ≤ 2' : '') : '—',
        ca: j.ca && j.ca.pct != null ? (j.ca.pct >= 0 ? '+ ' : '− ') + Math.abs(j.ca.pct) + ' %' : (j.ca ? nf(j.ca.ca) + ' €' : '—'), caCls: j.ca && j.ca.pct != null ? (j.ca.pct < -10 ? 'ko' : j.ca.pct < 0 ? 'att' : 'ok') : 'mu',
        mois: j.mois && j.mois.atteinte != null ? nf(j.mois.atteinte) + ' %' : (j.mois ? nf(j.mois.ca) + ' €' : '—'), moisSous: j.mois ? nf(j.mois.ca) + ' €' + (j.mois.budget != null ? ' / ' + nf(j.mois.budget) + ' €' : '') : '', moisCls: j.mois && j.mois.atteinte != null ? (j.mois.atteinte >= 100 ? 'ok' : j.mois.atteinte >= 90 ? 'att' : 'ko') : 'mu',
        b2b: j.mois && j.mois.pro != null ? nf(j.mois.pro, 1) + ' %' : '—', b2bSous: j.mois && j.mois.pro == null && j.mois.proJours ? 'lu ' + (j.mois.proLus || 0) + ' / ' + j.mois.proJours + ' j' : '', b2bCls: j.mois && j.mois.pro != null ? (j.mois.pro > 40 ? 'ko' : j.mois.pro > 35 ? 'att' : 'ok') : 'mu',
        derniere: t.derniereVisite ? fmtD(t.derniereVisite.le) : 'jamais', derniereSous: t.derniereVisite ? (t.derniereVisite.consultant || '') + (t.prochaineVisite ? ' · prochaine ' + fmtD(t.prochaineVisite.le) : '') : (t.prochaineVisite ? 'prochaine ' + fmtD(t.prochaineVisite.le) : ''),
        plans: t.plansOuverts + (t.p0 ? ' · ' + t.p0 + ' P0' : ''), plansCls: t.p0 ? 'ko' : (t.plansOuverts ? 'att' : ''), plano: t.plano ? t.plano.pct + ' %' : '—', msp: t.msp ? nf(t.msp.obtenu) + ' / ' + nf(t.msp.maximum) + ' · ' + t.msp.trimestre.replace(/^\d{4}-/, '') : '—' }; }) };
  }
  const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  // Trois niveaux (demande du 09/10/2026) : la tuile dit le chiffre, simple et précis ; dépliée,
  // elle montre son détail ; « Tout voir » ouvre la modale sur la liste complète.
  const ouverts = S.ffOuverts || {};
  const TU = (k, lab, v, sous, cls, html, extra) => Object.assign({ k, lab, v, sous, cls: cls || '', on: !!ouverts[k], toggle: () => app.setState({ ffOuverts: Object.assign({}, ouverts, { [k]: !ouverts[k] }) }), html }, extra || {});
  const M = (titre, html) => () => app.setState({ ffModal: { titre, html } });
  f.modal = S.ffModal || null;
  f.fermerModal = e => { if (!e || !e.target || !e.target.closest('.ff-modal-c') || e.target.closest('.ff-x')) { app.setState({ ffModal: null }); } };
  const tab = (cols, rows) => '<table class="ff-tab"><thead><tr>' + cols.map(c => '<th>' + esc(c) + '</th>').join('') + '</tr></thead><tbody>' + rows.map(r => '<tr>' + r.map(c => '<td>' + c + '</td>').join('') + '</tr>').join('') + '</tbody></table>';
  const mu = s => '<span class="mu">' + esc(s) + '</span>';
  const nZ = (n, u) => n ? n + ' ' + u + (n > 1 ? 's' : '') : '';
  // Le réseau lit le scoring (/scoring) : le classement, ses règles et l'envoi du rapport A4.
  if (onglet === 'reseau') { if (S.sqVue === 'msp') { app.state.sqVue = 'tableau'; } app.valsScoring(common); }
  if (onglet === 'reseau' && f.tableau) {
    const sqm = (((S.sq || {}).d) || {}).magasins || [], parId = {};
    sqm.forEach((l, i) => { parId[String(l.id)] = (common.sqLignes || [])[i] || null; });
    f.reseau = f.tableau.rows.map(r => Object.assign({}, r, { sq: parId[r.id] || null }))
      .sort((a, b) => ((a.sq ? a.sq.rang : 99) - (b.sq ? b.sq.rang : 99)) || String(a.court).localeCompare(String(b.court)));
  }
  if (!d) { return; }
  const J = d.journalier, R = d.terrain;
  const fe = d.feu || {};
  f.sous = 'fiche franchisé · ' + (d.magasin.ville || '') + (d.consultants.length ? ' · ' + d.consultants.map(c => c.nom + ' (' + c.types.join(', ') + ')').join(' · ') : '') + (fe.feu ? ' · feu ' + fe.feu + (fe.motifs && fe.motifs.length ? ' : ' + fe.motifs[0] : '') : '');
  const sc = R.scoring; const RV = J.revues || {}, RC = J.reclamations || {}, TA = J.taches || {}, IV = J.invendus || {}, tj = J.tachesJour || {};
  const pctCls = p => p == null ? 'mu' : (p < 70 ? 'ko' : p < 85 ? 'att' : 'ok');
  const heat = () => { const parJ = {}; (TA.jours || []).forEach(x => { parJ[x.jour] = x; }); let h = ''; for (let i = 29; i >= 0; i--) { const dte = plusJours(auj0, -i); const x = parJ[dte]; h += '<i class="' + (!x ? 'x' : (x.oblig ? 'm' : (x.f < x.t ? 'p' : ''))) + '" title="' + fmtD(dte) + (x ? ' : ' + x.f + ' / ' + x.t : ' : non relevé') + '"></i>'; } return '<div class="ff-heat">' + h + '</div>'; };
  const NIV = i => '<span class="gc-st ' + (i.niveau === 'critique' ? 'p0' : i.niveau === 'majeure' ? 'att' : 'm') + '">' + esc(i.niveau) + '</span>';
  const SUITE = i => i.suite ? fmtD(i.suite.jour) + ' : ' + (i.suite.conforme ? '<span class="ok">conforme</span>' : '<span class="ko">toujours non conforme</span>') : '<span class="mu">pas encore renotée</span>';
  const ST_RC = r => r.ouverte ? '<b class="att">sans réponse</b>' : esc(r.statut === 'REJECTED' ? 'refusée' : r.statut === 'ACCEPTED' ? 'acceptée' : String(r.statut || '').toLowerCase());
  const OB = J.objectifs || { produits: [], campagnes: [] }; const o1 = OB.produits[0];
  f.kpis = [];
  f.journalier = [
    TU('taches', 'Tâches faites · 30 j', TA.pct != null ? TA.pct + ' %' : '—', TA.pct != null ? TA.rendues + ' / ' + TA.attendues + (TA.joursObligManques ? ' · obligatoire manquée ' + nZ(TA.joursObligManques, 'jour') : ' · obligatoires tenues') : (TA.motif || ''), pctCls(TA.pct),
      (tj.lu ? 'Le ' + fmtD(tj.date) + ' : <b>' + tj.notees + ' / ' + tj.taches + '</b> photos notées' + (tj.aNoter ? ' · <b>' + tj.aNoter + ' à noter</b>' : '') : mu('Les revues du jour ne se lisent pas.')) + heat() + '<div class="gc-leg" style="margin-top:4px"><span><i style="background:#C9E3CD"></i>tout rendu</span><span><i style="background:#F5D58F"></i>partiel</span><span><i style="background:#E8A0A0"></i>obligatoire manquée</span><span><i style="background:#EDE7E0"></i>non relevé</span></div>',
      { modal: (TA.jours || []).length ? M('Tâches jour par jour · 30 jours', tab(['Jour', 'Rendues', 'Attendues', 'Obligatoire'], TA.jours.slice().reverse().map(x => [fmtDJ(x.jour), String(x.f), String(x.t), x.oblig ? '<b class="ko">manquée</b>' : 'tenue']))) : null, lien: go('suivi'), lienTxt: 'Tâches et contrôles photo' }),
    TU('notes', 'Points moyens', RV.lu && RV.moyenne != null ? nf(RV.moyenne, 1) + ' / 5' : '—', RV.lu ? (RV.notees ? RV.notees + ' notées · ' + (RV.parNote || []).map(p => p.note + '★ ' + p.n).join(' · ') : 'rien de noté sur 30 jours') : (RV.motif || ''), RV.lu && RV.moyenne != null ? (RV.moyenne >= 4 ? 'ok' : RV.moyenne >= 3 ? 'att' : 'ko') : 'mu',
      RV.lu && RV.notees ? '<div class="ff-rep">' + (RV.parNote || []).map(p => '<div><span>' + p.note + ' ★ ' + esc(p.nom) + '</span><i><b style="width:' + Math.round(100 * p.n / RV.notees) + '%"></b></i><span>' + p.n + '</span></div>').join('') + '</div>' : mu(RV.lu ? 'Aucune tâche notée sur 30 jours.' : (RV.motif || 'les revues ne se lisent pas')),
      { lien: go('suivi'), lienTxt: 'Contrôle des tâches' }),
    TU('infractions', 'Infractions · 30 j', RV.lu ? String(RV.nc || 0) : '—', RV.lu ? ([nZ(RV.mineures, 'mineure'), nZ(RV.majeures, 'majeure'), nZ(RV.critiques, 'critique')].filter(Boolean).join(' · ') || 'aucune') : (RV.motif || ''), RV.lu ? (RV.nc ? ((RV.majeures || RV.critiques) ? 'ko' : 'att') : 'ok') : 'mu',
      RV.lu && RV.nc ? '<div class="ff-nc">' + (RV.infractions || []).map(i => '<div>' + NIV(i) + '<span>' + fmtD(i.jour) + ' · <b>' + esc(i.tache) + '</b>' + (i.note != null ? ' · ' + i.note + ' / 5' : '') + (i.comment ? ' — ' + esc(i.comment) : '') + (i.recidive ? ' · <b class="ko">' + i.recidive + ' j sur 7</b>' : '') + '</span></div>').join('') + '</div>' : mu(RV.lu ? 'Aucune infraction sur 30 jours.' : (RV.motif || 'les revues ne se lisent pas')),
      { modal: RV.lu && RV.nc ? M('Les infractions · 30 jours', tab(['Jour', 'Tâche', 'Note', 'Gravité', 'Commentaire', 'Récidive', 'Suite', 'Par'], (RV.infractions || []).map(i => [fmtDJ(i.jour), '<b>' + esc(i.tache) + '</b>', i.note != null ? i.note + ' / 5' : '—', NIV(i) + ' ' + esc(i.niveauNom || ''), esc(i.comment || ''), i.recidive ? '<b class="ko">' + i.recidive + ' j sur 7</b>' : '—', SUITE(i), esc(i.consultant || '')]))) : null, lien: go('suivi'), lienTxt: 'Contrôle des tâches' }),
    TU('invendus', 'Invendus · 7 j', IV.lu && IV.part != null ? nf(IV.part, 1) + ' %' : '—', IV.lu && IV.part != null ? 'du CA · cible sous ' + nf(IV.cible || 4) + ' %' : (IV.motif || ''), IV.lu && IV.part != null ? (IV.part > (IV.cible || 4) ? 'ko' : 'ok') : 'mu',
      IV.lu ? 'Du ' + fmtD(IV.du) + ' au ' + fmtD(IV.au) + ' : <b>' + nf(IV.cout, 2) + ' €</b> d’invendus' + (IV.ca != null ? ' pour ' + nf(IV.ca) + ' € de CA' : '') + '. ' + mu('Pièces, motifs et produits : écran Invendus et poubelle.') : mu(IV.motif || 'la poubelle du panel ne se lit pas'),
      { lien: go('invendus'), lienTxt: 'Invendus et poubelle' }),
    TU('reclamations', 'Réclamations · 30 j', RC.lu ? String(RC.n) : '—', RC.lu ? ((RC.ouvertes ? RC.ouvertes + ' sans réponse' : (RC.n ? 'toutes traitées' : 'aucune')) + (RC.montant ? ' · ' + nf(RC.montant, 2) + ' €' : '')) : (RC.motif || ''), RC.lu ? (RC.ouvertes ? 'att' : '') : 'mu',
      RC.lu && RC.n ? ((RC.parFournisseur || []).length ? 'Par fournisseur : ' + RC.parFournisseur.map(f2 => esc(f2.nom) + ' ' + f2.n).join(' · ') + '<br>' : '') + '<span class="mu">' + (RC.dernieres || []).map(r => fmtD(r.le) + ' ' + esc(r.fournisseur) + ' — ' + esc(r.reference) + (r.qte != null ? ' ' + nf(r.qte) + ' ' + esc(r.unite) : '') + (r.motif ? ' · ' + esc(r.motif) : '') + ' · ' + ST_RC(r)).join('<br>') + '</span>' : mu(RC.lu ? 'Aucune réclamation sur 30 jours.' : (RC.motif || 'le panel n’a pas rendu les réclamations')),
      { modal: RC.lu && RC.n ? M('Les réclamations fournisseur · 30 jours', tab(['Date', 'Fournisseur', 'Référence', 'Qté', 'Motif', 'Statut', 'Réponse', 'Montant'], (RC.lignes || RC.dernieres || []).map(r => [fmtDJ(r.le), esc(r.fournisseur), '<b>' + esc(r.reference) + '</b>', r.qte != null ? nf(r.qte) + ' ' + esc(r.unite) : '—', esc(r.motif), ST_RC(r), esc(r.reponse || '') + (r.reponseLe ? ' <span class="mu">' + fmtD(r.reponseLe) + '</span>' : ''), r.montant != null ? nf(r.montant, 2) + ' €' : '—']))) : null, lien: go('caAchats'), lienTxt: 'Réclamations fournisseur' }),
    TU('objectifs', 'Objectifs', o1 ? (o1.pct != null ? o1.pct + ' %' : '—') : (OB.campagnes[0] ? nf(OB.campagnes[0].reel) : '—'), o1 ? o1.nom + (OB.produits.length > 1 ? ' · + ' + (OB.produits.length - 1) : '') : (OB.campagnes[0] ? 'clients · ' + OB.campagnes[0].nom : 'aucun objectif en cours'), o1 && o1.pct != null ? (o1.pct >= 100 ? 'ok' : o1.pct >= 70 ? 'att' : 'ko') : 'mu',
      (OB.produits.length ? OB.produits.map(c => '« ' + esc(c.nom) + ' » : <b>' + (c.pct != null ? c.pct + ' %' : '—') + '</b> · ' + nf(c.vendu) + ' / ' + nf(c.objectif) + ' pièces · jusqu’au ' + fmtD(c.fin)).join('<br>') : mu('Pas d’objectif produits en cours.')) + (OB.campagnes.length ? '<br>' + OB.campagnes.map(c => 'Campagne « ' + esc(c.nom) + ' » : <b>' + nf(c.reel) + '</b> clients' + (c.clientsPrevus != null ? ' / ' + nf(c.clientsPrevus) : '') + (c.n1Ecoule != null ? ' · N−1 ' + nf(c.n1Ecoule) : '') + (c.jourCourant ? ' · jour ' + c.jourCourant + ' / ' + c.nbJours : '')).join('<br>') : ''),
      { lien: go('mktObjectifs'), lienTxt: 'Objectifs' }),
    TU('remarques', 'Remarques · 30 j', String(J.remarques.n || 0), J.remarques.n ? J.remarques.parOperateur.map(o => o.employe + ' ' + o.n).join(' · ') : 'aucune', J.remarques.n ? 'att' : 'ok',
      J.remarques.n ? '<span class="mu">' + J.remarques.dernieres.map(r => fmtD(r.le) + ' ' + esc(r.employe) + ' — ' + esc(r.produit) + (r.motifLib ? ' · ' + esc(r.motifLib) : '') + (r.texte ? ' : ' + esc(r.texte) : '')).join('<br>') + '</span>' : mu('Aucune remarque sur 30 jours.')),
    TU('google', 'Note Google', J.google && J.google.note != null ? nf(J.google.note, 1) : '—', J.google && J.google.note != null ? nf(J.google.avis) + ' avis · ' + (J.google.faibles ? J.google.faibles + ' ≤ 2 sur 30 j' : 'aucun avis faible') + (J.google.cible ? ' · cible ' + nf(J.google.cible, 1) : '') : 'pas de fiche reliée', J.google && J.google.note != null ? (J.google.faibles || (J.google.cible && J.google.note < J.google.cible) ? 'att' : 'ok') : 'mu',
      J.google && (J.google.derniers || []).length ? '<span class="mu">' + J.google.derniers.slice(0, 5).map(a => fmtD(a.le) + ' ' + a.note + '/5' + (a.extrait ? ' « ' + esc(a.extrait) + ' »' : '')).join('<br>') + '</span>' : mu('Pas d’avis récent.'),
      { lien: go('reputation'), lienTxt: 'Note Google et avis' }),
    TU('ca', 'CA semaine', J.ca ? nf(J.ca.ca) + ' €' : '—', J.ca ? ((J.ca.objectif ? 'objectif ' + nf(J.ca.objectif) + ' €' : '') + (J.ca.pct != null ? ' · ' + (J.ca.pct >= 0 ? '+ ' : '− ') + Math.abs(J.ca.pct) + ' %' : '')) : 'ne se lit pas', J.ca && J.ca.pct != null ? (J.ca.pct < -10 ? 'ko' : J.ca.pct < 0 ? 'att' : 'ok') : 'mu',
      J.ca ? pl(J.ca.jours, 'jour') + ' vu' + (J.ca.jours > 1 ? 's' : '') + ' cette semaine. ' + mu('Le poste Budget du scoring lit le CA du trimestre face au budget.') : mu('Le CA de la semaine ne se lit pas.'),
      { lien: go('resultatJour'), lienTxt: 'Résultat' })];
  const conf = R.conformite || {}; const plano = conf.planogramme || {}; const asso = conf.assortiment || {};
  f.terrain = [
    TU('comptoir', 'Comptoir', plano.pct != null ? plano.pct + ' %' : '—', (plano.pct != null ? 'planogramme tenu' : (plano.motif || conf.motif || 'planogramme non lu')) + (asso.obligatoires != null && !asso.motif ? ' · obligatoires ' + asso.presentes + ' / ' + asso.obligatoires : ''), plano.pct != null ? ((asso.manquantes || plano.pct < 80) ? 'att' : 'ok') : 'mu',
      (plano.pct != null ? 'Planogramme : <b>' + plano.pct + ' %</b>' + (plano.tenus != null ? ' (' + plano.tenus + ' / ' + plano.emplacements + ' emplacements)' : '') + (plano.comptoirsMontes != null ? ' · ' + plano.comptoirsMontes + ' comptoir(s) photographié(s) aujourd’hui' : '') : mu('Planogramme : ' + (plano.motif || conf.motif || 'non lu'))) + '<br>' + (asso.obligatoires != null && !asso.motif ? 'Obligatoires vues en caisse sur ' + (asso.jours || 30) + ' j : <b>' + asso.presentes + ' / ' + asso.obligatoires + '</b>' + (asso.manquantes ? ' · <b class="ko">' + pl(asso.manquantes, 'manquante') + '</b>' + ((asso.liste || []).length ? ' : ' + asso.liste.filter(l => l.manquante !== false).slice(0, 6).map(l => esc(l.nom)).join(', ') : '') : ' · toutes passées en caisse') : mu('Assortiment : ' + (asso.motif || 'non lu'))) + (R.plano ? '<br>' + mu('À la dernière visite : planogramme ' + R.plano.pct + ' % le ' + fmtD(R.plano.le)) : ''),
      { lien: go('assortiment'), lienTxt: 'Conformité du comptoir' }),
    TU('scoring', 'Scoring ' + (sc && sc.trimestre ? (sc.trimestre.court || sc.trimestre.lib) : 'du trimestre'), sc ? nf(sc.total, 1) + ' / 20' : '—', sc ? (sc.etoiles != null ? nf(sc.etoiles, 1) + ' ★ · ' : '') + sc.rang + 'ᵉ sur ' + sc.magasins + ' · précédent ' + nf(sc.prec.total, 1) : 'pas de scoring calculé', sc ? (sc.total >= 16 ? 'ok' : sc.total >= 12 ? 'att' : 'ko') : 'mu',
      sc ? '<div class="ff-postes">' + Object.keys(sc.postes).map(k => '<div><small>' + esc((sc.noms && sc.noms[k]) || k) + '</small><b>' + (sc.postes[k].v != null ? nf(sc.postes[k].v, 1) : '—') + '</b></div>').join('') + '</div>' + mu('Chaque poste vaut 5' + (sc.delta != null ? ' · ' + (sc.delta >= 0 ? '+ ' : '− ') + nf(Math.abs(sc.delta), 2) + ' ★ sur le trimestre précédent' : '') + '.') : mu('Pas de scoring calculé pour ce trimestre.'),
      { lien: () => app.setState({ ffOnglet: 'reseau' }), lienTxt: 'Le réseau' })];
  // Santé du magasin : la vue générale en tête (09/10/2026) — le mois face au budget, la part du pro, le scoring,
  // ses postes Google et Tâches, les infractions mineures, la météo du franchisé.
  const prend = (arr, k) => { const i = arr.findIndex(t => t.k === k); return i >= 0 ? arr.splice(i, 1)[0] : null; };
  const tInf = prend(f.journalier, 'infractions'), tGoo = prend(f.journalier, 'google'), tSco = prend(f.terrain, 'scoring');
  const MO = J.mois || {}, PRO = MO.pro || {};
  const pv = k => sc && sc.postes && sc.postes[k] && sc.postes[k].v != null ? sc.postes[k].v : null;
  const c5 = v => v == null ? 'mu' : (v >= 4 ? 'ok' : v >= 3 ? 'att' : 'ko');
  const joursM = (MO.jours || []).filter(x => x.passe && !x.ferme);
  const maxJ = Math.max(1, ...joursM.map(x => Math.max(+x.ca || 0, +x.objectif || 0)));
  const barres = joursM.length ? '<div class="ff-bars">' + joursM.map(x => '<i title="' + esc(x.court) + ' : ' + nf(x.ca) + ' €' + (x.objectif != null ? ' / ' + nf(x.objectif) + ' €' : '') + '"><b class="' + (x.objectif != null && x.ca != null && x.ca < x.objectif ? 'ko' : 'ok') + '" style="height:' + Math.round(100 * (+x.ca || 0) / maxJ) + '%"></b>' + (x.objectif != null ? '<u style="bottom:' + Math.round(100 * x.objectif / maxJ) + '%"></u>' : '') + '</i>').join('') + '</div><div class="gc-leg" style="margin-top:2px"><span><i style="background:#8FBF98"></i>CA du jour</span><span><i style="background:#D98C8C"></i>sous le budget</span><span><i style="background:#333"></i>budget du jour</span></div>' : '';
  f.sante = [
    TU('mois', 'CA du mois / budget', MO.lu ? (MO.atteinte != null ? nf(MO.atteinte) + ' %' : nf(MO.ca) + ' €') : '—', MO.lu ? nf(MO.ca) + ' €' + (MO.attendu != null ? ' sur ' + nf(MO.attendu) + ' € attendus à date' : ' · pas de budget') : (MO.motif || ''), MO.lu && MO.atteinte != null ? (MO.atteinte >= 100 ? 'ok' : MO.atteinte >= 90 ? 'att' : 'ko') : 'mu',
      MO.lu ? (MO.budget != null ? 'Budget du mois : <b>' + nf(MO.budget) + ' €</b>' + (MO.ecart != null ? ' · écart à date <b class="' + (MO.ecart < 0 ? 'ko' : 'ok') + '">' + (MO.ecart >= 0 ? '+ ' : '− ') + nf(Math.abs(MO.ecart)) + ' €</b>' : '') : mu('Aucun budget encodé pour ce mois.')) + (MO.tickets ? '<br>' + nf(MO.tickets) + ' tickets · panier ' + nf(MO.panier, 2) + ' €' : '') + barres : mu(MO.motif || 'le résultat du mois ne se lit pas'),
      { modal: joursM.length ? M('CA du mois jour par jour', tab(['Jour', 'CA', 'Budget', 'Écart', 'Atteinte'], joursM.map(x => [esc(x.court), nf(x.ca) + ' €', x.objectif != null ? nf(x.objectif) + ' €' : '—', x.objectif != null && x.ca != null ? '<b class="' + (x.ca < x.objectif ? 'ko' : 'ok') + '">' + (x.ca >= x.objectif ? '+ ' : '− ') + nf(Math.abs(x.ca - x.objectif)) + ' €</b>' : '—', x.objectif ? nf(100 * x.ca / x.objectif) + ' %' : '—']))) : null, lien: go('resultatJour'), lienTxt: 'Résultat' }),
    TU('b2b', 'Part B2B · mois', PRO.part != null ? nf(PRO.part, 1) + ' %' : '—', PRO.part != null ? 'du CA · règle ≤ ' + nf(PRO.max || 40) + ' %' : (PRO.jours ? 'tickets lus ' + (PRO.joursLus || 0) + ' / ' + PRO.jours + ' jours' : (MO.motif || 'tickets non lus')), PRO.part != null ? (PRO.part > (PRO.max || 40) ? 'ko' : PRO.part > (PRO.alerte || 35) ? 'att' : 'ok') : 'mu',
      MO.lu ? 'CA pro : <b>' + nf(PRO.ca) + ' €</b>' + (PRO.tickets != null ? ' · ' + nf(PRO.tickets) + ' tickets pro' : '') + (PRO.panier != null ? ' · panier pro ' + nf(PRO.panier, 2) + ' €' : '') + (PRO.complet === false && PRO.jours ? '<br>' + mu('Part calculée quand tous les jours sont lus : ' + (PRO.joursLus || 0) + ' / ' + PRO.jours + '.') : '') + '<br>' + mu('Règle : le pro ne dépasse pas ' + nf(PRO.max || 40) + ' % du CA du mois.') : mu(MO.motif || 'le résultat du mois ne se lit pas'),
      { lien: go('resultatJour'), lienTxt: 'Résultat' }),
    TU('scGoogle', 'Scoring Google', pv('google') != null ? nf(pv('google'), 1) + ' / 5' : '—', J.google && J.google.note != null ? 'note ' + nf(J.google.note, 1) + ' · ' + nf(J.google.avis) + ' avis' + (J.google.faibles ? ' · ' + J.google.faibles + ' ≤ 2' : '') : 'pas de fiche reliée', c5(pv('google')), tGoo ? tGoo.html : '', { lien: go('reputation'), lienTxt: 'Note Google et avis' }),
    TU('scTaches', 'Scoring tâches', pv('taches') != null ? nf(pv('taches'), 1) + ' / 5' : '—', (TA.pct != null ? TA.pct + ' % faites' : (TA.motif || 'pas de relevé')) + (RV.lu && RV.moyenne != null ? ' · points moyens ' + nf(RV.moyenne, 1) : ''), c5(pv('taches')),
      (TA.pct != null ? 'Faites sur 30 jours : <b>' + TA.rendues + ' / ' + TA.attendues + '</b>' + (TA.joursObligManques ? ' · <b class="ko">obligatoire manquée ' + nZ(TA.joursObligManques, 'jour') + '</b>' : ' · obligatoires tenues') : '') + (RV.lu && RV.notees ? '<div class="ff-rep" style="margin-top:6px">' + (RV.parNote || []).map(p => '<div><span>' + p.note + ' ★ ' + esc(p.nom) + '</span><i><b style="width:' + Math.round(100 * p.n / RV.notees) + '%"></b></i><span>' + p.n + '</span></div>').join('') + '</div>' : ''),
      { lien: go('suivi'), lienTxt: 'Contrôle des tâches' }),
    tInf && Object.assign(tInf, { lab: 'Infractions mineures · 30 j', v: RV.lu ? String(RV.mineures || 0) : '—', sous: RV.lu ? ([nZ(RV.majeures, 'majeure'), nZ(RV.critiques, 'critique')].filter(Boolean).join(' · ') || (RV.nc ? 'aucune majeure' : 'aucune infraction')) : (RV.motif || ''), cls: RV.lu ? ((RV.majeures || RV.critiques) ? 'ko' : (RV.mineures ? 'att' : 'ok')) : 'mu' })].filter(Boolean);
  f.journal = (d.journal || []).map(e => ({ le: fmtD(e.le), volet: e.volet, texte: e.texte }));
  // Le bandeau du scoring (B2) : la note du trimestre en tête de la fiche, ses quatre postes, le rang.
  const nfS = (n, dd) => n == null ? '—' : nf(n, dd == null ? 1 : dd);
  f.score = sc ? {
    tri: (sc.trimestre && (sc.trimestre.lib || sc.trimestre.court)) || 'du trimestre', enCours: !!(sc.trimestre && sc.trimestre.enCours),
    total: nf(sc.total, 1), sur: sc.sur || 20, pct: sc.etoiles != null ? sc.etoiles / 5 * 100 : 0, na: sc.etoiles == null,
    rang: sc.rang, magasins: sc.magasins, manque: Math.max(0, 4 - (sc.n || 0)),
    delta: sc.delta != null ? (sc.delta >= 0 ? '+ ' : '− ') + nf(Math.abs(sc.delta), 1) + ' ★ vs le trimestre précédent' : 'sans comparaison',
    deltaCls: sc.delta == null ? 'mu' : (sc.delta >= 0.05 ? 'ok' : (sc.delta <= -0.05 ? 'ko' : 'mu')),
    postes: Object.keys(sc.postes || {}).map(k => { const x = sc.postes[k] || {}; return { nom: (sc.noms && sc.noms[k]) || k, v: x.v == null ? '—' : nf(x.v, 1), na: x.v == null,
      pct: x.v == null ? 0 : x.v / 5 * 100, d: app.sqDetail(k, x, nfS), alerte: k === 'taches' && x.joursZero ? pl(x.joursZero, 'jour') + ' à 0 — obligatoire manquée' : '',
      // Le client mystère s'encode dans Paramètres (16/10/2026) : le lien y mène, ce magasin choisi.
      encoder: k === 'msp' ? () => { if (sc.trimestre && sc.trimestre.cle) { app.sqMspOuvrir(shop, sc.trimestre.cle); } app.setState({ screen: 'clientMystere' }); } : null }; }),
    rapport: sc.trimestre && sc.trimestre.cle ? API_BASE + '/scoring/rapport?trimestre=' + encodeURIComponent(sc.trimestre.cle) : '' } : null;
}


/* --- Contrôles qualité du mois (12/10/2026) ------------------------------------------------
 * Tous les contrôles photo du mois, magasin par magasin, une ligne chacun, avec le badge de sa note
 * au barème (Exemplaire, Conforme, mineur, majeur, critique) ou son état (à noter, en attente, non
 * rendue). Lu par /controles/qualite, relu tant que des journées manquent ; la photo s'ouvre en
 * modale (/pwa/tasks/detail, URL fraîche) et s'y note (POST /pwa/tasks/review). */
const CQM_ETATS = { a_noter: ['À noter', '#5b3d9e', '#EEE6FA'], en_attente: ['En attente', '#7a6a55', '#F4EFE8'], non_rendue: ['Non rendue', '#555', '#E9E6E1'] };
export function cqmBadge(d, etat){
  if (typeof etat === 'number') {
    const nv = ((d && d.niveaux) || []).find(n => n.n === etat) || {};
    const nom = String(nv.nom || etat);
    const court = nom.includes('—') ? nom.split('—').pop().trim() : nom;
    return { txt: court.charAt(0).toUpperCase() + court.slice(1), long: nom, fg: '#fff', bg: nv.couleur || '#666', n: etat };
  }
  const e = CQM_ETATS[etat] || [etat, '#555', '#eee'];
  return { txt: e[0], long: e[0], fg: e[1], bg: e[2], n: null };
}
export function cqmCharge(app, mois, force, tours){
  const b = app.state.cqm; const cle = mois;
  if (!force && b && b.cle === cle && (b.d || b.chargement)) { return; }
  if (app._cqmEnCours === cle) { return; }
  app._cqmEnCours = cle;
  const t = tours || 0;
  app.setState({ cqm: { cle, chargement: true, d: b && b.cle === cle ? b.d : null, tours: t } });
  readOne('/controles/qualite?m=' + encodeURIComponent(mois)).then(d => {
    app._cqmEnCours = null;
    app.setState({ cqm: { cle, chargement: false, d: d || { error: 'injoignable' }, tours: t + 1 } });
    // Des journées restent à lire : on relit, tant que chaque tour en apporte (douze tours au plus).
    if (d && !d.error && d.manque > 0 && d.lus > 0 && t + 1 < 12) { setTimeout(() => { if ((app.state.cqm || {}).cle === cle) { cqmCharge(app, mois, true, t + 1); } }, 150); }
  });
}
function cqmOuvrir(app, l){
  app.setState({ cqmModal: { l, chargement: true, d: null, note: typeof l.etat === 'number' ? l.etat : null, comment: l.commentaire || '', msg: null, envoi: false } });
  readOne('/pwa/tasks/detail?shop=' + encodeURIComponent(l.shopId) + '&task=' + encodeURIComponent(l.taskId) + '&date=' + encodeURIComponent(l.date)).then(d => {
    const M = app.state.cqmModal; if (!M || M.l !== l) { return; }
    app.setState({ cqmModal: Object.assign({}, M, { chargement: false, d: d || { error: 'injoignable' } }) });
  });
}
function cqmNoter(app){
  const M = app.state.cqmModal; if (!M || M.envoi || !M.note) { return; }
  const seuil = ((app.state.cqm || {}).d || {}).seuil || 4;
  if (M.note < seuil && !String(M.comment || '').trim()) { app.setState({ cqmModal: Object.assign({}, M, { msg: { ko: true, txt: 'Sous « Conforme », le motif est obligatoire.' } }) }); return; }
  const l = M.l, dt = M.d || {};
  app.setState({ cqmModal: Object.assign({}, M, { envoi: true, msg: null }) });
  write(app.source || 'api', 'POST', '/pwa/tasks/review', { shopId: l.shopId, taskId: l.taskId, date: l.date, note: M.note, comment: String(M.comment || '').trim(),
    checklistId: dt.checklistId || l.checklistId || null, completionId: dt.completionId || l.completionId || null }).then(r => {
    const M2 = app.state.cqmModal; if (!M2 || M2.l !== l) { return; }
    if (!r || !r.ok) { app.setState({ cqmModal: Object.assign({}, M2, { envoi: false, msg: { ko: true, txt: 'Note refusée : ' + ((r && r.error) || 'serveur injoignable') } }) }); return; }
    // La ligne prend la note tout de suite ; la journée se relira au prochain passage (garde effacée).
    l.etat = M2.note; l.note = M2.note; l.commentaire = String(M2.comment || '').trim() || null;
    app.setState({ cqmModal: Object.assign({}, M2, { envoi: false, msg: { ko: false, txt: 'Note enregistrée au panel.' } }) });
  });
}
export function valsCQM(app, common){
  const S = app.state;
  const mois = S.cqmMois || auj().slice(0, 7);
  cqmCharge(app, mois, false);
  const b = S.cqm || {}, d = b.d && !b.d.error ? b.d : null;
  const shop = S.cqmShop || '', filtre = S.cqmFiltre || '', cl = S.cqmCl || '';
  const r = { moisLib: MOIS[+mois.slice(5, 7) - 1] + ' ' + mois.slice(0, 4), moisPrec: () => app.setState({ cqmMois: decaleMois(mois, -1), cqmN: 0 }),
    moisSuiv: mois < auj().slice(0, 7) ? () => app.setState({ cqmMois: decaleMois(mois, 1), cqmN: 0 }) : null,
    rafraichir: () => cqmCharge(app, mois, true, 0), chargement: !d && !!b.chargement, indispo: !b.chargement && b.d && !d ? 'Lecture impossible — API injoignable.' : '',
    lecture: d && d.manque > 0 ? (b.chargement ? 'lecture des journées restantes… ' : '') + d.manque + ' journée' + (d.manque > 1 ? 's' : '') + ' encore à lire' : '',
    erreur: d && d.api && d.api.erreur ? d.api.erreur : '', magasins: [], checklists: [], puces: [], kpis: [], parShop: [], lignes: [], reste: 0, plus: null, modal: null };
  common.cqm = r;
  if (d) {
    r.magasins = [{ v: '', nom: 'Tous les magasins', on: !shop }].concat((d.magasins || []).map(m => ({ v: m.id, nom: m.nom, on: m.id === shop })));
    r.setShop = e => app.setState({ cqmShop: e.target.value, cqmN: 0 });
    const base = (d.lignes || []).filter(l => !shop || l.shopId === shop);
    const cls = [...new Set(base.map(l => l.checklist).filter(Boolean))].sort();
    r.checklists = [{ v: '', nom: 'Toutes les checklists', on: !cl }].concat(cls.map(c => ({ v: c, nom: c, on: c === cl })));
    r.setCl = e => app.setState({ cqmCl: e.target.value, cqmN: 0 });
    const L0 = base.filter(l => !cl || l.checklist === cl);
    const nb = f => L0.filter(f).length;
    const nc = l => typeof l.etat === 'number' && l.etat < (d.seuil || 4);
    const defs = [['', 'Tous', () => true], ['nc', 'Non conformes', nc], ['1', null, l => l.etat === 1], ['2', null, l => l.etat === 2], ['3', null, l => l.etat === 3],
      ['ok', 'Conformes', l => typeof l.etat === 'number' && l.etat >= (d.seuil || 4)], ['a_noter', null, l => l.etat === 'a_noter'], ['non_rendue', null, l => l.etat === 'non_rendue'], ['en_attente', null, l => l.etat === 'en_attente']];
    r.puces = defs.map(([k, nom, f]) => { const bd = k && !nom ? cqmBadge(d, /^\d$/.test(k) ? +k : k) : null; return { nom: nom || bd.txt, n: nb(f), on: filtre === k, badge: bd, choisir: () => app.setState({ cqmFiltre: k, cqmN: 0 }) }; }).filter(p => p.n > 0 || p.on || p.nom === 'Tous');
    const fx = (defs.find(x => x[0] === filtre) || defs[0])[2];
    const L = L0.filter(fx);
    const notes = L0.filter(l => typeof l.etat === 'number');
    const rendus = L0.filter(l => l.photo).length;
    const passes = L0.filter(l => l.etat !== 'en_attente').length;
    const ncs = notes.filter(nc);
    r.kpis = [['Contrôles photo', nf(L0.length), (d.du ? 'du ' + fmtD(d.du) + ' au ' + fmtD(d.au) : '') + (shop ? '' : ' · ' + (d.magasins || []).length + ' magasins')],
      ['Rendus', passes ? Math.round(100 * rendus / passes) + ' %' : '—', nf(rendus) + ' photo' + (rendus > 1 ? 's' : '') + ' · ' + nf(nb(l => l.etat === 'non_rendue')) + ' non rendue' + (nb(l => l.etat === 'non_rendue') > 1 ? 's' : '')],
      ['Notés', nf(notes.length), nf(nb(l => l.etat === 'a_noter')) + ' photo' + (nb(l => l.etat === 'a_noter') > 1 ? 's' : '') + ' à noter'],
      ['Non conformes', nf(ncs.length), [1, 2, 3].map(n => nf(ncs.filter(l => l.etat === n).length) + ' ' + cqmBadge(d, n).txt.toLowerCase()).join(' · '), ncs.length ? 'ko' : 'ok']];
    r.parShop = (d.magasins || []).filter(m => !shop || m.id === shop).map(m => {
      const X = L0.filter(l => l.shopId === m.id); const pX = X.filter(l => l.etat !== 'en_attente').length; const nX = X.filter(l => typeof l.etat === 'number');
      return { nom: m.nom, total: nf(X.length), rendus: pX ? Math.round(100 * X.filter(l => l.photo).length / pX) + ' %' : '—',
        niveaux: [5, 4, 3, 2, 1].map(n => ({ b: cqmBadge(d, n), n: nX.filter(l => l.etat === n).length })), aNoter: X.filter(l => l.etat === 'a_noter').length, nonRendues: X.filter(l => l.etat === 'non_rendue').length };
    });
    const N = Math.max(150, +S.cqmN || 0);
    r.lignes = L.slice(0, N).map(l => ({ date: fmtDJ(l.date), shop: l.shop, checklist: l.checklist || '', tache: l.tache, heure: l.heure || '', fait: l.fait || '', photo: l.photo,
      badge: cqmBadge(d, l.etat), commentaire: l.commentaire || '', ouvrir: () => cqmOuvrir(app, l) }));
    r.reste = Math.max(0, L.length - N);
    r.plus = r.reste ? () => app.setState({ cqmN: N + 150 }) : null;
    r.titre = (filtre ? (r.puces.find(p => p.on) || {}).nom + ' · ' : '') + nf(L.length) + ' contrôle' + (L.length > 1 ? 's' : '');
  }
  const M = S.cqmModal;
  if (M) {
    const dt = M.d && !M.d.error ? M.d : null;
    r.modal = { titre: M.l.tache, sous: M.l.shop + ' · ' + fmtDJ(M.l.date) + (M.l.checklist ? ' · ' + M.l.checklist : ''), chargement: M.chargement, indispo: M.d && !dt ? 'Photo illisible — API injoignable.' : '',
      photo: dt && dt.photo ? dt.photo : null, faitLe: dt && dt.faitLe ? 'faite à ' + String(dt.faitLe).slice(11, 16) : (M.l.fait ? 'faite à ' + M.l.fait : ''),
      badge: cqmBadge(d || {}, M.l.etat), commentaire: M.l.commentaire || '',
      choix: (d ? d.niveaux || [] : []).slice().sort((a, c) => c.n - a.n).map(n => ({ b: cqmBadge(d, n.n), on: M.note === n.n, choisir: () => app.setState({ cqmModal: Object.assign({}, app.state.cqmModal, { note: n.n, msg: null }) }) })),
      motifRequis: !!M.note && M.note < ((d || {}).seuil || 4), comment: M.comment || '', setComment: e => { const X = app.state.cqmModal; if (X) { X.comment = e.target.value; } },
      peutNoter: !!dt && !!M.l.photo, envoi: M.envoi, msg: M.msg, enregistrer: () => cqmNoter(app), fermer: () => app.setState({ cqmModal: null }) };
  }
}
