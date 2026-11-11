/* Cockpit CEO — Catalogue › Structure de gamme (décidée le 11/11/2026).
 *
 * Par catégorie du panel, sur les ventes réseau de douze mois : le standard (80 % du CA ou de la
 * marge, 6 au plus), les quatre saisons et trois périodes (4 au plus), ce qui sort (sous 3 % de la
 * catégorie). Les règles sont des variables du réseau (PUT /produits/structure) ; on les règle dans
 * la ligne de règles, « Rafraîchir » relit les ventes et recalcule, « Enregistrer ces règles » les
 * garde pour tout le monde. Le calcul est celui de structure-regles.js — le même que la maquette.
 *
 * Gestes : une décision par référence (garder en standard, garder hors standard), le retrait du
 * catalogue des références sous le seuil (POST /produits/structure/desactiver — le serveur revérifie),
 * l'assortiment obligatoire aligné (POST /produits/structure/appliquer — même écriture que
 * PUT /production/obligatoire/{ref}), l'export CSV.
 */
import { readOne } from './api.js';
import { structurer, REGLES, SAISONS, PERIODES } from './structure-regles.js';

const DEFAUT = { base: 'ca', std: REGLES.stdPart * 100, max: REGLES.stdMax, sai: REGLES.saiMax, seuil: REGLES.seuil * 100 };
const BORNES = { std: [50, 100], max: [1, 20], sai: [1, 10], seuil: [0, 20] };
const SA = Object.fromEntries(SAISONS.map(s => [s.id, s]));
const PE = Object.fromEntries(PERIODES.map(s => [s.id, s]));

const nb = n => Math.round(n).toLocaleString('fr-BE');
const eur = n => n == null ? '—' : nb(n) + ' €';
const keur = n => n >= 1e6 ? (n / 1e6).toLocaleString('fr-BE', { maximumFractionDigits: 2 }) + ' M€' : nb(n / 1000) + ' k€';
const pc = (x, d) => x == null ? '—' : (x * 100).toLocaleString('fr-BE', { maximumFractionDigits: d || 0, minimumFractionDigits: d || 0 }) + ' %';
const vx = x => Number(x).toLocaleString('fr-BE') + ' %';
const pl = (n, m, f) => nb(n) + ' ' + (n > 1 ? (f || m + 's') : m);
const somme = a => a.reduce((s, x) => s + x, 0);

/** Une règle ramenée dans ses bornes ; un nombre illisible garde la valeur d'avant. */
function borne(k, v, repli){
  const x = parseFloat(String(v).replace(',', '.'));
  if (!isFinite(x)) { return repli; }
  const [a, b] = BORNES[k];
  const y = Math.min(b, Math.max(a, x));
  return k === 'max' || k === 'sai' ? Math.round(y) : Math.round(y * 10) / 10;
}
const memeRegles = (a, b) => ['base', 'std', 'max', 'sai', 'seuil'].every(k => String(a[k]) === String(b[k]));
const opts = (r, base, dec) => ({ base, stdPart: r.std / 100, saiPart: r.std / 100, stdMax: r.max, saiMax: r.sai, seuil: r.seuil / 100, decisions: dec });

/* --- Lecture ------------------------------------------------------------------------- */
export function sgCharge(app, force){
  const S = app.state;
  if (!force && (app.D.sgData || app._sgEnCours)) { return; }
  app._sgEnCours = true;
  if (force) { app.setState({ sgLecture: true }); }
  readOne('/produits/structure').then(d => {
    app._sgEnCours = false;
    app.D.sgData = d || { indispo: true, motif: 'API injoignable' };
    app._sgMemo = null;
    const rg = d && d.reglage;
    // Les règles du réseau deviennent celles du calcul, sauf si l'on est en train d'en essayer d'autres.
    const patch = { sgLecture: false };
    if (rg && (!S.sgRegles || !S.sgEssai)) { patch.sgRegles = Object.assign({}, DEFAUT, rg.regles); patch.sgSaisie = null; }
    app.setState(patch);
  });
}

