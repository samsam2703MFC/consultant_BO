/* Dashboard magasin — une page à part.
 *
 * Pour UN magasin (?shop=4) : tout ce que Résultat dit de lui (Jour · Semaine ·
 * Mois, déplié), puis l'analyse des heures — la cascade de chaque heure
 * (ventes − matière = marge brute, marge brute − travail = résultat) et le top
 * 5 des produits de l'heure cliquée, avec leur marge.
 *
 * Trois lectures de l'API du cockpit, jamais devinées :
 *   ../api/cockpit/exploitation/jour?date=         (Résultat › Jour)
 *   ../api/cockpit/exploitation/periode?vue=&date= (Résultat › Semaine, Mois)
 *   ../api/cockpit/ventes/stats?shop=&vue=&date=   (les heures, les produits)
 */
(function () {
  'use strict';
  const API = '../api/cockpit';
  const q = new URLSearchParams(location.search);
  const S = { shop: q.get('shop') || '4', vue: ['jour', 'semaine', 'mois'].includes(q.get('vue')) ? q.get('vue') : 'jour',
    date: /^\d{4}-\d{2}-\d{2}$/.test(q.get('date') || '') ? q.get('date') : new Date().toISOString().slice(0, 10),
    heure: null, mode: 'moy', stores: [], res: {}, st: {}, enCours: {}, err: {} };
  const AUJ = new Date().toISOString().slice(0, 10);
  const $ = document.getElementById('dash');

  /* --- formats ------------------------------------------------------------ */
  const esc = v => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const nf = (n, d) => Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d, maximumFractionDigits: d });
  const fE = n => n == null ? '—' : nf(Math.round(n), 0) + ' €';
  const fK = n => n == null ? '—' : (Math.abs(n) >= 10000 ? nf(n / 1000, 1) + ' k€' : fE(n));
  const fU = n => n == null ? '—' : nf(n, 2) + ' €';
  const fP = n => n == null ? '—' : nf(n, 1) + ' %';
  const fS = n => n == null ? '—' : (n >= 0 ? '+ ' : '− ') + fE(Math.abs(n));
  const fSK = n => n == null ? '—' : (n >= 0 ? '+ ' : '− ') + fK(Math.abs(n));
  const fN = n => n == null ? '—' : nf(Math.round(n), 0);
  const coul = n => n == null ? 'mu' : (n >= 0 ? 'ok' : 'ko');
  const fD = d => d ? d.slice(8, 10) + '/' + d.slice(5, 7) : '';
  const fDL = d => { if (!d) { return ''; } const t = new Date(d + 'T12:00:00'); return t.toLocaleDateString('fr-BE', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }); };
  const MOIS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

  /* --- lecture ------------------------------------------------------------ */
  function lire(path) {
    return fetch(API + path, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(r => r.ok ? r.json() : r.json().then(j => Promise.reject(new Error((j && j.error) || ('HTTP ' + r.status))), () => Promise.reject(new Error('HTTP ' + r.status))));
  }
  function cleRes() { return S.vue + '|' + S.date; }
  function cleSt() { return S.shop + '|' + S.vue + '|' + S.date; }
  function charger(force) {
    const kr = cleRes(), ks = cleSt();
    if ((force || !S.res[kr]) && !S.enCours[kr]) {
      S.enCours[kr] = true; delete S.err[kr];
      const p = S.vue === 'jour' ? '/exploitation/jour?date=' + S.date : '/exploitation/periode?vue=' + S.vue + '&date=' + S.date;
      lire(p).then(d => { S.res[kr] = d; }).catch(e => { S.err[kr] = e.message; }).finally(() => { S.enCours[kr] = false; rendre(); });
    }
    if ((force || !S.st[ks]) && !S.enCours[ks]) {
      S.enCours[ks] = true; delete S.err[ks];
      lire('/ventes/stats?shop=' + encodeURIComponent(S.shop) + '&vue=' + S.vue + '&date=' + S.date)
        .then(d => { S.st[ks] = d; if (S.heure === null && d.meilleure) { S.heure = d.meilleure.h; } })
        .catch(e => { S.err[ks] = e.message; }).finally(() => { S.enCours[ks] = false; rendre(); });
    }
    rendre();
  }
  function urlMaj() {
    const u = new URL(location.href);
    u.searchParams.set('shop', S.shop); u.searchParams.set('vue', S.vue); u.searchParams.set('date', S.date);
    history.replaceState(null, '', u.toString());
  }

  /* --- le magasin dans la réponse Résultat --------------------------------- */
  function magasin(d) {
    if (!d || !Array.isArray(d.magasins)) { return null; }
    return d.magasins.find(m => String(m.shopId) === String(S.shop)) || null;
  }
  function nomShop() {
    const s = S.stores.find(x => String(x.id) === String(S.shop));
    return s ? s.nom : ('Magasin ' + S.shop);
  }
  function libPeriode() {
    if (S.vue === 'jour') { return fDL(S.date); }
    const d = S.res[cleRes()];
    if (S.vue === 'semaine') { return d && d.du ? 'semaine du ' + fD(d.du) + ' au ' + fD(d.au) : 'semaine'; }
    const t = new Date(S.date + 'T12:00:00');
    return MOIS[t.getMonth()] + ' ' + t.getFullYear();
  }

  /* --- rendu -------------------------------------------------------------- */
  function rendre() {
    const kr = cleRes(), ks = cleSt();
    const d = S.res[kr], st = S.st[ks];
    const m = magasin(d);
    let h = '';
    h += `<div class="db-hd"><img src="../assets/img/logo.png" alt=""><div><div class="db-titre">${esc(nomShop())}</div><div class="db-sous">Dashboard magasin · ${esc(libPeriode())}${S.vue === 'jour' && S.date === AUJ ? ' · en direct, relu toutes les 10 min' : ''}</div></div>
      <span style="flex:1"></span><a class="db-lien" href="../#/resultat">Cockpit › Résultat ›</a></div>`;
    h += `<div class="db-nav">
      <div class="db-ong">${[['jour', 'Jour'], ['semaine', 'Semaine'], ['mois', 'Mois']].map(o => `<button data-vue="${o[0]}" class="${S.vue === o[0] ? 'on' : ''}">${o[1]}</button>`).join('')}</div>
      <span class="db-lab">Magasin</span><select class="db-sel" id="db-shop">${S.stores.map(s => `<option value="${esc(s.id)}"${String(s.id) === String(S.shop) ? ' selected' : ''}>${esc(s.nom)}</option>`).join('') || `<option value="${esc(S.shop)}">Magasin ${esc(S.shop)}</option>`}</select>
      <span class="db-lab">${S.vue === 'jour' ? 'Date' : (S.vue === 'semaine' ? 'Semaine du' : 'Mois de')}</span>
      <button class="db-btn" data-pas="-1">‹</button><input class="db-sel" type="date" id="db-date" value="${S.date}" max="${AUJ}"><button class="db-btn" data-pas="1">›</button>
      ${S.date !== AUJ ? `<button class="db-btn" data-auj="1">Aujourd’hui</button>` : ''}
      <span style="flex:1"></span><button class="db-btn" data-recharger="1">↻ Relire</button><button class="db-btn" onclick="window.print()">⎙ Imprimer</button></div>`;
    if (S.err[kr]) { h += `<div class="db-err">Résultat : ${esc(S.err[kr])}</div>`; }
    h += `<div class="db-sec">Résultat — ${S.vue === 'jour' ? 'la journée' : (S.vue === 'semaine' ? 'la semaine' : 'le mois')}<small>${S.vue === 'jour' ? 'budget du jour, référence des mêmes jours, P&amp;L court' : 'objectif réparti par la pondération réseau, attendu à ce jour, P&amp;L'}</small></div>`;
    if (!d && !S.err[kr]) { h += squelette(3); }
    else if (d && !m) { h += `<div class="db-alerte">Ce magasin n’est pas dans la réponse de Résultat pour cette période.</div>`; }
    else if (m) { h += S.vue === 'jour' ? rendJour(m, d) : rendPeriode(m, d); }
    h += `<div class="db-sec">Les heures — ventes, matière, travail, résultat<small>${S.vue === 'jour' ? 'heure par heure' : 'moyenne par jour ouvert de la période, ou total'}</small></div>`;
    if (S.err[ks]) { h += `<div class="db-err">Heures : ${esc(S.err[ks])}</div>`; }
    if (!st && !S.err[ks]) { h += squelette(4); }
    else if (st) { h += rendHeures(st); }
    $.innerHTML = h;
    brancher();
  }
  function squelette(n) {
    return `<div class="db-tuiles">${Array.from({ length: 5 }, () => `<div class="db-tui"><div class="db-sk" style="width:60%"></div><div class="db-sk" style="height:24px;margin:8px 0 6px"></div><div class="db-sk" style="width:80%"></div></div>`).join('')}</div>
      <div class="db-card"><div class="ct"><div class="db-sk" style="width:220px"></div></div><div style="padding:14px 16px">${Array.from({ length: n }, () => `<div class="db-sk" style="margin-bottom:10px"></div>`).join('')}</div></div>`;
  }
  function tuile(k, v, s, cls) { return `<div class="db-tui ${cls || ''}"><div class="k">${k}</div><div class="v">${v}</div><div class="s">${s || ''}</div></div>`; }
  function cascade(m) {
    const ov = m.overhead == null ? null : m.overhead;
    return `<div class="db-casc">
      <div><div class="k">Chiffre d’affaires</div><div class="v">${fK(m.ca != null ? m.ca : m.realise)}</div><div class="s">${m.tickets != null ? fN(m.tickets) + ' clients · ' + fU(m.panier) : ''}</div></div>
      <div><div class="k">− Coût matière</div><div class="v">${fK(m.coutMatiere)}</div><div class="s">${fP(m.coutMatierePct)} · food cost</div></div>
      <div><div class="k">= Marge brute</div><div class="v">${fK(m.margeBrute)}</div><div class="s">${fP(m.margeBrutePct != null ? m.margeBrutePct : (m.ca ? 100 * m.margeBrute / m.ca : null))}</div></div>
      <div><div class="k">− Personnel</div><div class="v">${fK(m.labour)}</div><div class="s">${fP(m.labourPct)}${m.labourSource ? ' · ' + esc(m.labourSource) : ''}</div></div>
      <div><div class="k">− Frais généraux</div><div class="v">${fK(ov)}</div><div class="s">${fP(m.overheadPct)}${m.overheadSource ? ' · ' + esc(m.overheadSource) : ''}</div></div>
      <div><div class="k">= Résultat net</div><div class="v ${coul(m.net)}">${m.net == null ? '—' : fSK(m.net)}</div><div class="s">${m.net == null ? esc(m.motifNet || 'non calculable') : fP(m.netPct) + ' des ventes'}${m.net != null && m.motifNet ? ' · ' + esc(m.motifNet) : ''}</div></div>
    </div>`;
  }

  /* Résultat › Jour, déplié pour le magasin. */
  function rendJour(m, d) {
    const ref = d.reference || {};
    const att = m.objectifJour ? Math.min(100, 100 * m.ca / m.objectifJour) : 0;
    let h = `<div class="db-tuiles">
      ${tuile('CA du jour', fK(m.ca), m.objectifJour ? 'objectif ' + fK(m.objectifJour) + ' · ' + fP(100 * (m.objectifAtteinte || 0)) + ' atteint' + (m.objectifSource ? ' · ' + esc(m.objectifSource) : '') : 'pas d’objectif du jour')}
      ${tuile('vs référence', m.caDelta == null ? '—' : (m.caDelta >= 0 ? '+ ' : '− ') + fP(Math.abs(m.caDelta)), (ref.libelle ? esc(ref.libelle) : 'référence') + ' · ' + fK(m.refCa), m.caDelta == null ? '' : (m.caDelta >= 0 ? 'bon' : 'vif'))}
      ${tuile('Clients', fN(m.tickets), 'référence ' + fN(m.refTickets) + (m.ticketsDelta != null ? ' · ' + (m.ticketsDelta >= 0 ? '+ ' : '− ') + fP(Math.abs(m.ticketsDelta)) : ''))}
      ${tuile('Panier moyen', fU(m.panier), m.produitsParClient ? nf(m.produitsParClient, 1) + ' produits / client' : '')}
      ${tuile('Projection fin de journée', m.projection != null ? fK(m.projection) : '—', m.projection != null ? (m.projectionPart != null ? fP(m.projectionPart) + ' de la journée écoulée' : '') + (m.projectionRythme ? ' · au rythme : ' + fK(m.projectionRythme) : '') : esc(m.projectionMotif || ''))}
      ${tuile('Résultat net du jour', m.net == null ? '—' : fSK(m.net), m.net == null ? esc(m.motifNet || '') : fP(m.netPct) + ' des ventes', m.net == null ? '' : (m.net >= 0 ? 'bon' : 'vif'))}
    </div>`;
    if (m.objectifJour) {
      h += `<div class="db-card"><div style="padding:12px 16px"><div class="db-lab">Objectif du jour — ${fK(m.objectifJour)}${m.objectifJourNom ? ' · profil des ' + esc(m.objectifJourNom) + 's' : ''}</div>
        <div class="db-bar"><i style="width:${att.toFixed(1)}%"></i>${m.projectionPart != null ? `<b style="left:${Math.min(100, m.projectionPart).toFixed(1)}%"></b>` : ''}</div>
        <div class="db-mini" style="margin-top:5px">${fP(att)} réalisé${m.projectionPart != null ? ' · le repère noir est la part de journée normalement écoulée (' + fP(m.projectionPart) + ')' : ''}</div></div></div>`;
    }
    h += `<div class="db-card"><div class="ct"><span class="db-lab">Le P&amp;L court de la journée</span><span class="db-mini">matière : coût des recettes vendues · personnel : ${esc(m.planningSource || 'planning')} · frais généraux : ${esc(m.overheadSource || '—')}</span></div>${cascade(m)}</div>`;
    // Catégories et planning côte à côte.
    const cats = Array.isArray(m.categories) ? m.categories : [];
    const plan = Array.isArray(m.planning) ? m.planning : [];
    h += `<div class="db-g2" style="margin-bottom:12px">`;
    h += `<div class="db-card"><div class="ct"><span class="db-lab">Par catégorie</span><span class="db-mini">CA du jour, référence, écart</span></div>
      ${cats.length ? `<table class="db-t"><tr><th>Catégorie</th><th>CA</th><th>Référence</th><th>Écart</th><th>Part</th></tr>${cats.map(c => `<tr><td>${esc(c.categorie)}</td><td>${fE(c.ca)}</td><td class="mu">${fE(c.ref)}</td><td class="${coul(c.delta)}">${c.delta == null ? '—' : (c.delta >= 0 ? '+ ' : '− ') + fP(Math.abs(c.delta))}</td><td class="mu">${c.part != null ? fP(100 * c.part) : '—'}</td></tr>`).join('')}</table>` : `<div class="db-note" style="padding-top:12px">Pas de ventilation par catégorie pour ce jour.</div>`}</div>`;
    const hMin = plan.length ? Math.floor(Math.min(...plan.map(p => hDe(p.debut)))) : 6, hMax = plan.length ? Math.ceil(Math.max(...plan.map(p => hDe(p.fin)))) : 19;
    h += `<div class="db-card"><div class="ct"><span class="db-lab">Qui est en poste</span><span class="db-mini">${m.planningHeures != null ? nf(m.planningHeures, 1) + ' h · ' + fE(m.planningCout) : ''}${m.planningHeuresZero ? ' · ' + nf(m.planningHeuresZero, 1) + ' h à 0 €/h (' + esc((m.planningZeroNoms || []).join(', ')) + ')' : ''}</span></div>
      <div style="padding:8px 16px 12px">${plan.length ? plan.map(p => `<div class="db-plan"><span><b>${esc(p.nom)}</b><br><span class="mu">${esc(p.debut)} – ${esc(p.fin)} · ${nf(p.h, 1)} h${p.franchise ? ' · franchisé' : ''}</span></span><span class="g"><i class="${p.franchise ? 'fr' : ''}" style="left:${(100 * (hDe(p.debut) - hMin) / (hMax - hMin)).toFixed(1)}%;width:${(100 * (hDe(p.fin) - hDe(p.debut)) / (hMax - hMin)).toFixed(1)}%"></i></span><span style="text-align:right"><b>${fE(p.cout)}</b><br><span class="mu">${p.caH != null ? fE(p.caH) + '/h vendu' : ''}</span></span></div>`).join('') : '<div class="db-note">Pas de planning lu pour ce jour.</div>'}</div></div>`;
    h += `</div>`;
    const serie = Array.isArray(m.serie) ? m.serie.filter(x => x.ouvert) : [];
    if (serie.length) {
      const mx = Math.max(...serie.map(x => x.ca || 0), 1);
      h += `<div class="db-card"><div class="ct"><span class="db-lab">Les derniers jours</span><span class="db-mini">CA et résultat net par jour ouvert</span></div>
        <div class="db-jours" style="grid-template-columns:repeat(${serie.length},1fr)">${serie.map(x => `<div><em>${fK(x.ca)}</em><div class="bb"><i class="${x.date === S.date ? 'auj' : ''}" style="height:${(100 * x.ca / mx).toFixed(1)}%"></i></div><span>${fD(x.date)}</span><span class="${coul(x.net)}">${x.net == null ? '' : fP(x.netPct)}</span></div>`).join('')}</div></div>`;
    }
    return h;
  }
  function hDe(t) { const p = String(t || '0:0').split(':'); return (+p[0] || 0) + (+p[1] || 0) / 60; }

  /* Résultat › Semaine, Mois — le magasin déplié. */
  function rendPeriode(m, d) {
    const sansO = m.objectif == null;
    const srcO = m.objectifSource === 'budget' ? 'budget validé' : (m.objectifSource === 'theorique' ? 'CA théorique de l’étude' : esc(m.objectifSource || ''));
    const sansB = Array.isArray(m.sansBudget) && m.sansBudget.length ? ' · sans budget ' + esc(m.sansBudget.join(', ')) : '';
    const att = m.objectif ? Math.min(100, 100 * m.realise / m.objectif) : 0;
    const attendu = m.objectif && m.attendu != null ? Math.min(100, 100 * m.attendu / m.objectif) : null;
    const cl = m.clientsManquants;
    let h = `<div class="db-tuiles">
      ${tuile('Objectif', sansO ? '—' : fK(m.objectif), sansO ? 'pas de budget pour cette période' : srcO + ' · réparti sur la pondération réseau' + sansB)}
      ${tuile('Attendu à ce jour', sansO ? '—' : fK(m.attendu), sansO ? '' : 'jours passés au poids de chacun')}
      ${tuile('Réalisé', fK(m.realise), fN(m.tickets) + ' clients · panier ' + fU(m.panier) + (m.atteinte != null && !sansO ? ' · ' + fP(100 * m.atteinte) + ' de l’attendu' : ''))}
      ${tuile('Écart à l’attendu', sansO ? '—' : fSK(m.ecart), sansO ? '' : (cl != null ? (cl > 0 ? fN(cl) + ' clients manquants' : fN(-cl) + ' clients d’avance') + ' · ' + fE(Math.abs(m.ecart)) + ' ÷ ' + fU(m.panier) : ''), sansO ? '' : (m.ecart >= 0 ? 'bon' : 'vif'))}
      ${tuile('Reste à faire', sansO ? '—' : fK(m.reste), sansO ? '' : 'prévu sur les jours restants : ' + fK(m.prevu))}
      ${tuile('Résultat net', m.net == null ? '—' : fSK(m.net), m.net == null ? esc(m.motifNet || '') : fP(m.netPct) + ' des ventes', m.net == null ? '' : (m.net >= 0 ? 'bon' : 'vif'))}
    </div>`;
    if (!sansO) {
      h += `<div class="db-card"><div style="padding:12px 16px"><div class="db-lab">Objectif ${S.vue === 'semaine' ? 'de la semaine' : 'du mois'} — ${fK(m.objectif)}</div>
        <div class="db-bar"><i style="width:${att.toFixed(1)}%"></i>${attendu != null ? `<b style="left:${attendu.toFixed(1)}%"></b>` : ''}</div>
        <div class="db-mini" style="margin-top:5px">${fP(att)} réalisé${attendu != null ? ' · le repère noir est l’attendu à ce jour (' + fP(attendu) + ')' : ''}</div></div></div>`;
    }
    const jours = Array.isArray(m.jours) ? m.jours : [];
    if (jours.length) {
      const mx = Math.max(...jours.map(x => Math.max(x.ca || 0, x.objectif || 0)), 1);
      h += `<div class="db-card"><div class="ct"><span class="db-lab">Jour par jour</span><span class="db-mini">barre : CA · trait noir : objectif du jour · rose : sous l’objectif · orange : aujourd’hui</span></div>
        <div class="db-jours" style="grid-template-columns:repeat(${jours.length},minmax(0,1fr))">${jours.map(x => {
          const ca = x.ca || 0; const cls = x.ferme ? 'ferme' : (x.aujourdhui ? 'auj' : (x.objectif && ca < x.objectif && x.passe ? 'sous' : ''));
          return `<div title="${esc(x.date)}${x.objectif ? ' · objectif ' + fE(x.objectif) : ''}"><em>${x.ferme ? '' : (x.passe || x.aujourdhui ? (S.vue === 'mois' ? nf(ca / 1000, 1) : fK(ca)) : '')}</em><div class="bb"><i class="${cls}" style="height:${x.ferme ? 4 : (100 * ca / mx).toFixed(1)}%"></i>${x.objectif ? `<b style="bottom:${(100 * x.objectif / mx).toFixed(1)}%"></b>` : ''}</div><span>${esc(x.court)}</span></div>`; }).join('')}</div>
        ${S.vue === 'mois' ? '<div class="db-note">Montants en k€.</div>' : ''}</div>`;
    }
    h += `<div class="db-card"><div class="ct"><span class="db-lab">Le P&amp;L ${S.vue === 'semaine' ? 'de la semaine' : 'du mois'}</span><span class="db-mini">matière : coût des recettes vendues · personnel : planning × taux · frais généraux : panel</span></div>${cascade(m)}</div>`;
    return h;
  }

  /* Les heures : courbe, tableau en cascade, panneau de l'heure. */
  function rendHeures(st) {
    const L = st.heures || [];
    if (!L.length) { return `<div class="db-alerte">Aucune heure vendue sur cette période${st.joursServis && st.joursServis.length === 0 ? ' — le panel n’a pas répondu' : ''}.</div>`; }
    const moy = S.mode === 'moy' && S.vue !== 'jour';
    const val = (l, k) => moy ? l.moy[k] : l[k];
    const nJ = Math.max(1, st.nJoursOuverts || 1);
    const tot = st.totaux || {};
    if (S.heure === null || !L.some(l => l.h === S.heure)) { S.heure = st.meilleure ? st.meilleure.h : L[0].h; }
    const sel = L.find(l => l.h === S.heure) || L[0];
    let h = `<div class="db-tuiles">
      ${tuile(S.vue === 'jour' ? 'Clients' : 'Clients / jour ouvert', fN(tot.tickets / (S.vue === 'jour' ? 1 : nJ)), 'panier ' + fU(tot.panier) + (S.vue !== 'jour' ? ' · ' + nJ + ' jour(s) ouvert(s)' : ''))}
      ${tuile('Ventes', fK(tot.ca), 'matière ' + fP(tot.ca ? 100 * tot.mat / tot.ca : null) + ' · marge brute ' + fP(tot.mbPct))}
      ${tuile('Résultat des heures', fSK(tot.res), fP(tot.resPct) + ' des ventes · travail ' + fK(tot.trav), tot.res >= 0 ? 'bon' : 'vif')}
      ${st.meilleure ? tuile('Heure la plus rentable', st.meilleure.h + ' – ' + (st.meilleure.h + 1) + ' h', fSK(moy ? st.meilleure.moy : st.meilleure.res) + (moy ? ' par jour ouvert' : ''), 'bon') : ''}
      ${st.pire ? tuile('Heure la moins rentable', st.pire.h + ' – ' + (st.pire.h + 1) + ' h', fSK(moy ? st.pire.moy : st.pire.res) + (moy ? ' par jour ouvert' : ''), (moy ? st.pire.moy : st.pire.res) < 0 ? 'vif' : '') : ''}
    </div>`;
    // La courbe.
    const max = Math.max(...L.map(l => val(l, 'ca')), 1) * 1.1;
    const w = 100 / L.length;
    const pts = [], pts2 = [];
    let cols = '';
    L.forEach((l, i) => {
      const x = (i + 0.5) * w;
      pts.push([x, 100 - 100 * val(l, 'trav') / max]); pts2.push([x, 100 - 100 * (val(l, 'trav') + val(l, 'mat')) / max]);
      cols += `<div class="col" data-h="${l.h}" style="left:${(x - w / 2).toFixed(2)}%;width:${w.toFixed(2)}%;height:${(100 * val(l, 'ca') / max).toFixed(1)}%"><em>${fE(val(l, 'ca'))}</em><i class="${val(l, 'res') < 0 ? 'perte' : ''}${l.h === sel.h ? ' sel' : ''}" style="height:100%"></i><span>${l.h} h</span></div>`;
    });
    const path = a => a.map((p, i) => (i ? 'L' : 'M') + p[0].toFixed(2) + ' ' + p[1].toFixed(2)).join(' ');
    h += `<div class="db-card"><div class="ct"><span class="db-lab">${S.vue === 'jour' ? 'La journée' : (moy ? 'La journée type — moyenne par jour ouvert' : 'La période — total des heures')}</span>
      ${S.vue !== 'jour' ? `<div class="db-ong" style="margin-left:8px"><button data-mode="moy" class="${moy ? 'on' : ''}">Moyenne / jour ouvert</button><button data-mode="tot" class="${moy ? '' : 'on'}">Total</button></div>` : ''}
      <span class="db-mini">cliquez une heure</span></div>
      <div class="db-chart">${cols}<svg viewBox="0 0 100 100" preserveAspectRatio="none"><path d="${path(pts)}" fill="none" stroke="#222" stroke-width=".7" vector-effect="non-scaling-stroke"/><path d="${path(pts2)}" fill="none" stroke="#D97706" stroke-width=".9" stroke-dasharray="2 1.5" vector-effect="non-scaling-stroke"/></svg></div>
      <div class="db-axe"><span><i class="p"></i>Ventes de l’heure &nbsp; <i></i>coût du travail &nbsp; <i class="t"></i>travail + matière</span><span>barre claire : l’heure ne paie pas ses coûts</span></div></div>`;
    // Tableau + panneau.
    h += `<div class="db-g2">`;
    h += `<div class="db-card"><div class="ct"><span class="db-lab">Heure par heure — ventes − matière = marge brute · marge brute − travail = résultat</span></div>
      <table class="db-t"><tr><th>Heure</th><th>Clients</th><th>Panier</th><th>Ventes</th><th>− Matière</th><th>= Marge brute</th><th>En poste</th><th>− Travail</th><th>= Résultat</th></tr>
      ${L.map(l => `<tr class="hv${l.h === sel.h ? ' sel' : ''}${val(l, 'res') < 0 ? ' perte' : ''}" data-h="${l.h}"><td>${l.h === sel.h ? '▾' : '▸'} ${l.h} – ${l.h + 1} h</td><td>${moy ? nf(l.moy.tickets, 0) : fN(l.tickets)}</td><td>${fU(l.panier)}</td><td>${fE(val(l, 'ca'))}</td><td class="mu">${fE(val(l, 'mat'))}</td><td><b>${fE(val(l, 'mb'))}</b> <span class="mu" style="font-weight:400">${fP(l.mbPct)}</span></td><td>${l.poste}</td><td class="mu">${fE(val(l, 'trav'))}</td><td class="${coul(val(l, 'res'))}"><b>${fS(val(l, 'res'))}</b></td></tr>`).join('')}
      <tr class="tot"><td>${S.vue === 'jour' ? 'Journée' : (moy ? 'Jour type' : 'Période')}</td><td>${fN(tot.tickets / (moy ? nJ : 1))}</td><td>${fU(tot.panier)}</td><td>${fE(tot.ca / (moy ? nJ : 1))}</td><td>${fE(tot.mat / (moy ? nJ : 1))}</td><td>${fE(tot.mb / (moy ? nJ : 1))} <span class="mu" style="font-weight:400">${fP(tot.mbPct)}</span></td><td>—</td><td>${fE(tot.trav / (moy ? nJ : 1))}</td><td class="${coul(tot.res)}">${fS(tot.res / (moy ? nJ : 1))}</td></tr></table>
      <div class="db-note" style="padding-top:10px">Ventes, matière, personnel et marge de l’heure viennent du panel (répartition horaire). Le personnel en poste est la moyenne des jours ouverts.</div></div>`;
    h += rendPanneau(st, sel, moy, nJ);
    h += `</div>`;
    return h;
  }
  function rendPanneau(st, l, moy, nJ) {
    const V = moy ? l.moy.ca : l.ca, M = moy ? l.moy.mat : l.mat, MB = V - M, T = moy ? l.moy.trav : l.trav, R = MB - T;
    const base = Math.max(V, 1);
    const rows = [['Ventes', V, 0, V, '#8D1D2C'], ['− Coût matière', M, V - M, V, '#e5c9a0'], ['= Marge brute', MB, 0, MB, '#8D1D2C'], ['− Coût du travail', T, MB - T, MB, '#D97706'], ['= Résultat de l’heure', R, 0, Math.max(R, 0), R >= 0 ? '#2d7a3e' : '#C0182B']];
    const top = l.top || [];
    const mx = Math.max(...top.map(x => x.v), 1);
    const pr = st.produits || {};
    const couv = pr.total ? `${pr.jours.length} jour(s) sur ${pr.total}` : '';
    return `<div class="db-card"><div class="ct"><span class="db-lab">${l.h} – ${l.h + 1} h</span><span class="db-mini">${moy ? nf(l.moy.tickets, 0) : fN(l.tickets)} clients · panier ${fU(l.panier)}${moy ? ' · par jour ouvert' : ''}</span></div>
      <div class="db-wf">${rows.map((r, k) => `<div class="r${k === 4 ? ' tot' : ''}"><span class="n">${r[0]}</span><span class="b"><i style="left:${Math.max(0, 100 * r[2] / base).toFixed(1)}%;width:${Math.max(0, 100 * (r[3] - r[2]) / base).toFixed(1)}%;background:${r[4]}"></i></span><span class="v ${k === 4 ? coul(R) : ''}">${k === 4 ? fS(R) : fE(r[1])}</span></div>`).join('')}</div>
      <div class="ct" style="border-top:.5px solid var(--color-border-tertiary);border-bottom:none;padding:10px 16px 4px"><span class="db-lab">Top 5 de l’heure — par marge brute</span><span class="db-mini">■ marge &nbsp;<span style="color:#c9a36a">■</span> coût matière</span></div>
      ${top.length ? `<div class="db-pl">${top.map((x, k) => `<div class="r"><b class="n">${k + 1}</b><span title="${esc(x.nom)}">${esc(x.nom)}</span><span class="v mu">${nf(x.q, 0)} pcs</span><span class="b"><i style="width:${(100 * x.v / mx).toFixed(1)}%;background:#e5c9a0"></i>${x.m != null ? `<i style="width:${(100 * Math.max(0, x.m) / mx).toFixed(1)}%"></i>` : ''}</span><span class="v"><b>${x.m != null ? fU(x.m) : fU(x.v)}</b></span><span class="v ${x.taux == null ? 'mu' : (x.taux < 50 ? 'wa' : 'ok')}">${x.taux != null ? nf(x.taux, 0) + ' %' : 'sans coût'}</span></div>`).join('')}</div>
        <div class="db-note">${l.references} référence(s) vendue(s) à cette heure${l.topSur ? ' · tickets lus sur ' + l.topSur + ' jour(s)' : ''}${pr.total && !pr.complet ? ' · <b>tickets lus : ' + couv + '</b> — la moisson complète la période au fil des heures' : ''}. Marge = ventes − quantité × coût de la recette ; « sans coût » : recette non chiffrée.</div>`
        : `<div class="db-note" style="padding-top:8px">${pr.total ? (pr.jours.length ? 'Aucune ligne produit à cette heure sur les jours lus (' + couv + ').' : 'Tickets pas encore lus pour cette période — la moisson les lit par lots ; relisez dans quelques minutes.') : 'Les tickets ne sont moissonnés que depuis août 2026.'}</div>`}
    </div>`;
  }

  /* --- gestes -------------------------------------------------------------- */
  function brancher() {
    $.querySelectorAll('[data-vue]').forEach(b => b.addEventListener('click', () => { S.vue = b.dataset.vue; S.heure = null; urlMaj(); charger(false); }));
    const sh = document.getElementById('db-shop'); if (sh) { sh.addEventListener('change', () => { S.shop = sh.value; S.heure = null; urlMaj(); charger(false); }); }
    const dt = document.getElementById('db-date'); if (dt) { dt.addEventListener('change', () => { if (dt.value && dt.value <= AUJ) { S.date = dt.value; S.heure = null; urlMaj(); charger(false); } }); }
    $.querySelectorAll('[data-pas]').forEach(b => b.addEventListener('click', () => {
      const t = new Date(S.date + 'T12:00:00'); const n = +b.dataset.pas;
      if (S.vue === 'jour') { t.setDate(t.getDate() + n); } else if (S.vue === 'semaine') { t.setDate(t.getDate() + 7 * n); } else { t.setMonth(t.getMonth() + n, 1); }
      const d = t.toISOString().slice(0, 10); if (d > AUJ) { return; }
      S.date = d; S.heure = null; urlMaj(); charger(false); }));
    $.querySelectorAll('[data-auj]').forEach(b => b.addEventListener('click', () => { S.date = AUJ; S.heure = null; urlMaj(); charger(false); }));
    $.querySelectorAll('[data-recharger]').forEach(b => b.addEventListener('click', () => charger(true)));
    $.querySelectorAll('[data-mode]').forEach(b => b.addEventListener('click', () => { S.mode = b.dataset.mode; rendre(); }));
    $.querySelectorAll('[data-h]').forEach(el => el.addEventListener('click', () => { S.heure = +el.dataset.h; rendre(); }));
  }

  /* --- départ ------------------------------------------------------------- */
  lire('/stores?statut=tous').then(l => { S.stores = (Array.isArray(l) ? l : []).filter(s => !s.status || /ouvert/i.test(s.status)).map(s => ({ id: s.id, nom: s.nom || s.name })); rendre(); }).catch(() => {});
  urlMaj();
  charger(false);
  // La journée en cours se relit toutes les dix minutes.
  setInterval(() => { if (S.vue === 'jour' && S.date === AUJ) { charger(true); } }, 600000);
})();
