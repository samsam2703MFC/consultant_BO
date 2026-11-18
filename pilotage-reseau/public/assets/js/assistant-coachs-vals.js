/* Cockpit CEO — ERP franchisé › Assistant · coachs (#/assistant, 12/11/2026, Sam : « dans le cockpit, le ton des
 * coachs : sur quoi il met l'accent, ce qu'il vérifie — par rapport au budget, au trafic, aux tâches réalisées » ;
 * « garde toutes les demandes dans une table, pour après créer des agents spécifiques »).
 *
 * Deux onglets :
 *  - Coachs : Mr. Pierre, le Coach et le Coach Vente, coachs business de pointe au ton différent. Pour chacun : nom,
 *    accroche, ton, pour le budget, le trafic et les contrôles qualité, le poids (accent fort, suivi, n'en parle pas)
 *    et ce qu'il vérifie, et ses objectifs 2026 (18/11/2026 : food cost, personnel, clients scannés, menus, articles
 *    par ticket, panier ; une cible vide n'est pas suivie). Un brouillon reste un brouillon jusqu'à « Publier »
 *    (POST /assistant/coachs). GET /assistant/coachs.
 *  - Questions posées : ce que les franchisés demandent (GET /assistant/questions-posees?jours=), pour décider plus
 *    tard des agents spécialisés.
 *
 * Remplit `common.asc` ; le gabarit est tplAssistantCoachs (assistant-coachs-tpl.js). */
import { readOne } from './api.js';

const nf = n => n == null ? '—' : Number(n).toLocaleString('fr-BE').replace(/ /g, ' ');

function charge(app, quoi, force){
  const S = app.state, cle = quoi === 'q' ? 'q|' + (S.ascJours || 30) : 'c', b = S['asc' + quoi];
  if (!force && b && b.cle === cle && (b.d || b.chargement)) { return; }
  if (app['_asc' + quoi] === cle && !force) { return; }
  app['_asc' + quoi] = cle;
  app.setState({ ['asc' + quoi]: { cle, chargement: true, d: b && b.cle === cle ? b.d : null } });
  readOne(quoi === 'q' ? '/assistant/questions-posees?jours=' + (S.ascJours || 30) : '/assistant/coachs').then(d => {
    app['_asc' + quoi] = null;
    app.setState({ ['asc' + quoi]: { cle, chargement: false, d: d || { error: 'injoignable' } } });
  });
}

/** Le brouillon d'un coach : ascB[coach] = {nom, dit, ton, accents:{axe:{niveau, verifie}}, objectifs:{cle: saisie}} (seuls les champs touchés). */
function brouillon(app, k, patch, axe){
  const B = JSON.parse(JSON.stringify(app.state.ascB || {}));
  B[k] = B[k] || {};
  if (axe === 'objectifs') { B[k].objectifs = Object.assign({}, B[k].objectifs || {}, patch); }
  else if (axe) { B[k].accents = B[k].accents || {}; B[k].accents[axe] = Object.assign({}, B[k].accents[axe] || {}, patch); }
  else { Object.assign(B[k], patch); }
  app.setState({ ascB: B });
}

function publier(app, d){
  const S = app.state;
  if (S.ascEnvoi) { return; }
  const coachs = {};
  Object.keys(d.coachs).forEach(k => {
    const c = d.coachs[k], b = (S.ascB || {})[k] || {};
    coachs[k] = { nom: b.nom != null ? b.nom : c.nom, dit: b.dit != null ? b.dit : c.dit, ton: b.ton != null ? b.ton : c.ton, accents: {},
      objectifs: Object.assign({}, c.objectifs || {}, b.objectifs || {}) };
    Object.keys(d.axes).forEach(ax => { coachs[k].accents[ax] = Object.assign({}, c.accents[ax], (b.accents || {})[ax] || {}); });
  });
  app.setState({ ascEnvoi: true, ascMsg: null });
  app.api('POST', '/assistant/coachs', { coachs, qui: 'Cockpit' }).then(r => {
    const ok = r && r.ok !== false && !r.error;
    app.setState({ ascEnvoi: false, ascMsg: ok ? { txt: 'Coachs publiés : le dashboard des magasins les utilise dès la prochaine question.', ko: false } : { txt: 'Publication refusée : ' + ((r && r.error) || 'sans réponse'), ko: true } });
    if (ok) { app.setState({ ascB: {}, ascc: { cle: 'c', chargement: false, d: r } }); }
  });
}