/** Le calcul, gardé tant que ni les données, ni les règles, ni les décisions ne changent. */
function calcul(app){
  const d = app.D.sgData, S = app.state;
  if (!d || d.indispo || !d.produits) { return null; }
  const r = S.sgRegles || Object.assign({}, DEFAUT, (d.reglage || {}).regles);
  const dec = (d.reglage || {}).decisions || {};
  const cle = JSON.stringify([d.lu, d.produits.length, r, dec]);
  if (app._sgMemo && app._sgMemo.cle === cle) { return app._sgMemo; }
  const R = structurer(d, opts(r, r.base, dec));
  const Ralt = structurer(d, opts(r, r.base === 'ca' ? 'mg' : 'ca', dec));
  const mc = (d.bornes || []).map(b => +String(b[0]).slice(5, 7));
  app._sgMemo = { cle, R, alt: new Map(Ralt.produits.map(p => [p.id, p])), regles: r, idx: mm => mc.indexOf(mm) };
  return app._sgMemo;
}

/* --- Gestes -------------------------------------------------------------------------- */
function message(app, txt, ko){
  app.setState({ sgMsg: { txt, ko: !!ko } });
  clearTimeout(app._sgMsgT);
  app._sgMsgT = setTimeout(() => { if (app.state.sgMsg && app.state.sgMsg.txt === txt) { app.setState({ sgMsg: null }); } }, 9000);
}

/** « Rafraîchir » : les valeurs saisies deviennent les règles du calcul, et les ventes sont relues.
 *  Sans relecture (changement de base), le calcul suit tout de suite. */
function rafraichir(app, relire = true){
  const S = app.state, avant = calcul(app);
  const r0 = S.sgRegles || DEFAUT, s = Object.assign({}, r0, S.sgSaisie || {});
  const r = { base: s.base === 'mg' ? 'mg' : 'ca' };
  Object.keys(BORNES).forEach(k => { r[k] = borne(k, s[k], r0[k]); });
  const t0 = avant ? avant.R.totaux : null;
  const enreg = ((app.D.sgData || {}).reglage || {}).regles || DEFAUT;
  app.setState({ sgRegles: r, sgSaisie: null, sgEssai: !memeRegles(r, Object.assign({}, DEFAUT, enreg)) });
  app._sgApres = () => {
    const m = calcul(app); if (!m || !t0) { return; }
    const t1 = m.R.totaux, h = new Date().toLocaleTimeString('fr-BE', { hour: '2-digit', minute: '2-digit' });
    message(app, 'Recalculé à ' + h + ' — standard ' + t0.standard + ' → ' + t1.standard + ', à désactiver ' + t0.desactiver + ' → ' + t1.desactiver);
  };
  if (relire) { sgCharge(app, true); } else { app.setState({}); }
}

function enregistrerRegles(app){
  const r = app.state.sgRegles;
  app.setState({ sgBusy: true });
  app.api('PUT', '/produits/structure', { regles: r }).then(res => {
    app.setState({ sgBusy: false });
    if (!res || res.ok === false) { return; }
    if (app.D.sgData && res.reglage) { app.D.sgData.reglage = res.reglage; app._sgMemo = null; }
    app.setState({ sgEssai: false });
    message(app, 'Règles enregistrées pour le réseau : désactiver sous ' + vx(r.seuil) + ', standard ' + vx(r.std) + ', ' + r.max + ' max, saisons ' + r.sai + ' max');
  });
}

function decider(app, p, val){
  const d = app.D.sgData; if (!d) { return; }
  app.api('PUT', '/produits/structure', { decisions: { [p.id]: val || null }, noms: { [p.id]: p.nom } }).then(res => {
    if (!res || res.ok === false) { return; }
    if (res.reglage) { d.reglage = res.reglage; } else {
      const dec = Object.assign({}, (d.reglage || {}).decisions); if (val) { dec[p.id] = val; } else { delete dec[p.id]; }
      d.reglage = Object.assign({}, d.reglage, { decisions: dec });
    }
    app._sgMemo = null;
    message(app, p.nom + ' : ' + (val ? motDecision(val) : 'retour à la proposition'));
  });
}

/** Les références à retirer, cochées dans la liste « À désactiver ». */
/** Les références à retirer : proposées sous le seuil, et non gardées par une décision. */
function aDesactiver(m){ return m.R.produits.filter(p => p.actif && p.statut === 'desactiver'); }

