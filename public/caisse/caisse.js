/* La caisse — ouvertures, fermetures et reports, par magasin et par semaine.
 *
 *   caisse/?shop=2&date=2026-11-04[&vue=jour&jour=2026-11-08]
 *
 * Une seule lecture : GET ../api/cockpit/caisse/semaine?shop=&date= (src/caisse.php),
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
  const memo = (k, v) => { try { if (v === undefined) { return localStorage.getItem(k); } localStorage.setItem(k, v); } catch (e) { return null; } return null; };
  const S = {
    shop: q.get('shop') || memo('caisse.shop') || '',
    date: estDate(q.get('date')) ? q.get('date') : AUJ,
    vue: q.get('vue') === 'jour' ? 'jour' : 'semaine',
    jour: estDate(q.get('jour')) ? q.get('jour') : null,
    stores: null, D: null, err: null, enCours: false, cache: {},
  };

  function lire(path) {
    return fetch(API + path, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(r => r.json().catch(() => ({})).then(d => { if (!r.ok) { throw new Error(d.error || ('HTTP ' + r.status)); } return d; }));
  }
  function url() {
    const p = new URLSearchParams();
    if (S.shop) { p.set('shop', S.shop); }
    p.set('date', lundi(S.date));
    if (S.vue === 'jour') { p.set('vue', 'jour'); if (S.jour) { p.set('jour', S.jour); } }
    history.replaceState(null, '', '?' + p.toString());
  }
  function charger(relire) {
    if (!S.shop) { rendre(); return; }
    const du = lundi(S.date), cle = S.shop + '|' + du;
    url();
    if (!relire && S.cache[cle]) { S.D = S.cache[cle]; S.err = null; caleJour(); rendre(); return; }
    S.enCours = true; S.err = null; rendre();
    lire('/caisse/semaine?shop=' + encodeURIComponent(S.shop) + '&date=' + du + (relire ? '&rafraichir=1' : ''))
      .then(d => {
        if (lundi(S.date) !== du || S.shop !== String(d.shop && d.shop.id)) { return; }   // une réponse arrivée trop tard
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
  function jourCaisse(c, j) {
    const l = svc(c, j);
    if (!l.length) { return null; }
    const prem = l[0];
    const journee = l.filter(s => !estNuit(s)), nuit = l.filter(estNuit);
    const dernierJour = journee[journee.length - 1] || prem, dernier = l[l.length - 1];
    const veille = svc(c, ajoute(j, -1));
    const report = veille.length ? veille[veille.length - 1].laisse : null;
    const pts = [];
    if (report != null && prem.fond != null && prem.fond !== report) {
      pts.push({ g: 'stop', k: 'rupture', t: 'Report rompu : ' + eur(report) + ' laissés, ' + eur(prem.fond) + ' au fond' });
    }
    if (journee.length > 1) {
      const a = journee[0];
      pts.push({ g: 'warn', k: 'reouv', t: 'Fermée à ' + hm(a.ferm) + ' puis rouverte' + (a.fond > 0 && a.final === 0 ? ' : le fond de ' + eur(a.fond) + ' a disparu' : '') });
    }
    if (journee.some(s => s.ferme && s.final === 0)) { pts.push({ g: 'warn', k: 'final0', t: 'Fermeture sans montant (0,00 € déclaré)' }); }
    l.forEach(s => {
      if (s.laisse != null && s.laisse > ABERRANT) {
        const x100 = s.final != null && Math.abs(s.laisse - s.final * 100) < 0.5;
        pts.push({ g: 'stop', k: 'aberrant', t: 'Laissé ' + eur(s.laisse) + (x100 ? ' = 100 × le total déclaré : virgule oubliée ?' : ' : montant impossible') });
      }
    });
    nuit.forEach(s => pts.push({ g: 'warn', k: 'nuit', t: 'Rouverte à ' + hm(s.ouv) + ' après la fermeture, refermée le ' + jcm(s.ferm.slice(0, 10)) + ' à ' + hm(s.ferm) }));
    const ouverts = l.filter(s => !s.ferme);
    if (ouverts.length && j < ajoute(AUJ, -1)) { pts.push({ g: 'stop', k: 'ouvert', t: 'Service jamais fermé' }); }
    return { l, prem, journee, nuit, dernierJour, dernier, report, pts, enCours: ouverts.length > 0 && j >= ajoute(AUJ, -1) };
  }
  function jourMagasin(j) {
    const cf = (S.D.comptable || {})[j] || null;
    const caisses = S.D.caisses.map(c => jourCaisse(c.id, j)).filter(Boolean);
    const fonds = caisses.reduce((a, x) => a + (x.prem.fond || 0), 0);
    const laisse = caisses.reduce((a, x) => a + (x.dernier.laisse || 0), 0);
    const declare = caisses.reduce((a, x) => a + x.l.reduce((b, s) => b + (s.final || 0), 0), 0);
    const aberrant = caisses.some(x => x.pts.some(p => p.k === 'aberrant'));
    const enCours = caisses.some(x => x.enCours);
    const versements = cf ? (cf.versements || 0) + (cf.depots || 0) : null;
    const especes = cf ? cf.especes : null;
    const sorties = aberrant || enCours || especes == null || !caisses.length ? null : fonds + especes - laisse - versements;
    return { j, cf, recette: cf ? cf.recette : null, especes, versements, fonds, laisse, declare, sorties, aberrant, enCours, caisses };
  }
  function tousLesPoints() {
    const out = [];
    S.D.caisses.forEach(c => S.D.jours.forEach(j => { const x = jourCaisse(c.id, j); if (x) { x.pts.forEach(p => out.push(Object.assign({ c: c.id, nom: c.nom, j }, p))); } }));
    const o = { stop: 0, warn: 1 };
    return out.sort((a, b) => a.j.localeCompare(b.j) || o[a.g] - o[b.g]);
  }

  /* --- barre supérieure ------------------------------------------------------------ */
  function barre() {
    const st = S.stores || [];
    const nomShop = (st.find(s => String(s.id) === String(S.shop)) || {}).nom || (S.D && S.D.shop.nom) || '';
    const per = S.vue === 'jour' && S.jour ? jl(S.jour) + ' ' + S.jour.slice(0, 4) : semLib(lundi(S.date));
    return `<header class="topbar ca-tb">
      <div><p class="topbar__kicker"><a href="../">Cockpit</a> · Exploitation${nomShop ? ' · ' + esc(nomShop) : ''}</p><h1>Caisse</h1></div>
      <div class="ca-ctl">
        <select data-act="shop" aria-label="Magasin">${S.shop ? '' : '<option value="">Choisir un magasin…</option>'}${st.map(s => `<option value="${esc(s.id)}"${String(s.id) === String(S.shop) ? ' selected' : ''}>${esc(s.nom)}</option>`).join('')}</select>
        <div class="seg" role="tablist"><button data-act="vue" data-v="semaine" class="${S.vue === 'semaine' ? 'is-on' : ''}">Semaine</button><button data-act="vue" data-v="jour" class="${S.vue === 'jour' ? 'is-on' : ''}">Jour</button></div>
        <button class="btn btn--sm btn--icon" data-act="pas" data-n="-1" aria-label="Précédent" title="${S.vue === 'jour' ? 'Jour précédent' : 'Semaine précédente'}">‹</button>
        <span class="per">${esc(per)}</span>
        <button class="btn btn--sm btn--icon" data-act="pas" data-n="1" aria-label="Suivant" title="${S.vue === 'jour' ? 'Jour suivant' : 'Semaine suivante'}">›</button>
        <input type="date" data-act="date" value="${esc(S.vue === 'jour' && S.jour ? S.jour : S.date)}" max="${AUJ}" aria-label="Aller à une date">
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
    const lk = x.report == null ? '' : `<div class="lk" title="Laissé la veille : ${esc(eur(x.report))} · fond d’ouverture : ${esc(eur(x.prem.fond))}"><i class="${rupt ? 'ko' : 'ok'}">${rupt ? '✗' : '✓'}</i></div>`;
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
    <tr><td>− Laissé pour demain</td>${td(x => x.aberrant ? '<span class="tag-stop">impossible</span>' : (x.enCours ? '<span class="tag-warn">en cours</span>' : eur(x.laisse)))}<td class="sem"><span class="nul">—</span></td></tr>
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
    if (n('aberrant')) { l.push(`<b>${n('aberrant')} montant${n('aberrant') > 1 ? 's' : ''} laissé${n('aberrant') > 1 ? 's' : ''} impossible${n('aberrant') > 1 ? 's' : ''}</b> — ${[...new Set(PTS.filter(p => p.k === 'aberrant').map(p => jc(p.j)))].join(', ')}.`); }
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
    if (!D.caisses.length) {
      return `<div class="ca-msg">Aucun service de caisse ouvert sur cette semaine pour ce magasin.</div>`;
    }
    return `
    <div class="kpi-row">
      <div class="kpi"><div class="kpi__value">${eur0(recette)}</div><div class="kpi__label">Recette de la semaine</div><div class="kpi__note">export comptable · ${passes} jour${passes > 1 ? 's' : ''}</div></div>
      <div class="kpi"><div class="kpi__value">${eur(especes)}${recette ? `<small>${nf(100 * especes / recette, 1)} %</small>` : ''}</div><div class="kpi__label">Espèces encaissées</div><div class="kpi__note">le reste en carte et titres-repas</div></div>
      <div class="kpi ${vers ? 'ok' : 'stop'}"><div class="kpi__value">${eur(vers)}</div><div class="kpi__label">Versements · dépôts</div><div class="kpi__note">${vers ? 'enregistrés dans l’export' : 'aucun enregistré'}</div></div>
      <div class="kpi ${sorties > 0 ? 'stop' : 'ok'}"><div class="kpi__value">${eur(sorties)}</div><div class="kpi__label">Sorti du tiroir sans trace</div><div class="kpi__note">${calc.length} jour${calc.length > 1 ? 's' : ''} calculable${calc.length > 1 ? 's' : ''} sur ${passes}</div></div>
      <div class="kpi ${PTS.length ? 'warn' : 'ok'}"><div class="kpi__value">${PTS.length}${nStop ? `<small>dont ${nStop} à corriger</small>` : ''}</div><div class="kpi__label">Points à vérifier</div><div class="kpi__note">${n('rupture')} reports rompus · ${n('final0')} fermetures à 0 · ${n('aberrant')} montants impossibles</div></div>
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
        L.push(`<span class="note">${rupt ? `<span class="tag-stop">${esc(rupt.t)}</span>` : '<span class="tag-ok">✓ suit le laissé de la veille</span>'}</span>`);
      }
      if (!s.ferme) {
        L.push(`<span class="h"></span><span>Pas encore fermée</span><span class="m">${j < ajoute(AUJ, -1) ? '<span class="tag-stop">jamais fermée</span>' : '<span class="tag-warn">en cours</span>'}</span>`);
        return;
      }
      L.push(`<span class="h">${hm(s.ferm)}${s.ferm && s.ferm.slice(0, 10) > j ? ' <small>' + jc(s.ferm.slice(0, 10)) + '</small>' : ''}</span><span>Fermeture · ${esc(s.parFerm)} — total déclaré</span><span class="m${s.final === 0 ? ' z' : ''}">${eur(s.final)}</span>`);
      L.push(`<span class="h"></span><span>Laissé pour le lendemain</span><span class="m${s.laisse > ABERRANT ? ' x' : ''}">${eur(s.laisse)}</span>`);
      if (s.laisse > ABERRANT) {
        const x100 = s.final != null && Math.abs(s.laisse - s.final * 100) < 0.5;
        L.push(`<span class="note"><span class="tag-stop">${x100 ? '100 × le total déclaré : virgule oubliée ?' : 'Montant impossible pour un fond de caisse.'} Attendu : le fond${x.prem.fond ? ', ' + esc(eur(x.prem.fond)) : ''}.</span></span>`);
      }
      if (nuit) { L.push(`<span class="note"><span class="tag-warn">Ouverte ${hm(s.ouv)} → ${hm(s.ferm)} le lendemain, fond ${esc(eur(s.fond))}, ${s.final ? esc(eur(s.final)) + ' déclarés' : 'rien déclaré'} : qui, et pourquoi ?</span></span>`); }
    });
    if (lend) {
      const ok = lend.fond === x.dernier.laisse;
      L.push(`<span class="h">${jcm(lend.jour)}</span><span>Ouverture du lendemain · ${esc(lend.parOuv)}</span><span class="m${ok ? '' : ' x'}">fond ${eur(lend.fond)}</span>`);
      L.push(`<span class="note">${ok ? '<span class="tag-ok">✓ suit</span>' : `<span class="tag-stop">Ne suit pas le laissé (${esc(eur(x.dernier.laisse))})</span>`}</span>`);
    }
    const g = x.pts.some(p => p.g === 'stop') ? 'tag-stop' : 'tag-warn';
    return `<section class="card"><h3>${esc(c.nom)} ${x.pts.length ? `<span class="${g}" style="margin-left:6px">${x.pts.length} point${x.pts.length > 1 ? 's' : ''}</span>` : '<span class="tag-ok" style="margin-left:6px">rien à signaler</span>'}</h3>
      <p class="sous">caisse ${c.id} · ${x.l.length} service${x.l.length > 1 ? 's' : ''} ce jour-là</p><div class="fiche">${L.join('')}</div></section>`;
  }
  function vueJour() {
    const D = S.D, j = S.jour;
    if (j > AUJ) { return `<div class="ca-msg">Ce jour n’est pas encore passé.</div>`; }
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
    <div class="cols">
      <section class="card"><h3>Les espèces, du fond du matin au fond du soir</h3><p class="sous">Le magasin, toutes les caisses ensemble.</p>
        ${cf ? `<div class="wf">
          <span class="op"></span><span>Fonds d’ouverture<span class="s">${esc(fondsDetail || '—')}, saisis à l’ouverture</span></span><span class="m">${eur(m.fonds)}</span>
          <span class="op">+</span><span>Espèces encaissées<span class="s">export comptable</span></span><span class="m">${eur(m.especes)}</span>
          <span class="op">−</span><span>Retraits<span class="s">enregistrés à la caisse, illisibles par l’API TFBuddy</span></span><span class="m na">non lisibles</span>
          <span class="op">−</span><span>Versements espèces · dépôts</span><span class="m${m.versements ? '' : ' na'}">${m.versements ? eur(m.versements) : 'aucun'}</span>
          <span class="op r">=</span><span class="r eg">Attendu dans les tiroirs au soir</span><span class="m r eg">${eur(m.fonds + (m.especes || 0) - (m.versements || 0))}</span>
          <span class="op"></span><span>Laissé pour le lendemain, déclaré</span><span class="m${m.aberrant ? ' x' : ''}">${m.enCours ? '<span class="tag-warn">en cours</span>' : eur(laisseTot)}</span>
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
  function rendre() {
    let corps;
    if (S.err) { corps = `<div class="ca-msg err">${esc(S.err)}</div>`; }
    else if (!S.shop) { corps = `<div class="ca-msg">Choisissez un magasin.</div>`; }
    else if (S.enCours && !S.D) { corps = `<div class="ca-msg">Lecture de TFBuddy… La première lecture d’une semaine prend quelques secondes ; les journées closes sont ensuite gardées.</div>`; }
    else if (!S.D) { corps = ''; }
    else {
      const lac = (S.D.lacunes || []).filter(l => l.jour <= AUJ);
      corps = (S.enCours ? `<div class="ca-msg">Lecture de TFBuddy…</div>` : '')
        + (lac.length ? `<div class="ca-msg warn">Lecture incomplète : ${lac.map(l => esc(jc(l.jour) + ' — ' + l.quoi)).join(' · ')}. <button class="lien" data-act="relire">Relire</button></div>` : '')
        + (S.vue === 'jour' ? vueJour() : vueSemaine());
    }
    $.innerHTML = barre() + `<main class="ca-body">${corps}</main>`;
  }
  $.addEventListener('click', e => {
    const b = e.target.closest('[data-act]');
    if (!b || b.tagName === 'SELECT' || b.tagName === 'INPUT') { return; }
    const a = b.dataset.act;
    if (a === 'vue') { S.vue = b.dataset.v; if (S.vue === 'jour') { caleJour(); } url(); rendre(); }
    else if (a === 'ouvrir') { S.vue = 'jour'; S.jour = b.dataset.j; url(); rendre(); window.scrollTo(0, 0); }
    else if (a === 'relire') { charger(true); }
    else if (a === 'pas') {
      const n = +b.dataset.n;
      if (S.vue === 'jour' && S.jour) {
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
    if (t.dataset.act === 'shop') { S.shop = t.value; memo('caisse.shop', S.shop); S.D = null; charger(false); }
    else if (t.dataset.act === 'date' && estDate(t.value)) {
      S.date = t.value; if (S.vue === 'jour') { S.jour = t.value; } else { S.jour = null; }
      charger(false);
    }
  });

  /* --- démarrage : les magasins, puis la semaine ------------------------------------------ */
  rendre();
  lire('/stores?statut=tous')
    .then(l => {
      S.stores = (Array.isArray(l) ? l : []).filter(s => !s.status || /ouvert/i.test(s.status)).map(s => ({ id: String(s.id), nom: s.nom || s.name || ('#' + s.id) }));
      if (!S.shop && S.stores.length) { S.shop = S.stores[0].id; }
    })
    .catch(() => { S.stores = []; })
    .finally(() => charger(false));
})();
