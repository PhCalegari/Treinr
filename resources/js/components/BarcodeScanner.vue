<script setup lang="ts">
import { Flashlight, FlashlightOff, SwitchCamera } from '@lucide/vue';
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Lê código de barras pela câmera. A câmera só abre em HTTPS (ou localhost).
 *
 * Para ler bem de perto e sem borrar:
 * - pede resolução alta (Full HD): as barras finas precisam de pixels;
 * - foco automático contínuo quando o aparelho permite, e toque na imagem para focar;
 * - zoom 2× por padrão quando suportado: a câmera principal dos celulares novos não foca
 *   muito de perto; com zoom a pessoa segura a ~20 cm e o código fica grande e nítido;
 * - lanterna e troca de câmera (celulares com várias câmeras traseiras);
 * - leitor nativo do navegador (BarcodeDetector, Android) quando existir; senão ZXing.
 */
const emit = defineEmits<{ detected: [code: string] }>();

type Caps = MediaTrackCapabilities & { zoom?: { min: number; max: number }; torch?: boolean; focusMode?: string[] };
type BarcodeDetectorLike = { detect: (src: CanvasImageSource) => Promise<{ rawValue: string }[]> };

const video = ref<HTMLVideoElement | null>(null);
const error = ref('');
const starting = ref(true);
const zoomLevels = ref<number[]>([]);
const zoom = ref(1);
const torchAvailable = ref(false);
const torchOn = ref(false);
const cameras = ref<MediaDeviceInfo[]>([]);
const cameraIndex = ref(-1);

let stream: MediaStream | null = null;
let track: MediaStreamTrack | null = null;
let stopDecoding: (() => void) | null = null;
let done = false;

const FORMATS = ['ean_13', 'ean_8', 'upc_a', 'upc_e'];

function found(code: string) {
    if (done) return;
    done = true;
    navigator.vibrate?.(80);
    stop();
    emit('detected', code);
}

function stop() {
    stopDecoding?.();
    stopDecoding = null;
    stream?.getTracks().forEach((t) => t.stop());
    stream = null;
    track = null;
}

async function apply(constraints: Record<string, unknown>) {
    try {
        await track?.applyConstraints({ advanced: [constraints as MediaTrackConstraintSet] });
    } catch {
        /* o aparelho não aceita esse ajuste: segue sem ele */
    }
}

async function open(deviceId?: string) {
    stop();
    starting.value = true;
    const video_: MediaTrackConstraints = {
        width: { ideal: 1920 },
        height: { ideal: 1080 },
        ...(deviceId ? { deviceId: { exact: deviceId } } : { facingMode: { ideal: 'environment' } }),
    };
    stream = await navigator.mediaDevices.getUserMedia({ video: video_, audio: false });
    track = stream.getVideoTracks()[0];
    video.value!.srcObject = stream;
    await video.value!.play().catch(() => {});

    const caps = (track.getCapabilities?.() ?? {}) as Caps;
    if (caps.focusMode?.includes('continuous')) await apply({ focusMode: 'continuous' });
    torchAvailable.value = !!caps.torch;
    torchOn.value = false;
    if (caps.zoom && caps.zoom.max > 1) {
        zoomLevels.value = [1, 2, 3].filter((z) => z >= caps.zoom!.min && z <= caps.zoom!.max);
        zoom.value = zoomLevels.value.includes(2) ? 2 : 1;
        await apply({ zoom: zoom.value });
    } else {
        zoomLevels.value = [];
    }

    // Lista as câmeras traseiras (os nomes só aparecem depois da permissão).
    const devices = (await navigator.mediaDevices.enumerateDevices()).filter((d) => d.kind === 'videoinput');
    const back = devices.filter((d) => !/front|frontal|user|facetime/i.test(d.label));
    cameras.value = back.length ? back : devices;
    const current = track.getSettings().deviceId;
    cameraIndex.value = cameras.value.findIndex((d) => d.deviceId === current);

    await startDecoding();
    starting.value = false;
}

