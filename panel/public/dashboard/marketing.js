/* Dashboard magasin › Marketing › Mon marketing.
 *
 * 15/11/2026, « mon marketing : c'est seulement les campagnes en cours et la description complète de ce qui s'y passe ».
 * Une page, une chose : chaque campagne en cours du magasin, décrite comme la note de campagne que le réseau envoie au
 * franchisé — où elle en est dans le temps, ce sur quoi elle se juge, l'objectif du magasin, les produits mis en avant,
 * le mot du responsable, le calendrier, la communication, les documents joints.
 *   GET …/dashboard/marketing/campagnes?shop=               les campagnes en cours et leur note, réduite au magasin
 *                                                          (src/franchise/dashboard_marketing.php)
 *   GET …/exploitation/objectifs-produits?shop=&date=      les objectifs produits des campagnes en cours
 *   GET …/dashboard/marketing/campagnes/annexe?shop=&id=   un document joint (PDF)
 * En tête (15/11/2026, « il faut un calendrier annuel avec les campagnes + les produits et saisons des produits ») : le
 * calendrier de l'année — les campagnes du magasin et les saisons des produits (les gammes saisonnières du panel, leurs
 * fenêtres de chaque année ; un clic montre leurs produits et leur prix), le jour d'aujourd'hui en trait.
 *   GET …/dashboard/marketing/annee?shop=&annee=           les campagnes de l'année (toutes phases)
 *   GET …/production/saisons?date=AAAA-01-01                les gammes saisonnières et leurs produits (références)
 *   GET …/production/catalogue                              le nom et le prix de chaque référence
 * Les posts, les avis et la newsletter ont leur page : Réseaux sociaux, Fidélité, Vendre › Offres › Newsletter.
 * Rien n'est inventé : une partie sans donnée ne s'affiche pas, ou dit pourquoi.
 *
 * `window.DashMarketing.racine(shop, {apiBase})` rend l'élément du module, gardé d'un rendu à l'autre.
 */
