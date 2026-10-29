/* Repost automatique — trois maquettes pour le module Marketing (/marketing/),
 * menu « Réseaux sociaux » › « Repost automatique » :
 *   A — le réglage (le bloc validé le 19/10) et le fil de ce qu'est devenu chaque post ;
 *   B — le tableau posts × boutiques, avec les chiffres et les relances ;
 *   C — plusieurs règles (nouveautés, offres d'opération, recyclage) et la semaine à venir.
 *
 * ILLUSTRÉ, et dit sur chaque écran : les posts de la marque, les heures et les
 * statuts. Les boutiques, leurs Pages et leurs langues sont celles du module
 * Social (social/public/social-data.js) ; le jeton Instagram expiré de Gosselies
 * aussi. Rien n'est lu chez Meta : le serveur n'est pas joignable d'ici.
 *
 *   node docs/maquettes/repost-automatique/generer.js
 */
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');
const OUT = __dirname + '/';
const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

/* --- Les données -------------------------------------------------------------- */
const AUJ = 'jeudi 29 octobre 2026, 10:40';
const SHOPS = [
  { id: 'halle', name: 'Halle', ini: 'HA', langs: 'NL + FR', ig: 'ok', regle: 'auto' },
  { id: 'corbais', name: 'Corbais', ini: 'CO', langs: 'FR', ig: 'ok', regle: 'valide' },
  { id: 'gosselies', name: 'Gosselies', ini: 'GO', langs: 'FR', ig: 'expire', regle: 'auto' },
  { id: 'sombreffe', name: 'Sombreffe', ini: 'SO', langs: 'FR', ig: 'ok', regle: 'valide' },
  { id: 'gembloux', name: 'Gembloux', ini: 'GE', langs: 'FR', ig: 'ok', regle: 'valide' },
  { id: 'wavre', name: 'Wavre', ini: 'WA', langs: 'FR', ig: 'ok', regle: 'auto' },
];
// Statuts : ok (publié), val (à valider par le franchisé), ref (refusé), err (échec chez Meta), excl (exclu par la règle).
const ok = (h, d) => ['ok', h, d || ''];
const POSTS = [
  { id: 'p1', quand: 'je 29/10 · 10:00', type: 'Reel', reseaux: ['fb', 'ig'], img: 'brioche-croustillante', tuile: '#F2C9A0',
    titre: 'Brioche croustillante', texte: 'La nouveauté d’automne arrive en boutique ce week-end : brioche croustillante, sucre perlé et beurre noisette. #nouveauté',
    regle: 'nv', s: { halle: ok('10:30', 'version NL'), corbais: ok('10:12', 'validé'), gosselies: ['err', 'IG', 'Facebook publié 10:30 · Instagram en échec : jeton expiré le 27/10'], sombreffe: ['val', '14:00', 'proposé à 10:00 · publié à 14:00 sans réponse'], gembloux: ok('10:21', 'validé'), wavre: ok('10:30') } },
  { id: 'p2', quand: 'me 28/10 · 17:30', type: 'Photo', reseaux: ['fb', 'ig'], img: 'sweet-tart-big', tuile: '#EBD3B8',
    titre: 'Tarte aux pommes du week-end', texte: 'Notre tarte aux pommes du week-end : à commander jusqu’à vendredi midi, en boutique ou sur le webshop. #gamme',
    regle: 'op', op: 'Gamme Automne', s: { halle: ok('18:00', 'version NL'), corbais: ok('21:30', 'sans réponse en 4 h'), gosselies: ['err', 'IG', 'Facebook publié 18:00 · Instagram en échec : jeton expiré'], sombreffe: ['ref', 'refusé', '« Plus de tartes aux pommes ce week-end, four en panne. » — 28/10 18:04'], gembloux: ok('17:52', 'validé'), wavre: ok('18:00') } },
  { id: 'p3', quand: 'ma 27/10 · 09:00', type: 'Carrousel', reseaux: ['fb', 'ig'], img: 'pumpkin', tuile: '#E9C79A',
    titre: 'Gamme Automne : cinq incontournables', texte: 'Cinq incontournables de la Gamme Automne, de la courge rôtie à la tartelette poire-amande. #gamme',
    regle: 'nv', s: { halle: ok('09:30', 'version NL'), corbais: ok('09:41', 'validé'), gosselies: ['err', 'IG', 'Facebook publié 09:30 · Instagram en échec : jeton expiré'], sombreffe: ok('11:12', 'validé'), gembloux: ok('13:00', 'sans réponse en 4 h'), wavre: ok('09:30') } },
  { id: 'p4', quand: 'lu 26/10 · 12:00', type: 'Photo', reseaux: ['fb'], img: 'bread-1', tuile: '#D9CFC3',
    titre: 'L’atelier recrute', texte: 'Notre atelier de production recrute un·e boulanger·ère de nuit (CDI, Gosselies). Envoyez votre CV !',
    regle: null, s: Object.fromEntries(SHOPS.map(x => [x.id, ['excl', 'exclu', 'contient « recrute » : jamais reposté']])) },
  { id: 'p5', quand: 've 23/10 · 08:00', type: 'Photo', reseaux: ['fb', 'ig'], img: 'croissant', tuile: '#F6DDBF',
    titre: 'Croissant pur beurre', texte: 'Croissant pur beurre, cuit trois fois par jour. 1,30 € — le prix du matin, toute la journée. #nouveauté',
    regle: 'nv', s: { halle: ok('08:30', 'version NL'), corbais: ok('08:44', 'validé'), gosselies: ok('08:30'), sombreffe: ok('09:02', 'validé'), gembloux: ok('08:37', 'validé'), wavre: ok('08:30') } },
  { id: 'p6', quand: 'je 22/10 · 18:00', type: 'Photo', reseaux: ['fb', 'ig'], img: 'cookies', tuile: '#F0C08C',
    titre: 'Biscuits d’Halloween', texte: 'Halloween approche : les biscuits citrouille sont arrivés dans toutes nos boutiques. #nouveauté',
    regle: 'nv', s: { halle: ok('18:30', 'version NL'), corbais: ok('18:05', 'validé'), gosselies: ok('18:30'), sombreffe: ok('19:40', 'validé'), gembloux: ok('18:16', 'validé'), wavre: ok('18:30') } },
  { id: 'p7', quand: 'ma 20/10 · 10:00', type: 'Vidéo', reseaux: ['fb'], img: 'sandwiches', tuile: '#E3D9CC',
    titre: 'Pain au levain, 48 h', texte: 'Pain au levain : 48 heures de fermentation lente, et ça se goûte. Tous les jours dès 7 h. #gamme',
    regle: 'nv', s: { halle: ok('10:30', 'version NL'), corbais: ok('10:52', 'validé'), gosselies: ok('10:30'), sombreffe: ok('14:00', 'sans réponse en 4 h'), gembloux: ok('10:18', 'validé'), wavre: ok('10:30') } },
];
const LIB = { ok: 'Publié', val: 'À valider', ref: 'Refusé', err: 'Échec', excl: 'Exclu' };
const ICO = { ok: '✓', val: '⏳', ref: '✗', err: '⚠', excl: '—' };
const compte = k => POSTS.reduce((a, p) => a + SHOPS.filter(x => p.s[x.id][0] === k).length, 0);
const N = { ok: compte('ok'), val: compte('val'), ref: compte('ref'), err: compte('err'), excl: compte('excl') };
const attendus = N.ok + N.val + N.ref + N.err;

