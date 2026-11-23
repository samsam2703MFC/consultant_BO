<?php
/**
 * [383] Asystent « Poczta od klientów » : heures comptées sur les jours ouvrés (week.production_days : week-end exclu) et
 * terme de réponse ; boîte = celle de Poczta (MailController::mailUser : la sienne ; administrateur dans la session d'un poste
 * → boîte de la personne du poste ; poczta wstrzymana → rien ; pas de boîte → rien) ; état de la synchronisation (OK, en
 * retard, erreur, jamais, wstrzymana, aucune boîte) — jamais « 0 e-mail » présenté comme un résultat quand la synchro est
 * en panne ; e-mails de clients (INBOX, id_customer) des lookback_days jours ; « répondu » = answered, réponse « Wysłane z
 * ERP » (status sent ; un échec ne compte pas) ou dossier Wysłane (in_reply_to) ; signaux sans réponse après reply_hours h et
 * réponse datée d'aujourd'hui après le terme ; vue par client ; délai moyen des réponses du jour ; décisions (odpowiedziane
 * refusé tant que rien n'est répondu, notes obligatoires, przekazane avec personne / poste), décision caduque, message d'une
 * autre boîte refusé, étape 3 bloquée, « Zakończ » → n_wizard_done, état de la tâche ; réglages bornés ; aucune écriture
 * dans les tables d'origine (checksums) ; AUCUN ENVOI NI CONNEXION : contrôleur construit par PHP-DI (SMTP, file e-mail,
 * SMS espionnés — jamais construits ni appelés), boîtes pointées sur un faux serveur TCP local qui ne reçoit aucune
 * connexion, aucun mot de passe ni réglage de connexion dans les réponses ; data(), decision(), finish() réellement appelés
 * (connecté 200, non connecté 403), et MailController::messages() réellement appelé pour prouver que l'assistant montre
 * exactement la boîte que Poczta montre ; enregistrement (catalogue, droits, routes, équipe, menu) ; migration 231 rejouable.
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide).
 * Base erp_customer_mail_check_test recréée (tables Poczta des migrations 071, 168, 205, 206), supprimée à la fin
 * (CM_JSON_OUT=fichier : données de la page avant et après les décisions, pour un aperçu).
 */
declare(strict_types=1);
require_once __DIR__ . '/../public/vendor/autoload.php';
require_once __DIR__ . '/../App/Core/functions.php';

use App\Controllers\Web\Mail\{CustomerMailCheckController, MailController};
use App\Core\Web\{PageAccess, ViewSession};
use App\Exceptions\CustomException;
use App\Repositories\Mail\{CustomerMailCheckRepository, UserMailRepository};
use App\Repositories\Organization\{LightWizardRepository, PageAccessRepository, TaskWizardRepository};
use App\Repositories\Workshop\ParamRepository;
use App\Services\Communication\{OutgoingMessageService, SmtpTransport};
use App\Services\Logistics\Sms\SmsTransport;
use App\Services\Organization\{PageAccessService, PositionTaskService, TaskWizards};
use App\Services\Organization\Wizards\CustomerMailCheckService;
use App\Services\Workshop\Migration\MigrationRunner;

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$pdo = new PDO($dsn, getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('DROP DATABASE IF EXISTS erp_customer_mail_check_test'); $pdo->exec('CREATE DATABASE erp_customer_mail_check_test CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$pdo->exec('USE erp_customer_mail_check_test');
foreach ([
    'CREATE TABLE user (id INT PRIMARY KEY, login VARCHAR(40), displayed_name VARCHAR(80) NULL, name VARCHAR(40) NULL, surname VARCHAR(40) NULL) ENGINE=InnoDB',
    "CREATE TABLE customer (id INT PRIMARY KEY, company VARCHAR(80), active TINYINT DEFAULT 1, email_head VARCHAR(120) NULL, email_salesman VARCHAR(120) NULL, email_accountant VARCHAR(120) NULL, communication_lang VARCHAR(5) NULL)",
    'CREATE TABLE supplier (id INT PRIMARY KEY, name VARCHAR(80), email VARCHAR(120) NULL, is_active TINYINT DEFAULT 1)',
    "CREATE TABLE n_position_menu (id_position INT NOT NULL, item VARCHAR(40) NOT NULL, open_path VARCHAR(190) NULL, PRIMARY KEY (id_position, item))",
    "CREATE TABLE n_wizard_done (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(40) NOT NULL, day DATE NOT NULL, summary VARCHAR(255) NULL, done_by INT NULL,
        done_at DATETIME NOT NULL, UNIQUE KEY uq_wizard_done (code, day))",
    "CREATE TABLE n_prod_param (code VARCHAR(64) PRIMARY KEY, value TEXT NOT NULL, default_value TEXT NOT NULL, value_type ENUM('int','decimal','string','bool','time','json') NOT NULL,
        unit VARCHAR(16) NULL, description_pl VARCHAR(255) NOT NULL, description_fr VARCHAR(255) NOT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, updated_by INT NULL)",
    "CREATE TABLE n_position (id INT PRIMARY KEY, code VARCHAR(40), name_pl VARCHAR(80), kind VARCHAR(20) DEFAULT 'erp', sort_order INT DEFAULT 0, active TINYINT DEFAULT 1)",
    "CREATE TABLE n_position_duty (id INT AUTO_INCREMENT PRIMARY KEY, id_position INT NOT NULL DEFAULT 1, number VARCHAR(12), wizard_code VARCHAR(40) NULL, proposed_wizard VARCHAR(160) NULL,
        rhythm ENUM('daily','weekly','monthly','quarterly','none') NOT NULL DEFAULT 'none')",
    'CREATE TABLE n_prod_user_role (id INT AUTO_INCREMENT PRIMARY KEY, id_user INT, role_code VARCHAR(30), id_group INT NULL)',
    'CREATE TABLE n_user_position (id_user INT PRIMARY KEY, id_position INT)',
    'CREATE TABLE n_page_access (id_position INT, page_key VARCHAR(40), updated_by INT NULL, PRIMARY KEY (id_position, page_key))',
] as $sql) $pdo->exec($sql);
// Tables Poczta : exactement les migrations du module (071 boîte, messages, « Wysłane z ERP » ; 168 politique administrateur ; 205, 206 colonnes de 345 / 347).
foreach (['071_user_mailbox.sql', '168_mailbox_admin.sql', '205_position_mailbox.sql', '206_mailbox_account_chosen.sql'] as $f) {
    foreach (MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/' . $f)) as $sql) if (trim($sql) !== '') $pdo->exec($sql);
}
$pdo->exec("INSERT INTO n_prod_param (code, value, default_value, value_type, description_pl, description_fr) VALUES ('week.production_days', '1,2,3,4,5', '1,2,3,4,5', 'string', 'dni', 'jours')");
$pdo->exec("INSERT INTO n_position (id, code, name_pl, sort_order) VALUES (1, 'ADMINISTRACJA', 'Administracja i fakturowanie', 1), (2, 'LOGISTYKA', 'Logistyka', 2), (3, 'PIEKARZ', 'Piekarz', 3)");
$pdo->exec("INSERT INTO n_position_duty (id_position, number, wizard_code, proposed_wizard, rhythm) VALUES (1, '6', NULL, 'Poczta od klientów', 'daily'),
    (1, '1', 'orders_day_checked', NULL, 'daily'), (1, '4', NULL, 'Dane klientów', 'monthly'), (2, '7', NULL, NULL, 'none')");
$m231 = (string) file_get_contents(__DIR__ . '/../migrations/tfb/231_customer_mail_check.sql');
for ($k = 0; $k < 2; $k++) foreach (MigrationRunner::splitStatements($m231) as $sql) if (trim($sql) !== '') $pdo->exec($sql);

$fail = 0;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) $fail++; };
$err = function (callable $f): string { try { $f(); return 'none'; } catch (CustomException $e) { return 'custom:' . $e->getCode() . ':' . $e->getMessage(); } };

