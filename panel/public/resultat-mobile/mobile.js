/* Résultat réseau — la vue téléphone du cockpit (07/11/2026).
 *
 * Le cockpit est une application de bureau : rail de 246 px, tableaux larges. Au téléphone,
 * l'écran Résultat devient cette page : une colonne, les trois étendues (Jour, Semaine, Mois),
 * le réseau en tête puis un magasin par carte, dépliable. Mêmes lectures que l'écran de bureau :
 *   ../api/cockpit/exploitation/jour?date=               (la journée, magasin par magasin)
 *   ../api/cockpit/exploitation/periode?vue=semaine|mois&date=  (la période, objectif et P&L)
 * Rien ne s'écrit ici. Le cockpit (#/resultat) renvoie vers cette page sous 760 px de large ;
 * « Version complète » ramène au cockpit et l'y garde pour la session.
 */
(function () {
  'use strict';
  const API = '../api/cockpit';
  const $ = document.getElementById('rm');
  const q = new URLSearchParams(location.search);
  const AUJ = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
  const S = {
    vue: ['jour', 'semaine', 'mois'].includes(q.get('vue')) ? q.get('vue') : 'jour',
    date: /^\d{4}-\d{2}-\d{2}$/.test(q.get('date') || '') && q.get('date') <= AUJ ? q.get('date') : AUJ,
    res: {}, err: {}, enCours: {}, ouvert: null, tri: 'ca',
  };

  /* --- formats ---------------------------------------------------------------- */
  const esc = v => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const nf = (n, d) => Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d, maximumFractionDigits: d });
  const fE = n => n == null || isNaN(n) ? '—' : nf(Math.round(n), 0) + ' €';
  const fK = n => n == null || isNaN(n) ? '—' : (Math.abs(n) >= 10000 ? nf(n / 1000, 1) + ' k€' : fE(n));
  const fU = n => n == null || isNaN(n) ? '—' : nf(n, 2) + ' €';
  const fP = n => n == null || isNaN(n) ? '—' : nf(n, 1) + ' %';
  const sg = (n, d = 1) => n == null || isNaN(n) ? '—' : (n > 0 ? '+' : n < 0 ? '−' : '') + nf(Math.abs(n), d) + ' %';
  const JOURS = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
  const MOIS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
  const dt = d => new Date(d + 'T12:00:00');
  const fDL = d => { const t = dt(d); return JOURS[t.getDay()] + ' ' + t.getDate() + ' ' + MOIS[t.getMonth()]; };
  const fDC = d => d ? d.slice(8, 10) + '/' + d.slice(5, 7) : '';
  const nomCourt = n => String(n || '').replace(/^Atelier by\s*/i, '').replace(/^[-–\s]+/, '').replace(/^.* - /, '') || n;

  /* --- lecture -------------------------------------------------------------- */
  const cle = () => S.vue + '|' + S.date;
  const chemin = () => S.vue === 'jour' ? '/exploitation/jour?date=' + S.date : '/exploitation/periode?vue=' + S.vue + '&date=' + S.date;
  function lire(force) {
    const k = cle();
    if ((!force && S.res[k]) || S.enCours[k]) { rendre(); return; }
    S.enCours[k] = true; delete S.err[k]; rendre();
    fetch(API + chemin() + (force ? '&rafraichir=1' : ''), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(r => r.ok ? r.json() : r.json().then(j => Promise.reject(new Error((j && j.error) || ('HTTP ' + r.status))), () => Promise.reject(new Error('HTTP ' + r.status))))
      .then(d => { if (d && d.error === 'auth') { throw new Error('session expirée — reconnectez-vous au cockpit'); } S.res[k] = d; })
      .catch(e => { S.err[k] = e.message; })
      .finally(() => { S.enCours[k] = false; rendre(); });
  }
  function urlMaj() {
    const u = new URL(location.href);
    u.searchParams.set('vue', S.vue); u.searchParams.set('date', S.date);
    history.replaceState(null, '', u.toString());
  }

  /* --- seuils et couleurs ----------------------------------------------------- */
  // Un état se dit par un mot ET une couleur (design system §3) : jamais la couleur seule.
  const ton = (v, seuil, sens) => v == null || seuil == null ? '' : (sens * (v - seuil) > 0 ? 'ko' : (sens * (v - seuil) > -2 ? 'wa' : 'ok'));
  const tonDelta = v => v == null ? '' : (v >= 0 ? 'ok' : (v > -5 ? 'wa' : 'ko'));

  /* --- rendu ------------------------------------------------------------------ */
  function etendueLib(d) {
    if (S.vue === 'jour') { return (S.date === AUJ ? 'Aujourd’hui · ' : '') + fDL(S.date); }
    if (!d) { return S.vue === 'semaine' ? 'la semaine' : 'le mois'; }
    if (S.vue === 'semaine') { return 'Semaine du ' + fDC(d.du) + ' au ' + fDC(d.au) + (d.enCours ? ' · en cours' : ''); }
    const t = dt(d.du); return MOIS[t.getMonth()].replace(/^./, c => c.toUpperCase()) + ' ' + t.getFullYear() + (d.enCours ? ' · au ' + fDC(d.jusqua) : '');
  }
  function jauge(part, repere) {
    const p = Math.max(0, Math.min(1.2, part || 0)) / 1.2 * 100, r = repere != null ? Math.max(0, Math.min(1.2, repere)) / 1.2 * 100 : null;
    return `<div class="rm-jg"><i class="${part >= 1 ? 'or' : ''}" style="width:${p.toFixed(1)}%"></i>${r != null ? `<b style="left:${r.toFixed(1)}%"></b>` : ''}</div>`;
  }
  function tete(R, d) {
    const jour = S.vue === 'jour', sx = (d && d.seuils) || {};
    let h = `<section class="rm-hero">`;
    if (jour) {
      h += `<div class="k">Chiffre d’affaires réseau</div><div class="v">${fE(R.ca)}</div>
        <div class="s"><span class="${tonDelta(R.caDelta)}">${sg(R.caDelta)}</span> face à la ${esc(((d.reference || {}).libelle) || 'référence')} (${fE(R.refCa)})</div>`;
    } else {
      const at = R.atteinte, ecart = R.ecart;
      h += `<div class="k">Réalisé · ${R.magasinsAvecObjectif || R.magasins} magasins</div><div class="v">${fE(R.realise)}</div>
        <div class="s">objectif ${fK(R.objectif)} · attendu à ce jour ${fK(R.attendu)}</div>
        ${jauge(R.objectif ? R.realise / R.objectif : 0, R.objectif ? R.attendu / R.objectif : null)}
        <div class="s"><span class="${ecart >= 0 ? 'ok' : 'ko'}">${ecart >= 0 ? 'en avance de ' : 'en retard de '}${fE(Math.abs(ecart || 0))}</span> · ${at != null ? nf(at * 100, 0) + ' % de l’attendu' : ''}</div>`;
    }
    h += `<div class="rm-net"><span>Résultat net</span><b class="${R.net >= 0 ? 'ok' : 'ko'}">${fE(R.net)}</b><small>${fP(R.netPct)} des ventes</small></div></section>`;
    // Les tuiles : clients, panier, puis le P&L court face aux seuils du réseau.
    const seuil = (v, x) => x == null || v == null ? '' : (v > x ? 'au-dessus du seuil de ' : 'sous le seuil de ') + nf(x, x % 1 ? 1 : 0) + ' %';
    const tuile = (k, v, s, c) => `<div class="rm-t ${c || ''}"><div class="k">${k}</div><div class="v">${v}</div><div class="s">${s}</div></div>`;
    h += `<div class="rm-tuiles">
      ${tuile('Clients', nf(R.tickets || 0, 0), R.clientsManquants > 0 ? `<span class="ko">−${nf(R.clientsManquants, 0)}</span> face à la référence` : (jour ? 'tickets de caisse' : 'tickets de la période'))}
      ${tuile('Panier moyen', fU(R.panierRetenu || R.panier), R.partPro ? 'pro ' + fP(R.partPro) + ' du CA' : 'comptoir')}
      ${tuile('Matière', fP(R.coutMatierePct), seuil(R.coutMatierePct, sx.food), ton(R.coutMatierePct, sx.food, 1))}
      ${tuile('Marge brute', fP(R.margeBrutePct), fK(R.margeBrute))}
      ${tuile('Main-d’œuvre', fP(R.labourPct), seuil(R.labourPct, sx.labour), ton(R.labourPct, sx.labour, 1))}
      ${tuile('Frais généraux', fP(R.overheadPct), seuil(R.overheadPct, sx.overhead), ton(R.overheadPct, sx.overhead, 1))}
    </div>`;
    return h;
  }
  function carteMagasin(m, d) {
    const jour = S.vue === 'jour', sx = (d && d.seuils) || {}, id = String(m.shopId), ouv = S.ouvert === id;
    if (m.ouvert === false) { return `<div class="rm-mag ferme"><div class="rm-mh"><span class="nom">${esc(nomCourt(m.magasin))}</span><span class="mu">fermé</span></div></div>`; }
    const ca = jour ? m.ca : m.realise;
    const sous = jour
      ? `<span class="${tonDelta(m.caDelta)}">${sg(m.caDelta)}</span> vs réf. · obj. ${fK(m.objectifJour)}`
      : `obj. ${fK(m.objectif)} · <span class="${(m.ecart || 0) >= 0 ? 'ok' : 'ko'}">${(m.ecart || 0) >= 0 ? '+' : '−'}${fK(Math.abs(m.ecart || 0))}</span> vs attendu`;
    const part = jour ? (m.objectifJour ? m.ca / m.objectifJour : null) : (m.objectif ? m.realise / m.objectif : null);
    const rep = jour ? (m.projection && m.objectifJour ? m.projection / m.objectifJour : null) : (m.objectif ? m.attendu / m.objectif : null);
    let h = `<div class="rm-mag${ouv ? ' on' : ''}"><button class="rm-mh" data-mag="${esc(id)}" aria-expanded="${ouv}">
      <span class="nom">${esc(nomCourt(m.magasin))}</span><span class="ca">${fE(ca)}</span>
      <span class="sous">${sous}</span><span class="net ${m.net >= 0 ? 'ok' : 'ko'}">net ${fK(m.net)}</span></button>
      ${part != null ? jauge(part, rep) : ''}
      <div class="rm-mini"><span>clients <b>${nf(m.tickets || 0, 0)}</b></span><span>panier <b>${fU(m.panierRetenu || m.panier)}</b></span>
        <span class="${ton(m.coutMatierePct, sx.food, 1)}">mat. <b>${fP(m.coutMatierePct)}</b></span><span class="${ton(m.labourPct, sx.labour, 1)}">MO <b>${fP(m.labourPct)}</b></span></div>`;
    if (ouv) {
      h += `<div class="rm-det">`;
      if (jour && m.projection) {
        h += `<p>Projection de fin de journée : <b>${fE(m.projection)}</b>${m.objectifJour ? ' · ' + nf(m.projection / m.objectifJour * 100, 0) + ' % de l’objectif du ' + esc(m.objectifJourNom || 'jour') : ''}${m.projectionReste ? ' · reste ' + fE(m.projectionReste) + ' à vendre' : ''}.</p>`;
      }
      if (m.motifNet) { h += `<p class="mu">${esc(m.motifNet)}.</p>`; }
      h += `<table class="rm-pl"><tr><td>Ventes</td><td class="n">${fE(ca)}</td><td></td></tr>
        <tr><td>Matière</td><td class="n">${fE(m.coutMatiere)}</td><td class="n ${ton(m.coutMatierePct, sx.food, 1)}">${fP(m.coutMatierePct)}</td></tr>
        <tr><td>Main-d’œuvre${m.planningHeures ? ' · ' + nf(m.planningHeures, 0) + ' h' : ''}</td><td class="n">${fE(m.labour)}</td><td class="n ${ton(m.labourPct, sx.labour, 1)}">${fP(m.labourPct)}</td></tr>
        <tr><td>Frais généraux</td><td class="n">${fE(m.overhead)}</td><td class="n ${ton(m.overheadPct, sx.overhead, 1)}">${fP(m.overheadPct)}</td></tr>
        ${m.invendus ? `<tr><td>Invendus</td><td class="n">${fE(m.invendus)}</td><td class="n">${fP(m.invendusPct)}</td></tr>` : ''}
        <tr class="tot"><td>Résultat net</td><td class="n ${m.net >= 0 ? 'ok' : 'ko'}">${fE(m.net)}</td><td class="n">${fP(m.netPct)}</td></tr></table>`;
      if (jour && Array.isArray(m.categories) && m.categories.length) {
        h += `<div class="rm-lab">Catégories du jour</div>` + m.categories.slice(0, 6).map(c => `<div class="rm-cat"><span>${esc(c.categorie)}</span><b>${fE(c.ca)}</b><span class="${tonDelta(c.delta)}">${sg(c.delta, 0)}</span></div>`).join('');
      }
      if (m.caPro) { h += `<p class="mu">Clients pro : ${fE(m.caPro)} (${m.ticketsPro || 0} ticket${(m.ticketsPro || 0) > 1 ? 's' : ''}) · comptoir ${fE(m.caComptoir)}.</p>`; }
      h += `<a class="rm-lien" href="../dashboard/?shop=${encodeURIComponent(id)}&vue=${S.vue === 'jour' ? 'ops' : S.vue}&date=${S.date}">Ouvrir le dashboard du magasin ›</a></div>`;
    }
    return h + `</div>`;
  }
  function rendre() {
    const k = cle(), d = S.res[k], R = d && d.reseau;
    const fin = S.vue === 'jour' ? S.date >= AUJ : (d ? d.au >= AUJ : S.date >= AUJ);
    let h = `<header class="rm-bar"><img src="../assets/img/logo.png" alt="L’Atelier By"><div><div class="rm-kick">Vue réseau</div><div class="rm-t1">Résultat</div></div>
      <button class="rm-ic" data-relire="1" aria-label="Relire">↻</button></header>
      <nav class="rm-ong" role="tablist">${[['jour', 'Jour'], ['semaine', 'Semaine'], ['mois', 'Mois']].map(o => `<button role="tab" aria-selected="${S.vue === o[0]}" class="${S.vue === o[0] ? 'on' : ''}" data-vue="${o[0]}">${o[1]}</button>`).join('')}</nav>
      <div class="rm-date"><button data-pas="-1" aria-label="Précédent">‹</button><span>${esc(etendueLib(d))}</span><button data-pas="1" ${fin ? 'disabled' : ''} aria-label="Suivant">›</button></div>`;
    if (S.err[k] && !d) { h += `<div class="rm-err">${esc(S.err[k])}<button data-relire="1">Réessayer</button></div>`; }
    else if (!d || !R) { h += `<div class="rm-sk"></div><div class="rm-sk court"></div><div class="rm-sk"></div><div class="rm-sk"></div>`; }
    else {
      h += tete(R, d);
      const mags = (d.magasins || []).slice().sort((a, b) => S.tri === 'net' ? (b.net || 0) - (a.net || 0) : ((S.vue === 'jour' ? b.ca : b.realise) || 0) - ((S.vue === 'jour' ? a.ca : a.realise) || 0));
      h += `<div class="rm-sec"><span>Magasins</span><span class="rm-tri">trier <button class="${S.tri === 'ca' ? 'on' : ''}" data-tri="ca">CA</button><button class="${S.tri === 'net' ? 'on' : ''}" data-tri="net">net</button></span></div>`;
      h += mags.map(m => carteMagasin(m, d)).join('');
      if (S.vue === 'jour' && Array.isArray(R.categories) && R.categories.length) {
        h += `<div class="rm-sec"><span>Catégories du réseau</span></div><div class="rm-card">` +
          R.categories.slice(0, 8).map(c => `<div class="rm-cat"><span>${esc(c.categorie)}</span><b>${fE(c.ca)}</b><span class="${tonDelta(c.delta)}">${sg(c.delta, 0)}</span></div>`).join('') + `</div>`;
      }
      const lu = d.cache && d.cache.le ? new Date(d.cache.le) : null;
      h += `<footer class="rm-pied">${lu ? 'Lu à ' + lu.toLocaleTimeString('fr-BE', { hour: '2-digit', minute: '2-digit' }) + ' · ' : ''}${esc(d.source || '')}${S.enCours[k] ? ' · relecture…' : ''}
        <a href="../?bureau=1#/resultat" data-bureau="1">Version complète du cockpit ›</a></footer>`;
    }
    $.innerHTML = h;
    brancher();
  }
  function brancher() {
    $.querySelectorAll('[data-vue]').forEach(b => b.addEventListener('click', () => { S.vue = b.dataset.vue; S.ouvert = null; urlMaj(); lire(false); }));
    $.querySelectorAll('[data-relire]').forEach(b => b.addEventListener('click', () => lire(true)));
    $.querySelectorAll('[data-tri]').forEach(b => b.addEventListener('click', () => { S.tri = b.dataset.tri; rendre(); }));
    $.querySelectorAll('[data-mag]').forEach(b => b.addEventListener('click', () => { S.ouvert = S.ouvert === b.dataset.mag ? null : b.dataset.mag; rendre(); }));
    $.querySelectorAll('[data-bureau]').forEach(a => a.addEventListener('click', () => { try { sessionStorage.setItem('cockpit.bureau', '1'); } catch (e) { /* rien */ } }));
    $.querySelectorAll('[data-pas]').forEach(b => b.addEventListener('click', () => {
      const t = dt(S.date), n = +b.dataset.pas;
      if (S.vue === 'jour') { t.setDate(t.getDate() + n); } else if (S.vue === 'semaine') { t.setDate(t.getDate() + 7 * n); } else { t.setDate(1); t.setMonth(t.getMonth() + n); }
      let x = t.toISOString().slice(0, 10); if (x > AUJ) { x = AUJ; } if (x === S.date) { return; }
      S.date = x; S.ouvert = null; urlMaj(); lire(false);
    }));
  }
  // La journée en cours bouge : relue toutes les dix minutes tant que la page est visible.
  setInterval(() => { if (document.visibilityState === 'visible' && S.vue === 'jour' && S.date === AUJ) { lire(true); } }, 600000);
  lire(false);
})();
