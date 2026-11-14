/* Maquette « Benchmark concurrents : import PDF » (demande du 14/11/2026 : « pour le magasin de Sombreffe et alentours,
 * pour le concurrent Dumont Partner à Sombreffe, encoder ceci dans les benchmarks — rajouter un mode en pilotage où
 * j'uploade les PDF et fais le matching avec nos produits »).
 *
 *   node docs/maquettes/benchmark-import-pdf/generer.js    → a.html (déposer), b.html (rapprocher), c.html (encoder)
 *   node docs/maquettes/benchmark-import-pdf/capturer.js   → a.png, b.png, c.png
 *
 * Données (donnees.json) :
 *  - RÉEL : nos produits et leurs prix à Sombreffe (GET /shops/5/products/available, lu le 14/11/2026, lecture seule) ;
 *    le concurrent (Dumont et Partners, BCE 0465.380.462, chaussée de Gembloux 37, Tongrinne — registre public) ;
 *    les concurrents déjà suivis pour Sombreffe (src/data/benchmark_*.json, relevé du 07/11/2026).
 *  - EXEMPLE : les lignes et prix de Dumont et Partners, les noms des PDF : les PDF ne sont pas encore reçus.
 *    Ils sont en italique pointillé dans les écrans. */
const fs = require('fs');
const path = require('path');
const D = __dirname;
const CHROME = fs.readFileSync(path.join(D, '..', 'parametrage-produits', 'chrome.html'), 'utf8');
const DATA = JSON.parse(fs.readFileSync(path.join(D, 'donnees.json'), 'utf8'));
const SRC = path.join(D, '..', '..', '..', 'src', 'data');
const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const nf = (n, d) => Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d, maximumFractionDigits: d }).replace(/[  ]/g, ' ');
const eur = n => nf(n, 2) + ' €';
const pct = x => (x > 0.005 ? '+' : x < -0.005 ? '−' : '') + nf(Math.abs(x * 100), 0) + ' %';
const sens = x => x > 0.02 ? 'plus' : x < -0.02 ? 'moins' : 'egal';

const ICO = 'M12 3v18M7 21h10M4 7h16M4 7l-2.5 6a2.5 2.5 0 0 0 5 0zM20 7l-2.5 6a2.5 2.5 0 0 0 5 0z';
function page(contenu, titre) {
  let h = CHROME;
  h = h.replace('<link rel="stylesheet" href="../../../public/assets/css/controles-photo.css">', '<link rel="stylesheet" href="../../../public/assets/css/verification.css">\n<link rel="stylesheet" href="bi.css">');
  h = h.replace(/<title>[^<]*<\/title>/, '<title>Benchmark concurrents · import PDF — Cockpit CEO (maquette)</title>');
  h = h.replace('class="sidebar__item is-active" aria-current="page"', 'class="sidebar__item"');
  // Le mode s'ajoute au groupe Pilotage du rail, sous le dashboard magasin.
  h = h.replace(/(<span class="sidebar__item-label">Dashboard magasin ↗<\/span>\s*<\/button>)/, `$1<button class="sidebar__item is-active" aria-current="page"><svg class="ico" viewBox="0 0 24 24"><path d="${ICO}"></path></svg><span class="sidebar__item-label">Benchmark concurrents</span></button>`);
  h = h.replace('<p class="topbar__kicker">Vue Réseau</p>', '<p class="topbar__kicker">Pilotage</p>');
  h = h.replace(/<h1>Résultat<\/h1>/, '<h1>Benchmark concurrents</h1>');
  h = h.replace(/<div class="topbar__actions">/, '<div class="topbar__actions"><span class="bi-pill">Maquette · nos produits et prix : Sombreffe, lus le 14/11 · lignes Dumont : exemple, PDF pas encore reçus · rien n’est écrit</span>');
  h = h.replace(/<p class="shell-sub page-intro">[\s\S]*?<\/p>/, '');
  h = h.replace('class="app__body"', 'class="app__body app__body--wide"');
  return h.replace(/<div data-screen="resultat"[\s\S]*?<div id="mq-contenu"><\/div><\/div>/, `<div class="bi">${contenu}</div>`).replace('</body>', (titre || '') + '</body>');
}

