# Mon équipe : la fiche d'une personne (maquette, 24/11/2026)

Demande : « dans Mon équipe je n'ai pas accès à l'édition de chaque membre du personnel, évaluation, coût… qu'est-ce que
tu sais récupérer dans les repos GitHub et dans le swagger ? », puis « fais une maquette (2) screenshot ».

## Captures

| Fichier | Variante |
|---|---|
| `a1-fenetre-identite-et-contrat.png` | **A · une fenêtre à onglets**, ouverte d'un clic sur une personne (Responsabilités, Planning). Onglet « Identité et contrat » : le taux horaire changé montre son effet (coût du mois, personnel / CA) avant « Enregistrer » |
| `a2-fenetre-evaluations.png` | A · onglet « Évaluations » : la moyenne, la répartition des notes, les services passés **à noter** (une note de 1 à 5 et un mot), l'historique |
| `b-page-de-la-personne.png` | **B · une page par personne** (Mon équipe › Camille Roussel) : six chiffres (taux, heures, coût, note, vendu, primes), puis identité et contrat, heures et coût semaine par semaine, compétences et postes, évaluations, ventes et primes |

**Comment elles sont faites** (`capturer.cjs`) : le vrai dashboard servi en local avec la fausse API des vidéos
(`panel/tools/videos/mock.js`) et la maquette posée par-dessus (`fiche.css`, `fiche.js`). **Tout est fictif** (Magasin
démo, Camille Roussel) ; aucune donnée réelle, aucune écriture.

## D'où viendrait chaque donnée (swagger TF Buddy, `https://test.tfbuddy.com/docs/openapi.json`, 1 290 chemins)

| Bloc | Lire | Écrire |
|---|---|---|
| Identité, contrat, taux horaire, rôle, langue, téléphone, PIN, actif | `GET /employees/{id}` (Employee : `money_per_hour`, `employment_type`, `franchise_role`, `login_ready`, `pin_set`…) | `PATCH /employees/{id}` (EmployeeWrite) ; désactiver : `soft_delete` ou `DELETE` |
| Évaluations | `GET /employees/{id}/evaluations`, `GET /shops/{id}/evaluations`, `GET /shops/{id}/employees/evaluation-summary` | `POST /schedule/{service}/evaluation` {rating, note}, `DELETE /evaluations/{id}` |
| Compétences, poste et niveau | `GET /employees/{id}/competencies`, `…/positions`, `GET /position-levels/{id}/requirements` | `PUT /employees/{id}/competencies`, `PUT …/positions` |
| Postes de travail | dans la fiche | `PUT /employees/{id}/workstations` (déjà utilisé par Mon équipe) |
| Heures et coût | le planning de Mon équipe (`/shops/{id}/schedule`) × `money_per_hour` | — |
| Vendu, primes | `/shops/{id}/statistics/sales/employees/{date}`, la page des primes (`panel/src/primes.php`) | — |

## Ce qui reste à vérifier ou n'existe pas

- **Les droits** : le 23/11/2026, `GET /shops/{id}/employees` a été refusé au compte du cockpit malgré le swagger (le
  compte admin de l'ERP le relit). `PATCH /employees/{id}` et les évaluations sont à essayer en ligne avec les deux
  comptes avant de promettre l'écriture.
- **L'échelle des notes** : le swagger dit `rating` entier, sans bornes ; la maquette suppose 1 à 5.
- **Le coût employeur** (brut, charges) n'est pas dans TF Buddy : le coût montré est heures du planning × taux horaire.
- **Les compétences et niveaux** de la maquette sont des exemples ; le référentiel réel est celui des postes
  (`/positions`, `/position-levels`) du panel.
