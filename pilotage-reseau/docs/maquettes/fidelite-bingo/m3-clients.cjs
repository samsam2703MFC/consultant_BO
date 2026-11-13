/* Maquette 3 — Cockpit › ERP franchisé › Fidélité, onglet Clients : la récurrence que le bingo produit, magasin par
 * magasin, lue dans l'ERP. GET /shops/{id}/loyalty/statistics (participants, reconnaissances, points gagnés) et GET
 * /shops/{id}/loyalty/customers (par client : solde et paliers de la carte, points gagnés sur la période, dernier
 * passage), appelés semaine par semaine (from = lundi, to = dimanche) pour la grille ; les bons émis : GET
 * /admin/voucher-campaigns/{id}/codes. Aucune table du cockpit. */
const { nf, ill, api, gap, SEMAINES, SEUIL, page, ecrire } = require('./commun.cjs');

const kpi = (v, l, s, r) => `<div style="padding:12px 16px;border-left:1px solid var(--row-line)" class="kpi"><div style="font-size:11px;color:var(--muted);margin-bottom:4px">${l}</div><b>${v}</b><div style="font-size:11.5px;color:var(--muted-2);margin-top:3px">${s}</div><div style="margin-top:6px">${api(r)}</div></div>`;
const bandeau = `<div class="liste" style="display:grid;grid-template-columns:repeat(5,minmax(0,1fr));margin-bottom:20px">
  ${kpi(nf(1412), 'Membres de la carte', '1 021 venus sur la période', 'participants.point_customers')}
  ${kpi('74 %', 'Jouent au bingo', '1 044 membres ont au moins une case', 'calcul · par semaine')}
  ${kpi('31 %', 'Case cochée cette semaine', 'S8, au jeudi 16:00 : la semaine n’est pas finie', 'calcul · semaine en cours')}
  ${kpi(nf(196800), 'Points gagnés', 'depuis le 23/11 · 9 870 tickets', 'shop_activity.points_earned')}
  ${kpi(nf(4380), 'Cartes reconnues en caisse', '1 012 clients · 3 caisses', 'recognitions')}
</div>`.replace('border-left:1px solid var(--row-line)', 'border-left:none');

/* Semaine après semaine : la part des membres actifs qui ont gagné 20 points dans la semaine, et la même mesure l'hiver
 * passé (la carte à points existait, pas le bingo). */
const CETTE = [44, 49, 41, 43, 58, 22, 47, 31], AVANT = [31, 33, 30, 31, 45, 18, 34, 29];
const graphe = `<div class="card"><h3>Semaine après semaine · part des membres qui cochent <span style="text-transform:none;letter-spacing:0;color:var(--muted)">20 points dans la semaine</span></h3>
  <div style="display:grid;grid-template-columns:repeat(8,minmax(0,1fr));gap:14px;align-items:end;height:190px;padding:0 6px;border-bottom:1px solid var(--line)">${CETTE.map((v, i) => `
    <div style="display:flex;gap:4px;align-items:end;justify-content:center;height:100%">
      <div style="display:flex;flex-direction:column;align-items:center;justify-content:end;height:100%;width:22px"><span style="font-size:10px;color:var(--muted);margin-bottom:2px">${AVANT[i]}</span><i style="display:block;width:100%;height:${AVANT[i] * 2.6}px;background:var(--chart-prev);border-radius:3px 3px 0 0"></i></div>
      <div style="display:flex;flex-direction:column;align-items:center;justify-content:end;height:100%;width:22px"><span style="font-size:11px;color:var(--ink);margin-bottom:2px">${v}</span><i style="display:block;width:100%;height:${v * 2.6}px;background:${i === 7 ? 'repeating-linear-gradient(135deg,var(--chart-real) 0 4px,#d68a98 4px 8px)' : 'var(--chart-real)'};border-radius:3px 3px 0 0"></i></div></div>`).join('')}</div>
  <div style="display:grid;grid-template-columns:repeat(8,minmax(0,1fr));gap:14px;padding:6px 6px 0">${SEMAINES.slice(0, 8).map((s, i) => `<div style="text-align:center"><div style="width:30px;height:30px;margin:0 auto">${ill(s.ill)}</div><div style="font-size:10.5px;color:${i === 7 ? 'var(--accent)' : 'var(--muted-2)'}">S${s.n} · ${s.titre}</div><div style="font-size:10px;color:var(--green)">${i === 7 ? 'en cours' : '+' + (CETTE[i] - AVANT[i])}</div></div>`).join('')}</div>
  <div style="display:flex;gap:16px;font-size:11.5px;color:var(--muted-2);margin-top:10px"><span><i style="display:inline-block;width:10px;height:10px;border-radius:2px;background:var(--chart-real);vertical-align:-1px"></i> Hiver 2026 – 2027, avec le bingo</span><span><i style="display:inline-block;width:10px;height:10px;border-radius:2px;background:var(--chart-prev);vertical-align:-1px"></i> Mêmes semaines l’hiver passé, points seuls</span><span style="margin-left:auto">en % des membres venus dans la saison · <span class="ok">en vert, l’écart</span></span></div>
  <div class="src" style="margin:8px 0 0">Une semaine = un appel par magasin ${api('GET /shops/{id}/loyalty/customers?card_id=12&from=lun&to=dim')} · ${api('programs[].shop_activity.points_earned')} ≥ ${SEUIL}</div></div>`;