/* --- Morceaux communs --------------------------------------------------------- */
const icon = d => `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="${d}"/></svg>`;
const I = {
  dashboard: 'M3 3h7v7H3zM14 3h7v7h-7zM14 14h7v7h-7zM3 14h7v7H3z',
  calendar: 'M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z',
  campaigns: 'M3 11l14-7v16L3 13zM7 14v5',
  cal: 'M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zM8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01',
  post: 'M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z',
  repost: 'M17 1l4 4-4 4M3 11V9a4 4 0 0 1 4-4h14M7 23l-4-4 4-4M21 13v2a4 4 0 0 1-4 4H3',
  grid: 'M3 3h18v18H3zM3 9h18M3 15h18M9 3v18M15 3v18',
  ads: 'M4 4h16v12H4zM2 20h20M9 8l4 2-4 2z',
  leads: 'M4 4h16v16H4zM8 8h8M8 12h8M8 16h5',
  promotions: 'M19 5L5 19M7 7a1.5 1.5 0 1 0 .01 0M17 17a1.5 1.5 0 1 0 .01 0',
  bilan: 'M3 3v18h18M7 15l4-4 3 3 5-6',
  google: 'M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z',
  ctrl: 'M9 11l3 3L22 4M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11',
  fr: 'M17 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M22 21v-2a4 4 0 0 0-3-3.9',
  funds: 'M3 7h18v12H3zM3 7l3-4h12l3 4M16 13h.5',
  roi: 'M3 17l6-6 4 4 8-8M15 7h6v6',
  set: 'M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7',
};
// Le menu du réseau (marketing/src/lib/navigation.ts, NETWORK), avec l'entrée nouvelle sous « Réseaux sociaux ».
const NAV = [
  ['Pilotage', [['dashboard', 'Tableau de bord'], ['calendar', 'Calendrier marketing']]],
  ['Campagnes', [['campaigns', 'Campagnes']], 'Nouvelle campagne'],
  ['Réseaux sociaux', [['cal', 'Calendrier éditorial'], ['post', 'Créer un post'], ['repost', 'Repost automatique', true], ['grid', 'Grille Instagram'], ['ads', 'Publicités Meta'], ['leads', 'Formulaires de leads']]],
  ['Offres', [['promotions', 'Offres']]],
  ['Boutiques', [['bilan', 'Bilan des boutiques'], ['google', 'Avis & fiches Google'], ['ctrl', 'Contrôle des posts'], ['fr', 'Espace franchisé']]],
  ['Budget & performance', [['funds', 'Fonds marketing'], ['roi', 'Performance']]],
  ['Paramètres', [['set', 'Paramètres & connexions']]],
];
const sidebar = () => `<nav class="sb">
  <div class="sb__head"><img src="../../../public/assets/img/logo.png" alt="L'Atelier By"><div class="sb__kick">Module Marketing</div></div>
  <div class="sb__back"><span>${icon('M19 12H5M12 19l-7-7 7-7')}Retour à l’ERP</span></div>
  <div class="sb__brand">L’Atelier By</div>
  <div class="sb__groups">${NAV.map(([lab, items, act]) => `<div class="sb__grp"><div class="sb__lab">${lab}</div>${act ? `<div class="sb__act">${icon('M12 5v14M5 12h14')}${act}</div>` : ''}${items.map(([k, l, nv]) => `<div class="sb__it${nv ? ' on' : ''}">${icon(I[k])}${l}${nv ? '<span class="nv"></span>' : ''}</div>`).join('')}</div>`).join('')}</div>
</nav>`;
const page = (titre, corps, css) => `<!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>${esc(titre)}</title><link rel="stylesheet" href="rp.css">${css ? `<style>${css}</style>` : ''}</head>
<body><div class="ws">${sidebar()}<main class="mn">${corps}</main></div></body></html>
`;
const entete = (lettre, quoi, actions) => `<div class="mq"><b>Maquette ${lettre} à valider</b> — ${quoi} Les posts de la marque, les heures et les statuts sont illustrés ; les boutiques, leurs Pages et le jeton Instagram expiré de Gosselies sont ceux du module Social. Rien n’est enregistré ni publié.</div>
<header class="hd"><div class="hd__t"><span class="kick">Page et Instagram de la marque → Pages des boutiques</span><h1>REPOST AUTOMATIQUE</h1></div><div class="hd__a">${actions}</div></header>`;
const st = (c, court) => `<span class="st ${c[0]}" title="${esc(c[2])}">${ICO[c[0]]} ${court ? (c[0] === 'ok' ? c[1] : c[0] === 'err' ? 'Instagram' : LIB[c[0]].toLowerCase()) : LIB[c[0]] + (c[0] === 'ok' || c[0] === 'val' ? ' ' + c[1] : '')}</span>`;
const vignette = (p, lg) => `<span class="th${lg ? ' lg' : ''}" style="background:${p.tuile}"><img src="img/${p.img}.png" alt=""><i>${p.type}</i></span>`;
const reseaux = p => ['fb', 'ig'].map(n => `<span class="nw ${p.reseaux.includes(n) ? n : 'off'}">${n === 'fb' ? 'f' : 'ig'}</span>`).join('');
const avatar = (x, sm) => `<span class="av${sm ? ' sm' : ''}">${x.ini}</span>`;

