// Utilitários dos gráficos SVG (escalas e marcações de eixo).

/** Passo "redondo" (1, 2, 2,5, 5 × 10^n) para cerca de `count` marcações. */
export function niceStep(range: number, count = 4): number {
    if (range <= 0) return 1;
    const raw = range / count;
    const pow = 10 ** Math.floor(Math.log10(raw));
    const n = raw / pow;
    const step = n <= 1 ? 1 : n <= 2 ? 2 : n <= 2.5 ? 2.5 : n <= 5 ? 5 : 10;
    return step * pow;
}

/** Domínio arredondado e marcações. `zero` força o eixo a começar em 0 (barras). */
export function niceDomain(values: number[], { zero = false, count = 4 } = {}): { min: number; max: number; ticks: number[] } {
    let lo = Math.min(...values);
    let hi = Math.max(...values);
    if (zero) lo = Math.min(0, lo);
    if (lo === hi) {
        const pad = Math.abs(hi) * 0.05 || 1;
        lo = zero ? 0 : lo - pad;
        hi = hi + pad;
    }
    const step = niceStep(hi - lo, count);
    const min = Math.floor(lo / step) * step;
    const max = Math.ceil(hi / step) * step;
    const ticks: number[] = [];
    for (let t = min; t <= max + step / 2; t += step) ticks.push(Math.round(t * 1000) / 1000);
    return { min, max, ticks };
}

export const dayMs = 86_400_000;
export const toTime = (date: string) => new Date(`${date}T12:00:00`).getTime();

export const fmtNumber = (v: number, decimals = 1) =>
    v.toLocaleString('pt-BR', { maximumFractionDigits: decimals, minimumFractionDigits: 0 });

/** Marcação compacta do eixo: 1.500 -> "1,5 mil". */
export const fmtTick = (v: number) => (Math.abs(v) >= 1000 ? `${fmtNumber(v / 1000, 1)} mil` : fmtNumber(v, 1));

export const fmtShortDate = (date: string) =>
    new Date(`${date}T12:00:00`).toLocaleDateString('pt-BR', { day: '2-digit', month: 'short' }).replace('.', '');

export const fmtLongDate = (date: string) =>
    new Date(`${date}T12:00:00`).toLocaleDateString('pt-BR', { weekday: 'short', day: '2-digit', month: 'short', year: 'numeric' });
