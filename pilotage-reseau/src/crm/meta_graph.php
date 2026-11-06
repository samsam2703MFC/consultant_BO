<?php
/**
 * Meta (Facebook / Instagram) — le strict nécessaire de la Graph API pour les
 * formulaires de prospects (« Lead Ads »).
 *
 * COPIE IDENTIQUE dans deux applications déployées séparément :
 *   app/crm/api/lib/meta_graph.php      (réglages, connexion, rattrapage)
 *   app/franchise/api/meta_graph.php    (webhook : création du candidat)
 * `tools/check-traduction.sh` échoue si elles divergent.
 *
 * Aucune dépendance au reste de l'application : ni base, ni réglages.
 */

const META_GRAPH_VERSION = 'v25.0';

/** Racine de la Graph API (META_GRAPH_BASE = serveur factice pour les tests locaux). */
function meta_graph_base(): string
{
    $b = getenv('META_GRAPH_BASE');
    return $b ? rtrim((string) $b, '/') : 'https://graph.facebook.com/' . META_GRAPH_VERSION;
}

/**
 * Appel Graph. `$path` relatif (« 123/leads ») ou URL complète de pagination
 * (`paging.next`, qui porte déjà le jeton).
 *
 * @return array{ok: bool, code: int, data: array, error: ?string}
 */
function meta_graph(string $method, string $path, array $params = [], ?string $token = null, int $timeout = 20): array
{
    if (!function_exists('curl_init')) return ['ok' => false, 'code' => 0, 'data' => [], 'error' => 'Extension cURL absente du serveur.'];
    if ($token !== null && $token !== '') $params['access_token'] = $token;
    $url = preg_match('#^https?://#', $path) ? $path : meta_graph_base() . '/' . ltrim($path, '/');
    $ch = curl_init();
    if ($method === 'GET') {
        if ($params) $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($params);
        curl_setopt($ch, CURLOPT_URL, $url);
    } else {
        curl_setopt_array($ch, [CURLOPT_URL => $url, CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($params)]);
        if ($method === 'DELETE') curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    }
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    if ($raw === false) return ['ok' => false, 'code' => 0, 'data' => [], 'error' => 'Meta injoignable : ' . $cerr];
    $d = json_decode((string) $raw, true);
    if (!is_array($d)) $d = [];
    if ($code >= 400 || isset($d['error'])) return ['ok' => false, 'code' => $code, 'data' => $d, 'error' => meta_error_text($d)];
    return ['ok' => true, 'code' => $code, 'data' => $d, 'error' => null];
}

/** Une erreur Meta en clair, avec la cause la plus probable et le remède. */
function meta_error_text(array $d): string
{
    $e = is_array($d['error'] ?? null) ? $d['error'] : [];
    $msg = (string) ($e['message'] ?? 'réponse inattendue');
    $code = (int) ($e['code'] ?? 0);
    $aide = '';
    // L'application elle-même bloquée par Meta : aucun jeton n'y changera rien.
    // Toute l'APPLICATION est bloquée (son propre jeton aussi, oct. 2026) : ni
    // le jeton ni les permissions n'y sont pour rien. L'import par fichier ne
    // passe pas par l'API : il reste ouvert en attendant.
    if (stripos($msg, 'api access blocked') !== false) $aide = "Meta a bloqué l'application elle-même (pas le jeton) — developers.facebook.com → Mes apps → votre application : le bandeau en haut du tableau de bord et les Alertes disent pourquoi et quoi faire. En attendant, téléchargez les prospects chez Meta et déposez le fichier dans « Leads de plus de 90 jours » : l'import par fichier n'a pas besoin de l'API";
    elseif ($code === 190) $aide = "jeton invalide ou expiré — utilisez le jeton d'un utilisateur système du Business Manager, qui n'expire pas";
    elseif (in_array($code, [10, 200, 283, 3], true)) $aide = "permission manquante — le jeton doit porter leads_retrieval, pages_manage_metadata, pages_manage_ads, pages_show_list, pages_read_engagement et ads_management, être demandé par une personne qui peut faire de la publicité sur la Page, et l'application doit avoir l'accès aux prospects (Business Manager → Page → Accès aux prospects)";
    elseif ($code === 100) $aide = "identifiant ou champ refusé — vérifiez l'ID de la Page et celui de l'application";
    elseif (in_array($code, [4, 17, 32, 613], true)) $aide = 'limite d’appels Meta atteinte — réessayez plus tard';
    return "Meta ($code) : $msg" . ($aide !== '' ? " — $aide." : '');
}

/** Signature d'un webhook (en-tête X-Hub-Signature-256) : HMAC-SHA256 du corps BRUT avec le secret de l'application. */
function meta_signature_ok(string $raw, string $header, string $secret): bool
{
    if ($secret === '' || strncmp($header, 'sha256=', 7) !== 0) return false;
    return hash_equals('sha256=' . hash_hmac('sha256', $raw, $secret), $header);
}

