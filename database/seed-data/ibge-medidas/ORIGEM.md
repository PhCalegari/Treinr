# Medidas caseiras – IBGE (POF 2008-2009)

**Fonte:** IBGE – Pesquisa de Orçamentos Familiares 2008-2009: *Tabela de Medidas Referidas para os Alimentos
Consumidos no Brasil* (Rio de Janeiro: IBGE, 2011). Dado público; a fonte é citada no app.

**Estes arquivos:**
- `pof_medidas_caseiras.csv`: tabela do IBGE em CSV, processada pelo projeto https://github.com/brolesi/taco
  (MIT; commit b800c910), obtida via https://github.com/ianhudsonai/taco-br-mcp em 2026-11-03.
- `taco_pof_map.json`: ligação curada à mão entre alimentos da TACO e códigos do IBGE (204 alimentos),
  do projeto https://github.com/ianhudsonai/taco-br-mcp (MIT).

**Como o Treinr usa:** `database/scripts/build-taco-portions.mjs` cruza os dois arquivos e gera
`../taco/porcoes.json`, mantendo só medidas confiáveis: aquelas em que a descrição da fonte do IBGE
corresponde à própria medida (algumas medidas do IBGE foram estimadas a partir de outra e são descartadas).