/* ── Ce qui est commun aux trois écrans ─────────────────────────────────────── */
const MAG = DATA.magasin, CO = DATA.concurrent, PDF = DATA.pdfs, L = DATA.lignes;
const NOUS = Object.fromEntries(DATA.nous.map(p => [p.id, p]));
const MODES = ['Vue d’ensemble', 'Relevés', 'Import PDF', 'Vérification de l’API'];
const modes = `<div class="bi-modes">${MODES.map(m => `<button class="${m === 'Import PDF' ? 'on' : ''}">${m}${m === 'Import PDF' ? '<b>nouveau</b>' : ''}</button>`).join('')}<span class="vr-sp"></span>
  <span class="bi-mag">Magasin <select class="vr-sel"><option>${esc(MAG.nom)}</option></select> Concurrent <select class="vr-sel"><option>${esc(CO.nom)}</option></select></span></div>`;
const ETAPES = ['Déposer les PDF', 'Rapprocher avec nos produits', 'Encoder dans le benchmark'];
const etapes = n => `<ol class="bi-etapes">${ETAPES.map((e, i) => `<li class="${i < n ? 'fait' : i === n ? 'on' : ''}"><i>${i < n ? '✓' : i + 1}</i>${e}</li>`).join('')}</ol>`;
const ST = { sur: 'Sûre', conf: 'À confirmer', sans: 'Sans équivalent', ign: 'Ignorée', illi: 'Illisible' };
const compte = st => L.filter(l => l.st === st).length;

/** La comparaison d'une ligne avec un de nos produits, sur une base commune. */
function compare(l, pid) {
  const p = NOUS[pid || l.pid];
  if (!p || l.prix == null) { return null; }
  if (!p.prix) { return { manque: true, p }; }
  let eux, nous, base, unite = '';
  if (l.base === 'lot') { eux = l.prix / l.lot; nous = p.prix; base = `les ${l.lot} → ${eur(eux)} pièce`; }
  else if (l.base === 'kg') { eux = l.prix / (l.g / 1000); nous = p.prix / (p.poids / 1000); unite = '/kg'; base = `au kg · ${l.g} g / ${p.poids} g${p.estime ? ' (estimé)' : ''}`; }
  else if (l.base === 'surface') { eux = l.prix * Math.pow(p.d / l.d, 2); nous = p.prix; base = `Ø ${l.d} → Ø ${p.d} : ${eur(eux)}`; }
  else if (l.base === 'part') { eux = l.prix / l.parts; nous = p.prix / p.parts; unite = '/part'; base = `à la part · ${l.parts} p. / ${p.parts} p.`; }
  else if (l.base === 'promo') {
    eux = l.lot ? l.prix / l.lot : l.prix * Math.pow(p.d / l.d, 2); nous = p.prix;
    base = l.lot ? `promo · ${eur(eux)} pièce` : `promo · normal ${eur(l.normal)}`;
  } else { eux = l.prix; nous = p.prix; base = 'à la pièce'; }
  return { eux, nous, unite, base, ecart: nous / eux - 1, p };
}

/* ── L'aperçu d'une page de PDF (dessin, pas le vrai document) ───────────────── */
function pagePdf(pdf, p, ouvert) {
  const lignes = L.filter(l => l.pdf === pdf && l.p === p);
  const secs = [...new Set(lignes.map(l => l.sec))];
  const tete = pdf === 'S' ? 'PROMOS DE NOVEMBRE' : 'DUMONT & PARTNERS';
  return `<div class="bi-page"><h5>${tete}</h5><div class="s">Boulangerie – Pâtisserie · Tongrinne · ${pdf === 'T' ? 'Tarif au 1er octobre 2026' : pdf === 'F' ? 'Fêtes de fin d’année 2026' : 'Novembre 2026'}</div>
    ${secs.map(s => `<h6>${esc(s)}</h6>${lignes.filter(l => l.sec === s).map(l => `<div class="ln ${l === ouvert ? 'hl' : l.st !== 'ign' && l.st !== 'illi' ? 'vu' : ''}"><span>${esc(l.brut)}</span><i></i><span>${l.prix != null ? nf(l.prix, 2) + ' €' : ''}</span></div>`).join('')}`).join('')}
    <div class="pd">${PDF[pdf].nom} · page ${p} / ${PDF[pdf].pages}</div></div>`;
}

