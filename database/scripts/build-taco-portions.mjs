// Gera as medidas caseiras dos alimentos da TACO a partir da tabela do IBGE (POF 2008-2009).
//
// Uso (na raiz do projeto):  node database/scripts/build-taco-portions.mjs
//
// Entrada: database/seed-data/ibge-medidas/pof_medidas_caseiras.csv  (medidas do IBGE)
//          database/seed-data/ibge-medidas/taco_pof_map.json         (alimento TACO -> código IBGE)
// Saída:   database/seed-data/taco/porcoes.json  { "<id TACO>": [{ "label": "concha", "grams": 140 }, ...] }
//
// Só ficam medidas confiáveis: aquelas em que a descrição da fonte do IBGE cita a própria medida
// (ex.: CONCHA com fonte "Feijão cozido - concha média cheia"). Medidas que o IBGE estimou a partir
// de outra (ex.: ESCUMADEIRA com fonte "colher de arroz cheia") são descartadas.

import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const csvPath = join(root, 'database/seed-data/ibge-medidas/pof_medidas_caseiras.csv');
const mapPath = join(root, 'database/seed-data/ibge-medidas/taco_pof_map.json');
const outPath = join(root, 'database/seed-data/taco/porcoes.json');

const IGNORE = new Set(['GRAMA', 'MILILITRO', 'QUILO', 'LITRO', 'NAO SE APLICA']);

// Nome exibido (com acentos). Medidas não listadas aparecem em minúsculas.
const LABELS = {
    'COLHER DE SOPA': 'colher de sopa',
    'COLHER DE SOBREMESA': 'colher de sobremesa',
    'COLHER DE CHA': 'colher de chá',
    'COLHER DE CAFE': 'colher de café',
    'COLHER DE ARROZ/SERVIR': 'colher de servir',
    'CONCHA': 'concha',
    'ESCUMADEIRA': 'escumadeira',
    'PRATO FUNDO': 'prato fundo',
    'PRATO RASO': 'prato raso',
    'PRATO DE SOBREMESA': 'prato de sobremesa',
    'PORCAO': 'porção',
    'UNIDADE': 'unidade',
    'FATIA': 'fatia',
    'PEDACO': 'pedaço',
    'BIFE': 'bife',
    'FILE': 'filé',
    'POSTA': 'posta',
    'COXA': 'coxa',
    'SOBRECOXA': 'sobrecoxa',
    'ASA': 'asa',
    'PEITO': 'peito',
    'XICARA': 'xícara',
    'XICARA DE CHA': 'xícara de chá',
    'XICARA DE CAFE': 'xícara de café',
    'COPO': 'copo',
    'COPO AMERICANO': 'copo americano',
    'COPO MEDIO': 'copo médio',
    'COPO GRANDE': 'copo grande',
    'COPO PEQUENO': 'copo pequeno',
    'UNIDADE PEQUENA': 'unidade pequena',
    'UNIDADE MEDIA': 'unidade média',
    'UNIDADE GRANDE': 'unidade grande',
    'PUNHADO': 'punhado',
    'COPO DE REQUEIJAO': 'copo de requeijão',
    'CANECA': 'caneca',
    'LATA': 'lata',
    'GARRAFA': 'garrafa',
    'CAIXA': 'caixa',
    'PACOTE': 'pacote',
    'POTE': 'pote',
    'BANDA': 'banda (metade)',
    'METADE': 'metade',
    'GOMO': 'gomo',
    'CACHO': 'cacho',
    'FOLHA': 'folha',
    'RAMO': 'ramo',
    'DENTE': 'dente',
    'ESPIGA': 'espiga',
    'RODELA': 'rodela',
    'TABLETE': 'tablete',
    'BOLA': 'bola',
    'CUBO': 'cubo',
    'PONTA DE FACA': 'ponta de faca',
    'PITADA': 'pitada',
    'PEGADOR': 'pegador',
    'TIGELA': 'tigela',
    'CUMBUCA': 'cumbuca',
    'SACHE': 'sachê',
};

function parseCsv(text) {
    const rows = [];
    let row = [], cur = '', quoted = false;
    for (let i = 0; i < text.length; i++) {
        const c = text[i];
        if (quoted) {
            if (c === '"' && text[i + 1] === '"') { cur += '"'; i++; }
            else if (c === '"') quoted = false;
            else cur += c;
        } else if (c === '"') quoted = true;
        else if (c === ',') { row.push(cur); cur = ''; }
        else if (c === '\n' || c === '\r') {
            if (c === '\r' && text[i + 1] === '\n') i++;
            row.push(cur); rows.push(row); row = []; cur = '';
        } else cur += c;
    }
    if (cur || row.length) { row.push(cur); rows.push(row); }
    const [header, ...data] = rows.filter((r) => r.length > 1);
    return data.map((r) => Object.fromEntries(header.map((h, i) => [h, r[i] ?? ''])));
}

const norm = (s) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/\s+/g, ' ').trim();

/** A fonte do IBGE cita a própria medida? (COLHER DE ARROZ/SERVIR aceita "colher de arroz" ou "colher de servir") */
function isReliable(measure, source) {
    const src = norm(source);
    const m = norm(measure);
    const alternatives = m.includes('/') ? [m.split('/')[0], m.split('/')[0].replace(/\S+$/, m.split('/')[1])] : [m];
    return alternatives.some((alt) => src.includes(alt.trim()));
}

const pof = parseCsv(readFileSync(csvPath, 'utf8'));
const map = JSON.parse(readFileSync(mapPath, 'utf8')).entries;

const byFood = new Map();
for (const r of pof) {
    const key = `${r.codigo_alimento}|${r.codigo_preparacao}`;
    if (!byFood.has(key)) byFood.set(key, []);
    byFood.get(key).push(r);
}

const out = {};
let kept = 0, dropped = 0;
for (const entry of map) {
    const rows = byFood.get(`${entry.pof_code}|${entry.pof_prep}`) ?? [];
    const allowed = new Set(entry.measures ?? []);
    const portions = new Map();
    for (const r of rows) {
        const measure = r.descricao_medida.trim();
        const grams = Number(r.quantidade_g);
        if (IGNORE.has(measure) || !(grams > 0) || (allowed.size && !allowed.has(measure))) continue;
        if (!isReliable(measure, r.descricao_fonte)) { dropped++; continue; }
        const label = LABELS[measure] ?? measure.toLowerCase();
        if (!portions.has(label)) portions.set(label, Math.round(grams * 10) / 10);
    }
    if (portions.size) {
        out[entry.taco_id] = [...portions].map(([label, grams]) => ({ label, grams })).sort((a, b) => a.grams - b.grams);
        kept += portions.size;
    }
}

writeFileSync(outPath, JSON.stringify(out, null, 1) + '\n', 'utf8');
console.log(`OK: ${Object.keys(out).length} alimentos da TACO com medidas caseiras, ${kept} medidas (${dropped} descartadas por não serem confiáveis) -> ${outPath}`);
