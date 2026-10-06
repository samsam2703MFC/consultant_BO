/* Maquettes « Mes primes » (app worker) et « Paramètres des primes » (cockpit), sur les chiffres réels
 * de Halle (reel-halle-primes.json, lu le 06/10/2026). Aucun nom : « vous » et des collègues anonymes.
 *   node docs/maquettes/primes/generer.js   → a.html, b-moi.html, b-magasin.html, cockpit.html, planche.html */
const fs = require('fs'), path = require('path');
const D = __dirname, R = JSON.parse(fs.readFileSync(path.join(D, 'reel-halle-primes.json'), 'utf8'));
const O = R.octobre, S9 = R.septembre;

// ---------- les nombres
const NB = ' ';
const eur0 = v => Math.round(v).toLocaleString('fr-BE') + NB + '€';
const eur2 = v => v.toLocaleString('fr-BE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + NB + '€';
const n1 = v => v.toLocaleString('fr-BE', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
const n2 = v => v.toLocaleString('fr-BE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const pc = v => Math.round(v) + NB + '%';
const pc1 = v => n1(v) + NB + '%';
const ent = v => Math.round(v).toLocaleString('fr-BE');

// ---------- le magasin : octobre en cours, la projection au profil de la semaine
const auj = 1233.9;   // la projection du jour par le cockpit (mardi, 16 h 55 : 1 192,85 € encaissés)
const caJour = Object.fromEntries(O.jours.map(j => [j.date, j.ca]));
const semaine = [], weekend = [];
for (const j of O.jours) {
  const d = new Date(j.date + 'T12:00:00'); const v = j.date === '2026-10-06' ? auj : j.ca;
  (d.getDay() === 0 || d.getDay() === 6 ? weekend : semaine).push(v);
}
const moy = a => a.reduce((x, y) => x + y, 0) / a.length;
let restSem = 0, restWe = 0;
for (let d = 7; d <= 31; d++) { const w = new Date(2026, 9, d).getDay(); if (w === 0 || w === 6) restWe++; else restSem++; }
const faitAuj = O.ca - caJour['2026-10-06'] + auj;
const projection = faitAuj + restSem * moy(semaine) + restWe * moy(weekend);
const objectif = O.objectifMois, atteinteProj = projection / objectif, atteinteFait = O.ca / objectif;
const joursRestants = restSem + restWe, objJour = objectif / O.joursOuverts;

// ---------- le modèle de la prime magasin : des euros par heure prestée, par palier d'atteinte
const PALIERS = [
  { pct: 97, eh: 0.5, lib: 'Encouragement' },
  { pct: 100, eh: 1.0, lib: 'Objectif' },
  { pct: 105, eh: 1.5, lib: 'Dépassé' },
  { pct: 110, eh: 2.0, lib: 'Record' },
];
const HEURES_MIN = 20;
// l'équipe du mois : vous + cinq collègues, 604 h au planning (19,5 h par jour × 31)
const EQUIPE = { vous: 128, autres: [112, 104, 96, 88, 76] };
const heuresEquipe = EQUIPE.vous + EQUIPE.autres.reduce((a, b) => a + b, 0);
const palierDe = pct => { let p = null; for (const x of PALIERS) { if (pct >= x.pct) p = x; } return p; };
const manque = pct => Math.max(0, objectif * pct / 100 - projection);

// ---------- vous : le mois en cours (6 jours), le jour, le record, le score
const V = {
  heuresFaites: 38.5, heuresMois: EQUIPE.vous, ca: 3290, tickets: 262, lignes: 702,
  croisees: 146,                 // tickets à 2 lignes ou plus
  record: 2.8, recordMois: 'mars 2026',
  score: 59, rangMag: 2, surMag: 6, rangRes: 9, surRes: 31, scorePremier: 61,
  aujTickets: 61, aujCroisees: 29, aujService: '06:00 – 13:00',
};
V.lt = V.lignes / V.tickets; V.taux = 100 * V.croisees / V.tickets; V.caH = V.ca / V.heuresFaites;
const CIBLE_CROIS = 55;   // la cible du magasin, en % de tickets à 2 lignes ou plus
const CROIS_PALIERS = [{ plus: 0, m: 40 }, { plus: 5, m: 70 }, { plus: 10, m: 100 }];
const REC = { eurDixieme: 100, max: 3 };
const MEILLEURE = { magasin: 75, reseau: 150 };
const primeCrois = taux => { let m = 0; for (const p of CROIS_PALIERS) { if (taux >= CIBLE_CROIS + p.plus) m = p.m; } return m; };
const primeRecord = (lt, rec) => { if (lt <= rec) return 0; return Math.min(REC.max, Math.max(0, Math.floor((lt - rec + 1e-9) / 0.1) - 1)) * REC.eurDixieme; };
const acquisCrois = primeCrois(V.taux), acquisRecord = primeRecord(V.lt, V.record);
const acquis = acquisCrois + acquisRecord;
const palProj = palierDe(100 * atteinteProj);
const primeMagProj = palProj ? palProj.eh * V.heuresMois : 0;
const aPortee = CROIS_PALIERS[1].m + REC.eurDixieme + MEILLEURE.magasin + PALIERS[1].eh * V.heuresMois;
const SEMAINES = [{ l: 'S36', t: 50 }, { l: 'S37', t: 53 }, { l: 'S38', t: 51 }, { l: 'S39', t: 54 }, { l: 'S40', t: 57 }, { l: 'S41', t: 56, on: true }];
// les mois passés : octobre est réel (CA, objectif), les mois d'avant illustrent les règles
const HIST = [
  { m: 'Septembre', tot: 0, det: ['ventes croisées 52 % (cible 55 %)', '2,6 lignes/ticket, record 2,8', 'magasin à 83 %', '3e du magasin'] },
  { m: 'Août', tot: 296, det: ['record battu + 0,2 : 100 €', 'meilleure du magasin : 75 €', 'magasin à 101 % : 121 h × 1 € = 121 €'] },
  { m: 'Juillet', tot: 40, det: ['ventes croisées 57 % : 40 €', 'magasin à 92 %'] },
  { m: 'Juin', tot: 158, det: ['ventes croisées 55 % : 40 €', 'magasin à 104 % : 118 h × 1 € = 118 €'] },
];

// ---------- les pièces d'écran
const e = s => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;');
const nav = actif => `<nav class="emp-bottom-nav">${[['dashboard', 'Accueil', 'bi-house'], ['schedule', 'Planning', 'bi-calendar3'], ['tasks', 'Tâches', 'bi-check2-square'], ['primes', 'Primes', 'bi-trophy'], ['profile', 'Profil', 'bi-person']]
  .map(([k, l, i]) => `<a class="emp-bottom-item${k === actif ? ' is-active' : ''}"><i class="bi ${i}"></i><span>${l}</span></a>`).join('')}</nav>`;
const page = (titre, mq, sous, corps) => `<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>${e(titre)}</title>
<link rel="stylesheet" href="/public/employee/assets/atelier/app.css"><link rel="stylesheet" href="/public/employee/assets/bootstrap-icons/bootstrap-icons.css"><link rel="stylesheet" href="/public/employee/assets/atelier/marque.css"><link rel="stylesheet" href="pr.css"></head>
<body><div class="tel">
<div class="emp-topbar"><div class="emp-topbar-inner"><div><h1 class="emp-title">Mes primes</h1><p class="emp-subtitle">${e(sous)}</p></div><img src="/public/employee/assets/atelier/logo.png" alt=""></div></div>
<div class="emp-content">${corps}</div>${nav('primes')}</div></body></html>`;
const sous = `${R.magasin.replace('Atelier by - ', '')} · octobre 2026, au 6`;

const barre = (v, max, cls, cible) => `<div class="barre${cible ? ' avec-cible' : ''}"><i class="${cls || ''}" style="width:${Math.max(2, Math.min(100, 100 * v / max)).toFixed(1)}%"></i>${cible ? `<span class="cible" data-l="${e(cible.l)}" style="left:${(100 * cible.v / max).toFixed(1)}%"></span>` : ''}</div>`;

// la carte « aujourd'hui »
const carteAuj = () => `<div class="pr-card"><div class="auj"><div class="cercle" style="--p:${Math.round(100 * V.aujCroisees / V.aujTickets)}%"><b>${V.aujCroisees}</b><small>sur ${V.aujTickets}</small></div>
<div class="tx"><div class="q">${V.aujCroisees} ventes croisées aujourd’hui</div>${pc(100 * V.aujCroisees / V.aujTickets)} de vos tickets ont 2 articles ou plus. La cible du magasin : ${CIBLE_CROIS}${NB}%.<div class="mu" style="font-size:11.5px;margin-top:3px">Service ${V.aujService} · « Et avec ça ? »</div></div></div></div>`;

// les trois briques de la prime individuelle
const briques = () => `
<div class="brique"><div class="t"><b>Ventes croisées</b><span class="puce ${acquisCrois ? 'ok' : 'mu'}">${acquisCrois ? acquisCrois + NB + '€ acquis' : 'sous la cible'}</span></div>
 <div class="n">${V.croisees}<small>tickets à 2 articles ou plus, sur ${V.tickets}</small></div>
 ${barre(V.taux, 70, acquisCrois ? 'ok' : 'att', { v: CIBLE_CROIS, l: 'cible ' + CIBLE_CROIS + NB + '%' })}
 <div class="s"><b>${pc(V.taux)}</b> de vos tickets. Prochain palier : <b>${CIBLE_CROIS + 5}${NB}%</b> = ${CROIS_PALIERS[1].m}${NB}€, soit ${Math.ceil((CIBLE_CROIS + 5) / 100 * V.tickets - V.croisees)} ventes croisées de plus sur les mêmes tickets.</div></div>
<div class="brique"><div class="t"><b>Bats ton record</b><span class="puce ${acquisRecord ? 'ok' : 'att'}">${acquisRecord ? acquisRecord + NB + '€ acquis' : 'à ' + n2(V.record - V.lt) + ' du record'}</span></div>
 <div class="n">${n2(V.lt)}<small>lignes par ticket · record ${n1(V.record)} (${V.recordMois})</small></div>
 ${barre(V.lt, 3.2, acquisRecord ? 'ok' : '', { v: V.record, l: 'record ' + n1(V.record) })}
 <div class="s">Le premier dixième au-dessus pose le record, puis <b>chaque dixième = ${REC.eurDixieme}${NB}€</b> (${REC.max} au plus). À ${n1(V.record + 0.2)} : ${REC.eurDixieme}${NB}€ · à ${n1(V.record + 0.3)} : ${2 * REC.eurDixieme}${NB}€.</div></div>
<div class="brique"><div class="t"><b>Meilleure vendeuse</b><span class="puce mu">${V.rangMag}e du magasin</span></div>
 <div class="n">${V.score}<small>score · ${V.rangRes}e du réseau sur ${V.surRes}</small></div>
 ${barre(V.score, V.scorePremier * 1.15, '', { v: V.scorePremier, l: '1re à ' + V.scorePremier })}
 <div class="s">Vos ventes ramenées à vos heures (${eur0(V.caH)}/h sur ${n1(V.heuresFaites)}${NB}h), corrigées du créneau. 1re du magasin : <b>${MEILLEURE.magasin}${NB}€</b> · 1re du réseau : <b>${MEILLEURE.reseau}${NB}€</b>.</div></div>`;

// la prime magasin : jauge, paliers, votre part
const jauge = () => `<div class="jauge"><div class="b"><i class="proj" style="width:${(100 * Math.min(1.1, atteinteProj) / 1.1).toFixed(1)}%"></i><i class="fait" style="width:${(100 * atteinteFait / 1.1).toFixed(1)}%"></i>${PALIERS.map(p => `<span class="rep" style="left:${(100 * p.pct / 110).toFixed(1)}%"></span>`).join('')}</div>
<div class="leg"><span>${eur0(O.ca)} encaissés · ${pc1(100 * atteinteFait)}</span><span>objectif ${eur0(objectif)}</span></div></div>`;
const paliers = () => `<div class="paliers">${PALIERS.map(p => `<div class="pal${palProj && palProj.pct === p.pct ? ' on' : ''}${!palProj && p.pct === 97 ? ' prochain' : ''}"><div class="p">${p.pct}${NB}%</div><div class="e">${eur0(p.eh * V.heuresMois)}</div><div class="v">${n2(p.eh)}${NB}€ / h</div></div>`).join('')}</div>`;
const jours = () => {
  const max = Math.max(objJour, ...O.jours.map(j => j.ca)) * 1.05; const out = [];
  for (let d = 1; d <= 31; d++) {
    const k = '2026-10-' + String(d).padStart(2, '0'); const w = new Date(2026, 9, d).getDay();
    const v = k === '2026-10-06' ? auj : caJour[k];
    out.push(v === undefined ? '<i class="vide" style="height:3px"></i>' : `<i class="${k === '2026-10-06' ? 'auj' : (w === 0 || w === 6 ? 'we' : '')}" style="height:${(100 * v / max).toFixed(0)}%"></i>`);
  }
  return `<div class="jours" style="position:relative"><span class="obj" style="position:absolute;bottom:${(100 * objJour / max).toFixed(0)}%"></span>${out.join('')}</div><div class="jours-leg"><span>1er oct.</span><span>objectif du jour ${eur0(objJour)}</span><span>31 oct.</span></div>`;
};
const parts = () => `<div class="parts"><i class="moi" style="flex:${EQUIPE.vous}"></i>${EQUIPE.autres.map(h => `<i style="flex:${h}"></i>`).join('')}</div>
<div class="note">Répartie au prorata des heures prestées : <b>vous ${EQUIPE.vous}${NB}h</b>, l’équipe ${ent(heuresEquipe)}${NB}h, soit ${pc(100 * EQUIPE.vous / heuresEquipe)} de la prime du magasin pour vous. Sous ${HEURES_MIN}${NB}h dans le mois, pas de part.</div>`;
const projTexte = () => `Au rythme de la semaine (${eur0(moy(semaine))} les jours de semaine, ${eur0(moy(weekend))} le week-end), le mois finit à <b>${eur0(projection)}</b>, soit <b>${pc1(100 * atteinteProj)}</b>. ${palProj ? `Palier ${palProj.pct}${NB}% atteint : ${eur0(primeMagProj)} pour vous.` : `Pour ${PALIERS[0].pct}${NB}% il manque ${eur0(manque(PALIERS[0].pct))}, soit <b>${eur0(manque(PALIERS[0].pct) / joursRestants)} par jour</b> sur ${joursRestants} jours ; pour 100${NB}%, ${eur0(manque(100) / joursRestants)} par jour.`}`;
const hist = () => `<div class="hist">${HIST.map(h => `<div class="ligne"><span class="m">${h.m}</span><span class="det">${h.det.join(' · ')}</span><span class="tot${h.tot ? '' : ' zero'}">${h.tot ? eur0(h.tot) : '0 €'}</span></div>`).join('')}</div>`;
const regles = () => `<div class="note">Tout se calcule sur le mois complet et se paie début du mois suivant, en bons payés par la marque. Les ventes sont celles encaissées à votre nom ; les heures, celles du planning. Le calcul est le même pour toutes : s’il vous semble faux, dites-le, on vérifie.</div>`;

// ---------- A : une seule page
const A = page('Mes primes — A, une page', 'A · une page', sous, `
<div class="pr-card rubis"><div class="k">Ce mois-ci, au 6 octobre</div><div class="gros">${eur0(acquis)}<small>acquis si le mois finit comme ça</small></div>
<div style="font-size:13px;margin-top:4px">Jusqu’à <b>${eur0(aPortee)}</b> à portée : ventes croisées à ${CIBLE_CROIS + 5}${NB}%, record battu de 0,2, 1re du magasin, objectif du magasin atteint.</div>
${barre(6, 31, '')}<div class="k" style="margin-top:6px">6 jours sur 31 · ${n1(V.heuresFaites)}${NB}h prestées sur ${V.heuresMois} au planning</div></div>
${carteAuj()}
<div class="pr-card"><div class="pr-h"><h2>Ma prime individuelle</h2><small>${eur0(acquis)} acquis</small></div>${briques()}</div>
<div class="pr-card"><div class="pr-h"><h2>La prime magasin</h2><small>objectif du mois</small></div>${jauge()}<div class="note" style="margin-top:10px">${projTexte()}</div>${paliers()}<div class="note">Le palier atteint fin de mois donne tant d’euros <b>par heure prestée</b>. Ici, pour vos ${V.heuresMois}${NB}h.</div>${parts()}</div>
<div class="pr-card"><div class="pr-h"><h2>Les mois passés</h2><small>payés en bons</small></div>${hist()}</div>
<div class="pr-card doux">${regles()}</div>`);

// ---------- B : deux onglets, Moi et Mon magasin
const tabs = on => `<div class="pr-tabs"><span class="${on === 'moi' ? 'on' : ''}">Moi</span><span class="${on === 'mag' ? 'on' : ''}">Mon magasin</span></div>`;
const hS = t => (100 * (t - 40) / 25).toFixed(0);
const semaines = () => `<div class="sem"><span class="cible" style="bottom:${hS(CIBLE_CROIS)}%"></span>${SEMAINES.map(s => `<i class="${s.on ? 'on' : ''}" style="height:${hS(s.t)}%"><b>${s.t}</b></i>`).join('')}</div><div class="sem-leg">${SEMAINES.map(s => `<span>${s.l}</span>`).join('')}</div><div class="note">Vos ventes croisées, semaine par semaine, en % de vos tickets. Le trait : la cible du magasin, ${CIBLE_CROIS}${NB}%.</div>`;
const BM = page('Mes primes — B, onglet Moi', 'B · onglet Moi', sous, `${tabs('moi')}
${carteAuj()}
<div class="pr-card rubis"><div class="k">Mon mois, au 6 octobre</div><div class="gros">${eur0(acquis)}<small>acquis</small></div><div style="font-size:12.5px">${primeMagProj ? '+ ' + eur0(primeMagProj) + ' de prime magasin au rythme actuel' : 'Prime magasin : ' + eur0(PALIERS[0].eh * V.heuresMois) + ' dès ' + PALIERS[0].pct + NB + '% de l’objectif, ' + pc1(100 * atteinteProj) + ' au rythme actuel'} · jusqu’à ${eur0(aPortee)} à portée</div></div>
<div class="pr-card"><div class="pr-h"><h2>Mes trois primes</h2><small>${V.tickets} tickets · ${n1(V.heuresFaites)}${NB}h</small></div>${briques()}</div>
<div class="pr-card"><div class="pr-h"><h2>Semaine par semaine</h2><small>ventes croisées</small></div>${semaines()}</div>
<div class="pr-card"><div class="pr-h"><h2>Mes mois</h2><small>payés en bons</small></div>${hist()}</div>
<div class="pr-card doux">${regles()}</div>`);
const BG = page('Mes primes — B, onglet Mon magasin', 'B · onglet Mon magasin', sous, `${tabs('mag')}
<div class="pr-card rubis"><div class="k">${R.magasin.replace('Atelier by - ', '')} · objectif d’octobre</div><div class="gros">${pc1(100 * atteinteProj)}<small>au rythme actuel</small></div>
<div style="font-size:12.5px">${eur0(O.ca)} encaissés sur ${eur0(objectif)} · le mois finirait à ${eur0(projection)}</div>${barre(projection, objectif * 1.1, '', { v: objectif, l: '100 %' })}</div>
<div class="pr-card"><div class="pr-h"><h2>Jour par jour</h2><small>CA, le trait : l’objectif du jour</small></div>${jours()}<div class="note">Les jours de semaine font ${eur0(moy(semaine))} en moyenne, le week-end ${eur0(moy(weekend))}. Aujourd’hui, mardi : ${eur0(caJour['2026-10-06'])} à 16 h 55, ${eur0(auj)} attendus.</div></div>
<div class="pr-card"><div class="pr-h"><h2>Ce qu’il manque</h2><small>sur ${joursRestants} jours</small></div>
${PALIERS.map(p => `<div class="ligne"><span><b>${p.pct}${NB}%</b> <span class="d">${p.lib}</span></span><span class="d">${manque(p.pct) > 0 ? eur0(manque(p.pct) / joursRestants) + ' / jour de plus' : 'atteint au rythme actuel'}</span><b>${eur0(p.eh * V.heuresMois)}</b></div>`).join('')}
<div class="note">La colonne de droite : votre part, ${V.heuresMois}${NB}h × le taux du palier (${PALIERS.map(p => n2(p.eh) + NB + '€/h à ' + p.pct + NB + '%').join(', ')}).</div></div>
<div class="pr-card"><div class="pr-h"><h2>L’équipe</h2><small>${ent(heuresEquipe)}${NB}h au planning</small></div>${parts()}
<div class="ligne" style="margin-top:6px"><span>Prime de l’équipe à 100${NB}%</span><b>${eur0(PALIERS[1].eh * heuresEquipe)}</b></div><div class="ligne"><span>À 105${NB}%</span><b>${eur0(PALIERS[2].eh * heuresEquipe)}</b></div><div class="ligne"><span>À 110${NB}%</span><b>${eur0(PALIERS[3].eh * heuresEquipe)}</b></div></div>
<div class="pr-card doux"><div class="note">L’objectif du mois est celui du budget du magasin (${eur0(objectif)} en octobre, ${eur0(S9.objectifMois)} en septembre, atteint à ${pc1(100 * S9.ca / S9.objectifMois)}). Il se lit dans le cockpit, personne ne le change en cours de mois.</div></div>`);

// ---------- le cockpit : Paramètres des primes
const ck = fs.readFileSync(path.join(D, 'cockpit.part.html'), 'utf8')
  .replace(/\{\{(\w+)\}\}/g, (m, k) => ({
    objectif: eur0(objectif), projection: eur0(projection), atteinte: pc1(100 * atteinteProj), ca: eur0(O.ca), faitPct: pc1(100 * atteinteFait),
    heuresEquipe: ent(heuresEquipe), env100: eur0(PALIERS[1].eh * heuresEquipe), env105: eur0(PALIERS[2].eh * heuresEquipe), env110: eur0(PALIERS[3].eh * heuresEquipe), env97: eur0(PALIERS[0].eh * heuresEquipe),
    manque97: eur0(manque(97)), manque100: eur0(manque(100)), parJour97: eur0(manque(97) / joursRestants), parJour100: eur0(manque(100) / joursRestants), joursRestants,
    sept: eur0(S9.ca), septPct: pc1(100 * S9.ca / S9.objectifMois), septObj: eur0(S9.objectifMois),
    pctEnv: n2(100 * PALIERS[1].eh * heuresEquipe / objectif), moySem: eur0(moy(semaine)), moyWe: eur0(moy(weekend)),
  })[k] ?? m);
fs.writeFileSync(path.join(D, 'cockpit.html'), ck);

// ---------- la planche
const planche = `<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>Planche — Mes primes</title><link rel="stylesheet" href="/public/assets/ds/global.css">
<style>body{margin:0;background:#EAE4DC;font-family:var(--font-ui);color:#222;padding:28px 32px}h1{font:400 30px var(--font-display);margin:0 0 4px}.s{color:#666;font-size:13px;margin-bottom:18px}
.tels{display:flex;gap:28px;align-items:flex-start}.tel{flex:0 0 auto}.tel h2{font:400 18px var(--font-display);margin:0 0 8px}.tel .d{font-size:12px;color:#666;margin-bottom:8px;max-width:390px}
.tel img{width:390px;border-radius:18px;box-shadow:0 18px 50px rgba(0,0,0,.22);display:block}.ck{margin-top:30px}.ck img{width:1440px;border-radius:12px;box-shadow:0 18px 50px rgba(0,0,0,.18);display:block}</style></head>
<body><h1>Mes primes, dans l’app worker</h1><div class="s">Halle, octobre 2026 au 6 : CA ${eur0(O.ca)} sur ${eur0(objectif)} · projection ${eur0(projection)} (${pc1(100 * atteinteProj)}) · prime individuelle acquise ${eur0(acquis)}, jusqu’à ${eur0(aPortee)} à portée. Personne n’est nommé.</div>
<div class="tels"><div class="tel"><h2>A · une page</h2><div class="d">Tout en défilant : ce mois-ci, aujourd’hui, les trois primes, la prime magasin, les mois passés.</div><img src="a-long.png"></div>
<div class="tel"><h2>B · Moi</h2><div class="d">Deux onglets. Moi : le compteur du jour, mes trois primes, mes semaines, mes mois.</div><img src="b-moi-long.png"></div>
<div class="tel"><h2>B · Mon magasin</h2><div class="d">L’objectif du mois, jour par jour, ce qu’il manque par palier, l’équipe au prorata des heures.</div><img src="b-magasin-long.png"></div></div>
<div class="ck"><h2 style="font:400 18px var(--font-display);margin:0 0 8px">Cockpit · Paramètres des primes</h2><img src="cockpit.png"></div></body></html>`;

for (const [f, h] of [['a.html', A], ['b-moi.html', BM], ['b-magasin.html', BG], ['planche.html', planche]]) fs.writeFileSync(path.join(D, f), h);
console.log(JSON.stringify({ projection: Math.round(projection), atteinteProj: +(100 * atteinteProj).toFixed(1), moySem: Math.round(moy(semaine)), moyWe: Math.round(moy(weekend)), restSem, restWe, acquis, aPortee, primeMagProj, manque97: Math.round(manque(97)), manque100: Math.round(manque(100)), heuresEquipe, lt: +V.lt.toFixed(2), taux: +V.taux.toFixed(1) }));
