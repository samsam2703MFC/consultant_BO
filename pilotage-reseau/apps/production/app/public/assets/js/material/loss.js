// [251] Karta surowca : odpad przy obróbce [%] (obieranie, gniazda, pestki, ości ; 0 = bez odpadu). Pod polem : co
// zostaje, cena jednostki użytecznej i ostrzeżenie, gdy wartość wygląda na część użyteczną (≥ 80 %). Cena : z karty
// (data-loss-price, za 1 kg / 1 l / 1 szt.) albo z kroku Zakup kreatora (cena opakowania ÷ zawartość).
(function () {
    const input = document.querySelector('[data-loss]');
    const out = document.querySelector('[data-loss-out]');
    if (!input || !out) return;
    const nf = (v, d) => Number(v).toLocaleString('pl-PL', {minimumFractionDigits: d, maximumFractionDigits: d}).replace(/ /g, ' ');
    const pctf = (v) => nf(v, Number.isInteger(v) ? 0 : 1);
    const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]));

    // Cena za 1 kg / 1 l / 1 szt. i jej jednostka.
    function price() {
        if (input.dataset.lossPrice) return {value: Number(input.dataset.lossPrice), unit: input.dataset.lossUnit || 'kg'};
        const pack = Number(String(document.getElementById('pack_price')?.value || '').replace(',', '.'));
        const size = Number(String(document.getElementById('package_size_in_base_unit')?.value || '').replace(',', '.'));
        const unitSel = document.getElementById('id_unit');
        const unit = unitSel && unitSel.selectedIndex >= 0 ? unitSel.options[unitSel.selectedIndex].text.trim().toLowerCase() : '';
        if (!(pack > 0) || !(size > 0)) return null;
        const per = pack / size;
        if (unit === 'g') return {value: per * 1000, unit: 'kg'};
        if (unit === 'ml') return {value: per * 1000, unit: 'l'};
        return {value: per, unit: unit || 'jedn.'};
    }

    function render() {
        const raw = String(input.value || '').replace(',', '.').trim();
        const loss = raw === '' ? 0 : Number(raw);
        if (!(loss >= 0 && loss < 100)) {
            out.className = 'form-text text-danger';
            out.textContent = 'Wpisz liczbę od 0 do 99 % (0 = bez odpadu).';
            return;
        }
        const kept = 100 - loss;
        const p = price();
        let html = loss <= 0 ? 'Bez odpadu — do receptury trafia całość.' : `Zostaje <b>${pctf(kept)} %</b>`;
        if (p && loss > 0) html += ` · 1 ${esc(p.unit)} użyteczny = <b>${nf(p.value / (kept / 100), 2)} zł</b> (zakup ${nf(p.value, 2)} zł)`;
        if (loss >= 80) html += `<br><b>Bardzo dużo — sprawdź :</b> wpisuje się odpad, nie część użyteczną (jabłko 35 %, nie 65 %).`;
        out.className = 'form-text' + (loss >= 80 ? ' text-danger' : '');
        out.innerHTML = html;
    }

    ['input', 'change'].forEach((ev) => input.addEventListener(ev, render));
    ['pack_price', 'package_size_in_base_unit', 'id_unit'].forEach((id) => {
        const el = document.getElementById(id);
        if (el) ['input', 'change'].forEach((ev) => el.addEventListener(ev, render));
    });
    render();
})();
