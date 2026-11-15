/* Cockpit CEO — Benchmark (rail Pilotage, 14/11/2026, maquette docs/maquettes/benchmark-import-pdf).
 * Trois onglets :
 *  - Importer : un PDF du concurrent ou une recherche internet par l'IA ; chaque ligne lue face à notre produit
 *    proposé (choisir, confirmer, sans équivalent, ignorer) ; le concurrent, son code postal et les magasins où il
 *    compte ; « Vérifier » puis « Encoder » dans le benchmark de TFBuddy.
 *  - Classement : les concurrents de chaque magasin et de son franchisé, rangés par code postal.
 *  - Zones : les codes postaux de chaque magasin (plages au format de MétaDonnée).
 * Serveur : src/benchmark_import.php. Gabarit : benchmark-tpl.js ; style : benchmark.css (bk-). */
import { readOne, write } from './api.js';

const nf = (n, d) => n == null || isNaN(n) ? '—' : Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d, maximumFractionDigits: d }).replace(/[  ]/g, ' ');
const eur = (v, d = 2) => v == null ? '—' : nf(v, d) + ' €';
const pct = x => x == null ? '—' : (x > 0.005 ? '+' : x < -0.005 ? '−' : '') + nf(Math.abs(x * 100), 0) + ' %';
const sens = x => x == null ? '' : x > 0.02 ? 'plus' : x < -0.02 ? 'moins' : 'egal';
const plat = s => String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, ' ').trim();
const TYPES = [['artisan', 'Artisan'], ['chaine', 'Chaîne'], ['supermarche', 'Supermarché'], ['autre', 'Autre']];
const ETATS = { produit: ['ok', 'Rattachée'], attente: ['att', 'À confirmer'], sans: ['sans', 'Sans équivalent'], ignore: ['mu', 'Ignorée'], illi: ['ko', 'Illisible'] };
const CLASSES = { zone: ['ok', 'dans la zone'], ailleurs: ['att', 'zone d’un autre magasin'], hors: ['ko', 'hors zone'], inconnu: ['mu', 'code postal inconnu'], national: ['mu', 'national'] };

function lireListe(app, frais){
  const L = app.state.bkListe;
  if (!frais && L && (L.d || L.chargement)) { return; }
  app.setState({ bkListe: { chargement: true, d: L ? L.d : null } });
  readOne('/benchmarks/import').then(d => app.setState({ bkListe: { chargement: false, d: d || { error: 'injoignable' } } }));
}
function lireFiche(app, id){
  const F = app.state.bkFiche;
  if (F && F.id === id && (F.d || F.chargement)) { return; }
  app.setState({ bkFiche: { id, chargement: true, d: null }, bkPlan: null, bkRes: null, bkCherche: null, bkEdit: null });
  readOne('/benchmarks/import/' + id).then(d => { if ((app.state.bkFiche || {}).id === id) { app.setState({ bkFiche: { id, chargement: false, d: d || { error: 'injoignable' } } }); } });
}
function lireClassement(app, frais){
  const C = app.state.bkClass;
  if (!frais && C && (C.d || C.chargement)) { return; }
  app.setState({ bkClass: { chargement: true, d: C ? C.d : null } });
  readOne('/benchmarks/classement').then(d => app.setState({ bkClass: { chargement: false, d: d || { error: 'injoignable' } } }));
}
/** Une modification du relevé : le serveur rend la vue à jour. */
function majReleve(app, id, corps){
  app.setState({ bkMaj: true, bkPlan: null });
  write(app.source || 'api', 'PUT', '/benchmarks/import', Object.assign({ id }, corps)).then(r => {
    const maj = { bkMaj: false };
    if (r && r.ok && r.id) { maj.bkFiche = { id, chargement: false, d: r }; maj.bkListe = null; }
    else { maj.bkMsg = 'Modification refusée : ' + ((r && r.error) || 'serveur injoignable'); }
    app.setState(maj);
  });
}

