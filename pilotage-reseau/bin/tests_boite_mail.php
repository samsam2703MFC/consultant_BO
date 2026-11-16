#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests de la boîte mail du magasin (src/franchise/boite_protocole.php).
 *
 *   php bin/tests_boite_mail.php
 *       la lecture MIME (en-têtes encodés, jeux de caractères, multipart, noms de fichier RFC 2231, adresses), la
 *       composition d'un e-mail et sa relecture, le nettoyage du HTML — sans réseau.
 *   BM_TEST_IMAP=127.0.0.1:1143:aucune BM_TEST_SMTP=127.0.0.1:2525:aucune BM_TEST_USER=… BM_TEST_PASS=… php bin/tests_boite_mail.php
 *       en plus, contre un vrai serveur IMAP (Dovecot) et un serveur SMTP de test : dossiers, dépôt, recherche, lecture,
 *       drapeaux, déplacement, envoi. Jamais contre une vraie boîte : le test y dépose et y déplace des e-mails.
 *
 * Les e-mails ci-dessous sont des formes d'e-mail (ce que les messageries produisent), pas des données du réseau.
 */

require __DIR__ . '/../src/franchise/boite_protocole.php';

$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

echo "En-têtes\n";
verifie('mot encodé B en UTF-8', bmEntete('=?UTF-8?B?RGVtYW5kZSBkZSBkZXZpcyDigJMgYW5uaXZlcnNhaXJl?=') === 'Demande de devis – anniversaire');
verifie('mot encodé Q en ISO-8859-1 (é, espace)', bmEntete('=?ISO-8859-1?Q?R=E9servation_g=E2teau?=') === 'Réservation gâteau');
verifie('deux mots encodés recollés', bmEntete('=?UTF-8?Q?Caf=C3=A9?= =?UTF-8?Q?_et_croissants?=') === 'Café et croissants');
verifie('8 bits sans annonce (Windows-1252) relu en UTF-8', bmEntete("Cr\xE8me br\xFBl\xE9e") === 'Crème brûlée');
$p = bmParams("attachment; filename*0*=UTF-8''Devis%20n%C2%B0; filename*1*=%20412.pdf");
verifie('nom de fichier RFC 2231 en deux morceaux', ($p['filename'] ?? '') === 'Devis n° 412.pdf', json_encode($p));
$p = bmParams('text/plain; charset="iso-8859-1"; format=flowed');
verifie('paramètres entre guillemets ou non', ($p['charset'] ?? '') === 'iso-8859-1' && ($p['format'] ?? '') === 'flowed');
$a = bmAdresses('"Dupont, Marie" <Marie.Dupont@Exemple.be>, contact@societe.example, =?UTF-8?Q?L=C3=A9a?= <lea@exemple.be>');
verifie('trois adresses, la virgule entre guillemets ne coupe pas', count($a) === 3 && $a[0]['nom'] === 'Dupont, Marie' && $a[0]['adresse'] === 'marie.dupont@exemple.be', json_encode($a));
verifie('nom encodé décodé', ($a[2]['nom'] ?? '') === 'Léa');

echo "Un e-mail multipart (texte, HTML, image intégrée, PDF joint)\n";
$brut = "From: =?UTF-8?Q?Soci=C3=A9t=C3=A9?= <achats@societe.example>\r\nTo: magasin@atelierby.be\r\nSubject: =?UTF-8?B?Q29tbWFuZGUgZOKAmXVuIHBsYXRlYXU=?=\r\n"
    . "Message-ID: <abc123@societe.example>\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"MIX\"\r\n\r\npréambule ignoré\r\n"
    . "--MIX\r\nContent-Type: multipart/related; boundary=\"REL\"\r\n\r\n"
    . "--REL\r\nContent-Type: multipart/alternative; boundary=\"ALT\"\r\n\r\n"
    . "--ALT\r\nContent-Type: text/plain; charset=iso-8859-1\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\nBonjour,\r\nNous voudrions 30 croissants et 2 tartes pour le 21/11. Merci =E0 vous=\r\n !\r\n"
    . "--ALT\r\nContent-Type: text/html; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
    . chunk_split(base64_encode('<p onclick="x()">Bonjour,</p><script>alert(1)</script><p>Nous voudrions <b>30 croissants</b>.</p><img src="cid:logo@x"><a href="javascript:alert(2)">lien</a>'), 76, "\r\n")
    . "--ALT--\r\n"
    . "--REL\r\nContent-Type: image/png\r\nContent-ID: <logo@x>\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: inline\r\n\r\n" . base64_encode("\x89PNG\r\n\x1a\nfaux") . "\r\n"
    . "--REL--\r\n"
    . "--MIX\r\nContent-Type: application/pdf; name=\"=?UTF-8?Q?bon_de_commande_n=C2=B0_7.pdf?=\"\r\nContent-Disposition: attachment\r\nContent-Transfer-Encoding: base64\r\n\r\n" . base64_encode('%PDF-1.4 contenu') . "\r\n"
    . "--MIX--\r\népilogue ignoré\r\n";
