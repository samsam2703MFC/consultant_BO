# SMS des formulaires bureaux — design court (28/11/2026)

## Pourquoi
Sam : « rajouter un SMS envoyé lors d'un formulaire envoyé ? au GSM du franchisé et au mien (Sam) », « paramètre dans
consultant BO, rail dashboard », puis « A » (les deux formulaires bureaux) et « oui lance » sur ce design.

## Le comportement
- **Déclencheurs** : une nouvelle inscription par le formulaire en six étapes (`bdvPublic`, source `questionnaire`) et
  une nouvelle demande hors zone (`bdvHorsZoneEnregistrer`, à l'insertion seulement : un nouvel essai, même
  `request_key`, ne renvoie rien).
- **Destinataires** : le GSM du franchisé (réglage du magasin : interrupteur + numéro) et le numéro du siège (un réglage
  pour tout le réseau : interrupteur + numéro), lu et modifié par le siège seul ; le franchisé lit « le siège reçoit aussi
  ces SMS ». Deux numéros identiques : un seul SMS.
- **Texte** : un SMS, en ASCII (accents retirés, 160 caractères au plus), jamais l'e-mail ni le téléphone du prospect :
  `L'Atelier By Corbais : nouvelle inscription bureau - Imprimerie du Val, 1370, 22 pers. Dashboard > Clients > Bureaux`
  (« demande hors zone » pour l'autre formulaire).
- **Garde-fous** : au plus 10 formulaires notifiés par jour et par magasin (essais compris) ; l'envoi part après la
  réponse au visiteur (`register_shutdown_function`, `fastcgi_finish_request` s'il existe) ; chaque envoi au journal.
- **Réglage** : dashboard › Paramètres (la roue du rail) › groupe « Alertes SMS » › « SMS des formulaires bureaux » :
  interrupteur et GSM du franchisé, bloc du siège (siège seul), « Envoyer un SMS d'essai », avertissement sans
  fournisseur SMS réglé (`smsConfigure()`).

## Approches
- Retenue : un module `panel/src/sms_formulaires.php` (tables `panel_sms_formulaire`, `panel_sms_formulaire_envoi`),
  appelé par `bureaux_dev.php` ; l'envoi par `shared/src/sms.php` (Twilio, déjà utilisé par les primes).
- Écartées : le numéro écrit dans le code (règle du dépôt) ; un e-mail (pas demandé).
