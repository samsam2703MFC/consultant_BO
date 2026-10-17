/*
 * Le portail Marketing — /marketing (demande du 13/10/2026).
 *
 * Un outil de marketing, pas une suite d'écrans par canal : le travail suit un FLUX,
 * et la campagne en est le fil. Chaque newsletter lui est rattachée :
 *
 *   Tableau de bord — où en est chaque étape, ce qui demande un geste, les 14 jours ;
 *   1 · Planifier    — les campagnes et leurs newsletters, le calendrier ;
 *   2 · Newsletter   — le module newsletter, rattaché à une campagne ;
 *   3 · Mesurer      — newsletters (ouvertures, clics), avis Google, posts sauvages ;
 *   Paramètres       — sources, charte, pages, avis ; API et tables du portail.
 *
 * Les POSTS (Facebook, Instagram, LinkedIn) vivent dans le portail social (/social,
 * dépôt à part) depuis le 17/10/2026 : l'y créer, l'y valider, l'y publier. Les
 * anciennes adresses du circuit des posts (onglet=valider, publier, social,
 * creer&vue=post) y mènent.
 *
 * Deux vues : la MARQUE (sans ?shop=, tout le réseau, décisions comprises) et le
 * MAGASIN (?shop={id} : ses newsletters, les campagnes qui le couvrent).
 *
 * Lecture : ../api/cockpit/marketing/hub?shop= (src/marketing_hub.php). Écritures : les
 * routes du portail, sous /api/cockpit/marketing/… (newsletter, avis, brand-guard).
 * Adresse : ?shop=&onglet=vue|plan|creer|mesurer|parametres&vue=…&p=…&mois=AAAA-MM —
 * les anciennes (campagnes, calendrier, email) y mènent.
 */
