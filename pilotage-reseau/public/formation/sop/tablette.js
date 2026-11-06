/* La tablette du poste (module Formation › Standards, SOP) — lire une SOP pas à pas, répondre au quiz, faire valider « vu faire » par la responsable.
 *
 * ?shop=<magasin>&poste=<cuisson|production|vente|nettoyage> — l'onglet SOP de l'app Cuisine ouvre cette page.
 * On s'identifie comme pour les checklists : son nom, puis son PIN (vérifié au panel par le cockpit ; le PIN ne
 * reste jamais dans la tablette). Sans identification on peut lire, mais rien n'est compté. Après 10 minutes sans
 * geste, la tablette se déconnecte : elle est partagée.
 * API : ../api/cockpit/formation/sop/tablette/* (src/sop.php), à côté du module Formation.
 *
 * TROIS GRANDS ONGLETS (06/11/2026, client : « la tablette doit être orientée opérationnel, donc les SOP : Ouverture ·
 * Journée de travail · Fermeture ; c'est le mode d'emploi pour la tablette ») : chaque onglet montre les SOP de son
 * moment (journée = journée, après-midi, puis « au besoin » pour une SOP sans moment) ; « Comment ça marche » explique la
 * tablette. L'onglet d'office suit l'heure (avant 11 h : ouverture ; à partir de 17 h : fermeture). Identifiée, la personne
 * voit d'abord SES SOP (« Mes postes » : un de ses postes v25, sinon son poste de tablette) et leur état (à lire, lu,
 * quiz, vu faire) ; « Tout le magasin » montre les autres. Les anciens onglets « Ma journée » / « Toutes les SOP » sont
 * remplacés.
 *
 * OUVERTE ET LA MÊME POUR TOUS LES MAGASINS (06/11/2026, client : « la tablette doit être ouverte, et pour tous les
 * magasins la même chose » ; « je n'ai pas besoin du suivi pour les SOP ») : plus de PIN du magasin ni d'identification ;
 * la tablette est le mode d'emploi du travail en magasin. On lit les SOP pas à pas ; le quiz reste un exercice, corrigé
 * par le serveur sans rien enregistrer. LIRE UNE SOP = UN ASSISTANT HORIZONTAL (06/11/2026) : frise des étapes en haut,
 * l'étape en grand (texte à gauche, photo ou vidéo à droite), deux gros boutons en bas ; balayer l'écran ou les flèches
 * du clavier passent d'une étape à l'autre. Ce qui suit (PIN du magasin, PIN de l'employé, « vu faire ») décrit l'ancien
 * fonctionnement, dont les routes restent sur le serveur.
 *
 * Ancien : UNE SEULE TABLETTE PAR MAGASIN (lot 5 de la fusion, 06/11/2026, src/tablettes.php) : au premier lancement, le PIN DU
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
      prec: '← Précédente', suiv: 'C’est fait · suivante →', fini: 'J’ai tout lu ✓', faites: 'étapes faites', faite: 'étape faite', restent: 'restantes',
      st: { non: 'à lire', lu: 'lue · quiz à faire', quiz: 'quiz réussi · à voir faire', vu: 'vue faire ✓', a_revoir: 'nouvelle version · à revoir' },
      aValider: 'à valider', aValiderS: 'SOP à valider : lisible, sans quiz ni validation pour l’instant.', sansComp: 'Autres SOP',
      question: 'Question', valider: 'Valider', reussi: 'Quiz réussi', rate: 'Pas encore', relire: 'Relire la SOP', recommencer: 'Recommencer le quiz',
      demander: 'Demander à être vu(e) faire', plusTard: 'Plus tard', reste: 'Il reste à être vu(e) faire par la responsable du magasin, sur le poste.',
      vuFaire: 'Vu faire', vuFaireS: 'La responsable a vu faire cette SOP, sur place.', responsable: 'Responsable', pasEncore: 'Pas encore', okVu: '✓ Vu faire',
      noteP: 'Une remarque (facultatif)', compAcq: 'Compétence acquise', compEnc: 'Compétence', retour: '← Les SOP', identifie: 'Identifie-toi pour que ta lecture soit comptée.',
      faux: 'À revoir', okLu: 'Lecture enregistrée', erreurCharge: 'Impossible de charger les SOP', pasDeQuiz: 'Cette SOP n’a pas de quiz.',
      journee: 'Ma journée', toutes: 'Toutes les SOP', sesPostes: 'Mes postes', rienJournee: 'Aucun standard n’est encore rangé pour tes postes.',
      moment: { ouverture: 'Ouverture', journee: 'Journée', 'apres-midi': 'Après-midi', fermeture: 'Fermeture', autres: 'Autres moments' },
      onglets: { ouverture: 'Ouverture', journee: 'Journée de travail', fermeture: 'Fermeture', aide: 'Comment ça marche' }, manuel: 'Mode d’emploi du magasin', okFin: 'SOP lue jusqu’au bout',
      ongletsS: { ouverture: 'avant les clients', journee: 'du début à la fin', fermeture: 'jusqu’au coffre', aide: 'la tablette' },
      sousJ: { journee: 'Pendant la journée', 'apres-midi': 'L’après-midi', autres: 'Au besoin' }, mesPostes: 'Mes postes', toutMagasin: 'Tout le magasin',
      rienMoment: 'Aucune SOP pour ce moment.', aideT: 'La tablette du magasin',
      aide: [['Choisir le moment', 'Ouverture, Journée de travail ou Fermeture : les SOP du moment.'],
        ['Choisir ton poste', 'Vente, Production, Cuisson ou Nettoyage — ou toutes.'],
        ['Suivre la SOP pas à pas', 'Les étapes une par une, avec les photos, les vidéos et les points critiques.'],
        ['Faire le quiz', 'À la fin, quelques questions pour vérifier : c’est un exercice, rien n’est enregistré.']],
      etats: { non: 'à lire', lu: 'lu', quiz: 'quiz réussi', vu: 'vu faire', a_revoir: 'à revoir' }, standards: 'standards', panelCoche: 'cochée au panel',
      mag: { titre: 'Tablette du magasin', pin: 'PIN du magasin', aide: 'Les 6 chiffres donnés par le consultant, une seule fois sur cette tablette.', magasin: 'Magasin',
        ouvrir: 'Ouvrir', effacer: 'Effacer', ouverture: 'Ouverture…', fermer: 'Fermer la tablette', fermerQ: 'Fermer la tablette ? Il faudra retaper le PIN du magasin.',
        sansBoutique: 'Cette tablette n’est reliée à aucune boutique : à régler dans le Cockpit › Formation › Tablettes.', autre: 'Cette tablette est celle de « %s » : le lien demandait un autre magasin.',
        err: { pin: 'PIN du magasin inconnu.', archive: 'Cette tablette n’est plus active.', essais: 'Trop d’essais : réessayer dans 15 minutes.', suspendu: 'Ouverture suspendue (trop d’essais) : réessayer dans une heure.', reseau: 'Pas de connexion : réessayer.' } } },
    nl: { titre: 'SOP', poste: { cuisson: 'Bakken', production: 'Productie', vente: 'Verkoop', nettoyage: 'Schoonmaak', tous: 'Alle' },
      quiLit: 'Wie leest?', quiLitS: 'Kies je naam en tik je pincode — zoals bij de checklists.', pinDe: 'Pincode van', anonyme: 'Niet aangemeld',
      lireSans: 'Zonder aanmelding kun je lezen, maar niets wordt geteld.', changer: 'Andere persoon', sortir: 'Afmelden',
      vus: 'voorgedaan', quiz: 'quiz geslaagd', lues: 'gelezen', etapes: 'stappen', etape: 'Stap', crit: 'Kritiek punt',
      prec: '← Vorige', suiv: 'Klaar · volgende →', fini: 'Alles gelezen ✓', faites: 'stappen klaar', faite: 'stap klaar', restent: 'te gaan',
      st: { non: 'te lezen', lu: 'gelezen · quiz te doen', quiz: 'quiz geslaagd · voor te doen', vu: 'voorgedaan ✓', a_revoir: 'nieuwe versie · opnieuw' },
      aValider: 'te valideren', aValiderS: 'SOP te valideren: leesbaar, nog zonder quiz of validatie.', sansComp: 'Andere SOP',
      question: 'Vraag', valider: 'Bevestigen', reussi: 'Quiz geslaagd', rate: 'Nog niet', relire: 'SOP opnieuw lezen', recommencer: 'Quiz opnieuw',
      demander: 'Vragen om voor te doen', plusTard: 'Later', reste: 'Nu nog voordoen voor de winkelverantwoordelijke, op de post.',
      vuFaire: 'Voorgedaan', vuFaireS: 'De verantwoordelijke heeft deze SOP zien uitvoeren, ter plaatse.', responsable: 'Verantwoordelijke', pasEncore: 'Nog niet', okVu: '✓ Voorgedaan',
      noteP: 'Een opmerking (facultatief)', compAcq: 'Competentie verworven', compEnc: 'Competentie', retour: '← De SOP', identifie: 'Meld je aan zodat je lezing telt.',
      faux: 'Opnieuw bekijken', okLu: 'Lezing bewaard', erreurCharge: 'De SOP konden niet geladen worden', pasDeQuiz: 'Deze SOP heeft geen quiz.',
      journee: 'Mijn dag', toutes: 'Alle SOP', sesPostes: 'Mijn posten', rienJournee: 'Er staat nog geen standaard voor jouw posten.',
      moment: { ouverture: 'Opening', journee: 'Overdag', 'apres-midi': 'Namiddag', fermeture: 'Sluiting', autres: 'Andere momenten' },
      onglets: { ouverture: 'Opening', journee: 'Werkdag', fermeture: 'Sluiting', aide: 'Hoe werkt het' }, manuel: 'Handleiding van de winkel', okFin: 'SOP helemaal gelezen',
      ongletsS: { ouverture: 'vóór de klanten', journee: 'van begin tot einde', fermeture: 'tot aan de kluis', aide: 'de tablet' },
      sousJ: { journee: 'Overdag', 'apres-midi': 'In de namiddag', autres: 'Indien nodig' }, mesPostes: 'Mijn posten', toutMagasin: 'Hele winkel',
      rienMoment: 'Geen SOP voor dit moment.', aideT: 'De tablet van de winkel',
      aide: [['Het moment kiezen', 'Opening, Werkdag of Sluiting: de SOP van dat moment.'],
        ['Je post kiezen', 'Verkoop, Productie, Bakken of Schoonmaak — of alle.'],
        ['De SOP stap voor stap volgen', 'De stappen één voor één, met de foto’s, de video’s en de kritieke punten.'],
        ['De quiz doen', 'Op het einde enkele vragen om na te gaan: een oefening, er wordt niets bewaard.']],
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
    vue: 'liste', sop: null, etape: 0, vuEtapes: {}, sens: '', qi: 0, reponses: [], choix: null, resultat: null,
    pinPour: null, pin: '', valideur: null, note: '', occupe: false, erreur: null,
    onglet: ongletDuMoment(), // ouverture · journee · fermeture · aide (06/11/2026)
    tout: false               // identifiée : false = ses postes, true = tout le magasin
  };
  if (!POSTES.includes(S.poste)) { S.poste = 'tous'; }
  /** L'onglet d'office : l'heure du magasin (avant 11 h : ouverture ; à partir de 17 h : fermeture ; sinon la journée). */
  function ongletDuMoment() { const h = new Date().getHours(); return h < 11 ? 'ouverture' : h >= 17 ? 'fermeture' : 'journee'; }
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
    S.etatLu = true; S.appareil = { nom: '' };
    return charger();
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
    try { S.init = await api('/formation/sop/tablette/init'); S.erreur = null; }
    catch (e) { S.erreur = t().erreurCharge + ' : ' + e.message; }
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
    const m = S.init && S.init.magasin ? S.init.magasin.nom : t().manuel;
    return `<div class="tb-hd">${retour ? `<button class="tb-ret" data-a="liste">←</button>` : ''}<img src="../img/logo.png" alt="L'Atelier">
      <div class="t">${S.sop ? esc(L(S.sop.titre)) : esc(t().titre) + ' · ' + esc(m)}<small>${esc(S.sop ? (S.sop.code || '') + ' · v' + S.sop.version : (S.poste === 'tous' ? t().poste.tous : t().poste[S.poste]))}</small></div>
      <span class="sp"></span>
      <span class="tb-bascule"><button data-l="fr" class="${S.langue === 'fr' ? 'on' : ''}">FR</button><button data-l="nl" class="${S.langue === 'nl' ? 'on' : ''}">NL</button></span>
</div>`;
  }

  /* ── La liste : trois grands onglets, Ouverture · Journée de travail · Fermeture, et « Comment ça marche » ── */
  const ONGLETS = ['ouverture', 'journee', 'fermeture', 'aide'];
  const ongletDe = m => m === 'ouverture' ? 'ouverture' : m === 'fermeture' ? 'fermeture' : 'journee';
  function onglets() {
    const n = k => (S.init.sops || []).filter(s => ongletDe(s.moment) === k && visible(s)).length;
    return `<div class="tb-moments" role="tablist">${ONGLETS.map(k => `<button role="tab" data-onglet="${k}" class="${S.onglet === k ? 'on' : ''}" aria-selected="${S.onglet === k}">
      <b>${esc(t().onglets[k])}</b><small>${k === 'aide' ? esc(t().ongletsS[k]) : n(k) + ' SOP · ' + esc(t().ongletsS[k])}</small></button>`).join('')}</div>`;
  }
  /** Un standard est de la journée de la personne : un de ses postes v25 est celui du standard (sinon l'ancien poste). */
  function pourMoi(s) {
    const p = (s.classement && s.classement.postes) || [];
    if (p.length) { return p.some(x => (S.moi.postesV25 || []).includes(x)); }
    return (S.moi.postes || []).includes(s.poste);
  }
  /** Ce que la liste montre : identifiée, ses postes (ou tout le magasin) ; sinon le poste choisi. */
  const visible = s => S.moi ? (S.tout || pourMoi(s)) : (S.poste === 'tous' || s.poste === S.poste);
  function vueListe() {
    if (!ONGLETS.includes(S.onglet)) { S.onglet = ongletDuMoment(); }
    if (S.onglet === 'aide') { return `<div class="tb-corps"><div class="tb-liste">${onglets()}${vueAide()}</div></div>`; }
    const st = id => (S.suivi[id] || {}).statut || 'non';
    const ic = { non: '·', lu: 'L', quiz: 'Q', vu: '✓', a_revoir: '!' };
    const sops = (S.init.sops || []).filter(s => ongletDe(s.moment) === S.onglet && visible(s));
    // La journée se lit dans l'ordre : la journée, l'après-midi, puis ce qui se fait au besoin ; ouverture et fermeture par poste.
    const groupes = S.onglet === 'journee'
      ? [['journee', t().sousJ.journee], ['apres-midi', t().sousJ['apres-midi']], ['autres', t().sousJ.autres]].map(([m, l]) => ({ l, sops: sops.filter(s => m === 'autres' ? !['journee', 'apres-midi'].includes(s.moment) : s.moment === m) }))
      : POSTES.map(p => ({ l: t().poste[p], sops: sops.filter(s => s.poste === p) })).concat([{ l: t().sansComp, sops: sops.filter(s => !POSTES.includes(s.poste)) }]);
    const corps = groupes.filter(g => g.sops.length).map(g => {
      const vus = g.sops.filter(s => st(s.id) === 'vu').length;
      return `<div class="tb-grp"><div class="sec-t">${esc(g.l)}${S.moi ? `<small>${vus} / ${g.sops.length} ${esc(t().vus)}</small>` : ''}</div>
        ${g.sops.map(s => `<button class="tb-sop" data-sop="${esc(s.id)}"><span class="st ${st(s.id)}">${ic[st(s.id)]}</span><span class="x"><b>${esc(L(s.titre))}${s.statut === 'a_valider' ? ` <span class="pill att">${esc(t().aValider)}</span>` : ''}</b>
          <small>${esc(t().poste[s.poste] || s.poste || '')} · ${s.nbEtapes} ${esc(t().etapes)}</small></span>${S.moi ? `<span class="pill ${st(s.id) === 'vu' ? 'ok' : st(s.id) === 'a_revoir' ? 'ko' : st(s.id) === 'non' ? '' : 'att'}">${esc(t().etats[st(s.id)])}</span>` : ''}<span class="fl">›</span></button>`).join('')}</div>`;
    }).join('') || `<div class="vide">${esc(S.moi && !S.tout ? t().rienJournee : t().rienMoment)}</div>`;
    const chips = S.moi
      ? `<div class="tb-chips"><button data-tout="0" class="${S.tout ? '' : 'on'}">${esc(t().mesPostes)}</button><button data-tout="1" class="${S.tout ? 'on' : ''}">${esc(t().toutMagasin)}</button></div>`
      : `<div class="tb-chips">${['tous'].concat(POSTES).map(p => `<button data-poste="${p}" class="${S.poste === p ? 'on' : ''}">${esc(t().poste[p])}</button>`).join('')}</div>`;
    return `<div class="tb-corps"><div class="tb-liste">${onglets()}${chips}${corps}</div></div>`;
  }
  function vueAide() {
    return `<div class="tb-aide"><div class="sec-t">${esc(t().aideT)}</div><ol>${t().aide.map(([a, b]) => `<li><b>${esc(a)}</b><span>${esc(b)}</span></li>`).join('')}</ol>
</div>`;
  }

  /** Une photo d'étape : celles du dépôt (sop/photos/), celles envoyées par l'assistant de création (« u/… », servies par l'API). */
  const photoUrl = p => String(p).startsWith('u/') ? API + '/formation/sop/photo/' + encodeURIComponent(String(p).slice(2)) : 'photos/' + p;
  /** La vidéo d'une étape : YouTube ou Vimeo dans la page, sinon un lien. */
  function video(u) {
    const yt = /(?:youtube\.com\/(?:watch\?v=|shorts\/|embed\/)|youtu\.be\/)([\w-]{6,20})/.exec(u), vi = /vimeo\.com\/(?:video\/)?(\d{5,12})/.exec(u);
    const src = yt ? 'https://www.youtube-nocookie.com/embed/' + yt[1] + '?rel=0' : vi ? 'https://player.vimeo.com/video/' + vi[1] : '';
    return src ? `<div class="tb-video"><iframe src="${esc(src)}" title="Vidéo" allow="fullscreen; picture-in-picture" allowfullscreen loading="lazy"></iframe></div>`
      : `<a class="tb-video__lien" href="${esc(u)}" target="_blank" rel="noopener">▶ Vidéo ↗</a>`;
  }

  /** Le point critique : un triangle d'alerte au trait (design system §7.12), 28 px pour l'écran du poste. */
  const ALERTE = '<svg class="tb-alerte__i" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0zM12 9v4M12 17h.01"/></svg>';

  function cote() {
    if (S.moi) {
      const v = Object.values(S.suivi);
      const n = k => v.filter(x => x.statut === k).length;
      return `<div class="tb-cote"><h2>${esc(S.moi.nom)}</h2><div class="mini" style="font-size:13px">${esc((S.moi.postes || []).map(p => t().poste[p] || p).join(' · '))}</div>
        <div class="carte" style="background:var(--paper)"><div style="display:flex;gap:18px;font-size:14px"><span><b class="tb-n">${n('vu')}</b> ${esc(t().vus)}</span><span><b class="tb-n">${n('quiz')}</b> ${esc(t().quiz)}</span><span><b class="tb-n">${n('lu')}</b> ${esc(t().lues)}</span></div></div>
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
  /* L'assistant horizontal (06/11/2026, client : « un assistant de type horizontal, fait pour le travail sur tablette ») :
     en haut la frise des étapes, rangées par section, qui défile à l'horizontale (l'étape affichée au centre) ; l'étape
     en grand, le texte à gauche et la photo ou la vidéo à droite (empilés en portrait) ; en bas deux gros boutons. On
     passe d'une étape à l'autre en balayant l'écran, par la frise ou par les flèches du clavier. */
  function vueLecture() {
    const E = etapes();
    if (!E.length) { return `<div class="vide">—</div>`; }
    const i = Math.min(S.etape, E.length - 1), e = E[i];
    let k = -1;
    const frise = (S.sop.contenu.sections || []).map((sec, si) => `<div class="tb-fr-g ${si === e.si ? 'on' : ''}">
      <div class="tb-fr-s" title="${esc(L(sec.titre))}">${esc(L(sec.titre))}</div><div class="tb-fr-p">${sec.etapes.map(x => { k++;
        const fait = S.vuEtapes[k] && k !== i, court = L(x.titre) || L(x.texte).split('\n')[0].slice(0, 60);
        return `<button data-etape="${k}" class="${k === i ? 'on' : fait ? 'fait' : ''}${x.critique ? ' crit' : ''}" title="${esc(x.numero + ' · ' + court)}"
          aria-label="${esc(t().etape + ' ' + (k + 1) + ' : ' + court)}"${k === i ? ' aria-current="step"' : ''}>${fait ? '✓' : esc(x.numero)}</button>`; }).join('')}</div></div>`).join('');
    const titre = L(e.titre), texte = L(e.texte);
    const dernier = i === E.length - 1;
    const faites = Object.keys(S.vuEtapes).length;
    const media = (e.photo ? `<img class="photo" src="${esc(photoUrl(e.photo))}" alt="" data-zoom="${esc(photoUrl(e.photo))}">` : '') + (e.video ? video(e.video) : '');
    return `<div class="tb-pas">
      <nav class="tb-frise" aria-label="${esc(t().etapes)}">${frise}</nav>
      <div class="tb-prog"><i style="width:${Math.round(faites / E.length * 100)}%"></i></div>
      <section class="tb-etape${media ? ' avec' : ''}${S.sens ? ' ' + S.sens : ''}" data-balayage>
        <div class="tb-etape__tx"><div class="ou">${esc(t().etape)} ${i + 1} / ${E.length} · <span>${esc(L(e.section))}</span></div>
          ${S.sop.statut === 'a_valider' ? `<div class="note">${esc(t().aValiderS)}</div>` : ''}
          <h1>${esc(titre || ('' + e.numero))}</h1>
          ${e.critique ? `<div class="tb-alerte">${ALERTE}<div class="tb-alerte__tx"><b>${esc(t().crit)}</b>${esc(texte)}</div></div>` : `<div class="tx">${esc(texte)}</div>`}</div>
        ${media ? `<div class="tb-etape__media">${media}</div>` : ''}
      </section>
      <div class="tb-pied"><button class="gb" data-a="prec" ${i === 0 ? 'disabled' : ''}>${esc(t().prec)}</button>
        <div class="av"><b>${esc(t().etape)} ${i + 1} / ${E.length}</b><small>${faites} ${esc(faites > 1 ? t().faites : t().faite)} · ${E.length - faites} ${esc(t().restent)}</small></div>
        ${dernier ? `<button class="gb g" data-a="fini">${esc(t().fini)}</button>` : `<button class="gb p" data-a="suiv">${esc(t().suiv)}</button>`}</div></div>`;
  }
  /** Avancer (d = 1 : l'étape est faite) ou reculer d'une étape ; aller à l'étape n (la frise). */
  function pas(d) {
    const N = etapes().length;
    if (d > 0) { S.vuEtapes[S.etape] = true; if (S.etape >= N - 1) { return rendre(); } S.etape++; }
    else { if (S.etape <= 0) { return; } S.etape--; }
    S.sens = d > 0 ? 'de-droite' : 'de-gauche';
    rendre();
  }
  function allerA(n) {
    if (n === S.etape) { return; }
    S.vuEtapes[S.etape] = true;
    S.sens = n > S.etape ? 'de-droite' : 'de-gauche';
    S.etape = n; rendre();
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
    if (r.reussi && r.exercice) {
      return `<div class="tb-q" style="text-align:center"><div class="rond">✓</div><h1>${esc(t().reussi)} · ${r.score} / ${r.total}</h1>
        <button class="tb-gros" data-a="liste">${esc(t().retour)}</button></div>`;
    }
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
    if (!S.init) { el.innerHTML = entete(false) + `<div class="vide">${esc(S.erreur || '…')}</div>`; return; }
    const vues = { liste: vueListe, lecture: vueLecture, quiz: vueQuiz, resultat: vueResultat, vu: vueVu };
    el.innerHTML = entete(S.vue !== 'liste') + (vues[S.vue] || vueListe)();
    el.classList.toggle('tb--pas', S.vue === 'lecture');
    if (S.vue === 'lecture') {
      const on = el.querySelector('.tb-frise button.on');
      const doux = S.sens && !matchMedia('(prefers-reduced-motion: reduce)').matches;
      if (on) { on.scrollIntoView({ inline: 'center', block: 'nearest', behavior: doux ? 'smooth' : 'auto' }); }
    }
    S.sens = '';   // l'animation d'entrée ne joue qu'au changement d'étape (pas sur FR / NL)
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
        S.pinPour = null; S.tout = false; actif();
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
    if (d.tout !== undefined) { S.tout = d.tout === '1'; return rendre(); }
    if (d.pers) { S.pinPour = +d.pers; S.pin = ''; S.erreur = null; return rendre(); }
    if (d.val) { S.valideur = +d.val; S.pin = ''; S.erreur = null; return rendre(); }
    if (d.k) { return touche(d.k, d.act); }
    if (d.sop) { return ouvrir(d.sop); }
    if (d.etape !== undefined) { return allerA(+d.etape); }
    if (d.choix !== undefined) { S.choix = +d.choix; return rendre(); }
    switch (d.a) {
      case 'liste': S.vue = 'liste'; S.sop = null; S.erreur = null; return rendre();
      case 'sortir': return sortir();
      case 'fermerTablette': return fermerAppareil();
      case 'prec': return pas(-1);
      case 'suiv': return pas(1);
      case 'fini': {
        S.vuEtapes[S.etape] = true;
        if (!S.jeton) {
          if (S.sop.statut === 'a_valider' || !(S.sop.contenu.quiz || []).length) { toast(t().okFin); S.vue = 'liste'; S.sop = null; return rendre(); }
          S.vue = 'quiz'; S.qi = 0; S.reponses = []; S.choix = null; return rendre();
        }
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
  // Balayer l'étape : vers la gauche = suivante (l'étape est faite), vers la droite = précédente. Un geste surtout
  // vertical fait défiler le texte, sans changer d'étape.
  let toucheX = null, toucheY = 0;
  document.addEventListener('touchstart', ev => {
    toucheX = S.vue === 'lecture' && ev.touches.length === 1 && ev.target.closest('[data-balayage]') && !ev.target.closest('iframe') ? ev.touches[0].clientX : null;
    toucheY = ev.touches[0] ? ev.touches[0].clientY : 0;
  }, { passive: true });
  document.addEventListener('touchend', ev => {
    if (toucheX === null || !ev.changedTouches[0]) { return; }
    const dx = ev.changedTouches[0].clientX - toucheX, dy = ev.changedTouches[0].clientY - toucheY;
    toucheX = null;
    if (Math.abs(dx) < 70 || Math.abs(dx) < Math.abs(dy) * 1.5) { return; }
    pas(dx < 0 ? 1 : -1);
  }, { passive: true });
  document.addEventListener('keydown', ev => {
    if (S.vue !== 'lecture' || ev.target.closest('input, textarea')) { return; }
    if (ev.key === 'ArrowRight') { ev.preventDefault(); pas(1); }
    else if (ev.key === 'ArrowLeft') { ev.preventDefault(); pas(-1); }
  });
  document.addEventListener('input', ev => { if (ev.target.matches('[data-note]')) { S.note = ev.target.value; } });

  demarrer();
})();
