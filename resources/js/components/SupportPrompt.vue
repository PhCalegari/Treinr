<script setup lang="ts">
// Janela "Apoie o Treinr" com QR code e PIX copia e cola.
// Aparece sozinha de vez em quando (regra em useSupportPrompt) ou pelo link do menu.
import { router, usePage } from '@inertiajs/vue3';
import { Check, Copy, Heart } from '@lucide/vue';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { useSupportPrompt, WORKOUT_FINISHED_EVENT } from '@/composables/useSupportPrompt';
import { getJson } from '@/lib/http';
import { pix as supportPix } from '@/routes/support';

const page = usePage();
const { open, checkIn, isDue, msUntilReady, show, later, contributed } = useSupportPrompt();

const data = ref<{ payload: string; name: string; qr_svg: string } | null>(null);
const error = ref('');
const copied = ref(false);

// Telas "calmas", em que a pessoa não está no meio de um registro.
const CALM_PATHS = ['/dashboard', '/progress', '/goals'];
const path = () => new URL(page.url, 'http://x').pathname;

/**
 * Tenta mostrar. "calm": precisa de 5 min de uso e estar numa tela calma.
 * "workout": logo depois de finalizar um treino (o treino já levou bem mais que 5 min).
 */
function tryShow(reason: 'calm' | 'workout') {
    if (open.value || !isDue()) return;
    if (document.querySelector('[role="dialog"]')) return; // outra janela aberta (ex.: adicionar alimento)
    if (reason === 'calm' && (msUntilReady() > 0 || !CALM_PATHS.includes(path()))) return;
    show();
}

const timers: ReturnType<typeof setTimeout>[] = [];
const later_ = (fn: () => void, ms: number) => timers.push(setTimeout(fn, ms));
let stopNavigate: (() => void) | undefined;
const onWorkoutFinished = () => later_(() => tryShow('workout'), 1500);

onMounted(() => {
    if (!page.props.supportEnabled) return;
    checkIn();
    // Ao completar os 5 minutos, se a pessoa estiver numa tela calma.
    later_(() => tryShow('calm'), msUntilReady() + 500);
    // Depois disso, a cada troca de tela (espera a página assentar).
    stopNavigate = router.on('navigate', () => later_(() => tryShow('calm'), 1500));
    window.addEventListener(WORKOUT_FINISHED_EVENT, onWorkoutFinished);
});

onBeforeUnmount(() => {
    timers.forEach(clearTimeout);
    stopNavigate?.();
    window.removeEventListener(WORKOUT_FINISHED_EVENT, onWorkoutFinished);
});

watch(open, async (isOpen) => {
    if (!isOpen || data.value) return;
    try {
        data.value = await getJson(supportPix().url);
    } catch {
        error.value = 'Não foi possível carregar o PIX agora.';
    }
});

async function copy() {
    if (!data.value) return;
    try {
        await navigator.clipboard.writeText(data.value.payload);
    } catch {
        // Navegadores antigos: seleciona o texto para copiar manualmente.
        const el = document.getElementById('pix-payload') as HTMLTextAreaElement | null;
        el?.select();
        document.execCommand?.('copy');
    }
    copied.value = true;
    setTimeout(() => (copied.value = false), 2500);
}
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => !v && later()">
        <DialogContent class="sm:max-w-sm">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2"><Heart class="size-5 fill-primary text-primary" /> Apoie o Treinr</DialogTitle>
                <DialogDescription>
                    O Treinr é feito por um desenvolvedor independente, sem anúncios. Se ele está te ajudando a treinar e comer melhor, um PIX de
                    qualquer valor ajuda a manter o servidor e as novidades.
                </DialogDescription>
            </DialogHeader>

            <div v-if="data" class="flex flex-col items-center gap-3">
                <!-- SVG gerado pelo próprio servidor a partir do código PIX. -->
                <div class="rounded-xl bg-white p-3 [&_svg]:size-52" role="img" :aria-label="`QR code PIX para ${data.name}`" v-html="data.qr_svg"></div>
                <p class="text-sm text-muted-foreground">Para: <span class="font-medium text-foreground">{{ data.name }}</span> · você escolhe o valor</p>
                <textarea
                    id="pix-payload"
                    :value="data.payload"
                    readonly
                    rows="3"
                    class="w-full resize-none rounded-lg border bg-muted/40 p-2 font-mono text-[11px] break-all"
                    aria-label="PIX copia e cola"
                ></textarea>
                <Button class="w-full" @click="copy">
                    <component :is="copied ? Check : Copy" class="size-4" />
                    {{ copied ? 'Copiado!' : 'Copiar PIX copia e cola' }}
                </Button>
            </div>
            <p v-else-if="error" class="text-sm text-muted-foreground">{{ error }}</p>
            <p v-else class="py-8 text-center text-sm text-muted-foreground">Carregando…</p>

            <div class="flex justify-between gap-2">
                <Button variant="ghost" size="sm" @click="later">Agora não</Button>
                <Button variant="ghost" size="sm" @click="contributed">Já contribuí 💛</Button>
            </div>
        </DialogContent>
    </Dialog>
</template>