export function valsAssistantCoachs(app, common){
  const S = app.state, onglet = S.ascOnglet || 'c';
  charge(app, 'c', false);
  if (onglet === 'q') { charge(app, 'q', false); }
  const bc = S.ascc || {}, d = bc.d && !bc.d.error ? bc.d : null;
  const a = { onglet, msg: S.ascMsg || null, fermerMsg: () => app.setState({ ascMsg: null }), envoi: !!S.ascEnvoi,
    ongletC: () => app.setState({ ascOnglet: 'c' }), ongletQ: () => app.setState({ ascOnglet: 'q' }),
    chargement: !!bc.chargement && !d, indispo: !bc.chargement && bc.d && !d ? 'Lecture impossible — ' + (bc.d.error || 'API injoignable') + ' (GET /assistant/coachs).' : '' };
  common.asc = a;
  if (d) {
    const B = S.ascB || {};
    a.niveaux = Object.keys(d.niveaux).map(n => ({ cle: n, lib: d.niveaux[n] }));
    a.coachs = Object.keys(d.coachs).map(k => {
      const c = d.coachs[k], b = B[k] || {}, def = d.defauts[k];
      const v = ch => b[ch] != null ? b[ch] : c[ch];
      return { cle: k, avatar: d.avatars[k], defaut: k === d.defaut,
        nom: v('nom'), dit: v('dit'), ton: v('ton'), modifie: !!B[k],
        setNom: e => brouillon(app, k, { nom: e.target.value }), setDit: e => brouillon(app, k, { dit: e.target.value }), setTon: e => brouillon(app, k, { ton: e.target.value }),
        departs: () => brouillon(app, k, { nom: def.nom, dit: def.dit, ton: def.ton, accents: JSON.parse(JSON.stringify(def.accents)), objectifs: Object.assign({}, def.objectifs || {}) }),
        // Les objectifs 2026 : la saisie telle quelle (« 12,5 »), sinon la valeur publiée, virgule belge.
        objectifs: Object.keys(d.objectifs || {}).map(o => {
          const D = d.objectifs[o], v = b.objectifs && o in b.objectifs ? b.objectifs[o] : (c.objectifs || {})[o];
          return { cle: o, lib: D.lib, unite: D.unite, sens: D.sens, mesure: D.mesure,
            valeur: v == null ? '' : (typeof v === 'number' ? String(v).replace('.', ',') : String(v)),
            set: e => brouillon(app, k, { [o]: e.target.value.trim() }, 'objectifs') };
        }),
        levierPrix: (() => { const v = b.objectifs && 'foodCost' in b.objectifs ? b.objectifs.foodCost : (c.objectifs || {}).foodCost; return v != null && v !== ''; })(),
        axes: Object.keys(d.axes).map(ax => {
          const x = Object.assign({}, c.accents[ax], (b.accents || {})[ax] || {});
          return { cle: ax, lib: d.axes[ax], niveau: x.niveau, verifie: x.verifie,
            choisir: a.niveaux.map(n => ({ cle: n.cle, lib: n.lib, on: n.cle === x.niveau, go: () => brouillon(app, k, { niveau: n.cle }, ax) })),
            setVerifie: e => brouillon(app, k, { verifie: e.target.value }, ax) };
        }) };
    });
    a.nbModifs = Object.keys(B).length;
    a.publier = () => publier(app, d);
    a.annuler = () => app.setState({ ascB: {}, ascMsg: null });
  }
  if (onglet === 'q') {
    const bq = S.ascq || {}, q = bq.d && !bq.d.error ? bq.d : null;
    a.qChargement = !!bq.chargement && !q;
    a.qIndispo = !bq.chargement && bq.d && !q ? 'Lecture impossible — ' + (bq.d.error || 'API injoignable') + ' (GET /assistant/questions-posees).' : '';
    a.periodes = [7, 30, 90].map(j => ({ j, on: (S.ascJours || 30) === j, go: () => app.setState({ ascJours: j }) }));
    a.rafraichir = () => charge(app, 'q', true);
    if (q) {
      const ORIG = { tuile: 'tuile du bilan', liste: 'liste toute prête', suite: 'question de suite', libre: 'question libre' };
      a.q = { total: nf(q.total), magasins: nf(q.magasins), echecs: q.echecs, duree: q.dureeMoyenneMs == null ? '—' : nf(Math.round(q.dureeMoyenneMs / 100) / 10) + ' s', jetons: nf(q.jetonsSortie),
        parCoach: q.parCoach, parMagasin: q.parMagasin, parOrigine: q.parOrigine.map(o => ({ lib: ORIG[o.origine] || o.origine, n: o.n })),
        top: q.lesPlusPosees, dernieres: q.dernieres.map(r => Object.assign({}, r, { quand: r.le.slice(8, 10) + '/' + r.le.slice(5, 7) + ' ' + r.le.slice(11, 16), orig: ORIG[r.origine] || r.origine })) };
    }
  }
}
