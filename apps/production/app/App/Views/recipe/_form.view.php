<?php
/**
 * Jeden formularz receptury dla trzech typów (dawniej sześć kopii new / edit) :
 *  - product     : receptura produktu gotowego (1 szt., składniki : surowce + półprodukty)  → RecipeController
 *  - semi        : półprodukt (1 szt., składniki : surowce + kompozycje)                     → SemiProductController
 *  - composition : kompozycja (wydajność w g, składniki : surowce + kompozycje)               → CompositionController
 * Pola POST bez zmian (material_data, sfp_data / composition_data, preparation_type, name, yield_quantity,
 * final_product), więc walidacja i zapis w usługach zostają takie same.
 *
 * Wejście : $form = ['kind' => ..., 'mode' => 'new'|'edit'] oraz dane kontrolera (materials, sfp / compositions,
 * preparation_types, old_data, row / product_data / products_without_recipe, errors).
 * Ścieżka z Karty produktu (krok 3) : for_product (produkt), card_url (powrót do karty), selected_product
 * (produkt wybrany na liście) ; wiersz z kluczem 'added' = półprodukt utworzony przed chwilą, czeka na ilość.
 */
$kind = $form['kind'];
$isNew = $form['mode'] === 'new';
$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$labels = [
    'product'     => ['type' => 'Receptura produktu', 'new' => 'Nowa receptura produktu', 'edit' => 'Edycja receptury produktu', 'list' => '/recipes'],
    'semi'        => ['type' => 'Półprodukt', 'new' => 'Nowy półprodukt', 'edit' => 'Edycja półproduktu', 'list' => '/semi-products'],
    'composition' => ['type' => 'Kompozycja', 'new' => 'Nowa kompozycja', 'edit' => 'Edycja kompozycji', 'list' => '/compositions'],
][$kind];
// Składniki złożone : półprodukty w recepturze produktu, kompozycje w półprodukcie i kompozycji.
$sub = $kind === 'product'
    ? ['key' => 'sfp_data', 'title' => 'Półprodukty', 'options' => $sfp ?? []]
    : ['key' => 'composition_data', 'title' => 'Kompozycje', 'options' => $compositions ?? []];
$selfId = $kind === 'composition' && !$isNew ? (int) ($row['id'] ?? 0) : 0;
$old = $old_data ?? [];
$name = $kind === 'product'
    ? ($product_data['name'] ?? '')
    : old_value('name', $row['name'] ?? '');
$card = $for_product ?? null;
$added = array_values(array_filter((array) ($old[$sub['key']] ?? []), fn($item) => !empty($item['added'])));
?>
<?php $this->view("page_components/header"); ?>

