<?php $this->view('page_components/header'); ?>
<?php
$issueLabels = [
    'MISSING_IDENTIFICATION_NUMBER' => 'Brak numeru identyfikacyjnego',
    'MISSING_IDENTIFICATION_SOURCE' => 'Brak źródła numeru identyfikacyjnego',
    'MISSING_UNIT_WEIGHT' => 'Brak masy jednego elementu opakowania',
    'MISSING_PACKAGING_LEVEL' => 'Brak poziomu opakowania',
    'MISSING_PACKAGING_TYPE' => 'Brak typu opakowania',
    'MISSING_DOMINANT_MATERIAL' => 'Brak materiału dominującego',
    'MISSING_RECYCLED_CONTENT' => 'Brak danych o recyklacie',
    'MISSING_RECYCLABILITY' => 'Brak dokumentacji recyklowalności',
    'MISSING_MINIMISATION' => 'Brak potwierdzenia minimalizacji',
    'MISSING_DIMENSIONS' => 'Brak wymiarów lub objętości',
    'MISSING_REUSE_CYCLES' => 'Brak liczby cykli użycia',
];
$levelLabels = ['SALES' => 'Handlowe — karton sprzedawany klientowi', 'INNER' => 'Wewnętrzne — komponent kartonu', 'GROUPED' => 'Zbiorcze — grupa sprzedawanych kartonów', 'TRANSPORT' => 'Transportowe', 'E_COMMERCE' => 'E-commerce'];
$materialLabels = ['PLASTIC' => 'Tworzywo sztuczne', 'PAPER_CARDBOARD' => 'Papier i tektura', 'GLASS' => 'Szkło', 'ALUMINIUM' => 'Aluminium', 'FERROUS_METAL' => 'Metale żelazne', 'WOOD' => 'Drewno', 'MULTIMATERIAL' => 'Wielomateriałowe', 'OTHER' => 'Pozostałe'];
$packagingTypeLabels = ['CARTON_BOX' => 'Pudełko kartonowe', 'BAG' => 'Worek / woreczek / torebka', 'DOYPACK' => 'Doypack', 'FILM' => 'Folia opakowaniowa', 'STRETCH_FILM' => 'Folia stretch', 'TRAY' => 'Tacka / wytłoczka', 'CUP_WITH_LID' => 'Kubek z wieczkiem', 'MOULD' => 'Foremka', 'LABEL' => 'Etykieta', 'WRAPPING_PAPER' => 'Papier do pakowania', 'PAPER_BAND' => 'Obręcz papierowa', 'ADHESIVE_TAPE' => 'Taśma klejąca', 'RIBBON' => 'Wstążka', 'OTHER' => 'Inny typ'];
?>
<div class="page-heading">
    <div class="page-title">
        <h3>Rejestr PPWR</h3>
        <p class="text-subtitle text-muted">Centralne zestawienie danych materiałów opakowaniowych i kartonów sprzedażowych. Masa jest współdzielona z kartoteką surowca.</p>
    </div>
    <section class="section">
        <div class="row">
            <div class="col-md-4"><div class="card"><div class="card-body"><div class="text-muted small">Materiały opakowaniowe</div><div class="fs-4 fw-bold"><?= count($materials) ?></div></div></div></div>
            <div class="col-md-4"><div class="card"><div class="card-body"><div class="text-muted small">Profile kompletne</div><div class="fs-4 fw-bold text-success"><?= count(array_filter($materials, static fn(array $row): bool => $row['computed_status'] === 'COMPLETE')) ?></div></div></div></div>
            <div class="col-md-4"><div class="card"><div class="card-body"><div class="text-muted small">Wymagają danych</div><div class="fs-4 fw-bold text-warning"><?= count(array_filter($materials, static fn(array $row): bool => $row['computed_status'] !== 'COMPLETE')) ?></div></div></div></div>
        </div>
        <div class="card">
            <div class="card-body p-0"><div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Materiał</th><th>Identyfikator</th><th class="text-end">Masa [g]</th><th>Domyślna rola</th><th>Materiał dominujący</th><th>Status</th><th>Braki</th><th class="text-end">Akcja</th></tr></thead>
                    <tbody>
                    <?php foreach ($materials as $material): ?>
                        <tr>
                            <td><?= htmlspecialchars($material['material_name']) ?></td>
                            <td><?= htmlspecialchars($material['identification_number'] ?? '—') ?></td>
                            <td class="text-end"><?= $material['packaging_unit_weight_g'] !== null ? number_format((float)$material['packaging_unit_weight_g'], 2, ',', ' ') : '—' ?></td>
                            <td><?= htmlspecialchars($levelLabels[$material['packaging_level'] ?? ''] ?? '—') ?></td>
                            <td><?= htmlspecialchars($materialLabels[$material['dominant_material'] ?? ''] ?? '—') ?></td>
                            <td><span class="badge <?= $material['computed_status'] === 'COMPLETE' ? 'bg-success' : 'bg-warning text-dark' ?>"><?= $material['computed_status'] === 'COMPLETE' ? 'Kompletny' : 'Wymaga danych' ?></span></td>
                            <td class="small"><?= htmlspecialchars(implode(', ', array_map(static fn(string $issue): string => $issueLabels[$issue] ?? $issue, $material['issues']))) ?: '—' ?></td>
                            <td class="text-end"><button class="btn btn-sm btn-primary ppwr-edit" type="button" data-material='<?= htmlspecialchars(json_encode($material, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>'><i class="bi bi-pencil me-1"></i>Profil PPWR</button></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($materials)): ?><tr><td colspan="8" class="text-center text-muted py-4">Nie znaleziono materiałów oznaczonych jako element opakowania.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div></div>
        </div>
        <div class="card mt-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h4 class="card-title mb-0">Kartony sprzedażowe produktów</h4>
                <a class="btn btn-sm btn-outline-primary" href="<?= ROOT ?>/ppwr/sales-packaging">Edytuj kartony</a>
            </div>
            <div class="card-body p-0"><div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Produkt</th><th>Karton sprzedażowy</th><th class="text-end">PPC</th><th>Status</th><th>Braki</th></tr></thead>
                    <tbody>
                    <?php foreach ($products as $product): ?>
                        <tr>
                            <td><?= htmlspecialchars($product['product_name']) ?></td>
                            <td><?= htmlspecialchars($product['outer_material_name'] ?? '—') ?></td>
                            <td class="text-end"><?= $product['ppc'] ? (int)$product['ppc'] : '—' ?></td>
                            <td><span class="badge <?= $product['computed_status'] === 'COMPLETE' ? 'bg-success' : 'bg-warning text-dark' ?>"><?= $product['computed_status'] === 'COMPLETE' ? 'Kompletna' : 'Wymaga danych' ?></span></td>
                            <td class="small"><?= htmlspecialchars(implode(', ', $product['issues'])) ?: '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($products)): ?><tr><td colspan="5" class="text-center text-muted py-4">Brak aktywnych produktów.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div></div>
        </div>
    </section>
