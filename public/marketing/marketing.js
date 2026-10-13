/*
 * Le module Marketing — /marketing (demande du 13/10/2026).
 *
 * Réseaux sociaux, emailing et campagnes réunis en un seul module : ils vivaient
 * dans quatre sections du rail du cockpit et ne se lisaient jamais ensemble. Cinq
 * onglets :
 *   1. Vue d'ensemble — un chiffre par canal, ce qui demande un geste (tous canaux
 *      confondus), les quatorze prochains jours ;
 *   2. Calendrier — les campagnes en bandes, les newsletters et les posts au jour ;
 *   3. Campagnes — avec, pour chacune, ses prises de parole : posts et newsletters
 *      sur sa période (une campagne en cours sans post ni mail se voit) ;
 *   4. Réseaux sociaux — le cycle d'un post : rédaction, contrôle de l'agent,
 *      décision, publication ; les posts « sauvages » de Brand Guard ; la charte ;
 *   5. Emailing — le module newsletter du cockpit, monté tel quel.
 *
 * Deux vues : la MARQUE (sans ?shop=, tout le réseau, décisions comprises) et le
 * MAGASIN (?shop={id} : ses posts, ses newsletters, les campagnes qui le couvrent).
 *
 * Lecture : ../api/cockpit/marketing/hub?shop= (src/marketing_hub.php), une seule
 * lecture pour tous les canaux. Écritures : les routes de chaque canal, inchangées —
 * POST /facebook/posts, POST /facebook/posts/{id}/controle, PATCH /facebook/posts/{id},
 * PATCH /facebook/posts/{id}/ecarts/{eid} ; la newsletter écrit elle-même.
 * Adresse : ?shop=&onglet=vue|calendrier|campagnes|social|email&mois=AAAA-MM&vue=…
 */
