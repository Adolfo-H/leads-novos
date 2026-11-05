#!/usr/bin/env node
/**
 * Agente Ubuntu somente leitura (não oficial). Cada conta tem sessão isolada.
 * Permanece conectado e SÓ consulta quando o gestor solicita um lote no Prospector.
 * NUNCA envia mensagens, NUNCA altera confirmação humana.
 */
import { spawn } from 'node:child_process';
import { existsSync } from 'node:fs';
import { mkdir, chmod, open, readFile, unlink } from 'node:fs/promises';
import { resolve, join } from 'node:path';

const project = resolve(process.cwd());
const sail = join(project, 'vendor', 'bin', 'sail');
const args = process.argv.slice(2);
const value = (name, fallback) => args.find((x) => x.startsWith(`${name}=`))?.slice(name.length + 1) ?? fallback;
const session = value('--session', '');
if (!['1', '2'].includes(session)) throw new Error('Use --session=1 ou --session=2.');
if (!existsSync(sail)) throw new Error('Execute na raiz do projeto Laravel com vendor/bin/sail.');
const slot = session === '1' ? '0' : '1';
const base = join(project, 'storage', 'app', 'private');
const authDir = join(base, session === '1' ? 'whatsapp-web-session-v4' : 'whatsapp-web-session-v5-2');
const lockPath = join(base, `whatsapp-web-agent-${session}.lock`);
const sleep = (ms) => new Promise((res) => setTimeout(res, ms));
let closed = false;
let sock = null;
let heartbeat = null;
let connectionOpen = false;

function artisan(params, timeoutMs = 60000, input = null) {
  return new Promise((resolveOutput, reject) => {
    const p = spawn(sail, ['artisan', ...params], {
      cwd: project, shell: false, env: { ...process.env, TERM: 'dumb' },
      stdio: [input === null ? 'ignore' : 'pipe', 'pipe', 'pipe'],
    });
    if (input !== null) p.stdin.end(input);
    let out = '';
    let err = '';
    const timer = setTimeout(() => p.kill('SIGTERM'), timeoutMs);
    p.stdout.on('data', (chunk) => { out += String(chunk); });
    p.stderr.on('data', (chunk) => { err += String(chunk); });
    p.on('error', (error) => { clearTimeout(timer); reject(error); });
    p.on('close', (code) => {
      clearTimeout(timer);
      if (code !== 0) return reject(new Error(`Artisan ${params[0]} (${code}): ${err.slice(-300) || out.slice(-300)}`));
      resolveOutput(out.trim());
    });
  });
}
async function command(action, extra = []) {
  const output = await artisan(['contacts:wa-web:agent', action, `--session=${session}`, ...extra]);
  const line = output.split(/\r?\n/).at(-1);
  return JSON.parse(line);
}

// EC_WA_PAIR_V13_1: QR visível no painel apenas para gestores, expira após 85s.
async function publishPairingQr(secretQr) {
  if (closed || connectionOpen) return;
  try {
    const QRModule = await import('qrcode');
    const QRCode = QRModule.default ?? QRModule;
    const data = await QRCode.toDataURL(secretQr, { errorCorrectionLevel: 'M', width: 300, margin: 2 });
    if (closed || connectionOpen) return;
    await artisan(['contacts:wa-web:pairing', 'publish', `--session=${session}`], 60000, data);
    console.log(`Conta ${session}: QR disponível na Central por tempo limitado.`);
  } catch (e) {
    console.error(`Conta ${session}: não foi possível publicar QR no painel: ${e.message}`);
  }
}