</div>

<div class="modal fade" id="ppwrModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg"><form class="modal-content" id="ppwrForm">
        <div class="modal-header"><h5 class="modal-title">Profil PPWR: <span id="ppwrMaterialName"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="alert alert-info py-2">Uzupełnij dane dostępne dla danego materiału. Wymiary są wymagane dla opakowań zbiorczych, transportowych i e-commerce.</div>
            <div class="alert alert-warning d-none" id="ppwrMissingSummary"><strong>Brakujące dane:</strong><ul class="mb-0 mt-2" id="ppwrMissingSummaryList"></ul></div>
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label" for="identification_number">Numer identyfikacyjny surowca</label><input class="form-control" maxlength="255" id="identification_number"><div class="form-text">Numer własny albo numer dostawcy używany do identyfikacji tego opakowania.</div></div>
                <div class="col-md-6"><label class="form-label" for="identification_source">Źródło numeru</label><select class="form-select" id="identification_source"><option value="">Wybierz</option><option value="OWN">Numer własny</option><option value="SUPPLIER">Numer dostawcy</option></select></div>
                <div class="col-md-6"><label class="form-label" for="packaging_unit_weight_g">Masa jednego elementu opakowania [g]</label><input class="form-control" type="number" min="0.001" step="0.001" id="packaging_unit_weight_g"><div class="form-text">Wspólna wartość dla BDO i PPWR — zapisuje się w kartotece surowca.</div></div>
                <div class="col-md-6"><label class="form-label" for="packaging_level">Domyślna rola opakowania</label><select class="form-select" id="packaging_level"><option value="">Wybierz</option><?php foreach ($levelLabels as $value => $label): ?><option value="<?= $value ?>"><?= $label ?></option><?php endforeach; ?></select><div class="form-text">Rola jest wskazówką dla materiału. Ostateczna klasyfikacja następuje w konfiguracji produktu.</div></div>
                <div class="col-md-6"><label class="form-label" for="packaging_type">Typ opakowania</label><select class="form-select" id="packaging_type"><option value="">Wybierz</option><?php foreach ($packagingTypeLabels as $value => $label): ?><option value="<?= $value ?>"><?= $label ?></option><?php endforeach; ?></select></div>
                <div class="col-md-6"><label class="form-label" for="dominant_material">Materiał dominujący</label><select class="form-select" id="dominant_material"><option value="">Wybierz</option><?php foreach ($materialLabels as $value => $label): ?><option value="<?= $value ?>"><?= $label ?></option><?php endforeach; ?></select><div class="form-text" id="dominantMaterialHint">Materiał stanowiący największą część masy opakowania. Dla plastiku uruchamia dane o recyklacie.</div></div>
                <div class="col-md-6"><label class="form-label" for="food_contact_status">Kontakt z żywnością</label><select class="form-select" id="food_contact_status"><option value="UNKNOWN">Nieustalono</option><option value="YES">Tak</option><option value="NO">Nie</option></select><div class="form-text">Wybierz „Tak”, gdy materiał styka się bezpośrednio z produktem spożywczym.</div></div>
                <div class="col-md-6"><label class="form-label" for="reuse_status">Ponowne użycie</label><select class="form-select" id="reuse_status"><option value="NOT_APPLICABLE">Nie dotyczy</option><option value="SINGLE_USE">Jednorazowe</option><option value="REUSABLE">Wielokrotne</option><option value="REFILL">Refill</option></select><div class="form-text">Określa, czy opakowanie po użyciu jest jednorazowe, zwracane czy ponownie napełniane.</div></div>
                <div class="col-md-6"><label class="form-label" for="recyclability_status">Dokumentacja recyklowalności</label><select class="form-select" id="recyclability_status"><option value="NOT_ASSESSED">Brak informacji</option><option value="ASSESSED">Deklaracja / specyfikacja dostawcy</option><option value="VERIFIED">Dokumentacja sprawdzona</option><option value="NOT_RECYCLABLE">Potwierdzono: nie nadaje się do recyklingu</option></select><div class="form-text">Wybierz źródło potwierdzające możliwość recyklingu. Nie wystawiaj własnej oceny bez dokumentacji.</div></div>
                <div class="col-md-6 ppwr-recyclability-evidence"><label class="form-label" for="recyclability_grade">Klasa recyklowalności</label><select class="form-select" id="recyclability_grade"><option value="">Nie ustalono</option><option value="A">A</option><option value="B">B</option><option value="C">C</option></select><div class="form-text">Wprowadź tylko wtedy, gdy wynika z wiarygodnej dokumentacji lub oceny.</div></div>
                <div class="col-md-6 ppwr-recyclability-evidence"><label class="form-label" for="recyclability_assessment_date">Data dokumentu</label><input class="form-control" type="date" id="recyclability_assessment_date"><div class="form-text">Data deklaracji dostawcy, specyfikacji albo sprawdzenia dokumentacji.</div></div>
                <div class="col-md-6"><label class="form-label" for="minimisation_status">Potwierdzenie minimalizacji</label><select class="form-select" id="minimisation_status"><option value="NOT_ASSESSED">Brak potwierdzenia</option><option value="ASSESSED">Potwierdzone wewnętrznie</option><option value="VERIFIED">Potwierdzone dokumentacją</option></select><div class="form-text">Potwierdź, że masy ani objętości nie można zmniejszyć bez pogorszenia ochrony, higieny, trwałości lub transportu produktu.</div></div>
                <div class="col-12"><label class="form-label" for="minimisation_justification">Uzasadnienie minimalizacji / pustej przestrzeni</label><textarea class="form-control" id="minimisation_justification" rows="2"></textarea></div>
                <div class="col-12"><div class="form-text">Wymiary długość, szerokość i wysokość dotyczą zawsze zewnętrznego obrysu opakowania.</div></div>
                <div class="col-md-4"><label class="form-label" for="length_mm">Długość zewnętrzna [mm]</label><input class="form-control" type="number" min="1" step="1" id="length_mm"></div>
                <div class="col-md-4"><label class="form-label" for="width_mm">Szerokość zewnętrzna [mm]</label><input class="form-control" type="number" min="1" step="1" id="width_mm"></div>
                <div class="col-md-4"><label class="form-label" for="height_mm">Wysokość zewnętrzna [mm]</label><input class="form-control" type="number" min="1" step="1" id="height_mm"></div>
                <div class="col-md-6"><label class="form-label" for="outer_volume_cm3">Objętość zewnętrzna [cm³]</label><input class="form-control" type="number" min="1" step="1" id="outer_volume_cm3" readonly><div class="form-text">Wyliczana automatycznie z wymiarów zewnętrznych.</div></div>
                <div class="col-md-6"><label class="form-label" for="inner_volume_cm3">Objętość wewnętrzna [cm³]</label><input class="form-control" type="number" min="0.001" step="0.001" id="inner_volume_cm3"><div class="form-text">Przestrzeń dostępna wewnątrz opakowania; przyda się do oceny pustej przestrzeni kartonu sprzedażowego.</div></div>
                <div class="col-md-6"><label class="form-label" for="measurement_source">Źródło wymiarów</label><select class="form-select" id="measurement_source"><option value="">Nieustalone</option><option value="SUPPLIER">Dostawca</option><option value="INTERNAL_MEASUREMENT">Pomiar wewnętrzny</option><option value="SPECIFICATION">Specyfikacja</option><option value="LEGACY_DESCRIPTION">Opis historyczny</option></select><div class="form-text">Wskaż pochodzenie danych, aby odróżnić pomiar od wartości wywnioskowanej z opisu.</div></div>
                <div class="col-md-6"><label class="form-label" for="measured_at">Data pomiaru</label><input class="form-control" type="date" id="measured_at"><div class="form-text">Data pomiaru lub dokumentu, z którego pobrano wymiary.</div></div>
                <div class="col-md-6 ppwr-plastic"><label class="form-label" for="recycled_content_percent">Recyklat post-consumer [%]</label><input class="form-control" type="number" min="0" max="100" step="0.01" id="recycled_content_percent"><div class="form-text">Procent tworzywa pochodzącego z odpadów konsumenckich po użyciu. Wpisz wartość wyłącznie z dokumentacji dostawcy.</div></div>
                <div class="col-md-6 ppwr-plastic"><label class="form-label" for="recycled_content_status">Status danych o recyklacie</label><select class="form-select" id="recycled_content_status"><option value="NOT_ASSESSED">Nie oceniono</option><option value="DECLARED">Deklaracja dostawcy</option><option value="VERIFIED">Zweryfikowano</option></select><div class="form-text">„Deklaracja dostawcy” oznacza otrzymaną informację; „Zweryfikowano” — sprawdzoną dokumentację.</div></div>
                <div class="col-md-6 ppwr-reuse"><label class="form-label" for="target_reuse_cycles">Docelowa liczba cykli</label><input class="form-control" type="number" min="1" step="1" id="target_reuse_cycles"><div class="form-text">Planowana minimalna liczba użyć tego samego opakowania przed wycofaniem.</div></div>
                <div class="col-md-6 ppwr-reuse"><label class="form-label" for="return_system_description">System zwrotu / refill</label><input class="form-control" maxlength="500" id="return_system_description"><div class="form-text">Krótko opisz sposób zwrotu, mycia, ponownego użycia lub ponownego napełnienia.</div></div>
            </div>
            <div class="invalid-feedback d-block d-none mt-3" id="ppwrError"></div>
        </div>
        <div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Anuluj</button><button class="btn btn-primary" type="submit" id="savePpwr">Zapisz profil PPWR</button></div>
    </form></div>