(function () {
  'use strict';
  const API = '../api/cockpit';
  const q = new URLSearchParams(location.search);
  // Le portail social (/social, à côté du cockpit) : les posts s'y créent, s'y valident, s'y publient.
  // Un magasin y a son espace (franchisee/?shop=).
  const SOCIAL = new URL('../../social/', location.href).href;
  const social = shop => shop ? SOCIAL + 'franchisee/?shop=' + encodeURIComponent(shop) : SOCIAL;
  const SOCIAL_POST = SOCIAL + 'SA%20Post.dc.html';
  // Le circuit des posts d'avant (créer un post, valider, publier) : ses adresses mènent au portail social.
  (function () {
    const o = q.get('onglet');
    if (o === 'valider' || o === 'publier' || o === 'social' || (o === 'creer' && q.get('vue') === 'post')) {
      location.replace(q.get('shop') ? social(q.get('shop')) : (o === 'creer' ? SOCIAL_POST : SOCIAL));
    }
  })();
  // Le flux, dans l'ordre du travail. [id, libellé, numéro de l'étape]
  const ONGLETS = [['vue', 'Tableau de bord', ''], ['plan', 'Planifier', '1'], ['creer', 'Newsletter', '2'], ['mesurer', 'Mesurer', '3'], ['parametres', 'Paramètres', '']];
  // Les adresses d'avant le flux mènent à l'étape qui a repris leur contenu.
  const ANCIENS_ONGLETS = { campagnes: ['plan', 'campagnes'], calendrier: ['plan', 'calendrier'], email: ['creer', 'newsletter'] };
  const CANAL_NOM = { campagne: 'Campagne', email: 'Newsletter', social: 'Post', charte: 'Charte', avis: 'Avis Google' };
  // Les sous-pages de Paramètres. Un magasin ne voit que l'inventaire : les réglages sont à la marque.
  const PARAM_VUES = [['sources', 'API et tables', true], ['charte', 'Charte Brand Guard', false], ['pages', 'Pages Facebook', false], ['avis', 'Avis Google', false]];
  const NL_ST = { draft: 'Brouillon', sched: 'Programmée', live: 'En cours d’envoi', sent: 'Envoyée' };
  const PHASE = { en_cours: 'En cours', a_venir: 'À venir', terminee: 'Terminée', brouillon: 'Brouillon', sans_date: 'Sans dates' };
  const CAMP_F = [['actives', 'En cours et à venir', c => ['en_cours', 'a_venir', 'sans_date'].includes(c.phase)], ['en_cours', 'En cours', c => c.phase === 'en_cours'],
    ['a_venir', 'À venir', c => c.phase === 'a_venir'], ['terminee', 'Terminées', c => c.phase === 'terminee'], ['brouillon', 'Brouillons', c => c.phase === 'brouillon'], ['toutes', 'Toutes', () => true]];
  const JOURS = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];

  const pad = n => String(n).padStart(2, '0');
  const iso = d => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
  const AUJ = iso(new Date());
  const decale = (j, n) => { const t = new Date(j + 'T12:00:00'); t.setDate(t.getDate() + n); return iso(t); };
  const moisOk = m => /^\d{4}-\d{2}$/.test(m || '');

  const ancien = ANCIENS_ONGLETS[q.get('onglet')] || null;
  const S = {
    shop: q.get('shop') || '',
    onglet: ancien ? ancien[0] : (ONGLETS.some(o => o[0] === q.get('onglet')) ? q.get('onglet') : 'vue'),
    // La sous-vue de chaque étape : planifier (campagnes | calendrier), mesurer (résultats | avis | sauvages).
    planVue: ancien && ancien[0] === 'plan' ? ancien[1] : (q.get('onglet') === 'plan' && q.get('vue') === 'calendrier' ? 'calendrier' : 'campagnes'),
    mesVue: ['avis', 'sauvages'].includes(q.get('vue')) ? q.get('vue') : 'resultats',
    campOuverte: null, nlCampagne: null,
    data: null, err: null, auth: false, enCours: false, msg: null, envoi: false,
    mois: moisOk(q.get('mois')) ? q.get('mois') : AUJ.slice(0, 7),
    calF: { campagne: true, email: true, social: true }, calSel: null,
    campF: 'actives', campQ: '', campSel: null,
    paramVue: PARAM_VUES.some(v => v[0] === q.get('p')) ? q.get('p') : (q.get('shop') ? 'sources' : 'charte'),
    par: { sources: null, charte: null, pages: null, err: {}, enCours: {} },   // lu à la première ouverture de chaque sous-page
    charteDraft: null, chFam: 'toutes', pagesDraft: {}, mailDraft: null, cibleDraft: null, srcF: 'toutes',
    rech: null,   // la recherche de fiche Google ouverte : { magasinId, q, candidats, busy, err }
    railOuvert: false,   // au téléphone seulement : le rail sort de la gauche
  };
  const $app = document.getElementById('mk-app');
  const $rail = document.getElementById('mk-rail');
  const $voile = document.getElementById('mk-voile');
  /** Un geste vient-il du portail ? (la newsletter montée à côté a ses propres data-a) */
  const chezNous = el => $app.contains(el) || $rail.contains(el);

  /* --- formats ---------------------------------------------------------------- */
  const esc = v => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const nf = (n, d) => Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d || 0, maximumFractionDigits: d || 0 });
  const fN = n => n == null ? '—' : nf(Math.round(n));
  const fE = n => n == null ? '—' : nf(Math.round(n)) + ' €';
  const fP = n => n == null ? '—' : nf(n, n % 1 ? 1 : 0) + ' %';
  const fD = d => d ? d.slice(8, 10) + '/' + d.slice(5, 7) : '—';
  const fDT = d => d ? fD(d) + (d.length > 10 && d.slice(11, 16) !== '00:00' ? ' ' + d.slice(11, 16) : '') : '—';
  const fDL = d => { if (!d) { return ''; } return new Date(d.slice(0, 10) + 'T12:00:00').toLocaleDateString('fr-BE', { weekday: 'long', day: 'numeric', month: 'long' }); };
  const pl = (n, mot, mots) => fN(n) + ' ' + (Math.abs(n) >= 2 ? (mots || mot + 's') : mot);
  const extrait = (t, n) => { t = String(t || '').replace(/\s+/g, ' ').trim(); return t.length > (n || 70) ? t.slice(0, n || 70) + '…' : t; };
  const periode = (du, au) => !du ? 'sans dates' : (!au || au === du ? 'le ' + fD(du) : fD(du) + ' → ' + fD(au));
  const image = u => !u ? '' : (/^(https?:|data:|\/)/.test(u) ? u : '../assistant/' + u.replace(/^\.?\//, ''));

  /* --- lecture, écriture -------------------------------------------------------- */
  function appel(method, path, corps) {
    const o = { method, headers: { Accept: 'application/json' }, credentials: 'same-origin' };
    if (corps !== undefined) { o.headers['Content-Type'] = 'application/json'; o.body = JSON.stringify(corps); }
    return fetch(API + path, o).then(r => r.json().catch(() => ({})).then(j => {
      if (r.status === 401) { const e = new Error('session expirée'); e.auth = true; throw e; }
      if (!r.ok) { throw new Error((j && (j.error || j.description)) || ('HTTP ' + r.status)); }
      return j;
    }));
  }
  let lecture = null;
  /** Relit le hub. Une lecture déjà en route est partagée, pas doublée. */
  function charger(silence) {
    if (lecture) { return lecture; }
    S.enCours = true;
    if (!silence) { rendre(); }
    const shop = S.shop;
    lecture = appel('GET', '/marketing/hub' + (shop ? '?shop=' + encodeURIComponent(shop) : ''))
      .then(d => { if (shop === S.shop) { S.data = d; S.err = null; S.auth = false; } })
      .catch(e => { S.err = e.message || 'lecture impossible'; S.auth = !!e.auth; })
      .finally(() => { S.enCours = false; lecture = null; rendre(); if (shop !== S.shop) { charger(false); } });
    return lecture;
  }
  /** Un geste d'écriture : boutons bloqués pendant l'appel, message au retour, puis relecture du hub. */
  function geste(promesse, ok) {
    S.envoi = true; S.msg = null; rendre();
    return promesse.then(r => {
      const t = typeof ok === 'function' ? ok(r) : ok;
      S.msg = t && typeof t === 'object' ? t : { ok: true, texte: t };   // un geste réussi peut rapporter un échec partiel
      S.envoi = false; return charger(true).then(() => r);
    })
      .catch(e => { S.msg = { ok: false, texte: e.auth ? 'Session expirée — reconnectez-vous dans le cockpit.' : (e.message || 'écriture impossible') }; return null; })
      .finally(() => { S.envoi = false; rendre(); });
  }
  function urlMaj() {
    const u = new URLSearchParams();
    if (S.shop) { u.set('shop', S.shop); }
    if (S.onglet !== 'vue') { u.set('onglet', S.onglet); }
    if (S.onglet === 'plan' && S.planVue === 'calendrier') { u.set('vue', 'calendrier'); if (S.mois !== AUJ.slice(0, 7)) { u.set('mois', S.mois); } }
    if (S.onglet === 'mesurer' && S.mesVue !== 'resultats') { u.set('vue', S.mesVue); }
    if (S.onglet === 'parametres') { u.set('p', S.paramVue); }
    const s = u.toString();
    history.replaceState(null, '', location.pathname + (s ? '?' + s : ''));
  }

  /* --- accès aux données ---------------------------------------------------------- */
  const D = () => S.data || {};
  const canal = k => D()[k] || { dispo: false, raison: 'pas encore lu' };
  const marque = () => !S.shop;
  const magasinNom = id => { const m = (D().magasins || []).find(x => String(x.id) === String(id)); return m ? m.nom : null; };
  const par = () => {
    if (marque()) { return 'CEO'; }
    const m = (D().magasins || []).find(x => String(x.id) === S.shop);
    return (m && m.franchise) || ('Franchisé ' + (m ? m.nom : S.shop));
  };
  // Ce qui demande un geste, sans les posts : ils ont leurs alertes dans le portail social.
  const aFaire = () => (D().aFaire || []).filter(a => a.canal !== 'social');

  /** Tout ce qui a une date, tous canaux — le calendrier et l'agenda lisent la même liste. */
  function evenements() {
    const ev = [];
    const c = canal('campagnes'), m = canal('emailing'), b = canal('charte');
    if (c.dispo && S.calF.campagne) {
      c.liste.forEach(x => { if (x.debut) { ev.push({ canal: 'campagne', id: x.id, du: x.debut, au: x.fin && x.fin >= x.debut ? x.fin : x.debut, titre: x.nom, sous: x.type || '', brouillon: x.phase === 'brouillon', statut: x.statutNom, ref: x }); } });
    }
    if (m.dispo && S.calF.email) {
      m.liste.forEach(x => { if (x.envoiLe) { ev.push({ canal: 'email', id: x.id, du: x.envoiLe.slice(0, 10), au: x.envoiLe.slice(0, 10), heure: x.envoiLe.slice(11, 16), titre: x.nom + (x.exemple ? ' (exemple)' : ''), sous: x.objet || NL_ST[x.statut] || x.statut, statut: NL_ST[x.statut] || x.statut, ref: x }); } });
    }
    if (b.dispo && S.calF.social) {
      (b.sauvages || []).forEach(x => {
        const quand = x.publieLe || x.quand;
        if (quand) { ev.push({ canal: 'social', sauvage: true, id: 'bg' + x.id, du: quand.slice(0, 10), au: quand.slice(0, 10), heure: quand.slice(11, 16), titre: x.boutique || 'Page', sous: 'post sauvage · ' + extrait(x.texte, 50), statut: 'Sauvage', ref: x }); }
      });
    }
    return ev;
  }
  /** Les newsletters datées dans la période d'une campagne. */
  function parole(c) {
    if (!c.debut) { return null; }
    const fin = c.fin || c.debut;
    const dans = d => d && d.slice(0, 10) >= c.debut && d.slice(0, 10) <= fin;
    return { mails: (canal('emailing').liste || []).filter(x => !x.exemple && dans(x.envoiLe)).length };
  }

  /* --- le cadre ----------------------------------------------------------------------- */
  function rendre() {
    const y = window.scrollY, yr = $rail.scrollTop;
    $rail.innerHTML = rail();
    $rail.scrollTop = yr;
    document.getElementById('mk').classList.toggle('rail-ouvert', S.railOuvert);
    $voile.hidden = !S.railOuvert;
    $app.innerHTML = entete() + message() + corps();
    if (window.scrollY !== y) { window.scrollTo(0, y); }
    nlMonter();
  }
  /* Ce que dit l'en-tête de chaque étape. */
  const ETAPE_SOUS = {
    vue: 'Où en est chaque étape, ce qui demande un geste, les 14 prochains jours.',
    plan: 'Les campagnes et leurs newsletters — les posts se préparent dans le portail social.',
    creer: 'La newsletter, rattachée à sa campagne.',
    mesurer: 'Ce que chaque canal et chaque campagne ont donné.',
    parametres: 'Sources, charte, pages Facebook, avis Google.',
  };
  function entete() {
    const d = D();
    const nom = S.shop ? (d.magasin || magasinNom(S.shop) || 'Magasin ' + S.shop) : 'Tout le réseau';
    const o = ONGLETS.find(x => x[0] === S.onglet) || ONGLETS[0];
    document.title = (o[0] === 'vue' ? 'Marketing' : o[1] + ' · Marketing') + ' — ' + (S.shop ? nom : "L'Atelier by");
    return `<div class="mk-hd"><button class="mk-burger" data-a="rail" aria-label="Ouvrir le menu">☰</button>
      <div><div class="mk-titre">${o[2] ? `<span class="n">${o[2]}</span> ` : ''}${esc(o[1])}</div>
      <div class="mk-sous">${esc(ETAPE_SOUS[o[0]] || '')} <span class="mu">· ${esc(nom)}</span></div></div></div>`;
  }
  /** Les compteurs des étapes — le rail et le tableau de bord lisent les mêmes. */
  function badgesEtapes() {
    const urg = aFaire().filter(a => a.niveau === 'urgent').length;
    const e = etapes();
    const sauv = (canal('charte').sauvages || []).length;
    const b = (n, alerte) => n ? `<b class="${alerte ? 'urg' : ''}">${n}</b>` : '';
    return { vue: b(urg, true), plan: b(e.plan.n), creer: b(e.creer.n), mesurer: b(sauv, true), parametres: '' };
  }
  /** Le rail : le menu du portail. Les sous-vues de l'étape ouverte se déplient dessous. */
  function rail() {
    const d = D(), badge = S.data ? badgesEtapes() : {};
    const mags = d.magasins || [];
    const nom = S.shop ? (d.magasin || magasinNom(S.shop) || 'Magasin ' + S.shop) : 'Tout le réseau';
    const opts = [`<option value="">Tout le réseau (marque)</option>`].concat(mags.map(m => `<option value="${esc(m.id)}"${String(m.id) === S.shop ? ' selected' : ''}>${esc(m.nom)}</option>`));
    if (S.shop && !mags.some(m => String(m.id) === S.shop)) { opts.push(`<option value="${esc(S.shop)}" selected>${esc(nom)}</option>`); }
    const sous = {
      plan: [['campagnes', 'Campagnes et newsletters'], ['calendrier', 'Calendrier']],
      mesurer: [['resultats', 'Résultats'], ['avis', 'Avis Google'], ['sauvages', 'Posts sauvages', badge.mesurer]],
      parametres: PARAM_VUES.filter(v => marque() || v[2]).map(v => [v[0], v[1]]),
    };
    const sousActive = { plan: S.planVue, mesurer: S.mesVue, parametres: S.paramVue };
    const entree = o => {
      const on = S.onglet === o[0];
      const enfants = on && sous[o[0]] ? sous[o[0]].map(x => `<button class="mk-rail-i sous${sousActive[o[0]] === x[0] ? ' on' : ''}" data-a="nav" data-v="${o[0]}" data-s="${x[0]}"><span>${esc(x[1])}</span>${x[2] || ''}</button>`).join('') : '';
      return `<button class="mk-rail-i${on ? ' on' : ''}" data-a="nav" data-v="${o[0]}"${on ? ' aria-current="page"' : ''}><span>${o[2] ? `<i>${o[2]}</i>` : ''}${esc(o[1])}</span>${badge[o[0]] || ''}</button>${enfants}`;
    };
    const groupe = (titre, ids) => `<div class="mk-rail-g"><div class="mk-rail-t">${titre}</div>${ONGLETS.filter(o => ids.includes(o[0])).map(entree).join('')}</div>`;
    return `<div class="mk-rail-tete"><img src="../assets/img/logo.png" alt="L’Atelier by"><div class="mk-rail-sub">Marketing</div></div>
      <div class="mk-rail-vue"><label class="mk-lab" for="mk-vue">Vue</label>
        <select id="mk-vue" data-f="shop" title="La marque voit tout le réseau ; un magasin voit ses newsletters et les campagnes qui le couvrent">${opts.join('')}</select></div>
      <nav class="mk-rail-nav">
        ${groupe('Pilotage', ['vue'])}
        ${groupe('Le flux', ['plan', 'creer', 'mesurer'])}
        ${groupe('Réglages', ['parametres'])}
        <div class="mk-rail-g"><div class="mk-rail-t">Liens</div>
          <a class="mk-rail-i" href="${esc(social(S.shop))}" target="_blank" rel="noopener" title="Les posts : créer, valider, publier"><span>Portail social${S.shop ? ' · magasin' : ''}</span><small>↗</small></a>
          ${marque() ? '<a class="mk-rail-i" href="../assistant/" target="_blank" rel="noopener"><span>Assistant de campagne</span><small>↗</small></a>' : ''}
          <a class="mk-rail-i" href="../#/campagnes" target="_blank" rel="noopener"><span>Cockpit</span><small>↗</small></a></div>
      </nav>
      <div class="mk-rail-pied"><span>${d.genere ? 'lu à ' + esc(d.genere.slice(11, 16)) : 'lecture…'}</span>
        <button class="mk-btn petit" data-a="recharger"${S.enCours ? ' disabled' : ''}>${S.enCours ? 'Lecture…' : '↻ Actualiser'}</button></div>`;
  }
  function message() {
    if (!S.msg) { return ''; }
    return `<div class="mk-msg ${S.msg.ok ? 'ok' : 'ko'}"><span>${esc(S.msg.texte)}</span><button data-a="msg-x" aria-label="Fermer">×</button></div>`;
  }
  function corps() {
    if (S.err && !S.data) {
      return `<div class="mk-err">${S.auth ? 'Session expirée ou absente — <a href="../">se connecter dans le cockpit</a>, puis revenir ici.' : 'Lecture impossible : ' + esc(S.err)} <button class="mk-btn petit" data-a="recharger">Réessayer</button></div>`;
    }
    if (!S.data) { return '<div class="mk-attente">Lecture des campagnes et des newsletters…</div>'; }
    switch (S.onglet) {
      case 'plan': return vPlan();
      case 'creer': return vCreer();
      case 'mesurer': return vMesurer();
      case 'parametres': return vParametres();
      default: return vVue();
    }
  }
  const indispo = (k, nom) => { const c = canal(k); return c.dispo ? '' : `<div class="mk-err">${esc(nom)} indisponible — ${esc(c.raison || 'source absente')}. Les autres canaux restent lisibles.</div>`; };

  /* --- 1. vue d'ensemble -------------------------------------------------------------- */
  /* --- le flux : où en est chaque étape ------------------------------------------------------- */
  const nls = () => (canal('emailing').liste || []).filter(x => !x.exemple);
  const campagnes = () => (canal('campagnes').liste || []);
  const campagneDe = id => campagnes().find(c => c.id === id) || null;
  function etapes() {
    const mois = AUJ.slice(0, 7), nl = nls();
    return {
      plan: { n: campagnes().filter(c => ['en_cours', 'a_venir', 'sans_date'].includes(c.phase)).length },
      creer: { n: nl.filter(x => x.statut === 'draft').length, prog: nl.filter(x => x.statut === 'sched').length },
      mesurer: { n: nl.filter(x => ['sent', 'live'].includes(x.statut) && String(x.envoiLe || '').startsWith(mois)).length },
    };
  }
  /** Les newsletters d'une campagne : rattachées d'abord ; à défaut, celles de sa période qui ne sont rattachées à rien. */
  function contenusDe(c) {
    const ns = nls().filter(x => x.campagneId === c.id);
    let parDates = [];
    if (c.debut) {
      const fin = c.fin || c.debut;
      const dans = d => d && d.slice(0, 10) >= c.debut && d.slice(0, 10) <= fin;
      parDates = nls().filter(x => x.campagneId == null && dans(x.envoiLe));
    }
    return { nls: ns, parDates };
  }

  /* --- tableau de bord ----------------------------------------------------------------------- */
  function vVue() {
    const e = etapes(), faire = aFaire();
    const mOuv = (canal('emailing').chiffres || {}).tauxOuverture;
    const av = canal('avis'), note = av.dispo ? (S.shop ? ((av.magasins || [])[0] || {}).note : (av.reseau || {}).moyenne) : null;
    const etape = (id, num, titre, gros, sous, alerte) => `<button class="mk-etape${alerte ? ' alerte' : ''}" data-a="onglet" data-v="${id}"><span class="num">${num}</span><span class="t">${titre}</span><span class="v">${gros}</span><span class="d">${sous}</span></button>`;
    const chaine = [
      etape('plan', '1', 'Planifier', pl(e.plan.n, 'campagne'), 'en cours ou à venir'),
      etape('creer', '2', 'Newsletter', pl(e.creer.n, 'brouillon'), pl(e.creer.prog, 'programmée')),
      etape('mesurer', '3', 'Mesurer', fN(e.mesurer.n) + ' <small>envoyées ce mois</small>', `ouverture ${mOuv == null ? '—' : fP(mOuv)} · Google ${note == null ? '—' : nf(note, 2) + ' ★'}`),
    ].join('<span class="mk-fleche">›</span>')
      + `<a class="mk-etape" href="${esc(social(S.shop))}" target="_blank" rel="noopener" style="text-decoration:none;color:inherit"><span class="num">↗</span><span class="t">Posts</span><span class="v">Portail social</span><span class="d">créer, valider, publier</span></a>`;
    return `<div class="mk-chaine">${chaine}</div>
      <div class="mk-grille mk-g2" style="margin-top:14px">
        <div class="mk-card"><div class="mk-ct"><span class="mk-k">À faire</span><span class="mk-mini">${faire.length ? pl(faire.length, 'point') + ' · toutes étapes' : 'rien en attente'}</span></div>
          ${faire.length ? `<ul class="mk-liste">${faire.map((a, i) => `<li class="mk-canal-${esc(a.canal)}" data-a="faire" data-i="${i}"><span class="mk-pastille"></span><div class="tx"><div class="tt">${a.niveau === 'urgent' ? '<span class="mk-badge urg">urgent</span> ' : ''}${esc(a.titre)}</div><div class="dd">${esc(a.detail)}</div></div><span class="qd">${esc(nomEtape((a.cible || {}).onglet) || CANAL_NOM[a.canal] || a.canal)}</span></li>`).join('')}</ul>`
            : '<div class="mk-vide">Aucune newsletter en souffrance, aucune campagne en brouillon qui approche. Rien ne presse.</div>'}</div>
        <div class="mk-card"><div class="mk-ct"><span class="mk-k">Les 14 prochains jours</span><span class="mk-mini">campagnes et envois</span></div>${agendaJours(AUJ, 14)}</div>
      </div>`;
  }
  const nomEtape = id => { const o = ONGLETS.find(x => x[0] === id); return o ? (o[2] ? o[2] + ' · ' : '') + o[1] : ''; };
  const chips = (cle, liste, val) => `<div class="mk-chips" style="--c:#222">${liste.map(v => `<button class="${v[0] === val ? 'on' : ''}${v[3] ? ' urg' : ''}" data-a="${cle}" data-v="${v[0]}">${v[1]}${v[2] != null ? ' <b>' + v[2] + '</b>' : ''}</button>`).join('')}</div>`;

  /* --- 1 · Planifier ---------------------------------------------------------------------------- */
  function vPlan() {
    return `<div class="mk-barre-f">${chips('plan-vue', [['campagnes', 'Campagnes et newsletters', null], ['calendrier', 'Calendrier', null]], S.planVue)}<span class="sp"></span>
      <span class="mk-aide">Une campagne, puis ses newsletters ; ses posts se préparent dans le <a href="${esc(social(S.shop))}" target="_blank" rel="noopener">portail social ↗</a>.</span></div>
      ${S.planVue === 'calendrier' ? vCalendrier() : vCampagnes()}`;
  }

  /* --- 2 · Newsletter -------------------------------------------------------------------------- */
  function vCreer() {
    const c = S.nlCampagne != null ? campagneDe(S.nlCampagne) : null;
    return (c ? `<div class="mk-msg ok" style="font-weight:500">La prochaine newsletter créée sera rattachée à la campagne « ${esc(c.nom)} ». <button class="mk-btn petit" data-a="nl-camp-x" style="margin-left:auto;font-size:11px">Ne pas rattacher</button></div>` : '') + vEmail();
  }

  /* --- 3 · Mesurer ----------------------------------------------------------------------------- */
  function vMesurer() {
    const sauv = (canal('charte').sauvages || []).length;
    const tete = `<div class="mk-barre-f">${chips('mes-vue', [['resultats', 'Résultats', null], ['avis', 'Avis Google', null], ['sauvages', 'Posts sauvages', sauv, sauv > 0]], S.mesVue)}</div>`;
    if (S.mesVue === 'avis') { return tete + vAvis(); }
    if (S.mesVue === 'sauvages') {
      const b = canal('charte');
      return tete + `<div class="mk-cote"><div>${!b.dispo ? indispo('charte', 'Brand Guard') : (sauv ? `<div class="mk-posts">${b.sauvages.map(carteSauvage).join('')}</div>` : '<div class="mk-card"><div class="mk-vide">Aucun post sauvage sur 30 jours : tout ce qui est paru sur les pages est passé par le portail.</div></div>')}</div><div>${carteCharte()}</div></div>`;
    }
    const envoyees = nls().filter(x => ['sent', 'live'].includes(x.statut));
    const vivantes = campagnes().filter(c => ['en_cours', 'a_venir', 'terminee'].includes(c.phase)).slice(0, 12);
    return tete + `<div class="mk-grille mk-gk">${kpiCampagnes()}${kpiEmail()}${kpiAvis()}${kpiCharte()}</div>
      <div style="margin-top:14px">
        <div class="mk-card"><div class="mk-ct"><span class="mk-k">Newsletters envoyées</span><span class="mk-mini">ouvertures et clics réels</span></div>
          ${envoyees.length ? `<div class="mk-defile"><table class="mk-tab"><thead><tr><th>Newsletter</th><th class="n">Envois</th><th class="n">Ouv.</th><th class="n">Clics</th></tr></thead><tbody>${envoyees.map(x => `<tr><td class="nom">${esc(x.nom)}<small>${esc(fDT(x.envoiLe))}${x.campagneId && campagneDe(x.campagneId) ? ' · ' + esc(campagneDe(x.campagneId).nom) : ''}</small></td><td class="n">${fN(x.envoyes)}</td><td class="n">${x.envoyes ? fP(Math.round(1000 * x.ouvertures / x.envoyes) / 10) : '—'}</td><td class="n">${x.envoyes ? fP(Math.round(1000 * x.clics / x.envoyes) / 10) : '—'}</td></tr>`).join('')}</tbody></table></div>` : '<div class="mk-vide">Aucune newsletter envoyée (les exemples ne comptent pas).</div>'}</div>
      </div>
      <div class="mk-card" style="margin-top:14px"><div class="mk-ct"><span class="mk-k">Campagnes · newsletters</span><span class="mk-mini">rattachées, envoyées ou non — le retour des posts est dans le <a href="${esc(social(S.shop))}" target="_blank" rel="noopener">portail social ↗</a></span></div>
        <div class="mk-defile"><table class="mk-tab"><thead><tr><th>Campagne</th><th>Période</th><th class="n">Newsletters</th><th class="n">Envoyées</th></tr></thead><tbody>
        ${vivantes.map(c => { const k = contenusDe(c); return `<tr data-a="camp-voir" data-id="${c.id}" style="cursor:pointer"><td class="nom">${esc(c.nom)}<small>${esc(PHASE[c.phase] || '')}</small></td><td class="per">${esc(periode(c.debut, c.fin))}</td><td class="n">${k.nls.length}</td><td class="n">${k.nls.filter(x => ['sent', 'live'].includes(x.statut)).length}</td></tr>`; }).join('') || '<tr><td colspan="4"><div class="mk-vide">Aucune campagne.</div></td></tr>'}
        </tbody></table></div></div>`;
  }
  function kpi(canalCss, onglet, titre, corpsHtml, dispo, raison) {
    if (!dispo) { return `<div class="mk-kpi off mk-canal-${canalCss}"><div class="t">${titre}</div><div class="d">Indisponible — ${esc(raison || '')}</div></div>`; }
    return `<button class="mk-kpi mk-canal-${canalCss}" data-a="onglet" data-v="${onglet}"><div class="t">${titre}</div>${corpsHtml}</button>`;
  }
  function kpiCampagnes() {
    const c = canal('campagnes'); const k = c.chiffres || {};
    const pct = k.budget > 0 ? Math.min(100, 100 * k.depense / k.budget) : 0;
    return kpi('campagne', 'plan', 'Campagnes', `<div class="v">${fN(k.enCours)}<small>en cours</small></div>
      <div class="d"><b>${fN(k.aVenir)}</b> à venir${marque() ? ' · <b>' + fN(k.brouillons) + '</b> en brouillon' : ''} · ${fN(k.terminees)} terminées</div>
      <div class="mk-barre" title="Dépensé sur le budget des campagnes lancées"><i style="width:${pct.toFixed(1)}%"></i></div>
      <div class="d">${fE(k.depense)} dépensés sur ${fE(k.budget)}</div>`, c.dispo, c.raison);
  }
  function kpiEmail() {
    const c = canal('emailing'); const k = c.chiffres || {};
    return kpi('email', 'creer', 'Emailing', `<div class="v">${k.tauxOuverture == null ? '—' : fP(k.tauxOuverture)}<small>d’ouverture</small></div>
      <div class="d">${pl(k.envoyes || 0, 'mail envoyé', 'mails envoyés')} · clic ${fP(k.tauxClic)}</div>
      <div class="d"><b>${fN(k.programmees)}</b> programmée${k.programmees > 1 ? 's' : ''} · ${fN(k.automatisations)} automatisation${k.automatisations > 1 ? 's' : ''}${k.optin != null ? ' · ' + fN(k.optin) + ' contacts opt-in' : ''}</div>
      ${c.test ? '<div><span class="mk-badge att">mode test · rien ne part</span></div>' : ''}${k.exemples ? `<div class="d mu">${pl(k.exemples, 'campagne d’exemple non comptée', 'campagnes d’exemple non comptées')}</div>` : ''}`, c.dispo, c.raison);
  }
  function kpiCharte() {
    const c = canal('charte'); const k = c.stats || {};
    return kpi('charte', 'mesurer', 'Charte de marque · 30 j', `<div class="v">${k.tauxConformite == null ? '—' : fP(k.tauxConformite)}<small>conformes</small></div>
      <div class="d">${pl(k.controles || 0, 'contrôle')} · score moyen ${k.scoreMoyen == null ? '—' : k.scoreMoyen + '/100'}</div>
      <div class="d">${(c.sauvages || []).length ? `<b class="ko">${pl(c.sauvages.length, 'post sauvage', 'posts sauvages')}</b>` : 'aucun post sauvage'}</div>`, c.dispo, c.raison);
  }
  /** L'agenda : un jour par ligne, ce qui démarre, part ou sort ce jour-là. */
  function agendaJours(du, n) {
    const ev = evenements();
    const lignes = [];
    for (let i = 0; i < n; i++) {
      const j = decale(du, i);
      const duJour = ev.filter(e => e.canal === 'campagne' ? (e.du === j || (e.au === j && e.au !== e.du)) : e.du === j)
        .sort((a, b) => (a.heure || '') < (b.heure || '') ? -1 : 1);
      duJour.forEach(e => lignes.push({ j, e }));
    }
    if (!lignes.length) { return '<div class="mk-vide">Rien de daté sur cette période : ni campagne qui démarre, ni envoi prévu.</div>'; }
    return `<ul class="mk-liste">${lignes.map(({ j, e }) => `<li class="mk-canal-${e.canal}" data-a="ev" data-c="${e.canal}" data-id="${esc(e.id)}"><span class="mk-pastille" style="${e.sauvage ? 'background:var(--mk-ko)' : ''}"></span>
      <div class="tx"><div class="tt">${esc(e.titre)}${e.canal === 'campagne' ? ` <span class="mk-badge">${e.du === j ? 'démarre' : 'se termine'}</span>` : ''}</div><div class="dd">${esc(CANAL_NOM[e.canal])} · ${esc(e.statut || '')}${e.sous ? ' · ' + esc(e.sous) : ''}</div></div>
      <span class="qd">${j === AUJ ? 'aujourd’hui' : esc(fDL(j).split(' ').slice(0, 3).join(' '))}${e.heure && e.heure !== '00:00' ? '<small>' + esc(e.heure) + '</small>' : ''}</span></li>`).join('')}</ul>`;
  }

  /* --- 2. calendrier -------------------------------------------------------------------- */
  function grille(mois) {
    const [y, m] = mois.split('-').map(Number);
    const premier = new Date(y, m - 1, 1), dernier = new Date(y, m, 0);
    const debut = new Date(y, m - 1, 1 - (premier.getDay() + 6) % 7);
    const fin = new Date(y, m - 1, dernier.getDate() + (6 - (dernier.getDay() + 6) % 7));
    const jours = [];
    for (const d = new Date(debut); d <= fin; d.setDate(d.getDate() + 1)) { jours.push(iso(d)); }
    return jours;
  }
  function vCalendrier() {
    const jours = grille(S.mois);
    const ev = evenements();
    const camps = ev.filter(e => e.canal === 'campagne');
    const ponct = ev.filter(e => e.canal !== 'campagne');
    const titre = new Date(S.mois + '-15T12:00:00').toLocaleDateString('fr-BE', { month: 'long', year: 'numeric' });
    const filtres = [['campagne', 'Campagnes'], ['email', 'Newsletters'], ['social', 'Posts sauvages']].map(f =>
      `<button class="mk-canal-${f[0]}${S.calF[f[0]] ? '' : ' off'}" data-a="cal-f" data-v="${f[0]}"><span class="mk-pastille"></span>${f[1]}</button>`).join('');
    let cases = JOURS.map(j => `<div class="jh">${j}</div>`).join('');
    for (let w = 0; w < jours.length; w += 7) {
      const sem = jours.slice(w, w + 7);
      const cs = camps.filter(c => c.du <= sem[6] && c.au >= sem[0]).sort((a, b) => a.du < b.du ? -1 : a.du > b.du ? 1 : (b.au > a.au ? 1 : -1));
      const vus = cs.slice(0, 4), cachees = cs.length - vus.length;
      sem.forEach((j, i) => {
        const hors = j.slice(0, 7) !== S.mois;
        const bandes = vus.map(c => {
          if (j < c.du || j > c.au) { return '<div class="mk-band vide"></div>'; }
          const deb = j === c.du, fin = j === c.au, label = deb || i === 0;
          return `<div class="mk-band mk-canal-campagne${deb ? ' deb' : ''}${fin ? ' fin' : ''}${c.brouillon ? ' brouillon' : ''}" style="${c.ref.typeCouleur ? '--c:' + esc(c.ref.typeCouleur) : ''}" data-a="ev" data-c="campagne" data-id="${c.id}" title="${esc(c.titre + ' · ' + periode(c.du, c.au) + ' · ' + (c.statut || ''))}">${label ? esc(c.titre) : '&nbsp;'}</div>`;
        }).join('');
        const lesPonct = ponct.filter(e => e.du === j).sort((a, b) => (a.heure || '') < (b.heure || '') ? -1 : 1);
        const vp = lesPonct.slice(0, 3), reste = lesPonct.length - vp.length + (i === 0 ? cachees : 0);
        cases += `<div class="j${hors ? ' hors' : ''}${j === AUJ ? ' auj' : ''}"><div class="n"><span>${+j.slice(8)}</span></div>${bandes}
          ${vp.map(e => `<div class="mk-ev mk-canal-${e.canal}${e.sauvage ? ' sauvage' : ''}${e.refuse ? ' refuse' : ''}" data-a="ev" data-c="${e.canal}" data-id="${esc(e.id)}" title="${esc(e.titre + ' · ' + (e.statut || '') + ' · ' + (e.sous || ''))}">${e.heure && e.heure !== '00:00' ? '<b>' + esc(e.heure) + '</b>' : ''}${esc(e.canal === 'email' ? '✉ ' + e.titre : e.titre)}</div>`).join('')}
          ${reste > 0 ? `<div class="mk-plus" data-a="cal-jour" data-v="${j}" style="cursor:pointer">+ ${reste} de plus</div>` : ''}</div>`;
      });
    }
    // Au téléphone : la même chose en liste, jour par jour.
    const duMois = jours.filter(j => j.slice(0, 7) === S.mois);
    const agenda = agendaJours(duMois[0], duMois.length);
    return `<div class="mk-card"><div class="mk-calbar">
        <button class="mk-btn" data-a="mois" data-v="-1" aria-label="Mois précédent">‹</button><span class="mois">${esc(titre)}</span><button class="mk-btn" data-a="mois" data-v="1" aria-label="Mois suivant">›</button>
        ${S.mois !== AUJ.slice(0, 7) ? '<button class="mk-btn petit" data-a="mois" data-v="0">Aujourd’hui</button>' : ''}
        <div class="mk-filtres">${filtres}</div></div>
        <div class="mk-cal">${cases}</div><div class="mk-agenda">${agenda}</div>${detailCal()}</div>
      ${['campagnes', 'emailing'].map(k => indispo(k, { campagnes: 'Campagnes', emailing: 'Emailing' }[k])).join('')}`;
  }
  function trouveEv(c, id) { return evenements().find(e => e.canal === c && String(e.id) === String(id)); }
  function detailCal() {
    const sel = S.calSel;
    if (!sel) { return ''; }
    if (sel.jour) {
      const ev = evenements().filter(e => e.du <= sel.jour && e.au >= sel.jour);
      return `<div class="mk-detail"><div class="tx"><b style="text-transform:capitalize">${esc(fDL(sel.jour))}</b>
        <ul class="mk-liste" style="margin-top:6px">${ev.map(e => `<li class="mk-canal-${e.canal}" data-a="ev" data-c="${e.canal}" data-id="${esc(e.id)}"><span class="mk-pastille"></span><div class="tx"><div class="tt">${esc(e.titre)}</div><div class="dd">${esc(CANAL_NOM[e.canal])} · ${esc(e.statut || '')}${e.sous ? ' · ' + esc(e.sous) : ''}</div></div></li>`).join('')}</ul></div>
        <button class="mk-btn petit" data-a="cal-x">Fermer</button></div>`;
    }
    const e = trouveEv(sel.canal, sel.id);
    if (!e) { return ''; }
    const r = e.ref;
    let tx = '', acts = '';
    if (e.canal === 'campagne') {
      const p = parole(r);
      tx = `<b>${esc(r.nom)}</b> · ${esc(r.type || 'sans type')}<br>${esc(periode(r.debut, r.fin))} · ${esc(r.statutNom || '')} · ${r.reseau ? 'tout le réseau' : pl(r.nBoutiques, 'magasin')} · budget ${fE(r.budget)}
        ${p ? `<br>Newsletters sur la période : ${pl(p.mails, 'newsletter')}` : ''}`;
      acts = `<button class="mk-btn petit" data-a="camp-voir" data-id="${r.id}">Voir la campagne</button><a class="mk-btn petit prim" href="${SOCIAL_POST}" target="_blank" rel="noopener">Préparer un post ↗</a>`;
    } else if (e.canal === 'email') {
      tx = `<b>${esc(r.nom)}</b>${r.exemple ? ' <span class="mk-badge">exemple</span>' : ''}<br>${esc(r.objet || '')}<br>${esc(NL_ST[r.statut] || r.statut)} · ${esc(fDT(r.envoiLe))}${r.envoyes ? ` · ${pl(r.envoyes, 'envoi')}, ${pl(r.ouvertures, 'ouverture')}, ${pl(r.clics, 'clic')}` : ''}`;
      acts = '<button class="mk-btn petit" data-a="onglet" data-v="creer">Ouvrir la newsletter</button>';
    } else {
      tx = `<b>${esc(r.boutique || 'Page')}</b> · <span class="ko">post sauvage</span> · ${esc(fDT(r.publieLe || r.quand))} · score ${r.score}/100<br>${esc(extrait(r.texte, 200))}`;
      acts = `<button class="mk-btn petit" data-a="mes-vue" data-v="sauvages">Voir les sauvages</button>${r.lien ? `<a class="mk-btn petit" href="${esc(r.lien)}" target="_blank" rel="noopener">Facebook ↗</a>` : ''}`;
    }
    return `<div class="mk-detail mk-canal-${e.canal}"><span class="mk-pastille" style="background:var(--c);margin-top:5px"></span><div class="tx">${tx}</div><div style="display:flex;gap:6px;flex-wrap:wrap">${acts}<button class="mk-btn petit" data-a="cal-x">Fermer</button></div></div>`;
  }

  /* --- 3. campagnes ----------------------------------------------------------------------- */
  function vCampagnes() {
    const c = canal('campagnes');
    if (!c.dispo) { return indispo('campagnes', 'Campagnes'); }
    const filtres = CAMP_F.filter(f => marque() || f[0] !== 'brouillon');
    const f = filtres.find(x => x[0] === S.campF) || filtres[0];
    const rq = S.campQ.trim().toLowerCase();
    const liste = c.liste.filter(f[2]).filter(x => !rq || (x.nom + ' ' + (x.type || '')).toLowerCase().includes(rq));
    const chipsF = filtres.map(x => `<button class="${x[0] === f[0] ? 'on' : ''}" data-a="camp-f" data-v="${x[0]}">${x[1]} <b>${c.liste.filter(x[2]).length}</b></button>`).join('');
    const lignes = liste.map(x => {
      const k = contenusDe(x);
      const envoyees = k.nls.filter(x => ['sent', 'live'].includes(x.statut)).length;
      const etat = k.nls.length ? pl(k.nls.length, 'newsletter') + `<small>${envoyees} envoyée${envoyees > 1 ? 's' : ''}</small>`
        : (k.parDates.length ? `<span class="mu">${pl(k.parDates.length, 'newsletter')} sur la période</span><small>non rattachée${k.parDates.length > 1 ? 's' : ''}</small>` : `<span class="mu">aucune newsletter</span>`);
      const pct = x.budget > 0 ? Math.min(100, 100 * x.depense / x.budget) : 0;
      const ouverte = S.campOuverte === x.id;
      return `<tr id="camp-${x.id}" class="${S.campSel === x.id || ouverte ? 'sel' : ''}">
        <td class="masque-mob" style="width:46px">${x.image ? `<img class="mk-vign" src="${esc(image(x.image))}" alt="" loading="lazy" onerror="this.style.visibility='hidden'">` : `<span class="mk-vign" style="background:${esc(x.typeCouleur || 'var(--mk-doux)')};opacity:.25"></span>`}</td>
        <td class="nom"><button class="mk-lien-nom" data-a="camp-ouvrir" data-id="${x.id}">${ouverte ? '▾' : '▸'} ${esc(x.nom)}</button><small><span class="mk-pastille" style="background:${esc(x.typeCouleur || '#bbb')};width:7px;height:7px"></span> ${esc(x.type || 'sans type')} · ${x.reseau ? 'réseau' : pl(x.nBoutiques, 'magasin')}</small></td>
        <td class="per">${esc(periode(x.debut, x.fin))}<small>${esc(PHASE[x.phase] || '')}</small></td>
        <td><span class="mk-statut" style="color:${esc(x.statutTexte)};background:${esc(x.statutFond)}">${esc(x.statutNom)}</span></td>
        <td class="n masque-mob">${fE(x.depense)}<small>sur ${fE(x.budget)}</small><div class="mk-barre" style="width:90px;margin-left:auto;margin-top:3px"><i style="width:${pct.toFixed(1)}%"></i></div></td>
        <td>${etat}</td>
        <td><div class="acts"><a class="mk-btn petit prim" href="${SOCIAL_POST}" target="_blank" rel="noopener" title="Le post se prépare dans le portail social, qui lit les campagnes du cockpit">+ Post ↗</a>
          <button class="mk-btn petit" data-a="nl-camp" data-id="${x.id}" title="Une newsletter rattachée à la campagne">+ Newsletter</button>
          ${marque() ? `<a class="mk-btn petit" href="../assistant/?id=${x.id}" target="_blank" rel="noopener" title="L’assistant de campagne : offre, canaux, rétroplanning">Assistant ↗</a>` : ''}</div></td></tr>
        ${ouverte ? `<tr class="mk-sous-ligne"><td colspan="7">${contenusCampagne(x, k)}</td></tr>` : ''}`;
    }).join('');
    return `<div class="mk-barre-f mk-canal-campagne"><div class="mk-chips">${chipsF}</div><span class="sp"></span>
        <input class="mk-in" type="search" placeholder="Chercher une campagne" value="${esc(S.campQ)}" data-f="campQ" style="min-width:200px">
        ${marque() ? '<a class="mk-btn prim" href="../assistant/" target="_blank" rel="noopener">+ Nouvelle campagne</a><a class="mk-btn" href="../#/campagnes" target="_blank" rel="noopener">Budget, mesure, note ↗</a>' : ''}</div>
      <div class="mk-card"><div class="mk-defile"><table class="mk-tab"><thead><tr><th class="masque-mob"></th><th>Campagne</th><th>Période</th><th>Statut</th><th class="n masque-mob">Dépensé</th><th>Newsletters</th><th></th></tr></thead>
        <tbody>${lignes || `<tr><td colspan="7"><div class="mk-vide">Aucune campagne ${esc(f[1].toLowerCase())}${rq ? ' pour « ' + esc(S.campQ) + ' »' : ''}.</div></td></tr>`}</tbody></table></div></div>`;
  }
  /** Les newsletters d'une campagne, à l'étape où elles en sont. */
  function contenusCampagne(c, k) {
    const ln = x => `<li class="mk-canal-email" data-a="onglet" data-v="creer"><span class="mk-pastille"></span><div class="tx"><div class="tt">✉ ${esc(x.nom)} <span class="mk-badge">${esc(NL_ST[x.statut] || x.statut)}</span></div><div class="dd">${esc(x.objet || '')}${x.envoyes ? ' · ' + pl(x.envoyes, 'envoi') + ', ' + pl(x.ouvertures, 'ouverture') : ''}</div></div><span class="qd">${esc(fDT(x.envoiLe))}</span></li>`;
    return `<div class="mk-contenus">${k.nls.length ? `<ul class="mk-liste">${k.nls.map(ln).join('')}</ul>` : '<div class="mk-vide">Aucune newsletter rattachée. Ajoutez-en une : elle portera le nom de la campagne jusqu’aux résultats. Les posts, eux, se rattachent dans le portail social.</div>'}
      ${k.parDates.length ? `<div class="mk-aide" style="padding:8px 16px">Sur la même période, non rattachées : ${k.parDates.map(x => 'newsletter ' + esc(x.nom)).join(' · ')}.</div>` : ''}</div>`;
  }
  /* --- posts sauvages et charte (Brand Guard) ----------------------------------------------- */
  function carteSauvage(x) {
    return `<div class="mk-post"><div class="hd"><span class="mag">${esc(x.boutique || 'Page')}</span><span class="mk-badge urg">sauvage</span><span class="mk-badge ${x.statut === 'conforme' ? 'ok' : x.statut === 'bloque' ? 'urg' : 'att'}">${esc(x.statut === 'a_corriger' ? 'à corriger' : x.statut === 'bloque' ? 'bloquant' : 'conforme')} · ${x.score}/100</span>
        <span class="qd">paru ${esc(fDT(x.publieLe || x.quand))}</span></div>
      <div class="msg">${esc(x.texte || '(sans texte)')}</div>
      ${x.message ? `<div class="mk-agent">${esc(x.message)}</div>` : ''}
      ${(x.ecarts || []).length ? `<div class="mk-agent"><ul class="mk-ecarts" style="margin:0">${x.ecarts.map(e => `<li><span class="g g${e.gravite === 'bloquant' ? 3 : e.gravite === 'majeur' ? 2 : 1}"></span><div class="tx"><b>${esc(e.libelle)}</b>${e.correction ? '<small>→ ' + esc(e.correction) + '</small>' : ''}</div></li>`).join('')}</ul></div>` : ''}
      <div class="mk-form"><div class="ligne"><span class="mk-aide">Publié sur la page sans passer par une demande de publication.</span>${x.lien ? `<a class="mk-btn petit" href="${esc(x.lien)}" target="_blank" rel="noopener" style="margin-left:auto">Voir sur Facebook ↗</a>` : ''}</div></div></div>`;
  }
  function carteCharte() {
    const b = canal('charte');
    if (!b.dispo) { return `<div class="mk-card mk-canal-charte"><div class="mk-ct"><span class="mk-k">Charte de marque</span></div><div class="mk-vide">Brand Guard indisponible — ${esc(b.raison || '')}</div></div>`; }
    const k = b.stats || {};
    const meta = b.meta || {};
    const top = (k.topRegles || []);
    const bout = (k.boutiques || []).slice(0, 8);
    return `<div class="mk-card mk-canal-charte"><div class="mk-ct" style="border-top:3px solid var(--c)"><span class="mk-k">Charte de marque · 30 jours</span><span class="mk-mini">version ${esc(b.charteVersion || '—')}</span></div>
      <div class="mk-chiffres"><div><b>${k.tauxConformite == null ? '—' : fP(k.tauxConformite)}</b><span>conformes</span></div><div><b>${k.scoreMoyen == null ? '—' : k.scoreMoyen}</b><span>score moyen /100</span></div>
        <div><b>${fN(k.controles || 0)}</b><span>contrôles</span></div><div><b class="${(b.sauvages || []).length ? 'ko' : ''}">${fN((b.sauvages || []).length)}</b><span>posts sauvages</span></div></div>
      ${top.length ? `<div class="mk-cb" style="padding-top:0"><div class="mk-k" style="font-size:9.5px;margin-bottom:6px">Règles les plus enfreintes</div>${top.map(t => `<div style="display:flex;justify-content:space-between;font-size:12px;padding:3px 0"><span>${esc(t.libelle)}</span><b>${t.n}</b></div>`).join('')}</div>` : ''}
      ${marque() && bout.length ? `<table class="mk-tab"><thead><tr><th>Magasin</th><th class="n">Contrôles</th><th class="n">Conformes</th><th class="n">Sauvages</th></tr></thead><tbody>${bout.map(x => `<tr><td>${esc(x.boutique)}</td><td class="n">${x.controles}</td><td class="n">${fP(x.tauxConformite)}</td><td class="n ${x.sauvages ? 'ko' : ''}">${x.sauvages}</td></tr>`).join('')}</tbody></table>` : ''}
      <div class="mk-cb mk-aide">${meta.configure ? 'Pages Facebook relues (Graph ' + esc(meta.version || '') + ') : les posts parus sans demande sont repérés chaque matin.' : 'Accès Meta non configuré : seules les demandes de publication sont relues, pas les pages — un post paru sans demande ne se voit pas.'}
        ${marque() ? '<br><a href="../#/brand-guard" target="_blank" rel="noopener">Règles de la charte et pages des magasins ↗</a>' : ''}</div></div>`;
  }

  /* --- 5. emailing ------------------------------------------------------------------------ */
  function vEmail() {
    // Le mode test, le module le dit lui-même, juste en dessous : pas de second bandeau.
    return `<div class="mk-texte">Le module newsletter du cockpit, ${marque() ? 'vue marque — segments, automatisations et réglages compris' : 'vue magasin — ses segments, sa campagne, son adresse d’envoi'}. Ses envois et leurs résultats remontent dans 3 · Mesurer.</div>`;
  }
  let nlRole = null;
  function nlMonter() {
    const host = document.getElementById('mk-nl');
    const voir = S.onglet === 'creer' && !!S.data;
    host.hidden = !voir;
    if (!voir || !window.CockpitNewsletter) { return; }
    const role = S.shop || 'brand';
    const cle = role + '|' + (S.nlCampagne || '');
    if (nlRole === cle) { return; }   // déjà monté ainsi : le module garde son DOM et ses données
    nlRole = cle;
    window.CockpitNewsletter.mount(host, { role, apiBase: API, imgDir: '../assets/img/newsletter/', campagne: S.nlCampagne || null, notify: t => { S.msg = { ok: true, texte: t }; rendre(); } });
  }
  /* --- avis Google ----------------------------------------------------------------------- */
  const fNote = v => v == null ? '—' : nf(v, 2);
  const etoiles = n => { const p = n == null ? 0 : Math.round(n); return `<span class="mk-etoiles" title="${n == null ? 'sans note' : nf(n, 2) + ' sur 5'}">${[1, 2, 3, 4, 5].map(i => `<i class="${i <= p ? 'on' : ''}">★</i>`).join('')}</span>`; };
  const COUL_ETOILE = { 5: '#C9A227', 4: '#2d7a3e', 3: '#D97706', 2: '#C0182B', 1: '#8D1D2C' };
  function kpiAvis() {
    const c = canal('avis');
    const r = c.reseau || {}, m = S.shop ? ((c.magasins || [])[0] || {}) : null;
    const note = m ? m.note : r.moyenne;
    const cible = c.cible;
    const sous = note != null && cible != null && note < cible;
    return kpi('avis', 'mesurer', 'Avis Google', `<div class="v ${sous ? 'ko' : (note != null ? 'ok' : '')}">${fNote(note)}<small>/ 5 · cible ${fNote(cible)}</small></div>
      <div class="d">${m ? (m.avis ? pl(m.avis, 'avis', 'avis') + ' Google' : 'fiche non raccordée') : fN(r.avis) + ' avis · ' + pl(r.notes || 0, 'magasin noté', 'magasins notés')}</div>
      <div class="d">${m ? (m.avis5Requis ? '<b>' + m.avis5Requis + '</b> avis 5★ pour la cible' : (note != null && !sous ? 'à la cible' : '')) : (r.sousCible ? '<b class="ko">' + pl(r.sousCible, 'magasin') + '</b> sous la cible' : 'tous à la cible')}</div>`, c.dispo, c.raison);
  }
  function vAvis() {
    const c = canal('avis');
    if (!c.dispo) { return indispo('avis', 'Avis Google'); }
    const r = c.reseau || {}, co = c.connecteur || {}, cible = c.cible;
    const dis = S.envoi ? ' disabled' : '';
    const barres = rp => {
      const lus = (rp && rp.lus) || 0;
      if (!lus) { return '<div class="mk-aide">Aucun avis rapatrié pour l’instant.</div>'; }
      return `<div class="mk-barres">${((rp.niveaux) || []).map(l => `<div><span style="color:${COUL_ETOILE[l.note]}">${l.note} ★</span><i><b style="width:${l.n ? Math.max(2, 100 * l.n / lus) : 0}%;background:${COUL_ETOILE[l.note]}"></b></i><em>${fN(l.n)}</em></div>`).join('')}</div>`;
    };
    const tri = (c.magasins || []).slice().sort((a, b) => a.note == null ? 1 : b.note == null ? -1 : a.note - b.note);
    const tete = S.shop ? '' : `<div class="mk-card mk-canal-avis"><div class="mk-ct" style="border-top:3px solid var(--c)"><span class="mk-k">Le réseau</span><span class="mk-mini">moyenne pondérée par le nombre d’avis</span></div>
      <div class="mk-avis-tete"><div><div class="mk-gros ${r.moyenne != null && r.moyenne < cible ? 'ko' : 'ok'}">${fNote(r.moyenne)}</div>${etoiles(r.moyenne)}<div class="mk-aide">${fN(r.avis)} avis Google · ${pl(r.notes || 0, 'magasin noté', 'magasins notés')} · cible ${fNote(cible)}</div></div>
        <div style="flex:1;min-width:220px">${barres(r.repartition)}<div class="mk-aide">${r.repartition && r.repartition.lus ? fN(r.repartition.lus) + ' avis lus sur ' + fN(r.avis) + ' — Google ne publie pas la répartition complète d’une fiche.' : ''}</div></div>
        <div class="mk-aide" style="max-width:300px">${r.avis5Requis > 0 ? '<b class="ko">' + fN(r.avis5Requis) + ' avis 5★</b> pour ramener le réseau à ' + fNote(cible) + '.' : (r.sousCible ? 'Réseau à la cible en moyenne — l’effort porte sur ' + (r.sousCible > 1 ? 'les ' + r.sousCible + ' magasins' : 'le magasin') + ' en dessous.' : 'Tous les magasins notés sont à la cible.')}</div></div></div>`;
    const cartes = tri.map(m => {
      const sans = m.note == null || !m.avis, ok = !sans && m.note >= cible;
      const rech = S.rech && S.rech.magasinId === m.id ? S.rech : null;
      return `<div class="mk-post mk-avis" id="avis-${esc(m.id)}"><div class="hd"><span class="mag">${esc(m.nom)}</span>${m.ville && m.ville.toLowerCase() !== String(m.nom).toLowerCase() ? '<span class="mk-mini">' + esc(m.ville) + '</span>' : ''}
          <span class="mk-badge ${sans ? '' : ok ? 'ok' : 'urg'}">${sans ? 'sans note' : ok ? 'à la cible' : (m.ecart > 0 ? '+' : '') + nf(m.ecart, 2)}</span>
          <span class="qd">${m.synchro ? 'relu ' + esc(fDT(m.synchro)) : 'jamais relu'}</span></div>
        <div class="mk-avis-note"><span class="mk-gros-m ${sans ? 'mu' : ok ? 'ok' : 'ko'}">${fNote(m.note)}</span>${etoiles(m.note)}<span class="mk-aide">${sans ? 'Fiche Google non raccordée' : pl(m.avis, 'avis', 'avis')}</span></div>
        <div class="mk-agent">${m.avis5Requis == null ? (sans ? 'Raccordez la fiche pour suivre la note.' : 'Cible inatteignable par ajout d’avis.') : (m.avis5Requis === 0 ? 'À la cible — rien à rattraper.' : '<b>' + m.avis5Requis + ' avis 5★</b> pour atteindre ' + fNote(cible) + '.')}</div>
        ${(m.derniers || []).length ? `<ul class="mk-revues">${m.derniers.map(a => `<li><div class="l1">${etoiles(a.note)}<b>${esc(a.auteur)}</b><span class="mu">${esc(fD(a.le))}</span>${a.repondu === true ? '<span class="mk-badge ok">répondu</span>' : ''}</div><div class="${a.texte ? '' : 'mu'}">${esc(a.texte ? extrait(a.texte, 220) : 'Note sans commentaire.')}</div></li>`).join('')}</ul>` : ''}
        ${rech ? panneauRech(rech) : ''}
        <div class="mk-form"><div class="ligne">${m.url ? `<a class="mk-btn petit" href="${esc(m.url)}" target="_blank" rel="noopener">Fiche Google ↗</a>` : ''}
          ${marque() ? `<button class="mk-btn petit" data-a="rech-ouvrir" data-id="${esc(m.id)}">${m.placeId ? 'Changer de fiche' : 'Raccorder la fiche'}</button>${m.placeId ? `<button class="mk-btn petit ko" data-a="fiche-detacher" data-id="${esc(m.id)}"${dis}>Détacher</button>` : ''}` : ''}
          ${m.placeId && co.configure ? `<button class="mk-btn petit" data-a="avis-sync" data-id="${esc(m.id)}"${dis} style="margin-left:auto">Relire ce magasin</button>` : ''}</div></div></div>`;
    }).join('');
    return `<div class="mk-barre-f mk-canal-avis"><span class="mk-aide">${co.configure ? 'Connecteur Google configuré · ' + pl(co.raccordes || 0, 'fiche raccordée', 'fiches raccordées') + ' · ' + (co.derniereSynchro ? 'dernière relecture le ' + esc(fDT(co.derniereSynchro)) : 'jamais relu') : '<span class="ko">Clé Google absente</span> — elle se pose dans le cockpit, Paramètres (elle ne passe jamais par le navigateur).'}</span><span class="sp"></span>
        ${co.configure && marque() ? `<button class="mk-btn prim" data-a="avis-sync"${dis} title="Chaque fiche relue est un appel facturé par Google">Relire tous les avis Google</button>` : ''}</div>
      ${tete}<div class="mk-posts">${cartes || '<div class="mk-card"><div class="mk-vide">Aucun magasin dans la réputation.</div></div>'}</div>`;
  }
  function panneauRech(r) {
    return `<div class="mk-form" style="border-top:none"><label>Chercher la fiche Google</label>
      <div class="ligne"><input class="mk-in" value="${esc(r.q)}" data-f="rech-q"><button class="mk-btn petit prim" data-a="rech-chercher"${r.busy ? ' disabled' : ''}>${r.busy ? 'Recherche…' : 'Chercher'}</button><button class="mk-btn petit" data-a="rech-x">Fermer</button></div>
      ${r.err ? `<div class="ko mk-aide">${esc(r.err)}</div>` : ''}
      ${Array.isArray(r.candidats) ? (r.candidats.length ? `<ul class="mk-revues">${r.candidats.map((c, i) => `<li><div class="l1"><b>${esc(c.nom)}</b><span class="mu">${c.note == null ? 'sans note' : nf(c.note, 1) + ' ★ · ' + fN(c.avis || 0) + ' avis'}</span><button class="mk-btn petit ok" data-a="rech-choisir" data-i="${i}" style="margin-left:auto"${r.busy ? ' disabled' : ''}>Choisir</button></div><div class="mu">${esc(c.adresse || '')}</div></li>`).join('')}</ul>` : '<div class="mk-aide">Aucune fiche trouvée — essayez avec l’adresse.</div>') : '<div class="mk-aide">Deux boulangeries de la même enseigne dans la même ville ne se distinguent que par l’adresse : vérifiez-la.</div>'}</div>`;
  }

  /* --- 6. paramètres ------------------------------------------------------------------------ */
  const PARAM_SOURCE = { sources: '/marketing/sources', charte: '/marketing/brand-guard/rules', pages: '/marketing/brand-guard/pages' };
  function chargerParam(vue, force) {
    const P = S.par;
    if (!PARAM_SOURCE[vue] || P.enCours[vue] || (P[vue] && !force)) { return; }
    P.enCours[vue] = true; delete P.err[vue];
    appel('GET', PARAM_SOURCE[vue]).then(d => { P[vue] = d; }).catch(e => { P.err[vue] = e.message || 'lecture impossible'; })
      .finally(() => { P.enCours[vue] = false; if (S.onglet === 'parametres') { rendre(); } });
  }
  function vParametres() {
    const vues = PARAM_VUES.filter(v => marque() || v[2]);
    const vue = vues.find(v => v[0] === S.paramVue) || vues[0];
    const chips = vues.map(v => `<button class="${v[0] === vue[0] ? 'on' : ''}" data-a="param-vue" data-v="${v[0]}">${v[1]}</button>`).join('');
    if (PARAM_SOURCE[vue[0]] && !S.par[vue[0]] && !S.par.err[vue[0]]) { setTimeout(() => chargerParam(vue[0]), 0); }
    const attente = k => S.par.err[k] ? `<div class="mk-err">Lecture impossible : ${esc(S.par.err[k])} <button class="mk-btn petit" data-a="param-relire" data-v="${k}">Réessayer</button></div>` : (!S.par[k] ? '<div class="mk-attente">Lecture…</div>' : '');
    const corpsP = vue[0] === 'sources' ? (attente('sources') || pSources())
      : vue[0] === 'charte' ? (attente('charte') || pCharte()) : vue[0] === 'pages' ? (attente('pages') || pPages()) : pAvis();
    return `<div class="mk-barre-f" style="--c:#222"><div class="mk-chips">${chips}</div>${marque() ? '' : '<span class="sp"></span><span class="mk-aide">Les réglages appartiennent à la marque : un magasin voit ici d’où viennent ses données.</span>'}</div>${corpsP}`;
  }

  /* API et tables : ce que le module appelle, lu dans le code (GET /marketing/sources). */
  const FAM_API = { tfbuddy: ['Reliées à TFBuddy', 'urg'], locale: ['Locales', 'ok'], externe: ['Services extérieurs (hors TFBuddy)', 'att'] };
  const ORIG = { portail: ['Portail marketing · mar_', 'ok'], module: ['Module marketing autonome · base partagée', 'att'], cockpit: ['Cockpit · créée ici', 'bleu'], tfbuddy: ['TFBuddy · base partagée', 'urg'] };
  const MODE = { lecture: 'lue', ecriture: 'écrite', creation: 'créée et écrite' };
  function pSources() {
    const d = S.par.sources;
    const apis = d.apis || [], routes = d.routes || [], tables = d.tables || [];
    const nTf = routes.filter(r => r.tfbuddy).length, nLoc = routes.filter(r => r.locale).length;
    const parOrig = k => tables.filter(t => t.origine === k).length;
    const ligneApi = a => `<tr><td class="nom">${esc(a.nom)}<small>${esc(a.quoi)}</small></td><td class="masque-mob">${esc(a.id === 'base' ? 'base ' + (d.base || '—') : (a.hote || '—'))}</td>
      <td>${a.configure ? '<span class="mk-badge ok">configurée</span>' : '<span class="mk-badge">non configurée</span>'}</td>
      <td>${a.routes.length ? `<span title="${esc(a.routes.join('\n'))}">${pl(a.routes.length, 'route')}</span>` : '<span class="mu">pas appelée par le module</span>'}</td></tr>`;
    const groupes = ['tfbuddy', 'locale', 'externe'].map(f => {
      const l = apis.filter(a => a.famille === f);
      const plus = f === 'locale' ? `<tr><td class="nom">API du cockpit<small>/api/cockpit — servie par ce serveur ; toutes les routes ci-dessous en passent par elle.</small></td><td class="masque-mob">ce serveur</td><td><span class="mk-badge ok">en service</span></td><td>${pl(routes.length, 'route')}</td></tr>` : '';
      return `<tr class="grp"><td colspan="4"><span class="mk-badge ${FAM_API[f][1]}">${FAM_API[f][0]}</span></td></tr>${plus}${l.map(ligneApi).join('')}`;
    }).join('');
    const sourcesRoute = r => (r.tfbuddy ? '<span class="mk-badge urg">TFBuddy</span> ' : '') + r.apis.filter(a => a !== 'base').map(a => `<span class="mk-badge att">${esc((apis.find(x => x.id === a) || {}).nom || a)}</span>`).join(' ') + (r.locale ? '<span class="mk-badge ok">locale</span>' : '');
    const lignesRoutes = routes.map(r => `<tr><td><span class="mk-statut" style="background:var(--mk-doux)">${esc(r.methode)}</span></td><td class="nom" style="font-family:ui-monospace,Menlo,monospace;font-size:11px">${esc(r.chemin)}<small style="font-family:var(--font-ui)">${esc(r.quoi)}</small></td>
      <td>${sourcesRoute(r)}</td><td class="masque-mob" style="font-size:11px">${r.tables.map(t => `<span class="mk-tbl ${t.mode}" title="${esc(MODE[t.mode])}">${esc(t.nom)}</span>`).join(' ') || '<span class="mu">aucune</span>'}</td>
      <td class="masque-mob mu" style="font-size:10.5px">${esc(r.fichier || '')}<br>${esc(r.fonction)}${r.existe ? '' : ' <span class="ko">introuvable</span>'}</td></tr>`).join('');
    const filtres = [['toutes', 'Toutes', tables.length]].concat(Object.keys(ORIG).map(k => [k, ORIG[k][0], parOrig(k)]));
    const tf = S.srcF;
    const lignesTables = tables.filter(t => tf === 'toutes' || t.origine === tf).map(t => `<tr><td class="nom" style="font-family:ui-monospace,Menlo,monospace;font-size:11.5px">${esc(t.nom)}</td>
      <td><span class="mk-badge ${ORIG[t.origine][1]}">${esc(ORIG[t.origine][0])}</span></td><td>${t.ecrite ? 'lue et écrite' : 'lue'}</td>
      <td>${t.existe == null ? '<span class="mu">—</span>' : t.existe ? '<span class="ok">présente</span>' : '<span class="ko">absente</span>'}</td>
      <td class="n">${t.lignes == null ? '—' : '≈ ' + fN(t.lignes)}</td><td class="masque-mob" title="${esc(t.routes.join('\n'))}">${pl(t.routes.length, 'route')}</td></tr>`).join('');
    return `<div class="mk-grille mk-gk" style="margin-bottom:14px">
        <div class="mk-kpi off mk-canal-campagne"><div class="t">Routes du module</div><div class="v">${routes.length}</div><div class="d">toutes servies en local par /api/cockpit</div></div>
        <div class="mk-kpi off" style="--c:var(--mk-ko)"><div class="t">Reliées à TFBuddy</div><div class="v">${nTf}</div><div class="d">${nTf ? 'appellent le panel ou l’ERP' : 'aucune route n’appelle le panel ni l’ERP TFBuddy'}</div></div>
        <div class="mk-kpi off" style="--c:var(--mk-ok)"><div class="t">Locales seules</div><div class="v">${nLoc}</div><div class="d">base MySQL et calcul, rien ne sort du serveur</div></div>
        <div class="mk-kpi off mk-canal-charte"><div class="t">Tables</div><div class="v">${tables.length}</div><div class="d">${parOrig('portail')} portail (mar_) · ${parOrig('module')} module autonome · ${parOrig('cockpit')} cockpit · ${parOrig('tfbuddy')} TFBuddy</div></div></div>
      <div class="mk-card"><div class="mk-ct"><span class="mk-k">API</span><span class="mk-mini">base ${esc(d.base || '—')} · lu le ${esc(fDT(d.genere))}</span><button class="mk-btn petit" data-a="param-relire" data-v="sources">↻ Relire</button></div>
        <div class="mk-defile"><table class="mk-tab"><thead><tr><th>API</th><th class="masque-mob">Hôte</th><th>État</th><th>Utilisée par</th></tr></thead><tbody>${groupes}</tbody></table></div></div>
      <div class="mk-card"><div class="mk-ct"><span class="mk-k">Les routes du module</span><span class="mk-mini">ce que la page et la newsletter appellent, et ce que chaque route touche</span></div>
        <div class="mk-defile"><table class="mk-tab"><thead><tr><th></th><th>Route</th><th>Sources</th><th class="masque-mob">Tables</th><th class="masque-mob">Code</th></tr></thead><tbody>${lignesRoutes}</tbody></table></div></div>
      <div class="mk-card"><div class="mk-ct"><span class="mk-k">Toutes les tables</span><div class="mk-chips" style="--c:#222;margin-left:auto">${filtres.map(f => `<button class="${tf === f[0] ? 'on' : ''}" data-a="src-f" data-v="${f[0]}">${esc(f[1])} <b>${f[2]}</b></button>`).join('')}</div></div>
        <div class="mk-defile"><table class="mk-tab"><thead><tr><th>Table</th><th>Origine</th><th>Usage</th><th>En base</th><th class="n">Lignes</th><th class="masque-mob">Routes</th></tr></thead><tbody>${lignesTables}</tbody></table></div>
        ${d.baseLue ? '' : '<div class="mk-cb mk-aide">La base n’a pas répondu : présence et lignes inconnues.</div>'}</div>
      <div class="mk-card"><div class="mk-ct"><span class="mk-k">Registre du portail · tables mar_</span><span class="mk-mini">${(d.registre || []).length} tables, rangées par famille — un seul préfixe pour tout le marketing</span></div>
        <div class="mk-defile"><table class="mk-tab"><thead><tr><th>Table</th><th>Famille</th><th>Ce qu’elle contient</th><th>En base</th><th class="n">Lignes</th><th class="masque-mob">Ancien nom</th></tr></thead><tbody>
        ${(d.registre || []).map(t => `<tr><td class="nom" style="font-family:ui-monospace,Menlo,monospace;font-size:11.5px">${esc(t.nom)}${t.parLeModule ? '' : '<small style="font-family:var(--font-ui)">écrans du cockpit</small>'}</td><td>${esc(t.famille)}</td><td>${esc(t.quoi)}</td>
          <td>${t.existe == null ? '<span class="mu">—</span>' : t.existe ? '<span class="ok">présente</span>' : '<span class="mu">créée à la première utilisation</span>'}</td><td class="n">${t.lignes == null ? '—' : '≈ ' + fN(t.lignes)}</td><td class="masque-mob mu" style="font-family:ui-monospace,Menlo,monospace;font-size:10.5px">${esc(t.ancien || 'nouvelle')}</td></tr>`).join('')}
        </tbody></table></div></div>
      <div class="mk-card"><div class="mk-ct"><span class="mk-k">Applications liées</span><span class="mk-mini">ouvertes depuis le module, avec leur propre API</span></div>
        <div class="mk-defile"><table class="mk-tab"><tbody>${(d.liees || []).map(l => `<tr><td class="nom">${esc(l.nom)}<small>${esc(l.quoi)}</small></td><td style="font-family:ui-monospace,Menlo,monospace;font-size:11px">${esc(l.api)}</td><td>${l.tfbuddy ? '<span class="mk-badge urg">TFBuddy</span>' : '<span class="mk-badge ok">locale</span>'}</td></tr>`).join('')}</tbody></table></div></div>
      <div class="mk-texte">${esc(d.limites || '')}</div>`;
  }

  /* La charte Brand Guard, relue par le modèle : chaque enregistrement fait une nouvelle version. */
  const GRAV_BG = [['bloquant', 'Bloquant', 'urg'], ['majeur', 'Majeur', 'urg'], ['mineur', 'Mineur', 'att']];
  function pCharte() {
    const ch = S.par.charte;
    const d = S.charteDraft || (ch.regles || []);
    const modifie = !!S.charteDraft;
    const fams = Array.from(new Set(d.map(r => r.famille).filter(Boolean)));
    const ff = S.chFam;
    const vis = d.map((r, i) => [r, i]).filter(([r]) => ff === 'toutes' || r.famille === ff);
    return `<div class="mk-card"><div class="mk-ct"><span class="mk-k">Charte Brand Guard</span><span class="mk-mini">version ${esc(ch.charteVersion || '—')} · ${d.filter(r => r.actif).length}/${d.length} règles actives · relue par le modèle sur le texte et les visuels</span></div>
      <div class="mk-cb"><div class="mk-chips" style="--c:var(--mk-charte)">${[['toutes', 'Toutes']].concat(fams.map(f => [f, f])).map(f => `<button class="${ff === f[0] ? 'on' : ''}" data-a="ch-fam" data-v="${esc(f[0])}">${esc(f[1])}</button>`).join('')}</div></div>
      <div class="mk-defile"><table class="mk-tab"><thead><tr><th style="width:22%">Règle</th><th>Ce que le modèle vérifie</th><th>Gravité</th><th></th></tr></thead><tbody>
        ${vis.map(([r, i]) => `<tr${r.actif ? '' : ' style="opacity:.55"'}><td><input class="mk-in" style="width:100%" value="${esc(r.libelle)}" placeholder="Libellé" data-f="ch-libelle" data-i="${i}"><small class="mu">${esc(r.famille)} · ${esc(r.code)}</small></td>
          <td><textarea class="mk-in" style="width:100%;min-height:68px" data-f="ch-description" data-i="${i}" placeholder="Ce que la règle exige">${esc(r.description)}</textarea></td>
          <td><select data-f="ch-gravite" data-i="${i}">${GRAV_BG.map(g => `<option value="${g[0]}"${g[0] === r.gravite ? ' selected' : ''}>${g[1]}</option>`).join('')}</select></td>
          <td class="n"><div class="acts"><button class="mk-btn petit ${r.actif ? 'ok' : ''}" data-a="ch-actif" data-i="${i}">${r.actif ? 'Active' : 'Inactive'}</button><button class="mk-btn petit ko" data-a="ch-retirer" data-i="${i}" title="Retirer la règle">×</button></div></td></tr>`).join('')}
      </tbody></table></div>
      <div class="mk-form"><div class="ligne"><button class="mk-btn" data-a="ch-ajouter">+ Ajouter une règle</button>${modifie ? '<span class="wa mk-aide">Modifiée — la charte en service reste l’ancienne jusqu’à l’enregistrement.</span>' : ''}<span style="flex:1"></span>
        <button class="mk-btn" data-a="ch-annuler"${modifie ? '' : ' disabled'}>Annuler</button><button class="mk-btn prim" data-a="ch-enregistrer"${modifie && !S.envoi ? '' : ' disabled'}>Enregistrer la charte</button></div></div></div>`;
  }

  /* Les pages Facebook des magasins : une ligne par magasin, la page trouvée ou non. */
  function lignesPages() {
    const pg = S.par.pages || { pages: [] };
    const mags = D().magasins || [];
    const ids = new Set(mags.map(m => String(m.id)));
    const parMag = {}; const orphelines = [];
    (pg.pages || []).forEach(p => { if (p.magasinId && ids.has(String(p.magasinId))) { parMag[String(p.magasinId)] = p; } else { orphelines.push(p); } });
    const ligne = (cle, base, mag) => {
      const d = S.pagesDraft[cle] || {};
      return { cle, id: base ? base.id : null, magasinId: mag ? mag.id : (base ? base.magasinId : null),
        boutique: mag ? mag.nom : (base ? base.boutique : ''), franchise: d.franchise != null ? d.franchise : (base ? base.franchise || '' : (mag ? mag.franchise || '' : '')),
        url: d.pageUrl != null ? d.pageUrl : (base ? base.pageUrl || '' : ''), modifie: !!S.pagesDraft[cle], base };
    };
    return mags.map(m => ligne('m:' + m.id, parMag[String(m.id)] || null, m)).concat(orphelines.map(p => ligne('p:' + p.id, p, null)));
  }
  function pPages() {
    const pg = S.par.pages, meta = pg.meta || {};
    const L = lignesPages();
    const modifie = L.some(l => l.modifie) || S.mailDraft != null;
    const ET = { connectee: ['Connectée', 'ok'], erreur: ['Erreur', 'urg'], a_connecter: ['À connecter', ''] };
    return `<div class="mk-card"><div class="mk-ct"><span class="mk-k">Pages Facebook des magasins</span><span class="mk-mini">${meta.configure ? 'System User Meta configuré · API ' + esc(meta.version || '') : 'META_SYSTEM_TOKEN absent du serveur — les pages se résoudront dès qu’il sera posé (docs/brand-guard.md)'}</span></div>
      <div class="mk-defile"><table class="mk-tab"><thead><tr><th>Magasin</th><th>Franchisé</th><th>Page Facebook</th><th>État</th></tr></thead><tbody>
        ${L.map(l => { const st = l.base ? (ET[l.base.statut] || ET.a_connecter) : ['Pas de page', '']; return `<tr><td class="nom">${esc(l.boutique)}${l.modifie ? ' <span class="wa">•</span>' : ''}</td>
          <td><input class="mk-in" value="${esc(l.franchise)}" data-f="pg-franchise" data-k="${esc(l.cle)}" style="width:100%;min-width:150px"></td>
          <td><input class="mk-in" value="${esc(l.url)}" placeholder="https://www.facebook.com/…" data-f="pg-url" data-k="${esc(l.cle)}" style="width:100%;min-width:220px"></td>
          <td><span class="mk-badge ${st[1]}">${st[0]}</span><small class="mu" style="display:block">${esc(l.base ? (l.base.erreur || (l.base.pageId ? 'id ' + l.base.pageId + (l.base.derniereSync ? ' · lue le ' + fDT(String(l.base.derniereSync)) : '') : '')) : '')}</small></td></tr>`; }).join('')}
      </tbody></table></div>
      <div class="mk-form"><label>Alerte des posts sauvages — adresse</label><div class="ligne"><input class="mk-in" type="email" value="${esc(S.mailDraft != null ? S.mailDraft : (pg.mailAlerte || ''))}" data-f="pg-mail" placeholder="marketing@atelierby.be"></div>
        <div class="ligne"><span class="mk-aide">Résoudre : chaque adresse devient un identifiant de page, côté serveur — le jeton Meta ne quitte jamais le serveur.</span><span style="flex:1"></span>
          <button class="mk-btn" data-a="pg-enregistrer" data-v="0"${modifie && !S.envoi ? '' : ' disabled'}>Enregistrer</button><button class="mk-btn prim" data-a="pg-enregistrer" data-v="1"${S.envoi ? ' disabled' : ''}>Enregistrer et résoudre</button></div></div></div>`;
  }

  /* La note Google visée et l'état du connecteur. La clé reste dans le cockpit. */
  function pAvis() {
    const c = canal('avis');
    if (!c.dispo) { return indispo('avis', 'Avis Google'); }
    const co = c.connecteur || {};
    const v = S.cibleDraft != null ? S.cibleDraft : String(c.cible).replace('.', ',');
    return `<div class="mk-card mk-canal-avis"><div class="mk-ct" style="border-top:3px solid var(--c)"><span class="mk-k">Avis Google</span></div>
      <div class="mk-form" style="border-top:none;background:none"><label>Note visée</label><div class="ligne"><input class="mk-in" style="max-width:90px" value="${esc(v)}" data-f="cible" inputmode="decimal"><span class="mk-aide">sur 5 — sert au « sous la cible » et au nombre d’avis 5★ à obtenir, magasin par magasin.</span>
        <span style="flex:1"></span><button class="mk-btn prim" data-a="cible-enregistrer"${S.cibleDraft != null && !S.envoi ? '' : ' disabled'}>Enregistrer</button></div></div>
      <div class="mk-cb mk-aide">Connecteur Google : ${co.configure ? '<span class="ok">configuré</span>' : '<span class="ko">clé absente</span>'} · ${pl(co.raccordes || 0, 'fiche raccordée', 'fiches raccordées')} · ${co.derniereSynchro ? 'dernière relecture le ' + esc(fDT(co.derniereSynchro)) : 'jamais relu'}${co.erreur ? ' · <span class="ko">' + esc(co.erreur) + '</span>' : ''}.<br>
        La clé Google se règle dans le cockpit (<a href="../#/parametres" target="_blank" rel="noopener">Paramètres ↗</a>) : elle ne passe jamais par le navigateur. Les fiches se raccordent dans Réseaux sociaux → Avis Google.</div></div>`;
  }

  /** Le brouillon des réglages : une copie, l'original reste ce qui est en service. */
  function brouillonCharte() { return S.charteDraft || (S.charteDraft = JSON.parse(JSON.stringify((S.par.charte || {}).regles || []))); }
  /** Une saisie ne redessine pas : on rend la main au bouton d'enregistrement directement. */
  function activer(action) { document.querySelectorAll('[data-a="' + action + '"]').forEach(b => { b.disabled = S.envoi; }); }

  /* --- navigation ------------------------------------------------------------------------- */
  function aller(onglet) {
    // En quittant la newsletter, on relit : une newsletter créée là-bas doit apparaître partout ici.
    const relire = S.onglet === 'creer' && onglet !== 'creer';
    S.onglet = onglet; S.msg = null;
    if (onglet === 'parametres' && !PARAM_VUES.filter(v => marque() || v[2]).some(v => v[0] === S.paramVue)) { S.paramVue = 'sources'; }
    urlMaj(); rendre();
    if (relire) { charger(true); }
  }
  function defiler(id) {
    if (!id) { return; }
    requestAnimationFrame(() => { const el = document.getElementById(id); if (el) { el.scrollIntoView({ block: id === 'mk-app' ? 'start' : 'center', behavior: 'smooth' }); } });
  }
  function voirCampagne(id) {
    const c = campagneDe(+id);
    S.campSel = +id; S.campOuverte = +id; S.planVue = 'campagnes';
    if (c && !CAMP_F.find(f => f[0] === S.campF)[2](c)) { S.campF = 'toutes'; }
    aller('plan'); defiler('camp-' + id);
  }
  /* --- événements --------------------------------------------------------------------------- */
  document.addEventListener('click', e => {
    const el = e.target.closest('[data-a]');
    if (!el || !chezNous(el)) { return; }
    const a = el.dataset.a, v = el.dataset.v, id = el.dataset.id;
    if (a === 'onglet') { aller(v); }
    else if (a === 'rail') { S.railOuvert = !S.railOuvert; rendre(); }
    else if (a === 'nav') {
      // Une entrée du rail : l'étape, et la sous-vue quand on la nomme.
      const sv = el.dataset.s;
      if (sv) {
        if (v === 'plan') { S.planVue = sv; S.calSel = null; }
        else if (v === 'mesurer') { S.mesVue = sv; }
        else if (v === 'parametres') { S.paramVue = sv; }
      }
      S.railOuvert = false;
      if (S.onglet === v) { urlMaj(); rendre(); } else { aller(v); }
      window.scrollTo(0, 0);
    }
    else if (a === 'recharger') { S.msg = null; charger(false); }
    else if (a === 'msg-x') { S.msg = null; rendre(); }
    else if (a === 'faire') {
      const it = aFaire()[+el.dataset.i];
      const c = it && it.cible || {};
      if (c.campagne) { voirCampagne(c.campagne); }
      else {
        if (c.onglet === 'mesurer' && c.vue) { S.mesVue = c.vue; }
        aller(ONGLETS.some(o => o[0] === c.onglet) ? c.onglet : 'vue');
      }
    }
    else if (a === 'ev') {
      // Dans le calendrier, un clic ouvre le détail sous la grille ; ailleurs (l'agenda
      // de la vue d'ensemble), il mène droit à l'objet.
      const ev = trouveEv(el.dataset.c, id);
      if ((S.onglet === 'plan' && S.planVue === 'calendrier') || !ev) { S.calSel = { canal: el.dataset.c, id }; rendre(); }
      else if (ev.canal === 'campagne') { voirCampagne(ev.id); }
      else if (ev.canal === 'email') { aller('creer'); }
      else { S.mesVue = 'sauvages'; aller('mesurer'); }
    }
    else if (a === 'cal-jour') { S.calSel = { jour: v }; rendre(); }
    else if (a === 'cal-x') { S.calSel = null; rendre(); }
    else if (a === 'cal-f') { S.calF[v] = !S.calF[v]; S.calSel = null; rendre(); }
    else if (a === 'mois') {
      if (v === '0') { S.mois = AUJ.slice(0, 7); }
      else { const [y, m] = S.mois.split('-').map(Number); const d = new Date(y, m - 1 + (+v), 1); S.mois = d.getFullYear() + '-' + pad(d.getMonth() + 1); }
      S.calSel = null; urlMaj(); rendre();
    }
    else if (a === 'camp-f') { S.campF = v; rendre(); }
    else if (a === 'camp-voir') { voirCampagne(id); }
    // --- les étapes et leurs sous-vues
    else if (a === 'plan-vue') { S.planVue = v; S.calSel = null; urlMaj(); rendre(); }
    else if (a === 'mes-vue') { S.mesVue = v; if (S.onglet !== 'mesurer') { aller('mesurer'); } else { urlMaj(); rendre(); } }
    else if (a === 'aller-param') { e.preventDefault(); S.paramVue = v; aller('parametres'); }
    else if (a === 'camp-ouvrir') { S.campOuverte = S.campOuverte === +id ? null : +id; rendre(); }
    else if (a === 'nl-camp') { S.nlCampagne = +id; aller('creer'); defiler('mk-app'); }
    else if (a === 'nl-camp-x') { S.nlCampagne = null; rendre(); }
    else if (a === 'lien') { /* un lien dans une ligne cliquable : il s'ouvre, la ligne ne navigue pas */ }
    // --- avis Google
    else if (a === 'avis-sync') {
      if (!id && !confirm('Relire les avis Google de toutes les fiches raccordées ? Chaque fiche relue est un appel facturé par Google.')) { return; }
      geste(appel('POST', '/marketing/avis/sync', id ? { magasinId: id } : {}), r => (r && r.magasins != null ? pl(r.magasins, 'magasin relu', 'magasins relus') + ', ' + pl(r.nouveaux || 0, 'nouvel avis', 'nouveaux avis') : 'Avis relus') + (r && r.erreurs && r.erreurs.length ? ' — ' + r.erreurs.length + ' en échec' : '') + '.');
    }
    else if (a === 'rech-ouvrir') {
      const m = (canal('avis').magasins || []).find(x => String(x.id) === id) || {};
      S.rech = { magasinId: id, q: ((m.nom || '') + (m.ville ? ' ' + m.ville : '')).trim(), candidats: null, busy: false, err: '' }; rendre();
    }
    else if (a === 'rech-x') { S.rech = null; rendre(); }
    else if (a === 'rech-chercher') {
      const r = S.rech; if (!r || !r.q.trim()) { return; }
      r.busy = true; r.err = ''; rendre();
      appel('GET', '/marketing/avis/recherche?q=' + encodeURIComponent(r.q)).then(d => { r.candidats = (d && d.candidats) || []; })
        .catch(e => { r.err = e.message || 'Recherche impossible — vérifiez la clé Google.'; r.candidats = null; })
        .finally(() => { r.busy = false; rendre(); });
    }
    else if (a === 'rech-choisir') {
      const r = S.rech, c = r && (r.candidats || [])[+el.dataset.i];
      if (!c) { return; }
      geste(appel('PUT', '/marketing/avis/' + encodeURIComponent(r.magasinId) + '/fiche', { placeId: c.placeId }), () => { S.rech = null; return 'Fiche « ' + c.nom + ' » raccordée.'; });
    }
    else if (a === 'fiche-detacher') {
      if (!confirm('Détacher la fiche Google de ce magasin ? Les avis déjà rapatriés sont conservés.')) { return; }
      geste(appel('PUT', '/marketing/avis/' + encodeURIComponent(id) + '/fiche', { placeId: '' }), 'Fiche détachée.');
    }
    // --- paramètres
    else if (a === 'param-vue') { S.paramVue = v; urlMaj(); rendre(); }
    else if (a === 'param-relire') { chargerParam(v, true); rendre(); }
    else if (a === 'src-f') { S.srcF = v; rendre(); }
    else if (a === 'ch-fam') { S.chFam = v; rendre(); }
    else if (a === 'ch-actif') { const r = brouillonCharte()[+el.dataset.i]; if (r) { r.actif = !r.actif; } rendre(); }
    else if (a === 'ch-retirer') {
      const d = brouillonCharte(), r = d[+el.dataset.i];
      if (r && confirm('Retirer la règle « ' + (r.libelle || r.code) + ' » de la charte ?')) { d.splice(+el.dataset.i, 1); rendre(); }
    }
    else if (a === 'ch-ajouter') {
      const d = brouillonCharte();
      const fams = Array.from(new Set(d.map(r => r.famille).filter(Boolean)));
      d.push({ code: 'regle-' + (d.length + 1) + '-' + Date.now().toString(36).slice(-3), famille: S.chFam !== 'toutes' ? S.chFam : (fams[0] || 'Charte de marque'), libelle: '', description: '', gravite: 'majeur', actif: true });
      rendre();
    }
    else if (a === 'ch-annuler') { S.charteDraft = null; rendre(); }
    else if (a === 'ch-enregistrer') {
      const d = (S.charteDraft || []).map(r => Object.assign({}, r, { libelle: (r.libelle || '').trim(), description: (r.description || '').trim() }));
      const vide = d.find(r => !r.libelle || !r.description);
      if (vide) { S.msg = { ok: false, texte: 'Chaque règle porte un libellé et une description — « ' + (vide.libelle || vide.code) + ' » est incomplète.' }; rendre(); return; }
      // Une règle nouvelle prend un code lisible, tiré de son libellé : c'est lui que citent les verdicts.
      d.forEach(r => { if (/^regle-\d+-/.test(r.code)) { r.code = (r.famille + '-' + r.libelle).toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 40); } });
      geste(appel('PUT', '/marketing/brand-guard/rules', { regles: d, par: par() }), r => { S.charteDraft = null; if (r && r.regles) { S.par.charte = r; } return 'Charte enregistrée — version ' + ((r && r.charteVersion) || '—') + '.'; });
    }
    else if (a === 'pg-enregistrer') {
      const resoudre = v === '1';
      const pages = lignesPages().filter(l => l.modifie || (resoudre && l.url)).map(l => ({ id: l.id, magasinId: l.magasinId, boutique: l.boutique, franchise: l.franchise, pageUrl: l.url, actif: true }));
      const corpsPg = { pages, resoudre };
      if (S.mailDraft != null) { corpsPg.mailAlerte = S.mailDraft; }
      geste(appel('PUT', '/marketing/brand-guard/pages', corpsPg), r => {
        S.pagesDraft = {}; S.mailDraft = null; if (r && r.pages) { S.par.pages = r; }
        const res = r && r.resolution;
        if (res && res.motif) { return { ok: false, texte: 'Pages enregistrées, mais pas résolues : ' + res.motif + '.' }; }
        return res ? (res.pages || []).filter(x => x.ok).length + ' page(s) résolue(s) sur ' + (res.pages || []).length + '.' : 'Pages enregistrées.';
      });
    }
    else if (a === 'cible-enregistrer') {
      const n = parseFloat(String(S.cibleDraft || '').replace(',', '.'));
      if (!(n >= 1 && n <= 5)) { S.msg = { ok: false, texte: 'La note visée est un nombre entre 1 et 5.' }; rendre(); return; }
      geste(appel('PUT', '/marketing/avis/cible', { valeur: Math.round(n * 10) / 10 }), () => { S.cibleDraft = null; return 'Note visée : ' + nf(n, 1) + ' / 5.'; });
    }
  });
  // La saisie met l'état à jour SANS redessiner (le curseur resterait sinon en route).
  document.addEventListener('input', e => {
    const el = e.target, f = el.dataset && el.dataset.f;
    if (!f || !chezNous(el)) { return; }
    if (f === 'campQ') { S.campQ = el.value; const p = el.selectionStart; rendre(); const n = document.querySelector('[data-f="campQ"]'); if (n) { n.focus(); n.setSelectionRange(p, p); } return; }
    if (f === 'rech-q' && S.rech) { S.rech.q = el.value; return; }
    if (f === 'ch-libelle' || f === 'ch-description') { const r = brouillonCharte()[+el.dataset.i]; if (r) { r[f.slice(3)] = el.value; } activer('ch-enregistrer'); return; }
    if (f === 'pg-franchise' || f === 'pg-url') { const k = el.dataset.k; S.pagesDraft[k] = Object.assign({}, S.pagesDraft[k], { [f === 'pg-url' ? 'pageUrl' : 'franchise']: el.value }); activer('pg-enregistrer'); return; }
    if (f === 'pg-mail') { S.mailDraft = el.value; activer('pg-enregistrer'); return; }
    if (f === 'cible') { S.cibleDraft = el.value; activer('cible-enregistrer'); return; }
  });
  document.addEventListener('change', e => {
    const el = e.target, f = el.dataset && el.dataset.f;
    if (!f || !chezNous(el)) { return; }
    if (f === 'shop') {
      S.shop = el.value; S.data = null; S.campSel = null; S.calSel = null; S.msg = null;
      if (!S.shop && S.campF === 'brouillon') { S.campF = 'actives'; }
      urlMaj(); charger(false);
    }
    else if (f === 'ch-gravite') { const r = brouillonCharte()[+el.dataset.i]; if (r) { r.gravite = el.value; } rendre(); }
  });

  $voile.addEventListener('click', () => { S.railOuvert = false; rendre(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && S.railOuvert) { S.railOuvert = false; rendre(); } });

  urlMaj();
  rendre();
  charger(false);
})();
