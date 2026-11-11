<script setup lang="ts">
// Compartilhar o treino feito: imagem no estilo Hevy (pronta para Instagram/WhatsApp),
// distribuição de séries por músculo e o resumo em texto.
import { Check, Copy, Download, Share2 } from '@lucide/vue';
import { onBeforeUnmount, ref, watch } from 'vue';
import ShareRow from '@/components/ShareRow.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    copyText,
    downloadBlob,
    fmtDuration,
    fmtKg,
    renderWorkoutCard,
    shareOrDownload,
} from '@/lib/shareCard';
import type { CardBackground } from '@/lib/shareCard';

export type ShareInfo = {
    author: string;
    sex: string | null;
    groups: string[];
    secondary: string[];
    distribution: { name: string; sets: number }[];
    records: number;
    exercises: { name: string; sets: number }[];
};

const props = defineProps<{
    title: string;
    startedAt: string;
    durationMin: number | null;
    volumeKg: number;
    sets: number;
    share: ShareInfo;
}>();
const open = defineModel<boolean>('open', { required: true });

const blob = ref<Blob | null>(null);
const preview = ref<string | null>(null);
const error = ref('');
const copied = ref(false);
const status = ref('');

// Fundo do cartão (lembrado neste aparelho).
const BACKGROUNDS: { value: CardBackground; label: string }[] = [
    { value: 'light', label: 'Claro' },
    { value: 'dark', label: 'Escuro' },
    { value: 'transparent', label: 'Transparente' },
];
const BG_KEY = 'treinr:share-background';
function savedBackground(): CardBackground {
    try {
        const v = localStorage.getItem(BG_KEY);
        return v === 'light' || v === 'transparent' ? v : 'dark';
    } catch {
        return 'dark';
    }
}
const background = ref<CardBackground>(savedBackground());
function pickBackground(v: CardBackground) {
    if (v === background.value) return;
    background.value = v;
    try {
        localStorage.setItem(BG_KEY, v);
    } catch {
        // sem armazenamento: vale só agora
    }
    void build();
}

const fileName = () => `treinr-${props.startedAt.slice(0, 10)}.png`;

async function build() {
    error.value = '';
    try {
        blob.value = await renderWorkoutCard(
            {
                title: props.title,
                date: props.startedAt,
                durationMin: props.durationMin,
                volumeKg: props.volumeKg,
                sets: props.sets,
                records: props.share.records,
                groups: props.share.groups,
                secondary: props.share.secondary,
                sex: props.share.sex,
                author: props.share.author,
            },
            background.value,
        );
        if (preview.value) URL.revokeObjectURL(preview.value);
        preview.value = URL.createObjectURL(blob.value);
    } catch {
        error.value = 'Não foi possível montar a imagem neste aparelho.';
    }
}
watch(open, (v) => v && !blob.value && build(), { immediate: true });
onBeforeUnmount(() => preview.value && URL.revokeObjectURL(preview.value));

const text = () =>
    [
        `🏋️ ${props.title} — ${new Date(props.startedAt).toLocaleDateString('pt-BR')}`,
        `⏱ ${fmtDuration(props.durationMin)} · ${fmtKg(props.volumeKg)} · ${props.sets} séries${props.share.records ? ` · 🏅 ${props.share.records} recorde(s)` : ''}`,
        '',
        ...props.share.exercises.map(
            (e) =>
                `• ${e.name} — ${e.sets} ${e.sets === 1 ? 'série' : 'séries'}`,
        ),
        '',
        'Feito no Treinr · treinr.space',
    ].join('\n');

async function shareImage() {
    if (!blob.value) return;
    const r = await shareOrDownload(blob.value, fileName(), text());
    status.value = r === 'downloaded' ? 'Imagem salva. Agora é só postar!' : '';
}
function saveImage() {
    if (blob.value) downloadBlob(blob.value, fileName());
}
async function copySummary() {
    copied.value = await copyText(text());
    setTimeout(() => (copied.value = false), 2000);
}

const maxSets = () =>
    Math.max(1, ...props.share.distribution.map((d) => d.sets));
