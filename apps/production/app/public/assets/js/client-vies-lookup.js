(() => {
    const form = document.querySelector('[data-vies-lookup-form]');
    if (!form) return;

    const vat = form.querySelector('#tax_number');
    const button = form.querySelector('[data-vies-lookup-button]');
    const status = form.querySelector('[data-vies-lookup-status]');
    const fields = ['tax_number', 'company', 'fac_street', 'fac_street_num', 'fac_zip', 'fac_town'];
    const locked = new Set();

    const setStatus = (message, variant = 'muted') => {
        status.textContent = message;
        status.className = `form-text text-${variant}`;
    };

    const unlock = () => {
        locked.forEach((id) => {
            const field = form.querySelector(`#${id}`);
            if (!field) return;
            field.readOnly = false;
            field.classList.remove('bg-light');
            field.removeAttribute('aria-readonly');
        });
        locked.clear();
    };

    const setField = (id, value) => {
        const field = form.querySelector(`#${id}`);
        if (!field || !value) return;
        field.value = value;
        field.readOnly = true;
        field.classList.add('bg-light');
        field.setAttribute('aria-readonly', 'true');
        locked.add(id);
    };

    vat.addEventListener('input', () => {
        if (locked.size > 0) {
            unlock();
            setStatus('Numer VAT zmieniono — wyszukaj dane ponownie.', 'muted');
        }
    });

    button.addEventListener('click', async () => {
        unlock();
        const rawVat = vat.value.trim();
        if (!rawVat) {
            setStatus('Wprowadź numer VAT.', 'danger');
            vat.focus();
            return;
        }

        button.disabled = true;
        button.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span><span class="visually-hidden">Wyszukiwanie</span>';
        setStatus('Pobieranie danych z VIES…', 'muted');

        try {
            const response = await fetch(form.dataset.viesLookupEndpoint, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
                credentials: 'same-origin',
                body: JSON.stringify({vat: rawVat}),
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok || !payload.ok) {
                setStatus(payload.message || 'Nie udało się pobrać danych z VIES.', 'danger');
                return;
            }

            const data = payload.data;
            vat.value = data.vat;
            if (!data.valid) {
                setStatus('Numer VAT nie jest aktywny w VIES.', 'warning');
                return;
            }

            setField('tax_number', data.vat);
            setField('company', data.company);
            if (data.address_fields) {
                fields.slice(2).forEach((id) => setField(id, data.address_fields[id]));
                setStatus('Dane podstawowe pobrano z VIES i zablokowano do edycji.', 'success');
            } else {
                setStatus('Pobrano VAT i nazwę. VIESAPI.eu nie zwrócił pełnego adresu — uzupełnij go ręcznie.', 'warning');
            }
        } catch (_) {
            setStatus('Nie udało się połączyć z usługą VIES.', 'danger');
        } finally {
            button.disabled = false;
            button.innerHTML = '<i class="bi bi-search" aria-hidden="true"></i><span class="visually-hidden">Pobierz dane z VIES</span>';
        }
    });
})();
