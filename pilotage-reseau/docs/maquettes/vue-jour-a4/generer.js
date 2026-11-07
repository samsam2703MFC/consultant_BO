/* Trois maquettes de la vue Jour du dashboard magasin tenue en UNE page A4,
 * chaque section devenue une question avec sa réponse en une ligne, et la
 * section complète dans une liste déroulante sous la question :
 *   A — une ligne par question (liste en accordéon, quatre chapitres) ;
 *   B — l'essentiel en tête, puis quatre chapitres de quatre questions en colonnes ;
 *   C — des vignettes à mini-graphique, rangées par urgence (à faire, le chiffre,
 *       pourquoi, le contexte).
 *
 * RÉEL : Halle (shop 4), samedi 3 octobre 2026, relu en lecture seule vers 17 h 15
 * (reel-halle-0310.json). Les photos des contrôles sont des liens signés de
 * 20 minutes : elles sont hachurées ici, pas reproduites. Réseau anonyme, aucun
 * nom de client.
 *
 * A4 = 1 240 × 1 754 px (150 dpi) : la page doit tenir dans la feuille repliée,
 * et encore avec une section ouverte.
 *
 *   node docs/maquettes/vue-jour-a4/generer.js   (serveur statique sur 8099 depuis la racine)
 */
const fs = require('fs');
const { chromium } = require('/opt/node22/lib/node_modules/playwright');
const OUT = __dirname + '/';
const D = require('./reel-halle-0310.json');
const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const nf = (n, d = 0) => Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d, maximumFractionDigits: d });
const fE = (n, d = 0) => nf(n, d) + ' €';
const fP = (n, d = 1) => nf(n, d) + ' %';
const sgE = n => (n < 0 ? '− ' : '+ ') + fE(Math.abs(n));
const H = D.heures, P = D.pnl;
const COUL = { ok: '#2D7A3E', att: '#D97706', ko: '#C0182B', or: '#C9A227', ctl: '#2F6EA5', neutre: '#9a948c' };
const palier = t => t == null ? '#bdb6ad' : t < 40 ? '#222' : t < 50 ? '#C0182B' : t < 60 ? '#E8892B' : t < 70 ? '#2D7A3E' : '#E2B93B';
const hh = h => `${h} – ${h + 1} h`;

/* --- Les mini-visuels (la ligne repliée) ---------------------------------------------------- */
const maxCa = Math.max(...H.map(x => x.ca)), maxRes = Math.max(...H.map(x => x.res));
const mvCa = (ht = 24) => `<div class="mv" style="height:${ht}px">${H.map(x => `<i style="height:${Math.max(2, ht * x.ca / maxCa).toFixed(1)}px;background:${x.ca === maxCa ? '#8D1D2C' : '#c99aa0'}" title="${hh(x.h)} · ${fE(x.ca)}"></i>`).join('')}</div>`;
const mvNet = (ht = 24) => `<div class="mv" style="height:${ht}px">${H.map(x => `<i style="height:${Math.max(3, ht * Math.abs(x.res) / maxRes).toFixed(1)}px;background:${x.res < 0 ? COUL.ko : (x.res === maxRes ? '#1f5e2e' : '#7fb38b')}" title="${hh(x.h)} · ${sgE(x.res)}"></i>`).join('')}</div>`;
const filC = { ko: COUL.ko, ctl: COUL.ctl, ok: COUL.ok };
const mvFil = () => `<div class="fil">${D.taches.fil.map(s => `<i style="background:${filC[s]}"></i>`).join('')}</div>`;
const mvCarres = () => `<div class="carres">${D.photos.map(p => `<i style="background:${filC[p[1]]}"></i>`).join('')}</div>`;
const mvPile = seg => `<div class="pile">${seg.map(([p, c]) => `<i style="width:${p}%;background:${c}"></i>`).join('')}</div>`;
const mvJauge = () => { const top = D.record.ca; return `<div class="jauge"><i style="width:${(100 * D.ca / top).toFixed(1)}%"></i><b style="left:${(100 * D.objectif / top).toFixed(1)}%"></b></div>`; };
const persoPct = H.map(x => x.ca ? 100 * x.trav / x.ca : null);
const mvCellules = () => `<div class="cellules">${persoPct.map((p, i) => `<i style="background:${p == null ? '#eee' : (p > 33 ? COUL.ko : '#9cc9a6')}" title="${hh(H[i].h)} · ${p == null ? '—' : fP(p)}"></i>`).join('')}</div>`;
const mvMois = (ht = 24) => { const m = Math.max(...D.mois.map(x => x.netPct)); return `<div class="mv" style="height:${ht}px;gap:6px">${D.mois.map(x => `<i style="height:${(ht * x.netPct / m).toFixed(1)}px;background:${x.netPct >= 40 ? '#E2B93B' : '#2D7A3E'}"></i>`).join('')}</div>`; };
const bench = (k, inv) => { const v = D.bench.map(x => x[k]); const lo = Math.min(...v), hi = Math.max(...v); const pos = x => 6 + 88 * (x - lo) / ((hi - lo) || 1); const moi = D.bench.find(x => x.moi)[k]; return `<div class="points">${v.map(x => `<i class="${x === moi ? 'moi' : ''}" style="left:${pos(x).toFixed(1)}%"></i>`).join('')}</div>`; };
const chip = (cls, t) => `<span class="chip ${cls}">${t}</span>`;
const pileGroupes = () => mvPile(D.groupes.map(g => [g.part, palier(g.taux)]));

/* --- Le contenu des sections (la liste déroulée) ---------------------------------------------- */
const dh = (lab, mini) => `<div class="dh"><span class="lab">${lab}</span>${mini ? `<span class="mini">${mini}</span>` : ''}</div>`;
const tuiles = (n, L) => `<div class="tu" style="grid-template-columns:repeat(${n},minmax(0,1fr))">${L.map(([k, v, s, st]) => `<div${st ? ` style="${st}"` : ''}><div class="k">${k}</div><div class="v">${v}</div><div class="s">${s}</div></div>`).join('')}</div>`;

