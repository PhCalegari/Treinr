// Quando mostrar o "apoie o desenvolvedor".
// Regra: só para quem já usou o app em 3 dias diferentes, no máximo uma vez a cada 30 dias
// (90 depois de "Já contribuí"). Dentro do dia, espera 5 minutos de uso e um momento calmo
// (Início, Evolução ou Metas), ou o fim de um treino. Nunca durante um treino ou com outra
// janela aberta. Fica guardado neste aparelho.
import { ref } from 'vue';

const KEY = 'treinr:support';
const SINCE_KEY = 'treinr:support:since';
export const MIN_DAYS_USED = 3;
export const EVERY_DAYS = 30;
export const AFTER_CONTRIBUTED_DAYS = 90;
export const MIN_USE_MS = 5 * 60 * 1000;

type State = { days: string[]; nextAt: string | null };

const open = ref(false);

const today = () => new Date().toLocaleDateString('sv-SE'); // AAAA-MM-DD no fuso do aparelho
const addDays = (date: string, n: number) => {
    const d = new Date(`${date}T12:00:00`);
    d.setDate(d.getDate() + n);
    return d.toLocaleDateString('sv-SE');
};

function read(): State {
    try {
        const raw = JSON.parse(localStorage.getItem(KEY) ?? 'null');
        if (raw && Array.isArray(raw.days)) return { days: raw.days.slice(-10), nextAt: raw.nextAt ?? null };
    } catch {
        /* armazenamento indisponível: segue sem lembrar */
    }
    return { days: [], nextAt: null };
}

function write(state: State) {
    try {
        localStorage.setItem(KEY, JSON.stringify(state));
    } catch {
        /* ignora */
    }
}

/** Início do uso nesta aba (para contar os 5 minutos, mesmo trocando de página). */
function usageStart(): number {
    try {
        const saved = Number(sessionStorage.getItem(SINCE_KEY));
        if (saved > 0) return saved;
        const now = Date.now();
        sessionStorage.setItem(SINCE_KEY, String(now));
        return now;
    } catch {
        return Date.now();
    }
}

export function useSupportPrompt() {
    /** Registra o dia de uso e o início do uso nesta aba. */
    function checkIn() {
        const state = read();
        const t = today();
        if (!state.days.includes(t)) {
            state.days.push(t);
            write(state);
        }
        usageStart();
    }

    /** Já pode aparecer (dias de uso e intervalo)? Não considera os 5 minutos. */
    function isDue(): boolean {
        const state = read();
        return state.days.length >= MIN_DAYS_USED && (!state.nextAt || today() >= state.nextAt);
    }

    /** Milissegundos que faltam para completar os 5 minutos de uso. */
    function msUntilReady(): number {
        return Math.max(0, usageStart() + MIN_USE_MS - Date.now());
    }

    function show() {
        open.value = true;
    }

    /** "Agora não" ou fechar: volta a aparecer daqui a 30 dias. */
    function later() {
        open.value = false;
        write({ ...read(), nextAt: addDays(today(), EVERY_DAYS) });
    }

    /** "Já contribuí": some por 90 dias. */
    function contributed() {
        open.value = false;
        write({ ...read(), nextAt: addDays(today(), AFTER_CONTRIBUTED_DAYS) });
    }

    return { open, checkIn, isDue, msUntilReady, show, later, contributed };
}

/** Avisado pela tela do treino quando a pessoa finaliza (momento ideal para o pedido). */
export const WORKOUT_FINISHED_EVENT = 'treinr:workout-finished';
