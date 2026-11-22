# Maquette « Suivi des franchisés » (24/10/2026)

Demande : récupérer tout ce qu'on avait sur le contrôle des franchisés sur le terrain par tablette et le mettre dans
le rail sous le nom « Suivi des franchisés », dans le menu existant **Franchisés · évaluation et suivi** (on affine
après). Sur l'ordinateur, un tableau de bord pour préparer les visites, adapter et envoyer les rapports ; la visite
elle-même sur la tablette ; une page admin qui décide qui fait quoi, comme les primes du personnel ; une page du
franchisé avec ses rapports de visite et les points qu'il doit travailler ; tout le client mystère dans le sous-menu ;
un sous-menu Budget où le budget est transféré.

**Construit le 24/10/2026** (« ok pour tout, construis-le dans le cockpit ») : voir `docs/suivi-franchises.md`. La
maquette reste ici comme référence ; les réponses retenues aux questions sont sous « À trancher ».

Une seule page cliquable, `index.html` (format Artifact, sans `<!doctype>`) : le rail fonctionne, les onglets aussi,
les étapes de la tablette se parcourent, chaque bouton dit ce qu'il ferait. Écran direct : `#tableau`, `#tablette`,
`#msp`, `#franchise`, `#admin`. Consultants fictifs (Sam V., Nadia K., Youssef B., Lucie D.), magasins réels, photos
de Gosselies du 01/10/2026 à titre d'illustration.

## Le rail

Dans la section existante **Franchisés · évaluation et suivi** :

| Entrée | Statut |
|---|---|
| Fiche franchisé, Scoring du trimestre | inchangés, en tête |
| **▾ Suivi des franchisés** | nouveau sous-menu, cinq entrées ci-dessous |
| **▾ Budget** : Suivi budget, Encodage du budget, Paramètres du budget | transféré de Magasins (aujourd'hui un écran à trois onglets) |
| ▸ Suivi journalier et opérations | inchangé |
| ▸ Suivi de terrain | inchangé pour l'instant, marqué « à affiner » : ses quatre entrées sont reprises dans Suivi des franchisés |

Magasins perd l'entrée Budget ; le reste du rail ne bouge pas.

## Les écrans

| Capture | Écran | Ce qu'il montre |
|---|---|---|
| `a-tableau-de-bord.png` | Tableau de bord · Vue d'ensemble | 5 tuiles (visites de la semaine, rapports à adapter, envoyés, points ouverts, corrections à valider) ; les visites en quatre colonnes : à préparer → sur la tablette → rapport à adapter → envoyé ; le tableau des franchisés (feu, dernier rapport lu ou pas, points ouverts, prochaine visite, cadre du mois, scoring) |
| `b-preparer.png` | Tableau de bord · Préparer | le dossier de visite assemblé tout seul (santé du magasin, points ouverts, contrôles photo, scoring, client mystère et comptoir, dernière météo en interne), ce que la tablette emporte (type de visite et sa checklist, points ajoutés par le bureau, documents, ordre du jour au franchisé à J−2), « Envoyer sur la tablette » |
| `c-adapter-envoyer.png` | Tableau de bord · Adapter et envoyer | le brouillon renvoyé par la tablette, partie par partie (en une phrase, ce qui va bien, vu sur place, le vrai problème, recommandation), les points et photos qui partent ou restent internes, l'aperçu de ce que recevra le franchisé, l'envoi (destinataires, qui envoie, délai de 48 h) |
| `d-points-corrections.png` | Tableau de bord · Points et corrections | les photos de correction des franchisés à valider (visite / correction côte à côte, Accepter, À reprendre), tous les points ouverts par urgence |
| `e-tablette.png`, `f-tablette-fin.png` | Visite sur tablette | l'application en visite, huit étapes : arrivée et photo du jour, dossier du bureau, checklist du type (les points du bureau en tête), contrôles photo, vu sur place, points à travailler, météo du franchisé (interne), fin de visite et signature ; hors ligne avec la file d'attente ; le circuit d'une visite en six temps |
| `g-client-mystere.png` | Client mystère | les rapports magasin par magasin (note, rubriques sur 20, précédent, ce qui a suivi, PDF), l'encodage du rapport reçu et ce qui le suit (points, contrôle à la visite suivante, page du franchisé), où il se lit ensuite, le contrôle sur la tablette |
| `h-page-franchise.png` | Page du franchisé | l'aperçu de la page que le franchisé ouvre avec son lien : ses points à travailler (urgent, en attente de validation, à reprendre) avec l'envoi de la photo de correction, ses rapports de visite ; à côté, ce qu'il ne voit pas et ce qui revient au bureau |
| `i-qui-fait-quoi.png` | Qui fait quoi (admin) | par magasin : visites au cadre, qui prépare, qui visite, qui adapte le rapport, qui valide l'envoi, qui valide les corrections, qui est en copie ; les personnes et leurs droits ; les règles et délais (dossier à J−2, ordre du jour, rapport sous 48 h, délais P0 / P1 / P2, validation des photos, escalade, ce que voit le franchisé). Mis en page comme les Paramètres des primes |
| `*-tel.png` | les mêmes à 390 px | le rail devient un menu, les colonnes s'empilent |

## Ce qui est récupéré, et où ça va

| Ce qu'on avait | Où ça vivait | Où ça va |
|---|---|---|
| App Visites (PWA `visites/`) : agenda, portfolio, fiche, checklist par modules, photos, review, plan d'action, file hors ligne | hors rail (`Suivi de terrain › Visites ↗`) | Visite sur tablette (l'app) ; agenda et portfolio au tableau de bord |
| Fiche franchisé en mode tablette (`?app=consultant`) : agenda, checklist du consultant, santé, benchmark, météo | Fiche franchisé | Visite sur tablette (les deux applications n'en font plus qu'une) ; santé et benchmark dans le dossier de visite |
| Gestion consultant : mon planning, tâches et contrôles, réseau, cadre de visite | Suivi de terrain | planning et réseau au tableau de bord ; cadre de visite et affectation dans Qui fait quoi |
| Review et plan d'action P0 à P2, machine à états, escalade à Sam | app Visites | Adapter et envoyer ; Points et corrections |
| Vue admin « À valider » (avant / photo du franchisé) | app Visites (`?role=admin`) | Points et corrections |
| Synthèse de Sam (`/visites/synthese`) | app Visites | Vue d'ensemble |
| Vue franchisé (`?shop=`) et page `dashboard/suivi.html?shop=` | deux pages à part | Page du franchisé (une seule) |
| Client mystère : encodage trimestriel (`/scoring/msp`, `ceo_scoring_msp`) et rapport mensuel de l'app Visites (`POST /msp`, `ceo_msp`), module de la checklist, type de visite | Scoring du trimestre ; Suivi de terrain ; app Visites | Client mystère (un seul encodage) |
| Conformité du comptoir (`/visites/conformite`) | Suivi de terrain | dossier de visite et checklist de la tablette |
| Météo du franchisé (`ceo_franchise_meteo`) | Fiche franchisé | étape 7 de la tablette ; dossier de visite, en interne |
| Budget : suivi, encodage, paramètres | Magasins › Budget | sous-menu Budget |