</div>

<?php $this->view('page_components/footer'); ?>
<script>
    let selectedMaterialId = null;
    const ppwrModal = new bootstrap.Modal(document.getElementById('ppwrModal'));
    const fields = ['identification_number', 'identification_source', 'packaging_unit_weight_g', 'packaging_level', 'packaging_type', 'dominant_material', 'food_contact_status', 'recycled_content_percent', 'recycled_content_status', 'recyclability_status', 'recyclability_grade', 'recyclability_assessment_date', 'reuse_status', 'target_reuse_cycles', 'return_system_description', 'minimisation_status', 'minimisation_justification', 'length_mm', 'width_mm', 'height_mm', 'outer_volume_cm3', 'inner_volume_cm3', 'measurement_source', 'measured_at'];

    function toggleConditionalFields() {
        const plastic = document.getElementById('dominant_material').value === 'PLASTIC';
        const reuse = ['REUSABLE', 'REFILL'].includes(document.getElementById('reuse_status').value);
        const recyclabilityEvidence = ['ASSESSED', 'VERIFIED'].includes(document.getElementById('recyclability_status').value);
        document.querySelectorAll('.ppwr-plastic').forEach(element => element.classList.toggle('d-none', !plastic));
        document.querySelectorAll('.ppwr-reuse').forEach(element => element.classList.toggle('d-none', !reuse));
        document.querySelectorAll('.ppwr-recyclability-evidence').forEach(element => element.classList.toggle('d-none', !recyclabilityEvidence));
    }

    function bdoToDominantMaterial(category) {
        return ['PLASTIC', 'PAPER_CARDBOARD', 'GLASS', 'ALUMINIUM', 'FERROUS_METAL', 'WOOD'].includes(category) ? category : '';
    }

    function updateDominantMaterialHint(material) {
        const suggested = bdoToDominantMaterial(material.bdo_material_category);
        document.getElementById('dominantMaterialHint').textContent = suggested
            ? 'Podpowiedziano z kategorii BDO. Potwierdź wartość lub zmień ją, jeśli inny materiał dominuje masowo.'
            : 'Wybierz materiał stanowiący największą część masy opakowania. Dla pozycji wielomateriałowych wskaż go na podstawie specyfikacji.';
    }

    function wholeNumber(value) {
        if (value === null || value === undefined || value === '') return '';
        const number = Number(value);
        return Number.isFinite(number) ? String(Math.round(number)) : '';
    }

    function decimalWithoutTrailingZeros(value) {
        if (value === null || value === undefined || value === '') return '';
        const number = Number(value);
        return Number.isFinite(number) ? String(number) : '';
    }

    function calculateOuterVolume() {
        const length = Number(document.getElementById('length_mm').value);
        const width = Number(document.getElementById('width_mm').value);
        const height = Number(document.getElementById('height_mm').value);
        document.getElementById('outer_volume_cm3').value = length > 0 && width > 0 && height > 0
            ? String(Math.round((length * width * height) / 1000))
            : '';
    }

    const issueFields = {
        MISSING_IDENTIFICATION_NUMBER: {fields: ['identification_number'], message: 'Podaj numer identyfikacyjny materiału.'},
        MISSING_IDENTIFICATION_SOURCE: {fields: ['identification_source'], message: 'Wskaż źródło numeru identyfikacyjnego.'},
        MISSING_UNIT_WEIGHT: {fields: ['packaging_unit_weight_g'], message: 'Podaj masę jednego elementu opakowania.'},
        MISSING_PACKAGING_LEVEL: {fields: ['packaging_level'], message: 'Wybierz domyślną rolę opakowania.'},
        MISSING_PACKAGING_TYPE: {fields: ['packaging_type'], message: 'Wybierz typ opakowania.'},
        MISSING_DOMINANT_MATERIAL: {fields: ['dominant_material'], message: 'Wybierz materiał dominujący.'},
        MISSING_RECYCLED_CONTENT: {fields: ['recycled_content_status'], message: 'Wskaż status danych o recyklacie.'},
        MISSING_RECYCLABILITY: {fields: ['recyclability_status'], message: 'Wskaż dokumentację recyklowalności.'},
        MISSING_MINIMISATION: {fields: ['minimisation_status'], message: 'Wskaż sposób potwierdzenia minimalizacji.'},
        MISSING_DIMENSIONS: {fields: ['length_mm', 'width_mm', 'height_mm']},
        MISSING_REUSE_CYCLES: {fields: ['target_reuse_cycles'], message: 'Podaj docelową liczbę cykli użycia.'}
    };
    const issueLabels = <?= json_encode($issueLabels, JSON_UNESCAPED_UNICODE) ?>;

    function clearMissingFieldHighlights() {
        document.querySelectorAll('#ppwrForm .is-invalid').forEach(field => field.classList.remove('is-invalid'));
        document.querySelectorAll('#ppwrForm .ppwr-field-feedback').forEach(feedback => feedback.remove());
        document.getElementById('ppwrMissingSummary').classList.add('d-none');
        document.getElementById('ppwrMissingSummaryList').replaceChildren();
    }

    function showMissingFieldHighlights(issues) {
        clearMissingFieldHighlights();
        if (!issues.length) return;

        const summary = document.getElementById('ppwrMissingSummary');
        const summaryList = document.getElementById('ppwrMissingSummaryList');
        issues.forEach(issue => {
            const details = issueFields[issue];
            const item = document.createElement('li');
            item.textContent = issueLabels[issue] || issue;
            summaryList.appendChild(item);
            if (!details) return;
            details.fields.forEach(fieldId => {
                const field = document.getElementById(fieldId);
                field.classList.add('is-invalid');
                if (details.message && !field.parentElement.querySelector(`[data-ppwr-feedback="${fieldId}"]`)) {
                    const feedback = document.createElement('div');
                    feedback.className = 'invalid-feedback d-block ppwr-field-feedback';
                    feedback.dataset.ppwrFeedback = fieldId;
                    feedback.textContent = details.message;
                    field.insertAdjacentElement('afterend', feedback);
                }
            });
        });
        summary.classList.remove('d-none');
    }

    document.querySelectorAll('.ppwr-edit').forEach(button => button.addEventListener('click', () => {
        const material = JSON.parse(button.dataset.material);
        selectedMaterialId = material.id_raw_material;
        document.getElementById('ppwrMaterialName').textContent = material.material_name;
        fields.forEach(field => document.getElementById(field).value = material[field] ?? '');
        if (!material.dominant_material) {
            document.getElementById('dominant_material').value = bdoToDominantMaterial(material.bdo_material_category);
        }
        updateDominantMaterialHint(material);
        ['length_mm', 'width_mm', 'height_mm', 'outer_volume_cm3'].forEach(field => {
            document.getElementById(field).value = wholeNumber(material[field]);
        });
        document.getElementById('packaging_unit_weight_g').value = decimalWithoutTrailingZeros(material.packaging_unit_weight_g);
        calculateOuterVolume();
        document.getElementById('ppwrError').classList.add('d-none');
        toggleConditionalFields();
        showMissingFieldHighlights(material.issues || []);
        ppwrModal.show();
    }));

    document.getElementById('dominant_material').addEventListener('change', toggleConditionalFields);
    document.getElementById('reuse_status').addEventListener('change', toggleConditionalFields);
    document.getElementById('recyclability_status').addEventListener('change', toggleConditionalFields);
    ['length_mm', 'width_mm', 'height_mm'].forEach(field => {
        document.getElementById(field).addEventListener('input', calculateOuterVolume);
    });
    fields.forEach(fieldId => {
        const field = document.getElementById(fieldId);
        field.addEventListener(field.tagName === 'SELECT' ? 'change' : 'input', () => {
            field.classList.remove('is-invalid');
            field.parentElement.querySelector(`[data-ppwr-feedback="${fieldId}"]`)?.remove();
        });
    });
    document.getElementById('ppwrForm').addEventListener('submit', async event => {
        event.preventDefault();
        const data = Object.fromEntries(fields.map(field => [field, document.getElementById(field).value]));
        const error = document.getElementById('ppwrError');
        error.classList.add('d-none');
        try {
            const response = await fetch(`<?= ROOT ?>/ajax/audits/ppwr-data/materials/${selectedMaterialId}`, {method: 'PATCH', headers: {'Content-Type': 'application/json', 'Accept': 'application/json'}, credentials: 'same-origin', body: JSON.stringify(data)});
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) { error.textContent = payload.message || 'Nie udało się zapisać profilu PPWR.'; error.classList.remove('d-none'); return; }
            ppwrModal.hide();
            window.location.reload();
        } catch (_) { error.textContent = 'Nie udało się połączyć z serwerem.'; error.classList.remove('d-none'); }
    });
</script>
