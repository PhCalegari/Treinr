// Cartão quadrado (1080×1080) para compartilhar o treino feito, no estilo do Hevy:
// título, boneco de frente e de costas com os músculos trabalhados, duração, volume e recordes.
// Tudo é desenhado no próprio aparelho (canvas), sem enviar nada ao servidor.
import { BODY_BACK, BODY_FRONT, BODY_OUTLINE } from '@/data/bodyMap';
import type { BodyShape } from '@/data/bodyMap';
import {
    FEMALE_BACK,
    FEMALE_FRONT,
    FEMALE_OUTLINE,
} from '@/data/bodyMapFemale';

export type WorkoutCardData = {
    title: string;
    date: string; // ISO
    durationMin: number | null;
    volumeKg: number;
    sets: number;
    records: number;
    groups: string[];
    secondary: string[];
    sex: string | null;
    author: string;
};

/** Fundo do cartão: escuro (padrão), claro ou transparente (para colar sobre foto no Stories). */
export type CardBackground = 'dark' | 'light' | 'transparent';

const DARK = {
    bg: '#232323',
    text: '#f2f2f2',
    muted: '#a3a39e',
    accent: '#f5c518',
    body: '#454545',
    idle: '#5c5c5c',
    line: '#161616',
    active: '#f5c518',
    secondary: '#9c7f12',
    shadow: false,
};
type Palette = typeof DARK;
const PALETTES: Record<CardBackground, Palette> = {
    dark: DARK,
    light: {
        bg: '#ffffff',
        text: '#1c1c1c',
        muted: '#6b6b66',
        accent: '#b58900',
        body: '#d9d9d5',
        idle: '#c4c4bf',
        line: '#a8a8a3',
        active: '#e0b000',
        secondary: '#f0d77a',
        shadow: false,
    },
    // Sem fundo: textos brancos com sombra para ler sobre qualquer foto.
    transparent: {
        ...DARK,
        bg: '',
        text: '#ffffff',
        muted: '#eeeeee',
        shadow: true,
    },
};

const GROUP_OF: Record<string, string> = {
    chest: 'CHEST',
    trapezius: 'BACK',
    'upper-back': 'BACK',
    'lower-back': 'BACK',
    deltoids: 'SHOULDERS',
    biceps: 'BICEPS',
    triceps: 'TRICEPS',
    forearm: 'FOREARMS',
    abs: 'ABS',
    obliques: 'ABS',
    quadriceps: 'QUADRICEPS',
    adductors: 'QUADRICEPS',
    hamstring: 'HAMSTRINGS',
    gluteal: 'GLUTES',
    calves: 'CALVES',
};
const NOT_MUSCLE = new Set([
    'head',
    'hair',
    'neck',
    'hands',
    'feet',
    'knees',
    'ankles',
]);