async function startDecoding() {
    const Detector = (window as unknown as { BarcodeDetector?: { new (o: { formats: string[] }): BarcodeDetectorLike; getSupportedFormats?: () => Promise<string[]> } })
        .BarcodeDetector;
    const supported = Detector?.getSupportedFormats ? await Detector.getSupportedFormats().catch(() => []) : [];
    if (Detector && FORMATS.some((f) => supported.includes(f))) {
        // Leitor nativo (Android/Chrome): rápido e bom com foco imperfeito.
        const detector = new Detector({ formats: FORMATS.filter((f) => supported.includes(f)) });
        let alive = true;
        const loop = async () => {
            if (!alive || done || !video.value) return;
            if (video.value.readyState >= 2) {
                const codes = await detector.detect(video.value).catch(() => []);
                const code = codes.find((c) => /^\d{8,14}$/.test(c.rawValue));
                if (code) return found(code.rawValue);
            }
            setTimeout(loop, 120);
        };
        loop();
        stopDecoding = () => (alive = false);
        return;
    }

    // ZXing (iPhone e outros), em modo de esforço máximo.
    const { BrowserMultiFormatReader } = await import('@zxing/browser');
    const { BarcodeFormat, DecodeHintType } = await import('@zxing/library');
    const hints = new Map();
    hints.set(DecodeHintType.POSSIBLE_FORMATS, [BarcodeFormat.EAN_13, BarcodeFormat.EAN_8, BarcodeFormat.UPC_A, BarcodeFormat.UPC_E]);
    hints.set(DecodeHintType.TRY_HARDER, true);
    const reader = new BrowserMultiFormatReader(hints, { delayBetweenScanAttempts: 100 });
    const controls = await reader.decodeFromStream(stream!, video.value!, (result) => {
        if (result) found(result.getText());
    });
    stopDecoding = () => controls.stop();
}

async function setZoom(z: number) {
    zoom.value = z;
    await apply({ zoom: z });
}

async function toggleTorch() {
    torchOn.value = !torchOn.value;
    await apply({ torch: torchOn.value });
}

/** Toque na imagem: foca de novo (quando o aparelho permite). */
async function refocus() {
    const caps = (track?.getCapabilities?.() ?? {}) as Caps;
    if (caps.focusMode?.includes('single-shot')) {
        await apply({ focusMode: 'single-shot' });
        if (caps.focusMode.includes('continuous')) setTimeout(() => apply({ focusMode: 'continuous' }), 1200);
    }
}

async function switchCamera() {
    if (cameras.value.length < 2) return;
    const next = cameras.value[(cameraIndex.value + 1) % cameras.value.length];
    try {
        await open(next.deviceId);
    } catch {
        error.value = 'Não foi possível trocar de câmera.';
    }
}

onMounted(async () => {
    if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
        error.value = 'A câmera só funciona com o app em HTTPS. Digite o código abaixo.';
        starting.value = false;
        return;
    }
    try {
        await open();
    } catch (e) {
        error.value =
            e instanceof DOMException && e.name === 'NotAllowedError'
                ? 'Permissão da câmera negada. Libere nas configurações do navegador ou digite o código.'
                : 'Não foi possível abrir a câmera. Digite o código abaixo.';
        starting.value = false;
    }
});

onBeforeUnmount(() => {
    done = true;
    stop();
});
</script>

<template>
    <div>
        <div v-if="!error" class="relative aspect-[4/3] overflow-hidden rounded-lg bg-black">
            <video ref="video" class="size-full object-cover" muted playsinline @click="refocus"></video>
            <!-- Moldura: o código deve ocupar a faixa central -->
            <div class="pointer-events-none absolute inset-x-6 top-1/2 h-28 -translate-y-1/2 rounded-lg border-2 border-primary/90 shadow-[0_0_0_9999px_rgba(0,0,0,0.35)]">
                <div class="absolute inset-x-3 top-1/2 h-0.5 -translate-y-1/2 animate-pulse bg-red-500/80"></div>
            </div>
            <p v-if="starting" class="absolute inset-0 flex items-center justify-center text-sm text-white">Abrindo a câmera…</p>

            <!-- Controles -->
            <div v-else class="absolute inset-x-0 bottom-2 flex items-center justify-center gap-2">
                <div v-if="zoomLevels.length > 1" class="flex rounded-full bg-black/60 p-0.5">
                    <button
                        v-for="z in zoomLevels"
                        :key="z"
                        type="button"
                        class="rounded-full px-2.5 py-1 text-xs font-semibold"
                        :class="zoom === z ? 'bg-primary text-primary-foreground' : 'text-white'"
                        :aria-pressed="zoom === z"
                        @click="setZoom(z)"
                    >
                        {{ z }}×
                    </button>
                </div>
                <button v-if="torchAvailable" type="button" class="rounded-full bg-black/60 p-2 text-white" :aria-label="torchOn ? 'Desligar lanterna' : 'Ligar lanterna'" @click="toggleTorch">
                    <component :is="torchOn ? FlashlightOff : Flashlight" class="size-4" />
                </button>
                <button v-if="cameras.length > 1" type="button" class="rounded-full bg-black/60 p-2 text-white" aria-label="Trocar câmera" @click="switchCamera">
                    <SwitchCamera class="size-4" />
                </button>
            </div>
        </div>
        <p v-else class="rounded-lg bg-muted p-3 text-sm text-muted-foreground">{{ error }}</p>
        <p v-if="!error" class="mt-2 text-xs text-muted-foreground">
            Segure a uns 15–25 cm, com o código dentro da moldura. Se ficar borrado, afaste um pouco
            <template v-if="zoomLevels.length > 1"> ou troque o zoom</template>; toque na imagem para focar.
        </p>
    </div>
</template>
