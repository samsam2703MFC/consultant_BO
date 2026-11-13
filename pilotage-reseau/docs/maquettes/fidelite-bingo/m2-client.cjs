/* Maquette 2 — ce que voit le client (webshop et PWA) : une seule carte, la carte à points avec le bingo dedans ; le
 * passage au comptoir ; les points à échanger ; les bons. Puis un passage en caisse d'un bout à l'autre.
 * Côté client, l'ERP sert : GET /webshop/loyalty/identity, /cards, /points, /history, /program, GET /webshop/vouchers,
 * POST /webshop/loyalty/points/redeem, POST /webshop/vouchers/activation et /prepare. La grille du bingo n'existe pas
 * dans l'ERP : le cockpit la calcule depuis les points de la semaine (GET /shops/{id}/loyalty/customers) et la sert. */
const { eur, ill, api, gap, SEMAINES, SEUIL, grille, page, ecrire } = require('./commun.cjs');

/* Un faux QR (21 × 21, motifs de repérage aux coins) : la vraie app dessine le code 98… de /webshop/loyalty/identity. */
function qr(px) {
  const n = 21, m = [];
  let g = 7;
  for (let y = 0; y < n; y++) { m.push([]); for (let x = 0; x < n; x++) { g = (g * 1103515245 + 12345) & 0x7fffffff; m[y].push((g >> 16) % 3 === 0); } }
  const coin = (cx, cy) => { for (let y = 0; y < 7; y++) { for (let x = 0; x < 7; x++) { m[cy + y][cx + x] = x === 0 || y === 0 || x === 6 || y === 6 || (x > 1 && x < 5 && y > 1 && y < 5); } } };
  coin(0, 0); coin(14, 0); coin(0, 14);
  const r = [];
  m.forEach((l, y) => l.forEach((on, x) => { if (on) { r.push(`<rect x="${x}" y="${y}" width="1" height="1"/>`); } }));
  return `<svg width="${px}" height="${px}" viewBox="-1 -1 ${n + 2} ${n + 2}" shape-rendering="crispEdges"><g fill="#1c1a17">${r.join('')}</g></svg>`;
}
const barre = h => `<div class="bar"><span>${h}</span><span>jeu. 14 janv.</span></div>`;
const pg = (v, max) => `<div style="height:5px;border-radius:3px;background:var(--chip);margin-top:5px;position:relative"><i style="position:absolute;left:0;top:0;bottom:0;width:${Math.min(100, Math.round(100 * v / max))}%;border-radius:3px;background:var(--accent)"></i></div>`;

/* 1 — La carte : les points en tête, la grille du bingo dessous, d'un seul tenant. */
const tel1 = `<div class="tel">${barre('14:58')}<div class="sc">
  <div class="tt">Bonjour Julie <small>Corbais</small></div>
  <div style="border:1.4px solid var(--accent);border-radius:16px;overflow:hidden;background:#fff">
    <div class="pc" style="border-radius:0"><div class="h"><span>Carte L’Atelier</span><span>Hiver · S8 / 13</span></div>
      <div class="n">164 <small>points</small></div>
      <div class="j"><i style="width:${Math.round(100 * 164 / 300)}%"></i><b style="position:absolute;left:50%;top:-3px;bottom:-3px;width:1.5px;background:#fff"></b></div>
      <div class="m">150 atteints : un bon de 5 € à échanger · prochain palier 300 → 11 €</div></div>
    <div style="padding:8px 8px 6px"><div class="btit" style="margin:0 2px 6px"><b style="font-size:17px">BINGO d’hiver</b><span>6 / 13 · une case = ${SEUIL} points</span></div>
      ${grille()}</div></div>
  <div class="blc" style="background:var(--abricot-d);border-color:var(--abricot-d)"><div class="l"><span>Cette semaine</span><span>6 / ${SEUIL} points</span></div>${pg(6, SEUIL)}
    <div style="font-size:10.5px;color:var(--muted-2);margin-top:5px">Jusqu’à 17:00, vos points comptent double : 7 € suffisent pour cocher la case.</div></div>
</div></div>`;

