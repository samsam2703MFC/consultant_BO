<?php $this->view('page_components/header'); ?>
<?php $categories = ['PLASTIC' => 'Tworzywa sztuczne', 'ALUMINIUM' => 'Aluminium', 'FERROUS_METAL' => 'Metale żelazne', 'PAPER_CARDBOARD' => 'Papier i tektura', 'GLASS' => 'Szkło', 'WOOD' => 'Drewno', 'MULTIMATERIAL' => 'Wielomateriałowe', 'OTHER' => 'Pozostałe']; ?>
<?php $issueLabels = ['MISSING_RECIPE' => 'Brak receptury', 'MISSING_PACKAGING' => 'Brak elementów opakowania w recepturze', 'MISSING_CATEGORY' => 'Brak kategorii BDO', 'MISSING_USAGE_TYPE' => 'Brak rodzaju użycia', 'MISSING_WEIGHT' => 'Brak masy jednej sztuki']; ?>
<div class="page-heading">
    <div class="page-title"><h3>Audyt danych opakowań BDO</h3><p class="text-subtitle text-muted">Uzupełnij dane surowca bezpośrednio z listy. Dla materiałów w gramach masa pochodzi z receptury.</p></div>
    <section class="section"><div class="card"><div class="card-body p-0"><div class="table-responsive">
        <table class="table table-hover align-middle mb-0"><thead><tr><th>Produkt</th><th>Element opakowania</th><th>Jednostka</th><th>Problem</th><th class="text-end">Akcje</th></tr></thead><tbody>
        <?php foreach ($issues as $issue): ?>
            <tr>
                <td><?= htmlspecialchars($issue['product_name']) ?></td>
                <td><?= htmlspecialchars($issue['material_name'] ?? '—') ?></td>
                <td><?= htmlspecialchars($issue['unit_name'] ?? '—') ?></td>
                <td><span class="badge bg-warning text-dark"><?= htmlspecialchars($issueLabels[$issue['issue']] ?? $issue['issue']) ?></span></td>
                <td class="text-end">
                    <?php if (!empty($issue['id_raw_material'])): ?>
                        <button type="button" class="btn btn-sm btn-primary packaging-edit"
                            data-id="<?= (int)$issue['id_raw_material'] ?>"
                            data-name="<?= htmlspecialchars($issue['material_name'], ENT_QUOTES) ?>"
                            data-unit="<?= htmlspecialchars($issue['unit_name'] ?? '', ENT_QUOTES) ?>"
                            data-category="<?= htmlspecialchars($issue['bdo_material_category'] ?? '', ENT_QUOTES) ?>"
                            data-usage="<?= htmlspecialchars($issue['packaging_usage_type'] ?? '', ENT_QUOTES) ?>"
                            data-weight="<?= htmlspecialchars($issue['packaging_unit_weight_g'] ?? '', ENT_QUOTES) ?>">
                            <i class="bi bi-pencil me-1"></i>Uzupełnij dane BDO
                        </button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($issues)): ?><tr><td colspan="5" class="text-center text-success py-4">Brak wykrytych problemów.</td></tr><?php endif; ?>
        </tbody></table>
    </div></div></div></section>
</div>

<div class="modal fade" id="packagingModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><form class="modal-content" id="packagingForm">
        <div class="modal-header"><h5 class="modal-title">Dane opakowania BDO</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <strong id="packagingMaterialName"></strong><div class="text-muted small mb-3">Jednostka: <span id="packagingMaterialUnit"></span></div>
            <div class="mb-3"><label class="form-label" for="packagingCategory">Kategoria BDO</label><select class="form-select" id="packagingCategory" required><option value="">Wybierz</option><?php foreach ($categories as $value => $label): ?><option value="<?= $value ?>"><?= $label ?></option><?php endforeach; ?></select></div>
            <div class="mb-3"><label class="form-label" for="packagingUsage">Rodzaj użycia</label><select class="form-select" id="packagingUsage" required><option value="">Wybierz</option><option value="SINGLE_USE">Jednorazowe</option><option value="REUSABLE">Wielokrotnego użytku</option></select></div>
            <div class="mb-3" id="packagingWeightGroup"><label class="form-label" for="packagingWeight">Masa jednej sztuki [g]</label><input class="form-control" type="number" min="0.0001" step="0.0001" id="packagingWeight"><div class="form-text">Wymagane dla materiałów liczonych w sztukach.</div></div>
            <div class="alert alert-info py-2 d-none" id="packagingGramInfo">Dla materiału w gramach masa wynika z ilości zapisanej w recepturze.</div>
            <div class="invalid-feedback d-block d-none" id="packagingError"></div>
        </div>
        <div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Anuluj</button><button class="btn btn-primary" type="submit" id="savePackaging"><span class="button-label"><i class="bi bi-save me-1"></i>Zapisz</span><span class="spinner-border spinner-border-sm d-none"></span></button></div>
    </form></div>
