/* Maquette : le formulaire d'un bureau. Données fictives. ?ecran=societe | livraison | mobile | merci */
(function () {
  const ecran = new URLSearchParams(location.search).get('ecran') || 'societe';
  const app = document.getElementById('app');
  const LOGO = '../../../../shared/public/assets/img/logo.png';
  const OK = '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>';
  const CADENAS = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>';
  const ch = (lib, val, o = {}) => `<label class="ch ${o.c || 'c3'}"><span>${lib}${o.req ? ' <em>*</em>' : ''}</span><span class="in ${o.cl || ''}${val ? '' : ' vide'}">${val || o.ph || ''}${o.fin ? `<span class="fin">${o.fin}</span>` : ''}</span>${o.aide ? `<small>${o.aide}</small>` : ''}</label>`;
  const ETAPES = [['Vous', 'le contact du bureau'], ['Votre compte', 'pour commander au webshop'], ['Votre société', 'TVA, facturation'], ['La livraison', 'adresse, jours, heure'], ['Votre équipe', 'services, collègues'], ['Récapitulatif', 'et votre bon']];
  const etapes = ici => `<ol class="bf-etapes">${ETAPES.map(([t, s], i) => `<li class="${i < ici ? 'fait' : i === ici ? 'ici' : ''}"><span class="n">${i < ici ? OK : i + 1}</span><span class="t">${t}<small>${s}</small></span></li>`).join('')}</ol>`;
  const haut = () => `<header class="bf-haut"><img src="${LOGO}" alt="L'Atelier by"><div class="mag"><b>Atelier by Berlo · Corbais</b>Rue de la Gare 12, 1435 Corbais</div></header>`;
  const bon = (code) => `<div class="bon"><div class="k">Votre bon de bienvenue</div><div class="q">20</div><div class="t">mini viennoiseries au choix</div>
    <div class="d">Un vendredi matin, de 6 h à 12 h, au magasin ou livrées au bureau. Croissant, chocolat, pomme, frangipane…</div>
    <div class="perfo"></div><div class="code"><span>Votre code</span><b>${code || '• • • • • •'}</b></div></div>`;
  const recap = (lignes) => `<div class="recap"><h4>Votre bureau</h4>${lignes.map(([a, b]) => `<div class="l${b ? '' : ' vide'}"><span>${a}</span><b>${b || 'à venir'}</b></div>`).join('')}</div>`;
  const sur = `<div class="sur">${CADENAS}<span>Votre mot de passe va directement à notre webshop ; le magasin ne le voit pas. Vos données servent à livrer votre bureau, jamais revendues.</span></div>`;
  const titre = (ici) => `<div class="bf-titre"><div><p class="k">Du frais au bureau</p><h1>Inscrivez votre bureau chez L'Atelier by</h1><p>En six étapes : votre compte webshop, votre société et le lieu de livraison. Le magasin valide votre bureau, et vos collègues commandent ensuite au même endroit.</p></div>
    <div class="prog">Étape ${ici + 1} sur 6 · environ 3 minutes<div class="barre"><i style="width:${Math.round((ici + 1) / 6 * 100)}%"></i></div></div></div>`;

  function societe() {
    const carte = `<section class="bf-carte"><h2>Votre société</h2><p class="aide">Le numéro de TVA suffit : nous lisons le nom et le siège dans le registre européen (VIES). Le bureau, ses factures et ses remises sont ceux de cette société.</p>
      <div class="g">${ch('Numéro de TVA', 'BE 0789.114.256', { req: true, c: 'c3', cl: 'ok', fin: OK })}${ch('Pays', 'Belgique', { req: true, c: 'c3' })}</div>
      <div class="vies"><span class="ic">${OK}</span><span><b>Techni-Sol SRL</b> · TVA valide · Rue de l'Industrie 22, 6041 Gosselies<br>Le nom et l'adresse du siège sont repris ci-dessous ; modifiez-les si besoin.</span></div>
      <div class="g">${ch('Raison sociale', 'Techni-Sol SRL', { req: true, c: 'c4' })}${ch('Secteur', 'Construction, bâtiment', { c: 'c2' })}
        ${ch('Nom du bureau', 'Techni-Sol · Corbais', { req: true, c: 'c3', aide: 'Celui que vos collègues verront au webshop.' })}${ch('E-mail de facturation', 'compta@techni-sol.be', { c: 'c3', aide: 'Les factures du bureau y partent.' })}</div>
      <h3>Adresse de facturation</h3>
      <div class="g">${ch('Rue', 'Rue de l’Industrie', { req: true, c: 'c3' })}${ch('Numéro', '22', { req: true, c: 'c1' })}${ch('Code postal', '6041', { req: true, c: 'c1' })}${ch('Boîte', '', { ph: '—', c: 'c1' })}
        ${ch('Localité', 'Gosselies', { req: true, c: 'c3' })}${ch('Bon de commande', '', { ph: 'si votre comptabilité en demande un', c: 'c3' })}</div>
      <h3>Qui paie les commandes ?</h3>
      <div class="choix"><div class="on">La société<small>Une facture par mois, au nom de Techni-Sol</small></div><div>Chacun paie<small>Chaque collègue paie en ligne sa commande</small></div><div>Les deux<small>La société paie les réunions, chacun son lunch</small></div></div>
      <h3>Votre logo <span style="text-transform:none;letter-spacing:0;color:var(--faint)">· facultatif</span></h3>
      <div class="logo-z"><i>TS</i><span>techni-sol-logo.png · 18 Ko<br><span style="color:var(--faint)">Il s'affiche sur le webshop de vos collègues. PNG, JPG ou WebP.</span></span><span class="btn btn--sm" style="margin-left:auto">Changer</span></div>
      <div class="pied"><span class="btn btn--ghost">Retour</span><span class="muet">Enregistré au fur et à mesure sur cet appareil</span><span class="btn btn--primary">Continuer : la livraison</span></div></section>`;
    return haut() + `<div class="bf-page">${titre(2)}${etapes(2)}<div>${carte}</div><aside class="bf-cote">${bon()}${recap([['Contact', 'Marie Dupont · Office manager'], ['Compte', 'marie.dupont@techni-sol.be'], ['Société', 'Techni-Sol SRL · BE0789114256'], ['Livraison', ''], ['Équipe', '']])}${sur}</aside></div>`;
  }

  function livraison() {
    const carte = `<section class="bf-carte"><h2>Où et quand livrer ?</h2><p class="aide">L'adresse où nos livreurs déposent la commande. Elle peut différer du siège. Dites-nous comment entrer : nous livrons vite et sans appeler.</p>
      <div class="g">${ch('Adresse du bureau', 'Rue de la Station 14, Corbais', { req: true, c: 'c6', cl: 'focus' })}</div>
      <div class="carte-sugg"><div>Rue de la Station 14, 1435 Corbais, Belgique</div><div>Rue de la Station 14, 1435 Mont-Saint-Guibert</div><div>Rue de la Station 140, 1435 Corbais</div></div>
      <div class="plan"><i></i><span>Adresse vérifiée · 1,8 km du magasin · tournée du matin</span></div>
      <div class="g">${ch('Bâtiment', 'Bloc B', { c: 'c2' })}${ch('Étage', '2e', { c: 'c2' })}${ch('Personnes sur place', '18', { c: 'c2' })}
        ${ch('Consignes d’accès', 'Accueil au rez-de-chaussée ; code portail 2580 ; parking visiteurs à droite.', { c: 'c6', cl: 'zone' })}</div>
      <h3>Les jours de livraison</h3>
      <div class="jours"><span class="on">Lun</span><span>Mar</span><span class="on">Mer</span><span>Jeu</span><span class="on">Ven</span><span>Sam</span></div>
      <div class="g">${ch('Livré entre', '11:30', { req: true, c: 'c2' })}${ch('et', '12:15', { req: true, c: 'c2' })}${ch('Commande au plus tard', 'la veille, 16 h', { c: 'c2', aide: 'Le magasin le confirmera.' })}</div>
      <h3>À la livraison</h3>
      <div class="choix"><div class="on">Scan du QR code<small>À l’accueil, en dix secondes</small></div><div>Signature<small>Sur le téléphone du livreur</small></div><div>Dépôt<small>Sans contact, photo à l’appui</small></div></div>
      <div class="pied"><span class="btn btn--ghost">Retour</span><span class="muet">Un deuxième site ? Vous l'ajouterez après validation.</span><span class="btn btn--primary">Continuer : votre équipe</span></div></section>`;
    return haut() + `<div class="bf-page">${titre(3)}${etapes(3)}<div>${carte}</div><aside class="bf-cote">${bon()}${recap([['Contact', 'Marie Dupont · Office manager'], ['Compte', 'marie.dupont@techni-sol.be'], ['Société', 'Techni-Sol SRL · BE0789114256'], ['Livraison', 'Rue de la Station 14, Corbais · lun, mer, ven · 11:30–12:15'], ['Équipe', '']])}${sur}</aside></div>`;
  }

  function mobile() {
    return `<div class="mob">${haut().replace('<div class="mag"><b>Atelier by Berlo · Corbais</b>Rue de la Gare 12, 1435 Corbais</div>', '<div class="mag"><b>Corbais</b>Étape 2 sur 6</div>')}
      <div class="mh"><p class="k">Du frais au bureau</p><h1>Votre compte pour commander</h1></div>
      <div class="mbar"><i class="on"></i><i class="on"></i><i></i><i></i><i></i><i></i></div>
      <div class="mbon"><span class="q">20</span><span><b>Un bon vous attend</b>20 mini viennoiseries au choix, un vendredi matin, à la fin du formulaire.</span></div>
      <section class="bf-carte"><h2>Vous</h2><p class="aide">Vous êtes le contact du bureau : le magasin vous appelle pour la première livraison.</p>
        <div class="g">${ch('Prénom', 'Marie', { req: true })}${ch('Nom', 'Dupont', { req: true })}${ch('Fonction', 'Office manager', { c: 'c6' })}${ch('Téléphone', '+32 470 12 34 56', { req: true, c: 'c6', cl: 'ok', fin: OK })}</div>
        <div class="ch c6" style="margin-bottom:14px"><span>Langue</span><div class="seg"><span class="on">Français</span><span>Nederlands</span><span>English</span></div></div></section>
      <section class="bf-carte"><h2>Votre compte webshop</h2><p class="aide">Avec lui, vous et vos collègues commandez au nom du bureau. Vous avez déjà un compte ? <span style="color:var(--accent)">Connectez-vous</span>.</p>
        <div class="g">${ch('E-mail', 'marie.dupont@techni-sol.be', { req: true, c: 'c6', cl: 'ok', fin: OK })}
        <label class="ch c6"><span>Mot de passe <em>*</em></span><span class="in focus">••••••••••<span class="fin">Afficher</span></span><span class="force"><i class="on"></i><i class="on"></i><i class="on"></i><i></i></span><small>Bon · 8 caractères au moins, avec un chiffre</small></label>
        ${ch('Confirmer le mot de passe', '••••••••••', { req: true, c: 'c6', cl: 'ok', fin: OK })}</div>
        ${sur.replace('class="sur"', 'class="sur" style="margin:0 0 14px"')}</section>
      <div class="bas"><span class="btn btn--primary">Continuer : votre société</span></div></div>`;
  }

  function merci() {
    const qr = window.qrcode ? (() => { const q = window.qrcode(0, 'M'); q.addData('BUR-7KQ2M9'); q.make(); return q.createSvgTag({ margin: 0, scalable: true }); })() : '';
    return haut() + `<div class="merci"><div><p class="k">${OK} C'est envoyé</p><h1>Merci Marie, votre bureau est inscrit.</h1>
      <p class="intro">Votre compte webshop est créé et votre bon est prêt. Le magasin de Corbais valide Techni-Sol aujourd'hui ; vous recevez alors un e-mail, et vos collègues aussi.</p>
      <ol class="suite"><li class="fait"><span class="n">${OK}</span><span><b>Votre compte webshop</b><small>marie.dupont@techni-sol.be · connectez-vous quand vous voulez</small></span></li>
        <li class="fait"><span class="n">${OK}</span><span><b>Votre bon de bienvenue</b><small>Un code à votre nom, valable une fois, jusqu'au 30/06/2027</small></span></li>
        <li><span class="n">3</span><span><b>Le magasin valide votre bureau</b><small>D'habitude dans la journée. Il vous appelle pour la première livraison.</small></span></li>
        <li><span class="n">4</span><span><b>Vos 6 collègues reçoivent leur invitation</b><small>Ils créent leur compte et commandent au nom de Techni-Sol</small></span></li></ol>
      <div class="acts" style="max-width:520px"><span class="btn btn--primary">Ouvrir le webshop</span><span class="btn">Ajouter le bon à mon téléphone</span></div></div>
      <div><div class="bon grand"><div class="k">Votre bon de bienvenue</div><div class="q">20</div><div class="t">mini viennoiseries au choix</div>
        <div class="d">Un vendredi matin, de 6 h à 12 h. Montrez ce code en caisse, ou saisissez-le au webshop.</div><div class="perfo"></div>
        <div class="qr"><div class="bl">${qr}</div><div class="cd">Votre code<b>BUR-7KQ2M9</b>Une fois · au nom de Marie Dupont<br>Valable jusqu'au 30/06/2027</div></div></div>
        <p style="font-size:12px;color:var(--muted);line-height:1.5;margin:12px 4px 0">Envoyé aussi par e-mail à marie.dupont@techni-sol.be.</p></div></div>`;
  }

  app.innerHTML = ({ societe, livraison, mobile, merci }[ecran] || societe)();
})();
