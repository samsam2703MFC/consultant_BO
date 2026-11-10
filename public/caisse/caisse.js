/* La caisse de Gosselies — ouvertures, fermetures et reports, par semaine.
 *
 *   caisse/?date=2026-11-04[&vue=jour&jour=2026-11-08]
 *
 * Un seul magasin suivi : Gosselies (demande du 10/11/2026). Pas de choix de
 * magasin ; un ancien lien `?shop=…` ouvre Gosselies.
 *
 * Une seule lecture : GET ../api/cockpit/caisse/semaine?shop=3&date= (src/caisse.php),
 * qui rend les services de caisse TFBuddy du dimanche d'avant au lundi d'après,
 * le nom des caisses et l'export comptable de chaque jour. Tout le reste —
 * reports, points à vérifier, compte des espèces — se calcule ICI, dans
 * `jourCaisse()` et `jourMagasin()` : une seule définition des règles.
 */
(function () {
  'use strict';
  const API = '../api/cockpit';
  const $ = document.getElementById('caisse');
  const q = new URLSearchParams(location.search);
  const local = d => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
  const AUJ = local(new Date());
  const estDate = v => /^\d{4}-\d{2}-\d{2}$/.test(v || '');

  /* --- dates ------------------------------------------------------------------ */
  const dt = j => new Date(j + 'T12:00:00Z');
  const iso = d => d.toISOString().slice(0, 10);
  const ajoute = (j, n) => { const d = dt(j); d.setUTCDate(d.getUTCDate() + n); return iso(d); };
  const lundi = j => ajoute(j, -((dt(j).getUTCDay() + 6) % 7));
  const JS = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'];
  const JL = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
  const MOIS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
  const MC = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
  const jc = j => JS[dt(j).getUTCDay()] + ' ' + (+j.slice(8, 10));
  const jcm = j => jc(j) + '/' + (+j.slice(5, 7));
  const jl = j => JL[dt(j).getUTCDay()] + ' ' + (+j.slice(8, 10)) + ' ' + MOIS[+j.slice(5, 7) - 1];
  const semLib = du => { const au = ajoute(du, 6); return (+du.slice(8, 10)) + (du.slice(5, 7) !== au.slice(5, 7) ? ' ' + MC[+du.slice(5, 7) - 1] : '') + ' – ' + (+au.slice(8, 10)) + ' ' + MC[+au.slice(5, 7) - 1] + ' ' + au.slice(0, 4); };
  const hm = ts => ts ? String(ts).slice(11, 16) : '—';

  /* --- formats ---------------------------------------------------------------- */
  const esc = v => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const nf = (n, d) => Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d, maximumFractionDigits: d });
  const eur = v => v == null ? '—' : nf(v, 2) + ' €';
  const eur0 = v => v == null ? '—' : nf(Math.round(v), 0) + ' €';
  const pct = (a, b) => b ? Math.round(100 * a / b) : null;

  /* --- état --------------------------------------------------------------------- */
  const SHOP = '3', NOM_SHOP = 'Gosselies';   // le seul magasin suivi (= CAISSE_SHOP de src/caisse.php)
  const S = {
    shop: SHOP,
    date: estDate(q.get('date')) ? q.get('date') : AUJ,
    vue: ['jour', 'reports'].includes(q.get('vue')) ? q.get('vue') : 'semaine',
    mois: /^\d{4}-\d{2}$/.test(q.get('mois') || '') ? q.get('mois') : (estDate(q.get('date')) ? q.get('date') : AUJ).slice(0, 7),
    jour: estDate(q.get('jour')) ? q.get('jour') : null,
    D: null, err: null, enCours: false, cache: {},
    T: {}, Tcours: {}, Terr: {},   // les tickets en espèces d'une journée, par « magasin|jour »
  };

  function lire(path) {
    return fetch(API + path, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(r => r.json().catch(() => ({})).then(d => { if (!r.ok) { throw new Error(d.error || ('HTTP ' + r.status)); } return d; }));
  }
  function url() {
    const p = new URLSearchParams();
    if (S.vue === 'reports') { p.set('vue', 'reports'); p.set('mois', S.mois); history.replaceState(null, '', '?' + p.toString()); return; }
    p.set('date', lundi(S.date));
    if (S.vue === 'jour') { p.set('vue', 'jour'); if (S.jour) { p.set('jour', S.jour); } }
    history.replaceState(null, '', '?' + p.toString());
  }
  /* Le mois : ses semaines, lues une à une (TFBuddy n'aime pas les rafales ; les
   * journées closes sont gardées côté serveur, une semaine déjà vue revient vite),
   * puis assemblées en un registre continu, sans coupure au dimanche. */
  const finMois = m => { const d = new Date(Date.UTC(+m.slice(0, 4), +m.slice(5, 7), 0)); return iso(d); };
  function semainesDuMois(m) {
    const out = [];
    for (let l = lundi(m + '-01'); l <= finMois(m) && l <= AUJ; l = ajoute(l, 7)) { out.push(l); }
    return out;
  }
  function fusionMois(m, sems) {
    const jours = [];
    for (let j = m + '-01'; j <= finMois(m) && j <= AUJ; j = ajoute(j, 1)) { jours.push(j); }
    const vus = {}, services = [], comptable = {}, caisses = [], lacunes = [];
    sems.forEach(d => {
      d.services.forEach(s => { if (!vus[s.id]) { vus[s.id] = true; services.push(s); } });
      Object.assign(comptable, d.comptable || {});
      d.caisses.forEach(c => { if (!caisses.some(x => x.id === c.id)) { caisses.push(c); } });
      (d.lacunes || []).forEach(l => { if (l.jour.slice(0, 7) === m) { lacunes.push(l); } });
    });
    return { shop: sems[0].shop, mois: m, du: m + '-01', au: finMois(m), jours, caisses, services, comptable, lacunes,
      lu: sems[sems.length - 1].lu, sources: sems[0].sources };
  }
  async function chargerMois(relire) {
    const m = S.mois, shop = S.shop, cleM = shop + '|M' + m;
    url();
    if (!relire && S.cache[cleM]) { S.D = S.cache[cleM]; S.err = null; rendre(); return; }
    const sems = semainesDuMois(m);
    if (!sems.length) { S.D = null; S.err = 'Ce mois n’a pas encore commencé.'; rendre(); return; }
    S.enCours = true; S.err = null; S.D = null; S.prog = '0/' + sems.length; rendre();
    try {
      const lues = [];
      for (const l of sems) {
        const cle = shop + '|' + l;
        let d = !relire && S.cache[cle];
        if (!d) { d = await lire('/caisse/semaine?shop=' + encodeURIComponent(shop) + '&date=' + l + (relire ? '&rafraichir=1' : '')); S.cache[cle] = d; }
        if (S.mois !== m || S.shop !== shop || S.vue !== 'reports') { return; }   // l'écran a changé entre-temps
        lues.push(d); S.prog = lues.length + '/' + sems.length; rendre();
      }
      S.D = fusionMois(m, lues); S.cache[cleM] = S.D;
    } catch (e) { S.err = e.message; S.D = null; }
    finally { if (S.mois === m && S.shop === shop) { S.enCours = false; rendre(); } }
  }
  function charger(relire) {
    if (S.vue === 'reports') { chargerMois(relire); return; }
    const du = lundi(S.date), cle = S.shop + '|' + du;
    url();
    if (!relire && S.cache[cle]) { S.D = S.cache[cle]; S.err = null; caleJour(); rendre(); return; }
    S.enCours = true; S.err = null; rendre();
    lire('/caisse/semaine?shop=' + encodeURIComponent(S.shop) + '&date=' + du + (relire ? '&rafraichir=1' : ''))
      .then(d => {
        if (S.vue === 'reports' || lundi(S.date) !== du || S.shop !== String(d.shop && d.shop.id)) { return; }   // une réponse arrivée trop tard
        S.cache[cle] = d; S.D = d; caleJour();
      })
      .catch(e => { S.err = e.message; S.D = null; })
      .finally(() => { S.enCours = false; rendre(); });
  }
  /* Le jour regardé en vue Jour : celui demandé s'il est dans la semaine, sinon
   * le dernier jour passé de la semaine. */
  function caleJour() {
    const D = S.D; if (!D) { return; }
    if (S.jour && S.jour >= D.du && S.jour <= D.au) { return; }
    const passes = D.jours.filter(j => j <= AUJ);
    S.jour = passes.length ? passes[passes.length - 1] : D.du;
    url();
  }

  /* --- les règles de contrôle ---------------------------------------------------- */
  const ABERRANT = 1000;   // un « laissé » au-delà de 1 000 € n'est pas un fond de caisse
  const svc = (c, j) => S.D.services.filter(s => s.caisse === c && s.jour === j).sort((a, b) => String(a.ouv).localeCompare(String(b.ouv)));
  const estNuit = s => s.ouv && s.ouv.slice(11, 13) >= '17' && s.ferm && s.ferm.slice(0, 10) > s.jour;
  /* « a » vaut-il 100 × « b » ? La virgule oubliée à la saisie : 155,66 € tapés 15566. */
  const x100 = (a, b) => a != null && b != null && b > 0 && Math.abs(a - b * 100) < 0.5;
  /* Le service qui suit `s` sur la même caisse : le suivant du jour, sinon le premier du lendemain. */
  function suivant(s) {
    const l = svc(s.caisse, s.jour), i = l.indexOf(s);
    return i >= 0 && i < l.length - 1 ? l[i + 1] : (svc(s.caisse, ajoute(s.jour, 1))[0] || null);
  }
  /* Un « laissé » au-delà d'un fond possible : ce qu'il était sans doute. `corrige` est le montant
   * réel quand le service suivant l'a ouvert (virgule remise) ; sinon null, et le laissé reste impossible. */
  function causeLaisse(s) {
    if (s.laisse == null || s.laisse <= ABERRANT) { return null; }
    const n = suivant(s);
    if (n && x100(s.laisse, n.fond)) {
      return { corrige: n.fond, t: 'Laissé ' + eur(s.laisse) + ' : virgule oubliée — ' + (n.jour === s.jour ? 'le service suivant' : 'le lendemain') + ' ouvre à ' + eur(n.fond) };
    }
    if (x100(s.laisse, s.final)) { return { corrige: null, t: 'Laissé ' + eur(s.laisse) + ' = 100 × le total déclaré : virgule oubliée ?' }; }
    return { corrige: null, t: 'Laissé ' + eur(s.laisse) + ' : montant impossible' };
  }
  /* Le laissé tel qu'il compte : saisi, ou corrigé quand la virgule oubliée se lit au service suivant. */
  const laisseReel = s => { const k = causeLaisse(s); return k ? k.corrige : s.laisse; };
  function jourCaisse(c, j) {
    const l = svc(c, j);
    if (!l.length) { return null; }
    const prem = l[0];
    const journee = l.filter(s => !estNuit(s)), nuit = l.filter(estNuit);
    const dernierJour = journee[journee.length - 1] || prem, dernier = l[l.length - 1];
    const veille = svc(c, ajoute(j, -1));
    const report = veille.length ? veille[veille.length - 1].laisse : null;
    // La veille a laissé 100 × ce fond : ce n'est pas une rupture, c'est la virgule
    // oubliée la veille au soir — déjà comptée là-bas, une seule fois.
    const virgule = report != null && prem.fond != null && x100(report, prem.fond);
    const pts = [];
    if (report != null && prem.fond != null && prem.fond !== report && !virgule) {
      pts.push({ g: 'stop', k: 'rupture', t: 'Report rompu : ' + eur(report) + ' laissés, ' + eur(prem.fond) + ' au fond' });
    }
    if (journee.length > 1) {
      const a = journee[0];
      pts.push({ g: 'warn', k: 'reouv', t: 'Fermée à ' + hm(a.ferm) + ' puis rouverte' + (a.fond > 0 && a.final === 0 ? ' : le fond de ' + eur(a.fond) + ' a disparu' : '') });
    }
    if (journee.some(s => s.ferme && s.final === 0)) { pts.push({ g: 'warn', k: 'final0', t: 'Fermeture sans montant (0,00 € déclaré)' }); }
    l.forEach(s => {
      const k = causeLaisse(s);
      if (k) { pts.push({ g: 'stop', k: 'aberrant', t: k.t, corrige: k.corrige }); }
    });
    nuit.forEach(s => pts.push({ g: 'warn', k: 'nuit', t: 'Rouverte à ' + hm(s.ouv) + ' après la fermeture, refermée le ' + jcm(s.ferm.slice(0, 10)) + ' à ' + hm(s.ferm) }));
    const ouverts = l.filter(s => !s.ferme);
    if (ouverts.length && j < ajoute(AUJ, -1)) { pts.push({ g: 'stop', k: 'ouvert', t: 'Service jamais fermé' }); }
    return { l, prem, journee, nuit, dernierJour, dernier, report, virgule, pts, enCours: ouverts.length > 0 && j >= ajoute(AUJ, -1) };
  }
  function jourMagasin(j) {
    const cf = (S.D.comptable || {})[j] || null;
    const caisses = S.D.caisses.map(c => jourCaisse(c.id, j)).filter(Boolean);
    const fonds = caisses.reduce((a, x) => a + (x.prem.fond || 0), 0);
    const laisse = caisses.reduce((a, x) => a + (laisseReel(x.dernier) || 0), 0);
    const declare = caisses.reduce((a, x) => a + x.l.reduce((b, s) => b + (s.final || 0), 0), 0);
    // Impossible = un laissé aberrant que le service suivant ne permet pas de corriger.
    const aberrant = caisses.some(x => x.l.some(s => { const k = causeLaisse(s); return k && k.corrige == null; }));
    const corrige = caisses.some(x => x.l.some(s => { const k = causeLaisse(s); return k && k.corrige != null; }));
    const enCours = caisses.some(x => x.enCours);
    const versements = cf ? (cf.versements || 0) + (cf.depots || 0) : null;
    const especes = cf ? cf.especes : null;
    const sorties = aberrant || enCours || especes == null || !caisses.length ? null : fonds + especes - laisse - versements;
    return { j, cf, recette: cf ? cf.recette : null, especes, versements, fonds, laisse, declare, sorties, aberrant, corrige, enCours, caisses };
  }
  function tousLesPoints() {
    const out = [];
    S.D.caisses.forEach(c => S.D.jours.forEach(j => { const x = jourCaisse(c.id, j); if (x) { x.pts.forEach(p => out.push(Object.assign({ c: c.id, nom: c.nom, j }, p))); } }));
    const o = { stop: 0, warn: 1 };
    return out.sort((a, b) => a.j.localeCompare(b.j) || o[a.g] - o[b.g]);
  }

  /* --- barre supérieure ------------------------------------------------------------ */
  function barre() {
    const nomShop = (S.D && S.D.shop.nom) || NOM_SHOP;
    const per = S.vue === 'reports' ? MOIS[+S.mois.slice(5, 7) - 1] + ' ' + S.mois.slice(0, 4)
      : (S.vue === 'jour' && S.jour ? jl(S.jour) + ' ' + S.jour.slice(0, 4) : semLib(lundi(S.date)));
    const pasT = S.vue === 'jour' ? 'Jour' : (S.vue === 'reports' ? 'Mois' : 'Semaine');
    return `<header class="topbar ca-tb">
      <div><p class="topbar__kicker"><a href="../">Cockpit</a> · Exploitation · ${esc(nomShop)}</p><h1>Caisse · ${esc(NOM_SHOP)}</h1></div>
      <div class="ca-ctl">
        <div class="seg" role="tablist"><button data-act="vue" data-v="semaine" class="${S.vue === 'semaine' ? 'is-on' : ''}">Semaine</button><button data-act="vue" data-v="jour" class="${S.vue === 'jour' ? 'is-on' : ''}">Jour</button><button data-act="vue" data-v="reports" class="${S.vue === 'reports' ? 'is-on' : ''}">Reports</button></div>
        <button class="btn btn--sm btn--icon" data-act="pas" data-n="-1" aria-label="Précédent" title="${pasT} précédent${pasT === 'Semaine' ? 'e' : ''}">‹</button>
        <span class="per">${esc(per)}</span>
        <button class="btn btn--sm btn--icon" data-act="pas" data-n="1" aria-label="Suivant" title="${pasT} suivant${pasT === 'Semaine' ? 'e' : ''}">›</button>
        ${S.vue === 'reports'
          ? `<input type="month" data-act="mois" value="${esc(S.mois)}" max="${AUJ.slice(0, 7)}" aria-label="Aller à un mois">`
          : `<input type="date" data-act="date" value="${esc(S.vue === 'jour' && S.jour ? S.jour : S.date)}" max="${AUJ}" aria-label="Aller à une date">`}
        <button class="btn btn--sm" data-act="relire" title="Relire TFBuddy, journées closes comprises">↻ Relire</button>
      </div>
    </header>`;
  }

  /* ============================== LA SEMAINE ===================================== */
  function blocJour(c, j) {
    if (j > AUJ) { return `<div class="jr vide"><div class="jr__hd">${jc(j)}</div><div class="jr__p">à venir</div></div>`; }
    const x = jourCaisse(c, j);
    if (!x) { return `<div class="jr vide"><div class="jr__hd">${jc(j)}</div><div class="jr__p">aucun service</div></div>`; }
    const g = x.pts.some(p => p.g === 'stop') ? 'stop' : (x.pts.length ? 'warn' : '');
    const rupt = x.pts.find(p => p.k === 'rupture');
    const lk = x.report == null ? '' : (x.virgule
      ? `<div class="lk" title="Laissé la veille : ${esc(eur(x.report))} = 100 × ce fond de ${esc(eur(x.prem.fond))} — virgule oubliée la veille, la chaîne suit"><i class="vi">≈</i></div>`
      : `<div class="lk" title="Laissé la veille : ${esc(eur(x.report))} · fond d’ouverture : ${esc(eur(x.prem.fond))}"><i class="${rupt ? 'ko' : 'ok'}">${rupt ? '✗' : '✓'}</i></div>`);
    const decl = x.journee.reduce((a, s) => a + (s.final || 0), 0);
    const d = x.dernierJour;
    return `<button class="jr ${g}" data-act="ouvrir" data-j="${j}" title="Ouvrir le ${esc(jl(j))}">${lk}
      <div class="jr__hd"><b>${jc(j)}</b><span>${x.l.length > 1 ? x.l.length + ' services' : ''}</span></div>
      <div class="jr__p">ouv. ${hm(x.prem.ouv)} · ${esc(x.prem.parOuv)}</div>
      <div class="jr__l"><span>Fond</span><b class="${rupt ? 'x' : ''}">${eur(x.prem.fond)}</b></div>
      <div class="jr__l"><span>Déclaré</span><b class="${decl === 0 && d.ferme ? 'z' : ''}">${d.ferme || decl ? eur(decl) : '—'}</b></div>
      <div class="jr__l"><span>Laissé</span><b class="${d.laisse > ABERRANT ? 'x' : ''}">${eur(d.laisse)}</b></div>
      <div class="jr__p">${d.ferme ? 'ferm. ' + hm(d.ferm) + ' · ' + esc(d.parFerm) : '<span class="tag-warn">en cours</span>'}</div>
      ${x.pts.length ? `<div class="jr__pts">${x.pts.map(p => `<span class="tag-${p.g}">${esc(p.t)}</span>`).join('')}</div>` : ''}
    </button>`;
  }
  function chaine(c) {
    const dim = svc(c.id, ajoute(S.D.du, -1));
    const rep0 = dim.length ? dim[dim.length - 1].laisse : null;
    return `<div class="ch">
      <div class="ch__lab">${esc(c.nom)}<small>caisse ${c.id}</small><span class="rep0">laissé ${jc(ajoute(S.D.du, -1))} : ${eur(rep0)}</span></div>
      ${S.D.jours.map(j => blocJour(c.id, j)).join('')}
    </div>`;
  }
  function tableMagasin(SEM) {
    const td = (f, cls) => SEM.map(x => `<td class="${cls || ''}">${x.j > AUJ ? '<span class="nul">—</span>' : f(x)}</td>`).join('');
    const tot = f => SEM.reduce((a, x) => a + (f(x) || 0), 0);
    // Les moyens de paiement de la semaine, dans l'ordre de l'export ; ceux restés à zéro toute la semaine sont tus.
    const noms = [];
    SEM.forEach(x => ((x.cf && x.cf.moyens) || []).forEach(m => { if (noms.indexOf(m.nom) < 0) { noms.push(m.nom); } }));
    const mont = (x, n) => { const m = ((x.cf && x.cf.moyens) || []).find(y => y.nom === n); return m ? m.montant : null; };
    const lignesMoyens = noms.filter(n => /esp[eè]ce/i.test(n) || tot(x => mont(x, n)) !== 0)
      .map(n => `<tr><td>${esc(n)}</td>${td(x => x.cf ? eur(mont(x, n) || 0) : '<span class="nul">—</span>')}<td class="sem">${eur(tot(x => mont(x, n)))}</td></tr>`).join('');
    const nCalc = SEM.filter(x => x.sorties != null).length;
    return `<table class="mg"><thead><tr><th>Le magasin</th>${SEM.map(x => `<th>${x.j > AUJ ? jc(x.j) : `<button class="lien" data-act="ouvrir" data-j="${x.j}">${jc(x.j)}</button>`}</th>`).join('')}<th class="sem">Semaine</th></tr></thead><tbody>
    <tr class="sep"><td colspan="9">Encaissé · export comptable TFBuddy</td></tr>
    <tr class="tot"><td>Recette du jour<small>compte 400000</small></td>${td(x => x.cf ? eur(x.recette) : '<span class="tag-warn">illisible</span>')}<td class="sem">${eur(tot(x => x.recette))}</td></tr>
    ${lignesMoyens}
    <tr class="sep"><td colspan="9">Les espèces · du fond du matin au fond du soir</td></tr>
    <tr><td>Fonds d’ouverture<small>toutes les caisses</small></td>${td(x => x.caisses.length ? eur(x.fonds) : '<span class="nul">—</span>')}<td class="sem">${eur(tot(x => x.fonds))}</td></tr>
    <tr><td>+ Espèces encaissées</td>${td(x => eur(x.especes))}<td class="sem">${eur(tot(x => x.especes))}</td></tr>
    <tr><td>− Retraits<small>enregistrés à la caisse, illisibles par l’API</small></td>${td(() => '<span class="nul">non lisibles</span>')}<td class="sem"><span class="nul">—</span></td></tr>
    <tr><td>− Versements · dépôts</td>${td(x => x.versements ? eur(x.versements) : '<span class="nul">aucun</span>')}<td class="sem">${eur(tot(x => x.versements))}</td></tr>
    <tr><td>− Laissé pour demain</td>${td(x => x.aberrant ? '<span class="tag-stop">impossible</span>' : (x.enCours ? '<span class="tag-warn">en cours</span>' : eur(x.laisse) + (x.corrige ? '<br><small class="nul">virgule remise</small>' : '')))}<td class="sem"><span class="nul">—</span></td></tr>
    <tr class="cle"><td>= Sorti du tiroir sans trace<small>ni versement ni retrait enregistré</small></td>${td(x => x.sorties == null ? '<span class="tag-stop">non calculable</span>' : eur(x.sorties))}<td class="sem">${eur(tot(x => x.sorties))}<br><small class="nul">${nCalc} j. calculables</small></td></tr>
    <tr class="sep"><td colspan="9">Ce que les équipes déclarent à la fermeture</td></tr>
    <tr><td>Total déclaré<small>final_cash, toutes les caisses</small></td>${td(x => !x.caisses.length ? '<span class="nul">—</span>' : (x.declare === 0 ? '<span class="tag-warn">0,00 €</span>' : eur(x.declare)))}<td class="sem">${eur(tot(x => x.declare))}</td></tr>
    <tr><td>… en % de la recette</td>${td(x => x.declare && x.recette ? pct(x.declare, x.recette) + ' %' : '<span class="nul">—</span>')}<td class="sem"><span class="nul">—</span></td></tr>
    </tbody></table>`;
  }
  function constats(SEM, PTS) {
    const n = k => PTS.filter(p => p.k === k).length;
    const recette = SEM.reduce((a, x) => a + (x.recette || 0), 0), especes = SEM.reduce((a, x) => a + (x.especes || 0), 0);
    const vers = SEM.reduce((a, x) => a + (x.versements || 0), 0);
    const calc = SEM.filter(x => x.sorties != null), sorties = calc.reduce((a, x) => a + x.sorties, 0);
    const decl = SEM.filter(x => x.declare > 0 && x.recette).map(x => pct(x.declare, x.recette));
    const partEsp = recette ? 100 * especes / recette : null;
    const l = [];
    if (n('rupture')) { l.push(`<b>Le fond de caisse n’est pas tenu</b> : ${n('rupture')} report${n('rupture') > 1 ? 's' : ''} rompu${n('rupture') > 1 ? 's' : ''} — le fond du matin ne suit pas ce qui a été laissé la veille.`); }
    if (decl.length && partEsp != null && Math.min(...decl) > 2 * partEsp) {
      l.push(`<b>« Déclaré » n’est pas l’espèce comptée</b> : il fait ${Math.min(...decl)} à ${Math.max(...decl)} % de la recette, quand les espèces n’en font que ${Math.round(partEsp)} %. Les équipes reportent un total de caisse, pas le comptage du tiroir.`);
    }
    if (n('final0')) { l.push(`<b>${n('final0')} fermeture${n('final0') > 1 ? 's' : ''} sans montant</b> (0,00 € déclaré).`); }
    if (calc.length && sorties > 0) {
      l.push(vers === 0
        ? `<b>Les espèces partent sans trace</b> : ${eur(sorties)} sortis des tiroirs sur ${calc.length} jour${calc.length > 1 ? 's' : ''}, sans un versement ni un dépôt enregistré.`
        : `<b>${eur(vers)} de versements enregistrés</b> ; ${eur(sorties)} restent sortis des tiroirs sans trace sur ${calc.length} jour${calc.length > 1 ? 's' : ''}.`);
    }
    const vir = PTS.filter(p => p.k === 'aberrant' && p.corrige != null), imp = PTS.filter(p => p.k === 'aberrant' && p.corrige == null);
    if (vir.length) { l.push(`<b>${vir.length} montant${vir.length > 1 ? 's' : ''} laissé${vir.length > 1 ? 's' : ''} tapé${vir.length > 1 ? 's' : ''} sans virgule</b> : le service suivant ouvre au centième près (15 566 → 155,66 €). La chaîne suit une fois la virgule remise ; c'est la saisie du soir qui est à reprendre.`); }
    if (imp.length) { l.push(`<b>${imp.length} montant${imp.length > 1 ? 's' : ''} laissé${imp.length > 1 ? 's' : ''} impossible${imp.length > 1 ? 's' : ''}</b> — ${[...new Set(imp.map(p => jc(p.j)))].join(', ')}.`); }
    if (n('nuit') || n('reouv')) { l.push(`<b>${n('nuit') + n('reouv')} caisse${n('nuit') + n('reouv') > 1 ? 's' : ''} fermée${n('nuit') + n('reouv') > 1 ? 's' : ''} puis rouverte${n('nuit') + n('reouv') > 1 ? 's' : ''}</b>, dont ${n('nuit')} après la fermeture du magasin.`); }
    if (n('ouvert')) { l.push(`<b>${n('ouvert')} service${n('ouvert') > 1 ? 's' : ''} jamais fermé${n('ouvert') > 1 ? 's' : ''}.</b>`); }
    return l.length ? `<ul class="cst">${l.map(x => `<li>${x}</li>`).join('')}</ul>` : '<p class="muted">Rien à signaler sur cette semaine.</p>';
  }
  function vueSemaine() {
    const D = S.D;
    const SEM = D.jours.map(jourMagasin);
    const PTS = tousLesPoints();
    const recette = SEM.reduce((a, x) => a + (x.recette || 0), 0), especes = SEM.reduce((a, x) => a + (x.especes || 0), 0);
    const vers = SEM.reduce((a, x) => a + (x.versements || 0), 0);
    const calc = SEM.filter(x => x.sorties != null), sorties = calc.reduce((a, x) => a + x.sorties, 0);
    const passes = D.jours.filter(j => j <= AUJ).length;
    const nStop = PTS.filter(p => p.g === 'stop').length;
    const n = k => PTS.filter(p => p.k === k).length;
    const nVir = PTS.filter(p => p.k === 'aberrant' && p.corrige != null).length, nImp = n('aberrant') - nVir;
    if (!D.caisses.length) {
      return `<div class="ca-msg">Aucun service de caisse ouvert sur cette semaine pour ce magasin.</div>`;
    }
    return `
    <div class="kpi-row">
      <div class="kpi"><div class="kpi__value">${eur0(recette)}</div><div class="kpi__label">Recette de la semaine</div><div class="kpi__note">export comptable · ${passes} jour${passes > 1 ? 's' : ''}</div></div>
      <div class="kpi"><div class="kpi__value">${eur(especes)}${recette ? `<small>${nf(100 * especes / recette, 1)} %</small>` : ''}</div><div class="kpi__label">Espèces encaissées</div><div class="kpi__note">le reste en carte et titres-repas</div></div>
      <div class="kpi ${vers ? 'ok' : 'stop'}"><div class="kpi__value">${eur(vers)}</div><div class="kpi__label">Versements · dépôts</div><div class="kpi__note">${vers ? 'enregistrés dans l’export' : 'aucun enregistré'}</div></div>
      <div class="kpi ${sorties > 0 ? 'stop' : 'ok'}"><div class="kpi__value">${eur(sorties)}</div><div class="kpi__label">Sorti du tiroir sans trace</div><div class="kpi__note">${calc.length} jour${calc.length > 1 ? 's' : ''} calculable${calc.length > 1 ? 's' : ''} sur ${passes}</div></div>
      <div class="kpi ${PTS.length ? 'warn' : 'ok'}"><div class="kpi__value">${PTS.length}${nStop ? `<small>dont ${nStop} à corriger</small>` : ''}</div><div class="kpi__label">Points à vérifier</div><div class="kpi__note">${n('rupture')} reports rompus · ${n('final0')} fermetures à 0${nVir ? ' · ' + nVir + ' virgule' + (nVir > 1 ? 's' : '') + ' oubliée' + (nVir > 1 ? 's' : '') : ''}${nImp ? ' · ' + nImp + ' montant' + (nImp > 1 ? 's' : '') + ' impossible' + (nImp > 1 ? 's' : '') : ''}</div></div>
    </div>
    <section class="blk">
      <div class="blk__hd"><h2>La chaîne des reports, caisse par caisse</h2>
        <p>Chaque soir, ce qui est <b>laissé</b> dans le tiroir doit se retrouver le lendemain comme <b>fond</b> d’ouverture. Entre deux jours : ✓ le fond suit, ✗ la chaîne est rompue. Un clic sur un jour l’ouvre en détail.</p></div>
      <div class="ch-scroll">${D.caisses.map(chaine).join('')}</div>
    </section>
    <section class="card blk mg-card"><div class="ch-scroll">${tableMagasin(SEM)}</div></section>
    <div class="cols3">
      <section class="card"><h3>Ce qu’il faut regarder</h3><p class="sous">${PTS.length ? PTS.length + ' point' + (PTS.length > 1 ? 's' : '') + ' sur la semaine, dans l’ordre des jours.' : 'Aucun point sur la semaine.'}</p>
        ${PTS.length ? `<ul class="lst">${PTS.map(p => `<li><span class="q">${jc(p.j)} · ${esc(p.nom)}</span><span><span class="tag-${p.g}">${p.g === 'stop' ? 'À corriger' : 'À vérifier'}</span> ${esc(p.t)}</span><button class="lien" data-act="ouvrir" data-j="${p.j}">ouvrir le jour ›</button></li>`).join('')}</ul>` : ''}</section>
      <section class="card"><h3>Ce que la semaine dit</h3><p class="sous">Lu dans les chiffres ci-contre.</p>${constats(SEM, PTS)}
        <p class="src">Sources : ${esc(D.sources.services)} ; ${esc(D.sources.comptable)}. Retraits : ${esc(D.sources.retraits)}. Lu le ${esc(String(D.lu).slice(0, 16).replace('T', ' à '))}.</p></section>
    </div>`;
  }

  /* ================================ LE JOUR ======================================= */
  function timeline(j) {
    const H0 = 4, H1 = 32, w = h => (100 * (Math.min(H1, Math.max(H0, h)) - H0) / (H1 - H0)).toFixed(2) + '%';
    const hh = ts => { const d = ts.slice(0, 10) > j ? 24 : 0; return d + (+ts.slice(11, 13)) + (+ts.slice(14, 16)) / 60; };
    const maintenant = j === AUJ ? (() => { const d = new Date(); return d.getHours() + d.getMinutes() / 60; })() : H1;
    const ax = [4, 6, 8, 10, 12, 14, 16, 18, 20, 22, 24, 26, 28, 30, 32].map(h => `<span style="left:${w(h)}">${String(h % 24).padStart(2, '0')}h</span>`).join('');
    const gr = [6, 8, 10, 12, 14, 16, 18, 20, 22, 24, 26, 28, 30].map(h => `<i class="g" style="left:${w(h)}"></i>`).join('');
    return `<div class="tl"><div class="tl__ax">${ax}</div>
    ${S.D.caisses.map(c => {
      const l = svc(c.id, j);
      return `<div class="tl__r"><div class="tl__n">${esc(c.nom)}<small>caisse ${c.id}</small></div><div class="tl__t">${gr}
        ${l.map(s => {
          const a = hh(s.ouv), b = s.ferm ? hh(s.ferm) : maintenant, cls = estNuit(s) ? ' n' : (!s.ferm && j < ajoute(AUJ, -1) ? ' o' : '');
          return `<span class="b${cls}" style="left:${w(a)};width:calc(${w(b)} - ${w(a)})" title="${esc(hm(s.ouv) + ' → ' + hm(s.ferm))}">${hm(s.ouv)} → ${s.ferm ? hm(s.ferm) : 'en cours'}${s.ferm && s.ferm.slice(0, 10) > j ? ' (' + jc(s.ferm.slice(0, 10)) + ')' : ''} · fond ${esc(eur(s.fond))}</span>`;
        }).join('') || '<span class="nul" style="position:absolute;top:9px;left:8px;font-size:11px">aucun service</span>'}
      </div></div>`;
    }).join('')}</div>`;
  }
  function ficheCaisse(c, j) {
    const x = jourCaisse(c.id, j);
    if (!x) { return `<section class="card"><h3>${esc(c.nom)}</h3><p class="sous">caisse ${c.id} · aucun service ce jour-là</p></section>`; }
    const lend = svc(c.id, ajoute(j, 1))[0];
    const rupt = x.pts.find(p => p.k === 'rupture');
    const L = [];
    L.push(`<span class="h t1">${jcm(ajoute(j, -1))}</span><span class="t1">Laissé la veille au soir</span><span class="m t1">${eur(x.report)}</span>`);
    x.l.forEach((s, i) => {
      const nuit = estNuit(s);
      L.push(`<span class="h">${hm(s.ouv)}</span><span>${nuit ? '<span class="tag-warn">Rouverte après la fermeture</span> ' : ''}Ouverture · ${esc(s.parOuv)}</span><span class="m${i === 0 && rupt ? ' x' : ''}">fond ${eur(s.fond)}</span>`);
      if (i === 0 && x.report != null) {
        L.push(`<span class="note">${rupt ? `<span class="tag-stop">${esc(rupt.t)}</span>`
          : (x.virgule ? `<span class="tag-warn">≈ suit le laissé de la veille, virgule remise (${esc(eur(x.report))} → ${esc(eur(x.prem.fond))})</span>` : '<span class="tag-ok">✓ suit le laissé de la veille</span>')}</span>`);
      }
      if (!s.ferme) {
        L.push(`<span class="h"></span><span>Pas encore fermée</span><span class="m">${j < ajoute(AUJ, -1) ? '<span class="tag-stop">jamais fermée</span>' : '<span class="tag-warn">en cours</span>'}</span>`);
        return;
      }
      L.push(`<span class="h">${hm(s.ferm)}${s.ferm && s.ferm.slice(0, 10) > j ? ' <small>' + jc(s.ferm.slice(0, 10)) + '</small>' : ''}</span><span>Fermeture · ${esc(s.parFerm)} — total déclaré</span><span class="m${s.final === 0 ? ' z' : ''}">${eur(s.final)}</span>`);
      L.push(`<span class="h"></span><span>Laissé pour le lendemain</span><span class="m${s.laisse > ABERRANT ? ' x' : ''}">${eur(s.laisse)}</span>`);
      const k = causeLaisse(s);
      if (k) {
        L.push(`<span class="note"><span class="tag-stop">${esc(k.t)}${k.corrige == null ? '. Attendu : le fond' + (x.prem.fond ? ', ' + esc(eur(x.prem.fond)) : '') + '.' : '.'}</span></span>`);
      }
      if (nuit) { L.push(`<span class="note"><span class="tag-warn">Ouverte ${hm(s.ouv)} → ${hm(s.ferm)} le lendemain, fond ${esc(eur(s.fond))}, ${s.final ? esc(eur(s.final)) + ' déclarés' : 'rien déclaré'} : qui, et pourquoi ?</span></span>`); }
    });
    if (lend) {
      const ok = lend.fond === x.dernier.laisse, vi = !ok && x100(x.dernier.laisse, lend.fond);
      L.push(`<span class="h">${jcm(lend.jour)}</span><span>Ouverture du lendemain · ${esc(lend.parOuv)}</span><span class="m${ok || vi ? '' : ' x'}">fond ${eur(lend.fond)}</span>`);
      L.push(`<span class="note">${ok ? '<span class="tag-ok">✓ suit</span>' : (vi ? '<span class="tag-warn">≈ suit, une fois la virgule remise</span>' : `<span class="tag-stop">Ne suit pas le laissé (${esc(eur(x.dernier.laisse))})</span>`)}</span>`);
    }
    const g = x.pts.some(p => p.g === 'stop') ? 'tag-stop' : 'tag-warn';
    return `<section class="card"><h3>${esc(c.nom)} ${x.pts.length ? `<span class="${g}" style="margin-left:6px">${x.pts.length} point${x.pts.length > 1 ? 's' : ''}</span>` : '<span class="tag-ok" style="margin-left:6px">rien à signaler</span>'}</h3>
      <p class="sous">caisse ${c.id} · ${x.l.length} service${x.l.length > 1 ? 's' : ''} ce jour-là</p><div class="fiche">${L.join('')}</div></section>`;
  }
  /* --- les espèces, ticket par ticket (GET /caisse/jour) ------------------------------ */
  const COUL = ['#B03048', '#1B6FA8', '#3f7a52', '#B0792A'];
  function lireJour() {
    const k = S.shop + '|' + S.jour;
    if (!S.jour || S.jour > AUJ || S.T[k] || S.Tcours[k] || S.Terr[k]) { return; }
    S.Tcours[k] = true;
    const relire = S.Trelire; S.Trelire = false;
    lire('/caisse/jour?shop=' + encodeURIComponent(S.shop) + '&date=' + S.jour + (relire ? '&rafraichir=1' : ''))
      .then(d => { S.T[k] = d; })
      .catch(e => { S.Terr[k] = e.message; })
      .finally(() => { delete S.Tcours[k]; if (S.vue === 'jour') { rendre(); } });
  }
  const hDe = ts => { const h = String(ts).slice(11, 19); return (+h.slice(0, 2)) + (+h.slice(3, 5)) / 60 + (+h.slice(6, 8)) / 3600; };
  /* La courbe du tiroir : une ligne en escalier par caisse, du fond d'ouverture à la dernière vente. */
  function courbeTiroir(series, h0, h1, ymax) {
    const W = 1000, H = 190, ml = 52, mr = 12, mt = 10, mb = 22;
    const x = h => ml + (W - ml - mr) * (Math.min(h1, Math.max(h0, h)) - h0) / (h1 - h0);
    const y = v => mt + (H - mt - mb) * (1 - (v / (ymax || 1)));
    const gx = [];
    for (let h = Math.ceil(h0); h <= Math.floor(h1); h += 2) { gx.push(`<line x1="${x(h)}" y1="${mt}" x2="${x(h)}" y2="${H - mb}" stroke="#e6e0d8" stroke-dasharray="3 3"/><text x="${x(h)}" y="${H - 6}" text-anchor="middle" font-size="11" fill="#7a736a">${String(h).padStart(2, '0')}h</text>`); }
    const pas = ymax > 2000 ? 1000 : ymax > 800 ? 200 : ymax > 300 ? 100 : 50;
    const gy = [];
    for (let v = 0; v <= ymax; v += pas) { gy.push(`<line x1="${ml}" y1="${y(v)}" x2="${W - mr}" y2="${y(v)}" stroke="#e6e0d8"/><text x="${ml - 6}" y="${y(v) + 4}" text-anchor="end" font-size="11" fill="#7a736a">${nf(v, 0)} €</text>`); }
    const lignes = series.map(s => {
      let d = `M${x(s.debut)},${y(s.fond)}`, v = s.fond;
      s.pts.forEach(p => { d += ` H${x(p.h)} V${y(p.v)}`; v = p.v; });
      d += ` H${x(s.fin)}`;
      const pts = s.pts.map(p => `<circle cx="${x(p.h)}" cy="${y(p.v)}" r="2.4" fill="${s.coul}"><title>${esc(p.titre)}</title></circle>`).join('');
      return `<path d="${d}" fill="none" stroke="${s.coul}" stroke-width="2"/>${pts}`;
    }).join('');
    return `<svg viewBox="0 0 ${W} ${H}" width="100%" role="img" aria-label="Les espèces dans le tiroir au fil de la journée">${gy.join('')}${gx.join('')}${lignes}</svg>`;
  }
  function especesTickets(j) {
    const k = S.shop + '|' + j, T = S.T[k];
    const tete = `<h3>Les espèces, ticket par ticket</h3>`;
    if (S.Terr[k]) { return `<section class="card blk">${tete}<div class="ca-msg err">${esc(S.Terr[k])}</div></section>`; }
    if (!T) { return `<section class="card blk">${tete}<p class="sous">Lecture des tickets du jour chez TFBuddy…</p></section>`; }
    const E = T.especes || {};
    const calage = { exact: 'calé au centime sur l’export comptable', 'approché': 'calé à 2 % près sur l’export comptable', appris: 'repris de la correspondance apprise', introuvable: 'introuvable' }[E.calage] || E.calage;
    if (E.type == null) {
      return `<section class="card blk">${tete}<div class="ca-msg warn">Le mode de paiement « espèces » n’a pas pu être identifié ce jour-là : aucun total de tickets ne correspond à la ligne « Espèce » de l’export comptable${E.totalExport != null ? ' (' + esc(eur(E.totalExport)) + ')' : ''}.</div>
        <p class="src">Totaux par type de paiement : ${(T.paiements || []).map(p => `type ${p.id} · ${p.n} ticket${p.n > 1 ? 's' : ''} · ${esc(eur(p.total))}${p.nom ? ' (' + esc(p.nom) + ')' : ''}`).join(' ; ')}.</p></section>`;
    }
    // Le tiroir de chaque caisse : son fond d'ouverture, puis chaque ticket en espèces.
    const series = [], cumul = {};
    let h0 = 6, h1 = 19, ymax = 0;
    S.D.caisses.forEach((c, i) => {
      const x = jourCaisse(c.id, j);
      const tks = T.tickets.filter(t => t.caisse === c.id);
      if (!x && !tks.length) { return; }
      const fond = x ? (x.prem.fond || 0) : 0;
      const debut = x && x.prem.ouv ? hDe(x.prem.ouv) : (tks.length ? hDe(tks[0].heure) : 6);
      const finS = x && x.dernierJour.ferm && x.dernierJour.ferm.slice(0, 10) === j ? hDe(x.dernierJour.ferm) : null;
      let v = fond;
      const pts = tks.map(t => { v += t.montant; cumul[t.id] = v; return { h: hDe(t.heure), v, titre: t.heure.slice(11, 16) + ' · ' + (t.vendeuse || '') + ' · ' + eur(t.montant) + ' → ' + eur(v) }; });
      const fin = finS != null ? finS : (pts.length ? pts[pts.length - 1].h : debut);
      h0 = Math.min(h0, Math.floor(debut)); h1 = Math.max(h1, Math.ceil(fin));
      ymax = Math.max(ymax, fond, ...pts.map(p => p.v));
      series.push({ id: c.id, nom: c.nom, coul: COUL[i % COUL.length], fond, debut, fin, pts, total: v - fond, n: tks.length });
    });
    ymax = Math.ceil((ymax || 50) * 1.1);
    // Par vendeuse : combien de tickets en espèces, et pour combien.
    const parV = {};
    T.tickets.forEach(t => { const n = t.vendeuse || '—'; parV[n] = parV[n] || { n: 0, m: 0 }; parV[n].n++; parV[n].m += t.montant; });
    const vend = Object.entries(parV).sort((a, b) => b[1].m - a[1].m);
    const concorde = E.ecart != null && Math.abs(E.ecart) < 0.01;
    const ctrl = E.totalExport == null ? '<span class="tag-warn">export comptable illisible : total non contrôlé</span>'
      : (concorde ? `<span class="tag-ok">✓ concorde avec l’export comptable (${esc(eur(E.totalExport))})</span>` : `<span class="tag-stop">écart de ${esc(eur(E.ecart))} avec l’export comptable (${esc(eur(E.totalExport))})</span>`);
    const caisseCoul = id => (series.find(s => s.id === id) || {}).coul || '#7a736a';
    return `<section class="card blk">${tete}
      <p class="sous">${E.n} ticket${E.n > 1 ? 's' : ''} payé${E.n > 1 ? 's' : ''} en espèces sur ${T.nTickets} · ${esc(eur(E.totalTickets))} ${ctrl} · type de paiement ${esc(String(E.type))}, ${esc(calage)}.</p>
      ${E.n ? `${courbeTiroir(series, h0, h1, ymax)}
      <div class="tk-leg">${series.map(s => `<span><i style="background:${s.coul}"></i>${esc(s.nom)} : fond ${esc(eur(s.fond))} → ${esc(eur(s.fond + s.total))} attendus <small>(${s.n} ticket${s.n > 1 ? 's' : ''}, + ${esc(eur(s.total))})</small></span>`).join('')}</div>
      <div class="tk-v">${vend.map(([n, x]) => `<span class="chip chip--neutral">${esc(n)} · ${x.n} · ${esc(eur(x.m))}</span>`).join('')}</div>
      <div class="tk-scroll"><table class="tk"><thead><tr><th>Heure</th><th>Caisse</th><th>Vendeuse</th><th>Ticket</th><th class="num">Montant</th><th class="num">Dans le tiroir</th></tr></thead><tbody>
        ${T.tickets.map(t => `<tr><td>${esc(t.heure.slice(11, 19))}</td><td><i class="pt" style="background:${caisseCoul(t.caisse)}"></i>${esc(t.caisseNom || '—')}</td><td>${esc(t.vendeuse || '—')}</td><td class="nul">#${t.id}${t.pro ? ' <span class="tag-warn">pro</span>' : ''}${t.corrige ? ' <span class="tag-warn">corrigé</span>' : ''}</td><td class="num${t.montant < 0 ? ' neg' : ''}">${esc(eur(t.montant))}</td><td class="num">${cumul[t.id] != null ? esc(eur(cumul[t.id])) : '—'}</td></tr>`).join('')}
      </tbody></table></div>
      <p class="src">« Dans le tiroir » : le fond d’ouverture de la caisse plus les espèces encaissées jusqu’à ce ticket. Les retraits, que TFBuddy ne relit pas, n’y sont pas.</p>` : '<p class="muted">Aucun ticket payé en espèces ce jour-là.</p>'}
    </section>`;
  }
  function vueJour() {
    const D = S.D, j = S.jour;
    if (j > AUJ) { return `<div class="ca-msg">Ce jour n’est pas encore passé.</div>`; }
    lireJour();   // les tickets en espèces : lus à part, la journée s'affiche sans les attendre
    const m = jourMagasin(j), cf = m.cf;
    const pts = tousLesPoints().filter(p => p.j === j);
    const nav = `<div class="jnav">${D.jours.map(x => { const n = tousLesPoints().filter(p => p.j === x).length;
      return `<button class="filter${x === j ? ' is-on' : ''}${n && x !== j ? ' ko' : ''}" data-act="ouvrir" data-j="${x}"${x > AUJ ? ' disabled' : ''}>${jc(x)}${n ? ' · ' + n : ''}</button>`; }).join('')}</div>`;
    const moyens = (cf && cf.moyens) || [];
    const max = Math.max(1, ...moyens.map(r => r.montant || 0));
    const fondsDetail = m.caisses.map(x => eur(x.prem.fond)).join(' + ');
    const laisseTot = m.caisses.reduce((a, x) => a + (x.dernier.laisse || 0), 0);
    return `${nav}
    ${pts.length
      ? `<div class="alerte"><span>⚠</span><div><b>${pts.length} point${pts.length > 1 ? 's' : ''} à vérifier ce ${esc(jl(j))}</b><ul>${pts.map(p => `<li>${esc(p.nom)} — ${esc(p.t)}</li>`).join('')}</ul></div></div>`
      : `<div class="alerte ok"><span>✓</span><div><b>Rien à signaler ce ${esc(jl(j))}</b> — fonds, fermetures et reports se suivent.</div></div>`}
    <section class="card blk"><h3>Les services de la journée</h3><p class="sous">Une barre par service ouvert sur la caisse. Hachuré : un service ouvert après la fermeture du magasin.</p>${timeline(j)}</section>
    <div class="cols">${D.caisses.map(c => ficheCaisse(c, j)).join('')}</div>
    ${especesTickets(j)}
    <div class="cols">
      <section class="card"><h3>Les espèces, du fond du matin au fond du soir</h3><p class="sous">Le magasin, toutes les caisses ensemble.</p>
        ${cf ? `<div class="wf">
          <span class="op"></span><span>Fonds d’ouverture<span class="s">${esc(fondsDetail || '—')}, saisis à l’ouverture</span></span><span class="m">${eur(m.fonds)}</span>
          <span class="op">+</span><span>Espèces encaissées<span class="s">export comptable</span></span><span class="m">${eur(m.especes)}</span>
          <span class="op">−</span><span>Retraits<span class="s">enregistrés à la caisse, illisibles par l’API TFBuddy</span></span><span class="m na">non lisibles</span>
          <span class="op">−</span><span>Versements espèces · dépôts</span><span class="m${m.versements ? '' : ' na'}">${m.versements ? eur(m.versements) : 'aucun'}</span>
          <span class="op r">=</span><span class="r eg">Attendu dans les tiroirs au soir</span><span class="m r eg">${eur(m.fonds + (m.especes || 0) - (m.versements || 0))}</span>
          <span class="op"></span><span>Laissé pour le lendemain, déclaré</span><span class="m${m.aberrant || m.corrige ? ' x' : ''}">${m.enCours ? '<span class="tag-warn">en cours</span>' : eur(laisseTot)}</span>
          ${m.corrige && !m.aberrant && !m.enCours ? `<span class="op"></span><span>… virgule remise<span class="s">le montant qui a réellement ouvert le service suivant</span></span><span class="m">${eur(m.laisse)}</span>` : ''}
          <span class="op"></span><span>${m.sorties == null
            ? `<span class="tag-stop">Écart non calculable</span><span class="s">${m.enCours ? 'une caisse est encore ouverte.' : (m.aberrant ? 'un « laissé » est une saisie impossible.' : 'données incomplètes.')} Sans comptage réel du tiroir, la journée ne se boucle pas.</span>`
            : `Sorti du tiroir sans trace<span class="s">ni versement ni retrait enregistré</span>`}</span><span class="m${m.sorties > 0 ? ' x' : ''}">${m.sorties == null ? '' : eur(m.sorties)}</span>
        </div>` : '<div class="ca-msg warn">Export comptable illisible pour ce jour : ni recette ni espèces.</div>'}
      </section>
      <section class="card"><h3>Les moyens de paiement</h3><p class="sous">Export comptable TFBuddy, ${esc(jl(j))}.</p>
        ${cf ? `<table class="mp">${moyens.map(r => `<tr><td><span class="bar${/esp[eè]ce/i.test(r.nom) ? ' es' : ''}" style="width:${Math.max(2, Math.round(110 * (r.montant || 0) / max))}px"></span>${esc(r.nom)}</td><td class="m">${eur(r.montant)}</td></tr>`).join('')}
          <tr class="tot"><td>Recette du jour</td><td class="m">${eur(m.recette)}</td></tr>
          <tr><td>Total déclaré aux fermetures</td><td class="m">${eur(m.declare)}${m.recette && m.declare ? ` <span class="nul">· ${pct(m.declare, m.recette)} %</span>` : ''}</td></tr>
        </table>
        ${m.declare && m.recette && m.especes != null && m.declare > 2 * m.especes ? `<p class="src">Le total déclaré suit la <b>recette</b> (${pct(m.declare, m.recette)} %), pas les <b>espèces</b> (${esc(eur(m.especes))}) : <code>final_cash</code> n’est pas le comptage du tiroir.</p>` : ''}`
        : '<div class="ca-msg warn">Export comptable illisible pour ce jour.</div>'}
      </section>
    </div>`;
  }

  /* --- rendu et gestes ------------------------------------------------------------------ */
  /* ================================ LES REPORTS ======================================
   * Le registre du mois, jour après jour, sans coupure au dimanche : pour chaque
   * caisse, ce qui a été laissé la veille, le fond du matin et son lien (✓ suit,
   * ≈ virgule oubliée, ✗ rompu), le total déclaré et ce qui est laissé le soir ;
   * pour le magasin, les espèces, les versements et ce qui sort du tiroir sans
   * trace, avec son cumul. */
  /* Les ruptures du mois, une à une : le soir (ce qui a été laissé, à quelle heure,
   * par qui), le matin (le fond, à quelle heure, par qui), l'écart, la cause
   * probable et à qui poser la question. La cause se lit dans les deux montants :
   * c'est une piste, pas un verdict — le tiroir, lui, n'est pas dans TFBuddy. */
  const CAUSES = {
    apparu:     { nom: 'Fond apparu au matin', ordre: 0 },
    disparu:    { nom: 'Fond laissé, absent au matin', ordre: 1 },
    ecart:      { nom: 'Écart de comptage', ordre: 2 },
    impossible: { nom: 'Laissé impossible la veille', ordre: 3 },
    nuit:       { nom: 'Caisse rouverte la nuit', ordre: 4 },
  };
  function rupturesDuMois() {
    const D = S.D, out = [];
    D.caisses.forEach(c => D.jours.forEach(j => {
      const x = jourCaisse(c.id, j);
      if (!x || !x.pts.some(p => p.k === 'rupture')) { return; }
      const veille = svc(c.id, ajoute(j, -1));
      const s0 = veille[veille.length - 1], s1 = x.prem;
      const laisse = s0.laisse, fond = s1.fond, ecart = Math.round((fond - laisse) * 100) / 100;
      const soir = (s0.parFerm || '—') + ' (fermeture du ' + jcm(s0.jour) + ' à ' + hm(s0.ferm) + ')';
      const matin = (s1.parOuv || '—') + ' (ouverture à ' + hm(s1.ouv) + ')';
      let cause, question, demander;
      if (estNuit(s0)) {
        cause = 'nuit';
        question = 'La caisse a été rouverte à ' + hm(s0.ouv) + ' la veille et refermée le matin avec ' + eur(laisse) + ' laissés : qui l’a rouverte, et pourquoi ?';
        demander = [s0.parOuv + ' (réouverture de ' + hm(s0.ouv) + ')', matin];
      } else if (laisse > ABERRANT) {
        cause = 'impossible';
        question = 'Le soir, ' + eur(laisse) + ' ont été déclarés laissés : quel était le vrai montant ?';
        demander = [soir];
      } else if (laisse === 0 && fond > 0) {
        cause = 'apparu';
        question = 'Le fond de ' + eur(fond) + ' est-il resté dans le tiroir la nuit (la fermeture n’a pas déclaré le laissé), ou a-t-il été remis le matin ?';
        demander = [soir, matin];
      } else if (laisse > 0 && fond === 0) {
        cause = 'disparu';
        question = 'Les ' + eur(laisse) + ' laissés le soir étaient-ils dans le tiroir le matin (l’ouverture n’a pas saisi le fond), ou ont-ils été retirés pendant la nuit ?';
        demander = [matin, soir];
      } else {
        cause = 'ecart';
        question = 'Le soir ' + eur(laisse) + ', le matin ' + eur(fond) + ' : d’où vient l’écart de ' + eur(ecart) + ' ?';
        demander = [soir, matin];
      }
      out.push({ j, c, s0, s1, laisse, fond, ecart, cause, question, demander });
    }));
    // Dans l'ordre des jours, toutes caisses mêlées : c'est l'ordre d'une enquête.
    const rang = Object.fromEntries(D.caisses.map((c, i) => [c.id, i]));
    return out.sort((a, b) => a.j.localeCompare(b.j) || rang[a.c.id] - rang[b.c.id]);
  }
  function blocRuptures(R) {
    if (!R.length) { return `<section class="card blk"><h3>Les ruptures du mois</h3><p class="muted">Aucune : chaque matin, le fond suit ce qui a été laissé la veille.</p></section>`; }
    const parCause = {};
    R.forEach(r => { parCause[r.cause] = (parCause[r.cause] || 0) + 1; });
    // Qui revient le plus : au soir (fermeture) et au matin (ouverture).
    const pers = {};
    R.forEach(r => {
      const f = r.s0.parFerm || '—', o = r.s1.parOuv || '—';
      pers[f] = pers[f] || { soir: 0, matin: 0 }; pers[f].soir++;
      pers[o] = pers[o] || { soir: 0, matin: 0 }; pers[o].matin++;
    });
    const qui = Object.entries(pers).sort((a, b) => (b[1].soir + b[1].matin) - (a[1].soir + a[1].matin));
    const ecartNet = R.reduce((a, r) => a + (r.cause === 'impossible' ? 0 : r.ecart), 0);
    // Ce qui revient : la même cause, la même fermeture, la même ouverture.
    const motifs = {};
    R.forEach(r => { const k = r.cause + '|' + (r.s0.parFerm || '—') + '|' + (r.s1.parOuv || '—'); motifs[k] = (motifs[k] || 0) + 1; });
    const [mk, mn] = Object.entries(motifs).sort((a, b) => b[1] - a[1])[0] || ['', 0];
    const [mc, mf, mo] = mk.split('|');
    const revient = mn >= 3 ? `<p class="rp-motif">Ce qui revient le plus : <b>${esc(CAUSES[mc].nom.toLowerCase())}</b>, fermeture par <b>${esc(mf)}</b> puis ouverture par <b>${esc(mo)}</b> — <b>${mn} fois</b> sur ${R.length}.${mc === 'apparu' ? ' Le fond reste vraisemblablement dans le tiroir la nuit sans être déclaré comme laissé à la fermeture.' : (mc === 'disparu' ? ' Le fond laissé n’est vraisemblablement pas saisi à l’ouverture.' : '')}</p>` : '';
    return `<section class="card blk"><h3>Les ruptures du mois, une à une</h3>
      <p class="sous">${R.length} rupture${R.length > 1 ? 's' : ''} : le fond du matin ne suit pas ce qui a été laissé la veille. La cause est une piste lue dans les deux montants, pas un verdict : seul le tiroir le dit. Écart cumulé : ${esc(eur(ecartNet))} (un fond apparu compte en plus, un fond disparu en moins).</p>
      ${revient}
      <div class="rp-res">
        <div><div class="section-label">Par cause</div><div class="tk-v">${Object.keys(parCause).sort((a, b) => CAUSES[a].ordre - CAUSES[b].ordre).map(k => `<span class="chip chip--neutral">${esc(CAUSES[k].nom)} · ${parCause[k]}</span>`).join('')}</div></div>
        <div><div class="section-label">Qui revient le plus</div><div class="tk-v">${qui.slice(0, 8).map(([n, v]) => `<span class="chip chip--neutral">${esc(n)} · ${v.soir ? v.soir + ' fermeture' + (v.soir > 1 ? 's' : '') : ''}${v.soir && v.matin ? ', ' : ''}${v.matin ? v.matin + ' ouverture' + (v.matin > 1 ? 's' : '') : ''}</span>`).join('')}</div></div>
      </div>
      <div class="ch-scroll"><table class="mg rp-r">
        <thead><tr><th>Jour</th><th>Caisse</th><th>Le soir · laissé</th><th>Le matin · fond</th><th>Écart</th><th>Cause probable · question</th><th></th></tr></thead>
        <tbody>${R.map(r => `<tr>
          <td><b>${jc(r.j)}</b></td>
          <td>${esc(r.c.nom)}</td>
          <td><b>${esc(eur(r.laisse))}</b><small>${esc(jc(r.s0.jour))} ${hm(r.s0.ferm)} · ${esc(r.s0.parFerm || '—')}</small></td>
          <td><b>${esc(eur(r.fond))}</b><small>${hm(r.s1.ouv)} · ${esc(r.s1.parOuv || '—')}</small></td>
          <td class="${r.ecart > 0 ? 'pl' : 'x'}">${r.cause === 'impossible' ? '<span class="nul">—</span>' : (r.ecart > 0 ? '+ ' : '− ') + esc(eur(Math.abs(r.ecart)))}</td>
          <td><span class="tag-${r.cause === 'ecart' || r.cause === 'impossible' ? 'stop' : 'warn'}">${esc(CAUSES[r.cause].nom)}</span> ${esc(r.question)}<small>À demander : ${r.demander.map(esc).join(' ; ')}</small></td>
          <td><button class="lien" data-act="ouvrir" data-j="${r.j}">ouvrir le jour ›</button></td>
        </tr>`).join('')}</tbody>
      </table></div></section>`;
  }
  function vueReports() {
    const D = S.D;
    if (!D.caisses.length) { return `<div class="ca-msg">Aucun service de caisse sur ce mois pour ce magasin.</div>`; }
    const jours = D.jours;
    let cumul = 0, nCalc = 0;
    const nAVoir = jours.filter(j => tousLesPoints().some(p => p.j === j)).length;
    const lignes = jours.filter(j => !S.rpFiltre || tousLesPoints().some(p => p.j === j)).map(j => {
      const m = jourMagasin(j);
      const pts = tousLesPoints().filter(p => p.j === j);
      if (m.sorties != null) { cumul += m.sorties; nCalc++; }
      const cel = D.caisses.map(c => {
        const x = jourCaisse(c.id, j);
        if (!x) { return `<td class="cs"><span class="nul">—</span></td><td></td><td></td><td></td>`; }
        const rupt = x.pts.some(p => p.k === 'rupture');
        const lien = x.report == null ? '' : (rupt ? '<i class="ko" title="report rompu">✗</i>' : (x.virgule ? '<i class="vi" title="virgule oubliée la veille">≈</i>' : '<i class="ok" title="le fond suit">✓</i>'));
        const decl = x.journee.reduce((a, s) => a + (s.final || 0), 0);
        const d = x.dernierJour, k = causeLaisse(x.dernier);
        const laisse = x.enCours ? '<span class="tag-warn">en cours</span>'
          : `<span class="${d.laisse > ABERRANT ? 'x' : ''}">${eur(x.dernier.laisse)}</span>${k && k.corrige != null ? `<small>→ ${eur(k.corrige)}</small>` : ''}`;
        return `<td class="cs">${eur(x.report)}</td><td class="rp-f${rupt ? ' x' : ''}">${lien}${eur(x.prem.fond)}</td><td class="${decl === 0 && d.ferme ? 'z' : ''}">${d.ferme || decl ? eur(decl) : '—'}</td><td>${laisse}</td>`;
      }).join('');
      const g = pts.some(p => p.g === 'stop') ? 'stop' : (pts.length ? 'warn' : '');
      return `<tr class="rp-l ${g}" data-act="ouvrir" data-j="${j}" title="Ouvrir le ${esc(jl(j))}">
        <td class="rp-j"><b>${jc(j)}</b>${dt(j).getUTCDay() === 1 ? '<small>sem.</small>' : ''}</td>${cel}
        <td class="cs">${m.cf ? eur(m.especes) : '<span class="tag-warn">illisible</span>'}</td>
        <td>${m.versements ? eur(m.versements) : '<span class="nul">—</span>'}</td>
        <td class="${m.sorties > 0 ? 'x' : ''}">${m.sorties == null ? '<span class="nul">n.c.</span>' : eur(m.sorties)}</td>
        <td>${m.sorties == null ? '' : eur(cumul)}</td>
        <td class="rp-p">${pts.length ? `<span class="tag-${g}" title="${esc(pts.map(p => p.nom + ' — ' + p.t).join('\n'))}">${pts.length}</span>` : ''}</td>
      </tr>`;
    }).join('');
    const PTS = tousLesPoints();
    const n = k => PTS.filter(p => p.k === k).length;
    const nVir = PTS.filter(p => p.k === 'aberrant' && p.corrige != null).length;
    const SEM = jours.map(jourMagasin);
    const esp = SEM.reduce((a, x) => a + (x.especes || 0), 0), vers = SEM.reduce((a, x) => a + (x.versements || 0), 0);
    const liens = D.caisses.reduce((a, c) => a + jours.filter(j => { const x = jourCaisse(c.id, j); return x && x.report != null; }).length, 0);
    const suivis = D.caisses.reduce((a, c) => a + jours.filter(j => { const x = jourCaisse(c.id, j); return x && x.report != null && !x.pts.some(p => p.k === 'rupture'); }).length, 0);
    return `
    <div class="kpi-row">
      <div class="kpi ${n('rupture') ? 'stop' : 'ok'}"><div class="kpi__value">${suivis}<small>/ ${liens}</small></div><div class="kpi__label">Reports qui suivent</div><div class="kpi__note">${n('rupture')} rompu${n('rupture') > 1 ? 's' : ''}${nVir ? ' · ' + nVir + ' virgule' + (nVir > 1 ? 's' : '') + ' oubliée' + (nVir > 1 ? 's' : '') : ''}</div></div>
      <div class="kpi"><div class="kpi__value">${eur(esp)}</div><div class="kpi__label">Espèces encaissées</div><div class="kpi__note">${jours.length} jour${jours.length > 1 ? 's' : ''} · export comptable</div></div>
      <div class="kpi ${vers ? 'ok' : 'stop'}"><div class="kpi__value">${eur(vers)}</div><div class="kpi__label">Versements · dépôts</div><div class="kpi__note">${vers ? 'enregistrés dans l’export' : 'aucun enregistré sur le mois'}</div></div>
      <div class="kpi ${cumul > 0 ? 'stop' : 'ok'}"><div class="kpi__value">${eur(cumul)}</div><div class="kpi__label">Sorti du tiroir sans trace</div><div class="kpi__note">${nCalc} jour${nCalc > 1 ? 's' : ''} calculable${nCalc > 1 ? 's' : ''} sur ${jours.length}</div></div>
      <div class="kpi ${PTS.length ? 'warn' : 'ok'}"><div class="kpi__value">${PTS.length}</div><div class="kpi__label">Points à vérifier</div><div class="kpi__note">${n('final0')} fermetures à 0 · ${n('nuit') + n('reouv')} réouvertures</div></div>
    </div>
    <div class="rp-outils"><button class="filter${S.rpFiltre ? ' is-on' : ''}" data-act="rpFiltre">Seulement les jours à vérifier · ${nAVoir}</button><a class="lien" href="#ruptures">Les ruptures, une à une ↓</a></div>
    <section class="card blk mg-card"><div class="ch-scroll"><table class="mg rp">
      <thead>
        <tr class="rp-g"><th></th>${D.caisses.map(c => `<th colspan="4" class="cs">${esc(c.nom)} <small>caisse ${c.id}</small></th>`).join('')}<th colspan="5" class="cs">Le magasin</th></tr>
        <tr><th>Jour</th>${D.caisses.map(() => '<th class="cs">Laissé la veille</th><th>Fond du matin</th><th>Déclaré</th><th>Laissé le soir</th>').join('')}<th class="cs">Espèces</th><th>Versements</th><th>Sorti sans trace</th><th>Cumul</th><th>Points</th></tr>
      </thead>
      <tbody>${lignes}</tbody>
    </table></div></section>
    <p class="src">Une ligne par jour, du 1er au ${jours.length ? jc(jours[jours.length - 1]) : '—'}. ✓ le fond du matin suit ce qui a été laissé la veille ; ≈ il le suit une fois la virgule remise ; ✗ la chaîne est rompue. « → » sous un laissé : le montant corrigé, lu à l’ouverture suivante. Un clic sur une ligne ouvre la journée.</p>
    <div id="ruptures">${blocRuptures(rupturesDuMois())}</div>`;
  }
  function rendre() {
    let corps;
    if (S.err) { corps = `<div class="ca-msg err">${esc(S.err)}</div>`; }
    else if (S.enCours && !S.D) { corps = S.vue === 'reports'
      ? `<div class="ca-msg">Lecture du mois chez TFBuddy, semaine par semaine : ${esc(S.prog || '')}… Une semaine jamais ouverte prend quelques secondes ; les journées closes sont ensuite gardées.</div>`
      : `<div class="ca-msg">Lecture de TFBuddy… La première lecture d’une semaine prend quelques secondes ; les journées closes sont ensuite gardées.</div>`; }
    else if (!S.D) { corps = ''; }
    else {
      const lac = (S.D.lacunes || []).filter(l => l.jour <= AUJ);
      corps = (S.enCours ? `<div class="ca-msg">Lecture de TFBuddy…</div>` : '')
        + (lac.length ? `<div class="ca-msg warn">Lecture incomplète : ${lac.map(l => esc(jc(l.jour) + ' — ' + l.quoi)).join(' · ')}. <button class="lien" data-act="relire">Relire</button></div>` : '')
        + (S.vue === 'jour' ? vueJour() : (S.vue === 'reports' ? vueReports() : vueSemaine()));
    }
    $.innerHTML = barre() + `<main class="ca-body">${corps}</main>`;
  }
  $.addEventListener('click', e => {
    const b = e.target.closest('[data-act]');
    if (!b || b.tagName === 'SELECT' || b.tagName === 'INPUT') { return; }
    const a = b.dataset.act;
    if (a === 'vue') {
      const avant = S.vue; S.vue = b.dataset.v;
      if (S.vue === 'reports') { if (avant !== 'reports') { S.mois = (avant === 'jour' && S.jour ? S.jour : S.date).slice(0, 7); } charger(false); return; }
      // Sortir des reports : le jeu chargé est le mois, il faut la semaine.
      if (avant === 'reports') { if (S.vue === 'jour' && (!S.jour || S.jour.slice(0, 7) !== S.mois)) { S.jour = null; } S.date = S.jour || (S.mois + '-01' <= AUJ ? S.mois + '-01' : AUJ); charger(false); return; }
      if (S.vue === 'jour') { caleJour(); } url(); rendre();
    }
    else if (a === 'ouvrir') {
      const j = b.dataset.j;
      if (S.vue === 'reports') { S.vue = 'jour'; S.jour = j; S.date = j; charger(false); window.scrollTo(0, 0); return; }
      S.vue = 'jour'; S.jour = j; url(); rendre(); window.scrollTo(0, 0);
    }
    else if (a === 'rpFiltre') { S.rpFiltre = !S.rpFiltre; rendre(); }
    else if (a === 'relire') { const k = S.shop + '|' + S.jour; delete S.T[k]; delete S.Terr[k]; S.Trelire = S.vue === 'jour'; charger(true); }
    else if (a === 'pas') {
      const n = +b.dataset.n;
      if (S.vue === 'reports') {
        const d = new Date(Date.UTC(+S.mois.slice(0, 4), +S.mois.slice(5, 7) - 1 + n, 1));
        const m = iso(d).slice(0, 7);
        if (m > AUJ.slice(0, 7)) { return; }
        S.mois = m; charger(false);
      } else if (S.vue === 'jour' && S.jour) {
        const j = ajoute(S.jour, n);
        if (j > AUJ) { return; }
        S.jour = j;
        if (lundi(j) !== lundi(S.date)) { S.date = j; charger(false); } else { url(); rendre(); }
      } else {
        S.date = ajoute(lundi(S.date), 7 * n); S.jour = null; charger(false);
      }
    }
  });
  $.addEventListener('change', e => {
    const t = e.target;
    if (t.dataset.act === 'mois' && /^\d{4}-\d{2}$/.test(t.value) && t.value <= AUJ.slice(0, 7)) { S.mois = t.value; charger(false); }
    else if (t.dataset.act === 'date' && estDate(t.value)) {
      S.date = t.value; if (S.vue === 'jour') { S.jour = t.value; } else { S.jour = null; }
      charger(false);
    }
  });

  /* --- démarrage : Gosselies, la semaine (ou le mois) demandée ----------------------------- */
  rendre();
  charger(false);
})();
