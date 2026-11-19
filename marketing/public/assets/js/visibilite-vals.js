/* Cockpit CEO — Marque & marketing › Visibilité Google (#/visibilite, 09/11/2026).
 * Maquettes : docs/maquettes/visibilite-google (A : fiches Google, B : rang local).
 *
 * GET /visibilite : le dernier relevé de chaque magasin — sa fiche Google comparée au site du réseau, et sa place
 * dans les recherches suivies (boulangerie, sandwiches, cougnous, galettes ; en néerlandais à Halle).
 * POST /visibilite/sync {magasinId} : relit Google, un magasin à la fois (une fiche et sept recherches chacun),
 * pour qu'une relecture du réseau ne dépasse jamais le délai d'une requête et que l'écran dise où il en est.
 *
 * Remplit `common.vg` ; gabarit : visibilite-tpl.js ; style : visibilite.css (vg-). */
import { readOne } from './api.js';

const JOURS = ['lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.'];
const hm = s => String(s || '').replace(/\b0(\d):/g, '$1:');
const nf = (n, d) => n == null ? '—' : Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d || 0, maximumFractionDigits: d || 0 });
const jjmm = s => s ? String(s).slice(8, 10) + '/' + String(s).slice(5, 7) : '';
const quand = s => s ? jjmm(s) + '/' + String(s).slice(0, 4) + ' à ' + String(s).slice(11, 16) : '';
const rangTxt = r => r == null ? 'erreur' : (r === 0 ? 'hors des 20' : (r === 1 ? '1er' : r + 'e'));
const STATUT = { OPERATIONAL: 'Ouvert', CLOSED_TEMPORARILY: 'Fermé temporairement', CLOSED_PERMANENTLY: 'Fermé définitivement' };

/** Sept plages (lundi → dimanche) en une ligne : « lun. → sam. 6:30–18:30 · dim. fermé ». */
export function horairesResume(h){
  if (!h) { return ''; }
  const out = [];
  let i = 0;
  while (i < 7) {
    let j = i;
    while (j + 1 < 7 && h[j + 1] === h[i]) { j++; }
    const jours = i === j ? JOURS[i] : JOURS[i] + ' → ' + JOURS[j];
    out.push(jours + ' ' + (h[i] ? hm(h[i]) : 'fermé'));
    i = j + 1;
  }
  return out.join(' · ');
}

function charge(app, force){
  const b = app.state.vgData;
  if (!force && b && (b.d || b.chargement)) { return; }
  if (app._vgEnCours) { return; }
  app._vgEnCours = true;
  app.setState({ vgData: { chargement: true, d: b ? b.d : null } });
  readOne('/visibilite').then(d => {
    app._vgEnCours = false;
    app.setState({ vgData: { chargement: false, d: d || { erreur: true } } });
  });
}

/** Le compteur du rail : les écarts à corriger, une fois l'écran lu. */
export function visibiliteBadge(app){
  const d = ((app.state || {}).vgData || {}).d;
  return d && !d.erreur && !d.indispo ? (d.ecarts || 0) : 0;
}

/** La relecture : un magasin après l'autre, l'écran dit lequel. */
async function relire(app, magasins){
  if (app.state.vgBusy) { return; }
  const erreurs = [];
  let appels = 0;
  for (let i = 0; i < magasins.length; i++) {
    const m = magasins[i];
    app.setState({ vgBusy: 'Lecture de ' + (m.ville || m.nom) + ' (' + (i + 1) + '/' + magasins.length + ')…' });
    const r = await app.api('POST', '/visibilite/sync', { magasinId: m.id });
    if (!r || r.ok === false) { erreurs.push((m.ville || m.nom) + ' : ' + ((r && r.error) || 'refusé')); if (r && r.status === 422) { break; } continue; }
    appels += r.appels || 0;
    (r.erreurs || []).forEach(e => erreurs.push(e));
  }
  app.setState({ vgBusy: null });
  charge(app, true);
  app.notify('Google relu : ' + magasins.length + ' magasin(s), ' + appels + ' appel(s)' + (erreurs.length ? ' — ' + erreurs.length + ' erreur(s), voir la console' : ''));
  if (erreurs.length) { console.warn('[cockpit] visibilité Google :', erreurs); }
}

