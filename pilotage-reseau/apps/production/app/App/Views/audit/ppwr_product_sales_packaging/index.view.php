<?php $this->view('page_components/header'); ?>
<div class="page-heading">
    <div class="page-title"><h3>Konfiguracja kartonów sprzedażowych PPWR</h3><p class="text-subtitle text-muted">Wskaż dla każdego produktu karton sprzedawany klientowi. Kandydaci są pobierani z materiałów opakowaniowych w recepturze.</p></div>
    <section class="section"><div class="card"><div class="card-body p-0"><div class="table-responsive">
        <table class="table table-hover align-middle mb-0"><thead><tr><th>Produkt</th><th>Karton sprzedażowy</th><th class="text-end">PPC</th><th>Status</th><th>Braki</th><th class="text-end">Akcja</th></tr></thead><tbody>
        <?php foreach ($products as $product): ?>
            <tr>
                <td><?= htmlspecialchars($product['product_name']) ?></td>
                <td><?= htmlspecialchars($product['outer_material_name'] ?? '—') ?></td>
                <td class="text-end"><?= $product['ppc'] ? (int)$product['ppc'] : '—' ?></td>
                <td><span class="badge <?= $product['computed_status'] === 'COMPLETE' ? 'bg-success' : 'bg-warning text-dark' ?>"><?= $product['computed_status'] === 'COMPLETE' ? 'Kompletna' : 'Wymaga danych' ?></span></td>
                <td class="small"><?= htmlspecialchars(implode(', ', $product['issues'])) ?: '—' ?></td>
                <td class="text-end"><button class="btn btn-sm btn-primary product-packaging-edit" type="button" data-product='<?= htmlspecialchars(json_encode($product, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>'><i class="bi bi-box-seam me-1"></i>Wybierz karton</button></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($products)): ?><tr><td colspan="6" class="text-center text-muted py-4">Brak aktywnych produktów.</td></tr><?php endif; ?>
        </tbody></table>
    </div></div></div></section>
</div>

<div class="modal fade" id="productPackagingModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><form class="modal-content" id="productPackagingForm">
    <div class="modal-header"><h5 class="modal-title">Karton sprzedażowy: <span id="productPackagingName"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <div class="alert alert-info py-2">Wybierz karton z materiałów opakowaniowych występujących w recepturze. Pozycja oznaczona „rekomendowany” ma w profilu PPWR typ kartonu i rolę handlową.</div>
        <div class="mb-3"><label class="form-label" for="outerPackagingMaterial">Karton sprzedawany klientowi</label><select class="form-select" id="outerPackagingMaterial" required><option value="">Ładowanie kandydatów…</option></select></div>
        <div class="form-text">Liczba produktów w kartonie jest pobierana z parametru PPC produktu i nie jest edytowana w tym miejscu.</div>
        <div class="invalid-feedback d-block d-none" id="productPackagingError"></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Anuluj</button><button type="submit" class="btn btn-primary">Zapisz konfigurację</button></div>
</form></div></div>

<?php $this->view('page_components/footer'); ?>
<script>
    let selectedProduct = null;
    const productPackagingModal = new bootstrap.Modal(document.getElementById('productPackagingModal'));
    const productPackagingError = document.getElementById('productPackagingError');
    function showProductPackagingError(message) { productPackagingError.textContent = message; productPackagingError.classList.remove('d-none'); }

    document.querySelectorAll('.product-packaging-edit').forEach(button => button.addEventListener('click', async () => {
        selectedProduct = JSON.parse(button.dataset.product);
        document.getElementById('productPackagingName').textContent = selectedProduct.product_name;
        productPackagingError.classList.add('d-none');
        const select = document.getElementById('outerPackagingMaterial');
        select.innerHTML = '<option value="">Ładowanie kandydatów…</option>';
        productPackagingModal.show();
        try {
            const response = await fetch(`<?= ROOT ?>/ajax/audits/ppwr-product-packaging/products/${selectedProduct.id_final_product}/candidates`, {headers: {'Accept': 'application/json'}, credentials: 'same-origin'});
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) { showProductPackagingError(payload.message || 'Nie udało się pobrać materiałów z receptury.'); return; }
            select.innerHTML = '<option value="">Wybierz</option>';
            (payload.data || []).forEach(candidate => {
                const option = document.createElement('option');
                option.value = candidate.id_raw_material;
                option.selected = Number(candidate.id_raw_material) === Number(selectedProduct.id_outer_raw_material);
                option.textContent = candidate.material_name + (Number(candidate.is_recommended) ? ' — rekomendowany karton sprzedażowy' : '');
                select.appendChild(option);
            });
            if (!(payload.data || []).length) showProductPackagingError('Receptura produktu nie zawiera materiału z flagą „element opakowania”.');
        } catch (_) { showProductPackagingError('Nie udało się połączyć z serwerem.'); }
    }));

    document.getElementById('productPackagingForm').addEventListener('submit', async event => {
        event.preventDefault();
        productPackagingError.classList.add('d-none');
        try {
            const response = await fetch(`<?= ROOT ?>/ajax/audits/ppwr-product-packaging/products/${selectedProduct.id_final_product}`, {
                method: 'PATCH', headers: {'Content-Type': 'application/json', 'Accept': 'application/json'}, credentials: 'same-origin',
                body: JSON.stringify({id_outer_raw_material: document.getElementById('outerPackagingMaterial').value})
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) { showProductPackagingError(payload.message || 'Nie udało się zapisać konfiguracji.'); return; }
            productPackagingModal.hide(); window.location.reload();
        } catch (_) { showProductPackagingError('Nie udało się połączyć z serwerem.'); }
    });
</script>