(function () {
  'use strict';

  const nf0 = new Intl.NumberFormat('fr-BE', { maximumFractionDigits: 0 });
  const n0 = v => (v == null || isNaN(v)) ? '—' : nf0.format(Math.round(v)).replace(/[  ]/g, ' ');
  const nD = (v, d) => (v == null || isNaN(v)) ? '—' : new Intl.NumberFormat('fr-BE', { minimumFractionDigits: d || 0, maximumFractionDigits: d || 0 }).format(v).replace(/[  ]/g, ' ');
  const eur = v => (v == null || isNaN(v)) ? '—' : n0(v) + ' €';
  const esc = v => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const MOIS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
  const dLong = s => /^\d{4}-\d{2}-\d{2}/.test(s || '') ? (+s.slice(8, 10)) + ' ' + MOIS[+s.slice(5, 7) - 1] + ' ' + s.slice(0, 4) : (s || '—');
  const dCourt = s => /^\d{4}-\d{2}-\d{2}/.test(s || '') ? s.slice(8, 10) + '/' + s.slice(5, 7) : (s || '—');
  const auj = () => { const t = new Date(); return t.getFullYear() + '-' + String(t.getMonth() + 1).padStart(2, '0') + '-' + String(t.getDate()).padStart(2, '0'); };
  const isoDe = s => /^(\d{2})\/(\d{2})\/(\d{4})$/.test(s || '') ? s.replace(/^(\d{2})\/(\d{2})\/(\d{4})$/, '$3-$2-$1') : s;
  const ico = d => `<svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="${d}"></path></svg>`;
  const I = { doc: 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6', ok: 'M20 6L9 17l-5-5' };
  const ETATS_OBJ = { atteint: ['ok', 'atteint'], avance: ['ok', 'en avance'], clous: ['att', 'dans les clous'], retard: ['ko', 'en retard'], sans: ['mu', 'sans vente'] };

  const st = { shop: null, api: '../api/cockpit', el: null, d: null, err: null, obj: null, objErr: null,
    an: new Date().getFullYear(), cal: {}, calErr: null, saisons: null, catalogue: null, saisonOuverte: null, ventes: {} };

  function lire(p) {
    return fetch(st.api + p, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(r => r.json().catch(() => ({})).then(j => r.ok ? j : Promise.reject(new Error((j && ((j.error && j.error.message) || j.error || j.message)) || ('HTTP ' + r.status)))));
  }
  function charger(frais) {
    st.err = null; rendre();
    const s = encodeURIComponent(st.shop);
    lire('/dashboard/marketing/campagnes?shop=' + s + (frais ? '&rafraichir=1' : '&_cache=300')).then(d => { st.d = d; })
      .catch(e => { st.err = e.message; }).finally(rendre);
    lire('/exploitation/objectifs-produits?shop=' + s + '&date=' + auj()).then(d => { st.obj = d.campagnes || []; st.objErr = null; })
      .catch(e => { st.objErr = e.message; }).finally(rendre);
    chargerAnnee(frais);
    if (!st.saisons || frais) { lire('/production/saisons?date=' + st.an + '-01-01').then(d => { st.saisons = d.saisons || []; }).catch(e => { st.calErr = e.message; }).finally(rendre); }
    if (!st.catalogue) { lire('/production/catalogue').then(d => { st.catalogue = {}; (Array.isArray(d) ? d : []).forEach(p => { st.catalogue[String(p.ref)] = p; }); }).catch(() => { st.catalogue = {}; }).finally(rendre); }
  }
  function chargerAnnee(frais) {
    const an = st.an;
    if (st.cal[an] && !frais) { return; }
    lire('/dashboard/marketing/annee?shop=' + encodeURIComponent(st.shop) + '&annee=' + an).then(d => { st.cal[an] = d.campagnes || []; }).catch(e => { st.calErr = e.message; }).finally(rendre);
  }

  /* --- le calendrier de l'année --------------------------------------------------------------------------------- */
  const MOIS_C = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
  const jourAn = (iso, an) => { const d = new Date(iso + 'T00:00:00'), o = new Date(an, 0, 1); return Math.round((d - o) / 864e5); };
  const joursAn = an => (new Date(an + 1, 0, 1) - new Date(an, 0, 1)) / 864e5;
  /** Une barre entre deux dates, rognée à l'année : [gauche %, largeur %] ou null. */
  function barre(du, au, an) {
    const n = joursAn(an), a = Math.max(0, jourAn(du, an)), b = Math.min(n - 1, jourAn(au, an));
    return b < a ? null : [100 * a / n, Math.max(0.6, 100 * (b - a + 1) / n)];
  }
  /** Les fenêtres d'une gamme saisonnière dans l'année (MM-JJ → MM-JJ ; à cheval sur deux années : deux morceaux). */
  function fenetres(s, an) {
    if (!/^\d{2}-\d{2}$/.test(s.debut || '') || !/^\d{2}-\d{2}$/.test(s.fin || '')) { return []; }
    return s.debut <= s.fin ? [[an + '-' + s.debut, an + '-' + s.fin]] : [[an + '-01-01', an + '-' + s.fin], [an + '-' + s.debut, an + '-12-31']];
  }
  /** L'illustration d'une gamme, celle du design system (public/assets/img/saisons, reprises du Marketing), au mot-clé de son nom. */
  function saisonImage(nom) {
    const f = String(nom || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]/g, '');
    const m = [['printan', 'printemps'], ['estival', 'ete'], ['automn', 'automne'], ['hivern', 'hiver'], ['noel', 'noel'], ['nicolas', 'saint-nicolas'],
      ['epiphanie', 'epiphanie'], ['mere', 'fete-des-meres'], ['pascal', 'paques'], ['paque', 'paques'], ['valentin', 'saint-valentin'], ['chandeleur', 'chandeleur'],
      ['glace', 'glace'], ['b2b', 'b2b']].find(([k]) => f.includes(k));
    return '../assets/img/saisons/' + (m ? m[1] : 'standard') + '.png';
  }
  /** La saison l'an dernier, pour l'année regardée : [du, au] (à cheval sur deux années : de N-1 à N). */
  function fenetreN1(s, an) {
    if (!/^\d{2}-\d{2}$/.test(s.debut || '') || !/^\d{2}-\d{2}$/.test(s.fin || '')) { return null; }
    return s.debut <= s.fin ? [(an - 1) + '-' + s.debut, (an - 1) + '-' + s.fin] : [(an - 1) + '-' + s.debut, an + '-' + s.fin];
  }
  /** Le panel connaît un produit par son identifiant (pwaId), le catalogue par sa référence. */
  const pidDe = r => { const p = st.catalogue && st.catalogue[String(r)]; return p && p.pwaId ? +p.pwaId : +r; };
  /** La saison de l'année regardée : [du, au] (à cheval : de N à N+1). */
  function fenetreN(s, an) {
    if (!/^\d{2}-\d{2}$/.test(s.debut || '') || !/^\d{2}-\d{2}$/.test(s.fin || '')) { return null; }
    return s.debut <= s.fin ? [an + '-' + s.debut, an + '-' + s.fin] : [an + '-' + s.debut, (an + 1) + '-' + s.fin];
  }
  /**
   * Les chiffres d'une saison : l'an dernier sur la même période. Sans aucune vente l'an dernier (un magasin qui n'était
   * pas ouvert, une gamme absente), ceux de cette année si la saison a commencé — et la liste le dit.
   */
  function chargerVentes(s) {
    const f = fenetreN1(s, st.an); if (!f || !(s.produits || []).length) { return; }
    const cle = s.id + ':' + f[0];
    if (st.ventes[cle]) { return; }
    const V = st.ventes[cle] = { du: f[0], au: f[1] };
    const lireP = (du, au) => lire('/dashboard/marketing/ventes-periode?shop=' + encodeURIComponent(st.shop) + '&du=' + du + '&au=' + au + '&pids=' + s.produits.map(pidDe).join(','));
    lireP(f[0], f[1]).then(d => {
      V.d = d;
      const g = fenetreN(s, st.an), a = auj();
      if (d.total && !d.total.q && g && g[0] <= a) {
        return lireP(g[0], g[1] < a ? g[1] : a).then(d2 => { if (d2.total && d2.total.q) { V.n1Vide = true; V.repli = { du: g[0], au: g[1] < a ? g[1] : a }; V.d = d2; } else { V.vide = true; } });
      }
      if (d.total && !d.total.q) { V.vide = true; }
    }).catch(e => { V.err = e.message; }).finally(rendre);
  }
  const eur2 = v => v == null || isNaN(v) ? '—' : new Intl.NumberFormat('fr-BE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(v).replace(/[  ]/g, ' ') + ' €';
  const dLongue = iso => /^\d{4}-\d{2}-\d{2}/.test(iso || '') ? (+iso.slice(8, 10)) + ' ' + MOIS_C[+iso.slice(5, 7) - 1] + ' ' + iso.slice(0, 4) : '—';
  /** La liste d'une saison, sur toute la largeur : catégorie, produit, et ce qu'il a fait l'an dernier sur la saison. */
  function listeSaison(s, img) {
    const n = (s.produits || []).length, f = fenetreN1(s, st.an), V = f ? st.ventes[s.id + ':' + f[0]] : null;
    const quand = V && V.repli ? `cette année, du ${dLongue(V.repli.du)} au ${dLongue(V.repli.au)}` : f ? `l’an dernier, du ${dLongue(f[0])} au ${dLongue(f[1])}` : '';
    const tete = `<div class="cal-drop-h"><img src="${esc(img)}" alt=""><div><b>${esc(s.nom)}</b><small>${jourMois(s.debut)} → ${jourMois(s.fin)} · ${n} produit${n > 1 ? 's' : ''} au panel${quand ? ' · les chiffres : ' + quand : ''}</small></div>
      <button type="button" class="cal-x" data-calsaison="${s.id}" aria-label="Fermer">×</button></div>${s.alerte ? `<p class="cal-alerte">À vérifier : ${esc(s.alerte)}</p>` : ''}`;
    if (!n) { return `<div class="cal-drop">${tete}<p class="mc-mu">Pas de produit rattaché à cette gamme au panel.</p></div>`; }
    const D = V && V.d, P = D ? D.produits : {};
    const lignes = (s.produits || []).map(r => {
      const c = (st.catalogue && st.catalogue[String(r)]) || {}, v = P[String(pidDe(r))] || null;
      return { cat: c.categorie || '—', nom: c.nom || 'référence ' + r, prix: c.prix, v };
    }).sort((a, b) => ((b.v && b.v.ca) || 0) - ((a.v && a.v.ca) || 0) || a.cat.localeCompare(b.cat, 'fr') || a.nom.localeCompare(b.nom, 'fr'));
    const nf1 = v => v == null ? '—' : new Intl.NumberFormat('fr-BE', { maximumFractionDigits: 1 }).format(v).replace(/[  ]/g, ' ');
    const cell = (v, k) => !D ? `<td class="n">${V && V.err ? '—' : '<span class="db-sk cal-sk"></span>'}</td>` : `<td class="n">${k(v)}</td>`;
    const corps = lignes.map(l => `<tr><td class="cat">${esc(l.cat)}</td><td>${esc(l.nom)}${l.prix != null ? `<small>${eur2(l.prix)} TTC aujourd’hui</small>` : ''}</td>
      ${cell(l.v, v => v ? nf1(v.q) : '0')}${cell(l.v, v => v ? eur2(v.ca) : eur2(0))}${cell(l.v, v => v && v.caHt != null ? eur2(v.caHt) : '—')}
      ${cell(l.v, v => !v ? eur2(0) : v.marge != null ? eur2(v.marge) + (v.methode === 'taux' ? '<small title="Vendu aussi en portions ou en variantes : le taux de marge de la recette, appliqué au CA HT">au taux de la recette</small>' : '') : `<span class="cal-motif" title="${esc(v.motif || '')}">${esc(v.motif || '—')}</span>`)}${cell(l.v, v => v && v.margePct != null ? nf1(v.margePct) + ' %' : '—')}</tr>`).join('');
    const T = D && D.total;
    return `<div class="cal-drop">${tete}
      ${V && V.err ? `<p class="cal-alerte">Les ventes de l’an dernier ne se lisent pas : ${esc(V.err)}.</p>` : ''}
      ${V && V.n1Vide ? `<p class="cal-note">Aucune vente du magasin sur cette saison l’an dernier (le magasin n’était pas encore ouvert, ou la gamme n’y était pas) : voici les chiffres de cette année, du ${dLongue(V.repli.du)} au ${dLongue(V.repli.au)}.</p>` : ''}
      ${V && V.vide ? `<p class="cal-note">Aucune vente du magasin sur cette saison l’an dernier${fenetreN(s, st.an) && fenetreN(s, st.an)[0] <= auj() ? ', ni cette année' : ' — et elle n’a pas encore commencé cette année'} : le magasin n’était peut-être pas encore ouvert, ou la gamme n’y était pas.</p>` : ''}
      <table class="cal-t"><thead><tr><th>Catégorie</th><th>Produit</th><th class="n">Qté vendue ${V && V.repli ? 'N' : 'N-1'}</th><th class="n">CA TTC ${V && V.repli ? 'N' : 'N-1'}</th><th class="n">CA HT ${V && V.repli ? 'N' : 'N-1'}</th><th class="n">Marge réalisée</th><th class="n">Marge %</th></tr></thead>
        <tbody>${corps}</tbody>
        ${T ? `<tfoot><tr><td colspan="2">La gamme, ${V && V.repli ? 'cette année' : 'l’an dernier'} sur la saison${T.sansMarge ? ` <small>${T.sansMarge} produit${T.sansMarge > 1 ? 's' : ''} sans marge calculable (recette ou prix à vérifier) : hors total de la marge</small>` : ''}</td>
          <td class="n">${nf1(T.q)}</td><td class="n">${eur2(T.ca)}</td><td class="n">${eur2(T.caHt)}</td><td class="n">${eur2(T.marge)}</td><td class="n">${T.margePct != null ? nf1(T.margePct) + ' %' : '—'}</td></tr></tfoot>` : ''}</table>
      <p class="cal-src">Ventes du magasin au panel ; marge réalisée = CA HT encaissé − quantité × coût de la recette du jour (un produit vendu aussi en portions ou en variantes : le CA HT × le taux de marge de sa recette).</p></div>`;
  }
  const jourMois = mmjj => /^\d{2}-\d{2}$/.test(mmjj || '') ? (+mmjj.slice(3)) + ' ' + MOIS_C[+mmjj.slice(0, 2) - 1] : '—';
  const isoCourt = iso => /^\d{4}-\d{2}-\d{2}/.test(iso || '') ? jourMois(iso.slice(5, 10)) : '—';
  const PHASES_C = { en_cours: 'en cours', a_venir: 'à venir', terminee: 'terminée', sans_date: 'sans date' };

  /**
   * Le calendrier de l'année (15/11/2026 ; « quelque chose de plus lisible, avec les illustrations du design system ; au
   * clic, une liste déroulante des produits ») : les campagnes, puis les saisons des produits dans l'ordre de l'année,
   * chacune avec son illustration, ses dates en clair et ce qui est en cours aujourd'hui ; un clic ouvre la liste de ses
   * produits et de leur prix.
   */
  function calAnnee() {
    const an = st.an, C = st.cal[an], ajd = auj(), cetteAnnee = +ajd.slice(0, 4) === an;
    const moisAuj = cetteAnnee ? +ajd.slice(5, 7) - 1 : -1;
    const fond = `<div class="cal-fond" aria-hidden="true">${MOIS_C.map((m, i) => `<i class="${i % 2 ? 'p' : ''}${i === moisAuj ? ' auj' : ''}"></i>`).join('')}</div>`;
    const trait = cetteAnnee ? `<b class="cal-auj" style="left:${(100 * jourAn(ajd, an) / joursAn(an)).toFixed(2)}%"></b>` : '';
    const piste = barres => `<div class="cal-piste">${fond}${trait}${barres}</div>`;

    // Les campagnes : le nom dans la barre quand elle est assez longue, sinon à côté.
    const campagnes = C == null ? '<div class="db-sk" style="height:56px;margin:6px 0"></div>' : C.length ? C.map(c => {
      const b = barre(c.debut, c.fin, an), on = c.phase === 'en_cours';
      return `<div class="cal-l cal-l--c${on ? ' on' : ''}"><div class="cal-lib"><span class="cal-pt" style="background:${esc(c.typeCouleur || 'var(--accent)')}"></span><div><b>${esc(c.nom)}</b>
          <small>${isoCourt(c.debut)} → ${isoCourt(c.fin)} · ${esc(c.type || 'Campagne')}</small></div><span class="cal-etat${on ? ' on' : ''}">${PHASES_C[c.phase] || ''}</span></div>
        ${piste(b ? `<span class="cal-b" style="left:${b[0].toFixed(2)}%;width:${b[1].toFixed(2)}%;background:${esc(c.typeCouleur || 'var(--accent)')}" title="${esc(c.nom)} · ${isoCourt(c.debut)} → ${isoCourt(c.fin)}">${b[1] > 9 ? `<em>${esc(c.nom)}</em>` : ''}</span>` : '')}</div>`;
    }).join('') : `<p class="mc-mu cal-vide">Pas de campagne pour le magasin en ${an}.</p>`;

    // Les saisons, dans l'ordre de l'année (leur début), celles d'aujourd'hui en avant.
    const S = st.saisons == null ? null : st.saisons.slice().sort((x, y) => String(x.debut).localeCompare(String(y.debut)));
    const nomRef = r => (st.catalogue && st.catalogue[String(r)]) || null;
    const enCours = s => cetteAnnee && fenetres(s, an).some(([d, f]) => d <= ajd && ajd <= f);
    const saisons = S == null ? '<div class="db-sk" style="height:120px;margin:6px 0"></div>' : S.map(s => {
      const bs = fenetres(s, an).map(([d, f]) => barre(d, f, an)).filter(Boolean);
      const ouv = st.saisonOuverte === s.id, n = (s.produits || []).length, on = enCours(s), img = saisonImage(s.nom);
      const liste = ouv ? listeSaison(s, img) : '';
      return `<div class="cal-l cal-l--s${on ? ' on' : ''}${ouv ? ' ouv' : ''}">
          <button type="button" class="cal-lib cal-lib--s" data-calsaison="${s.id}" aria-expanded="${ouv}"><img class="cal-ill" src="${esc(img)}" alt="" loading="lazy">
            <div><b>${esc(s.nom)}${s.alerte ? ' <span class="cal-w" title="' + esc(s.alerte) + '">à vérifier</span>' : ''}</b><small>${jourMois(s.debut)} → ${jourMois(s.fin)}${on ? ' · <span class="cal-maint">en ce moment</span>' : ''}</small></div>
            <span class="cal-nb">${n} produit${n > 1 ? 's' : ''} <i class="cal-chev" aria-hidden="true">▾</i></span></button>
          ${piste(bs.map(b => `<span class="cal-b cal-b--s" style="left:${b[0].toFixed(2)}%;width:${b[1].toFixed(2)}%" title="${esc(s.nom)} · ${jourMois(s.debut)} → ${jourMois(s.fin)}"></span>`).join(''))}
        </div>${liste}`;
    }).join('');

    return `<section class="cal">
      <div class="cal-h"><h3>Le calendrier de l’année</h3>${cetteAnnee ? '<span class="cal-leg"><b class="cal-leg-auj"></b>aujourd’hui</span>' : ''}<span class="sp"></span>
        <span class="cal-an"><button type="button" class="db-btn" data-calan="-1" aria-label="Année précédente">‹</button><b>${an}</b><button type="button" class="db-btn" data-calan="1" aria-label="Année suivante">›</button></span></div>
      <div class="cal-tete"><div class="cal-tete-l"></div><div class="cal-mois">${MOIS_C.map((m, i) => `<span class="${i === moisAuj ? 'auj' : ''}">${m}</span>`).join('')}</div></div>
      <div class="cal-sec">Les campagnes du magasin</div>${campagnes}
      <div class="cal-sec">Les saisons des produits <small>les gammes du panel, dans l’ordre de l’année · un clic ouvre la liste de leurs produits</small></div>${saisons}
      ${st.calErr ? `<p class="mc-mu">Une partie du calendrier ne se lit pas : ${esc(st.calErr)}.</p>` : ''}
    </section>`;
  }

  /* --- une campagne --------------------------------------------------------------------------------------------- */
  const val = (v, k) => v == null ? '—' : nD(v, k.decimales) + (k.unite ? ' ' + k.unite : '');

  function temps(c) {
    if (!c.jours || !c.jour) { return ''; }
    const j = Math.max(1, Math.min(c.jours, c.jour)), reste = c.jours - j;
    return `<div class="mc-temps"><div class="mc-temps-b"><i style="width:${(100 * j / c.jours).toFixed(1)}%"></i></div>
      <span>jour ${j} sur ${c.jours}${reste > 0 ? ` · encore ${reste} jour${reste > 1 ? 's' : ''}` : ' · dernier jour'}</span></div>`;
  }

  function vise(c) {
    const k = c.kpi || {}, m = c.magasin;
    const obj = k.objectifPct == null ? null : (k.objectifPct >= 0 ? '+' : '−') + nD(Math.abs(k.objectifPct), 0) + ' %';
    const faits = [];
    if (m) {
      const repli = m.source === 'repli' && m.repli;
      faits.push([`${esc(k.nom || 'Le KPI')} — référence`, val(m.reference, k),
        repli ? `pas de relevé l’an dernier : moyenne du ${dCourt(m.repli.du)} au ${dCourt(m.repli.au)}` : 'la même période l’an dernier']);
      faits.push([`${esc(k.nom || 'Le KPI')} — visé`, val(m.cible, k), obj ? obj + ' sur la référence' : 'pas d’écart chiffré : tenir la référence']);
      if (m.clientsPrevus != null) { faits.push(['Clients sur la période', n0(m.clientsPrevus), m.clientsA1 != null ? n0(m.clientsA1) + ' sur la période de référence' : '']); }
      if (m.caAttendu != null) { faits.push(['Chiffre d’affaires attendu', eur(m.caAttendu), m.caA1 != null ? eur(m.caA1) + ' sur la période de référence' : '']); }
      else if (m.caA1 != null) { faits.push(['Chiffre d’affaires de référence', eur(m.caA1), 'sur la période de référence']); }
      if (m.budget != null) { faits.push(['Objectif de la période', eur(m.budget), m.budgetSource === 'theorique' ? 'CA théorique de l’étude' : 'budget validé du magasin']); }
    }
    return `<section class="mc-bloc"><h3>Ce que la campagne vise</h3>
      <p class="mc-txt">Levier <b>${esc(c.levier || '—')}</b>${k.nom ? ` : la campagne se juge sur <b>${esc(k.nom)}</b>${k.unite ? ` (${esc(k.unite)})` : ''}` : ''}${obj ? `, avec un objectif de <b>${obj}</b>` : ''}.</p>
      ${faits.length ? `<dl class="mc-faits">${faits.map(([t, v, s]) => `<div><dt>${t}</dt><dd>${esc(v)}${s ? `<small>${esc(s)}</small>` : ''}</dd></div>`).join('')}</dl>`
        : '<p class="mc-mu">Les chiffres du magasin ne se calculent pas pour cette campagne.</p>'}
    </section>`;
  }

  function produits(c) {
    const o = (st.obj || []).find(x => String(x.id) === String(c.id));
    if (!o) { return ''; }
    const e = ETATS_OBJ[o.etat] || ['mu', o.etat || ''];
    return `<section class="mc-bloc" id="mc-${esc(c.id)}-obj"><h3>Les produits mis en avant</h3>
      <div class="mc-obj-h"><span>Objectif du magasin : <b>${n0(o.objectif)} pièces</b></span><span class="mm-etat ${e[0]}">${esc(e[1])}</span></div>
      <div class="mm-jauge"><i class="${e[0]}" style="width:${Math.min(100, o.pct || 0).toFixed(1)}%"></i>${o.attendu != null ? `<b style="left:${Math.min(100, o.attendu).toFixed(1)}%" title="où il faudrait en être aujourd’hui"></b>` : ''}</div>
      <p class="mc-mu">${n0(o.vendu)} vendues (${n0(o.pct)} %)${o.reste > 0 ? ' · reste ' + n0(o.reste) + (o.ilFaut ? ', soit ' + n0(o.ilFaut) + ' par jour' : '') : ''}</p>
      ${(o.produits || []).length ? `<ul class="mc-prod">${o.produits.map(p => `<li>${esc(p.nom)}${p.vendu != null ? `<span>${n0(p.vendu)} vendu${p.vendu > 1 ? 's' : ''}</span>` : ''}</li>`).join('')}</ul>` : ''}
    </section>`;
  }

  function mot(c) {
    if (!c.mot) { return ''; }
    return `<section class="mc-bloc mc-mot"><h3>Le mot du responsable</h3><p>${esc(c.mot.texte).replace(/\n/g, '<br>')}</p>
      <span class="mc-sig">${esc(c.mot.nom)}${c.mot.fonction ? ' — ' + esc(c.mot.fonction) : ''}</span></section>`;
  }

  function calendrier(c) {
    const E = c.etapes || [];
    if (!E.length) { return ''; }
    const a = auj();
    const jalons = E.map(e => ({ l: e.libelle, q: String(e.quand || '').slice(0, 5), iso: isoDe(e.quand), fait: e.fait }))
      .map(j => Object.assign(j, { cle: j.iso === c.du }));
    // Le lancement et la fin bornent le calendrier ; une étape du réseau le jour du lancement (« Go live ») en tient lieu.
    if (!jalons.some(j => j.cle)) { jalons.push({ l: 'Lancement de la campagne', iso: c.du, q: dCourt(c.du), cle: true }); }
    jalons.push({ l: 'Fin de la campagne', iso: c.au, q: dCourt(c.au), cle: true });
    jalons.sort((x, y) => String(x.iso).localeCompare(String(y.iso)));
    return `<section class="mc-bloc" id="mc-${esc(c.id)}-cal"><h3>Le calendrier</h3><ol class="mc-cal">${jalons.map(j => {
      const etat = j.fait ? 'fait' : (j.iso && j.iso <= a ? 'passe' : 'avenir');
      return `<li class="${etat}${j.cle ? ' cle' : ''}"><span class="q">${esc(j.q || '—')}</span><span class="l">${esc(j.l)}</span>${j.fait ? `<span class="s">${ico(I.ok)} fait</span>` : ''}</li>`;
    }).join('')}</ol></section>`;
  }

  function communication(c) {
    const C = c.canaux || [], F = c.formats || [], B = c.investis || [];
    if (!C.length && !F.length && !B.length) { return ''; }
    return `<section class="mc-bloc"><h3>La communication</h3>
      ${C.length ? `<ul class="mc-canaux">${C.map(x => `<li><span>${esc(x.nom)}</span><small>${esc(x.famille)}${x.agence ? ' · ' + esc(x.agence) : ''}${x.valide ? '' : ' · envisagé'}</small></li>`).join('')}</ul>` : ''}
      ${F.length ? `<p class="mc-mu">Formats : ${F.map(f => esc(f.nom)).join(' · ')}</p>` : ''}
      ${B.length ? `<p class="mc-mu">Le budget, levier par levier : ${B.map(x => esc(x.nom) + (x.montant != null ? ' ' + eur(x.montant) : '') + (x.kpi ? ' (jugé sur ' + esc(x.kpi) + ')' : '')).join(' · ')}</p>` : ''}
    </section>`;
  }

  function documents(c) {
    const A = c.annexes || [];
    if (!A.length) { return ''; }
    const lien = a => st.api + '/dashboard/marketing/campagnes/annexe?shop=' + encodeURIComponent(st.shop) + '&id=' + a.id;
    return `<section class="mc-bloc"><h3>Les documents</h3><ul class="mc-docs">${A.map(a => `<li><a href="${esc(lien(a))}" target="_blank" rel="noopener">${ico(I.doc)}${esc(a.nom)}</a><small>${esc([a.type, a.taille].filter(Boolean).join(' · '))}</small></li>`).join('')}</ul></section>`;
  }

  function campagne(c) {
    const portee = c.portee === 'Réseau' ? `tout le réseau${c.nMagasins ? ' · ' + c.nMagasins + ' magasins' : ''}` : `${c.nMagasins || 'quelques'} magasins désignés, dont le vôtre`;
    const img = c.image ? (/^(https?:)?\/\//.test(c.image) ? c.image : '../assistant/' + String(c.image).replace(/^\.?\//, '')) : null;
    const gauche = vise(c) + produits(c) + mot(c);
    const droite = calendrier(c) + communication(c) + documents(c);
    return `<article class="mc" id="mc-${esc(c.id)}">
      <header class="mc-h">${img ? `<img class="mc-img" src="${esc(img)}" alt="">` : `<span class="mc-pastille" style="background:${esc(c.typeCouleur || 'var(--muted)')}">${c.iconePath ? `<svg viewBox="0 0 24 24" aria-hidden="true"><path d="${esc(c.iconePath)}"></path></svg>` : ''}</span>`}
        <div class="mc-h-t"><span class="mc-eyebrow">${esc(c.type || 'Campagne')}${c.levier ? ' · ' + esc(c.levier) : ''}</span><h2>${esc(c.nom)}</h2>
          <p>du ${dLong(c.du)} au ${dLong(c.au)} · ${portee}</p>${temps(c)}</div></header>
      ${c.note ? `<p class="mc-mu mc-note">${esc(c.note)}</p>` : ''}
      <div class="mc-corps"><div>${gauche}</div><div>${droite || '<section class="mc-bloc"><h3>Le calendrier</h3><p class="mc-mu">Pas d’étape posée par le réseau.</p></section>'}</div></div>
    </article>`;
  }

  /* --- le résumé (26/11/2026, DESIGN_SYSTEM.md §7.13, la forme de Mon équipe et de Commandes) ---------------------
   * Ligne 1 : le chiffre clé (les campagnes en cours, 700 : le seul gras), ce qu'il compte, jusqu'à quand. Ligne 2 :
   * « À corriger » — rien de calculé en plus : l'état des objectifs produits (« en retard », « sans vente ») et les étapes
   * du calendrier passées sans « fait », comme chaque campagne les montre déjà. Un bouton mène au bloc, mis en évidence. */
  function aCorriger(L) {
    const A = [], a = auj();
    L.forEach(c => {
      const o = (st.obj || []).find(x => String(x.id) === String(c.id));
      if (o && o.etat === 'retard') { A.push({ sig: 'stop', txt: `${esc(c.nom)} · objectif produits en retard`, cible: `mc-${c.id}-obj` }); }
      else if (o && o.etat === 'sans') { A.push({ sig: 'prize', txt: `${esc(c.nom)} · produits mis en avant sans vente`, cible: `mc-${c.id}-obj` }); }
      (c.etapes || []).filter(e => !e.fait && /^\d{4}-\d{2}-\d{2}$/.test(isoDe(e.quand) || '') && isoDe(e.quand) <= a)
        .forEach(e => A.push({ sig: 'prize', txt: `${esc(c.nom)} · ${esc(String(e.quand || '').slice(0, 5))} ${esc(e.libelle)} : pas encore faite`, cible: `mc-${c.id}-cal` }));
    });
    return A;
  }
  function resume(L) {
    if (!L.length) { return ''; }
    const A = st.obj == null && !st.objErr ? null : aCorriger(L);
    const fins = L.slice().sort((x, y) => String(x.au).localeCompare(String(y.au))).map(c => `${esc(c.nom)} jusqu’au ${isoCourt(c.au)}`).join(' · ');
    const l2 = A == null ? '<div class="db-sk" style="height:30px"></div>'
      : A.length ? `<span class="mmr-t">À corriger</span><span class="mmr-l">${A.map(x => `<button type="button" class="btn btn--sm mmr-b" data-mmac="${esc(x.cible)}"><i class="mmr-ic ${x.sig}" aria-hidden="true"></i>${x.txt}</button>`).join('')}</span>`
        : '<p class="mmr-ok">Rien à corriger : les campagnes en cours suivent leur calendrier et leurs objectifs produits.</p>';
    return `<section class="mmr" aria-label="Résumé de mes campagnes"><div class="mmr-l1"><span class="mmr-v">${L.length}</span>
        <span class="mmr-q">campagne${L.length > 1 ? 's' : ''} en cours pour le magasin<small>${fins}</small></span></div>
      <div class="mmr-l2"${A && A.length ? ' role="region" aria-label="À corriger"' : ''}>${l2}</div></section>`;
  }
  /** Le geste d'un point : le bloc à l'écran, encadré un instant (défilement doux sauf « réduire les animations »). */
  function montrer(id) {
    const el = st.el && st.el.querySelector('#' + id);
    if (!el) { return; }
    const doux = !(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    el.style.scrollMarginTop = '16px';
    el.scrollIntoView({ behavior: doux ? 'smooth' : 'auto', block: 'start' });
    el.classList.remove('mmr-vu'); void el.offsetWidth; el.classList.add('mmr-vu');
    clearTimeout(el._mmT); el._mmT = setTimeout(() => el.classList.remove('mmr-vu'), 1800);
  }

  function html() {
    if (st.err && !st.d) { return `<div class="db-err">Les campagnes ne se lisent pas : ${esc(st.err)} <button type="button" class="db-btn" data-mmrelire="1">Relire</button></div>`; }
    if (!st.d) { return '<div class="db-sk" style="height:120px;margin-bottom:12px"></div><div class="db-sk" style="height:360px"></div>'; }
    const L = st.d.campagnes || [];
    return `${resume(L)}${calAnnee()}<h3 class="mc-titre">Les campagnes en cours</h3>${L.length ? L.map(campagne).join('') : '<div class="mc-vide">Aucune campagne en cours pour le magasin aujourd’hui.</div>'}
      ${st.objErr ? `<p class="mc-mu">Les objectifs produits ne se lisent pas : ${esc(st.objErr)}.</p>` : ''}
      <p class="mm-pied">lu à ${esc(String(st.d.genere || '').slice(11, 16) || '—')} · <button type="button" class="mm-lienb" data-mmrelire="1">relire</button></p>`;
  }

  function rendre() {
    if (!st.el) { return; }
    st.el.innerHTML = html();
    st.el.querySelectorAll('[data-mmrelire]').forEach(b => b.addEventListener('click', () => charger(true)));
    st.el.querySelectorAll('[data-mmac]').forEach(b => b.addEventListener('click', () => montrer(b.dataset.mmac)));
    st.el.querySelectorAll('[data-calan]').forEach(b => b.addEventListener('click', () => { st.an += +b.dataset.calan; st.saisonOuverte = null; chargerAnnee(false); rendre(); }));
    st.el.querySelectorAll('[data-calsaison]').forEach(b => b.addEventListener('click', () => {
      const id = +b.dataset.calsaison; st.saisonOuverte = st.saisonOuverte === id ? null : id;
      const s = st.saisonOuverte != null && (st.saisons || []).find(x => x.id === id); if (s) { chargerVentes(s); }
      rendre();
    }));
  }

  window.DashMarketing = {
    racine(shop, o) {
      o = o || {};
      if (o.apiBase) { st.api = o.apiBase; }
      if (!st.el || String(st.shop) !== String(shop)) {
        st.shop = String(shop); st.el = document.createElement('div'); st.el.className = 'mm-racine';
        Object.assign(st, { d: null, err: null, obj: null, objErr: null });
        charger(false);
      }
      return st.el;
    },
    relire() { if (st.el) { charger(true); } },
  };
})();
