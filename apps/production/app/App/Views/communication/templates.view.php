<?php $this->view('page_components/header'); ?>
<?php
$languages = $languages ?? ['fr' => 'Francuski', 'nl' => 'Niderlandzki'];
$templateTypes = $templateTypes ?? [
    'invoice' => 'Faktura',
    'proforma' => 'Proforma',
    'wdt' => 'WDT',
    'delivery_difference' => 'Dokument różnic',
    'payment_reminder' => 'Przypomnienie',
    'payment_reminder_final' => 'Stanowcze przypomnienie',
    'proforma_payment_reminder' => 'Przypomnienie o płatności — proforma',
    'proforma_payment_reminder_final' => 'Stanowcze przypomnienie o płatności — proforma',
];
?>

<style>
    .template-editor {
        min-height: 44px;
        border: 1px solid #dce7f1;
        border-radius: .375rem;
        padding: .65rem .75rem;
        background: #fff;
        white-space: pre-wrap;
    }

    .template-editor:focus {
        border-color: #86b7fe;
        box-shadow: 0 0 0 .25rem rgba(13, 110, 253, .15);
        outline: 0;
    }

    .template-editor-body {
        min-height: 220px;
    }

    .template-token {
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        padding: .1rem .45rem;
        margin: 0 .1rem;
        background: #eef5ff;
        border: 1px solid #b8d7ff;
        color: #0d47a1;
        font-family: monospace;
        font-size: .875em;
        user-select: none;
    }

    .variable-button {
        margin: 0 .35rem .35rem 0;
    }

    .email-preview {
        max-width: 760px;
        margin: 0 auto;
        border: 1px solid #dce7f1;
        border-radius: .5rem;
        background: #fff;
        overflow: hidden;
    }

    .email-preview-header {
        padding: 1rem 1.25rem;
        border-bottom: 1px solid #dce7f1;
        background: #f8fafc;
    }

    .email-preview-body {
        padding: 1.5rem 1.25rem;
        white-space: pre-wrap;
        line-height: 1.6;
    }
</style>

<div class="page-heading">
    <h3>Szablony komunikacji z klientem</h3>
    <p class="text-subtitle text-muted">Szablon jest wzorcem; edycja wiadomości przed wysyłką nie zmienia zapisanego wzorca.</p>
</div>