const DROPS = {
  nc: () => dh('Les non-conformités d’hier — vendredi 02/10', 'seuil : 4 / 5 · une tâche notée sous le seuil est à reprendre')
    + `<div class="db"><div class="tu" style="grid-template-columns:repeat(7,minmax(0,1fr))">${D.nc.semaine.map(x => { const d = x.jour.slice(8, 10) + '/' + x.jour.slice(5, 7); return `<div style="${x.jour === '2026-10-02' ? 'outline:2px solid #222;outline-offset:-2px' : ''}"><div class="k">${d}</div><div class="v" style="font-size:19px">${x.notees ? x.notees + ' notée' + (x.notees > 1 ? 's' : '') : '—'}</div><div class="s ${x.nc ? 'c-ko' : 'c-ok'}">${x.notees ? (x.nc ? x.nc + ' non conforme' : 'rien à reprendre') : 'pas de relevé'}</div></div>`; }).join('')}</div>
    <div class="note">La semaine glissante : chaque jour, les tâches notées par le consultant et celles sous le seuil. Une non-conformité d’hier s’affiche ici en rouge avec la tâche, la photo et le commentaire, jusqu’à ce qu’elle soit reprise.</div></div>`,
  taches: () => dh('Les tâches du jour', `${D.taches.obligatoires} obligatoires · ${fP(100 * D.taches.faites / D.taches.obligatoires, 0)} faites · dernière rendue à ${D.taches.derniere} par ${esc(D.taches.par)}`)
    + `<div class="db">${tuiles(3, [['Faites', `<span class="c-ok">${D.taches.faites}</span> <small style="font:400 12px var(--font-ui);color:#888">/ ${D.taches.obligatoires}</small>`, `${D.taches.aControler} à contrôler`, 'background:#eef6f0'], ['Non faites', `<span class="c-att">${D.taches.nonFaites}</span> <small style="font:400 12px var(--font-ui);color:#888">/ ${D.taches.obligatoires}</small>`, '3 d’exploitation', 'background:#fdf3e4'], ['Bloquantes', `<span class="c-ko">${D.taches.bloquantes}</span>`, 'exploitation non rendue', 'background:#fbe9eb']])}
    <div style="margin-top:12px"><div class="lab" style="font:600 9.5px var(--font-ui);letter-spacing:.07em;text-transform:uppercase;color:#666;margin-bottom:6px">Le fil de la journée</div>${mvFil()}</div>
    <div class="note"><b style="color:#222">Pas rendues :</b> ${D.photos.filter(p => p[1] === 'ko').map(p => esc(p[0])).join(' · ')} — contrôle qualité d’ouverture, sans checklist. <b style="color:#222">À contrôler :</b> ${D.photos.filter(p => p[1] === 'ctl').map(p => esc(p[0])).join(' · ')}.</div></div>`,
  photos: () => dh('Les contrôles en photo — 03/10', `8 photos rendues de 09:28 à 09:30 · ${D.photosNote.notees} notées à ${D.photosNote.noteA} · moyenne ${nf(D.photosNote.moyenne, 1)} / 5`)
    + `<div class="db"><div style="display:grid;grid-template-columns:repeat(11,minmax(0,1fr));gap:8px">${D.photos.map(p => `<div><div class="ph" style="height:96px"><span class="st">${p[1] === 'ok' ? chip('ok', '4/5') : p[1] === 'ctl' ? chip('ctl', 'à contrôler') : chip('ko', '✗')}</span></div><div style="font:600 10px var(--font-ui);margin-top:5px;line-height:1.25">${esc(p[0])}</div><div style="font-size:9.5px;color:${p[1] === 'ko' ? COUL.ko : (p[1] === 'ctl' ? COUL.ctl : COUL.ok)}">${p[2] ? p[2] + ' · ' : ''}${p[1] === 'ok' ? 'conforme' : p[1] === 'ctl' ? 'pas encore notée' : 'pas encore rendue'}</div></div>`).join('')}</div>
    <div class="note">Les écarts d’abord · un clic ouvre la photo en grand, avec la note et le commentaire du consultant.</div></div>`,
  stock: () => dh('Le stock', 'inventaire du panel')
    + `<div class="db"><div style="font-size:12px"><b>${D.stock.refs} références</b> à l’inventaire · dernier comptage le <b>${D.stock.compte}</b>, il y a ${D.stock.ilya} · aucune rupture signalée.</div><div class="note">Une référence sous son seuil, ou un comptage de plus de 7 jours, passe la question en orange.</div></div>`,
  vendu: () => dh('Le chiffre de la journée', 'budget du jour, référence des mêmes jours')
    + `<div class="db">${tuiles(4, [['CA du jour', fE(D.ca), `objectif ${fE(D.objectif)} · ${fP(D.atteinte)} atteint · <span class="c-ko">− 7,3 % vs 26/09</span>`], ['Clients', `${D.tickets} <small class="c-ko" style="font:600 12px var(--font-ui)">−2</small>`, `179 à J−7 à la même heure (sam. 26/09) · ${nf(D.produits)} produits vendus`], ['Panier moyen', fE(D.panier, 2), `réseau ${fE(D.panierReseau, 2)} · ${nf(D.ppc, 2)} produits / client`], ['Projection fin de journée', fE(D.projection), `${fP(D.projectionPart)} de la journée écoulée · au rythme : 2 709 €`]])}</div>`,
  objectif: () => { const top = D.record.ca; const x = v => (100 * v / top).toFixed(1) + '%'; return dh(`Objectif du jour — ${fE(D.objectif)} · profil des samedis`, 'le repère noir : l’objectif · l’échelle va jusqu’au record des samedis')
    + `<div class="db"><div style="position:relative;height:46px;margin:6px 0 4px"><div style="position:absolute;left:0;right:0;top:14px;height:16px;border-radius:8px;background:#efe9e1"></div><div style="position:absolute;left:0;top:14px;height:16px;width:${x(D.ca)};border-radius:8px;background:#E2B93B"></div><div style="position:absolute;left:${x(D.objectif)};top:8px;width:2px;height:28px;background:#222"></div><div style="position:absolute;left:${x(D.objectif)};top:-6px;font:600 10px var(--font-ui);transform:translateX(-50%)">objectif ${fE(D.objectif)}</div><div style="position:absolute;left:${x(D.ca)};top:34px;font:600 10px var(--font-ui);transform:translateX(-50%)">réalisé ${fE(D.ca)}</div><div style="position:absolute;right:0;top:34px;font-size:10px;color:#777">record ${fE(D.record.ca)} le ${D.record.date}</div></div>
    <div style="font-size:12px;margin-top:6px">🏆 <b>Objectif atteint</b> · ${fP(D.atteinte)} réalisé · dépassé de <b>${fE(D.ca - D.objectif)}</b> · ${fP(D.projectionPart)} de la journée normalement écoulée.</div></div>`; },
  resultat: () => { const L = [['Chiffre d’affaires', `${D.tickets} clients · ${fE(D.panier, 2)}`, P.ca, 100, '#222', '', true], ['− Coût matière', `seuil ${fP(P.matSeuil)} · recettes vendues`, -P.mat, P.matPct, '#D9822B', 'c-att'], ['= Marge brute', '', P.mb, P.mbPct, '#222', '', true], ['− Invendus et poubelle', 'rien déclaré au panel', 0, 0, '#bbb', 'c-mu'], ['− Main-d’œuvre', `seuil ${fP(P.moSeuil)} · hors ${nf(P.franchiseH, 1)} h de franchisé`, -P.mo, P.moPct, COUL.ok, 'c-ok'], ['− Frais généraux', `seuil ${fP(P.fgSeuil)} · mesure`, -P.fg, P.fgPct, COUL.ko, 'c-ko'], ['= Résultat', '', P.net, P.netPct, COUL.ok, 'c-ok', true]];
    return dh('Le P&L court de la journée', 'matière : coût des recettes vendues · personnel : panel · frais généraux : mesure')
    + `<div class="db">${L.map(([l, s, v, p, c, cl, gras]) => `<div style="display:grid;grid-template-columns:230px minmax(0,1fr) 90px 60px;gap:14px;align-items:center;padding:5px 0;border-top:.5px solid var(--trait)"><div><div style="font-size:12px;font-weight:${gras ? 700 : 600}">${l}</div>${s ? `<div style="font-size:9.5px;color:#888">${s}</div>` : ''}</div><div class="barre" style="height:8px"><i style="width:${p}%;background:${c}"></i></div><div style="text-align:right;font:600 12px var(--font-ui)">${v > 0 && l.startsWith('=') && l.includes('Résultat') ? sgE(v) : (v < 0 ? '−' + fE(-v) : fE(v))}</div><div class="${cl}" style="text-align:right;font:600 11.5px var(--font-ui)">${fP(p)}</div></div>`).join('')}</div>`; },
  reseau: () => { const M = [['Chiffre d’affaires', 'ca', '2e', fE(D.ca), 'médiane 2 644 € · le 1er : 5 540 €'], ['Clients', 'tickets', '2e', nf(D.tickets), 'médiane 177 · le 1er : 286'], ['Panier moyen', 'panier', '2e', fE(D.panier, 2), 'médiane 14,94 € · le 1er : 19,37 €'], ['Marge brute', 'mbPct', '🏆 1er', fP(P.mbPct), 'médiane 60,1 % · le 2e : 60,1 %']];
    return dh('Ta place dans le réseau', '4 magasins ouverts · la journée · anonyme')
    + `<div class="db">${tuiles(4, M.map(([k, cle, rg, v, s]) => [`${k} ${chip(rg.includes('1er') ? 'or' : '', rg + ' / 4')}`, v, `${bench(cle)}<div style="margin-top:6px">${s}</div>`]))}<div class="note">Les autres magasins ne sont jamais nommés : un point par magasin, le tien en bordeaux.</div></div>`; },
  canaux: () => { const C = D.canaux; return dh('Commandes et canaux — la journée', 'comptoir = tickets caisse (dont clients pro) · click & collect · livraison')
    + `<div class="db">${tuiles(3, [['Comptoir', fE(C.comptoir), `${fP(C.comptoirPct)} du CA · ${C.comptoirTickets} tickets · panier ${fE(C.comptoirPanier, 2)}<br>dont clients pro <b>${fE(C.pro)}</b> (${C.proTickets} tickets, à facturer ${fE(C.pro)})`, 'border-left:3px solid #7d756b'], ['Click & collect', `<span style="color:#2F6EA5">${fE(C.cc)}</span>`, `${fP(C.ccPct)} du CA · ${C.ccN} commande · panier ${fE(C.cc, 2)} · 1 remise`, 'border-left:3px solid #2F6EA5'], ['Livraison', fE(C.liv), 'aucune livraison aujourd’hui', 'border-left:3px solid #1d3b5c']])}
    <div style="font-size:11.5px;margin-top:10px"><b>Les commandes du jour :</b> ${C.commandes} commandes dont ${C.commandesComptoir} au comptoir · webshop ${fE(C.cc)} · <b>${C.aPreparer} à préparer</b> · demain : ${C.demainN} commandes déjà prises (${fE(C.demainCa)}) <span style="color:#8D1D2C;font-weight:600">voir les commandes ▾</span></div><div class="note">La liste montre l’heure, les articles et le montant, jamais le nom du client.</div></div>`; },
  categories: () => dh('Ventes par catégorie', 'groupe › catégorie › produit · couleur : taux de marge brute · coef : CA ÷ coût matière')
    + `<div class="db"><table class="tb serre"><thead><tr><th style="width:260px">Groupe</th><th>Poids dans le CA</th><th class="n">CA</th><th class="n">Part</th><th class="n">Marge brute</th><th class="n">Taux</th><th class="n">Coef</th></tr></thead><tbody>${D.groupes.map(g => `<tr><td><b>▸ ${esc(g.nom)}</b><span class="sous">${g.cats} catégorie${g.cats > 1 ? 's' : ''} · ${nf(g.q)} pièces</span></td><td><div class="barre"><i style="width:${(100 * g.ca / D.groupes[0].ca).toFixed(1)}%;background:${palier(g.taux)}"></i></div></td><td class="n"><b>${fE(g.ca)}</b></td><td class="n">${fP(g.part, 0)}</td><td class="n" style="color:${palier(g.taux) === '#E2B93B' ? '#9a7a12' : palier(g.taux)}">${g.mb == null ? '?' : fE(g.mb)}</td><td class="n" style="color:${palier(g.taux) === '#E2B93B' ? '#9a7a12' : palier(g.taux)}">${g.taux == null ? '?' : fP(g.taux, 0)}</td><td class="n">${g.coef == null ? '?' : '×' + nf(g.coef, 2)}</td></tr>`).join('')}
    <tr class="tot"><td>Total <span class="sous">${D.nGroupes} groupes · ${D.nCats} catégories</span></td><td></td><td class="n">${fE(D.ca)}</td><td class="n">100 %</td><td class="n">${fE(D.mbCategories)}</td><td class="n">${fP(D.tauxCategories, 0)}</td><td class="n">×${nf(D.coefTotal, 2)}</td></tr></tbody></table>
    <div class="leg">${[['< 40 %', '#222'], ['40 – 50 %', '#C0182B'], ['50 – 60 %', '#E8892B'], ['60 – 70 %', '#2D7A3E'], ['≥ 70 % or', '#E2B93B'], ['coût matière inconnu', '#bdb6ad']].map(([l, c]) => `<span><i style="background:${c}"></i>${l}</span>`).join('')}<span style="margin-left:auto">▸ ouvre le groupe, puis la catégorie et ses produits · vue Treemap à côté</span></div></div>`,
  offres: () => dh('Promotions et bundles — ce qu’elles rapportent', 'référence : les 4 semaines d’avant')
    + `<div class="db"><div style="font-size:12px">Aucune offre en cours : pas de bundle vendu, pas de promotion posée dans le cockpit.</div><div class="note">Bundles = produits de la catégorie « Bundle & Promotion » du panel · promotions = celles posées par le cockpit sur les jours creux. Une offre en cours affiche ses ventes, sa marge et l’écart à la référence.</div></div>`,
  invendus: () => dh('Invendus et poubelle — la journée', 'pièces jetées déclarées en caisse · leur coût de production se retranche du résultat')
    + `<div class="db"><div style="font-size:12px"><b>Rien déclaré au panel aujourd’hui</b> : aucune pièce jetée encodée en caisse, rien n’est retranché du résultat.</div><div class="note">À encoder avant la fermeture, motif par motif (date dépassée, abîmé, dégustation, qualité). Les reports au lendemain ne se lisent pas dans le panel : la caisse les enregistre comme une production du matin.</div></div>`,
  heures: () => { const HB = 120; return dh('Ce que chaque heure rapporte', 'le chiffre au-dessus : les ventes de l’heure · la case : la marge nette (ventes − matière − rémunération)')
    + `<div class="db"><div class="hrs" style="grid-template-columns:repeat(${H.length},minmax(0,1fr))">${H.map(x => { const k = HB / maxCa, rem = Math.min(x.trav, Math.max(0, x.ca - x.mat)), net = Math.max(0, x.res); return `<div class="col"><span class="ca">${fE(x.ca)}</span><div class="bar" style="height:${(x.ca * k).toFixed(1)}px${x.res === maxRes ? ';outline:2px solid #222;outline-offset:1px' : ''}"><i style="height:${(x.mat * k).toFixed(1)}px;background:var(--beige)"></i><i style="height:${(rem * k).toFixed(1)}px;background:var(--rem)"></i><i style="height:${(net * k).toFixed(1)}px;background:${COUL.ok}"></i></div><span class="h"${x.res === maxRes ? ' style="color:#8D1D2C;font-weight:700"' : ''}>${x.h} h</span><span class="net" style="background:${x.res < 0 ? COUL.ko : (x.res < .2 * x.ca ? COUL.att : COUL.ok)}${x.res === maxRes ? ';outline:2px solid #222;outline-offset:1px' : ''}">${sgE(x.res)}</span></div>`; }).join('')}</div>
    <div class="leg"><span><i style="background:var(--beige)"></i>coût matière</span><span><i style="background:var(--rem)"></i>rémunération</span><span><i style="background:${COUL.ok}"></i>marge nette de l’heure</span><span><i style="background:${COUL.ko}"></i>perte</span><span style="margin-left:auto">la plus remplie : ${hh(9)}, 25 clients · panier ${fE(20.63, 2)}</span></div>
    <div class="note"><b style="color:#222">Le top de 10 – 11 h :</b> ${H.find(x => x.h === 10).top.map(t => `${esc(t.nom)} (${t.q} pcs, ${fE(t.m, 2)} de marge)`).join(' · ')}. Un clic sur une heure ouvre son détail ; le tableau heure par heure complet se déplie en dessous.</div></div>`; },
  personnel: () => dh('Qui est en poste', 'planning du panel · effectif et coût du personnel en % des ventes de l’heure (seuil 33 %)')
    + `<div class="db"><div style="display:grid;grid-template-columns:110px repeat(${H.length},minmax(0,1fr));gap:3px;align-items:center;font-size:10.5px">
      <span style="font:600 9px var(--font-ui);letter-spacing:.07em;text-transform:uppercase;color:#777">Heure</span>${H.map(x => `<span style="text-align:center;color:#666">${x.h} h</span>`).join('')}
      <span style="font:600 9px var(--font-ui);letter-spacing:.07em;text-transform:uppercase;color:#777">En poste</span>${H.map(x => `<span style="text-align:center;color:#fff;font-weight:600;border-radius:3px;padding:4px 0;background:${['#e7c9cd', '#d19aa1', '#b8606b', '#8D1D2C', '#6d1622'][Math.min(4, x.poste)]}">${x.poste}</span>`).join('')}
      <span style="font:600 9px var(--font-ui);letter-spacing:.07em;text-transform:uppercase;color:#777">Personnel / CA</span>${persoPct.map(p => `<span style="text-align:center;font-weight:600;border-radius:3px;padding:4px 0;background:${p > 33 ? '#fbe2e5' : '#e3f0e6'};color:${p > 33 ? COUL.ko : COUL.ok}">${fP(p)}</span>`).join('')}</div>
      <div style="display:flex;gap:18px;flex-wrap:wrap;font-size:11px;margin-top:10px"><span><b>${D.personnel.personnes}</b> personnes · <b>${nf(D.personnel.heures, 1)} h</b> dont ${nf(D.personnel.franchiseH, 1)} h franchisé</span><span>coût <b>${fE(D.personnel.cout)}</b> · ${fP(D.personnel.pct)} du CA (seuil ${D.personnel.seuil} %)</span><span>budget du jour ${fE(D.personnel.budget)} · reste ${fE(D.personnel.reste)}</span><span>${fE(D.personnel.caParH)} de CA / h travaillée</span><span style="color:#8D1D2C;font-weight:600">voir le planning ▾</span></div></div>`,
  mois: () => { const lo = -10, hi = 50, y = v => 100 * (Math.min(hi, Math.max(lo, v)) - lo) / (hi - lo); const bornes = [lo, 0, 10, 20, 40, hi], cb = ['#222', '#C0182B', '#E8892B', '#2D7A3E', '#E2B93B'];
    return dh('Le jour dans le mois', `marge nette en % des ventes, un jour = une barre — <b>${fE(D.mois.reduce((a, x) => a + x.net, 0))}</b> cumulés sur ${fE(D.mois.reduce((a, x) => a + x.ca, 0))} de ventes`)
    + `<div class="db"><div style="position:relative;height:120px;display:flex;flex-direction:column-reverse;border-radius:6px;overflow:hidden">${bornes.slice(0, -1).map((b, i) => `<div style="flex:0 0 ${100 * (bornes[i + 1] - b) / (hi - lo)}%;background:${cb[i]}1f"></div>`).join('')}
      <div style="position:absolute;inset:0;display:grid;grid-template-columns:repeat(${D.mois.length},1fr);gap:40px;padding:0 60px">${D.mois.map(x => `<div style="position:relative"><div style="position:absolute;left:0;right:0;bottom:${y(0)}%;height:${y(x.netPct) - y(0)}%;background:${x.netPct >= 40 ? '#E2B93B' : palier(65)};border-radius:4px 4px 0 0;${x.date === D.date ? 'outline:2px solid #222' : ''}"></div><div style="position:absolute;left:0;right:0;bottom:${y(x.netPct) + 2}%;text-align:center;font:600 10.5px var(--font-ui)">${fP(x.netPct)} · ${fE(x.net)}</div></div>`).join('')}</div></div>
      <div style="display:grid;grid-template-columns:repeat(${D.mois.length},1fr);gap:40px;padding:4px 60px 0;font-size:10.5px;text-align:center;color:#666">${D.mois.map(x => `<span${x.date === D.date ? ' style="font-weight:700;color:#222"' : ''}>${x.date.slice(8, 10)}/${x.date.slice(5, 7)} · ${fE(x.ca)}</span>`).join('')}</div>
      <div class="note">Ici la main-d’œuvre et les frais généraux sont répartis à la moyenne du mois : le 03/10 ressort à ${fE(D.mois[2].net)}, quand le P&L court, qui prend le planning du jour, dit ${fE(P.net)}. Les deux chiffres sont à aligner avant de coder.</div></div>`; },
  note: () => dh('La note du jour — samedi 3 octobre 2026', 'ce qui explique la journée, relu l’an prochain la même semaine · une note par jour')
    + `<div class="db"><div style="display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:18px"><div><div style="height:64px;border:.5px solid var(--trait2);border-radius:8px;padding:9px 11px;font-size:11.5px;color:#999">Ce qui explique la journée : météo, événement, animation, panne, équipe…</div><div style="display:flex;gap:8px;align-items:center;margin-top:8px"><span style="border:.5px solid var(--trait2);border-radius:6px;padding:5px 10px;font-size:11px;color:#999;width:150px">signé (prénom)</span><span style="background:#8D1D2C;color:#fff;border-radius:6px;padding:6px 12px;font:600 11px var(--font-ui)">Enregistrer</span><span style="font-size:10.5px;color:#888">pas encore de note ce jour</span></div></div>
      <div style="font-size:11px;line-height:1.5"><div style="font:600 9.5px var(--font-ui);letter-spacing:.07em;text-transform:uppercase;color:#666">Cette semaine · du 28/09 au 04/10</div><i>Aucune autre note cette semaine.</i><div style="font:600 9.5px var(--font-ui);letter-spacing:.07em;text-transform:uppercase;color:#666;margin-top:8px">Même semaine N-1 · du 29/09 au 05/10</div><i>Aucune note cette semaine-là l’an dernier.</i></div></div></div>`,
};

