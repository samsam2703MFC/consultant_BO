/* Cockpit CEO — Recettes à corriger › Matières premières › Références et pertes (26/11/2026 : « les prix moyens
 * Belgique au kg et les % de perte ; d'abord la Tagliata, ensuite tous les autres produits »).
 * GET /verification/recettes/references?rid= | ?q= | (toute la carte) rend, pour chaque matière, ses prix dans les
 * magasins, sa référence et sa perte au panel, et la proposition (moyenne du réseau, sinon prix de marché ; perte).
 * POST /verification/recettes/references l'écrit (prix de référence et perte de la matière, pour tout le réseau), relit
 * les food costs avant / après, et dit si la perte entre dans le coût de la recette. Gabarit : verification-refs-tpl.js. */
import { readOne, write } from './api.js';

const nf = (n, d) => n == null || isNaN(n) ? '—' : Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d || 0, maximumFractionDigits: d || 0 }).replace(/[  ]/g, ' ');
const num = s => { const t = String(s == null ? '' : s).trim().replace(/\s/g, '').replace(',', '.'); if (t === '') { return null; } const n = Number(t); return isFinite(n) ? n : NaN; };
const fE = (v, d) => v == null || isNaN(v) ? '—' : nf(v, d == null ? 2 : d) + ' €';
const ROUTE = '/verification/recettes/';

function charger(app, cible, frais){
  const qs = cible.rid ? '?rid=' + cible.rid : (cible.q ? '?q=' + encodeURIComponent(cible.q) : '');
  app.setState({ vfRef: { chargement: true, cible, d: null }, vfRefEd: {}, vfRefPrev: null, vfRefRes: null });
  readOne(ROUTE + 'references' + qs + (frais ? (qs ? '&' : '?') + 'rafraichir=1' : '')).then(d => app.setState({ vfRef: { chargement: false, cible, d: d || { error: 'injoignable' } } }));
}

/** Envoie des lots de lignes l'un après l'autre (toute la carte) ; seul le dernier relit les food costs. */
function envoyerParLots(app, base, lignes, taille, progres){
  const lots = []; for (let i = 0; i < lignes.length; i += taille) { lots.push(lignes.slice(i, i + taille)); }
  const tout = { ok: true, ecritures: [], effet: [], pertes: null, erreurs: [] };
  return lots.reduce((pr, lot, i) => pr.then(() => envoyer(app, Object.assign({}, base, { lignes: lot, sansEffet: i < lots.length - 1 })).then(r => {
    r = r || { ok: false, error: 'injoignable' };
    tout.ok = tout.ok && !!r.ok; tout.ecritures = tout.ecritures.concat(r.ecritures || []); tout.erreurs = tout.erreurs.concat(r.erreurs || [], r.error && !(r.ecritures || []).length ? [r.error] : []);
    if (r.effet && r.effet.length) { tout.effet = r.effet; } if (r.pertes) { tout.pertes = r.pertes; }
    progres(i + 1, lots.length);
  })), Promise.resolve()).then(() => tout);
}

/** Les pertes sur les lignes de toute la carte, tranche après tranche ; rend une promesse du bilan. */
function lancerPertesLignes(app){
  const tranche = depuis => write(app.source || 'api', 'POST', ROUTE + 'pertes-lignes', Object.assign(app.state.vfRefAdmin ? { compte: 'admin' } : {}, { depuis, lot: 15 }));
  let nOk = 0, nRefus = 0, erreurs = [];
  const suite = depuis => tranche(depuis).then(r => {
    if (!r || !r.ok) { const etat = 'Arrêt à la recette ' + depuis + ' : ' + ((r && r.error) || 'pas de réponse') + ' — ' + nOk + ' écriture(s) faite(s).'; app.setState({ vfPl: { etat, ko: true } }); return { ok: false, etat }; }
    nOk += r.nOk; nRefus += r.nRefus; erreurs = erreurs.concat(r.erreurs || [], (r.ecritures || []).filter(e => e.resultat !== 'ok').map(e => e.resume + ' : ' + (e.message || e.resultatNom)));
    const etat = 'Recettes ' + r.fin + ' sur ' + r.total + ' · ' + nOk + ' recette(s) ou sous-recette(s) corrigée(s)' + (nRefus ? ' · ' + nRefus + ' refusée(s)' : '');
    if (r.suivant != null) { app.setState({ vfPl: { enCours: true, etat } }); return suite(r.suivant); }
    app.setState({ vfPl: { etat: '✓ Terminé : ' + etat + '.', ko: nRefus > 0, erreurs: erreurs.slice(0, 30) }, vrReg: null });
    return { ok: nRefus === 0, etat };
  });
  app.setState({ vfPl: { enCours: true, etat: 'Écriture des pertes sur les lignes…' } });
  return suite(0);
}

