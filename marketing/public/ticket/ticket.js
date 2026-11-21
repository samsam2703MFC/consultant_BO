/*
 * La fiche produits du ticket de caisse (21/11/2026) : la page que le client ouvre en scannant le QR de son ticket.
 * Une lecture : GET ../api/cockpit/marketing/ticket/client?t={boutique}-{transaction}-{clé} (marketing/src/ticket_client.php).
 * Tout le texte produit vient de l'API, sans retouche ; un bloc sans donnée n'est pas affiché. La langue est celle de la
 * boutique (Flandre en néerlandais), le client peut basculer. Rendu repris de la maquette
 * (marketing/docs/maquettes/ticket-fiche-produits/generer.py) : mêmes blocs, mêmes classes.
 */
(function () {
  'use strict';
  var API = '../api/cockpit';
  var NBSP = ' ';
  var params = new URLSearchParams(location.search);
  var CODE = params.get('t') || '';
  var racine = document.getElementById('page');
  var donnees = null;

  var ALLERGENES = [
    ['cereals_gluten', 'Gluten', 'Gluten'], ['crustaceans', 'Crustacés', 'Schaaldieren'], ['eggs', 'Œufs', 'Eieren'],
    ['fish', 'Poisson', 'Vis'], ['peanuts', 'Arachides', 'Pinda’s'], ['soybeans', 'Soja', 'Soja'], ['milk', 'Lait', 'Melk'],
    ['nuts', 'Fruits à coque', 'Noten'], ['celery', 'Céleri', 'Selderij'], ['mustard', 'Moutarde', 'Mosterd'],
    ['sesame_seeds', 'Sésame', 'Sesam'], ['sulphites', 'Sulfites', 'Sulfieten'], ['lupin', 'Lupin', 'Lupine'],
    ['molluscs', 'Mollusques', 'Weekdieren']
  ];
  var JOURS = { fr: ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'],
    nl: ['zondag', 'maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag', 'zaterdag'] };
  var MOIS = { fr: ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'],
    nl: ['januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'] };

  // Les textes de l'interface. Le néerlandais suit le ton du site (« uw »).
  var T = {
    fr: {
      titre: 'Vos produits en détail · L’Atelier By', surTitre: 'Votre ticket · {ville}', h1: 'Vos produits,', h1s: 'en détail.',
      chapeau: 'Composition, allergènes, cuisson, finition et conservation : tout sur les produits de votre ticket.',
      articles: '{n} articles', ticket: 'Votre ticket · transaction n° {num}', ticketT: '{p} produits, {n} articles',
      total: 'Total payé, TVA comprise', alg: 'Allergènes', algT: 'Ce que contient votre ticket',
      sans: '<b>Aucun de vos produits</b> ne contient, d’après nos fiches : {liste}.',
      traces: 'Nos ateliers travaillent aussi {liste} : des traces restent possibles. Une allergie sévère ? Parlez-en en boutique avant de déguster.',
      et: 'et', fraich: 'Fraîcheur', fraichT: 'Dans quel ordre les déguster', fiches: 'Fiche par fiche', h2: 'Tout ce qu’il faut ', h2s: 'savoir.',
      fichesP: 'Glissez d’un produit à l’autre, ou touchez-en un dans la barre.', fichesPB: 'Touchez un produit dans la barre pour aller à sa fiche.',
      barre: 'Produits du ticket', prec: 'Produit précédent', suiv: 'Produit suivant', sur: 'sur',
      composition: 'Composition', allergenes: 'Allergènes', cuisson: 'Cuisson', finition: 'Préparation et finition', conservation: 'Conservation',
      aucun: 'Aucun des 14 allergènes réglementaires, d’après nos fiches.', g14: 'Les 14 allergènes, un par un', contient: 'Contient',
      rechauffer: 'Réchauffer au four', nousCuisons: 'Comment nous le cuisons', entre: 'À conserver entre {a} et {b}.', lire: 'Lire la suite',
      vege: 'Végétarien', aDeguster: 'À déguster {quand}', recommander: 'Le recommander',
      avis: 'Votre avis', avisT: 'Comment était votre passage à {ville} ?', avisP: 'Un mot sur Google aide l’équipe de la boutique, et les clients qui hésitent encore.', avisB: 'Laisser un avis',
      app: 'Mobile', appT: 'Tous vos tickets, au même endroit', appP: 'Dans l’application : vos fiches produits, le programme de fidélité et vos commandes.', appB: 'Télécharger l’application',
      shop: 'Votre boutique', adresse: 'Adresse', tel: 'Téléphone', commander: 'Commander pour demain',
      pied: 'Les informations de cette page viennent des fiches techniques de nos produits, à la date de votre achat. Une recette peut évoluer : en cas d’allergie ou d’intolérance, <b>demandez conseil en boutique</b>.',
      pied2: '© {an} L’Atelier By', auj: 'aujourd’hui, avant {h}', demMatin: 'demain matin', dem: 'demain', jusqua: 'jusqu’à {m}', jusquau: 'jusqu’au {d}',
      charge: 'Un instant…', introuvable: 'Ticket introuvable', introuvableP: 'Le lien de ce ticket n’est pas valable. Scannez de nouveau le QR de votre ticket, ou demandez en boutique.',
      reseau: 'Le serveur ne répond pas. Réessayez dans un instant.'
    },
    nl: {
      titre: 'Uw producten in detail · L’Atelier By', surTitre: 'Uw kasticket · {ville}', h1: 'Uw producten,', h1s: 'in detail.',
      chapeau: 'Samenstelling, allergenen, bereiding, afwerking en bewaren: alles over de producten op uw kasticket.',
      articles: '{n} artikelen', ticket: 'Uw kasticket · transactie nr. {num}', ticketT: '{p} producten, {n} artikelen',
      total: 'Totaal betaald, incl. btw', alg: 'Allergenen', algT: 'Wat uw kasticket bevat',
      sans: '<b>Geen enkel product</b> op uw kasticket bevat, volgens onze fiches: {liste}.',
      traces: 'In onze ateliers verwerken we ook {liste}: sporen zijn mogelijk. Een ernstige allergie? Vraag raad in de winkel voor u proeft.',
      et: 'en', fraich: 'Versheid', fraichT: 'Wat eet u best eerst?', fiches: 'Product per product', h2: 'Alles wat u moet ', h2s: 'weten.',
      fichesP: 'Veeg van het ene product naar het andere, of tik er een aan in de balk.', fichesPB: 'Klik op een product in de balk om naar de fiche te gaan.',
      barre: 'Producten op het kasticket', prec: 'Vorig product', suiv: 'Volgend product', sur: 'van',
      composition: 'Samenstelling', allergenes: 'Allergenen', cuisson: 'Bakken en opwarmen', finition: 'Bereiding en afwerking', conservation: 'Bewaren',
      aucun: 'Geen van de 14 wettelijke allergenen, volgens onze fiches.', g14: 'De 14 allergenen, een voor een', contient: 'Bevat',
      rechauffer: 'Opwarmen in de oven', nousCuisons: 'Hoe wij het bakken', entre: 'Bewaren tussen {a} en {b}.', lire: 'Meer lezen',
      vege: 'Vegetarisch', aDeguster: 'Te eten {quand}', recommander: 'Opnieuw bestellen',
      avis: 'Uw mening', avisT: 'Hoe was uw bezoek in {ville}?', avisP: 'Een woordje op Google helpt het winkelteam, en klanten die nog twijfelen.', avisB: 'Laat een review achter',
      app: 'Mobiel', appT: 'Al uw kastickets op één plek', appP: 'In de app: uw productfiches, het getrouwheidsprogramma en uw bestellingen.', appB: 'Download de app',
      shop: 'Uw winkel', adresse: 'Adres', tel: 'Telefoon', commander: 'Bestel voor morgen',
      pied: 'De informatie op deze pagina komt uit de technische fiches van onze producten, op de datum van uw aankoop. Een recept kan wijzigen: bij een allergie of intolerantie, <b>vraag raad in de winkel</b>.',
      pied2: '© {an} L’Atelier By', auj: 'vandaag, vóór {h}', demMatin: 'morgenochtend', dem: 'morgen', jusqua: 'tot {m}', jusquau: 'tot {d}',
      charge: 'Even geduld…', introuvable: 'Kasticket niet gevonden', introuvableP: 'De link van dit kasticket is niet geldig. Scan de QR-code op uw kasticket opnieuw, of vraag het in de winkel.',
      reseau: 'De server antwoordt niet. Probeer het zo meteen opnieuw.'
    }
  };

  var ICONES = {
    four: '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18"/><circle cx="7" cy="6.5" r=".6"/><circle cx="10" cy="6.5" r=".6"/><rect x="6.5" y="12" width="11" height="5" rx="1"/>',
    horloge: '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
    feuille: '<path d="M5 19c0-8 5-13 14-14-1 9-6 14-14 14z"/><path d="M5 19 13 11"/>',
    poids: '<path d="M6 8h12l2 12H4z"/><circle cx="12" cy="5.5" r="2"/>',
    fleche: '<path d="M5 12h14M13 6l6 6-6 6"/>', gauche: '<path d="M19 12H5M11 6l-6 6 6 6"/>',
    alerte: '<path d="M12 4 21 19H3z"/><path d="M12 10v4M12 16.5v.01"/>',
    etoile: '<path d="m12 4 2.4 4.9 5.4.8-3.9 3.8.9 5.4L12 16.4l-4.8 2.5.9-5.4-3.9-3.8 5.4-.8z"/>'
  };
  function ic(n) { return '<svg class="ic" viewBox="0 0 24 24" aria-hidden="true">' + ICONES[n] + '</svg>'; }
  function e(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function f(t, v) { return t.replace(/\{(\w+)\}/g, function (m, k) { return v[k] != null ? v[k] : m; }); }
  function maj(t) { return t ? t.charAt(0).toUpperCase() + t.slice(1) : t; }
  function euros(x, lg) {
    var v = Number(x || 0).toFixed(2).replace('.', ',');
    return lg === 'nl' ? '€' + NBSP + v : v + NBSP + '€';
  }
  function jourLong(d, lg) {
    var n = lg === 'fr' && d.getDate() === 1 ? '1er' : String(d.getDate());
    return JOURS[lg][d.getDay()] + ' ' + n + NBSP + MOIS[lg][d.getMonth()];
  }
  function heure(d, lg) {
    var mm = ('0' + d.getMinutes()).slice(-2);
    return lg === 'nl' ? d.getHours() + '.' + mm + NBSP + 'u' : d.getHours() + NBSP + 'h' + NBSP + mm;
  }
  function memeJour(a, b) { return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate(); }
  // La date limite (vente + durée de conservation du produit) dite au client : {fin, urgent, court, long}.
  function limite(vente, minutes, lg) {
    var t = T[lg]; var fin = new Date(vente.getTime() + minutes * 60000);
    if (memeJour(fin, vente)) {
      var q = new Date(fin.getTime()); q.setMinutes(q.getMinutes() - q.getMinutes() % 15);
      var x = f(t.auj, { h: heure(q, lg) }); return { fin: fin, urgent: true, court: maj(x), long: x };
    }
    var dem = new Date(vente.getTime()); dem.setDate(dem.getDate() + 1);
    if (memeJour(fin, dem)) {
      var m = fin.getHours() < 12 ? t.demMatin : t.dem;
      return { fin: fin, urgent: false, court: maj(f(t.jusqua, { m: m })), long: f(t.jusqua, { m: m + ', ' + jourLong(fin, lg) }) };
    }
    var n = lg === 'fr' && fin.getDate() === 1 ? '1er' : String(fin.getDate());
    return { fin: fin, urgent: false, court: maj(f(t.jusquau, { d: n + NBSP + MOIS[lg][fin.getMonth()] })), long: f(t.jusquau, { d: jourLong(fin, lg) }) };
  }
  function nomAllergene(code, lg) { for (var i = 0; i < ALLERGENES.length; i++) { if (ALLERGENES[i][0] === code) { return ALLERGENES[i][lg === 'fr' ? 1 : 2]; } } return code; }
  function liste(mots, lg) { return mots.length > 1 ? mots.slice(0, -1).join(', ') + ' ' + T[lg].et + ' ' + mots[mots.length - 1] : mots.join(''); }
  function vignette(l) { return l.image ? 'style="background-image:url(\'' + e(l.image) + '\')"' : 'data-vide="1"'; }
  function dateVente(s) {
    var m = /^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/.exec(s || '');
    return m ? new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]) : new Date();
  }

  // ---- les blocs d'une fiche ----------------------------------------------------------------------------------------
  function blocComposition(l, lg) {
    var c = (l.composition || {})[lg] || { items: [], traduit: true };
    if (!c.items.length) { return ''; }
    var txt = c.items.map(function (it) {
      var al = it[1] || [];
      return e(it[0]) + (al.length ? ' (' + al.map(function (a) { return '<b>' + e(nomAllergene(a, lg).toLowerCase()) + '</b>'; }).join(', ') + ')' : '');
    }).join(', ');
    return '<div class="blk"><span class="eyebrow">' + e(T[lg].composition) + '</span><p class="blk__txt"' + (c.traduit ? '' : ' lang="fr"') + '>' + txt + '.</p></div>';
  }
  function blocAllergenes(l, lg) {
    var t = T[lg]; var al = l.allergenes || [];
    var tete = al.length ? '<div class="chips">' + al.map(function (a) { return '<span class="chip">' + e(nomAllergene(a, lg)) + '</span>'; }).join('') + '</div>'
      : '<p class="none">' + e(t.aucun) + '</p>';
    var g = ALLERGENES.map(function (a) { var oui = al.indexOf(a[0]) >= 0; return '<li class="' + (oui ? 'oui' : '') + '">' + e(nomAllergene(a[0], lg)) + '<span>' + (oui ? e(t.contient) : '—') + '</span></li>'; }).join('');
    return '<div class="blk"><span class="eyebrow">' + e(t.allergenes) + '</span>' + tete + '<details class="g14"><summary>' + e(t.g14) + '</summary><ul>' + g + '</ul></details></div>';
  }
  function texteLong(s, lg) {
    var parts = s.split(/\s(?=\d{1,2}\.\s)/).map(function (x) { return x.trim(); }).filter(Boolean);
    var corps = parts.length > 1 ? '<ol class="num">' + parts.map(function (x) { return '<li>' + e(x.replace(/^\d{1,2}\.\s*/, '')) + '</li>'; }).join('') + '</ol>' : '<p>' + e(s) + '</p>';
    var attr = lg !== 'fr' ? ' lang="fr"' : '';
    if (s.length < 260) { return '<div class="how__d"' + attr + '>' + corps + '</div>'; }
    return '<details class="long"' + attr + '><summary><div class="how__d">' + corps + '</div><span class="plus">' + e(T[lg].lire) + '</span></summary></details>';
  }
  function blocCuisson(l, lg) {
    var t = T[lg]; var items = ''; var r = l.rechauffe;
    if (r && (r.degres || r.minutes)) {
      items += '<div class="how__i"><span class="how__ic">' + ic('four') + '</span><div><p class="how__t">' + e(t.rechauffer) + '</p><p class="how__big">'
        + (r.degres ? r.degres + NBSP + '°C' : '') + (r.minutes ? '<span>· ' + r.minutes + NBSP + 'min</span>' : '') + '</p></div></div>';
    }
    (l.cuisson || []).forEach(function (x) {
      items += '<div class="how__i"><span class="how__ic">' + ic('four') + '</span><div><p class="how__t">' + e(t.nousCuisons) + '</p>' + texteLong(x, lg) + '</div></div>';
    });
    return items ? '<div class="blk"><span class="eyebrow">' + e(t.cuisson) + '</span><div class="how">' + items + '</div></div>' : '';
  }
  function blocFinition(l, lg) {
    if (!(l.etapes || []).length) { return ''; }
    return '<div class="blk"><span class="eyebrow">' + e(T[lg].finition) + '</span><ol class="steps"' + (lg !== 'fr' ? ' lang="fr"' : '') + '>'
      + l.etapes.map(function (s) { return '<li>' + e(s) + '</li>'; }).join('') + '</ol></div>';
  }
  function blocConservation(l, lg, lim) {
    var t = T[lg]; var m = /(-?\d+)\s*°C\s*[–-]\s*(-?\d+)\s*°C/.exec(l.stockage || '');
    var temp = m ? '<p class="blk__txt">' + e(f(t.entre, { a: m[1] + NBSP + '°C', b: m[2] + NBSP + '°C' })) + '</p>' : '';
    var date = lim ? '<p class="until">' + ic('horloge') + '<span>' + e(f(t.aDeguster, { quand: lim.long })) + '</span></p>' : '';
    return temp || date ? '<div class="blk"><span class="eyebrow">' + e(t.conservation) + '</span><div class="keep">' + temp + date + '</div></div>' : '';
  }
  function fiche(k, d, l, lg) {
    var t = T[lg]; var lim = l._lim;
    var tags = [];
    if (lim) { tags.push('<li class="tag' + (lim.urgent ? ' tag--now' : '') + '">' + ic('horloge') + e(lim.court) + '</li>'); }
    if (l.vegetarien) { tags.push('<li class="tag tag--abr">' + ic('feuille') + e(t.vege) + '</li>'); }
    if (l.poids) { tags.push('<li class="tag">' + ic('poids') + Math.round(l.poids) + NBSP + 'g</li>'); }
    var qte = l.prix != null ? (l.qte > 1 ? l.qte + ' × ' + euros(l.prix, lg) + ' · ' + euros(l.prix * l.qte, lg) : euros(l.prix, lg)) : '';
    var cta = '';
    if (l.webshop && d.liens.webshop) {
      var u = d.liens.webshop + (d.liens.webshop.indexOf('?') >= 0 ? '&' : '?') + 'shop=' + d.boutique.id + '&product=' + l.id + '&open=product&lang=' + lg;
      cta = '<div class="prod__cta"><a class="btn" href="' + e(u) + '">' + e(t.recommander) + ' ' + ic('fleche') + '</a></div>';
    }
    return '<article class="prod" id="p-' + l.id + '" aria-roledescription="fiche" aria-label="' + (k + 1) + ' ' + t.sur + ' ' + d.lignes.length + ' : ' + e(l.nom[lg]) + '">'
      + '<header class="prod__head"><span class="th prod__th" role="img" aria-label="' + e(l.nom[lg]) + '" ' + vignette(l) + '><span class="prod__qty">×' + l.qte + '</span></span>'
      + '<div><p class="eyebrow eyebrow--ruby">' + e(l.famille[lg]) + '</p><h3 class="prod__name">' + e(l.nom[lg]) + '</h3><p class="prod__px">' + qte + '</p></div></header>'
      + (tags.length ? '<ul class="tags">' + tags.join('') + '</ul>' : '')
      + blocComposition(l, lg) + blocAllergenes(l, lg) + blocCuisson(l, lg) + blocFinition(l, lg) + blocConservation(l, lg, lim) + cta + '</article>';
  }

  // ---- la page ------------------------------------------------------------------------------------------------------
  function page(d, lg) {
    var t = T[lg]; var b = d.boutique; var L = d.lignes;
    var vente = dateVente(d.transaction.date);
    L.forEach(function (l) { l.nom[lg] = maj(l.nom[lg]); l._lim = l.vieMinutes ? limite(vente, l.vieMinutes, lg) : null; });
    var nArt = L.reduce(function (s, l) { return s + l.qte; }, 0);
    var total = d.transaction.total != null ? d.transaction.total : L.reduce(function (s, l) { return s + (l.prix || 0) * l.qte; }, 0);
    var num = String(d.transaction.id).replace(/\B(?=(\d{3})+(?!\d))/g, NBSP);
    var jour = maj(jourLong(vente, lg)) + ' ' + vente.getFullYear();
    var quand = jour + ' · ' + heure(vente, lg);
    var rows = L.map(function (l) {
      return '<a class="row" href="#p-' + l.id + '"><span class="th" ' + vignette(l) + '></span><span><b>' + e(l.nom[lg]) + '</b><small>' + l.qte + (l.prix != null ? ' × ' + euros(l.prix, lg) : '') + '</small></span>'
        + '<em>' + (l.prix != null ? euros(l.prix * l.qte, lg) : '') + '</em>' + ic('fleche') + '</a>';
    }).join('');
    var presents = ALLERGENES.map(function (a) { return [a[0], L.filter(function (l) { return (l.allergenes || []).indexOf(a[0]) >= 0; })]; }).filter(function (x) { return x[1].length; });
    var codesPresents = presents.map(function (x) { return x[0]; });
    var alg = presents.map(function (x) { return '<div class="alg-r"><span class="chip">' + e(nomAllergene(x[0], lg)) + '</span><p>' + e(x[1].map(function (l) { return l.nom[lg]; }).join(', ')) + '</p></div>'; }).join('');
    var absents = ALLERGENES.filter(function (a) { return codesPresents.indexOf(a[0]) < 0; }).map(function (a) { return nomAllergene(a[0], lg).toLowerCase(); }).sort(function (a, b) { return a.localeCompare(b, lg); });
    var travailles = (d.travailles || []).filter(function (a) { return codesPresents.indexOf(a) < 0; }).map(function (a) { return nomAllergene(a, lg).toLowerCase(); });
    var groupes = []; var vus = {};
    L.filter(function (l) { return l._lim; }).sort(function (a, b) { return a._lim.fin - b._lim.fin; }).forEach(function (l) {
      if (!vus[l._lim.long]) { vus[l._lim.long] = { quand: l._lim.long, ls: [] }; groupes.push(vus[l._lim.long]); }
      vus[l._lim.long].ls.push(l);
    });
    var tl = groupes.map(function (g, k) {
      return '<li class="' + (k === 0 ? 'now' : '') + '"><b>' + e(maj(g.quand)) + '</b><span>' + e(g.ls.map(function (x) { return x.nom[lg] + (x.qte > 1 ? ' × ' + x.qte : ''); }).join(', ')) + '</span></li>';
    }).join('');
    var picks = L.map(function (l, k) {
      return '<button type="button" class="pick__b' + (k === 0 ? ' on' : '') + '" aria-controls="p-' + l.id + '"><span class="th" ' + vignette(l) + '></span>' + e(l.nom[lg]) + (l.qte > 1 ? '<em>×' + l.qte + '</em>' : '') + '</button>';
    }).join('');
    var points = L.map(function (l, k) { return '<i class="' + (k === 0 ? 'on' : '') + '"></i>'; }).join('');
    var fiches = L.map(function (l, k) { return fiche(k, d, l, lg); }).join('');
    var langues = ['fr', 'nl'].map(function (x) { return x === lg ? '<span class="on">' + x.toUpperCase() + '</span>' : '<a href="#" data-lang="' + x + '" hreflang="' + x + '" lang="' + x + '">' + x.toUpperCase() + '</a>'; }).join('');
    var avis = d.liens.avis ? '<section class="abr"><p class="eyebrow eyebrow--ruby">' + e(t.avis) + '</p><h2 class="card__t">' + e(f(t.avisT, { ville: b.ville })) + '</h2><p class="card__p">' + e(t.avisP) + '</p><div class="stars">' + ic('etoile') + ic('etoile') + ic('etoile') + ic('etoile') + ic('etoile') + '</div><a class="btn" href="' + e(d.liens.avis) + '">' + e(t.avisB) + ' ' + ic('fleche') + '</a></section>' : '';
    var app = d.liens.application ? '<section class="card"><p class="eyebrow">' + e(t.app) + '</p><h2 class="card__t">' + e(t.appT) + '</h2><p class="card__p">' + e(t.appP) + '</p><a class="link" href="' + e(d.liens.application) + '">' + e(t.appB) + ' ' + ic('fleche') + '</a></section>' : '';
    var commander = d.liens.webshop ? '<a class="link" href="' + e(d.liens.webshop + (d.liens.webshop.indexOf('?') >= 0 ? '&' : '?') + 'shop=' + b.id + '&lang=' + lg) + '">' + e(t.commander) + ' ' + ic('fleche') + '</a>' : '';
    var shop = '<section class="card shop"><p class="eyebrow">' + e(t.shop) + '</p><h2 class="card__t">' + e(b.nom) + '</h2><dl>'
      + (b.rue || b.ville ? '<dt>' + e(t.adresse) + '</dt><dd>' + e([b.rue, [b.cp, b.ville].filter(Boolean).join(' ')].filter(Boolean).join(', ')) + '</dd>' : '')
      + (b.tel ? '<dt>' + e(t.tel) + '</dt><dd>' + e(b.tel) + '</dd>' : '') + '</dl>' + commander + '</section>';

    document.documentElement.lang = lg;
    document.title = t.titre;
    racine.innerHTML = '<header class="nav wrap"><a class="nav__logo" href="#"><img src="../assets/img/logo.png" alt="L’Atelier By"></a><div class="lang" aria-label="Langue · Taal">' + langues + '</div></header>'
      + '<main><section class="head wrap"><p class="eyebrow eyebrow--ruby">' + e(f(t.surTitre, { ville: b.ville || b.nom })) + '</p><h1>' + e(t.h1) + '<span class="script">' + e(t.h1s) + '</span></h1>'
      + '<p class="head__lede">' + e(t.chapeau) + '</p><div class="meta"><span>' + ic('horloge') + e(quand) + '</span><span>' + e(f(t.articles, { n: nArt })) + ' · ' + euros(total, lg) + '</span></div></section>'
      + '<div class="sand"><div class="wrap top"><section class="card"><p class="eyebrow">' + e(f(t.ticket, { num: num })) + '</p><h2 class="card__t">' + e(f(t.ticketT, { p: L.length, n: nArt })) + '</h2>'
      + '<div class="rows">' + rows + '</div><div class="total"><span>' + e(t.total) + '</span><b>' + euros(total, lg) + '</b></div><p class="fine">' + e(b.nom) + ' · ' + e(quand) + '</p></section>'
      + '<div class="stack"><section class="card"><p class="eyebrow eyebrow--ruby">' + e(t.alg) + '</p><h2 class="card__t">' + e(t.algT) + '</h2><div class="alg-l">' + alg + '</div>'
      + (absents.length ? '<p class="sans">' + f(t.sans, { liste: e(absents.join(', ')) }) + '</p>' : '')
      + (travailles.length ? '<p class="note">' + ic('alerte') + '<span>' + e(f(t.traces, { liste: liste(travailles, lg) })) + '</span></p>' : '') + '</section>'
      + (tl ? '<section class="card"><p class="eyebrow">' + e(t.fraich) + '</p><h2 class="card__t">' + e(t.fraichT) + '</h2><ul class="tl">' + tl + '</ul></section>' : '') + '</div></div>'
      + '<section id="fiches" class="fiches"><div class="wrap chap"><p class="eyebrow">' + e(t.fiches) + '</p><h2 class="h2">' + e(t.h2) + '<span class="script">' + e(t.h2s) + '</span></h2>'
      + '<p class="chap__lede"><span class="mobile">' + e(t.fichesP) + '</span><span class="bureau">' + e(t.fichesPB) + '</span></p></div>'
      + '<nav class="pick" aria-label="' + e(t.barre) + '"><div class="pick__in">' + picks + '</div></nav>'
      + '<div class="ctrl"><p class="ctrl__n"><b>1</b><span> / ' + L.length + '</span></p><div class="ctrl__dots" aria-hidden="true">' + points + '</div>'
      + '<button type="button" class="arr arr--prev" aria-label="' + e(t.prec) + '" disabled>' + ic('gauche') + '</button><button type="button" class="arr arr--next" aria-label="' + e(t.suiv) + '">' + ic('fleche') + '</button></div>'
      + '<div class="track" id="track" tabindex="0" aria-roledescription="carrousel">' + fiches + '</div></section>'
      + '<div class="wrap end">' + avis + app + shop + '</div></div>'
      + '<footer class="foot wrap"><p>' + t.pied + '</p><p>' + e(f(t.pied2, { an: vente.getFullYear() })) + '</p></footer></main>';
    racine.querySelectorAll('.lang a[data-lang]').forEach(function (a) {
      a.addEventListener('click', function (ev) { ev.preventDefault(); var x = a.getAttribute('data-lang'); params.set('lang', x); history.replaceState(null, '', '?' + params.toString()); page(donnees, x); });
    });
    carrousel();
  }

  // ---- le carrousel (repris de la maquette) -------------------------------------------------------------------------
  function carrousel() {
    var track = document.getElementById('track'); if (!track || !track.children.length) { return; }
    var cards = [].slice.call(track.children), picks = [].slice.call(document.querySelectorAll('.pick__b'));
    var dots = [].slice.call(document.querySelectorAll('.ctrl__dots i')), n = document.querySelector('.ctrl__n b');
    var prev = document.querySelector('.arr--prev'), next = document.querySelector('.arr--next'), pickIn = document.querySelector('.pick__in');
    var bureau = window.matchMedia('(min-width:860px)'), i = 0, t = null;
    function haut() { return document.querySelector('.nav').offsetHeight + document.querySelector('.pick').offsetHeight + 8; }
    function marquer(k) {
      i = k;
      picks.forEach(function (b, j) { b.classList.toggle('on', j === k); b.setAttribute('aria-current', j === k ? 'true' : 'false'); });
      dots.forEach(function (d, j) { d.classList.toggle('on', j === k); });
      if (n) { n.textContent = k + 1; }
      if (prev) { prev.disabled = k === 0; } if (next) { next.disabled = k === cards.length - 1; }
      var b = picks[k]; if (b) { pickIn.scrollLeft = b.offsetLeft - (pickIn.clientWidth - b.offsetWidth) / 2; }
      track.style.height = bureau.matches ? '' : cards[k].offsetHeight + 'px';
    }
    function remonter() { var y = track.getBoundingClientRect().top; if (y < haut()) { window.scrollBy({ top: y - haut(), behavior: 'smooth' }); } }
    function aller(k) {
      k = Math.max(0, Math.min(cards.length - 1, k));
      if (bureau.matches) { cards[k].scrollIntoView({ behavior: 'smooth', block: 'start' }); marquer(k); return; }
      track.scrollTo({ left: cards[k].offsetLeft - cards[0].offsetLeft, behavior: 'smooth' });
      marquer(k); remonter();
    }
    track.addEventListener('scroll', function () {
      clearTimeout(t);
      t = setTimeout(function () {
        var pas = cards.length > 1 ? cards[1].offsetLeft - cards[0].offsetLeft : 1;
        var k = Math.round(track.scrollLeft / pas);
        if (k !== i) { marquer(k); remonter(); }
      }, 90);
    }, { passive: true });
    picks.forEach(function (b, j) { b.addEventListener('click', function () { aller(j); }); });
    if (prev) { prev.addEventListener('click', function () { aller(i - 1); }); }
    if (next) { next.addEventListener('click', function () { aller(i + 1); }); }
    [].slice.call(document.querySelectorAll('a.row')).forEach(function (a) {
      a.addEventListener('click', function (ev) {
        var k = cards.findIndex(function (c) { return '#' + c.id === a.getAttribute('href'); }); if (k < 0) { return; }
        ev.preventDefault(); document.getElementById('fiches').scrollIntoView({ behavior: 'smooth' }); setTimeout(function () { aller(k); }, 350);
      });
    });
    track.addEventListener('keydown', function (ev) { if (ev.key === 'ArrowRight') { aller(i + 1); } if (ev.key === 'ArrowLeft') { aller(i - 1); } });
    window.addEventListener('resize', function () { marquer(i); });
    if ('ResizeObserver' in window) { new ResizeObserver(function () { marquer(i); }).observe(track); }
    [].slice.call(document.querySelectorAll('details')).forEach(function (d) { d.addEventListener('toggle', function () { marquer(i); }); });
    window.carrousel = { aller: aller, marquer: marquer };
    marquer(0);
  }

  function etat(titre, texte) { racine.innerHTML = '<header class="nav wrap"><span class="nav__logo"><img src="../assets/img/logo.png" alt="L’Atelier By"></span></header><p class="etat"><b>' + e(titre) + '</b>' + e(texte) + '</p>'; }

  var lgNav = (navigator.language || 'fr').slice(0, 2) === 'nl' ? 'nl' : 'fr';
  etat('', T[lgNav].charge);
  if (!/^\d{1,6}-\d{1,12}-[A-Za-z0-9]{6}$/.test(CODE)) { etat(T[lgNav].introuvable, T[lgNav].introuvableP); return; }
  fetch(API + '/marketing/ticket/client?t=' + encodeURIComponent(CODE) + (params.get('lang') ? '&lang=' + encodeURIComponent(params.get('lang')) : ''), { headers: { Accept: 'application/json' } })
    .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
    .then(function (x) {
      if (!x.ok || !x.j || !x.j.lignes) { etat(T[lgNav].introuvable, T[lgNav].introuvableP); return; }
      donnees = x.j; page(donnees, donnees.langue === 'nl' ? 'nl' : 'fr');
    })
    .catch(function () { etat(T[lgNav].introuvable, T[lgNav].reseau); });
})();