/* --- Les seize questions --------------------------------------------------------------------- */
const CHAP = [
  ['pret', 'Le magasin est-il prêt ?', 'ce matin, avant d’ouvrir, et pendant la journée'],
  ['chiffre', 'Combien la journée rapporte-t-elle ?', 'le chiffre, l’objectif, le résultat, le réseau'],
  ['origine', 'D’où vient le chiffre ?', 'les canaux, les produits, les offres, la poubelle'],
  ['deroule', 'Comment la journée s’est-elle passée ?', 'les heures, l’équipe, le mois, la note'],
];
const Q = [
  { id: 'nc', ch: 'pret', q: 'Hier, une non-conformité à reprendre ?', r: 'Non', s: 'vendredi 2 octobre · 7 tâches notées, rien à reprendre', v: 'ok', mini: () => chip('ok', '7 notées · 0 à reprendre') },
  { id: 'taches', ch: 'pret', q: 'Les tâches du jour sont-elles faites ?', r: '8 / 11', s: '3 bloquantes pas rendues · 1 à contrôler · dernière rendue à 09:30 par Nathan C.', v: 'ko', mini: mvFil },
  { id: 'photos', ch: 'pret', q: 'Les contrôles en photo sont-ils conformes ?', r: '7 / 8', s: 'photos rendues conformes · 1 à contrôler · 3 pas rendues · moyenne 4,0 / 5', v: 'att', mini: mvCarres },
  { id: 'stock', ch: 'pret', q: 'Le stock est-il à jour ?', r: 'Oui', s: '632 références à l’inventaire · dernier comptage le 01/10, il y a 1 jour', v: 'ok', mini: () => chip('ok', 'compté le 01/10') },
  { id: 'vendu', ch: 'chiffre', q: 'Combien ai-je vendu ?', r: fE(D.ca), s: `${D.tickets} clients · panier ${fE(D.panier, 2)} · − 7,3 % face au samedi 26/09 · projection ${fE(D.projection)}`, v: 'neutre', mini: () => mvCa() },
  { id: 'objectif', ch: 'chiffre', q: 'L’objectif du jour est-il atteint ?', r: fP(D.atteinte), s: `objectif ${fE(D.objectif)} dépassé de ${fE(D.ca - D.objectif)} · record des samedis : ${fE(D.record.ca)} le ${D.record.date}`, v: 'or', mini: mvJauge },
  { id: 'resultat', ch: 'chiffre', q: 'Combien me reste-t-il ?', r: sgE(P.net), s: `${fP(P.netPct)} des ventes après matière, main-d’œuvre et frais généraux · matière ${fP(P.matPct)} pour un seuil de ${P.matSeuil} %`, v: 'ok', mini: () => mvPile([[P.matPct, '#E8CBA0'], [P.moPct, '#D9822B'], [P.fgPct, '#C0182B'], [P.netPct, '#2D7A3E']]) },
  { id: 'reseau', ch: 'chiffre', q: 'Où suis-je dans le réseau ?', r: '2e / 4', s: 'en chiffre d’affaires sur 4 magasins ouverts · 1er en marge brute (60,3 %) · le 1er vend 5 540 €', v: 'neutre', mini: () => bench('ca') },
  { id: 'canaux', ch: 'origine', q: 'Par où passent les ventes ?', r: '96 % comptoir', s: `click & collect ${fE(D.canaux.cc)} · pro ${fE(D.canaux.pro)} à facturer · 2 commandes à préparer · demain : 5 déjà prises (${fE(D.canaux.demainCa)})`, v: 'neutre', mini: () => mvPile([[D.canaux.comptoirPct, '#7d756b'], [D.canaux.ccPct, '#2F6EA5']]) },
  { id: 'categories', ch: 'origine', q: 'Qu’est-ce qui se vend ?', r: 'Viennoiserie', s: `${fP(D.groupes[0].part, 0)} du CA · puis tartes ${fP(D.groupes[1].part, 0)} et boulangerie ${fP(D.groupes[2].part, 0)} · coef ×${nf(D.coefTotal, 2)} · marge brute ${D.tauxCategories} %`, v: 'neutre', mini: pileGroupes },
  { id: 'offres', ch: 'origine', q: 'Mes promotions rapportent-elles ?', r: 'Aucune', s: 'pas de bundle vendu, pas de promotion posée dans le cockpit', v: 'neutre', mini: () => chip('', 'rien en cours') },
  { id: 'invendus', ch: 'origine', q: 'Qu’est-ce qui part à la poubelle ?', r: '0 €', s: 'rien encodé en caisse aujourd’hui · à déclarer avant la fermeture : le coût se retranche du résultat', v: 'att', mini: () => chip('att', 'pas encore déclaré') },
  { id: 'heures', ch: 'deroule', q: 'Quelles heures rapportent ?', r: '10 – 11 h', s: `${sgE(maxRes)} de marge nette · la moins bonne : 6 – 7 h (${sgE(-4.43)}) · ${sgE(D.heuresTot.res)} sur la journée`, v: 'ok', mini: () => mvNet() },
  { id: 'personnel', ch: 'deroule', q: 'L’équipe est-elle bien dimensionnée ?', r: fP(D.personnel.pct), s: `du CA en coût du personnel, seuil ${D.personnel.seuil} % · ${D.personnel.personnes} personnes, ${nf(D.personnel.heures, 1)} h · une heure au-dessus : 6 h`, v: 'ok', mini: mvCellules },
  { id: 'mois', ch: 'deroule', q: 'Comment se place la journée dans le mois ?', r: 'La meilleure', s: `des 3 jours d’octobre : ${fP(D.mois[2].netPct)} de marge nette · ${fE(D.mois.reduce((a, x) => a + x.net, 0))} cumulés sur ${fE(D.mois.reduce((a, x) => a + x.ca, 0))}`, v: 'or', mini: () => mvMois() },
  { id: 'note', ch: 'deroule', q: 'Qu’est-ce qui explique la journée ?', r: 'Pas de note', s: 'une ligne relue l’an prochain la même semaine : météo, événement, animation, panne, équipe', v: 'att', mini: () => chip('att', '✎ à écrire') },
];
Q.forEach(x => { x.q = x.q.replace(/ \?$/, '\u00a0?'); });
const qd = id => Q.find(x => x.id === id);
const nV = v => Q.filter(x => x.v === v).length;