/** Une décision dite en mots — la même liste que la liste déroulante, et que le journal du serveur. */
function motDecision(d){
  if (d === 'standard') { return 'gardée en standard'; }
  if (d === 'garder') { return 'gardée hors standard'; }
  const [t, k] = String(d).split(':');
  return (t === 'saison' ? 'gardée en saison ' + (SA[k] || {}).nom : 'gardée pour ' + (PE[k] || {}).nom);
}
/** Les choix de la liste déroulante, dans l'ordre : garder en standard ou hors standard, puis une saison, puis une période. */
const CHOIX = [['standard', 'Garder en standard'], ['garder', 'Garder hors standard']]
  .concat(SAISONS.map(s => ['saison:' + s.id, 'Saison : ' + s.nom.toLowerCase()]))
  .concat(PERIODES.map(e => ['periode:' + e.id, 'Période : ' + e.nom]));

function desactiver(app){
  const m = calcul(app); if (!m) { return; }
  const ids = aDesactiver(m).map(p => p.id);
  // Par lots de 40 : la version livrée écrit chaque retrait dans TFBuddy et le relit, et n'en accepte pas
  // plus par appel (comme « Retirer du catalogue ») ; ici, sans TFBuddy, les lots ne changent rien.
  const lots = []; for (let i = 0; i < ids.length; i += 40) { lots.push(ids.slice(i, i + 40)); }
  const bilan = { faits: 0, refuses: [], echecs: [], alertes: [], inchangees: 0 };
  app.setState({ sgBusy: true, sgProgres: '0 / ' + ids.length });
  const suivant = k => {
    if (k >= lots.length) { fin(); return; }
    app.api('POST', '/produits/structure/desactiver', { ids: lots[k], confirmer: true }).then(res => {
      if (!res || res.ok === false) { fin(res && res.error); return; }
      bilan.faits += (res.faits || []).length; bilan.inchangees += (res.inchangees || []).length;
      bilan.refuses.push(...(res.refuses || [])); bilan.echecs.push(...(res.echecs || [])); bilan.alertes.push(...(res.alertes || []));
      app.setState({ sgProgres: Math.min(ids.length, (k + 1) * 40) + ' / ' + ids.length });
      suivant(k + 1);
    });
  };
  const fin = err => {
    const resume = bilan.faits + ' retirée(s) du catalogue' + (bilan.refuses.length ? ', ' + bilan.refuses.length + ' refusée(s)' : '')
      + (bilan.echecs.length ? ', ' + bilan.echecs.length + ' refusée(s) par TFBuddy' : '') + (bilan.inchangees ? ', ' + bilan.inchangees + ' déjà inactive(s)' : '')
      + (bilan.alertes.length ? ' — ' + bilan.alertes.length + ' alerte(s) à vérifier' : '') + (err ? ' — arrêté : ' + err : '');
    app.setState({ sgBusy: false, sgProgres: null, sgConfirm: null,
      sgResultat: { quoi: 'desactiver', resume, refuses: [...bilan.refuses, ...bilan.echecs, ...bilan.alertes.map(a => ({ nom: a.nom, motif: a.alerte }))] } });
    app.D.prodCatalogue = null;   // le catalogue a changé : la fiche et l'assortiment le relisent
    sgCharge(app, true);
  };
  suivant(0);
}

/** Ce que « Appliquer à l'assortiment » enverra : standard, saisons et périodes, et les obligatoires qui sortent. */
function planAssortiment(m, retirer){
  const P = m.R.produits.filter(p => p.actif && p.nature !== 'hors');
  const standard = P.filter(p => p.statut === 'standard');
  const saison = p => {
    if (p.statut === 'periode') { return p.periode; }
    // Une gamme du panel par référence : la saison retenue où elle vend le plus.
    const parS = (p.retenue || []).map(id => [id, somme((SA[id].mois || []).map(mm => (p.profil || [])[m.idx(mm)] || 0))]);
    parS.sort((a, b) => b[1] - a[1]);
    return parS.length ? parS[0][0] : null;
  };
  const saisons = P.filter(p => p.statut === 'saison' || p.statut === 'periode').map(p => ({ ref: p.ref || String(p.id), saison: saison(p), p }));
  const retenues = new Set([...standard, ...saisons.map(x => x.p)].map(p => p.id));
  const sortent = P.filter(p => p.must && !retenues.has(p.id));
  return { standard, saisons, sortent, inactives: m.R.produits.filter(p => !p.actif && p.statut === 'periode'), retirer: !!retirer };
}

