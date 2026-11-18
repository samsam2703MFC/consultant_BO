# Spécification : ce que l'API de l'ERP doit ajouter

18/11/2026. Elle accompagne l'admin modifié de `erp-admin/`. Elle couvre les décisions 4 et 5 de `LISEZMOI-AGENT.md` :
les sociétés liées à une carte à points, les points des salariés au nom de la société, et l'envoi des QR codes à usage
unique. **Pas de nouvelle table** : chaque donnée s'appuie sur un stockage qui existe déjà. Quand un choix reste
ouvert, il est marqué « à confirmer ».

## 1. `GET /admin/loyalty/options` : la liste des bureaux

Ajouter `offices[]` à côté de `shops`, `products`, `categories` :

```json
"offices": [
  { "id": 11, "id_shop": 4, "name": "Bureau Halle-Centre", "company_name": "…", "id_company_client": 1204,
    "primary_city": "Halle", "status": "active", "members_count": 14 }
]
```

- Source : les bureaux non archivés de chaque magasin, ceux que rend `GET /panel/shops/{shop}/offices`, plus le nombre
  de membres. Statuts `active` et `pending`.
- Droit : `LOYALTY.MANAGE`, comme le reste de la route.

## 2. La carte : `office_ids` et `office_qr_delivery`

`GET /admin/loyalty/cards`, `GET /admin/loyalty/cards/{id}`, `POST`, `PATCH` :

| Champ | Type | Règle |
|---|---|---|
| `office_ids` | `int[]` | Facultatif, vide par défaut, sans doublons. Chaque bureau existe, n'est pas archivé et appartient à l'un des magasins de la carte (`shop_ids`), sinon 422 `UNKNOWN_OFFICE` (`error.office_ids`). Non vide seulement si `points_config.points_enabled`, sinon 422 `OFFICES_REQUIRE_POINTS`. |
| `office_qr_delivery` | `"OFFICE" \| "MEMBERS"` | `OFFICE` par défaut. Sans effet si `office_ids` est vide. |

- **Conditions figées** : les deux champs font partie des conditions. Si `terms_locked`, un changement est refusé
  (`LOYALTY_CONFIGURATION_LOCKED`) ; l'admin les relit depuis l'API et ne les envoie pas.
- **Révision** : comme les autres champs, `revision` obligatoire au `PATCH`.
- **Stockage, sans nouvelle table** : dans le stockage actuel de la carte, par exemple le JSON `points_config`
  (`points_config.office_ids`, `points_config.office_qr_delivery`). L'API les expose quand même au premier niveau,
  comme l'admin les envoie. À confirmer selon le schéma de l'ERP.

## 3. Le moteur de points : crédit au nom de la société

Au moment où un ticket rapporte des points sur une carte K :

1. C = le client du ticket.
2. Si `K.office_ids` n'est pas vide, chercher un bureau O de `K.office_ids` dont C est **membre** (la table des membres
   du bureau, `office.members[].client_id`), avec O actif.
3. Si O existe, le **bénéficiaire** est `O.id_company_client` (le client société) ; sinon c'est C, comme aujourd'hui.
   Si C est membre de plusieurs bureaux liés, on prend le plus petit `O.id`. Ce choix est déterministe ; à confirmer.
4. Le lot de points est inscrit au bénéficiaire. Si le lot peut porter une origine (métadonnée de transaction),
   y noter `employee_client_id = C` et l'id du ticket ; sinon, rien de plus.
5. Les coefficients restent ceux de la carte : magasin × bonus jour et heure.
6. Les paliers et les bons se calculent sur le solde **du bénéficiaire**. Le bon d'échange
   (`POST /webshop/loyalty/points/redeem`) est émis au nom du client société, avec la logique actuelle.

Conséquences à documenter :
- `GET /shops/{id}/loyalty/customers` : le salarié garde ses achats (`shop_activity`), mais ses `points_earned` vont au
  client société ;
- `/admin/loyalty/simulate` : accepter un `client_id` facultatif pour montrer le bénéficiaire. Ce n'est pas obligatoire.

## 4. L'envoi des QR codes à usage unique, dès que la carte est créée

**Déclencheur** : la carte devient active (`POST` avec `active` = 1, ou `PATCH` de 0 à 1) avec `office_ids` non vide.
Aussi quand un bureau est ajouté à une carte active, pour ce seul bureau. Un seul envoi par couple carte × bureau :
idempotent, avec une clé `request_key` = `loyalty-{card_id}-office-{office_id}-v{version}`.

**Le contenu, en réutilisant ce qui existe** :
1. Les membres du bureau : `office.members[]` (`client_id`, nom, e-mail).
2. Un code unique par membre, à usage unique : le mécanisme des **cartes PDF d'un bureau**
   (`POST /panel/shops/{shop}/offices/{office}/cards`, mode `personalized`, `cards[]{name, code}`). Il crée déjà un lot
   d'impression, une campagne et des codes uniques, et rend le PDF (une carte par personne, avec le QR du code).
   - Le code porte le client (`id_customer` = membre) et `usage_limit` 1.
   - À la première lecture (scan en caisse ou dans l'app), il **confirme le rattachement** du salarié à la société pour
     cette carte, puis il est consommé. Ensuite, le salarié se sert de son identifiant de fidélité habituel.
   - `product_id` (un cadeau de bienvenue lié au code) : facultatif. **À confirmer avec Sam** : un café offert à
     l'activation, ou rien.
3. L'envoi passe par la file d'e-mails existante (celle de `…/members/invite`) :
   - `OFFICE` : un e-mail à `office.contact_email`, avec le PDF de tous les membres (une page par salarié) ;
   - `MEMBERS` : un e-mail à chaque membre qui a un e-mail, avec son PDF (une page). Les membres sans e-mail vont dans
     le PDF du bureau, qui part quand même à `contact_email`.
   - Si la file n'accepte pas les pièces jointes : un lien de téléchargement signé et à durée limitée vers le PDF, comme
     l'invitation du bureau. À vérifier dans l'ERP.

**Vie privée** : le PDF du bureau contient les noms des salariés, pas leur e-mail. Les URL et les codes sont des
données sensibles : ne pas les journaliser en clair.

## 5. Erreurs à ajouter (les traductions sont dans le paquet)

| Code | Quand |
|---|---|
| `UNKNOWN_OFFICE` | Un bureau n'existe pas, est archivé ou n'est pas dans les magasins de la carte |
| `OFFICES_REQUIRE_POINTS` | `office_ids` non vide sur une carte sans points |

## 6. Tests d'acceptation

1. `GET /admin/loyalty/options` rend les bureaux des quatre magasins, avec `id_company_client` et `members_count`.
2. Une carte à points avec `office_ids` = [11] s'enregistre, se relit, et apparaît dans la fenêtre d'aperçu de la liste.
3. `office_ids` sur une carte sans points → 422 `OFFICES_REQUIRE_POINTS` ; bureau d'un autre magasin → 422 `UNKNOWN_OFFICE`.
4. Carte figée : changer `office_ids` est refusé.
5. Un ticket de 12 € d'un membre du bureau 11 : 12 points au client société 1204, 0 au salarié. Un ticket d'un
   non-membre : points au client, comme avant.
6. Le solde de la société atteint 100 → bon de 5 € à son nom.
7. Activation de la carte : un seul envoi par bureau. En `OFFICE`, un e-mail à `contact_email` avec N pages ; en
   `MEMBERS`, un e-mail par membre. Chaque code n'est accepté qu'une fois.
8. Réactiver la carte ou la réenregistrer n'envoie pas un deuxième e-mail.