async function pulse(state, error = '') {
  try {
    const jid = String(sock?.user?.id || '');
    const userId = jid.endsWith('@s.whatsapp.net') ? jid.split(':')[0].split('@')[0] : '';
    const account = /^[0-9]{10,15}$/.test(userId) && state === 'connected' ? [`--account=${userId}`] : [];
    await command('pulse', [`--state=${state}`, ...account, ...(error ? [`--error=${error.slice(0, 150)}`] : [])]);
  } catch (errorPulse) {
    console.error('Falha no heartbeat:', errorPulse.message);
  }
}
function mask(phone) {
  const p = String(phone);
  return p.length > 7 ? `+${p.slice(0, 4)}••••${p.slice(-3)}` : '***';
}
function classify(result) {
  if (!Array.isArray(result)) return 'inconclusive';
  if (result.some((entry) => entry?.exists === true)) return 'found';
  if (result.some((entry) => entry?.exists === false)) return 'not_found';
  return 'inconclusive';
}
async function onWhatsAppWithTimeout(number) {
  let timer;
  try {
    return await Promise.race([
      sock.onWhatsApp(number),
      new Promise((_, reject) => {
        timer = setTimeout(() => reject(new Error('Consulta excedeu 25s')), 25000);
      }),
    ]);
  } finally {
    if (timer) clearTimeout(timer);
  }
}
async function connect() {
  const [{ default: makeWASocket, useMultiFileAuthState, DisconnectReason, Browsers },
    { default: pino }] = await Promise.all([
    import('@whiskeysockets/baileys'), import('pino'),
  ]);
  let tries = 0;
  while (!closed && tries < 4) {
    tries += 1;
    await pulse('connecting');
    const { state, saveCreds } = await useMultiFileAuthState(authDir);
    const current = makeWASocket({
      auth: state, logger: pino({ level: 'silent' }),
      browser: Browsers.ubuntu(`ExportControl - Consulta ${session}`),
      syncFullHistory: false, markOnlineOnConnect: false,
    });
    sock = current;
    try {
      const result = await new Promise((resolveConnection, rejectConnection) => {
        const timer = setTimeout(() => rejectConnection(new Error('Conexão sem resposta em 180 segundos')), 180000);
        const finish = (fn, arg) => { clearTimeout(timer); fn(arg); };
        current.ev.on('creds.update', saveCreds);
        current.ev.on('connection.update', ({ connection, qr, lastDisconnect }) => {
          if (qr) {
            // O QR não aparece no log, pois concede acesso à conta.
            void publishPairingQr(qr);
          }
          if (connection === 'open') {
            connectionOpen = true;
            finish(resolveConnection, true);
          } else if (connection === 'close') {
            connectionOpen = false;
            const reason = lastDisconnect?.error?.output?.statusCode;
            finish(rejectConnection, new Error(`Conexão encerrada (${reason ?? 'sem código'})`));
          }
        });
      });
      if (result && connectionOpen) {
        console.log(`Conta ${session} conectada; aguardando pedido da Central.`);
        await pulse('connected');
        await artisan(['contacts:wa-web:pairing', 'clear', `--session=${session}`]).catch(() => {});
        return;
      }
    } catch (error) {
      console.error(`Conta ${session}:`, error.message);
      await pulse('disconnected');
      if (tries >= 4) throw error;
      await sleep(Math.min(15000, tries * 2500));
    }
  }
  throw new Error('Conexão cancelada.');
}
async function runBatch(batch) {
  const id = Number(batch.id);
  const count = Number(batch.limit);
  console.log(`Conta ${session}: lote #${id} solicitado, até ${count} número(s).`);
  let failure = '';
  try {
    const scope = ['all', 'mobile', 'landline'].includes(batch.scope) ? batch.scope : 'mobile'; // EC_WA_FULL_DIRECTORY_V8_NODE
    const scopeArgs = scope === 'all' ? ['--include-fixed'] : scope === 'landline' ? ['--fixed-only'] : [];
    // EC_WA_V16_NODE: lote opcional de IDs pré-selecionados pelo gestor.
    let selected = [];
    if (batch.target_ids) {
      try { selected = JSON.parse(batch.target_ids); } catch { throw new Error('IDs do lote inválidos'); }
      if (!Array.isArray(selected) || selected.length > 10 || selected.some((x) => !Number.isInteger(x) || x < 1)) {
        throw new Error('Seleção de números inválida no lote');
      }
    }
    const selectedArgs = selected.length ? [`--ids=${selected.join(',')}`] : [];
    const payload = await artisan(['contacts:wa-web:candidates', ...scopeArgs, ...selectedArgs, `--slot=${slot}`, `--limit=${count}`]);
    const candidates = JSON.parse(payload.split(/\r?\n/).at(-1)).candidates || [];
    let step = 0;
    for (const candidate of candidates) {
      const state = await command('continue', [`--batch=${id}`]);
      if (!state.continue || closed) break;
      if (!connectionOpen) throw new Error('Sessão desconectada durante o lote.');
      if (!/^55[1-9][0-9]{9,10}$/.test(String(candidate.phone))) continue;
      await command('current', [`--phone=${candidate.id}`]);
      let result;
      try {
        result = classify(await onWhatsAppWithTimeout(candidate.phone));
      } catch (error) {
        result = 'error';
      }
      // Resultado e histórico técnico gravados antes de atualizar o contador do lote.
      await artisan(['contacts:wa-web:record', String(candidate.id), result, `--session=${session}`]);
      await command('progress', [`--batch=${id}`, `--result=${result}`]);
      step += 1;
      console.log(`${step}/${count}: ${mask(candidate.phone)} — ${result}`);
      if (result === 'error') {
        failure = 'Falha ao consultar número; lote interrompido sem marcar ausência.';
        break;
      }
      if (step < candidates.length) await sleep(15000);
    }
  } catch (error) {
    failure = error.message.slice(0, 220);
    console.error(`Lote ${id} interrompido:`, failure);
  } finally {
    await command('finish', [`--batch=${id}`, ...(failure ? [`--error=${failure}`] : [])]);
    await command('current', ['--phone=0']);
  }
}
async function holdLock() {
  await mkdir(base, { recursive: true, mode: 0o700 });
  await chmod(base, 0o700);
  let handle;
  try {
    handle = await open(lockPath, 'wx', 0o600);
  } catch (error) {
    if (error.code !== 'EEXIST') throw error;
    const stored = await readFile(lockPath, 'utf8').catch(() => '');
    const pid = Number(stored.trim());
    let running = false;
    if (Number.isInteger(pid) && pid > 1) {
      try { process.kill(pid, 0); running = true; } catch (err) { running = err.code === 'EPERM'; }
    }
    if (running) throw new Error(`O agente da conta ${session} já está executando (PID ${pid}).`);
    await unlink(lockPath);
    handle = await open(lockPath, 'wx', 0o600);
  }
  await handle.writeFile(String(process.pid));
  await handle.close();
}
async function shutdown() {
  if (closed) return;
  closed = true;
  if (heartbeat) clearInterval(heartbeat);
  try { await pulse('disconnected'); } catch { /* noop */ }
  try { await sock?.end(new Error('Parada voluntária do agente')); } catch { /* noop */ }
  await unlink(lockPath).catch(() => {});
  process.exit(0);
}
process.on('SIGTERM', () => { void shutdown(); });
process.on('SIGINT', () => { void shutdown(); });

async function main() {
  await holdLock();
  await mkdir(authDir, { recursive: true, mode: 0o700 });
  await chmod(authDir, 0o700);
  await command('recover');
  await connect();
  heartbeat = setInterval(() => { void pulse(connectionOpen ? 'connected' : 'disconnected'); }, 30000);
  while (!closed) {
    if (!connectionOpen) {
      await pulse('disconnected');
      throw new Error('A sessão WhatsApp foi desconectada. Reinicie após revalidar o QR Code.');
    }
    try {
      const batch = await command('claim');
      if (batch.id) await runBatch(batch);
    } catch (error) {
      console.error('Falha ao ler solicitações:', error.message);
      await sleep(5000);
    }
    if (!closed) await sleep(12000);
  }
}
main().catch(async (error) => {
  console.error('Erro do agente:', error.message);
  await pulse('error', error.message);
  if (heartbeat) clearInterval(heartbeat);
  try { await sock?.end(new Error('Falha do agente')); } catch { /* noop */ }
  await unlink(lockPath).catch(() => {});
  process.exitCode = 1;
});
