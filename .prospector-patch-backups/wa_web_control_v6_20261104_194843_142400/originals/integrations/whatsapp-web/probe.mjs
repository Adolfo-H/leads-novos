#!/usr/bin/env node
/**
 * Consulta experimental pelo WhatsApp Web via dispositivo vinculado.
 * NÃO faz envio, NÃO usa API da Meta, NÃO atualiza confirmações humanas.
 * Limitado a 10 números por execução. Não executa periodicamente.
 * CUIDADO: tecnologia de terceiros, sujeita a bloqueio/limitação/alterações.
 */
import { spawn } from 'node:child_process';
import { existsSync } from 'node:fs';
import { mkdir, chmod } from 'node:fs/promises';
import { resolve, join } from 'node:path';

const project = resolve(process.cwd());
const sail = join(project, 'vendor', 'bin', 'sail');
const args = process.argv.slice(2);
const has = (flag) => args.includes(flag);
const value = (key, defaultValue) => {
  const arg = args.find((a) => a.startsWith(`${key}=`));
  return arg === undefined ? defaultValue : arg.slice(key.length + 1);
};
// EC_WA_DUAL_V5: duas contas, sessões privadas independentes e filas disjuntas.
// O modo sem --session continua compatível com o comportamento anterior da V4.
const session = value('--session', 'legacy');
if (!['legacy', '1', '2'].includes(session)) {
  throw new Error('Sessão inválida: utilize --session=1 ou --session=2.');
}
const sessionDir = join(
  project, 'storage', 'app', 'private',
  session === '2' ? 'whatsapp-web-session-v5-2' : 'whatsapp-web-session-v4',
);
const slot = session === '1' ? 0 : session === '2' ? 1 : null;
const limit = Number(value('--limit', '5'));
const dryRun = has('--dry-run');
const includeFixed = has('--include-fixed');

if (!Number.isInteger(limit) || limit < 1 || limit > 10) {
  throw new Error('Limite inválido: use --limit=1 até --limit=10.');
}
if (!existsSync(sail)) {
  throw new Error('Execute na raiz do Prospector (arquivo vendor/bin/sail não encontrado).');
}

function artisan(params, timeoutMs = 60000) {
  return new Promise((resolveOutput, reject) => {
    const proc = spawn(sail, ['artisan', ...params], {
      cwd: project, shell: false,
      stdio: ['ignore', 'pipe', 'pipe'],
      env: { ...process.env, TERM: 'dumb' },
    });
    let stdout = '';
    let stderr = '';
    const timer = setTimeout(() => proc.kill('SIGTERM'), timeoutMs);
    proc.stdout.on('data', (buf) => { stdout += String(buf); });
    proc.stderr.on('data', (buf) => { stderr += String(buf); });
    proc.on('error', (err) => { clearTimeout(timer); reject(err); });
    proc.on('close', (code) => {
      clearTimeout(timer);
      if (code !== 0) {
        reject(new Error(`Falha do Artisan (${code}): ${stderr.slice(-600)} ${stdout.slice(-250)}`));
        return;
      }
      resolveOutput(stdout.trim());
    });
  });
}

function interpretPresence(response) {
  // Resposta AUSENTE / indefinida não prova que não existe conta.
  if (!Array.isArray(response)) return 'inconclusive';
  if (response.some((record) => record?.exists === true)) return 'found';
  if (response.some((record) => record?.exists === false)) return 'not_found';
  return 'inconclusive';
}

function mask(phone) {
  const text = String(phone);
  return text.length > 6 ? `+${text.slice(0, 4)}••••${text.slice(-3)}` : '***';
}

