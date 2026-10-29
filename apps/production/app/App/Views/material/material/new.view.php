<?php $this->view("page_components/header"); ?>
    <div class="page-heading">
        <div class="page-title">
            <div class="row">
                <div class="col-12 col-md-6 order-md-1 order-last">
                    <h3>Surowiec</h3>
                </div>
                <div class="col-12 col-md-6 order-md-2 order-first">
                    <nav aria-label="breadcrumb" class="breadcrumb-header float-start float-lg-end">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="<?=ROOT?>/dashboard">Dashboard</a></li>
                            <li class="breadcrumb-item active" aria-current="page">Surowiec
                            </li>
                        </ol>
                    </nav>
                </div>
            </div>
        </div>
        <section class="section">
            <div class="card">
                <div class="card-header">
                    <h4 class="card-title">Nowy surowiec</h4>
                </div>
                <div class="card-body">

                    <?php if(!empty($errors)):?>
                        <div class="alert alert-danger"><?= implode("<br>", array_map('htmlspecialchars', $errors)) ?></div>
                    <?php endif; ?>
                    <?php $steps_error = ''; foreach (['name', 'id_category', 'id_unit', 'id_current_supplier', 'package_size_in_base_unit', 'pack_price'] as $f) { if (!empty($errors[$f])) { $steps_error = $f; break; } } ?>

                    <form method="POST" data-at-steps data-at-steps-error="<?= htmlspecialchars($steps_error) ?>" class="at-form-steps">
                        <section data-step="Surowiec">
                            <h4 class="at-step-title">Surowiec i jednostka</h4>
                            <p class="at-step-lead">Nazwa z etykiety dostawcy, kategoria i jednostka, w której liczy receptura (g, szt.…).</p>
                            <div class="row g-3">
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="name">Nazwa *</label>
                                    <input value="<?= old_value('name') ?>" type="text" class="form-control" id="name" name="name" placeholder="np. Mąka pszenna T55" required>
                                </div>
                                <div class="col-12 col-md-4">
                                    <label class="form-label" for="id_category">Kategoria *</label>
                                    <select class="form-select" name="id_category" id="id_category" required>
                                        <?php foreach ($category_material ?? [] as $category): ?>
                                            <option value="<?= $category['id'] ?>" <?= old_select('id_category', $category['id']) ?>><?= htmlspecialchars((string) $category['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-6 col-md-2">
                                    <label class="form-label" for="id_unit">Jednostka *</label>
                                    <select class="form-select" name="id_unit" id="id_unit" required>
                                        <?php foreach ($units ?? [] as $unit): ?>
                                            <option value="<?= $unit['id'] ?>" <?= old_select('id_unit', $unit['id']) ?>><?= htmlspecialchars((string) $unit['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </section>

                        <section data-step="Zakup" hidden>
                            <h4 class="at-step-title">Zakup : dostawca, opakowanie, cena</h4>
                            <p class="at-step-lead">Cena za opakowanie i jego zawartość w jednostce surowca dają koszt w recepturach. Nowego dostawcę dodasz bez wychodzenia ze strony.</p>
                            <div class="row g-3">
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="id_current_supplier">Dostawca *</label>
                                    <div class="input-group">
                                        <select class="form-select" name="id_current_supplier" id="id_current_supplier" required>
                                            <option value="">Wybierz dostawcę…</option>
                                            <?php foreach ($suppliers ?? [] as $supplier): ?>
                                                <option value="<?= $supplier['id'] ?>" <?= old_select('id_current_supplier', $supplier['id']) ?>><?= htmlspecialchars((string) $supplier['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" class="btn btn-outline-primary" data-new-supplier><i class="bi bi-plus-lg"></i> Nowy</button>
                                    </div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label" for="package_size_in_base_unit">Zawartość opakowania *</label>
                                    <input value="<?= old_value('package_size_in_base_unit') ?>" type="number" min="0.0001" step="any" class="form-control" id="package_size_in_base_unit" name="package_size_in_base_unit" placeholder="np. 25000" required>
                                    <div class="form-text">w jednostce surowca (np. 25 kg = 25000 g)</div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label" for="pack_price">Cena / opakowanie [zł] *</label>
                                    <input value="<?= old_value('pack_price') ?>" type="number" min="0.01" step="0.01" class="form-control" id="pack_price" name="pack_price" placeholder="0,00" required>
                                </div>
                            </div>
                            <div class="at-new-supplier border rounded-3 p-3 mt-3" hidden data-new-supplier-box>
                                <h6 class="mb-2">Nowy dostawca</h6>
                                <div class="row g-2">
                                    <div class="col-12 col-md-4"><input class="form-control" data-ns="name" placeholder="Nazwa *" aria-label="Nazwa dostawcy"></div>
                                    <div class="col-12 col-md-3"><input class="form-control" data-ns="nip" placeholder="NIP / VAT" aria-label="NIP dostawcy"></div>
                                    <div class="col-12 col-md-3"><input class="form-control" type="email" data-ns="email" placeholder="e-mail zamówień" aria-label="E-mail dostawcy"></div>
                                    <div class="col-12 col-md-2 d-grid"><button type="button" class="btn btn-primary" data-ns-save>Dodaj</button></div>
                                </div>
                                <div class="small mt-2" data-ns-msg aria-live="polite">Dni dostaw i język zamówień uzupełnisz na karcie dostawcy.</div>
                            </div>
                        </section>

                        <section data-step="Alergeny" hidden>
                            <h4 class="at-step-title">Alergeny</h4>
                            <p class="at-step-lead">Zaznacz alergeny z karty specyfikacji dostawcy — trafiają na etykiety produktów z tym surowcem.</p>
                            <div class="row g-2">
                                <?php $picked = array_map('intval', (array) ($_POST['allergens'] ?? [])); ?>
                                <?php foreach ($allergens ?? [] as $aid => $allergen): ?>
                                    <div class="col-6 col-md-4"><label class="form-check-label"><input class="form-check-input me-1" type="checkbox" name="allergens[]" value="<?= (int) $aid ?>" <?= in_array((int) $aid, $picked, true) ? 'checked' : '' ?> data-summary-group="Alergen"><?= htmlspecialchars((string) $allergen['name']) ?></label></div>
                                <?php endforeach; ?>
                            </div>
                        </section>

                        <section data-step="Wartości odżywcze" hidden>
                            <h4 class="at-step-title">Wartości odżywcze (na 100 g)</h4>
                            <p class="at-step-lead">Z karty dostawcy. Puste = do uzupełnienia później (Surowce › Wartości odżywcze, wyszukiwanie w internecie).</p>
                            <div class="row g-3">
                            <div class="col-6 col-md-3">
                                <label class="form-label" for="energy_100g">Wartość energetyczna (kcal) / 100 g</label>
                                <input value="<?= old_value('energy_100g') ?>" type="number" step="any" min="0" inputmode="decimal" class="form-control" id="energy_100g" name="energy_100g" placeholder="do uzupełnienia">
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label" for="fat_100g">Tłuszcz [g] / 100 g</label>
                                <input value="<?= old_value('fat_100g') ?>" type="number" step="any" min="0" inputmode="decimal" class="form-control" id="fat_100g" name="fat_100g" placeholder="do uzupełnienia">
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label" for="saturated_fat_100g">w tym kwasy nasycone [g] / 100 g</label>
                                <input value="<?= old_value('saturated_fat_100g') ?>" type="number" step="any" min="0" inputmode="decimal" class="form-control" id="saturated_fat_100g" name="saturated_fat_100g" placeholder="do uzupełnienia">
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label" for="carbohydrates_100g">Węglowodany [g] / 100 g</label>
                                <input value="<?= old_value('carbohydrates_100g') ?>" type="number" step="any" min="0" inputmode="decimal" class="form-control" id="carbohydrates_100g" name="carbohydrates_100g" placeholder="do uzupełnienia">
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label" for="sugars_100g">w tym cukry [g] / 100 g</label>
                                <input value="<?= old_value('sugars_100g') ?>" type="number" step="any" min="0" inputmode="decimal" class="form-control" id="sugars_100g" name="sugars_100g" placeholder="do uzupełnienia">
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label" for="salt_100g">Sól [g] / 100 g</label>
                                <input value="<?= old_value('salt_100g') ?>" type="number" step="any" min="0" inputmode="decimal" class="form-control" id="salt_100g" name="salt_100g" placeholder="do uzupełnienia">
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label" for="fiber_100g">Błonnik [g] / 100 g</label>
                                <input value="<?= old_value('fiber_100g') ?>" type="number" step="any" min="0" inputmode="decimal" class="form-control" id="fiber_100g" name="fiber_100g" placeholder="do uzupełnienia">
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label" for="proteins_100g">Białko [g] / 100 g</label>
                                <input value="<?= old_value('proteins_100g') ?>" type="number" step="any" min="0" inputmode="decimal" class="form-control" id="proteins_100g" name="proteins_100g" placeholder="do uzupełnienia">
                            </div>
                            </div>
                        </section>

                        <section data-step="Opakowanie" hidden>
                            <h4 class="at-step-title">Opakowanie (BDO / PPWR)</h4>
                            <p class="at-step-lead">Tylko gdy surowiec jest elementem opakowania (karton, folia, etykieta) — do raportów BDO i PPWR.</p>
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" name="is_part_of_package" id="is_part_of_package" <?= old_checkbox('is_part_of_package', 1) ?> value="1">
                                <label class="form-check-label" for="is_part_of_package">Jest elementem opakowania</label>
                            </div>
                            <div id="bdo-packaging-fields" class="row g-3" <?= empty($_POST['is_part_of_package']) ? 'hidden' : '' ?>>
                                <div class="col-12 col-md-4">
                                    <label class="form-label" for="bdo_material_category">Kategoria BDO</label>
                                    <select class="form-select" name="bdo_material_category" id="bdo_material_category">
                                        <option value="">Wybierz</option>
                                        <?php foreach (['PLASTIC' => 'Tworzywa sztuczne', 'ALUMINIUM' => 'Aluminium', 'FERROUS_METAL' => 'Metale żelazne', 'PAPER_CARDBOARD' => 'Papier i tektura', 'GLASS' => 'Szkło', 'WOOD' => 'Drewno', 'MULTIMATERIAL' => 'Wielomateriałowe', 'OTHER' => 'Pozostałe'] as $code => $label): ?>
                                            <option value="<?= $code ?>" <?= old_select('bdo_material_category', $code) ?>><?= $label ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12 col-md-4">
                                    <label class="form-label" for="packaging_usage_type">Rodzaj użycia</label>
                                    <select class="form-select" name="packaging_usage_type" id="packaging_usage_type">
                                        <option value="">Wybierz</option>
                                        <option value="SINGLE_USE" <?= old_select('packaging_usage_type', 'SINGLE_USE') ?>>Jednorazowe</option>
                                        <option value="REUSABLE" <?= old_select('packaging_usage_type', 'REUSABLE') ?>>Wielokrotnego użytku</option>
                                    </select>
                                </div>
                                <div class="col-12 col-md-4">
                                    <label class="form-label" for="packaging_unit_weight_g">Masa jednej sztuki [g]</label>
                                    <input value="<?= old_value('packaging_unit_weight_g') ?>" type="number" min="0" step="0.0001" class="form-control" id="packaging_unit_weight_g" name="packaging_unit_weight_g" placeholder="Nie dotyczy materiałów w g">
                                </div>
                            </div>
                        </section>

                        <section data-step="Zaopatrzenie" hidden>
                            <h4 class="at-step-title">Zaopatrzenie</h4>
                            <p class="at-step-lead">« Na zamówienie » : kupowany według planu produkcji. « Na stanie » : stan min. i maks. (w jednostce surowca) — Zaopatrzenie podpowie zamówienie poniżej minimum.</p>
                            <div class="row g-3">
                                <div class="col-12 col-md-4">
                                    <label class="form-label" for="supply_mode">Tryb</label>
                                    <select class="form-select" id="supply_mode" name="supply_mode">
                                        <option value="order" <?= old_select('supply_mode', 'order') ?>>Na zamówienie (wg planu)</option>
                                        <option value="stock" <?= old_select('supply_mode', 'stock') ?>>Na stanie (min. / maks.)</option>
                                    </select>
                                </div>
                                <div class="col-6 col-md-4">
                                    <label class="form-label" for="stock_min">Stan min.</label>
                                    <input value="<?= old_value('stock_min') ?>" type="number" min="0" step="any" class="form-control" id="stock_min" name="stock_min" placeholder="—">
                                </div>
                                <div class="col-6 col-md-4">
                                    <label class="form-label" for="stock_max">Stan maks.</label>
                                    <input value="<?= old_value('stock_max') ?>" type="number" min="0" step="any" class="form-control" id="stock_max" name="stock_max" placeholder="—">
                                </div>
                            </div>
                        </section>

                        <section data-step="Podsumowanie" data-at-steps-summary hidden>
                            <h4 class="at-step-title">Podsumowanie</h4>
                            <p class="at-step-lead">Sprawdź i utwórz surowiec. Następnie otworzy się karta surowca.</p>
                            <div data-at-steps-summary-body></div>
                        </section>

                        <a href="<?= ROOT ?>/materials/all" class="btn btn-link px-0">Anuluj</a>
                        <button class="btn btn-success" data-at-steps-submit><i class="bi bi-check2"></i> Utwórz surowiec</button>
                    </form>
                </div>
            </div>
        </section>
    </div>
<?php $this->view("page_components/footer"); ?>
<script>
    document.getElementById('is_part_of_package').addEventListener('change', function () {
        document.getElementById('bdo-packaging-fields').hidden = !this.checked;
    });
    // Nowy dostawca bez wychodzenia ze strony : POST /ajax/material-suppliers, potem wybrany na liście.
    (() => {
        const box = document.querySelector('[data-new-supplier-box]');
        const msg = box.querySelector('[data-ns-msg]');
        document.querySelector('[data-new-supplier]').addEventListener('click', () => { box.hidden = !box.hidden; if (!box.hidden) box.querySelector('[data-ns="name"]').focus(); });
        box.querySelector('[data-ns-save]').addEventListener('click', async (e) => {
            const btn = e.currentTarget;
            const data = {};
            box.querySelectorAll('[data-ns]').forEach((el) => { data[el.dataset.ns] = el.value.trim(); });
            if (!data.name) { msg.textContent = 'Wpisz nazwę dostawcy.'; msg.className = 'small mt-2 text-danger'; return; }
            btn.disabled = true;
            const res = await api('/ajax/material-suppliers', { method: 'POST', data });
            btn.disabled = false;
            if (!res.ok || !res.id) { msg.textContent = res.message || res.error || 'Nie udało się dodać dostawcy.'; msg.className = 'small mt-2 text-danger'; return; }
            const select = document.getElementById('id_current_supplier');
            select.add(new Option(res.name, res.id, true, true));
            msg.textContent = 'Dodano dostawcę « ' + res.name + ' » i wybrano go.'; msg.className = 'small mt-2 text-success';
            box.querySelectorAll('[data-ns]').forEach((el) => { el.value = ''; });
            setTimeout(() => { box.hidden = true; }, 1200);
        });
    })();
</script>
<script src="<?= ROOT ?>/assets/js/at-form-steps.js?v=<?= \App\Core\Twig\AppExtension::assetVersion() ?>"></script>
