/* Maquettes « l'onglet Produits » (demande du 11/11/2026) : extraction des chiffres réels, en lecture seule.
 * Lit sur le serveur, pour un magasin :
 *   /ventes/stats?vue=semaine&date=…   les 12 dernières semaines closes et la semaine en cours,
 *                                      catégorie par catégorie et produit par produit (q, v, c, m) ;
 *   /analyse/produits/coefficients     le prix catalogue, le coût de recette, le coefficient et sa cible.
 * et écrit reel-halle.json (compact : une ligne par produit, une valeur par semaine).
 *   node docs/maquettes/onglet-produits/extraire.js [shop] [aujourd'hui]
 *   COCKPIT=http://…/api/cockpit pour lire un autre serveur. */
const fs = require('fs');
const path = require('path');
const API = process.env.COCKPIT || 'http://185.180.206.46/consulant_bo/api/cockpit';
const SHOP = process.argv[2] || '4';
const AUJ = process.argv[3] || new Date().toISOString().slice(0, 10);

const iso = d => d.toISOString().slice(0, 10);
const lundi = s => { const d = new Date(s + 'T12:00:00Z'); d.setUTCDate(d.getUTCDate() - (d.getUTCDay() + 6) % 7); return d; };
const numSem = s => { const d = new Date(s + 'T12:00:00Z'); d.setUTCDate(d.getUTCDate() + 3 - (d.getUTCDay() + 6) % 7); const j4 = new Date(Date.UTC(d.getUTCFullYear(), 0, 4)); return 1 + Math.round(((d - j4) / 864e5 - 3 + (j4.getUTCDay() + 6) % 7) / 7); };
const lire = async (u, essais = 3) => { for (let i = 0; ; i++) { try { const r = await fetch(API + u); if (!r.ok) { throw new Error(r.status + ' ' + u); } return await r.json(); } catch (e) { if (i >= essais - 1) { throw e; } await new Promise(z => setTimeout(z, 2000 * (i + 1))); } } };

(async () => {
  const l0 = lundi(AUJ);
  const dates = [];
  for (let k = 12; k >= 0; k--) { const d = new Date(l0); d.setUTCDate(d.getUTCDate() - 7 * k); dates.push(iso(d)); }
  const semaines = await Promise.all(dates.map(d => lire('/ventes/stats?shop=' + SHOP + '&vue=semaine&date=' + d)));
  const coef = await lire('/analyse/produits/coefficients?shop=' + SHOP);
  const N = semaines.length;
  const S = semaines.map((w, i) => ({ s: 'S' + numSem(w.du), du: w.du, au: w.au, jours: (w.joursServis || w.jours).filter(j => j <= AUJ).length,
    enCours: i === N - 1, ca: w.totaux.ca, mat: w.totaux.mat, mb: w.totaux.mb, tickets: w.totaux.tickets }));
  // Une ligne par produit vendu : les portions (id « parent:portion ») restent à part, rattachées au parent.
  const P = new Map(), C = new Map();
  semaines.forEach((w, i) => (w.categories || []).forEach(c => {
    if (!C.has(c.nom)) { C.set(c.nom, { nom: c.nom, groupe: c.groupe || c.nom }); }
    (c.produits || []).forEach(p => {
      const id = String(p.id);
      if (!P.has(id)) { P.set(id, { id, nom: p.nom, cat: c.nom, q: Array(N).fill(0), v: Array(N).fill(0), c: Array(N).fill(0) }); }
      const x = P.get(id); x.q[i] += p.q || 0; x.v[i] = +(x.v[i] + (p.v || 0)).toFixed(2); x.c[i] = +(x.c[i] + (p.c || 0)).toFixed(2);
    });
  }));
  const K = new Map(coef.produits.map(p => [String(p.id), p]));
  const fiche = p => p ? { prixTtc: p.prixTtc, prixHt: p.prixHt, conseille: p.conseille, tva: p.tva, cout: p.cout, coef: p.coef, cible: p.cible,
    prixCible: p.prixCible, prixObjectif: p.prixObjectif, margePct: p.margePct, zone: p.zone, vie: p.vie, divisible: p.divisible, part: p.part, webshop: p.webshop } : null;
  const produits = [...P.values()].map(x => { const [pere, portion] = x.id.split(':'); return { ...x, pere: portion ? pere : null, fiche: fiche(K.get(pere)) }; });
  const out = { shop: +SHOP, magasin: semaines[N - 1].magasin, lu: AUJ, source: 'GET /ventes/stats?vue=semaine (×' + N + '), GET /analyse/produits/coefficients',
    seuils: coef.seuils, semaines: S, categories: [...C.values()], produits };
  fs.writeFileSync(path.join(__dirname, 'reel-halle.json'), JSON.stringify(out));
  console.log(S.map(s => s.s + ' ' + s.du + ' ' + Math.round(s.ca) + ' €' + (s.enCours ? ' (en cours, ' + s.jours + ' j)' : '')).join('\n'), '\n', produits.length, 'lignes produits');
})().catch(e => { console.error(e); process.exit(1); });
