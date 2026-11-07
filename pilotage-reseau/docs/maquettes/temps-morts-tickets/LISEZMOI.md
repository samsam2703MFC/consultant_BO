# Temps morts — trois autres façons de montrer les tickets

**Statut : maquette du 07/11/2026. Rien n'est codé.** Question posée sur l'onglet « Temps morts » du
dashboard magasin : « c'est quoi les petites barres noires ? » — chaque trait noir y est un ticket. Trois
autres façons de dessiner la même journée, pour choisir :

| Variante | Capture | Ce qu'on lit d'un coup d'œil |
|---|---|---|
| 1. La grille des minutes | `v1-grille-minutes.png` | un carré par minute : foncé s'il y a eu un ticket (le chiffre quand il y en a plusieurs), rose s'il tombe dans un temps mort. On compte les minutes. |
| 2. Le rythme de la journée | `v2-rythme-5-minutes.png` | un histogramme : les tickets de chaque tranche de 5 minutes, les temps morts en rose derrière, les plus longs avec leur durée. On voit les rushs et les creux. |
| 3. Le fil de la journée | `v3-fil-de-la-journee.png` | un point par ticket sur deux lignes (matin, après-midi) ; sous la ligne, chaque temps mort et sa durée, 30 min ou plus en rose foncé. On lit les trous un par un. |

Chiffres réels : Sombreffe, vendredi 6 novembre 2026, 159 tickets, 16 temps morts (`data.json`, lu sur
`/exploitation/temps-morts`). Une variante choisie remplace « La journée minute par minute » de l'onglet ;
les tuiles et la semaine ne changent pas.

```
npx http-server . -p 8099 -c-1   # depuis la racine du dépôt
node pilotage-reseau/docs/maquettes/temps-morts-tickets/capturer.js
```