/** SVG do corpo com cores fixas (o canvas não enxerga as variáveis CSS). */
export function bodySvg(
    side: 'front' | 'back',
    sex: string | null,
    groups: string[],
    secondary: string[],
    C: Palette = DARK,
): { svg: string; ratio: number } {
    const female = sex === 'female';
    const shapes: BodyShape[] = female
        ? side === 'front'
            ? FEMALE_FRONT
            : FEMALE_BACK
        : side === 'front'
          ? BODY_FRONT
          : BODY_BACK;
    const outline = (female ? FEMALE_OUTLINE : BODY_OUTLINE)[side];
    const vb = female
        ? side === 'front'
            ? '-50 -40 734 1538'
            : '756 0 774 1448'
        : side === 'front'
          ? '0 0 724 1448'
          : '724 0 724 1448';
    const [, , w, h] = vb.split(' ').map(Number);
    const fill = (slug: string) => {
        if (NOT_MUSCLE.has(slug)) return C.body;
        const g = GROUP_OF[slug];
        if (g && groups.includes(g)) return C.active;
        if (g && secondary.includes(g)) return C.secondary;
        return C.idle;
    };
    const parts = shapes
        .map(
            (p) =>
                `<g fill="${fill(p.slug)}">${p.d.map((d) => `<path d="${d}"/>`).join('')}</g>`,
        )
        .join('');
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="${vb}" width="${w}" height="${h}"><path d="${outline}" fill="${C.body}" stroke="${C.line}" stroke-width="3"/>${parts}</svg>`;
    return { svg, ratio: w / h };
}

function loadImage(svg: string): Promise<HTMLImageElement> {
    return new Promise((resolve, reject) => {
        const img = new Image();
        img.onload = () => resolve(img);
        img.onerror = reject;
        img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
    });
}

export function fmtDuration(min: number | null): string {
    if (min === null) return '–';
    const h = Math.floor(min / 60);
    const m = min % 60;
    return h ? `${h}h ${m}min` : `${m}min`;
}
export const fmtKg = (kg: number) =>
    `${kg.toLocaleString('pt-BR', { maximumFractionDigits: 1 })} kg`;

/** Encolhe a fonte até o texto caber na largura. */
function fitText(
    ctx: CanvasRenderingContext2D,
    text: string,
    weight: number,
    size: number,
    family: string,
    maxWidth: number,
) {
    let s = size;
    do {
        ctx.font = `${weight} ${s}px ${family}`;
        s -= 2;
    } while (ctx.measureText(text).width > maxWidth && s > 24);
}

export async function renderWorkoutCard(
    d: WorkoutCardData,
    background: CardBackground = 'dark',
): Promise<Blob> {
    const C = PALETTES[background];
    const display = '"Montserrat", system-ui, sans-serif';
    const sans = '"Montserrat", system-ui, sans-serif';
    try {
        await Promise.all([
            document.fonts.load(`800 80px "Montserrat"`),
            document.fonts.load(`600 40px "Montserrat"`),
        ]);
    } catch {
        // Sem a fonte, usa a do sistema.
    }

    const S = 1080;
    const canvas = document.createElement('canvas');
    canvas.width = S;
    canvas.height = S;
    const ctx = canvas.getContext('2d')!;
    if (C.bg) {
        ctx.fillStyle = C.bg;
        ctx.fillRect(0, 0, S, S);
    }
    if (C.shadow) {
        ctx.shadowColor = 'rgba(0, 0, 0, 0.55)';
        ctx.shadowBlur = 14;
        ctx.shadowOffsetY = 2;
    }
    ctx.textAlign = 'center';
    ctx.textBaseline = 'alphabetic';

    // Título e data
    ctx.fillStyle = C.text;
    fitText(ctx, d.title.toUpperCase(), 700, 84, display, S - 120);
    ctx.fillText(d.title.toUpperCase(), S / 2, 140);
    ctx.fillStyle = C.muted;
    ctx.font = `500 32px ${sans}`;
    const date = new Date(d.date).toLocaleDateString('pt-BR', {
        weekday: 'long',
        day: '2-digit',
        month: 'long',
    });
    ctx.fillText(date.charAt(0).toUpperCase() + date.slice(1), S / 2, 192);

    // Bonecos (frente e costas)
    const bodyH = 520;
    const top = 235;
    const [front, back] = await Promise.all(
        (['front', 'back'] as const).map(async (side) => {
            const { svg, ratio } = bodySvg(
                side,
                d.sex,
                d.groups,
                d.secondary,
                C,
            );
            return { img: await loadImage(svg), w: bodyH * ratio };
        }),
    );
    const gap = 60;
    const total = front.w + back.w + gap;
    const x0 = (S - total) / 2;
    ctx.drawImage(front.img, x0, top, front.w, bodyH);
    ctx.drawImage(back.img, x0 + front.w + gap, top, back.w, bodyH);

    // Números
    const stats: [string, string][] = [
        ['Duração', fmtDuration(d.durationMin)],
        ['Volume', fmtKg(d.volumeKg)],
        d.records > 0
            ? ['Recordes', `${d.records} 🏅`]
            : ['Séries', String(d.sets)],
    ];
    const colW = (S - 120) / 3;
    stats.forEach(([label, value], i) => {
        const cx = 60 + colW * i + colW / 2;
        ctx.fillStyle = C.muted;
        ctx.font = `500 34px ${sans}`;
        ctx.fillText(label, cx, 850);
        ctx.fillStyle = C.text;
        fitText(ctx, value, 700, 60, display, colW - 20);
        ctx.fillText(value, cx, 915);
    });

    // Rodapé: logo + marca e autor
    let brandX = 60;
    try {
        const logo = await loadImage(
            await (await fetch('/favicon.svg')).text(),
        );
        ctx.drawImage(logo, 60, 966, 68, 68);
        brandX = 142;
    } catch {
        // sem o logo, fica só o nome
    }
    ctx.textAlign = 'left';
    ctx.fillStyle = C.accent;
    ctx.font = `800 50px ${display}`;
    ctx.fillText('TREINR', brandX, 1018);
    ctx.textAlign = 'right';
    ctx.fillStyle = C.text;
    ctx.font = `600 34px ${sans}`;
    ctx.fillText(
        d.author ? `@${d.author.toLowerCase()}` : 'treinr.space',
        S - 60,
        1015,
    );

    return new Promise((resolve, reject) =>
        canvas.toBlob(
            (b) => (b ? resolve(b) : reject(new Error('canvas'))),
            'image/png',
        ),
    );
}

/** Compartilha (menu do celular) ou, sem suporte, baixa o arquivo. */
export async function shareOrDownload(
    blob: Blob,
    fileName: string,
    text: string,
): Promise<'shared' | 'downloaded' | 'cancelled'> {
    const file = new File([blob], fileName, { type: blob.type });
    const nav = navigator as Navigator & {
        canShare?: (d: ShareData) => boolean;
    };
    if (nav.share && nav.canShare?.({ files: [file] })) {
        try {
            await nav.share({ files: [file], text });
            return 'shared';
        } catch (e) {
            if ((e as DOMException)?.name === 'AbortError') return 'cancelled';
        }
    }
    downloadBlob(blob, fileName);
    return 'downloaded';
}

export function downloadBlob(blob: Blob, fileName: string) {
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = fileName;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 2000);
}

/** Copia texto; usa o método antigo quando a área de transferência moderna não está liberada. */
export async function copyText(text: string): Promise<boolean> {
    try {
        await navigator.clipboard.writeText(text);
        return true;
    } catch {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        const ok = document.execCommand('copy');
        ta.remove();
        return ok;
    }
}