/* --- Le squelette commun --------------------------------------------------------------------- */
const LIB = { a: 'Maquette A · une ligne par question', b: 'Maquette B · l’essentiel, 4 chapitres', c: 'Maquette C · vignettes par urgence' };
const entete = () => `<div class="hd"><img src="/public/assets/img/logo.png" alt=""><div><div class="t">${esc(D.magasin)}</div><div class="s">Dashboard magasin · ${esc(D.jourLib)} · relu à ${D.relu} · 🔔 ${D.messagesPanel}</div></div>
  <div class="nav"><span class="on">Jour</span><span>Semaine</span><span>Mois</span><span>Trimestre</span><span>Année</span><span class="dt">‹ 03/10/2026 ›</span><span class="cl">⎙ imprimer A4</span></div></div>`;
const compte = () => `<div class="compte"><b style="color:#222">16 questions</b>${chip('ko', `<span class="vd ko"></span>${nV('ko')} à reprendre`)}${chip('att', `<span class="vd att"></span>${nV('att')} à surveiller`)}${chip('ok', `<span class="vd ok"></span>${nV('ok') + nV('or')} en ordre, dont ${nV('or')} au niveau or`)}${chip('', `<span class="vd neutre"></span>${nV('neutre')} pour information`)}<span style="margin-left:auto;color:#777">un clic sur une question ouvre sa section complète · un second clic la replie</span></div>`;
const templates = () => Q.map(x => `<template id="t-${x.id}">${DROPS[x.id]()}</template>`).join('');
const SCRIPT = def => `<script>
function fermer(){document.querySelectorAll('.drop.on').forEach(function(z){z.classList.remove('on');z.innerHTML='';delete z.dataset.ouvert;});document.querySelectorAll('[data-q].on').forEach(function(q){q.classList.remove('on');});}
function ouvrir(id){var q=document.querySelector('[data-q="'+id+'"]');var z=document.getElementById(q.dataset.zone);fermer();z.innerHTML=document.getElementById('t-'+id).innerHTML;z.dataset.ouvert=id;z.classList.add('on');q.classList.add('on');if(z.hasAttribute('data-encoche')){var e=document.createElement('span');e.className='encoche';var zr=z.getBoundingClientRect(),qr=q.getBoundingClientRect();e.style.left=(qr.left+qr.width/2-zr.left-8)+'px';z.appendChild(e);}}
function hauteur(){var c=document.querySelector('.contenu');return c.offsetTop+c.offsetHeight;}
document.addEventListener('click',function(e){var q=e.target.closest('[data-q]');if(!q)return;var z=document.getElementById(q.dataset.zone);if(z.dataset.ouvert===q.dataset.q){fermer();}else{ouvrir(q.dataset.q);}mesurer();});
var H0=null;function mesurer(){var o=document.querySelector('[data-q].on');var h=hauteur();document.getElementById('mesure').innerHTML='replié : <b>'+H0+' px</b>'+(o?' · « '+o.dataset.lib+' » ouvert : <b>'+h+' px</b>':'');}
window.addEventListener('load',function(){fermer();H0=hauteur();ouvrir('${def}');mesurer();});
</script>`;
const page = (id, titre, corps, def) => `<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>${titre}</title>
<link rel="stylesheet" href="/public/assets/ds/global.css"><link rel="stylesheet" href="a4.css">
<style>@media print{@page{size:A4 portrait;margin:0}body{background:#fff}.feuille{margin:0;box-shadow:none;zoom:.64}.feuille .pied{display:none}}</style></head>
<body><div class="feuille" id="mq-${id}"><div class="contenu">${entete()}${compte()}${corps}</div>
<div class="pied"><span>A4 portrait · <b>1 240 × 1 754 px</b> (150 dpi)</span><span id="mesure"></span><span class="mq">${LIB[id]}</span></div></div>
${templates()}${SCRIPT(def)}</body></html>`;
const lib = x => esc(x.q.replace(/\s*\?$/, ''));

