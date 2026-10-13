/*
 * Le portail Marketing — /marketing (demande du 13/10/2026).
 *
 * Un outil de marketing, pas une suite d'écrans par canal : le travail suit un FLUX,
 * et la campagne en est le fil. Chaque contenu (post, newsletter) lui est rattaché
 * et avance d'étape en étape :
 *
 *   Tableau de bord — où en est chaque étape, ce qui demande un geste, les 14 jours ;
 *   1 · Planifier    — les campagnes et leurs contenus, le calendrier ;
 *   2 · Créer        — le rédacteur de post (Facebook, Instagram, LinkedIn, visuel
 *                      téléversé) et la newsletter, rattachés à une campagne ; les brouillons ;
 *   3 · Valider      — l'agent contrôle, la charte relit, la marque décide ;
 *   4 · Publier      — publier maintenant ou à l'heure prévue, réseau par réseau ;
 *   5 · Mesurer      — publiés, newsletters (ouvertures, clics), avis Google, posts sauvages ;
 *   Paramètres       — publication, agent, charte, pages, avis ; API et tables du portail.
 *
 * Deux vues : la MARQUE (sans ?shop=, tout le réseau, décisions comprises) et le
 * MAGASIN (?shop={id} : ses posts, ses newsletters, les campagnes qui le couvrent).
 *
 * Lecture : ../api/cockpit/marketing/hub?shop= (src/marketing_hub.php). Écritures : les
 * routes du portail, sous /api/cockpit/marketing/… (social, newsletter, avis, publication,
 * brand-guard). Adresse : ?shop=&onglet=vue|plan|creer|valider|publier|mesurer|parametres
 * &vue=…&p=…&mois=AAAA-MM — les anciennes (campagnes, calendrier, social, email) y mènent.
 */
