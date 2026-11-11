// Rótulos em português para valores vindos do banco.

export const levelLabels: Record<string, string> = {
    beginner: 'Iniciante',
    intermediate: 'Intermediário',
    advanced: 'Avançado',
};

export const mechanicLabels: Record<string, string> = {
    compound: 'Multiarticular',
    isolation: 'Isolado (monoarticular)',
};

export const forceLabels: Record<string, string> = {
    push: 'Empurrar',
    pull: 'Puxar',
    static: 'Isométrico',
};

/** "3 × 8–12" ou "2 × 30 s" a partir da sugestão do exercício. */
export function formatSuggestion(s: {
    sets: number | null;
    reps_min: number | null;
    reps_max: number | null;
    duration_sec: number | null;
}): string | null {
    if (!s.sets) return null;
    if (s.duration_sec) {
        const d = s.duration_sec >= 60 ? `${Math.round(s.duration_sec / 60)} min` : `${s.duration_sec} s`;
        return `${s.sets} × ${d}`;
    }
    if (s.reps_min && s.reps_max) {
        return s.reps_min === s.reps_max ? `${s.sets} × ${s.reps_min}` : `${s.sets} × ${s.reps_min}–${s.reps_max}`;
    }
    return `${s.sets} séries`;
}

export const goalLabels: Record<string, string> = {
    hypertrophy: 'Hipertrofia',
    weight_loss: 'Emagrecimento',
    maintenance: 'Manutenção',
};

export const goalHints: Record<string, string> = {
    hypertrophy: 'Leve superávit: +10% sobre o gasto (até 400 kcal)',
    weight_loss: 'Déficit moderado: −20% do gasto (até 750 kcal)',
    maintenance: 'Calorias iguais ao gasto estimado',
};

export const formulaLabels: Record<string, string> = {
    mifflin_st_jeor: 'Mifflin-St Jeor',
    katch_mcardle: 'Katch-McArdle (usa o % de gordura)',
};

export const angleLabels: Record<string, string> = {
    front: 'Frente',
    side: 'Lado',
    back: 'Costas',
    other: 'Outra',
};