/* --- A : une ligne par question -------------------------------------------------------------- */
function pageA() {
  const corps = CHAP.map(([k, t, s], i) => `<div class="a-chap"><span class="n">${i + 1}</span><span class="t">${t}</span><small>${s}</small></div><div class="a-liste">${Q.filter(x => x.ch === k).map(x => `<div class="a-ligne" data-q="${x.id}" data-zone="z-${x.id}" data-lib="${lib(x)}"><span class="vd ${x.v}"></span><span class="q">${esc(x.q)}</span><span class="r ${x.v === 'ko' ? 'c-ko' : x.v === 'att' ? 'c-att' : x.v === 'or' ? 'c-or' : ''}">${x.r}</span><span class="s">${x.s}</span><span>${x.mini()}</span><span class="fl">▾</span></div><div class="drop" id="z-${x.id}"></div>`).join('')}</div>`).join('');
  return page('a', 'Vue Jour A4 — A, une ligne par question', corps, 'heures');
}

/* --- B : l'essentiel puis quatre chapitres en colonnes ------------------------------------------ */
function pageB() {
  const ess = `<div class="b-ess"><div><div class="ph1">Samedi : <em>${fE(D.ca)}</em> vendus, l’objectif dépassé de <em>${fE(D.ca - D.objectif)}</em>, <em>${sgE(P.net)}</em> de résultat — la meilleure journée d’octobre.</div>
    <div class="ph2"><b style="color:#C0182B">À reprendre :</b> 3 tâches bloquantes pas rendues (biscuiterie, pâtisseries, traiteur), 1 photo à contrôler. <b style="color:#9a5a06">Avant de fermer :</b> déclarer la poubelle, écrire la note du jour.</div></div>
    <div class="b-kpi"><div><div class="k">Vendu</div><div class="v">${fE(D.ca)}</div><div class="s">${D.tickets} clients · ${fE(D.panier, 2)}</div></div><div style="background:#f7edc8"><div class="k">Objectif</div><div class="v">${fP(D.atteinte)}</div><div class="s">🏆 + ${fE(D.ca - D.objectif)}</div></div><div style="background:#e3f0e6"><div class="k">Résultat</div><div class="v c-ok">${sgE(P.net)}</div><div class="s">${fP(P.netPct)} des ventes</div></div><div><div class="k">Réseau</div><div class="v">2e / 4</div><div class="s">1er en marge brute</div></div></div></div>`;
  const corps = ess + CHAP.map(([k, t, s], i) => `<div class="b-band"><div class="bh"><span class="n">${i + 1}</span><span class="t">${t}</span><small>${s}</small></div><div class="b-cols">${Q.filter(x => x.ch === k).map(x => `<div class="b-col" data-q="${x.id}" data-zone="z-${k}" data-lib="${lib(x)}"><span class="q">${esc(x.q)}</span><span class="r"><span class="vd ${x.v}"></span><span class="${x.v === 'ko' ? 'c-ko' : x.v === 'att' ? 'c-att' : x.v === 'or' ? 'c-or' : ''}">${x.r}</span></span><span class="s">${x.s}</span><span class="mvz">${x.mini()}</span><span class="vs"><span class="fl" style="width:18px;height:18px;font-size:9px">▾</span>voir la section</span></div>`).join('')}</div><div class="drop" id="z-${k}" data-encoche></div></div>`).join('');
  return page('b', 'Vue Jour A4 — B, l’essentiel puis quatre chapitres', corps, 'categories');
}