/* 2 — Au comptoir : le code, puis ce que le ticket vient de donner. */
const tel2 = `<div class="tel">${barre('15:11')}<div class="sc">
  <div class="tt">Au comptoir <small>Corbais</small></div>
  <div class="blc" style="text-align:center;padding:14px">${qr(150)}
    <div style="font:12px ui-monospace,Menlo,monospace;letter-spacing:.06em;margin-top:6px">9840 2177 3011 8552</div>
    <div style="font-size:10.5px;color:var(--muted);margin-top:4px">Points et bingo comptent tout seuls.</div></div>
  <div class="cap">À l’instant · ticket de 22,40 €</div>
  <div class="blc" style="padding:12px">
    <div class="l"><span style="font-size:22px;color:var(--accent)">+44 points</span><span class="tag acc">×2</span></div>
    <div style="display:flex;gap:10px;align-items:center;margin-top:10px;padding-top:10px;border-top:1px solid var(--row-line)">
      <div class="bc coch" style="width:58px;min-height:58px;border:1px solid var(--line);border-radius:8px;box-shadow:none"><div class="w">${ill('bread-1')}</div></div>
      <div style="font-size:11.5px">Case S8 « Le bon pain » cochée<div style="color:var(--muted);font-size:10.5px">50 points cette semaine · 7 / 13</div></div></div>
    <div style="font-size:11px;color:var(--muted-2);margin-top:10px">Encore 2 cases pour le Bon de l’hiver, ${eur(19.9)}.</div>${pg(7, 9)}</div>
  <div class="blc"><div class="l"><span>Solde</span><span>208 points</span></div></div>
</div></div>`;

const palier = (pts, v, etat) => `<div class="blc"><div class="l"><span><span style="font-size:14px">${pts}</span> points → bon de ${eur(v)}</span>${etat}</div></div>`;
const tel3 = `<div class="tel">${barre('15:12')}<div class="sc">
  <div class="tt">Mes points <small>208 points</small></div>
  ${palier(150, 5, '<span class="btn p" style="padding:4px 10px;font-size:11px">Échanger</span>')}
  ${palier(300, 11, '<span style="font-size:10.5px;color:var(--muted)">encore 92</span>')}
  ${palier(600, 25, '<span style="font-size:10.5px;color:var(--muted)">encore 392</span>')}
  <div style="font-size:10.5px;color:var(--muted);margin:6px 2px 0">Échanger ne décoche rien : le bingo compte les points gagnés, pas ceux qui restent.</div>
  <div class="cap">Comment j’en gagne</div>
  <div class="blc" style="line-height:1.7">1 point par euro, en boutique et au webshop<br>×2 du mardi au jeudi, de 14:00 à 17:00<br>×2 du lundi au samedi, de 17:30 à 19:00<br>${SEUIL} points dans la semaine : une case du bingo</div>
  <div class="cap">Ce qui a bougé</div>
  <div class="blc" style="padding:4px 12px">${[['jeu. 14/01 · Corbais · ×2', '+44'], ['lun. 11/01 · Corbais', '+6'], ['sam. 09/01 · Gosselies', '+31'], ['mer. 16/12 · bon de 5 €', '−150']].map(([a, b], i) => `<div class="l" style="padding:5px 0;${i ? 'border-top:1px solid var(--row-line)' : ''}"><span>${a}</span><span style="color:${b[0] === '+' ? 'var(--green)' : 'var(--muted)'}">${b}</span></div>`).join('')}</div>
  <div style="font-size:10.5px;color:var(--muted);margin:8px 2px 0">18 points expirent le 28/01 : chaque point vaut un an.</div>
</div></div>`;

const bon = (titre, sous, droite, style) => `<div class="blc" style="${style || ''}"><div class="l"><span><span style="font-size:13px">${titre}</span><br><span style="font-size:10.5px;color:var(--muted)">${sous}</span></span>${droite}</div></div>`;
const tel4 = `<div class="tel">${barre('15:12')}<div class="sc">
  <div class="tt">Mes bons <small>1 à utiliser</small></div>
  ${bon('9,90 € · Bon des Rois', 'bingo · 6e case · jusqu’au 05/02', '<span class="tag acc">en caisse</span>', 'border-color:var(--accent);box-shadow:inset 0 0 0 1px var(--accent)')}
  ${bon('5 € · contre 150 points', 'vos points le permettent', '<span style="font-size:11px;color:var(--accent)">Échanger</span>', 'border-style:dashed')}
  <div class="blc" style="font-size:10.5px;color:var(--muted-2);background:var(--table-head)">Un bon à la fois au comptoir : celui que vous choisissez ici. La caisse le lit en scannant votre carte.</div>
  <div class="cap">Les prochains du bingo</div>
  ${bon('19,90 € · Bon de l’hiver', 'à la 9e case · encore 2', '<span style="font-size:11px;color:var(--muted)">7 / 9</span>')}
  ${bon('29,90 € · Bon carte pleine', 'à la 12e case · encore 5', '<span style="font-size:11px;color:var(--muted)">7 / 12</span>')}
  ${bon('49,90 € · Super bon + tirage', 'S6 manquée : plus possible', '<span style="font-size:10.5px;color:var(--faint)">13 / 13</span>', 'opacity:.6')}
  <div class="cap">Utilisés</div>
  ${bon('4,90 € · Bon de l’Avent', 'bingo · le 17/12 à Corbais', '<span style="font-size:11px;color:var(--faint)">utilisé</span>', 'opacity:.55')}
</div></div>`;

