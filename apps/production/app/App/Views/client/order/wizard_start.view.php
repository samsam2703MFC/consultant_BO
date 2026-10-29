<?php
/** Kreator « Nowe zamówienie », krok 1 (audyt 2, P4) : klient, typ zamówienia, data wysyłki i komentarz → produkty klienta. */
$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$selected = (int) ($_POST['id_customer'] ?? ($_GET['client'] ?? 0));
$type = (string) ($_POST['type'] ?? 'STANDARD');
$wizardStep = 1;
?>
<?php $this->view("page_components/header"); ?>
<div class="page-heading">
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h3>Nowe zamówienie</h3>
                <p class="text-subtitle text-muted">Klient i typ → produkty z cennika klienta → proforma od razu → wysyłka. Na końcu otwiera się zamówienie.</p>
            </div>
            <div class="col-12 col-md-6 order-md-2 order-first">
                <nav aria-label="breadcrumb" class="breadcrumb-header float-start float-lg-end">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="<?= ROOT ?>/client-orders">Zamówienia</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Nowe zamówienie</li>
                    </ol>
                </nav>
            </div>
        </div>
    </div>
    <section class="section">
        <?php require __DIR__ . '/_wizard_steps.view.php'; ?>
        <div class="card">
            <div class="card-body">
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger"><?= implode('<br>', array_map($e, (array) $errors)) ?></div>
                <?php endif; ?>
                <form method="POST" id="orderStart" autocomplete="off">
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-bold" for="id_customer">Klient *</label>
                            <select class="form-select choices" name="id_customer" id="id_customer" required>
                                <option value="">— wybierz klienta —</option>
                                <?php foreach ($customers as $c): ?>
                                    <option value="<?= $c['id'] ?>" <?= $selected === $c['id'] ? 'selected' : '' ?>><?= $e($c['company']) ?><?= $c['franchise'] ? ' · franczyza' : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label fw-bold" for="date">Data wysyłki *</label>
                            <input type="date" class="form-control" name="date" id="date" value="<?= $e($_POST['date'] ?? $default_date) ?>" required>
                        </div>
                        <div class="col-12">
                            <span class="form-label fw-bold d-block mb-2">Typ zamówienia</span>
                            <div class="at-choices" role="radiogroup" aria-label="Typ zamówienia">
                                <label class="at-choice"><input type="radio" name="type" value="STANDARD" <?= $type === 'STANDARD' ? 'checked' : '' ?>>
                                    <strong>Standardowe</strong><small>Produkty po cenach z cennika klienta</small></label>
                                <label class="at-choice" data-supplement><input type="radio" name="type" value="SUPPLEMENT" <?= $type === 'SUPPLEMENT' ? 'checked' : '' ?>>
                                    <strong>Domówienie</strong><small>Tylko sklep franczyzy : dopisane do jego zamówienia w systemie franczyzy</small></label>
                                <label class="at-choice"><input type="radio" name="type" value="FREEBIE" <?= $type === 'FREEBIE' ? 'checked' : '' ?>>
                                    <strong>Gratis</strong><small>Proforma na 0 ; pomijane w dokumencie różnic przy wysyłce</small></label>
                            </div>
                        </div>
                        <div class="col-12 col-md-8">
                            <label class="form-label fw-bold" for="note">Komentarz do zamówienia</label>
                            <textarea class="form-control" name="note" id="note" rows="2" maxlength="1000" placeholder="np. dostawa przed 7:00"><?= $e($_POST['note'] ?? '') ?></textarea>
                        </div>
                    </div>
                    <div class="at-wizard-nav">
                        <a href="<?= ROOT ?>/client-orders" class="btn btn-outline-dark">Anuluj</a>
                        <span class="at-wizard-hint">Krok 1 z 4</span>
                        <button class="btn btn-primary at-next">Dalej : produkty <i class="bi bi-arrow-right"></i></button>
                    </div>
                </form>
            </div>
        </div>
    </section>
</div>
<?php $this->view("page_components/footer"); ?>
<script>
    // Domówienie tylko dla sklepu franczyzy : inaczej wybór wraca na « Standardowe ».
    (function () {
        // Lista choices.js przebudowuje opcje (bez atrybutów data-) : sklepy franczyzy z tej listy.
        const franchise = <?= json_encode(array_values(array_map(fn($c) => $c['id'], array_filter($customers, fn($c) => $c['franchise'])))) ?>;
        const client = document.getElementById('id_customer');
        const box = document.querySelector('[data-supplement]');
        const radio = box.querySelector('input');
        const sync = () => {
            const isFranchise = franchise.includes(parseInt(client.value, 10));
            radio.disabled = !isFranchise;
            box.classList.toggle('is-disabled', !isFranchise);
            if (!isFranchise && radio.checked) document.querySelector('input[name=type][value=STANDARD]').checked = true;
        };
        // Karta zaznaczona (is-checked) jak w innych kreatorach.
        const mark = () => document.querySelectorAll('.at-choice').forEach((c) => c.classList.toggle('is-checked', c.querySelector('input').checked));
        document.querySelectorAll('input[name=type]').forEach((r) => r.addEventListener('change', mark));
        client.addEventListener('change', () => { sync(); mark(); });
        sync();
        mark();
    })();
</script>