export function valsBenchmark(app, c){
  const S = app.state;
  const onglet = S.bkOnglet || 'importer';
  const v = { onglet, msg: S.bkMsg || null, fermerMsg: () => app.setState({ bkMsg: null }),
    onglets: [['importer', 'Importer'], ['classement', 'Classement par code postal'], ['zones', 'Zones des magasins']].map(([k, nom]) => ({ nom, on: k === onglet, choisir: () => app.setState({ bkOnglet: k, bkMsg: null }) })) };
  c.bk = v;
  lireListe(app, false);
  const LL = S.bkListe || {};
  const L = LL.d && !LL.d.error ? LL.d : null;
  v.chargement = !!LL.chargement && !L;
  v.erreur = LL.d && LL.d.error ? 'Le serveur du cockpit n’a pas répondu : réessayez dans un instant.' : null;
  if (!L) { return; }
  const mags = L.magasins || [];
  const nomMag = {}; mags.forEach(m => { nomMag[m.id] = m.nom; });
  if (onglet === 'classement') { v.classement = vueClassement(app); return; }
  if (onglet === 'zones') { v.zones = vueZones(app, L); return; }

  // ── Importer : la liste à gauche ─────────────────────────────────────────────
  const sel = S.bkSel != null ? S.bkSel : ((L.imports || [])[0] ? (L.imports[0].id) : 'nouveau');
  v.liste = {
    nouveau: () => app.setState({ bkSel: 'nouveau', bkLecture: null }), nouveauOn: sel === 'nouveau',
    items: (L.imports || []).map(i => ({ titre: i.concurrent || i.titre, on: i.id === sel, source: i.source === 'pdf' ? 'PDF' : 'Internet',
      sous: [i.cp ? 'CP ' + i.cp : 'CP ?', (i.magasins || []).join(', ') || 'aucun magasin', i.lignes + ' ligne' + (i.lignes > 1 ? 's' : '')].join(' · '),
      date: i.creeLe.slice(8, 10) + '/' + i.creeLe.slice(5, 7), encode: i.statut === 'encode', choisir: () => app.setState({ bkSel: i.id, bkLecture: null }) })),
  };
  v.ia = L.ia || {}; v.panel = !!L.panel;
  if (sel === 'nouveau') { v.nouveau = vueNouveau(app, L); return; }
  lireFiche(app, sel);
  const FF = S.bkFiche || {};
  const D = FF.id === sel && FF.d && !FF.d.error ? FF.d : null;
  v.fiche = { chargement: !!FF.chargement && !D, erreur: FF.d && FF.d.error ? FF.d.error : null };
  if (!D) { return; }
  Object.assign(v.fiche, vueFiche(app, D, mags));
}

/* ── Nouveau relevé : PDF ou recherche internet ──────────────────────────────── */
function vueNouveau(app, L){
  const S = app.state;
  const F = S.bkForm || {};
  const mode = S.bkMode || 'pdf';
  const poser = k => e => app.setState({ bkForm: Object.assign({}, app.state.bkForm || {}, { [k]: e.target.value }) });
  const lec = S.bkLecture || {};
  const fin = r => {
    if (r && r.ok && r.id) { app.setState({ bkLecture: null, bkSel: r.id, bkListe: null, bkFiche: null, bkForm: null, bkMsg: r.deja ? 'Ce PDF a déjà été lu : voici son relevé.' : null }); }
    else { app.setState({ bkLecture: { erreur: (r && r.error) || 'serveur injoignable' } }); }
  };
  const max = L.maxOctets || 0;
  return {
    mode, modes: [['pdf', 'Un PDF du concurrent', 'tarif, folder, carte des fêtes, scan'], ['web', 'Recherche internet par l’IA', 'site, webshop, Facebook du concurrent']]
      .map(([k, nom, sous]) => ({ nom, sous, on: k === mode, choisir: () => app.setState({ bkMode: k, bkLecture: null }) })),
    iaOk: !!(L.ia || {}).configure, modele: (L.ia || {}).modele || '',
    fichierNom: F.fichierNom || '', max: max ? nf(max / 1048576, 0) + ' Mo' : '',
    setFichier: e => {
      const f = e.target.files && e.target.files[0]; if (!f) { return; }
      if (max && f.size > max) { app.setState({ bkLecture: { erreur: 'PDF trop lourd : ' + nf(f.size / 1048576, 1) + ' Mo (' + nf(max / 1048576, 0) + ' Mo au plus).' } }); return; }
      const r = new FileReader();
      r.onload = () => app.setState({ bkForm: Object.assign({}, app.state.bkForm || {}, { fichier: r.result, fichierNom: f.name, fichierTxt: f.name + ' · ' + nf(f.size / 1048576, 1) + ' Mo' }), bkLecture: null });
      r.readAsDataURL(f);
    },
    fichierTxt: F.fichierTxt || '',
    cnom: F.cnom || '', ccp: F.ccp || '', lieu: F.lieu || '', site: F.site || '', consignes: F.consignes || '',
    poserCnom: poser('cnom'), poserCcp: poser('ccp'), poserLieu: poser('lieu'), poserSite: poser('site'), poserConsignes: poser('consignes'),
    enCours: !!lec.enCours, erreur: lec.erreur || null,
    lire: mode === 'pdf' && F.fichier && !lec.enCours ? () => {
      app.setState({ bkLecture: { enCours: true } });
      write(app.source || 'api', 'POST', '/benchmarks/import/pdf', { fichier: F.fichier, nom: F.fichierNom, concurrent: { nom: F.cnom || '', cp: F.ccp || '' } }).then(fin);
    } : null,
    chercher: mode === 'web' && String(F.cnom || '').trim() && String(F.lieu || '').trim() && !lec.enCours ? () => {
      app.setState({ bkLecture: { enCours: true } });
      write(app.source || 'api', 'POST', '/benchmarks/import/web', { concurrent: F.cnom, lieu: F.lieu, site: F.site || '', consignes: F.consignes || '' }).then(fin);
    } : null,
  };
}

