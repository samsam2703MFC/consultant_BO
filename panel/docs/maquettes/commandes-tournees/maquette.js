/* Maquette « Commandes et tournées » : trois versions du même écran (?v=a, ?v=b, ?v=c), dessinées depuis donnees.js. */
(() => {
  const D = window.MQ, { eur, min } = D;
  const V = (new URLSearchParams(location.search).get('v') || 'a').toLowerCase();
  const esc = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const MAINT = min(D.MAINTENANT);

  const ICO = {
    tdb: 'M3 3h7v7H3zM14 3h7v7h-7zM14 14h7v7h-7zM3 14h7v7H3z',
    commandes: 'M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2 2 2 0 0 0-2-2h-2a2 2 0 0 0-2 2M9 12h6M9 16h4',
    controle: 'M9 11l3 3L22 4M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11',
    journal: 'M12 8v4l3 3M3 12a9 9 0 1 0 9-9 9 9 0 0 0-9 9',
    equipe: 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75',
    cal: 'M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z',
    calEd: 'M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zM8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01',
    perf: 'M3 17l6-6 4 4 8-8M15 7h6v6',
    fonds: 'M3 7h18v12H3zM3 7l3-4h12l3 4M16 13h.5',
    loupe: 'M3 3v18h18M7 15l4-5 3 3 5-7',
    bundles: 'M21 8l-9-5-9 5 9 5 9-5zM3 8v8l9 5 9-5V8M12 13v8',
    offres: 'M19 5L5 19M7 7a1.5 1.5 0 1 0 .01 0M17 17a1.5 1.5 0 1 0 .01 0',
    offre: 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M8 13h8M8 17h5',
    analyse: 'M4 20V10M10 20V4M16 20v-7M22 20H2',
    pub: 'M4 4h16v12H4zM2 20h20M9 8l4 2-4 2z',
    reseaux: 'M3 3h18v18H3zM3 9h18M3 15h18M9 3v18M15 3v18',
    etoile: 'M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z',
    brochures: 'M3 5l6-2 6 2 6-2v16l-6 2-6-2-6 2zM9 3v16M15 5v16',
    formation: 'M2 9l10-5 10 5-10 5zM6 11v5c0 1.5 2.7 3 6 3s6-1.5 6-3v-5M22 9v6',
    mail: 'M3 5h18v14H3zM3 6l9 7 9-7',
    ia: 'M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2zM8 9h8M8 13h5',
    camion: 'M1 5h13v11H1zM14 9h4l4 4v3h-8M5.5 18.5a1.8 1.8 0 1 0 0-.01M17.5 18.5a1.8 1.8 0 1 0 0-.01',
    magasin: 'M3 9l1.5-5h15L21 9M3 9h18v11H3zM9 20v-6h6v6',
    horloge: 'M12 7v5l3 3M3 12a9 9 0 1 0 9-9 9 9 0 0 0-9 9',
    personne: 'M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8',
    imprimer: 'M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v8H6z',
    envoyer: 'M22 2L11 13M22 2l-7 20-4-9-9-4z',
    plus: 'M12 5v14M5 12h14',
    liste: 'M8 6h13M8 12h13M8 18h13M3.5 6h.01M3.5 12h.01M3.5 18h.01',
    g: 'M15 18l-6-6 6-6', d: 'M9 18l6-6-6-6',
    bureau: 'M4 21V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v16M16 9h2a2 2 0 0 1 2 2v10M2 21h20M8 7h4M8 11h4M8 15h4',
  };
  const ic = (k, cls = 'ic') => `<svg class="${cls}" viewBox="0 0 24 24" aria-hidden="true"><path d="${ICO[k]}"/></svg>`;

  const canal = k => `<span class="canal"><i class="dot" style="background:${D.CANAUX[k].coul}"></i>${D.CANAUX[k].lib}</span>`;
  const statut = c => {
    const s = D.STATUTS[c.statut];
    return `<span class="chip st-${s.forme}">${s.lib}${c.fait ? ' ' + c.fait : ''}</span>`;
  };
  const nomDe = c => c.bureau ? D.BUREAUX[c.bureau].nom : c.client;
  const tourneeDe = id => D.TOURNEES.find(t => t.id === id);
  const arretDe = c => D.deTournee(c.t).indexOf(c) + 1;
  const fait = c => c.statut === 'livree' || c.statut === 'remise';
  const aPreparer = c => (c.statut === 'prep' || c.statut === 'afaire') && min(c.h) <= 13 * 60;
  const total = D.somme(D.COMMANDES);

  /* ——— Le rail du dashboard franchisé, avec l'entrée « Commandes » ajoutée dans « La journée » ——— */
  function rail() {
    const n = D.COMMANDES.filter(aPreparer).length;
    const item = (ico, lib, extra = '') => `<div class="sidebar__item${extra.includes('on') ? ' is-active' : ''}${extra.includes('ia') ? ' is-ia' : ''}">${ic(ico, 'ico')}<span>${lib}</span>${extra.includes('n') ? `<span class="sidebar__count">${n}</span>` : ''}${extra.includes('boite') ? '<span class="sidebar__count">3</span>' : ''}</div>`;
    const G = [
      ['La journée', [['tdb', 'Opérationnel'], ['commandes', 'Commandes', 'on n'], ['controle', 'Tâches'], ['journal', 'Temps morts'], ['equipe', 'Mon équipe']]],
      ['Les chiffres', [['cal', 'Semaine'], ['calEd', 'Mois'], ['perf', 'Année'], ['fonds', 'Trésorerie']]],
      ['Vendre', [['loupe', 'Analyse'], ['bundles', 'Produits'], ['offres', 'Promotions'], ['offre', 'Offres'], ['analyse', 'Concurrence']]],
      ['Marketing', [['pub', 'Mon marketing'], ['reseaux', 'Réseaux sociaux'], ['etoile', 'Fidélité'], ['brochures', 'Mon catalogue']]],
      ['Se former', [['formation', 'Formations et suivi']]],
    ];
    return `<nav class="sidebar">
      <div class="sidebar__head"><img class="sidebar__logo" src="../../../public/tablette/img/logo.png" alt="L'Atelier By"><div class="sidebar__kicker">Dashboard magasin</div></div>
      <div class="rcarte"><div class="k">Objectif du jour<em>en direct</em></div><div class="v">54 %<small>de 1 420 €</small></div>
        <div class="barre"><i style="width:54%"></i></div><div class="s">772 € · trait : projection 1 498 €</div></div>
      <div class="sidebar__groups">
        <div class="sidebar__group">${item('mail', 'Boîte mail', 'boite')}${item('ia', 'Assistant IA', 'ia')}</div>
        ${G.map(([g, L]) => `<div class="sidebar__group"><div class="sidebar__group-label">${g}</div>${L.map(([i, l, x]) => item(i, l, x || '')).join('')}</div>`).join('')}
      </div></nav>`;
  }

  function tete(droite) {
    return `<div class="tete"><div><p class="tete__kicker">${esc(D.MAGASIN.nom)}</p><h1>Commandes</h1></div>${droite}</div>`;
  }
  /* L'interrupteur abricot (24/11/2026, « A et B on toggle, apricot ») : la même page, deux vues — la liste, les tournées. */
  function bascule(on) {
    return `<div class="bascule" role="tablist" aria-label="Vue des commandes">
      <span role="tab" aria-selected="${on === 'liste'}" class="${on === 'liste' ? 'is-on' : ''}">${ic('liste')}Liste <em>${D.COMMANDES.length}</em></span>
      <span role="tab" aria-selected="${on === 'tournees'}" class="${on === 'tournees' ? 'is-on' : ''}">${ic('camion')}Tournées <em>${D.TOURNEES.length}</em></span></div>`;
  }
  function barreJour(principal = true, seg = '<span class="is-on">Jour</span><span>Semaine</span>') {
    return `<div class="barre-jour"><span class="btn btn--icon btn--sm">${ic('g')}</span><span class="date">${D.JOUR.long}</span><span class="btn btn--icon btn--sm">${ic('d')}</span>
      <span class="ici">Aujourd’hui · ${D.MAINTENANT}</span>
      <div class="pousse"><span class="seg">${seg}</span><span class="btn${principal ? ' btn--primary' : ''}">${ic('plus')}Nouvelle commande</span></div></div>`;
  }
  const chipTournee = c => {
    if (c.sansTournee) { return `<span class="tag-warn">Livraison sans tournée</span> <span class="linklike is-accent">Placer</span>`; }
    if (!c.t) { return `<span class="canal">${ic('magasin')}Retrait au magasin</span>`; }
    const t = tourneeDe(c.t);
    return `<span class="tournee-chip">${ic('camion')}${esc(t.court)} ${t.depart} <span class="no">· arrêt ${arretDe(c)}</span></span>`;
  };
  const etatTournee = t => {
    if (t.etat === 'terminee') { return `<span class="chip st-ok">Terminée ${t.retour}</span>`; }
    const dans = min(t.depart) - MAINT;
    return `<span class="chip ${t.etat === 'prep' ? 'st-prep' : 'st-neutre'}">Départ dans ${dans} min</span>`;
  };
  const rondArret = (c, i) => `<span class="rond ${c.statut === 'livree' ? 'is-fait' : c.statut === 'prete' ? 'is-pret' : ''}" title="${esc(nomDe(c))}">${i + 1}</span>`;

  /* ——— A : la liste du jour, les trois canaux, la tournée de chaque commande ——— */
  function versionA() {
    const L = [...D.COMMANDES].sort((a, b) => min(a.h) - min(b.h) || a.n - b.n);
    const aLivrer = L.filter(c => c.t || c.livrer);
    const kpiCanal = k => {
      const C = D.parCanal(k);
      return `<div class="kpi" style="border-left-color:${D.CANAUX[k].coul}"><div class="kpi__value">${C.length}</div><div class="kpi__label">${D.CANAUX[k].lib}</div><div class="kpi__note">${eur(D.somme(C))}${k === 'webshop' ? ' · payées en ligne' : k === 'bureau' ? ' · sur facture' : ''}</div></div>`;
    };
    let lignes = '', passe = true;
    for (const c of L) {
      if (passe && min(c.h) > MAINT) { lignes += `<tr class="maintenant"><td colspan="6"><div>Maintenant · ${D.MAINTENANT}</div></td></tr>`; passe = false; }
      const b = c.bureau ? D.BUREAUX[c.bureau] : null;
      lignes += `<tr class="${fait(c) ? 'is-passe' : ''}${c.sansTournee ? ' is-alerte' : ''}">
        <td class="num" style="text-align:left">${c.h}</td>
        <td>${canal(c.canal)}<span class="sub">n° ${c.n}</span></td>
        <td>${esc(nomDe(c))}<span class="sub">${esc(c.quoi)}${c.note ? ' · ' + esc(c.note) : ''}</span></td>
        <td>${chipTournee(c)}</td>
        <td class="num">${eur(c.montant)}<span class="sub">${D.PAIEMENTS[c.pay]}</span></td>
        <td>${statut(c)}</td></tr>`;
    }
    lignes += `<tr class="total"><td></td><td colspan="2">${L.length} commandes · ${aLivrer.length} à livrer, ${L.length - aLivrer.length} à retirer</td><td></td><td class="num">${eur(total)}</td><td></td></tr>`;

    const cartes = D.TOURNEES.map(t => {
      const C = D.deTournee(t.id), sans = t.bureaux.filter(k => !C.some(c => c.bureau === k));
      return `<div class="card t-carte"><div class="t-carte__nom">${esc(t.nom)}</div>
        <div class="t-carte__meta" style="display:flex;align-items:center;gap:8px">${etatTournee(t)}<span>${t.depart} → ${t.retour} · ${esc(t.livreur)}</span></div>
        <div class="t-arrets">${C.map(rondArret).join('')}${sans.map(() => '<span class="rond is-vide">–</span>').join('')}
          <span class="compte" style="margin-left:6px">${t.etat === 'terminee' ? 'tout est livré' : `${C.filter(c => c.statut === 'prete').length} prête${C.filter(c => c.statut === 'prete').length > 1 ? 's' : ''} sur ${C.length}`}${sans.length ? ' · ' + sans.length + ' sans commande' : ''}</span></div>
        <div class="t-carte__pied"><span>${C.length} arrêts · ${eur(D.somme(C))}</span><span class="linklike">Feuille de route</span></div></div>`;
    }).join('');

    const pasEncore = Object.entries(D.RYTHME).filter(([, r]) => r.demain === 'non' && r.relance);
    const nOui = Object.values(D.RYTHME).filter(r => r.demain === 'oui').length;
    const demain = `<div class="card demain"><h2 class="bloc">Demain · mer. 25/11</h2>
      <div style="font-size:13px">Commandes jusqu’à ${D.JOUR.coupure} : ${nOui} bureaux ont commandé. <span class="muted">Ceux qui commandent d’habitude, pas encore :</span></div>
      <ul>${pasEncore.map(([k, r]) => `<li><span class="q">${esc(D.BUREAUX[k].nom)}<span class="sub">${r.freq === 'chaque jour' ? 'commande chaque jour' : 'dernière commande ' + r.dern} · tournée ${esc(D.TOURNEES.find(t => t.bureaux.includes(k)).court)}</span></span><span class="btn btn--sm">Relancer</span></li>`).join('')}</ul></div>`;

    return `${tete(bascule('liste'))}${barreJour()}
      <div class="card" style="padding:6px 0 10px"><div class="kpi-row">
        <div class="kpi"><div class="kpi__value">${D.COMMANDES.length}</div><div class="kpi__label">Commandes du jour</div><div class="kpi__note">${eur(total)}</div></div>
        ${kpiCanal('webshop')}${kpiCanal('comptoir')}${kpiCanal('bureau')}
        <div class="kpi"><div class="kpi__value">${D.COMMANDES.filter(aPreparer).length}</div><div class="kpi__label">À préparer avant 13:00</div><div class="kpi__note">1er départ ${D.TOURNEES[1].depart}, dans ${min(D.TOURNEES[1].depart) - MAINT} min</div></div>
      </div>
      <div class="part">${['webshop', 'comptoir', 'bureau'].map(k => `<i style="width:${(100 * D.somme(D.parCanal(k)) / total).toFixed(1)}%;background:${D.CANAUX[k].coul}"></i>`).join('')}</div>
      <div style="display:flex;gap:16px;margin:0 18px;font-size:11.5px;color:var(--muted)">${['webshop', 'comptoir', 'bureau'].map(k => `<span>${D.CANAUX[k].lib} ${Math.round(100 * D.somme(D.parCanal(k)) / total)} %</span>`).join('')}<span style="margin-left:auto">part du montant des commandes</span></div></div>
      <div class="a-grille">
        <div class="card tab-card"><div class="toolbar">
          <span class="seg"><span class="is-on">Toutes <em>${D.COMMANDES.length}</em></span>${['webshop', 'comptoir', 'bureau'].map(k => `<span><i class="dot" style="background:${D.CANAUX[k].coul}"></i>${D.CANAUX[k].lib} <em>${D.parCanal(k).length}</em></span>`).join('')}</span>
          <span class="seg"><span class="is-on">Tout</span><span>À livrer <em>${aLivrer.length}</em></span><span>À retirer <em>${L.length - aLivrer.length}</em></span></span>
          <span class="recherche">Chercher un client, un bureau, un n°</span></div>
          <table><thead><tr><th style="width:58px">Heure</th><th style="width:96px">Canal</th><th>Client ou bureau</th><th style="width:196px">Livraison</th><th class="num" style="width:108px">Montant</th><th style="width:118px">Statut</th></tr></thead>
          <tbody>${lignes}</tbody></table></div>
        <div class="volet"><h2 class="bloc" style="margin:4px 0 -2px">Tournées du jour<small>2 camionnettes</small></h2>${cartes}${demain}</div>
      </div>`;
  }

  /* ——— B : le tableau des tournées — la journée en une frise, une colonne par tournée, une pour le retrait ——— */
  function versionB() {
    const H0 = 6, H1 = 18, x = h => (100 * (min(h) - H0 * 60) / ((H1 - H0) * 60)).toFixed(2) + '%';
    const bloc = t => `<div class="bloc is-${t.etat === 'terminee' ? 'fait' : t.etat}" style="left:${x(t.depart)};width:calc(${x(t.retour)} - ${x(t.depart)})">${esc(t.court)} ${t.depart}</div>`;
    const retraits = D.COMMANDES.filter(c => !c.t && !c.livrer);
    const piste = (lib, inner) => `<div class="frise__ligne"><div class="frise__lib">${lib}</div><div class="frise__piste">${inner}</div></div>`;
    const frise = `<div class="card frise"><h2 class="bloc">La journée<small>06:00 – 18:00 · ce que le magasin doit sortir, et quand</small></h2>
      <div class="frise__axe">${Array.from({ length: 13 }, (_, i) => `<span style="left:${(100 * i / 12).toFixed(2)}%">${String(H0 + i).padStart(2, '0')}:00</span>`).join('')}</div>
      <div class="frise__corps">
        ${piste('Camionnette 1', D.TOURNEES.filter(t => t.vehicule === 'Camionnette 1').map(bloc).join(''))}
        ${piste('Camionnette 2', D.TOURNEES.filter(t => t.vehicule === 'Camionnette 2').map(bloc).join(''))}
        ${piste('Retrait au magasin', retraits.map(c => `<i class="pt${fait(c) ? ' is-passe' : ''}" style="left:${x(c.h)};background:${D.CANAUX[c.canal].coul}" title="${c.h}"></i>`).join(''))}
        ${piste('Sans tournée', D.COMMANDES.filter(c => c.sansTournee).map(c => `<i class="pt" style="left:${x(c.h)};background:${D.CANAUX[c.canal].coul};box-shadow:0 0 0 2px var(--prize)"></i>`).join(''))}
        <div class="frise__now" style="left:calc(150px + (100% - 150px) * ${(MAINT - H0 * 60) / ((H1 - H0) * 60)})"><span>${D.MAINTENANT}</span></div>
      </div>
      <div class="frise__leg">${['webshop', 'comptoir', 'bureau'].map(k => `<span><i class="dot" style="background:${D.CANAUX[k].coul}"></i>${D.CANAUX[k].lib}</span>`).join('')}<span><i class="dot" style="background:var(--green);opacity:.35"></i>Tournée terminée</span><span><i class="dot" style="background:var(--prize)"></i>En préparation</span></div></div>`;

    const sc = D.COMMANDES.find(c => c.sansTournee), tp = tourneeDe(sc.proposee);
    const alerte = `<div class="alerte">${ic('camion')}<div class="txt">Une livraison n’est dans aucune tournée : n° ${sc.n} · ${D.CANAUX[sc.canal].lib} · ${sc.h} · ${esc(sc.client)} · ${eur(sc.montant)}. <span class="muted">${esc(tp.nom)} passe à 600 m, entre les arrêts 2 et 3.</span></div><span class="btn btn--sm">Mettre dans ${esc(tp.nom)}</span></div>`;

    const carteArret = (c, i) => {
      const b = D.BUREAUX[c.bureau];
      return `<div class="arret${fait(c) ? ' is-passe' : ''}"><div class="arret__l1">${rondArret(c, i)}<span class="arret__nom">${esc(b.nom)}</span><span class="arret__h">${c.h}</span></div>
        <div class="arret__lieu">${esc(b.lieu)} · ${esc(b.contact)}</div>
        <div class="arret__l2">${canal(c.canal)}<span class="q">${esc(c.quoi.split(' · ')[0])}</span><span class="m">${eur(c.montant)}</span></div>
        ${c.note ? `<div class="arret__note">${esc(c.note)}</div>` : ''}
        <div class="arret__l3">${statut(c)}<span class="compte">${D.PAIEMENTS[c.pay]}</span></div></div>`;
    };
    const vide = k => { const b = D.BUREAUX[k], r = D.RYTHME[k];
      return `<div class="arret is-vide"><div class="arret__l1"><span class="rond is-vide">–</span><span class="arret__nom muted">${esc(b.nom)}</span></div>
        <div class="arret__lieu">Pas de commande aujourd’hui · dernière ${esc(r.dern)}</div><div class="arret__l3"><span></span><span class="btn btn--sm">Relancer</span></div></div>`; };
    const colonne = t => {
      const C = D.deTournee(t.id), sans = t.bureaux.filter(k => !C.some(c => c.bureau === k));
      return `<div class="card col"><div class="col__tete"><div class="col__nom"><span>${esc(t.nom)}</span>${etatTournee(t)}</div>
        <div class="col__meta">${ic('horloge')} ${t.depart} → ${t.retour} &nbsp;·&nbsp; ${esc(t.livreur)} &nbsp;·&nbsp; ${esc(t.vehicule)}</div>
        <div class="col__prog">${C.map(c => `<i class="is-${c.statut === 'livree' ? 'fait' : c.statut === 'prete' ? 'pret' : c.statut === 'prep' ? 'prep' : 'afaire'}"></i>`).join('')}</div></div>
        <div class="col__corps">${C.map(carteArret).join('')}${sans.map(vide).join('')}</div>
        <div class="col__pied"><span>${C.length} arrêts · ${eur(D.somme(C))}</span><span class="linklike">Feuille de route</span></div></div>`;
    };
    const R = retraits.sort((a, b) => min(a.h) - min(b.h));
    const colRetrait = `<div class="card col"><div class="col__tete"><div class="col__nom"><span>Retrait au magasin</span><span class="chip st-neutre">${R.filter(c => !fait(c)).length} à venir</span></div>
      <div class="col__meta">${ic('magasin')} Rue du Pircha 202 · ${D.MAGASIN.heures}<br>Comptoir ${R.filter(c => c.canal === 'comptoir').length} · Webshop ${R.filter(c => c.canal === 'webshop').length} (click & collect)</div>
      <div class="col__prog">${R.map(c => `<i class="is-${fait(c) ? 'fait' : c.statut === 'prete' ? 'pret' : c.statut === 'prep' ? 'prep' : 'afaire'}"></i>`).join('')}</div></div>
      <div class="col__corps">${R.map(c => `<div class="arret${fait(c) ? ' is-passe' : ''}"><div class="arret__l1">${canal(c.canal)}<span class="arret__h">${c.h}</span></div>
        <div class="arret__nom" style="margin-top:6px">${esc(c.client)}</div><div class="arret__l2" style="margin-left:0"><span>${esc(c.quoi)}</span><span class="m">${eur(c.montant)}</span></div>
        <div class="arret__l3" style="margin-left:0">${statut(c)}<span class="compte">${D.PAIEMENTS[c.pay]}</span></div></div>`).join('')}</div>
      <div class="col__pied"><span>${R.length} commandes · ${eur(D.somme(R))}</span></div></div>`;

    return `${tete(bascule('tournees'))}${barreJour()}${frise}${alerte}
      <div class="colonnes">${D.TOURNEES.map(colonne).join('')}${colRetrait}</div>`;
  }

  /* ——— C : la feuille de route d'une tournée, son plan, et les bureaux qu'elle dessert ——— */
  function versionC() {
    const t = tourneeDe('midi-cha'), C = D.deTournee(t.id), sc = D.COMMANDES.find(c => c.sansTournee);
    const demainDe = tt => { const R = tt.bureaux.map(k => D.RYTHME[k]);
      const o = R.filter(r => r.demain === 'oui').length, n = R.filter(r => r.demain === 'non' && r.habituel !== 'jamais').length, j = R.filter(r => r.habituel === 'jamais').length;
      return `${o} commandé${o > 1 ? 's' : ''}${n ? ` · <span style="color:var(--prize-ink)">${n} pas encore</span>` : ''}${j ? ` · ${j} pas le mercredi` : ''}`; };
    const liste = `<div class="card liste-t"><div class="liste-t__groupe">Aujourd’hui · ${D.JOUR.court}</div>
      ${D.TOURNEES.map(tt => { const CC = D.deTournee(tt.id);
        return `<div class="liste-t__item${tt.id === t.id ? ' is-on' : ''}"><div class="n"><span>${esc(tt.nom)}</span></div><div class="m">${tt.depart} · ${esc(tt.livreur)} · ${CC.length} arrêts · ${eur(D.somme(CC))}</div><div>${etatTournee(tt)}</div></div>`; }).join('')}
      <div class="liste-t__groupe" style="margin-top:6px">Demain · ${D.JOUR.demain}</div>
      ${D.TOURNEES.map(tt => `<div class="liste-t__item"><div class="n"><span>${esc(tt.nom)}</span></div><div class="m">${demainDe(tt)}</div></div>`).join('')}
      <div style="padding:10px 10px 6px"><span class="linklike">${'+'} Nouvelle tournée</span></div></div>`;

    // Le plan schématique : x, y (0–100) → le cadre du dessin.
    const W = 430, Hh = 560, px = x => 24 + x * (W - 48) / 100, py = y => 22 + y * (Hh - 44) / 100;
    const M = D.MAGASIN, pts = [[M.x, M.y], ...C.map(c => [D.BUREAUX[c.bureau].x, D.BUREAUX[c.bureau].y])], der = pts[pts.length - 1];
    const zones = [['Aéropôle', 76, 8], ['Biopark', 90, 36], ['Gosselies', 44, 30], ['Jumet', 30, 78], ['Courcelles', 10, 34], ['Charleroi', 66, 95]];
    const autres = Object.entries(D.BUREAUX).filter(([k]) => !t.bureaux.includes(k));
    const couleurStatut = c => c.statut === 'livree' ? 'var(--green)' : c.statut === 'prete' ? '#1B6FA8' : c.statut === 'prep' ? 'var(--prize)' : 'var(--muted-2)';
    const sb = D.BUREAUX.urbanisme;
    const plan = `<svg viewBox="0 0 ${W} ${Hh}" role="img" aria-label="Plan schématique de la tournée">
      <rect width="${W}" height="${Hh}" fill="#FBF9F5"/>
      <path d="M${px(0)} ${py(46)} C ${px(30)} ${py(40)}, ${px(60)} ${py(52)}, ${px(100)} ${py(40)}" stroke="#ECE6DC" stroke-width="9" fill="none"/>
      <path d="M${px(48)} ${py(0)} C ${px(50)} ${py(30)}, ${px(44)} ${py(60)}, ${px(62)} ${py(100)}" stroke="#ECE6DC" stroke-width="9" fill="none"/>
      <path d="M${px(14)} ${py(100)} C ${px(30)} ${py(70)}, ${px(70)} ${py(64)}, ${px(100)} ${py(78)}" stroke="#F1ECE4" stroke-width="6" fill="none"/>
      ${zones.map(([n, x, y]) => `<text x="${px(x)}" y="${py(y)}" font-size="9.5" letter-spacing="1.4" fill="#B8B2A8" text-anchor="middle" font-family="Gotham">${n.toUpperCase()}</text>`).join('')}
      ${autres.map(([, b]) => `<circle cx="${px(b.x)}" cy="${py(b.y)}" r="4" fill="#DDD6CB"/>`).join('')}
      <polyline points="${pts.map(([x, y]) => px(x) + ',' + py(y)).join(' ')}" fill="none" stroke="#8D1D2C" stroke-width="2" stroke-linejoin="round" opacity="0.75"/>
      <line x1="${px(der[0])}" y1="${py(der[1])}" x2="${px(M.x)}" y2="${py(M.y)}" stroke="#8D1D2C" stroke-width="1.5" stroke-dasharray="2 5" opacity="0.45"/>
      <polyline points="${px(D.BUREAUX.horizon.x)},${py(D.BUREAUX.horizon.y)} ${px(sc.x)},${py(sc.y)} ${px(D.BUREAUX.hainaut.x)},${py(D.BUREAUX.hainaut.y)}" fill="none" stroke="#B0792A" stroke-width="2" stroke-dasharray="4 4"/>
      <circle cx="${px(sb.x)}" cy="${py(sb.y)}" r="9" fill="#fff" stroke="#B8B2A8" stroke-dasharray="3 3"/><text x="${px(sb.x)}" y="${py(sb.y) + 3.5}" font-size="10" fill="#B8B2A8" text-anchor="middle" font-family="Gotham">–</text>
      <circle cx="${px(sc.x)}" cy="${py(sc.y)}" r="10" fill="#FBF3E6" stroke="#B0792A" stroke-width="1.5" stroke-dasharray="3 2"/><text x="${px(sc.x)}" y="${py(sc.y) + 3.5}" font-size="10" fill="#7A5207" text-anchor="middle" font-family="Gotham">+</text>
      ${C.map((c, i) => { const b = D.BUREAUX[c.bureau], col = couleurStatut(c), plein = c.statut === 'livree';
        return `<circle cx="${px(b.x)}" cy="${py(b.y)}" r="11" fill="${plein ? col : '#fff'}" stroke="${col}" stroke-width="2"/><text x="${px(b.x)}" y="${py(b.y) + 4}" font-size="11" fill="${plein ? '#fff' : col}" text-anchor="middle" font-family="Gotham">${i + 1}</text>
          <text x="${px(b.x) + 16}" y="${py(b.y) + 4}" font-size="10" fill="#5c5852" font-family="Gotham">${c.h}</text>`; }).join('')}
      <rect x="${px(M.x) - 12}" y="${py(M.y) - 12}" width="24" height="24" rx="6" fill="#8D1D2C"/>
      <path transform="translate(${px(M.x) - 8} ${py(M.y) - 8}) scale(0.667)" d="${ICO.magasin}" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
      <text x="${px(M.x) - 18}" y="${py(M.y) + 4}" font-size="10" fill="#8D1D2C" text-anchor="end" font-family="Gotham">Magasin</text>
    </svg>`;

    const etape = (c, i) => { const b = D.BUREAUX[c.bureau];
      return `<li class="etape"><div class="etape__h">${c.h}<small>prévu</small></div>${rondArret(c, i)}
        <div><div class="etape__nom">${esc(b.nom)}</div><div class="etape__det">${canal(c.canal)}<span>n° ${c.n}</span><span>${esc(c.quoi.split(' · ')[0])}</span></div>
          <div class="etape__det">${esc(b.lieu)} · ${esc(b.contact)}${c.note ? ` · <span style="color:var(--prize-ink)">${esc(c.note)}</span>` : ''}</div></div>
        <div class="etape__droite"><span>${eur(c.montant)}</span>${statut(c)}<span class="compte">${D.PAIEMENTS[c.pay]}</span></div></li>`; };
    const iSc = C.findIndex(c => c.n === sc.entre[1]);
    const proposee = `<li class="etape is-proposee"><div class="etape__h">${sc.h}<small>proposé</small></div><span class="rond is-vide" style="border-color:var(--prize);color:var(--prize-ink)">+</span>
      <div><div class="etape__nom">${esc(sc.client)} <span class="tag-warn">Dans aucune tournée</span></div><div class="etape__det">${canal(sc.canal)}<span>n° ${sc.n}</span><span>${esc(sc.quoi)}</span></div>
        <div class="etape__det">Sur le chemin entre les arrêts ${iSc} et ${iSc + 1} : + 6 min</div>
        <div class="etape__det"><span class="btn btn--sm">Ajouter à la tournée</span></div></div>
      <div class="etape__droite"><span>${eur(sc.montant)}</span><span class="compte">${D.PAIEMENTS[sc.pay]}</span></div></li>`;
    const pied = (h, lib, det) => `<li class="etape is-${h}"><div class="etape__h">${h === 'depart' ? t.depart : t.retour}<small>${h === 'depart' ? 'départ' : 'retour'}</small></div><span class="rond" style="background:var(--accent);border-color:var(--accent);color:#fff">${ic('magasin')}</span>
      <div><div class="etape__nom">${lib}</div><div class="etape__det">${det}</div></div><div></div></li>`;
    const nPrete = C.filter(c => c.statut === 'prete').length;
    const etapes = [pied('depart', 'Magasin · rue du Pircha 202', `Charger : ${nPrete} prêtes · ${C.filter(c => c.statut === 'prep').length} en préparation · ${C.filter(c => c.statut === 'afaire').length} à préparer`),
      ...C.map((c, i) => (i === iSc ? proposee : '') + etape(c, i)), pied('retour', 'Retour au magasin', '1 encaissement à rapporter : 52,20 €')].join('');

    const jours = ['L', 'M', 'M', 'J', 'V'];
    const lignesBureaux = t.bureaux.map(k => { const b = D.BUREAUX[k], r = D.RYTHME[k], c = C.find(x => x.bureau === k);
      const dem = r.demain === 'oui' ? '<span class="chip st-ok">Commandé</span>' : r.habituel === 'jamais' ? '<span class="muted">Pas le mercredi</span>' : '<span class="tag-warn">Pas encore · avant 16:00</span>';
      return `<tr><td>${esc(b.nom)}<span class="sub">${esc(b.lieu)} · ${b.pers} pers.</span></td>
        <td><span class="sem">${r.sem.map((v, i) => `<i class="${v === 1 ? 'is-oui' : v === 0 ? 'is-non' : 'is-futur'}${i === 1 ? ' is-auj' : ''}">${jours[i]}</i>`).join('')}</span></td>
        <td>${c ? `${eur(c.montant)}<span class="sub">arrêt ${C.indexOf(c) + 1}</span>` : '<span class="muted">Pas de commande</span>'}</td>
        <td>${esc(r.freq)}<span class="sub">panier moyen ${eur(r.panier)}${c ? '' : ' · dernière ' + esc(r.dern)}</span></td>
        <td>${dem}</td>
        <td class="num">${r.relance ? '<span class="btn btn--sm">Relancer</span>' : ''}</td></tr>`; }).join('');

    return `${tete('<span class="tete__version">Version C · la feuille de route</span>')}${barreJour(false, '<span class="is-on">Tournées</span><span>Toutes les commandes</span>')}
      <div class="c-grille">${liste}
        <div>
          <div class="card"><div class="fdr-tete"><div><div class="fdr-tete__nom">${esc(t.nom)}</div>
            <div class="fdr-tete__meta"><span>${ic('horloge')}${t.depart} → ${t.retour}</span><span>${ic('personne')}${esc(t.livreur)}</span><span>${ic('camion')}${esc(t.vehicule)}</span><span>${C.length} arrêts · ${eur(D.somme(C))}</span>${etatTournee(t)}</div></div>
            <div style="display:flex;gap:8px"><span class="btn">${ic('envoyer')}Envoyer à ${esc(t.livreur.split(' ')[0])}</span><span class="btn btn--primary">${ic('imprimer')}Imprimer la feuille de route</span></div></div></div>
          <div class="fdr-grille">
            <div class="card plan">${plan}<div class="plan__leg"><span><i class="dot" style="background:#1B6FA8"></i>Prête</span><span><i class="dot" style="background:var(--prize)"></i>En préparation</span><span><i class="dot" style="border:1.5px solid var(--muted-2);width:9px;height:9px"></i>À préparer</span><span><i class="dot" style="background:#DDD6CB"></i>Bureaux des autres tournées</span><span style="margin-left:auto">plan schématique</span></div></div>
            <div class="card"><h2 class="bloc">Feuille de route<small>dans l’ordre de passage</small></h2><ul class="etapes">${etapes}</ul></div>
          </div>
          <div class="card tab-card" style="margin-top:16px"><div style="padding:16px 16px 8px"><h2 class="bloc" style="margin:0">Les bureaux de la tournée<small>Commandes pour demain jusqu’à ${D.JOUR.coupure} · encore 5 h 20</small></h2></div>
            <table class="c-bureaux"><thead><tr><th style="width:240px">Bureau</th><th>Cette semaine</th><th>Aujourd’hui</th><th>Habitude</th><th>Demain · mer. 25/11</th><th></th></tr></thead><tbody>${lignesBureaux}</tbody></table></div>
        </div></div>`;
  }

  const corps = V === 'b' ? versionB() : V === 'c' ? versionC() : versionA();
  document.getElementById('app').innerHTML = `<div class="workspace">${rail()}<main class="main">${corps}</main></div>`;
})();