<section class="section">
    <?php if (($_GET['template_saved'] ?? '') === 'created'): ?>
        <div class="alert alert-success">Szablon został zapisany.</div>
    <?php elseif (($_GET['template_saved'] ?? '') === 'updated'): ?>
        <div class="alert alert-success">Szablon został zaktualizowany.</div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger"><?= implode('<br>', array_map(fn($error) => htmlspecialchars($error, ENT_QUOTES, 'UTF-8'), $errors)) ?></div>
    <?php endif; ?>

    <div class="alert alert-light border">
        Możesz tworzyć wiele szablonów dla tego samego typu dokumentu, ponieważ podczas przygotowania emaila operator będzie mógł wybrać treść najlepiej pasującą do typu klienta, języka i sytuacji. To elastyczny mechanizm wariantów, a nie jedna sztywna wiadomość dla wszystkich.
    </div>

    <div class="card">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <h5 id="form-title" class="mb-1">Nowy szablon</h5>
                    <div id="form-mode" class="text-muted small">Tworzysz nowy wzorzec wiadomości.</div>
                </div>
                <button id="new-template" type="button" class="btn btn-outline-secondary btn-sm">Wyczyść formularz</button>
            </div>

            <form id="template-form" method="post" action="<?= ROOT ?>/communication/templates" class="row g-3">
                <input type="hidden" name="template_id" id="template-id-input">
                <input type="hidden" name="subject_template" id="subject-template-input">
                <input type="hidden" name="body_template" id="body-template-input">

                <div class="col-md-4">
                    <label class="form-label">Typ</label>
                    <select id="template-type" class="form-select" name="template_type">
                        <?php foreach ($templateTypes as $type => $label): ?>
                            <option value="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Język</label>
                    <select id="language-code" class="form-select" name="language_code">
                        <?php foreach ($languages as $code => $label): ?>
                            <option value="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-5">
                    <label class="form-label">Nazwa</label>
                    <input id="template-name" class="form-control" name="name" required>
                </div>

                <div class="col-12">
                    <label class="form-label">Zmienne dostępne dla wybranego typu</label>
                    <div id="variable-palette" class="border rounded p-2 bg-light"></div>
                    <div class="form-text">Kliknięcie zmiennej wstawia ją do aktywnego pola jako nieedytowalny token.</div>
                </div>

                <div class="col-12">
                    <label class="form-label">Temat</label>
                    <div id="subject-editor" class="template-editor" contenteditable="true" data-field="subject"></div>
                </div>

                <div class="col-12">
                    <label class="form-label">Treść</label>
                    <div id="body-editor" class="template-editor template-editor-body" contenteditable="true" data-field="body"></div>
                </div>

                <div class="col-12">
                    <label class="form-check">
                        <input id="is-active" class="form-check-input" type="checkbox" name="is_active" checked> Aktywny
                    </label>
                    <label class="form-check ms-3">
                        <input id="is-default" class="form-check-input" type="checkbox" name="is_default"> Domyślny
                    </label>
                </div>

                <div class="col-12">
                    <button id="preview-current-template" type="button" class="btn btn-outline-primary me-2">Podgląd z przykładowymi danymi</button>
                    <button id="submit-template" class="btn btn-primary">Zapisz nowy szablon</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <table class="table">
                <thead>
                    <tr>
                        <th>Typ</th>
                        <th>Język</th>
                        <th>Nazwa</th>
                        <th>Wersja</th>
                        <th>Status</th>
                        <th>Akcje</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($templates as $template): ?>
                        <tr>
                            <td><?= htmlspecialchars($templateTypes[$template['template_type']] ?? $template['template_type'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($languages[$template['language_code']] ?? $template['language_code'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($template['name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= (int)$template['version'] ?></td>
                            <td><?= $template['is_active'] ? 'aktywny' : 'archiwalny' ?><?= $template['is_default'] ? ' · domyślny' : '' ?></td>
                            <td>
                                <button class="btn btn-outline-secondary btn-sm preview-template" type="button" data-id="<?= (int)$template['id'] ?>">Podgląd</button>
                                <button class="btn btn-outline-primary btn-sm edit-template" type="button" data-id="<?= (int)$template['id'] ?>">Edytuj</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<div class="modal fade" id="template-preview-modal" tabindex="-1" aria-labelledby="template-preview-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title" id="template-preview-modal-title">Podgląd wiadomości</h5>
                    <div id="template-preview-description" class="text-muted small"></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
            </div>
            <div class="modal-body bg-light">
                <div class="email-preview">
                    <div class="email-preview-header">
                        <div class="small text-muted">Od: SL Food</div>
                        <div id="template-preview-to" class="small text-muted">Do:</div>
                        <div class="mt-3 fw-semibold" id="template-preview-subject"></div>
                    </div>
                    <div class="email-preview-body" id="template-preview-body"></div>
                </div>
            </div>
            <div class="modal-footer">
                <span class="me-auto small text-muted">To tylko podgląd — wiadomość nie zostanie wysłana.</span>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Zamknij</button>
            </div>
        </div>
    </div>
</div>

<?php $this->view('page_components/footer'); ?>

<script>
const templates = <?= json_encode($templates ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const rootUrl = <?= json_encode(ROOT, JSON_UNESCAPED_UNICODE) ?>;
const variablesByType = {
    invoice: [
        ['client.company', 'Nazwa klienta'],
        ['client.contact_name', 'Osoba kontaktowa'],
        ['document.number', 'Numer faktury'],
        ['document.date', 'Data faktury'],
        ['amount.value', 'Kwota faktury'],
        ['amount.currency', 'Waluta']
    ],
    proforma: [
        ['client.company', 'Nazwa klienta'],
        ['client.contact_name', 'Osoba kontaktowa'],
        ['document.number', 'Numer proformy'],
        ['document.date', 'Data proformy'],
        ['amount.value', 'Kwota proformy'],
        ['amount.currency', 'Waluta']
    ],
    wdt: [
        ['client.company', 'Nazwa klienta'],
        ['client.contact_name', 'Osoba kontaktowa'],
        ['document.number', 'Numer WDT'],
        ['document.date', 'Data WDT'],
        ['invoice.number', 'Numer faktury'],
        ['invoice.date', 'Data faktury'],
        ['amount.value', 'Kwota'],
        ['amount.currency', 'Waluta']
    ],
    delivery_difference: [
        ['client.company', 'Nazwa klienta'],
        ['client.contact_name', 'Osoba kontaktowa'],
        ['document.number', 'Numer dokumentu różnic'],
        ['document.date', 'Data dokumentu różnic'],
        ['invoice.number', 'Numer faktury'],
        ['invoice.date', 'Data faktury'],
        ['difference.amount', 'Kwota różnicy'],
        ['difference.currency', 'Waluta'],
        ['difference.type', 'Typ różnicy']
    ],
    payment_reminder: [
        ['client.company', 'Nazwa klienta'],
        ['client.contact_name', 'Osoba kontaktowa'],
        ['invoice.number', 'Numer faktury'],
        ['invoice.date', 'Data faktury'],
        ['difference.amount', 'Kwota różnicy'],
        ['difference.currency', 'Waluta'],
        ['difference.type', 'Typ różnicy']
    ],
    payment_reminder_final: [
        ['client.company', 'Nazwa klienta'],
        ['client.contact_name', 'Osoba kontaktowa'],
        ['invoice.number', 'Numer faktury'],
        ['invoice.date', 'Data faktury'],
        ['difference.amount', 'Kwota różnicy'],
        ['difference.currency', 'Waluta'],
        ['difference.type', 'Typ różnicy']
    ],
    proforma_payment_reminder: [
        ['client.company', 'Nazwa klienta'],
        ['client.contact_name', 'Osoba kontaktowa'],
        ['proforma.number', 'Numer proformy'],
        ['proforma.date', 'Data proformy'],
        ['proforma.amount', 'Kwota proformy'],
        ['proforma.currency', 'Waluta'],
        ['company.name', 'Nazwa firmy'],
        ['today.date', 'Data dzisiejsza']
    ],
    proforma_payment_reminder_final: [
        ['client.company', 'Nazwa klienta'],
        ['client.contact_name', 'Osoba kontaktowa'],
        ['proforma.number', 'Numer proformy'],
        ['proforma.date', 'Data proformy'],
        ['proforma.amount', 'Kwota proformy'],
        ['proforma.currency', 'Waluta'],
        ['company.name', 'Nazwa firmy'],
        ['today.date', 'Data dzisiejsza']
    ]
};

const form = document.getElementById('template-form');
const templateIdInput = document.getElementById('template-id-input');
const typeSelect = document.getElementById('template-type');
const languageSelect = document.getElementById('language-code');
const nameInput = document.getElementById('template-name');
const subjectEditor = document.getElementById('subject-editor');
const bodyEditor = document.getElementById('body-editor');
const subjectInput = document.getElementById('subject-template-input');
const bodyInput = document.getElementById('body-template-input');
const activeInput = document.getElementById('is-active');
const defaultInput = document.getElementById('is-default');
const palette = document.getElementById('variable-palette');
const formTitle = document.getElementById('form-title');
const formMode = document.getElementById('form-mode');
const submitButton = document.getElementById('submit-template');
const previewCurrentButton = document.getElementById('preview-current-template');
const previewModalElement = document.getElementById('template-preview-modal');
const previewModal = new bootstrap.Modal(previewModalElement);
const previewTitle = document.getElementById('template-preview-modal-title');
const previewDescription = document.getElementById('template-preview-description');
const previewTo = document.getElementById('template-preview-to');
const previewSubject = document.getElementById('template-preview-subject');
const previewBody = document.getElementById('template-preview-body');
let activeEditor = bodyEditor;
let savedRange = null;

function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, char => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    })[char]);
}

function tokenHtml(token) {
    return `<span class="template-token" contenteditable="false" data-token="${escapeHtml(token)}">{{ ${escapeHtml(token)} }}</span>`;
}

function templateToEditorHtml(value) {
    return escapeHtml(value || '')
        .replace(/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/g, (match, token) => tokenHtml(token))
        .replace(/\n/g, '<br>');
}

function editorToTemplate(editor) {
    return editor.innerText.replace(/\u00a0/g, ' ').trim();
}

function previewDataFor(type) {
    const data = {
        client: {
            company: 'Maison Vandoorne BV',
            contact_name: 'Claire Dubois'
        },
        document: {
            number: 'FV/2026/00428',
            date: '23.07.2026'
        },
        invoice: {
            number: 'FV/2026/00428',
            date: '23.07.2026'
        },
        amount: {
            value: '1 248,50',
            currency: 'EUR'
        },
        difference: {
            amount: '186,40',
            currency: 'EUR',
            type: 'brakująca dostawa'
        },
        company: {
            name: 'ATELIER WRO01 SP. Z O.O.'
        },
        today: {
            date: '28.07.2026'
        }
    };

    if (type === 'proforma' || type === 'proforma_payment_reminder' || type === 'proforma_payment_reminder_final') {
        data.document.number = 'PF/2026/00117';
        data.document.date = '22.07.2026';
        data.amount.value = '2 075,00';
        data.proforma = {number: 'PF/2026/00117', date: '22.07.2026', amount: '2 075,00', currency: 'EUR'};
    } else if (type === 'wdt') {
        data.document.number = 'WDT/2026/00091';
        data.document.date = '21.07.2026';
        data.invoice.number = 'FV/2026/00415';
        data.invoice.date = '20.07.2026';
        data.amount.value = '3 490,00';
    } else if (type === 'delivery_difference') {
        data.document.number = 'RD/2026/00036';
        data.document.date = '22.07.2026';
    }

    return data;
}

function valueAtPath(data, path) {
    return path.split('.').reduce((value, key) => value && value[key], data);
}

function renderPreviewTemplate(template, data) {
    return String(template || '').replace(/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/g, (match, path) => {
        const value = valueAtPath(data, path);
        return value === undefined || value === null || value === '' ? `[brak danych: ${path}]` : value;
    });
}

function showPreview(template) {
    const data = previewDataFor(template.template_type);
    const typeLabel = typeSelect.options[typeSelect.selectedIndex]?.text || template.template_type;
    const languageLabel = languageSelect.options[languageSelect.selectedIndex]?.text || template.language_code;
    const subject = renderPreviewTemplate(template.subject_template, data);
    const body = renderPreviewTemplate(template.body_template, data);

    previewTitle.textContent = `Podgląd wiadomości: ${template.name || 'bez nazwy'}`;
    previewDescription.textContent = `${typeLabel} · ${languageLabel} · przykładowe dane`;
    previewTo.textContent = `Do: ${data.client.contact_name} (${data.client.company})`;
    previewSubject.textContent = `Temat: ${subject || '(brak tematu)'}`;
    previewBody.textContent = body || '(brak treści)';
    previewModal.show();
}

function rememberSelection() {
    const selection = window.getSelection();
    if (!selection || selection.rangeCount === 0) return;
    const range = selection.getRangeAt(0);
    if (activeEditor.contains(range.commonAncestorContainer)) savedRange = range.cloneRange();
}

function focusEditor(editor) {
    activeEditor = editor;
    editor.focus();
    rememberSelection();
}

function placeCaretAtEnd(editor) {
    const range = document.createRange();
    range.selectNodeContents(editor);
    range.collapse(false);
    const selection = window.getSelection();
    selection.removeAllRanges();
    selection.addRange(range);
    savedRange = range.cloneRange();
}

function insertToken(token) {
    const editor = activeEditor || bodyEditor;
    editor.focus();

    const selection = window.getSelection();
    if (savedRange && editor.contains(savedRange.commonAncestorContainer)) {
        selection.removeAllRanges();
        selection.addRange(savedRange);
    } else {
        placeCaretAtEnd(editor);
    }

    const range = selection.getRangeAt(0);
    const wrapper = document.createElement('span');
    wrapper.innerHTML = tokenHtml(token) + ' ';
    const fragment = document.createDocumentFragment();
    let lastNode = null;
    while (wrapper.firstChild) fragment.appendChild(wrapper.firstChild);
    lastNode = fragment.lastChild;
    range.deleteContents();
    range.insertNode(fragment);
    if (lastNode) {
        const nextRange = document.createRange();
        nextRange.setStartAfter(lastNode);
        nextRange.collapse(true);
        selection.removeAllRanges();
        selection.addRange(nextRange);
        savedRange = nextRange.cloneRange();
    }
}

function renderPalette() {
    const variables = variablesByType[typeSelect.value] || [];
    palette.innerHTML = '';
    variables.forEach(([token, label]) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-outline-secondary btn-sm variable-button';
        button.textContent = `${label} {{ ${token} }}`;
        button.addEventListener('click', () => insertToken(token));
        palette.appendChild(button);
    });
}

function resetForm() {
    form.action = `${rootUrl}/communication/templates`;
    templateIdInput.value = '';
    formTitle.textContent = 'Nowy szablon';
    formMode.textContent = 'Tworzysz nowy wzorzec wiadomości.';
    submitButton.textContent = 'Zapisz nowy szablon';
    typeSelect.value = 'invoice';
    languageSelect.value = 'fr';
    nameInput.value = '';
    subjectEditor.innerHTML = '';
    bodyEditor.innerHTML = '';
    activeInput.checked = true;
    defaultInput.checked = false;
    activeEditor = bodyEditor;
    savedRange = null;
    renderPalette();
}

function loadTemplate(id) {
    const template = templates.find(item => Number(item.id) === Number(id));
    if (!template) return;

    form.action = `${rootUrl}/communication/templates`;
    templateIdInput.value = template.id;
    formTitle.textContent = 'Edycja szablonu';
    formMode.textContent = `Nadpisujesz zapisany szablon: ${template.name}`;
    submitButton.textContent = 'Zapisz zmiany';
    typeSelect.value = template.template_type;
    languageSelect.value = template.language_code;
    nameInput.value = template.name || '';
    subjectEditor.innerHTML = templateToEditorHtml(template.subject_template || '');
    bodyEditor.innerHTML = templateToEditorHtml(template.body_template || '');
    activeInput.checked = Number(template.is_active) === 1;
    defaultInput.checked = Number(template.is_default) === 1;
    activeEditor = bodyEditor;
    savedRange = null;
    renderPalette();
    form.scrollIntoView({behavior: 'smooth', block: 'start'});
}

[subjectEditor, bodyEditor].forEach(editor => {
    editor.addEventListener('focus', () => focusEditor(editor));
    editor.addEventListener('keyup', rememberSelection);
    editor.addEventListener('mouseup', rememberSelection);
});

typeSelect.addEventListener('change', renderPalette);
document.getElementById('new-template').addEventListener('click', resetForm);
document.querySelectorAll('.edit-template').forEach(button => button.addEventListener('click', () => loadTemplate(button.dataset.id)));
document.querySelectorAll('.preview-template').forEach(button => button.addEventListener('click', () => {
    const template = templates.find(item => Number(item.id) === Number(button.dataset.id));
    if (!template) return;

    const previousType = typeSelect.value;
    const previousLanguage = languageSelect.value;
    typeSelect.value = template.template_type;
    languageSelect.value = template.language_code;
    showPreview(template);
    typeSelect.value = previousType;
    languageSelect.value = previousLanguage;
}));

previewCurrentButton.addEventListener('click', () => showPreview({
    name: nameInput.value || 'niezapisany szablon',
    template_type: typeSelect.value,
    language_code: languageSelect.value,
    subject_template: editorToTemplate(subjectEditor),
    body_template: editorToTemplate(bodyEditor)
}));

form.addEventListener('submit', event => {
    subjectInput.value = editorToTemplate(subjectEditor);
    bodyInput.value = editorToTemplate(bodyEditor);

    if (!subjectInput.value || !bodyInput.value) {
        event.preventDefault();
        alert('Temat i treść szablonu są wymagane.');
    }
});

renderPalette();
</script>