/**
 * `field_data` d'un lead → [clé normalisée => valeur] et la liste
 * [clé, valeur] dans l'ordre du formulaire (rien ne se perd : ce qui n'a pas
 * de champ dans le CRM part en note).
 *
 * @return array{0: array<string,string>, 1: list<array{0:string,1:string}>}
 */
function meta_lead_fields(array $fieldData): array
{
    $flat = []; $qa = [];
    foreach ($fieldData as $f) {
        if (!is_array($f)) continue;
        $k = mb_strtolower(trim((string) ($f['name'] ?? '')));
        if ($k === '') continue;
        $v = trim(implode(', ', array_map('strval', (array) ($f['values'] ?? []))));
        $flat[$k] = $v;
        $qa[] = [$k, $v];
    }
    return [$flat, $qa];
}

/** Première valeur non vide parmi des clés possibles. */
function meta_pick(array $flat, array $keys): string
{
    foreach ($keys as $k) if (isset($flat[$k]) && trim($flat[$k]) !== '') return trim($flat[$k]);
    return '';
}

/**
 * Version de la lecture des coordonnées (meta_lead_coord). À AUGMENTER à
 * chaque fois qu'elle apprend à trouver ce qu'elle ratait : les leads
 * « sans e-mail » lus par une version plus ancienne sont relus d'office
 * (fl_meta_relire, cron et « Récupérer e-mails et réponses »).
 * 2 : adresse prise dans n'importe quelle réponse qui EN EST une, nettoyée.
 */
const META_LECTEUR_V = 2;

/**
 * Une réponse → adresse e-mail propre, ou '' si ce n'en est pas une.
 * Les fautes de frappe qui ne changent pas l'adresse sont réparées : espaces
 * (« jean @ gmail.com », correcteur du téléphone), majuscules, « mailto: »,
 * point ou virgule final, virgule à la place d'un point APRÈS l'arobase
 * (« gmail,com »), adresse au milieu d'une phrase (« c'est jean@x.be merci »).
 * Accents permis avant l'arobase (FILTER_FLAG_EMAIL_UNICODE) : « josé@… ».
 */
function meta_email_propre(string $v): string
{
    $v = mb_strtolower(trim($v));
    if ($v === '' || !str_contains($v, '@')) return '';
    $v = (string) preg_replace('/^mailto:/u', '', $v);
    if (preg_match('/[^\s<>"\'(),;:]+\s*@\s*[^\s<>"\'();:]+/u', $v, $m)) $v = $m[0];
    $v = (string) preg_replace('/\s+/u', '', $v);
    $v = rtrim($v, '.,;:!?');
    $at = strrpos($v, '@');
    if ($at !== false) $v = substr($v, 0, $at + 1) . str_replace(',', '.', substr($v, $at + 1));
    return filter_var($v, FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE) ? $v : '';
}

/**
 * Coordonnées d'un lead : e-mail, prénom, nom, téléphone, ville, code postal.
 *
 * D'abord les champs standard de Meta ; à défaut, une question PERSONNALISÉE
 * qui les porte (« Votre adresse e-mail », « Numéro de GSM », « Code postal »).
 * Sans ce repli, un formulaire qui posait sa propre question e-mail donnait un
 * lead « sans e-mail », donc sans fiche, alors que l'adresse était là. Une
 * seule lecture pour l'arrivée du lead (API franchise) et pour le formulaire
 * de l'écran Leads (CRM) : sinon l'un trouverait ce que l'autre rate.
 *
 * @param array<string,string> $flat clé normalisée => valeur (meta_lead_fields)
 * L'e-mail, dans cet ordre : le champ standard ; une question qui parle
 * d'e-mail ; à défaut, N'IMPORTE QUELLE réponse qui est une adresse (« Comment
 * vous joindre ? »). `emailBrut` = ce que la personne a tapé quand rien n'a pu
 * en faire une adresse — dit au journal, pour la corriger à la main.
 *
 * @return array{email:string,emailBrut:string,first:string,last:string,phone:string,ville:string,cp:string}
 */