/* --- A : le réglage et le fil ------------------------------------------------- */
function maquetteA() {
  const ligneBoutique = x => `<div class="a-sh"><span class="ck on">✓</span>${avatar(x)}<span class="a-sh__n"><b>${x.name}</b><span>L’Atelier By ${x.name} · ${x.langs}</span></span>
    <span class="nw fb">f</span><span class="nw ${x.ig === 'ok' ? 'ig' : 'off'}" title="${x.ig === 'ok' ? 'Instagram connecté' : 'Instagram : jeton expiré'}">ig</span>${x.ig === 'expire' ? '<span class="st err">⚠ Instagram à reconnecter</span>' : ''}</div>`;
  const reglage = `<section class="card a-reg">
    <div class="a-src">${'<span class="av br">A</span>'}<span><b>Chaque nouveau post de « L’Atelier By » est reposté sur :</b><span class="sub">Page Facebook et Instagram de la marque · lus toutes les 15 minutes</span></span></div>
    <div>${SHOPS.map(ligneBoutique).join('')}</div>
    <div class="a-blk"><div class="seg"><span class="on">Partager le post</span><span>Copier le post</span></div>
      <span class="sub">Facebook : la Page de la boutique partage le post de la marque. Instagram n’a pas de repost : la boutique publie une copie (même visuel, même texte).</span></div>
    <div class="a-row"><span class="ck on">✓</span><span><b>Le franchisé valide avant publication</b><span class="sub">Sans réponse sous <span class="sel">4 heures</span> : <span class="sel">publié quand même</span></span></span></div>
    <div class="a-row"><span class="ck on">✓</span><span><b>Jamais les posts qui contiennent</b><span class="a-kw"><span class="tag kw">recrute ✕</span><span class="tag kw">franchise ✕</span><span class="tag kw">concours ✕</span><span class="tag">+ un mot</span></span></span></div>
    <div class="a-foot"><span class="on-off"><span class="sw on"></span><span style="color:var(--ok)">Activé</span></span><span class="sub" style="flex:1">30 min après le post de la marque · entre 7:00 et 21:00</span><span class="btn p">Enregistrer</span></div>
  </section>`;
  const filtres = [['Tous', POSTS.length, 1], ['À valider', N.val], ['Refusés', N.ref], ['Échecs', N.err], ['Exclus', 1]];
  const fil = `<section class="card a-fil">
    <div class="a-fil__hd"><h2>Le fil des reposts</h2><span class="sub">les 7 derniers posts de la marque · ${N.ok} reposts publiés sur ${attendus} attendus</span></div>
    <div class="a-flt">${filtres.map(([l, n, on]) => `<span class="${on ? 'on' : ''}">${l} <b>${n}</b></span>`).join('')}</div>
    ${POSTS.map(p => {
      const pb = SHOPS.filter(x => ['val', 'ref', 'err'].includes(p.s[x.id][0]));
      const actions = [];
      if (pb.some(x => p.s[x.id][0] === 'err')) actions.push('<span class="btn s">↻ Relancer Instagram · Gosselies</span>');
      if (pb.some(x => p.s[x.id][0] === 'val')) actions.push('<span class="btn s">Rappeler Sombreffe</span>');
      const note = pb.filter(x => p.s[x.id][0] === 'ref').map(x => `<div class="a-note"><b>${x.name} a refusé</b> ${esc(p.s[x.id][2])}</div>`).join('');
      return `<div class="a-post${p.regle ? '' : ' excl'}">${vignette(p)}
        <div class="a-post__c"><div class="a-post__m"><span>${p.quand}</span><span>${p.type}</span>${reseaux(p)}${p.op ? `<span class="tag">opération « ${p.op} »</span>` : ''}</div>
          <div class="a-post__t"><b>${p.titre}</b> — ${esc(p.texte)}</div>
          ${p.regle ? `<div class="a-post__s">${SHOPS.map(x => `<span class="a-ps">${x.name} ${st(p.s[x.id], true)}</span>`).join('')}</div>` : `<div class="a-post__s"><span class="st excl">— Exclu : contient « recrute »</span><span class="btn l">Reposter quand même</span></div>`}
          ${note}${actions.length ? `<div class="a-post__a">${actions.join('')}</div>` : ''}</div></div>`;
    }).join('')}
  </section>`;
  const css = `.a-cols{display:grid;grid-template-columns:minmax(0,520px) minmax(0,1fr);gap:14px;align-items:start}
.a-reg{display:flex;flex-direction:column;gap:16px}.a-src{display:flex;gap:10px;align-items:center}.a-src>span:last-child{display:flex;flex-direction:column;gap:2px;font-size:14px}.a-src b{font-weight:500}
.a-sh{display:flex;align-items:center;gap:9px;padding:8px 2px;border-top:.5px solid rgba(34,34,34,.08);flex-wrap:wrap}.a-sh__n{display:flex;flex-direction:column;flex:1 1 auto;min-width:0}.a-sh__n b{font-size:13.5px;font-weight:500}.a-sh__n span{font-size:11.5px;color:var(--txt-m)}
.a-blk{display:flex;flex-direction:column;gap:8px}.a-row{display:flex;gap:10px;align-items:flex-start;font-size:13.5px}.a-row>span:last-child{display:flex;flex-direction:column;gap:6px}.a-row b{font-weight:500}.a-row .ck{margin-top:1px}.a-kw{display:flex;gap:6px;flex-wrap:wrap}
.a-foot{display:flex;align-items:center;gap:12px;border-top:.5px solid rgba(34,34,34,.10);padding-top:14px;flex-wrap:wrap}
.a-fil{display:flex;flex-direction:column;gap:10px}.a-fil__hd{display:flex;align-items:baseline;justify-content:space-between;gap:10px;flex-wrap:wrap}
.a-flt{display:flex;gap:6px;flex-wrap:wrap}.a-flt span{font-size:12px;border-radius:999px;padding:5px 11px;background:var(--beige);color:var(--muted-2)}.a-flt span.on{background:var(--accent);color:#fff}.a-flt b{font-weight:600;margin-left:3px}
.a-post{display:flex;gap:12px;padding:12px 0;border-top:.5px solid rgba(34,34,34,.08)}.a-post.excl{opacity:.72}.a-post__c{display:flex;flex-direction:column;gap:6px;min-width:0;flex:1}
.a-post__m{display:flex;gap:8px;align-items:center;font-size:11.5px;color:var(--txt-m);flex-wrap:wrap}.a-post__t{font-size:13px;line-height:1.45;color:#333}.a-post__t b{font-weight:500;color:var(--ink)}
.a-post__s{display:flex;gap:6px;flex-wrap:wrap;align-items:center}.a-ps{display:inline-flex;align-items:center;gap:4px;font-size:11.5px;color:var(--muted-2)}
.a-note{font-size:12px;background:var(--ref-bg);color:#6B1520;border-radius:8px;padding:6px 10px}.a-note b{font-weight:500}.a-post__a{display:flex;gap:6px;flex-wrap:wrap}`;
  return page('Repost automatique — maquette A', entete('A', 'le réglage tel qu’il a été simplifié le 19/10, et à côté le fil : ce qu’est devenu chaque post de la marque, boutique par boutique.',
    '<span class="btn">Essayer sur la Page test</span>') + `<div class="a-cols">${reglage}${fil}</div>`, css);
}

