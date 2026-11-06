# Les fiches imprimables des primes (31/10/2026)

| PDF (`public/assets/primes/`) | Pour qui | Contenu |
|---|---|---|
| `primes-franchise-mode-emploi.pdf` | le franchisé | Une page A4 : les deux familles de primes (marque, magasin), sa page « Primes du magasin » en trois gestes (créer, suivre, ajuster), les règles du jeu, ce qu'il faut pour que ça marche. |
| `primes-personnel-qr.pdf` | l'équipe du magasin, à afficher | Une page A4 : le QR code de l'app employé (`https://185.180.206.46/consulant_bo/employee/`), se connecter, l'installer sur l'écran d'accueil, ce qu'on trouve dans l'app, comment gagner, quand c'est payé. |
| `primes-personnel-qr-nl.pdf` | idem, en néerlandais | La même affiche, avec les mots de l'app en néerlandais (Home, Rooster, Premies, Profiel ; Gekoppelde verkoop…). |

**Dans le cockpit** : la page d'un magasin (`#/primes?shop=…`), volet « Fiches à imprimer et à envoyer », ouvre chaque
PDF pour l'impression et envoie l'affiche (FR, NL ou les deux) par mail à chaque personne cochée de l'équipe, une par
une, avec le lien direct vers l'app (`src/primes_fiches.php`, routes `GET /ventes/primes-fiches/equipe?shop=` et
`POST /ventes/primes-fiches/envoyer`, au journal). L'adresse : celle de « Mon profil » dans l'app, sinon celle du panel
recopiée au profil ; sans adresse, la personne est listée « sans adresse ». Il faut le SMTP réglé (Paramètres › SMTP).

Sources : `franchise.html`, `personnel.html`, `personnel-nl.html` et `commun.css` (polices Gotham et Vank et logo du
cockpit, liés en relatif ; le QR code est un SVG dans les deux affiches). Régénérer après une modification :
`node docs/primes/generer.js` — il écrit les PDF dans `public/assets/primes/` et vérifie que chaque fiche tient sur une page.

Les polices : les fichiers `Gotham_Regular*.otf` du design system sont en réalité du Gotham **Black** (leur nom
interne) ; les fiches prennent Light pour le texte, Medium pour l'accent, Black pour les chiffres forts.
