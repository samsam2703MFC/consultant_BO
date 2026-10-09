# Maquettes « Gestion consultant » (09/10/2026)

Cinq écrans : une entrée « Gestion consultant » (A à D) et une fiche franchisé (E), dans une section du rail
« Franchisés · évaluation et suivi » en deux volets (suivi journalier et opérations, suivi de terrain), sur le design system
(`/public/assets/ds/global.css`, Ruby Red, beige, Gotham, Vank). Consultants fictifs, magasins réels. L'audit qui les
précède : `docs/audit-taches-consultant.md`.

| Fichier | Écran | Ce qu'il montre |
|---|---|---|
| `a-mon-planning.html` | A — Mon planning | le mois du consultant tous magasins (visites par type et statut, échéances, visites des autres consultants, ce que le cadre attend encore), la semaine en détail, la carte Agenda Google (abonnement ICS, invitation .ics, lien par visite, connexion bidirectionnelle en lot 2), « À planifier » d'après le cadre |
| `b-taches-controles.html` | B — Tâches et contrôles | les tâches du consultant de toutes sources (visite, plan d'action, panel, récurrente, helpdesk, perso) en trois colonnes ; la liste de contrôle de la visite choisie : la liste déroulante du type, ses tâches à faire (avant, pendant, après) avec échéance, ses points module par module ; la dernière visite du magasin |
| `c-reseau.html` | C — Réseau | consultants × magasins (dernière visite, prochaine, plans ouverts, feu), la charge par consultant, la couverture face au cadre, les règles |
| `e-fiche-franchise.html` | E — Fiche franchisé | le rail réorganisé en deux volets, le tableau des franchisés (indicateurs journaliers et de terrain), la fiche d'un magasin en deux colonnes, le journal de l'évaluation |
| `d-cadre-visite.html` | D — Cadre de visite | par magasin : nombre de visites par mois, type, profil de consultant, checklist attribuée, consultant, réalisé ; la liste déroulante des types et la définition du type choisi (durée, profil, checklist, tâches) ; le formulaire « Planifier une visite » où le type charge tout |

Fichiers partagés : `coque.js` (rail, barre, onglets, données du cadre : profils, consultants, types, plan par magasin),
`gc.css` (styles propres aux maquettes), `capturer.cjs` (captures Playwright).

Pour régénérer les captures : un serveur à la racine du dépôt (`php -S 127.0.0.1:8099 -t .`), puis
`node docs/maquettes/gestion-consultant/capturer.cjs`.
