// Aviso de fim do descanso: bipe curto, vibração e (se o app estiver em segundo plano) notificação.
// O som precisa ser "destravado" num toque do usuário (regra dos navegadores): por isso prepareRestAlert()
// é chamado quando o descanso começa, que é sempre depois de tocar em "concluir série".
let ctx: AudioContext | null = null;

export function prepareRestAlert() {
    try {
        ctx ??= new (
            window.AudioContext ||
            (window as unknown as { webkitAudioContext: typeof AudioContext })
                .webkitAudioContext
        )();
        if (ctx.state === 'suspended') void ctx.resume();
    } catch {
        ctx = null;
    }
    // Pede permissão de notificação uma única vez, no primeiro descanso.
    if ('Notification' in window && Notification.permission === 'default') {
        try {
            void Notification.requestPermission();
        } catch {
            // navegador sem suporte: segue só com som e vibração
        }
    }
}

function beep() {
    if (!ctx) return;
    const t0 = ctx.currentTime;
    // Três bipes curtos e agudos.
    [0, 0.22, 0.44].forEach((offset) => {
        const osc = ctx!.createOscillator();
        const gain = ctx!.createGain();
        osc.type = 'sine';
        osc.frequency.value = 880;
        gain.gain.setValueAtTime(0.0001, t0 + offset);
        gain.gain.exponentialRampToValueAtTime(0.35, t0 + offset + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, t0 + offset + 0.16);
        osc.connect(gain).connect(ctx!.destination);
        osc.start(t0 + offset);
        osc.stop(t0 + offset + 0.18);
    });
}

export async function fireRestAlert(nextExercise: string | null) {
    beep();
    navigator.vibrate?.([200, 100, 200]);
    if (document.visibilityState === 'visible') return;
    if (!('Notification' in window) || Notification.permission !== 'granted')
        return;
    const body = nextExercise
        ? `Próxima série: ${nextExercise}`
        : 'Hora da próxima série.';
    try {
        const reg = await navigator.serviceWorker?.getRegistration();
        if (reg)
            await reg.showNotification('Descanso acabou', {
                body,
                icon: '/icon-192.png',
                tag: 'treinr-rest',
                data: { url: location.pathname },
            });
        else
            new Notification('Descanso acabou', {
                body,
                icon: '/icon-192.png',
                tag: 'treinr-rest',
            });
    } catch {
        // sem notificação: o som e a vibração já avisaram
    }
}