/* --- B : le tableau posts × boutiques ---------------------------------------- */
function maquetteB() {
  const taux = Math.round(N.ok / attendus * 100);
  const tuiles = `<div class="tiles">
    <div class="tile"><span class="k">Posts de la marque</span><span class="v">${POSTS.length}<small>du 20 au 29/10</small></span><span class="s">1 exclu par la règle (« recrute »)</span></div>
    <div class="tile ok"><span class="k">Reposts publiés</span><span class="v">${N.ok}<small>/ ${attendus}</small></span><span class="s">${taux} % des reposts attendus</span></div>
    <div class="tile val"><span class="k">À valider</span><span class="v">${N.val}</span><span class="s">Sombreffe · publié à 14:00 sans réponse</span></div>
    <div class="tile ref"><span class="k">Refusés</span><span class="v">${N.ref}</span><span class="s">Sombreffe · « four en panne »</span></div>
    <div class="tile err"><span class="k">Échecs</span><span class="v">${N.err}</span><span class="s">tous sur l’Instagram de Gosselies</span></div>
    <div class="tile ill"><span class="k">Portée des reposts</span><span class="v">9 840</span><span class="s">personnes touchées en plus de la marque (4 120)</span></div>
  </div>`;
  const alerte = `<div class="alert"><span class="ic">!</span><span style="flex:1"><b>Instagram de Gosselies : jeton expiré depuis le 27/10.</b><small>${N.err} reposts n’y sont pas partis (Facebook, lui, a publié). Reconnecter le compte, puis relancer : les copies partent dans l’ordre, une toutes les 10 minutes.</small></span><span class="btn s">Reconnecter</span><span class="btn s p">↻ Relancer les ${N.err}</span></div>`;
  const tete = `<tr><th class="b-post">Post de la marque</th>${SHOPS.map(x => {
    const cel = POSTS.filter(p => p.regle).map(p => p.s[x.id][0]);
    const pc = Math.round(cel.filter(c => c === 'ok').length / cel.length * 100);
    return `<th><div class="b-sh">${avatar(x, true)}<b>${x.name}</b></div><div class="b-mode ${x.regle}">${x.regle === 'auto' ? '<span class="sw sm on"></span> automatique' : '<span class="sw sm"></span> avec validation'}</div><div class="b-pc">${pc} % repris</div></th>`;
  }).join('')}<th class="b-act"></th></tr>`;
  const lignes = POSTS.map(p => `<tr class="${p.regle ? '' : 'excl'}"><td class="b-post"><div class="b-p">${vignette(p)}<span class="b-p__c"><span class="b-p__m">${p.quand} · ${p.type} ${reseaux(p)}</span><b>${p.titre}</b><span class="b-p__x">${esc(p.texte)}</span></span></div></td>
    ${p.regle ? SHOPS.map(x => {
      const c = p.s[x.id];
      return `<td class="b-c ${c[0]}${p.id === 'p2' && x.id === 'sombreffe' ? ' pick' : ''}">${st(c, true)}<span class="b-d">${c[0] === 'ok' ? esc(c[2]) : c[0] === 'val' ? 'auto à ' + c[1] : c[0] === 'err' ? 'Facebook ✓' : ''}</span></td>`;
    }).join('') : `<td colspan="${SHOPS.length}" class="b-c excl"><span class="st excl">— Exclu sur les 6 Pages : contient « recrute »</span></td>`}
    <td class="b-act">${p.regle ? (Object.values(p.s).some(c => c[0] !== 'ok') ? '<span class="btn s">Relancer</span>' : '<span class="btn s">Retirer</span>') : '<span class="btn s">Reposter</span>'}</td></tr>`).join('');
  const bulle = `<div class="b-pop"><div class="b-pop__h">${avatar(SHOPS[3], true)}<b>Sombreffe a refusé</b><span class="sub">me 28/10 · 18:04</span></div>
    <div class="b-pop__q">« Plus de tartes aux pommes ce week-end, four en panne. »</div>
    <div class="sub">Post « Tarte aux pommes du week-end » · opération Gamme Automne</div>
    <div class="b-pop__a"><span class="btn s">Reposter quand même</span><span class="btn s">Laisser</span></div></div>`;
  const css = `.b-tabs{display:flex;gap:4px;border-bottom:1px solid rgba(34,34,34,.12)}.b-tabs span{padding:8px 14px;font-size:13px;color:var(--txt-m);border-bottom:2px solid transparent;margin-bottom:-1px}.b-tabs span.on{color:var(--accent);border-color:var(--accent);font-weight:500}
.b-wrap{position:relative}.b-tbl{background:#fff;border-radius:10px;border:.5px solid rgba(34,34,34,.10);overflow:hidden}
table{width:100%;border-collapse:collapse;font-size:12.5px}th{background:#FBF9F5;font-weight:400;text-align:left;padding:10px 8px;vertical-align:top;border-bottom:1px solid rgba(34,34,34,.08)}
td{padding:9px 8px;border-top:1px solid rgba(34,34,34,.06);vertical-align:middle}.b-post{width:31%;padding-left:14px}.b-act{width:84px;text-align:right;padding-right:14px}
.b-sh{display:flex;align-items:center;gap:6px}.b-sh b{font-weight:500;font-size:12.5px}.b-mode{font-size:10.5px;color:var(--txt-m);margin-top:6px;display:flex;align-items:center;gap:5px}.b-mode.auto{color:var(--ok)}.b-pc{font-size:10.5px;color:var(--excl);margin-top:3px}
.b-p{display:flex;gap:10px;align-items:center}.b-p__c{display:flex;flex-direction:column;gap:2px;min-width:0}.b-p__m{font-size:11px;color:var(--txt-m);display:flex;gap:4px;align-items:center}.b-p__c b{font-weight:500;font-size:13px}.b-p__x{font-size:11.5px;color:var(--txt-m);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:330px}
.b-c{text-align:left}.b-c .b-d{display:block;font-size:10.5px;color:var(--excl);margin-top:3px;padding-left:4px}.b-c.pick{box-shadow:inset 0 0 0 2px var(--accent);border-radius:6px}
tr.excl td{background:#FBF9F5}tr.excl .th{opacity:.6}
.b-pop{position:absolute;top:208px;right:200px;width:290px;background:#fff;border-radius:10px;box-shadow:0 14px 34px rgba(35,34,32,.22);border:.5px solid rgba(34,34,34,.12);padding:12px 14px;display:flex;flex-direction:column;gap:7px;z-index:2}
.b-pop::before{content:'';position:absolute;top:-6px;left:40px;width:12px;height:12px;background:#fff;transform:rotate(45deg);border-left:.5px solid rgba(34,34,34,.12);border-top:.5px solid rgba(34,34,34,.12)}
.b-pop__h{display:flex;gap:8px;align-items:center}.b-pop__h b{font-weight:500;font-size:13px;flex:1}.b-pop__q{font-size:13px;line-height:1.45;background:var(--ref-bg);color:#6B1520;border-radius:8px;padding:7px 10px}.b-pop__a{display:flex;gap:6px}`;
  return page('Repost automatique — maquette B', entete('B', 'un tableau : chaque post de la marque en ligne, chaque boutique en colonne. On y voit qui reprend, qui refuse, ce qui a échoué — et on relance d’un clic.',
    '<span class="on-off"><span class="sw on"></span><span style="color:var(--ok)">Activé</span></span><span class="sel" style="font-size:13px;padding:8px 10px">20 – 29 octobre</span>')
    + `<div class="b-tabs"><span class="on">Suivi</span><span>Réglage</span><span>Côté franchisé</span></div>${tuiles}${alerte}
    <div class="b-wrap"><div class="b-tbl"><table><thead>${tete}</thead><tbody>${lignes}</tbody></table></div>${bulle}</div>
    <div class="leg"><span class="st ok">✓ publié</span><span class="st val">⏳ à valider</span><span class="st ref">✗ refusé</span><span class="st err">⚠ échec</span><span class="st excl">— exclu</span><span>· la colonne d’une boutique : automatique, ou le franchisé valide (sans réponse en 4 h, publié) · « Retirer » supprime les reposts des Pages des boutiques</span></div>`, css);
}