$lu = bmMimeLire($brut);
$c = bmMimeContenu($lu);
verifie('objet décodé', bmEntete($lu['entetes']['subject'] ?? '') === 'Commande d’un plateau');
verifie('expéditeur', (bmAdresses($lu['entetes']['from'] ?? '')[0]['nom'] ?? '') === 'Société');
verifie('texte en quoted-printable ISO-8859-1, ligne molle recollée', str_contains($c['texte'], 'Merci à vous !'), $c['texte']);
verifie('HTML en base64', is_string($c['html']) && str_contains($c['html'], '30 croissants'));
verifie('une pièce jointe, nom décodé, contenu exact', count($c['pieces']) === 1 && $c['pieces'][0]['nom'] === 'bon de commande n° 7.pdf' && $c['pieces'][0]['taille'] === strlen('%PDF-1.4 contenu'), json_encode($c['pieces']));
verifie('l’image intégrée n’est pas une pièce jointe (cid)', isset($c['cids']['logo@x']));
verifie('ni préambule ni épilogue comptés', count($lu['feuilles']) === 4, (string) count($lu['feuilles']));
$propre = bmHtmlPropre((string) $c['html'], $c['cids'], static fn ($id) => '/piece?p=' . $id);
verifie('HTML nettoyé : ni script, ni onclick, ni javascript:', !str_contains($propre, '<script') && !str_contains($propre, 'onclick') && !str_contains(strtolower($propre), 'javascript:'), $propre);
verifie('image intégrée → adresse de la pièce', str_contains($propre, '/piece?p=1.1.2'), $propre);

echo "Écrire un e-mail, et le relire\n";
[$m, $mid] = bmComposer(['nom' => 'L’Atelier By Halle', 'adresse' => 'halle@atelierby.be'], [['nom' => 'Marie', 'adresse' => 'marie@exemple.be']], [],
    'Re: Votre demande – offre n° 412', "Bonjour Marie,\n\nVoici notre offre.\n.\nUn point seul sur une ligne.\n\n> Nous voudrions 30 croissants", [['nom' => 'Offre n° 412.pdf', 'type' => 'application/pdf', 'contenu' => '%PDF-1.4 x']], ['<abc123@societe.example>', '']);
$r = bmMimeLire($m); $rc = bmMimeContenu($r);
verifie('objet relu à l’identique', bmEntete($r['entetes']['subject'] ?? '') === 'Re: Votre demande – offre n° 412');
verifie('réponse : In-Reply-To et References', ($r['entetes']['in-reply-to'] ?? '') === '<abc123@societe.example>' && str_contains($r['entetes']['references'] ?? '', '<abc123@societe.example>'));
verifie('Message-ID au domaine de la boîte', str_ends_with($mid, '@atelierby.be>'));
verifie('texte relu', str_contains($rc['texte'], 'Voici notre offre.') && str_contains($rc['texte'], '> Nous voudrions'));
verifie('la citation en bloc de citation dans le HTML', str_contains((string) $rc['html'], '<blockquote'));
verifie('la pièce jointe relue, nom UTF-8 compris', count($rc['pieces']) === 1 && $rc['pieces'][0]['nom'] === 'Offre n° 412.pdf', json_encode($rc['pieces']));
verifie('expéditeur avec apostrophe typographique encodé', (bmAdresses($r['entetes']['from'] ?? '')[0]['nom'] ?? '') === 'L’Atelier By Halle');