const fmtSets = (n: number) =>
    n.toLocaleString('pt-BR', { maximumFractionDigits: 1 });
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="max-h-[92vh] max-w-[calc(100vw-2rem)] gap-0 overflow-y-auto p-0 sm:max-w-md"
        >
            <DialogHeader class="border-b px-4 py-3.5">
                <DialogTitle class="text-center"
                    >Compartilhar treino</DialogTitle
                >
                <DialogDescription class="sr-only"
                    >Imagem pronta para o Instagram ou WhatsApp, e o resumo em
                    texto.</DialogDescription
                >
            </DialogHeader>

            <div class="p-4">
                <div
                    class="aspect-square w-full overflow-hidden rounded-2xl bg-muted"
                    :class="background === 'transparent' ? 'checker' : ''"
                    data-test="share-preview"
                >
                    <img
                        v-if="preview"
                        :src="preview"
                        alt="Cartão do treino com músculos trabalhados, duração e volume"
                        class="size-full object-contain"
                    />
                    <p
                        v-else-if="error"
                        class="p-6 text-center text-sm text-destructive"
                    >
                        {{ error }}
                    </p>
                    <p
                        v-else
                        class="p-6 text-center text-sm text-muted-foreground"
                    >
                        Montando a imagem…
                    </p>
                </div>
                <div
                    class="mt-3 flex items-center justify-center gap-5"
                    role="radiogroup"
                    aria-label="Fundo da imagem"
                >
                    <button
                        v-for="b in BACKGROUNDS"
                        :key="b.value"
                        type="button"
                        role="radio"
                        :aria-checked="background === b.value"
                        class="flex flex-col items-center gap-1.5 text-xs"
                        :class="
                            background === b.value
                                ? 'font-semibold text-foreground'
                                : 'text-muted-foreground'
                        "
                        :data-test="`share-bg-${b.value}`"
                        @click="pickBackground(b.value)"
                    >
                        <span
                            class="size-11 rounded-full border border-border ring-offset-2 ring-offset-background"
                            :class="[
                                background === b.value
                                    ? 'ring-2 ring-primary'
                                    : '',
                                b.value === 'light'
                                    ? 'bg-white'
                                    : b.value === 'dark'
                                      ? 'bg-[#232323]'
                                      : 'checker',
                            ]"
                        ></span>
                        {{ b.label }}
                    </button>
                </div>
            </div>

            <p
                class="bg-muted/50 px-4 py-2 text-xs font-medium tracking-wide text-muted-foreground uppercase"
            >
                Compartilhar
            </p>
            <div class="divide-y divide-border">
                <ShareRow
                    :icon="Share2"
                    title="Compartilhar imagem"
                    description="Instagram, WhatsApp e outros apps."
                    :disabled="!blob"
                    data-test="share-image"
                    @click="shareImage"
                />
                <ShareRow
                    :icon="Download"
                    title="Salvar imagem"
                    description="Baixa o cartão para postar depois."
                    :disabled="!blob"
                    @click="saveImage"
                />
                <ShareRow
                    :icon="copied ? Check : Copy"
                    :title="copied ? 'Copiado!' : 'Copiar resumo'"
                    description="Exercícios e séries em texto."
                    data-test="copy-summary"
                    @click="copySummary"
                />
            </div>
            <p
                v-if="status"
                class="px-4 pt-2 text-center text-sm text-muted-foreground"
                role="status"
            >
                {{ status }}
            </p>

            <template v-if="share.distribution.length">
                <p
                    class="mt-2 bg-muted/50 px-4 py-2 text-xs font-medium tracking-wide text-muted-foreground uppercase"
                >
                    Séries por músculo
                </p>
                <ul class="flex flex-col gap-2 p-4">
                    <li
                        v-for="d in share.distribution"
                        :key="d.name"
                        class="grid grid-cols-[6.5rem_1fr_2.5rem] items-center gap-2 text-sm"
                    >
                        <span class="truncate">{{ d.name }}</span>
                        <span class="h-3 overflow-hidden rounded-full bg-muted">
                            <span
                                class="block h-full rounded-full bg-primary"
                                :style="{
                                    width: (d.sets / maxSets()) * 100 + '%',
                                }"
                            ></span>
                        </span>
                        <span
                            class="text-right text-muted-foreground tabular-nums"
                            >{{ fmtSets(d.sets) }}</span
                        >
                    </li>
                </ul>
                <p class="px-4 pb-4 text-xs text-muted-foreground">
                    Músculo principal conta 1 série; auxiliar conta meia.
                </p>
            </template>
        </DialogContent>
    </Dialog>
</template>

<style scoped>
/* Xadrez cinza: indica fundo transparente. */
.checker {
    background-color: #3a3a3a;
    background-image:
        linear-gradient(45deg, #4a4a4a 25%, transparent 25%),
        linear-gradient(-45deg, #4a4a4a 25%, transparent 25%),
        linear-gradient(45deg, transparent 75%, #4a4a4a 75%),
        linear-gradient(-45deg, transparent 75%, #4a4a4a 75%);
    background-size: 20px 20px;
    background-position:
        0 0,
        0 10px,
        10px -10px,
        -10px 0;
}
</style>
