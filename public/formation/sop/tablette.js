/* La tablette du poste (module Formation › Standards, SOP) — lire une SOP pas à pas, répondre au quiz, faire valider « vu faire » par la responsable.
 *
 * ?shop=<magasin>&poste=<cuisson|production|vente|nettoyage> — l'onglet SOP de l'app Cuisine ouvre cette page.
 * On s'identifie comme pour les checklists : son nom, puis son PIN (vérifié au panel par le cockpit ; le PIN ne
 * reste jamais dans la tablette). Sans identification on peut lire, mais rien n'est compté. Après 10 minutes sans
 * geste, la tablette se déconnecte : elle est partagée.
 * API : ../api/cockpit/formation/sop/tablette/* (src/sop.php), à côté du module Formation.
 *
 * « Ma journée » (Manuel du réseau, 06/11/2026) : une fois identifiée, la personne voit d'abord SES standards — ceux
 * dont un poste du référentiel v25 (P1–P12) est l'un des siens (déduits de ses postes de tablette, plus l'encadrement
 * si elle valide « vu faire ») — rangés par moment (ouverture, journée, après-midi, fermeture, autres), avec leur
 * état : à lire, lu, quiz, vu faire. « Toutes les SOP » garde la liste par compétence. Le PIN ne change pas.
 *
 * UNE SEULE TABLETTE PAR MAGASIN (lot 5 de la fusion, 06/11/2026, src/tablettes.php) : au premier lancement, le PIN DU
 * MAGASIN (6 chiffres, Cockpit › Formation › Manuel du réseau › Tablettes) ouvre l'appareil ; la session est gardée sur
 * l'appareil (jeton dans localStorage `foTablette`, envoyé en X-Tablette, et cookie). Le magasin vient de cette
 * session : ?shop= ne fait plus que pré-choisir le magasin à l'écran du PIN ; ?poste= et ?langue= (lien de l'app
 * Cuisine) gardent leur rôle. Ensuite, comme avant, le PIN DE L'EMPLOYÉ l'identifie et signe.
 */