/* --- C : les règles et la semaine à venir ------------------------------------- */
function maquetteC() {
  const REGLES = [
    { nom: 'Nouveautés et gammes', coul: '#8D1D2C', actif: true,
      quoi: ['posts de la Page et de l’Instagram de la marque', 'avec <span class="tag kw">#nouveauté</span> ou <span class="tag kw">#gamme</span>'],
      vers: 'les 6 boutiques', comment: 'partage sur Facebook, copie sur Instagram', quand: '30 min après la marque', valid: 'Corbais, Sombreffe et Gembloux valident · sans réponse en 4 h : publié',
      bilan: '30 j : 9 posts → 51 reposts publiés · 1 en attente' },
    { nom: 'Offres d’une opération', coul: '#C17A2A', actif: true,
      quoi: ['posts rattachés à une opération du Marketing', 'ex. « Gamme Automne » (01/10 → 15/11)'],
      vers: 'les boutiques de l’opération', comment: 'copie, avec le texte de la boutique : « Chez nous à {ville}, {adresse} — {horaires} »', quand: 'le lendemain à 8:00', valid: 'le franchisé valide · sans réponse en 4 h : publié',
      bilan: '30 j : 3 posts → 14 reposts publiés · 1 refusé' },
    { nom: 'Recyclage des meilleurs posts', coul: '#2F5D8A', actif: false,
      quoi: ['un post de la marque de plus de 8 semaines', 'dans le top 10 % d’engagement, sans prix ni date'],
      vers: 'les boutiques sans post depuis 5 jours', comment: 'copie, publiée comme un post de la boutique', quand: 'le mardi à 11:00 · au plus 1 par semaine', valid: 'le franchisé valide · sans réponse : rien ne part',
      bilan: 'en pause · 4 posts candidats aujourd’hui (le plus fort : « Pain au levain », 14/08, 312 réactions)' },
  ];
  const regle = r => `<section class="card c-r${r.actif ? '' : ' off'}" style="--c:${r.coul}">
    <div class="c-r__h"><span class="c-dot"></span><h2>${r.nom}</h2><span class="sw${r.actif ? ' on' : ''}"></span><span class="c-r__e" style="color:${r.actif ? 'var(--ok)' : 'var(--txt-m)'}">${r.actif ? 'Active' : 'En pause'}</span><span class="btn s">Modifier</span></div>
    <div class="c-g"><span class="c-k">Quoi</span><span>${r.quoi.join(' · ')}</span>
      <span class="c-k">Vers</span><span>${r.vers}</span>
      <span class="c-k">Comment</span><span>${r.comment}</span>
      <span class="c-k">Quand</span><span>${r.quand}</span>
      <span class="c-k">Validation</span><span>${r.valid}</span></div>
    <div class="c-r__b">${r.bilan}</div></section>`;
  const garde = `<section class="card c-gf"><h2>Garde-fous du réseau <span class="sub">— valent pour toutes les règles</span></h2>
    <div class="c-gf__l">
      <div><span class="ck on">✓</span>Jamais si le texte contient <span class="tag kw">recrute</span> <span class="tag kw">franchise</span> <span class="tag kw">concours</span></div>
      <div><span class="ck on">✓</span>Entre <span class="sel">7:00</span> et <span class="sel">21:00</span> · hors plage, le repost attend l’ouverture suivante</div>
      <div><span class="ck on">✓</span>Au plus <span class="sel">2</span> reposts par jour et par Page</div>
      <div><span class="ck on">✓</span>Dans la langue de la boutique : Halle reçoit la version néerlandaise quand elle existe</div>
      <div><span class="ck on">✓</span>Jamais deux fois le même post sur la même Page</div>
    </div></section>`;
  // La semaine à venir : ce qui partira, règle par règle. Les posts de la marque sont ceux déjà programmés au calendrier éditorial (illustrés).
  const JOURS = [
    ['je 29/10', 'aujourd’hui', [['14:00', 'Brioche croustillante', 'Sombreffe · publié sans réponse', '#8D1D2C', 'val']]],
    ['ve 30/10', '', [['08:00', 'Offre Gamme Automne : −20 % sur la 2e tarte', 'Corbais, Gembloux, Wavre · à valider', '#C17A2A', 'val'], ['08:30', 'Week-end d’automne', '6 Pages · Facebook + Instagram', '#8D1D2C']]],
    ['sa 31/10', '', [['10:30', 'Halloween, c’est ce soir', '6 Pages · Facebook + Instagram', '#8D1D2C']]],
    ['di 01/11', '', []],
    ['lu 02/11', '', []],
    ['ma 03/11', '', [['11:00', 'Pain au levain, 48 h (14/08)', 'Gosselies, Sombreffe · si la règle est active', '#2F5D8A', 'ghost']]],
    ['me 04/11', '', [['08:30', 'Saint-Martin : les spéculoos sont là', '6 Pages · Facebook + Instagram', '#8D1D2C']]],
  ];
  const semaine = `<section class="card c-w"><div class="c-w__h"><h2>La semaine à venir</h2><span class="sub">ce qui partira sur les Pages des boutiques</span></div>
    ${JOURS.map(([j, s, items]) => `<div class="c-d"><div class="c-d__j"><b>${j}</b>${s ? `<span>${s}</span>` : ''}</div><div class="c-d__i">${items.length ? items.map(([h, t, q, c, k]) => `<div class="c-ev${k ? ' ' + k : ''}" style="--c:${c}"><span class="c-ev__h">${h}</span><span class="c-ev__c"><b>${t}</b><span>${q}</span></span></div>`).join('') : '<span class="mini">rien de prévu</span>'}</div></div>`).join('')}
    <div class="leg" style="margin-top:6px"><span><i class="c-sq" style="background:#8D1D2C"></i>Nouveautés et gammes</span><span><i class="c-sq" style="background:#C17A2A"></i>Offres d’une opération</span><span><i class="c-sq" style="background:#2F5D8A;opacity:.45"></i>Recyclage (en pause)</span></div>
  </section>`;
  const valider = `<section class="card c-v"><div class="c-w__h"><h2>En attente des franchisés</h2><span class="sub">2 reposts proposés, pas encore validés</span></div>
    <div class="c-v__l">${avatar(SHOPS[3], true)}<span><span><b>Sombreffe</b> — Brioche croustillante</span><span class="sub">proposé à 10:00 · publié à 14:00 sans réponse</span></span><span class="btn s">Rappeler</span></div>
    <div class="c-v__l">${avatar(SHOPS[1], true)}<span><span><b>Corbais, Gembloux, Wavre</b> — Offre Gamme Automne</span><span class="sub">proposé ce soir à 20:00 pour demain 8:00</span></span></div></section>`;
  const css = `.c-cols{display:grid;grid-template-columns:minmax(0,1fr) 400px;gap:14px;align-items:start}.c-l,.c-rt{display:flex;flex-direction:column;gap:14px}
.c-r{border-left:4px solid var(--c);display:flex;flex-direction:column;gap:12px}.c-r.off{opacity:.78;border-left-style:dashed}.c-r__h{display:flex;align-items:center;gap:10px}.c-r__h h2{flex:1}.c-r__e{font-size:12px;font-weight:500;min-width:52px}
.c-dot{width:10px;height:10px;border-radius:50%;background:var(--c)}.c-g{display:grid;grid-template-columns:86px 1fr;gap:7px 12px;font-size:13px;line-height:1.45}.c-k{font-size:10.5px;letter-spacing:.06em;text-transform:uppercase;color:var(--txt-m);padding-top:2px}
.c-r__b{font-size:12px;color:var(--muted-2);background:var(--paper);border-radius:8px;padding:7px 10px}
.c-gf{display:flex;flex-direction:column;gap:10px}.c-gf h2 .sub{font-weight:300}.c-gf__l{display:flex;flex-direction:column;gap:9px;font-size:13px}.c-gf__l>div{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.c-w{display:flex;flex-direction:column;gap:2px}.c-w__h{display:flex;flex-direction:column;gap:2px;margin-bottom:6px}
.c-d{display:grid;grid-template-columns:74px 1fr;gap:10px;padding:8px 0;border-top:.5px solid rgba(34,34,34,.08)}.c-d__j{display:flex;flex-direction:column;font-size:12.5px}.c-d__j b{font-weight:500}.c-d__j span{font-size:10.5px;color:var(--accent);font-weight:500}
.c-d__i{display:flex;flex-direction:column;gap:6px}.c-ev{display:flex;gap:8px;border-radius:8px;padding:6px 9px;background:color-mix(in srgb,var(--c) 9%,#fff);border-left:3px solid var(--c)}.c-ev.ghost{border-left-style:dashed;opacity:.6}.c-ev.val{background:var(--val-bg)}
.c-ev__h{font-size:12px;font-weight:500;color:var(--c);min-width:36px}.c-ev__c{display:flex;flex-direction:column;font-size:12.5px;gap:1px}.c-ev__c b{font-weight:500}.c-ev__c span{font-size:11px;color:var(--txt-m)}
.c-sq{display:inline-block;width:9px;height:9px;border-radius:2px;margin-right:5px;vertical-align:-1px}
.c-v{display:flex;flex-direction:column;gap:2px}.c-v__l{display:flex;gap:9px;align-items:center;padding:8px 0;border-top:.5px solid rgba(34,34,34,.08);font-size:12.5px}.c-v__l>span:nth-child(2){flex:1;display:flex;flex-direction:column;gap:1px}.c-v__l b{font-weight:500}`;
  return page('Repost automatique — maquette C', entete('C', 'plusieurs règles nommées — les nouveautés partout, les offres seulement chez les boutiques de l’opération, le recyclage des meilleurs posts — et, à droite, ce qui partira cette semaine.',
    '<span class="btn">Essayer sur la Page test</span><span class="btn p">+ Nouvelle règle</span>')
    + `<div class="c-cols"><div class="c-l">${REGLES.map(regle).join('')}${garde}</div><div class="c-rt">${semaine}${valider}</div></div>`, css);
}