/* ── Un relevé : le concurrent, son classement, les lignes, l'encodage ───────── */
function vueFiche(app, D, mags){
  const S = app.state;
  const id = D.id;
  const E = S.bkEdit && S.bkEdit.id === id ? S.bkEdit.c : null;
  const C = Object.assign({}, D.concurrent, E || {});
  const poserC = k => e => app.setState({ bkEdit: { id, c: Object.assign({}, (app.state.bkEdit && app.state.bkEdit.id === id ? app.state.bkEdit.c : {}), { [k]: e.target.value }) } });
  const cl = D.classement || {};
  const parCp = cl.statut === 'zone' ? 'CP ' + cl.cp + (cl.commune ? ' · ' + cl.commune : '') + ' → zone de ' + (cl.nomsMagasins || []).join(' et ')
    : cl.statut === 'hors' ? 'CP ' + cl.cp + ' · hors zone : aucun magasin ne couvre ce code postal — cochez le ou les magasins'
      : 'Code postal inconnu : saisissez-le, ou cochez le ou les magasins';
  const choix = (i, val) => majReleve(app, id, { choix: { [i]: val } });
  const cat = D.catalogue || [];
  const qCh = S.bkCherche && S.bkCherche.id === id ? S.bkCherche : null;
  // Les lignes, rangées par rubrique dans l'ordre du document.
  const groupes = [];
  (D.lignes || []).forEach(l => {
    const g = (l.rubrique || (D.source === 'web' ? 'Pages web' : 'Sans rubrique'));
    let G = groupes.find(x => x.nom === g); if (!G) { G = { nom: g, lignes: [] }; groupes.push(G); }
    const dec = l.decision || {}, pr = l.proposition || {}, cmp = l.comparaison;
    const opts = [];
    const nomProp = pr.pid ? ((l.candidats || []).find(x => x.pid === pr.pid) || {}).nom || ('produit ' + pr.pid) : '';
    opts.push({ v: 'auto', nom: pr.statut === 'sans' || !pr.pid ? 'Proposé : sans équivalent' : (pr.statut === 'sur' ? 'Proposé : ' : 'À confirmer : ') + nomProp + ' (' + pr.score + ' %)' });
    (l.candidats || []).forEach(x => opts.push({ v: String(x.pid), nom: x.nom + ' · ' + eur(x.prix) + ' (' + x.score + ' %)' }));
    if (dec.pid && !(l.candidats || []).some(x => x.pid === dec.pid)) { opts.push({ v: String(dec.pid), nom: (l.produit || {}).nom || ('produit ' + dec.pid) }); }
    opts.push({ v: '0', nom: 'Sans équivalent chez nous' }, { v: '-1', nom: 'Ignorer la ligne' }, { v: 'chercher', nom: 'Chercher un autre produit…' });
    const courant = l.choix != null ? String(l.choix) : 'auto';
    const fmt = [l.g ? nf(l.g, 0) + ' g' : '', l.d ? 'Ø ' + nf(l.d, 0) + ' cm' : '', l.pieces ? 'les ' + l.pieces : '', l.parts ? l.parts + ' parts' : ''].filter(Boolean).join(' · ');
    const etat = ETATS[dec.etat] || ['mu', dec.etat];
    const ligne = {
      i: l.i, libelle: l.libelle, sous: [l.page ? 'p. ' + l.page : '', l.url ? l.url.replace(/^https?:\/\/(www\.)?/, '').slice(0, 48) : ''].filter(Boolean).join(' · '), url: l.url || '',
      prix: l.prix != null ? eur(l.prix) : '—', promo: l.promo != null ? 'promo ' + eur(l.promo) + (l.promoTexte ? ' · ' + l.promoTexte : '') : (l.promoTexte || ''), fmt,
      illi: dec.etat === 'illi', options: opts.map(o => Object.assign(o, { on: o.v === courant })),
      choisir: e => { const x = e.target.value; if (x === 'chercher') { app.setState({ bkCherche: { id, i: l.i, q: '' } }); return; } choix(l.i, x === 'auto' ? null : Number(x)); },
      confirmer: dec.etat === 'attente' && pr.pid ? () => choix(l.i, pr.pid) : null,
      etat: etat[1], ton: etat[0], appris: !!pr.appris && dec.etat === 'produit',
      confiance: l.etat === 'illi' ? null : pr.score, confTon: pr.statut,
      nous: cmp && !cmp.manque && l.produit ? eur(l.produit.prix) : (cmp && cmp.manque ? '0,00 €' : (l.produit ? eur(l.produit.prix) : '')),
      nousSous: cmp && cmp.unite ? eur(cmp.nous) + cmp.unite : (cmp && cmp.manque ? 'prix manquant au panel' : (cmp && l.produit && Math.abs(cmp.nous - l.produit.prix) > 0.005 ? eur(cmp.nous) + ' la pièce' : '')), manque: !!(cmp && cmp.manque),
      base: cmp && !cmp.manque ? cmp.base : '', euxSous: cmp && !cmp.manque && (cmp.unite || cmp.base.indexOf('→') >= 0 || l.pieces) ? 'eux ' + eur(cmp.eux) + cmp.unite : '',
      ecart: cmp && !cmp.manque && dec.etat !== 'ignore' && dec.etat !== 'sans' ? pct(cmp.ecart) : '', ecartTon: cmp && !cmp.manque ? (cmp.indicatif ? 'indicatif' : sens(cmp.ecart)) : '',
      ouvert: !!(qCh && qCh.i === l.i),
    };
    if (ligne.ouvert) {
      const q = plat(qCh.q);
      ligne.cherche = { q: qCh.q, id: 'bk-q-' + l.i, poser: e => app.setState({ bkCherche: { id, i: l.i, q: e.target.value } }), fermer: () => app.setState({ bkCherche: null }),
        resultats: (q.length >= 2 ? cat.filter(p => plat(p[1] + ' ' + p[2]).indexOf(q) >= 0) : []).slice(0, 10)
          .map(p => ({ nom: p[1], sous: p[2] + ' · ' + eur(p[3]) + ' · ' + p[0], prendre: () => { app.setState({ bkCherche: null }); choix(l.i, p[0]); } })) };
    }
    G.lignes.push(ligne);
  });
  const n = D.compte || {};
  const R = D.resume || {};
  const attente = (D.lignes || []).filter(l => (l.decision || {}).etat === 'attente' && (l.proposition || {}).pid);
  const plan = S.bkPlan && S.bkPlan.id === id ? S.bkPlan : null;
  const res = S.bkRes && S.bkRes.id === id ? S.bkRes : null;
  const enc = (D.encodage && D.encodage.magasins) || null;
  return {
    id, titre: C.nom || D.titre, source: D.source, creeLe: D.creeLe, nomOrigine: D.nomOrigine || '', octets: D.octets ? nf(D.octets / 1048576, 1) + ' Mo' : '',
    demande: D.demande || '', sources: (D.sources || []).map(u => ({ url: u, txt: u.replace(/^https?:\/\/(www\.)?/, '').slice(0, 70) })), remarques: D.remarques || '',
    modele: D.modele || '', jetons: D.jetons ? nf(D.jetons, 0) + ' jetons' : '', encode: D.statut === 'encode',
    c: { nom: C.nom || '', adresse: C.adresse || '', cp: C.cp || '', ville: C.ville || '', site: C.site || '',
      types: TYPES.map(([k, nom]) => ({ k, nom, on: (C.type || 'artisan') === k })),
      poserNom: poserC('nom'), poserAdresse: poserC('adresse'), poserCp: poserC('cp'), poserVille: poserC('ville'), poserSite: poserC('site'), poserType: poserC('type'),
      change: !!E, enregistrer: E ? () => { app.setState({ bkEdit: null }); majReleve(app, id, { concurrent: Object.assign({}, D.concurrent, E) }); } : null,
      annuler: E ? () => app.setState({ bkEdit: null }) : null },
    classement: { txt: parCp, ton: cl.statut === 'zone' ? 'ok' : (cl.statut === 'hors' ? 'ko' : 'mu'), cp: cl.cp || '' },
    magasins: mags.map(m => {
      const on = (D.magasins || []).includes(m.id);
      return { nom: m.nom, franchise: m.franchise || '', on, parCp: (cl.magasins || []).includes(m.id),
        basculer: () => majReleve(app, id, { magasins: on ? D.magasins.filter(x => x !== m.id) : D.magasins.concat([m.id]) }) };
    }),
    date: D.dateReleve || '', poserDate: e => majReleve(app, id, { date: e.target.value }),
    tuiles: [['Lignes lues', String((D.lignes || []).length), D.source === 'pdf' ? 'dans le PDF' : 'sur internet'],
      ['Rattachées', String(n.produit || 0), 'à un de nos produits'], ['À confirmer', String(n.attente || 0), attente.length ? 'un clic chacune' : 'rien en attente', n.attente ? 'att' : ''],
      ['Sans équivalent', String(n.sans || 0), (n.ignore || 0) + ' ignorée(s) · ' + (n.illi || 0) + ' illisible(s)'],
      ['Écart médian', R.mediane == null ? '—' : pct(R.mediane), R.compares ? 'nous face à eux · ' + R.compares + ' comparé' + (R.compares > 1 ? 's' : '') : 'rien à comparer', sens(R.mediane)],
      ['Nous plus chers', String(R.plus || 0), (R.moins || 0) + ' où nous sommes moins chers']],
    groupes, catalogueLu: !!D.catalogueLu, magasinRef: (mags.find(m => m.id === D.magasinRef) || {}).nom || '',
    toutConfirmer: attente.length ? () => { const ch = {}; attente.forEach(l => { ch[l.i] = l.proposition.pid; }); majReleve(app, id, { choix: ch }); } : null, nAttente: attente.length,
    maj: !!S.bkMaj,
    // Vérifier, puis encoder.
    verifier: !(plan && plan.enCours) ? () => {
      app.setState({ bkPlan: { id, enCours: true }, bkRes: null });
      write(app.source || 'api', 'POST', '/benchmarks/import/encoder', { id, apercu: true }).then(r => app.setState({ bkPlan: { id, d: r } }));
    } : null,
    plan: plan && plan.d ? (plan.d.ok ? { lignes: plan.d.items, magasins: (plan.d.magasins || []).map(m => ({ nom: m.magasin, franchise: m.franchise,
      txt: (m.nouveau ? 'nouveau concurrent' : 'concurrent existant n° ' + m.concurrent) + ' · ' + m.items + ' ligne(s), dont ' + m.avecProduit + ' rattachée(s)' })),
      fiche: plan.d.fiche || {}, observation: plan.d.observation || {} } : { refus: plan.d.error || 'refusé' }) : null,
    planEnCours: !!(plan && plan.enCours),
    encoder: plan && plan.d && plan.d.ok && !(res && res.enCours) && !n.attente ? () => {
      app.setState({ bkRes: { id, enCours: true } });
      write(app.source || 'api', 'POST', '/benchmarks/import/encoder', { id }).then(r => {
        app.setState({ bkRes: { id, d: r }, bkPlan: null, bkListe: null, bkClass: null, bkFiche: null });
      });
    } : null,
    resEnCours: !!(res && res.enCours),
    resultat: res && res.d ? (res.d.magasins ? res.d.magasins.map(m => ({ ok: m.etat === 'ok' || m.etat === 'deja', txt: m.magasin + ' : ' + (m.etat === 'deja' ? 'déjà encodé' : (m.relus != null ? m.relus + ' / ' + m.items + ' ligne(s) relue(s) dans TFBuddy' : '')) + (m.message && m.etat !== 'deja' ? ' — ' + m.message : '') }))
      : [{ ok: false, txt: res.d.error || 'refusé' }]) : (enc ? enc.map(m => ({ ok: m.etat === 'ok' || m.etat === 'deja', txt: m.magasin + ' : ' + (m.relus != null ? m.relus + ' / ' + m.items + ' ligne(s) dans TFBuddy' : m.etat) })) : null),
    encodeLe: D.encodage && D.encodage.le ? D.encodage.le : '',
    supprimer: () => { if (typeof confirm === 'function' && !confirm('Supprimer ce relevé et son PDF ? Ce qui est déjà encodé dans TFBuddy y reste.')) { return; }
      write(app.source || 'api', 'DELETE', '/benchmarks/import/' + id).then(() => app.setState({ bkSel: null, bkFiche: null, bkListe: null })); },
  };
}