/* ═══ A. DÉPOSER ═══════════════════════════════════════════════════════════════ */
// Les concurrents déjà suivis pour Sombreffe : les référentiels du benchmark (relevé du 07/11/2026).
const suivis = {};
for (const f of ['cougnou', 'galette', 'top10']) {
  const d = JSON.parse(fs.readFileSync(path.join(SRC, `benchmark_${f}.json`), 'utf8'));
  for (const c of d.concurrents) {
    if (!(c.magasins || []).includes(MAG.id)) { continue; }
    const s = suivis[c.nom] || (suivis[c.nom] = { nom: c.nom, type: c.type, ville: (c.ville || {})[String(MAG.id)] || (c.ville || {})['*'] || '', km: (c.distance_km || {})[String(MAG.id)], prix: 0, familles: [] });
    s.prix += (c.items || []).length; s.familles.push(d.libelle || f);
  }
}
const ordreType = { Artisan: 0, 'Chaîne': 1, GMS: 2 };
const SUIVIS = Object.values(suivis).sort((a, b) => (ordreType[a.type] - ordreType[b.type]) || ((a.km || 99) - (b.km || 99)) || b.prix - a.prix);
const GMS = SUIVIS.filter(s => s.type === 'GMS');
const coSuivi = s => `<div class="bi-co"><i class="bi-dot ${esc(s.type)}"></i><div><b>${esc(s.nom)}</b><small>${esc(s.ville)}${s.km ? ' · ' + s.km + ' km' : ''} · ${s.type.toLowerCase()} · relevé 07/11 · ${s.prix} prix (${s.familles.join(', ')})</small></div>${/dumont/i.test(s.nom) ? '<span class="vr-sp"></span><span class="bi-tag homo">homonyme</span>' : ''}</div>`;
const nArtisans10 = SUIVIS.filter(s => s.type === 'Artisan' && s.km != null && s.km <= 10).length;
const zone = `<aside class="bi-card bi-zone"><div class="bi-sec" style="margin-top:0">${esc(MAG.nom)} et alentours</div>
  <select class="vr-sel"><option>${esc(MAG.nom)} — ${esc(MAG.adresse)}</option></select>
  <div class="bi-conc"><div class="bi-gr">À moins de 15 km</div><div class="bi-co on"><i class="bi-dot Artisan"></i><div><b>${esc(CO.nom)}</b><small>Tongrinne · ${CO.distance} · artisan<br>jamais relevé</small></div><span class="vr-sp"></span><span class="bi-tag neuf">nouveau</span></div>
  ${SUIVIS.filter(s => s.km != null && s.km <= 15).map(coSuivi).join('')}
  <div class="bi-gr">Plus loin</div>${SUIVIS.filter(s => s.type !== 'GMS' && !(s.km != null && s.km <= 15)).map(coSuivi).join('')}
  <div class="bi-gr">Supermarchés</div><div class="bi-co"><i class="bi-dot GMS"></i><div><b>${GMS.map(s => esc(s.nom.replace(/ \(.*\)/, ''))).join(', ')}</b><small>prix nationaux · relevé 07/11 · ${GMS.reduce((n, s) => n + s.prix, 0)} prix</small></div></div></div>
  <div class="bi-note">${SUIVIS.length} concurrents relevés pour ${esc(MAG.nom)} (benchmarks du 07/11), ${nArtisans10 ? nArtisans10 + ' artisan(s) à moins de 10 km' : '<b>aucun artisan à moins de 10 km</b>'} : ${esc(CO.nom)} sera le premier.</div></aside>`;

const parPdf = k => { const ls = L.filter(l => l.pdf === k); return { prix: ls.filter(l => l.st !== 'ign' && l.st !== 'illi').length, ign: ls.filter(l => l.st === 'ign').length, illi: ls.filter(l => l.st === 'illi').length }; };
const fichier = k => { const f = PDF[k], n = parPdf(k); return `<div class="bi-fic"><span class="ico-pdf">PDF</span><div><b class="bi-ex">${esc(f.nom)}</b><small>${f.pages} page${f.pages > 1 ? 's' : ''} · ${nf(f.ko, 0)} Ko · date lue : <span class="bi-ex">${esc(f.date)}</span></small></div>
  <div class="bi-lu"><span class="${f.lecture === 'image' ? 'img' : 'ok'}">${f.lecture === 'image' ? 'scan · lu par image' : 'texte lu'}</span><span class="ok">${n.prix} lignes de prix</span>${n.ign ? `<span>${n.ign} sans produit</span>` : ''}${n.illi ? `<span class="att">${n.illi} illisible</span>` : ''}</div></div>`; };
