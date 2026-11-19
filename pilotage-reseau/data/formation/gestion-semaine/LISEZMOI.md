# La gestion de la semaine : le lien Manuel ↔ checklists (18/11/2026)

Demande de Sam : relier le Manuel opératoire à la checklist de gestion hebdomadaire et aux tâches qui vont avec, au
niveau franchisé et poste.

## Ce qui est fait

- **Article 0.0.57** « La gestion de la semaine : la réunion du lundi et ses contrôles » (`art-gestion-semaine.json`),
  encodé en v1.0, accès responsable, **publié le 19/11/2026** (Sam : « publie 0.0.57 ») : quiz et « vu faire » ouverts. Il décrit la réunion d’équipe du lundi (5.5.01), l’auto-audit HACCP
  du lundi (5.6.01) et la relecture de la semaine de la routine du dashboard (5.2.01). Publication : « publie 0.0.57 ».
- **Liens posés sur les documents existants** (champ « checklists » du classement, sans nouvelle version) :

| Document | Code | Checklist reliée |
|---|---|---|
| Animation d’équipe et RH (`art-fo-06`) | 0.0.16 | 5.5.01 Réunion d’équipe (lundi) |
| HACCP & Hygiène (`art-fo-02`) | 0.0.13 | 5.6.01 Auto-audit HACCP |
| Comprendre le dashboard magasin (`art-pil-01`) | 0.0.15 | 5.2.01 Routine du dashboard magasin |
| Bonnes pratiques du dirigeant (`art-bonnes-pratiques-dirigeant`) | 0.0.55 | 5.5.01 Réunion d’équipe (lundi) |

## Ce qui manque : le niveau franchisé et poste

Le cockpit lit les tâches dans le cadre des tâches du panel (`GET /task/cadre`, `POST /task/qui`) :

- **Les tâches de gestion hebdomadaire n’ont aucun niveau de poste attribué** (« qui fait » vide) : les 5 tâches de 5.5.01,
  les 5 de 5.6.01 et la tâche « Lundi : semaine relue » de 5.2.01. Elles ont des contrôleurs : la personne qui la fait,
  le pôle Stratégie et Développement, le contrôleur qualité et conformité.
- **Les tâches quotidiennes du comptoir en ont**, par exemple « Photo du comptoir - Pains & Boulangerie » : Débutant,
  Équipier confirmé, Responsable d’équipe, Franchise, Manager.
- Conséquence : le Manuel ne peut pas dire « cette tâche est pour le franchisé » tant que le panel ne le dit pas. L’article
  0.0.57 le signale dans sa partie « Qui fait chaque tâche ».

Pour compléter : choisir les niveaux qui font chaque tâche de la semaine (franchisé, manager, responsable d’équipe…),
puis les régler dans le cadre des tâches du cockpit (« qui fait »). C’est une écriture dans le panel de production : elle
attend l’accord de Sam.

## Hors de ce lot

- Un écran « Ma semaine » dans la tablette, par franchisé et par poste (cases à cocher, documents liés) : demande un
  développement du cockpit et une mise en ligne, et dépend du point ci-dessus.
- Le bloc « checklist et tâches » dans la fiche de chaque article : même dépendance.