/* --- C : les vignettes, rangées par urgence ---------------------------------------------------- */
function pageC() {
  const RANGS = [
    ['À faire maintenant', 'ce qui attend une action du magasin aujourd’hui', ['taches', 'photos', 'invendus', 'note']],
    ['Le chiffre', 'ce que la journée a vendu et ce qu’il en reste', ['vendu', 'objectif', 'resultat', 'reseau']],
    ['Pourquoi', 'ce qui fait le chiffre', ['heures', 'categories', 'canaux', 'personnel']],
    ['Le contexte', 'ce qui est en ordre ou à garder en tête', ['mois', 'nc', 'stock', 'offres']],
  ];
  const gr = { taches: () => mvFil(), photos: () => mvCarres(), invendus: () => `<div class="pile"><i style="width:0"></i></div><div style="font-size:9.5px;color:#888;margin-top:4px">0 pièce déclarée · 4 magasins sur 4 sans déclaration</div>`, note: () => `<div style="height:30px;border:.5px dashed var(--trait2);border-radius:6px;font-size:10px;color:#aaa;padding:7px 9px">Ce qui explique la journée…</div>`,
    vendu: () => mvCa(42), objectif: () => mvJauge(), resultat: () => mvPile([[P.matPct, '#E8CBA0'], [P.moPct, '#D9822B'], [P.fgPct, '#C0182B'], [P.netPct, '#2D7A3E']]) + '<div style="display:flex;justify-content:space-between;font-size:9px;color:#888;margin-top:4px"><span>matière</span><span>équipe</span><span>frais</span><span>résultat</span></div>', reseau: () => `<div style="display:grid;grid-template-columns:44px 1fr;gap:4px 8px;align-items:center;font-size:9.5px;color:#888"><span>CA</span>${bench('ca')}<span>marge</span>${bench('mbPct')}</div>`,
    heures: () => mvNet(42), categories: () => pileGroupes() + `<div style="font-size:9.5px;color:#888;margin-top:4px">${D.groupes.slice(0, 3).map(g => esc(g.nom)).join(' · ')} · ${D.nGroupes - 3} autres</div>`, canaux: () => mvPile([[D.canaux.comptoirPct, '#7d756b'], [D.canaux.ccPct, '#2F6EA5']]) + '<div style="display:flex;justify-content:space-between;font-size:9px;color:#888;margin-top:4px"><span>comptoir</span><span>click & collect</span></div>', personnel: () => mvCellules(),
    mois: () => mvMois(42), nc: () => `<div class="carres">${D.nc.semaine.map(x => `<i style="width:auto;flex:1;background:${x.notees ? COUL.ok : '#e4ddd3'}"></i>`).join('')}</div><div style="font-size:9.5px;color:#888;margin-top:4px">les 7 derniers jours</div>`, stock: () => chip('ok', '632 références'), offres: () => chip('', 'rien en cours') };
  const corps = RANGS.map(([t, s, ids], i) => `<div class="c-rang"><span class="t">${t}</span><small>${s}</small></div><div class="c-zone"><div class="c-grille">${ids.map(id => { const x = qd(id); return `<div class="c-tuile ${x.v}" data-q="${x.id}" data-zone="z-r${i}" data-lib="${lib(x)}"><span class="q">${esc(x.q)}</span><span class="fl">▾</span><span class="r ${x.v === 'ko' ? 'c-ko' : x.v === 'att' ? 'c-att' : x.v === 'or' ? 'c-or' : ''}">${x.r}</span><span class="s">${x.s}</span><div class="gr">${gr[x.id]()}</div></div>`; }).join('')}</div><div class="drop" id="z-r${i}" data-encoche></div></div>`).join('');
  return page('c', 'Vue Jour A4 — C, les vignettes rangées par urgence', corps, 'resultat');
}