</div>

<?php $this->view('page_components/footer'); ?>
<script>
    let selectedMaterialId = null;
    const packagingModal = new bootstrap.Modal(document.getElementById('packagingModal'));
    const gramUnits = ['g', 'gram', 'gramy'];

    function isGramUnit(unit) { return gramUnits.includes((unit || '').trim().toLowerCase()); }
    function showPackagingError(message) { const error = document.getElementById('packagingError'); error.textContent = message; error.classList.remove('d-none'); }

    document.querySelectorAll('.packaging-edit').forEach(button => button.addEventListener('click', () => {
        selectedMaterialId = button.dataset.id;
        const isGram = isGramUnit(button.dataset.unit);
        document.getElementById('packagingMaterialName').textContent = button.dataset.name;
        document.getElementById('packagingMaterialUnit').textContent = button.dataset.unit || '—';
        document.getElementById('packagingCategory').value = button.dataset.category || '';
        document.getElementById('packagingUsage').value = button.dataset.usage || '';
        document.getElementById('packagingWeight').value = button.dataset.weight || '';
        document.getElementById('packagingWeightGroup').classList.toggle('d-none', isGram);
        document.getElementById('packagingGramInfo').classList.toggle('d-none', !isGram);
        document.getElementById('packagingError').classList.add('d-none');
        packagingModal.show();
    }));

    document.getElementById('packagingForm').addEventListener('submit', async event => {
        event.preventDefault();
        const category = document.getElementById('packagingCategory').value;
        const usage = document.getElementById('packagingUsage').value;
        const weight = document.getElementById('packagingWeight').value.trim();
        const unit = document.getElementById('packagingMaterialUnit').textContent;
        if (!category || !usage) { showPackagingError('Wybierz kategorię BDO oraz rodzaj użycia.'); return; }
        if (!isGramUnit(unit) && (!/^\d+(?:[.,]\d+)?$/.test(weight) || Number(weight.replace(',', '.')) <= 0)) { showPackagingError('Podaj masę jednej sztuki większą od 0 g.'); return; }
        const save = document.getElementById('savePackaging');
        save.disabled = true; save.querySelector('.button-label').classList.add('d-none'); save.querySelector('.spinner-border').classList.remove('d-none'); document.getElementById('packagingError').classList.add('d-none');
        try {
            const response = await fetch(`<?= ROOT ?>/ajax/audits/packaging-data/materials/${selectedMaterialId}`, { method: 'PATCH', headers: {'Content-Type': 'application/json', 'Accept': 'application/json'}, credentials: 'same-origin', body: JSON.stringify({bdo_material_category: category, packaging_usage_type: usage, packaging_unit_weight_g: isGramUnit(unit) ? null : weight.replace(',', '.')}) });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) { showPackagingError(payload.message || 'Nie udało się zapisać danych.'); return; }
            packagingModal.hide();
            await Swal.fire({icon: 'success', title: 'Zapisano', text: payload.message || 'Dane opakowania zostały zaktualizowane.', timer: 1400, showConfirmButton: false});
            window.location.reload();
        } catch (_) { showPackagingError('Nie udało się połączyć z serwerem.'); }
        finally { save.disabled = false; save.querySelector('.button-label').classList.remove('d-none'); save.querySelector('.spinner-border').classList.add('d-none'); }
    });
</script>