const leg = (titre, texte, routes, note) => `<div style="width:300px"><div style="font-size:14px;margin:14px 2px 4px">${titre}</div>
  <div style="font-size:12px;color:var(--muted-2);margin:0 2px 8px">${texte}</div>
  <div style="display:flex;flex-wrap:wrap;gap:4px">${routes.map(api).join('')}</div>${note ? `<div style="margin-top:8px">${note}</div>` : ''}</div>`;

const colonnes = [
  [tel1, leg('Ma carte', 'Une seule carte : le solde de points et son palier en tête, la grille du bingo dessous, et ce qu’il manque cette semaine — en points, l’unité que le client connaît déjà.',
    ['GET /webshop/loyalty/cards', 'GET /webshop/loyalty/points'], gap('La grille n’est pas dans l’ERP : le cockpit la calcule depuis les points de chaque semaine et la sert à l’app.', 'Servi par le cockpit'))],
  [tel2, leg('Au comptoir', 'Le code à montrer, puis ce que le ticket vient de rapporter : les points, et la case qu’ils ont cochée.',
    ['GET /webshop/loyalty/identity', 'GET /webshop/loyalty/history', 'GET /shops/{id}/loyalty/customers'], '')],
  [tel3, leg('Mes points', 'Les paliers, ce qui manque pour chacun, comment on gagne, l’historique. Le client échange lui-même : l’ERP ne le fait jamais tout seul.',
    ['GET /webshop/loyalty/points', 'POST /webshop/loyalty/points/redeem'], '')],
  [tel4, leg('Mes bons', 'Bons de points et bons du bingo au même endroit, un seul choisi pour la caisse ; les bons à venir donnent la raison de revenir.',
    ['GET /webshop/vouchers', 'POST /webshop/vouchers/activation', 'POST /webshop/vouchers/prepare'], gap('À vérifier sur l’API de test : un code de campagne au nom du client remonte-t-il dans /webshop/vouchers ?', 'À vérifier'))],
];

const etapes = [
  ['1', 'Julie montre sa carte', 'Le code 98… identifie la cliente, rien d’autre.', 'POST /shops/{id}/clients/loyalty-card/resolve'],
  ['2', 'Ticket de 22,40 € à 15:10', '+44 points : 22 tranches, ×2 « Jours creux ».', 'calculé par l’ERP, au ticket'],
  ['3', 'La semaine passe à 50 points', '≥ 20 : S8 cochée, 7 / 13, dès que le cockpit relit la semaine.', 'GET /shops/{id}/loyalty/customers?card_id=12&from=2027-01-11&to=2027-01-14'],
  ['4', 'À la 9e case, le Bon de l’hiver', '19,90 €, valable 30 jours, au nom de Julie.', 'POST /admin/voucher-campaigns/{id}/codes'],
  ['5', 'Elle le choisit pour la caisse', 'La caisse le lit au prochain scan de sa carte.', 'POST /webshop/vouchers/activation'],
];

const corps = `<div style="padding:28px 40px 44px">
<div class="hd"><div><div class="fil">Fidélité › l’app du client <code>webshop · PWA</code></div><h1>Ce que voit le client</h1>
  <p class="intro">Une seule carte : ses points, et le bingo que ses points remplissent semaine après semaine. Julie, jeudi 14 janvier, à Corbais : avant son passage de 15:10, au comptoir, puis dans ses points et ses bons.</p></div>
  <div class="dr"><span class="tag mq">Maquette 2 · le client</span><span class="tag ill">chiffres illustrés · API de test lue sans jeton</span></div></div>
<div style="display:flex;justify-content:space-between;margin-top:8px">${colonnes.map(([t, l]) => `<div>${t}${l}</div>`).join('')}</div>
<div class="g" style="margin-top:30px"><h3>Un passage en caisse, d’un bout à l’autre</h3>
  <div class="liste" style="display:grid;grid-template-columns:repeat(5,minmax(0,1fr))">${etapes.map(([n, t, d, r], i) => `<div style="padding:14px 16px;${i ? 'border-left:1px solid var(--row-line)' : ''}">
    <div style="display:flex;gap:8px;align-items:center"><span style="width:22px;height:22px;border-radius:999px;background:var(--accent);color:#fff;font-size:11px;display:inline-flex;align-items:center;justify-content:center;flex:0 0 auto">${n}</span><span style="font-size:13px">${t}</span></div>
    <div style="font-size:12px;color:var(--muted-2);margin:6px 0 8px">${d}</div><div style="display:flex">${r.startsWith('calculé') ? `<span style="font-size:11px;color:var(--muted)">${r}</span>` : `<span class="api" style="white-space:normal;word-break:break-all">${r}</span>`}</div></div>`).join('')}</div>
  <div class="src" style="margin-top:8px">Les points : l’ERP les compte au ticket. La case et les bons : le cockpit lit les points de la semaine et écrit le bon dans l’ERP — aucune table de son côté.</div></div>
</div>`;

ecrire('m2-client.html', page('Fidélité — maquette 2 · le client', corps, false));