const total = L.length;
const A = `${modes}${etapes(0)}<div class="bi-cols">${zone}<div class="bi-cols d" style="grid-template-columns:1fr 360px">
 <section class="bi-card"><h2 class="bi-h">Nouveau relevé par PDF <small>tarifs, folders, cartes de fêtes d’un concurrent</small></h2>
  <div class="bi-sec">Le concurrent <span>— créé chez TFBuddy à l’encodage</span><code>POST /shops/{id}/benchmarks/competitors</code></div>
  <div class="bi-grille"><label class="bi-ch">Nom<input class="bi-in" value="${esc(CO.nom)}"></label><label class="bi-ch">Type<select class="bi-in"><option>Artisan</option></select></label><label class="bi-ch">Distance de ${esc(MAG.nom)}<input class="bi-in" value="${CO.distance}"></label>
   <label class="bi-ch l2">Adresse<input class="bi-in" value="${esc(CO.adresse)}"></label><label class="bi-ch">N° d’entreprise<input class="bi-in" value="${CO.bce}"></label>
   <div class="bi-ch l2">Magasins qui le comparent<div class="bi-gch"><button class="on">Sombreffe</button><button>Gosselies</button><button>Corbais</button><button>Halle</button></div></div></div>
  <div class="bi-info"><b>Homonyme :</b> « Pâtisserie Dumont (Bouge) », déjà suivie pour Sombreffe à 25 km, est une autre société (Namur). Le cockpit ne les fusionne pas : il retrouve un concurrent par son nom exact, et le n° d’entreprise départage.</div>
  <div class="bi-sec">Les documents <span>— un PDF ou plusieurs ; un scan est lu comme une image</span></div>
  <div class="bi-drop"><svg viewBox="0 0 24 24"><path d="M12 16V4M7 9l5-5 5 5M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/></svg><div><b>Glisser les PDF ici</b><small>ou <a href="#" style="color:var(--color-primary)">parcourir</a> · 20 Mo au plus par fichier · gardés dans le dossier privé du serveur, jamais servis sur le web</small></div></div>
  <div class="bi-fics">${['T', 'F', 'S'].map(fichier).join('')}</div>
  <div class="bi-sec">Le relevé <span>— une observation datée, avec ses lignes</span><code>POST …/competitors/{id}/observations</code></div>
  <div class="bi-grille"><label class="bi-ch">Date du relevé<input class="bi-in" value="10/11/2026"></label><label class="bi-ch">Prix lus<select class="bi-in"><option>TTC, tels qu’affichés</option></select></label><label class="bi-ch">Promotions<select class="bi-in"><option>prix promo + prix normal</option></select></label></div>
  <div class="bi-pied"><span class="bi-note">${total} lignes lues dans 3 PDF. Le même PDF déposé deux fois n’ajoute rien : il est reconnu à son empreinte.</span><span class="vr-sp" style="flex:1"></span><button class="rc-btn gris">Annuler</button><button class="rc-btn">Rapprocher avec nos produits →</button></div>
 </section>
 <aside class="bi-colle"><div class="bi-sec" style="margin:0">Ce que le cockpit a lu <span>— ${PDF.T.nom}, p. 2</span></div>${pagePdf('T', 2)}
  <div class="bi-legende"><i style="background:rgba(47,93,138,.25)"></i>ligne de prix reconnue : libellé, format, prix, promo.</div>
  <section class="bi-card"><div class="bi-sec" style="margin-top:0">Formats reconnus <span>— la base de comparaison</span></div><div class="bi-fmts">
   <div><code>800 g</code><span>au kilo, face au poids de notre produit</span></div><div><code>Ø 26 cm</code><span>à surface égale, ramené à notre Ø</span></div>
   <div><code>les 6</code><span>ramené à la pièce</span></div><div><code>6 pers.</code><span>à la part</span></div>
   <div><code>4 + 1 offert</code><span>promo : prix promo et prix normal</span></div><div><code>−20 % le mercredi</code><span>promo datée</span></div>
   <div><code>sans format</code><span>à la pièce</span></div></div></section></aside></div></div>`;

