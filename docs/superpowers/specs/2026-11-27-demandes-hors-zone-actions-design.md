# Demandes hors zone : « Créer le bureau » et la poubelle — design court (27/11/2026)

## Pourquoi
Dans Clients › Bureaux › Bureaux en développement, le magasin reçoit les « Demandes hors zone » (les bureaux qui ne
trouvent pas leur zone sur les pages Livraison au bureau), mais ne peut rien en faire : la ligne n'a qu'un « Renvoyer »
quand TF Buddy ne l'a pas reçue. Sam : « je ne sais si valider le bureau, l'envoyer dans le wizard ou l'effacer (icône
petite poubelle) », puis « deux actions, « Créer le bureau » et la poubelle ».

## Buts / non-buts
- But : sur chaque demande hors zone, deux actions — **Créer le bureau** et **Effacer** (icône poubelle).
- Non-but : un « Valider » direct. Une demande hors zone n'a que code postal, commune, société, effectif, e-mail : ni
  nom, ni téléphone, ni adresse, ni TVA (TF Buddy exige une TVA pour créer un bureau).
- Non-but : effacer l'original dans TF Buddy (aucune route connue) : la poubelle n'efface que la copie du cockpit.
- Anti-but : effacer sans confirmation ; perdre la trace d'une demande reprise.

## Approches
1. **Retenue** — « Créer le bureau » ouvre la fenêtre « Nouveau bureau en développement », pré-remplie (société, e-mail,
   code postal, ville, personnes, une remarque qui cite la demande). Le magasin complète après avoir appelé le prospect ;
   la demande créée suit le chemin habituel (personne, société, bon dans TF Buddy, puis « Valider »).
2. L'assistant « Ajouter un bureau » en sept étapes : écarté, il exige que la société soit déjà dans Entreprises B2B.
3. Un « Valider » qui marque seulement « traitée » : écarté par Sam (deux actions suffisent).

## Le comportement
- **Créer le bureau** : `POST /dashboard/bureaux-dev` reçoit en plus `hors_zone: id`. Si la création passe, la demande
  hors zone passe au statut `reprise` (`traite_le` = maintenant, `donnees._reprise` = id de la nouvelle demande). Elle
  quitte la liste ; un lien « voir les N demandes reprises » la remontre, avec « Ouvrir la demande ». Elle s'efface après
  90 jours comme toute demande traitée (purge existante : `statut <> "a_valider"`).
- **Poubelle** : `POST /dashboard/bureaux-dev/effacer {shop, id}`, après `confirm()` (« La copie du cockpit disparaît ;
  l'original reste dans TF Buddy »). N'efface qu'une ligne `source = "hors_zone"` du magasin ; sinon 404. Journalisé.
- « Renvoyer » (TF Buddy n'a pas reçu) reste, à côté.

## Laissé au constructeur
Libellés exacts, l'icône (tracé de la corbeille de `boite.js`), la mise en page des boutons dans la cellule.
