<?php $this->view('page_components/header'); ?>
<div class="page-heading"><div class="page-title"><h3>Nowe urządzenie API</h3></div>
<section class="section"><div class="card"><div class="card-body">
    <?php if (!empty($errors)): ?><div class="alert alert-danger"><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div><?php endif; ?>
    <?php if (!empty($successes)): ?><div class="alert alert-success"><?= implode('<br>', array_map('htmlspecialchars', $successes)) ?></div><?php endif; ?>
    <?php if (!empty($device) && !empty($api_key)): ?>
        <div class="alert alert-warning"><strong>Zapisz te dane teraz.</strong> Klucz API nie będzie ponownie pokazany.</div>
        <dl class="row"><dt class="col-sm-3">Device ID</dt><dd class="col-sm-9"><code><?= htmlspecialchars($device['device_id']) ?></code></dd>
        <dt class="col-sm-3">API key</dt><dd class="col-sm-9"><code style="word-break:break-all"><?= htmlspecialchars($api_key) ?></code></dd></dl>
        <a class="btn btn-primary" href="<?= ROOT ?>/settings/api-devices">Wróć do urządzeń</a>
    <?php else: ?>
        <form method="post" class="row g-3"><div class="col-md-6"><label class="form-label">Nazwa urządzenia</label><input required maxlength="120" class="form-control" name="name" placeholder="np. Produkcja WPF – stanowisko 1"></div>
        <div class="col-md-6"><label class="form-label">Opis</label><input maxlength="500" class="form-control" name="description" placeholder="opcjonalnie"></div>
        <div class="col-12"><button class="btn btn-primary" type="submit">Utwórz i wygeneruj klucz</button><a class="btn btn-secondary" href="<?= ROOT ?>/settings/api-devices">Anuluj</a></div></form>
    <?php endif; ?>
</div></div></section></div>
<?php $this->view('page_components/footer'); ?>