/* ═══ B. RAPPROCHER ════════════════════════════════════════════════════════════ */
const ouvert = L.find(l => l.ouvert);
function cellProduit(l) {
  if (l.st === 'sans') { return `<div class="bi-choix sans"><div><b>sans équivalent chez nous</b><small>${l.cat ? 'écrit avec la catégorie seule' : 'écrit sans produit'}</small></div></div>`; }
  if (l.st === 'ign' || l.st === 'illi') { return `<span class="bi-mu">${esc(l.note)}</span>`; }
  const p = NOUS[l.pid];
  return `<div class="bi-choix ${l.st === 'conf' ? 'conf' : ''}"><div><b>${esc(p.nom)}${l.appris ? '<span class="bi-appris">appris</span>' : ''}</b><small>${p.id} · ${esc(p.cat)}${l.cand.length > 1 ? ` · ${l.cand.length} candidats` : ''}</small></div></div>`;
}
function ligneB(l) {
  const c = compare(l);
  const fmt = l.g ? l.g + ' g' : l.d ? 'Ø ' + l.d + ' cm' : l.lot ? 'les ' + l.lot : l.parts ? l.parts + ' parts' : '';
  const coche = l.st === 'sur' ? '<span class="bi-cb on"></span>' : l.st === 'conf' ? '<span class="bi-cb"></span>' : l.st === 'sans' ? '<span class="bi-cb on"></span>' : '<span class="bi-cb" style="opacity:.4"></span>';
  let prixNous = '', base = '', ecart = '';
  if (c && c.manque) { prixNous = `<span style="color:var(--vr-ko)">0,00 €</span><small>prix manquant au panel</small>`; base = 'pas de comparaison'; }
  else if (c) {
    prixNous = eur(c.p.prix) + (c.unite ? `<small>${eur(c.nous)}${c.unite}</small>` : '');
    base = esc(c.base) + (c.unite ? `<small>eux ${eur(c.eux)}${c.unite}</small>` : '');
    ecart = `<span class="bi-ec ${sens(c.ecart)}">${pct(c.ecart)}</span>`;
  }
  const conf = (l.c ? `<div class="bi-conf ${l.st}"><span><i style="width:${l.c}%"></i></span>${l.c} %</div>` : '') + `<span class="bi-st ${l.st}">${ST[l.st]}</span>${l.promo ? '<span class="bi-st promo">promo</span>' : ''}`;
  const tr = `<tr class="${l.st}${l === ouvert ? ' ouvert' : ''}"><td class="g">${coche}</td><td class="g nom w" style="min-width:170px"><b class="bi-ex">${esc(l.brut)}</b><small>p. ${l.p}</small>${l.note && l.st !== 'ign' && l.st !== 'illi' && l.st !== 'sur' ? `<small class="bi-nt">${esc(l.note)}</small>` : ''}</td>
    <td>${l.prix != null ? `<span class="bi-ex">${eur(l.prix)}</span>` : '—'}<small>${esc(fmt)}</small></td><td class="g">${cellProduit(l)}</td><td>${prixNous}</td><td class="g w" style="max-width:150px">${base}</td><td>${ecart}</td><td class="bi-cf">${conf}</td></tr>`;
  if (l !== ouvert) { return tr; }
  const cands = l.cand.map((id, i) => { const p = NOUS[id]; const cc = compare(l, id); return `<div class="bi-cand ${i === 0 ? 'on' : ''}"><span class="r"></span><div><b>${esc(p.nom)}</b><small>${p.id} · ${esc(p.cat)} · ${p.prix ? eur(p.prix) : '<span style="color:var(--vr-ko)">sans prix au panel</span>'}${cc && !cc.manque ? ' · écart ' + pct(cc.ecart) : ''}</small></div></div>`; }).join('');
  return tr + `<tr class="ouvert"><td></td><td colspan="7"><div class="bi-cands">${cands}<div class="bi-cand"><span class="r"></span><div><b>Sans équivalent chez nous</b><small>la ligne entre au benchmark avec la catégorie Viennoiserie, sans comparaison</small></div></div>
    <div class="bi-cand cherche"><input class="bi-in" placeholder="Chercher dans les ${MAG.actifs} produits de ${esc(MAG.nom)}…"><div class="bi-retenir"><span class="bi-cb on"></span>Retenir pour ${esc(CO.nom)} : « escargot aux raisins » = Huit</div></div></div></td></tr>`;
}
const sections = [...new Set(L.map(l => l.pdf + '|' + l.sec))];
const corpsB = sections.map(k => { const [pdf, sec] = k.split('|'); const ls = L.filter(l => l.pdf === pdf && l.sec === sec); return `<tr class="grp"><td colspan="8">${esc(sec)}<span>${ls.length} ligne${ls.length > 1 ? 's' : ''} · ${PDF[pdf].nom}</span></td></tr>` + ls.map(ligneB).join(''); }).join('');
const nPromo = L.filter(l => l.promo).length;
const B = `${modes}${etapes(1)}<div class="bi-cols d">
 <section class="bi-card p0"><div class="bi-tete"><div><h2 class="bi-h" style="margin:0">${esc(CO.nom)} → nos produits de ${esc(MAG.nom)} <small>${total} lignes lues dans 3 PDF · catalogue de ${esc(MAG.nom)} : ${MAG.actifs} produits actifs, prix lus le ${DATA.lu}</small></h2></div><span class="vr-sp" style="flex:1"></span>
   <button class="rc-btn gris">← Documents</button><button class="rc-btn sec">Accepter les ${compte('sur')} sûres</button><button class="rc-btn">Encoder →</button></div>
  <div class="vr-chips"><button class="on">Tout <b>${total}</b></button><button><i style="background:var(--vr-ok)"></i>Sûres <b>${compte('sur')}</b></button><button><i style="background:#E8A33D"></i>À confirmer <b>${compte('conf')}</b></button><button><i style="background:#7a5aa6"></i>Sans équivalent <b>${compte('sans')}</b></button><button>Ignorées <b>${compte('ign')}</b></button><button><i style="background:var(--vr-ko)"></i>Illisibles <b>${compte('illi')}</b></button><button>Promos <b>${nPromo}</b></button><span class="vr-sp" style="flex:1"></span><input class="vm-q" placeholder="Chercher une ligne ou un produit…"></div>
  <table class="bi-t"><thead><tr><th class="g"></th><th class="g">Ligne du PDF<small>libellé tel quel</small></th><th>Prix Dumont<small>format lu</small></th><th class="g">Notre produit à ${esc(MAG.nom)}<small>proposé · à changer d’un clic</small></th><th>Notre prix<small>TTC au panel</small></th><th class="g">Comparé<small>sur la même base</small></th><th>Écart<small>nous face à eux</small></th><th>Confiance<small>et état</small></th></tr></thead><tbody>${corpsB}</tbody></table>
 </section>
 <aside class="bi-colle"><div class="bi-sec" style="margin:0">La ligne ouverte, dans son PDF</div>${pagePdf('T', 2, ouvert)}
  <section class="bi-card"><div class="bi-sec" style="margin-top:0">Comment le cockpit rapproche</div><div class="bi-algo">
   <div><i>1</i><span><b>Mémoire.</b> Une correspondance déjà confirmée pour ce concurrent, puis pour le réseau, passe d’office (« couque suisse » = Suisse).</span></div>
   <div><i>2</i><span><b>Libellé.</b> Mots normalisés (accents, pluriels, « couque », « ind. », « pur beurre ») comparés aux ${MAG.actifs} produits actifs de ${esc(MAG.nom)}.</span></div>
   <div><i>3</i><span><b>Format.</b> Poids, Ø, pièces ou parts lus dans la ligne : la comparaison se fait au kilo, à surface égale, à la pièce ou à la part.</span></div>
   <div><i>4</i><span><b>Rubrique.</b> La catégorie de notre produit doit coller à la rubrique du PDF (« Tartes », « Viennoiseries »).</span></div>
   <div><i>✓</i><span><b>Sûre</b> à partir de 90 % : cochée d’office. En dessous : <b>à confirmer</b>. Rien n’est écrit sans votre clic sur « Encoder ».</span></div></div></section></aside></div>`;