function meta_lead_coord(array $flat): array
{
    $email = meta_email_propre(meta_pick($flat, ['email', 'e-mail', 'e_mail', 'work_email', 'adresse_e-mail']));
    $emailBrut = '';
    $first = meta_pick($flat, ['first_name', 'prénom', 'prenom', 'voornaam']);
    $last = meta_pick($flat, ['last_name', 'nom', 'nom_de_famille', 'achternaam']);
    if ($first === '' && $last === '') {
        $full = preg_split('/\s+/u', meta_pick($flat, ['full_name', 'nom_complet', 'volledige_naam', 'name']), 2) ?: [];
        $first = trim((string) ($full[0] ?? ''));
        $last = trim((string) ($full[1] ?? ''));
    }
    $phone = meta_pick($flat, ['phone_number', 'phone', 'téléphone', 'telephone', 'work_phone_number', 'gsm']);
    $ville = meta_pick($flat, ['city', 'ville', 'gemeente', 'stad']);
    $cp = meta_pick($flat, ['zip', 'zip_code', 'post_code', 'postal_code', 'code_postal', 'postcode']);
    // Questions personnalisées : la question doit PARLER du champ, et la
    // réponse en avoir la forme — « Avez-vous un apport ? 50 000 » n'est pas
    // un numéro de téléphone.
    $repli = function (string $motifCle, callable $forme) use ($flat): string {
        foreach ($flat as $k => $v) {
            $v = trim((string) $v);
            if ($v !== '' && preg_match($motifCle, (string) $k) && $forme($v)) return $v;
        }
        return '';
    };
    if ($email === '') {
        $x = $repli('/mail|courriel|lectronique/u', fn (string $v): bool => meta_email_propre($v) !== '');
        if ($x === '') $x = $repli('/./u', fn (string $v): bool => meta_email_propre($v) !== '');
        $email = meta_email_propre($x);
        if ($email === '') {
            // Rien d'exploitable : ce que la personne a tapé, s'il y a quelque chose.
            $emailBrut = meta_pick($flat, ['email', 'e-mail', 'e_mail', 'work_email', 'adresse_e-mail']);
            if ($emailBrut === '') $emailBrut = $repli('/mail|courriel|lectronique/u', fn (string $v): bool => true);
            if ($emailBrut === '') $emailBrut = $repli('/./u', fn (string $v): bool => str_contains($v, '@'));
        }
    }
    if ($phone === '') {
        $tel = fn (string $v): bool => preg_match_all('/\d/', $v) >= 8 && preg_match('/^[\d\s+().\/-]+$/', $v) === 1;
        $phone = $repli('/t[ée]l[ée]phone|phone|gsm|portable|mobile|telefoon/u', $tel);
        if ($phone === '') $phone = $repli('/num[ée]ro|nummer/u', $tel);
    }
    if ($cp === '') $cp = $repli('/code.?postal|postcode|postnummer|zip/u', fn (string $v): bool => preg_match('/^\d{4,5}$/', $v) === 1);
    return ['email' => $email, 'emailBrut' => mb_substr($emailBrut, 0, 120), 'first' => $first, 'last' => $last, 'phone' => $phone, 'ville' => $ville, 'cp' => $cp];
}

/**
 * Toutes les réponses d'un lead, dans l'ordre du formulaire, libellées en
 * clair — coordonnées comprises (c'est ce que la personne a écrit) — puis les
 * cases de consentement. Gardées au journal (fl_meta_leads.reponses) : c'est
 * là qu'on les relit pour CHAQUE lead, y compris celui qui n'a pas de fiche.
 *
 * @param array $lead objet lead de la Graph API (field_data,
 *                    custom_disclaimer_responses)
 * @return list<array{label:string,valeur:string}>
 */
function meta_lead_reponses(array $lead): array
{
    [, $qa] = meta_lead_fields((array) ($lead['field_data'] ?? []));
    $out = [];
    foreach ($qa as [$k, $v]) if ($v !== '') $out[] = ['label' => meta_question_label($k), 'valeur' => $v];
    foreach ((array) ($lead['custom_disclaimer_responses'] ?? []) as $cb) {
        if (!is_array($cb) || empty($cb['checkbox_key'])) continue;
        $out[] = ['label' => 'Case « ' . meta_question_label((string) $cb['checkbox_key']) . ' »', 'valeur' => !empty($cb['is_checked']) ? 'cochée' : 'non cochée'];
    }
    return $out;
}

/** Locale d'un formulaire Meta (nl_BE, en_GB, fr_FR…) → langue du CRM. */
function meta_lang(?string $locale): string
{
    $l = strtolower(substr((string) $locale, 0, 2));
    return in_array($l, ['nl', 'en'], true) ? $l : 'fr';
}

/** Libellé lisible d'une clé de question (« quel_emplacement_? » → « Quel emplacement ? »). */
function meta_question_label(string $key): string
{
    // Les champs standard de Meta ont un nom anglais : on les dit en français.
    $std = ['full_name' => 'Nom complet', 'first_name' => 'Prénom', 'last_name' => 'Nom', 'email' => 'E-mail', 'phone_number' => 'Téléphone',
        'city' => 'Ville', 'zip' => 'Code postal', 'zip_code' => 'Code postal', 'post_code' => 'Code postal', 'postal_code' => 'Code postal',
        'street_address' => 'Adresse', 'state' => 'Région', 'province' => 'Province', 'country' => 'Pays', 'date_of_birth' => 'Date de naissance',
        'gender' => 'Genre', 'job_title' => 'Fonction', 'company_name' => 'Entreprise', 'marital_status' => 'État civil', 'military_status' => 'Statut militaire',
        'relationship_status' => 'Situation familiale', 'work_email' => 'E-mail professionnel', 'work_phone_number' => 'Téléphone professionnel'];
    if (isset($std[$key])) return $std[$key];
    $s = trim(str_replace('_', ' ', $key));
    return $s === '' ? $key : mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
}