/* Où en sont les membres après sept semaines closes (S1 à S7). */
const REP = [368, 172, 160, 152, 141, 133, 115, 171];
const mx = Math.max(...REP);
const repartition = `<div class="card"><h3>Où en sont les membres <span style="text-transform:none;letter-spacing:0;color:var(--muted)">après S7</span></h3>
  ${REP.map((n, k) => `<div style="display:grid;grid-template-columns:84px minmax(0,1fr) 40px;gap:8px;align-items:center;font-size:11.5px;margin:4px 0">
    <span style="color:var(--muted-2)">${k} case${k > 1 ? 's' : ''}${k === 3 || k === 6 ? ' <span class="tag plein" style="font-size:8.5px;padding:0 4px">bon</span>' : ''}</span>
    <i style="display:block;height:12px;border-radius:2px;width:${Math.round(100 * n / mx)}%;background:${k === 0 ? 'var(--chip)' : (k === 7 ? 'var(--accent)' : 'var(--chart-real)')}"></i><span style="text-align:right;font-variant-numeric:tabular-nums">${n}</span></div>`).join('')}
  <div style="font-size:11.5px;color:var(--muted-2);margin-top:8px;line-height:1.6">3 et 6 cases : un bon déjà gagné.<br>7 sur 7 : <span style="color:var(--accent)">171 membres</span> peuvent encore remplir la carte.<br>0 case : 368 membres n’ont pas encore joué.</div></div>`;

const C = [
  ['Julie V.', 208, '150 atteint · à échanger', 7, 50, '14/01 15:10', 'Échanger 150 points'],
  ['Thomas R.', 412, '300 atteint', 8, 63, '14/01 08:12', 'En course pour la carte pleine'],
  ['Marc D.', 88, 'encore 62 pour 150', 8, 24, '13/01 12:40', 'En course pour la carte pleine'],
  ['Sofia L.', 251, '150 atteint · à échanger', 5, 0, '08/01 17:55', 'La case de cette semaine = 9,90 €'],
  ['Claire G.', 196, '150 atteint · à échanger', 4, 22, '13/01 09:05', 'Échanger 150 points'],
  ['Nadia B.', 37, 'encore 113 pour 150', 2, 12, '12/01 16:20', '8 points pour son 1er bon'],
  ['Emma P.', 140, 'encore 10 pour 150', 3, 0, '22/12 11:30', 'Décrochée : 3 semaines sans case'],
  ['Lucas M.', 9, 'encore 141 pour 150', 0, 8, '11/01 07:48', 'Pas encore de case'],
];
const table = `<div class="g"><h3>Les membres · Corbais ${api('GET /shops/4/loyalty/customers?card_id=12')}</h3><div class="liste">
  <div style="display:grid;grid-template-columns:96px 150px 110px 112px 100px minmax(0,1fr);gap:10px;padding:9px 16px;background:var(--table-head);font-size:11px;color:var(--muted)"><span>Client</span><span>Points · palier</span><span>Bingo</span><span>Cette semaine</span><span>Dernier passage</span><span>À faire</span></div>
  ${C.map(([n, p, pal, cases, sem, der, faire]) => `<div style="display:grid;grid-template-columns:96px 150px 110px 112px 100px minmax(0,1fr);gap:10px;padding:9px 16px;border-top:1px solid var(--row-line);font-size:12.5px;align-items:center">
    <span>${n}</span><span>${p}<br><small style="color:var(--muted);font-size:11px">${pal}</small></span>
    <span><span style="display:inline-flex;gap:2px">${Array.from({ length: 8 }, (_, i) => `<i style="width:9px;height:9px;border-radius:2px;background:${i < cases ? 'var(--accent)' : 'var(--chip)'}"></i>`).join('')}</span><br><small style="color:var(--muted);font-size:11px">${cases} / 8</small></span>
    <span class="${sem >= SEUIL ? 'ok' : ''}">${sem} pts${sem >= SEUIL ? ' · cochée' : ''}</span><span style="color:var(--muted-2)">${der}</span><span style="color:var(--muted-2)">${faire}</span></div>`).join('')}
</div><div class="src">Points et paliers : ${api('programs[].global_progress.points {balance, tiers[].reached, remaining}')} · cette semaine : ${api('shop_activity.points_earned')} du 11/01 à aujourd’hui, 4 magasins · dernier passage : ${api('last_transaction_at')} · prénom et initiale seulement : l’API ne donne ni téléphone ni e-mail.</div></div>`;

