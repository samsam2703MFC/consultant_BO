# Espace franchisé · Fidélité › Profils clients

Demande du 12/11/2026 : « une page orientée fidélité — niveau de fidélité — spider sur les profils client
(récurrence, type d'achat) — maquette, screenshot, puis on décide ».

`index.html` (1440 de large, design system commun), capture `fidelite-profils.jpg`. `generer.py` refait la page
depuis `profils.css` et `profils.body.html`.

## La page, de haut en bas

1. **Ce qu'on sait** : tickets particuliers, tickets rattachés à un client, clients connus, revenus, membres actifs.
2. **Les niveaux de fidélité** en échelle : Découverte → De retour → Régulier → Habitué, avec sous chaque flèche
   la récompense qui fait monter d'un niveau.
3. **Deux radars par niveau** : la récurrence (fréquence, récence, ancienneté, panier, diversité, comptoir) et le
   type d'achat (pain, viennoiserie, anti-gaspi, tartes & fêtes, goûter, salé). Les axes sont expliqués dessous.
4. **Ce que les profils disent** : quatre constats, et les clients par famille achetée.
5. **Les clients qui reviennent** : une fiche par client régulier ou habitué. Ses passages sur 90 jours, ses
   chiffres, ses deux radars, ce qu'il achète, et l'action proposée.

## Les chiffres sont réels

L'Atelier by Halle, du 14/08 au 11/11/2026, lus par l'API du panel sans aucune table locale
(`tools/sonde_profils_halle.php`) : 13 173 tickets particuliers, 253 rattachés à 209 clients. Les lignes des
253 tickets donnent les familles achetées (`/transactions/{id}?include=products`, puis `/products`).
Aucun client n'a la fidélité active : les niveaux se lisent aux achats, pas aux points.

## À trancher

- **Les règles des niveaux** : 1 / 2 / 3 à 5 / 6 jours de passage et plus sur 90 jours.
- **Les récompenses sous les flèches** : celles du programme de départ, à confirmer.
- **Les échelles des radars** : 4 passages par mois = 100 ; 60 € de panier = 100.
- **Les six familles du type d'achat**, et la place de l'anti-gaspi : un type d'achat ou un comportement ?
- **Les noms à l'écran** : prénom et initiale pour le magasin ? La maquette anonymise (A à F).
