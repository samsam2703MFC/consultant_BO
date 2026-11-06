document.addEventListener('DOMContentLoaded', function () {
    const langCode = document.getElementById('langCode')?.value || 'pl';
    const aliasType = document.getElementById('aliasType')?.value || 'default';
    const langSelect = document.getElementById('langSelect');
    const aliasTypeSelect = document.getElementById('aliasTypeSelect');
    const btn = document.getElementById('langLoadBtn');
    const allowedLangs = ['fr', 'en', 'pl', 'nl'];
    const allowedTypes = ['default', 'product_label'];

    // Ustawienie selectów na podstawie URL
    const params = new URLSearchParams(window.location.search);
    const lang = params.get('lang_code');
    const type = params.get('alias_type');
    
    if (lang && allowedLangs.includes(lang)) {
        langSelect.value = lang;
    } else {
        langSelect.value = '-1';
    }

    if (aliasTypeSelect) {
        if (type && allowedTypes.includes(type)) {
            aliasTypeSelect.value = type;
        } else if (aliasType && allowedTypes.includes(aliasType)) {
            aliasTypeSelect.value = aliasType;
        } else {
            aliasTypeSelect.value = '-1';
        }
    }

    // Obsługa kliknięcia "Wczytaj"
    btn.addEventListener('click', function () {
        const langVal = langSelect.value;
        const typeVal = aliasTypeSelect ? aliasTypeSelect.value : 'default';
        
        if (!allowedLangs.includes(langVal)) {
            alert(window.translations?.select_valid_language || 'Please select a valid language');
            return;
        }
        
        if (aliasTypeSelect && !allowedTypes.includes(typeVal)) {
            alert(window.translations?.select_valid_alias_type || 'Please select a valid alias type');
            return;
        }
        
        const url = new URL(window.location.href);
        url.searchParams.set('lang_code', langVal);
        
        // Only set alias_type if the select exists on the page
        if (aliasTypeSelect) {
            url.searchParams.set('alias_type', typeVal);
        }
        
        window.location.href = url.toString();
    });

    // Obsługa edycji aliasów
    document.querySelectorAll('.btn-edit-alias').forEach(editBtn => {
        editBtn.addEventListener('click', function () {
            const row = editBtn.closest('tr');
            row.querySelector('.alias-text').classList.add('d-none');
            row.querySelector('.alias-input').classList.remove('d-none');
            editBtn.classList.add('d-none');
            row.querySelector('.btn-save-alias').classList.remove('d-none');
        });
    });

    document.querySelectorAll('.btn-save-alias').forEach(saveBtn => {
        saveBtn.addEventListener('click', async function () {
            const row = saveBtn.closest('tr');
            const newAlias = row.querySelector('.alias-input').value;
            const idElem = row.getAttribute('data-alias-id_elem');
            const endpoint = window.endpoint;

            const data = {
                id: idElem,
                lang_code: langCode,
                alias: newAlias,
                alias_type: aliasType
            };

            api(endpoint.replace('{id}', idElem), {
                method: 'PATCH',
                data
            })
                .then(data => {
                    showSnack(data.ok, window.translations?.save_was_successful || 'Saved!');
                    row.querySelector('.alias-text').textContent = newAlias;
                    row.querySelector('.alias-text').classList.remove('d-none');
                    row.querySelector('.alias-input').classList.add('d-none');
                    row.querySelector('.btn-edit-alias').classList.remove('d-none');
                    saveBtn.classList.add('d-none');
                })
                .catch(error => {
                    alert(error?.message || window.translations?.error_saving_alias || 'Error saving alias');
                });
        });
    });
});
