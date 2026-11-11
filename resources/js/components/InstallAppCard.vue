<script setup lang="ts">
// Convite para instalar o Treinr no celular (ícone na tela inicial, abre em tela cheia).
// Android/Chrome: botão "Instalar". iPhone: passo a passo do Safari. Some se já estiver instalado ou se fechar.
import { Download, Share, X } from '@lucide/vue';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';

type InstallEvent = Event & {
    prompt: () => Promise<void>;
    userChoice: Promise<{ outcome: string }>;
};

const KEY = 'treinr:install-dismissed';
const visible = ref(false);
const ios = ref(false);
let deferred: InstallEvent | null = null;

const standalone = () =>
    window.matchMedia('(display-mode: standalone)').matches ||
    (navigator as Navigator & { standalone?: boolean }).standalone === true;
function dismissed(): boolean {
    try {
        return localStorage.getItem(KEY) === '1';
    } catch {
        return false;
    }
}
function onPrompt(e: Event) {
    e.preventDefault();
    deferred = e as InstallEvent;
    if (!dismissed() && !standalone()) visible.value = true;
}

onMounted(() => {
    if (standalone() || dismissed()) return;
    window.addEventListener('beforeinstallprompt', onPrompt);
    // iPhone/iPad no Safari: não há botão automático, mostramos como fazer.
    const ua = navigator.userAgent;
    ios.value =
        /iPhone|iPad|iPod/.test(ua) &&
        /Safari/.test(ua) &&
        !/CriOS|FxiOS/.test(ua);
    if (ios.value) visible.value = true;
});
onBeforeUnmount(() =>
    window.removeEventListener('beforeinstallprompt', onPrompt),
);

async function install() {
    if (!deferred) return;
    await deferred.prompt();
    const { outcome } = await deferred.userChoice;
    deferred = null;
    if (outcome === 'accepted') visible.value = false;
}
function close() {
    visible.value = false;
    try {
        localStorage.setItem(KEY, '1');
    } catch {
        // sem armazenamento: só esconde agora
    }
}
</script>

<template>
    <section
        v-if="visible"
        class="relative flex items-start gap-3 rounded-2xl bg-card p-4"
        data-test="install-app"
    >
        <img src="/icon-192.png" alt="" class="size-12 shrink-0 rounded-xl" />
        <div class="min-w-0 flex-1 pr-6">
            <p class="font-semibold">Instale o Treinr no celular</p>
            <p v-if="ios" class="mt-1 text-sm text-muted-foreground">
                No Safari, toque em
                <Share class="inline size-4 align-text-bottom" />
                <strong>Compartilhar</strong> e depois em
                <strong>Adicionar à Tela de Início</strong>.
            </p>
            <template v-else>
                <p class="mt-1 text-sm text-muted-foreground">
                    Abre direto da tela inicial, em tela cheia, como um app.
                </p>
                <Button size="sm" class="mt-2" @click="install"
                    ><Download class="size-4" /> Instalar</Button
                >
            </template>
        </div>
        <button
            type="button"
            class="absolute top-3 right-3 rounded-full p-1 text-muted-foreground hover:text-foreground"
            aria-label="Fechar convite para instalar"
            @click="close"
        >
            <X class="size-4" />
        </button>
    </section>
</template>
