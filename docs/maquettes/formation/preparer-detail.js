/* Le détail des écrans B (fiche : Ouverture du magasin — NL — v5, illustrée) et C (pas à pas : Ouverture du poste
   et premières cuissons — FR — v1.1), tiré du texte complet des deux SOP (sops.json › texte_complet). */
module.exports = function ({ sops, S, SRC }) {
  const brut = id => SRC.sops.find(x => (x.id || '') === id || new RegExp(id, 'i').test(x.titre || x.title || ''));
  const sansPoint = t => String(t || '').replace(/\s+/g, ' ').trim();

  // --- B · la fiche ------------------------------------------------------------------------------------
  const om = brut('ouverture-magasin') || brut('Ouverture du magasin');
  const tc = (om && om.texte_complet) || [];
  const HEURE = s => /PHASE 1/i.test(s) ? '06:00 – 07:00' : /PHASE 2/i.test(s) ? 'dès 07:00' : /ADDENDUM/i.test(s) ? 'caisse' : '';
  let phase = '', photo = 0;
  const sections = [];
  for (const sec of tc) {
    const t = sec.section_fr || sec.section || '';
    if (/CHECKLIST|^PROCÉDURE|RÈGLES D'OR/i.test(t)) { continue; } // la grille horaire répète les étapes ; l'en-tête et les règles d'or vont ailleurs
    const h = HEURE(t); if (h) { phase = h; }
    const titre = t.replace(/^PHASE \d[^—]*—\s*/i, '').replace(/^ADDENDUM À SOP-WKL-001 — /i, 'Addendum · ').replace(/^(\d\.)\s*/, '$1 ');
    const etapes = (sec.items || []).filter(it => !/^(Intro|Note)/i.test(it.n)).map(it => {
      const caisse = /ADDENDUM/i.test(t) && /^\d+$/.test(it.n);
      if (caisse) { photo++; }
      const fr = sansPoint(it.fr), nl = sansPoint(it.original);
      return { n: /^\d/.test(it.n) ? it.n : '!', fr, nl: nl.length > 120 ? nl.slice(0, 117) + '…' : nl,
        crit: /Norme|HYGI|°C|interdit|verboden|code 12|jamais|nooit/i.test(it.n + ' ' + fr), photo: caisse && photo <= 8 ? 'photos/caisse-' + photo + '.jpg' : null };
    });
    sections.push({ titre, titre_nl: '', heure: h || '', etapes });
  }
  const regles = (tc.find(x => /RÈGLES D'OR/i.test(x.section_fr || x.section || '')) || { items: [] }).items.map(it => sansPoint(it.fr));
  const so = S('ouv-mag');
  const fiche = so ? {
    sop: Object.assign({}, so, { texte: undefined }), sections, regles,
    pour: 'Vente · équipe du matin', moment: (om && om.moment) || '', objectif: so.objectif,
    versionNote: 'pas de numéro dans le document : v5 vient du nom du fichier', crit: so.crit, quiz: so.quiz,
    pages: ['photos/page-2.png', 'photos/page-4.png', 'photos/page-5.png'], remarques: (om && om.remarques) || []
  } : null;

  // --- C · le pas à pas ---------------------------------------------------------------------------------
  const op = brut('ouverture-poste') || brut('Ouverture du poste');
  const tcp = (op && op.texte_complet) || [];
  const secs = tcp.filter(s => /RECTO|VERSO/i.test(s.section || '')).map(s => ({
    titre: /RECTO/i.test(s.section) ? 'Ouvrir et lancer les cuissons' : 'Terminer les pains · jusqu’à 6 h',
    etapes: (s.items || []).filter(it => /^\d+$/.test(it.n)).map(it => ({ n: +it.n, court: it.titre, fr: it.titre, det: sansPoint(it.texte) }))
  }));
  const ctrl = tcp.find(s => /CONTRÔLE/i.test(s.section || '')) || { items: [] };
  const ctl = n => sansPoint((ctrl.items.find(it => new RegExp(n, 'i').test(it.n)) || {}).texte);
  const prio = (tcp.flatMap(s => s.items || []).find(it => /PRIORIT/i.test(it.n)) || {}).texte;
  const COUR = 7;
  const etape = secs.flatMap(s => s.etapes.map(e => Object.assign({ section: s.titre }, e))).find(e => e.n === COUR) || {};
  const sp = S('ouv-poste');
  const pasapas = sp ? {
    sop: Object.assign({}, sp, { texte: undefined }), sections: secs, courante: COUR,
    etape: Object.assign({}, etape, { crit: 'Ne pas relancer de programme : même four, « ajouter du temps », 4 min et 20 % d’humidité.' }),
    priorite: sansPoint(prio), appelle: ctl('APPELLE'), shyfter: ctl('SHYFTER'), doute: ctl('DOUTE'),
    reperes: ['Four gauche — pains et pains spéciaux — programme « grands pains »', 'Four droit — baguettes et pistolets — programme « baguettes »', 'Fin du programme = produits cuits'],
    reste: 'jusqu’à 6 h'
  } : null;
  return { fiche, pasapas };
};
