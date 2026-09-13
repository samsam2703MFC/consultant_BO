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
  const S = { shop: q.get('shop') || '4', vue: ['jour', 'semaine', 'mois', 'annee'].includes(q.get('vue')) ? q.get('vue') : 'jour',
    date: /^\d{4}-\d{2}-\d{2}$/.test(q.get('date') || '') ? q.get('date') : new Date().toISOString().slice(0, 10),
    heure: null, mode: 'moy', hmMetric: 'pct', tOuvert: false, stores: [], res: {}, st: {}, enCours: {}, err: {}, relances: {}, aux: {} };
  const AUJ = new Date().toISOString().slice(0, 10);
  const $ = document.getElementById('dash');

  /* --- formats ------------------------------------------------------------ */
  const esc = v => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const nf = (n, d) => Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d, maximumFractionDigits: d });
  const fE = n => n == null ? '—' : nf(Math.round(n) || 0, 0) + ' €';
  const fK = n => n == null ? '—' : (Math.abs(n) >= 10000 ? Number(n / 1000).toLocaleString('fr-BE', { minimumFractionDigits: 0, maximumFractionDigits: 1 }) + ' k€' : fE(n));
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
  function annee() { return +S.date.slice(0, 4); }
  function bornes() {
    const t = new Date(S.date + 'T12:00:00');
    if (S.vue === 'semaine') { const j = (t.getDay() + 6) % 7; const du = new Date(t); du.setDate(t.getDate() - j); const au = new Date(du); au.setDate(du.getDate() + 6); return [du.toISOString().slice(0, 10), au.toISOString().slice(0, 10)]; }
    const du = S.date.slice(0, 8) + '01'; const fin = new Date(t.getFullYear(), t.getMonth() + 1, 0); return [du, fin.toISOString().slice(0, 10)];
  }
  function lireAux(cle, path, force) {
    if ((force || !S.aux[cle]) && !S.enCours[cle]) {
      S.enCours[cle] = true; delete S.err[cle];
      lire(path).then(d => { S.aux[cle] = d; }).catch(e => { S.err[cle] = e.message; }).finally(() => { S.enCours[cle] = false; rendre(); });
    }
  }
  function charger(force) {
    const kr = cleRes(), ks = cleSt();
    if (S.vue === 'annee') {
      lireAux('perf|' + annee(), '/stores/perf?granularite=mois&annees=' + (annee() - 1) + ',' + annee(), force);
      lireAux('plan|' + S.shop + '|' + annee(), '/plan?shop=' + encodeURIComponent(S.shop) + '&exercice=' + annee(), force);
      rendre(); return;
    }
    if (S.vue === 'mois' && S.date.slice(0, 7) === AUJ.slice(0, 7)) { lireAux('rentab', '/exploitation/rentabilite?periode=mois', force); }
    if (S.vue === 'jour') { lireAux('taches|' + S.date, '/pwa/tasks?date=' + S.date, force); }
    else { const [du, au] = bornes(); lireAux('tachesP|' + du + '|' + au, '/pwa/tasks/heatmap/mois?du=' + du + '&au=' + (au < AUJ ? au : AUJ), force); }
    if ((force || !S.res[kr]) && !S.enCours[kr]) {
      S.enCours[kr] = true; delete S.err[kr];
      const p = S.vue === 'jour' ? '/exploitation/jour?date=' + S.date : '/exploitation/periode?vue=' + S.vue + '&date=' + S.date;
      lire(p).then(d => { S.res[kr] = d; }).catch(e => { S.err[kr] = e.message; }).finally(() => { S.enCours[kr] = false; rendre(); });
    }
    if ((force || !S.st[ks]) && !S.enCours[ks]) {
      S.enCours[ks] = true; delete S.err[ks];
      lire('/ventes/stats?shop=' + encodeURIComponent(S.shop) + '&vue=' + S.vue + '&date=' + S.date)
        .then(d => { S.st[ks] = d; if (S.heure === null && d.meilleure) { S.heure = d.meilleure.h; }
          // Une lecture partielle (tickets à suivre) se complète toute seule.
          if (d.produits && d.produits.aSuivre && (S.relances[ks] || 0) < 6) { S.relances[ks] = (S.relances[ks] || 0) + 1; setTimeout(() => { if (cleSt() === ks) { charger(true); } }, 4000); } })
        .catch(e => { S.err[ks] = e.message;
          // Un échec (temps dépassé côté serveur) se retente : ce qui a été lu est gravé.
          if ((S.relances[ks] || 0) < 3) { S.relances[ks] = (S.relances[ks] || 0) + 1; setTimeout(() => { if (cleSt() === ks) { charger(true); } }, 5000); } })
        .finally(() => { S.enCours[ks] = false; rendre(); });
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
    if (S.vue === 'annee') { return 'année ' + annee(); }
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
      <div class="db-ong">${[['jour', 'Jour'], ['semaine', 'Semaine'], ['mois', 'Mois'], ['annee', 'Année']].map(o => `<button data-vue="${o[0]}" class="${S.vue === o[0] ? 'on' : ''}">${o[1]}</button>`).join('')}</div>
      <span class="db-lab">${S.vue === 'jour' ? 'Date' : (S.vue === 'semaine' ? 'Semaine du' : (S.vue === 'mois' ? 'Mois de' : 'Année de'))}</span>
      <button class="db-btn" data-pas="-1">‹</button><input class="db-sel" type="date" id="db-date" value="${S.date}" max="${AUJ}"><button class="db-btn" data-pas="1">›</button>
      ${S.date !== AUJ ? `<button class="db-btn" data-auj="1">Aujourd’hui</button>` : ''}
      <span style="flex:1"></span><button class="db-btn" data-recharger="1">↻ Relire</button></div>`;
    if (S.vue === 'annee') { h += rendAnnee(); $.innerHTML = h; brancher(); return; }
    if (S.err[kr]) { h += `<div class="db-err">Résultat : ${esc(S.err[kr])}</div>`; }
    // Le bandeau : la place du magasin dans le réseau, sans nommer les autres.
    if (m) { h += rendBench(m, d); }
    h += rendTaches();
    h += `<div class="db-sec">Résultat — ${S.vue === 'jour' ? 'la journée' : (S.vue === 'semaine' ? 'la semaine' : 'le mois')}<small>${S.vue === 'jour' ? 'budget du jour, référence des mêmes jours, P&amp;L court' : 'objectif réparti par la pondération réseau, attendu à ce jour, P&amp;L'}</small></div>`;
    if (!d && !S.err[kr]) { h += squelette(3); }
    else if (d && !m) { h += `<div class="db-alerte">Ce magasin n’est pas dans la réponse de Résultat pour cette période.</div>`; }
    else if (m) { h += S.vue === 'jour' ? rendJour(m, d) : rendPeriode(m, d); }
    h += `<div class="db-sec">Les heures — ventes, matière, rémunération, marge nette<small>${S.vue === 'jour' ? 'heure par heure' : 'moyenne par jour ouvert de la période, ou total'}</small></div>`;
    if (S.err[ks]) { h += `<div class="db-err">Heures : ${esc(S.err[ks])}${(S.relances[ks] || 0) < 3 ? " — nouvelle lecture dans quelques secondes" : ""}</div>`; }
    if (st && st.produits && st.produits.aSuivre) { h += `<div class="db-alerte">Tickets lus sur ${st.produits.jours.length} jour(s) sur ${st.produits.total} — la lecture continue, la page se complète toute seule.</div>`; }
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
  function cascade(m, d) {
    const se = (d && d.seuils) || {};
    const ca = m.ca != null ? m.ca : m.realise;
    const feu = (v, s) => v == null || s == null ? 'var(--color-text-muted)' : (v <= s ? '#2d7a3e' : (v <= s * 1.3 ? '#D97706' : '#C0182B'));
    const feuRes = p => p == null ? 'var(--color-text-muted)' : (p >= 15 ? '#2d7a3e' : (p >= 5 ? '#D97706' : '#C0182B'));
    const mbPct = m.margeBrutePct != null ? m.margeBrutePct : (ca ? 100 * m.margeBrute / ca : null);
    const barre = p => Math.min(Math.abs(p || 0), 100).toFixed(1);
    const ligne = (lib, sous, v, pct, coul, w, fort, note) => `<div class="db-cl${fort ? ' fort' : ''}"><span><b>${lib}</b>${sous ? `<br><span class="mu">${sous}</span>` : ''}</span><span class="b"><i style="width:${w}%;background:${coul}"></i></span><span class="v">${v}</span><span class="p" style="color:${coul}">${pct}</span><span class="n mu">${note || ''}</span></div>`;
    const fr = m.planningHeuresFranchise ? 'hors ' + nf(m.planningHeuresFranchise, 1) + ' h de franchisé (' + esc((m.planningFranchiseNoms || []).join(', ')) + ')' : '';
    return `<div class="db-cascade">
      ${ligne('Chiffre d’affaires', m.tickets != null ? fN(m.tickets) + ' clients · ' + fU(m.panier) : '', fE(ca), '100 %', 'var(--color-text)', 100, true, '')}
      ${ligne('− Coût matière', se.food != null ? 'seuil ' + fP(se.food) : 'coût des recettes vendues', fE(-m.coutMatiere), fP(m.coutMatierePct), feu(m.coutMatierePct, se.food), barre(m.coutMatierePct), false)}
      ${ligne('= Marge brute', '', fE(m.margeBrute), fP(mbPct), 'var(--color-text)', barre(mbPct), true)}
      ${ligne('− Main-d’œuvre', (se.labour != null ? 'seuil ' + fP(se.labour) : '') + (m.labourSource ? ' · ' + esc(m.labourSource) : ''), m.labour == null ? '—' : fE(-m.labour), fP(m.labourPct), feu(m.labourPct, se.labour), barre(m.labourPct), false, fr)}
      ${ligne('− Frais généraux', (se.overhead != null ? 'seuil ' + fP(se.overhead) : '') + (m.overheadSource ? ' · ' + esc(m.overheadSource) : ''), m.overhead == null ? '—' : fE(-m.overhead), fP(m.overheadPct), feu(m.overheadPct, se.overhead), barre(m.overheadPct), false)}
      ${ligne('= Résultat', m.net == null ? esc(m.motifNet || 'non calculable') : '', m.net == null ? '—' : fS(m.net), fP(m.netPct), feuRes(m.netPct), barre(m.netPct), true, m.net != null && m.motifNet ? esc(m.motifNet) : '')}
    </div>`;
  }
  function cascadeVieille(m) {
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
      ${tuile('CA du jour', fK(m.ca), m.objectifJour ? 'objectif ' + fK(m.objectifJour) + ' · ' + fP(100 * (m.objectifAtteinte || 0)) + ' atteint' + (m.panier > 0 && m.objectifJour - m.ca > 0 ? ' · <b class="ko">−' + fN((m.objectifJour - m.ca) / m.panier) + ' clients</b> (' + fE(m.objectifJour - m.ca) + ' ÷ ' + fU(m.panier) + ')' : (m.objectifJour && m.panier > 0 ? ' · <b class="ok">+' + fN((m.ca - m.objectifJour) / m.panier) + ' clients</b> d’avance' : '')) : 'pas d’objectif du jour')}
      ${tuile('Marge brute', fK(m.margeBrute), fP(m.margeBrutePct != null ? m.margeBrutePct : (m.ca ? 100 * m.margeBrute / m.ca : null)) + ' des ventes · matière ' + fK(m.coutMatiere))}
      ${tuile('Clients', fN(m.tickets), 'référence ' + fN(m.refTickets) + (m.ticketsDelta != null ? ' · ' + (m.ticketsDelta >= 0 ? '+ ' : '− ') + fP(Math.abs(m.ticketsDelta)) : '') + (m.produits ? ' · ' + fN(m.produits) + ' produits vendus' : ''))}
      ${tuile('Panier moyen', fU(m.panier), (d.reseau && d.reseau.panier ? 'réseau ' + fU(d.reseau.panier) + ' · ' : '') + (m.produitsParClient ? nf(m.produitsParClient, 2) + ' produits / client' : ''))}
      ${tuile('Projection fin de journée', m.projection != null ? fK(m.projection) : '—', m.projection != null ? (m.projectionPart != null ? fP(m.projectionPart) + ' de la journée écoulée' : '') + (m.projectionRythme ? ' · au rythme : ' + fK(m.projectionRythme) : '') : esc(m.projectionMotif || ''))}
      ${tuile('Résultat net du jour', m.net == null ? '—' : fSK(m.net), m.net == null ? esc(m.motifNet || '') : fP(m.netPct) + ' des ventes', m.net == null ? '' : (m.net >= 0 ? 'bon' : 'vif'))}
    </div>`;
    if (m.objectifJour) {
      h += `<div class="db-card"><div style="padding:12px 16px"><div class="db-lab">Objectif du jour — ${fK(m.objectifJour)}${m.objectifJourNom ? ' · profil des ' + esc(m.objectifJourNom) + 's' : ''}</div>
        <div class="db-bar"><i style="width:${att.toFixed(1)}%"></i>${m.projectionPart != null ? `<b style="left:${Math.min(100, m.projectionPart).toFixed(1)}%"></b>` : ''}</div>
        <div class="db-mini" style="margin-top:5px">${fP(att)} réalisé${m.projectionPart != null ? ' · le repère noir est la part de journée normalement écoulée (' + fP(m.projectionPart) + ')' : ''}</div></div></div>`;
    }
    h += `<div class="db-card"><div class="ct"><span class="db-lab">Le P&amp;L court de la journée</span><span class="db-mini">matière : coût des recettes vendues · personnel : ${esc(m.planningSource || 'planning')} · frais généraux : ${esc(m.overheadSource || '—')}${m.overheadSource === 'reparti' ? ' — allocation du panel, le mois ÷ ses jours' : ''}</span></div>${cascade(m, d)}</div>`;
    // Catégories et planning côte à côte.
    const cats = Array.isArray(m.categories) ? m.categories : [];
    const plan = Array.isArray(m.planning) ? m.planning : [];
    h += `<div class="db-g2" style="margin-bottom:12px">`;
    h += `<div class="db-card"><div class="ct"><span class="db-lab">Ventes par catégorie</span><span class="db-mini">surface : poids dans le CA · couleur : écart à la référence</span></div>
      ${cats.length ? `<div class="db-tm">${treemap(cats)}</div><div class="db-leg">${[['#C0182B', '≤ −15 %'], ['#D97706', '−15 à −3 %'], ['#C9A227', 'stable'], ['#5f9e5f', '+3 à +15 %'], ['#2d7a3e', '≥ +15 %'], ['#B9B2A8', 'sans référence']].map(l => `<span><i style="background:${l[0]}"></i>${l[1]}</span>`).join('')}</div>` : `<div class="db-note" style="padding-top:12px">Pas de ventilation par catégorie pour ce jour.</div>`}</div>`;
    const hMin = plan.length ? Math.floor(Math.min(...plan.map(p => hDe(p.debut)))) : 6, hMax = plan.length ? Math.ceil(Math.max(...plan.map(p => hDe(p.fin)))) : 19;
    h += `<div class="db-card"><div class="ct"><span class="db-lab">Qui est en poste</span><span class="db-mini">${m.planningHeures != null ? nf(m.planningHeures, 1) + ' h · ' + fE(m.planningCout) : ''}${m.planningHeuresZero ? ' · ' + nf(m.planningHeuresZero, 1) + ' h à 0 €/h (' + esc((m.planningZeroNoms || []).join(', ')) + ')' : ''}</span></div>
      <div style="padding:8px 16px 12px">${plan.length ? plan.map(p => `<div class="db-plan"><span><b>${esc(p.nom)}</b><br><span class="mu">${esc(p.debut)} – ${esc(p.fin)} · ${nf(p.h, 1)} h${p.franchise ? ' · franchisé' : ''}</span></span><span class="g"><i class="${p.franchise ? 'fr' : ''}" style="left:${(100 * (hDe(p.debut) - hMin) / (hMax - hMin)).toFixed(1)}%;width:${(100 * (hDe(p.fin) - hDe(p.debut)) / (hMax - hMin)).toFixed(1)}%"></i></span><span style="text-align:right"><b>${fE(p.cout)}</b><br><span class="mu">${p.caH != null ? fE(p.caH) + '/h vendu' : ''}</span></span></div>`).join('') : '<div class="db-note">Pas de planning lu pour ce jour.</div>'}</div></div>`;
    h += `</div>`;
    const serie = Array.isArray(m.serie) ? m.serie.filter(x => x.ouvert) : [];
    if (serie.length) {
      const mx = Math.max(...serie.map(x => Math.abs(x.net || 0)), 1);
      const cumNet = serie.reduce((a, x) => a + (x.net || 0), 0), cumCa = serie.reduce((a, x) => a + (x.ca || 0), 0);
      h += `<div class="db-card"><div class="ct"><span class="db-lab">Le jour dans le mois</span><span class="db-mini">résultat net par jour — <b>${fE(cumNet)}</b> cumulés sur ${fE(cumCa)} de ventes · main-d’œuvre et frais généraux répartis</span></div>
        <div class="db-jours" style="grid-template-columns:repeat(${serie.length},1fr);height:150px">${serie.map(x => `<div title="${esc(x.date)} · CA ${fE(x.ca)} · net ${fE(x.net)} (${fP(x.netPct)})"><em class="${coul(x.net)}">${x.net == null ? '' : fE(x.net)}</em><div class="bb"><i style="height:${(100 * Math.abs(x.net || 0) / mx).toFixed(1)}%;background:${x.net == null ? 'var(--color-background-secondary)' : (x.net < 0 ? '#C0182B' : '#2d7a3e')};${x.date === S.date ? 'outline:2px solid #222;outline-offset:1px' : ''}"></i></div><span>${fD(x.date)}</span><span class="mu">${x.netPct == null ? '' : fP(x.netPct)}</span></div>`).join('')}</div></div>`;
    }
    return h;
  }

  /* Treemap « squarified » des catégories : surface = CA, couleur = écart à la référence. */
  function treemap(cats) {
    const vals = cats.filter(c => (c.ca || 0) > 0).sort((a, b) => b.ca - a.ca);
    if (!vals.length) { return ''; }
    const W = 1000, H = 440;
    const tot = vals.reduce((t, c) => t + c.ca, 0), ech = (W * H) / tot;
    const items = vals.map(c => ({ c, a: c.ca * ech }));
    const out = []; let x = 0, y = 0, w = W, h = H, row = [];
    const pire = (rw, l) => { const sm = rw.reduce((t, r) => t + r.a, 0); if (sm <= 0 || l <= 0) { return Infinity; } const mx = Math.max(...rw.map(r => r.a)), mn = Math.min(...rw.map(r => r.a)); return Math.max((l * l * mx) / (sm * sm), (sm * sm) / (l * l * mn)); };
    const poser = (rw, horiz) => { const sm = rw.reduce((t, r) => t + r.a, 0); if (sm <= 0) { return; }
      if (horiz) { const rh = sm / w; let cx = x; rw.forEach(r => { const rl = r.a / rh; out.push({ c: r.c, x: cx, y, w: rl, h: rh }); cx += rl; }); y += rh; h -= rh; }
      else { const rl = sm / h; let cy = y; rw.forEach(r => { const rh = r.a / rl; out.push({ c: r.c, x, y: cy, w: rl, h: rh }); cy += rh; }); x += rl; w -= rl; } };
    let garde = 0;
    while (items.length && garde++ < 400) { const horiz = w <= h, l = horiz ? w : h, it = items[0]; if (!row.length || pire(row, l) >= pire(row.concat([it]), l)) { row.push(items.shift()); } else { poser(row, horiz); row = []; } }
    if (row.length) { poser(row, w <= h); }
    const coulD = dl => dl == null ? '#B9B2A8' : (dl > 15 ? '#2d7a3e' : (dl > 3 ? '#5f9e5f' : (dl > -3 ? '#C9A227' : (dl > -15 ? '#D97706' : '#C0182B'))));
    return out.map(t => { const c = t.c; const gros = t.w > 150 && t.h > 90, moyen = t.w > 90 && t.h > 40;
      return `<div title="${esc(c.categorie)} · ${fE(c.ca)} · ${c.part != null ? fP(100 * c.part) + ' du CA' : ''}${c.delta != null ? ' · ' + (c.delta >= 0 ? '+' : '') + fP(c.delta) + ' vs réf. ' + fE(c.ref) : ' · sans référence'}" style="position:absolute;left:${(t.x / W * 100).toFixed(3)}%;top:${(t.y / H * 100).toFixed(3)}%;width:${Math.max(t.w / W * 100 - 0.35, 0).toFixed(3)}%;height:${Math.max(t.h / H * 100 - 0.8, 0).toFixed(3)}%;background:${coulD(c.delta)};color:#fff;border-radius:5px;padding:${gros ? '8px 10px' : '4px 6px'};overflow:hidden;font-size:${gros ? 12 : 10.5}px;line-height:1.3">${moyen ? `<b>${esc(c.categorie)}</b>${gros ? `<br><span style="font-family:var(--font-display);font-size:16px">${fE(c.ca)}</span><br><span style="opacity:.9;font-size:10.5px">${c.part != null ? fP(100 * c.part) + ' du CA' : ''}${c.delta != null ? ' · ' + (c.delta >= 0 ? '+' : '') + fP(c.delta) + ' vs réf.' : ''}</span>` : `<br><span style="font-size:10px;opacity:.9">${c.part != null ? Math.round(100 * c.part) + ' %' : ''}${c.delta != null ? ' · ' + (c.delta >= 0 ? '+' : '') + Math.round(c.delta) + ' %' : ''}</span>`}` : ''}</div>`; }).join('');
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
    h += rendTenir(m, d);
    h += `<div class="db-card"><div class="ct"><span class="db-lab">Le P&amp;L ${S.vue === 'semaine' ? 'de la semaine' : 'du mois'}</span><span class="db-mini">matière : coût des recettes vendues · personnel : planning × taux · frais généraux : panel</span></div>${cascade(m, d)}</div>`;
    if (S.vue === 'mois') { h += rendRentab(); }
    return h;
  }

  /* Ce qu'il manque, et ce qu'il faut pour tenir l'objectif — comme le drop de Résultat. */
  function rendTenir(m, d) {
    if (m.objectif == null) { return ''; }
    const jours = Array.isArray(m.jours) ? m.jours : [];
    const restants = jours.filter(j => !j.passe && !j.ferme && !j.aujourdhui);
    const eff = m.reste - m.prevu;   // ce qu'il faut faire EN PLUS de ce que la pondération prévoyait
    const effCl = m.panier > 0 ? Math.round(eff / m.panier) : null;
    const parJour = restants.length ? m.reste / restants.length : null;
    const cl = m.clientsManquants;
    return `<div class="db-g2" style="grid-template-columns:1fr 1fr;margin-bottom:12px">
      <div class="db-card"><div class="ct"><span class="db-lab">Ce qu’il manque</span><span class="db-mini">écart à l’attendu ${d.enCours ? 'à ce jour' : 'de la période'}</span></div>
        <div style="padding:12px 16px"><div style="font-family:var(--font-display);font-size:20px;color:${cl > 0 ? '#C0182B' : '#2d7a3e'}">${cl > 0 ? 'Il manque ' + fN(cl) + ' clients' : (cl < 0 ? fN(-cl) + ' clients d’avance' : 'Dans la cible')}</div>
        <div class="db-mini" style="margin-top:4px">${fS(m.ecart)} d’écart · ${fE(Math.abs(m.ecart))} ÷ panier ${fU(m.panier)} = ${fN(Math.abs(cl))} clients</div></div></div>
      <div class="db-card"><div class="ct"><span class="db-lab">Tenir l’objectif</span><span class="db-mini">${restants.length ? restants.length + ' jour(s) restant(s)' : 'période close'}</span></div>
        <div class="db-casc" style="grid-template-columns:repeat(3,1fr);padding:10px 16px">
          <div><div class="k">Reste à faire</div><div class="v">${fK(m.reste)}</div><div class="s">${parJour != null ? fE(parJour) + ' par jour ouvert restant' : ''}</div></div>
          <div><div class="k">Prévu par la pondération</div><div class="v">${fK(m.prevu)}</div><div class="s">${m.objectif ? Math.round(100 * m.prevu / m.objectif) + ' % de l’objectif' : ''}</div></div>
          <div><div class="k">Effort en plus</div><div class="v ${eff > 0 ? 'ko' : 'ok'}">${fSK(eff)}</div><div class="s">${effCl != null ? (effCl > 0 ? '+' : '') + fN(effCl) + ' clients sur la période' : ''}</div></div>
        </div></div></div>`;
  }

  /* Le résultat net jour par jour du mois en cours — les cases de l'analyse rentabilité. */
  function rendRentab() {
    if (S.date.slice(0, 7) !== AUJ.slice(0, 7)) { return `<div class="db-note" style="padding:0 0 12px">La heatmap de rentabilité ne se lit que sur le mois en cours (P&amp;L quotidien du panel).</div>`; }
    const r = S.aux['rentab'];
    const teinte = p => p == null ? 'background:var(--color-background-secondary);color:var(--color-text-muted)'
      : p < 0 ? 'background:#8D1D2C;color:#fff' : p < 5 ? 'background:#C17A2A;color:#fff'
      : p < 10 ? 'background:#A8B545;color:#fff' : p < 15 ? 'background:#7CB342;color:#fff'
      : p < 25 ? 'background:#3D8B44;color:#fff' : 'background:#C9A227;color:#fff';
    let h = `<div class="db-card"><div class="ct"><span class="db-lab">Rentabilité — le résultat net, jour par jour</span><span class="db-mini">${r && r.du ? 'mois en cours · ' + fD(r.du) + ' → ' + fD(r.au) : ''}</span></div>`;
    if (S.err['rentab']) { h += `<div class="db-note" style="padding-top:10px">Lecture impossible : ${esc(S.err['rentab'])}</div></div>`; return h; }
    if (!r) { h += `<div style="padding:14px 16px"><div class="db-sk"></div></div></div>`; return h; }
    const mg = (r.magasins || []).find(x => String(x.id) === String(S.shop));
    if (!mg || mg.indispo) { h += `<div class="db-note" style="padding-top:10px">${esc((mg && mg.motif) || r.motif || 'pas de résultat net jour par jour pour ce magasin')}</div></div>`; return h; }
    h += `<div style="display:flex;gap:4px;flex-wrap:wrap;padding:12px 16px 6px">${(mg.jours || []).map(j => `<div title="${esc(j.date)}${j.ouvert && j.net != null ? ' — net ' + fE(j.net) + ' · ' + fP(j.netPct) : ''}" style="${teinte(j.ouvert ? j.netPct : null)};border-radius:6px;min-width:44px;padding:5px 4px;text-align:center;font-size:10.5px;line-height:1.25"><b>${+j.date.slice(8, 10)}</b><br>${j.ouvert ? (j.netPct == null ? '' : Math.round(j.netPct) + ' %') : '·'}</div>`).join('')}</div>
      <div class="db-note">${mg.total && mg.total.netPct != null ? '<b>' + fP(mg.total.netPct) + ' · ' + fE(mg.total.net) + '</b> sur le mois · ' : ''}palette : rouge &lt; 0 % · orange 0–5 · verts 5–25 · doré &gt; 25 % · ${esc(mg.sourceJour || '')}${mg.labourMois != null ? ' · personnel du mois ' + fE(mg.labourMois) + ', frais généraux ' + fE(mg.overheadMois) + ', répartis par jour ouvert' : ''}</div></div>`;
    return h;
  }

  /* La place du magasin dans le réseau — le badge de rang sur la jauge : du
   * dernier (à gauche) au premier (à droite), toi en rouge, les autres en
   * points gris, la médiane un trait. Un trophée au premier. Jamais un nom. */
  function rendBench(m, d) {
    const L = (d.magasins || []).filter(x => x.ouvert !== false);
    const jour = S.vue === 'jour';
    const defs = jour
      ? [['Chiffre d’affaires', 'ca', fK, v => fSK(v)], ['Clients', 'tickets', fN, v => (v >= 0 ? '+' : '−') + fN(Math.abs(v))], ['Panier moyen', 'panier', fU, v => (v >= 0 ? '+ ' : '− ') + fU(Math.abs(v))], ['Marge brute', 'margeBrutePct', v => fP(v), v => (v >= 0 ? '+' : '−') + nf(Math.abs(v), 1) + ' pts'], ['Résultat net', 'netPct', v => fP(v), v => (v >= 0 ? '+' : '−') + nf(Math.abs(v), 1) + ' pts']]
      : [['Chiffre d’affaires', 'realise', fK, v => fSK(v)], ['Clients', 'tickets', fN, v => (v >= 0 ? '+' : '−') + fN(Math.abs(v))], ['Panier moyen', 'panier', fU, v => (v >= 0 ? '+ ' : '− ') + fU(Math.abs(v))], ['Atteinte de l’attendu', 'atteinte', v => fP(100 * v), v => (v >= 0 ? '+' : '−') + nf(Math.abs(100 * v), 1) + ' pts'], ['Résultat net', 'netPct', v => fP(v), v => (v >= 0 ? '+' : '−') + nf(Math.abs(v), 1) + ' pts']];
    const ord = n => n === 1 ? '1er' : n + 'e';
    let premiers = 0;
    const tuiles = defs.map(([lib, k, f, fd]) => {
      const vals = L.map(x => x[k]).filter(v => v != null && isFinite(v)).sort((a, b) => b - a);
      const v = m[k];
      if (v == null || !vals.length) { return `<div class="db-bt"><div class="k">${lib}</div><span class="rg mu">—</span><div class="v mu">—</div><div class="s">pas de valeur</div></div>`; }
      const n = vals.length, rang = vals.findIndex(x => x <= v) + 1;
      const med = vals[Math.floor((n - 1) / 2)], meilleur = vals[0], second = vals[1];
      const top = rang === 1, bas = rang === n && n > 1;
      if (top) { premiers++; }
      // La jauge : du minimum au maximum du réseau, en pourcentage de la largeur.
      const mn = vals[n - 1], mx = vals[0], ecart = mx - mn;
      const pos = x => ecart > 0 ? Math.max(0, Math.min(100, 100 * (x - mn) / ecart)) : 50;
      const autres = vals.filter((x, i) => i !== rang - 1).map(x => `<span class="pt" style="left:${pos(x).toFixed(1)}%"></span>`).join('');
      return `<div class="db-bt"><div class="k">${lib}</div><span class="rg${top ? ' top' : (bas ? ' bas' : '')}">${top ? '🏆 ' : ''}${ord(rang)} <small>/ ${n}</small></span>
        <div class="jg"><i class="l"></i>${autres}<span class="md" style="left:${pos(med).toFixed(1)}%"></span><span class="mo${top ? ' top' : ''}" style="left:${pos(v).toFixed(1)}%"></span></div>
        <div class="v">${f(v)}<small>médiane ${f(med)}</small></div>
        <div class="s">${fd(v - med)} vs médiane · ${top ? (second != null ? 'le 2e : ' + f(second) : 'seul en lice') : 'le 1er : ' + f(meilleur)}</div></div>`;
    }).join('');
    return `<div class="db-bench"><div class="db-bt tit"><div class="k">Ta place dans le réseau</div><div class="s">${L.length} magasins ouverts · ${jour ? 'la journée' : (S.vue === 'semaine' ? 'la semaine' : 'le mois')} · anonyme${premiers ? ' · <b>' + premiers + ' × 🏆</b>' : ''}</div><div class="leg"><span><i style="background:var(--color-primary)"></i>toi</span><span><i style="background:#c9c2b8"></i>un autre</span><span><b></b>médiane</span></div></div>${tuiles}</div>`;
  }

  /* Les tâches du jour (ou de la période) : faites, non faites, bloquantes,
   * le fil en miniature, et le détail qui se déplie. Bloquante = tâche
   * d'exploitation (ouverture, fermeture, contrôle opérationnel : checklists
   * CO-…) non rendue ; un contrôle qualité non rendu est « non fait ». */
  function rendTaches() {
    const jour = S.vue === 'jour';
    const cle = jour ? 'taches|' + S.date : (function () { const [du, au] = bornes(); return 'tachesP|' + du + '|' + au; })();
    const d = S.aux[cle];
    const tuileT = (k, v, s, cls) => `<div class="db-bt ${cls || ''}"><div class="k">${k}</div><div class="v">${v}</div><div class="s">${s || ''}</div></div>`;
    let corps = '', drop = '', titre = '', sous = '';
    if (S.err[cle]) { return `<div class="db-taches"><div class="db-bt tit"><div class="k">Les tâches ${jour ? 'du jour' : 'de la période'}</div><div class="s">${esc(S.err[cle])}</div></div></div>`; }
    if (!d) { return `<div class="db-taches"><div class="db-bt tit"><div class="k">Les tâches ${jour ? 'du jour' : 'de la période'}</div><div class="s">lecture du panel…</div></div>${tuileT('Faites', '<span class="db-sk" style="display:block;width:40px;height:22px"></span>')}${tuileT('Non faites', '<span class="db-sk" style="display:block;width:40px;height:22px"></span>')}${tuileT('Bloquantes', '<span class="db-sk" style="display:block;width:40px;height:22px"></span>')}<div class="db-bt fil"><div class="k">Le fil</div><div class="db-sk"></div></div></div>`; }
    if (jour) {
      const sh = (d.shops || []).find(x => String(x.shopId) === String(S.shop));
      const T = sh ? (sh.taches || []) : [];
      if (!T.length) { return `<div class="db-taches"><div class="db-bt tit"><div class="k">Les tâches du jour</div><div class="s">${d.indispo ? 'panel injoignable' : 'aucune tâche pour ce magasin ce jour'}</div></div></div>`; }
      const faite = t => t.statut !== 'nonRendue';
      const bloq = t => !faite(t) && /^CO-/i.test(String(t.checklist || ''));
      const nF = T.filter(faite).length, nN = T.length - nF, nB = T.filter(bloq).length;
      const nCtrl = T.filter(t => t.statut === 'aControler' || t.statut === 'aValider').length, nSans = T.filter(t => t.statut === 'sansPhoto').length;
      const nQ = T.filter(t => !faite(t) && !bloq(t)).length;
      // Les checklists, dans l'ordre de la journée.
      const cls = []; const par = {};
      T.forEach(t => { const c = String(t.checklist || 'Sans checklist'); if (!par[c]) { par[c] = []; cls.push(c); } par[c].push(t); });
      // L'ordre de la journée : l'ouverture d'abord, la fermeture en dernier, entre les deux par heure de première tâche rendue.
      const rang = c => /^CO-01/i.test(c) ? '0' : (/^CO-02/i.test(c) ? '9' : '5' + (par[c].filter(t => t.faitLe).map(t => String(t.faitLe)).sort()[0] || '9999') + c);
      cls.sort((a, b) => rang(a).localeCompare(rang(b)));
      const hDe = t => t.faitLe ? String(t.faitLe).slice(11, 16) : '';
      const mini = cls.map((c, i) => (i ? '<span class="sep"></span>' : '') + par[c].map(t => `<i class="${faite(t) ? 'f' : (bloq(t) ? 'b' : 'n')}" title="${esc(t.tache)}${faite(t) ? ' · ' + hDe(t) + (t.faitePar ? ' · ' + esc(t.faitePar) : '') : ' · non rendue'}"></i>`).join('')).join('');
      const dern = T.filter(t => t.faitLe).sort((a, b) => String(b.faitLe).localeCompare(String(a.faitLe)))[0];
      const court = c => c.replace(/^[A-Z]{2}-?[A-Z0-9]+\s*[—–-]\s*/i, '').replace(/\.$/, '');
      corps = `<div class="db-bt tit"><div class="k">Les tâches du jour</div><div class="s">${T.length} obligatoire(s) · ${T.length ? Math.round(100 * nF / T.length) : 0} % faites${dern ? ' · dernière rendue à ' + hDe(dern) + (dern.faitePar ? ' par ' + esc(dern.faitePar) : '') : ''}</div></div>
        ${tuileT('Faites', nF + '<small>/ ' + T.length + '</small>', (nCtrl ? nCtrl + ' à contrôler' : '') + (nSans ? (nCtrl ? ' · ' : '') + nSans + ' sans photo' : ''), 'ok')}
        ${tuileT('Non faites', nN + '<small>/ ' + T.length + '</small>', (nQ ? nQ + ' contrôle(s) qualité' : '') + (nB ? (nQ ? ' · ' : '') + nB + ' d’exploitation' : ''), 'wa')}
        ${tuileT('Bloquantes', String(nB), nB ? 'exploitation non rendue' : 'rien ne bloque', nB ? 'ko' : '')}
        <div class="db-bt fil" data-tdrop="1"><div class="k">Le fil de la journée <span class="dr">${S.tOuvert ? 'replier ▴' : 'détail ▾'}</span></div><div class="mini">${mini}</div><div class="s">${cls.map(court).map(esc).join(' · ')}</div></div>`;
      if (S.tOuvert) {
        // Le détail : une ligne par checklist — le compte, les heures, puis
        // chaque tâche en pastille (état, nom court, heure et personne au survol).
        const nomCourt = t => { let n = String(t.tache || ''); n = n.replace(/^Photo du comptoir\s*-\s*/i, 'Comptoir · ').replace(/^Contrôle Qualité\s*[–-]\s*/i, 'CQ · '); return n.length > 34 ? n.slice(0, 33) + '…' : n; };
        const etat = t => faite(t) ? 'f' : (bloq(t) ? 'b' : 'n');
        const ico = t => faite(t) ? '✓' : (bloq(t) ? '!' : '·');
        drop = `<div class="db-tdrop">${cls.map(c => { const L = par[c]; const f = L.filter(faite).length, b = L.filter(bloq).length; const hs = L.filter(t => t.faitLe).map(hDe).sort(); const qui = [...new Set(L.filter(t => t.faitePar).map(t => t.faitePar))];
          const meta = hs.length ? hs[0] + (hs.length > 1 ? ' → ' + hs[hs.length - 1] : '') + (qui.length ? ' · ' + esc(qui.join(', ')) : '') : (b ? b + ' bloquante(s)' : 'rien de rendu');
          return `<div class="r"><span class="n">${esc(c)}<small>${meta}</small></span><span class="pills">${L.map(t => `<span class="pl ${etat(t)}" title="${esc(t.tache)}${faite(t) ? ' · ' + hDe(t) + (t.faitePar ? ' · ' + esc(t.faitePar) : '') + (t.statut === 'aControler' || t.statut === 'aValider' ? ' · à contrôler' : '') : (bloq(t) ? ' · non rendue · bloquante' : ' · non rendue')}"><i>${ico(t)}</i>${esc(nomCourt(t))}${faite(t) ? `<em>${hDe(t)}</em>` : ''}</span>`).join('')}</span><span class="c ${f === L.length ? 'ok' : (b ? 'ko' : 'wa')}">${f} / ${L.length}</span></div>`; }).join('')}
          <div class="db-note" style="padding:6px 0 0">✓ rendue · ! bloquante (exploitation non rendue) · · non rendue. Survolez une pastille pour l’heure, la personne et l’état du contrôle.</div></div>`;
      }
    } else {
      const li = (d.lignes || []).find(x => String(x.shopId) === String(S.shop));
      const J = li ? (li.jours || []).filter(j => j.releve && j.j <= AUJ) : [];
      if (!J.length) { return `<div class="db-taches"><div class="db-bt tit"><div class="k">Les tâches de la période</div><div class="s">pas de relevé pour ce magasin sur la période</div></div></div>`; }
      const nF = J.reduce((a, j) => a + (j.faites || 0), 0), nN = J.reduce((a, j) => a + (j.pasFaites || 0), 0), tot = nF + nN;
      const teinte = p => p == null ? '' : (p >= 80 ? 'f' : (p >= 40 ? 'm' : (p > 0 ? 'n' : 'b')));
      const mauvais = J.filter(j => (j.part || 0) < 40).length;
      corps = `<div class="db-bt tit"><div class="k">Les tâches ${S.vue === 'semaine' ? 'de la semaine' : 'du mois'}</div><div class="s">${J.length} jour(s) relevé(s) · ${tot ? Math.round(100 * nF / tot) : 0} % faites · bloquantes : voir le jour</div></div>
        ${tuileT('Faites', nF + '<small>/ ' + tot + '</small>', Math.round(nF / Math.max(1, J.length)) + ' par jour en moyenne', 'ok')}
        ${tuileT('Non faites', nN + '<small>/ ' + tot + '</small>', Math.round(nN / Math.max(1, J.length)) + ' par jour en moyenne', 'wa')}
        ${tuileT('Jours sous 40 %', String(mauvais), mauvais ? 'jour(s) où moins de 40 % des tâches sont faites' : 'aucun jour en dessous', mauvais ? 'ko' : '')}
        <div class="db-bt fil" data-tdrop="1"><div class="k">Le fil des jours <span class="dr">${S.tOuvert ? 'replier ▴' : 'détail ▾'}</span></div><div class="mini">${J.map(j => `<i class="${teinte(j.part)}" title="${esc(j.j)} · ${j.faites} faites / ${j.faites + j.pasFaites} · ${j.part} %"></i>`).join('')}</div><div class="s">une case par jour · vert ≥ 80 % · vert clair ≥ 40 % · rose &lt; 40 % · rouge : rien de fait</div></div>`;
      if (S.tOuvert) {
        drop = `<div class="db-tdrop">${J.map(j => `<div class="r"><span class="n">${fDL(j.j)}</span><span class="db-bar" style="margin:0"><i style="width:${Math.min(100, j.part || 0)}%;background:${(j.part || 0) >= 80 ? '#2d7a3e' : ((j.part || 0) >= 40 ? '#A8B545' : '#C0182B')}"></i></span><span class="c ${(j.part || 0) >= 80 ? 'ok' : ((j.part || 0) >= 40 ? 'wa' : 'ko')}">${j.faites} / ${j.faites + j.pasFaites}</span></div>`).join('')}</div>`;
      }
    }
    return `<div class="db-taches">${corps}</div>${drop}`;
  }

  /* L'année : la heatmap des 12 mois (deux années) et l'objectif — 1 an, 3 ans, 5 ans. */
  function rendAnnee() {
    const Y = annee();
    const perf = S.aux['perf|' + Y], plan = S.aux['plan|' + S.shop + '|' + Y];
    let h = '';
    if (S.err['perf|' + Y]) { h += `<div class="db-err">Mois : ${esc(S.err['perf|' + Y])}</div>`; }
    if (S.err['plan|' + S.shop + '|' + Y]) { h += `<div class="db-err">Objectif : ${esc(S.err['plan|' + S.shop + '|' + Y])}</div>`; }
    // --- l'objectif de l'année, et la rampe
    h += `<div class="db-sec">L’objectif — ${Y}, 3 ans, 5 ans<small>engagement du franchisé, sinon la rampe de l’étude de marché</small></div>`;
    if (!plan && !S.err['plan|' + S.shop + '|' + Y]) { h += squelette(2); }
    else if (plan && plan.annee) {
      const A = plan.annee, src = A.objectifSource === 'rampe' ? 'rampe de l’étude, à engager' : 'engagement du franchisé';
      const att = A.objectif ? Math.min(100, 100 * A.realise / A.objectif) : 0, wAtt = A.objectif && A.attendu != null ? Math.min(100, 100 * A.attendu / A.objectif) : null;
      h += `<div class="db-tuiles">
        ${tuile('Objectif ' + Y, fK(A.objectif), src)}
        ${tuile('Réalisé', fK(A.realise), A.partEcoulee != null ? fP(A.partEcoulee) + ' de l’année écoulée' : '')}
        ${tuile('Attendu à ce jour', fK(A.attendu), 'au rythme des budgets mensuels')}
        ${tuile('Écart', fSK(A.ecart), A.ecart == null ? '' : (A.ecart >= 0 ? 'en avance' : 'en retard') + ' sur l’attendu', A.ecart == null ? '' : (A.ecart >= 0 ? 'bon' : 'vif'))}
        ${tuile('Projection fin d’année', fK(A.projection), A.projection != null && A.objectif ? fSK(A.projection - A.objectif) + ' sur l’objectif' : 'au rythme actuel', A.projection != null && A.objectif ? (A.projection >= A.objectif ? 'bon' : 'vif') : '')}
        ${tuile('Rampe à 5 ans', plan.rampe && plan.rampe.potentiel ? fK(plan.rampe.potentiel) : '—', plan.rampe && plan.rampe.potentiel ? 'potentiel à maturité · ' + (function () { const n = (plan.rampe.annees || []).find(a => a.an === Y + 1); return n ? 'l’an prochain ' + fK(n.ca) : ''; })() : 'sans étude de marché')}
      </div>
      ${A.objectif ? `<div class="db-card"><div style="padding:12px 16px"><div class="db-lab">Objectif ${Y} — ${fK(A.objectif)}</div><div class="db-bar"><i style="width:${att.toFixed(1)}%"></i>${wAtt != null ? `<b style="left:${wAtt.toFixed(1)}%"></b>` : ''}</div><div class="db-mini" style="margin-top:5px">${fP(att)} réalisé${wAtt != null ? ' · le repère noir est l’attendu à ce jour (' + fP(wAtt) + ')' : ''}</div></div></div>` : ''}`;
      const R = (plan.rampe && plan.rampe.annees) || [];
      if (R.length) {
        const mx = Math.max(...R.map(a => a.ca || 0), 1);
        const engs = plan.engagements || {};
        h += `<div class="db-card"><div class="ct"><span class="db-lab">La rampe à 5 ans</span><span class="db-mini">${plan.rampe.potentiel ? 'potentiel à maturité ' + fK(plan.rampe.potentiel) + ' · année ' + plan.rampe.anneeExploitation + ' d’exploitation en ' + Y : 'sans étude de marché'}</span></div>
          <div class="db-jours" style="grid-template-columns:repeat(${R.length},1fr);height:190px">${R.map(a => { const en = engs[String(a.an)]; const cur = a.an === Y;
            return `<div><em>${fK(a.ca)}${en && en.objectif ? '<br><span class="mu" style="font-weight:500">engagé ' + fK(en.objectif) + '</span>' : ''}</em><div class="bb"><i class="${cur ? '' : 'ferme'}" style="height:${(100 * (a.ca || 0) / mx).toFixed(1)}%;${cur ? '' : 'background:#e5d9cc'}"></i>${cur && A.realise ? `<b style="bottom:${(100 * A.realise / mx).toFixed(1)}%;background:#D97706"></b>` : ''}</div><span>${a.an} · ${a.maturite ? 'maturité' : 'année ' + a.anneeExploitation + ' · ' + a.coef + ' %'}</span></div>`; }).join('')}</div>
          <div class="db-note">Barres : la rampe de l’étude (potentiel × montée en régime) ; trait orange sur ${Y} : le réalisé à ce jour. L’engagement se dépose dans Cockpit › Plan de développement.</div></div>`;
      }
    } else if (plan) { h += `<div class="db-alerte">${esc(plan.error || 'Pas de plan pour ce magasin.')}</div>`; }
    // --- la heatmap des mois
    h += `<div class="db-sec">Les mois — ${Y - 1} et ${Y}<small>CA du mois et atteinte du budget, magasin seul</small></div>`;
    if (!perf && !S.err['perf|' + Y]) { h += squelette(2); }
    else if (perf) {
      const cells = (Array.isArray(perf) ? perf : []).filter(c => String(c.storeId) === String(S.shop));
      const MO = ['jan', 'fév', 'mar', 'avr', 'mai', 'juin', 'juil', 'août', 'sep', 'oct', 'nov', 'déc'];
      const tPct = p => p == null ? 'background:var(--color-background-secondary);color:var(--color-text-muted)'
        : p < 80 ? 'background:#8D1D2C;color:#fff' : p < 95 ? 'background:#C17A2A;color:#fff' : p < 105 ? 'background:#7CB342;color:#fff' : 'background:#C9A227;color:#fff';
      const mxCa = Math.max(...cells.map(c => c.ca || 0), 1);
      const tCa = ca => ca == null || !ca ? 'background:var(--color-background-secondary);color:var(--color-text-muted)' : `background:rgba(141,29,44,${(0.15 + 0.75 * ca / mxCa).toFixed(2)});color:${ca / mxCa > 0.45 ? '#fff' : '#222'}`;
      const auj = new Date();
      h += `<div class="db-card"><div class="ct"><span class="db-lab">Heatmap mensuelle</span>
        <div class="db-ong" style="margin-left:8px"><button data-hm="pct" class="${S.hmMetric === 'pct' ? 'on' : ''}">% d’atteinte du budget</button><button data-hm="ca" class="${S.hmMetric === 'ca' ? 'on' : ''}">CA du mois</button></div>
        <span class="db-mini">budget validé, sinon CA théorique de l’étude · le mois en cours est en cours</span></div>
        <div style="padding:12px 16px;overflow-x:auto"><div style="display:grid;grid-template-columns:70px repeat(12,minmax(64px,1fr));gap:4px;min-width:900px">
          <div></div>${MO.map(x => `<div class="db-lab" style="text-align:center">${x}</div>`).join('')}
          ${[Y - 1, Y].map(an => `<div style="font-weight:600;font-size:12px;display:flex;align-items:center">${an}</div>` + MO.map((x, i) => {
            const c = cells.find(z => +z.annee === an && +z.mois === i + 1); const bud = c ? (c.caBudget || c.caTheorique) : null;
            const pct = c && bud ? 100 * c.ca / bud : null; const futur = an > auj.getFullYear() || (an === auj.getFullYear() && i > auj.getMonth());
            const enCours = an === auj.getFullYear() && i === auj.getMonth();
            if (!c || futur || !c.ca) { return `<div style="${tPct(null)};border-radius:6px;min-height:46px;display:flex;align-items:center;justify-content:center;font-size:10.5px">${futur ? '' : '—'}</div>`; }
            return `<div title="${an}-${String(i + 1).padStart(2, '0')} · CA ${fE(c.ca)}${bud ? ' · budget ' + fE(bud) + ' · ' + fP(pct) : ''} · ${fN(c.tickets)} tickets · panier ${fU(c.panierMoyen)}" style="${S.hmMetric === 'pct' ? tPct(pct) : tCa(c.ca)};border-radius:6px;min-height:46px;display:flex;flex-direction:column;align-items:center;justify-content:center;font-size:11px;line-height:1.2;${enCours ? 'outline:2px dashed #222;outline-offset:-2px' : ''}"><b>${S.hmMetric === 'pct' ? (pct == null ? '—' : Math.round(pct) + ' %') : fK(c.ca)}</b><span style="font-size:9.5px;opacity:.85">${S.hmMetric === 'pct' ? fK(c.ca) : (pct == null ? '' : Math.round(pct) + ' %')}</span></div>`; }).join('')).join('')}
        </div></div>
        <div class="db-note">Atteinte : rouge &lt; 80 % · orange 80–95 · vert 95–105 · doré &gt; 105 %. Survolez une case : CA, budget, tickets, panier.</div></div>`;
      // Le tableau des mois de l'année : tickets, panier, marge, ratios.
      const an = cells.filter(c => +c.annee === Y && c.ca).sort((a, b) => a.mois - b.mois);
      if (an.length) {
        h += `<div class="db-card"><div class="ct"><span class="db-lab">${Y}, mois par mois</span><span class="db-mini">marge, labour et overhead connus depuis juillet 2026, food cost depuis janvier</span></div>
          <table class="db-t"><tr><th>Mois</th><th>CA</th><th>Budget</th><th>Atteinte</th><th>Clients</th><th>Panier</th><th>Food cost</th><th>Labour</th><th>Overhead</th><th>Marge nette</th></tr>
          ${an.map(c => { const bud = c.caBudget || c.caTheorique; const pct = bud ? 100 * c.ca / bud : null;
            return `<tr><td>${MO[c.mois - 1]} ${Y}</td><td>${fK(c.ca)}</td><td class="mu">${fK(bud)}</td><td class="${pct == null ? 'mu' : (pct >= 100 ? 'ok' : (pct >= 90 ? 'wa' : 'ko'))}">${fP(pct)}</td><td>${fN(c.tickets)}</td><td>${fU(c.panierMoyen)}</td><td>${fP(c.foodCostPct)}</td><td>${fP(c.labourCostPct)}</td><td>${fP(c.overheadPct)}</td><td class="${c.margePct == null ? 'mu' : (c.margePct >= 0.15 ? 'ok' : (c.margePct >= 0.05 ? 'wa' : 'ko'))}">${c.margePct == null ? '—' : fP(100 * c.margePct)}${c.margeNette != null ? ' <span class="mu">· ' + fK(c.margeNette) + '</span>' : ''}</td></tr>`; }).join('')}</table></div>`;
      }
    }
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
      ${tuile('Ventes', fK(tot.ca), 'matière ' + fP(tot.ca ? 100 * tot.mat / tot.ca : null) + ' · ' + fK(tot.mat))}
      ${tuile('Marge brute', fK(tot.mb), fP(tot.mbPct) + ' des ventes')}
      ${tuile('Marge nette des heures', fSK(tot.res), fP(tot.resPct) + ' des ventes · rémunération ' + fK(tot.trav), tot.res >= 0 ? 'bon' : 'vif')}
      ${st.meilleure ? tuile('Heure la plus rentable', st.meilleure.h + ' – ' + (st.meilleure.h + 1) + ' h', fSK(moy ? st.meilleure.moy : st.meilleure.res) + (moy ? ' par jour ouvert' : ''), 'bon') : ''}
      ${st.pire ? tuile('Heure la moins rentable', st.pire.h + ' – ' + (st.pire.h + 1) + ' h', fSK(moy ? st.pire.moy : st.pire.res) + (moy ? ' par jour ouvert' : ''), (moy ? st.pire.moy : st.pire.res) < 0 ? 'vif' : '') : ''}
    </div>`;
    // Le graphique : une grille commune, une colonne par heure — la barre
    // empilée (matière, rémunération, marge nette ; une perte hachurée) et,
    // exactement dessous, la case de la marge nette en euros.
    const max = Math.max(...L.map(l => val(l, 'ca')), 1) * 1.04;
    const teinteMn = pct => pct == null ? 'var(--color-background-secondary)' : (pct < 0 ? '#C0182B' : (pct < 20 ? '#D97706' : (pct < 40 ? '#A8B545' : '#2d7a3e')));
    const HB = 210;
    let barres = '', heures = '', cases = '';
    L.forEach(l => {
      const ca = val(l, 'ca'), mat = val(l, 'mat'), trav = val(l, 'trav'), mn = val(l, 'res');
      const pm = ca > 0 ? 100 * mat / ca : 0, pt = ca > 0 ? 100 * Math.max(0, Math.min(trav, ca - mat)) / ca : 0, pr = ca > 0 ? 100 * Math.max(mn, 0) / ca : 0;
      const pct = ca > 0 ? 100 * mn / ca : null;
      const on = l.h === sel.h;
      barres += `<div class="bh${on ? ' sel' : ''}" data-h="${l.h}"><em>${fE(ca)}</em><div class="st" style="height:${Math.max(2, (HB - 20) * ca / max).toFixed(0)}px"><i style="height:${pm.toFixed(1)}%;background:#e5c9a0"></i><i style="height:${pt.toFixed(1)}%;background:#D97706"></i><i style="height:${pr.toFixed(1)}%;background:#2d7a3e"></i>${mn < 0 && ca > 0 ? `<i style="height:${Math.min(100, 100 * Math.abs(mn) / ca).toFixed(1)}%;background:repeating-linear-gradient(45deg,#C0182B,#C0182B 3px,#f2c9cf 3px,#f2c9cf 6px)"></i>` : ''}</div></div>`;
      heures += `<div class="hh${on ? ' sel' : ''}" data-h="${l.h}">${l.h} h</div>`;
      cases += `<div class="cell${on ? ' sel' : ''}" data-h="${l.h}" style="background:${teinteMn(pct)}" title="${l.h} – ${l.h + 1} h · marge nette ${fS(mn)}${pct != null ? ' · ' + fP(pct) + ' des ventes' : ''}">${fS(mn)}</div>`;
    });
    h += `<div class="db-card"><div class="ct"><span class="db-lab">${S.vue === 'jour' ? 'Ce que chaque heure rapporte' : (moy ? 'La journée type — moyenne par jour ouvert' : 'La période — total des heures')}</span>
      ${S.vue !== 'jour' ? `<div class="db-ong" style="margin-left:8px"><button data-mode="moy" class="${moy ? 'on' : ''}">Moyenne / jour ouvert</button><button data-mode="tot" class="${moy ? '' : 'on'}">Total</button></div>` : ''}
      <span class="db-mini">le chiffre au-dessus : les ventes de l’heure · la case : la marge nette · cliquez une heure</span></div>
      <div class="db-gr" style="grid-template-columns:104px repeat(${L.length},minmax(0,1fr))">
        <div class="lab">Ventes de l’heure</div>${barres}
        <div class="axe"></div><div></div>${heures}
        <div class="lab">Marge nette<br><span>marge brute − rémunération</span></div>${cases}
      </div>
      <div class="db-axe" style="padding-top:8px"><span><i class="c" style="background:#e5c9a0"></i>coût matière &nbsp; <i class="c" style="background:#D97706"></i>rémunération &nbsp; <i class="c" style="background:#2d7a3e"></i>marge nette de l’heure &nbsp; <i class="c" style="background:#C0182B"></i>perte</span><span>hauteur de la barre = ventes · case : rouge &lt; 0 · orange &lt; 20 % des ventes · vert clair &lt; 40 % · vert ≥ 40 %</span></div></div>`;
    // Tableau + panneau.
    h += `<div class="db-g2">`;
    h += `<div class="db-card"><div class="ct"><span class="db-lab">Heure par heure — ventes − matière = marge brute · marge brute − rémunération = marge nette</span></div>
      <table class="db-t"><tr><th>Heure</th><th>Clients</th><th>Panier</th><th>Ventes</th><th>− Matière</th><th>= Marge brute</th><th>En poste</th><th>− Rémunération</th><th>= Marge nette</th></tr>
      ${L.map(l => `<tr class="hv${l.h === sel.h ? ' sel' : ''}${val(l, 'res') < 0 ? ' perte' : ''}" data-h="${l.h}"><td>${l.h === sel.h ? '▾' : '▸'} ${l.h} – ${l.h + 1} h</td><td>${moy ? nf(l.moy.tickets, 0) : fN(l.tickets)}</td><td>${fU(l.panier)}</td><td>${fE(val(l, 'ca'))}</td><td class="mu">${fE(val(l, 'mat'))}</td><td><b>${fE(val(l, 'mb'))}</b> <span class="mu" style="font-weight:400">${fP(l.mbPct)}</span></td><td>${l.poste}</td><td class="mu">${fE(val(l, 'trav'))}</td><td class="${coul(val(l, 'res'))}"><b>${fS(val(l, 'res'))}</b></td></tr>`).join('')}
      <tr class="tot"><td>${S.vue === 'jour' ? 'Journée' : (moy ? 'Jour type' : 'Période')}</td><td>${fN(tot.tickets / (moy ? nJ : 1))}</td><td>${fU(tot.panier)}</td><td>${fE(tot.ca / (moy ? nJ : 1))}</td><td>${fE(tot.mat / (moy ? nJ : 1))}</td><td>${fE(tot.mb / (moy ? nJ : 1))} <span class="mu" style="font-weight:400">${fP(tot.mbPct)}</span></td><td>—</td><td>${fE(tot.trav / (moy ? nJ : 1))}</td><td class="${coul(tot.res)}">${fS(tot.res / (moy ? nJ : 1))}</td></tr></table>
      <div class="db-note" style="padding-top:10px">Ventes, matière, rémunération et marge de l’heure viennent du panel (répartition horaire). Le personnel en poste est la moyenne des jours ouverts.</div></div>`;
    h += rendPanneau(st, sel, moy, nJ);
    h += `</div>`;
    return h;
  }
  function rendPanneau(st, l, moy, nJ) {
    const V = moy ? l.moy.ca : l.ca, M = moy ? l.moy.mat : l.mat, MB = V - M, T = moy ? l.moy.trav : l.trav, R = MB - T;
    const base = Math.max(V, 1);
    const rows = [['Ventes', V, 0, V, '#8D1D2C'], ['− Coût matière', M, V - M, V, '#e5c9a0'], ['= Marge brute', MB, 0, MB, '#8D1D2C'], ['− Rémunération', T, MB - T, MB, '#D97706'], ['= Marge nette', R, 0, Math.max(R, 0), R >= 0 ? '#2d7a3e' : '#C0182B']];
    const top = l.top || [];
    const mx = Math.max(...top.map(x => x.v), 1);
    const pr = st.produits || {};
    const couv = pr.total ? `${pr.jours.length} jour(s) sur ${pr.total}` : '';
    return `<div class="db-card"><div class="ct"><span class="db-lab">${l.h} – ${l.h + 1} h</span><span class="db-mini">${moy ? nf(l.moy.tickets, 0) : fN(l.tickets)} clients · panier ${fU(l.panier)}${moy ? ' · par jour ouvert' : ''}</span></div>
      <div class="db-wf">${rows.map((r, k) => `<div class="r${k === 4 ? ' tot' : ''}"><span class="n">${r[0]}</span><span class="b"><i style="left:${Math.max(0, 100 * r[2] / base).toFixed(1)}%;width:${Math.max(0, 100 * (r[3] - r[2]) / base).toFixed(1)}%;background:${r[4]}"></i></span><span class="v ${k === 4 ? coul(R) : ''}">${k === 4 ? fS(R) : fE(r[1])}</span></div>`).join('')}</div>
      <div class="ct" style="border-top:.5px solid var(--color-border-tertiary);border-bottom:none;padding:10px 16px 4px"><span class="db-lab">Top 5 de l’heure — par marge brute</span><span class="db-mini">■ marge &nbsp;<span style="color:#c9a36a">■</span> coût matière</span></div>
      ${top.length ? `<div class="db-pl">${top.map((x, k) => `<div class="r"><b class="n">${k + 1}</b><span title="${esc(x.nom)}">${esc(x.nom)}</span><span class="v mu">${nf(x.q, 0)} pcs</span><span class="b"><i style="width:${(100 * x.v / mx).toFixed(1)}%;background:#e5c9a0"></i>${x.m != null ? `<i style="width:${(100 * Math.max(0, x.m) / mx).toFixed(1)}%"></i>` : ''}</span><span class="v"><b>${x.m != null ? fU(x.m) : fU(x.v)}</b></span><span class="v ${x.taux == null ? 'mu' : (x.taux < 50 ? 'wa' : 'ok')}">${x.taux != null ? nf(x.taux, 0) + ' %' : 'sans coût'}</span></div>`).join('')}</div>
        ${(l.cats || []).length ? `<div class="ct" style="border-top:.5px solid var(--color-border-tertiary);border-bottom:none;padding:10px 16px 4px"><span class="db-lab">Top 3 des catégories — par marge brute</span><span class="db-mini">${l.categories || ''} catégorie(s) vendue(s)</span></div>
        <div class="db-pl">${l.cats.map((x, k) => `<div class="r"><b class="n">${k + 1}</b><span title="${esc(x.nom)}">${esc(x.nom)}<br><span class="mu" style="font-size:10px">${x.refs} référence(s) · ${x.part != null ? nf(x.part, 0) + ' % des ventes de l’heure' : ''}</span></span><span class="v mu">${nf(x.q, 0)} pcs</span><span class="b"><i style="width:${(100 * x.v / Math.max(...l.cats.map(y => y.v), 1)).toFixed(1)}%;background:#e5c9a0"></i>${x.m != null ? `<i style="width:${(100 * Math.max(0, x.m) / Math.max(...l.cats.map(y => y.v), 1)).toFixed(1)}%"></i>` : ''}</span><span class="v"><b>${x.m != null ? fU(x.m) : fU(x.v)}</b></span><span class="v ${x.taux == null ? 'mu' : (x.taux < 50 ? 'wa' : 'ok')}">${x.taux != null ? nf(x.taux, 0) + ' %' : 'sans coût'}</span></div>`).join('')}</div>` : ''}
        <div class="db-note">${l.references} référence(s) vendue(s) à cette heure${l.topSur ? ' · tickets lus sur ' + l.topSur + ' jour(s)' : ''}${pr.total && !pr.complet ? ' · <b>tickets lus : ' + couv + '</b> — la moisson complète la période au fil des heures' : ''}. Marge = ventes − quantité × coût de la recette ; « sans coût » : recette non chiffrée.</div>`
        : `<div class="db-note" style="padding-top:8px">${pr.total ? (pr.jours.length ? 'Aucune ligne produit à cette heure sur les jours lus (' + couv + ').' : 'Tickets pas encore lus pour cette période — la moisson les lit par lots ; relisez dans quelques minutes.') : 'Les tickets ne sont moissonnés que depuis août 2026.'}</div>`}
    </div>`;
  }

  /* --- gestes -------------------------------------------------------------- */
  function brancher() {
    $.querySelectorAll('[data-vue]').forEach(b => b.addEventListener('click', () => { S.vue = b.dataset.vue; S.heure = null; urlMaj(); charger(false); }));
    const dt = document.getElementById('db-date'); if (dt) { dt.addEventListener('change', () => { if (dt.value && dt.value <= AUJ) { S.date = dt.value; S.heure = null; urlMaj(); charger(false); } }); }
    $.querySelectorAll('[data-pas]').forEach(b => b.addEventListener('click', () => {
      const t = new Date(S.date + 'T12:00:00'); const n = +b.dataset.pas;
      if (S.vue === 'jour') { t.setDate(t.getDate() + n); } else if (S.vue === 'semaine') { t.setDate(t.getDate() + 7 * n); } else if (S.vue === 'mois') { t.setMonth(t.getMonth() + n, 1); } else { t.setFullYear(t.getFullYear() + n, 0, 1); }
      const d = t.toISOString().slice(0, 10); if (d > AUJ) { return; }
      S.date = d; S.heure = null; urlMaj(); charger(false); }));
    $.querySelectorAll('[data-auj]').forEach(b => b.addEventListener('click', () => { S.date = AUJ; S.heure = null; urlMaj(); charger(false); }));
    $.querySelectorAll('[data-recharger]').forEach(b => b.addEventListener('click', () => charger(true)));
    $.querySelectorAll('[data-mode]').forEach(b => b.addEventListener('click', () => { S.mode = b.dataset.mode; rendre(); }));
    $.querySelectorAll('[data-hm]').forEach(b => b.addEventListener('click', () => { S.hmMetric = b.dataset.hm; rendre(); }));
    $.querySelectorAll('[data-tdrop]').forEach(b => b.addEventListener('click', () => { S.tOuvert = !S.tOuvert; rendre(); }));
    $.querySelectorAll('[data-h]').forEach(el => el.addEventListener('click', () => { S.heure = +el.dataset.h; rendre(); }));
  }

  /* --- départ ------------------------------------------------------------- */
  lire('/stores?statut=tous').then(l => { S.stores = (Array.isArray(l) ? l : []).filter(s => !s.status || /ouvert/i.test(s.status)).map(s => ({ id: s.id, nom: s.nom || s.name })); rendre(); }).catch(() => {});
  urlMaj();
  charger(false);
  // La journée en cours se relit toutes les dix minutes.
  setInterval(() => { if (S.vue === 'jour' && S.date === AUJ) { charger(true); } }, 600000);
})();