## À trancher

1. **Une seule application tablette** : l'app Visites et la fiche franchisé en mode tablette fusionnent. La checklist
   de la visite est celle du type (cadre de visite) avec les points du bureau en tête ; la checklist du consultant lue
   dans le panel devient-elle un module de plus, ou disparaît-elle ?
2. **Rapport adapté au bureau** : la tablette envoie un brouillon, l'ordinateur le relit puis l'envoie. Délai de 48 h,
   et Sam valide l'envoi pour les visites de Youssef : bons défauts ?
3. **Client mystère** : deux tables aujourd'hui (par trimestre pour le scoring, par mois pour le feu). Un seul encodage,
   par passage, rangé dans son trimestre ?
4. **Suivi de terrain** : on retire le sous-menu une fois la maquette validée (ses entrées sont reprises) ?
5. **Budget** : trois entrées dans le sous-menu, ou une seule entrée qui garde l'écran à trois onglets ?

Retenu à la construction : 1. une seule application tablette, la checklist du consultant devient une étape ;
2. délai de 48 h, l'envoi se règle par magasin dans Qui fait quoi (qui adapte, qui envoie) ; 3. un seul encodage, par
passage, rangé dans son trimestre ; 4. Suivi de terrain retiré ; 5. trois entrées dans le sous-menu Budget. Ajouté
ensuite : chaque type de visite est annoncé ou inopiné, et une visite annoncée envoie ses courriels au franchisé dans
sa langue (fr, nl, en, de).

## Régénérer les captures

Un serveur à la racine du dépôt (`php -S 127.0.0.1:8099 -t .`), puis
`node docs/maquettes/suivi-franchises/capturer.cjs`.