// --- contre de vrais serveurs (tests) ---------------------------------------------------------------------------
$imap = getenv('BM_TEST_IMAP'); $smtp = getenv('BM_TEST_SMTP'); $u = (string) getenv('BM_TEST_USER'); $pw = (string) getenv('BM_TEST_PASS');
if ($imap && $u !== '') {
    echo "Contre un serveur IMAP de test ($imap)\n";
    $srv = bmServeur($imap, '');
    try { BmImap::ouvrir($srv, $u, 'mauvais-mot-de-passe'); verifie('un mauvais mot de passe est refusé', false); }
    catch (BmRefus $e) { verifie('un mauvais mot de passe est refusé (BmRefus)', true); }
    $s = BmImap::ouvrir($srv, $u, $pw);
    $d = $s->dossiers();
    $roles = array_column($d, 'role', 'brut');
    verifie('les dossiers et leurs rôles (réception, envoyés, archives, corbeille)', ($roles['INBOX'] ?? '') === 'inbox' && in_array('sent', $roles, true) && in_array('archive', $roles, true) && in_array('trash', $roles, true), json_encode($d));
    $avant = $s->etat('INBOX');
    verifie('déposer l’e-mail multipart en réception (non lu)', $s->deposer('INBOX', $brut, ''));
    verifie('déposer un e-mail 8 bits', $s->deposer('INBOX', "From: x@exemple.be\r\nSubject: Cr\xE8me\r\nContent-Type: text/plain; charset=iso-8859-1\r\n\r\nG\xE2teau pour 12 personnes\r\n", ''));
    $apres = $s->etat('INBOX');
    verifie('STATUS : deux de plus, deux non lus de plus', $apres['total'] === $avant['total'] + 2 && $apres['nonLus'] === $avant['nonLus'] + 2, json_encode([$avant, $apres]));
    $s->ouvrirDossier('INBOX');
    $tous = $s->chercher(['ALL']);
    $trouve = $s->chercher(['TEXT ', ['ch' => 'croissants']]);
    verifie('recherche plein texte', count($trouve) >= 1 && in_array(max($tous) - 1, $trouve, true), json_encode([$tous, $trouve]));
    $trouve8 = $s->chercher(['TEXT ', ['ch' => 'Gâteau']]);
    verifie('recherche avec accent (CHARSET UTF-8, littéral)', in_array(max($tous), $trouve8, true), json_encode($trouve8));
    $l = $s->lire([max($tous) - 1, max($tous)], 'UID FLAGS INTERNALDATE RFC822.SIZE BODYSTRUCTURE BODY.PEEK[HEADER.FIELDS (FROM TO CC SUBJECT DATE MESSAGE-ID)]');
    $e1 = bmMimeCouper((string) ($l[max($tous) - 1]['BODY[HEADER]'] ?? ''))[0];
    verifie('liste : en-têtes lus sans marquer lu', bmEntete($e1['subject'] ?? '') === 'Commande d’un plateau' && !in_array('\\Seen', (array) $l[max($tous) - 1]['FLAGS'], true), json_encode($l[max($tous) - 1]['FLAGS'] ?? null));
    verifie('liste : BODYSTRUCTURE lu en listes', is_array($l[max($tous) - 1]['BODYSTRUCTURE'] ?? null));
    $plein = $s->lire([max($tous) - 1], 'UID BODY.PEEK[]');
    verifie('lecture complète : l’e-mail revient octet pour octet', rtrim((string) ($plein[max($tous) - 1]['BODY[]'] ?? '')) === rtrim($brut));
    $s->drapeaux([max($tous)], '+', '\\Seen');
    $f = $s->lire([max($tous)], 'UID FLAGS');
    verifie('marquer lu', in_array('\\Seen', (array) $f[max($tous)]['FLAGS'], true));
    $arch = array_search('archive', $roles, true);
    $s->deplacer([max($tous)], (string) $arch);
    verifie('déplacer vers les archives', !in_array(max($tous), $s->chercher(['ALL']), true) && $s->etat((string) $arch)['total'] >= 1);
    $env = array_search('sent', $roles, true);
    $n0 = (int) $s->etat((string) $env)['total'];
    verifie('ranger une copie dans « Envoyés »', $s->deposer((string) $env, $m) && (int) $s->etat((string) $env)['total'] === $n0 + 1);
    unset($s);
}
if ($smtp && $u !== '') {
    echo "Contre un serveur SMTP de test ($smtp)\n";
    $srv = bmServeur($smtp, '');
    try { BmSmtp::envoyer($srv, $u, 'mauvais', 'halle@atelierby.be', ['marie@exemple.be'], $m); verifie('un mauvais mot de passe est refusé à l’envoi', false); }
    catch (RuntimeException $e) { verifie('un mauvais mot de passe est refusé à l’envoi', str_contains($e->getMessage(), 'mot de passe'), $e->getMessage()); }
    try { BmSmtp::envoyer($srv, $u, $pw, 'halle@atelierby.be', ['marie@exemple.be'], $m); verifie('envoi accepté', true); }
    catch (Throwable $e) { verifie('envoi accepté', false, $e->getMessage()); }
    try { BmSmtp::envoyer($srv, $u, $pw, 'halle@atelierby.be', ['marie@exemple.be', 'refuse@exemple.be'], $m); verifie('un destinataire refusé est dit', false); }
    catch (BmPartiel $e) { verifie('un destinataire refusé est dit (BmPartiel)', str_contains($e->getMessage(), 'refuse@exemple.be')); }
}

echo "\n$ok ok, $ko en échec\n";
exit($ko === 0 ? 0 : 1);