(function () {
  'use strict';
  const API = '../api/cockpit';
  const q = new URLSearchParams(location.search);
  const ONGLETS = [['vue', 'Vue d’ensemble', ''], ['calendrier', 'Calendrier', ''], ['campagnes', 'Campagnes', 'campagne'], ['social', 'Réseaux sociaux', 'social'], ['email', 'Emailing', 'email']];
  const CANAL_NOM = { campagne: 'Campagne', email: 'Newsletter', social: 'Post', charte: 'Charte' };
  const POST_ST = { brouillon: ['Brouillon', ''], a_controler: ['À contrôler', 'att'], a_valider: ['À valider', 'att'], valide: ['Validé · à publier', 'bleu'], refuse: ['Refusé', 'urg'], publie: ['Publié', 'ok'] };
  const NL_ST = { draft: 'Brouillon', sched: 'Programmée', live: 'En cours d’envoi', sent: 'Envoyée' };
  const PHASE = { en_cours: 'En cours', a_venir: 'À venir', terminee: 'Terminée', brouillon: 'Brouillon', sans_date: 'Sans dates' };
  const SOC_VUES = [
    ['a_traiter', 'À traiter', p => p.statut === 'a_controler' || p.statut === 'a_valider'],
    ['a_publier', 'Prêts à publier', p => p.statut === 'valide'],
    ['publies', 'Publiés', p => p.statut === 'publie'],
    ['refuses', 'Refusés', p => p.statut === 'refuse'],
    ['brouillons', 'Brouillons', p => p.statut === 'brouillon'],
    ['sauvages', 'Sauvages', null],
    ['tous', 'Tous', () => true],
  ];
  const CAMP_F = [['actives', 'En cours et à venir', c => ['en_cours', 'a_venir', 'sans_date'].includes(c.phase)], ['en_cours', 'En cours', c => c.phase === 'en_cours'],
    ['a_venir', 'À venir', c => c.phase === 'a_venir'], ['terminee', 'Terminées', c => c.phase === 'terminee'], ['brouillon', 'Brouillons', c => c.phase === 'brouillon'], ['toutes', 'Toutes', () => true]];
  const JOURS = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];

  const pad = n => String(n).padStart(2, '0');
  const iso = d => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
  const AUJ = iso(new Date());
  const decale = (j, n) => { const t = new Date(j + 'T12:00:00'); t.setDate(t.getDate() + n); return iso(t); };
  const moisOk = m => /^\d{4}-\d{2}$/.test(m || '');

  const S = {
    shop: q.get('shop') || '',
    onglet: ONGLETS.some(o => o[0] === q.get('onglet')) ? q.get('onglet') : 'vue',
    data: null, err: null, auth: false, enCours: false, msg: null, envoi: false,
    mois: moisOk(q.get('mois')) ? q.get('mois') : AUJ.slice(0, 7),
    calF: { campagne: true, email: true, social: true }, calSel: null,
    campF: 'actives', campQ: '', campSel: null,
    socVue: SOC_VUES.some(v => v[0] === q.get('vue')) ? q.get('vue') : 'a_traiter', postSel: null,
    dec: {},      // brouillon de décision par post : { note, famille, type, commentaire } — part au clic seulement
    pub: {},      // id Facebook saisi par post, au moment de le marquer publié
    compose: null,
  };
  const $app = document.getElementById('mk-app');

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
    return promesse.then(r => { S.msg = { ok: true, texte: typeof ok === 'function' ? ok(r) : ok }; S.envoi = false; return charger(true).then(() => r); })
      .catch(e => { S.msg = { ok: false, texte: e.auth ? 'Session expirée — reconnectez-vous dans le cockpit.' : (e.message || 'écriture impossible') }; return null; })
      .finally(() => { S.envoi = false; rendre(); });
  }
  function urlMaj() {
    const u = new URLSearchParams();
    if (S.shop) { u.set('shop', S.shop); }
    if (S.onglet !== 'vue') { u.set('onglet', S.onglet); }
    if (S.onglet === 'calendrier' && S.mois !== AUJ.slice(0, 7)) { u.set('mois', S.mois); }
    if (S.onglet === 'social' && S.socVue !== 'a_traiter') { u.set('vue', S.socVue); }
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
    const y = window.scrollY;
    $app.innerHTML = entete() + onglets() + message() + corps();
    if (window.scrollY !== y) { window.scrollTo(0, y); }
    nlMonter();
  }
  function entete() {
    const d = D();
    const mags = d.magasins || [];
    const nom = S.shop ? (d.magasin || magasinNom(S.shop) || 'Magasin ' + S.shop) : 'Tout le réseau';
    const opts = [`<option value="">Tout le réseau (marque)</option>`].concat(mags.map(m => `<option value="${esc(m.id)}"${String(m.id) === S.shop ? ' selected' : ''}>${esc(m.nom)}</option>`));
    if (S.shop && !mags.some(m => String(m.id) === S.shop)) { opts.push(`<option value="${esc(S.shop)}" selected>${esc(nom)}</option>`); }
    document.title = 'Marketing — ' + (S.shop ? nom : "L'Atelier by");
    return `<div class="mk-hd"><img src="../assets/img/logo.png" alt=""><div><div class="mk-titre">Marketing</div>
      <div class="mk-sous">${esc(nom)} · campagnes, réseaux sociaux, emailing${d.genere ? ' · lu à ' + esc(d.genere.slice(11, 16)) : ''}</div></div><span class="sp"></span>
      <label class="mk-lab">Vue <select data-f="shop" title="La marque voit tout le réseau ; un magasin voit ses posts, ses newsletters et les campagnes qui le couvrent">${opts.join('')}</select></label>
      <button class="mk-btn" data-a="recharger"${S.enCours ? ' disabled' : ''}>${S.enCours ? 'Lecture…' : '↻ Actualiser'}</button>
      ${marque() ? '<a class="mk-btn" href="../#/campagnes" target="_blank" rel="noopener">Cockpit ↗</a>' : ''}</div>`;
  }
  function onglets() {
    const d = D();
    const urg = (d.aFaire || []).filter(a => a.niveau === 'urgent').length;
    const soc = canal('social'), b = canal('charte'), c = canal('campagnes'), m = canal('emailing');
    const badge = {
      vue: urg ? `<b class="urg">${urg}</b>` : '',
      campagnes: c.dispo && c.chiffres.enCours ? `<b>${c.chiffres.enCours}</b>` : '',
      social: soc.dispo && (soc.chiffres.aTraiter + ((b.sauvages || []).length)) ? `<b class="${(b.sauvages || []).length ? 'urg' : ''}">${soc.chiffres.aTraiter + (b.sauvages || []).length}</b>` : '',
      email: m.dispo && m.chiffres.programmees ? `<b>${m.chiffres.programmees}</b>` : '',
      calendrier: '',
    };
    return `<div class="mk-ong">${ONGLETS.map(o => `<button data-a="onglet" data-v="${o[0]}" class="${S.onglet === o[0] ? 'on' : ''}">${o[2] ? `<i class="mk-canal-${o[2]}" style="background:var(--c)"></i>` : ''}${o[1]}${badge[o[0]]}</button>`).join('')}</div>`;
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
      case 'calendrier': return vCalendrier();
      case 'campagnes': return vCampagnes();
      case 'social': return vSocial();
      case 'email': return vEmail();
      default: return vVue();
    }
  }
  const indispo = (k, nom) => { const c = canal(k); return c.dispo ? '' : `<div class="mk-err">${esc(nom)} indisponible — ${esc(c.raison || 'source absente')}. Les autres canaux restent lisibles.</div>`; };

  /* --- 1. vue d'ensemble -------------------------------------------------------------- */
  function vVue() {
    const d = D();
    const faire = d.aFaire || [];
    const agenda = agendaJours(AUJ, 14);
    return `<div class="mk-grille mk-g4">${kpiCampagnes()}${kpiEmail()}${kpiSocial()}${kpiCharte()}</div>
      <div class="mk-grille mk-g2" style="margin-top:14px">
        <div class="mk-card"><div class="mk-ct"><span class="mk-k">À faire</span><span class="mk-mini">${faire.length ? pl(faire.length, 'point') + ' · tous canaux' : 'rien en attente'}</span></div>
          ${faire.length ? `<ul class="mk-liste">${faire.map((a, i) => `<li class="mk-canal-${esc(a.canal)}" data-a="faire" data-i="${i}"><span class="mk-pastille"></span><div class="tx"><div class="tt">${a.niveau === 'urgent' ? '<span class="mk-badge urg">urgent</span> ' : ''}${esc(a.titre)}</div><div class="dd">${esc(a.detail)}</div></div><span class="qd">${esc(CANAL_NOM[a.canal] || a.canal)}</span></li>`).join('')}</ul>`
            : '<div class="mk-vide">Aucun post à décider, aucune newsletter sans test, aucune campagne muette. Rien ne presse.</div>'}</div>
        <div class="mk-card"><div class="mk-ct"><span class="mk-k">Les 14 prochains jours</span><span class="mk-mini">campagnes, envois, posts</span></div>${agenda}</div>
      </div>`;
  }
  function kpi(canalCss, onglet, titre, corpsHtml, dispo, raison) {
    if (!dispo) { return `<div class="mk-kpi off mk-canal-${canalCss}"><div class="t">${titre}</div><div class="d">Indisponible — ${esc(raison || '')}</div></div>`; }
    return `<button class="mk-kpi mk-canal-${canalCss}" data-a="onglet" data-v="${onglet}"><div class="t">${titre}</div>${corpsHtml}</button>`;
  }
  function kpiCampagnes() {
    const c = canal('campagnes'); const k = c.chiffres || {};
    const pct = k.budget > 0 ? Math.min(100, 100 * k.depense / k.budget) : 0;
    return kpi('campagne', 'campagnes', 'Campagnes', `<div class="v">${fN(k.enCours)}<small>en cours</small></div>
      <div class="d"><b>${fN(k.aVenir)}</b> à venir${marque() ? ' · <b>' + fN(k.brouillons) + '</b> en brouillon' : ''} · ${fN(k.terminees)} terminées</div>
      <div class="mk-barre" title="Dépensé sur le budget des campagnes lancées"><i style="width:${pct.toFixed(1)}%"></i></div>
      <div class="d">${fE(k.depense)} dépensés sur ${fE(k.budget)}</div>`, c.dispo, c.raison);
  }
  function kpiEmail() {
    const c = canal('emailing'); const k = c.chiffres || {};
    return kpi('email', 'email', 'Emailing', `<div class="v">${k.tauxOuverture == null ? '—' : fP(k.tauxOuverture)}<small>d’ouverture</small></div>
      <div class="d">${pl(k.envoyes || 0, 'mail envoyé', 'mails envoyés')} · clic ${fP(k.tauxClic)}</div>
      <div class="d"><b>${fN(k.programmees)}</b> programmée${k.programmees > 1 ? 's' : ''} · ${fN(k.automatisations)} automatisation${k.automatisations > 1 ? 's' : ''}${k.optin != null ? ' · ' + fN(k.optin) + ' contacts opt-in' : ''}</div>
      ${c.test ? '<div><span class="mk-badge att">mode test · rien ne part</span></div>' : ''}${k.exemples ? `<div class="d mu">${pl(k.exemples, 'campagne d’exemple non comptée', 'campagnes d’exemple non comptées')}</div>` : ''}`, c.dispo, c.raison);
  }
  function kpiSocial() {
    const c = canal('social'); const k = c.chiffres || {};
    return kpi('social', 'social', 'Réseaux sociaux', `<div class="v">${fN(k.aTraiter)}<small>post${k.aTraiter > 1 ? 's' : ''} à traiter</small></div>
      <div class="d"><b>${fN(k.aPublier)}</b> prêt${k.aPublier > 1 ? 's' : ''} à publier · <b>${fN(k.publiesMois)}</b> publié${k.publiesMois > 1 ? 's' : ''} ce mois</div>
      <div class="d">${fN(k.refuses)} refusé${k.refuses > 1 ? 's' : ''} · note moyenne ${k.noteMoyenne == null ? '—' : nf(k.noteMoyenne, 1) + '/5'}</div>`, c.dispo, c.raison);
  }
  function kpiCharte() {
    const c = canal('charte'); const k = c.stats || {};
    return kpi('charte', 'social', 'Charte de marque · 30 j', `<div class="v">${k.tauxConformite == null ? '—' : fP(k.tauxConformite)}<small>conformes</small></div>
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
      acts = `<button class="mk-btn petit" data-a="soc-vue" data-v="sauvages">Voir les sauvages</button>${r.lien ? `<a class="mk-btn petit" href="${esc(r.lien)}" target="_blank" rel="noopener">Facebook ↗</a>` : ''}`;
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
    const chips = filtres.map(x => `<button class="${x[0] === f[0] ? 'on' : ''}" data-a="camp-f" data-v="${x[0]}">${x[1]} <b>${c.liste.filter(x[2]).length}</b></button>`).join('');
    const lignes = liste.map(x => {
      const p = parole(x);
      const vive = x.phase === 'en_cours' || x.phase === 'a_venir';
      const pTxt = !p ? '<span class="mu">—</span>' : (p.posts + p.mails === 0 ? `<span class="${vive ? 'wa' : 'mu'}">aucune${vive ? ' ⚠' : ''}</span>` : [p.posts ? pl(p.posts, 'post') : '', p.mails ? pl(p.mails, 'newsletter') : ''].filter(Boolean).join(' · '));
      const pct = x.budget > 0 ? Math.min(100, 100 * x.depense / x.budget) : 0;
      return `<tr id="camp-${x.id}" class="${S.campSel === x.id ? 'sel' : ''}">
        <td class="masque-mob" style="width:46px">${x.image ? `<img class="mk-vign" src="${esc(image(x.image))}" alt="" loading="lazy" onerror="this.style.visibility='hidden'">` : `<span class="mk-vign" style="background:${esc(x.typeCouleur || 'var(--mk-doux)')};opacity:.25"></span>`}</td>
        <td class="nom">${esc(x.nom)}<small><span class="mk-pastille" style="background:${esc(x.typeCouleur || '#bbb')};width:7px;height:7px"></span> ${esc(x.type || 'sans type')}</small></td>
        <td class="per">${esc(periode(x.debut, x.fin))}<small>${esc(PHASE[x.phase] || '')}</small></td>
        <td><span class="mk-statut" style="color:${esc(x.statutTexte)};background:${esc(x.statutFond)}">${esc(x.statutNom)}</span></td>
        <td class="masque-mob">${x.reseau ? 'Réseau' : pl(x.nBoutiques, 'magasin')}</td>
        <td class="n masque-mob">${fE(x.depense)}<small>sur ${fE(x.budget)}</small><div class="mk-barre" style="width:90px;margin-left:auto;margin-top:3px"><i style="width:${pct.toFixed(1)}%"></i></div></td>
        <td>${pTxt}</td>
        <td><div class="acts"><button class="mk-btn petit" data-a="post-camp" data-id="${x.id}" title="Un post pré-rempli : la marque, la période, le hashtag">Préparer un post</button>
          ${marque() ? `<a class="mk-btn petit" href="../assistant/?id=${x.id}" target="_blank" rel="noopener" title="L’assistant de campagne : offre, canaux, rétroplanning">Assistant ↗</a>` : ''}</div></td></tr>`;
    }).join('');
    return `<div class="mk-barre-f mk-canal-campagne"><div class="mk-chips">${chips}</div><span class="sp"></span>
        <input class="mk-in" type="search" placeholder="Chercher une campagne" value="${esc(S.campQ)}" data-f="campQ" style="min-width:200px">
        ${marque() ? '<a class="mk-btn prim" href="../assistant/" target="_blank" rel="noopener">+ Nouvelle campagne</a><a class="mk-btn" href="../#/campagnes" target="_blank" rel="noopener">Calendrier, budget, mesure ↗</a>' : ''}</div>
      <div class="mk-card"><div class="mk-defile"><table class="mk-tab"><thead><tr><th class="masque-mob"></th><th>Campagne</th><th>Période</th><th>Statut</th><th class="masque-mob">Portée</th><th class="n masque-mob">Dépensé</th><th title="Posts et newsletters datés sur la période de la campagne">Prises de parole</th><th></th></tr></thead>
        <tbody>${lignes || `<tr><td colspan="8"><div class="mk-vide">Aucune campagne ${esc(f[1].toLowerCase())}${rq ? ' pour « ' + esc(S.campQ) + ' »' : ''}.</div></td></tr>`}</tbody></table></div></div>
      <div class="mk-texte">« Prises de parole » : les posts (hors refusés) et les newsletters datés entre le début et la fin de la campagne. Les tables ne relient pas un post à sa campagne : le rapprochement se fait par les dates.</div>`;
  }

  /* --- 4. réseaux sociaux ---------------------------------------------------------------- */
  function vSocial() {
    const s = canal('social');
    if (!s.dispo) { return indispo('social', 'Réseaux sociaux') + (S.compose ? composer() : ''); }
    const b = canal('charte');
    const vue = SOC_VUES.find(v => v[0] === S.socVue) || SOC_VUES[0];
    const nb = v => v[0] === 'sauvages' ? (b.sauvages || []).length : posts().filter(v[2]).length;
    const chips = SOC_VUES.map(v => `<button class="${v[0] === vue[0] ? 'on' : ''}${v[0] === 'sauvages' && nb(v) ? ' urg' : ''}" data-a="soc-vue" data-v="${v[0]}">${v[1]} <b>${nb(v)}</b></button>`).join('');
    let liste;
    if (vue[0] === 'sauvages') {
      liste = !b.dispo ? indispo('charte', 'Brand Guard') : ((b.sauvages || []).length
        ? `<div class="mk-posts">${b.sauvages.map(carteSauvage).join('')}</div>`
        : '<div class="mk-card"><div class="mk-vide">Aucun post sauvage sur 30 jours : tout ce qui est paru sur les pages est passé par une demande. (Sans accès Meta configuré, les pages ne sont pas relues — voir la charte à droite.)</div></div>');
    } else {
      const ps = posts().filter(vue[2]);
      liste = ps.length ? `<div class="mk-posts">${ps.map(cartePost).join('')}</div>`
        : `<div class="mk-card"><div class="mk-vide">Aucun post ${esc(vue[1].toLowerCase())}.${vue[0] === 'a_traiter' ? ' Tout ce qui a été soumis est décidé.' : ''}</div></div>`;
    }
    return `<div class="mk-barre-f mk-canal-social"><div class="mk-chips">${chips}</div><span class="sp"></span>
        <button class="mk-btn prim" data-a="compose"${S.compose ? ' disabled' : ''}>+ Nouveau post</button></div>
      ${S.compose ? composer() : ''}
      <div class="mk-cote"><div>${liste}</div><div>${carteCharte()}</div></div>`;
  }
  function badgePost(p) { const st = POST_ST[p.statut] || [p.statut, '']; return `<span class="mk-badge ${st[1]}">${esc(st[0])}</span>`; }
  function cartePost(p) {
    const R = regles();
    const quand = (p.publie && p.publie.le) || p.publierLe;
    const ag = p.agent || {};
    const nv = ag.note != null ? niveau(ag.note) : null;
    const ecarts = (p.ecarts || []);
    const peutEcarter = marque() && p.statut !== 'publie';
    const medias = (p.medias || []).map(m => esc(m.nom || 'média') + (m.largeur ? ' · ' + m.largeur + ' px' : '') + (m.alt ? '' : ' · <span class="wa">sans texte alternatif</span>')).join(', ');
    const agent = (ag.note != null || ecarts.length) ? `<div class="mk-agent"><div class="l1"><span class="mk-k" style="font-size:9.5px">Agent de contrôle</span>
        ${nv ? `<span class="mk-note" style="background:${esc(nv.couleur || '#777')}" title="${esc(nv.nom)}">${ag.note}/5</span>` : ''}<span>${esc(ag.resume || '')}</span>
        ${ag.passages > 1 ? `<span class="mu" style="margin-left:auto">${ag.passages} passages</span>` : ''}</div>
        ${ecarts.length ? `<ul class="mk-ecarts">${ecarts.map(e => `<li class="${e.statut === 'ignore' ? 'ignore' : ''}"><span class="g g${Math.min(3, Math.max(1, e.gravite))}" title="gravité ${e.gravite}"></span>
          <div class="tx"><b>${esc(e.regle || e.type)}</b> — ${esc(e.message)}${e.extrait ? '<small>« ' + esc(e.extrait) + ' »</small>' : ''}${e.statut === 'ignore' ? '<small>écarté (dérogation)</small>' : ''}</div>
          ${peutEcarter ? `<button class="mk-btn petit" data-a="ecart" data-id="${esc(p.id)}" data-e="${e.id}" data-v="${e.statut === 'ignore' ? 'ouvert' : 'ignore'}"${S.envoi ? ' disabled' : ''} title="${e.statut === 'ignore' ? 'Rouvrir l’écart' : 'Accorder une dérogation : l’écart reste affiché, il sort de la note'}">${e.statut === 'ignore' ? 'Rouvrir' : 'Écarter'}</button>` : ''}</li>`).join('')}</ul>` : ''}</div>` : '';
    const dc = p.decision || {};
    const decision = dc.le ? `<div class="mk-decision"><b>${p.statut === 'refuse' ? 'Refusé' : 'Validé'}${dc.note != null ? ' ' + dc.note + '/5' : ''}</b>${dc.par ? ' par ' + esc(dc.par) : ''} le ${esc(fDT(dc.le))}${dc.famille ? ' · ' + esc(dc.famille) + ' — ' + esc(dc.type || '') : ''}${dc.commentaire ? '<br>' + esc(dc.commentaire) : ''}</div>` : '';
    const publie = p.statut === 'publie' ? `<div class="mk-decision" style="border-color:rgba(45,122,62,.35)"><b class="ok">Publié</b> le ${esc(fDT(p.publie.le))}${p.publie.fbId ? ' · id Facebook ' + esc(p.publie.fbId) : ' · <span class="wa">sans id Facebook : Brand Guard le prendra pour un post sauvage</span>'}</div>` : '';
    return `<div class="mk-post${S.postSel === p.id ? ' ouvert' : ''}" id="post-${esc(p.id)}"><div class="hd"><span class="mag">${esc(p.magasin || 'Réseau')}</span>${badgePost(p)}<span class="mk-badge">${esc(p.format)}</span>
        <span class="qd">${quand ? (p.statut === 'publie' ? 'publié ' : 'prévu ') + esc(fDT(quand)) : 'sans date'}</span></div>
      <div class="msg">${esc(p.message)}</div>
      <div class="meta"><span>${esc(p.auteur || '')}</span><span>soumis ${esc(fDT(p.soumisLe))}</span>${p.lien ? `<a href="${esc(p.lien)}" target="_blank" rel="noopener">${esc(extrait(p.lien, 40))}</a>` : ''}${medias ? '<span>' + medias + '</span>' : ''}</div>
      ${agent}${decision}${publie}${actionsPost(p, R)}</div>`;
  }
  function actionsPost(p, R) {
    const dis = S.envoi ? ' disabled' : '';
    if (p.statut === 'brouillon') {
      return `<div class="mk-form"><div class="ligne"><button class="mk-btn prim" data-a="soumettre" data-id="${esc(p.id)}"${dis}>Soumettre au contrôle</button><button class="mk-btn" data-a="reprendre" data-id="${esc(p.id)}">Reprendre dans un nouveau post</button></div></div>`;
    }
    if (p.statut === 'a_controler') {
      return `<div class="mk-form"><div class="ligne"><button class="mk-btn prim" data-a="controler" data-id="${esc(p.id)}"${dis}>Lancer le contrôle</button><span class="mk-aide">L’agent relit le texte, le lien et les visuels contre la charte.</span></div></div>`;
    }
    if (p.statut === 'refuse') {
      return `<div class="mk-form"><div class="ligne"><button class="mk-btn" data-a="reprendre" data-id="${esc(p.id)}">Corriger et resoumettre</button><span class="mk-aide">Un nouveau post, pré-rempli avec celui-ci.</span></div></div>`;
    }
    if (p.statut === 'valide') {
      return `<div class="mk-form"><label>Publié sur Facebook ? Son identifiant (facultatif)</label>
        <div class="ligne"><input class="mk-in" placeholder="ex. 123456789_987654321" value="${esc(S.pub[p.id] || '')}" data-f="pub" data-id="${esc(p.id)}"><button class="mk-btn ok" data-a="publier" data-id="${esc(p.id)}"${dis}>Marquer publié</button></div>
        <div class="mk-aide">Avec son identifiant, Brand Guard reconnaît le post quand il relit la page — sans lui, il le compte comme « sauvage ».</div></div>`;
    }
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
      date: iso(dt), heure: '10:00', visuel: '', alt: '', largeur: '', campagne: null }, base || {});
  }
  function composer() {
    const c = S.compose, R = regles();
    const mags = D().magasins || [];
    const n = c.message.trim().length;
    return `<div class="mk-card mk-canal-social"><div class="mk-ct" style="border-top:3px solid var(--c)"><span class="mk-k">Nouveau post${c.campagne ? ' · campagne « ' + esc(c.campagne) + ' »' : ''}</span><span class="mk-mini">relu par l’agent dès la soumission</span><button class="mk-btn petit" data-a="compose-x">Fermer</button></div>
      <div class="mk-compose"><div class="champs">
        <div class="ligne"><div><label>Magasin</label>${marque()
          ? `<select data-f="c-magasinId"><option value="">Réseau (post de la marque)</option>${mags.map(m => `<option value="${esc(m.id)}"${String(m.id) === c.magasinId ? ' selected' : ''}>${esc(m.nom)}</option>`).join('')}</select>`
          : `<input class="mk-in" value="${esc(magasinNom(S.shop) || S.shop)}" disabled>`}</div>
          <div><label>Format</label><select data-f="c-format">${(R.formats || []).map(f => `<option${f === c.format ? ' selected' : ''}>${esc(f)}</option>`).join('')}</select></div>
          <div><label>Signé</label><input class="mk-in" value="${esc(c.auteur)}" data-f="c-auteur"></div></div>
        <div><label>Message</label><textarea class="mk-in" data-f="c-message" placeholder="Le texte du post — ${esc(R.marque || 'la marque')}, le magasin, ${esc(R.hashtag || 'le hashtag du réseau')}…">${esc(c.message)}</textarea><div class="mk-compteur" id="mk-compteur">${n} caractère${n > 1 ? 's' : ''}</div></div>
        <div class="ligne"><div><label>Publier le</label><input class="mk-in" type="date" value="${esc(c.date)}" data-f="c-date"></div><div><label>à</label><input class="mk-in" type="time" value="${esc(c.heure)}" data-f="c-heure"></div>
          <div style="flex:2"><label>Lien (facultatif)</label><input class="mk-in" type="url" placeholder="https://www.atelierby.be/…" value="${esc(c.lien)}" data-f="c-lien"></div></div>
        <div class="ligne"><div style="flex:2"><label>Visuel — nom du fichier</label><input class="mk-in" placeholder="speculoos-halle.jpg" value="${esc(c.visuel)}" data-f="c-visuel"></div>
          <div><label>Largeur (px)</label><input class="mk-in" type="number" min="0" placeholder="1080" value="${esc(c.largeur)}" data-f="c-largeur"></div></div>
        <div><label>Texte alternatif du visuel</label><input class="mk-in" placeholder="Ce que montre l’image, pour les lecteurs d’écran" value="${esc(c.alt)}" data-f="c-alt"></div>
        <div class="ligne" style="justify-content:flex-end"><button class="mk-btn" data-a="envoyer-post" data-v="brouillon"${S.envoi || !n ? ' disabled' : ''}>Enregistrer en brouillon</button><button class="mk-btn prim" data-a="envoyer-post" data-v="a_controler"${S.envoi || !n ? ' disabled' : ''}>Soumettre au contrôle</button></div>
      </div><div class="apercu"><div class="mk-k" style="font-size:9.5px;margin-bottom:8px">Aperçu</div><div id="mk-apercu">${apercu()}</div></div></div></div>`;
  }
  function apercu() {
    const c = S.compose;
    const nom = "L'Atelier by" + (c.magasinId ? ' ' + (magasinNom(c.magasinId) || '') : '');
    const t = esc(c.message || 'Votre message apparaîtra ici.').replace(/(#[\wÀ-ÿ-]+)/g, '<span class="hash">$1</span>');
    const visuel = ['Photo', 'Carrousel', 'Vidéo'].includes(c.format) ? `<div class="img">${c.visuel ? esc(c.visuel) + (c.alt ? '<br><i>' + esc(c.alt) + '</i>' : '') : 'visuel attendu pour un post ' + esc(c.format.toLowerCase())}</div>` : '';
    return `<div class="mk-fb"><div class="h"><img src="../assets/img/newsletter/logo-white.png" alt=""><div><b>${esc(nom.trim())}</b><small>${esc(fDL(c.date))} · ${esc(c.heure)}</small></div></div>
      <div class="t">${t}</div>${visuel}${c.lien ? `<div class="l">${esc(c.lien)}</div>` : ''}</div>`;
  }
  function envoyerPost(statut) {
    const c = S.compose;
    if (!c || !c.message.trim()) { return; }
    const medias = c.visuel.trim() ? [{ nom: c.visuel.trim(), type: 'image', alt: c.alt.trim() || null, largeur: +c.largeur || null, hauteur: null }] : [];
    const corpsP = { magasinId: marque() ? c.magasinId : S.shop, auteur: c.auteur.trim() || 'Franchisé', format: c.format, message: c.message.trim(),
      lien: c.lien.trim() || null, medias, publierLe: c.date ? c.date + ' ' + (c.heure || '10:00') + ':00' : null, statut };
    geste(appel('POST', '/facebook/posts', corpsP), r => {
      S.compose = null;
      S.postSel = r && r.id;
      S.socVue = statut === 'brouillon' ? 'brouillons' : 'a_traiter';
      const k = r && r.controle;
      return statut === 'brouillon' ? 'Brouillon enregistré.' : (k ? 'Post soumis et contrôlé — ' + k.note + '/5 · ' + (k.resume || '') : 'Post soumis au contrôle.');
    }).then(() => defiler(S.postSel ? 'post-' + S.postSel : null));
  }
  function composerDepuisCampagne(id) {
    const c = (canal('campagnes').liste || []).find(x => x.id === +id);
    if (!c) { return; }
    const R = regles();
    const mag = S.shop ? (magasinNom(S.shop) || '') : '';
    const per = c.debut ? (c.fin && c.fin !== c.debut ? 'Du ' + fD(c.debut) + ' au ' + fD(c.fin) + '.' : 'Le ' + fD(c.debut) + '.') : '';
    const msg = [((R.marque || '') + (mag ? ' ' + mag : '')).trim() + (R.marque || mag ? ' — ' : '') + c.nom, per, R.hashtag || ''].filter(Boolean).join('\n');
    S.compose = composeNeuf({ message: msg, date: c.debut && c.debut > AUJ ? c.debut : decale(AUJ, 1), campagne: c.nom });
    aller('social');
    defiler('mk-app');
  }
  function reprendre(id) {
    const p = posts().find(x => x.id === id);
    if (!p) { return; }
    const m = (p.medias || [])[0] || {};
    const q2 = p.publierLe && p.publierLe.slice(0, 10) >= AUJ ? p.publierLe : null;
    S.compose = composeNeuf({ magasinId: p.magasinId ? String(p.magasinId) : (S.shop || ''), auteur: p.auteur || '', format: p.format || 'Photo', message: p.message || '', lien: p.lien || '',
      date: q2 ? q2.slice(0, 10) : decale(AUJ, 1), heure: q2 ? q2.slice(11, 16) : '10:00', visuel: m.nom || '', alt: m.alt || '', largeur: m.largeur ? String(m.largeur) : '' });
    rendre();
    defiler('mk-app');
  }

  /* --- 5. emailing ------------------------------------------------------------------------ */
  function vEmail() {
    // Le mode test, le module le dit lui-même, juste en dessous : pas de second bandeau.
    return `<div class="mk-texte">Le module newsletter du cockpit, ${marque() ? 'vue marque — segments, automatisations et réglages compris' : 'vue magasin — ses segments, sa campagne, son adresse d’envoi'}.</div>`;
  }
  let nlRole = null;
  function nlMonter() {
    const host = document.getElementById('mk-nl');
    const voir = S.onglet === 'email' && !!S.data;
    host.hidden = !voir;
    if (!voir || !window.CockpitNewsletter) { return; }
    const role = S.shop || 'brand';
    if (nlRole === role) { return; }   // déjà monté pour ce rôle : le module garde son DOM et ses données
    nlRole = role;
    window.CockpitNewsletter.mount(host, { role, apiBase: API, imgDir: '../assets/img/newsletter/', notify: t => { S.msg = { ok: true, texte: t }; rendre(); } });
  }

  /* --- navigation ------------------------------------------------------------------------- */
  function aller(onglet) {
    // En quittant l'emailing, on relit : une newsletter créée là-bas doit apparaître ici.
    const relire = S.onglet === 'email' && onglet !== 'email';
    S.onglet = onglet; S.msg = null;
    urlMaj(); rendre();
    if (relire) { charger(true); }
  }
  function defiler(id) {
    if (!id) { return; }
    requestAnimationFrame(() => { const el = document.getElementById(id); if (el) { el.scrollIntoView({ block: id === 'mk-app' ? 'start' : 'center', behavior: 'smooth' }); } });
  }
  function vueDuPost(p) {
    if (!p) { return 'tous'; }
    const v = SOC_VUES.find(x => x[2] && x[0] !== 'tous' && x[2](p));
    return v ? v[0] : 'tous';
  }
  function voirPost(id) {
    const p = posts().find(x => x.id === id);
    S.postSel = id; S.socVue = vueDuPost(p);
    aller('social'); defiler('post-' + id);
  }
  function voirCampagne(id) {
    const c = (canal('campagnes').liste || []).find(x => x.id === +id);
    S.campSel = +id;
    if (c && !CAMP_F.find(f => f[0] === S.campF)[2](c)) { S.campF = 'toutes'; }
    aller('campagnes'); defiler('camp-' + id);
  }

  /* --- événements --------------------------------------------------------------------------- */
  document.addEventListener('click', e => {
    const el = e.target.closest('[data-a]');
    if (!el || !$app.contains(el)) { return; }
    const a = el.dataset.a, v = el.dataset.v, id = el.dataset.id;
    if (a === 'onglet') { aller(v); }
    else if (a === 'recharger') { S.msg = null; charger(false); }
    else if (a === 'msg-x') { S.msg = null; rendre(); }
    else if (a === 'faire') {
      const it = (D().aFaire || [])[+el.dataset.i];
      const c = it && it.cible || {};
      if (c.post) { voirPost(c.post); }
      else if (c.campagne) { voirCampagne(c.campagne); }
      else if (c.vue) { S.socVue = c.vue; aller(c.onglet || 'social'); }
      else if (c.onglet) { aller(c.onglet); }
    }
    else if (a === 'ev') {
      // Dans le calendrier, un clic ouvre le détail sous la grille ; ailleurs (l'agenda
      // de la vue d'ensemble), il mène droit à l'objet.
      const ev = trouveEv(el.dataset.c, id);
      if (S.onglet === 'calendrier' || !ev) { S.calSel = { canal: el.dataset.c, id }; rendre(); }
      else if (ev.canal === 'campagne') { voirCampagne(ev.id); }
      else if (ev.canal === 'email') { aller('email'); }
      else if (ev.sauvage) { S.socVue = 'sauvages'; aller('social'); }
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
    else if (a === 'soc-vue') { S.socVue = v; S.postSel = null; if (S.onglet !== 'social') { aller('social'); } else { urlMaj(); rendre(); } }
    else if (a === 'compose') { S.compose = composeNeuf(); rendre(); }
    else if (a === 'compose-x') { S.compose = null; rendre(); }
    else if (a === 'envoyer-post') { envoyerPost(v); }
    else if (a === 'reprendre') { reprendre(id); }
    else if (a === 'controler') {
      geste(appel('POST', '/facebook/posts/' + encodeURIComponent(id) + '/controle', {}), r => { const k = r && r.controle; S.postSel = id; return k ? 'Contrôle relancé — ' + k.note + '/5 · ' + (k.resume || '') : 'Contrôle relancé.'; });
    }
    else if (a === 'soumettre') {
      geste(appel('POST', '/facebook/posts/' + encodeURIComponent(id) + '/controle', { soumettre: true }), r => { const k = r && r.controle; S.postSel = id; S.socVue = 'a_traiter'; return k ? 'Brouillon soumis et contrôlé — ' + k.note + '/5 · ' + (k.resume || '') : 'Brouillon soumis.'; });
    }
    else if (a === 'dec-note') { (S.dec[id] || (S.dec[id] = {})).note = +v; rendre(); }
    else if (a === 'decider') {
      const R = regles(), d = S.dec[id] || {};
      if ((v === 'refuse' || d.note < (R.seuil || 4)) && (!d.famille || !d.type)) {
        S.msg = { ok: false, texte: 'Famille et type d’écart obligatoires pour un refus ou sous la note de ' + (R.seuil || 4) + ' : le magasin doit savoir quoi corriger.' }; rendre(); return;
      }
      geste(appel('PATCH', '/facebook/posts/' + encodeURIComponent(id), { decision: v, note: d.note, famille: d.famille || '', type: d.type || '', commentaire: d.commentaire || '', par: par() }),
        () => { delete S.dec[id]; S.postSel = id; S.socVue = v === 'valide' ? 'a_publier' : 'refuses'; return v === 'valide' ? 'Post validé ' + d.note + '/5 — prêt à publier.' : 'Post refusé — le magasin voit pourquoi.'; });
    }
    else if (a === 'publier') {
      geste(appel('PATCH', '/facebook/posts/' + encodeURIComponent(id), { statut: 'publie', fbId: (S.pub[id] || '').trim(), par: par() }),
        () => { delete S.pub[id]; S.postSel = id; S.socVue = 'publies'; return 'Post marqué publié.'; });
    }
    else if (a === 'ecart') {
      geste(appel('PATCH', '/facebook/posts/' + encodeURIComponent(id) + '/ecarts/' + el.dataset.e, { statut: v, par: par() }),
        r => { S.postSel = id; return (v === 'ignore' ? 'Écart écarté' : 'Écart rouvert') + (r && r.note != null ? ' — note de l’agent ' + r.note + '/5' : '') + '.'; });
    }
  });
  // La saisie met l'état à jour SANS redessiner (le curseur resterait sinon en route) ;
  // seuls l'aperçu et le compteur du post suivent la frappe.
  document.addEventListener('input', e => {
    const el = e.target, f = el.dataset && el.dataset.f;
    if (!f || !$app.contains(el)) { return; }
    if (f === 'campQ') { S.campQ = el.value; const p = el.selectionStart; rendre(); const n = document.querySelector('[data-f="campQ"]'); if (n) { n.focus(); n.setSelectionRange(p, p); } return; }
    if (f === 'pub') { S.pub[el.dataset.id] = el.value; return; }
    if (f === 'dec-com') { (S.dec[el.dataset.id] || (S.dec[el.dataset.id] = {})).commentaire = el.value; return; }
    if (f.indexOf('c-') === 0 && S.compose) {
      S.compose[f.slice(2)] = el.value;
      const ap = document.getElementById('mk-apercu'); if (ap) { ap.innerHTML = apercu(); }
      const n = S.compose.message.trim().length;
      const cp = document.getElementById('mk-compteur'); if (cp) { cp.textContent = n + ' caractère' + (n > 1 ? 's' : ''); }
      document.querySelectorAll('[data-a="envoyer-post"]').forEach(b => { b.disabled = S.envoi || !n; });
    }
  });
  document.addEventListener('change', e => {
    const el = e.target, f = el.dataset && el.dataset.f;
    if (!f || !$app.contains(el)) { return; }
    if (f === 'shop') {
      S.shop = el.value; S.data = null; S.compose = null; S.dec = {}; S.postSel = null; S.campSel = null; S.calSel = null; S.msg = null;
      if (!S.shop && S.campF === 'brouillon') { S.campF = 'actives'; }
      urlMaj(); charger(false);
    }
    else if (f === 'dec-famille') { const d = S.dec[el.dataset.id] || (S.dec[el.dataset.id] = {}); d.famille = el.value; d.type = ''; rendre(); }
    else if (f === 'dec-type') { (S.dec[el.dataset.id] || (S.dec[el.dataset.id] = {})).type = el.value; }
    else if (f.indexOf('c-') === 0 && S.compose) {
      S.compose[f.slice(2)] = el.value;
      if (f === 'c-format' || f === 'c-magasinId') { const ap = document.getElementById('mk-apercu'); if (ap) { ap.innerHTML = apercu(); } }
    }
  });

  urlMaj();
  rendre();
  charger(false);
})();
