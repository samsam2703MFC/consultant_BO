/* Maquettes : Fidélité › Cartes de fidélité, la carte à points et le bingo, vus pour les particuliers et pour les
 * entreprises (28/11/2026). Sam : « réorganiser la vision des cartes des points et bingo par rapport aux clients et
 * aux firmes, faire une maquette ». Données fictives (donnees.js).
 *   ?v=a&l=p|e        A — le tableau croisé : un résumé carte × public, puis la liste (particuliers ou entreprises)
 *   ?v=b&c=bingo|points  B — la carte d'abord : on choisit la carte, particuliers et entreprises côte à côte
 *   ?v=c&l=p|e        C — le public d'abord : particuliers ou entreprises, chacun avec ses deux cartes */
(() => {
  const D = window.FE, Q = new URLSearchParams(location.search);
  const V = (Q.get('v') || 'a').toLowerCase(), L = Q.get('l') || 'p', C = Q.get('c') || 'bingo';
  const esc = s => String(s == null ? '' : s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const nf = (n, d = 0) => Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d, maximumFractionDigits: d });
  const eur = n => nf(n, 2) + '\u00a0€';
  const pl = (n, a, b) => nf(n) + ' ' + (n > 1 ? b : a);
  const coche = x => x.sem >= D.seuil;
  const presque = x => !coche(x) && x.sem > 0 && D.seuil - x.sem <= 3;
  const bons = pts => Math.floor(pts / D.palier);
  const tranche = pts => Math.min(4, Math.floor(pts / 25));

  /* Les totaux, tirés des lignes. */
  const P = D.particuliers, A = D.autres, E = D.entreprises, G = E.flatMap(e => e.gens);
  const part = { n: P.length + A.n, co: P.filter(coche).length + A.cochees, pr: P.filter(presque).length + A.presque, bons: P.reduce((s, x) => s + bons(x.pts), 0), tr: [0, 0, 0, 0, 0] };
  part.pas = part.n - part.co - part.pr;
  P.forEach(x => part.tr[tranche(x.pts)]++); A.tranches.forEach((v, i) => { part.tr[i] += v; });
  const ent = { soc: E.length, socCarte: E.filter(e => e.soc).length, gens: G.length, pers: E.reduce((s, e) => s + e.pers, 0),
    co: G.filter(coche).length, pr: G.filter(presque).length, socCo: E.filter(e => e.soc && coche(e.soc)).length,
    bonsGens: G.reduce((s, x) => s + bons(x.pts), 0), bonsSoc: E.reduce((s, e) => s + (e.soc ? bons(e.soc.pts) : 0), 0), tr: [0, 0, 0, 0, 0] };
  ent.pas = ent.gens - ent.co - ent.pr; ent.bons = ent.bonsGens + ent.bonsSoc;
  G.forEach(x => ent.tr[tranche(x.pts)]++);
  const tous = part.n + ent.gens, cases = part.co + ent.co + ent.socCo;
  const sansPers = E.filter(e => !e.gens.length);

  /* --- Les pièces ----------------------------------------------------------------------------------------------- */
  const tete = () => `<div class="db-nav db-nav--rail db-nav--seul"><div class="db-vt"><div class="kk">${esc(D.magasin)}</div><h1>Fidélité</h1></div></div>
    <div class="fd-ong"><button type="button">Profils des clients</button><button type="button" class="on">Cartes de fidélité</button></div>`;
  const periode = () => `<span class="fd-tg"><button type="button" class="on">Ce mois</button><button type="button">30 j</button><button type="button">Année</button></span>`;
  const bascule = (items, on, cls) => `<div class="pco-bascule${cls ? ' ' + cls : ''}" role="tablist">${items.map(([k, l, em]) => `<button type="button" class="${k === on ? 'is-on' : ''}">${l}${em ? ` <em>${em}</em>` : ''}</button>`).join('')}</div>`;
  /** Le résumé d'une vue (chrResume du dashboard, sans jauge). */
  const resume = o => `<section class="db-card chr fd-chr-c"><div class="chr-l1"><div class="chr-v"><span class="chr-kv">${o.v}</span></div>
      <div class="chr-q">${o.q}<span>${o.q2}</span></div><div class="chr-g"></div>
      <div class="chr-lev"><span class="chr-lh">${o.lev}</span><span>${o.lev2}</span></div></div>
    <div class="chr-l2"><span class="ac-t">À corriger</span><span class="ac-l">${o.L.map(t => `<button type="button" class="btn btn--sm ac-b"><i class="ac-ic ac-prize"></i>${t}</button>`).join('')}</span></div></section>`;
  const pastille = (couleur, nom, sous) => `<div class="fe-id"><i class="fdc-ic g" style="background:${couleur}"></i><div>${esc(nom)}<small>${esc(sous)}</small></div></div>`;

  /** Le solde de points d'un client : la barre jusqu'au prochain bon. */
  function points(pts, court) {
    const b = bons(pts), r = pts % D.palier;
    const s = b ? `<span class="fdc-pret">${b > 1 ? b + ' bons prêts' : 'bon prêt'}</span>` : `<span class="fe-s">${nf(D.palier - r)} pts du bon de ${D.bonPoints} €</span>`;
    return `<div class="fe-pt"><div class="fe-pt-l"><b>${nf(pts)} pts</b><span class="fe-bar"><i style="width:${b ? 100 : (100 * r / D.palier).toFixed(0)}%"></i></span>${court ? '' : s}</div>${court ? s : ''}</div>`;
  }
  /** La grille de bingo d'un client : 13 cases, la semaine en cours remplie à hauteur de ses achats. */
  function grille(x) {
    const s = D.bingo.sem;
    const cases = Array.from({ length: 13 }, (_, i) => {
      if (i + 1 < s) { return '<i class="ok"></i>'; }
      if (i + 1 > s) { return '<i class="av"></i>'; }
      return coche(x) ? '<i class="ok"></i>' : `<i class="cours" style="--p:${Math.min(100, 100 * x.sem / D.seuil).toFixed(0)}%"></i>`;
    }).join('');
    const t = coche(x) ? `<span class="fe-s ok">case cochée · ${eur(x.sem)}</span>`
      : presque(x) ? `<span class="fe-s att"><i class="ac-ic ac-prize"></i>${eur(x.sem)} · encore ${eur(D.seuil - x.sem)}</span>`
        : x.sem > 0 ? `<span class="fe-s">${eur(x.sem)} sur ${D.seuil} €</span>` : '<span class="fe-s">aucun achat cette semaine</span>';
    return `<div class="fe-bgc"><span class="fe-bg">${cases}</span>${t}</div>`;
  }
  /** La barre de la case de la semaine : cochée · à moins de 3\u00a0€ · pas encore. */
  function barreCase(co, pr, pas, mots) {
    const t = Math.max(1, co + pr + pas), w = n => (100 * n / t).toFixed(1) + '%';
    return `<div class="fe-sg"><i class="co" style="width:${w(co)}"></i><i class="pr" style="width:${w(pr)}"></i><i class="pa" style="width:${w(pas)}"></i></div>
      <div class="fe-lg"><span><i class="co"></i>${nf(co)} ${mots ? mots[0] : 'cochée' + (co > 1 ? 's' : '')}</span><span><i class="pr"></i>${nf(pr)} à moins de 3\u00a0€</span><span><i class="pa"></i>${nf(pas)} pas encore</span></div>`;
  }
  /** Les soldes de points par tranche ; la dernière, ce sont les bons prêts. */
  function histo(tr, blanc) {
    const mx = Math.max(1, ...tr), lab = ['0–24', '25–49', '50–74', '75–99', '100 et +'];
    return `<div class="fe-hi${blanc ? ' w' : ''}">${tr.map((n, i) => `<div><span>${n || ''}</span><i class="${i === 4 ? 'pr' : ''}" style="height:${n ? Math.max(4, 46 * n / mx) : 2}px;opacity:${n ? (0.35 + 0.65 * i / 4).toFixed(2) : 0.15}"></i><small>${lab[i]}</small></div>`).join('')}</div>`;
  }
  /** La saison en 13 petites cases, la semaine en cours avec son nombre de cases cochées. */
  function saisonMini(n) {
    return `<div class="fe-sm">${D.bingo.semaines.map(([d], i) => `<div class="${i + 1 === D.bingo.sem ? 'on' : ''}"><i>${i + 1 === D.bingo.sem ? n : ''}</i><small>${i % 2 ? '' : d}</small></div>`).join('')}</div>`;
  }
  const origine = () => `<p class="fe-s fe-or">Les ${ent.bons} bons : ${ent.bonsSoc} aux comptes société, ${ent.bonsGens} ${ent.bonsGens > 1 ? 'aux personnes' : 'à une personne'}.</p>`;
  const kp = (v, l, cls) => `<div class="fe-kp${cls ? ' ' + cls : ''}"><b>${v}</b><span>${l}</span></div>`;

  /* --- Les listes ------------------------------------------------------------------------------------------------ */
  const ligneClient = (x, sub) => `<tr class="${sub ? 'fe-sub' : ''}"><td>${esc(x.n)}</td><td>${points(x.pts)}</td><td>${grille(x)}</td>
    <td class="n">${x.achats != null ? nf(x.achats) + `<small>${eur(x.montant)}</small>` : ''}</td><td>${esc(x.dernier || '')}</td><td class="n"><span class="db-lien">Sa fiche ›</span></td></tr>`;
  function tableParticuliers() {
    return `<table class="fd-t fe-t"><thead><tr><th>Client</th><th>${pastille(D.carte.couleur, 'Carte à points', '')}</th><th>${pastille(D.bingo.couleur, 'Bingo · semaine ' + D.bingo.sem + ' sur 13', '')}</th><th class="n">Achats</th><th>Dernier achat</th><th></th></tr></thead>
      <tbody>${P.map(x => ligneClient(x)).join('')}<tr class="fe-plus"><td colspan="6">+ ${A.n} autres particuliers · ${A.cochees} ont coché leur case, ${A.presque} à moins de 3\u00a0€</td></tr></tbody></table>`;
  }
  /** Une entreprise : son compte (si la société a une carte) et ses personnes. */
  function socPoints(e) {
    const g = e.gens.reduce((s, x) => s + x.pts, 0), bg = e.gens.reduce((s, x) => s + bons(x.pts), 0);
    return `<div class="fe-dbl">${e.soc ? `<div><span class="fe-q">Société</span>${points(e.soc.pts, true)}</div>` : '<div><span class="fe-q">Société</span><span class="fe-s">pas de carte au nom de la société</span></div>'}
      <div><span class="fe-q">Personnes</span>${e.gens.length ? `<span class="fe-pt-l"><b>${nf(g)} pts</b>${bg ? `<span class="fdc-pret">${pl(bg, 'bon prêt', 'bons prêts')}</span>` : ''}</span>` : '<span class="fe-s">—</span>'}</div></div>`;
  }
  function socBingo(e) {
    const co = e.gens.filter(coche).length, pr = e.gens.filter(presque).length;
    return `<div class="fe-dbl">${e.soc ? `<div><span class="fe-q">Société</span>${grille(e.soc)}</div>` : '<div><span class="fe-q">Société</span><span class="fe-s">—</span></div>'}
      <div><span class="fe-q">Personnes</span>${e.gens.length ? `<span class="fe-s">${nf(co)} sur ${nf(e.gens.length)} ont coché${pr ? ` · <span class="att">${pl(pr, 'à moins de 3\u00a0€', 'à moins de 3\u00a0€')}</span>` : ''}</span>` : '<span class="fe-s">—</span>'}</div></div>`;
  }
  const inscrits = e => `<div class="fe-ins"><span><b>${nf(e.gens.length)}</b> sur ${nf(e.pers)}</span><span class="fe-bar g"><i style="width:${(100 * e.gens.length / e.pers).toFixed(0)}%"></i></span>
    ${e.gens.length ? '' : '<button type="button" class="btn btn--sm">Proposer la carte au bureau</button>'}</div>`;
  const ligneGens = x => `<tr class="fe-sub"><td>${esc(x.n)}</td><td></td><td>${points(x.pts, true)}</td><td>${grille(x)}</td><td></td><td class="n"><span class="db-lien">Sa fiche ›</span></td></tr>`;
  function tableEntreprises(ouverte) {
    return `<table class="fd-t fe-t fe-te"><thead><tr><th>Société et bureau</th><th>Personnes inscrites</th><th>${pastille(D.carte.couleur, 'Carte à points', '')}</th><th>${pastille(D.bingo.couleur, 'Bingo · semaine ' + D.bingo.sem + ' sur 13', '')}</th><th class="n">Achats</th><th></th></tr></thead><tbody>
      ${E.map((e, i) => `<tr class="fe-soc${i === ouverte ? ' on' : ''}"><td><span class="fe-chev">${i === ouverte ? '⌄' : '›'}</span>${esc(e.n)}<small>${esc(e.bur)} · ${esc(e.mode)}</small></td><td>${inscrits(e)}</td><td>${socPoints(e)}</td><td>${socBingo(e)}</td>
        <td class="n">${nf(e.achats)}<small>${eur(e.montant)}</small></td><td class="n"><span class="db-lien">${i === ouverte ? 'Fermer' : 'Ses personnes ›'}</span></td></tr>
        ${i === ouverte ? e.gens.map(ligneGens).join('') : ''}`).join('')}</tbody></table>`;
  }
  function reseau() {
    return `<div class="db-sec">Le réseau<small>même période · particuliers et entreprises séparés</small></div><div class="db-card"><table class="fd-t"><thead><tr><th>Magasin</th><th class="n">Particuliers inscrits</th><th class="n">Entreprises</th><th class="n">Personnes d’entreprise</th><th class="n">Points gagnés</th><th class="n">Cases de bingo cochées</th></tr></thead><tbody>
      ${D.reseau.map(m => `<tr class="${m.moi ? 'moi' : ''}"><td>${esc(m.nom)}</td><td class="n">${nf(m.p)}</td><td class="n">${nf(m.s)}</td><td class="n">${nf(m.g)}</td><td class="n">${nf(m.pts)}</td><td class="n">${nf(m.cases)}</td></tr>`).join('')}</tbody></table></div>`;
  }
  const filtre = (ph, f) => `<div class="fdc-filtre"><input type="search" class="db-sel" placeholder="${ph}"><span class="fd-tg">${f.map((l, i) => `<button type="button" class="${i ? '' : 'on'}">${l}</button>`).join('')}</span></div>`;

  /* Le résumé commun (A et B). */
  const resumeTous = () => resume({ v: nf(tous), q: 'personnes inscrites à une carte',
    q2: `${nf(part.n)} particuliers · ${nf(ent.gens)} dans ${nf(ent.soc)} entreprises (+ ${ent.socCarte} comptes société) · sur ${nf(D.reconnus90)} reconnus en caisse en 90 jours`,
    lev: `${nf(cases)} cases de bingo cochées cette semaine`, lev2: `semaine ${D.bingo.sem} sur 13 · ${D.bingo.reste}`,
    L: [`${pl(part.bons + ent.bons, 'bon de 5 € prêt', 'bons de 5 € prêts')} à offrir (${part.bons} particuliers, ${ent.bons} entreprises)`,
      `${pl(part.pr + ent.pr, 'client', 'clients')} à moins de 3\u00a0€ de leur case, ${D.bingo.reste}`,
      ...sansPers.map(e => `${esc(e.n)} : aucune personne inscrite sur ${e.pers}`)] });

  /* --- A · le tableau croisé ---------------------------------------------------------------------------------------- */
  function vueA() {
    let h = tete() + `<div class="db-sec">Du client reconnu à la récompense<small>${esc(D.magasin)} · lu dans l’ERP à ${D.lu}</small>${periode()}</div>` + resumeTous();
    h += `<div class="db-sec">Les deux cartes, pour les particuliers et pour les entreprises<small>points : soldes actuels, tous magasins · bingo : la semaine du 23 au 29/11</small></div>
    <div class="db-card fe-mx">
      <div class="h"></div><div class="h c">Particuliers<small>${nf(part.n)} inscrits</small></div><div class="h c">Entreprises<small>${nf(ent.soc)} sociétés · ${nf(ent.gens)} personnes inscrites sur ${nf(ent.pers)}</small></div>
      <div class="lab">${pastille(D.carte.couleur, D.carte.nom, D.carte.sous)}<span class="fe-role">fait consommer</span></div>
      <div class="c"><div class="fe-kps">${kp(nf(D.gagnesPart), 'points gagnés ici')}${kp(nf(part.bons), 'bons prêts', 'ok')}${kp(nf(part.tr[3]), 'à moins de 25 pts du bon')}</div>${histo(part.tr)}</div>
      <div class="c"><div class="fe-kps">${kp(nf(D.gagnesEnt), 'points gagnés ici')}${kp(nf(ent.bons), 'bons prêts', 'ok')}</div>${histo(ent.tr)}${origine()}</div>
      <div class="lab">${pastille(D.bingo.couleur, D.bingo.nom, D.bingo.sous)}<span class="fe-role">fait revenir</span></div>
      <div class="c"><div class="fe-kps">${kp(nf(part.co) + '<small> / ' + nf(part.n) + '</small>', 'ont coché la case de la semaine')}</div>${barreCase(part.co, part.pr, part.pas)}${saisonMini(part.co)}</div>
      <div class="c"><div class="fe-kps">${kp(nf(ent.co) + '<small> / ' + nf(ent.gens) + '</small>', 'personnes ont coché')}${kp(ent.socCo + '<small> / ' + ent.socCarte + '</small>', 'comptes société cochés')}</div>${barreCase(ent.co, ent.pr, ent.pas)}${saisonMini(ent.co + ent.socCo)}</div>
    </div>`;
    const b = bascule([['p', 'Particuliers', nf(part.n)], ['e', 'Entreprises', nf(ent.soc) + ' sociétés · ' + nf(ent.gens) + ' personnes']], L);
    h += `<div class="db-sec">Par client<small>${L === 'p' ? 'chaque particulier, sa carte à points et sa grille de bingo' : 'chaque société, son compte et ses personnes · cliquer une société l’ouvre'}</small><span class="fe-sp">${b}</span></div>
      <div class="db-card">${L === 'p' ? filtre('Rechercher un client…', ['Tous', 'Bon prêt', 'Case à cocher', 'Inactifs ici']) + tableParticuliers()
        : filtre('Société ou personne…', ['Toutes', 'Bon prêt', 'Case à cocher', 'Sans personne inscrite']) + tableEntreprises(0)}</div>`;
    return h + reseau();
  }

  /* --- B · la carte d'abord -------------------------------------------------------------------------------------------- */
  function vueB() {
    let h = tete() + `<div class="db-sec">Du client reconnu à la récompense<small>${esc(D.magasin)} · lu dans l’ERP à ${D.lu}</small>${periode()}</div>` + resumeTous();
    h += `<div class="fe-choix">${bascule([['points', 'Carte à points', D.carte.nom], ['bingo', 'Bingo de la saison', 'Hiver 2026 – 2027']], C, 'fe-gr')}<span class="fe-s">${C === 'bingo' ? esc(D.bingo.sous) : esc(D.carte.sous)}</span></div>`;
    if (C === 'bingo') {
      h += `<div class="db-sec">La saison · semaine ${D.bingo.sem} sur 13<small>du ${D.bingo.du} au ${D.bingo.au} · tirage au sort le ${D.bingo.tirage} · ${D.bingo.reste}</small></div>
      <div class="db-card fe-sn">${D.bingo.semaines.map(([d, t], i) => { const on = i + 1 === D.bingo.sem, fut = i + 1 > D.bingo.sem;
        return `<div class="fe-sw${on ? ' on' : ''}${fut ? ' fut' : ''}"><span class="no">S${i + 1} · ${d}</span><span class="tt">${esc(t)}</span>${(i + 1) % 3 === 0 ? `<span class="bn">3<sup>e</sup> case : bon ${D.bonBingo}\u00a0€</span>` : ''}${i === 12 ? `<span class="bn">13 / 13 : ${D.superBon}\u00a0€</span>` : ''}${on ? `<span class="st"><b>${cases}</b>cochées<small>${part.co} part. · ${ent.co + ent.socCo} entr.</small></span>` : '<span class="st av">à venir</span>'}</div>`; }).join('')}</div>`;
      const relance = L2 => L2.length ? `<ul class="fe-rl">${L2.map(x => `<li><span>${esc(x.n)}${x.soc ? `<small>${esc(x.soc)}</small>` : ''}</span><span class="att">encore ${eur(D.seuil - x.sem)}</span></li>`).join('')}</ul>` : '<p class="fe-s">Personne.</p>';
      const noms = L2 => `<div class="fe-nm">${L2.map(x => `<span>${esc(x.n)}</span>`).join('')}</div>`;
      const gensSoc = E.flatMap(e => e.gens.map(x => ({ ...x, soc: e.n })));
      h += `<div class="fe-2">
        <div class="db-card"><div class="ct">Particuliers<span class="db-mini">${nf(part.n)} inscrits</span></div><div class="fe-bd">
          <div class="fe-kps">${kp(nf(part.co) + '<small> / ' + nf(part.n) + '</small>', 'ont coché la case de la semaine')}</div>${barreCase(part.co, part.pr, part.pas)}
          <div class="lab">À relancer avant dimanche</div>${relance(P.filter(presque))}
          <div class="lab">Ont coché</div>${noms(P.filter(coche))}<p class="fe-s">+ ${A.cochees} autres</p>
          <div class="lab">Pas encore d’achat cette semaine</div>${noms(P.filter(x => !x.sem))}<p class="fe-s">+ ${A.pas} autres</p></div></div>
        <div class="db-card"><div class="ct">Entreprises<span class="db-mini">${nf(ent.soc)} sociétés · ${nf(ent.gens)} personnes inscrites</span></div><div class="fe-bd">
          <div class="fe-kps">${kp(nf(ent.co) + '<small> / ' + nf(ent.gens) + '</small>', 'personnes ont coché')}${kp(ent.socCo + '<small> / ' + ent.socCarte + '</small>', 'comptes société cochés')}</div>${barreCase(ent.co, ent.pr, ent.pas)}
          <div class="lab">Société par société</div>
          <div class="fe-ls">${E.map(e => { const co = e.gens.filter(coche).length;
            return `<div class="fe-lr"><div>${esc(e.n)}<small>${esc(e.mode)}</small></div><div>${e.soc ? grille(e.soc) : '<span class="fe-s">pas de carte société</span>'}</div>
              <div>${e.gens.length ? `<span class="fe-bar g w"><i style="width:${(100 * co / e.gens.length).toFixed(0)}%"></i></span><span class="fe-s">${co} sur ${e.gens.length} personnes</span>` : `<span class="fe-s">aucune personne inscrite sur ${e.pers}</span>`}</div></div>`; }).join('')}</div>
          <div class="lab">À relancer avant dimanche</div>${relance(gensSoc.filter(presque))}</div></div></div>`;
    } else {
      const proches = L2 => `<ul class="fe-rl">${L2.map(x => `<li><span>${esc(x.n)}${x.soc ? `<small>${esc(x.soc)}</small>` : ''}</span><span class="${bons(x.pts) ? 'ok' : 'att'}">${bons(x.pts) ? 'bon de 5 € à offrir' : nf(D.palier - x.pts % D.palier) + ' pts du bon'}</span></li>`).join('')}</ul>`;
      const gensSoc = E.flatMap(e => e.gens.map(x => ({ ...x, soc: e.n })));
      h += `<div class="db-sec">${esc(D.carte.nom)}<small>soldes actuels, tous magasins · points gagnés ici ce mois</small></div><div class="fe-2">
        <div class="db-card"><div class="ct">Particuliers<span class="db-mini">${nf(part.n)} inscrits</span></div><div class="fe-bd">
          <div class="fe-kps">${kp(nf(D.gagnesPart), 'points gagnés ici')}${kp(nf(part.bons), 'bons prêts', 'ok')}${kp(nf(part.tr[3]), 'à moins de 25 pts du bon')}</div>
          <div class="lab">Les soldes, en points</div>${histo(part.tr)}
          <div class="lab">Bons prêts et proches du bon</div>${proches(P.filter(x => x.pts >= 75))}</div></div>
        <div class="db-card"><div class="ct">Entreprises<span class="db-mini">${nf(ent.soc)} sociétés · ${nf(ent.gens)} personnes inscrites</span></div><div class="fe-bd">
          <div class="fe-kps">${kp(nf(D.gagnesEnt), 'points gagnés ici')}${kp(nf(ent.bons), 'bons prêts', 'ok')}</div>${origine()}
          <div class="lab">Société par société</div>
          <div class="fe-ls">${E.map(e => `<div class="fe-lr p"><div>${esc(e.n)}<small>${esc(e.mode)}</small></div><div>${socPoints(e)}</div></div>`).join('')}</div>
          <div class="lab">Personnes : bons prêts et proches du bon</div>${proches(gensSoc.filter(x => x.pts >= 75))}</div></div></div>`;
    }
    return h + reseau();
  }

  /* --- C · le public d'abord ------------------------------------------------------------------------------------------- */
  function vueC() {
    let h = tete() + `<div class="fe-choix">${bascule([['p', 'Particuliers', nf(part.n) + ' inscrits'], ['e', 'Entreprises', nf(ent.soc) + ' sociétés · ' + nf(ent.gens) + ' personnes']], L, 'fe-gr')}${periode()}</div>`;
    if (L === 'p') {
      h += `<div class="db-sec">Les particuliers, du client reconnu à la récompense<small>${esc(D.magasin)} · lu dans l’ERP à ${D.lu}</small></div>`;
      h += resume({ v: nf(part.n), q: 'particuliers inscrits à une carte', q2: `sur ${nf(D.reconnus90)} clients reconnus en caisse ces 90 jours · ${nf(D.reconnus90 - tous)} à inscrire`,
        lev: `${nf(part.co)} ont coché leur case cette semaine`, lev2: `bingo, semaine ${D.bingo.sem} sur 13 · ${D.bingo.reste}`,
        L: [`${pl(part.bons, 'bon de 5 € prêt', 'bons de 5 € prêts')} à offrir en caisse`, `${pl(part.pr, 'particulier', 'particuliers')} à moins de 3\u00a0€ de leur case`] });
      h += `<div class="db-sec">Leurs deux cartes<small>points : soldes actuels, tous magasins · bingo : la semaine du 23 au 29/11</small></div><div class="fe-2">
        <div class="fe-cv" style="background:${D.carte.couleur}"><div class="nm">${esc(D.carte.nom)}<span>fait consommer</span></div><div class="rc">${esc(D.carte.sous)}</div>
          <div class="fe-cv-k"><div><b>${nf(D.gagnesPart)}</b>points gagnés ici</div><div><b>${part.bons}</b>bons prêts</div><div><b>${part.tr[3]}</b>à moins de 25 pts du bon</div></div>${histo(part.tr, true)}</div>
        <div class="fe-cv" style="background:${D.bingo.couleur}"><div class="nm">${esc(D.bingo.nom)}<span>fait revenir</span></div><div class="rc">${esc(D.bingo.sous)}</div>
          <div class="fe-cv-k"><div><b>${part.co}<small> / ${part.n}</small></b>ont coché la semaine ${D.bingo.sem}</div><div><b>${part.pr}</b>à moins de 3\u00a0€</div><div><b>${part.pas}</b>pas encore</div></div>
          <div class="fe-cv-g">${D.bingo.semaines.map(([d, t], i) => `<i class="${i + 1 === D.bingo.sem ? 'on' : ''}" title="${esc(t)}"><b>${i + 1 === D.bingo.sem ? part.co : ''}</b><small>${d}</small></i>`).join('')}</div></div></div>`;
      h += `<div class="db-sec">Par client<small>${nf(part.n)} inscrits · sa carte à points et sa grille de bingo</small></div><div class="db-card">${filtre('Rechercher un client…', ['Tous', 'Bon prêt', 'Case à cocher', 'Inactifs ici'])}${tableParticuliers()}</div>`;
    } else {
      h += `<div class="db-sec">Les entreprises, leurs comptes et leurs personnes<small>${esc(D.magasin)} · lu dans l’ERP à ${D.lu}</small></div>`;
      h += resume({ v: nf(ent.soc), q: 'entreprises à la carte', q2: `${nf(ent.gens)} personnes inscrites sur ${nf(ent.pers)} dans leurs bureaux · ${ent.socCarte} sociétés ont une carte à leur nom`,
        lev: `${nf(ent.co + ent.socCo)} cases cochées cette semaine`, lev2: `${ent.co} personnes et ${ent.socCo} comptes société · ${D.bingo.reste}`,
        L: [`${pl(ent.bons, 'bon de 5 € prêt', 'bons de 5 € prêts')} (${ent.bonsSoc} aux comptes société, ${ent.bonsGens} ${ent.bonsGens > 1 ? 'aux personnes' : 'à une personne'})`, `${pl(ent.pr, 'personne', 'personnes')} à moins de 3\u00a0€ de leur case`,
          ...sansPers.map(e => `${esc(e.n)} : aucune personne inscrite sur ${e.pers}`)] });
      h += `<div class="db-sec">Société par société<small>en haut le compte de la société, en bas ses personnes · cliquer une société ouvre ses personnes</small>${filtre('Rechercher une société…', ['Toutes', 'Bon prêt', 'Sans personne inscrite']).replace('fdc-filtre', 'fdc-filtre fe-fl')}</div><div class="fe-tl">
        ${E.map(e => { const co = e.gens.filter(coche).length, pr = e.gens.filter(presque).length, g = e.gens.reduce((s, x) => s + x.pts, 0), bg = e.gens.reduce((s, x) => s + bons(x.pts), 0);
          return `<div class="db-card fe-ti"><div class="fe-ti-h"><div>${esc(e.n)}<small>${esc(e.bur)} · ${esc(e.mode)}</small></div><div class="r">${nf(e.achats)} achats<small>${eur(e.montant)} ce mois</small></div></div>
            <div class="fe-ti-b"><div class="lab">Le compte de la société</div>${e.soc ? `<div class="fe-ti-2"><div>${points(e.soc.pts, true)}</div><div>${grille(e.soc)}</div></div>` : '<p class="fe-s">Pas de carte au nom de la société : chaque personne paie et gagne sur sa propre carte.</p>'}</div>
            <div class="fe-ti-b"><div class="lab">Ses personnes · ${e.gens.length} inscrite${e.gens.length > 1 ? 's' : ''} sur ${e.pers}</div>
              ${e.gens.length ? `<div class="fe-ti-2"><div><span class="fe-pt-l"><b>${nf(g)} pts</b>${bg ? `<span class="fdc-pret">${pl(bg, 'bon prêt', 'bons prêts')}</span>` : '<span class="fe-s">aucun bon prêt</span>'}</span><span class="fe-bar g w"><i style="width:${(100 * e.gens.length / e.pers).toFixed(0)}%"></i></span><span class="fe-s">${Math.round(100 * e.gens.length / e.pers)} % du bureau inscrit</span></div>
                <div>${barreCase(co, pr, e.gens.length - co - pr)}</div></div>
                <div class="fe-nm d">${e.gens.map(x => `<span class="${coche(x) ? 'co' : presque(x) ? 'pr' : ''}">${esc(x.n)}</span>`).join('')}</div>`
                : '<p class="fe-s">Aucune personne du bureau n’a la carte.</p>'}</div>
            <div class="fe-ti-f">${e.gens.length < e.pers ? '<button type="button" class="btn btn--sm">Proposer la carte au bureau</button>' : ''}<span class="db-lien">Ses personnes ›</span></div></div>`; }).join('')}</div>`;
    }
    return h + reseau();
  }

  document.getElementById('dash').innerHTML = V === 'b' ? vueB() : V === 'c' ? vueC() : vueA();
})();