/* --- La planche ----------------------------------------------------------------- */
function planche() {
  const M = [
    ['a', 'A — Le réglage et le fil', 'Le bloc validé le 19/10, sans changement, et à côté le fil des 7 derniers posts de la marque avec, sous chacun, le statut dans chaque boutique.',
      ['Rien de nouveau à apprendre : le réglage reste un seul bloc', 'Chaque post se lit d’un coup d’œil, le refus du franchisé en clair', 'Relancer un échec, rappeler un franchisé depuis le post'],
      ['Une seule règle pour tous les posts : pour en écarter un, seulement des mots', 'Au-delà de 6 à 8 boutiques, les pastilles débordent']],
    ['b', 'B — Le tableau posts × boutiques', 'Un onglet « Suivi » : six chiffres, l’alerte de connexion, puis un post par ligne et une boutique par colonne. Chaque colonne dit si la boutique est en automatique ou si son franchisé valide.',
      ['On voit qui reprend, qui refuse, ce qui échoue — par boutique', 'Le réglage boutique par boutique (automatique ou validé) vit dans l’en-tête de sa colonne', 'Une panne de jeton se répare et se relance en deux clics'],
      ['Écran dense, pensé pour l’ordinateur', 'Le réglage passe dans un second onglet']],
    ['c', 'C — Les règles et la semaine à venir', 'Plusieurs règles nommées (nouveautés partout, offres seulement chez les boutiques de l’opération, recyclage des meilleurs posts) et leurs garde-fous communs ; à droite, ce qui partira cette semaine.',
      ['Le bon post à la bonne boutique : une offre ne part que là où elle vaut', 'On anticipe : la semaine à venir se lit avant qu’elle parte', 'Le recyclage remplit les jours creux avec des posts qui ont déjà marché'],
      ['Plus de réglages, à expliquer aux franchisés', 'Le suivi détaillé (B) reste à prévoir à côté']],
  ];
  const css = `body{background:#EAE4DC}.pl{font-weight:300;padding:28px 32px 36px;display:flex;flex-direction:column;gap:18px}.pl h1{font-size:34px}.pl__s{font-size:14px;color:var(--muted-2);max-width:980px;line-height:1.5}
.pl__g{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}.pl__m{background:#fff;border-radius:12px;border:.5px solid rgba(34,34,34,.1);overflow:hidden;display:flex;flex-direction:column}
.pl__m img{display:block;width:100%;height:330px;object-fit:cover;object-position:top left;border-bottom:.5px solid rgba(34,34,34,.1)}.pl__c{padding:14px 16px 16px;display:flex;flex-direction:column;gap:8px;font-size:13px;line-height:1.45}
.pl__c h2{font-size:16px}.pl__c ul{margin:0;padding-left:18px;display:flex;flex-direction:column;gap:3px}.pl__k{font-size:10.5px;letter-spacing:.08em;text-transform:uppercase;font-weight:500;margin-top:4px}.pl__k.p{color:var(--ok)}.pl__k.m{color:var(--accent)}
.pl__n{display:grid;grid-template-columns:1fr 1fr;gap:16px}.pl__n .card{font-size:13px;line-height:1.5}.pl__n h2{margin-bottom:6px}.pl__n ul{margin:0;padding-left:18px}`;
  return `<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Repost automatique — la planche</title><link rel="stylesheet" href="rp.css"><style>${css}</style></head><body><div class="pl">
  <div><span class="kick">Marketing › Réseaux sociaux › Repost automatique</span><h1>REPOST AUTOMATIQUE — TROIS MAQUETTES</h1></div>
  <div class="pl__s">Quand la marque publie sur sa Page Facebook ou son Instagram, le post est repris par les Pages des boutiques. La première maquette (19/10) réglait <i>quoi</i> reposter ; ces trois-là ajoutent la <b>gestion</b> : ce qui est parti, ce qui attend le franchisé, ce qui a été refusé ou a échoué, et ce qui partira. Même jeu de données dans les trois : 7 posts de la marque du 20 au 29/10, 6 boutiques du module Social.</div>
  <div class="pl__g">${M.map(([k, t, d, plus, moins]) => `<div class="pl__m"><img src="${k}.jpg" alt=""><div class="pl__c"><h2>${t}</h2><div>${d}</div><div class="pl__k p">Ce que ça apporte</div><ul>${plus.map(x => `<li>${x}</li>`).join('')}</ul><div class="pl__k m">Limites</div><ul>${moins.map(x => `<li>${x}</li>`).join('')}</ul></div></div>`).join('')}</div>
  <div class="pl__n"><div class="card"><h2>Dans les trois, ce que Meta impose</h2><ul>
    <li><b>Facebook</b> : « partager » publie sur la Page de la boutique un lien vers le post de la marque (rendu à confirmer sur la Page test) ; « copier » publie le même visuel comme post de la boutique.</li>
    <li><b>Instagram</b> n’a pas de repost par l’API : c’est toujours une copie (ou un post « collab » que la boutique accepte dans l’appli).</li>
    <li>Un jeton expiré bloque une Page sans bloquer les autres : d’où l’alerte et la relance.</li></ul></div>
    <div class="card"><h2>Pour décider</h2><ul>
    <li><b>A</b> si l’on veut aller vite : le réglage existe déjà, le fil s’ajoute à côté.</li>
    <li><b>B</b> si le sujet est de savoir quelles boutiques jouent le jeu — et d’agir quand l’une décroche.</li>
    <li><b>C</b> si les offres d’opération et le recyclage doivent partir tout seuls. B et C se combinent : C pour régler, B en onglet « Suivi ».</li></ul></div></div>
</div></body></html>
`;
}