(function () {
  'use strict';
  const API = '../api/cockpit';
  const q = new URLSearchParams(location.search);
  // Le flux, dans l'ordre du travail. [id, libellé, numéro de l'étape]
  const ONGLETS = [['vue', 'Tableau de bord', ''], ['plan', 'Planifier', '1'], ['creer', 'Créer', '2'], ['valider', 'Valider', '3'],
    ['publier', 'Publier', '4'], ['mesurer', 'Mesurer', '5'], ['parametres', 'Paramètres', '']];
  // Les adresses d'avant le flux mènent à l'étape qui a repris leur contenu.
  const ANCIENS_ONGLETS = { campagnes: ['plan', 'campagnes'], calendrier: ['plan', 'calendrier'], social: ['valider', null], email: ['creer', 'newsletter'] };
  const RESEAUX = [['facebook', 'Facebook', 'f'], ['instagram', 'Instagram', 'ig'], ['linkedin', 'LinkedIn', 'in']];
  const PUB_ST = { programme: ['programmé', 'bleu'], en_cours: ['en cours', 'att'], publie: ['publié', 'ok'], erreur: ['en échec', 'urg'], annule: ['annulé', ''] };
  const CANAL_NOM = { campagne: 'Campagne', email: 'Newsletter', social: 'Post', charte: 'Charte', avis: 'Avis Google' };
  // Les sous-pages de Paramètres. Un magasin ne voit que l'inventaire : les réglages sont à la marque.
  const PARAM_VUES = [['publication', 'Publication', false], ['sources', 'API et tables', true], ['agent', 'Agent de contrôle', false], ['charte', 'Charte Brand Guard', false], ['pages', 'Pages Facebook', false], ['avis', 'Avis Google', false]];
  const POST_ST = { brouillon: ['Brouillon', ''], a_controler: ['À contrôler', 'att'], a_valider: ['À valider', 'att'], valide: ['Validé · à publier', 'bleu'], refuse: ['Refusé', 'urg'], publie: ['Publié', 'ok'] };
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
    // La sous-vue de chaque étape : planifier (campagnes | calendrier), créer (post | newsletter), mesurer (résultats | avis | sauvages).
    planVue: ancien && ancien[0] === 'plan' ? ancien[1] : (q.get('onglet') === 'plan' && q.get('vue') === 'calendrier' ? 'calendrier' : 'campagnes'),
    creerVue: ancien && ancien[0] === 'creer' ? ancien[1] : (q.get('vue') === 'newsletter' ? 'newsletter' : 'post'),
    mesVue: ['avis', 'sauvages'].includes(q.get('vue')) ? q.get('vue') : 'resultats',
    valVue: 'a_valider', campOuverte: null, nlCampagne: null, pubPanneau: {}, televersement: null, pubEtat: null, pubDraft: null, pubTest: {},
    data: null, err: null, auth: false, enCours: false, msg: null, envoi: false,
    mois: moisOk(q.get('mois')) ? q.get('mois') : AUJ.slice(0, 7),
    calF: { campagne: true, email: true, social: true }, calSel: null,
    campF: 'actives', campQ: '', campSel: null,
    postSel: null,
    dec: {},      // brouillon de décision par post : { note, famille, type, commentaire } — part au clic seulement
    pub: {},      // id Facebook saisi par post, au moment de le marquer publié
    compose: null,
    paramVue: PARAM_VUES.some(v => v[0] === q.get('p')) ? q.get('p') : (q.get('shop') ? 'sources' : 'publication'),
    par: { publication: null, sources: null, agent: null, charte: null, pages: null, err: {}, enCours: {} },   // lu à la première ouverture de chaque sous-page
    agentDraft: null, charteDraft: null, chFam: 'toutes', pagesDraft: {}, mailDraft: null, cibleDraft: null, srcF: 'toutes',
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
    if (S.onglet === 'creer' && S.creerVue === 'newsletter') { u.set('vue', 'newsletter'); }
    if (S.onglet === 'mesurer' && S.mesVue !== 'resultats') { u.set('vue', S.mesVue); }
    if (S.onglet === 'parametres') { u.set('p', S.paramVue); }
    const s = u.toString();
    history.replaceState(null, '', location.pathname + (s ? '?' + s : ''));
  }

  /* --- accès aux données ---------------------------------------------------------- */
  const D = () => S.data || {};
  const canal = k => D()[k] || { dispo: false, raison: 'pas encore lu' };
  const posts = () => (canal('social').liste || []);
  const regles = () => (canal('social').regles || { seuil: 4, familles: [], niveaux: [], formats: ['Photo', 'Texte'] });
  const marque = () => !S.shop;
  const magasinNom = id => { const m = (D().magasins || []).find(x => String(x.id) === String(id)); return m ? m.nom : null; };
  const par = () => {
    if (marque()) { return 'CEO'; }
    const m = (D().magasins || []).find(x => String(x.id) === S.shop);
    return (m && m.franchise) || ('Franchisé ' + (m ? m.nom : S.shop));
  };
  const niveau = n => (regles().niveaux || []).find(x => x.n === n) || { nom: n + '/5', couleur: '#777' };

  /** Tout ce qui a une date, tous canaux — le calendrier et l'agenda lisent la même liste. */
  function evenements() {
    const ev = [];
    const c = canal('campagnes'), m = canal('emailing'), s = canal('social'), b = canal('charte');
    if (c.dispo && S.calF.campagne) {
      c.liste.forEach(x => { if (x.debut) { ev.push({ canal: 'campagne', id: x.id, du: x.debut, au: x.fin && x.fin >= x.debut ? x.fin : x.debut, titre: x.nom, sous: x.type || '', brouillon: x.phase === 'brouillon', statut: x.statutNom, ref: x }); } });
    }
    if (m.dispo && S.calF.email) {
      m.liste.forEach(x => { if (x.envoiLe) { ev.push({ canal: 'email', id: x.id, du: x.envoiLe.slice(0, 10), au: x.envoiLe.slice(0, 10), heure: x.envoiLe.slice(11, 16), titre: x.nom + (x.exemple ? ' (exemple)' : ''), sous: x.objet || NL_ST[x.statut] || x.statut, statut: NL_ST[x.statut] || x.statut, ref: x }); } });
    }
    if (s.dispo && S.calF.social) {
      s.liste.forEach(x => {
        const quand = (x.publie && x.publie.le) || x.publierLe;
        if (quand) { ev.push({ canal: 'social', id: x.id, du: quand.slice(0, 10), au: quand.slice(0, 10), heure: quand.slice(11, 16), titre: x.magasin || 'Réseau', sous: extrait(x.message, 60), statut: (POST_ST[x.statut] || [x.statut])[0], refuse: x.statut === 'refuse', ref: x }); }
      });
    }
    if (b.dispo && S.calF.social) {
      (b.sauvages || []).forEach(x => {
        const quand = x.publieLe || x.quand;
        if (quand) { ev.push({ canal: 'social', sauvage: true, id: 'bg' + x.id, du: quand.slice(0, 10), au: quand.slice(0, 10), heure: quand.slice(11, 16), titre: x.boutique || 'Page', sous: 'post sauvage · ' + extrait(x.texte, 50), statut: 'Sauvage', ref: x }); }
      });
    }
    return ev;
  }
  /** Les prises de parole d'une campagne : posts et newsletters datés dans sa période. */
  function parole(c) {
    if (!c.debut) { return null; }
    const fin = c.fin || c.debut;
    const dans = d => d && d.slice(0, 10) >= c.debut && d.slice(0, 10) <= fin;
    const ps = posts().filter(p => p.statut !== 'refuse' && dans((p.publie && p.publie.le) || p.publierLe));
    const ms = (canal('emailing').liste || []).filter(x => !x.exemple && dans(x.envoiLe));
    return { posts: ps.length, mails: ms.length };
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
    plan: 'Les campagnes et leurs contenus — chaque post et chaque newsletter s’y rattachent.',
    creer: 'Rédiger un post ou une newsletter, rattachés à leur campagne.',
    valider: 'Le contrôle de l’agent, puis la décision de la marque.',
    publier: 'Chaque post validé, réseau par réseau, et tout ce qui est programmé.',
    mesurer: 'Ce que chaque canal et chaque campagne ont donné.',
    parametres: 'Publication, sources, agent de contrôle, charte, pages Facebook, avis Google.',
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
    const d = D();
    const urg = (d.aFaire || []).filter(a => a.niveau === 'urgent').length;
    const e = etapes();
    const sauv = (canal('charte').sauvages || []).length;
    const b = (n, alerte) => n ? `<b class="${alerte ? 'urg' : ''}">${n}</b>` : '';
    return { vue: b(urg, true), plan: b(e.plan.n), creer: b(e.creer.n), valider: b(e.valider.n, e.valider.aValider && marque()),
      publier: e.publier.erreurs ? b(e.publier.erreurs, true) : b(e.publier.n), mesurer: b(sauv, true), parametres: '' };
  }
  /** Le rail : le menu du portail. Les sous-vues de l'étape ouverte se déplient dessous. */
  function rail() {
    const d = D(), e = S.data ? etapes() : null, badge = S.data ? badgesEtapes() : {};
    const mags = d.magasins || [];
    const nom = S.shop ? (d.magasin || magasinNom(S.shop) || 'Magasin ' + S.shop) : 'Tout le réseau';
    const opts = [`<option value="">Tout le réseau (marque)</option>`].concat(mags.map(m => `<option value="${esc(m.id)}"${String(m.id) === S.shop ? ' selected' : ''}>${esc(m.nom)}</option>`));
    if (S.shop && !mags.some(m => String(m.id) === S.shop)) { opts.push(`<option value="${esc(S.shop)}" selected>${esc(nom)}</option>`); }
    const n = v => v ? `<b>${v}</b>` : '';
    const sous = {
      plan: [['campagnes', 'Campagnes et contenus'], ['calendrier', 'Calendrier']],
      creer: [['post', 'Post réseaux sociaux', e && n(e.creer.posts)], ['newsletter', 'Newsletter', e && n(e.creer.nls)]],
      valider: [['a_valider', 'À valider', e && n(e.valider.aValider)], ['a_controler', 'À contrôler', e && n(e.valider.aControler)], ['refuse', 'Refusés', e && n(e.valider.refuses)]],
      mesurer: [['resultats', 'Résultats'], ['avis', 'Avis Google'], ['sauvages', 'Posts sauvages', badge.mesurer]],
      parametres: PARAM_VUES.filter(v => marque() || v[2]).map(v => [v[0], v[1]]),
    };
    const sousActive = { plan: S.planVue, creer: S.creerVue, valider: S.valVue, mesurer: S.mesVue, parametres: S.paramVue };
    const entree = o => {
      const on = S.onglet === o[0];
      const enfants = on && sous[o[0]] ? sous[o[0]].map(x => `<button class="mk-rail-i sous${sousActive[o[0]] === x[0] ? ' on' : ''}" data-a="nav" data-v="${o[0]}" data-s="${x[0]}"><span>${esc(x[1])}</span>${x[2] || ''}</button>`).join('') : '';
      return `<button class="mk-rail-i${on ? ' on' : ''}" data-a="nav" data-v="${o[0]}"${on ? ' aria-current="page"' : ''}><span>${o[2] ? `<i>${o[2]}</i>` : ''}${esc(o[1])}</span>${badge[o[0]] || ''}</button>${enfants}`;
    };
    const groupe = (titre, ids) => `<div class="mk-rail-g"><div class="mk-rail-t">${titre}</div>${ONGLETS.filter(o => ids.includes(o[0])).map(entree).join('')}</div>`;
    return `<div class="mk-rail-tete"><img src="../assets/img/logo.png" alt="L’Atelier by"><div class="mk-rail-sub">Marketing</div></div>
      <div class="mk-rail-vue"><label class="mk-lab" for="mk-vue">Vue</label>
        <select id="mk-vue" data-f="shop" title="La marque voit tout le réseau ; un magasin voit ses posts, ses newsletters et les campagnes qui le couvrent">${opts.join('')}</select></div>
      <nav class="mk-rail-nav">
        ${groupe('Pilotage', ['vue'])}
        ${groupe('Le flux', ['plan', 'creer', 'valider', 'publier', 'mesurer'])}
        ${groupe('Réglages', ['parametres'])}
        <div class="mk-rail-g"><div class="mk-rail-t">Liens</div>
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
    if (!S.data) { return '<div class="mk-attente">Lecture des campagnes, des posts et des newsletters…</div>'; }
    switch (S.onglet) {
      case 'plan': return vPlan();
      case 'creer': return vCreer();
      case 'valider': return vValider();
      case 'publier': return vPublier();
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
  /** L'étape du flux où un post attend son prochain geste. */
  function etapeDuPost(p) {
    return { brouillon: 'creer', a_controler: 'valider', a_valider: 'valider', refuse: 'valider', valide: 'publier', publie: 'mesurer' }[p.statut] || 'valider';
  }
  function etapes() {
    const ps = posts(), mois = AUJ.slice(0, 7), nl = nls();
    const vivantes = campagnes().filter(c => ['en_cours', 'a_venir', 'sans_date'].includes(c.phase));
    const erreurs = ps.reduce((n, p) => n + (p.publications || []).filter(x => x.statut === 'erreur').length, 0);
    return {
      plan: { n: vivantes.length, muettes: vivantes.filter(c => { const k = contenusDe(c); return k.posts.length + k.nls.length + k.parDates.length === 0; }).length },
      creer: { n: ps.filter(p => p.statut === 'brouillon').length + nl.filter(x => x.statut === 'draft').length,
        posts: ps.filter(p => p.statut === 'brouillon').length, nls: nl.filter(x => x.statut === 'draft').length },
      valider: { n: ps.filter(p => p.statut === 'a_controler' || p.statut === 'a_valider').length,
        aValider: ps.filter(p => p.statut === 'a_valider').length, aControler: ps.filter(p => p.statut === 'a_controler').length, refuses: ps.filter(p => p.statut === 'refuse').length },
      publier: { n: ps.filter(p => p.statut === 'valide').length + nl.filter(x => x.statut === 'sched').length, erreurs,
        prets: ps.filter(p => p.statut === 'valide' && !(p.publications || []).some(x => x.statut === 'programme')).length,
        programmes: ps.filter(p => (p.publications || []).some(x => x.statut === 'programme')).length, nls: nl.filter(x => x.statut === 'sched').length },
      mesurer: { n: ps.filter(p => p.statut === 'publie' && String((p.publie || {}).le || '').startsWith(mois)).length + nl.filter(x => ['sent', 'live'].includes(x.statut) && String(x.envoiLe || '').startsWith(mois)).length },
    };
  }
  /** Les contenus d'une campagne : rattachés d'abord ; à défaut, ceux de sa période qui ne sont rattachés à rien. */
  function contenusDe(c) {
    const ps = posts().filter(p => p.campagneId === c.id);
    const ns = nls().filter(x => x.campagneId === c.id);
    let parDates = [];
    if (c.debut) {
      const fin = c.fin || c.debut;
      const dans = d => d && d.slice(0, 10) >= c.debut && d.slice(0, 10) <= fin;
      parDates = posts().filter(p => p.campagneId == null && p.statut !== 'refuse' && dans((p.publie && p.publie.le) || p.publierLe)).map(p => ({ type: 'post', x: p }))
        .concat(nls().filter(x => x.campagneId == null && dans(x.envoiLe)).map(x => ({ type: 'nl', x })));
    }
    return { posts: ps, nls: ns, parDates };
  }
  const badgeReseaux = l => (l || ['facebook']).map(r => { const x = RESEAUX.find(y => y[0] === r); return x ? `<span class="mk-rs mk-rs-${r}" title="${x[1]}">${x[2]}</span>` : ''; }).join('');

  /* --- tableau de bord ----------------------------------------------------------------------- */
  function vVue() {
    const d = D(), e = etapes(), faire = d.aFaire || [];
    const pubOff = !(canal('social').publication || {}).reelle;
    const mOuv = (canal('emailing').chiffres || {}).tauxOuverture;
    const av = canal('avis'), note = av.dispo ? (S.shop ? ((av.magasins || [])[0] || {}).note : (av.reseau || {}).moyenne) : null;
    const etape = (id, num, titre, gros, sous, alerte) => `<button class="mk-etape${alerte ? ' alerte' : ''}" data-a="onglet" data-v="${id}"><span class="num">${num}</span><span class="t">${titre}</span><span class="v">${gros}</span><span class="d">${sous}</span></button>`;
    const chaine = [
      etape('plan', '1', 'Planifier', pl(e.plan.n, 'campagne'), e.plan.muettes ? `<b class="ko">${pl(e.plan.muettes, 'campagne')} sans contenu</b>` : 'en cours ou à venir', e.plan.muettes),
      etape('creer', '2', 'Créer', pl(e.creer.n, 'brouillon'), `${pl(e.creer.posts, 'post')} · ${pl(e.creer.nls, 'newsletter')}`),
      etape('valider', '3', 'Valider', fN(e.valider.n) + ' <small>à décider</small>', `${fN(e.valider.aValider)} à valider · ${fN(e.valider.aControler)} à contrôler`, e.valider.aValider && marque()),
      etape('publier', '4', 'Publier', fN(e.publier.n) + ' <small>prêts ou programmés</small>', e.publier.erreurs ? `<b class="ko">${pl(e.publier.erreurs, 'échec')}</b>` : (pubOff ? 'publication automatique éteinte' : `${fN(e.publier.programmes)} programmé${e.publier.programmes > 1 ? 's' : ''} · ${pl(e.publier.nls, 'newsletter')}`), e.publier.erreurs),
      etape('mesurer', '5', 'Mesurer', fN(e.mesurer.n) + ' <small>publiés ce mois</small>', `ouverture ${mOuv == null ? '—' : fP(mOuv)} · Google ${note == null ? '—' : nf(note, 2) + ' ★'}`),
    ].join('<span class="mk-fleche">›</span>');
    return `<div class="mk-chaine">${chaine}</div>
      <div class="mk-grille mk-g2" style="margin-top:14px">
        <div class="mk-card"><div class="mk-ct"><span class="mk-k">À faire</span><span class="mk-mini">${faire.length ? pl(faire.length, 'point') + ' · toutes étapes' : 'rien en attente'}</span></div>
          ${faire.length ? `<ul class="mk-liste">${faire.map((a, i) => `<li class="mk-canal-${esc(a.canal)}" data-a="faire" data-i="${i}"><span class="mk-pastille"></span><div class="tx"><div class="tt">${a.niveau === 'urgent' ? '<span class="mk-badge urg">urgent</span> ' : ''}${esc(a.titre)}</div><div class="dd">${esc(a.detail)}</div></div><span class="qd">${esc(nomEtape((a.cible || {}).onglet) || CANAL_NOM[a.canal] || a.canal)}</span></li>`).join('')}</ul>`
            : '<div class="mk-vide">Aucun post à décider, aucune publication en échec, aucune campagne muette. Rien ne presse.</div>'}</div>
        <div class="mk-card"><div class="mk-ct"><span class="mk-k">Les 14 prochains jours</span><span class="mk-mini">campagnes, envois, publications</span></div>${agendaJours(AUJ, 14)}</div>
      </div>`;
  }
  const nomEtape = id => { const o = ONGLETS.find(x => x[0] === id); return o ? (o[2] ? o[2] + ' · ' : '') + o[1] : ''; };
  const chips = (cle, liste, val) => `<div class="mk-chips" style="--c:#222">${liste.map(v => `<button class="${v[0] === val ? 'on' : ''}${v[3] ? ' urg' : ''}" data-a="${cle}" data-v="${v[0]}">${v[1]}${v[2] != null ? ' <b>' + v[2] + '</b>' : ''}</button>`).join('')}</div>`;

  /* --- 1 · Planifier ---------------------------------------------------------------------------- */
  function vPlan() {
    return `<div class="mk-barre-f">${chips('plan-vue', [['campagnes', 'Campagnes et contenus', null], ['calendrier', 'Calendrier', null]], S.planVue)}<span class="sp"></span>
      <span class="mk-aide">Une campagne, puis ses contenus : chaque post et chaque newsletter s’y rattachent.</span></div>
      ${S.planVue === 'calendrier' ? vCalendrier() : vCampagnes()}`;
  }

  /* --- 2 · Créer ------------------------------------------------------------------------------- */
  function vCreer() {
    const e = etapes();
    const tete = `<div class="mk-barre-f">${chips('creer-vue', [['post', 'Post réseaux sociaux', e.creer.posts || null], ['newsletter', 'Newsletter', e.creer.nls || null]], S.creerVue)}</div>`;
    if (S.creerVue === 'newsletter') {
      const c = S.nlCampagne != null ? campagneDe(S.nlCampagne) : null;
      return tete + (c ? `<div class="mk-msg ok" style="font-weight:500">La prochaine newsletter créée sera rattachée à la campagne « ${esc(c.nom)} ». <button class="mk-btn petit" data-a="nl-camp-x" style="margin-left:auto;font-size:11px">Ne pas rattacher</button></div>` : '') + vEmail();
    }
    if (!S.compose) { S.compose = composeNeuf(); }
    const brouillons = posts().filter(p => p.statut === 'brouillon');
    return tete + composer() + `<div class="mk-card"><div class="mk-ct"><span class="mk-k">Brouillons</span><span class="mk-mini">${brouillons.length ? 'à soumettre au contrôle quand ils sont prêts' : 'aucun'}</span></div>
      ${brouillons.length ? `<div class="mk-cb"><div class="mk-posts">${brouillons.map(cartePost).join('')}</div></div>` : '<div class="mk-vide">Un post enregistré en brouillon reste ici, au magasin : l’agent ne relit que ce qui est soumis.</div>'}</div>`;
  }

  /* --- 3 · Valider ----------------------------------------------------------------------------- */
  function vValider() {
    const s = canal('social');
    if (!s.dispo) { return indispo('social', 'Réseaux sociaux'); }
    const e = etapes().valider;
    const vues = [['a_valider', 'À valider', e.aValider], ['a_controler', 'À contrôler', e.aControler], ['refuse', 'Refusés', e.refuses]];
    const v = vues.find(x => x[0] === S.valVue) || vues[0];
    const ps = posts().filter(p => p.statut === v[0]);
    const vide = { a_valider: 'Rien à décider : tout ce que l’agent a relu est tranché.', a_controler: 'Aucun post en attente de l’agent.', refuse: 'Aucun post refusé.' }[v[0]];
    return `<div class="mk-barre-f">${chips('val-vue', vues, v[0])}<span class="sp"></span><span class="mk-aide">${marque() ? 'L’agent propose une note, la charte relit texte et visuels : la décision reste à la marque.' : 'La marque valide ou refuse — un refus dit quoi corriger.'}</span></div>
      <div class="mk-cote"><div>${ps.length ? `<div class="mk-posts">${ps.map(cartePost).join('')}</div>` : `<div class="mk-card"><div class="mk-vide">${vide}</div></div>`}</div><div>${carteCharte()}</div></div>`;
  }

  /* --- 4 · Publier ----------------------------------------------------------------------------- */
  function vPublier() {
    const s = canal('social');
    if (!s.dispo) { return indispo('social', 'Réseaux sociaux'); }
    const pubR = s.publication || {};
    const prets = posts().filter(p => p.statut === 'valide');
    const prog = [];
    // Un post programmé sur trois réseaux à la même heure : une ligne, trois pastilles.
    posts().forEach(p => {
      const parHeure = {};
      (p.publications || []).filter(x => x.statut === 'programme').forEach(x => { (parHeure[x.prevuLe] = parHeure[x.prevuLe] || []).push(x.reseau); });
      Object.keys(parHeure).forEach(q => prog.push({ quand: q, type: 'post', p, reseaux: parHeure[q] }));
    });
    nls().filter(x => x.statut === 'sched' && x.envoiLe).forEach(x => prog.push({ quand: x.envoiLe, type: 'nl', x }));
    prog.sort((a, b) => a.quand < b.quand ? -1 : 1);
    const bandeau = pubR.reelle ? `<div class="mk-msg ok" style="font-weight:500">Publication réelle active${pubR.auto ? ' — un post validé se programme tout seul à son heure' : ''}. L’horloge passe toutes les 5 minutes.</div>`
      : `<div class="mk-err" style="background:#fdf1e2;color:var(--mk-att)">Publication automatique éteinte : rien ne part sur les réseaux depuis le portail. ${marque() ? '<a href="#" data-a="aller-param" data-v="publication">Paramètres → Publication</a> pour l’activer ; en attendant, publiez à la main puis marquez le post publié.' : 'Publiez à la main, puis marquez le post publié avec son identifiant Facebook.'}</div>`;
    return `${bandeau}
      <div class="mk-grille mk-g2">
        <div><div class="mk-k" style="margin:2px 0 8px">Prêts à publier · ${prets.length}</div>${prets.length ? `<div class="mk-posts" style="grid-template-columns:minmax(0,1fr)">${prets.map(cartePost).join('')}</div>` : '<div class="mk-card"><div class="mk-vide">Aucun post validé en attente : tout ce qui est validé est parti.</div></div>'}</div>
        <div class="mk-card"><div class="mk-ct"><span class="mk-k">Programmé</span><span class="mk-mini">posts et newsletters, dans l’ordre</span></div>
          ${prog.length ? `<ul class="mk-liste">${prog.map(e => e.type === 'post'
            ? `<li class="mk-canal-social" data-a="post-voir" data-id="${esc(e.p.id)}"><span class="mk-pastille"></span><div class="tx"><div class="tt">${badgeReseaux(e.reseaux)} ${esc(e.p.magasin || 'Réseau')}</div><div class="dd">${esc(extrait(e.p.message, 80))}</div></div><span class="qd">${esc(fDT(e.quand))}</span></li>`
            : `<li class="mk-canal-email" data-a="creer-vue" data-v="newsletter"><span class="mk-pastille"></span><div class="tx"><div class="tt">✉ ${esc(e.x.nom)}</div><div class="dd">${esc(e.x.objet || 'newsletter programmée')}${e.x.testEnvoye ? '' : ' · <span class="ko">pas d’envoi test</span>'}</div></div><span class="qd">${esc(fDT(e.quand))}</span></li>`).join('')}</ul>`
            : '<div class="mk-vide">Rien de programmé.</div>'}</div>
      </div>`;
  }

  /* --- 5 · Mesurer ----------------------------------------------------------------------------- */
  function vMesurer() {
    const sauv = (canal('charte').sauvages || []).length;
    const tete = `<div class="mk-barre-f">${chips('mes-vue', [['resultats', 'Résultats', null], ['avis', 'Avis Google', null], ['sauvages', 'Posts sauvages', sauv, sauv > 0]], S.mesVue)}</div>`;
    if (S.mesVue === 'avis') { return tete + vAvis(); }
    if (S.mesVue === 'sauvages') {
      const b = canal('charte');
      return tete + `<div class="mk-cote"><div>${!b.dispo ? indispo('charte', 'Brand Guard') : (sauv ? `<div class="mk-posts">${b.sauvages.map(carteSauvage).join('')}</div>` : '<div class="mk-card"><div class="mk-vide">Aucun post sauvage sur 30 jours : tout ce qui est paru sur les pages est passé par le portail.</div></div>')}</div><div>${carteCharte()}</div></div>`;
    }
    const publies = posts().filter(p => p.statut === 'publie').slice(0, 30);
    const envoyees = nls().filter(x => ['sent', 'live'].includes(x.statut));
    const vivantes = campagnes().filter(c => ['en_cours', 'a_venir', 'terminee'].includes(c.phase)).slice(0, 12);
    return tete + `<div class="mk-grille mk-gk">${kpiCampagnes()}${kpiEmail()}${kpiSocial()}${kpiAvis()}${kpiCharte()}</div>
      <div class="mk-grille mk-g2" style="margin-top:14px">
        <div class="mk-card"><div class="mk-ct"><span class="mk-k">Publiés</span><span class="mk-mini">les 30 derniers, réseau par réseau</span></div>
          ${publies.length ? `<ul class="mk-liste">${publies.map(p => `<li class="mk-canal-social" data-a="post-voir" data-id="${esc(p.id)}"><span class="mk-pastille"></span><div class="tx"><div class="tt">${esc(p.magasin || 'Réseau')}${p.campagneId && campagneDe(p.campagneId) ? ' · <span class="mu">' + esc(campagneDe(p.campagneId).nom) + '</span>' : ''}</div><div class="dd">${esc(extrait(p.message, 90))}</div>
              <div class="dd">${(p.publications || []).length ? p.publications.map(x => x.lien ? `<a href="${esc(x.lien)}" target="_blank" rel="noopener" data-a="lien">${badgeReseaux([x.reseau])} voir ↗</a>` : badgeReseaux([x.reseau])).join(' ') : '<span class="mu">publié à la main' + ((p.publie || {}).fbId ? ' · id ' + esc(p.publie.fbId) : '') + '</span>'}</div></div><span class="qd">${esc(fDT((p.publie || {}).le))}</span></li>`).join('')}</ul>` : '<div class="mk-vide">Aucun post publié pour l’instant.</div>'}</div>
        <div class="mk-card"><div class="mk-ct"><span class="mk-k">Newsletters envoyées</span><span class="mk-mini">ouvertures et clics réels</span></div>
          ${envoyees.length ? `<div class="mk-defile"><table class="mk-tab"><thead><tr><th>Newsletter</th><th class="n">Envois</th><th class="n">Ouv.</th><th class="n">Clics</th></tr></thead><tbody>${envoyees.map(x => `<tr><td class="nom">${esc(x.nom)}<small>${esc(fDT(x.envoiLe))}${x.campagneId && campagneDe(x.campagneId) ? ' · ' + esc(campagneDe(x.campagneId).nom) : ''}</small></td><td class="n">${fN(x.envoyes)}</td><td class="n">${x.envoyes ? fP(Math.round(1000 * x.ouvertures / x.envoyes) / 10) : '—'}</td><td class="n">${x.envoyes ? fP(Math.round(1000 * x.clics / x.envoyes) / 10) : '—'}</td></tr>`).join('')}</tbody></table></div>` : '<div class="mk-vide">Aucune newsletter envoyée (les exemples ne comptent pas).</div>'}</div>
      </div>
      <div class="mk-card" style="margin-top:14px"><div class="mk-ct"><span class="mk-k">Campagnes · ce qui a été dit</span><span class="mk-mini">contenus rattachés, publiés ou non</span></div>
        <div class="mk-defile"><table class="mk-tab"><thead><tr><th>Campagne</th><th>Période</th><th class="n">Posts</th><th class="n">Publiés</th><th class="n">Newsletters</th><th class="n">Envoyées</th></tr></thead><tbody>
        ${vivantes.map(c => { const k = contenusDe(c); return `<tr data-a="camp-voir" data-id="${c.id}" style="cursor:pointer"><td class="nom">${esc(c.nom)}<small>${esc(PHASE[c.phase] || '')}</small></td><td class="per">${esc(periode(c.debut, c.fin))}</td><td class="n">${k.posts.length}</td><td class="n">${k.posts.filter(p => p.statut === 'publie').length}</td><td class="n">${k.nls.length}</td><td class="n">${k.nls.filter(x => ['sent', 'live'].includes(x.statut)).length}</td></tr>`; }).join('') || '<tr><td colspan="6"><div class="mk-vide">Aucune campagne.</div></td></tr>'}
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
  function kpiSocial() {
    const c = canal('social'); const k = c.chiffres || {};
    return kpi('social', 'valider', 'Réseaux sociaux', `<div class="v">${fN(k.aTraiter)}<small>post${k.aTraiter > 1 ? 's' : ''} à traiter</small></div>
      <div class="d"><b>${fN(k.aPublier)}</b> prêt${k.aPublier > 1 ? 's' : ''} à publier · <b>${fN(k.publiesMois)}</b> publié${k.publiesMois > 1 ? 's' : ''} ce mois</div>
      <div class="d">${fN(k.refuses)} refusé${k.refuses > 1 ? 's' : ''} · note moyenne ${k.noteMoyenne == null ? '—' : nf(k.noteMoyenne, 1) + '/5'}</div>`, c.dispo, c.raison);
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
    if (!lignes.length) { return '<div class="mk-vide">Rien de daté sur cette période : ni campagne qui démarre, ni envoi, ni post prévu.</div>'; }
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
    const filtres = [['campagne', 'Campagnes'], ['email', 'Newsletters'], ['social', 'Posts']].map(f =>
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
      ${['campagnes', 'emailing', 'social'].map(k => indispo(k, { campagnes: 'Campagnes', emailing: 'Emailing', social: 'Réseaux sociaux' }[k])).join('')}`;
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
        ${p ? `<br>Prises de parole sur la période : ${pl(p.posts, 'post')}, ${pl(p.mails, 'newsletter')}` : ''}`;
      acts = `<button class="mk-btn petit" data-a="camp-voir" data-id="${r.id}">Voir la campagne</button><button class="mk-btn petit prim" data-a="post-camp" data-id="${r.id}">Préparer un post</button>`;
    } else if (e.canal === 'email') {
      tx = `<b>${esc(r.nom)}</b>${r.exemple ? ' <span class="mk-badge">exemple</span>' : ''}<br>${esc(r.objet || '')}<br>${esc(NL_ST[r.statut] || r.statut)} · ${esc(fDT(r.envoiLe))}${r.envoyes ? ` · ${pl(r.envoyes, 'envoi')}, ${pl(r.ouvertures, 'ouverture')}, ${pl(r.clics, 'clic')}` : ''}`;
      acts = '<button class="mk-btn petit" data-a="onglet" data-v="email">Ouvrir l’emailing</button>';
    } else if (e.sauvage) {
      tx = `<b>${esc(r.boutique || 'Page')}</b> · <span class="ko">post sauvage</span> · ${esc(fDT(r.publieLe || r.quand))} · score ${r.score}/100<br>${esc(extrait(r.texte, 200))}`;
      acts = `<button class="mk-btn petit" data-a="mes-vue" data-v="sauvages">Voir les sauvages</button>${r.lien ? `<a class="mk-btn petit" href="${esc(r.lien)}" target="_blank" rel="noopener">Facebook ↗</a>` : ''}`;
    } else {
      tx = `<b>${esc(r.magasin || 'Réseau')}</b> · ${esc(r.format)} · ${esc((POST_ST[r.statut] || [r.statut])[0])} · ${esc(fDT((r.publie && r.publie.le) || r.publierLe))}<br>${esc(extrait(r.message, 220))}`;
      acts = `<button class="mk-btn petit prim" data-a="post-voir" data-id="${esc(r.id)}">Ouvrir le post</button>`;
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
      const vive = x.phase === 'en_cours' || x.phase === 'a_venir';
      const n = k.posts.length + k.nls.length;
      const etat = n ? [k.posts.length ? pl(k.posts.length, 'post') : '', k.nls.length ? pl(k.nls.length, 'newsletter') : ''].filter(Boolean).join(' · ')
        + `<small>${k.posts.filter(p => p.statut === 'publie').length} publié${k.posts.filter(p => p.statut === 'publie').length > 1 ? 's' : ''} · ${k.posts.filter(p => p.statut === 'a_valider' || p.statut === 'a_controler').length} à valider</small>`
        : (k.parDates.length ? `<span class="mu">${pl(k.parDates.length, 'contenu')} sur la période</span><small>non rattaché${k.parDates.length > 1 ? 's' : ''}</small>` : `<span class="${vive ? 'wa' : 'mu'}">aucun contenu${vive ? ' ⚠' : ''}</span>`);
      const pct = x.budget > 0 ? Math.min(100, 100 * x.depense / x.budget) : 0;
      const ouverte = S.campOuverte === x.id;
      return `<tr id="camp-${x.id}" class="${S.campSel === x.id || ouverte ? 'sel' : ''}">
        <td class="masque-mob" style="width:46px">${x.image ? `<img class="mk-vign" src="${esc(image(x.image))}" alt="" loading="lazy" onerror="this.style.visibility='hidden'">` : `<span class="mk-vign" style="background:${esc(x.typeCouleur || 'var(--mk-doux)')};opacity:.25"></span>`}</td>
        <td class="nom"><button class="mk-lien-nom" data-a="camp-ouvrir" data-id="${x.id}">${ouverte ? '▾' : '▸'} ${esc(x.nom)}</button><small><span class="mk-pastille" style="background:${esc(x.typeCouleur || '#bbb')};width:7px;height:7px"></span> ${esc(x.type || 'sans type')} · ${x.reseau ? 'réseau' : pl(x.nBoutiques, 'magasin')}</small></td>
        <td class="per">${esc(periode(x.debut, x.fin))}<small>${esc(PHASE[x.phase] || '')}</small></td>
        <td><span class="mk-statut" style="color:${esc(x.statutTexte)};background:${esc(x.statutFond)}">${esc(x.statutNom)}</span></td>
        <td class="n masque-mob">${fE(x.depense)}<small>sur ${fE(x.budget)}</small><div class="mk-barre" style="width:90px;margin-left:auto;margin-top:3px"><i style="width:${pct.toFixed(1)}%"></i></div></td>
        <td>${etat}</td>
        <td><div class="acts"><button class="mk-btn petit prim" data-a="post-camp" data-id="${x.id}" title="Un post rattaché à la campagne, pré-rempli : la marque, la période, le hashtag">+ Post</button>
          <button class="mk-btn petit" data-a="nl-camp" data-id="${x.id}" title="Une newsletter rattachée à la campagne">+ Newsletter</button>
          ${marque() ? `<a class="mk-btn petit" href="../assistant/?id=${x.id}" target="_blank" rel="noopener" title="L’assistant de campagne : offre, canaux, rétroplanning">Assistant ↗</a>` : ''}</div></td></tr>
        ${ouverte ? `<tr class="mk-sous-ligne"><td colspan="7">${contenusCampagne(x, k)}</td></tr>` : ''}`;
    }).join('');
    return `<div class="mk-barre-f mk-canal-campagne"><div class="mk-chips">${chipsF}</div><span class="sp"></span>
        <input class="mk-in" type="search" placeholder="Chercher une campagne" value="${esc(S.campQ)}" data-f="campQ" style="min-width:200px">
        ${marque() ? '<a class="mk-btn prim" href="../assistant/" target="_blank" rel="noopener">+ Nouvelle campagne</a><a class="mk-btn" href="../#/campagnes" target="_blank" rel="noopener">Budget, mesure, note ↗</a>' : ''}</div>
      <div class="mk-card"><div class="mk-defile"><table class="mk-tab"><thead><tr><th class="masque-mob"></th><th>Campagne</th><th>Période</th><th>Statut</th><th class="n masque-mob">Dépensé</th><th>Contenus</th><th></th></tr></thead>
        <tbody>${lignes || `<tr><td colspan="7"><div class="mk-vide">Aucune campagne ${esc(f[1].toLowerCase())}${rq ? ' pour « ' + esc(S.campQ) + ' »' : ''}.</div></td></tr>`}</tbody></table></div></div>`;
  }
  /** Les contenus d'une campagne, à l'étape où ils en sont. */
  function contenusCampagne(c, k) {
    const lp = p => `<li class="mk-canal-social" data-a="post-voir" data-id="${esc(p.id)}"><span class="mk-pastille"></span><div class="tx"><div class="tt">${badgeReseaux(p.reseaux)} ${esc(p.magasin || 'Réseau')} ${badgePost(p)}</div><div class="dd">${esc(extrait(p.message, 90))}</div></div><span class="qd">${esc(nomEtape(etapeDuPost(p)))}<small>${esc(fDT((p.publie && p.publie.le) || p.publierLe))}</small></span></li>`;
    const ln = x => `<li class="mk-canal-email" data-a="creer-vue" data-v="newsletter"><span class="mk-pastille"></span><div class="tx"><div class="tt">✉ ${esc(x.nom)} <span class="mk-badge">${esc(NL_ST[x.statut] || x.statut)}</span></div><div class="dd">${esc(x.objet || '')}${x.envoyes ? ' · ' + pl(x.envoyes, 'envoi') + ', ' + pl(x.ouvertures, 'ouverture') : ''}</div></div><span class="qd">${esc(fDT(x.envoiLe))}</span></li>`;
    const tous = k.posts.map(lp).concat(k.nls.map(ln));
    return `<div class="mk-contenus">${tous.length ? `<ul class="mk-liste">${tous.join('')}</ul>` : '<div class="mk-vide">Aucun contenu rattaché. Ajoutez un post ou une newsletter : ils porteront le nom de la campagne jusqu’aux résultats.</div>'}
      ${k.parDates.length ? `<div class="mk-aide" style="padding:8px 16px">Sur la même période, non rattachés : ${k.parDates.map(d => d.type === 'post' ? 'post ' + esc(extrait(d.x.message, 40)) : 'newsletter ' + esc(d.x.nom)).join(' · ')}.</div>` : ''}</div>`;
  }
  /* --- 4. réseaux sociaux ---------------------------------------------------------------- */
  /** Deux volets : les posts Facebook (ce qu'on publie) et les avis Google (ce qu'on dit de nous). */
  function badgePost(p) { const st = POST_ST[p.statut] || [p.statut, '']; return `<span class="mk-badge ${st[1]}">${esc(st[0])}</span>`; }
  function cartePost(p) {
    const R = regles();
    const quand = (p.publie && p.publie.le) || p.publierLe;
    const ag = p.agent || {};
    const nv = ag.note != null ? niveau(ag.note) : null;
    const ecarts = (p.ecarts || []);
    const peutEcarter = marque() && p.statut !== 'publie';
    const medias = (p.medias || []).map(m => (m.url ? `<a href="${esc(m.url)}" target="_blank" rel="noopener">${esc(m.nom || 'visuel')}</a>` : esc(m.nom || 'média') + ' <span class="wa">(non téléversé)</span>') + (m.largeur ? ' · ' + m.largeur + ' px' : '') + (m.alt ? '' : ' · <span class="wa">sans texte alternatif</span>')).join(', ');
    const camp = p.campagneId != null ? campagneDe(p.campagneId) : null;
    const agent = (ag.note != null || ecarts.length) ? `<div class="mk-agent"><div class="l1"><span class="mk-k" style="font-size:9.5px">Agent de contrôle</span>
        ${nv ? `<span class="mk-note" style="background:${esc(nv.couleur || '#777')}" title="${esc(nv.nom)}">${ag.note}/5</span>` : ''}<span>${esc(ag.resume || '')}</span>
        ${ag.passages > 1 ? `<span class="mu" style="margin-left:auto">${ag.passages} passages</span>` : ''}</div>
        ${ecarts.length ? `<ul class="mk-ecarts">${ecarts.map(e => `<li class="${e.statut === 'ignore' ? 'ignore' : ''}"><span class="g g${Math.min(3, Math.max(1, e.gravite))}" title="gravité ${e.gravite}"></span>
          <div class="tx"><b>${esc(e.regle || e.type)}</b> — ${esc(e.message)}${e.extrait ? '<small>« ' + esc(e.extrait) + ' »</small>' : ''}${e.statut === 'ignore' ? '<small>écarté (dérogation)</small>' : ''}</div>
          ${peutEcarter ? `<button class="mk-btn petit" data-a="ecart" data-id="${esc(p.id)}" data-e="${e.id}" data-v="${e.statut === 'ignore' ? 'ouvert' : 'ignore'}"${S.envoi ? ' disabled' : ''} title="${e.statut === 'ignore' ? 'Rouvrir l’écart' : 'Accorder une dérogation : l’écart reste affiché, il sort de la note'}">${e.statut === 'ignore' ? 'Rouvrir' : 'Écarter'}</button>` : ''}</li>`).join('')}</ul>` : ''}</div>` : '';
    const dc = p.decision || {};
    const decision = dc.le ? `<div class="mk-decision"><b>${p.statut === 'refuse' ? 'Refusé' : 'Validé'}${dc.note != null ? ' ' + dc.note + '/5' : ''}</b>${dc.par ? ' par ' + esc(dc.par) : ''} le ${esc(fDT(dc.le))}${dc.famille ? ' · ' + esc(dc.famille) + ' — ' + esc(dc.type || '') : ''}${dc.commentaire ? '<br>' + esc(dc.commentaire) : ''}</div>` : '';
    const pubs = p.publications || [];
    const publie = p.statut === 'publie' ? `<div class="mk-decision" style="border-color:rgba(45,122,62,.35)"><b class="ok">Publié</b> le ${esc(fDT(p.publie.le))}${pubs.length ? ' · ' + pubs.map(x => x.lien ? `<a href="${esc(x.lien)}" target="_blank" rel="noopener">${badgeReseaux([x.reseau])} voir ↗</a>` : badgeReseaux([x.reseau])).join(' ') : (p.publie.fbId ? ' · id Facebook ' + esc(p.publie.fbId) : ' · <span class="wa">sans id Facebook : Brand Guard le prendra pour un post sauvage</span>')}</div>` : '';
    return `<div class="mk-post${S.postSel === p.id ? ' ouvert' : ''}" id="post-${esc(p.id)}"><div class="hd">${badgeReseaux(p.reseaux)}<span class="mag">${esc(p.magasin || 'Réseau')}</span>${badgePost(p)}<span class="mk-badge">${esc(p.format)}</span>
        <span class="qd">${quand ? (p.statut === 'publie' ? 'publié ' : 'prévu ') + esc(fDT(quand)) : 'sans date'}</span></div>
      ${camp ? `<div class="mk-camp-l"><span class="mk-pastille" style="background:${esc(camp.typeCouleur || 'var(--mk-camp)')}"></span>Campagne « ${esc(camp.nom)} »</div>` : ''}
      <div class="msg">${esc(p.message)}</div>
      <div class="meta"><span>${esc(p.auteur || '')}</span><span>soumis ${esc(fDT(p.soumisLe))}</span>${p.lien ? `<a href="${esc(p.lien)}" target="_blank" rel="noopener">${esc(extrait(p.lien, 40))}</a>` : ''}${medias ? '<span>' + medias + '</span>' : ''}</div>
      ${agent}${decision}${publie}${actionsPost(p, R)}</div>`;
  }
  function actionsPost(p, R) {
    const dis = S.envoi ? ' disabled' : '';
    if (p.statut === 'brouillon') {
      return `<div class="mk-form"><div class="ligne"><button class="mk-btn prim" data-a="soumettre" data-id="${esc(p.id)}"${dis}>Soumettre au contrôle</button><button class="mk-btn" data-a="reprendre" data-id="${esc(p.id)}">Reprendre dans le rédacteur</button></div></div>`;
    }
    if (p.statut === 'a_controler') {
      return `<div class="mk-form"><div class="ligne"><button class="mk-btn prim" data-a="controler" data-id="${esc(p.id)}"${dis}>Lancer le contrôle</button><span class="mk-aide">L’agent relit le texte, le lien et les visuels contre la charte.</span></div></div>`;
    }
    if (p.statut === 'refuse') {
      return `<div class="mk-form"><div class="ligne"><button class="mk-btn" data-a="reprendre" data-id="${esc(p.id)}">Corriger et resoumettre</button><span class="mk-aide">Un nouveau post, pré-rempli avec celui-ci.</span></div></div>`;
    }
    if (p.statut === 'valide') { return panneauPublication(p); }
    if (p.statut === 'a_valider' && marque()) {
      const d = S.dec[p.id] || (S.dec[p.id] = { note: (p.agent && p.agent.note) || R.seuil || 4, famille: '', type: '', commentaire: '' });
      const fam = (R.familles || []).find(f => f.nom === d.famille);
      const sous = d.note < (R.seuil || 4);
      return `<div class="mk-form"><label>Décision — la note de l’agent est une proposition</label>
        <div class="ligne"><div class="mk-notes">${[1, 2, 3, 4, 5].map(n => { const nv = niveau(n); return `<button class="${d.note === n ? 'on' : ''}" style="${d.note === n ? 'background:' + esc(nv.couleur || '#555') : ''}" data-a="dec-note" data-id="${esc(p.id)}" data-v="${n}" title="${esc(nv.nom)}">${n}</button>`; }).join('')}</div>
          <span class="mk-aide">${esc(niveau(d.note).nom)}${sous ? ' · sous le seuil de ' + R.seuil + ' : famille et type obligatoires' : ''}</span></div>
        <div class="ligne"><select data-f="dec-famille" data-id="${esc(p.id)}"><option value="">Famille d’écart${sous ? ' *' : ''}</option>${(R.familles || []).map(f => `<option${f.nom === d.famille ? ' selected' : ''}>${esc(f.nom)}</option>`).join('')}</select>
          <select data-f="dec-type" data-id="${esc(p.id)}"${fam ? '' : ' disabled'}><option value="">Type${sous ? ' *' : ''}</option>${(fam ? fam.types : []).map(t => `<option${t === d.type ? ' selected' : ''}>${esc(t)}</option>`).join('')}</select></div>
        <textarea class="mk-in" style="min-height:52px" placeholder="Commentaire au magasin (facultatif)" data-f="dec-com" data-id="${esc(p.id)}">${esc(d.commentaire)}</textarea>
        <div class="ligne"><button class="mk-btn ok" data-a="decider" data-v="valide" data-id="${esc(p.id)}"${dis}>Valider</button><button class="mk-btn ko" data-a="decider" data-v="refuse" data-id="${esc(p.id)}"${dis}>Refuser</button>
          <button class="mk-btn petit" data-a="controler" data-id="${esc(p.id)}"${dis} style="margin-left:auto">Relancer le contrôle</button></div></div>`;
    }
    if (p.statut === 'a_valider') {
      return '<div class="mk-form"><span class="mk-aide">Contrôlé par l’agent — en attente de la décision de la marque.</span></div>';
    }
    return '';
  }
  /** Ce qu'un réseau exige — le miroir, à l'écran, de spPreparer() côté serveur. */
  function preparer(p, reseau) {
    const txt = String(p.message || '').trim(), imgs = (p.medias || []).filter(m => /^https?:\/\//.test(m.url || ''));
    const hash = (txt.match(/#[\p{L}\p{N}_]+/gu) || []).length, n = [...txt].length, pb = [];
    if (reseau === 'facebook' && !txt && !imgs.length) { pb.push('un texte ou un visuel'); }
    if (reseau === 'instagram') { if (!imgs.length) { pb.push('un visuel téléversé'); } if (imgs.length > 10) { pb.push('10 visuels au plus'); } if (n > 2200) { pb.push('2 200 caractères au plus'); } if (hash > 30) { pb.push('30 hashtags au plus'); } }
    if (reseau === 'linkedin') { if (p.magasinId) { pb.push('un post de la marque seulement'); } if (n > 3000) { pb.push('3 000 caractères au plus'); } if (!txt) { pb.push('un texte'); } }
    return pb;
  }
  /** L'étape 4 sur une carte : chaque réseau, son état, et les gestes possibles. */
  function panneauPublication(p) {
    const pubR = canal('social').publication || {};
    const dis = S.envoi ? ' disabled' : '';
    const pubs = p.publications || [];
    const lignes = (p.reseaux || ['facebook']).map(r => {
      const x = pubs.find(y => y.reseau === r), pb = preparer(p, r), nom = (RESEAUX.find(y => y[0] === r) || [r, r])[1];
      const accès = pubR.reseaux && pubR.reseaux[r] === false ? '<span class="wa">accès non configuré</span>' : '';
      const etat = x ? `<span class="mk-badge ${PUB_ST[x.statut][1]}">${PUB_ST[x.statut][0]}</span> ${x.statut === 'programme' ? 'le ' + esc(fDT(x.prevuLe)) : ''}${x.statut === 'publie' && x.lien ? `<a href="${esc(x.lien)}" target="_blank" rel="noopener">voir ↗</a>` : ''}${x.erreur && x.statut === 'erreur' ? `<small class="ko">${esc(x.erreur)}${x.tentatives > 1 ? ' · ' + x.tentatives + ' essais' : ''}</small>` : ''}${x.erreur && x.statut === 'annule' ? `<small class="mu">${esc(x.erreur)}</small>` : ''}`
        : (pb.length ? `<span class="ko">ne partira pas : ${esc(pb.join(', '))}</span>` : '<span class="mk-badge">à publier</span>');
      return `<div class="mk-pub-l">${badgeReseaux([r])}<b>${nom}</b><span class="tx">${etat} ${accès}</span></div>`;
    }).join('');
    const futur = p.publierLe && p.publierLe > (AUJ + ' ' + new Date().toTimeString().slice(0, 5));
    const prog = pubs.some(x => x.statut === 'programme'), erreur = pubs.some(x => x.statut === 'erreur');
    const gestes = pubR.reelle ? `<div class="ligne"><button class="mk-btn ok" data-a="pub-maintenant" data-id="${esc(p.id)}"${dis}>${erreur ? 'Réessayer maintenant' : 'Publier maintenant'}</button>
        ${futur && !prog ? `<button class="mk-btn" data-a="pub-programmer" data-id="${esc(p.id)}"${dis}>Programmer le ${esc(fDT(p.publierLe))}</button>` : ''}
        ${prog || erreur ? `<button class="mk-btn petit ko" data-a="pub-annuler" data-id="${esc(p.id)}"${dis} style="margin-left:auto">Annuler</button>` : ''}</div>` : '';
    const main = `<details class="mk-main"${pubR.reelle ? '' : ' open'}><summary>Publié à la main ?</summary><div class="ligne"><input class="mk-in" placeholder="id du post Facebook, ex. 123456789_987654321" value="${esc(S.pub[p.id] || '')}" data-f="pub" data-id="${esc(p.id)}"><button class="mk-btn" data-a="publier" data-id="${esc(p.id)}"${dis}>Marquer publié</button></div>
        <div class="mk-aide">Avec son identifiant, Brand Guard reconnaît le post en relisant la page — sans lui, il le compte comme « sauvage ».</div></details>`;
    return `<div class="mk-form"><label>Publication</label>${lignes}${gestes}${main}</div>`;
  }
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

  /* --- le rédacteur de post ---------------------------------------------------------------- */
  function composeNeuf(base) {
    const m = (D().magasins || []).find(x => String(x.id) === S.shop);
    const dt = new Date(Date.now() + 86400000);
    return Object.assign({ magasinId: S.shop || '', auteur: marque() ? 'Marque' : ((m && m.franchise) || 'Franchisé'), format: 'Photo', message: '', lien: '',
      date: iso(dt), heure: '10:00', visuel: '', url: '', alt: '', largeur: '', campagneId: '', reseaux: ['facebook'] }, base || {});
  }
  function composer() {
    const c = S.compose, R = regles();
    const mags = D().magasins || [];
    const n = c.message.trim().length;
    const camps = campagnes().filter(x => marque() ? x.phase !== 'terminee' : ['en_cours', 'a_venir', 'sans_date'].includes(x.phase));
    const linkedinOk = !c.magasinId && marque();
    if (!linkedinOk) { c.reseaux = c.reseaux.filter(r => r !== 'linkedin'); }
    const t = S.televersement;
    return `<div class="mk-card mk-canal-social"><div class="mk-ct" style="border-top:3px solid var(--c)"><span class="mk-k">Nouveau post</span><span class="mk-mini">relu par l’agent dès la soumission, publié après validation</span><button class="mk-btn petit" data-a="compose-x">Vider</button></div>
      <div class="mk-compose"><div class="champs">
        <div class="ligne"><div style="flex:2"><label>Campagne</label><select data-f="c-campagneId"><option value="">Sans campagne</option>${camps.map(x => `<option value="${x.id}"${String(x.id) === String(c.campagneId) ? ' selected' : ''}>${esc(x.nom)} · ${esc(periode(x.debut, x.fin))}</option>`).join('')}</select></div>
          <div><label>Magasin</label>${marque()
          ? `<select data-f="c-magasinId"><option value="">Réseau (post de la marque)</option>${mags.map(m => `<option value="${esc(m.id)}"${String(m.id) === c.magasinId ? ' selected' : ''}>${esc(m.nom)}</option>`).join('')}</select>`
          : `<input class="mk-in" value="${esc(magasinNom(S.shop) || S.shop)}" disabled>`}</div></div>
        <div><label>Réseaux</label><div class="mk-reseaux">${RESEAUX.map(r => { const off = r[0] === 'linkedin' && !linkedinOk; return `<label class="mk-case${off ? ' off' : ''}" title="${off ? 'LinkedIn publie sur la page de la marque : post réseau seulement' : ''}"><input type="checkbox" data-a="c-reseau" data-v="${r[0]}"${c.reseaux.includes(r[0]) ? ' checked' : ''}${off ? ' disabled' : ''}>${badgeReseaux([r[0]])} ${r[1]}</label>`; }).join('')}</div></div>
        <div><label>Message</label><textarea class="mk-in" data-f="c-message" placeholder="Le texte du post — ${esc(R.marque || 'la marque')}, le magasin, ${esc(R.hashtag || 'le hashtag du réseau')}…">${esc(c.message)}</textarea><div class="mk-compteur" id="mk-compteur">${n} caractère${n > 1 ? 's' : ''}</div></div>
        <div class="ligne"><div><label>Publier le</label><input class="mk-in" type="date" value="${esc(c.date)}" data-f="c-date"></div><div><label>à</label><input class="mk-in" type="time" value="${esc(c.heure)}" data-f="c-heure"></div>
          <div><label>Format</label><select data-f="c-format">${(R.formats || []).map(f => `<option${f === c.format ? ' selected' : ''}>${esc(f)}</option>`).join('')}</select></div></div>
        <div><label>Visuel</label><div class="ligne"><label class="mk-btn" style="cursor:pointer">${t && t.busy ? 'Téléversement…' : (c.url ? 'Changer le visuel' : 'Téléverser un visuel')}<input type="file" accept="image/jpeg,image/png,image/webp" data-f="c-fichier" hidden></label>
          <span class="mk-aide">${c.url ? `<a href="${esc(c.url)}" target="_blank" rel="noopener">${esc(c.visuel || 'visuel')}</a>${c.largeur ? ' · ' + esc(c.largeur) + ' px' : ''}` : 'JPEG, PNG ou WebP, 8 Mo au plus — Instagram l’exige'}</span></div>
          ${t && t.avert && t.avert.length ? `<div class="mk-aide wa">${t.avert.map(esc).join(' · ')}</div>` : ''}${t && t.err ? `<div class="mk-aide ko">${esc(t.err)}</div>` : ''}</div>
        <div class="ligne"><div style="flex:2"><label>Texte alternatif du visuel</label><input class="mk-in" placeholder="Ce que montre l’image, pour les lecteurs d’écran" value="${esc(c.alt)}" data-f="c-alt"></div>
          <div style="flex:2"><label>Lien (facultatif)</label><input class="mk-in" type="url" placeholder="https://www.atelierby.be/…" value="${esc(c.lien)}" data-f="c-lien"></div></div>
        <div class="ligne"><div style="flex:1;min-width:120px"><label>Signé</label><input class="mk-in" value="${esc(c.auteur)}" data-f="c-auteur"></div></div>
        <div id="mk-pret">${pret()}</div>
        <div class="ligne" style="justify-content:flex-end"><button class="mk-btn" data-a="envoyer-post" data-v="brouillon"${S.envoi || !n ? ' disabled' : ''}>Enregistrer en brouillon</button><button class="mk-btn prim" data-a="envoyer-post" data-v="a_controler"${S.envoi || !n || !c.reseaux.length ? ' disabled' : ''}>Soumettre au contrôle →</button></div>
      </div><div class="apercu"><div class="mk-k" style="font-size:9.5px;margin-bottom:8px">Aperçu</div><div id="mk-apercu">${apercu()}</div></div></div></div>`;
  }
  /** Prêt pour chaque réseau choisi ? Le même contrôle que le serveur fera à la publication. */
  function pret() {
    const c = S.compose;
    const p = { message: c.message, magasinId: c.magasinId, medias: c.url ? [{ url: c.url }] : [] };
    if (!c.reseaux.length) { return '<div class="mk-aide ko">Choisissez au moins un réseau.</div>'; }
    if (!c.message.trim() && !c.url) { return '<div class="mk-aide">Chaque réseau choisi dira ici s’il est prêt.</div>'; }
    return `<div class="mk-pret">${c.reseaux.map(r => { const pb = preparer(p, r); return `<span class="${pb.length ? 'ko' : 'ok'}">${badgeReseaux([r])} ${pb.length ? 'à corriger : ' + esc(pb.join(', ')) : 'prêt'}</span>`; }).join('')}</div>`;
  }
  function apercu() {
    const c = S.compose;
    const nom = "L'Atelier by" + (c.magasinId ? ' ' + (magasinNom(c.magasinId) || '') : '');
    const t = esc(c.message || 'Votre message apparaîtra ici.').replace(/(#[\wÀ-ÿ-]+)/g, '<span class="hash">$1</span>');
    const visuel = c.url ? `<img class="img" src="${esc(c.url)}" alt="${esc(c.alt)}" style="object-fit:cover;width:100%">`
      : (['Photo', 'Carrousel', 'Vidéo'].includes(c.format) || c.reseaux.includes('instagram') ? `<div class="img">${c.visuel ? esc(c.visuel) : 'visuel attendu'}</div>` : '');
    return `<div class="mk-fb"><div class="h"><img src="../assets/img/newsletter/logo-white.png" alt=""><div><b>${esc(nom.trim())}</b><small>${esc(fDL(c.date))} · ${esc(c.heure)} · ${c.reseaux.map(r => (RESEAUX.find(x => x[0] === r) || [r, r])[1]).join(', ') || '—'}</small></div></div>
      <div class="t">${t}</div>${visuel}${c.lien ? `<div class="l">${esc(c.lien)}</div>` : ''}</div>`;
  }
  function envoyerPost(statut) {
    const c = S.compose;
    if (!c || !c.message.trim()) { return; }
    const medias = (c.url || c.visuel.trim()) ? [{ nom: c.visuel.trim() || 'visuel.jpg', type: 'image', url: c.url || null, alt: c.alt.trim() || null, largeur: +c.largeur || null, hauteur: null }] : [];
    const corpsP = { magasinId: marque() ? c.magasinId : S.shop, auteur: c.auteur.trim() || 'Franchisé', format: c.format, message: c.message.trim(),
      lien: c.lien.trim() || null, medias, publierLe: c.date ? c.date + ' ' + (c.heure || '10:00') + ':00' : null, statut,
      reseaux: c.reseaux, campagneId: c.campagneId ? +c.campagneId : null };
    geste(appel('POST', '/marketing/social/posts', corpsP), r => {
      S.compose = null; S.televersement = null;
      S.postSel = r && r.id;
      const k = r && r.controle;
      if (statut !== 'brouillon') { S.onglet = 'valider'; S.valVue = k && k.note != null ? 'a_valider' : 'a_controler'; urlMaj(); }
      return statut === 'brouillon' ? 'Brouillon enregistré.' : (k ? 'Post soumis et contrôlé — ' + k.note + '/5 · ' + (k.resume || '') + '. Étape 3 : la validation.' : 'Post soumis au contrôle.');
    }).then(() => defiler(S.postSel ? 'post-' + S.postSel : null));
  }
  /** Téléverse le visuel choisi : réenregistré en JPEG côté serveur, adresse publique en retour. */
  function televerser(fichier) {
    if (!fichier || !S.compose) { return; }
    if (fichier.size > 8 * 1024 * 1024) { S.televersement = { err: 'Plus de 8 Mo : réduisez l’image.' }; rendre(); return; }
    S.televersement = { busy: true }; rendre();
    const lecteur = new FileReader();
    lecteur.onload = () => appel('POST', '/marketing/publication/media', { data: lecteur.result, nom: fichier.name })
      .then(r => { Object.assign(S.compose, { url: r.url, visuel: fichier.name, largeur: String(r.largeur || '') }); S.televersement = { avert: r.avertissements || [] }; })
      .catch(e => { S.televersement = { err: e.message || 'téléversement impossible' }; })
      .finally(() => rendre());
    lecteur.onerror = () => { S.televersement = { err: 'fichier illisible' }; rendre(); };
    lecteur.readAsDataURL(fichier);
  }
  function composerDepuisCampagne(id) {
    const c = campagneDe(+id);
    if (!c) { return; }
    const R = regles();
    const mag = S.shop ? (magasinNom(S.shop) || '') : '';
    const per = c.debut ? (c.fin && c.fin !== c.debut ? 'Du ' + fD(c.debut) + ' au ' + fD(c.fin) + '.' : 'Le ' + fD(c.debut) + '.') : '';
    const msg = [((R.marque || '') + (mag ? ' ' + mag : '')).trim() + (R.marque || mag ? ' — ' : '') + c.nom, per, R.hashtag || ''].filter(Boolean).join('\n');
    S.compose = composeNeuf({ message: msg, date: c.debut && c.debut > AUJ ? c.debut : decale(AUJ, 1), campagneId: String(c.id) });
    S.creerVue = 'post';
    aller('creer');
    defiler('mk-app');
  }
  function reprendre(id) {
    const p = posts().find(x => x.id === id);
    if (!p) { return; }
    const m = (p.medias || [])[0] || {};
    const q2 = p.publierLe && p.publierLe.slice(0, 10) >= AUJ ? p.publierLe : null;
    S.compose = composeNeuf({ magasinId: p.magasinId ? String(p.magasinId) : (S.shop || ''), auteur: p.auteur || '', format: p.format || 'Photo', message: p.message || '', lien: p.lien || '',
      date: q2 ? q2.slice(0, 10) : decale(AUJ, 1), heure: q2 ? q2.slice(11, 16) : '10:00', visuel: m.nom || '', url: m.url || '', alt: m.alt || '', largeur: m.largeur ? String(m.largeur) : '',
      campagneId: p.campagneId != null ? String(p.campagneId) : '', reseaux: (p.reseaux || ['facebook']).slice() });
    S.creerVue = 'post';
    aller('creer');
    defiler('mk-app');
  }
  /* --- 5. emailing ------------------------------------------------------------------------ */
  function vEmail() {
    // Le mode test, le module le dit lui-même, juste en dessous : pas de second bandeau.
    return `<div class="mk-texte">Le module newsletter du cockpit, ${marque() ? 'vue marque — segments, automatisations et réglages compris' : 'vue magasin — ses segments, sa campagne, son adresse d’envoi'}. Ses envois et leurs résultats remontent dans 4 · Publier et 5 · Mesurer.</div>`;
  }
  let nlRole = null;
  function nlMonter() {
    const host = document.getElementById('mk-nl');
    const voir = S.onglet === 'creer' && S.creerVue === 'newsletter' && !!S.data;
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
  const PARAM_SOURCE = { publication: '/marketing/publication/etat', sources: '/marketing/sources', agent: '/marketing/social/regles', charte: '/marketing/brand-guard/rules', pages: '/marketing/brand-guard/pages' };
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
    const corpsP = vue[0] === 'publication' ? (attente('publication') || pPublication()) : vue[0] === 'sources' ? (attente('sources') || pSources()) : vue[0] === 'agent' ? (attente('agent') || pAgent())
      : vue[0] === 'charte' ? (attente('charte') || pCharte()) : vue[0] === 'pages' ? (attente('pages') || pPages()) : pAvis();
    return `<div class="mk-barre-f" style="--c:#222"><div class="mk-chips">${chips}</div>${marque() ? '' : '<span class="sp"></span><span class="mk-aide">Les réglages appartiennent à la marque : un magasin voit ici d’où viennent ses données.</span>'}</div>${corpsP}`;
  }

  /* La publication : réglages, accès aux réseaux, horloge — et ce qu'il faut pour qu'elle marche. */
  function pPublication() {
    const e = S.par.publication;
    const d = S.pubDraft || (S.pubDraft = Object.assign({}, e.reglages));
    const meta = e.meta || {}, li = e.linkedin || {}, n = e.compteurs || {};
    const modifie = JSON.stringify(d) !== JSON.stringify(e.reglages);
    const bascule = (k, titre, aide) => `<label class="mk-bascule"><input type="checkbox" data-a="pubp-bascule" data-v="${k}"${d[k] ? ' checked' : ''}><span><b>${titre}</b><small>${aide}</small></span></label>`;
    const test = r => { const t = S.pubTest[r]; return t ? `<div class="mk-aide ${t.ok ? 'ok' : 'ko'}">${esc(t.message || '')}${(t.pages || []).map(x => `<br>${x.ok ? '✓' : '✗'} ${esc(x.nom || x.page)} — ${esc(x.detail)}`).join('')}</div>` : ''; };
    const acces = (r, nom, ok, detail) => `<tr><td class="nom">${badgeReseaux([r])} ${nom}<small>${detail}</small>${test(r)}</td><td>${ok ? '<span class="mk-badge ok">configuré</span>' : '<span class="mk-badge">à configurer</span>'}</td><td class="n"><button class="mk-btn petit" data-a="pubp-tester" data-v="${r}"${S.envoi ? ' disabled' : ''}>Tester</button></td></tr>`;
    const pages = (e.pages || []).filter(p => p.pageId);
    return `<div class="mk-grille mk-g2">
      <div class="mk-card"><div class="mk-ct"><span class="mk-k">Réglages</span><span class="mk-mini">${n.publie || 0} publiées · ${n.programme || 0} programmées · ${n.erreur || 0} en échec · horloge ${e.cronDernier ? 'passée le ' + esc(fDT(String(e.cronDernier))) : 'jamais passée'}</span></div>
        <div class="mk-cb" style="display:flex;flex-direction:column;gap:12px">
          ${bascule('reelle', 'Publication réelle', d.reelle ? 'Active : un post validé part sur ses réseaux, maintenant ou à son heure. Décocher arrête tout envoi.' : 'Éteinte, rien ne part sur les réseaux : le portail prépare, la marque publie à la main.')}
          ${bascule('auto', 'Programmer à la validation', 'Un post validé se programme tout seul à son heure prévue, sur ses réseaux.')}
          <div><label class="mk-lab" style="display:block;margin-bottom:4px">Page Facebook de la marque (posts réseau)</label>
            <div class="ligne" style="display:flex;gap:8px;flex-wrap:wrap"><input class="mk-in" style="flex:1;min-width:160px" placeholder="identifiant de la page, ex. 883508651512115" value="${esc(d.pageMarque || '')}" data-f="pubp-pageMarque">
            ${pages.length ? `<select data-f="pubp-pageChoix"><option value="">… ou une page connue</option>${pages.map(p => `<option value="${esc(p.pageId)}">${esc(p.boutique)} · ${esc(p.pageNom || p.pageId)}</option>`).join('')}</select>` : ''}</div>
            <div class="mk-aide">Les posts d’un magasin partent sur SA page (Paramètres → Pages Facebook, résolue). Instagram : le compte professionnel relié à la même page.</div></div>
          <div class="ligne" style="display:flex;gap:8px;flex-wrap:wrap"><div style="flex:2"><label class="mk-lab" style="display:block;margin-bottom:4px">Page entreprise LinkedIn (id d’organisation)</label><input class="mk-in" style="width:100%" placeholder="ex. 12345678" value="${esc(d.linkedinOrganisation || '')}" data-f="pubp-linkedinOrganisation"></div>
            <div style="flex:1"><label class="mk-lab" style="display:block;margin-bottom:4px">Version API LinkedIn</label><input class="mk-in" style="width:100%" placeholder="${esc(li.version || 'AAAAMM')}" value="${esc(d.linkedinVersion || '')}" data-f="pubp-linkedinVersion"></div></div>
          <div class="ligne" style="display:flex;gap:8px;justify-content:flex-end"><button class="mk-btn" data-a="pubp-annuler"${modifie ? '' : ' disabled'}>Annuler</button><button class="mk-btn prim" data-a="pubp-enregistrer"${modifie && !S.envoi ? '' : ' disabled'}>Enregistrer</button></div>
        </div></div>
      <div class="mk-card"><div class="mk-ct"><span class="mk-k">Accès aux réseaux</span><span class="mk-mini">les secrets restent sur le serveur — jamais ici</span></div>
        <table class="mk-tab"><tbody>
          ${acces('facebook', 'Facebook', !!meta.configure, 'System User Meta ' + (meta.tokenDefini ? 'défini' : 'absent (META_SYSTEM_TOKEN)') + ' · API ' + esc(meta.version || ''))}
          ${acces('instagram', 'Instagram', !!meta.configure, 'Par le même System User : le compte professionnel relié à chaque page')}
          ${acces('linkedin', 'LinkedIn', !!li.configure, 'Jeton ' + (li.jetonDefini ? 'défini' : 'absent (LINKEDIN_ACCESS_TOKEN)') + ' · organisation ' + esc(li.organisation || '—') + (li.joursRestants != null ? ' · <span class="' + (li.joursRestants < 10 ? 'ko' : '') + '">expire dans ' + li.joursRestants + ' j</span>' : '') + ' · version ' + esc(li.version || ''))}
        </tbody></table>
        <div class="mk-cb mk-aide"><b>Ce qu’il faut</b> — Meta : une application, un System User du Business Manager avec les pages attribuées et les droits pages_manage_posts, pages_read_engagement, instagram_basic, instagram_content_publish ; son jeton dans META_SYSTEM_TOKEN. LinkedIn : une application avec le produit Community Management API, un jeton d’administrateur de la page portant w_organization_social, dans LINKEDIN_ACCESS_TOKEN (60 jours). Les visuels doivent être lisibles depuis Internet : adresse du cockpit publique (${esc(e.base || '—')}). Détail : docs/publication-reseaux.md.</div></div>
    </div>`;
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

  /* Les règles de l'agent de contrôle (mécaniques, sans IA) et le seuil de publication. */
  function pAgent() {
    const enService = S.par.agent;
    const d = S.agentDraft || enService;
    const modifie = !!S.agentDraft;
    const fams = Array.from(new Set((d.regles || []).map(r => r.famille)));
    const GR = { 1: ['mineur', 'att'], 2: ['majeur', 'urg'], 3: ['critique', 'urg'] };
    return `<div class="mk-card"><div class="mk-ct"><span class="mk-k">Agent de contrôle des posts</span><span class="mk-mini">${(d.regles || []).filter(r => r.actif).length}/${(d.regles || []).length} règles actives · sans IA, le même texte donne toujours le même verdict</span></div>
      <div class="mk-cb"><div class="mk-form" style="background:none;border:none;padding:0"><div class="ligne"><label style="margin:0">Publiable à partir de</label>
        <div class="mk-notes">${[1, 2, 3, 4, 5].map(n => `<button class="${+d.seuil === n ? 'on' : ''}" style="${+d.seuil === n ? 'background:#222' : ''}" data-a="agent-seuil" data-v="${n}">${n}</button>`).join('')}</div><span class="mk-aide">sur 5 — en dessous, valider est une dérogation motivée (famille et type d’écart obligatoires).</span></div></div></div>
      ${fams.map(f => `<div class="mk-ct" style="background:#fbf9f6"><span class="mk-k">${esc(f)}</span></div><table class="mk-tab"><tbody>${(d.regles || []).map((r, i) => [r, i]).filter(([r]) => r.famille === f).map(([r, i]) => `<tr${r.actif ? '' : ' style="opacity:.55"'}>
          <td class="nom">${esc(r.nom)}<small>${esc(r.aide || '')}</small></td><td style="width:110px"><span class="mk-badge ${(GR[r.gravite] || GR[1])[1]}">${(GR[r.gravite] || GR[1])[0]}</span></td>
          <td class="n" style="width:110px"><button class="mk-btn petit ${r.actif ? 'ok' : ''}" data-a="agent-regle" data-i="${i}">${r.actif ? 'Active' : 'Inactive'}</button></td></tr>`).join('')}</tbody></table>`).join('')}
      <div class="mk-form"><div class="ligne">${modifie ? '<span class="wa mk-aide">Modifié — pas encore en service.</span>' : '<span class="mk-aide">Les mots, domaines et seuils de chaque règle restent ceux du réglage : seules l’activation et la note changent ici.</span>'}<span style="flex:1"></span>
        <button class="mk-btn" data-a="agent-annuler"${modifie ? '' : ' disabled'}>Annuler</button><button class="mk-btn prim" data-a="agent-enregistrer"${modifie && !S.envoi ? '' : ' disabled'}>Enregistrer</button></div></div></div>`;
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
  function brouillonAgent() { return S.agentDraft || (S.agentDraft = JSON.parse(JSON.stringify(S.par.agent || { seuil: 4, familles: [], regles: [] }))); }
  function brouillonCharte() { return S.charteDraft || (S.charteDraft = JSON.parse(JSON.stringify((S.par.charte || {}).regles || []))); }
  /** Une saisie ne redessine pas : on rend la main au bouton d'enregistrement directement. */
  function activer(action) { document.querySelectorAll('[data-a="' + action + '"]').forEach(b => { b.disabled = S.envoi; }); }

  /* --- navigation ------------------------------------------------------------------------- */
  function aller(onglet) {
    // En quittant la newsletter, on relit : une newsletter créée là-bas doit apparaître partout ici.
    const relire = S.onglet === 'creer' && S.creerVue === 'newsletter' && onglet !== 'creer';
    S.onglet = onglet; S.msg = null;
    if (onglet === 'parametres' && !PARAM_VUES.filter(v => marque() || v[2]).some(v => v[0] === S.paramVue)) { S.paramVue = 'sources'; }
    urlMaj(); rendre();
    if (relire) { charger(true); }
  }
  function defiler(id) {
    if (!id) { return; }
    requestAnimationFrame(() => { const el = document.getElementById(id); if (el) { el.scrollIntoView({ block: id === 'mk-app' ? 'start' : 'center', behavior: 'smooth' }); } });
  }
  function voirPost(id) {
    const p = posts().find(x => x.id === id);
    S.postSel = id;
    const etape = p ? etapeDuPost(p) : 'valider';
    if (etape === 'valider' && p) { S.valVue = p.statut; }
    if (etape === 'creer') { S.creerVue = 'post'; }
    if (etape === 'mesurer') { S.mesVue = 'resultats'; }
    aller(etape); defiler('post-' + id);
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
        else if (v === 'creer') { S.creerVue = sv; }
        else if (v === 'valider') { S.valVue = sv; S.postSel = null; }
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
      const it = (D().aFaire || [])[+el.dataset.i];
      const c = it && it.cible || {};
      if (c.post) { voirPost(c.post); }
      else if (c.campagne) { voirCampagne(c.campagne); }
      else {
        if (c.onglet === 'mesurer' && c.vue) { S.mesVue = c.vue; }
        if (c.onglet === 'creer' && c.vue) { S.creerVue = c.vue; }
        aller(c.onglet || 'vue');
      }
    }
    else if (a === 'ev') {
      // Dans le calendrier, un clic ouvre le détail sous la grille ; ailleurs (l'agenda
      // de la vue d'ensemble), il mène droit à l'objet.
      const ev = trouveEv(el.dataset.c, id);
      if ((S.onglet === 'plan' && S.planVue === 'calendrier') || !ev) { S.calSel = { canal: el.dataset.c, id }; rendre(); }
      else if (ev.canal === 'campagne') { voirCampagne(ev.id); }
      else if (ev.canal === 'email') { S.creerVue = 'newsletter'; aller('creer'); }
      else if (ev.sauvage) { S.mesVue = 'sauvages'; aller('mesurer'); }
      else { voirPost(ev.id); }
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
    else if (a === 'post-camp') { composerDepuisCampagne(id); }
    else if (a === 'post-voir') { voirPost(id); }
    // --- les étapes et leurs sous-vues
    else if (a === 'plan-vue') { S.planVue = v; S.calSel = null; urlMaj(); rendre(); }
    else if (a === 'creer-vue') { S.creerVue = v; if (S.onglet !== 'creer') { aller('creer'); } else { urlMaj(); rendre(); } }
    else if (a === 'val-vue') { S.valVue = v; S.postSel = null; rendre(); }
    else if (a === 'mes-vue') { S.mesVue = v; if (S.onglet !== 'mesurer') { aller('mesurer'); } else { urlMaj(); rendre(); } }
    else if (a === 'aller-param') { e.preventDefault(); S.paramVue = v; aller('parametres'); }
    else if (a === 'camp-ouvrir') { S.campOuverte = S.campOuverte === +id ? null : +id; rendre(); }
    else if (a === 'nl-camp') { S.nlCampagne = +id; S.creerVue = 'newsletter'; aller('creer'); defiler('mk-app'); }
    else if (a === 'nl-camp-x') { S.nlCampagne = null; rendre(); }
    else if (a === 'lien') { /* un lien dans une ligne cliquable : il s'ouvre, la ligne ne navigue pas */ }
    else if (a === 'c-reseau') {
      const l = S.compose.reseaux;
      S.compose.reseaux = el.checked ? Array.from(new Set(l.concat(v))) : l.filter(r => r !== v);
      rendre();
    }
    // --- 4 · Publier
    else if (a === 'pub-maintenant' || a === 'pub-programmer') {
      const maintenant = a === 'pub-maintenant';
      if (maintenant && !confirm('Publier ce post maintenant sur ses réseaux ?')) { return; }
      geste(appel('POST', '/marketing/social/posts/' + encodeURIComponent(id) + '/publier', { quand: maintenant ? 'maintenant' : 'programme' }), r => {
        S.postSel = id;
        const l = (r && r.publications) || [], ko = l.filter(x => x.statut === 'erreur');
        if (ko.length) { return { ok: false, texte: 'Publication en échec sur ' + ko.map(x => x.reseau).join(', ') + ' : ' + ko.map(x => x.erreur).join(' ; ') }; }
        return maintenant ? 'Publié sur ' + l.filter(x => x.statut === 'publie').map(x => x.reseau).join(', ') + '. Étape 5 : les résultats.' : 'Programmé — l’horloge le publiera à son heure.';
      }).then(() => { const p = posts().find(x => x.id === id); if (p && p.statut === 'publie') { S.mesVue = 'resultats'; aller('mesurer'); } });
    }
    else if (a === 'pub-annuler') {
      if (!confirm('Annuler ce qui n’est pas encore parti ?')) { return; }
      geste(appel('DELETE', '/marketing/social/posts/' + encodeURIComponent(id) + '/publier'), r => 'Programmation annulée' + (r && r.annulees != null ? ' (' + pl(r.annulees, 'réseau') + ')' : '') + '.');
    }
    // --- Paramètres → Publication
    else if (a === 'pubp-bascule') { S.pubDraft[v] = el.checked; rendre(); }
    else if (a === 'pubp-annuler') { S.pubDraft = null; rendre(); }
    else if (a === 'pubp-enregistrer') {
      geste(appel('PUT', '/marketing/publication/reglages', Object.assign({ par: par() }, S.pubDraft)), r => { S.par.publication = r; S.pubDraft = null; return 'Publication : ' + (r.reglages.reelle ? 'réelle' : 'éteinte') + (r.reglages.auto ? ', programmée à la validation' : '') + '.'; });
    }
    else if (a === 'pubp-tester') {
      S.pubTest[v] = { message: 'appel en cours…' }; rendre();
      appel('POST', '/marketing/publication/tester', { reseau: v }).then(r => { S.pubTest[v] = r; }).catch(err => { S.pubTest[v] = { ok: false, message: err.message }; }).finally(() => rendre());
    }
    else if (a === 'compose') { S.compose = composeNeuf(); aller('creer'); }
    else if (a === 'compose-x') { S.compose = composeNeuf(); S.televersement = null; rendre(); }
    else if (a === 'envoyer-post') { envoyerPost(v); }
    else if (a === 'reprendre') { reprendre(id); }
    else if (a === 'controler') {
      geste(appel('POST', '/marketing/social/posts/' + encodeURIComponent(id) + '/controle', {}), r => { const k = r && r.controle; S.postSel = id; return k ? 'Contrôle relancé — ' + k.note + '/5 · ' + (k.resume || '') : 'Contrôle relancé.'; });
    }
    else if (a === 'soumettre') {
      geste(appel('POST', '/marketing/social/posts/' + encodeURIComponent(id) + '/controle', { soumettre: true }), r => { const k = r && r.controle; S.postSel = id; S.onglet = 'valider'; S.valVue = 'a_valider'; urlMaj(); return k ? 'Brouillon soumis et contrôlé — ' + k.note + '/5 · ' + (k.resume || '') : 'Brouillon soumis.'; });
    }
    else if (a === 'dec-note') { (S.dec[id] || (S.dec[id] = {})).note = +v; rendre(); }
    else if (a === 'decider') {
      const R = regles(), d = S.dec[id] || {};
      if ((v === 'refuse' || d.note < (R.seuil || 4)) && (!d.famille || !d.type)) {
        S.msg = { ok: false, texte: 'Famille et type d’écart obligatoires pour un refus ou sous la note de ' + (R.seuil || 4) + ' : le magasin doit savoir quoi corriger.' }; rendre(); return;
      }
      geste(appel('PATCH', '/marketing/social/posts/' + encodeURIComponent(id), { decision: v, note: d.note, famille: d.famille || '', type: d.type || '', commentaire: d.commentaire || '', par: par() }),
        () => {
          delete S.dec[id]; S.postSel = id;
          if (v === 'valide') { S.onglet = 'publier'; urlMaj(); return 'Post validé ' + d.note + '/5. Étape 4 : la publication.'; }
          S.valVue = 'refuse'; return 'Post refusé — le magasin voit pourquoi.';
        });
    }
    else if (a === 'publier') {
      geste(appel('PATCH', '/marketing/social/posts/' + encodeURIComponent(id), { statut: 'publie', fbId: (S.pub[id] || '').trim(), par: par() }),
        () => { delete S.pub[id]; S.postSel = id; S.onglet = 'mesurer'; S.mesVue = 'resultats'; urlMaj(); return 'Post marqué publié.'; });
    }
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
    else if (a === 'agent-seuil') { brouillonAgent().seuil = +v; rendre(); }
    else if (a === 'agent-regle') { const r = brouillonAgent().regles[+el.dataset.i]; if (r) { r.actif = !r.actif; } rendre(); }
    else if (a === 'agent-annuler') { S.agentDraft = null; rendre(); }
    else if (a === 'agent-enregistrer') {
      const d = S.agentDraft; if (!d) { return; }
      geste(appel('PUT', '/marketing/social/regles', { valeur: { seuil: d.seuil, familles: d.familles, regles: d.regles } }),
        () => { S.agentDraft = null; S.par.agent = null; chargerParam('agent', true); return 'Règles de l’agent enregistrées — publiable à partir de ' + d.seuil + '/5.'; });
    }
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
    else if (a === 'ecart') {
      geste(appel('PATCH', '/marketing/social/posts/' + encodeURIComponent(id) + '/ecarts/' + el.dataset.e, { statut: v, par: par() }),
        r => { S.postSel = id; return (v === 'ignore' ? 'Écart écarté' : 'Écart rouvert') + (r && r.note != null ? ' — note de l’agent ' + r.note + '/5' : '') + '.'; });
    }
  });
  // La saisie met l'état à jour SANS redessiner (le curseur resterait sinon en route) ;
  // seuls l'aperçu et le compteur du post suivent la frappe.
  document.addEventListener('input', e => {
    const el = e.target, f = el.dataset && el.dataset.f;
    if (!f || !chezNous(el)) { return; }
    if (f === 'campQ') { S.campQ = el.value; const p = el.selectionStart; rendre(); const n = document.querySelector('[data-f="campQ"]'); if (n) { n.focus(); n.setSelectionRange(p, p); } return; }
    if (f === 'pub') { S.pub[el.dataset.id] = el.value; return; }
    if (f === 'rech-q' && S.rech) { S.rech.q = el.value; return; }
    if (f === 'ch-libelle' || f === 'ch-description') { const r = brouillonCharte()[+el.dataset.i]; if (r) { r[f.slice(3)] = el.value; } activer('ch-enregistrer'); return; }
    if (f === 'pg-franchise' || f === 'pg-url') { const k = el.dataset.k; S.pagesDraft[k] = Object.assign({}, S.pagesDraft[k], { [f === 'pg-url' ? 'pageUrl' : 'franchise']: el.value }); activer('pg-enregistrer'); return; }
    if (f === 'pg-mail') { S.mailDraft = el.value; activer('pg-enregistrer'); return; }
    if (f === 'cible') { S.cibleDraft = el.value; activer('cible-enregistrer'); return; }
    if (f === 'dec-com') { (S.dec[el.dataset.id] || (S.dec[el.dataset.id] = {})).commentaire = el.value; return; }
    if (f.indexOf('pubp-') === 0 && S.pubDraft && f !== 'pubp-pageChoix') { S.pubDraft[f.slice(5)] = el.value; activer('pubp-enregistrer'); activer('pubp-annuler'); return; }
    if (f.indexOf('c-') === 0 && f !== 'c-fichier' && S.compose) { S.compose[f.slice(2)] = el.value; majRedacteur(); }
  });
  /** Le rédacteur suit la frappe sans se redessiner : aperçu, compteur, « prêt » par réseau, boutons. */
  function majRedacteur() {
    const ap = document.getElementById('mk-apercu'); if (ap) { ap.innerHTML = apercu(); }
    const pr = document.getElementById('mk-pret'); if (pr) { pr.innerHTML = pret(); }
    const n = S.compose.message.trim().length;
    const cp = document.getElementById('mk-compteur'); if (cp) { cp.textContent = n + ' caractère' + (n > 1 ? 's' : ''); }
    document.querySelectorAll('[data-a="envoyer-post"]').forEach(b => { b.disabled = S.envoi || !n || (b.dataset.v !== 'brouillon' && !S.compose.reseaux.length); });
  }
  document.addEventListener('change', e => {
    const el = e.target, f = el.dataset && el.dataset.f;
    if (!f || !chezNous(el)) { return; }
    if (f === 'shop') {
      S.shop = el.value; S.data = null; S.compose = null; S.dec = {}; S.postSel = null; S.campSel = null; S.calSel = null; S.msg = null;
      if (!S.shop && S.campF === 'brouillon') { S.campF = 'actives'; }
      urlMaj(); charger(false);
    }
    else if (f === 'dec-famille') { const d = S.dec[el.dataset.id] || (S.dec[el.dataset.id] = {}); d.famille = el.value; d.type = ''; rendre(); }
    else if (f === 'ch-gravite') { const r = brouillonCharte()[+el.dataset.i]; if (r) { r.gravite = el.value; } rendre(); }
    else if (f === 'dec-type') { (S.dec[el.dataset.id] || (S.dec[el.dataset.id] = {})).type = el.value; }
    else if (f === 'pubp-pageChoix' && S.pubDraft) { if (el.value) { S.pubDraft.pageMarque = el.value; } rendre(); }
    else if (f === 'c-fichier') { televerser(el.files && el.files[0]); }
    else if (f.indexOf('c-') === 0 && S.compose) {
      S.compose[f.slice(2)] = el.value;
      // Le magasin change ce que LinkedIn permet : on redessine ; le reste suit sans redessiner.
      if (f === 'c-magasinId') { rendre(); } else { majRedacteur(); }
    }
  });

  $voile.addEventListener('click', () => { S.railOuvert = false; rendre(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && S.railOuvert) { S.railOuvert = false; rendre(); } });

  urlMaj();
  rendre();
  charger(false);
})();