(function () {
  'use strict';
  const API = '../api/cockpit';
  const Q = new URLSearchParams(location.search);
  const POSTES = ['cuisson', 'production', 'vente', 'nettoyage'];
  const INACTIF_MS = 10 * 60 * 1000;
  const CLE_APPAREIL = 'foTablette';         // le jeton de l'appareil (PIN du magasin), gardé sur la tablette
  const SHOP_LIEN = Q.get('shop') || '';     // le magasin demandé par le lien : seulement pré-choisi
  const T = {
    fr: { titre: 'SOP', poste: { cuisson: 'Cuisson', production: 'Production', vente: 'Vente', nettoyage: 'Nettoyage', tous: 'Toutes' },
      quiLit: 'Qui lit ?', quiLitS: 'Choisis ton nom, puis tape ton PIN — comme pour les checklists.', pinDe: 'PIN de', anonyme: 'Personne d’identifié',
      lireSans: 'Sans identification, on peut lire, mais rien n’est compté.', changer: 'Changer de personne', sortir: 'Se déconnecter',
      vus: 'vues faire', quiz: 'quiz réussis', lues: 'lues', etapes: 'étapes', etape: 'Étape', crit: 'Point critique',
      prec: '← Précédente', suiv: 'C’est fait · suivante →', fini: 'J’ai tout lu ✓', faites: 'étapes faites', restent: 'restantes',
      st: { non: 'à lire', lu: 'lue · quiz à faire', quiz: 'quiz réussi · à voir faire', vu: 'vue faire ✓', a_revoir: 'nouvelle version · à revoir' },
      aValider: 'à valider', aValiderS: 'SOP à valider : lisible, sans quiz ni validation pour l’instant.', sansComp: 'Autres SOP',
      question: 'Question', valider: 'Valider', reussi: 'Quiz réussi', rate: 'Pas encore', relire: 'Relire la SOP', recommencer: 'Recommencer le quiz',
      demander: 'Demander à être vu(e) faire', plusTard: 'Plus tard', reste: 'Il reste à être vu(e) faire par la responsable du magasin, sur le poste.',
      vuFaire: 'Vu faire', vuFaireS: 'La responsable a vu faire cette SOP, sur place.', responsable: 'Responsable', pasEncore: 'Pas encore', okVu: '✓ Vu faire',
      noteP: 'Une remarque (facultatif)', compAcq: 'Compétence acquise', compEnc: 'Compétence', retour: '← Les SOP', identifie: 'Identifie-toi pour que ta lecture soit comptée.',
      faux: 'À revoir', okLu: 'Lecture enregistrée', erreurCharge: 'Impossible de charger les SOP', pasDeQuiz: 'Cette SOP n’a pas de quiz.',
      journee: 'Ma journée', toutes: 'Toutes les SOP', sesPostes: 'Mes postes', rienJournee: 'Aucun standard n’est encore rangé pour tes postes.',
      moment: { ouverture: 'Ouverture', journee: 'Journée', 'apres-midi': 'Après-midi', fermeture: 'Fermeture', autres: 'Autres moments' },
      etats: { non: 'à lire', lu: 'lu', quiz: 'quiz réussi', vu: 'vu faire', a_revoir: 'à revoir' }, standards: 'standards', panelCoche: 'cochée au panel',
      mag: { titre: 'Tablette du magasin', pin: 'PIN du magasin', aide: 'Les 6 chiffres donnés par le consultant, une seule fois sur cette tablette.', magasin: 'Magasin',
        ouvrir: 'Ouvrir', effacer: 'Effacer', ouverture: 'Ouverture…', fermer: 'Fermer la tablette', fermerQ: 'Fermer la tablette ? Il faudra retaper le PIN du magasin.',
        sansBoutique: 'Cette tablette n’est reliée à aucune boutique : à régler dans le Cockpit › Formation › Tablettes.', autre: 'Cette tablette est celle de « %s » : le lien demandait un autre magasin.',
        err: { pin: 'PIN du magasin inconnu.', archive: 'Cette tablette n’est plus active.', essais: 'Trop d’essais : réessayer dans 15 minutes.', suspendu: 'Ouverture suspendue (trop d’essais) : réessayer dans une heure.', reseau: 'Pas de connexion : réessayer.' } } },
    nl: { titre: 'SOP', poste: { cuisson: 'Bakken', production: 'Productie', vente: 'Verkoop', nettoyage: 'Schoonmaak', tous: 'Alle' },
      quiLit: 'Wie leest?', quiLitS: 'Kies je naam en tik je pincode — zoals bij de checklists.', pinDe: 'Pincode van', anonyme: 'Niet aangemeld',
      lireSans: 'Zonder aanmelding kun je lezen, maar niets wordt geteld.', changer: 'Andere persoon', sortir: 'Afmelden',
      vus: 'voorgedaan', quiz: 'quiz geslaagd', lues: 'gelezen', etapes: 'stappen', etape: 'Stap', crit: 'Kritiek punt',
      prec: '← Vorige', suiv: 'Klaar · volgende →', fini: 'Alles gelezen ✓', faites: 'stappen klaar', restent: 'te gaan',
      st: { non: 'te lezen', lu: 'gelezen · quiz te doen', quiz: 'quiz geslaagd · voor te doen', vu: 'voorgedaan ✓', a_revoir: 'nieuwe versie · opnieuw' },
      aValider: 'te valideren', aValiderS: 'SOP te valideren: leesbaar, nog zonder quiz of validatie.', sansComp: 'Andere SOP',
      question: 'Vraag', valider: 'Bevestigen', reussi: 'Quiz geslaagd', rate: 'Nog niet', relire: 'SOP opnieuw lezen', recommencer: 'Quiz opnieuw',
      demander: 'Vragen om voor te doen', plusTard: 'Later', reste: 'Nu nog voordoen voor de winkelverantwoordelijke, op de post.',
      vuFaire: 'Voorgedaan', vuFaireS: 'De verantwoordelijke heeft deze SOP zien uitvoeren, ter plaatse.', responsable: 'Verantwoordelijke', pasEncore: 'Nog niet', okVu: '✓ Voorgedaan',
      noteP: 'Een opmerking (facultatief)', compAcq: 'Competentie verworven', compEnc: 'Competentie', retour: '← De SOP', identifie: 'Meld je aan zodat je lezing telt.',
      faux: 'Opnieuw bekijken', okLu: 'Lezing bewaard', erreurCharge: 'De SOP konden niet geladen worden', pasDeQuiz: 'Deze SOP heeft geen quiz.',
      journee: 'Mijn dag', toutes: 'Alle SOP', sesPostes: 'Mijn posten', rienJournee: 'Er staat nog geen standaard voor jouw posten.',
      moment: { ouverture: 'Opening', journee: 'Overdag', 'apres-midi': 'Namiddag', fermeture: 'Sluiting', autres: 'Andere momenten' },
      etats: { non: 'te lezen', lu: 'gelezen', quiz: 'quiz geslaagd', vu: 'voorgedaan', a_revoir: 'opnieuw' }, standards: 'standaarden', panelCoche: 'aangevinkt in het panel',
      mag: { titre: 'Tablet van de winkel', pin: 'Pincode van de winkel', aide: 'De 6 cijfers van de consultant, één keer op deze tablet.', magasin: 'Winkel',
        ouvrir: 'Openen', effacer: 'Wissen', ouverture: 'Bezig met openen…', fermer: 'Tablet afsluiten', fermerQ: 'Tablet afsluiten? De pincode van de winkel moet dan opnieuw ingetikt worden.',
        sansBoutique: 'Deze tablet is aan geen winkel gekoppeld: in te stellen in de Cockpit › Formation › Tablets.', autre: 'Deze tablet is die van “%s”: de link vroeg een andere winkel.',
        err: { pin: 'Onbekende pincode van de winkel.', archive: 'Deze tablet is niet meer actief.', essais: 'Te veel pogingen: probeer over 15 minuten opnieuw.', suspendu: 'Openen opgeschort (te veel pogingen): probeer over een uur opnieuw.', reseau: 'Geen verbinding: probeer opnieuw.' } } }
  };

  const S = {
    shop: '',          // la boutique du panel : celle de la session de l'appareil
    appareil: null,    // la tablette ouverte par le PIN du magasin : {id, nom, shop} ; null = écran du PIN du magasin
    etatLu: false, prechoix: null, autreShop: false, pinMag: '', pinMagErr: null,
    poste: Q.get('poste') || localStorage.getItem('foPoste') || 'tous',
    langue: (Q.get('langue') || localStorage.getItem('foLangue')) === 'nl' ? 'nl' : 'fr',
    init: null, moi: null, jeton: null, suivi: {},
    vue: 'liste', sop: null, etape: 0, vuEtapes: {}, qi: 0, reponses: [], choix: null, resultat: null,
    pinPour: null, pin: '', valideur: null, note: '', occupe: false, erreur: null,
    onglet: 'journee'   // une fois identifiée : « Ma journée » d'abord, ou « Toutes les SOP »
  };
  if (!POSTES.includes(S.poste)) { S.poste = 'tous'; }
  const $ = s => document.querySelector(s);
  const esc = v => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const t = () => T[S.langue];
  const L = v => { if (!v) { return ''; } if (typeof v === 'string') { return v; } const o = S.langue === 'nl' ? 'fr' : 'nl'; return v[S.langue] || v[o] || ''; };
  const ini = n => String(n || '').split(/\s+/).map(x => x[0] || '').join('').slice(0, 2).toUpperCase();

  const jetonAppareil = () => { try { return localStorage.getItem(CLE_APPAREIL) || ''; } catch (e) { return ''; } };
  async function api(chemin, corps, methode) {
    const h = { Accept: 'application/json', 'Content-Type': 'application/json' };
    const ja = jetonAppareil();
    if (ja) { h['X-Tablette'] = ja; }
    const r = await fetch(API + chemin, { method: methode || (corps ? 'POST' : 'GET'), credentials: 'same-origin',
      headers: h, body: corps ? JSON.stringify(corps) : undefined });
    let d = null;
    try { d = await r.json(); } catch (e) { d = null; }
    // La session de l'appareil a pris fin (nouveau PIN, appareil déconnecté, tablette archivée) : retour au PIN du magasin.
    if (r.status === 401 && d && d.appareil) { appareilFerme(); }
    if (!r.ok) { const er = new Error((d && (d.error || d.erreur)) || ('HTTP ' + r.status)); er.code = d && d.code; er.status = r.status; throw er; }
    return d;
  }
  function appareilFerme() {
    try { localStorage.removeItem(CLE_APPAREIL); } catch (e) { /* stockage fermé */ }
    sessionStorage.removeItem('foJeton');
    S.appareil = null; S.shop = ''; S.init = null; S.jeton = null; S.moi = null; S.suivi = {}; S.vue = 'liste'; S.sop = null; S.pinMag = ''; S.etatLu = true;
    setTimeout(rendre, 0);   // l'écran du PIN du magasin, même si l'appelant ne redessine pas
  }
  function toast(m) {
    const el = document.createElement('div'); el.className = 'toast'; el.textContent = m; document.body.appendChild(el);
    setTimeout(() => el.remove(), 2600);
  }

  /* ── Session partagée : jeton en mémoire de l'onglet, déconnexion après 10 minutes sans geste ── */
  let minuterie = null;
  function actif() {
    clearTimeout(minuterie);
    if (S.jeton) { minuterie = setTimeout(() => { sortir(); }, INACTIF_MS); }
  }
  document.addEventListener('pointerdown', actif, { passive: true });
  function sortir() {
    S.jeton = null; S.moi = null; S.suivi = {}; S.pinPour = null; S.pin = ''; S.vue = 'liste'; S.sop = null;
    sessionStorage.removeItem('foJeton');
    rendre();
  }

  /** Au lancement : l'appareil est-il ouvert (PIN du magasin) ? Puis la tablette, comme avant. */
  async function demarrer() {
    try {
      const e = await api('/formation/sop/tablette/appareil' + (SHOP_LIEN ? '?shop=' + encodeURIComponent(SHOP_LIEN) : ''));
      S.etatLu = true;
      if (e.ouvert) { S.appareil = e.magasin; S.shop = e.magasin.shop || ''; S.autreShop = !!e.autreShop; return charger(); }
      S.appareil = null; S.prechoix = e.prechoix || null;
    } catch (x) { S.etatLu = true; S.pinMagErr = t().mag.err.reseau; }
    rendre();
  }
  async function ouvrirAppareil() {
    if (S.pinMag.length !== 6 || S.occupe) { return; }
    S.occupe = true; S.pinMagErr = null; rendre();
    try {
      const r = await api('/formation/sop/tablette/appareil/ouvrir', { pin: S.pinMag });
      try { localStorage.setItem(CLE_APPAREIL, r.jeton); } catch (e) { /* le cookie de l'appareil suffit */ }
      S.appareil = r.magasin; S.shop = r.magasin.shop || ''; S.autreShop = !!(SHOP_LIEN && S.shop && SHOP_LIEN !== S.shop);
      S.pinMag = ''; S.occupe = false;
      return charger();
    } catch (x) { S.pinMagErr = (t().mag.err[x.code] || x.message || t().mag.err.reseau); S.pinMag = ''; }
    S.occupe = false; rendre();
  }
  async function fermerAppareil() {
    if (!confirm(t().mag.fermerQ)) { return; }
    try { await api('/formation/sop/tablette/appareil/fermer', {}); } catch (e) { /* fermée côté appareil quoi qu'il arrive */ }
    appareilFerme(); S.prechoix = null; rendre();
  }

  async function charger() {
    if (!S.appareil) { return rendre(); }
    if (!S.shop) { S.init = null; return rendre(); }
    try {
      S.init = await api('/formation/sop/tablette/init');
      const j = sessionStorage.getItem('foJeton');
      if (j) {
        try { const m = await api('/formation/sop/tablette/moi?jeton=' + encodeURIComponent(j)); S.jeton = j; S.moi = m.personne; S.suivi = m.suivi || {}; actif(); }
        catch (e) { sessionStorage.removeItem('foJeton'); }
      }
      S.erreur = null;
    } catch (e) { if (S.appareil) { S.erreur = t().erreurCharge + ' : ' + e.message; } }
    rendre();
  }

  /* ── Le PIN du magasin : il ouvre l'appareil, une fois (sop/tablette-magasin.css) ── */
  function vuePinMagasin() {
    const M = t().mag;
    const cases = [0, 1, 2, 3, 4, 5].map(i => `<span class="tbm-case${i < S.pinMag.length ? ' is-on' : ''}${i === S.pinMag.length && !S.occupe ? ' is-ici' : ''}">${i < S.pinMag.length ? '•' : ''}</span>`).join('');
    const touches = ['1', '2', '3', '4', '5', '6', '7', '8', '9', 'eff', '0', 'ok'];
    return `<div class="tbm"><div class="tbm-carte">
      <div class="tbm-haut"><img src="../img/logo.png" alt="L'Atelier By" class="tbm-logo"><span class="tbm-lg" role="group" aria-label="Langue">${['fr', 'nl'].map(l => `<button data-l="${l}" class="${S.langue === l ? 'is-on' : ''}">${l.toUpperCase()}</button>`).join('')}</span></div>
      <h1 class="tbm-titre">${esc(M.titre)}</h1>
      ${S.prechoix ? `<p class="tbm-mag"><span>${esc(M.magasin)}</span>${esc(S.prechoix.nom)}</p>` : ''}
      <p class="tbm-lab" id="tbm-lab">${esc(M.pin)}</p>
      <div class="tbm-cases" role="status" aria-labelledby="tbm-lab">${cases}</div>
      <p class="tbm-err" aria-live="polite">${S.pinMagErr ? esc(S.pinMagErr) : S.occupe ? esc(M.ouverture) : ''}</p>
      <div class="tbm-pave">${touches.map(k => k === 'eff' ? `<button class="tbm-t is-sec" data-pm="eff" aria-label="${esc(M.effacer)}" ${S.occupe || !S.pinMag ? 'disabled' : ''}>⌫</button>`
        : k === 'ok' ? `<button class="tbm-t is-ok" data-pm="ok" ${S.occupe || S.pinMag.length !== 6 ? 'disabled' : ''}>${esc(M.ouvrir)}</button>`
        : `<button class="tbm-t" data-pm="${k}" ${S.occupe || S.pinMag.length >= 6 ? 'disabled' : ''}>${k}</button>`).join('')}</div>
      <p class="tbm-aide">${esc(M.aide)}</p></div></div>`;
  }
  function touchePinMag(k) {
    if (S.occupe) { return; }
    if (k === 'eff') { S.pinMag = S.pinMag.slice(0, -1); S.pinMagErr = null; return rendre(); }
    if (k === 'ok') { return ouvrirAppareil(); }
    if (S.pinMag.length >= 6) { return; }
    S.pinMag += k; S.pinMagErr = null;
    if (S.pinMag.length === 6) { rendre(); return ouvrirAppareil(); }
    rendre();
  }
  // Un clavier branché (ou le pavé de l'iPad) sur l'écran du PIN du magasin.
  document.addEventListener('keydown', ev => {
    if (S.appareil || !S.etatLu) { return; }
    if (/^\d$/.test(ev.key)) { ev.preventDefault(); touchePinMag(ev.key); }
    else if (ev.key === 'Backspace') { ev.preventDefault(); touchePinMag('eff'); }
    else if (ev.key === 'Enter') { ev.preventDefault(); touchePinMag('ok'); }
  });

  /* ── L'en-tête ── */
  function entete(retour) {
    const m = S.init ? S.init.magasin.nom : '';
    return `<div class="tb-hd">${retour ? `<button class="tb-ret" data-a="liste">←</button>` : ''}<img src="../img/logo.png" alt="L'Atelier">
      <div class="t">${esc(t().titre)} · ${esc(m)}<small>${esc(S.sop ? (S.sop.code || '') + ' · v' + S.sop.version : (S.poste === 'tous' ? t().poste.tous : t().poste[S.poste]))}</small></div>
      <span class="sp"></span>
      <span class="tb-bascule"><button data-l="fr" class="${S.langue === 'fr' ? 'on' : ''}">FR</button><button data-l="nl" class="${S.langue === 'nl' ? 'on' : ''}">NL</button></span>
      ${S.moi ? `<button class="tb-moi" data-a="sortir" title="${esc(t().sortir)}"><i>${esc(ini(S.moi.nom))}</i>${esc(S.moi.nom)}</button>` : `<span class="tb-moi mu">${esc(t().anonyme)}</span>`}</div>`;
  }

  /* ── La liste des SOP du poste, rangées par compétence ── */
  function vueListe() {
    if (S.moi && S.onglet === 'journee') { return vueJournee(); }
    const sops = (S.init.sops || []).filter(s => S.poste === 'tous' || s.poste === S.poste);
    const groupes = new Map();
    sops.forEach(s => { const k = s.competence ? s.competence.nom : '\u0000' + t().sansComp; if (!groupes.has(k)) { groupes.set(k, []); } groupes.get(k).push(s); });
    const cles = [...groupes.keys()].sort((a, b) => (a[0] === '\u0000') - (b[0] === '\u0000') || a.localeCompare(b));
    const st = id => (S.suivi[id] || {}).statut || 'non';
    const ic = { non: '·', lu: 'L', quiz: 'Q', vu: '✓', a_revoir: '!' };
    const liste = cles.map(k => {
      const l = groupes.get(k);
      const nom = k.replace('\u0000', '');
      const vus = l.filter(s => st(s.id) === 'vu').length;
      return `<div class="tb-grp"><div class="gt"><span class="comp" style="font-size:12.5px;padding:4px 11px">${esc(nom)}</span>${S.moi ? `<span class="pill ${vus === l.length ? 'ok' : 'att'}">${vus} / ${l.length} ${esc(t().vus)}</span>` : ''}</div>
        ${l.map(s => `<button class="tb-sop" data-sop="${esc(s.id)}"><span class="st ${st(s.id)}">${ic[st(s.id)]}</span><span class="x"><b>${esc(L(s.titre))}${s.statut === 'a_valider' ? ` <span class="pill att">${esc(t().aValider)}</span>` : ''}</b>
          <small>${s.nbEtapes} ${esc(t().etapes)}${S.moi ? ' · ' + esc(t().st[st(s.id)]) : ''}</small></span><span class="fl">›</span></button>`).join('')}</div>`;
    }).join('') || `<div class="vide">—</div>`;
    const chips = `<div class="tb-chips">${POSTES.concat(['tous']).map(p => `<button data-poste="${p}" class="${S.poste === p ? 'on' : ''}">${esc(t().poste[p])}</button>`).join('')}</div>`;
    return `<div class="tb-corps"><div class="tb-liste">${onglets()}${chips}${liste}</div>${cote()}</div>`;
  }

  /* ── « Ma journée » : les standards de la personne, par moment ── */
  const MOMENTS = ['ouverture', 'journee', 'apres-midi', 'fermeture'];
  function onglets() {
    if (!S.moi) { return ''; }
    return `<div class="tb-chips" role="tablist"><button data-onglet="journee" class="${S.onglet === 'journee' ? 'on' : ''}">${esc(t().journee)}</button><button data-onglet="toutes" class="${S.onglet === 'toutes' ? 'on' : ''}">${esc(t().toutes)}</button></div>`;
  }
  /** Un standard est de la journée de la personne : un de ses postes v25 est celui du standard (sinon l'ancien poste). */
  function pourMoi(s) {
    const p = (s.classement && s.classement.postes) || [];
    if (p.length) { return p.some(x => (S.moi.postesV25 || []).includes(x)); }
    return (S.moi.postes || []).includes(s.poste);
  }
  function vueJournee() {
    const st = id => (S.suivi[id] || {}).statut || 'non';
    const ic = { non: '·', lu: 'L', quiz: 'Q', vu: '✓', a_revoir: '!' };
    const miens = (S.init.sops || []).filter(pourMoi);
    const groupes = MOMENTS.concat(['autres']).map(m => ({ m, l: miens.filter(s => m === 'autres' ? !MOMENTS.includes(s.moment) : s.moment === m) })).filter(g => g.l.length);
    const corps = groupes.map(g => {
      const vus = g.l.filter(s => st(s.id) === 'vu').length;
      return `<div class="tb-grp"><div class="sec-t">${esc(t().moment[g.m])}<small>${vus} / ${g.l.length} ${esc(t().vus)}</small></div>
        ${g.l.map(s => `<button class="tb-sop" data-sop="${esc(s.id)}"><span class="st ${st(s.id)}">${ic[st(s.id)]}</span><span class="x"><b>${esc(L(s.titre))}${s.statut === 'a_valider' ? ` <span class="pill att">${esc(t().aValider)}</span>` : ''}</b>
          <small>${esc(s.code || '')} · ${s.nbEtapes} ${esc(t().etapes)}</small></span><span class="pill ${st(s.id) === 'vu' ? 'ok' : st(s.id) === 'a_revoir' ? 'ko' : st(s.id) === 'non' ? '' : 'att'}">${esc(t().etats[st(s.id)])}</span><span class="fl">›</span></button>`).join('')}</div>`;
    }).join('') || `<div class="vide">${esc(t().rienJournee)}</div>`;
    const postes = (S.moi.postesV25 || []).join(' · ');
    return `<div class="tb-corps"><div class="tb-liste">${onglets()}<div class="mini" style="margin:-6px 0 12px">${esc(t().sesPostes)} : ${esc(postes || '—')} · ${miens.length} ${esc(t().standards)}</div>${corps}</div>${cote()}</div>`;
  }

  function cote() {
    if (S.moi) {
      const v = Object.values(S.suivi);
      const n = k => v.filter(x => x.statut === k).length;
      return `<div class="tb-cote"><h2>${esc(S.moi.nom)}</h2><div class="mini" style="font-size:13px">${esc((S.moi.postes || []).map(p => t().poste[p] || p).join(' · '))}</div>
        <div class="carte" style="background:#F5F1EB"><div style="display:flex;gap:18px;font-size:14px"><span><b style="font:400 26px var(--font-display)">${n('vu')}</b> ${esc(t().vus)}</span><span><b style="font:400 26px var(--font-display)">${n('quiz')}</b> ${esc(t().quiz)}</span><span><b style="font:400 26px var(--font-display)">${n('lu')}</b> ${esc(t().lues)}</span></div></div>
        <button class="tb-gros sec" data-a="sortir">${esc(t().changer)}</button><div class="mini" style="margin-top:auto">${esc(t().lireSans)}</div></div>`;
    }
    const pers = (S.init.personnes || []).filter(p => S.poste === 'tous' || p.valideur || (p.postes || []).includes(S.poste));
    return `<div class="tb-cote"><h2>${esc(t().quiLit)}</h2><div class="mini" style="font-size:13px">${esc(t().quiLitS)}</div>
      <div class="tb-pers">${pers.map(p => `<button data-pers="${p.id}" class="${S.pinPour === p.id ? 'on' : ''}"><b>${esc(p.nom)}</b><small>${esc((p.postes || []).map(x => t().poste[x] || x).join(' · '))}${p.valideur ? ' · ' + esc(t().responsable) : ''}</small></button>`).join('')}</div>
      ${S.pinPour ? pave(t().pinDe + ' ' + ((pers.find(p => p.id === S.pinPour) || {}).nom || ''), 'identifier') : ''}
      ${S.erreur ? `<div class="erreur">${esc(S.erreur)}</div>` : ''}
      <div class="mini" style="margin-top:auto;font-size:12px">${esc(t().lireSans)}</div>
      <button class="tbm-fermer" data-a="fermerTablette">${esc(t().mag.fermer)}</button></div>`;
  }

  function pave(titre, action) {
    return `<div class="lab" style="text-align:center;margin-top:6px">${esc(titre)}</div>
      <div class="points">${[0, 1, 2, 3].map(i => `<span class="${i < S.pin.length ? 'on' : ''}"></span>`).join('')}</div>
      <div class="pave">${['1', '2', '3', '4', '5', '6', '7', '8', '9', '⌫', '0', '✓'].map(k => `<button data-k="${k}" data-act="${action}" class="${k === '✓' ? 'ok' : ''}" ${S.occupe ? 'disabled' : ''}>${k}</button>`).join('')}</div>`;
  }

  /* ── Le pas à pas ── */
  function etapes() {
    const out = [];
    (S.sop.contenu.sections || []).forEach((sec, si) => sec.etapes.forEach(e => out.push(Object.assign({ si, section: sec.titre }, e))));
    return out;
  }
  function vueLecture() {
    const E = etapes();
    if (!E.length) { return `<div class="vide">—</div>`; }
    const i = Math.min(S.etape, E.length - 1), e = E[i];
    let k = -1;
    const plan = (S.sop.contenu.sections || []).map(sec => `<div class="s">${esc(L(sec.titre))}</div>${sec.etapes.map(x => { k++;
      const cls = k === i ? 'on' : (S.vuEtapes[k] ? 'fait' : ''); return `<button data-etape="${k}" class="${cls} ${x.critique ? 'crit' : ''}"><i>${S.vuEtapes[k] && k !== i ? '✓' : esc(x.numero)}</i><span>${esc(L(x.titre) || L(x.texte).split('\n')[0].slice(0, 60))}</span></button>`; }).join('')}`).join('');
    const titre = L(e.titre);
    const texte = L(e.texte);
    const dernier = i === E.length - 1;
    const faites = Object.keys(S.vuEtapes).length;
    return `<div class="tb-corps"><div class="tb-plan">${plan}</div>
      <div class="tb-main"><div class="ou">${esc(t().etape)} ${i + 1} / ${E.length} · <span>${esc(L(e.section))}</span></div>
        ${S.sop.statut === 'a_valider' ? `<div class="note">${esc(t().aValiderS)}</div>` : ''}
        <h1>${esc(titre || ('' + e.numero))}</h1>
        ${e.critique ? `<div class="tb-alerte"><span style="font-size:28px;line-height:1">⚠</span><div style="white-space:pre-line;font-size:21px"><b>${esc(t().crit)}</b>${esc(texte)}</div></div>` : `<div class="tx">${esc(texte)}</div>`}
        ${e.photo ? `<img class="photo" src="photos/${esc(e.photo)}" alt="" data-zoom="photos/${esc(e.photo)}">` : ''}
        ${!S.moi ? `<div class="mini" style="margin-top:auto;font-size:13px">${esc(t().identifie)}</div>` : ''}
      </div></div>
      <div class="tb-pied"><button class="gb" data-a="prec" ${i === 0 ? 'disabled' : ''}>${esc(t().prec)}</button>
        <div class="av"><div class="prog"><i class="pr" style="width:${Math.round(faites / E.length * 100)}%"></i></div><small>${faites} ${esc(t().faites)} · ${E.length - faites} ${esc(t().restent)}</small></div>
        ${dernier ? `<button class="gb g" data-a="fini">${esc(t().fini)}</button>` : `<button class="gb p" data-a="suiv">${esc(t().suiv)}</button>`}</div>`;
  }

  /* ── Le quiz ── */
  function vueQuiz() {
    const qs = S.sop.contenu.quiz || [];
    if (!qs.length) { return `<div class="tb-q"><div class="vide">${esc(t().pasDeQuiz)}</div><button class="tb-gros sec" data-a="liste">${esc(t().retour)}</button></div>`; }
    const q = qs[S.qi];
    const choix = (q.choix[S.langue] && q.choix[S.langue].some(x => x)) ? q.choix[S.langue] : q.choix[S.langue === 'nl' ? 'fr' : 'nl'];
    return `<div class="tb-q"><div class="mini" style="font-size:14px">${esc(L(S.sop.titre))} · ${esc(t().question)} ${S.qi + 1} / ${qs.length}</div>
      <div class="prog" style="margin:10px 0 18px;height:8px"><i class="pr" style="width:${Math.round(S.qi / qs.length * 100)}%"></i></div>
      <h1>${esc(L(q.question))}</h1>
      ${choix.map((c, j) => `<button class="tb-rep ${S.choix === j ? 'on' : ''}" data-choix="${j}">${esc(c)}</button>`).join('')}
      <button class="tb-gros" data-a="repondre" ${S.choix === null || S.occupe ? 'disabled' : ''}>${esc(t().valider)}</button></div>`;
  }

  function vueResultat() {
    const r = S.resultat;
    if (r.reussi) {
      return `<div class="tb-q" style="text-align:center"><div class="rond">✓</div><h1>${esc(t().reussi)} · ${r.score} / ${r.total}</h1>
        <div class="carte" style="text-align:left;font-size:16px">${esc(t().reste)}</div>
        <button class="tb-gros" data-a="vu">${esc(t().demander)}</button><button class="tb-gros sec" data-a="liste">${esc(t().plusTard)}</button></div>`;
    }
    const qs = S.sop.contenu.quiz || [];
    return `<div class="tb-q" style="text-align:center"><div class="rond ko">!</div><h1>${esc(t().rate)} · ${r.score} / ${r.total}</h1>
      <div class="carte" style="text-align:left">${r.faux.map(i => `<div style="padding:6px 0;font-size:15px">✗ ${esc(L(qs[i].question))}</div>`).join('')}</div>
      <button class="tb-gros" data-a="relire">${esc(t().relire)}</button><button class="tb-gros sec" data-a="requiz">${esc(t().recommencer)}</button></div>`;
  }

  /* ── « Vu faire » : la responsable valide avec son PIN ── */
  function vueVu() {
    const vals = (S.init.personnes || []).filter(p => p.valideur && (!S.moi || p.id !== S.moi.id));
    if (S.valideur === null && vals.length === 1) { S.valideur = vals[0].id; }
    const v = vals.find(p => p.id === S.valideur);
    return `<div class="tb-corps"><div class="tb-liste"><div class="tb-q" style="margin-top:10px">
        <div class="mini" style="font-size:14px">${esc(S.moi ? S.moi.nom : '')} · ${esc(L(S.sop.titre))}</div><h1>${esc(t().vuFaire)}</h1>
        <div class="carte" style="font-size:16px">${esc(t().vuFaireS)}</div>
        <textarea class="in" style="min-height:80px;font-size:15px" placeholder="${esc(t().noteP)}" data-note>${esc(S.note)}</textarea>
        ${S.erreur ? `<div class="erreur" style="margin-top:10px">${esc(S.erreur)}</div>` : ''}
        <div style="display:flex;gap:10px;margin-top:12px"><button class="tb-gros sec" data-a="pasencore" ${!v || S.pin.length < 4 ? 'disabled' : ''}>${esc(t().pasEncore)}</button></div></div></div>
      <div class="tb-cote"><h2>${esc(t().responsable)}</h2>
        <div class="tb-pers">${vals.map(p => `<button data-val="${p.id}" class="${S.valideur === p.id ? 'on' : ''}"><b>${esc(p.nom)}</b><small>${esc(t().responsable)}</small></button>`).join('')}</div>
        ${v ? pave(t().pinDe + ' ' + v.nom, 'vu') : ''}</div></div>`;
  }

  /* ── Rendu et gestes ── */
  function rendre() {
    const el = $('#tb');
    document.documentElement.lang = S.langue;
    if (!S.etatLu) { el.innerHTML = '<div class="vide">…</div>'; return; }
    if (!S.appareil) { el.innerHTML = vuePinMagasin(); return; }
    if (!S.shop) {
      el.innerHTML = `<div class="tbm"><div class="tbm-carte"><img src="../img/logo.png" alt="L'Atelier By" class="tbm-logo"><h1 class="tbm-titre">${esc(S.appareil.nom)}</h1>
        <p class="tbm-err">${esc(t().mag.sansBoutique)}</p><button class="tbm-fermer" data-a="fermerTablette">${esc(t().mag.fermer)}</button></div></div>`;
      return;
    }
    if (!S.init) { el.innerHTML = entete(false) + `<div class="vide">${esc(S.erreur || '…')}</div>`; return; }
    const vues = { liste: vueListe, lecture: vueLecture, quiz: vueQuiz, resultat: vueResultat, vu: vueVu };
    const autre = S.autreShop && S.vue === 'liste' ? `<div class="tbm-note">${esc(t().mag.autre.replace('%s', S.appareil.nom))}</div>` : '';
    el.innerHTML = entete(S.vue !== 'liste') + autre + (vues[S.vue] || vueListe)();
  }

  async function ouvrir(id) {
    try {
      S.sop = await api('/formation/sop/tablette/sop/' + encodeURIComponent(id));
      S.vue = 'lecture'; S.etape = 0; S.vuEtapes = {}; S.qi = 0; S.reponses = []; S.choix = null; S.resultat = null; S.valideur = null; S.pin = ''; S.note = ''; S.erreur = null;
      rendre(); window.scrollTo(0, 0);
    } catch (e) { toast(e.message); }
  }

  async function touche(k, act) {
    if (k === '⌫') { S.pin = S.pin.slice(0, -1); rendre(); return; }
    if (k !== '✓') { if (S.pin.length < 8) { S.pin += k; } rendre(); if (S.pin.length < 4 || k !== '✓') { return; } }
    if (S.pin.length < 4) { return; }
    S.occupe = true; S.erreur = null; rendre();
    try {
      if (act === 'identifier') {
        const r = await api('/formation/sop/tablette/identifier', { shop: S.shop, employe: S.pinPour, pin: S.pin });
        S.jeton = r.jeton; S.moi = r.personne; S.suivi = r.suivi || {};
        sessionStorage.setItem('foJeton', r.jeton);
        if (r.personne.langue && r.personne.langue !== S.langue) { S.langue = r.personne.langue; localStorage.setItem('foLangue', S.langue); }
        S.pinPour = null; S.onglet = 'journee'; actif();
      } else if (act === 'vu') {
        await valider('vu');
      }
    } catch (e) { S.erreur = e.message; }
    S.pin = ''; S.occupe = false; rendre();
  }

  async function valider(decision) {
    const r = await api('/formation/sop/tablette/vu', { shop: S.shop, sop: S.sop.id, employe: S.moi.id, valideur: S.valideur, pin: S.pin, decision, note: S.note || null });
    S.suivi[S.sop.id] = Object.assign({}, S.suivi[S.sop.id] || {}, { statut: r.statut });
    let m = decision === 'vu' ? t().okVu : t().pasEncore;
    // Les compétences C-… de la SOP (Manuel du réseau) : celles acquises sont cochées au panel ; un refus se dit.
    const acq = (r.competences || []).filter(x => x.acquise);
    if (acq.length) {
      m = t().compAcq + ' : ' + acq.map(x => x.nom + (x.coche && x.coche.ok ? ' (' + t().panelCoche + ')' : x.coche ? ' · panel : ' + x.coche.erreur : '')).join(' · ');
    } else if ((r.competences || []).length) {
      m += ' · ' + r.competences.map(x => t().compEnc + ' ' + x.nom + ' ' + x.vues + ' / ' + x.total).join(' · ');
    } else if (r.competence && r.competence.acquise) {
      m = t().compAcq + ' : ' + r.competence.nom;
      // Cochée au panel pour la personne ; un refus du panel se dit, la validation reste enregistrée.
      if (r.competence.panel && !r.competence.panel.ok) { m += ' · panel : ' + r.competence.panel.erreur; }
    }
    else if (r.competence) { m += ' · ' + t().compEnc + ' ' + r.competence.vues + ' / ' + r.competence.total; }
    toast(m);
    S.vue = 'liste'; S.sop = null;
  }

  document.addEventListener('click', async ev => {
    const b = ev.target.closest('button, [data-zoom]');
    if (!b) { return; }
    const d = b.dataset;
    if (d.zoom) { const z = document.createElement('div'); z.className = 'zoom'; z.innerHTML = `<img src="${esc(d.zoom)}">`; z.onclick = () => z.remove(); document.body.appendChild(z); return; }
    if (d.pm) { return touchePinMag(d.pm); }
    if (d.l) {
      S.langue = d.l; localStorage.setItem('foLangue', S.langue);
      if (S.jeton) { api('/formation/sop/tablette/langue', { jeton: S.jeton, langue: S.langue }, 'PUT').catch(() => {}); }
      return rendre();
    }
    if (d.poste) { S.poste = d.poste; localStorage.setItem('foPoste', S.poste); return rendre(); }
    if (d.onglet) { S.onglet = d.onglet; return rendre(); }
    if (d.pers) { S.pinPour = +d.pers; S.pin = ''; S.erreur = null; return rendre(); }
    if (d.val) { S.valideur = +d.val; S.pin = ''; S.erreur = null; return rendre(); }
    if (d.k) { return touche(d.k, d.act); }
    if (d.sop) { return ouvrir(d.sop); }
    if (d.etape !== undefined) { S.vuEtapes[S.etape] = true; S.etape = +d.etape; return rendre(); }
    if (d.choix !== undefined) { S.choix = +d.choix; return rendre(); }
    switch (d.a) {
      case 'liste': S.vue = 'liste'; S.sop = null; S.erreur = null; return rendre();
      case 'sortir': return sortir();
      case 'fermerTablette': return fermerAppareil();
      case 'prec': S.etape = Math.max(0, S.etape - 1); rendre(); return window.scrollTo(0, 0);
      case 'suiv': S.vuEtapes[S.etape] = true; S.etape++; rendre(); return window.scrollTo(0, 0);
      case 'fini': {
        S.vuEtapes[S.etape] = true;
        if (!S.jeton) { toast(t().identifie); S.vue = 'liste'; S.sop = null; return rendre(); }
        try { const r = await api('/formation/sop/tablette/lu', { jeton: S.jeton, sop: S.sop.id }); S.suivi[S.sop.id] = Object.assign({}, S.suivi[S.sop.id] || {}, { statut: r.statut }); toast(t().okLu); }
        catch (e) { toast(e.message); }
        if (S.sop.statut === 'a_valider' || !(S.sop.contenu.quiz || []).length) { S.vue = 'liste'; S.sop = null; return rendre(); }
        S.vue = 'quiz'; S.qi = 0; S.reponses = []; S.choix = null; return rendre();
      }
      case 'repondre': {
        S.reponses[S.qi] = S.choix; S.choix = null;
        if (S.qi < S.sop.contenu.quiz.length - 1) { S.qi++; return rendre(); }
        S.occupe = true;
        try { S.resultat = await api('/formation/sop/tablette/quiz', { jeton: S.jeton, sop: S.sop.id, reponses: S.reponses }); S.suivi[S.sop.id] = Object.assign({}, S.suivi[S.sop.id] || {}, { statut: S.resultat.statut }); S.vue = 'resultat'; }
        catch (e) { toast(e.message); }
        S.occupe = false; return rendre();
      }
      case 'relire': S.vue = 'lecture'; S.etape = 0; return rendre();
      case 'requiz': S.vue = 'quiz'; S.qi = 0; S.reponses = []; S.choix = null; return rendre();
      case 'vu': S.vue = 'vu'; S.pin = ''; S.valideur = null; S.erreur = null; return rendre();
      case 'pasencore':
        S.occupe = true;
        try { await valider('pas_encore'); } catch (e) { S.erreur = e.message; }
        S.pin = ''; S.occupe = false; return rendre();
    }
  });
  document.addEventListener('input', ev => { if (ev.target.matches('[data-note]')) { S.note = ev.target.value; } });

  demarrer();
})();
