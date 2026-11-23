/* /ventes/stock : l'inventaire matière du magasin de démo. */
const D = require('./demo');
const REFS = [
  ['Farine T65', 'Farines', 'kg', 180, 60], ['Farine de tradition', 'Farines', 'kg', 95, 40], ['Farine complète', 'Farines', 'kg', 22, 15],
  ['Beurre de tourage', 'Crèmerie', 'kg', 14, 20], ['Beurre doux', 'Crèmerie', 'kg', 18, 8], ['Crème 35 %', 'Crèmerie', 'l', 9, 6], ['Lait entier', 'Crèmerie', 'l', 24, 12], ['Œufs', 'Crèmerie', 'pce', 240, 120],
  ['Sucre semoule', 'Épicerie', 'kg', 40, 15], ['Chocolat noir 64 %', 'Épicerie', 'kg', 6.5, 5], ['Bâtons de chocolat', 'Épicerie', 'pce', 380, 300], ['Amandes effilées', 'Épicerie', 'kg', 1.2, 2], ['Levure fraîche', 'Épicerie', 'kg', 3.5, 2],
  ['Jambon cuit', 'Traiteur', 'kg', 4.2, 3], ['Filet de poulet', 'Traiteur', 'kg', 0, 2.5], ['Salade iceberg', 'Traiteur', 'pce', 6, 4], ['Tomates', 'Traiteur', 'kg', 3, 2],
  ['Gobelets 25 cl', 'Emballages', 'pce', 650, 300], ['Sacs kraft M', 'Emballages', 'pce', 420, 250], ['Boîtes pâtisserie 4', 'Emballages', 'pce', 35, 60],
  ['Café en grains', 'Boissons', 'kg', 7, 4], ['Jus d’orange (oranges)', 'Boissons', 'kg', 12, 8],
];
function stock() {
  const lignes = REFS.map(([ref, categorie, unite, st, mini]) => {
    const alerte = st < mini;
    return { ref, categorie, unite, stock: st, mini, manque: alerte ? D.r2(mini - st) : 0, alerte, modif: '2026-11-18 18:40:00' };
  });
  const A = lignes.filter(x => x.alerte);
  return { references: lignes.length, alertes: A.length, ruptures: A.filter(x => x.stock <= 0).length, negatifs: 0, dernierComptage: '2026-11-18 18:40:00', quand: '2026-11-19 15:38', lignes };
}
module.exports = { stock };
