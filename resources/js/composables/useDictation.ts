// Ditado pelo microfone, transcrito pelo próprio aparelho (Web Speech API: grátis, sem tokens).
// Ouve até a pessoa tocar para parar. O celular encerra sozinho depois de um silêncio (iPhone e
// Android): quando isso acontece, religa na hora e continua do ponto em que parou, sem repetir.
import { onBeforeUnmount, ref } from 'vue';
import type { Ref } from 'vue';
import { appendOnce, buildTranscript } from '@/lib/speech';
import type { SpeechResultLike } from '@/lib/speech';

type Recognition = {
    lang: string;
    interimResults: boolean;
    continuous: boolean;
    start: () => void;
    stop: () => void;
    onresult:
        | ((e: {
              resultIndex: number;
              results: ArrayLike<SpeechResultLike>;
          }) => void)
        | null;
    onend: (() => void) | null;
    onerror: ((e: { error: string }) => void) | null;
};

/** Limite de segurança de uma gravação (a pessoa pode tocar de novo para continuar). */
const MAX_LISTEN_MS = 3 * 60 * 1000;

const Ctor =
    typeof window === 'undefined'
        ? undefined
        : ((window as unknown as { SpeechRecognition?: new () => Recognition })
              .SpeechRecognition ??
          (
              window as unknown as {
                  webkitSpeechRecognition?: new () => Recognition;
              }
          ).webkitSpeechRecognition);

export function useDictation(
    text: Ref<string>,
    onError: (message: string) => void,
) {
    const canListen = !!Ctor;
    const listening = ref(false);
    const interim = ref('');
    const seconds = ref(0);
    let recognition: Recognition | null = null;
    let wantListening = false;
    let startedAt = 0;
    let clock: ReturnType<typeof setInterval> | undefined;

    function startSession() {
        if (!Ctor) return;
        const rec = new Ctor();
        recognition = rec;
        rec.lang = 'pt-BR';
        rec.interimResults = true;
        rec.continuous = true;
        const base = text.value.trim();
        rec.onresult = (e) => {
            const { finals, partial } = buildTranscript(e.results);
            interim.value = partial;
            text.value = [base, finals].filter(Boolean).join(' ');
        };
        rec.onerror = (e) => {
            if (
                e.error === 'not-allowed' ||
                e.error === 'service-not-allowed'
            ) {
                wantListening = false;
                onError(
                    'Permita o uso do microfone para este site nas configurações do navegador.',
                );
            } else if (e.error === 'audio-capture') {
                wantListening = false;
                onError(
                    'Não foi possível usar o microfone. Você pode digitar.',
                );
            }
            // "no-speech" (silêncio), "aborted" e falhas de rede passageiras: só religa.
        };
        rec.onend = () => {
            // Trecho que ainda não tinha virado definitivo: entra no texto, sem duplicar.
            text.value = appendOnce(text.value, interim.value);
            interim.value = '';
            if (recognition !== rec) return; // sessão antiga
            if (wantListening && Date.now() - startedAt < MAX_LISTEN_MS) {
                try {
                    startSession();
                    return;
                } catch {
                    /* não conseguiu religar: encerra abaixo */
                }
            }
            finish();
        };
        rec.start();
    }

    function finish() {
        wantListening = false;
        listening.value = false;
        clearInterval(clock);
        recognition = null;
    }

    function toggle() {
        if (listening.value) {
            wantListening = false;
            recognition?.stop(); // o onend encerra e guarda o último trecho
            return;
        }
        if (!Ctor) return;
        wantListening = true;
        startedAt = Date.now();
        seconds.value = 0;
        clearInterval(clock);
        clock = setInterval(() => {
            seconds.value = Math.floor((Date.now() - startedAt) / 1000);
            if (Date.now() - startedAt >= MAX_LISTEN_MS) toggle();
        }, 500);
        listening.value = true;
        try {
            startSession();
        } catch {
            finish();
            onError('Não foi possível usar o microfone. Você pode digitar.');
        }
    }

    function stop() {
        if (listening.value) toggle();
    }

    onBeforeUnmount(() => {
        wantListening = false;
        clearInterval(clock);
        recognition?.stop();
    });

    const elapsed = () =>
        `${Math.floor(seconds.value / 60)}:${String(seconds.value % 60).padStart(2, '0')}`;

    return { canListen, listening, interim, seconds, elapsed, toggle, stop };
}
