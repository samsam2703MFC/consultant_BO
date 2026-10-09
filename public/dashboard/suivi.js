/* La page du franchisé — « Mon suivi » (09/10/2026). Une lecture : GET ../api/cockpit/franchises/fiche?shop=.
 * Deux volets, comme la fiche du cockpit, dits au franchisé : ce que les données disent chaque jour, ce que
 * le consultant a constaté sur place ; et d'abord ce qu'il a à faire. Rien n'est saisi ici : les corrections
 * de plan d'action se font dans l'application Visites (../visites/?shop=). */
(function () {
  'use strict';
  const API = '../api/cockpit';
  const q = new URLSearchParams(location.search);
  const SHOP = q.get('shop') || '4';
  const $ = document.getElementById('suivi');
  const esc = v => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const nf = (n, d) => n == null ? '—' : Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d || 0, maximumFractionDigits: d || 0 });
  const pl = (n, u, p) => nf(n) + ' ' + u + (n > 1 ? (p || 's') : '');
  const fD = d => d ? d.slice(8, 10) + '/' + d.slice(5, 7) : '';
  const JC = ['dim', 'lun', 'mar', 'mer', 'jeu', 'ven', 'sam'];
  const fDJ = d => { if (!d) { return ''; } const t = new Date(d + 'T12:00:00'); return JC[t.getDay()] + ' ' + fD(d); };
  const fDL = d => { if (!d) { return ''; } const t = new Date(d + 'T12:00:00'); return t.toLocaleDateString('fr-BE', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }); };
  const plusJours = (d, n) => { const t = new Date(d + 'T12:00:00'); t.setDate(t.getDate() + n); return t.getFullYear() + '-' + String(t.getMonth() + 1).padStart(2, '0') + '-' + String(t.getDate()).padStart(2, '0'); };
  const ST = { planifiee: 'planifiée', confirmee: 'confirmée', en_cours: 'en cours', terminee: 'faite', annulee: 'annulée' };
  const PLAN_ST = { ouvert: 'à corriger', attente: 'correction envoyée, en attente de validation', valide: 'validé', reprendre: 'à reprendre', ferme: 'fermé', escalade: 'escaladé' };
  function lire(path) {
    return fetch(API + path, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(r => r.ok ? r.json() : r.json().then(j => Promise.reject(new Error((j && j.error) || ('HTTP ' + r.status))), () => Promise.reject(new Error('HTTP ' + r.status))));
  }
  function kpi(k, v, s, cls) { return `<div class="sv-kpi"><div class="k">${esc(k)}</div><div class="v ${cls || ''}">${esc(v)}</div><div class="s">${esc(s || '')}</div></div>`; }
  function bloc(t, html) { return `<div class="sv-bloc"><b class="t">${esc(t)}</b><div class="l">${html}</div></div>`; }
  function heat(jours, auj) {
    const parJ = {}; (jours || []).forEach(x => { parJ[x.jour] = x; }); let h = '';
    for (let i = 29; i >= 0; i--) { const dte = plusJours(auj, -i); const x = parJ[dte]; h += '<i class="' + (!x ? 'x' : (x.oblig ? 'm' : (x.f < x.t ? 'p' : ''))) + '" title="' + fD(dte) + (x ? ' : ' + x.f + ' / ' + x.t : ' : non relevé') + '"></i>'; }
    return '<div class="sv-heat">' + h + '</div><div class="sv-leg"><span><i style="background:#C9E3CD"></i>tout rendu</span><span><i style="background:#F5D58F"></i>partiel</span><span><i style="background:#E8A0A0"></i>obligatoire manquée</span><span><i style="background:#EDE7E0"></i>non relevé</span></div>';
  }
  function rendre(d) {
    const J = d.journalier, R = d.terrain, V = R.visites, auj = d.aujourdhui;
    const sc = R.scoring; const der = V.derniere; const pro = V.prochaines[0];
    const RV = J.revues || {}, RC = J.reclamations || {}, TA = J.taches || {}, IV = J.invendus || {}, tj = J.tachesJour || {};
    const OB = J.objectifs || { produits: [], campagnes: [] }; const o1 = OB.produits[0];
    const cons = d.consultants.length ? d.consultants.map(c => c.nom + ' (' + c.types.join(', ') + ')').join(' · ') : 'consultant à nommer au cadre';
    const plans = R.plans.filter(p => /^(ouvert|reprendre|escalade|attente)$/.test(p.statut)); const fermes = R.plans.filter(p => p.statut === 'ferme' || p.statut === 'valide');
    const aFaire = [];
    plans.forEach(p => aFaire.push({ p: p.priorite, cls: p.priorite === 'P0' ? 'p0' : p.priorite === 'P1' ? 'p1' : '', txt: esc(p.titre) + ' — ' + (PLAN_ST[p.statut] || p.statut) + (p.echeance ? ', pour le ' + fD(p.echeance) : '') + (p.retard ? ' · <span class="ko">en retard de ' + p.retard + ' j</span>' : '') + (p.detail ? '<br><span class="mu">' + esc(p.detail) + '</span>' : '') }));
    if (TA.joursObligManques) { aFaire.push({ p: 'tâches', cls: 'p1', txt: 'Une tâche obligatoire a manqué ' + pl(TA.joursObligManques, 'jour') + ' sur 30 : le jour vaut 0 au scoring.' }); }
    if (RV.lu && (RV.majeures || RV.critiques)) { aFaire.push({ p: 'contrôles', cls: 'p0', txt: pl((RV.majeures || 0) + (RV.critiques || 0), 'contrôle') + ' noté majeur ou critique : ' + (RV.infractions || []).filter(i => i.niveau !== 'mineure').slice(0, 3).map(i => esc(i.tache)).join(', ') + '. À corriger et rephotographier.' }); }
    if (RV.lu && RV.mineures) { aFaire.push({ p: 'contrôles', cls: 'p1', txt: pl(RV.mineures, 'infraction') + ' mineure' + (RV.mineures > 1 ? 's' : '') + ' sur 30 jours : voir la tuile Infractions.' }); }
    if (IV.lu && IV.part != null && IV.part > (IV.cible || 4)) { aFaire.push({ p: 'invendus', cls: 'p1', txt: 'Invendus à ' + nf(IV.part, 1) + ' % du CA sur 7 jours, au-dessus des ' + nf(IV.cible || 4) + ' % : ajustez la production.' }); }
    if (RC.lu && RC.ouvertes) { aFaire.push({ p: 'réclamations', cls: '', txt: pl(RC.ouvertes, 'réclamation') + ' fournisseur sans réponse : relancez depuis votre dashboard.' }); }
    if (J.google && J.google.faibles) { aFaire.push({ p: 'avis', cls: 'p1', txt: pl(J.google.faibles, 'avis') + ' ≤ 2 sur 30 jours : répondez-y sur votre fiche Google.' }); }
    if (pro) { aFaire.push({ p: 'visite', cls: '', txt: 'Visite ' + fDJ(pro.prevu_le) + ' à ' + pro.debut_h + ' : ' + esc(pro.typeNom) + ' par ' + esc(pro.consultantNom) + '.' }); }
    let h = `<div class="db-hd"><img src="../assets/img/logo.png" alt=""><div><div class="db-titre">${esc(d.magasin.court)} — Mon suivi</div><div class="db-sous">${esc(fDL(auj))} · ${esc(cons)}${d.feu && d.feu.feu ? ' · feu ' + esc(d.feu.feu) + (d.feu.motifs && d.feu.motifs.length ? ' : ' + esc(d.feu.motifs[0]) : '') : ''}</div></div></div>`;
    h += `<div class="sv-faire"><h2>À faire — ${aFaire.length ? pl(aFaire.length, 'point') : 'rien d’urgent'}</h2>${aFaire.length ? aFaire.map(a => `<div class="it"><span class="p ${a.cls}">${esc(a.p)}</span><div>${a.txt}</div></div>`).join('') : '<div class="it"><div>Aucun plan d’action ouvert, obligatoires tenues, pas d’avis faible.</div></div>'}
      <div class="sv-liens"><a class="p" href="../visites/?shop=${encodeURIComponent(d.shop)}">Corriger un plan d’action dans l’app Visites</a><a href="index.html?shop=${encodeURIComponent(d.shop)}">Mon dashboard</a></div></div>`;
    // Trois niveaux : la tuile dit le chiffre ; dépliée, son détail ; « Tout voir » ouvre la modale.
    const MOD = {};
    const tuile = (k, lab, v, sous, cls, html, modal) => { if (modal) { MOD[k] = modal; } return `<details class="sv-k${cls ? ' k-' + cls : ''}"><summary><small>${esc(lab)}</small><b>${esc(v)}</b><span>${esc(sous)}</span></summary><div class="d">${html}${modal ? `<div><button class="sv-lien" type="button" data-modal="${k}">Tout voir ›</button></div>` : ''}</div></details>`; };
    const tab = (cols, rows) => '<table class="sv-tab"><thead><tr>' + cols.map(c => '<th>' + esc(c) + '</th>').join('') + '</tr></thead><tbody>' + rows.map(r => '<tr>' + r.map(c => '<td>' + c + '</td>').join('') + '</tr>').join('') + '</tbody></table>';
    const mu = s => '<span class="mu">' + esc(s) + '</span>';
    const nZ = (n, u) => n ? n + ' ' + u + (n > 1 ? 's' : '') : '';
    const pctCls = p => p == null ? 'mu' : (p < 70 ? 'ko' : p < 85 ? 'att' : 'ok');
    const NIV = i => '<span class="sv-niv ' + esc(i.niveau) + '">' + esc(i.niveau) + '</span>';
    const SUITE = i => i.suite ? fD(i.suite.jour) + ' : ' + (i.suite.conforme ? '<span class="ok">conforme</span>' : '<span class="ko">toujours non conforme</span>') : '<span class="mu">pas encore renotée</span>';
    const ST_RC = r => r.ouverte ? '<span class="ko">sans réponse</span>' : esc(r.statut === 'REJECTED' ? 'refusée' : r.statut === 'ACCEPTED' ? 'acceptée' : String(r.statut || '').toLowerCase());
    const msp = R.msp; const conf = R.conformite || {}; const plano = conf.planogramme || {}; const asso = conf.assortiment || {};
    const nP = p => plans.filter(x => x.priorite === p).length;
    const volJ = tuile('taches', 'Tâches faites · 30 j', TA.pct != null ? TA.pct + ' %' : '—', TA.pct != null ? TA.rendues + ' / ' + TA.attendues + (TA.joursObligManques ? ' · obligatoire manquée ' + nZ(TA.joursObligManques, 'jour') : ' · obligatoires tenues') : (TA.motif || ''), pctCls(TA.pct),
        (tj.lu ? 'Le ' + fD(tj.date) + ' : <b>' + tj.notees + ' / ' + tj.taches + '</b> photos notées par le consultant' + (tj.aNoter ? ' · ' + tj.aNoter + ' en attente' : '') : '') + heat(TA.jours, auj),
        (TA.jours || []).length ? { titre: 'Vos tâches jour par jour · 30 jours', html: tab(['Jour', 'Rendues', 'Attendues', 'Obligatoire'], TA.jours.slice().reverse().map(x => [fDJ(x.jour), String(x.f), String(x.t), x.oblig ? '<span class="ko">manquée</span>' : 'tenue'])) } : null)
      + tuile('notes', 'Points moyens', RV.lu && RV.moyenne != null ? nf(RV.moyenne, 1) + ' / 5' : '—', RV.lu ? (RV.notees ? RV.notees + ' notées · ' + (RV.parNote || []).map(p => p.note + '★ ' + p.n).join(' · ') : 'rien de noté sur 30 jours') : (RV.motif || ''), RV.lu && RV.moyenne != null ? (RV.moyenne >= 4 ? 'ok' : RV.moyenne >= 3 ? 'att' : 'ko') : 'mu',
        RV.lu && RV.notees ? '<div class="sv-rep">' + (RV.parNote || []).map(p => '<div><span>' + p.note + ' ★ ' + esc(p.nom) + '</span><i><b style="width:' + Math.round(100 * p.n / RV.notees) + '%"></b></i><span>' + p.n + '</span></div>').join('') + '</div>' : mu(RV.lu ? 'Aucune tâche notée sur 30 jours.' : (RV.motif || 'les notes ne se lisent pas')))
      + tuile('infractions', 'Infractions · 30 j', RV.lu ? String(RV.nc || 0) : '—', RV.lu ? ([nZ(RV.mineures, 'mineure'), nZ(RV.majeures, 'majeure'), nZ(RV.critiques, 'critique')].filter(Boolean).join(' · ') || 'aucune') : (RV.motif || ''), RV.lu ? (RV.nc ? ((RV.majeures || RV.critiques) ? 'ko' : 'att') : 'ok') : 'mu',
        RV.lu && RV.nc ? '<div class="sv-nc">' + (RV.infractions || []).map(i => '<div>' + NIV(i) + '<span>' + fD(i.jour) + ' · <b>' + esc(i.tache) + '</b>' + (i.note != null ? ' · ' + i.note + ' / 5' : '') + (i.comment ? ' — ' + esc(i.comment) : '') + (i.recidive ? ' · <span class="ko">' + i.recidive + ' j sur 7</span>' : '') + '</span></div>').join('') + '</div>' : mu(RV.lu ? 'Aucune infraction sur 30 jours.' : (RV.motif || 'les notes ne se lisent pas')),
        RV.lu && RV.nc ? { titre: 'Vos infractions · 30 jours', html: tab(['Jour', 'Tâche', 'Note', 'Gravité', 'Commentaire', 'Récidive', 'Suite'], (RV.infractions || []).map(i => [fDJ(i.jour), '<b>' + esc(i.tache) + '</b>', i.note != null ? i.note + ' / 5' : '—', NIV(i) + ' ' + esc(i.niveauNom || ''), esc(i.comment || ''), i.recidive ? '<span class="ko">' + i.recidive + ' j sur 7</span>' : '—', SUITE(i)])) } : null)
      + tuile('invendus', 'Invendus · 7 j', IV.lu && IV.part != null ? nf(IV.part, 1) + ' %' : '—', IV.lu && IV.part != null ? 'du CA · cible sous ' + nf(IV.cible || 4) + ' %' : (IV.motif || ''), IV.lu && IV.part != null ? (IV.part > (IV.cible || 4) ? 'ko' : 'ok') : 'mu',
        IV.lu ? 'Du ' + fD(IV.du) + ' au ' + fD(IV.au) + ' : <b>' + nf(IV.cout, 2) + ' €</b> d’invendus' + (IV.ca != null ? ' pour ' + nf(IV.ca) + ' € de CA' : '') + '. ' + mu('Le détail par produit est dans votre dashboard.') : mu(IV.motif || 'la poubelle ne se lit pas'))
      + tuile('reclamations', 'Réclamations · 30 j', RC.lu ? String(RC.n) : '—', RC.lu ? ((RC.ouvertes ? RC.ouvertes + ' sans réponse' : (RC.n ? 'toutes traitées' : 'aucune')) + (RC.montant ? ' · ' + nf(RC.montant, 2) + ' €' : '')) : (RC.motif || ''), RC.lu ? (RC.ouvertes ? 'att' : '') : 'mu',
        RC.lu && RC.n ? '<span class="mu">' + (RC.dernieres || []).map(r => fD(r.le) + ' ' + esc(r.fournisseur) + ' — ' + esc(r.reference) + (r.qte != null ? ' ' + nf(r.qte) + ' ' + esc(r.unite) : '') + (r.motif ? ' · ' + esc(r.motif) : '') + ' · ' + ST_RC(r)).join('<br>') + '</span>' : mu(RC.lu ? 'Aucune réclamation sur 30 jours.' : (RC.motif || 'les réclamations ne se lisent pas')),
        RC.lu && RC.n ? { titre: 'Vos réclamations fournisseur · 30 jours', html: tab(['Date', 'Fournisseur', 'Référence', 'Qté', 'Motif', 'Statut', 'Réponse', 'Montant'], (RC.lignes || RC.dernieres || []).map(r => [fDJ(r.le), esc(r.fournisseur), '<b>' + esc(r.reference) + '</b>', r.qte != null ? nf(r.qte) + ' ' + esc(r.unite) : '—', esc(r.motif), ST_RC(r), esc(r.reponse || ''), r.montant != null ? nf(r.montant, 2) + ' €' : '—'])) } : null)
      + tuile('objectifs', 'Objectifs', o1 ? (o1.pct != null ? o1.pct + ' %' : '—') : (OB.campagnes[0] ? nf(OB.campagnes[0].reel) : '—'), o1 ? o1.nom + (OB.produits.length > 1 ? ' · + ' + (OB.produits.length - 1) : '') : (OB.campagnes[0] ? 'clients · ' + OB.campagnes[0].nom : 'aucun objectif en cours'), o1 && o1.pct != null ? (o1.pct >= 100 ? 'ok' : o1.pct >= 70 ? 'att' : 'ko') : 'mu',
        (OB.produits.length ? OB.produits.map(c => '« ' + esc(c.nom) + ' » : <b>' + (c.pct != null ? c.pct + ' %' : '—') + '</b> · ' + nf(c.vendu) + ' / ' + nf(c.objectif) + ' pièces · jusqu’au ' + fD(c.fin)).join('<br>') : mu('Pas d’objectif produits en cours.')) + (OB.campagnes.length ? '<br>' + OB.campagnes.map(c => 'Campagne « ' + esc(c.nom) + ' » : <b>' + nf(c.reel) + '</b> clients' + (c.clientsPrevus != null ? ' / ' + nf(c.clientsPrevus) : '') + (c.jourCourant ? ' · jour ' + c.jourCourant + ' / ' + c.nbJours : '')).join('<br>') : ''))
      + tuile('remarques', 'Remarques · 30 j', String(J.remarques.n || 0), J.remarques.n ? J.remarques.parOperateur.map(o => o.employe + ' ' + o.n).join(' · ') : 'aucune', J.remarques.n ? 'att' : 'ok',
        J.remarques.n ? '<span class="mu">' + J.remarques.dernieres.map(r => fD(r.le) + ' ' + esc(r.employe) + ' — ' + esc(r.produit) + (r.motifLib ? ' · ' + esc(r.motifLib) : '') + (r.texte ? ' : ' + esc(r.texte) : '')).join('<br>') + '</span>' : mu('Aucune remarque à votre équipe sur 30 jours.'))
      + tuile('google', 'Note Google', J.google && J.google.note != null ? nf(J.google.note, 1) : '—', J.google && J.google.note != null ? nf(J.google.avis) + ' avis · ' + (J.google.faibles ? J.google.faibles + ' ≤ 2 sur 30 j' : 'aucun avis faible') + (J.google.cible ? ' · cible ' + nf(J.google.cible, 1) : '') : 'pas de fiche reliée', J.google && J.google.note != null ? (J.google.faibles || (J.google.cible && J.google.note < J.google.cible) ? 'att' : 'ok') : 'mu',
        J.google && (J.google.derniers || []).length ? '<span class="mu">' + J.google.derniers.slice(0, 5).map(a => fD(a.le) + ' ' + a.note + '/5' + (a.extrait ? ' « ' + esc(a.extrait) + ' »' : '')).join('<br>') + '</span>' : mu('Pas d’avis récent.'))
      + tuile('ca', 'CA semaine', J.ca ? nf(J.ca.ca) + ' €' : '—', J.ca ? ((J.ca.objectif ? 'objectif ' + nf(J.ca.objectif) + ' €' : '') + (J.ca.pct != null ? ' · ' + (J.ca.pct >= 0 ? '+ ' : '− ') + Math.abs(J.ca.pct) + ' %' : '')) : 'ne se lit pas', J.ca && J.ca.pct != null ? (J.ca.pct < -10 ? 'ko' : J.ca.pct < 0 ? 'att' : 'ok') : 'mu',
        J.ca ? pl(J.ca.jours, 'jour') + ' vu' + (J.ca.jours > 1 ? 's' : '') + ' cette semaine.' : mu('Le CA de la semaine ne se lit pas.'));
    const volT = tuile('visite', 'Dernière visite', der ? (der.points.total ? der.points.ok + ' / ' + der.points.total : fD(der.prevu_le)) : '—', der ? fD(der.prevu_le) + ' · ' + der.typeNom + ' · ' + der.consultantNom : 'aucune visite terminée', der ? (der.points.ko ? 'ko' : 'ok') : 'mu',
        der ? (der.points.ko ? '<span class="ko">À corriger :</span> ' + der.ecarts.map(e => esc(e.libelle) + (e.valeur != null ? ' ' + e.valeur + ' %' : e.note != null ? ' ' + e.note + '/5' : '') + (e.commentaire ? ' (' + esc(e.commentaire) + ')' : '')).join(', ') : '<span class="ok">Rien de non conforme.</span>') + (der.reco ? '<br>' + mu('Recommandation : ' + der.reco) : '') : mu('Aucune visite terminée.'))
      + tuile('plans', 'Plans d’action', String(plans.length), plans.length ? [nZ(nP('P0'), 'P0'), nZ(nP('P1'), 'P1'), nZ(nP('P2'), 'P2')].filter(Boolean).join(' · ') : 'aucun ouvert', nP('P0') ? 'ko' : (plans.length ? 'att' : 'ok'),
        (plans.length ? plans.map(p => '<b>' + p.priorite + '</b> ' + esc(p.titre) + ' — ' + (PLAN_ST[p.statut] || p.statut) + (p.echeance ? ', pour le ' + fD(p.echeance) : '') + (p.retard ? ' · <span class="ko">retard ' + p.retard + ' j</span>' : '')).join('<br>') : mu('Aucun plan ouvert.')) + (fermes.length ? '<br>' + mu(fermes.length + ' fermé(s) ou validé(s) sur 90 jours') : ''),
        R.plans.length ? { titre: 'Vos plans d’action · 90 jours', html: tab(['Priorité', 'Plan', 'Statut', 'Échéance', 'Retard'], R.plans.map(p => ['<b>' + p.priorite + '</b>', '<b>' + esc(p.titre) + '</b>' + (p.detail ? '<br><span class="mu">' + esc(p.detail) + '</span>' : ''), esc(PLAN_ST[p.statut] || p.statut), p.echeance ? fDJ(p.echeance) : '—', p.retard ? '<span class="ko">' + p.retard + ' j</span>' : '—'])) } : null)
      + tuile('prochaine', 'Prochaine visite', pro ? fD(pro.prevu_le) : '—', pro ? pro.typeNom + ' · ' + pro.consultantNom + ' · ' + pro.debut_h + ' · ' + (ST[pro.statut] || '') : 'rien de planifié', pro ? '' : 'mu',
        (V.prochaines.length ? V.prochaines.map(v => fDJ(v.prevu_le) + ' ' + v.debut_h + ' · <b>' + esc(v.typeNom) + '</b> · ' + esc(v.consultantNom) + ' · ' + (ST[v.statut] || '')).join('<br>') : mu('Rien de planifié.')) + (R.cadre.length ? '<br>' + mu('Votre cadre : ' + R.cadre.map(l => l.typeNom + ' ' + l.nb + ' / ' + (l.par === 'mois' ? 'mois' : 'trimestre') + (l.consultantNom ? ' (' + l.consultantNom + ')' : '')).join(' · ')) : ''))
      + tuile('msp', 'Client mystère', msp ? nf(msp.obtenu) + ' / ' + nf(msp.maximum) : '—', msp ? msp.trimestre.replace(/^\d{4}-/, '') + (msp.v != null ? ' · ' + nf(msp.v, 1) + ' / 5' : '') : 'pas de rapport ce trimestre', msp && msp.v != null ? (msp.v >= 4 ? 'ok' : msp.v >= 3 ? 'att' : 'ko') : 'mu',
        msp ? (((Object.keys(msp.rubriques || {}).length ? Object.keys(msp.rubriques).map(k => esc(k) + ' <b>' + esc(msp.rubriques[k]) + '</b>').join(' · ') : '') + (msp.commentaire ? '<br>' + mu('« ' + msp.commentaire + ' »') : '')) || mu('Sans rubrique ni commentaire.')) : mu('Pas de rapport ce trimestre.'))
      + tuile('comptoir', 'Votre comptoir', plano.pct != null ? plano.pct + ' %' : '—', (plano.pct != null ? 'planogramme tenu' : (plano.motif || conf.motif || 'planogramme non lu')) + (asso.obligatoires != null && !asso.motif ? ' · obligatoires ' + asso.presentes + ' / ' + asso.obligatoires : ''), plano.pct != null ? ((asso.manquantes || plano.pct < 80) ? 'att' : 'ok') : 'mu',
        (plano.pct != null ? 'Planogramme : <b>' + plano.pct + ' %</b>' + (plano.tenus != null ? ' (' + plano.tenus + ' / ' + plano.emplacements + ' emplacements)' : '') : mu('Planogramme : ' + (plano.motif || conf.motif || 'non lu'))) + '<br>' + (asso.obligatoires != null && !asso.motif ? 'Références obligatoires vues en caisse sur ' + (asso.jours || 30) + ' j : <b>' + asso.presentes + ' / ' + asso.obligatoires + '</b>' + (asso.manquantes ? ' · <span class="ko">' + pl(asso.manquantes, 'manquante') + '</span>' + ((asso.liste || []).length ? ' : ' + asso.liste.slice(0, 6).map(l => esc(l.nom)).join(', ') : '') : ' · toutes passées en caisse') : mu('Assortiment : ' + (asso.motif || 'non lu'))))
      + tuile('scoring', 'Scoring ' + (sc && sc.trimestre ? (sc.trimestre.court || sc.trimestre.lib) : 'du trimestre'), sc ? nf(sc.total, 1) + ' / 20' : '—', sc ? (sc.etoiles != null ? nf(sc.etoiles, 1) + ' ★ · ' : '') + sc.rang + 'ᵉ sur ' + sc.magasins + ' · précédent ' + nf(sc.prec.total, 1) : 'pas encore calculé', sc ? (sc.total >= 16 ? 'ok' : sc.total >= 12 ? 'att' : 'ko') : 'mu',
        sc ? '<div class="sv-postes">' + Object.keys(sc.postes).map(k => '<div><small>' + esc((sc.noms && sc.noms[k]) || k) + '</small><b>' + (sc.postes[k].v != null ? nf(sc.postes[k].v, 1) : '—') + '</b></div>').join('') + '</div>' + mu('Chaque poste vaut 5 : la note Google, vos tâches, le client mystère, le budget.') : mu('Pas encore calculé.'));
    h += `<div class="sv-deux"><div class="db-card sv-volet j" style="padding:14px 16px"><h2>Chaque jour</h2><div class="sv-grille">${volJ}</div></div>
      <div class="db-card sv-volet t" style="padding:14px 16px"><h2>Sur place</h2><div class="sv-grille">${volT}</div></div></div>`;
    h += `<div class="db-card" style="padding:14px 16px;margin-top:12px"><div class="db-lab" style="margin-bottom:6px">Le journal — constaté et décidé, 60 jours</div>${(d.journal || []).length ? d.journal.map(e => `<div class="sv-jl"><span class="d">${esc(fD(e.le))}</span><span class="sv-src ${e.volet === 'terrain' ? 't' : ''}">${esc(e.volet)}</span><span>${esc(e.texte)}</span></div>`).join('') : '<div class="db-mini">Rien sur la période.</div>'}</div>`;
    h += `<div class="db-mini" style="margin-top:12px">Relu ${esc(d.lu)} · une tuile se déplie sur son détail, « Tout voir » ouvre la liste complète.</div>`;
    h += `<div class="sv-modal" id="svModal" hidden><div class="c"><button class="x" type="button" title="Fermer">✕</button><h3></h3><div class="b"></div></div></div>`;
    $.innerHTML = h;
    const modal = document.getElementById('svModal');
    $.addEventListener('click', e => {
      const b = e.target.closest('[data-modal]');
      if (b && MOD[b.dataset.modal]) { modal.querySelector('h3').textContent = MOD[b.dataset.modal].titre; modal.querySelector('.b').innerHTML = MOD[b.dataset.modal].html; modal.hidden = false; return; }
      if (e.target === modal || e.target.closest('#svModal .x')) { modal.hidden = true; }
    });
  }
  lire('/franchises/fiche?shop=' + encodeURIComponent(SHOP) + '&_cache=300').then(rendre).catch(e => { $.innerHTML = '<div class="db-alerte">Votre suivi ne se lit pas : ' + esc(e.message) + '</div>'; });
})();
