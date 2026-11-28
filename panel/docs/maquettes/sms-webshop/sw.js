/* Maquettes « Clients › SMS » (28/11/2026) : annoncer le webshop aux clients particuliers d'un magasin par SMS, avec
 * leur bon de bienvenue (50 % sur une tarte). Magasin 4, Halle. Les comptes de la vue « envoi » sont ceux lus dans
 * TF Buddy le 28/11/2026 (/shops/4/clients), arrondis ; tout le reste (noms, codes, suivi) est fictif.
 *   ?v=envoi · ?v=parcours · ?v=suivi */
(() => {
  const V = (new URLSearchParams(location.search).get('v') || 'envoi').toLowerCase();
  const esc = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const P = {
    tdb: 'M3 3h7v7H3zM14 3h7v7h-7zM14 14h7v7h-7zM3 14h7v7H3z', commandes: 'M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2 2 2 0 0 0-2-2h-2a2 2 0 0 0-2 2M9 12h6M9 16h4',
    controle: 'M9 11l3 3L22 4M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11', equipe: 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75',
    cal: 'M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z', perf: 'M3 17l6-6 4 4 8-8M15 7h6v6', fonds: 'M3 7h18v12H3zM3 7l3-4h12l3 4M16 13h.5',
    loupe: 'M3 3v18h18M7 15l4-5 3 3 5-7', bundles: 'M21 8l-9-5-9 5 9 5 9-5zM3 8v8l9 5 9-5V8M12 13v8', offres: 'M19 5L5 19M7 7a1.5 1.5 0 1 0 .01 0M17 17a1.5 1.5 0 1 0 .01 0',
    offre: 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M8 13h8M8 17h5', clients: 'M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75',
    analyse: 'M4 20V10M10 20V4M16 20v-7M22 20H2', pub: 'M4 4h16v12H4zM2 20h20M9 8l4 2-4 2z', etoile: 'M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z',
    mail: 'M3 5h18v14H3zM3 6l9 7 9-7', ia: 'M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2zM8 9h8M8 13h5', alerte: 'M12 9v4M12 17h.01M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z',
    ok: 'M20 6L9 17l-5-5', info: 'M12 16v-4M12 8h.01M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20z', sms: 'M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z', envoi: 'M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z',
  };
  const ic = (k, cls = 'ic') => `<svg class="${cls}" viewBox="0 0 24 24" aria-hidden="true"><path d="${P[k]}"/></svg>`;
  const pin = (n, abs) => `<span class="pin${abs ? ' abs' : ''}">${n}</span>`;
  const legende = (titre, L) => `<div class="legende"><h3>${titre}</h3><ol>${L.map((x, i) => `<li>${pin(i + 1)}<span>${x}</span></li>`).join('')}</ol></div>`;
  const alerte = (txt, cls = '', i = 'alerte') => `<div class="alerte ${cls}">${ic(i)}<div>${txt}</div></div>`;

  function rail() {
    const it = (i, l, x = '') => `<div class="sidebar__item${x === 'on' ? ' is-active' : ''}${x === 'ia' ? ' is-ia' : ''}">${ic(i, 'ico')}<span>${l}</span></div>`;
    return `<nav class="sidebar"><div class="sidebar__head"><img class="sidebar__logo" src="../../../public/tablette/img/logo.png" alt=""><div class="sidebar__kicker">Dashboard magasin</div></div>
      <div class="rcarte"><div class="k">Objectif du jour<em>en direct</em></div><div class="v">61 %<small>de 1 180 €</small></div><div class="barre"><i style="width:61%"></i></div><div class="s">720 € · trait : projection 1 240 €</div></div>
      <div class="sidebar__groups"><div class="sidebar__group">${it('mail', 'Boîte mail')}${it('ia', 'Assistant IA', 'ia')}</div>
      <div class="sidebar__group"><div class="sidebar__group-label">La journée</div>${it('tdb', 'Opérationnel')}${it('commandes', 'Commandes')}${it('controle', 'Tâches')}${it('equipe', 'Mon équipe')}</div>
      <div class="sidebar__group"><div class="sidebar__group-label">Les chiffres</div>${it('cal', 'Semaine')}${it('cal', 'Mois')}${it('perf', 'Année')}${it('fonds', 'Trésorerie')}</div>
      <div class="sidebar__group"><div class="sidebar__group-label">Vendre</div>${it('loupe', 'Analyse')}${it('bundles', 'Produits')}${it('offres', 'Promotions')}${it('clients', 'Clients', 'on')}${it('offre', 'Offres')}${it('analyse', 'Concurrence')}</div>
      <div class="sidebar__group"><div class="sidebar__group-label">Marketing</div>${it('pub', 'Mon marketing')}${it('etoile', 'Fidélité')}</div></div></nav>`;
  }
  function tete(action) {
    const o = (k, l, n) => `<span class="${k === 'sms' ? 'is-on' : ''}">${l}${n != null ? ` <em>${n}</em>` : ''}</span>`;
    return `<div class="tete" style="margin-bottom:10px"><div><p class="tete__kicker">Atelier by - Halle</p><h1>Clients</h1></div>${action || ''}</div>
      <div class="onglets rel">${pin(1, true)}${o('b2c', 'Particuliers', 680)}${o('b2b', 'Entreprises B2B', 0)}${o('bur', 'Bureaux')}${o('an', 'Analyse B2B')}${o('sms', 'SMS')}<span class="droite">Le bon : Vendre › Promotions</span></div>`;
  }

  const SMS_FR = `L'Atelier By Halle : notre webshop est en ligne ! Activez votre compte : <span class="var">atelierby.be/w/7KQ2MP</span> Bon de bienvenue : -50% sur une tarte. STOP : atelierby.be/stop`;
  const SMS_NL = `L'Atelier By Halle: onze webshop is online! Activeer je account: <span class="var">atelierby.be/w/7KQ2MP</span> Je welkomstbon: -50% op een taart. STOP atelierby.be/stop`;
  const bulle = (txt, h = '10:00') => `<div class="bulle">${txt.replace(/<span class="var">([^<]+)<\/span>/g, '<a>$1</a>').replace('atelierby.be/stop', '<a>atelierby.be/stop</a>')}</div><div class="heure">Aujourd’hui ${h}</div>`;
  const tel = (contenu, qui = 'AtelierBy') => `<div class="smtel"><div class="smtel__ecran"><div class="smtel__barre"><span>10:02</span><span>●●● 4G ▮</span></div>
    ${qui ? `<div class="smtel__qui"><div class="av">AB</div>${qui}</div>` : ''}${contenu}</div></div>`;

  /* ── 1. Préparer et envoyer ─────────────────────────────────────────────────────────────────────────────────── */
  function vueEnvoi() {
    const qui = `<section class="card bloc rel">${pin(2, true)}<div class="bloc__t"><h2>1 · Qui reçoit le SMS</h2><span class="k">lu dans TF Buddy · 28/11 09:40</span></div><div class="bloc__c">
      <div class="chiffres"><div><div class="v">680</div><div class="k">particuliers<br>du magasin</div></div><div><div class="v">612</div><div class="k">avec un GSM<br>belge lisible</div></div>
        <div class="on"><div class="v">≈ 590</div><div class="k">numéros différents<br>(doublons retirés)</div></div><div><div class="v">≈ 80</div><div class="k">déjà connectés<br>au webshop</div></div></div>
      <div class="choix"><div class="opt on"><i></i><div><b>Tous les GSM du magasin · ≈ 590</b><span class="reco">conseillé</span><br>Un seul texte : la page du lien s’adapte (déjà un compte : « Se connecter » ; pas encore : « Créer mon mot de passe »).</div></div>
        <div class="opt"><i></i><div><b>Seulement ceux qui ne se sont jamais connectés · ≈ 510</b><br>Pour un deuxième envoi, ou si l’on veut un autre texte pour les ≈ 80 qui commandent déjà en ligne.</div></div></div>
      <div class="exclus"><span>≈ 70 sans GSM lisible (fixe, « 999 »…)</span><span>≈ 20 doublons</span><span>0 désinscrit (STOP)</span><span>déjà reçu cette campagne : jamais deux fois</span></div>
      ${alerte('<b>L’accord du client.</b> TF Buddy n’a pas de case « accepte les SMS ». La loi belge admet un SMS publicitaire à un client qui a déjà acheté, pour des produits semblables, à condition que <b>chaque SMS</b> donne un moyen gratuit de ne plus en recevoir : le lien STOP est donc dans le texte, et un STOP ne reçoit plus rien, de ce magasin ni des autres.')}
    </div></section>`;

    const msg = `<section class="card bloc rel">${pin(3, true)}<div class="bloc__t"><h2>2 · Le message</h2><span class="k">Halle : 45 FR · 33 NL · 602 langue inconnue</span></div><div class="bloc__c">
      <div style="display:flex;gap:14px;align-items:center;margin-bottom:12px;flex-wrap:wrap"><span class="muted" style="font-size:12px">Langue</span><span class="seg"><span class="is-on">Celle du client, sinon NL</span><span>Celle du client, sinon FR</span><span>Les deux (2 SMS)</span></span></div>
      <div class="lang"><div><h4>Français <span>158 / 160 · 1 SMS</span></h4><div class="texte">${SMS_FR}</div></div>
        <div><h4>Nederlands <span>144 / 160 · 1 SMS</span></h4><div class="texte">${SMS_NL}</div></div></div>
      <div class="compteur"><span>Expéditeur : <b>AtelierBy</b> (SMSAPI, le fournisseur déjà réglé dans le cockpit)</span><span class="ok">${ic('ok')} sans caractère spécial : 160 caractères par SMS</span></div>
      <div style="font-size:12px;color:var(--muted);margin-bottom:6px">Le lien</div>
      <div class="choix" style="margin-top:0"><div class="opt on"><i></i><div><b>Un lien court personnel</b> <span class="var" style="background:var(--prize-soft);color:var(--prize-ink);border-radius:4px;padding:0 4px">atelierby.be/w/7KQ2MP</span><span class="reco">conseillé</span><br>Une page à son nom, son bon déjà affiché, son GSM déjà connu : « Créer mon mot de passe » lui envoie le code SMS du webshop. On sait qui a cliqué et qui a activé son compte.</div></div>
        <div class="opt"><i></i><div><b>Le lien direct au webshop</b> webshop.atelierby.be/?shop=4&amp;voucher=…<br>Rien à construire en plus ; le client doit trouver lui-même « Mot de passe oublié › SMS » et retaper son numéro. Le texte passe à 183 caractères : <b>2 SMS</b> par client, pas de suivi des clics, et le webshop efface le bon du lien au chargement.</div></div></div>
    </div></section>`;

    const bon = `<section class="card bloc rel">${pin(4, true)}<div class="bloc__t"><h2>3 · Le bon de bienvenue</h2><span class="k">une promotion du magasin, avec bon</span></div><div class="bloc__c">
      <div class="bon"><div><div class="l">La promotion</div><div class="select">Bienvenue webshop — 50 % sur une tarte <span class="muted">▾</span></div></div>
        <div><div class="l">Valable</div><div class="select">jusqu’au 31/12/2026 <span class="muted">▾</span></div></div></div>
      <div class="choix"><div class="opt on"><i></i><div><b>Un code personnel par client, une seule utilisation</b><span class="reco">conseillé</span><br>Créé à l’envoi au nom du client : il l’attend dans son compte webshop, « Mes bons », et l’utilise d’un geste. Il ne circule pas sur Facebook.</div></div>
        <div class="opt"><i></i><div><b>Un code commun</b> « BIENVENUE50 »<br>Le plus simple : le client le tape au panier. Mais n’importe qui peut l’utiliser : à plafonner (par exemple 300 utilisations).</div></div></div>
      ${alerte('La promotion doit exister dans <b>Vendre › Promotions</b> : 50 % sur <b>une tarte entière</b> des catégories du groupe Tartes (Ø 28 cm, Fruits frais, Croûtes, Tartissières, Tartocake), « avec bon », valable sur le webshop. Sans « entière », les 50 % passent sur un quart.', '', 'info')}
    </div></section>`;

    const envoi = `<section class="card bloc rel" style="padding-bottom:0">${pin(5, true)}<div class="bloc__t"><h2>4 · Essai et envoi</h2><span class="k">brouillon enregistré 09:52</span></div><div class="bloc__c">
      <div class="recap"><div><div class="v">≈ 590 SMS</div><div class="k">1 par client, en FR ou en NL</div></div><div><div class="v">≈ 590 crédits</div><div class="k">SMSAPI (1 SMS = 1 crédit)</div></div><div><div class="v">≈ 590 bons</div><div class="k">créés au moment de l’envoi</div></div></div>
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap"><span class="btn">${ic('sms')}Envoyer un essai à mon GSM · 0476 •• •• 12</span><span class="muted" style="font-size:12px">essai reçu à 09:55 ${ic('ok')}</span></div>
      <div style="display:flex;gap:14px;align-items:center;margin-top:14px;flex-wrap:wrap"><span class="muted" style="font-size:12px">Quand</span><span class="seg"><span>Maintenant</span><span class="is-on">Samedi 29/11 à 10:00</span><span>Choisir…</span></span></div>
      <div class="note-mq">Jamais avant 9:00 ni après 20:00 · 60 SMS par minute · un échec est réessayé une fois, puis noté dans le suivi.</div>
    </div><div class="pied"><span class="btn">Enregistrer le brouillon</span><span class="btn btn--primary">${ic('envoi')}Programmer l’envoi à ≈ 590 clients</span></div></section>`;

    const droite = `<div class="rel" style="position:sticky;top:12px">${pin(6, true)}<div class="card" style="padding:16px 14px"><div style="font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin:0 0 12px 4px">Ce que reçoit le client</div>
      ${tel(bulle(SMS_FR))}<div class="note-mq" style="text-align:center">Le code du lien est propre à chaque client.</div></div></div>`;

    return tete() + `<div class="etapes"><span class="ok"><b>${'✓'}</b>Qui</span><span class="ok"><b>✓</b>Le message</span><span class="ok"><b>✓</b>Le bon</span><span class="on"><b>4</b>Essai et envoi</span><span><b>5</b>Suivi</span></div>
      <div class="sw2"><div>${qui}${msg}${bon}${envoi}
      ${legende('Ce que propose la maquette', [
        '<b>Un cinquième onglet, SMS</b>, dans Vendre › Clients : la campagne se prépare là où sont les clients ; le bon vient de Promotions.',
        '<b>Les vrais chiffres de Halle</b> (TF Buddy, 28/11) : 680 particuliers, ≈ 590 GSM différents ; ≈ 80 seulement se sont déjà connectés au webshop.',
        '<b>Un SMS de 160 caractères</b>, dans la langue du client (Halle : NL par défaut, à décider), avec le lien STOP obligatoire.',
        '<b>Un code personnel par client</b>, créé dans TF Buddy à l’envoi, qui l’attend dans « Mes bons » du webshop ; un code commun reste possible.',
        '<b>L’essai à son propre GSM d’abord</b>, puis un envoi programmé (9:00 – 20:00), à la cadence de SMSAPI.',
        '<b>L’aperçu</b> du SMS tel qu’il arrive ; le parcours après le clic est l’autre maquette (?v=parcours).',
      ])}</div>${droite}</div>`;
  }

  /* ── 2. Le parcours du client ───────────────────────────────────────────────────────────────────────────────── */
  function vueParcours() {
    const e = (n, t) => `<p class="num-e"><b>${n}</b>${t}</p>`;
    const page = `<div class="web__url">atelierby.be/w/7KQ2MP</div><div class="web"><img class="web__logo" src="../../../public/tablette/img/logo.png" alt="">
      <h3>Bonjour Gabrielle,<br>notre webshop est ouvert.</h3><p>Commandez en ligne vos tartes, pains et viennoiseries : retrait à Halle (Auguste Demaeghtlaan) ou livraison.</p>
      <div class="code"><div class="c">B7KQ2MPX</div><div class="s">votre bon de bienvenue · -50 % sur une tarte · jusqu’au 31/12</div></div>
      <span class="cta">Créer mon mot de passe</span><p class="petit">Un code arrive par SMS au 0476 •• •• 12</p><span class="cta2">J’ai déjà un mot de passe</span>
      <p class="petit" style="margin-top:auto">Ce n’est pas vous ? <u>Ne plus recevoir de SMS</u></p></div>`;
    const code = `<div class="web__url">atelierby.be/w/7KQ2MP</div><div class="web"><img class="web__logo" src="../../../public/tablette/img/logo.png" alt="">
      <h3>Choisissez votre mot de passe</h3><p>Nous avons envoyé un code à 6 chiffres au 0476 •• •• 12.</p>
      <div class="champ"><b>Code reçu par SMS</b>4 8 2 1 9 0</div><div class="champ"><b>Nouveau mot de passe</b>••••••••••</div><div class="champ"><b>Encore une fois</b>••••••••••</div>
      <span class="cta">Enregistrer</span><span class="cta2">Ouvrir le webshop · se connecter avec son GSM</span><p class="petit">Pas reçu ? Renvoyer le code (dans 45 s)</p>
      <p class="petit" style="margin-top:auto">C’est le « mot de passe oublié par SMS » du webshop : le même compte, le même numéro de client.</p></div>`;
    const shop = `<div class="web__url">webshop.atelierby.be/?shop=4</div><div class="web"><img class="web__logo" src="../../../public/tablette/img/logo.png" alt="">
      <div class="bandeau">${ic('ok')} Connectée avec son GSM et son nouveau mot de passe</div><h3 style="font-size:16px">Mon compte › Mes bons</h3>
      <div class="code" style="text-align:left"><div style="display:flex;justify-content:space-between;align-items:center;gap:8px"><div><div style="font-size:14px">Bon de bienvenue</div><div class="s">-50 % sur une tarte entière · jusqu’au 31/12</div></div><span class="btn btn--primary btn--sm">Utiliser</span></div></div>
      <div class="produit"><div class="img"></div><div class="p">Tarte aux pommes Ø 28<br><em>12,45 €</em><s>24,90 €</s></div></div>
      <div class="produit"><div class="img" style="background:linear-gradient(135deg,#E9B8B8,#8D1D2C)"></div><div class="p">Tarte fruits frais Ø 28<br><em>14,95 €</em><s>29,90 €</s></div></div>
      <p class="petit" style="margin-top:auto">« Utiliser » garde le bon pour le panier ; il s’applique à une tarte, une fois.</p></div>`;
    return `<div class="tete" style="margin-bottom:6px"><div><p class="tete__kicker">Le parcours du client · lien personnel</p><h1>Du SMS au panier, en quatre écrans</h1></div></div>
      <div class="parcours"><div>${e(1, 'Le SMS arrive')}${tel(bulle(SMS_FR))}</div><div>${e(2, 'Sa page, à son nom')}${tel(page, '')}</div><div>${e(3, 'Le code SMS du webshop')}${tel(code, '')}</div><div>${e(4, 'Le webshop : Mes bons')}${tel(shop, '')}</div></div>
      <div style="max-width:1180px;margin:0 auto">${legende('À vérifier avant de construire', [
        '<b>Le « mot de passe oublié par SMS » du webshop</b> existe en ligne (POST /clients/auth/password/reset/sms/request puis …/confirm) : à essayer avec un client créé <b>en caisse</b> (sans e-mail ni mot de passe).',
        '<b>598 clients de Halle n’ont pas de numéro au format international</b> dans TF Buddy (le champ phone_e164 vide) : si le code SMS se cherche par ce champ, il faut d’abord le remplir (PATCH /clients/{id}).',
        '<b>Pas de bon dans le lien</b> : le webshop efface « ?voucher= » au chargement ; le bon au nom du client l’attend dans « Mes bons ». Le code SMS ne connecte pas : le client se connecte une fois au webshop avec son GSM et ce mot de passe.',
        '<b>Le code personnel</b> doit apparaître dans « Mes bons » et s’appliquer à une tarte entière, à 50 %, une fois : à essayer avec un vrai panier avant l’envoi.',
      ])}</div>`;
  }

  /* ── 3. Le suivi ────────────────────────────────────────────────────────────────────────────────────────────── */
  function vueSuivi() {
    const f = (v, k, p, w) => `<div><div class="v">${v}</div><div class="k">${k}</div>${p ? `<div class="p">${p}</div>` : ''}<div class="barre"><i style="width:${w}%"></i></div></div>`;
    const L = [['Gabrielle', 'FR', '0476 •• •• 12', 'bon', 'Bon utilisé · tarte aux pommes Ø 28 · 29/11 11:42'], ['Jan', 'NL', '0478 •• •• 40', 'compte', 'Compte activé 29/11 10:31'],
      ['Sofie', 'NL', '0499 •• •• 07', 'clic', 'A ouvert le lien 29/11 10:12'], ['Mehdi', 'FR', '0485 •• •• 63', 'remis', 'Remis 29/11 10:01'], ['Annick', 'FR', '0472 •• •• 91', 'echec', 'Numéro injoignable (SMSAPI)'],
      ['Pieter', 'NL', '0496 •• •• 28', 'stop', 'STOP 29/11 10:20 · ne reçoit plus rien'], ['Laura', 'FR', '0471 •• •• 55', 'compte', 'Compte activé 29/11 12:05'], ['Koen', 'NL', '0477 •• •• 19', 'remis', 'Remis 29/11 10:02']];
    const lib = { bon: 'Bon utilisé', compte: 'Compte activé', clic: 'Lien ouvert', remis: 'Remis', echec: 'Échec', stop: 'STOP' };
    const lignes = L.map(x => `<tr><td>${x[0]}<span class="sub">${x[1]} · ${x[2]}</span></td><td><span class="etat e-${x[3]}">${lib[x[3]]}</span></td><td class="muted" style="font-size:12px">${x[4]}</td></tr>`).join('');
    return tete() + `<div class="etapes"><span class="ok"><b>✓</b>Qui</span><span class="ok"><b>✓</b>Le message</span><span class="ok"><b>✓</b>Le bon</span><span class="ok"><b>✓</b>Essai et envoi</span><span class="on"><b>5</b>Suivi</span></div>
      <section class="card bloc rel">${pin(2, true)}<div class="bloc__t"><h2>Le webshop est en ligne · samedi 29/11 à 10:00</h2><span class="k">exemple fictif · 3 jours après l’envoi</span></div><div class="bloc__c">
        <div class="entonnoir">${f('592', 'SMS envoyés', '', 100)}${f('581', 'remis', '98 %', 98)}${f('214', 'ont ouvert le lien', '37 % des remis', 37)}${f('97', 'comptes activés', '45 % des clics', 16)}${f('41', 'bons utilisés', '≈ 41 tartes à -50 %', 7)}</div>
        <div class="exclus" style="margin-top:12px"><span>11 échecs (numéro injoignable)</span><span>6 STOP</span><span>crédits SMSAPI utilisés : 592</span></div>
      </div></section>
      <div class="sw2" style="grid-template-columns:minmax(0,1fr) 360px"><div class="rel">${pin(3, true)}<div class="card tab-card"><div class="filtres"><input placeholder="Nom, téléphone"><span class="grp">Voir <span class="seg"><span class="is-on">Tous</span><span>Pas encore cliqué</span><span>Compte activé</span><span>Échecs</span></span></span></div>
        <table><thead><tr><th>Client</th><th>Où il en est</th><th>Dernier geste</th></tr></thead><tbody>${lignes}</tbody></table></div></div>
        <div class="card rel" style="padding:16px 18px">${pin(4, true)}<div style="font-size:15px;margin-bottom:8px">Relancer</div>
          <p class="muted" style="font-size:12.5px;line-height:1.5;margin:0 0 12px">Une relance va seulement à ceux qui n’ont pas ouvert le lien, au plus tôt 7 jours après, une seule fois.</p>
          <div class="recap" style="grid-template-columns:1fr"><div><div class="v">367 clients</div><div class="k">remis, pas encore cliqué</div></div></div>
          <span class="btn" style="width:100%">Préparer la relance du 06/12</span></div></div>
      ${legende('Le suivi', [
        '<b>Une campagne = un envoi daté</b>, gardé avec son texte, ses chiffres et ses destinataires (le numéro masqué dans la liste).',
        '<b>Remis, ouvert, activé, bon utilisé</b> : la remise vient de SMSAPI, le clic du lien court, l’activation et le bon de TF Buddy.',
        '<b>Chaque client, où il en est</b> : de quoi appeler ceux qui ont ouvert sans activer, ou retirer un numéro faux.',
        '<b>Une relance</b>, une seule, à ceux qui n’ont pas ouvert ; les STOP ne reçoivent plus rien.',
      ])}`;
  }

  const corps = V === 'parcours' ? vueParcours() : V === 'suivi' ? vueSuivi() : vueEnvoi();
  document.getElementById('app').innerHTML = V === 'parcours' ? `<div style="width:1440px;padding:28px 24px 30px;background:var(--paper)">${corps}</div>` : `<div class="workspace">${rail()}<main class="main">${corps}</main></div>`;
})();