/* ── Classement : quel concurrent, sur quel franchisé ───────────────────────── */
function vueClassement(app){
  lireClassement(app, false);
  const CC = app.state.bkClass || {};
  const D = CC.d && !CC.d.error ? CC.d : null;
  const v = { chargement: !!CC.chargement && !D, erreur: CC.d && CC.d.error ? 'Le classement n’a pas pu être lu.' : null, relire: () => lireClassement(app, true), relecture: !!CC.chargement };
  if (!D) { return v; }
  const ligne = (x, ici) => {
    const k = CLASSES[x.classement] || ['mu', x.classement];
    return { nom: x.nom, type: x.type || '—', cp: x.cp || '', commune: x.commune || x.ville || '', ton: k[0],
      classe: x.classement === 'ailleurs' ? 'zone de ' + (x.aussi || []).join(', ') : (x.classement === 'zone' && (x.aussi || []).length ? 'dans la zone · aussi ' + x.aussi.join(', ') : k[1]),
      source: x.source === 'tfbuddy' ? 'TFBuddy' : (x.source === 'import' ? 'relevé à encoder' : 'relevé web du cockpit'),
      releves: x.source === 'tfbuddy' ? x.observations + ' relevé' + (x.observations > 1 ? 's' : '') : '', dernier: x.dernier ? x.dernier.slice(8, 10) + '/' + x.dernier.slice(5, 7) + '/' + x.dernier.slice(2, 4) : '',
      ouvrir: x.source === 'import' && x.id ? () => app.setState({ bkOnglet: 'importer', bkSel: x.id }) : null, chez: ici ? '' : (x.chez || '') };
  };
  v.magasins = (D.magasins || []).map(m => {
    const cs = m.concurrents || [];
    const n = k => cs.filter(x => x.classement === k).length;
    return { nom: m.nom, franchise: m.franchise || '—', cp: m.cp || '?', zone: m.zone.n + ' code' + (m.zone.n > 1 ? 's' : '') + ' postaux', codes: m.zone.codes.slice(0, 40).join(' · ') + (m.zone.codes.length > 40 ? '…' : ''),
      compte: [n('zone') + ' dans la zone', n('ailleurs') + ' d’une autre zone', n('hors') + ' hors zone', n('inconnu') + ' sans code postal', n('national') + ' nationaux'].filter(t => !/^0 /.test(t)).join(' · '),
      lignes: cs.map(x => ligne(x, true)) };
  });
  v.horsZone = (D.horsZone || []).map(x => ligne(x, false));
  v.tfbuddy = !!D.tfbuddy; v.lu = D.lu || '';
  v.zones = () => app.setState({ bkOnglet: 'zones' });
  return v;
}

