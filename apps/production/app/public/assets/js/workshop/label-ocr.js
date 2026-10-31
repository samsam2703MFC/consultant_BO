/*
 * Odczyt etykiety (OCR) wspólny dla tabletów [231] : tablet grupy (partie składników) i tablet pakowania (tryb
 * « Przyjęcia » : partia i termin ważności dostawy). Tesseract.js ładowany przy pierwszym odczycie ; tekst etykiety →
 * kandydaci na numer partii (LOT, L:, BATCH, PARTIA, CHARGE…) i datę przydatności (BBE, EXP, DDM, MHD, najlepiej
 * spożyć przed…). Zdjęcie zmniejszone na tablecie (JPEG) — nic nie jest wysyłane do zewnętrznych usług.
 */
(function () {
    'use strict';
    let worker = null;
    let progressCb = () => {};

    function loadImage(file) {
        if (window.createImageBitmap) return createImageBitmap(file, {imageOrientation: 'from-image'}).catch(() => createImageBitmap(file));
        return new Promise((resolve, reject) => { const img = new Image(); img.onload = () => resolve(img); img.onerror = reject; img.src = URL.createObjectURL(file); });
    }

    /** Zdjęcie → canvas (najwyżej 1600 px) i JPEG (data URL). */
    async function readPhoto(file, max = 1600) {
        const bitmap = await loadImage(file);
        const scale = Math.min(1, max / Math.max(bitmap.width, bitmap.height));
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(bitmap.width * scale);
        canvas.height = Math.round(bitmap.height * scale);
        canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        return {canvas, dataUrl: canvas.toDataURL('image/jpeg', 0.75)};
    }

    async function ocr(canvas, progress) {
        progressCb = progress || (() => {});
        if (!window.Tesseract) {
            await new Promise((resolve, reject) => {
                const s = document.createElement('script');
                s.src = 'https://cdn.jsdelivr.net/npm/tesseract.js@5.1.1/dist/tesseract.min.js';
                s.onload = resolve; s.onerror = reject;
                document.head.appendChild(s);
            });
        }
        if (!worker) worker = await window.Tesseract.createWorker('eng', 1, {logger: (m) => { if (m.status === 'recognizing text') progressCb(Math.round(m.progress * 100)); }});
        const r = await worker.recognize(canvas);
        return (r && r.data && r.data.text) || '';
    }

    // Tekst etykiety → kandydaci na numer partii (LOT, L:, BATCH, PARTIA, CHARGE…) i datę przydatności.
    function lotCandidates(text) {
        const up = String(text || '').toUpperCase().replace(/[|]/g, 'I');
        const lots = [];
        const dates = [];
        const add = (list, v) => { if (v && !list.includes(v)) list.push(v); };
        const re = /(?:N[°ºO]?\s*(?:DE\s*)?LOT[EU]?|\bLOT[EU]?|\bL(?=\s*[:.]\s*\w)|BATCH(?:\s*(?:NO|NR|N°))?|CHARGE(?:N-?NR)?|CH\.?-?B\.?|PARTIA|NR\s*PARTII|NUMER\s*PARTII)\s*[:.#°º\-]*\s*([A-Z0-9][A-Z0-9\-\/.]{1,24})/g;
        let m;
        while ((m = re.exec(up))) add(lots, m[1].replace(/[.\-\/]+$/, ''));
        const pad = (n) => String(n).padStart(2, '0');
        const date = (d, mo, y) => {
            y = Number(y); if (y < 100) y += 2000;
            d = Number(d); mo = Number(mo);
            if (mo < 1 || mo > 12 || d < 1 || d > 31 || y < 2020 || y > 2045) return null;
            return `${y}-${pad(mo)}-${pad(d)}`;
        };
        const keyed = /(?:BBE|BBD|BEST\s*BEFORE|EXP|DDM|DLC|DLUO|MHD|USE\s*BY|NAJLEPIEJ\s*SPO[ZŻ]Y[CĆ]\s*PRZED(?:\s*KO[NŃ]CEM)?|NALE[ZŻ]Y\s*SPO[ZŻ]Y[CĆ]\s*DO|A\s*CONSOMMER)[^0-9]{0,25}(\d{1,2})[.\/\-](\d{1,2})[.\/\-](\d{2,4})/g;
        while ((m = keyed.exec(up))) add(dates, date(m[1], m[2], m[3]));
        const any = /\b(\d{1,2})[.\/\-](\d{1,2})[.\/\-](\d{4}|\d{2})\b/g;
        while ((m = any.exec(up))) add(dates, date(m[1], m[2], m[3]));
        const iso = /\b(20\d{2})[.\/\-](\d{2})[.\/\-](\d{2})\b/g;
        while ((m = iso.exec(up))) add(dates, date(m[3], m[2], m[1]));
        const monthYear = /(?:BBE|BEST\s*BEFORE|EXP|DDM|DLUO|MHD|KO[NŃ]CEM)[^0-9]{0,15}(\d{1,2})[.\/\-](20\d{2})\b/g;
        while ((m = monthYear.exec(up))) { const y = Number(m[2]); const mo = Number(m[1]); if (mo >= 1 && mo <= 12) add(dates, `${y}-${pad(mo)}-${pad(new Date(y, mo, 0).getDate())}`); }
        if (!lots.length) {
            // Bez słowa « LOT » : kody mieszające litery i cyfry (nie daty).
            (up.match(/\b(?=[A-Z0-9\-\/]*\d)(?=[A-Z0-9\-\/]*[A-Z])[A-Z0-9][A-Z0-9\-\/]{4,19}\b/g) || []).filter(v => !/^\d{1,2}[\-\/]\d{1,2}/.test(v)).slice(0, 3).forEach(v => add(lots, v));
        }
        return {lots: lots.filter(Boolean).slice(0, 4), dates: dates.filter(Boolean).slice(0, 3)};
    }

    window.tfbLabelOcr = {ocr, lotCandidates, loadImage, readPhoto};
    window.tfbLotCandidates = lotCandidates;
})();
