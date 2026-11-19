/* Maquettes « l'onglet Produits : est-ce que ça se développe ? » (demande du 11/11/2026, 2e version).
 * Complète ../onglet-produits/reel-halle.json avec le réseau, en lecture seule :
 *   /analyse/categories/magasin?niveau=groupe|categorie   le magasin et la moyenne par magasin du réseau,
 *                                                         en euros et en pièces, semaine par semaine ;
 *   /analyse/produits/magasin?pid=                         le produit : le magasin, le réseau, le réseau l'an dernier.
 * Écrit reel-reseau.json.
 *   node docs/maquettes/onglet-produits-developpement/extraire-reseau.js
 *   COCKPIT=http://…/api/cockpit pour lire un autre serveur. */
const fs = require('fs');
const path = require('path');
const API = process.env.COCKPIT || 'http://185.180.206.46/consulant_bo/api/cockpit';
const R = JSON.parse(fs.readFileSync(path.join(__dirname, '..', 'onglet-produits', 'reel-halle.json'), 'utf8'));
const SHOP = R.shop;
const lire = async (u, essais = 3) => { for (let i = 0; ; i++) { try { const r = await fetch(API + u); if (!r.ok) { throw new Error(r.status + ' ' + u); } return await r.json(); } catch (e) { if (i >= essais - 1) { return { erreur: String(e.message || e) }; } await new Promise(z => setTimeout(z, 1500 * (i + 1))); } } };
async function parLots(liste, n, f) { const out = []; for (let i = 0; i < liste.length; i += n) { out.push(...await Promise.all(liste.slice(i, i + n).map(f))); } return out; }
const somme12 = a => a.slice(0, 12).reduce((x, y) => x + y, 0);

(async () => {
  const groupes = [...new Set(R.categories.map(c => c.groupe))];
  const cats = R.categories.map(c => c.nom);
  const lus = await parLots([...groupes.map(g => ['groupe', g]), ...cats.map(c => ['categorie', c])], 6, async ([niv, nom]) => {
    const d = await lire('/analyse/categories/magasin?shop=' + SHOP + '&niveau=' + niv + '&nom=' + encodeURIComponent(nom));
    const s = d.semaines || {};
    return { niv, nom, magasin: s.magasin, reseau: s.reseau, magasinQ: s.magasinQ, reseauQ: s.reseauQ, magasins: s.magasins, erreur: d.erreur || d.error };
  });
  const pids = R.produits.filter(p => !p.pere && somme12(p.q) >= 12).map(p => p.id);
  const prods = await parLots(pids, 6, async pid => {
    const d = await lire('/analyse/produits/magasin?pid=' + pid + '&shop=' + SHOP + '&mois=3');
    const s = d.semaines || {};
    return { id: pid, magasin: s.magasin, reseau: s.reseau, anDernier: s.anDernier, magasins: s.magasins, erreur: d.erreur || d.error };
  });
  const out = { shop: SHOP, lu: R.lu, tranches: R.semaines.map(s => s.s),
    groupes: lus.filter(x => x.niv === 'groupe'), categories: lus.filter(x => x.niv === 'categorie'), produits: prods };
  fs.writeFileSync(path.join(__dirname, 'reel-reseau.json'), JSON.stringify(out));
  console.log(out.groupes.length, 'groupes,', out.categories.length, 'catégories,', out.produits.length, 'produits ·', [...lus, ...prods].filter(x => x.erreur).length, 'erreurs');
})().catch(e => { console.error(e); process.exit(1); });
