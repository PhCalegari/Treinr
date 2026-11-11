// Monta o texto ditado a partir dos resultados do reconhecimento de voz do navegador.
// Remonta tudo a cada evento (não soma), porque no Chrome do Android cada resultado pode vir
// "acumulado" ("arroz", "arroz e feijão", ...) e somar repetiria a frase.

export type SpeechResultLike = { isFinal: boolean; 0: { transcript: string } };

export function buildTranscript(results: ArrayLike<SpeechResultLike>): { finals: string; partial: string } {
    let finals = '';
    let partial = '';
    for (let i = 0; i < results.length; i++) {
        const t = results[i][0].transcript.trim();
        if (!t) continue;
        if (results[i].isFinal) {
            // Se o novo trecho já contém o anterior (Android), substitui; senão (iPhone), junta.
            finals = t.toLowerCase().startsWith(finals.toLowerCase()) ? t : `${finals} ${t}`.trim();
        } else {
            partial = finals && t.toLowerCase().startsWith(finals.toLowerCase()) ? t.slice(finals.length).trim() : t;
        }
    }
    return { finals, partial };
}

/** Junta um trecho ao texto, sem repetir o que já está no fim. */
export function appendOnce(text: string, piece: string): string {
    const p = piece.trim();
    if (!p || text.trim().toLowerCase().endsWith(p.toLowerCase())) return text;
    return [text.trim(), p].filter(Boolean).join(' ');
}
