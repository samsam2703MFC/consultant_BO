/* Maquettes « Commandes : mieux en UX » (24/11/2026) — trois pistes, dessinées depuis ../commandes-tournees/donnees.js :
 *   ?v=a : Aujourd'hui, l'écran qui dit quoi faire maintenant (le prochain départ, sa préparation, la boîte « À faire ») ;
 *   ?v=b : la tournée au téléphone du livreur (les arrêts, livrer, la fin de tournée), suivie en direct au magasin ;
 *   ?v=c : démarrer en deux minutes, les tournées proposées d'après les commandes des 90 derniers jours. */
(() => {
  const D = window.MQ, { eur, min } = D;
  const V = (new URLSearchParams(location.search).get('v') || 'a').toLowerCase();
  const esc = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const P = {
    tdb: 'M3 3h7v7H3zM14 3h7v7h-7zM14 14h7v7h-7zM3 14h7v7H3z',
    commandes: 'M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2 2 2 0 0 0-2-2h-2a2 2 0 0 0-2 2M9 12h6M9 16h4',
    controle: 'M9 11l3 3L22 4M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11',
    journal: 'M12 8v4l3 3M3 12a9 9 0 1 0 9-9 9 9 0 0 0-9 9',
    equipe: 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75',
    cal: 'M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z',
    perf: 'M3 17l6-6 4 4 8-8M15 7h6v6', fonds: 'M3 7h18v12H3zM3 7l3-4h12l3 4M16 13h.5', loupe: 'M3 3v18h18M7 15l4-5 3 3 5-7',
    bundles: 'M21 8l-9-5-9 5 9 5 9-5zM3 8v8l9 5 9-5V8M12 13v8', offres: 'M19 5L5 19M7 7a1.5 1.5 0 1 0 .01 0M17 17a1.5 1.5 0 1 0 .01 0',
    pub: 'M4 4h16v12H4zM2 20h20M9 8l4 2-4 2z', mail: 'M3 5h18v14H3zM3 6l9 7 9-7', ia: 'M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2zM8 9h8M8 13h5',
    camion: 'M1 5h13v11H1zM14 9h4l4 4v3h-8M5.5 18.5a1.8 1.8 0 1 0 0-.01M17.5 18.5a1.8 1.8 0 1 0 0-.01',
    magasin: 'M3 9l1.5-5h15L21 9M3 9h18v11H3zM9 20v-6h6v6', horloge: 'M12 7v5l3 3M3 12a9 9 0 1 0 9-9 9 9 0 0 0-9 9',
    alerte: 'M12 9v4M12 17h.01M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z',
    check: 'M20 6L9 17l-5-5', cloche: 'M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0',
    tel: 'M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2z',
    nav: 'M3 11l19-9-9 19-2-8-8-2z', photo: 'M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2zM12 17a4 4 0 1 0 0-8 4 4 0 0 0 0 8z',
    etoile: 'M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z', plus: 'M12 5v14M5 12h14',
  };
  const ic = (k, cls = 'ic') => `<svg class="${cls}" viewBox="0 0 24 24" aria-hidden="true"><path d="${P[k]}"/></svg>`;
  const canal = k => `<span class="canal"><i class="dot" style="background:${D.CANAUX[k].coul}"></i>${D.CANAUX[k].lib}</span>`;
  const pin = (n, abs) => `<span class="pin${abs ? ' abs' : ''}">${n}</span>`;
  const legende = (titre, L) => `<div class="legende"><h3>${titre}</h3><ol>${L.map((x, i) => `<li>${pin(i + 1)}<span>${x}</span></li>`).join('')}</ol></div>`;

  function rail() {
    const it = (i, l, x = '') => `<div class="sidebar__item${x === 'on' ? ' is-active' : ''}${x === 'ia' ? ' is-ia' : ''}">${ic(i, 'ico')}<span>${l}</span>${x === 'on' ? '<span class="sidebar__count">7</span>' : ''}</div>`;
    return `<nav class="sidebar"><div class="sidebar__head"><img class="sidebar__logo" src="../../../public/tablette/img/logo.png" alt=""><div class="sidebar__kicker">Dashboard magasin</div></div>
      <div class="rcarte"><div class="k">Objectif du jour<em>en direct</em></div><div class="v">54 %<small>de 1 420 €</small></div><div class="barre"><i style="width:54%"></i></div><div class="s">772 € · trait : projection 1 498 €</div></div>
      <div class="sidebar__groups"><div class="sidebar__group">${it('mail', 'Boîte mail')}${it('ia', 'Assistant IA', 'ia')}</div>
      <div class="sidebar__group"><div class="sidebar__group-label">La journée</div>${it('tdb', 'Opérationnel')}${it('commandes', 'Commandes', 'on')}${it('controle', 'Tâches')}${it('journal', 'Temps morts')}${it('equipe', 'Mon équipe')}</div>
      <div class="sidebar__group"><div class="sidebar__group-label">Les chiffres</div>${it('cal', 'Semaine')}${it('cal', 'Mois')}${it('perf', 'Année')}${it('fonds', 'Trésorerie')}</div>
      <div class="sidebar__group"><div class="sidebar__group-label">Vendre</div>${it('loupe', 'Analyse')}${it('bundles', 'Produits')}${it('offres', 'Promotions')}</div>
      <div class="sidebar__group"><div class="sidebar__group-label">Marketing</div>${it('pub', 'Mon marketing')}${it('etoile', 'Fidélité')}</div></div></nav>`;
  }
  /* Les trois onglets par intention, à la place des deux interrupteurs (Jour · Semaine · Mois et Liste · Tournées). */
  function tete(on, droite, sansPin) {
    const o = (k, l, n) => `<span class="${on === k ? 'is-on' : ''}">${l}${n ? ` <em>${n}</em>` : ''}</span>`;
    return `<div class="tete" style="margin-bottom:10px"><div><p class="tete__kicker">${esc(D.MAGASIN.nom)}</p><h1>Commandes</h1></div>${droite || ''}</div>
      <div class="onglets rel">${on === 'auj' && !sansPin ? pin(1, true) : ''}${o('auj', 'Aujourd’hui', 20)}${o('per', 'Semaine et mois')}${o('trn', 'Tournées et bureaux', on === 'trn' ? '0' : 3)}<span class="droite">Mardi 24 novembre · 10:40</span></div>`;
  }

  /* ── A : Aujourd'hui ── */
  function versionA() {
    const t = D.TOURNEES.find(x => x.id === 'midi-cha'), C = D.deTournee(t.id), pretes = C.filter(c => c.statut === 'prete').length;
    const lignes = C.map((c, i) => { const b = D.BUREAUX[c.bureau], ok = c.statut === 'prete';
      return `<tr><td style="width:44px"><span class="rond ${ok ? 'is-pret' : ''}">${i + 1}</span></td>
        <td>${esc(b.nom)}<span class="sub">${c.h} · ${esc(c.quoi.split(' · ')[0])}${c.note ? ' · ' + esc(c.note) : ''}</span></td>
        <td>${canal(c.canal)}</td>
        <td>${ok ? '<span class="chip st-pret">Prête</span>' : c.statut === 'prep' ? '<span class="chip st-prep">En préparation</span>' : '<span class="tag-warn">Pas commencée</span>'}</td>
        <td class="num">${ok ? '<span class="btn btn--sm">Annuler</span>' : `<span class="btn btn--sm">${ic('check')}Marquer prête</span>`}</td>
        <td style="width:90px"><span class="coche${ok && i === 2 ? ' on' : ''}">${ok && i === 2 ? '✓' : ''}</span> <span class="muted" style="font-size:11.5px">chargée</span></td></tr>`; }).join('');
    const sc = D.COMMANDES.find(c => c.sansTournee);
    const tache = (cls, ico, t, d, b, fait) => `<div class="tache${fait ? ' fait' : ''}"><span class="ic-t ${cls}">${ic(ico)}</span><div><div class="t">${t}</div><div class="d">${d}</div>${b ? `<div class="b">${b}</div>` : ''}</div></div>`;
    return tete('auj') + `<div class="a2"><div>
      <div class="card depart rel">${pin(2, true)}<div class="depart__tete"><div><div class="depart__k">Prochain départ</div><div class="depart__nom">${esc(t.nom)}</div>
          <div class="depart__meta">${esc(t.livreur)} · ${esc(t.vehicule)} · ${C.length} arrêts · ${eur(D.somme(C))} · retour ${t.retour}</div>
          <div class="pret-barre">${C.map(c => `<i class="${c.statut === 'prete' ? 'ok' : c.statut === 'prep' ? 'pr' : ''}"></i>`).join('')}</div></div>
        <div class="compte-rebours"><div class="h">${t.depart}</div><div class="s">dans 35 min · ${pretes} sur ${C.length} prêtes</div></div></div>
        <table class="charge"><tbody>${lignes}</tbody></table>
        <div class="depart__pied rel">${pin(3, true)}<span class="btn btn--primary">${ic('camion')}La tournée part</span><span class="btn">Imprimer la feuille de route</span><span class="btn">Envoyer à Nadia</span><span style="margin-left:auto" class="muted">1 encaissement à la livraison : 52,20 €</span></div></div>
      <div class="card ensuite"><h2 class="bloc" style="padding:10px 18px 2px;margin:0">Ensuite</h2>
        <div class="l"><span class="h">11:30</span><span>${ic('camion')} Midi · Jumet – Courcelles <span class="muted">· Thomas D. · 3 arrêts</span></span><span class="tag-warn">3 pas commencées</span><span class="btn btn--sm">Préparer</span></div>
        <div class="l"><span class="h">12:30</span><span>${ic('magasin')} Retraits au magasin <span class="muted">· 6 jusqu’à 17:00</span></span><span class="chip st-pret">1 prête</span><span class="btn btn--sm">Voir</span></div>
        <div class="l"><span class="h">08:35</span><span class="muted">${ic('check')} Matin · Aéropôle, rentrée · 4 sur 4 livrées</span><span class="chip st-ok">Terminée</span><span></span></div></div>
      ${legende('Ce qui change', [
        '<b>Trois onglets par intention</b> — Aujourd’hui, Semaine et mois, Tournées et bureaux — au lieu de deux interrupteurs (Jour · Semaine · Mois et Liste · Tournées) qui se combinent en six écrans.',
        '<b>Le prochain départ en premier</b>, avec son compte à rebours : ce qui est prêt, ce qui ne l’est pas, et le geste pour chaque commande (« Marquer prête » écrit au panel, « chargée » se coche).',
        '<b>« La tournée part »</b> : un geste du magasin, qui fait passer ses arrêts « en route » et ouvre la tournée au téléphone du livreur (version B).',
        '<b>La boîte « À faire »</b> : tout ce qui demande une décision, avec la décision proposée en un clic — placer une livraison, relancer les bureaux avant 16:00 — et ce qui est réglé, barré.',
      ])}</div>
      <div class="card afaire rel">${pin(4, true)}<h2 class="bloc">À faire<small>4 maintenant</small></h2>
        ${tache('', 'alerte', `Une livraison n’est dans aucune tournée`, `n° ${sc.n} · Webshop · ${sc.h} · 6041 Gosselies · ${eur(sc.montant)}. La tournée Midi · Gosselies – Charleroi passe à 600 m.`, '<span class="btn btn--sm btn--primary">Mettre dans Midi · Charleroi</span><span class="btn btn--sm">Choisir</span>')}
        ${tache('', 'horloge', '2 commandes pas prêtes pour 11:15', 'Centre de formation Horizon (18 lunchs), Clinique vétérinaire du Pircha (6 lunchs, en préparation).', '<span class="btn btn--sm">Voir la préparation</span>')}
        ${tache('neutre', 'cloche', 'Demain : 6 bureaux n’ont pas encore commandé', 'Ils commandent d’habitude le mercredi. Commandes jusqu’à 16:00, encore 5 h 20.', '<span class="btn btn--sm">Leur envoyer un rappel</span><span class="btn btn--sm btn--ghost">Voir la liste</span>')}
        ${tache('neutre', 'magasin', 'Hier : 1 précommande pas retirée', 'n° 4489 · Précommande · 19,90 €, payée à la caisse.', '<span class="btn btn--sm">Appeler le client</span><span class="btn btn--sm btn--ghost">Classer</span>')}
        ${tache('ok', 'check', 'Matin · Aéropôle : 4 livraisons sur 4', 'Rentrée à 08:35. Réglé.', '', true)}</div></div>`;
  }

  /* ── B : la tournée au téléphone ── */
  function versionB() {
    const t = D.TOURNEES.find(x => x.id === 'midi-cha'), C = D.deTournee(t.id);
    const tel = (heure, tete, corps, pied) => `<div class="tel"><div class="tel__barre"><span>${heure}</span><span>5G · 82 %</span></div>${tete}<div class="tel__corps">${corps}</div>${pied ? `<div class="tel__pied">${pied}</div>` : ''}</div>`;
    const t1 = tel('11:52', `<div class="tel__tete"><div class="k">Ma tournée · Nadia</div><div class="n">${esc(t.nom)}</div><div class="m">Partie à 11:16 · 1 sur 4 livrée · retour prévu ${t.retour}</div><div class="pret-barre"><i class="ok"></i><i class="pr"></i><i></i><i></i></div></div>`,
      C.map((c, i) => { const b = D.BUREAUX[c.bureau], fait = i === 0, suiv = i === 1;
        return `<div class="stop${fait ? ' fait' : ''}${suiv ? ' suiv' : ''}"><span class="rond${fait ? ' is-fait' : ''}">${fait ? '✓' : i + 1}</span>
          <div><div class="n">${esc(b.nom)}</div><div class="s">${esc(b.lieu)}${fait ? ' · livrée 11:31' : ''}</div></div><span class="h">${c.h}</span>
          ${suiv ? `<div class="stop-actions"><span class="btn">${ic('nav')}Itinéraire</span><span class="btn">${ic('tel')}Appeler</span></div>` : ''}</div>`; }).join(''),
      '<span class="btn btn--primary gros">Arrivée chez Horizon</span>');
    const c2 = C[1], b2 = D.BUREAUX[c2.bureau];
    const t2 = tel('11:58', `<div class="tel__tete"><div class="k">Arrêt 2 sur 4 · ${c2.h}</div><div class="n">${esc(b2.nom)}</div><div class="m">${esc(b2.lieu)} · n° ${c2.n}</div></div>`,
      `<div class="bloc-tel consigne"><div class="k">Consigne</div><div class="v">${esc(b2.contact)} — demander Mme Leroy à l’accueil</div></div>
       <div class="bloc-tel"><div class="k">À remettre</div><div class="ligne-art"><span>Lunchs (sandwichs)</span><span>18</span></div><div class="ligne-art"><span>Boissons</span><span>18</span></div><div class="ligne-art"><span>Couverts et serviettes</span><span>1 sac</span></div></div>
       <div class="bloc-tel"><div class="k">Paiement</div><div class="v">Sur facture à 14 jours · rien à encaisser</div></div>
       <div class="bloc-tel"><div class="k">Preuve (facultatif)</div><div style="display:flex;gap:8px"><span class="btn btn--sm">${ic('photo')}Photo</span><span class="btn btn--sm">Nom de qui reçoit</span></div></div>`,
      `<span class="btn btn--primary gros">${ic('check')}Livré</span><span class="btn gros">Un problème : absent, refus, manque</span>`);
    const t3 = tel('12:41', `<div class="tel__tete"><div class="k">Tournée terminée</div><div class="n">${esc(t.nom)}</div><div class="m">11:16 → 12:41 · 1 h 25</div><div class="pret-barre"><i class="ok"></i><i class="ok"></i><i class="ok"></i><i class="ok"></i></div></div>`,
      `<div class="bloc-tel"><div class="recap-chiffre"><span>Livrés</span><b>4 sur 4</b></div><div class="recap-chiffre"><span>À l’heure</span><span>3 · 1 en retard de 8 min</span></div><div class="recap-chiffre"><span>Problème signalé</span><span>aucun</span></div></div>
       <div class="bloc-tel consigne"><div class="k">À rapporter au magasin</div><div class="v">52,20 € encaissés chez Clinique vétérinaire du Pircha (Bancontact)</div></div>
       <div class="bloc-tel"><div class="k">Bureaux de la tournée sans commande</div><div class="v">Bureau Lefèvre Architectes — 12 jours sans commande</div></div>`,
      '<span class="btn btn--primary gros">De retour au magasin</span>');
    return tete('auj', '<span class="tete__version">Le téléphone du livreur</span>', true) + `<div class="tels">
      <div class="tel-col"><div class="tel-cap"><b>1 · Ma tournée</b>Les arrêts dans l’ordre, le prochain en avant, l’itinéraire et l’appel en un geste</div>${t1}</div>
      <div class="tel-col"><div class="tel-cap"><b>2 · Un arrêt</b>La consigne, ce qu’il faut remettre, le paiement ; « Livré » en grand</div>${t2}</div>
      <div class="tel-col"><div class="tel-cap"><b>3 · Fin de tournée</b>Le bilan, l’argent à rapporter, ce qu’il faut dire au magasin</div>${t3}</div></div>
      <div class="direct">${ic('camion')}<span><b style="font-weight:400">Au magasin, en direct :</b> chaque « Livré » fait passer la commande à « Livrée 11:31 » dans Commandes, sans téléphone ni papier. Le lien s’ouvre sans application (le lien « Envoyer à Nadia »), sur la session du magasin.</span></div>`;
  }

  /* ── C : démarrer en deux minutes ── */
  function versionC() {
    const prop = (k, t, bureaux, stats) => `<div class="card prop"><div class="prop__tete"><div class="prop__k">${k}</div><div class="prop__nom">${esc(t.nom)}</div>
        <div class="prop__meta">${stats}</div></div>
        ${bureaux.map(([id, f, pm, h]) => `<div class="prop__b"><span class="coche on">✓</span><span>${esc(D.BUREAUX[id].nom)}<span class="s">${esc(D.BUREAUX[id].zone)} · ${f} · vers ${h}</span></span><span class="muted">${eur(pm)}</span></div>`).join('')}
        <div class="champs-mini"><label>Départ<span>${t.depart}</span></label><label>Retour<span>${t.retour}</span></label><label>Livreur<span>${t.livreur ? esc(t.livreur) : '—'}</span></label><label>Jours<span>du lundi au vendredi</span></label></div>
        <div class="prop__pied"><span class="muted" style="font-size:12px">${bureaux.length} bureaux</span><span class="btn btn--sm">Modifier</span></div></div>`;
    const T = D.TOURNEES;
    return tete('trn') + `<div class="card" style="display:flex;align-items:center;gap:18px;padding:16px 20px">
        <div style="flex:1"><div style="font-size:17px">Démarrer : vos tournées, proposées d’après vos commandes</div>
          <div class="muted" style="margin-top:4px;font-size:12.5px">13 bureaux ont commandé ces 90 derniers jours (162 fiches pro au panel), aucun n’est encore sur une tournée. Regroupés par zone et par heure habituelle : cochez, ajustez, créez.</div></div>
        <div class="etapes-dem"><span class="on"><span class="rond">1</span>Proposer</span><span>›</span><span><span class="rond">2</span>Ajuster</span><span>›</span><span><span class="rond">3</span>Créer</span></div>
        <span class="btn btn--primary rel">${pin(3, true)}Créer les 3 tournées</span></div>
      <div class="prop-grille"><div class="rel">${pin(1, true)}${prop('Le matin · petits-déjeuners', Object.assign({}, T[0]), [['delvaux', 'chaque jour', 158.30, '07:30'], ['aerotech', 'mar. et ven.', 61.20, '07:45'], ['renard', 'chaque jour', 36.10, '08:00'], ['biolab', '3 × / sem.', 44.80, '08:15']], '38 commandes en 90 jours · vers 07:30 – 08:15<br>Aéropôle, Biopark, Gosselies centre')}</div>
        ${prop('Midi · lunchs', Object.assign({}, T[1]), [['veto', '2 × / sem.', 48.60, '11:30'], ['urbanisme', 'irrégulier', 39.10, '11:45'], ['horizon', 'chaque jour', 142.00, '12:00'], ['hainaut', 'mar. et jeu.', 109.80, '12:10'], ['numerique', 'chaque jour', 76.40, '12:20']], '61 commandes en 90 jours · vers 11:30 – 12:20<br>Gosselies, Jumet, Charleroi')}
        ${prop('Midi · lunchs', Object.assign({}, T[2], { livreur: '' }), [['technisol', 'chaque jour', 64.20, '11:45'], ['mutualis', 'mar. et jeu.', 38.90, '12:00'], ['lambot', 'chaque jour', 88.50, '12:25'], ['dumont', 'irrégulier', 52.00, '12:15']], '44 commandes en 90 jours · vers 11:45 – 12:25<br>Jumet, Courcelles')}
        <div class="card hors rel">${pin(2, true)}<h2 class="bloc">Pas proposés<small>à placer à la main</small></h2>
          <div class="b"><span>Cabinet Lemaire<span class="s">Bruxelles · 2 commandes, retirées au magasin</span></span><span class="btn btn--sm">Ajouter</span></div>
          <div class="b"><span>Brasserie du Centre<span class="s">Nivelles · 1 commande en 90 jours</span></span><span class="btn btn--sm">Ajouter</span></div>
          <div class="b"><span>Mutualité, siège<span class="s">Charleroi · 1 commande, un samedi</span></span><span class="btn btn--sm">Ajouter</span></div>
          <p class="muted" style="font-size:11.5px;margin:10px 0 0;line-height:1.45">Trop loin, trop rares ou qui viennent chercher : ils restent au retrait au magasin tant qu’on ne les place pas.</p></div></div>
      ${legende('Ce qui change', [
        '<b>Rien à saisir pour commencer</b> : le cockpit lit les commandes des clients pro des 90 derniers jours et propose les tournées — la zone (le code postal), l’heure habituelle (petit-déjeuner, midi), le rythme et le panier de chaque bureau.',
        '<b>Les cas douteux à part</b> : trop loin, trop rares, ou qui viennent toujours chercher — ils ne polluent pas les tournées et se placent d’un clic.',
        '<b>Un seul geste</b> pour créer les trois tournées, après avoir décoché ou déplacé un bureau ; ensuite, la page s’ouvre sur Aujourd’hui (version A).',
        '<b>Le même assistant plus tard</b> : un bureau qui commande souvent sans être sur une tournée est proposé dans « À faire » (« Hainaut Assurances commande 2 × / semaine : l’ajouter à Midi · Charleroi ? »).',
      ])}`;
  }

  const corps = V === 'b' ? versionB() : V === 'c' ? versionC() : versionA();
  document.getElementById('app').innerHTML = `<div class="workspace">${rail()}<main class="main">${corps}</main></div>`;
})();