/* ── Zones : les codes postaux de chaque magasin ─────────────────────────────── */
const plageTxt = p => (p.de === p.a ? String(p.de) : p.de + '-' + p.a) + (p.libelle ? ' ' + p.libelle : '');
function lirePlages(txt, shopId){
  const out = [], ko = [];
  String(txt || '').split(/\n|;/).map(s => s.trim()).filter(Boolean).forEach(s => {
    const m = s.match(/^(\d{4})(?:\s*[-–à]\s*(\d{4}))?\s*(.*)$/);
    if (!m) { ko.push(s); return; }
    out.push({ de: Number(m[1]), a: Number(m[2] || m[1]), shopId: String(shopId), libelle: m[3].replace(/^[·,:-]\s*/, '').trim() });
  });
  return { plages: out, ko };
}
function vueZones(app, L){
  const S = app.state;
  const mags = L.magasins || [];
  const ed = S.bkZones || {};
  const plages = L.plages || [];
  const txtDe = id => ed[id] != null ? ed[id] : plages.filter(p => String(p.shopId) === String(id)).map(plageTxt).join('\n');
  const change = Object.keys(ed).length > 0;
  const test = String(S.bkTest || '').trim();
  const toutes = () => [].concat(...mags.map(m => lirePlages(txtDe(m.id), m.id).plages));
  let testTxt = '';
  if (/^\d{4}$/.test(test)) {
    const n = Number(test);
    const qui = mags.filter(m => toutes().some(p => String(p.shopId) === String(m.id) && n >= p.de && n <= p.a));
    testTxt = qui.length ? 'CP ' + test + ' → ' + qui.map(m => m.nom + (m.franchise ? ' (' + m.franchise + ')' : '')).join(' et ') : 'CP ' + test + ' · hors zone : aucun magasin ne le couvre';
  }
  const res = S.bkZonesRes;
  return {
    parDefaut: !!L.zonesParDefaut && !change,
    magasins: mags.map(m => {
      const t = txtDe(m.id), lu = lirePlages(t, m.id);
      return { nom: m.nom, franchise: m.franchise || '—', cp: m.cp || '?', id: 'bk-z-' + m.id, txt: t, n: lu.plages.length, ko: lu.ko,
        poser: e => app.setState({ bkZones: Object.assign({}, app.state.bkZones || {}, { [m.id]: e.target.value }), bkZonesRes: null }) };
    }),
    test, poserTest: e => app.setState({ bkTest: e.target.value }), testTxt,
    change, enCours: !!(res && res.enCours),
    enregistrer: change ? () => {
      app.setState({ bkZonesRes: { enCours: true } });
      write(app.source || 'api', 'PUT', '/benchmarks/zones', { plages: toutes() }).then(r => app.setState({ bkZonesRes: { d: r }, bkZones: r && r.ok ? null : app.state.bkZones, bkListe: r && r.ok ? null : app.state.bkListe, bkClass: null }));
    } : null,
    annuler: change ? () => app.setState({ bkZones: null, bkZonesRes: null }) : null,
    defaut: !L.zonesParDefaut ? () => {
      if (typeof confirm === 'function' && !confirm('Revenir aux zones par défaut (codes postaux à moins de 10 km de chaque boutique) ?')) { return; }
      app.setState({ bkZonesRes: { enCours: true } });
      write(app.source || 'api', 'PUT', '/benchmarks/zones', { defaut: true }).then(r => app.setState({ bkZonesRes: { d: r }, bkZones: null, bkListe: null, bkClass: null }));
    } : null,
    resultat: res && res.d ? (res.d.ok ? 'Zones enregistrées : ' + (res.d.plages || []).length + ' code(s) ou plage(s).' : 'Refusé : ' + (res.d.error || '?')) : '',
    resultatOk: !!(res && res.d && res.d.ok),
  };
}
