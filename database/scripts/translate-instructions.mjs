// Traduz para português (Brasil) as instruções de execução da Free Exercise DB.
// Uso (a chave vem do ambiente, nunca do código):
//   GEMINI_API_KEY=... node database/scripts/translate-instructions.mjs
// Gera database/seed-data/free-exercise-db/instructions_pt.json: { "<id>": ["passo 1", ...] }.
// Retoma de onde parou: exercícios já traduzidos no arquivo são pulados.
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const DIR = join(dirname(fileURLToPath(import.meta.url)), '../seed-data/free-exercise-db');
const OUT = join(DIR, 'instructions_pt.json');
const KEY = process.env.GEMINI_API_KEY;
const MODELS = (process.env.GEMINI_MODELS || 'gemini-3.5-flash-lite,gemini-3.5-flash').split(',');
const BATCH = 20;

if (!KEY) {
    console.error('Defina GEMINI_API_KEY.');
    process.exit(1);
}

const exercises = JSON.parse(readFileSync(join(DIR, 'exercises.json'), 'utf8'));
const namesPt = Object.assign({}, ...[1, 2, 3, 4].map((n) => JSON.parse(readFileSync(join(DIR, `names_pt_${n}.json`), 'utf8'))));
const done = existsSync(OUT) ? JSON.parse(readFileSync(OUT, 'utf8')) : {};

const PROMPT = `Traduza para português do Brasil as instruções de execução de exercícios de academia.
Regras:
- Linguagem clara de professor de educação física brasileiro, no imperativo ("Deite-se", "Segure", "Mantenha").
- Termos usuais de academia no Brasil: halteres, barra, anilha, polia/cabo, banco reto/inclinado/declinado, pegada pronada/supinada/neutra,
  pegada aberta/fechada, máquina Smith, repetições, séries, posição inicial, cotovelos, quadril, glúteos, posterior de coxa, panturrilha, core/abdômen.
- "This will be your starting position" = "Esta é a posição inicial". "Repeat for the recommended amount of repetitions" = "Repita pelo número de repetições indicado".
- Use o nome em português dado para cada exercício quando ele for citado.
- Mantenha EXATAMENTE o mesmo número de passos, na mesma ordem, um item por passo. Não acrescente nem remova informação.
- Unidades: converta só o texto; mantenha números como estão.`;

const schema = {
    type: 'ARRAY',
    items: {
        type: 'OBJECT',
        properties: { id: { type: 'STRING' }, steps: { type: 'ARRAY', items: { type: 'STRING' } } },
        required: ['id', 'steps'],
    },
};

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function translate(batch) {
    const input = batch.map((e) => ({ id: e.id, nome_pt: namesPt[e.name] ?? e.name, steps: e.instructions }));
    for (let attempt = 0; attempt < 6; attempt++) {
        const model = MODELS[attempt % MODELS.length];
        const res = await fetch(`https://generativelanguage.googleapis.com/v1beta/models/${model}:generateContent`, {
            method: 'POST',
            headers: { 'x-goog-api-key': KEY, 'Content-Type': 'application/json' },
            body: JSON.stringify({
                systemInstruction: { parts: [{ text: PROMPT }] },
                contents: [{ role: 'user', parts: [{ text: JSON.stringify(input) }] }],
                generationConfig: { temperature: 0.2, responseMimeType: 'application/json', responseSchema: schema },
            }),
        });
        if (res.status === 429 || res.status >= 500) {
            const wait = 5000 * (attempt + 1);
            console.log(`  ${model}: HTTP ${res.status}, tentando de novo em ${wait / 1000}s`);
            await sleep(wait);
            continue;
        }
        const body = await res.json();
        if (!res.ok) throw new Error(`${model}: ${body.error?.message}`);
        const text = (body.candidates?.[0]?.content?.parts ?? []).filter((p) => !p.thought).map((p) => p.text).join('');
        try {
            return JSON.parse(text);
        } catch {
            console.log(`  ${model}: resposta inválida, tentando de novo`);
        }
    }
    throw new Error('Muitas falhas seguidas');
}

const pending = exercises.filter((e) => e.instructions?.length && !done[e.id]);
console.log(`${Object.keys(done).length} já traduzidos, ${pending.length} pendentes.`);

for (let i = 0; i < pending.length; i += BATCH) {
    const batch = pending.slice(i, i + BATCH);
    const result = await translate(batch);
    const byId = new Map(result.map((r) => [r.id, r.steps]));
    let ok = 0;
    for (const e of batch) {
        const steps = byId.get(e.id);
        // Só aceita se o número de passos bater (senão fica para a próxima rodada).
        if (Array.isArray(steps) && steps.length === e.instructions.length && steps.every((s) => typeof s === 'string' && s.trim())) {
            done[e.id] = steps.map((s) => s.trim());
            ok++;
        }
    }
    writeFileSync(OUT, JSON.stringify(done, null, 1));
    console.log(`${Math.min(i + BATCH, pending.length)}/${pending.length} (lote: ${ok}/${batch.length} ok)`);
    await sleep(1500);
}
console.log(`Pronto: ${Object.keys(done).length} exercícios traduzidos em ${OUT}`);