const envoyer = (app, corps) => write(app.source || 'api', 'POST', ROUTE + 'references', Object.assign(app.state.vfRefAdmin ? { compte: 'admin' } : {}, corps));

export function valsRefs(app){
  const S = app.state, R = S.vfRef;
  // Ouverte par son adresse (« ?vue=refs&recette=tagliata ») : la recette se lit tout de suite.
  if (S.vfRefAuto && !R) { const t = S.vfRefAuto; app.setState({ vfRefAuto: null }); setTimeout(() => charger(app, t === 'tout' ? { tout: true } : (/^\d+$/.test(t) ? { rid: Number(t) } : { q: t })), 0); }
  const d = R && R.d && R.d.ok ? R.d : null;
  const v = {
    q: S.vfRefQ || '', poserQ: e => app.setState({ vfRefQ: e.target.value }),
    chercher: () => { const t = String(app.state.vfRefQ || '').trim(); if (t) { charger(app, /^\d+$/.test(t) ? { rid: Number(t) } : { q: t }); } },
    tout: () => charger(app, { tout: true }),
    relire: R && R.cible ? () => charger(app, R.cible, true) : null,
    chargement: !!(R && R.chargement),
    erreur: R && R.d && !R.d.ok && !R.d.choix ? (R.d.motif || R.d.error || 'Le serveur du cockpit n’a pas répondu : réessayez dans un instant.') : null,
    choix: R && R.d && R.d.choix ? { motif: R.d.motif, liste: R.d.choix.map(c => ({ nom: c.nom + ' · n° ' + c.rid, ouvrir: () => charger(app, { rid: c.rid }) })) } : null,
  };
  if (!d) { return v; }
  const E = S.vfRefEd || {};
  const aligner = S.vfRefAligner !== false;   // le même food cost dans tous les magasins (26/11/2026) : par défaut
  const maj = (mid, patch) => app.setState({ vfRefEd: Object.assign({}, app.state.vfRefEd, { [mid]: Object.assign({}, (app.state.vfRefEd || {})[mid], patch) }), vfRefPrev: null });
  v.titre = d.rid ? 'Recette n° ' + d.rid + (d.nom ? ' — ' + d.nom : '') : 'Toute la carte (les recettes déjà lues)';
  v.mags = (d.magasins || []).map(m => String(m.nom).replace(/^.* - /, ''));
  v.lignes = (d.lignes || []).map(l => {
    const P = l.propose || {}, e = E[l.mid] || {};
    const prixDef = P.prix != null ? Number((P.prix * l.facteur).toFixed(4)) : null;
    const prixTxt = e.prix != null ? e.prix : (prixDef != null ? String(prixDef).replace('.', ',') : '');
    const perteTxt = e.perte != null ? e.perte : (P.perte != null ? String(P.perte) : '');
    const al = aligner ? (l.aligner || []) : [];
    const coche = e.coche != null ? e.coche : (!!P.change || al.length > 0);
    const px = num(prixTxt), pt = num(perteTxt);
    const ref = l.reference != null ? l.reference * l.facteur : null;
    return {
      mid: l.mid, nom: l.nom, cat: l.cat, centrale: l.source === 'CENTRAL', par: l.par,
      emploi: (l.q != null ? nf(l.q, l.q < 10 ? 1 : 0) + ' ' + l.unite + ' par pièce' + (l.sous ? ' (dans ' + l.sous + ')' : '') + ' · ' : '')
        + l.nRecettes + ' recette' + (l.nRecettes > 1 ? 's' : '') + (!d.rid && l.recettes && l.recettes.length ? ' — ' + l.recettes.slice(0, 2).join(', ') + (l.nRecettes > 2 ? '…' : '') : ''),
      magasins: (l.magasins || []).map(m => !m.lu ? { txt: '?', ton: '' }
        : (m.prix == null || m.src === 'MISSING' ? { txt: 'sans prix', ton: 'ko' }
          : { txt: fE(m.prix * l.facteur, 2), ton: al.indexOf(m.sid) >= 0 ? 'att' : (m.src === 'REFERENCE' ? 'att' : 'ok'),
            sous: m.verrou ? 'tarif fournisseur' : (al.indexOf(m.sid) >= 0 ? 'à aligner' : (m.src === 'REFERENCE' ? 'référence' : '')) })),
      reference: ref == null ? '—' : fE(ref, 2), perteAvant: l.perte == null ? '—' : nf(l.perte, 0) + ' %',
      origine: P.origine === 'fournisseur' ? 'tarif fournisseur' : (P.origine === 'reseau' ? 'réseau' : (P.origine === 'marche' ? 'marché, estimé' : 'à saisir')),
      origineTon: P.origine === 'reseau' || P.origine === 'fournisseur' ? 'ok' : (P.origine === 'marche' ? 'att' : 'ko'), sourcePrix: P.source || '',
      prix: prixTxt, perte: perteTxt, raison: P.raison ? P.raison + (P.perteOrigine === 'courante' ? ' · perte courante' : '') : '',
      poserPrix: ev => maj(l.mid, { prix: ev.target.value, coche: true }), poserPerte: ev => maj(l.mid, { perte: ev.target.value, coche: true }),
      coche, cocher: () => maj(l.mid, { coche: !coche }), idPrix: 'vr-ref-px-' + l.mid, idPerte: 'vr-ref-pt-' + l.mid,
      invalide: (prixTxt !== '' && !(px > 0)) || (perteTxt !== '' && !(pt >= 0 && pt < 100)),
      envoi: Object.assign({ mid: l.mid, prix: px > 0 ? px : null, perte: pt >= 0 && pt < 100 ? pt : null }, aligner ? { aligner: true } : {}),
    };
  });
  const C = d.compteurs || {};
  v.kpis = [['Matières', C.matieres, d.rid ? 'dans la recette, sous-recettes comprises' : 'dans les recettes déjà lues', ''],
    ['À changer', C.aChanger, 'la proposition diffère du panel', C.aChanger ? 'att' : 'ok'],
    ['Prix de marché', C.marche, 'aucun magasin ne la chiffre : prix estimé', C.marche ? 'att' : 'ok'],
    ['Sans prix', C.sansPrix, 'ni magasin, ni prix de marché : à saisir', C.sansPrix ? 'ko' : 'ok'],
    ['Pertes à poser', C.pertes, 'perte proposée différente de celle du panel', C.pertes ? 'att' : 'ok'],
    ['Prix à aligner', C.aAligner, 'un magasin paie autrement que la référence', C.aAligner ? 'att' : 'ok']]
    .concat(C.fournisseur ? [['Tarif fournisseur', C.fournisseur, 'tenu par la centrale : la référence le reprend', '']] : []);
  v.aligner = { on: aligner, basculer: () => app.setState({ vfRefAligner: !aligner, vfRefEd: {}, vfRefPrev: null }) };
  const choisies = v.lignes.filter(l => l.coche && !l.invalide && (l.envoi.prix != null || l.envoi.perte != null));
  const prev = S.vfRefPrev, res = S.vfRefRes;
  v.admin = { on: !!S.vfRefAdmin, basculer: () => app.setState({ vfRefAdmin: !app.state.vfRefAdmin, vfRefPrev: null }) };
  v.barre = {
    n: choisies.length, enCours: !!(prev && prev.enCours) || !!(res && res.enCours),
    verifier: choisies.length && !(prev && prev.enCours) ? () => {
      app.setState({ vfRefPrev: { enCours: true } });
      envoyer(app, { apercu: true, rid: d.rid || 0, lignes: choisies.map(l => l.envoi) }).then(r => app.setState({ vfRefPrev: { d: r || { error: 'injoignable' } } }));
    } : null,
    apercu: prev && prev.d ? (prev.d.apercu ? { resume: prev.d.resume, ecritures: (prev.d.ecritures || []).map(e => ({ route: e.methode + ' ' + e.route, resume: e.resume, corps: JSON.stringify(e.corps) })), erreurs: prev.d.erreurs || [] }
      : { erreur: prev.d.error || 'refusé' }) : null,
    progres: S.vfRefProg || null,
    appliquer: prev && prev.d && prev.d.apercu ? () => {
      app.setState({ vfRefRes: { enCours: true }, vfRefProg: null });
      envoyerParLots(app, { rid: d.rid || 0 }, choisies.map(l => l.envoi), 20, (i, n) => app.setState({ vfRefProg: n > 1 ? 'lot ' + i + ' sur ' + n : null })).then(r => {
        app.setState({ vfRefRes: { d: r || { error: 'injoignable' } }, vfRefPrev: null, vfRefEd: {}, vrRecentes: ((r && r.ecritures) || []).concat(app.state.vrRecentes || []).slice(0, 20), vrReg: null, vmData: null });
        charger(app, R.cible, true);
      });
    } : null,
  };
  // « Tout faire » (26/11/2026 : « faire le tout automatiquement ») : toute la carte en un clic — les prix de référence,
  // les pertes et l'alignement des magasins (par lots), puis les pertes sur les lignes de toutes les recettes.
  const TF = S.vfTout || {};
  v.toutFaire = !d.rid ? {
    enCours: !!TF.enCours, etat: TF.etat || '', fini: !!TF.fini, n: choisies.length,
    lancer: TF.enCours || !choisies.length ? null : () => {
      app.setState({ vfTout: { enCours: true, etat: '1/2 · Prix de référence, pertes et alignement : ' + choisies.length + ' matière(s)…' }, vfRefProg: null });
      envoyerParLots(app, { rid: 0 }, choisies.map(l => l.envoi), 20, (i, n) => app.setState({ vfTout: { enCours: true, etat: '1/2 · Prix, pertes et alignement : lot ' + i + ' sur ' + n } }))
        .then(r => {
          const okE = (r.ecritures || []).filter(e => e.resultat === 'ok').length, fE_ = (r.ecritures || []).filter(e => e.resultat === 'fournisseur').length;
          const koE = (r.ecritures || []).length - okE - fE_;
          const etape1 = '1/2 ✓ ' + okE + ' écriture(s) relue(s) au panel' + (fE_ ? ', ' + fE_ + ' prix laissé(s) au tarif du fournisseur (les autres magasins s’y alignent)' : '') + (koE ? ', ' + koE + ' refusée(s) ou sans effet' : '') + '.';
          app.setState({ vfRefRes: { d: r }, vfTout: { enCours: true, etat: etape1 + ' 2/2 · Pertes sur les lignes…' }, vrRecentes: (r.ecritures || []).concat(app.state.vrRecentes || []).slice(0, 20), vmData: null });
          return lancerPertesLignes(app).then(b => app.setState({ vfTout: { fini: true, etat: etape1 + ' 2/2 ' + b.etat } }));
        })
        .then(() => charger(app, R.cible, true));
    },
  } : null;
  // Le résultat : chaque écriture, le food cost relu dans chaque magasin, et la perte (lue ou à poser sur les lignes).
  const rd = res && res.d;
  if (rd) {
    const P = rd.pertes;
    v.resultat = {
      ok: !!rd.ok, erreur: rd.error || null,
      lignes: (rd.ecritures || []).map(e => (e.resultat === 'ok' ? '✓ ' : (e.resultat === 'fournisseur' ? 'ℹ ' : '✕ ')) + e.resume + ' — ' + e.resultatNom + (e.message ? ' : ' + e.message : '')).concat(rd.erreurs || []),
      effet: (rd.effet || []).map(m => ({ magasin: String(m.magasin).replace(/^.* - /, ''), resume: m.resume,
        lignes: (m.lignes || []).slice(0, 8).map(x => ({ nom: x.nom || ('recette ' + x.rid), bouge: x.ecart != null && Math.abs(x.ecart) > 1e-6,
          txt: fE(x.avant, 3) + ' → ' + fE(x.apres, 3) + (x.pct != null ? ' (' + (x.pct > 0 ? '+' : '') + nf(x.pct, 1) + ' %)' : '') + (x.complet ? '' : ' · coût partiel') })) })),
      pertes: P ? { message: P.message, n: (P.aPoser || []).length, enCours: !!S.vfRefLigEnCours, res: S.vfRefLig || null,
        poser: (P.aPoser || []).length && d.rid && !S.vfRefLigEnCours ? () => {
          app.setState({ vfRefLigEnCours: true });
          write(app.source || 'api', 'POST', ROUTE + 'corriger', Object.assign(app.state.vfRefAdmin ? { compte: 'admin' } : {},
            { geste: 'recette', rid: d.rid, nom: d.nom, changements: P.aPoser.map(x => ({ type: 'perte', mid: x.mid, p: x.p })) }))
            .then(r => app.setState({ vfRefLigEnCours: false, vfRefLig: r && r.ok ? '✓ Pertes posées sur les lignes de la recette, relues au panel.'
              : '✕ ' + ((r && (r.error || ((r.ecritures || [])[0] || {}).message)) || 'refusé'), vrRecentes: ((r && r.ecritures) || []).concat(app.state.vrRecentes || []).slice(0, 20) }));
        } : null } : null,
      fermer: () => app.setState({ vfRefRes: null, vfRefLig: null }),
    };
  }
  // Les pertes sur les lignes, toute la carte (26/11/2026) : le coût d'une recette ne lit que la perte de la ligne.
  const PL = S.vfPl || {};
  const tranche = (depuis, apercu) => write(app.source || 'api', 'POST', ROUTE + 'pertes-lignes', Object.assign(app.state.vfRefAdmin ? { compte: 'admin' } : {}, { depuis, lot: 15, apercu }));
  v.pertesLignes = {
    enCours: !!PL.enCours, etat: PL.etat || '', ko: !!PL.ko,
    apercu: PL.apercu ? { lignes: PL.apercu.map(e => e.resume + ' — ' + e.route) } : null,
    verifier: PL.enCours ? null : () => {
      app.setState({ vfPl: { enCours: true, etat: 'Lecture des 15 premières recettes…' } });
      tranche(0, true).then(r => app.setState({ vfPl: r && r.ok ? { apercu: r.ecritures || [], etat: (r.ecritures || []).length + ' recette(s) ou sous-recette(s) à changer dans les 15 premières (sur ' + r.total + ' recettes) ; ' + r.matieresAvecPerte + ' matière(s) portent une perte au panel.', pret: true }
        : { etat: (r && r.error) || 'refusé', ko: true } }));
    },
    appliquer: PL.pret && !PL.enCours ? () => { lancerPertesLignes(app); } : null,
    erreurs: PL.erreurs || [],
  };
  v.source = d.source || '';
  v.maj = d.maj || '';
  return v;
}