/* --- Génération + captures ------------------------------------------------------------------------ */
(async () => {
  fs.writeFileSync(OUT + 'a.html', pageA()); fs.writeFileSync(OUT + 'b.html', pageB()); fs.writeFileSync(OUT + 'c.html', pageC());
  const b = await chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost'] });
  const c = await b.newContext({ viewport: { width: 1300, height: 1000 }, deviceScaleFactor: 1.25 });
  const p = await c.newPage(); const err = []; p.on('pageerror', e => err.push(e.message)); p.on('console', m => { if (m.type() === 'error') err.push(m.text()); });
  const mesures = {};
  for (const id of ['a', 'b', 'c']) {
    await p.goto('http://127.0.0.1:8099/docs/maquettes/vue-jour-a4/' + id + '.html', { waitUntil: 'load' }); await p.waitForTimeout(800);
    const m = await p.evaluate(() => { const o = document.querySelector('[data-q].on'); const ouvert = hauteur(); let pire = [0, '']; document.querySelectorAll('[data-q]').forEach(q => { ouvrir(q.dataset.q); const h = hauteur(); if (h > pire[0]) { pire = [h, q.dataset.lib]; } }); fermer(); const ferme = hauteur(); ouvrir(o.dataset.q); mesurer(); return { ouvert, ferme, q: o.dataset.lib, pire: pire[0], pireQ: pire[1], deborde: document.documentElement.scrollWidth > document.documentElement.clientWidth }; });
    mesures[id] = m;
    console.log(id, ': replié', m.ferme, 'px · ouvert', m.ouvert, 'px (' + m.q + ') · pire', m.pire, 'px (' + m.pireQ + ') · A4 1754 px · utile 1720 px', m.pire > 1720 ? '· DÉBORDE' : '· tient', m.deborde ? '· largeur déborde' : '');
    const el = await p.$('#mq-' + id); await el.screenshot({ path: OUT + id + '.jpg', type: 'jpeg', quality: 84 });
    await p.evaluate(() => { fermer(); mesurer(); }); await el.screenshot({ path: OUT + id + '-replie.jpg', type: 'jpeg', quality: 84 });
  }
  const PL = [
    ['a', 'A — Une ligne par question', 'Seize questions en quatre chapitres, une ligne chacune : la pastille, la question, la réponse chiffrée, la phrase qui la justifie et un mini-graphique. Un clic déroule la section complète sous sa ligne (ici « Quelles heures rapportent ? »).', ['La plus dense : repliée, la page n’occupe que 60 % de la feuille.', 'Se lit de haut en bas comme une check-list, sans chercher.', 'Le plus simple à coder : chaque carte actuelle devient le contenu d’une ligne.'], ['Peu de graphique replié : les chiffres font le travail.', 'Seize lignes de même poids : l’urgent ne saute pas aux yeux sans les pastilles.']],
    ['b', 'B — L’essentiel, puis quatre chapitres', 'La journée en une phrase et quatre chiffres en tête, puis quatre bandes (prêt, combien, d’où, comment) de quatre questions en colonnes. La section s’ouvre sous sa bande, une encoche sous la question (ici « Qu’est-ce qui se vend ? »).', ['La phrase du haut répond seule à « comment s’est passée la journée ? ».', 'Chaque chapitre se lit d’un coup d’œil, en largeur.', 'Le « À reprendre » est écrit, pas déduit.'], ['La plus haute repliée, à cause de la phrase et des quatre chiffres de tête.', 'La phrase de tête est à composer à partir des règles de verdict.']],
    ['c', 'C — Les vignettes, rangées par urgence', 'Seize vignettes à mini-graphique, rangées par ce qu’elles demandent : à faire maintenant, le chiffre, pourquoi, le contexte. La bordure du haut porte le verdict ; la section s’ouvre sous la rangée (ici « Combien me reste-t-il ? »).', ['L’action d’abord : la première rangée dit quoi faire.', 'Un graphique par question, lisible de loin, à l’écran du magasin.', 'Se transpose tel quel en tuiles au téléphone.'], ['Des vignettes de hauteur fixe : peu de place pour une phrase plus longue.', 'Si l’ordre suit les verdicts du jour, une question change de place d’un jour à l’autre.']],
  ];
  const planche = `<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>Vue Jour A4 : trois maquettes</title><link rel="stylesheet" href="/public/assets/ds/global.css"><style>
  body{margin:0;background:#EAE4DC;font-family:var(--font-ui);color:#222}.pl{width:1760px;padding:30px 36px}.u{font:600 11px var(--font-ui);letter-spacing:.08em;text-transform:uppercase;color:#8D1D2C}
  h1{font:400 30px var(--font-display);margin:6px 0 6px}.acc{font-size:13px;line-height:1.5;color:#555;max-width:1300px;margin:0 0 20px}
  .g{display:grid;grid-template-columns:repeat(3,1fr);gap:26px}.g img{width:100%;display:block;border-radius:6px;box-shadow:0 2px 12px rgba(0,0,0,.15)}
  h2{font:400 20px var(--font-display);margin:14px 0 4px}.g p{font-size:12px;line-height:1.5;color:#555;margin:0 0 8px}.m{font-size:11.5px;color:#222;margin:0 0 6px}
  h4{font:600 10px var(--font-ui);letter-spacing:.08em;text-transform:uppercase;color:#666;margin:10px 0 4px}ul{margin:0;padding-left:18px;font-size:12px;line-height:1.5}ul.p li::marker{color:#2D7A3E}ul.m2 li::marker{color:#C0182B}</style></head>
  <body><div class="pl" id="planche"><span class="u">Maquettes · dashboard magasin, vue Jour · 03/10/2026</span><h1>La vue Jour en une page A4 — une question par section, la section en liste déroulante</h1>
  <p class="acc">Aujourd’hui la vue Jour de Halle fait <b>4 555 px</b> de haut, soit 2,6 pages A4. Les trois maquettes posent les mêmes seize questions, avec les chiffres réels de Halle du samedi 3 octobre relus vers 17 h 15. Repliées, elles tiennent dans une page A4 (1 240 × 1 754 px à 150 dpi). Un clic sur une question déroule la section complète d’aujourd’hui sous la question. Chacune des seize sections a été ouverte tour à tour : la page tient toujours dans la feuille. Les photos des contrôles sont hachurées : ce sont des liens signés de 20 minutes.</p>
  <div class="g">${PL.map(([id, t, acc, plus, moins]) => `<div><img src="${id}.jpg" alt=""><h2>${t}</h2><p>${acc}</p><div class="m">Replié : <b>${mesures[id].ferme} px</b> · la plus haute section ouverte (« ${mesures[id].pireQ} ») : <b>${mesures[id].pire} px</b> · la feuille : 1 754 px</div><h4>Ce que ça apporte</h4><ul class="p">${plus.map(x => `<li>${x}</li>`).join('')}</ul><h4>Limites</h4><ul class="m2">${moins.map(x => `<li>${x}</li>`).join('')}</ul></div>`).join('')}</div></div></body></html>`;
  fs.writeFileSync(OUT + 'planche.html', planche);
  const q = await c.newPage(); await q.setViewportSize({ width: 1760, height: 1000 });
  await q.goto('http://127.0.0.1:8099/docs/maquettes/vue-jour-a4/planche.html', { waitUntil: 'load' }); await q.waitForTimeout(900);
  await (await q.$('#planche')).screenshot({ path: OUT + 'planche.jpg', type: 'jpeg', quality: 80 });
  fs.writeFileSync(OUT + 'mesures.json', JSON.stringify(mesures, null, 1));
  console.log('erreurs :', err.join(' | ') || 'aucune');
  await b.close();
})().catch(e => { console.error(e); process.exit(1); });