// --- Migracja 231 (deux passages)
$duties = $pdo->query('SELECT number, wizard_code, proposed_wizard, rhythm FROM n_position_duty ORDER BY id')->fetchAll();
$check($duties[0]['wizard_code'] === 'customer_mail_checked' && $duties[0]['proposed_wizard'] === null && $duties[0]['rhythm'] === 'daily', 'migration 231: ADMINISTRACJA 6 → customer_mail_checked (daily)');
$check($duties[1]['wizard_code'] === 'orders_day_checked' && $duties[2]['proposed_wizard'] === 'Dane klientów' && $duties[3]['wizard_code'] === null, 'migration: other duties untouched');
$fks = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'n_customer_mail_check'")->fetchColumn();
$params = $pdo->query("SELECT code, value FROM n_prod_param WHERE code LIKE 'customer_mail.%' ORDER BY code")->fetchAll(PDO::FETCH_KEY_PAIR);
$check((int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_customer_mail_check'")->fetchColumn() === 1 && $fks === 0
    && $params === ['customer_mail.lookback_days' => '30', 'customer_mail.reply_hours' => '24'], 'migration replayed: 1 table, no foreign key, 2 params once');
$cols = array_column($pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_customer_mail_check'")->fetchAll(), 'COLUMN_NAME');
$check(array_intersect($cols, ['subject', 'body_text', 'snippet', 'from_email', 'from_name', 'to_list']) === [] && in_array('id_mail', $cols, true), 'wizard table keeps only the message id (no subject, sender, body)');
$last = array_values(array_filter(MigrationRunner::splitStatements($m231), fn($s) => trim($s) !== ''));
$check(trim(end($last), " \n;") === "UPDATE n_position_duty SET wizard_code = 'customer_mail_checked', proposed_wizard = NULL WHERE proposed_wizard = 'Poczta od klientów'", 'migration: last statement is the duty UPDATE');

// --- Faux serveur IMAP / SMTP local : si quoi que ce soit se connectait à une boîte, la connexion attendrait ici.
$srv = stream_socket_server('tcp://127.0.0.1:0', $eno, $estr);
$port = (int) substr((string) stream_socket_get_name($srv, false), strrpos((string) stream_socket_get_name($srv, false), ':') + 1);
stream_set_blocking($srv, false);
$connections = function () use ($srv): int { $n = 0; while (($c = @stream_socket_accept($srv, 0)) !== false) { $n++; fclose($c); } return $n; };

// --- Données fictives : contrôle le mar. 24.11.2026 à 15:00 ; jours ouvrés lun.–ven.
$pdo->exec("INSERT INTO user (id, login, displayed_name) VALUES (1, 'admin', NULL), (2, 'ewa', 'Ewa L.'), (3, 'adam', 'Adam K.'), (4, 'piotr', NULL)");
$pdo->exec("INSERT INTO customer (id, company, email_head) VALUES (1, 'Bistro Lumière', 'bistro@example.test'), (2, 'Hotel Vistule', 'hotel@example.test'), (3, 'Café Mazur', 'mazur@example.test'),
    (4, 'Sklep Nowy', 'nowy@example.test'), (5, 'Piekarnia Róża', 'roza@example.test')");
$pdo->exec("INSERT INTO supplier (id, name, email) VALUES (1, 'Młyn Testowy', 'mlyn@example.test')");
$box = $pdo->prepare("INSERT INTO n_user_mailbox (id_user, email, display_name, provider, imap_host, imap_port, imap_security, imap_username, imap_secret, smtp_host, smtp_port, smtp_security, smtp_username, smtp_secret, last_sync_at, last_error)
    VALUES (?, ?, ?, 'other', '127.0.0.1', ?, 'ssl', ?, 'SECRET-NOT-TO-READ', '127.0.0.1', ?, 'ssl', ?, 'SECRET-NOT-TO-READ', ?, NULL)");
$box->execute([2, 'biuro@example.test', 'Biuro', $port, 'biuro-login', $port, 'biuro-login', '2026-11-24 14:55:00']);
$box->execute([3, 'magazyn@example.test', 'Magazyn', $port, 'magazyn-login', $port, 'magazyn-login', '2026-11-24 14:58:00']);
$mail = $pdo->prepare("INSERT INTO n_user_mail (id, id_user, folder, uid_validity, uid, message_id, in_reply_to, from_email, from_name, subject, sent_at, seen, answered, snippet, body_text, id_customer, id_supplier)
    VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'treść poufna', 'treść poufna', ?, ?)");
foreach ([
    // id, box, folder, message_id, in_reply_to, from, name, subject, sent_at, seen, answered, customer, supplier
    [101, 2, 'INBOX', '<m101@ex>', null, 'bistro@example.test', 'Bistro Lumière', 'Zamówienie na piątek', '2026-11-20 15:00:00', 1, 0, 1, null],     // pt. 15:00 → 48 h rob.
    [102, 2, 'INBOX', '<m102@ex>', null, 'hotel@example.test', '', 'Pytanie o fakturę', '2026-11-23 10:00:00', 0, 0, 2, null],                      // 29 h, nieprzeczytany
    [103, 2, 'INBOX', '<m103@ex>', null, 'mazur@example.test', 'Café Mazur', 'Nowe croissanty', '2026-11-24 09:00:00', 1, 0, 3, null],            // 6 h : w terminie
    [104, 2, 'INBOX', '<m104@ex>', null, 'bistro@example.test', 'Bistro Lumière', 'Reklamacja', '2026-11-21 10:00:00', 1, 0, 1, null],             // sobota : 39 h rob. (77 h kal.)
    [105, 2, 'INBOX', '<m105@ex>', null, 'nowy@example.test', 'Sklep Nowy', 'Dziękujemy', '2026-11-20 12:00:00', 1, 1, 4, null],                   // flaga odpowiedzi
    [106, 2, 'INBOX', '<m106@ex>', null, 'roza@example.test', 'Piekarnia Róża', 'Cennik 2027', '2026-11-19 09:00:00', 1, 1, 5, null],              // odpowiedź z ERP dziś, po terminie
    [107, 2, 'INBOX', '<m107@ex>', null, 'hotel@example.test', 'Hotel Vistule', 'Dostawa', '2026-11-23 08:00:00', 1, 0, 2, null],                  // odpowiedź w Wysłanych wczoraj
    [108, 2, 'INBOX', '<m108@ex>', null, 'mazur@example.test', 'Café Mazur', 'Godzina dostawy', '2026-11-24 08:00:00', 1, 0, 3, null],            // odpowiedź w Wysłanych dziś (3 h)
    [109, 2, 'INBOX', '<m109@ex>', null, 'mlyn@example.test', 'Młyn', 'Oferta mąki', '2026-11-20 09:00:00', 0, 0, null, 1],                         // dostawca : pominięty
    [110, 2, 'INBOX', '<m110@ex>', null, 'bistro@example.test', 'Bistro Lumière', 'Stary e-mail', '2026-10-01 09:00:00', 1, 0, 1, null],           // poza 30 dniami
    [111, 2, 'INBOX', '<m111@ex>', null, 'nowy@example.test', 'Sklep Nowy', 'Newsletter klienta', '2026-11-18 09:00:00', 1, 0, 4, null],           // wyjaśniony wczoraj
    [112, 2, 'INBOX', '<m112@ex>', null, 'roza@example.test', 'Piekarnia Róża', 'Paleta', '2026-11-23 09:00:00', 1, 0, 5, null],                   // odpowiedź z ERP nieudana
    [201, 2, 'SENT', '<s201@ex>', '<m107@ex>', 'biuro@example.test', 'Biuro', 'Re: Dostawa', '2026-11-23 16:00:00', 1, 0, 2, null],
    [202, 2, 'SENT', '<s202@ex>', '<m108@ex>', 'biuro@example.test', 'Biuro', 'Re: Godzina dostawy', '2026-11-24 11:00:00', 1, 0, 3, null],
    [301, 3, 'INBOX', '<m301@ex>', null, 'bistro@example.test', 'Bistro Lumière', 'Palety do odbioru', '2026-11-20 10:00:00', 1, 0, 1, null],     // skrzynka Adama
] as $i => $row) $mail->execute([$row[0], $row[1], $row[2], $i + 1, $row[3], $row[4], $row[5], $row[6], $row[7], $row[8], $row[9], $row[10], $row[11], $row[12]]);
$pdo->exec("INSERT INTO n_user_mail_sent (id, id_user, to_list, subject, body_text, id_reply_to, message_id, mode, status, error, id_customer, created_at) VALUES
    (501, 2, 'roza@example.test', 'Re: Cennik 2027', 'x', 106, '<e501@ex>', 'live', 'sent', NULL, 5, '2026-11-24 10:00:00'),
    (502, 2, 'roza@example.test', 'Re: Paleta', 'x', 112, '<e502@ex>', 'live', 'failed', 'SMTP 550', 5, '2026-11-23 12:00:00')");
// Décision d'hier sur 111 (pas de réponse nécessaire) : ne revient pas aujourd'hui.
$pdo->exec("INSERT INTO n_customer_mail_check (id_mail, id_mailbox, checked_on, decision, note, flags, id_user) VALUES (111, 2, '2026-11-23', 'not_needed', 'Newsletter, bez odpowiedzi', 'unanswered', 2)");

$sources = ['n_user_mail', 'n_user_mailbox', 'n_user_mail_sent', 'n_user_mail_admin', 'customer', 'supplier', 'user', 'n_position', 'n_prod_param', 'n_page_access', 'n_user_position', 'n_position_menu'];
$sum = fn() => implode('/', array_map(fn($t) => $pdo->query("SELECT CONCAT(COUNT(*), ':', COALESCE(MD5(GROUP_CONCAT(CONCAT_WS('|', " . implode(', ', array_map(fn($c) => 'IFNULL(`' . $c . '`, \'∅\')',
    array_column($pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$t}' ORDER BY ORDINAL_POSITION")->fetchAll(), 'COLUMN_NAME'))) . ") ORDER BY 1)), '')) FROM `{$t}`")->fetchColumn(), $sources));

$pdo->exec("INSERT INTO n_prod_user_role (id_user, role_code, id_group) VALUES (1, 'prod_admin', NULL)");
$pdo->exec('INSERT INTO n_user_position VALUES (2, 1), (3, 2), (4, 3)');
$pdo->exec("INSERT INTO n_page_access (id_position, page_key) VALUES (1, 'orders'), (1, 'invoices'), (2, 'logistics'), (3, 'production')");
// [392] Droit propre « customer_mail » : migration 235 (rejouée deux fois) — Administracja seulement.
foreach ([1, 2] as $k) foreach (MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/235_customer_mail_access.sql')) as $sql) $pdo->exec($sql);
$check((int) $pdo->query("SELECT COUNT(*) FROM n_page_access WHERE page_key = 'customer_mail'")->fetchColumn() === 1
    && (int) $pdo->query("SELECT id_position FROM n_page_access WHERE page_key = 'customer_mail'")->fetchColumn() === 1, 'migration 235 (twice) : « customer_mail » for Administracja only');
$make = fn() => new CustomerMailCheckService(new CustomerMailCheckRepository($pdo), new UserMailRepository($pdo), new PageAccessService(new PageAccessRepository($pdo)),
    new LightWizardRepository($pdo), new ParamRepository($pdo));
$svc = $make();
$now = '2026-11-24 15:00:00';
$today = '2026-11-24';
$tasks = new TaskWizards(new TaskWizardRepository($pdo), new class implements Psr\Container\ContainerInterface {
    public function get(string $id): mixed { throw new RuntimeException($id); }
    public function has(string $id): bool { return false; }
});

// --- Heures ouvrées
$wd = [1, 2, 3, 4, 5];
$check(CustomerMailCheckService::workHours('2026-11-20 15:00:00', '2026-11-24 15:00:00', $wd) === 48.0 && CustomerMailCheckService::workHours('2026-11-21 10:00:00', '2026-11-24 15:00:00', $wd) === 39.0
    && CustomerMailCheckService::workHours('2026-11-24 15:00:00', '2026-11-24 09:00:00', $wd) === 0.0, 'working-day hours: Fri 15:00 → Tue 15:00 = 48 h (calendar 96) ; Sat 10:00 → Tue 15:00 = 39 h');
$check(CustomerMailCheckService::deadline('2026-11-20 15:00:00', 24, $wd) === '2026-11-23 15:00:00' && CustomerMailCheckService::deadline('2026-11-21 10:00:00', 24, $wd) === '2026-11-24 00:00:00'
    && CustomerMailCheckService::deadline('2026-11-24 09:00:00', 24, $wd) === '2026-11-25 09:00:00', 'deadline: Friday 15:00 + 24 h → Monday 15:00 ; Saturday → Tuesday 0:00');
$check($svc->param('hours') === 24 && $svc->param('lookback') === 30, 'params from n_prod_param (24 h, 30 days)');
$pdo->exec("UPDATE n_prod_param SET value = '0' WHERE code = 'customer_mail.reply_hours'");
$check($make()->param('hours') === 24, 'param out of ParamRules bounds → default');
$pdo->exec("UPDATE n_prod_param SET value = '48' WHERE code = 'customer_mail.reply_hours'");
$x = $make()->data(2, $now);
$sig48 = array_column(array_filter($x['items'], fn($i) => $i['signals']), 'id');
sort($sig48);
$check($sig48 === [101, 106, 111], 'reply_hours = 48 → only 101 (48 h), 111 and the 73 h reply 106 still flagged — ' . implode(',', $sig48));
$pdo->exec("UPDATE n_prod_param SET value = '24' WHERE code = 'customer_mail.reply_hours'");

// --- Étape 1 : boîte d'Ewa (Administracja)
$erp0 = $sum();
$d = $svc->data(2, $now);
$boot = $d;
$by = array_column($d['items'], null, 'id');
$codes = fn(int $id) => array_column($by[$id]['signals'], 'code');
$ids = array_keys($by);
sort($ids);
$check($d['mailbox']['owner'] === 2 && $d['mailbox']['own'] === true && $d['mailbox']['email'] === 'biuro@example.test' && $d['mailbox']['sync']['code'] === 'ok' && $d['mailbox']['sync']['reliable'],
    'own mailbox, sync OK (5 min ago)');
$check($ids === [101, 102, 103, 104, 105, 106, 107, 108, 111, 112], 'customer INBOX mails of 30 days only (no supplier, no SENT, no older, no other box) — ' . implode(',', $ids));
$check($codes(101) === ['unanswered'] && str_contains($by[101]['signals'][0]['detail'], '48 h (2 dni rob.)') && $by[101]['deadline'] === '2026-11-23 15:00:00', 'Friday 15:00 unanswered → 48 h, deadline Monday 15:00');
$check($codes(102) === ['unanswered'] && str_contains($by[102]['signals'][0]['detail'], 'nieprzeczytany') && $by[102]['from'] === 'hotel@example.test', 'unread and unanswered (seen = 0) ; sender e-mail when no name');
$check($codes(103) === [] && !$by[103]['replied'], 'received 6 h ago → in time, no signal');
$check($codes(104) === ['unanswered'] && $by[104]['age_hours'] === 39.0, 'Saturday mail: weekend not counted (39 h)');
$check($codes(105) === [] && $by[105]['reply']['source'] === 'flag', 'answered flag → replied');
$check($codes(106) === ['late_reply'] && $by[106]['reply']['source'] === 'erp' && $by[106]['reply']['id'] === 501 && $by[106]['reply_hours'] === 73.0, 'reply from Poczta ERP today after 73 h → late reply');
$check($codes(107) === [] && $by[107]['reply']['source'] === 'sent' && $by[107]['reply']['id'] === 201, 'reply in the Sent folder (in_reply_to) yesterday within 8 h → no signal');
$check($codes(108) === [] && $by[108]['reply_hours'] === 3.0, 'reply in Sent folder today after 3 h');
$check($codes(112) === ['unanswered'] && $by[112]['reply'] === null, 'failed ERP send does not count as a reply');
$check($codes(111) === ['unanswered'] && $by[111]['review'] === false && $by[111]['decision']['code'] === 'not_needed', 'decided yesterday → not back in step 2');
$check(array_column($d['review'], 'id') === [106, 101, 104, 112, 102], 'review: waiting first, oldest first — ' . implode(',', array_column($d['review'], 'id')));
$t = $d['totals'];
$check($t['mails'] === 10 && $t['customers'] === 5 && $t['overdue'] === 5 && $t['late_reply'] === 1 && $t['review'] === 5 && $t['waiting'] === 5 && $t['earlier'] === 1 && $t['unread'] === 1
    && $t['avg_reply_hours'] === 38.0 && $t['replies_today'] === 2 && $d['can_finish'] === false, 'totals ; average response today (73 h + 3 h) / 2 = 38 h');
$cust = array_column($d['customers'], null, 'id');
$check($cust[1]['mails'] === 2 && $cust[1]['overdue'] === 2 && $cust[1]['oldest_hours'] === 48.0 && $cust[5]['mails'] === 2 && $cust[5]['replied'] === 1 && $d['customers'][0]['waiting'] >= $d['customers'][4]['waiting'],
    'per customer: Bistro 2 mails (older one out of the window), 2 overdue, oldest 48 h ; Róża 1 of 2 replied');
$check($by[101]['link']['href'] === '/mail?id=101' && $d['hours_rule'] === '24 h liczone w dniach roboczych (pon., wt., śr., czw., pt. — weekend się nie liczy, noc tak)', 'link to the existing reading screen ; hours rule stated');
$json = json_encode($d, JSON_UNESCAPED_UNICODE);
$check(!str_contains($json, 'SECRET') && !str_contains($json, 'biuro-login') && !str_contains($json, '127.0.0.1') && !str_contains($json, 'treść poufna'), 'no password, login, server nor mail body in the page data');
$check($erp0 === $sum(), 'reading writes nothing in the source tables');

// --- Synchronisation : jamais « 0 e-mail » comme résultat quand elle est en panne
$pdo->exec("UPDATE n_user_mailbox SET last_sync_at = '2026-11-24 13:00:00' WHERE id_user = 2");
$s = $make()->data(2, $now)['mailbox']['sync'];
$check($s['code'] === 'stale' && !$s['reliable'] && $s['can_finish'] && str_contains($s['detail'], '2 h temu'), 'no sync for 2 h → stale (not reliable)');
$pdo->exec("UPDATE n_user_mailbox SET last_sync_at = '2026-11-24 13:00:00', last_error = 'AUTHENTICATIONFAILED' WHERE id_user = 2");
$x = $make()->data(2, $now);
$check($x['mailbox']['sync']['code'] === 'error' && str_contains($x['mailbox']['sync']['detail'], 'AUTHENTICATIONFAILED') && count($x['items']) === 10 && str_starts_with($x['summary'], 'błąd synchronizacji'),
    'sync error shown, known mails still listed, summary starts with the error');
$pdo->exec("UPDATE n_user_mailbox SET last_sync_at = NULL, last_error = NULL WHERE id_user = 2");
$x = $make()->data(2, $now);
$check($x['mailbox']['sync']['code'] === 'never' && $x['can_finish'] === false, 'never synced → cannot finish');
$pdo->exec("UPDATE n_user_mailbox SET last_sync_at = '2026-11-24 14:55:00' WHERE id_user = 2");
$x = $make()->data(4, $now);
$check($x['mailbox']['sync']['code'] === 'none' && $x['items'] === [] && $x['available'] === false && $x['can_finish'] === false && $x['summary'] === 'brak skrzynki — e-maile od klientów nie sprawdzone', 'no mailbox → nothing, cannot finish, no « 0 e-mail » in the summary');
$check(str_starts_with($err(fn() => $make()->finish(4, $now)), 'custom:422:Nie można zakończyć : brak skrzynki'), 'finish refused without a mailbox');

// --- Visibilité : la boîte de Poczta, jamais celle d'un autre
$a = $make()->data(3, $now);
$check($a['mailbox']['email'] === 'magazyn@example.test' && array_column($a['items'], 'id') === [301], 'Adam sees only his own mailbox (301), not Ewa\'s');
$check(str_starts_with($err(fn() => $make()->saveDecision(['id_mail' => 101, 'decision' => 'not_needed', 'note' => 'Nie moje'], 3, $now)), 'custom:422:Tej wiadomości nie ma na liście'), 'decision on a mail of another mailbox refused');
$x = $make()->data(1, $now);
$check($x['mailbox']['owner'] === 1 && $x['mailbox']['sync']['code'] === 'none' && $x['items'] === [], 'administrator in full view: his own (missing) mailbox, not the others');
$_SESSION[ViewSession::KEY] = 1;
$x = $make()->data(1, $now);
$check($x['mailbox']['owner'] === 2 && $x['mailbox']['own'] === false && array_column($x['items'], 'id') === array_column($d['items'], 'id'), 'administrator in the Administracja session → mailbox of the person of the position (as Poczta)');
$_SESSION[ViewSession::KEY] = 2;
$check(array_column($make()->data(1, $now)['items'], 'id') === [301], 'administrator in the Logistyka session → Logistyka mailbox');
unset($_SESSION[ViewSession::KEY]);
$_SESSION[ViewSession::KEY] = 2;
$check($make()->data(3, $now)['mailbox']['owner'] === 3, 'non-administrator: session ignored, own mailbox');
unset($_SESSION[ViewSession::KEY]);
$pdo->exec("INSERT INTO n_user_mail_admin (id_user, managed, stopped, stopped_at) VALUES (3, 0, 1, '2026-11-20 08:00:00')");
$x = $make()->data(3, $now);
$check($x['mailbox']['sync']['code'] === 'stopped' && $x['items'] === [] && $x['can_finish'] === false, 'mail stopped by the administrator → nothing shown (as Poczta, 423)');
$pdo->exec('DELETE FROM n_user_mail_admin WHERE id_user = 3');

// --- Krok 2 : decyzje
$erp1 = $sum();
$dec = fn(int $id, string $decision, string $note = '', array $extra = [], string $at = '2026-11-24 15:00:00') => $svc->saveDecision(['id_mail' => $id, 'decision' => $decision, 'note' => $note] + $extra, 2, $at);
$check(str_starts_with($err(fn() => $dec(101, 'sent')), 'custom:422:Wybierz decyzję'), 'unknown decision refused');
$check(str_starts_with($err(fn() => $dec(101, 'answered')), 'custom:422:Odpowiedziane : wiadomość nie jest jeszcze oznaczona jako odpowiedziana'), 'answered refused while nothing is answered');
$check(str_starts_with($err(fn() => $dec(104, 'not_needed', ' ')), 'custom:422:Bez odpowiedzi — nie wymaga : wpisz'), 'not_needed needs a note');
$check(str_starts_with($err(fn() => $dec(102, 'forwarded', 'x')), 'custom:422:Przekazane : wpisz'), 'forwarded needs a note');
$check(str_starts_with($err(fn() => $dec(102, 'forwarded', 'Do logistyki', ['id_responsible' => 99])), 'custom:422:Nieznana osoba'), 'unknown person refused');
$check(str_starts_with($err(fn() => $dec(103, 'not_needed', 'W terminie')), 'custom:422:Tej wiadomości nie ma na liście') && str_starts_with($err(fn() => $dec(109, 'not_needed', 'Dostawca')), 'custom:422:Tej wiadomości'),
    'mail in time / supplier mail refused');
$check(str_starts_with($err(fn() => $svc->finish(2, $now)), 'custom:422:Najpierw zapisz decyzję dla 5 e-maili'), 'finish blocked while mails wait');
$check($erp1 === $sum() && (int) $pdo->query('SELECT COUNT(*) FROM n_customer_mail_check')->fetchColumn() === 1, 'refused decisions write nothing');
$d = $dec(106, 'answered');
$row = $pdo->query('SELECT * FROM n_customer_mail_check WHERE id_mail = 106')->fetch();
$check($row['decision'] === 'answered' && $row['reply_source'] === 'erp' && (int) $row['id_reply'] === 501 && $row['flags'] === 'late_reply' && (int) $row['id_mailbox'] === 2 && $row['checked_on'] === $today,
    'answered accepted for the late reply: reply source and id, signals, mailbox, day');
$d = $dec(102, 'forwarded', 'Przekazane do logistyki — dostawa', ['id_responsible' => 3, 'id_position' => 2]);
$f = array_column($d['review'], null, 'id')[102]['decision'];
$check($f['responsible'] === 'Adam K.' && $f['position'] === 'Logistyka' && $f['by'] === 'Ewa L.' && $d['totals']['waiting'] === 3, 'forwarded with person and position, author shown');
$st = $tasks->state(CustomerMailCheckService::CODE, '2026-11-24 00:00:00', '2026-11-25 00:00:00', $today);
$check($st['done'] === false && $st['step'] === 2 && $st['step_label'] === '2 e-maile wyjaśnione', 'task state: in progress (decisions saved today)');
// La réponse se fait dans Poczta (ici simulée comme UserMailService::send l'écrit : dziennik + answered) : l'assistant ne fait que la constater.
$pdo->exec("INSERT INTO n_user_mail_sent (id, id_user, to_list, subject, body_text, id_reply_to, message_id, mode, status, id_customer, created_at) VALUES (503, 2, 'bistro@example.test', 'Re: Zamówienie', 'x', 101, '<e503@ex>', 'live', 'sent', 1, '2026-11-24 15:30:00')");
$pdo->exec('UPDATE n_user_mail SET answered = 1 WHERE id = 101');
$later = '2026-11-24 15:40:00';
$x = $svc->data(2, $later);
$m101 = array_column($x['review'], null, 'id')[101];
$check(array_column($m101['signals'], 'code') === ['late_reply'] && $m101['waiting'] === true && $x['totals']['replies_today'] === 3, 'after replying in Poczta: still listed as late reply, waiting for « Odpowiedziane »');
$dec(101, 'answered', '', [], $later);
$dec(104, 'not_needed', 'Reklamacja załatwiona telefonicznie przez handlowca', [], $later);
$d = $dec(112, 'forwarded', 'Przekazane kierowcy — paleta do odbioru', ['id_position' => 2], $later);
$check($d['can_finish'] === true && $d['totals']['waiting'] === 0 && [$d['totals']['answered'], $d['totals']['not_needed'], $d['totals']['forwarded']] === [2, 1, 2], 'all decided → step 3 open');
$after = $d;
// Réponse retirée (drapeau enlevé, entrée supprimée) : « Odpowiedziane » devient caduque, l'e-mail attend de nouveau.
$pdo->exec('UPDATE n_user_mail SET answered = 0 WHERE id = 101'); $pdo->exec('DELETE FROM n_user_mail_sent WHERE id = 503');
$s101 = array_column($svc->data(2, $later)['review'], null, 'id')[101];
$check($s101['waiting'] === true && $s101['decision']['stale'] === true && str_starts_with($err(fn() => $svc->finish(2, $later)), 'custom:422:Najpierw zapisz decyzję dla 1 e-maila'), 'reply gone → decision stale, finish blocked again');
$pdo->exec("INSERT INTO n_user_mail_sent (id, id_user, to_list, subject, body_text, id_reply_to, message_id, mode, status, id_customer, created_at) VALUES (503, 2, 'bistro@example.test', 'Re: Zamówienie', 'x', 101, '<e503@ex>', 'live', 'sent', 1, '2026-11-24 15:30:00')");
$pdo->exec('UPDATE n_user_mail SET answered = 1 WHERE id = 101');
$erp2 = $sum();
$r = $svc->finish(2, $later);
$wdn = $pdo->query("SELECT * FROM n_wizard_done WHERE code = 'customer_mail_checked'")->fetchAll();
$check($r['summary'] === 'synchronizacja OK · 10 e-maili od 5 klientów (30 dni) · 4 bez odpowiedzi po 24 h · 2 odpowiedzi po terminie · śr. odpowiedź dziś 41,5 h (3) · 5 do wyjaśnienia : 2 odpowiedziane · 1 nie wymaga · 2 przekazane'
    && count($wdn) === 1 && $wdn[0]['day'] === $today && $r['data']['done'] !== null, 'finish: summary, n_wizard_done — ' . $r['summary']);
$st = $tasks->state(CustomerMailCheckService::CODE, '2026-11-24 00:00:00', '2026-11-25 00:00:00', $today);
$check($st['done'] === true && $st['summary'] === $r['summary'], 'task state: done today');
$check($tasks->state(CustomerMailCheckService::CODE, '2026-11-25 00:00:00', '2026-11-26 00:00:00', '2026-11-25')['done'] === false, 'next day: task open again');
$check($erp2 === $sum(), 'decisions and finish write nothing in the source tables');
$n = $make()->data(2, '2026-11-25 10:00:00');
$check(array_column($n['review'], 'id') === [103] && $n['review'][0]['signals'][0]['code'] === 'unanswered', 'next day: decided mails do not come back ; 103 (Tue 9:00) now overdue');

// --- Contrôleurs réels construits par PHP-DI (PDO de test) ; SMTP, file e-mail et SMS espionnés.
$spy = new class { public array $calls = []; };
$fakeSms = new class($spy) implements SmsTransport {
    public function __construct(private object $spy) {}
    public function name(): string { return 'fake'; }
    public function delivers(): bool { return false; }
    public function send(string $to, string $text, array $context = []): array { $this->spy->calls[] = 'sms:' . $to; return ['status' => 'failed', 'provider_id' => null, 'error' => 'test']; }
};
$testPdo = fn() => new PDO($dsn . ';dbname=erp_customer_mail_check_test', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
// Assistant : SMTP et file e-mail ne peuvent même pas être construits — le contrôleur n'en dépend pas.
$b = new DI\ContainerBuilder();
$b->addDefinitions([
    PDO::class => $testPdo,
    SmsTransport::class => $fakeSms,
    OutgoingMessageService::class => function () use ($spy) { $spy->calls[] = 'mail-queue built'; throw new RuntimeException('no mail in this test'); },
    SmtpTransport::class => function () use ($spy) { $spy->calls[] = 'smtp built'; throw new RuntimeException('no smtp in this test'); },
]);
$ctl = $b->build()->get(CustomerMailCheckController::class);
// Poczta (écran de référence de la visibilité) : son graphe tire le SMTP — remplacé par un espion qui note tout envoi.
$smtpSpy = new class($spy) implements SmtpTransport {
    public function __construct(private object $spy) {}
    public function send(array $account, string $to, string $subject, string $body, string $messageId, array $attachments = [], ?string $footer = null): string { $this->spy->calls[] = 'smtp:' . $to; return 'x'; }
};
$b2 = new DI\ContainerBuilder();
$b2->addDefinitions([PDO::class => $testPdo, SmsTransport::class => $fakeSms, SmtpTransport::class => $smtpSpy]);
$mailCtl = $b2->build()->get(MailController::class);
$call = function (?int $user, string $method, ?object $c = null) use ($ctl) {
    if ($user === null) unset($_SESSION['USER']); else $_SESSION['USER'] = ['id' => $user, 'login' => 'u' . $user];
    $res = ($c ?? $ctl)->$method();
    return [$res->getStatusCode(), json_decode((string) $res->getContent(), true)];
};
// Poczta : liste INBOX réellement servie par MailController::messages() (même requête que l'écran).
$poczta = function (?int $user) use ($call, $mailCtl) { $_GET = ['folder' => 'INBOX']; [$c, $b] = $call($user, 'messages', $mailCtl); $_GET = []; return [$c, $c === 200 ? array_column($b['rows'], 'id') : []]; };
$erp3 = $sum();
[$c2, $b2] = $call(2, 'data');
[$p2, $ids2] = $poczta(2);
$wiz2 = array_column($b2['data']['items'], 'id');
$check($c2 === 200 && $b2['data']['today'] === date('Y-m-d') && $b2['data']['mailbox']['owner'] === 2 && $b2['data']['mail_link'] === '/mail', 'controller data() (Administracja, logged in) : 200, real day');
$check($p2 === 200 && $wiz2 !== [] && array_diff($wiz2, $ids2) === [] && !in_array(301, $ids2, true) && !in_array(301, $wiz2, true),
    'Ewa: every mail of the assistant is in her Poczta list (MailController::messages really called), none of Adam\'s');
[$c3, $b3] = $call(3, 'data');
[$p3, $ids3] = $poczta(3);
$check($c3 === 403 && $ids3 === [301], '[392] Adam (Logistyka, no « customer_mail »): assistant refused, his own Poczta unchanged');
[$c4, $b4] = $call(4, 'data');
[$p4] = $poczta(4);
$check($c4 === 403 && $p4 === 409, '[392] Piekarz: assistant refused (no right), Poczta shows nothing (409)');
$_SESSION[ViewSession::KEY] = 1;
[$c1, $b1] = $call(1, 'data');
[$p1, $ids1] = $poczta(1);
$check($c1 === 200 && $b1['data']['mailbox']['owner'] === 2 && array_diff(array_column($b1['data']['items'], 'id'), $ids1) === [] && $ids1 === $ids2,
    'administrator in the Administracja session: same mailbox as Poczta shows him (Ewa\'s)');
unset($_SESSION[ViewSession::KEY]);
[$c1b, $b1b] = $call(1, 'data');
[$p1b] = $poczta(1);
$check($c1b === 200 && $b1b['data']['items'] === [] && $p1b === 409, 'administrator in full view: no other person\'s mailbox (Poczta 409 too)');
[$cA, $bA] = $call(null, 'data');
[$cAD] = $call(null, 'decision');
[$cAF] = $call(null, 'finish');
$check($cA === 403 && $bA['message'] === 'Brak dostępu do poczty (asystent « Poczta od klientów »).' && $cAD === 403 && $cAF === 403, 'not logged in → 403 for data(), decision() and finish()');
$pa = new PageAccessService(new PageAccessRepository($pdo));
$check(array_map(fn($u) => $pa->canSee($u, CustomerMailCheckService::PATH), [1, 2, 3, 4]) === [true, true, false, false]
    && array_map(fn($u) => $pa->canSee($u, '/mail'), [1, 2, 3, 4]) === [true, true, true, true],
    '[392] page guard : administrator and Administracja only ; Poczta (/mail) still open to everyone');
[$cDe, $bDe] = $call(2, 'decision');
$check($cDe === 422 && str_starts_with((string) $bDe['message'], 'Wybierz decyzję'), 'controller decision() really called : empty body → 422');
[$cF2, $bF2] = $call(2, 'finish');
$check(in_array($cF2, [200, 422], true) && ($cF2 === 200 || str_starts_with((string) $bF2['message'], 'Najpierw zapisz decyzję')), 'controller finish() really called : done or blocked by waiting mails');
$check($spy->calls === [] && $connections() === 0, 'no send, no connection: SMS gateway never called, mail queue and SMTP never built, fake IMAP/SMTP server never contacted');
// Contrôle du détecteur : une vraie connexion au faux serveur est bien vue.
$probe = stream_socket_client('tcp://127.0.0.1:' . $port, $eno, $estr, 2);
usleep(50000);
$check($connections() === 1, 'detector works: a real connection to the fake IMAP/SMTP server is counted');
fclose($probe);
$check($erp3 === $sum(), 'controller calls write nothing in the source tables (no mail marked read or answered, no mailbox changed)');
unset($_SESSION['USER']);

// --- Aucun envoi, aucune connexion, aucun mot de passe : code sans les commentaires (PHP : tokenizer ; JS : lignes // et blocs /* */).
$strip = fn(string $f) => str_ends_with($f, '.php')
    ? implode('', array_map(fn($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t, token_get_all((string) file_get_contents(__DIR__ . '/../' . $f))))
    : preg_replace(['~/\*.*?\*/~s', '~^\s*//.*$~m'], '', (string) file_get_contents(__DIR__ . '/../' . $f));
$src = implode("\n", array_map($strip, ['App/Services/Organization/Wizards/CustomerMailCheckService.php', 'App/Repositories/Mail/CustomerMailCheckRepository.php',
    'App/Controllers/Web/Mail/CustomerMailCheckController.php', 'public/assets/js/mail/customer-mail-check.js']));
$check(!preg_match('/->send\(|->sync\(|->open\(|ImapClient|PHPMailer|UserMailService|MailboxSecretCipher|decrypt|_secret|imap_|smtp_|SmtpTransport|OutgoingMessageService|SmsTransport|stream_socket|fsockopen|curl_|https?:\/\/|\/ajax\/mail\/(send|sync|messages)|SELECT \*|boxes->mailbox\(/i', $src),
    'no send / sync / open call, no IMAP / SMTP / cipher / password column, no socket, no hard-coded URL, no SELECT *');
$check(!preg_match('/INSERT\s+INTO\s+(n_user_mail|customer|n_wizard)|UPDATE\s+(n_user_mail|customer)|DELETE\s+FROM/i', $src), 'no write to mails, mailboxes or customers');

// --- Enregistrement : catalogue, droits, routes, équipe, menu.
$check(TaskWizards::info('customer_mail_checked')['link'] === '/mail/customer-check' && TaskWizards::byLink('/mail/customer-check') === 'customer_mail_checked'
    && isset(TaskWizards::AUTO['customer_mail_checked']) && PositionTaskService::LINK_AUTO['/mail/customer-check'] === 'customer_mail_checked', 'catalog, auto description and position-task link');
$check(TaskWizards::CATALOG['customer_mail_checked'][0] === 'Poczta od klientów (bez odpowiedzi)' && TaskWizards::CATALOG['customer_mail_checked'][0] !== 'Poczta od klientów', 'catalog label distinct from the proposed name in the matrix (224)');
$check(PageAccess::keyFor('/mail/customer-check') === 'customer_mail' && PageAccess::keyFor('/mail') === null && PageAccess::keyFor('/ajax/mail/customer-check/finish') === 'customer_mail'
    && PageAccess::keyFor('/mail/admin') === 'mail_admin', '[392] own right « customer_mail » (page and ajax) ; Poczta (/mail) and /mail/admin unchanged');
$check(!isset(PageAccess::PAGES['mail']) && isset(PageAccess::PAGES['customer_mail']) && !PageAccess::adminOnly('customer_mail'), '[392] Poczta stays outside the catalog ; « customer_mail » is a grantable right');
$check(str_contains((string) file_get_contents(__DIR__ . '/../App/Services/Organization/TeamTasksService.php'), "'customer_mail_checked' =>")
    && str_contains((string) file_get_contents(__DIR__ . '/../App/Views/page_components/sidebar.twig'), '/mail/customer-check'), 'team tasks hint and menu entry');
$dispatcher = FastRoute\simpleDispatcher(require __DIR__ . '/../App/Core/Routes/Mail/CustomerMailCheckRoutes.php');
$found = fn(string $m, string $u) => ($x = $dispatcher->dispatch($m, $u))[0] === FastRoute\Dispatcher::FOUND ? $x[1]['method'] : null;
$check($found('GET', '/mail/customer-check') === 'index' && $found('GET', '/ajax/mail/customer-check') === 'data'
    && $found('POST', '/ajax/mail/customer-check/decision') === 'decision' && $found('POST', '/ajax/mail/customer-check/finish') === 'finish', 'routes');
$allR = FastRoute\simpleDispatcher(require __DIR__ . '/../App/Core/Routes/routes.php');
$check($allR->dispatch('GET', '/mail/customer-check')[0] === FastRoute\Dispatcher::FOUND && $allR->dispatch('GET', '/mail/customer-check')[1]['controller'] === CustomerMailCheckController::class
    && $allR->dispatch('GET', '/mail')[0] === FastRoute\Dispatcher::FOUND && $allR->dispatch('GET', '/ajax/mail/messages/5')[0] === FastRoute\Dispatcher::FOUND,
    'routes file loaded by the */*Routes.php glob ; existing Poczta routes unchanged');

if (getenv('CM_JSON_OUT')) file_put_contents((string) getenv('CM_JSON_OUT'), json_encode(['before' => $boot, 'after' => $after], JSON_UNESCAPED_UNICODE));
fclose($srv);
$pdo->exec('DROP DATABASE erp_customer_mail_check_test');
echo $fail ? "\n{$fail} FAIL\n" : "\nall ok\n";
exit($fail ? 1 : 0);
