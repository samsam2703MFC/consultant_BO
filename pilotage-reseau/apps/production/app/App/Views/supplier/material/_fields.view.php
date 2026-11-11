<?php
// Pola dostawcy (nowy i edycja) : $row = dostawca albo [] ; wartości z POST po błędzie.
use App\Services\Supplier\MaterialSupplierService;

$row = $row ?? [];
$val = fn(string $k) => htmlspecialchars((string) (isset($_POST[$k]) ? $_POST[$k] : ($row[$k] ?? '')));
$days = isset($_POST['delivery_days']) ? array_map('intval', (array) $_POST['delivery_days']) : MaterialSupplierService::weekdays(isset($row['delivery_weekdays']) ? (int) $row['delivery_weekdays'] : null);
$lang = (string) ($_POST['order_lang'] ?? ($row['order_lang'] ?? ''));
$active = isset($_POST['name']) ? !empty($_POST['is_active']) : (int) ($row['is_active'] ?? 1) === 1;
?>
<div class="row g-3">
    <div class="col-12 col-md-6">
        <label class="form-label" for="name">Nazwa *</label>
        <input value="<?= $val('name') ?>" type="text" class="form-control" id="name" name="name" placeholder="Nazwa dostawcy" maxlength="255" required>
    </div>
    <div class="col-12 col-md-6">
        <label class="form-label" for="nip">NIP / VAT UE</label>
        <input value="<?= $val('nip') ?>" type="text" class="form-control" id="nip" name="nip" placeholder="np. 5261040828" maxlength="20" autocomplete="off">
        <div class="form-text">Faktury KSeF z tym NIP-em od razu trafiają do tego dostawcy.</div>
    </div>
    <div class="col-12 col-md-6">
        <label class="form-label" for="email">E-mail (zamówienia)</label>
        <input value="<?= $val('email') ?>" type="email" class="form-control" id="email" name="email" placeholder="zamowienia@dostawca.pl">
    </div>
    <div class="col-12 col-md-6">
        <label class="form-label" for="phone">Numer telefonu</label>
        <input value="<?= $val('phone') ?>" type="text" class="form-control" id="phone" name="phone" placeholder="+48 …">
    </div>
    <div class="col-12 col-md-8">
        <span class="form-label d-block">Zwykłe dni dostaw</span>
        <div class="d-flex flex-wrap gap-3">
            <?php foreach (MaterialSupplierService::WEEKDAYS as $d => $label): ?>
                <label class="form-check-label"><input class="form-check-input me-1" type="checkbox" name="delivery_days[]" value="<?= $d ?>" <?= in_array($d, $days, true) ? 'checked' : '' ?> data-summary-group="Dni dostaw"><?= $label ?></label>
            <?php endforeach; ?>
        </div>
        <div class="form-text">Zamówienie dostaje datę najbliższego takiego dnia (Zaopatrzenie).</div>
    </div>
    <div class="col-12 col-md-4">
        <label class="form-label" for="order_lang">Język zamówień</label>
        <select class="form-select" id="order_lang" name="order_lang">
            <?php foreach (MaterialSupplierService::ORDER_LANGS as $code => $label): ?>
                <option value="<?= $code ?>" <?= ($lang === '' ? 'pl' : $lang) === $code ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-12">
        <input type="hidden" name="is_active" value="0">
        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1" <?= $active ? 'checked' : '' ?>>
            <label class="form-check-label" for="is_active">Aktywny (widoczny w zamówieniach i ofertach)</label></div>
    </div>
</div>
