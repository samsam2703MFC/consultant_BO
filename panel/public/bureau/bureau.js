/* Le questionnaire « bureau » d'un magasin (26/11/2026 ; serveur panel/src/bureaux_dev.php). Sam : « fais aussi un
 * questionnaire directement (1 par magasin) qui crée directement aussi un voucher ». Une page publique : la personne dit
 * qui elle est et où est son bureau ; à l'envoi, TF Buddy reçoit la personne, la société et le bon de bienvenue, et le
 * magasin voit le bureau « à valider » dans son dashboard (Clients › Bureaux). */
(function () {
  'use strict';
  const esc = v => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const el = document.getElementById('bq');
  const jeton = (new URLSearchParams(location.search).get('j') || '').toLowerCase();
  const api = '../api/cockpit/bureau-questionnaire/' + encodeURIComponent(jeton);
  const CADEAU = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 12v10H4V12M2 7h20v5H2zM12 22V7M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7zM12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/></svg>';
  const JOURS = [[1, 'Lun'], [2, 'Mar'], [3, 'Mer'], [4, 'Jeu'], [5, 'Ven']];
  let Q = null, envoi = false, erreur = null;

  const champ = (k, l, o) => { o = o || {}; return `<label class="bq-champ"><span>${esc(l)}${o.requis ? ' <em>*</em>' : ''}</span>${o.zone ? `<textarea name="${k}" rows="3" placeholder="${esc(o.aide || '')}"></textarea>`
    : `<input name="${k}" type="${o.type || 'text'}"${o.auto ? ` autocomplete="${o.auto}"` : ''}${o.mode ? ` inputmode="${o.mode}"` : ''} placeholder="${esc(o.aide || '')}"${o.requis ? ' required' : ''}>`}</label>`; };

  function formulaire() {
    el.innerHTML = `<img class="bq-logo" src="../assets/img/logo.png" alt="L'Atelier by">
      <p class="bq-kicker">${esc(Q.magasin)}</p><h1>${esc(Q.titre || 'Du frais au bureau')}</h1>${Q.message ? `<p class="bq-intro">${esc(Q.message)}</p>` : ''}
      ${Q.bon ? `<div class="bq-bon">${CADEAU}<div>Votre bon de bienvenue : <b>${esc(Q.bon)}</b>. Le code s’affiche dès l’envoi.</div></div>` : ''}
      <form id="bq-f" novalidate>
        <section class="bq-carte"><h2>Vous</h2><div class="bq-2">${champ('prenom', 'Prénom', { requis: true, auto: 'given-name' })}${champ('nom', 'Nom', { requis: true, auto: 'family-name' })}</div>
          ${champ('email', 'E-mail', { requis: true, type: 'email', auto: 'email' })}<div class="bq-2">${champ('tel', 'Téléphone', { requis: true, type: 'tel', auto: 'tel', aide: '+32 4.. .. .. ..' })}${champ('fonction', 'Fonction', { aide: 'Office manager, RH…' })}</div></section>
        <section class="bq-carte"><h2>Votre bureau</h2>${champ('societe', 'Société', { requis: true, auto: 'organization' })}${champ('tva', 'Numéro de TVA', { aide: 'BE0123456789 (facultatif)' })}
          ${champ('adresse', 'Adresse', { requis: true, auto: 'street-address', aide: 'Rue et numéro' })}<div class="bq-2">${champ('cp', 'Code postal', { auto: 'postal-code', mode: 'numeric' })}${champ('ville', 'Ville', { requis: true, auto: 'address-level2' })}</div>
          ${champ('personnes', 'Combien de personnes au bureau ?', { type: 'number', mode: 'numeric' })}
          <div class="bq-champ">Les jours où vous aimeriez être livrés</div><div class="bq-jours">${JOURS.map(([n, l]) => `<label><input type="checkbox" name="jours" value="${n}"><span>${l}</span></label>`).join('')}</div>
          ${champ('remarque', 'Un mot pour nous', { zone: true, aide: 'Accès, étage, habitudes du lunch…' })}</section>
        <div class="bq-piege" aria-hidden="true"><label>Site web <input name="site_web" tabindex="-1" autocomplete="off"></label></div>
        <label class="bq-accord"><input type="checkbox" name="accord"><span>J’accepte que ${esc(Q.magasin)} me recontacte pour livrer mon bureau. Mes coordonnées ne servent qu’à cela.</span></label>
        ${erreur ? `<p class="bq-err">${esc(erreur)}</p>` : ''}
        <button class="btn btn--primary bq-envoyer" type="submit"${envoi ? ' disabled' : ''}>${envoi ? 'Envoi…' : 'Envoyer'}</button>
      </form><p class="bq-pied">L’Atelier by · ${esc(Q.magasin)}</p>`;
    document.getElementById('bq-f').addEventListener('submit', envoyer);
  }

  function envoyer(ev) {
    ev.preventDefault();
    if (envoi) { return; }
    const f = ev.target, v = n => (f.elements[n] ? f.elements[n].value : '');
    const corps = { prenom: v('prenom'), nom: v('nom'), email: v('email'), tel: v('tel'), fonction: v('fonction'), societe: v('societe'), tva: v('tva'),
      adresse: v('adresse'), cp: v('cp'), ville: v('ville'), personnes: v('personnes'), remarque: v('remarque'), site_web: v('site_web'),
      jours: Array.from(f.querySelectorAll('input[name="jours"]:checked')).map(x => +x.value), accord: f.elements.accord.checked };
    envoi = true; erreur = null;
    const garde = Object.assign({}, corps);
    fetch(api, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(corps) })
      .then(r => r.json().catch(() => ({})).then(j => { if (!r.ok) { throw new Error(j.error || 'L’envoi n’a pas abouti : réessayez.'); } return j; }))
      .then(merci)
      .catch(e => { envoi = false; erreur = e.message; formulaire(); remplir(garde); });
    f.querySelector('button[type="submit"]').disabled = true; f.querySelector('button[type="submit"]').textContent = 'Envoi…';
  }
  /** Après une erreur, la saisie revient dans le formulaire. */
  function remplir(c) {
    const f = document.getElementById('bq-f'); if (!f) { return; }
    Object.keys(c).forEach(k => { const i = f.elements[k]; if (i && i.type !== 'checkbox' && typeof c[k] === 'string') { i.value = c[k]; } });
    (c.jours || []).forEach(n => { const i = f.querySelector('input[name="jours"][value="' + n + '"]'); if (i) { i.checked = true; } });
    if (c.accord) { f.elements.accord.checked = true; }
  }
  function merci(d) {
    const b = d.bon;
    el.innerHTML = `<img class="bq-logo" src="../assets/img/logo.png" alt="L'Atelier by"><div class="bq-merci"><h1>Merci !</h1>
      <p class="bq-intro">${esc(Q.magasin)} a bien reçu votre bureau et vous recontacte pour organiser la livraison.</p>
      ${b && b.code ? `<p class="bq-kicker">Votre code de bienvenue</p><div class="bq-code">${esc(b.code)}</div>${b.texte ? `<p class="bq-intro">${esc(b.texte)}</p>` : ''}<p class="bq-intro" style="font-size:12.5px">Notez-le ou faites une capture : il se donne en caisse ou sur le webshop.</p>` : ''}
      </div><p class="bq-pied">L’Atelier by · ${esc(Q.magasin)}</p>`;
    window.scrollTo(0, 0);
  }

  if (!/^[a-f0-9]{32}$/.test(jeton)) { el.innerHTML = '<p class="bq-attente">Ce lien n’est pas complet : demandez le lien du questionnaire à votre magasin.</p>'; return; }
  fetch(api, { headers: { Accept: 'application/json' } }).then(r => r.json().catch(() => ({})).then(j => { if (!r.ok) { throw new Error(j.error || 'questionnaire introuvable'); } return j; }))
    .then(q => { Q = q; document.title = (q.titre || 'Du frais au bureau') + ' — ' + q.magasin; formulaire(); })
    .catch(e => { el.innerHTML = `<p class="bq-attente">${esc(e.message.charAt(0).toUpperCase() + e.message.slice(1))}.</p>`; });
})();