async function connect() {
  await mkdir(sessionDir, { recursive: true, mode: 0o700 });
  await chmod(sessionDir, 0o700);
  const [{ default: makeWASocket, useMultiFileAuthState, DisconnectReason, Browsers },
    { default: qrcode }, { default: pino }] = await Promise.all([
    import('@whiskeysockets/baileys'), import('qrcode-terminal'), import('pino'),
  ]);
  let finished = false;
  let attempts = 0;
  let activeSocket;
  const timeout = setTimeout(() => {
    if (!finished) console.error('QR/WhatsApp Web: limite de 180s atingido. Interrompa e tente outra vez.');
  }, 180000);

  try {
    const socket = await new Promise((res, rej) => {
      const qrTimer = setTimeout(() => rej(new Error('Não conectou em 180 segundos.')), 180000);
      async function start() {
        try {
          const { state, saveCreds } = await useMultiFileAuthState(sessionDir);
          const sock = makeWASocket({
            auth: state, logger: pino({ level: 'silent' }),
            browser: Browsers.ubuntu('ExportControl - Consulta Piloto'),
            syncFullHistory: false,
            markOnlineOnConnect: false,
          });
          activeSocket = sock;
          sock.ev.on('creds.update', saveCreds);
          sock.ev.on('connection.update', ({ connection, qr, lastDisconnect }) => {
            if (finished) return;
            if (qr) {
              console.log('\nEscaneie o QR Code: WhatsApp > Aparelhos conectados > Conectar aparelho.');
              qrcode.generate(qr, { small: true });
            }
            if (connection === 'open') {
              finished = true;
              clearTimeout(qrTimer);
              res(sock);
            }
            if (connection === 'close') {
              const status = lastDisconnect?.error?.output?.statusCode;
              if ([DisconnectReason.restartRequired, DisconnectReason.connectionClosed].includes(status) && attempts < 2) {
                attempts += 1;
                setTimeout(start, 1500);
              } else {
                clearTimeout(qrTimer);
                rej(new Error(`Conexão encerrada antes da consulta (código ${status ?? 'indefinido'}).`));
              }
            }
          });
        } catch (error) {
          clearTimeout(qrTimer);
          rej(error);
        }
      }
      void start();
    });
    return socket;
  } finally {
    clearTimeout(timeout);
  }
}

async function main() {
  console.log('PROSPECTOR | consulta experimental WhatsApp Web | sem envio');
  console.log(session === 'legacy' ? 'Modo legado: fila sem divisão (não execute com as duas sessões em paralelo).' : `Sessão ${session} de 2 | fila ${slot} (divisão por ID) | autenticação independente`);
  console.log(`Lote: até ${limit} número(s). Fonte: índice local. Fixos: ${includeFixed ? 'permitidos' : 'não'}.`);
  const flags = ['contacts:wa-web:candidates', `--limit=${limit}`];
  if (includeFixed) flags.push('--include-fixed');
  if (slot !== null) flags.push(`--slot=${slot}`);
  const output = await artisan(flags);
  const payload = JSON.parse(output.split(/\r?\n/).at(-1));
  const candidates = payload.candidates || [];
  if (candidates.length === 0) {
    console.log('Sem telefones pendentes disponíveis para este lote.');
    return;
  }
  if (dryRun) {
    console.log(`Prévia: ${candidates.length} candidato(s) — nenhum acesso ao WhatsApp.`);
    for (const item of candidates) console.log(`#${item.id} ${mask(item.phone)} (${item.kind})`);
    return;
  }

  console.log('AVISO: biblioteca NÃO OFICIAL. Há risco de bloqueio e resultados inconclusivos.');
  console.log('Nunca conecte a conta principal sem aceitar conscientemente esse risco.');
  console.log('Nenhuma mensagem será enviada. Consultas não alteram as confirmações humanas.');
  let sock;
  try {
    sock = await connect();
    console.log(`WhatsApp Web conectado. Consultando ${candidates.length} número(s).`);
    let checked = 0;
    for (const item of candidates) {
      // Apenas BR E.164 reconhecido pelo índice: +55 DD(D) e número.
      if (!/^55[1-9][0-9]{9,10}$/.test(String(item.phone))) continue;
      let status;
      try {
        const result = await Promise.race([
          sock.onWhatsApp(item.phone),
          new Promise((_, reject) => setTimeout(() => reject(new Error('Consulta excedeu 25 segundos.')), 25000)),
        ]);
        status = interpretPresence(result);
      } catch (_error) {
        status = 'error';
      }
      await artisan(['contacts:wa-web:record', String(item.id), status]);
      checked += 1;
      console.log(`${checked}/${candidates.length}: ${mask(item.phone)} — ${({
        found: 'encontrado (indício)',
        not_found: 'não localizado (indício)',
        inconclusive: 'inconclusivo',
        error: 'falha de consulta',
      })[status]}`);
      if (status === 'error') {
        console.warn('Interrompendo o lote após falha: não transformar erro em negativa.');
        break;
      }
      if (checked < candidates.length) {
        await new Promise((resolveWait) => setTimeout(resolveWait, 15000));
      }
    }
    console.log('Concluído. Consulte /contatos/verificacao-whatsapp para conferir os indícios.');
  } finally {
    if (sock) {
      try { await sock.end(new Error('Encerramento voluntário após lote')); } catch { /* fim normal */ }
    }
  }
}

main().catch((error) => {
  console.error('Não foi possível concluir o piloto:', error.message);
  process.exitCode = 1;
});
