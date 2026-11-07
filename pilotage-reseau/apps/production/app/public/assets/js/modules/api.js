export async function api(endpoint, { method = 'GET', data = null, headers = {} } = {}) {
    let url = buildUrl(endpoint);

    const opts = { method, headers: { ...headers } };

    // GET → query string
    if (method === 'GET' && data) {
        url += '?' + new URLSearchParams(data);
    }
    // inne metody → JSON body
    else if (data) {
        opts.headers['Content-Type'] = 'application/json';
        opts.body = JSON.stringify(data);
    }

    const res = await fetch(url, opts);

    // spróbuj sparsować JSON,
    // jeśli nie ma body lub to nie jest JSON, payload będzie null
    let payload = null;
    try {
        payload = await res.json();
    } catch (e) {
        // ignore
    }

    // Zwracamy zawsze pełen obiekt
    return {
        ok:      res.ok,
        status:  res.status,
        data:   payload,
        ...payload
    };
}

export async function downloadPDF(endpoint, payload = {}, defaultFilename = "plik.pdf", method = "GET") {
    try {

        let fetchOptions = {
            method: method.toUpperCase(),
            headers: {
                "Content-Type": "application/json",
                "Accept": "application/pdf"
            },
            credentials: "same-origin"
        };

        let url = buildUrl(endpoint);

        if (fetchOptions.method === "GET") {
            const query = new URLSearchParams(payload).toString();
            url += "?" + query;
        } else {
            fetchOptions.body = JSON.stringify(payload);
        }

        const response = await fetch(url, fetchOptions);

        if (!response.ok) {
            let errorMessage = "";

            switch (response.status) {
                case 404:
                    errorMessage = "Nie znaleziono endpointu: " + endpoint;
                    break;
                case 403:
                    errorMessage = "Brak dostępu do żądanego zasobu";
                    break;
                case 500:
                    errorMessage = "Błąd serwera podczas pobierania PDF";
                    break;
                default:
                    errorMessage = `Błąd pobierania PDF: ${response.status} ${response.statusText}`;
                    break;
            }

            const contentType = response.headers.get("Content-Type") || "";
            if (contentType.includes("application/json")) {
                const errorData = await response.json();
                if (errorData.message) {
                    // Jeśli `message` to tablica, to złącz
                    errorMessage = Array.isArray(errorData.message)
                        ? errorData.message.join(", ")
                        : errorData.message;
                }
            }

            throw new Error(errorMessage);
        }

        const blob = await response.blob();

        const disposition = response.headers.get("Content-Disposition") || "";
        const match = /filename="?(.+?)"?(;|$)/.exec(disposition);
        const filename = match ? match[1] : defaultFilename;

        const downloadUrl = URL.createObjectURL(blob);
        const a = document.createElement("a");
        a.href = downloadUrl;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(downloadUrl);

    } catch (err) {
        Swal.fire({
            icon: "error",
            title: "Błąd",
            text: err.message || "Nie udało się pobrać pliku PDF"
        });
    }
}


function ensureTrailingSlash(s) {
    return s.endsWith('/') ? s : s + '/';
}
function ensureLeadingSlash(s) {
    return s.startsWith('/') ? s : '/' + s;
}

function detectBasePath() {
    // 1) Jawne wskazanie pełnego URL (najpewniejsze).
    if (window.API_BASE_URL) {
        try {
            const u = new URL(window.API_BASE_URL, window.location.origin);
            return ensureTrailingSlash(u.origin + ensureLeadingSlash(u.pathname));
        } catch { /* jeśli coś nie tak, lecimy dalej */ }
    }
    // 2) Jawny podkatalog (np. "/minierp/").
    if (window.APP_BASE_PATH) {
        return window.location.origin + ensureTrailingSlash(ensureLeadingSlash(window.APP_BASE_PATH));
    }
    // 3) Heurystyka z pathname: jeśli pierwszy segment to znany katalog (erp/minierp) – użyj go.
    const m = window.location.pathname.match(/^\/(minierp|erp)(?:\/|$)/i);
    if (m) return window.location.origin + `/${m[1].toLowerCase()}/`;

    // 4) Fallback: root (lokalnie "test.localhost/").
    return ensureTrailingSlash(window.location.origin + '/');
}

function buildUrl(endpoint) {
    if (!endpoint) return detectBasePath();
    if (/^https?:\/\//i.test(endpoint)) return endpoint; // już pełny URL

    const base = detectBasePath(); // np. "http://test.localhost/" albo "http://192.168.10.10/minierp/"
    const clean = String(endpoint).replace(/^\/+/, '');
    return base + clean; // -> absolutny URL
}

export async function downloadCSV(endpoint, payload = {}, defaultFilename = "plik.csv", method = "GET") {
    try {
        let fetchOptions = {
            method: method.toUpperCase(),
            headers: {
                "Content-Type": "application/json",
                "Accept": "text/csv"
            },
            credentials: "same-origin"
        };

        let url = buildUrl(endpoint);

        if (fetchOptions.method === "GET") {
            const query = new URLSearchParams(payload).toString();
            url += "?" + query;
        } else {
            fetchOptions.body = JSON.stringify(payload);
        }

        const response = await fetch(url, fetchOptions);

        if (!response.ok) {
            let errorMessage = "";

            switch (response.status) {
                case 404:
                    errorMessage = "ENDPOINT_NOT_FOUND " + endpoint;
                    break;
                case 403:
                    errorMessage = "FORBIDDEN";
                    break;
                case 500:
                    errorMessage = "INTERNAL_SERVER_ERROR";
                    break;
                default:
                    errorMessage = `ERROR: ${response.status} ${response.statusText}`;
                    break;
            }

            const contentType = response.headers.get("Content-Type") || "";
            if (!response.ok || !contentType.includes("text/csv")) {
                let errorMessage = `ERROR: ${response.status} ${response.statusText}`;
                if (contentType.includes("application/json")) {
                    const errorData = await response.json();
                    if (errorData.message) errorMessage = errorData.message;
                }
                throw new Error(errorMessage);
            }



            throw new Error(errorMessage);
        }

        const blob = await response.blob();

        const disposition = response.headers.get("Content-Disposition") || "";
        const match = /filename="?(.+?)"?(;|$)/.exec(disposition);
        const filename = match ? match[1] : defaultFilename;

        const downloadUrl = URL.createObjectURL(blob);
        const a = document.createElement("a");
        a.href = downloadUrl;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(downloadUrl);

    } catch (err) {
        Swal.fire({
            icon: "error",
            title: "Błąd",
            text: err.message || "CSV_DOWNLOAD_ERROR"
        });
    }
}

// function buildUrl(endpoint) {
//     // aktualna baza
//     let base = window.location.origin;
//
//     // sprawdź czy w ścieżce jest "/erp"
//     if (window.location.pathname.includes("/erp")) {
//         base += "/erp/";
//     } else {
//         base += "/";
//     }
//
//     // usuń ewentualny wiodący slash z endpointu, żeby nie było podwójnego
//     endpoint = endpoint.replace(/^\/+/, "");
//
//     return base + endpoint;
// }