export function valsVisibilite(app, common){
  charge(app, false);
  const S = app.state, b = S.vgData || {}, d = b.d;
  const v = common.vg = { chargement: !d, erreur: null };
  if (!d) { return; }
  if (d.erreur || d.indispo) { v.chargement = false; v.erreur = d.indispo ? d.raison : 'La lecture de /visibilite a échoué — voir Diagnostic API.'; return; }

  const onglet = S.vgOnglet || 'fiches';
  const mags = d.magasins || [];
  const lus = mags.filter(m => m.fiche);
  v.configure = !!(d.connecteur || {}).configure;
  v.busy = S.vgBusy || null;
  v.relire = () => relire(app, mags);
  v.onglets = [['fiches', 'Fiches Google', d.ecarts || 0], ['rang', 'Rang local', 0]].map(([k, nom, n]) => ({
    nom, n, on: onglet === k, choisir: () => app.setState({ vgOnglet: k }) }));
  v.surFiches = onglet === 'fiches';
  v.luLe = d.lu_le ? 'Lu le ' + quand(d.lu_le) : 'Jamais lu';
  const site = d.site || {};
  v.siteTxt = site.erreur ? 'Site : ' + site.erreur
    : (site.lu_le ? 'Site relu le ' + quand(site.lu_le) + ' · ' + site.boutiques + ' boutique(s) publiée(s)' : 'Site pas encore relu');
  v.siteUrl = site.url || '';
  v.vide = !d.lu_le;
  v.magasins = mags.map(m => ({ id: m.id, ville: m.ville || m.nom, nom: m.nom, langue: m.langue, raccorde: !!m.placeId }));
  v.raccorder = () => app.setState({ screen: 'reputation', railOuvert: false });

  // --- A · Fiches Google : une ligne par donnée, une colonne par magasin
  const cel = (m, fn) => {
    if (!m.placeId) { return { txt: '—', muet: true }; }
    if (!m.fiche) { return { txt: m.lu_le ? 'illisible' : 'pas encore lue', muet: true }; }
    return fn(m.fiche, m);
  };
  const ligne = (lib, sous, fn) => ({ lib, sous, cels: mags.map(m => cel(m, fn)) });
  v.lignes = [
    ligne('Nom sur Google', '', f => ({ txt: f.nom || '—', warn: f.nom && !/atelier/i.test(f.nom) ? 'sans l’enseigne' : null })),
    ligne('Catégorie principale', '', f => ({ txt: f.typeLibelle || f.type || '—', warn: f.type && f.type !== 'bakery' ? 'Boulangerie attendue' : null })),
    ligne('Horaires habituels', '', (f, m) => {
      const g = horairesResume(f.horaires), s = m.site ? horairesResume(m.site.horaires) : null;
      const r = { txt: g || 'aucun' };
      if (!f.horaires) { r.warn = 'absents de Google'; }
      if (!m.site) { r.site = 'boutique introuvable sur le site'; return r; }
      r.site = 'site : ' + (s || 'aucun horaire');
      if (!m.site.horaires) { r.warn = r.warn || 'absents du site'; }
      else if (f.horaires && JSON.stringify(f.horaires) !== JSON.stringify(m.site.horaires)) { r.warn = 'le site contredit Google'; }
      else if (f.horaires) { r.ok = 'identiques'; }
      return r;
    }),
    ligne('Horaires exceptionnels', '7 prochains jours', f => f.speciaux && f.speciaux.length
      ? { txt: f.speciaux.map(jjmm).join(', '), ok: 'saisis' } : { txt: 'aucun', muet: true }),
    ligne('Lien du site', '', (f, m) => {
      const k = m.lien;
      if (!f.site) { return { txt: 'aucun', warn: 'lien manquant' }; }
      let court = f.site;
      try { const u = new URL(f.site); court = u.host.replace(/^www\./, '') + (u.pathname !== '/' ? u.pathname : '') + u.search; } catch (e) { /* lien brut */ }
      return { txt: court, neutre: k === 'accueil' ? 'accueil' : (k === 'webshop' ? 'webshop' : null), ok: k === 'page' ? 'page de la boutique' : null };
    }),
    ligne('Téléphone', '', (f, m) => {
      if (!f.telephone) { return { txt: 'aucun', warn: 'absent de Google' }; }
      const chiffres = t => String(t || '').replace(/\D/g, '').replace(/^0032/, '0').replace(/^32(?=\d{8,})/, '0');
      if (!m.site || !m.site.telephone) { return { txt: f.telephone }; }
      return chiffres(m.site.telephone) === chiffres(f.telephone) ? { txt: f.telephone, ok: '= site' } : { txt: f.telephone, warn: 'site : ' + m.site.telephone };
    }),
    ligne('Note · avis', '', f => ({ txt: f.note == null ? 'pas de note' : nf(f.note, 1) + ' · ' + nf(f.avis) + ' avis' })),
    ligne('Photos', '', f => f.photos >= 10 ? { txt: '10 et plus' } : { txt: String(f.photos), warn: 'moins de 10' }),
    ligne('Options', '', f => ({ txt: (f.options || []).join(' · ') || '—' })),
    ligne('Statut', '', f => ({ txt: STATUT[f.statut] || f.statut || '—', warn: f.statut && f.statut !== 'OPERATIONAL' ? 'vérifier' : null })),
  ];
  // Les écarts, tous magasins, du plus grave au moins grave.
  v.ecarts = [];
  mags.forEach(m => (m.ecarts || []).forEach(e => v.ecarts.push({
    gravite: e.gravite, titre: (m.ville || m.nom) + ' · ' + e.quoi, detail: e.detail,
    action: e.ou === 'cockpit' ? { lib: 'Raccorder la fiche', fn: v.raccorder }
      : (e.ou === 'google' && m.fiche && m.fiche.url ? { lib: 'Ouvrir la fiche Google', href: m.fiche.url }
        : (e.ou === 'site' && v.siteUrl ? { lib: 'Ouvrir le site', href: v.siteUrl } : null)) })));
  v.ecarts.sort((a, b2) => a.gravite - b2.gravite);
  const notes = lus.filter(m => m.fiche.note != null);
  const nAvis = notes.reduce((s, m) => s + m.fiche.avis, 0);
  v.kpis = [
    { val: String(v.ecarts.length), lib: 'À corriger', note: v.ecarts.filter(e => e.gravite === 1).length + ' urgent(s)', accent: true },
    { val: lus.length + ' / ' + mags.length, lib: 'Fiches lues', note: mags.filter(m => !m.placeId).length ? mags.filter(m => !m.placeId).length + ' non raccordée(s)' : 'raccordées dans Note Google et avis' },
    { val: String(mags.filter(m => (m.ecarts || []).some(e => /horaires/i.test(e.quoi))).length), lib: 'Horaires à corriger', note: 'Google ou le site' },
    { val: nAvis ? nf(notes.reduce((s, m) => s + m.fiche.note * m.fiche.avis, 0) / nAvis, 1) : '—', lib: 'Note moyenne', note: nf(nAvis) + ' avis sur ' + notes.length + ' fiche(s)' },
  ];

  // --- B · Rang local : une ligne par recherche, une colonne par magasin
  const theme = S.vgTheme || 'tous';
  v.themes = [{ cle: 'tous', nom: 'Tous les thèmes' }].concat(d.themes || []).map(t => ({
    nom: t.nom, on: theme === t.cle, choisir: () => app.setState({ vgTheme: t.cle }) }));
  const nl = mags.find(m => m.langue === 'nl');
  const sel = S.vgSel || null;
  let n = 0, top3 = 0, hors = 0, gain = 0, perte = 0, premiere = null;
  v.groupes = (d.themes || []).filter(t => theme === 'tous' || t.cle === theme).map(t => ({
    nom: t.nom,
    lignes: t.requetes.map(q => ({
      requete: q.fr, nl: nl ? (nl.ville || nl.nom) + ' : ' + q.nl.replace('{ville}', nl.ville || '') : '',
      cels: mags.map(m => {
        const r = (m.rangs || []).find(x => x.modele === q.fr);
        if (!r) { return { vide: true }; }
        n++;
        if (r.rang > 0 && r.rang <= 3) { top3++; }
        if (r.rang === 0) { hors++; }
        const avant = m.precedent && m.precedent.rangs ? m.precedent.rangs[q.fr] : undefined;
        let ev = null;
        if (avant != null && r.rang != null) {
          const a = avant === 0 ? 21 : avant, b2 = r.rang === 0 ? 21 : r.rang;
          if (a !== b2) { ev = a - b2; if (ev > 0) { gain += ev; } else { perte -= ev; } }
        }
        const cle = m.id + '|' + q.fr;
        const c = { txt: rangTxt(r.rang), ton: r.rang == null ? 'abs' : (r.rang === 0 ? 'abs' : (r.rang <= 3 ? 'top' : (r.rang <= 10 ? 'mid' : 'bas'))),
          ev, on: sel === cle, choisir: () => app.setState({ vgSel: cle }) };
        c.detail = { requete: r.requete, rang: r.rang, total: r.total, erreur: r.erreur, top: r.top || [], ville: m.ville || m.nom, avant };
        if (!premiere && r.rang !== null) { premiere = c; }
        if (sel === cle) { v.detail = c.detail; }
        return c;
      })
    }))
  }));
  if (!v.detail && premiere) { v.detail = premiere.detail; premiere.on = true; }
  v.rangKpis = [
    { val: top3 + ' / ' + n, lib: 'Dans les 3 premiers', note: 'ce que Google Maps montre sans défiler', accent: true },
    { val: String(hors), lib: 'Hors des 20', note: 'la fiche n’apparaît pas' },
    { val: '+ ' + gain, lib: 'Places gagnées', note: perte + ' place(s) perdue(s) depuis le relevé précédent' },
    { val: String(n), lib: 'Recherches suivies', note: 'rayon ' + nf((d.rayon || 0) / 1000) + ' km autour du magasin' },
  ];
  const nRech = (d.themes || []).reduce((s, t) => s + t.requetes.length, 0);
  v.cout = { recherches: nRech * mags.length, fiches: mags.filter(m => m.placeId).length };
}