function appliquer(app){
  const m = calcul(app); if (!m) { return; }
  const pa = planAssortiment(m, app.state.sgRetirer);
  app.setState({ sgBusy: true });
  app.api('POST', '/produits/structure/appliquer', {
    standard: pa.standard.map(p => p.ref || String(p.id)),
    saisons: pa.saisons.map(x => ({ ref: x.ref, saison: x.saison })),
    retirer: pa.retirer ? pa.sortent.map(p => p.ref || String(p.id)) : [], confirmer: true,
  }).then(res => {
    app.setState({ sgBusy: false, sgConfirm: null });
    if (!res || res.ok === false) { return; }
    app.setState({ sgResultat: { quoi: 'appliquer', resume: res.resume, refuses: (res.erreurs || []).map(e => ({ nom: e.ref, motif: e.motif })) } });
    app.D.prodCatalogue = null;
    sgCharge(app, true);
  });
}

function exporter(m){
  const l = [['Groupe', 'Catégorie', 'Référence', 'Nom', 'Statut', 'Proposition', 'Décision', 'Part de la catégorie (%)', 'CA 12 mois (€)', 'Marge nette 12 mois (€)', 'Saison ou période', 'Obligatoire aujourd’hui', 'Active'].concat(SAISONS.map(x => 'Pièces ' + x.nom.toLowerCase()))];
  m.R.produits.filter(p => p.nature !== 'retiree').forEach(p => {
    l.push([p.grp, p.cat, p.ref || p.id, p.nom, mot(p, p.statut), mot(p, p.propose || p.statut, true), p.decide ? motDecision(p.decide) : '',
      p.part != null ? (p.part * 100).toFixed(2).replace('.', ',') : '', p.u ? Math.round(p.ca) : '', p.mg != null ? Math.round(p.mg) : '',
      p.retenue ? p.retenue.map(s => SA[s].nom).join(' + ') : p.periode ? PE[p.periode].nom : '', p.must ? 'oui' : '', p.actif ? 'oui' : 'non'].concat(SAISONS.map(x => p.parSaison ? p.parSaison[x.id] : '')));
  });
  const csv = l.map(r => r.map(c => /[;"\n]/.test(String(c)) ? '"' + String(c).replace(/"/g, '""') + '"' : c).join(';')).join('\n');
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' }));
  a.download = 'structure-gamme-' + new Date().toISOString().slice(0, 10) + '.csv';
  document.body.appendChild(a); a.click(); a.remove();
}

/** Le statut en un mot ; « prop » : celui de la proposition, avant décision (sa saison, sa période d'origine). */
function mot(p, s, prop){
  const ret = prop && p.decide ? p.proposeRetenue : p.retenue, per = prop && p.decide ? p.proposePeriode : p.periode;
  return ({ standard: 'Standard', saison: ret ? ret.map(x => SA[x].nom).join(' · ') : 'Saison', periode: per ? PE[per].nom : 'Période',
    desactiver: 'À désactiver', arbitrer: 'À arbitrer', observation: 'En observation', garder: 'Gardée hors standard', hors: 'Hors structure' })[s] || '—';
}

/* --- Valeurs de l'écran ---------------------------------------------------------------- */
export function valsStructure(app, c){
  const S = app.state, D = app.D;
  sgCharge(app, false);
  const d = D.sgData;
  const m = calcul(app);
  if (app._sgApres && m && !S.sgLecture) { const f = app._sgApres; app._sgApres = null; setTimeout(f, 0); }
  const r = (m && m.regles) || S.sgRegles || DEFAUT;
  const saisie = Object.assign({}, r, S.sgSaisie || {});
  const enreg = Object.assign({}, DEFAUT, ((d || {}).reglage || {}).regles || {});
  const vue = ['1', '3', '4'].includes(S.sgVue) ? S.sgVue : '1';
  const g = {
    chargement: !d, lecture: !!S.sgLecture,
    indispo: d && (d.indispo || !d.produits) ? (d.motif || 'données illisibles') : null,
    msg: S.sgMsg, resultat: S.sgResultat ? Object.assign({}, S.sgResultat, { fermer: () => app.setState({ sgResultat: null }) }) : null,
    busy: !!S.sgBusy,
    regles: r, enreg, vx,
    saisie: Object.assign({}, saisie, {
      set: k => e => app.setState({ sgSaisie: Object.assign({}, S.sgSaisie || {}, { [k]: e.target.value }) }),
      base: k => () => { app.setState({ sgSaisie: Object.assign({}, app.state.sgSaisie || {}, { base: k }) }); rafraichir(app, false); },
      change: k => String(borne(k, saisie[k], r[k])) !== String(r[k]),
    }),
    rafraichir: () => rafraichir(app),
    essai: !memeRegles(r, enreg),
    enregistrer: () => enregistrerRegles(app),
    revenir: () => app.setState({ sgRegles: enreg, sgSaisie: null, sgEssai: false }),
    vue: S.sgCat && vue === '1' ? '2' : vue,
    vues: [['1', 'Par catégorie'], ['3', 'Saisons et périodes'], ['4', 'À désactiver']].map(([k, l]) => ({ nom: l, on: vue === k, n: k === '4' && m ? m.R.totaux.desactiver : null,
      choisir: () => app.setState({ sgVue: k, sgCat: null }) })),
    lu: d && d.lu ? d.lu.split('-').reverse().join('/') : '', magasins: d && d.magasins ? d.magasins.length : 0,
    du: d && d.bornes && d.bornes[0] ? d.bornes[0][0].split('-').reverse().join('/') : '', au: d && d.bornes && d.bornes.length ? d.bornes[d.bornes.length - 1][1].split('-').reverse().join('/') : '',
    plancher: d ? d.plancher : 0, mois: d ? d.mois : [],
  };
  c.sg = g;
  if (!m) { return; }
  const R = m.R, T = R.totaux;
  g.R = R; g.T = T; g.SA = SA; g.PE = PE; g.mot = mot;
  g.baseCourt = r.base === 'mg' ? 'de la marge' : 'du CA'; g.baseNom = r.base === 'mg' ? 'marge nette' : 'chiffre d’affaires';
  g.autreBase = r.base === 'ca' ? 'la marge' : 'le CA';
  g.alt = m.alt;
  g.exporter = () => exporter(m);
  g.ouvrir = cat => () => app.setState({ sgVue: '1', sgCat: cat });
  g.fermerCat = () => app.setState({ sgCat: null });
  g.decider = p => e => decider(app, p, e.target.value);
  const fusion = (d.produits || []).filter(p => (p.anciens || []).length).length;
  const dbl = R.produits.filter(p => p.actif && p.doublon);
  const musts = R.produits.filter(p => p.actif && p.must && p.nature !== 'hors');
  g.lecture1 = { fusion, doublons: [...new Set(dbl.map(p => p.nom))], musts: musts.length, mustStd: musts.filter(p => p.statut === 'standard').length };

  // Vue 2 : une catégorie ouverte.
  if (g.vue === '2') {
    const C = R.cats.find(x => x.cat === S.sgCat);
    if (!C) { g.vue = '1'; } else { g.cat = C; }
  }
  // Vue 4 : la liste à désactiver, cochée par défaut.
  g.choix = CHOIX; g.motDecision = motDecision;
  g.deplierTout = () => app.setState({ sgDeplie: S.sgDeplie === '*' ? '' : '*' });
  g.deplie = S.sgDeplie === undefined ? null : S.sgDeplie;
  g.deplier = cat => () => app.setState({ sgDeplie: S.sgDeplie === cat ? '' : cat });
  const off = aDesactiver(m);
  g.aDesactiver = off;
  g.offCa = somme(off.map(p => p.caPer || 0));
  g.offVendues = off.filter(p => p.u).length;
  g.offMusts = off.filter(p => p.must).length;

  // Les confirmations.
  g.confirm = S.sgConfirm || null;
  g.progres = S.sgProgres || null;
  g.demanderDesactiver = off.length ? () => app.setState({ sgConfirm: 'desactiver' }) : null;
  g.demanderAppliquer = () => app.setState({ sgConfirm: 'appliquer' });
  g.annuler = () => app.setState({ sgConfirm: null });
  g.desactiver = () => desactiver(app);
  g.appliquer = () => appliquer(app);
  if (g.confirm === 'appliquer') {
    const pa = planAssortiment(m, S.sgRetirer);
    g.plan = { standard: pa.standard.length, saisons: pa.saisons.filter(x => x.p.statut === 'saison').length, periodes: pa.saisons.filter(x => x.p.statut === 'periode').length,
      sortent: pa.sortent.length, sortentNoms: pa.sortent.slice(0, 8).map(p => p.nom), inactives: pa.inactives.map(p => p.nom), retirer: !!S.sgRetirer,
      basculerRetirer: () => app.setState({ sgRetirer: !S.sgRetirer }) };
  }
}

export { borne, DEFAUT, nb, eur, keur, pc, vx, pl, somme };
