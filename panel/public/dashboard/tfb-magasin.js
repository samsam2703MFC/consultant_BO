/* Le compte TF Buddy du magasin (25/11/2026 ; serveur panel/src/tfb_magasin.php). Sam : « all should work either with
 * admin, consultant or shop token ». Les routes du panel sous /panel/shops/{shop}/… (entreprises B2B, bureaux, analyse,
 * livraisons) ne répondent qu'à un employé du magasin connecté au panel : cette connexion est celle du panel lui-même,
 * téléphone, PIN, puis le code reçu par SMS. La session (12 h) reste dans ce navigateur (localStorage, une par magasin) ;
 * le jeton d'accès, court, se renouvelle tout seul avant d'expirer et part dans l'en-tête `X-TFB-Jeton` des modules
 * qui le demandent (Clients, Commandes). Le cockpit ne garde rien.
 *
 *   window.DashTfb.entetes(shop, api) → Promise<{ 'X-TFB-Jeton': … } | {}>
 *   window.DashTfb.etat(shop)         → { connecte, expireSession }
 *   window.DashTfb.ouvrir(shop, api, apres)   la fenêtre de connexion ; `apres()` une fois connecté
 *   window.DashTfb.deconnecter(shop, api)
 */
(function () {
  'use strict';
  const esc = v => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const cle = shop => 'tfb-magasin:' + shop;
  const maintenant = () => Math.floor(Date.now() / 1000);
  const lire = shop => {
    try {
      const s = JSON.parse(localStorage.getItem(cle(shop)) || 'null');
      if (!s || !s.acces) { return null; }
      if (s.expireSession && s.expireSession < maintenant()) { localStorage.removeItem(cle(shop)); return null; }
      return s;
    } catch (e) { return null; }
  };
  const ecrire = (shop, j) => { try { if (j) { localStorage.setItem(cle(shop), JSON.stringify(j)); } else { localStorage.removeItem(cle(shop)); } } catch (e) { /* stockage refusé */ } };
  const poster = (api, p, corps) => fetch(api + p, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify(corps) })
    .then(r => r.json().catch(() => ({})).then(j => { if (!r.ok) { const e = new Error(j.error || ('erreur ' + r.status)); e.code = r.status; e.expire = !!j.expire; e.tfb = j.code || null; e.attente = !!j.attente; throw e; } return j; }));
  const enCours = {};
  /** Le cookie du jeton (tfb_jeton_{shop}) : 5 minutes, même site seulement, HTTPS ; effacé sans jeton. */
  function cuire(shop, j) {
    try {
      const sec = location.protocol === 'https:' ? '; Secure' : '';
      document.cookie = 'tfb_jeton_' + shop + '=' + (j ? encodeURIComponent(j) : '') + '; path=/; SameSite=Strict' + sec + (j ? '; max-age=300' : '; max-age=0');
    } catch (e) { /* cookies refusés : l'en-tête reste */ }
  }

  /** Un jeton d'accès valable (renouvelé s'il expire dans moins de 45 s), ou null : pas de session, ou session finie. */
  function jeton(shop, api) {
    const s = lire(shop);
    if (!s) { return Promise.resolve(null); }
    if ((s.expireAcces || 0) - 45 > maintenant()) { return Promise.resolve(s.acces); }
    if (!s.renouvellement) { return Promise.resolve(s.acces); }
    // Un seul renouvellement à la fois : TF Buddy fait tourner le jeton de renouvellement, deux appels se marcheraient dessus.
    if (!enCours[shop]) {
      enCours[shop] = poster(api, '/dashboard/tfb/renouveler', { shop, renouvellement: s.renouvellement })
        .then(d => { const j = Object.assign({}, s, d.jetons); if (!j.expireSession) { j.expireSession = s.expireSession; } ecrire(shop, j); return j.acces; })
        .catch(e => { if (e.expire) { ecrire(shop, null); return null; } return s.acces; })
        .finally(() => { delete enCours[shop]; });
    }
    return enCours[shop];
  }

  /* Le code SMS en cours (25/11/2026) : gardé 5 minutes dans l'onglet (sessionStorage), il se reprend quand on rouvre
   * la fenêtre au lieu de redemander une connexion — chaque connexion envoie un SMS, et TF Buddy limite les essais par
   * téléphone et par adresse IP (PANEL_AUTH_LIMIT), avec 60 s entre deux SMS (PANEL_AUTH_RESEND_WAIT). */
  const cleCh = shop => 'tfb-challenge:' + shop;
  const chLire = shop => { try { const c = JSON.parse(sessionStorage.getItem(cleCh(shop)) || 'null'); return c && c.t + 300 > maintenant() ? c : null; } catch (e) { return null; } };
  const chEcrire = (shop, c) => { try { if (c) { sessionStorage.setItem(cleCh(shop), JSON.stringify(c)); } else { sessionStorage.removeItem(cleCh(shop)); } } catch (e) { /* stockage refusé */ } };

  /* ── La fenêtre de connexion ─────────────────────────────────────────────────────────────────────────────── */
  let fen = null;
  function fermer() { if (fen) { fen.remove(); fen = null; } }  // la minuterie de la fenêtre s'arrête d'elle-même (fen null)
  function ouvrir(shop, api, apres) {
    fermer();
    const enCoursCh = chLire(shop);
    const st = enCoursCh ? { etape: 'sms', challenge: enCoursCh.challenge, busy: false, err: null, info: 'Un code a déjà été envoyé par SMS il y a moins de 5 minutes : saisissez-le.', tel: enCoursCh.tel || '', envoi: enCoursCh.envoi || enCoursCh.t }
      : { etape: 'tel', challenge: null, busy: false, err: null, info: null, tel: '', envoi: 0 };
    let minuterie = null;
    const resteSms = () => Math.max(0, (st.envoi || 0) + 60 - maintenant());
    // Chaque fenêtre a son élément et sa minuterie : une fenêtre fermée ne redessine jamais la suivante.
    const el = document.createElement('div');
    fen = el;
    el.className = 'confirm tfbm-fen';
    const rendre = () => {
      if (fen !== el) { return; }
      el.innerHTML = `<div class="confirm__box card" role="dialog" aria-modal="true" aria-label="Connecter le compte TF Buddy du magasin" style="width:min(440px,100%)">
        <h3>Connecter le compte TF Buddy du magasin</h3>
        <p class="muted" style="font-size:12.5px;line-height:1.5">La connexion du panel TF Buddy : votre téléphone, votre PIN, puis le code reçu par SMS. La session dure 12 heures sur cet appareil ; le cockpit ne garde ni le PIN ni la session.</p>
        ${st.etape === 'tel' ? `<label class="tfbm-champ">Téléphone<input id="tfbm-tel" type="tel" autocomplete="tel" placeholder="+32 4.. .. .. .." value="${esc(st.tel)}"></label>
          <label class="tfbm-champ">PIN<input id="tfbm-pin" type="password" inputmode="numeric" autocomplete="current-password" maxlength="8"></label>`
        : `<p style="font-size:13px">${esc(st.info || 'Un code a été envoyé par SMS.')}</p><label class="tfbm-champ">Code reçu par SMS<input id="tfbm-code" inputmode="numeric" autocomplete="one-time-code" maxlength="8"></label>
          <div style="display:flex;gap:14px;align-items:center;margin-top:6px">${resteSms() ? `<span class="muted" style="font-size:12px">nouveau code possible dans ${resteSms()} s</span>` : '<button type="button" class="linklike" data-tfbm="sms">renvoyer le code</button>'}
            <button type="button" class="linklike" data-tfbm="autre">autre téléphone</button></div>`}
        ${st.err ? `<p class="error">${esc(st.err)}</p>` : ''}
        <div class="confirm__actions"><button type="button" class="btn btn--ghost" data-tfbm="fermer">Annuler</button>
          <button type="button" class="btn btn--primary" data-tfbm="ok"${st.busy ? ' disabled' : ''}>${st.etape === 'tel' ? 'Recevoir le code' : 'Se connecter'}</button></div></div>`;
      // Le focus : le PIN si le téléphone est déjà là (après un refus), sinon le premier champ.
      const i = (st.tel && el.querySelector('#tfbm-pin')) || el.querySelector('#tfbm-tel') || el.querySelector('#tfbm-code'); if (i) { i.focus(); }
    };
    const fini = j => { ecrire(shop, j); chEcrire(shop, null); clearInterval(minuterie); fermer(); if (apres) { apres(); } };
    const versSms = (ch, info) => { st.etape = 'sms'; st.challenge = ch; st.info = info; st.envoi = maintenant(); chEcrire(shop, { challenge: ch, tel: st.tel, t: maintenant(), envoi: st.envoi }); };
    // Le compte à rebours du « renvoyer le code » (60 s, la règle de TF Buddy).
    minuterie = setInterval(() => {
      if (fen !== el) { clearInterval(minuterie); return; }
      // Redessiner seulement pendant le compte à rebours (le code saisi est gardé).
      if (st.etape === 'sms' && resteSms() > 0 && !st.busy) { const c = el.querySelector('#tfbm-code'), v = c ? c.value : ''; rendre(); const c2 = el.querySelector('#tfbm-code'); if (c2) { c2.value = v; c2.focus(); } }
      else if (st.etape === 'sms' && resteSms() === 0 && !el.querySelector('[data-tfbm="sms"]') && !st.busy) { const c = el.querySelector('#tfbm-code'), v = c ? c.value : ''; rendre(); const c2 = el.querySelector('#tfbm-code'); if (c2) { c2.value = v; c2.focus(); } }
    }, 1000);
    const ok = () => {
      if (st.busy) { return; }
      st.busy = true; st.err = null;
      if (st.etape === 'tel') { st.tel = (el.querySelector('#tfbm-tel') || {}).value || ''; }
      const p = st.etape === 'tel'
        ? poster(api, '/dashboard/tfb/connexion', { shop, phone: st.tel, pin: (el.querySelector('#tfbm-pin') || {}).value || '' })
          .then(d => { if (d.etape === 'ok') { return fini(d.jetons); } versSms(d.challenge, d.texte); })
        : poster(api, '/dashboard/tfb/confirmer', { shop, challenge: st.challenge, code: (el.querySelector('#tfbm-code') || {}).value || '' }).then(d => fini(d.jetons));
      p.catch(e => {
        st.err = e.message;
        // Un code déjà parti (PANEL_AUTH_RESEND_WAIT) et encore en main : on revient à sa saisie.
        const memeTel = c => String(c.tel || '').replace(/\D/g, '') === String(st.tel || '').replace(/\D/g, '');
        if (e.attente && st.etape === 'tel') { const c = chLire(shop); if (c && memeTel(c)) { st.etape = 'sms'; st.challenge = c.challenge; st.envoi = c.envoi || c.t; st.err = null; st.info = 'Un code a déjà été envoyé par SMS : saisissez-le.'; } }
        // Un code épuisé ou expiré : on l'oublie, la prochaine tentative redemande une connexion.
        if (e.tfb === 'PANEL_CODE_INVALID' && e.code === 401) { chEcrire(shop, null); }
      }).finally(() => { st.busy = false; rendre(); });
      rendre();
    };
    el.addEventListener('click', ev => {
      if (ev.target === el) { fermer(); return; }
      const a = ev.target.closest('[data-tfbm]'); if (!a) { return; }
      if (a.dataset.tfbm === 'fermer') { fermer(); }
      else if (a.dataset.tfbm === 'ok') { ok(); }
      else if (a.dataset.tfbm === 'sms') { poster(api, '/dashboard/tfb/sms', { shop, challenge: st.challenge }).then(() => { st.info = 'Un nouveau code a été envoyé.'; st.err = null; st.envoi = maintenant(); const c = chLire(shop); if (c) { chEcrire(shop, Object.assign(c, { envoi: st.envoi })); } }).catch(e => { st.err = e.message; }).finally(rendre); }
      // « autre téléphone » : le code en cours reste gardé ; il se reprend si l'on redonne le même numéro avant 60 s.
      else if (a.dataset.tfbm === 'autre') { st.etape = 'tel'; st.challenge = null; st.err = null; st.info = null; rendre(); }
    });
    el.addEventListener('keydown', ev => { if (ev.key === 'Enter') { ev.preventDefault(); ok(); } if (ev.key === 'Escape') { fermer(); } });
    document.body.appendChild(el);
    rendre();
  }

  window.DashTfb = {
    /** L'en-tête du jeton, et le même jeton dans un cookie court (5 min) : il passe même si l'en-tête se perd en route. */
    entetes(shop, api) { return jeton(String(shop), api).then(j => { cuire(String(shop), j); return j ? { 'X-TFB-Jeton': j } : {}; }); },
    /** Le contrôle d'après connexion (GET /dashboard/tfb/etat) : le serveur reçoit-il le jeton, que répond TF Buddy avec lui ? */
    controler(shop, api) {
      return this.entetes(shop, api).then(h => fetch(api + '/dashboard/tfb/etat?shop=' + encodeURIComponent(shop), { credentials: 'same-origin', headers: Object.assign({ Accept: 'application/json' }, h) }))
        .then(r => r.json().catch(() => ({ error: 'réponse illisible' })));
    },
    etat(shop) { const s = lire(String(shop)); return { connecte: !!s, expireSession: s ? s.expireSession || null : null }; },
    ouvrir(shop, api, apres) { ouvrir(String(shop), api, apres); },
    deconnecter(shop, api) {
      const s = lire(String(shop)); ecrire(String(shop), null); cuire(String(shop), null);
      return s && s.renouvellement ? poster(api, '/dashboard/tfb/deconnexion', { shop: String(shop), renouvellement: s.renouvellement }).catch(() => null) : Promise.resolve(null);
    },
    /** Une réponse du serveur dit la session du magasin périmée : on l'oublie. */
    oublier(shop) { ecrire(String(shop), null); cuire(String(shop), null); },
  };
})();