/* --- Écriture et captures ----------------------------------------------------- */
(async () => {
  fs.writeFileSync(OUT + 'a.html', maquetteA());
  fs.writeFileSync(OUT + 'b.html', maquetteB());
  fs.writeFileSync(OUT + 'c.html', maquetteC());
  const nav = await chromium.launch();
  const p = await nav.newPage({ viewport: { width: 1480, height: 900 }, deviceScaleFactor: 1 });
  for (const k of ['a', 'b', 'c']) {
    await p.goto('file://' + path.resolve(OUT + k + '.html'));
    await p.evaluate(() => document.fonts.ready);
    await p.screenshot({ path: OUT + k + '.jpg', fullPage: true, type: 'jpeg', quality: 82 });
  }
  fs.writeFileSync(OUT + 'planche.html', planche());
  await p.setViewportSize({ width: 1480, height: 900 });
  await p.goto('file://' + path.resolve(OUT + 'planche.html'));
  await p.evaluate(() => document.fonts.ready);
  await p.screenshot({ path: OUT + 'planche.jpg', fullPage: true, type: 'jpeg', quality: 82 });
  await nav.close();
  console.log('a.html b.html c.html planche.html + captures — publiés ' + N.ok + '/' + attendus + ', à valider ' + N.val + ', refusés ' + N.ref + ', échecs ' + N.err);
})();
