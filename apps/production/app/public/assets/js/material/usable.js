// [250] Karta surowca : wykorzystanie [%] (część użyteczna po obróbce). Pod polem : odpad, cena jednostki użytecznej
// i ostrzeżenie, gdy wartość wygląda na wpisany odpad (≤ 20 %). Cena : z karty (data-usable-price, za 1 kg / 1 l /
// 1 szt.) albo z kroku Zakup kreatora (cena opakowania ÷ zawartość).
(function () {
    const input = document.querySelector('[data-usable]');
    const out = document.querySelector('[data-usable-out]');
    if (!input || !out) return;
    const nf = (v, d) => Number(v).toLocaleString('pl-PL', {minimumFractionDigits: d, maximumFractionDigits: d}).replace(/ /g, ' ');
    const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]));

    // Cena za 1 kg / 1 l / 1 szt. i jej jednostka.
    function price() {
        if (input.dataset.usablePrice) return {value: Number(input.dataset.usablePrice), unit: input.dataset.usableUnit || 'kg'};
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
        const pct = raw === '' ? 100 : Number(raw);
        if (!(pct >= 1 && pct <= 100)) {
            out.className = 'form-text text-danger';
            out.textContent = 'Wpisz liczbę od 1 do 100 % (100 = bez odpadu).';
            return;
        }
        const waste = 100 - pct;
        const p = price();
        let html = waste <= 0 ? 'Bez odpadu — do receptury trafia całość.' : `Odpad <b>${nf(waste, 0)} %</b>`;
        if (p && waste > 0) html += ` · 1 ${esc(p.unit)} użyteczny = <b>${nf(p.value / (pct / 100), 2)} zł</b> (zakup ${nf(p.value, 2)} zł)`;
        if (pct <= 20) html += `<br><b>Bardzo mało — sprawdź :</b> wpisuje się część, która trafia do receptury (jogurt = 100 %, nie 1 %).`;
        out.className = 'form-text' + (pct <= 20 ? ' text-danger' : '');
        out.innerHTML = html;
    }

    ['input', 'change'].forEach((ev) => input.addEventListener(ev, render));
    ['pack_price', 'package_size_in_base_unit', 'id_unit'].forEach((id) => {
        const el = document.getElementById(id);
        if (el) ['input', 'change'].forEach((ev) => el.addEventListener(ev, render));
    });
    render();
})();
