# Dashboard magasin › Le résultat : les redevances (royalties)

**Statut : maquette du 16/11/2026, à valider. Rien n'est codé.**

Demande (16/11/2026) : « dans Le résultat — du chiffre d'affaires au résultat, mettre le % de royalties, en dessous de
la marge brute ; teste pour Harmonie 5 % ; maquette en screenshot sur octobre (mois) ».

## Les captures

Sur la vraie page Mois d'octobre 2026 d'Atelier by Harmonie - Sombreffe (dashboard en ligne, chiffres réels), avec une
copie du dashboard qui ajoute la ligne (`maquette.patch`, non publiée) :

- `resultat-avant-apres.png` : la carte « Le résultat » avant et après ;
- `mois-octobre-avec-redevances.png` : la page entière, la tuile « Résultat hors TVA » comprise.

| | Avant | Après |
|---|---|---|
| Marge brute | 30 267 € · 56,1 % | 30 267 € · 56,1 % |
| − Redevances (5 % du CA hors TVA 53 985 €) | — | − 2 699 € · 5,0 % |
| Invendus, main-d'œuvre, frais généraux | − 543 €, − 11 918 €, − 18 700 € | inchangés |
| = Résultat hors TVA | − 894 € · − 1,7 % | **− 3 593 € · − 6,7 %** |

## Ce qu'il faut savoir avant de coder

- **Pas de double compte** : les frais d'octobre de Sombreffe au panel (18 700 €) sont des charges financières
  (16 200 €) et des rémunérations de tiers (2 500 €) — aucune redevance dedans. Si un magasin saisit un jour ses
  redevances dans ses frais, il faudra les en retirer (ou ne pas ajouter la ligne).
- **La base** : 5 % du chiffre d'affaires **hors TVA**, comme le reste de la cascade (à confirmer : la redevance du
  contrat se calcule-t-elle sur le hors TVA ?).
- **Le taux** : le panel a déjà ses champs sur la fiche du magasin (`royalties_enabled`, `royalty_brand_percentage`,
  `royalty_marketing_percentage`, `royalty_assistance_percentage`, lus par `/fonds`, `src/endpoints.php`
  `fondsRoyaltiesCalcul`) — mais **aucun magasin ne les a remplis** (lu le 16/11/2026). Pour coder : lire ces taux, et
  sinon un taux réglé au cockpit par magasin (Harmonie 5 %).
- La ligne s'applique partout où la cascade s'affiche : Résultat du jour, Semaine, Mois ; la tuile « Résultat » et le
  P&L hors TVA du siège (`src/pnl_ht.php`) devront la retrancher aussi, pour que les chiffres restent les mêmes partout.
