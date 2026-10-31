# Les fiches imprimables des primes (31/10/2026)

| Fichier | Pour qui | Contenu |
|---|---|---|
| `primes-franchise-mode-emploi.pdf` | le franchisé | Une page A4 : les deux familles de primes (marque, magasin), sa page « Primes du magasin » en trois gestes (créer, suivre, ajuster), les règles du jeu, ce qu'il faut pour que ça marche. |
| `primes-personnel-qr.pdf` | l'équipe du magasin, à afficher | Une page A4 : le QR code de l'app employé (`https://185.180.206.46/consulant_bo/employee/`), se connecter, l'installer sur l'écran d'accueil, ce qu'on trouve dans l'app, comment gagner, quand c'est payé. |

Sources : `franchise.html`, `personnel.html` et `commun.css` (polices Gotham et Vank et logo du cockpit, liés en relatif).
Régénérer après une modification : `node docs/primes/generer.js` (vérifie que chaque fiche tient sur une page).

Les polices : les fichiers `Gotham_Regular*.otf` du design system sont en réalité du Gotham **Black** (leur nom
interne) ; les fiches prennent Light pour le texte, Medium pour l'accent, Black pour les chiffres forts.
