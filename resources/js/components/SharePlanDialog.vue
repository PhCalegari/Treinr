<script setup lang="ts">
// Compartilhar o plano de treino (ABC, ABCD...): copiar como texto ou enviar um link.
// Quem abre o link vê o plano e, com conta no Treinr, copia para os próprios treinos.
import { router } from '@inertiajs/vue3';
import { Check, Copy, Link2, Link2Off as LinkOff, Share2 } from '@lucide/vue';
import { ref } from 'vue';
import ShareRow from '@/components/ShareRow.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { copyText } from '@/lib/shareCard';
import { share as planShare, unshare as planUnshare } from '@/routes/workouts';

export type SharePlan = {
    id: string;
    name: string;
    share_url: string | null;
    days: {
        label: string;
        name: string | null;
        exercises: {
            name: string;
            sets: number;
            reps_min: number | null;
            reps_max: number | null;
            kind: string;
            duration_sec: number | null;
            notes: string | null;
        }[];
    }[];
};

const props = defineProps<{ plan: SharePlan }>();
const open = defineModel<boolean>('open', { required: true });

const copied = ref<'' | 'text' | 'link'>('');
const busy = ref(false);
function flash(what: 'text' | 'link') {
    copied.value = what;
    setTimeout(() => (copied.value = ''), 2000);
}

function itemText(e: SharePlan['days'][number]['exercises'][number]): string {
    if (e.kind !== 'strength') {
        const t = e.duration_sec
            ? e.kind === 'cardio'
                ? `${Math.round(e.duration_sec / 60)} min`
                : `${e.duration_sec}s`
            : '';
        return [`${e.sets}×`, t].filter(Boolean).join(' ');
    }
    const reps =
        e.reps_min && e.reps_max
            ? e.reps_min === e.reps_max
                ? `${e.reps_min}`
                : `${e.reps_min}-${e.reps_max}`
            : null;
    return reps ? `${e.sets}×${reps}` : `${e.sets} séries`;
}

const planText = () =>
    [
        `🏋️ ${props.plan.name.toUpperCase()}`,
        ...props.plan.days.flatMap((d) => [
            '',
            `*Treino ${d.label}${d.name ? ` — ${d.name}` : ''}*`,
            ...d.exercises.map(
                (e, i) =>
                    `${i + 1}. ${e.name} — ${itemText(e)}${e.notes ? ` (${e.notes})` : ''}`,
            ),
        ]),
        '',
        props.plan.share_url
            ? `Abra no Treinr: ${props.plan.share_url}`
            : 'Montado no Treinr · treinr.space',
    ].join('\n');

async function copyPlanText() {
    if (await copyText(planText())) flash('text');
}

/** Cria o link (se preciso) e abre o menu de compartilhar do celular; sem menu, copia. */
function shareLink() {
    if (props.plan.share_url) return sendLink(props.plan.share_url);
    busy.value = true;
    router.post(
        planShare(props.plan.id).url,
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () =>
                props.plan.share_url && sendLink(props.plan.share_url),
            onFinish: () => (busy.value = false),
        },
    );
}
async function sendLink(url: string) {
    const nav = navigator as Navigator;
    if (nav.share) {
        try {
            await nav.share({
                title: props.plan.name,
                text: `Meu treino "${props.plan.name}" no Treinr:`,
                url,
            });
            return;
        } catch (e) {
            if ((e as DOMException)?.name === 'AbortError') return;
        }
    }
    if (await copyText(url)) flash('link');
}
function stopSharing() {
    if (
        !confirm(
            'Desativar o link? Quem tiver o endereço não verá mais o treino.',
        )
    )
        return;
    router.delete(planUnshare(props.plan.id).url, {
        preserveScroll: true,
        preserveState: true,
    });
}
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
                    >Mande o plano inteiro para outra pessoa, por link ou
                    texto.</DialogDescription
                >
            </DialogHeader>

            <div class="flex items-center gap-3 p-4">
                <span
                    class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-primary font-display text-xl font-bold text-primary-foreground"
                    >{{ plan.days.map((d) => d.label).join('') }}</span
                >
                <span class="min-w-0">
                    <span class="block truncate font-semibold">{{
                        plan.name
                    }}</span>
                    <span class="block text-sm text-muted-foreground"
                        >{{ plan.days.length }}
                        {{ plan.days.length === 1 ? 'dia' : 'dias' }} ·
                        {{
                            plan.days.reduce(
                                (n, d) => n + d.exercises.length,
                                0,
                            )
                        }}
                        exercícios</span
                    >
                </span>
            </div>

            <p
                class="bg-muted/50 px-4 py-2 text-xs font-medium tracking-wide text-muted-foreground uppercase"
            >
                Link
            </p>
            <div class="divide-y divide-border">
                <ShareRow
                    :icon="
                        copied === 'link'
                            ? Check
                            : plan.share_url
                              ? Share2
                              : Link2
                    "
                    :title="
                        copied === 'link'
                            ? 'Link copiado!'
                            : plan.share_url
                              ? 'Enviar link'
                              : 'Criar link do treino'
                    "
                    description="Quem abrir vê os dias e exercícios e, com conta no Treinr, copia para os treinos dele."
                    :disabled="busy"
                    data-test="share-link"
                    @click="shareLink"
                />
                <ShareRow
                    v-if="plan.share_url"
                    :icon="LinkOff"
                    title="Desativar link"
                    description="O endereço atual para de funcionar."
                    tone="danger"
                    @click="stopSharing"
                />
            </div>
            <p
                v-if="plan.share_url"
                class="px-4 pt-2 font-mono text-xs break-all text-muted-foreground"
                data-test="share-url"
            >
                {{ plan.share_url }}
            </p>

            <p
                class="mt-2 bg-muted/50 px-4 py-2 text-xs font-medium tracking-wide text-muted-foreground uppercase"
            >
                Texto
            </p>
            <div class="divide-y divide-border pb-2">
                <ShareRow
                    :icon="copied === 'text' ? Check : Copy"
                    :title="
                        copied === 'text' ? 'Copiado!' : 'Copiar como texto'
                    "
                    description="Exercícios, séries e repetições para colar no WhatsApp."
                    data-test="copy-plan-text"
                    @click="copyPlanText"
                />
            </div>
        </DialogContent>
    </Dialog>
</template>
