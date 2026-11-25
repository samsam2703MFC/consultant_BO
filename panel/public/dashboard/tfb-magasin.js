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
    .then(r => r.json().catch(() => ({})).then(j => { if (!r.ok) { const e = new Error(j.error || ('erreur ' + r.status)); e.code = r.status; e.expire = !!j.expire; throw e; } return j; }));
  const enCours = {};

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

  /* ── La fenêtre de connexion ─────────────────────────────────────────────────────────────────────────────── */
  let fen = null;
  function fermer() { if (fen) { fen.remove(); fen = null; } }
  function ouvrir(shop, api, apres) {
    fermer();
    const st = { etape: 'tel', challenge: null, busy: false, err: null, info: null, tel: '' };
    fen = document.createElement('div');
    fen.className = 'confirm tfbm-fen';
    const rendre = () => {
      fen.innerHTML = `<div class="confirm__box card" role="dialog" aria-modal="true" aria-label="Connecter le compte TF Buddy du magasin" style="width:min(440px,100%)">
        <h3>Connecter le compte TF Buddy du magasin</h3>
        <p class="muted" style="font-size:12.5px;line-height:1.5">La connexion du panel TF Buddy : votre téléphone, votre PIN, puis le code reçu par SMS. La session dure 12 heures sur cet appareil ; le cockpit ne garde ni le PIN ni la session.</p>
        ${st.etape === 'tel' ? `<label class="tfbm-champ">Téléphone<input id="tfbm-tel" type="tel" autocomplete="tel" placeholder="+32 4.. .. .. .." value="${esc(st.tel)}"></label>
          <label class="tfbm-champ">PIN<input id="tfbm-pin" type="password" inputmode="numeric" autocomplete="current-password" maxlength="8"></label>`
        : `<p style="font-size:13px">${esc(st.info || 'Un code a été envoyé par SMS.')}</p><label class="tfbm-champ">Code reçu par SMS<input id="tfbm-code" inputmode="numeric" autocomplete="one-time-code" maxlength="8"></label>
          <button type="button" class="linklike" data-tfbm="sms">renvoyer le code</button>`}
        ${st.err ? `<p class="error">${esc(st.err)}</p>` : ''}
        <div class="confirm__actions"><button type="button" class="btn btn--ghost" data-tfbm="fermer">Annuler</button>
          <button type="button" class="btn btn--primary" data-tfbm="ok"${st.busy ? ' disabled' : ''}>${st.etape === 'tel' ? 'Recevoir le code' : 'Se connecter'}</button></div></div>`;
      // Le focus : le PIN si le téléphone est déjà là (après un refus), sinon le premier champ.
      const i = (st.tel && fen.querySelector('#tfbm-pin')) || fen.querySelector('#tfbm-tel') || fen.querySelector('#tfbm-code'); if (i) { i.focus(); }
    };
    const fini = j => { ecrire(shop, j); fermer(); if (apres) { apres(); } };
    const ok = () => {
      if (st.busy) { return; }
      st.busy = true; st.err = null;
      if (st.etape === 'tel') { st.tel = (fen.querySelector('#tfbm-tel') || {}).value || ''; }
      const p = st.etape === 'tel'
        ? poster(api, '/dashboard/tfb/connexion', { shop, phone: st.tel, pin: (fen.querySelector('#tfbm-pin') || {}).value || '' })
          .then(d => { if (d.etape === 'ok') { return fini(d.jetons); } st.etape = 'sms'; st.challenge = d.challenge; st.info = d.texte; })
        : poster(api, '/dashboard/tfb/confirmer', { shop, challenge: st.challenge, code: (fen.querySelector('#tfbm-code') || {}).value || '' }).then(d => fini(d.jetons));
      p.catch(e => { st.err = e.message; }).finally(() => { st.busy = false; if (fen) { rendre(); } });
      rendre();
    };
    fen.addEventListener('click', ev => {
      if (ev.target === fen) { fermer(); return; }
      const a = ev.target.closest('[data-tfbm]'); if (!a) { return; }
      if (a.dataset.tfbm === 'fermer') { fermer(); }
      else if (a.dataset.tfbm === 'ok') { ok(); }
      else if (a.dataset.tfbm === 'sms') { poster(api, '/dashboard/tfb/sms', { shop, challenge: st.challenge }).then(() => { st.info = 'Un nouveau code a été envoyé.'; st.err = null; }).catch(e => { st.err = e.message; }).finally(rendre); }
    });
    fen.addEventListener('keydown', ev => { if (ev.key === 'Enter') { ev.preventDefault(); ok(); } if (ev.key === 'Escape') { fermer(); } });
    document.body.appendChild(fen);
    rendre();
  }

  window.DashTfb = {
    entetes(shop, api) { return jeton(String(shop), api).then(j => j ? { 'X-TFB-Jeton': j } : {}); },
    etat(shop) { const s = lire(String(shop)); return { connecte: !!s, expireSession: s ? s.expireSession || null : null }; },
    ouvrir(shop, api, apres) { ouvrir(String(shop), api, apres); },
    deconnecter(shop, api) {
      const s = lire(String(shop)); ecrire(String(shop), null);
      return s && s.renouvellement ? poster(api, '/dashboard/tfb/deconnexion', { shop: String(shop), renouvellement: s.renouvellement }).catch(() => null) : Promise.resolve(null);
    },
    /** Une réponse du serveur dit la session du magasin périmée : on l'oublie. */
    oublier(shop) { ecrire(String(shop), null); },
  };
})();