<div class="page-heading">
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h3><?= $isNew ? $labels['new'] : $labels['edit'] ?></h3>
                <p class="text-subtitle text-muted"><?= $isNew ? 'Receptury, półprodukty i kompozycje mają jeden formularz ; typ decyduje o składnikach złożonych i wydajności.' : $e($name) ?></p>
            </div>
            <div class="col-12 col-md-6 order-md-2 order-first">
                <nav aria-label="breadcrumb" class="breadcrumb-header float-start float-lg-end">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="<?= ROOT ?>/recipes">Receptury</a></li>
                        <li class="breadcrumb-item"><a href="<?= ROOT . $labels['list'] ?>"><?= $labels['type'] ?></a></li>
                        <li class="breadcrumb-item active" aria-current="page"><?= $isNew ? 'Nowa' : 'Edycja' ?></li>
                    </ol>
                </nav>
            </div>
        </div>
    </div>
    <section class="section">
        <div class="card">
            <div class="card-body">
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger"><?= implode('<br>', array_map($e, (array) $errors)) ?></div>
                <?php endif; ?>
                <?php if ($card): ?>
                    <div class="alert alert-light-primary d-flex gap-2 align-items-start" data-card-path>
                        <i class="bi bi-signpost-split"></i>
                        <div>
                            <?php if ($kind === 'semi'): ?>
                                <strong>Półprodukt dla produktu « <?= $e($card['name']) ?> »</strong> — krok 1 z 2.
                                Po zapisie przejdziesz do receptury produktu (krok 2), z tym półproduktem już na liście.
                            <?php elseif ($isNew): ?>
                                <strong>Receptura produktu « <?= $e($card['name']) ?> »</strong> — krok 2 z 2.
                                Surowce i półprodukty na 1 szt. ; po zapisie wrócisz do Karty produktu (krok « Receptura »).
                            <?php else: ?>
                                <strong>Receptura produktu « <?= $e($card['name']) ?> »</strong> — po zapisie wrócisz do Karty produktu (krok « Receptura »).
                            <?php endif; ?>
                            <?php foreach ($added as $item): ?>
                                <div class="mt-1">Nowy półprodukt « <?= $e($item['name']) ?> » jest na liście półproduktów — podaj jego ilość.</div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                <form method="POST" id="recipeForm" data-kind="<?= $kind ?>">
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <?php if ($kind === 'product' && $isNew): ?>
                                <label for="final_product" class="form-label">Produkt (bez receptury)</label>
                                <select class="form-select choices" name="final_product" id="final_product">
                                    <?php foreach (($products_without_recipe ?? []) as $product): ?>
                                        <option value="<?= (int) $product['id'] ?>" <?= old_select('final_product', $product['id'], $selected_product ?? '') ?>><?= $e($product['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php elseif ($kind === 'product'): ?>
                                <label class="form-label">Produkt</label>
                                <div class="form-control bg-light"><?= $e($name) ?></div>
                            <?php else: ?>
                                <label for="recipe_name" class="form-label">Nazwa <?= $kind === 'semi' ? 'półproduktu' : 'kompozycji' ?></label>
                                <input type="text" class="form-control" id="recipe_name" name="name" value="<?= $e($name) ?>" placeholder="Nazwa" required>
                            <?php endif; ?>
                        </div>
                        <div class="col-8 col-md-4">
                            <label for="yield_quantity" class="form-label">Otrzymana ilość z receptury</label>
                            <?php if ($kind === 'composition'): ?>
                                <input type="number" step="any" min="0" class="form-control" id="yield_quantity" name="yield_quantity" value="<?= $e(old_value('yield_quantity', $row['yield_quantity'] ?? '')) ?>" required>
                            <?php else: ?>
                                <div class="form-control bg-light">1</div>
                            <?php endif; ?>
                        </div>
                        <div class="col-4 col-md-2">
                            <label class="form-label">Jednostka</label>
                            <div class="form-control bg-light"><?= $kind === 'composition' ? 'g' : 'szt' ?></div>
                        </div>
                        <?php if ($kind !== 'product' && !$isNew && isset($producer_groups)): ?>
                            <div class="col-12 col-md-6">
                                <label for="recipe_producer" class="form-label">Przygotowuje grupa</label>
                                <select class="form-select" id="recipe_producer" data-producer="<?= (int) ($row['id'] ?? 0) ?>">
                                    <option value="">— nie ustawiono</option>
                                    <?php foreach ($producer_groups as $g): ?>
                                        <option value="<?= (int) $g['id'] ?>" <?= ($producer['id'] ?? null) === (int) $g['id'] ? 'selected' : '' ?>><?= $e($g['name_pl']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <span class="form-text">Zapis od razu ; tablet tej grupy pokazuje recepturę, a receptury innych grup — kto ją przygotowuje.</span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <h5 class="mt-4">Surowce</h5>
                    <table class="table table-sm align-middle" data-rows="material_data">
                        <thead><tr><th>Nazwa</th><th style="width:180px">Ilość</th><th style="width:60px"></th></tr></thead>
                        <tbody>
                        <?php foreach (($old['material_data'] ?? []) as $item): ?>
                            <tr>
                                <td><?= $e($item['name']) ?>
                                    <input type="hidden" name="material_data[<?= (int) $item['id'] ?>][id]" value="<?= (int) $item['id'] ?>">
                                    <input type="hidden" name="material_data[<?= (int) $item['id'] ?>][name]" value="<?= $e($item['name']) ?>"></td>
                                <td><input type="number" step="any" class="form-control form-control-sm" name="material_data[<?= (int) $item['id'] ?>][quantity]" value="<?= $e($item['quantity']) ?>"></td>
                                <td><button class="btn btn-sm btn-outline-danger" type="button" data-remove aria-label="Usuń">×</button></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="bg-light-info" data-add>
                                <td><select class="choices form-select" aria-label="Surowiec">
                                    <option value="-1">Wybierz surowiec…</option>
                                    <?php foreach (($materials ?? []) as $material): ?>
                                        <option value="<?= (int) $material['id'] ?>"><?= $e($material['name']) ?> — [<?= $e($material['unit_name'] ?? '') ?>]</option>
                                    <?php endforeach; ?>
                                </select></td>
                                <td><input type="number" step="any" class="form-control form-control-sm" placeholder="Ilość" aria-label="Ilość"></td>
                                <td><button class="btn btn-sm btn-success" type="button" data-add-row>Dodaj</button></td>
                            </tr>
                        </tfoot>
                    </table>

                    <h5 class="mt-4"><?= $sub['title'] ?></h5>
                    <table class="table table-sm align-middle" data-rows="<?= $sub['key'] ?>">
                        <thead><tr><th>Nazwa</th><th style="width:180px">Ilość</th><th style="width:60px"></th></tr></thead>
                        <tbody>
                        <?php foreach (($old[$sub['key']] ?? []) as $item): ?>
                            <tr<?= !empty($item['added']) ? ' class="table-success" data-added' : '' ?>>
                                <td><?= $e($item['name']) ?>
                                    <input type="hidden" name="<?= $sub['key'] ?>[<?= (int) $item['id'] ?>][id]" value="<?= (int) $item['id'] ?>">
                                    <input type="hidden" name="<?= $sub['key'] ?>[<?= (int) $item['id'] ?>][name]" value="<?= $e($item['name']) ?>"></td>
                                <td><input type="number" step="any" class="form-control form-control-sm" name="<?= $sub['key'] ?>[<?= (int) $item['id'] ?>][quantity]" value="<?= $e($item['quantity']) ?>"<?= !empty($item['added']) ? ' min="1" required autofocus aria-label="Ilość nowego półproduktu"' : '' ?>></td>
                                <td><button class="btn btn-sm btn-outline-danger" type="button" data-remove aria-label="Usuń">×</button></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="bg-light-info" data-add>
                                <td><select class="form-select" aria-label="<?= $sub['title'] ?>">
                                    <option value="-1">Wybierz…</option>
                                    <?php foreach ($sub['options'] as $option): ?>
                                        <?php if ($selfId && (int) $option['id'] === $selfId) continue; ?>
                                        <option value="<?= (int) $option['id'] ?>"><?= $e($option['name']) ?><?= !empty($option['unit_name']) ? ' [' . $e($option['unit_name']) . ']' : '' ?></option>
                                    <?php endforeach; ?>
                                </select></td>
                                <td><input type="number" step="any" class="form-control form-control-sm" placeholder="Ilość" aria-label="Ilość"></td>
                                <td><button class="btn btn-sm btn-success" type="button" data-add-row>Dodaj</button></td>
                            </tr>
                        </tfoot>
                    </table>

                    <h5 class="mt-4">Przygotowanie <small class="text-muted fw-normal">czynności i czas (minuty, jak w Karcie produktu)</small></h5>
                    <table class="table table-sm align-middle" data-rows="preparation_type" style="max-width: 720px">
                        <thead><tr><th>Czynność</th><th style="width:180px">Czas [min]</th><th style="width:60px"></th></tr></thead>
                        <tbody>
                        <?php foreach (($old['preparation_type'] ?? []) as $item): ?>
                            <tr>
                                <td><input type="text" class="form-control form-control-sm" name="preparation_type[<?= (int) $item['id_preparation'] ?>][name]" value="<?= $e($item['name']) ?>"></td>
                                <td><input type="number" min="0" step="0.5" class="form-control form-control-sm" data-minutes value="<?= $e(round(((float) $item['duration_second']) / 60, 2)) ?>" aria-label="Czas w minutach">
                                    <input type="hidden" name="preparation_type[<?= (int) $item['id_preparation'] ?>][duration_second]" value="<?= $e($item['duration_second']) ?>">
                                    <input type="hidden" name="preparation_type[<?= (int) $item['id_preparation'] ?>][id_preparation]" value="<?= (int) $item['id_preparation'] ?>"></td>
                                <td><button class="btn btn-sm btn-outline-danger" type="button" data-remove aria-label="Usuń">×</button></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="bg-light-info" data-add>
                                <td><select class="form-select" aria-label="Czynność">
                                    <option value="-1">Wybierz czynność…</option>
                                    <?php foreach (($preparation_types ?? []) as $type): ?>
                                        <option value="<?= (int) $type['id'] ?>"><?= $e($type['name']) ?></option>
                                    <?php endforeach; ?>
                                </select></td>
                                <td><input type="number" min="0" step="0.5" class="form-control form-control-sm" placeholder="0" aria-label="Czas w minutach"></td>
                                <td><button class="btn btn-sm btn-success" type="button" data-add-row>Dodaj</button></td>
                            </tr>
                        </tfoot>
                    </table>

                    <div class="d-flex justify-content-between mt-4">
                        <?php if ($card): ?>
                            <a href="<?= $e($card_url) ?>" class="btn btn-outline-secondary">Powrót do karty produktu</a>
                        <?php else: ?>
                            <a href="<?= ROOT . $labels['list'] ?>" class="btn btn-outline-secondary">Powrót</a>
                        <?php endif; ?>
                        <button class="btn btn-success"><?= $isNew ? 'Utwórz' : 'Zapisz' ?></button>
                    </div>
                </form>
            </div>
        </div>
    </section>
</div>

<?php $this->view("page_components/footer"); ?>

<script>
    // Wiersze składników i czynności : dodaj z wiersza « Dodaj », usuń ×, bez duplikatów.
    (function () {
        const form = document.getElementById('recipeForm');
        const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
        // Czas czynności wpisywany w minutach (jak w Karcie i kreatorze), zapisywany w sekundach (duration_second).
        const toSeconds = (min) => { const m = parseFloat(String(min ?? '').replace(',', '.')); return m >= 0 ? Math.round(m * 60) : ''; };
        form.addEventListener('input', (e) => {
            const m = e.target.closest('[data-minutes]');
            if (m) m.nextElementSibling.value = toSeconds(m.value);
        });
        const removeBtn = '<button class="btn btn-sm btn-outline-danger" type="button" data-remove aria-label="Usuń">×</button>';
        const rowHtml = {
            preparation_type: (id, name, qty) =>
                `<td><input type="text" class="form-control form-control-sm" name="preparation_type[${id}][name]" value="${esc(name)}"></td>
                 <td><input type="number" min="0" step="0.5" class="form-control form-control-sm" data-minutes value="${esc(qty)}" aria-label="Czas w minutach">
                     <input type="hidden" name="preparation_type[${id}][duration_second]" value="${toSeconds(qty)}">
                     <input type="hidden" name="preparation_type[${id}][id_preparation]" value="${id}"></td><td>${removeBtn}</td>`,
            default: (key, id, name, qty) =>
                `<td>${esc(name)}<input type="hidden" name="${key}[${id}][id]" value="${id}"><input type="hidden" name="${key}[${id}][name]" value="${esc(name)}"></td>
                 <td><input type="number" step="any" class="form-control form-control-sm" name="${key}[${id}][quantity]" value="${esc(qty)}"></td><td>${removeBtn}</td>`,
        };
        form.addEventListener('click', (e) => {
            if (e.target.closest('[data-remove]')) { e.target.closest('tr').remove(); return; }
            const add = e.target.closest('[data-add-row]');
            if (!add) return;
            const table = add.closest('table');
            const key = table.dataset.rows;
            const addRow = add.closest('tr');
            const select = addRow.querySelector('select');
            const qty = addRow.querySelector('input[type=number]');
            if (!select || select.value === '-1') return;
            const id = parseInt(select.value, 10);
            const name = select.options[select.selectedIndex].text.replace(/ — \[[^\]]*\]$/, '');
            if (table.querySelector(`tbody input[name="${key}[${id}][${key === 'preparation_type' ? 'id_preparation' : 'id'}]"]`)) {
                alert('Ta pozycja jest już na liście.');
                return;
            }
            const tr = document.createElement('tr');
            tr.innerHTML = key === 'preparation_type' ? rowHtml.preparation_type(id, name, qty.value) : rowHtml.default(key, id, name, qty.value);
            table.tBodies[0].appendChild(tr);
            qty.value = '';
            if (!select.classList.contains('choices')) select.selectedIndex = 0;
        });
        // Grupa przygotowująca : zapis od razu (poza formularzem receptury)
        document.querySelector('[data-producer]')?.addEventListener('change', (e) => {
            fetch('<?= ROOT ?>/ajax/recipes/' + e.target.dataset.producer + '/producer', {
                method: 'PUT', credentials: 'same-origin', headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
                body: JSON.stringify({group_id: e.target.value || null}),
            }).then(r => r.json().then(b => showSnack(r.ok, b.message || 'Błąd')));
        });
    })();
</script>
