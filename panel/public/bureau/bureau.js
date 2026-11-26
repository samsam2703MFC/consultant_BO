/* Le formulaire « bureau » d'un magasin (26/11/2026, Sam : « faire le formulaire ») : six étapes — vous, votre compte,
   votre société, la livraison, votre équipe, le récapitulatif — puis le bon de bienvenue. Maquette :
   panel/docs/maquettes/bureau-formulaire/. Le serveur (panel/src/bureaux_dev.php) crée le compte webshop (sans mot de
   passe : TF Buddy envoie l'e-mail qui le fait choisir), la société et le bon ; le bureau, le magasin le valide.
   La saisie est gardée sur l'appareil (localStorage) jusqu'à l'envoi. */
(function () {
  'use strict';
  const esc = v => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const el = document.getElementById('bq');
  const p = new URLSearchParams(location.search);
  const jeton = (p.get('j') || '').toLowerCase();
  const magasin = (p.get('m') || '').replace(/\D/g, '');
  const api = '../api/cockpit/bureau-questionnaire/' + (jeton ? encodeURIComponent(jeton) : 'm/' + magasin);
  const CLE = 'bureau-form:' + (jeton || 'm' + magasin);
  const OK = '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>';
  const CADENAS = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>';
  const CADEAU = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 12v10H4V12M2 7h20v5H2zM12 22V7M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7zM12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/></svg>';
  const ETAPES = [['Vous', 'le contact du bureau'], ['Votre compte', 'pour commander au webshop'], ['Votre société', 'TVA, facturation'],
    ['La livraison', 'adresse, jours, heure'], ['Votre équipe', 'services, collègues'], ['Récapitulatif', 'et votre bon']];
  const JOURS = [[1, 'Lun'], [2, 'Mar'], [3, 'Mer'], [4, 'Jeu'], [5, 'Ven'], [6, 'Sam']];
  const SERVICES = ['Direction', 'Comptabilité', 'RH', 'Commercial', 'Production', 'Informatique', 'Accueil', 'Logistique'];
  const SECTEURS = [['', 'Choisir'], ['corporate', 'Entreprise, bureaux'], ['retail', 'Commerce'], ['horeca', 'Horeca'], ['event', 'Événementiel']];
  const PAYS = [['BE', 'Belgique'], ['FR', 'France'], ['NL', 'Pays-Bas'], ['LU', 'Luxembourg'], ['DE', 'Allemagne']];
  // Les champs obligatoires de chaque étape (le serveur vérifie de même : bdvNettoyer).
  const REQ = [['prenom', 'nom', 'tel'], ['email'], ['tva', 'societe', 'factRue', 'factCp', 'factVille'], ['adresse', 'cp', 'ville', 'jours', 'de', 'a'], [], ['accord']];

  let Q = null, etape = 0, loin = 0, err = {}, msg = null, envoi = false, fin = null, vies = null, ajout = { nom: '', email: '' };
  let f = { prenom: '', nom: '', fonction: '', tel: '', langue: 'fr', email: '', tva: '', pays: 'BE', societe: '', secteur: '', bureau: '', emailFact: '',
    factRue: '', factNum: '', factBoite: '', factCp: '', factVille: '', bonCommande: '', paiement: 'company', logo: '', adresse: '', cp: '', ville: '',
    batiment: '', etage: '', personnes: '', remarque: '', jours: [], de: '', a: '', remise: '', services: [], collegues: [], accord: false, recontact: true, nouvelles: false, site_web: '' };
  try { const s = JSON.parse(localStorage.getItem(CLE) || 'null'); if (s && s.f) { f = Object.assign(f, s.f); etape = Math.min(5, s.etape | 0); loin = Math.max(etape, s.loin | 0); } } catch (e) { /* rien de gardé */ }
  const garder = () => { try { localStorage.setItem(CLE, JSON.stringify({ f, etape, loin })); } catch (e) { /* stockage refusé : la saisie reste dans la page */ } };

  const vide = k => Array.isArray(f[k]) ? f[k].length === 0 : (k === 'accord' ? !f.accord : String(f[k] || '').trim() === '');
  function controler(i) {
    const e = {};
    REQ[i].forEach(k => { if (vide(k)) { e[k] = true; } });
    if (i === 0 && f.tel && f.tel.replace(/\D/g, '').length < 8) { e.tel = true; }
    if (i === 1 && f.email && !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(f.email)) { e.email = true; }
    if (i === 2 && f.tva && !/^[A-Z]{2}[0-9A-Z]{6,14}$/.test(f.tva.toUpperCase().replace(/[\s.\-]/g, ''))) { e.tva = true; }
    if (i === 2 && f.emailFact && !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(f.emailFact)) { e.emailFact = true; }
    if (i === 3 && f.de && f.a && f.a <= f.de) { e.a = true; }
    return e;
  }

  /* ── Les morceaux ── */
  const champ = (k, lib, o = {}) => `<label class="ch ${o.c || 'c3'}${err[k] ? ' err' : ''}"><span>${lib}${o.req ? ' <em>*</em>' : ''}</span>${
    o.choix ? `<select data-k="${k}">${o.choix.map(([v, t]) => `<option value="${esc(v)}"${String(f[k]) === String(v) ? ' selected' : ''}>${esc(t)}</option>`).join('')}</select>`
      : o.zone ? `<textarea data-k="${k}" rows="3" placeholder="${esc(o.ph || '')}">${esc(f[k])}</textarea>`
        : `${o.bouton ? '<span class="avec">' : ''}<input data-k="${k}" type="${o.type || 'text'}" value="${esc(f[k])}" placeholder="${esc(o.ph || '')}"${o.ac ? ` autocomplete="${o.ac}"` : ''}${o.im ? ` inputmode="${o.im}"` : ''}>${o.bouton || ''}${o.bouton ? '</span>' : ''}`}${o.aide ? `<small>${o.aide}</small>` : ''}</label>`;
  const bonInfo = () => {
    const b = Q && Q.bonDetail; if (!b) { return null; }
    const nom = String(b.nom || ''), apres = nom.includes(':') ? nom.split(':').slice(1).join(':').trim() : nom;
    // Un grand chiffre seulement quand le nom commence par lui (« 20 mini viennoiseries ») ; « −20 % … » reste entier.
    const m = apres.match(/^(\d{1,3})\s+(\D.*)$/);
    return { n: m ? m[1] : '', titre: m ? m[2] : apres, desc: b.description || '' };
  };
  const carteBon = (code) => { const b = bonInfo(); if (!b) { return ''; }
    return `<div class="bon"><div class="k">Votre bon de bienvenue</div>${b.n ? `<div class="q">${esc(b.n)}</div>` : ''}<div class="t">${esc(b.titre)}</div>${b.desc ? `<div class="d">${esc(b.desc)}</div>` : ''}
      <div class="perfo"></div><div class="code"><span>Votre code</span><b>${code ? esc(code) : '• • • • • •'}</b></div></div>`; };
  const resume = () => {
    const l = [['Contact', [f.prenom, f.nom].filter(Boolean).join(' ') + (f.fonction ? ' · ' + f.fonction : ''), 0], ['Compte', f.email, 1],
      ['Société', f.societe ? f.societe + (f.tva ? ' · ' + f.tva.toUpperCase().replace(/[\s.\-]/g, '') : '') : '', 2],
      ['Livraison', f.adresse ? [f.adresse + (f.ville ? ', ' + f.ville : ''), f.jours.length ? JOURS.filter(j => f.jours.includes(j[0])).map(j => j[1].toLowerCase()).join(', ') : '', f.de && f.a ? f.de + '–' + f.a : ''].filter(Boolean).join(' · ') : '', 3],
      ['Équipe', [f.services.length ? f.services.join(', ') : '', f.collegues.length ? f.collegues.length + ' collègue' + (f.collegues.length > 1 ? 's' : '') + ' invité' + (f.collegues.length > 1 ? 's' : '') : ''].filter(Boolean).join(' · '), 4]];
    return `<div class="recap"><h4>Votre bureau</h4>${l.map(([a, b, i]) => `<div class="l${b ? '' : ' vide'}"><span>${a}</span><b>${esc(b || 'à venir')}</b>${etape === 5 && b ? `<a data-aller="${i}">modifier</a>` : ''}</div>`).join('')}</div>`;
  };
  const sur = `<div class="sur">${CADENAS}<span>Aucun mot de passe ici : notre webshop vous envoie un e-mail pour choisir le vôtre. Vos données servent à livrer votre bureau, jamais revendues.</span></div>`;

  function corpsEtape() {
    const aide = t => `<p class="aide">${t}</p>`;
    if (etape === 0) {
      return `<h2>Vous</h2>${aide('Vous êtes le contact du bureau : le magasin vous appelle pour la première livraison.')}
        <div class="g">${champ('prenom', 'Prénom', { req: true, ac: 'given-name' })}${champ('nom', 'Nom', { req: true, ac: 'family-name' })}
        ${champ('fonction', 'Fonction', { ph: 'Office manager, assistante de direction…', ac: 'organization-title' })}${champ('tel', 'Téléphone', { req: true, type: 'tel', ac: 'tel', ph: '+32 470 12 34 56', aide: err.tel ? 'Un numéro d’au moins 8 chiffres.' : '' })}</div>
        <div class="ch c6"><span>Langue</span></div><div class="seg">${[['fr', 'Français'], ['nl', 'Nederlands'], ['en', 'English']].map(([v, t]) => `<button type="button" data-langue="${v}" class="${f.langue === v ? 'on' : ''}">${t}</button>`).join('')}</div>`;
    }
    if (etape === 1) {
      return `<h2>Votre compte webshop</h2>${aide('Avec lui, vous et vos collègues commandez au nom du bureau. Vous avez déjà un compte chez nous ? Donnez le même e-mail : nous vous relions.')}
        <div class="g">${champ('email', 'E-mail', { req: true, c: 'c6', type: 'email', ac: 'email', im: 'email', aide: err.email ? 'Un e-mail valide, s’il vous plaît.' : 'Votre identifiant au webshop.' })}</div>
        <div class="vies ok" style="margin-bottom:14px"><span class="ic">${OK}</span><span><b>Pas de mot de passe à inventer ici.</b><br>Dès l’envoi, notre webshop vous écrit pour que vous choisissiez le vôtre (le lien vaut une heure ; ensuite, « mot de passe oublié » au webshop).</span></div>${sur}`;
    }
    if (etape === 2) {
      const v = vies;
      const bv = v ? (v.valide ? `<div class="vies ok"><span class="ic">${OK}</span><span><b>${esc(v.societe || 'Société')}</b> · TVA valide${v.rue || v.ville ? ' · ' + esc([v.rue, v.numero].filter(Boolean).join(' ') + (v.ville ? ', ' + [v.cp, v.ville].filter(Boolean).join(' ') : '')) : ''}<br>Le nom et le siège sont repris ci-dessous ; modifiez-les si besoin.</span></div>`
        : v.verifiee ? '<div class="vies ko">Ce numéro n’est pas connu du registre européen (VIES). Vérifiez-le ; s’il est juste, continuez : le magasin le contrôlera.</div>'
          : '<div class="vies ko">Le registre européen ne répond pas : remplissez les champs vous-même.</div>') : '';
      return `<h2>Votre société</h2>${aide('Le numéro de TVA suffit : nous lisons le nom et le siège dans le registre européen (VIES). Le bureau, ses factures et ses remises sont ceux de cette société.')}
        <div class="g">${champ('tva', 'Numéro de TVA', { req: true, ph: 'BE 0123.456.789', bouton: '<button type="button" class="btn btn--sm" data-act="vies">Vérifier</button>', aide: err.tva ? 'La forme : BE0123456789.' : '' })}${champ('pays', 'Pays', { req: true, choix: PAYS })}</div>${bv}
        <div class="g">${champ('societe', 'Raison sociale', { req: true, c: 'c4', ac: 'organization' })}${champ('secteur', 'Secteur', { c: 'c2', choix: SECTEURS })}
          ${champ('bureau', 'Nom du bureau', { ph: f.societe || 'le nom de la société', aide: 'Celui que vos collègues verront au webshop.' })}${champ('emailFact', 'E-mail de facturation', { type: 'email', ph: f.email, aide: err.emailFact ? 'Un e-mail valide, ou rien.' : 'Les factures du bureau y partent.' })}</div>
        <h3>Adresse de facturation</h3>
        <div class="g">${champ('factRue', 'Rue', { req: true })}${champ('factNum', 'Numéro', { c: 'c1' })}${champ('factCp', 'Code postal', { req: true, c: 'c1', im: 'numeric' })}${champ('factBoite', 'Boîte', { c: 'c1' })}
          ${champ('factVille', 'Localité', { req: true })}${champ('bonCommande', 'Bon de commande', { ph: 'si votre comptabilité en demande un' })}</div>
        <h3>Qui paie les commandes ?</h3>
        <div class="choix deux">${[['company', 'La société', 'Une facture au nom de ' + (f.societe || 'la société')], ['person', 'Chacun paie', 'Chaque collègue paie en ligne sa commande']].map(([v, t, s]) => `<button type="button" data-paiement="${v}" class="${f.paiement === v ? 'on' : ''}">${t}<small>${esc(s)}</small></button>`).join('')}</div>
        <h3>Votre logo <span>· facultatif</span></h3>
        <div class="logo-z">${f.logo ? `<img src="${esc(f.logo)}" alt="Logo">` : '<i></i>'}<span>${f.logo ? 'Votre logo' : 'Il s’affiche sur le webshop de vos collègues.'}<br><span style="color:var(--faint)">PNG, JPG ou WebP.</span></span>
          <label class="btn btn--sm">${f.logo ? 'Changer' : 'Ajouter'}<input type="file" accept="image/png,image/jpeg,image/webp" data-logo></label>${f.logo ? '<button type="button" class="btn btn--sm btn--ghost" data-act="sans-logo">Retirer</button>' : ''}</div>`;
    }
    if (etape === 3) {
      return `<h2>Où et quand livrer ?</h2>${aide('L’adresse où nos livreurs déposent la commande. Elle peut différer du siège. Dites-nous comment entrer : nous livrons vite et sans appeler.')}
        ${f.factRue && !f.adresse ? '<p style="margin:-6px 0 14px"><button type="button" class="btn btn--sm" data-act="meme-adresse">Même adresse que la facturation</button></p>' : ''}
        <div class="g">${champ('adresse', 'Rue et numéro', { req: true, c: 'c6', ac: 'street-address' })}${champ('cp', 'Code postal', { req: true, c: 'c2', im: 'numeric', ac: 'postal-code' })}${champ('ville', 'Localité', { req: true, c: 'c4', ac: 'address-level2' })}
          ${champ('batiment', 'Bâtiment', { c: 'c2' })}${champ('etage', 'Étage', { c: 'c2' })}${champ('personnes', 'Personnes sur place', { c: 'c2', type: 'number', im: 'numeric' })}
          ${champ('remarque', 'Consignes d’accès', { c: 'c6', zone: true, ph: 'Accueil, code du portail, parking…' })}</div>
        <h3>Les jours de livraison <em style="font-style:normal;color:var(--accent)">*</em></h3>
        <div class="jours${err.jours ? ' err' : ''}">${JOURS.map(([n, t]) => `<button type="button" data-jour="${n}" class="${f.jours.includes(n) ? 'on' : ''}">${t}</button>`).join('')}</div>
        <div class="g">${champ('de', 'Livré entre', { req: true, c: 'c3', type: 'time' })}${champ('a', 'et', { req: true, c: 'c3', type: 'time', aide: err.a && f.de && f.a ? 'Après l’heure de début.' : '' })}</div>
        <h3>À la livraison</h3>
        <div class="choix">${[['qr', 'Scan du QR code', 'À l’accueil, en dix secondes'], ['signature', 'Signature', 'Sur le téléphone du livreur'], ['dropoff', 'Dépôt', 'Sans contact, photo à l’appui']].map(([v, t, s]) => `<button type="button" data-remise="${v}" class="${f.remise === v ? 'on' : ''}">${t}<small>${s}</small></button>`).join('')}</div>`;
    }
    if (etape === 4) {
      return `<h2>Votre équipe</h2>${aide('Facultatif, mais utile : vos services, et les collègues qui commanderont. Ils reçoivent leur invitation au webshop quand le magasin valide le bureau.')}
        <h3>Les services</h3><div class="puces">${SERVICES.concat(f.services.filter(s => !SERVICES.includes(s))).map(s => `<button type="button" data-service="${esc(s)}" class="${f.services.includes(s) ? 'on' : ''}">${esc(s)}</button>`).join('')}</div>
        <h3>Les collègues à inviter <span>· ${f.collegues.length} sur 30</span></h3>
        <div class="coll">${f.collegues.map((c, i) => `<div><span>${esc(c.nom || c.email)}</span><small>${esc(c.email)}</small><button type="button" data-retirer="${i}">Retirer</button></div>`).join('')}</div>
        <div class="g"><label class="ch c2"><span>Nom</span><input data-ajout="nom" value="${esc(ajout.nom)}" placeholder="Prénom Nom"></label><label class="ch c3"><span>E-mail</span><input data-ajout="email" type="email" value="${esc(ajout.email)}" placeholder="collegue@societe.be"></label>
          <div class="ch c1"><span>&nbsp;</span><button type="button" class="btn" data-act="ajouter" style="min-height:44px">Ajouter</button></div></div>`;
    }
    return `<h2>Récapitulatif</h2>${aide('Vérifiez, puis envoyez : votre compte et votre bon se créent tout de suite, le magasin valide ensuite votre bureau.')}
      <div style="margin:0 0 16px">${resume()}</div>
      <label class="coche${err.accord ? ' err' : ''}"><input type="checkbox" data-c="accord"${f.accord ? ' checked' : ''}><span>J’accepte les conditions d’utilisation du webshop et l’usage de mes données pour livrer ce bureau. <em style="font-style:normal;color:var(--accent)">*</em></span></label>
      <label class="coche"><input type="checkbox" data-c="recontact"${f.recontact ? ' checked' : ''}><span>Le magasin peut m’appeler pour organiser la première livraison.</span></label>
      <label class="coche"><input type="checkbox" data-c="nouvelles"${f.nouvelles ? ' checked' : ''}><span>Je veux recevoir les nouveautés et les offres du magasin.</span></label>
      <div class="piege" aria-hidden="true"><label>Site web <input data-k="site_web" tabindex="-1" autocomplete="off"></label></div>`;
  }

  function rendre() {
    if (fin) { return rendreFin(); }
    const pct = Math.round((etape + 1) / 6 * 100);
    const b = bonInfo();
    el.innerHTML = `<div class="bf-page">
      <div class="bf-titre"><div><p class="k">${esc(Q.titre || 'Du frais au bureau')}</p><h1>Inscrivez votre bureau chez L’Atelier by</h1><p>${esc(Q.message || 'En six étapes : votre compte webshop, votre société et le lieu de livraison. Le magasin valide votre bureau, et vos collègues commandent ensuite au même endroit.')}</p></div>
        <div class="prog">Étape ${etape + 1} sur 6 · ${esc(ETAPES[etape][0])}<div class="barre"><i style="width:${pct}%"></i></div></div></div>
      <ol class="bf-etapes">${ETAPES.map(([t, s], i) => `<li data-aller="${i}" class="${i < etape ? 'fait' : i === etape ? 'ici' : ''}${i <= loin && i !== etape ? ' ouvert' : ''}"><span class="n">${i < etape ? OK : i + 1}</span><span class="t">${t}<small>${s}</small></span></li>`).join('')}</ol>
      <div>${b && etape < 5 ? `<div class="bf-mbon"><span class="q">${b.n ? esc(b.n) : CADEAU}</span><span><b>Un bon vous attend</b>${esc([b.n, b.titre].filter(Boolean).join(' '))}${b.desc ? ' · ' + esc(b.desc) : ''}, à la fin du formulaire.</span></div>` : ''}
        <section class="bf-carte">${msg ? `<p class="bf-err" role="alert">${esc(msg)}</p>` : ''}${corpsEtape()}
          <div class="pied">${etape > 0 ? '<button type="button" class="btn btn--ghost" data-act="retour">Retour</button>' : ''}<span class="muet">Gardé sur cet appareil jusqu’à l’envoi</span>
            <button type="button" class="btn btn--primary" data-act="${etape < 5 ? 'suite' : 'envoyer'}"${envoi ? ' disabled' : ''}>${etape < 5 ? 'Continuer : ' + ETAPES[etape + 1][0].toLowerCase() : envoi ? 'Envoi…' : 'Envoyer mon inscription'}</button></div></section></div>
      <aside class="bf-cote" id="bf-cote">${carteBon()}${resume()}${sur}</aside></div>`;
  }

  function rendreFin() {
    const r = fin, code = r.bon && r.bon.code, b = bonInfo();
    let qr = '';
    if (code && window.qrcode) { try { const q = window.qrcode(0, 'M'); q.addData(code); q.make(); qr = q.createSvgTag({ margin: 0, scalable: true }); } catch (e) { qr = ''; } }
    const etapes = [
      r.compte === 'cree' ? [true, 'Votre compte webshop est créé', esc(f.email) + ' · un e-mail de notre webshop arrive pour choisir votre mot de passe (lien valable une heure).']
        : r.compte === 'existant' ? [true, 'Vous aviez déjà un compte webshop', 'Utilisez-le avec ' + esc(f.email) + ' ; mot de passe oublié ? Le webshop vous en renvoie un.']
          : [false, 'Votre compte webshop', 'Le magasin le finalise avec vous.'],
      code ? [true, 'Votre bon de bienvenue', 'Un code à votre nom, valable une fois.'] : null,
      r.rejoint ? [true, 'Vous avez rejoint le bureau « ' + esc(r.rejoint) + ' »', 'Il existait déjà : vous pouvez commander à son nom.']
        : [false, 'Le magasin valide votre bureau', 'D’habitude dans la journée. Il vous appelle pour la première livraison.'],
      f.collegues.length && !r.rejoint ? [false, f.collegues.length > 1 ? 'Vos ' + f.collegues.length + ' collègues reçoivent leur invitation' : 'Votre collègue reçoit son invitation', 'À la validation, pour commander au nom de ' + esc(f.bureau || f.societe) + '.'] : null,
    ].filter(Boolean);
    el.innerHTML = `<div class="merci"><div><p class="ok">${OK} C’est envoyé</p><h1>Merci ${esc(f.prenom)}, ${r.rejoint ? 'vous êtes dans votre bureau.' : 'votre bureau est inscrit.'}</h1>
      <p class="intro">${r.rejoint ? 'Vous faites partie du bureau de votre société au webshop de ' + esc(Q.magasin) + '.' : 'Le magasin de ' + esc(Q.magasin) + ' valide ' + esc(f.bureau || f.societe) + ' ; vous recevez alors un e-mail' + (f.collegues.length ? ', et vos collègues aussi' : '') + '.'}</p>
      <ol class="suite">${etapes.map(([fait, t, s], i) => `<li class="${fait ? 'fait' : ''}"><span class="n">${fait ? OK : i + 1}</span><span><b>${t}</b><small>${s}</small></span></li>`).join('')}</ol>
      ${code ? '<div style="display:flex;gap:10px;flex-wrap:wrap"><button type="button" class="btn btn--primary" data-act="copier-code">Copier le code</button><button type="button" class="btn" data-act="imprimer">Imprimer le bon</button></div>' : ''}</div>
      <div>${code && b ? `<div class="bon"><div class="k">Votre bon de bienvenue</div>${b.n ? `<div class="q">${esc(b.n)}</div>` : ''}<div class="t">${esc(b.titre)}</div>${b.desc ? `<div class="d">${esc(b.desc)}. Montrez ce code en caisse.</div>` : ''}<div class="perfo"></div>
        <div class="qr">${qr ? `<div class="bl">${qr}</div>` : ''}<div class="cd">Votre code<b>${esc(code)}</b>Une fois · au nom de ${esc([f.prenom, f.nom].join(' '))}</div></div></div>` : ''}</div></div>`;
  }

  /* ── Les gestes ── */
  function aller(i) { err = {}; msg = null; etape = i; loin = Math.max(loin, i); garder(); rendre(); window.scrollTo({ top: 0, behavior: 'smooth' }); }
  function suite() {
    err = controler(etape);
    if (Object.keys(err).length) { msg = 'Il manque une information, en rouge ci-dessous.'; rendre(); const x = el.querySelector('.err input, .err select'); if (x) { x.focus(); } return; }
    if (etape === 2 && !f.bureau) { f.bureau = f.societe; }
    aller(etape + 1);
  }
  function verifierTva() {
    const t = f.tva.toUpperCase().replace(/[\s.\-]/g, '');
    if (!/^[A-Z]{2}[0-9A-Z]{6,14}$/.test(t)) { err = { tva: true }; rendre(); return; }
    fetch(api + '/tva', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ tva: t }) })
      .then(r => r.json().catch(() => ({}))).then(v => {
        vies = v && v.tva ? v : { verifiee: false };
        if (v && v.valide) {
          if (!f.societe && v.societe) { f.societe = v.societe; }
          if (!f.factRue && v.rue) { f.factRue = v.rue; f.factNum = f.factNum || v.numero || ''; }
          if (!f.factCp && v.cp) { f.factCp = v.cp; }
          if (!f.factVille && v.ville) { f.factVille = v.ville; }
          f.tva = t; err = {}; garder();
        }
        rendre();
      }).catch(() => { vies = { verifiee: false }; rendre(); });
  }
  function lireLogo(fichier) {
    if (!fichier || !/^image\/(png|jpeg|webp)$/.test(fichier.type) || fichier.size > 8 * 1024 * 1024) { msg = 'Le logo : une image PNG, JPG ou WebP de 8 Mo au plus.'; rendre(); return; }
    createImageBitmap(fichier).then(bm => {
      const k = Math.min(1, 400 / Math.max(bm.width, bm.height)), c = document.createElement('canvas');
      c.width = Math.max(1, Math.round(bm.width * k)); c.height = Math.max(1, Math.round(bm.height * k));
      c.getContext('2d').drawImage(bm, 0, 0, c.width, c.height);
      let u = c.toDataURL('image/webp', 0.85); if (!u.startsWith('data:image/webp')) { u = c.toDataURL('image/png'); }
      if (u.length > 300000) { msg = 'Le logo est trop lourd, même réduit : essayez une image plus simple.'; } else { f.logo = u; msg = null; garder(); }
      rendre();
    }).catch(() => { msg = 'Ce logo ne se lit pas.'; rendre(); });
  }
  function envoyer() {
    err = controler(5);
    for (let i = 0; i < 5; i++) { if (Object.keys(controler(i)).length) { msg = 'L’étape « ' + ETAPES[i][0] + ' » est incomplète.'; aller(i); err = controler(i); rendre(); return; } }
    if (Object.keys(err).length) { msg = 'Cochez votre accord pour envoyer.'; rendre(); return; }
    envoi = true; msg = null; rendre();
    const corps = Object.assign({}, f, { tva: f.tva.toUpperCase().replace(/[\s.\-]/g, ''), bureau: f.bureau || f.societe });
    fetch(api, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(corps) })
      .then(r => r.json().catch(() => ({})).then(j => { if (!r.ok || !j.ok) { throw new Error(j.error || 'L’envoi n’a pas abouti : réessayez dans un instant.'); } return j; }))
      .then(j => { fin = j; try { localStorage.removeItem(CLE); } catch (e) { /* rien */ } envoi = false; rendre(); window.scrollTo(0, 0); })
      .catch(e => { envoi = false; msg = e.message; rendre(); });
  }

  el.addEventListener('input', e => {
    const t = e.target;
    if (t.dataset.k) { f[t.dataset.k] = t.value; if (err[t.dataset.k]) { delete err[t.dataset.k]; t.closest('.ch') && t.closest('.ch').classList.remove('err'); } garder();
      const c = document.getElementById('bf-cote'); if (c) { c.innerHTML = carteBon() + resume() + sur; } }
    if (t.dataset.ajout) { ajout[t.dataset.ajout] = t.value; }
  });
  el.addEventListener('change', e => {
    const t = e.target;
    if (t.dataset.c) { f[t.dataset.c] = t.checked; if (t.dataset.c === 'accord') { delete err.accord; } garder(); }
    if (t.dataset.logo !== undefined && t.files && t.files[0]) { lireLogo(t.files[0]); }
    if (t.dataset.k === 'tva' && etape === 2 && /^[A-Z]{2}[0-9A-Z]{6,14}$/.test(f.tva.toUpperCase().replace(/[\s.\-]/g, '')) && (!vies || vies.tva !== f.tva.toUpperCase().replace(/[\s.\-]/g, ''))) { verifierTva(); }
  });
  el.addEventListener('keydown', e => {
    if (e.key === 'Enter' && e.target.tagName === 'INPUT' && e.target.type !== 'checkbox') { e.preventDefault(); if (e.target.dataset.ajout) { el.querySelector('[data-act="ajouter"]').click(); } else if (etape < 5) { suite(); } }
  });
  el.addEventListener('click', e => {
    const b = e.target.closest('[data-act],[data-aller],[data-langue],[data-paiement],[data-remise],[data-jour],[data-service],[data-retirer]');
    if (!b) { return; }
    const d = b.dataset;
    if (d.aller !== undefined) { const i = +d.aller; if (i <= loin && i !== etape) { aller(i); } return; }
    if (d.langue) { f.langue = d.langue; }
    if (d.paiement) { f.paiement = d.paiement; }
    if (d.remise) { f.remise = f.remise === d.remise ? '' : d.remise; }
    if (d.jour) { const n = +d.jour; f.jours = f.jours.includes(n) ? f.jours.filter(x => x !== n) : f.jours.concat(n).sort(); delete err.jours; }
    if (d.service) { f.services = f.services.includes(d.service) ? f.services.filter(x => x !== d.service) : f.services.concat(d.service); }
    if (d.retirer !== undefined) { f.collegues.splice(+d.retirer, 1); }
    switch (d.act) {
      case 'suite': return suite();
      case 'retour': return aller(Math.max(0, etape - 1));
      case 'envoyer': return envoyer();
      case 'vies': return verifierTva();
      case 'sans-logo': f.logo = ''; break;
      case 'meme-adresse': f.adresse = [f.factRue, f.factNum].filter(Boolean).join(' ') + (f.factBoite ? ' bte ' + f.factBoite : ''); f.cp = f.factCp; f.ville = f.factVille; break;
      case 'ajouter': {
        const m = ajout.email.trim().toLowerCase();
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(m)) { msg = 'L’e-mail du collègue n’est pas valide.'; break; }
        if (f.collegues.length >= 30) { msg = 'Trente collègues au plus ici ; les autres, le magasin les ajoute.'; break; }
        if (!f.collegues.some(c => c.email === m)) { f.collegues.push({ nom: ajout.nom.trim(), email: m }); }
        ajout = { nom: '', email: '' }; msg = null; break;
      }
      case 'copier-code': { const c = fin && fin.bon && fin.bon.code; if (c && navigator.clipboard) { navigator.clipboard.writeText(c).then(() => { b.textContent = 'Code copié'; }); } return; }
      case 'imprimer': window.print(); return;
    }
    garder(); rendre();
  });

  if (!/^[a-f0-9]{32}$/.test(jeton) && !/^[1-9]\d{0,5}$/.test(magasin)) { el.innerHTML = '<p class="bq-attente">Ce lien n’est pas complet : demandez le lien du formulaire à votre magasin.</p>'; return; }
  fetch(api, { headers: { Accept: 'application/json' } }).then(r => r.json().catch(() => ({})).then(j => { if (!r.ok) { throw new Error(j.error || 'formulaire introuvable'); } return j; }))
    .then(j => { Q = j; document.getElementById('bf-mag').innerHTML = '<b>' + esc(j.magasin) + '</b>Du frais au bureau'; document.title = 'Inscrire mon bureau — ' + j.magasin; rendre(); })
    .catch(e => { el.innerHTML = '<p class="bq-attente">' + esc(e.message.charAt(0).toUpperCase() + e.message.slice(1)) + '.</p>'; });
})();
