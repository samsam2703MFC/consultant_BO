/*
 * Kartony i palety [205] : rysunki palety (SVG) wspólne dla katalogu kartonów i kroku « Paleta » karty produktu.
 *  - top(plan) : warstwa z góry (kartony ponumerowane, wymiary palety) ;
 *  - side(plan, layers, opts) : z boku (podstawa, warstwy, linijka, maks. wysokość, piętrowanie).
 * plan = odpowiedź /ajax/pallet/plan albo PalletLayout::plan (rects w mm, base_mm, carton, limits, stack_height_mm).
 */
window.PalletViews = (() => {
    const C = { kraft: '#D2AE80', kraftS: '#8A6740', wood: '#E9D3AA', woodS: '#B48E5C', red: '#C41E3A', prim: '#8D1D2C', ok: '#2E7D4F', muted: '#666' };
    const fmt = (v, d = 0) => Number(v).toLocaleString('pl-PL', { minimumFractionDigits: d, maximumFractionDigits: d });

    function top(plan, width = 460) {
        const PL = plan.pallet_length_mm, PW = plan.pallet_width_mm, pad = 26;
        const o = plan.limits ? plan.limits.overhang_mm : 0;
        const s = (width - pad * 2) / (PL + 2 * o);
        const X = (mm) => pad + (mm + o) * s, Y = (mm) => pad + (mm + o) * s;
        const h = (PW + 2 * o) * s + pad * 2;
        let svg = `<svg viewBox="0 0 ${width} ${h}" width="${width}" height="${h}" role="img" aria-label="Warstwa z góry : ${plan.per_layer} kartonów">`;
        svg += `<rect x="${X(0)}" y="${Y(0)}" width="${PL * s}" height="${PW * s}" rx="3" fill="${C.wood}" stroke="${C.woodS}"/>`;
        for (let k = 0; k < 5; k++) svg += `<rect x="${X(0)}" y="${Y(k * PW / 5) + 3}" width="${PL * s}" height="${PW / 5 * s - 6}" fill="#F1E1C2" opacity=".75"/>`;
        plan.rects.forEach((r, i) => {
            svg += `<rect x="${X(r.x) + 1}" y="${Y(r.y) + 1}" width="${r.w * s - 2}" height="${r.h * s - 2}" rx="3" fill="${C.kraft}" stroke="${C.kraftS}" stroke-width="1.2"/>`;
            if (r.w * s > 18 && r.h * s > 14) svg += `<text x="${X(r.x + r.w / 2)}" y="${Y(r.y + r.h / 2) + 4}" text-anchor="middle" font-size="12" font-weight="700" fill="#5a4026">${i + 1}</text>`;
        });
        svg += `<text x="${X(PL / 2)}" y="${pad - 8}" text-anchor="middle" font-size="12" fill="${C.muted}">${fmt(PL)} mm</text>`;
        svg += `<text x="${pad - 10}" y="${Y(PW / 2)}" text-anchor="middle" font-size="12" fill="${C.muted}" transform="rotate(-90 ${pad - 10} ${Y(PW / 2)})">${fmt(PW)} mm</text>`;
        return svg + '</svg>';
    }

    function side(plan, layers, opts = {}) {
        const PL = plan.pallet_length_mm, base = plan.base_mm, ch = plan.carton.height_mm, maxH = plan.limits.max_height_mm;
        const stackH = opts.stack ? plan.stack_height_mm : null;
        const topMm = base + layers * ch;
        const Hmax = Math.max(maxH, topMm) + 120, width = opts.width || 420, left = 56, right = 150;
        const s = (width - left - right) / PL, H = Hmax * s, top = 14;
        const y = (mm) => top + H - mm * s;
        let svg = `<svg viewBox="0 0 ${width} ${H + top + 22}" width="${width}" height="${H + top + 22}" role="img" aria-label="Paleta z boku : ${layers} warstw, ${fmt(topMm)} mm">`;
        for (let mm = 0; mm <= Hmax; mm += 250) {
            svg += `<line x1="${left - 6}" x2="${left + PL * s + 6}" y1="${y(mm)}" y2="${y(mm)}" stroke="rgba(34,34,34,${mm % 500 ? .05 : .1})"/>`;
            if (mm % 500 === 0) svg += `<text x="${left - 10}" y="${y(mm) + 4}" text-anchor="end" font-size="11" fill="${C.muted}">${fmt(mm / 1000, 1)} m</text>`;
        }
        const W = PL * s;
        svg += `<rect x="${left}" y="${y(base)}" width="${W}" height="${22 * s}" fill="${C.wood}" stroke="${C.woodS}"/>`;
        [0, .5, 1].forEach(f => { svg += `<rect x="${left + f * (W - 145 * s)}" y="${y(base - 22)}" width="${145 * s}" height="${(base - 44) * s}" fill="${C.wood}" stroke="${C.woodS}"/>`; });
        svg += `<rect x="${left}" y="${y(22)}" width="${W}" height="${22 * s}" fill="${C.wood}" stroke="${C.woodS}"/>`;
        const minY = plan.rects.length ? Math.min(...plan.rects.map(r => r.y)) : 0;
        const front = plan.rects.filter(r => Math.abs(r.y - minY) < 1);
        for (let l = 0; l < layers; l++) front.forEach(r => {
            const over = base + (l + 1) * ch > maxH;
            svg += `<rect x="${left + r.x * s + 1}" y="${y(base + (l + 1) * ch) + 1}" width="${r.w * s - 2}" height="${ch * s - 2}" rx="2" fill="${over ? 'rgba(196,30,58,.15)' : C.kraft}" stroke="${over ? C.red : C.kraftS}" ${over ? 'stroke-dasharray="4 3"' : ''}/>`;
        });
        svg += `<line x1="${left + W + 10}" x2="${left + W + 10}" y1="${y(0)}" y2="${y(topMm)}" stroke="${C.prim}" stroke-width="2"/>`;
        svg += `<text x="${left + W + 18}" y="${y(topMm) + 4}" font-size="13" font-weight="700" fill="${C.prim}">${fmt(topMm)} mm</text>`;
        svg += `<line x1="${left - 6}" x2="${left + W + 6}" y1="${y(maxH)}" y2="${y(maxH)}" stroke="${C.red}" stroke-dasharray="6 4" stroke-width="1.5"/>`;
        if (Math.abs(y(maxH) - y(topMm)) > 12) svg += `<text x="${left + W + 18}" y="${y(maxH) + 4}" font-size="11" fill="${C.red}">max ${fmt(maxH)} mm</text>`;
        if (stackH && stackH < maxH) {
            svg += `<line x1="${left - 6}" x2="${left + W + 6}" y1="${y(stackH)}" y2="${y(stackH)}" stroke="${C.ok}" stroke-dasharray="3 4" stroke-width="1.5"/>`;
            if (Math.abs(y(stackH) - y(topMm)) > 12) svg += `<text x="${left + W + 18}" y="${y(stackH) + 4}" font-size="11" fill="${C.ok}">piętrowanie ≤ ${fmt(stackH)}</text>`;
        }
        return svg + '</svg>';
    }

    return { top, side, fmt };
})();