/* ═══ C. ENCODER ═══════════════════════════════════════════════════════════════ */
// Après confirmation : chaque ligne « à confirmer » garde le produit proposé ; les promos et les prix manquants ne comptent pas.
const ecrits = L.filter(l => l.st === 'sur' || l.st === 'conf');
const comp = ecrits.filter(l => !l.promo).map(l => ({ l, c: compare(l) })).filter(x => x.c && !x.c.manque);
const med = a => { const s = [...a].sort((x, y) => x - y); const m = s.length >> 1; return s.length % 2 ? s[m] : (s[m - 1] + s[m]) / 2; };
const ecarts = comp.map(x => x.c.ecart);
const nPlus = ecarts.filter(e => sens(e) === 'plus').length, nMoins = ecarts.filter(e => sens(e) === 'moins').length, nEgal = ecarts.length - nPlus - nMoins;
const FAM = { Pains: 'Pains & petits pains', 'Petits pains': 'Pains & petits pains', Viennoiseries: 'Viennoiseries', 'Tartes Ø 26 cm (6-8 p.)': 'Tartes', 'Pâtisseries': 'Pâtisseries', Traiteur: 'Traiteur', 'Saint-Nicolas': 'Fêtes', 'Noël & Nouvel An': 'Fêtes' };
const fams = {};
for (const x of comp) { (fams[FAM[x.l.sec]] = fams[FAM[x.l.sec]] || []).push(x.c.ecart); }
const ECH = 0.5; // ±50 %
const posx = e => 50 + Math.max(-1, Math.min(1, e / ECH)) * 50;
const barres = Object.entries(fams).map(([f, es]) => { const m = med(es), lo = Math.min(...es), hi = Math.max(...es);
  const a = posx(Math.min(0, m)), b = posx(Math.max(0, m));
  return `<span>${esc(f)}</span><span class="n">${es.length}</span><div class="bi-bar"><i class="rg" style="left:${posx(lo)}%;width:${posx(hi) - posx(lo)}%"></i><i class="md ${sens(m)}" style="left:${a}%;width:${Math.max(b - a, .6)}%"></i></div><span class="v"><span class="bi-ec ${sens(m)}">${pct(m)}</span></span>`; }).join('');
