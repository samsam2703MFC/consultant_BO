<?php $this->view('page_components/header'); ?>
<?php /* [341] Un compte d'envoi par usage : factures → accounting@, logistique → logistyka@, production → produkcja@.
        Réponses reçues sur chaque adresse (IMAP). Nouveau compte : serveurs OVH MX Plan proposés. */
$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); ?>
<div class="page-heading"><h3>Konfiguracja poczty e-mail</h3>
    <p class="text-subtitle text-muted">Jeden adres na przeznaczenie : wiadomość wychodzi z adresu swojego przeznaczenia, a gdy go brak — z adresu faktur. Login = pełny adres e-mail.</p></div>
<section class="section">
<?php if (($_GET['saved'] ?? '') === '1'): ?><div class="alert alert-success">Konfiguracja została zapisana. Hasła nie są ponownie wyświetlane.</div><?php endif; ?>
<?php if (!empty($errors)): ?><div class="alert alert-danger"><?= implode('<br>', array_map($e, $errors)) ?></div><?php endif; ?>
<div class="card mb-4"><div class="card-body d-flex flex-wrap gap-3 align-items-center">
    <button type="button" class="btn btn-outline-primary" data-sync><i class="bi bi-arrow-repeat"></i> Pobierz odpowiedzi teraz</button>
    <span class="form-text mb-0" data-sync-feedback>Odpowiedzi na wszystkie adresy pobierane są automatycznie co 5 minut.</span>
</div></div>
<?php foreach ($purposes as $purpose => $meta): $a = $accounts[$purpose] ?? []; $new = empty($a); ?>
<div class="card mb-4" id="konto-<?= $e($purpose) ?>">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h4 class="card-title mb-0"><?= $e($meta['label']) ?></h4>
        <span class="badge <?= $new ? 'bg-secondary' : 'bg-success' ?>"><?= $new ? 'Nie skonfigurowano (' . $e($meta['hint']) . ')' : $e($a['from_email']) ?></span>
        <?php if (!$new): ?><span class="form-text mb-0">Ostatnie pobranie odpowiedzi : <?= !empty($a['last_synced_at']) ? $e(date('d.m.Y H:i', strtotime($a['last_synced_at']))) : '—' ?></span><?php endif; ?>
    </div>
    <div class="card-body">
    <form method="post" action="<?= ROOT ?>/communication/email-settings" class="row g-3">
        <input type="hidden" name="purpose" value="<?= $e($purpose) ?>">
        <div class="col-md-4"><label class="form-label">Nazwa konfiguracji</label><input class="form-control" name="name" required value="<?= $e($a['name'] ?? ('OVH ' . $meta['hint'])) ?>"></div>
        <div class="col-md-4"><label class="form-label">Nazwa nadawcy</label><input class="form-control" name="from_name" required value="<?= $e($a['from_name'] ?? 'SL Food') ?>"></div>
        <div class="col-md-4"><label class="form-label">Adres nadawcy (From)</label><input class="form-control" type="email" name="from_email" required value="<?= $e($a['from_email'] ?? '') ?>" placeholder="<?= $e($meta['hint']) ?>…"></div>
        <div class="col-md-4"><label class="form-label">Odpowiedzi na adres (Reply-To)</label><input class="form-control" type="email" name="reply_to_email" required value="<?= $e($a['reply_to_email'] ?? '') ?>"></div>
        <div class="col-12"><h5 class="mt-2 mb-0">Wysyłka (SMTP)</h5></div>
        <div class="col-md-4"><label class="form-label">Serwer</label><input class="form-control" name="smtp_host" required value="<?= $e($a['smtp_host'] ?? 'ssl0.ovh.net') ?>"></div>
        <div class="col-md-2"><label class="form-label">Port</label><input class="form-control" name="smtp_port" type="number" value="<?= (int) ($a['smtp_port'] ?? 465) ?>"></div>
        <div class="col-md-2"><label class="form-label">Szyfrowanie</label><select class="form-select" name="smtp_encryption">
            <?php $enc = $a['smtp_encryption'] ?? 'ssl'; ?>
            <option value="ssl" <?= $enc === 'ssl' ? 'selected' : '' ?>>SSL</option><option value="tls" <?= $enc === 'tls' ? 'selected' : '' ?>>STARTTLS</option></select></div>
        <div class="col-md-4"><label class="form-label">Login</label><input class="form-control" type="email" name="smtp_username" required value="<?= $e($a['smtp_username'] ?? '') ?>"></div>
        <div class="col-12"><label class="form-label">Hasło SMTP</label><input class="form-control" type="password" name="smtp_secret" autocomplete="new-password" placeholder="<?= $new ? 'Wymagane' : 'Pozostaw puste, aby nie zmieniać' ?>"></div>
        <div class="col-12"><h5 class="mt-2 mb-0">Odbiór odpowiedzi (IMAP)</h5></div>
        <div class="col-md-4"><label class="form-label">Serwer</label><input class="form-control" name="imap_host" required value="<?= $e($a['imap_host'] ?? 'ssl0.ovh.net') ?>"></div>
        <div class="col-md-2"><label class="form-label">Port</label><input class="form-control" name="imap_port" type="number" value="<?= (int) ($a['imap_port'] ?? 993) ?>"></div>
        <div class="col-md-6"><label class="form-label">Login</label><input class="form-control" type="email" name="imap_username" required value="<?= $e($a['imap_username'] ?? '') ?>"></div>
        <div class="col-12"><label class="form-label">Hasło IMAP</label><input class="form-control" type="password" name="imap_secret" autocomplete="new-password" placeholder="<?= $new ? 'Wymagane' : 'Pozostaw puste, aby nie zmieniać' ?>"></div>
        <div class="col-12 d-flex flex-wrap gap-2 align-items-center"><button class="btn btn-primary">Zapisz</button>
            <?php if (!$new): ?><div class="input-group" style="max-width:420px"><input type="email" class="form-control" placeholder="adres testowy" data-test-recipient><button class="btn btn-outline-primary" type="button" data-test="<?= $e($purpose) ?>">Wyślij test</button></div><span class="form-text" data-test-feedback></span><?php endif; ?>
        </div>
    </form>
    </div>
</div>
<?php endforeach; ?>
<p class="form-text text-warning">Hasła SMTP i IMAP są zapisywane w bazie bez szyfrowania. Dostęp do bazy należy ograniczyć.</p>
</section><?php $this->view('page_components/footer'); ?>
<script>
document.querySelector('[data-sync]').addEventListener('click', async (ev) => {
    const b = ev.currentTarget, out = document.querySelector('[data-sync-feedback]');
    b.disabled = true; out.textContent = '…';
    const r = await fetch('<?= ROOT ?>/ajax/communication/email-settings/sync', {method: 'POST'});
    const d = await r.json().catch(() => ({}));
    out.textContent = d.message || 'Błąd'; b.disabled = false;
});
document.querySelectorAll('[data-test]').forEach(b => b.addEventListener('click', async () => {
    const card = b.closest('form'), out = card.querySelector('[data-test-feedback]');
    out.textContent = '…';
    const r = await fetch('<?= ROOT ?>/ajax/communication/email-settings/test', {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({recipient: card.querySelector('[data-test-recipient]').value, purpose: b.dataset.test})});
    const d = await r.json().catch(() => ({}));
    out.textContent = d.message || 'Błąd';
}));
</script>
