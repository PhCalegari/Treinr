<script setup lang="ts">
// Mapa muscular (frente e costas): silhueta cheia e músculos acesos na cor de destaque.
// Corpo masculino ou feminino conforme o sexo do perfil (ou o informado em "sex").
// Desenho: react-native-body-highlighter (MIT), em resources/js/data/bodyMap*.ts.
// "crop" dá zoom só na região dos músculos acesos (ex.: cartões "Músculos alvo").
// "intensity" (0 a 1 por grupo) acende mais forte o que foi mais treinado.
import { usePage } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { BODY_BACK, BODY_FRONT, BODY_OUTLINE } from '@/data/bodyMap';
import {
    FEMALE_BACK,
    FEMALE_FRONT,
    FEMALE_OUTLINE,
} from '@/data/bodyMapFemale';

const props = withDefaults(
    defineProps<{
        /** Códigos dos grupos acesos (CHEST, BACK, SHOULDERS, ...). */
        groups: string[];
        /** Grupos secundários (acesos mais fracos). */
        secondary?: string[];
        view?: 'auto' | 'front' | 'back';
        crop?: boolean;
        label?: string;
        /** 'male' | 'female'; sem valor, usa o do usuário logado. */
        sex?: string | null;
        /** Força de cada grupo aceso (0 a 1). */
        intensity?: Record<string, number>;
        /** Cor fixa por grupo (ex.: recuperação), tem prioridade sobre o resto. */
        colors?: Record<string, string>;
        /** Grupo em destaque (contorno), ex.: o tocado na tela de recuperação. */
        highlight?: string | null;
        /** Deixa tocar nos músculos (emite "select" com o código do grupo). */
        interactive?: boolean;
    }>(),
    {
        secondary: () => [],
        view: 'auto',
        crop: false,
        label: '',
        sex: null,
        intensity: () => ({}),
    },
);

/** Partes do desenho -> grupos musculares do Treinr. */
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
/** Partes que não são músculo (pintadas como a silhueta). */
const NOT_MUSCLE = new Set([
    'head',
    'hair',
    'neck',
    'hands',
    'feet',
    'knees',
    'ankles',
]);

const FRONT_ONLY = ['CHEST', 'BICEPS', 'ABS', 'QUADRICEPS'];
const BACK_ONLY = ['BACK', 'TRICEPS', 'GLUTES', 'HAMSTRINGS'];

const page = usePage();
const female = computed(
    () =>
        (props.sex ??
            (page.props.auth as { user?: { sex?: string | null } } | undefined)
                ?.user?.sex) === 'female',
);

const side = computed<'front' | 'back'>(() => {
    if (props.view !== 'auto') return props.view;
    const back = props.groups.filter((g) => BACK_ONLY.includes(g)).length;
    const front = props.groups.filter((g) => FRONT_ONLY.includes(g)).length;
    return back > front ? 'back' : 'front';
});

const FULL = {
    male: { front: '0 0 724 1448', back: '724 0 724 1448' },
    female: { front: '-50 -40 734 1538', back: '756 0 774 1448' },
};
const shapes = computed(() =>
    female.value
        ? side.value === 'front'
            ? FEMALE_FRONT
            : FEMALE_BACK
        : side.value === 'front'
          ? BODY_FRONT
          : BODY_BACK,
);
const outline = computed(
    () => (female.value ? FEMALE_OUTLINE : BODY_OUTLINE)[side.value],
);

const active = computed(() => new Set(props.groups));
const second = computed(
    () => new Set(props.secondary.filter((g) => !props.groups.includes(g))),
);

const emit = defineEmits<{ select: [group: string] }>();

function fillFor(slug: string): string {
    if (NOT_MUSCLE.has(slug)) return 'var(--muscle-body)';
    const group = GROUP_OF[slug];
    if (group && props.colors?.[group]) return props.colors[group];
    if (group && active.value.has(group)) {
        const k = props.intensity[group];
        // Sem intensidade informada: cor cheia. Com intensidade: de 35% a 100% da cor de destaque.
        if (k === undefined) return 'var(--muscle-active)';
        const pct = Math.round(35 + Math.min(1, Math.max(0, k)) * 65);
        return `color-mix(in srgb, var(--muscle-active) ${pct}%, var(--muscle-idle))`;
    }
    if (group && second.value.has(group)) return 'var(--muscle-secondary)';
    return 'var(--muscle-idle)';
}
const isActive = (slug: string) =>
    !!GROUP_OF[slug] && active.value.has(GROUP_OF[slug]);

// Zoom: caixa que envolve os músculos acesos, quadrada e com folga.
const svg = ref<SVGSVGElement | null>(null);
const cropBox = ref<string | null>(null);
async function computeCrop() {
    cropBox.value = null;
    if (!props.crop || !props.groups.length) return;
    await nextTick();
    const els = svg.value
        ? [
              ...svg.value.querySelectorAll<SVGGraphicsElement>(
                  '[data-active="1"]',
              ),
          ]
        : [];
    if (!els.length) return;
    let x1 = Infinity,
        y1 = Infinity,
        x2 = -Infinity,
        y2 = -Infinity;
    for (const el of els) {
        const b = el.getBBox();
        x1 = Math.min(x1, b.x);
        y1 = Math.min(y1, b.y);
        x2 = Math.max(x2, b.x + b.width);
        y2 = Math.max(y2, b.y + b.height);
    }
    const size = Math.max(x2 - x1, y2 - y1) * 1.25 + 40;
    const cx = (x1 + x2) / 2;
    const cy = (y1 + y2) / 2;
    cropBox.value = `${(cx - size / 2).toFixed(0)} ${(cy - size / 2).toFixed(0)} ${size.toFixed(0)} ${size.toFixed(0)}`;
}
onMounted(computeCrop);
watch(
    () => [props.groups.join(), props.crop, side.value, female.value],
    computeCrop,
);

const viewBox = computed(
    () => cropBox.value ?? FULL[female.value ? 'female' : 'male'][side.value],
);
const ariaLabel = computed(() => props.label || 'Músculos trabalhados');
</script>

<template>
    <svg
        ref="svg"
        :viewBox="viewBox"
        role="img"
        :aria-label="ariaLabel"
        preserveAspectRatio="xMidYMid meet"
        class="muscle-map"
    >
        <!-- Silhueta cheia (estilo "boneco"): os vãos entre os músculos ficam na cor do corpo. -->
        <path
            :d="outline"
            fill="var(--muscle-body)"
            stroke="var(--muscle-line)"
            stroke-width="1.5"
            vector-effect="non-scaling-stroke"
        />
        <g
            v-for="part in shapes"
            :key="part.slug"
            :fill="fillFor(part.slug)"
            :stroke="
                highlight && GROUP_OF[part.slug] === highlight
                    ? 'var(--foreground)'
                    : undefined
            "
            :stroke-width="
                highlight && GROUP_OF[part.slug] === highlight ? 6 : undefined
            "
            :class="interactive && GROUP_OF[part.slug] ? 'cursor-pointer' : ''"
            @click="
                interactive &&
                GROUP_OF[part.slug] &&
                emit('select', GROUP_OF[part.slug])
            "
        >
            <path
                v-for="(d, i) in part.d"
                :key="i"
                :d="d"
                :data-active="isActive(part.slug) ? 1 : 0"
            />
        </g>
    </svg>
</template>