const rel = (n, t, d) => `<div class="r" style="grid-template-columns:52px minmax(0,1fr) auto"><span style="font-size:18px;font-variant-numeric:tabular-nums">${n}</span><div class="ph" style="line-height:1.45">${t}<small>${d}</small></div><span style="color:var(--accent);font-size:12px">Préparer</span></div>`;
const relances = `<aside><div class="g"><h3>À relancer cette semaine</h3><div class="liste">
  ${rel(214, 'La case de cette semaine leur donne un bon', '2 ou 5 cases, moins de 20 points depuis lundi : « jeudi 14–17 h, vos points comptent double »')}
  ${rel(377, '150 points atteints, pas échangés', 'l’ERP n’échange jamais tout seul : « 5 € vous attendent dans l’app »')}
  ${rel(171, 'Encore en course pour la carte pleine', 'sept cases sur sept : ne pas rater « Le bon pain »')}
  ${rel(96, 'Ont décroché', 'trois cases ou plus, puis deux semaines sans case')}
  ${rel(71, 'Un bon du bingo expire sous 7 jours', 'lu dans les codes des campagnes : leur fin de validité')}
</div><div class="src">« Préparer » ouvre la newsletter ou la notification de l’app avec la liste ; rien ne part sans validation.</div></div>
<div class="card"><h3>Lire l’API sans se tromper</h3><div style="display:grid;gap:8px;font-size:12px;color:var(--muted-2)">
  <div>Le solde et les paliers sont ceux du réseau : ils se répètent dans chaque magasin, le cockpit ne les additionne jamais.</div>
  <div>Les points gagnés et les ventes sont ceux du magasin choisi, sur la période : ceux-là s’additionnent.</div>
  <div>Une carte reconnue n’est ni un scan prouvé ni un achat : une demande de la caisse, répétitions comprises.</div>
  <div>Pas de série par jour : un jour = un appel (from = to). La saison = 4 magasins × 13 semaines d’appels.</div></div></div></aside>`;

const corps = `<div class="hd"><div><div class="fil">ERP franchisé › Fidélité <code>/fidelity</code></div><h1>Fidélité</h1>
  <p class="intro">Ce que la carte et son bingo font à la récurrence : qui coche chaque semaine, où en sont les membres, qui relancer. Tout est lu dans l’ERP, magasin par magasin, et recalculé à l’ouverture.</p></div>
  <div class="dr"><span class="tag mq">Maquette 3 · les clients</span><span class="tag ill">chiffres illustrés · API de test lue sans jeton</span></div></div>
<div class="barre"><div class="seg"><span>Programme</span><span>Bingo</span><span class="on">Clients</span><span>En caisse</span></div>
  <div class="seg p"><span>Réseau</span><span class="on">Corbais</span><span>Gosselies</span><span>Halle</span><span>Sombreffe</span></div>
  <span class="etat">Hiver · S1 → S8 · du 23/11/2026 au jeudi 14/01/2027, 16:00</span></div>
<div class="src" style="margin:-8px 0 12px 2px">${api('GET /shops/4/loyalty/statistics?card_id=12&from=2026-11-23&to=2027-01-14')}</div>
${bandeau}
<div style="display:grid;grid-template-columns:minmax(0,1fr) 330px;gap:20px;align-items:start">${graphe}${repartition}</div>
<div style="display:grid;grid-template-columns:minmax(0,1fr) 330px;gap:20px;align-items:start;margin-top:6px">${table}${relances}</div>`;

ecrire('m3-clients.html', page('Fidélité — maquette 3 · les clients', corps));
