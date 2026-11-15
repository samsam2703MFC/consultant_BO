# Espace franchisé · Fidélité › Profils clients

Demande du 12/11/2026 : « une page orientée fidélité — niveau de fidélité — spider sur les profils client
(récurrence, type d'achat) — maquette, screenshot, puis on décide ».

`index.html` (1440 de large, design system commun), capture `fidelite-profils.jpg`. `generer.py` refait la page
depuis `profils.css` et `profils.body.html`.

## La page, de haut en bas (deuxième version, 12/11/2026)

Retour de Sam sur la première version : « trop de graphiques ; garder les profils client en % des clients
scannés, et sous chaque vignette les conditions en jours par mois ».

1. **Quatre vignettes** : Découverte, De retour, Régulier, Habitué. Chacune donne la part des clients scannés
   (90,4 / 6,7 / 1,9 / 1,0 %) et, dessous, **sa condition en jours par mois** (et en passages sur 3 mois),
   puis la part du CA, le panier, la part en commande et le dernier passage.
2. **Un seul couple de radars**, récurrence et type d'achat : le profil de la vignette choisie face à la moyenne
   des clients scannés. Un clic sur une autre vignette change le profil affiché.
3. **Ce que dit le profil choisi** : trois constats.
4. **Les clients qui reviennent** : un tableau, sans graphique (profil, jours par mois, dernier passage,
   panier, ce qu'il achète, action proposée).

`vignettes.py` construit les vignettes, les radars et le tableau ; `generer.py` assemble la page.

## Les chiffres sont réels

L'Atelier by Halle, du 14/08 au 11/11/2026, lus par l'API du panel sans aucune table locale
(`tools/sonde_profils_halle.php`) : 13 173 tickets particuliers, 253 rattachés à 209 clients. Les lignes des
253 tickets donnent les familles achetées (`/transactions/{id}?include=products`, puis `/products`).
Aucun client n'a la fidélité active : les niveaux se lisent aux achats, pas aux points.

## À trancher

- **Les conditions des profils** : moins d'1 jour par mois (1 ou 2 passages en 3 mois), 1 à 1,7 jour par mois,
  2 jours par mois ou plus.
- **Les échelles des radars** : 4 passages par mois = 100 ; 60 € de panier = 100.
- **Les six familles du type d'achat**, et la place de l'anti-gaspi : un type d'achat ou un comportement ?
- **Les noms à l'écran** : prénom et initiale pour le magasin ? La maquette anonymise (A à F).
