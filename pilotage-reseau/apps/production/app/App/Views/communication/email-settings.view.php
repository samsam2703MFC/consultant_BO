<?php $this->view('page_components/header'); ?>
<div class="page-heading"><h3>Konfiguracja poczty e-mail</h3><p class="text-subtitle text-muted">Konto Gmail pilotażowe: użyj hasła aplikacji, nie hasła głównego konta.</p></div>
<section class="section">
<?php if (($_GET['saved'] ?? '') === '1'): ?><div class="alert alert-success">Konfiguracja została zapisana. Hasła nie są ponownie wyświetlane.</div><?php endif; ?>
<?php if (!empty($errors)): ?><div class="alert alert-danger"><?= implode('<br>', array_map(fn($e) => htmlspecialchars($e, ENT_QUOTES, 'UTF-8'), $errors)) ?></div><?php endif; ?>
<div class="card"><div class="card-body"><form method="post" action="<?= ROOT ?>/communication/email-settings" class="row g-3">
<div class="col-md-4"><label class="form-label">Nazwa konfiguracji</label><input class="form-control" name="name" required value="<?= htmlspecialchars($account['name'] ?? 'Gmail pilot', ENT_QUOTES, 'UTF-8') ?>"></div>
<div class="col-md-4"><label class="form-label">Nazwa nadawcy</label><input class="form-control" name="from_name" required value="<?= htmlspecialchars($account['from_name'] ?? 'SL Food', ENT_QUOTES, 'UTF-8') ?>"></div>
<div class="col-md-4"><label class="form-label">Adres From</label><input class="form-control" type="email" name="from_email" required value="<?= htmlspecialchars($account['from_email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"></div>
<div class="col-12"><label class="form-label">Reply-To</label><input class="form-control" type="email" name="reply_to_email" required value="<?= htmlspecialchars($account['reply_to_email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"></div>
<div class="col-12"><h5 class="mt-2">SMTP</h5></div>
<div class="col-md-4"><label class="form-label">Host</label><input class="form-control" name="smtp_host" required value="<?= htmlspecialchars($account['smtp_host'] ?? 'smtp.gmail.com', ENT_QUOTES, 'UTF-8') ?>"></div>
<div class="col-md-2"><label class="form-label">Port</label><input class="form-control" name="smtp_port" type="number" value="<?= (int)($account['smtp_port'] ?? 587) ?>"></div>
<div class="col-md-2"><label class="form-label">Szyfrowanie</label><select class="form-select" name="smtp_encryption"><option value="tls" <?= ($account['smtp_encryption'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>STARTTLS</option><option value="ssl" <?= ($account['smtp_encryption'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL</option></select></div>
<div class="col-md-4"><label class="form-label">Login</label><input class="form-control" type="email" name="smtp_username" required value="<?= htmlspecialchars($account['smtp_username'] ?? '', ENT_QUOTES, 'UTF-8') ?>"></div>
<div class="col-12"><label class="form-label">Hasło aplikacji SMTP</label><input class="form-control" type="password" name="smtp_secret" placeholder="<?= empty($account) ? 'Wymagane' : 'Pozostaw puste, aby nie zmieniać' ?>"></div>
<div class="col-12"><h5 class="mt-2">IMAP — odbiór odpowiedzi</h5></div>
<div class="col-md-4"><label class="form-label">Host</label><input class="form-control" name="imap_host" required value="<?= htmlspecialchars($account['imap_host'] ?? 'imap.gmail.com', ENT_QUOTES, 'UTF-8') ?>"></div>
<div class="col-md-2"><label class="form-label">Port</label><input class="form-control" name="imap_port" type="number" value="<?= (int)($account['imap_port'] ?? 993) ?>"></div>
<div class="col-md-6"><label class="form-label">Login</label><input class="form-control" type="email" name="imap_username" required value="<?= htmlspecialchars($account['imap_username'] ?? '', ENT_QUOTES, 'UTF-8') ?>"></div>
<div class="col-12"><label class="form-label">Hasło aplikacji IMAP</label><input class="form-control" type="password" name="imap_secret" placeholder="<?= empty($account) ? 'Wymagane' : 'Pozostaw puste, aby nie zmieniać' ?>"></div>
<div class="col-12"><button class="btn btn-primary">Zapisz konfigurację</button><div class="form-text text-warning">Hasła SMTP i IMAP są zapisywane w bazie bez szyfrowania. Dostęp do bazy należy ograniczyć.</div></div></form></div></div>
<div class="card mt-4"><div class="card-body"><h5>Test wysyłki</h5><div class="input-group"><input id="test-recipient" type="email" class="form-control" placeholder="adres@testowy.pl"><button id="test-email" class="btn btn-outline-primary" type="button">Wyślij test</button></div><div id="test-feedback" class="form-text"></div></div></div>
</section><?php $this->view('page_components/footer'); ?>
<script>document.getElementById('test-email').addEventListener('click', async () => { const recipient=document.getElementById('test-recipient').value; const r=await fetch('<?= ROOT ?>/ajax/communication/email-settings/test',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({recipient})}); const d=await r.json(); document.getElementById('test-feedback').textContent=d.message || 'Błąd'; });</script>
