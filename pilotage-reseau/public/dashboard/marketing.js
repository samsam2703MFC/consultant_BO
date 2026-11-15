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
    an: new Date().getFullYear(), cal: {}, calErr: null, saisons: null, catalogue: null, saisonOuverte: null };

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
  function calAnnee() {
    const an = st.an, C = st.cal[an], S = st.saisons;
    const grille = `<div class="cal-grille" aria-hidden="true">${MOIS_C.map(() => '<i></i>').join('')}</div>`;
    const ajd = auj(), trait = +ajd.slice(0, 4) === an ? `<b class="cal-auj" style="left:${(100 * jourAn(ajd, an) / joursAn(an)).toFixed(2)}%" title="aujourd’hui"></b>` : '';
    const ligne = (lib, sous, barres, attr) => `<div class="cal-l"${attr || ''}><div class="cal-lib"><span>${lib}</span>${sous ? `<small>${sous}</small>` : ''}</div><div class="cal-piste">${grille}${trait}${barres}</div></div>`;
    const campagnes = C == null ? '<div class="db-sk" style="height:60px;margin:6px 0"></div>' : C.length ? C.map(c => {
      const b = barre(c.debut, c.fin, an);
      return ligne(esc(c.nom), esc(c.type || ''), b ? `<span class="cal-b${c.phase === 'en_cours' ? ' on' : ''}" style="left:${b[0].toFixed(2)}%;width:${b[1].toFixed(2)}%;background:${esc(c.typeCouleur || 'var(--accent)')}" title="${esc(c.nom + ' · du ' + dCourt(c.debut) + ' au ' + dCourt(c.fin))}"></span>` : '');
    }).join('') : `<p class="mc-mu cal-vide">Pas de campagne pour le magasin en ${an}.</p>`;
    const nomRef = r => (st.catalogue && st.catalogue[String(r)]) || null;
    const saisons = S == null ? '<div class="db-sk" style="height:90px;margin:6px 0"></div>' : S.map(s => {
      const bs = fenetres(s, an).map(([d, f]) => barre(d, f, an)).filter(Boolean);
      const ouv = st.saisonOuverte === s.id, n = (s.produits || []).length;
      const titre = (s.emoji ? s.emoji + ' ' : '') + s.nom;
      return ligne(`<button type="button" class="cal-s" data-calsaison="${s.id}">${esc(titre)}</button>`, `${n} produit${n > 1 ? 's' : ''} · du ${s.debut.slice(3)}/${s.debut.slice(0, 2)} au ${s.fin.slice(3)}/${s.fin.slice(0, 2)}${s.alerte ? ' ⚠' : ''}`,
        bs.map(b => `<span class="cal-b cal-b--s" style="left:${b[0].toFixed(2)}%;width:${b[1].toFixed(2)}%" title="${esc(titre + (s.alerte ? ' — ' + s.alerte : ''))}"></span>`).join(''), ouv ? ' data-ouverte="1"' : '')
        + (ouv ? `<div class="cal-prod">${s.alerte ? `<p class="mc-mu">⚠ ${esc(s.alerte)}</p>` : ''}${n ? (s.produits || []).map(r => { const p = nomRef(r); return `<span class="cal-p">${esc(p ? p.nom : 'réf. ' + r)}${p && p.prix != null ? `<small>${esc(String(p.prix).replace('.', ','))} €</small>` : ''}</span>`; }).join('') : '<p class="mc-mu">Pas de produit rattaché à cette gamme au panel.</p>'}</div>` : '');
    }).join('');
    return `<section class="cal">
      <div class="cal-h"><h3>Le calendrier de l’année</h3><span class="cal-an"><button type="button" class="db-btn" data-calan="-1" aria-label="Année précédente">‹</button><b>${an}</b><button type="button" class="db-btn" data-calan="1" aria-label="Année suivante">›</button></span></div>
      <div class="cal-mois"><div></div><div>${MOIS_C.map(m => `<span>${m}</span>`).join('')}</div></div>
      <div class="cal-sec">Les campagnes</div>${campagnes}
      <div class="cal-sec">Les saisons des produits <small>— les gammes du panel ; un clic montre leurs produits</small></div>${saisons}
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
    return `<section class="mc-bloc"><h3>Les produits mis en avant</h3>
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
    return `<section class="mc-bloc"><h3>Le calendrier</h3><ol class="mc-cal">${jalons.map(j => {
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
    return `<article class="mc">
      <header class="mc-h">${img ? `<img class="mc-img" src="${esc(img)}" alt="">` : `<span class="mc-pastille" style="background:${esc(c.typeCouleur || 'var(--muted)')}">${c.iconePath ? `<svg viewBox="0 0 24 24" aria-hidden="true"><path d="${esc(c.iconePath)}"></path></svg>` : ''}</span>`}
        <div class="mc-h-t"><span class="mc-eyebrow">${esc(c.type || 'Campagne')}${c.levier ? ' · ' + esc(c.levier) : ''}</span><h2>${esc(c.nom)}</h2>
          <p>du ${dLong(c.du)} au ${dLong(c.au)} · ${portee}</p>${temps(c)}</div>
        <span class="mm-statut">en cours</span></header>
      ${c.note ? `<p class="mc-mu mc-note">${esc(c.note)}</p>` : ''}
      <div class="mc-corps"><div>${gauche}</div><div>${droite || '<section class="mc-bloc"><h3>Le calendrier</h3><p class="mc-mu">Pas d’étape posée par le réseau.</p></section>'}</div></div>
    </article>`;
  }

  function html() {
    if (st.err && !st.d) { return `<div class="db-err">Les campagnes ne se lisent pas : ${esc(st.err)} <button type="button" class="db-btn" data-mmrelire="1">Relire</button></div>`; }
    if (!st.d) { return '<div class="db-sk" style="height:120px;margin-bottom:12px"></div><div class="db-sk" style="height:360px"></div>'; }
    const L = st.d.campagnes || [];
    return `${calAnnee()}<h3 class="mc-titre">Les campagnes en cours</h3>${L.length ? L.map(campagne).join('') : '<div class="mc-vide">Aucune campagne en cours pour le magasin aujourd’hui.</div>'}
      ${st.objErr ? `<p class="mc-mu">Les objectifs produits ne se lisent pas : ${esc(st.objErr)}.</p>` : ''}
      <p class="mm-pied">${L.length ? L.length + ' campagne' + (L.length > 1 ? 's' : '') + ' en cours · ' : ''}lu à ${esc(String(st.d.genere || '').slice(11, 16) || '—')} · <button type="button" class="mm-lienb" data-mmrelire="1">relire</button></p>`;
  }

  function rendre() {
    if (!st.el) { return; }
    st.el.innerHTML = html();
    st.el.querySelectorAll('[data-mmrelire]').forEach(b => b.addEventListener('click', () => charger(true)));
    st.el.querySelectorAll('[data-calan]').forEach(b => b.addEventListener('click', () => { st.an += +b.dataset.calan; st.saisonOuverte = null; chargerAnnee(false); rendre(); }));
    st.el.querySelectorAll('[data-calsaison]').forEach(b => b.addEventListener('click', () => { const id = +b.dataset.calsaison; st.saisonOuverte = st.saisonOuverte === id ? null : id; rendre(); }));
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
