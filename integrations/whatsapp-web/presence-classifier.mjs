/**
 * Resultado técnico do onWhatsApp() (Baileys):
 * - []: nenhum cadastro positivo foi retornado; não comprova ausência;
 * - undefined/null: não houve resposta utilizável, continua inconclusivo;
 * - {exists:false}: ausência sinalizada explicitamente;
 * - erro/timeout: tratado pelo chamador como erro, nunca como ausência.
 * O resultado negativo é um INDÍCIO do provedor, não confirmação humana.
 */
export function classifyPresence(response) {
  if (!Array.isArray(response)) return 'inconclusive';
  if (response.some((entry) => entry?.exists === true)) return 'found';
  if (response.some((entry) => entry?.exists === false)) return 'not_found';
  return 'inconclusive';
}
