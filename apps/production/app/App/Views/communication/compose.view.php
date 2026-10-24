<?php $this->view('page_components/header'); ?>

<div class="page-heading">
    <div class="d-flex justify-content-between align-items-start gap-3">
        <div>
            <h3>Przygotowanie wiadomości</h3>
            <p class="text-subtitle text-muted mb-0">Wiadomość: <?= htmlspecialchars($message_type_label ?? 'Dokument', ENT_QUOTES, 'UTF-8') ?></p>
        </div>
        <a href="<?= htmlspecialchars($back_url ?? (ROOT . '/communication/messages'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary">Wróć</a>
    </div>
</div>

<section class="section">
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger"><?= implode('<br>', array_map(fn($error) => htmlspecialchars($error, ENT_QUOTES, 'UTF-8'), $errors)) ?></div>
    <?php endif; ?>

    <?php if (!empty($document)): ?>
        <div class="alert alert-info border">
                            Treść jest zapisywana jako niezmienna kopia wiadomości. Edycja nie modyfikuje szablonu; PDF dokumentu zostanie dołączony w chwili przygotowania wiadomości.
        </div>

        <div class="card mb-4">
            <div class="card-body">
                <div class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <div class="text-muted small">Dokument</div>
                        <div class="fw-semibold"><?= htmlspecialchars($document['number'], ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted small">Data dokumentu</div>
                        <div><?= htmlspecialchars($document['date'], ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                    <div class="col-md-5 text-md-end">
                        <button id="download-proforma-pdf" class="btn btn-outline-primary" type="button">
                            <i class="bi bi-file-earmark-pdf"></i> Otwórz PDF dokumentu
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="language-code" class="form-label">Język</label>
                        <select id="language-code" class="form-select">
                            <?php foreach (($languages ?? []) as $code => $label): ?>
                                <option value="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>" <?= ($selected_language ?? 'fr') === $code ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-8">
                        <label for="template-id" class="form-label">Wariant szablonu</label>
                        <select id="template-id" class="form-select"></select>
                        <div id="template-feedback" class="form-text"></div>
                    </div>

                    <div class="col-12">
                        <label for="recipient" class="form-label">Odbiorca</label>
                        <input id="recipient" class="form-control" type="email" value="<?= htmlspecialchars($recipient_email ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                        <?php if (empty($recipient_email)): ?>
                            <div class="form-text text-danger">Klient nie ma uzupełnionego adresu e-mail do faktur, kontaktu głównego ani handlowego.</div>
                        <?php endif; ?>
                    </div>

                    <div class="col-12">
                        <label for="subject" class="form-label">Temat</label>
                        <input id="subject" class="form-control" readonly>
                    </div>
                    <div class="col-12">
                        <label for="body" class="form-label">Treść</label>
                        <textarea id="body" class="form-control" rows="13" readonly></textarea>
                    </div>
                    <div class="col-12 d-flex justify-content-between align-items-center gap-3">
                        <div>
                            <span class="text-muted small d-block">Załącznik planowany do wysyłki: PDF dokumentu wskazany powyżej.</span>
                            <span id="edit-feedback" class="text-muted small">Treść pochodzi z wybranego szablonu.</span>
                        </div>
                        <div class="d-flex gap-2">
                            <button id="toggle-edit" type="button" class="btn btn-outline-secondary">Włącz edycję</button>
                            <button id="reset-template-content" type="button" class="btn btn-outline-secondary d-none">Przywróć szablon</button>
                            <button id="save-draft" type="button" class="btn btn-outline-primary">Zapisz szkic</button>
                            <button id="queue-email" type="button" class="btn btn-primary">Przekaż do wysyłki</button>
                        </div>
                    </div>
                    <div class="col-12"><div id="send-feedback" class="small" aria-live="polite"></div></div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</section>

<?php $this->view('page_components/footer'); ?>

<script>
const preparedTemplates = <?= json_encode($templates ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const languageSelect = document.getElementById('language-code');
const templateSelect = document.getElementById('template-id');
const templateFeedback = document.getElementById('template-feedback');
const subjectInput = document.getElementById('subject');
const bodyInput = document.getElementById('body');
const editButton = document.getElementById('toggle-edit');
const resetTemplateButton = document.getElementById('reset-template-content');
const editFeedback = document.getElementById('edit-feedback');
const pdfDownloadButton = document.getElementById('download-proforma-pdf');
const documentPdfUrl = <?= json_encode($document['pdf_url'] ?? '', JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const documentNumber = <?= json_encode($document['number'] ?? 'document', JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const saveEndpoint = <?= json_encode($save_endpoint ?? '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const queueSuccessRedirect = <?= json_encode($queue_success_redirect ?? (ROOT . '/proforma-documents'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const queueSuccessRedirectLabel = <?= json_encode($queue_success_redirect_label ?? 'Przejdź do przeglądu proform', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const communicationMessagesUrl = <?= json_encode(ROOT . '/communication/messages', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const recipientInput = document.getElementById('recipient');
const saveDraftButton = document.getElementById('save-draft');
const queueEmailButton = document.getElementById('queue-email');
const sendFeedback = document.getElementById('send-feedback');
let isEditing = false;

function templatesForSelectedLanguage() {
    return preparedTemplates.filter(template => template.language_code === languageSelect.value);
}

function renderSelectedTemplate() {
    const template = templatesForSelectedLanguage().find(item => String(item.id) === templateSelect.value);
    subjectInput.value = template?.subject || '';
    bodyInput.value = template?.body || '';
    templateFeedback.textContent = template?.render_error || '';
    templateFeedback.className = template?.render_error ? 'form-text text-danger' : 'form-text';
}

function setEditing(enabled) {
    isEditing = enabled;
    subjectInput.readOnly = !enabled;
    bodyInput.readOnly = !enabled;
    editButton.textContent = enabled ? 'Zakończ edycję' : 'Włącz edycję';
    resetTemplateButton.classList.toggle('d-none', !enabled);
    editFeedback.textContent = enabled
        ? 'Edytujesz lokalną kopię wiadomości. Zmiany nie modyfikują szablonu.'
        : 'Treść pochodzi z wybranego szablonu.';
    editFeedback.className = enabled ? 'text-warning small' : 'text-muted small';
}

function renderTemplateChoices() {
    const templates = templatesForSelectedLanguage();
    templateSelect.innerHTML = '';

    if (templates.length === 0) {
        templateSelect.disabled = true;
        const option = new Option('Brak aktywnego szablonu dla tego języka', '');
        templateSelect.add(option);
        subjectInput.value = '';
        bodyInput.value = '';
        templateFeedback.textContent = 'Utwórz aktywny szablon tego typu i języka w konfiguracji szablonów e-mail.';
        templateFeedback.className = 'form-text text-warning';
        return;
    }

    templateSelect.disabled = false;
    templates.forEach(template => {
        const label = `${template.name}${Number(template.is_default) === 1 ? ' · domyślny' : ''}`;
        templateSelect.add(new Option(label, template.id));
    });
    renderSelectedTemplate();
}

if (languageSelect && templateSelect) {
    languageSelect.addEventListener('change', () => {
        setEditing(false);
        renderTemplateChoices();
    });
    templateSelect.addEventListener('change', () => {
        setEditing(false);
        renderSelectedTemplate();
    });
    renderTemplateChoices();
}

if (editButton && resetTemplateButton) {
    editButton.addEventListener('click', () => setEditing(!isEditing));
    resetTemplateButton.addEventListener('click', () => {
        renderSelectedTemplate();
        setEditing(false);
    });
}

if (pdfDownloadButton) {
    pdfDownloadButton.addEventListener('click', () => {
        downloadPDF(documentPdfUrl, {}, `DOKUMENT_${documentNumber}.pdf`);
    });
}

async function saveMessage(action) {
    if (!recipientInput.checkValidity()) { recipientInput.reportValidity(); return; }
    if (!templateSelect.value || !subjectInput.value.trim() || !bodyInput.value.trim()) {
        sendFeedback.textContent = 'Wybierz szablon oraz uzupełnij temat i treść.';
        sendFeedback.className = 'small text-danger';
        return;
    }
    const button = action === 'queue' ? queueEmailButton : saveDraftButton;
    const original = button.textContent;
    button.disabled = true;
    sendFeedback.textContent = 'Przygotowywanie wiadomości i załącznika PDF…';
    sendFeedback.className = 'small text-muted';
    try {
        const response = await fetch(saveEndpoint, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json'},
            body: new URLSearchParams({
                action,
                template_id: templateSelect.value,
                recipient_email: recipientInput.value.trim(),
                subject: subjectInput.value,
                body: bodyInput.value,
            }),
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || 'Nie udało się zapisać wiadomości.');
        sendFeedback.textContent = `${data.message} Nr: ${data.id}.`;
        sendFeedback.className = 'small text-success';
        if (action === 'queue') {
            await Swal.fire({
                icon: 'success',
                title: 'E-mail dodany do kolejki',
                html: `Wiadomość zostanie wysłana przez worker SMTP.<br><br>Możesz także wysłać ją natychmiast w <a href="${communicationMessagesUrl}">historii wiadomości</a>.`,
                confirmButtonText: queueSuccessRedirectLabel,
                showCloseButton: true
            });
            window.location.href = queueSuccessRedirect;
        }
    } catch (error) {
        sendFeedback.textContent = error.message;
        sendFeedback.className = 'small text-danger';
    } finally {
        button.disabled = false;
        button.textContent = original;
    }
}

if (saveDraftButton) saveDraftButton.addEventListener('click', () => saveMessage('draft'));
if (queueEmailButton) queueEmailButton.addEventListener('click', () => saveMessage('queue'));
</script>