const tri = [...comp].sort((x, y) => y.c.ecart - x.c.ecart);
const li = x => `<li><span class="t"><span class="bi-ex">${esc(x.l.brut)}</span> → ${esc(x.c.p.nom)}<small>eux ${eur(x.c.eux)}${x.c.unite} · nous ${eur(x.c.nous)}${x.c.unite}${x.c.unite ? '' : ''}</small></span><span class="bi-ec ${sens(x.c.ecart)}">${pct(x.c.ecart)}</span></li>`;
const nProd = ecrits.length, nSans = compte('sans'), nConf = compte('conf');
const manque = L.find(l => l.prixManque);
const C = `${modes}${etapes(2)}<div class="bi-cols c">
 <section class="bi-card"><h2 class="bi-h">Ce qui sera écrit chez TFBuddy <small>module Benchmark · compte consultant du cockpit</small></h2>
  <div class="bi-note">Chaque écriture est relue : TFBuddy répond 201 même quand une ligne n’entre pas. Rejouable sans doublon : l’observation porte l’étiquette <code>[cockpit:pdf:9f3c…]</code> des trois PDF.</div>
  <div class="bi-sec">Le plan</div><div class="bi-plan">
   <div class="bi-op"><span class="m">POST</span><div><code>/shops/${MAG.id}/benchmarks/competitors</code><b>${esc(CO.nom)}</b><small>artisan · Tongrinne · ${CO.distance} · notes : adresse, n° ${CO.bce} · is_active</small></div><div class="n">1<small>nouveau</small></div></div>
   <div class="bi-op"><span class="m">POST</span><div><code>…/competitors/{id}/observations</code><b>Relevé PDF du 10/11/2026</b><small>observed_at ${DATA.releve} · notes : les 3 documents, leur date lue, l’étiquette du cockpit</small></div><div class="n">1</div></div>
   <div class="bi-op"><span class="m">POST</span><div><code>…/observations/{id}/items</code><b>Lignes rattachées à un de nos produits</b><small>name_raw, price, unit_type / unit_size (g, pièce, cm), my_product_id, my_category_id · dont ${nPromo} promos (is_promo, promo_price) et ${nConf} confirmées à la main</small></div><div class="n">${nProd}</div></div>
   <div class="bi-op"><span class="m">POST</span><div><code>…/observations/{id}/items</code><b>Lignes sans équivalent chez nous</b><small>name_raw, price, my_category_id quand il y en a une, sans my_product_id : lues au benchmark, jamais comparées</small></div><div class="n">${nSans}</div></div>
   <div class="bi-op non"><span class="m no">—</span><div><b>Non écrites</b><small>${compte('ign')} ignorées (conditions, livraison) · ${compte('illi')} illisible du scan, à saisir à la main si elle compte</small></div><div class="n">${compte('ign') + compte('illi')}</div></div>
   <div class="bi-op"><span class="m" style="background:var(--bi-bleu)">MÉM.</span><div><b>Correspondances retenues pour ${esc(CO.nom)}</b><small>le prochain tarif se rapprochera tout seul ; elles se corrigent dans « Relevés »</small></div><div class="n">${nConf}</div></div></div>
  <div class="bi-info"><b>À corriger d’abord au panel :</b> ${esc(NOUS[manque.pid].nom)} est à 0,00 € à ${esc(MAG.nom)}. La ligne « ${esc(manque.brut)} » est écrite, mais l’analyse ne la comparera qu’une fois notre prix posé.</div>
  <div class="bi-pied"><button class="rc-btn gris">← Rapprochement</button><span class="vr-sp" style="flex:1"></span><button class="rc-btn">Encoder ${nProd + nSans} lignes</button></div></section>
 <section class="bi-card"><h2 class="bi-h">${esc(MAG.nom)} face à ${esc(CO.nom)} <small>${comp.length} produits comparés · relu ensuite dans <code>analysis/my-products</code></small></h2>
  <div class="bi-tuiles" style="margin-top:12px"><div class="bi-tuile"><small>Comparés</small><b>${comp.length}</b><span>sur ${nProd} lignes rattachées</span></div><div class="bi-tuile plus"><small>Nous plus chers</small><b>${nPlus}</b><span>de plus de 2 %</span></div><div class="bi-tuile moins"><small>Nous moins chers</small><b>${nMoins}</b><span>${nEgal} au même prix (±2 %)</span></div><div class="bi-tuile ${sens(med(ecarts))}"><small>Écart médian</small><b>${pct(med(ecarts))}</b><span>nous face à Dumont</span></div></div>
  <div class="bi-sec">Par famille <span>— écart médian, trait gris : du plus bas au plus haut</span></div>
  <div class="bi-fam"><span class="h">Famille</span><span class="h n">Prod.</span><span class="h" style="display:flex;justify-content:space-between"><span>−50 %</span><span>nous moins chers · plus chers</span><span>+50 %</span></span><span class="h v">Médiane</span>${barres}</div>
  <div class="bi-top" style="margin-top:16px"><div><div class="bi-sec" style="margin-top:0">Nous plus chers</div><ol>${tri.slice(0, 6).map(li).join('')}</ol></div><div><div class="bi-sec" style="margin-top:0">Nous moins chers</div><ol>${tri.slice(-6).reverse().map(li).join('')}</ol></div></div>
  <div class="bi-pied"><span class="bi-note">Les chiffres viennent des lignes Dumont d’exemple : ils changeront avec les vrais PDF.</span><span class="vr-sp" style="flex:1"></span><button class="rc-btn sec">Dashboard ${esc(MAG.nom)} › Concurrence ↗</button><button class="rc-btn sec">→ Plan d’action</button></div></section></div>`;

for (const [f, c] of [['a', A], ['b', B], ['c', C]]) { fs.writeFileSync(path.join(D, f + '.html'), page(c)); }
console.log('écrit : a.html, b.html, c.html ·', total, 'lignes ·', comp.length, 'comparées · écart médian', pct(med(ecarts)));
