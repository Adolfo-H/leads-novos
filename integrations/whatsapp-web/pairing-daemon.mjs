#!/usr/bin/env node
/**
 * Ponte no Ubuntu. NÃO verifica números, NÃO envia mensagens.
 * Inicia uma conta somente quando um gestor solicita conexão na Central.
 */
import { spawn } from 'node:child_process';
import { openSync, closeSync, existsSync, readFileSync, mkdirSync, renameSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';

const project = resolve(process.cwd());
const sail = join(project, 'vendor', 'bin', 'sail');
const node = process.execPath;
const sleep = (ms) => new Promise((res) => setTimeout(res, ms));
let stop = false;
process.on('SIGINT', () => { stop = true; });
process.on('SIGTERM', () => { stop = true; });

function callArtisan(action, session, args = []) {
  return new Promise((ok, fail) => {
    const child = spawn(sail, ['artisan', 'contacts:wa-web:pairing', action, `--session=${session}`, ...args], {
      cwd: project, shell: false, stdio: ['ignore', 'pipe', 'pipe'],
      env: { ...process.env, TERM: 'dumb' },
    });
    let out = '';
    let err = '';
    const timeout = setTimeout(() => child.kill('SIGTERM'), 35000);
    child.stdout.on('data', (v) => { out += String(v); });
    child.stderr.on('data', (v) => { err += String(v); });
    child.on('error', (e) => { clearTimeout(timeout); fail(e); });
    child.on('close', (code) => {
      clearTimeout(timeout);
      if (code !== 0) return fail(new Error(err.slice(-200) || out.slice(-200) || `Código ${code}`));
      ok(out.trim().split(/\r?\n/).at(-1));
    });
  });
}
const privateDir = join(project, 'storage', 'app', 'private');
const logs = join(project, 'storage', 'logs');
function pidFile(session) { return join(privateDir, `whatsapp-web-agente-${session}.pid`); }
function authDir(session) { return join(privateDir, session === 1 ? 'whatsapp-web-session-v4' : 'whatsapp-web-session-v5-2'); }
function runningPid(session) {
  try {
    const pid = Number(readFileSync(pidFile(session), 'utf8').trim());
    if (!Number.isInteger(pid) || pid < 2) return null;
    process.kill(pid, 0);
    return pid;
  } catch { return null; }
}
async function stopOld(session) {
  const pid = runningPid(session);
  if (pid !== null) {
    console.log(`Conta ${session}: encerrando agente ${pid} para reconectar.`);
    try { process.kill(pid, 'SIGTERM'); } catch { /* já finalizou */ }
    for (let i = 0; i < 30; i++) {
      await sleep(400);
      if (runningPid(session) === null) break;
    }
    if (runningPid(session) !== null) throw new Error('O agente anterior ainda está ativo. Não foi encerrado à força.');
  }
  // O agente antigo remove seu próprio lock em shutdown normal.
  const oldLock = join(privateDir, `whatsapp-web-agent-${session}.lock`);
  if (existsSync(oldLock)) {
    const oldPid = Number(readFileSync(oldLock, 'utf8').trim());
    let alive = false;
    try { if (oldPid > 1) { process.kill(oldPid, 0); alive = true; } } catch { /* stale */ }
    if (alive) throw new Error(`A sessão ${session} ainda possui agente ativo (PID ${oldPid}).`);
    // O agent.mjs trata locks obsoletos sozinho.
  }
}
async function start(session, reset) {
  await stopOld(session);
  if (reset && existsSync(authDir(session))) {
    // Preserva credenciais anteriores, ainda que inválidas, numa pasta privada de backup.
    const suffix = new Date().toISOString().replace(/[:.]/g, '-');
    renameSync(authDir(session), `${authDir(session)}.backup-${suffix}`);
    console.log(`Conta ${session}: sessão anterior arquivada; aguardando novo QR no painel.`);
  }
  mkdirSync(logs, { recursive: true });
  const logFd = openSync(join(logs, `whatsapp-web-agente-${session}.log`), 'a', 0o600);
  let child;
  try {
    child = spawn(node, ['integrations/whatsapp-web/agent.mjs', `--session=${session}`], {
      cwd: project, detached: true, shell: false, stdio: ['ignore', logFd, logFd],
    });
  } finally {
    closeSync(logFd);
  }
  child.unref();
  writeFileSync(pidFile(session), String(child.pid), { mode: 0o600 });
  console.log(`Conta ${session}: novo agente iniciado (PID ${child.pid}).`);
}

if (!existsSync(sail)) throw new Error('Execute na raiz do Prospector com vendor/bin/sail.');
mkdirSync(privateDir, { recursive: true, mode: 0o700 });
console.log('Conector de WhatsApp da Central iniciado. Aguardando clique do gestor.');
while (!stop) {
  for (const session of [1, 2]) {
    if (stop) break;
    try {
      const result = JSON.parse(await callArtisan('poll', session));
      if (typeof result.token !== 'string' || !/^[A-Za-z0-9]{40}$/.test(result.token)) continue;
      await start(session, result.reset === true);
      await callArtisan('ack', session, [`--token=${result.token}`]);
    } catch (e) {
      // Docker/contêiner podem estar momentaneamente indisponíveis; mantém a solicitação pendente.
      console.error(`Conta ${session}: ${String(e.message).slice(0, 200)}`);
    }
  }
  await sleep(12000);
}
console.log('Conector encerrado. Os agentes de consulta não foram alterados.');
