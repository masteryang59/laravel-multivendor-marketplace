#!/usr/bin/env node
// Runs PHP (the WebAssembly build from @php-wasm/node) with this repo mounted
// at /app, so artisan, Composer and PHPUnit work on machines without PHP:
//   node scripts/php.mjs -v
//   node scripts/php.mjs artisan migrate --seed
// Set PHP_VERSION=8.3 / 8.4 / 8.5 to pick the version (default 8.4).
import { LogSeverity, logger } from '@php-wasm/logger';
import { createNodeFsMountHandler, loadNodeRuntime } from '@php-wasm/node';
import { FileLockManagerInMemory, PHP, setPhpIniEntries } from '@php-wasm/universal';
import { createSpawnHandler, splitShellCommand } from '@php-wasm/util';
import fs, { mkdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, posix, resolve } from 'node:path';
import tls from 'node:tls';
import { fileURLToPath } from 'node:url';

export const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
export const phpVersion = process.env.PHP_VERSION || '8.4';

// Windows' LockFileEx refuses to unlock a range the OS already released when
// the file closed; php-wasm warns about it on every Storage::put(). Harmless.
logger.setSeverityFilterLevel(LogSeverity.Error);

const CA_BUNDLE = '/etc/ssl/certs/ca-certificates.crt';

export const INI = [
  '-d', 'memory_limit=1024M',
  '-d', 'error_reporting=E_ALL',
  '-d', 'display_errors=stderr',
  '-d', `openssl.cafile=${CA_BUNDLE}`,
  '-d', `curl.cainfo=${CA_BUNDLE}`,
];

// On Windows a rename or unlink right after a write sometimes fails with
// EBUSY/EPERM while another process (antivirus, indexer) still has the file
// open. Laravel writes caches as tempnam() + rename() and SQLite deletes its
// journal, so retry for a while like graceful-fs does. php-wasm's NODEFS looks
// these functions up on the shared fs module at call time.
if (process.platform === 'win32' && !fs.renameSync.retrying) {
  const pause = new Int32Array(new SharedArrayBuffer(4));
  for (const name of ['renameSync', 'unlinkSync', 'rmdirSync']) {
    const original = fs[name];
    fs[name] = Object.assign(function retrying(...args) {
      for (let attempt = 0; ; attempt++) {
        try {
          return original.apply(fs, args);
        } catch (error) {
          if (attempt >= 40 || !['EBUSY', 'EPERM', 'EACCES'].includes(error.code)) throw error;
          Atomics.wait(pause, 0, 0, Math.min(10 * (attempt + 1), 100));
        }
      }
    }, { retrying: true });
  }
}

const COMPOSER_CACHE =join(tmpdir(), 'php-wasm-composer-cache');
const sharedLocks = new FileLockManagerInMemory();
let nextProcessId = 1;

export async function createPhp({ env = {} } = {}) {
  const php = new PHP(
    await loadNodeRuntime(phpVersion, {
      fileLockManager: sharedLocks,
      emscriptenOptions: {
        processId: nextProcessId++,
        ENV: {
          ...process.env,
          COMPOSER_HOME: '/tmp/composer',
          COMPOSER_CACHE_DIR: '/composer-cache',
          ...env,
          TMPDIR: '/tmp',
          HOME: '/tmp',
        },
      },
    }),
  );
  php.mkdirTree('/app');
  php.mount('/app', createNodeFsMountHandler(root));
  php.mkdirTree('/tmp');
  // Composer renames downloads inside its cache; renames under some Windows
  // drives fail with EBUSY in sandboxes, so keep the cache in the OS temp dir.
  mkdirSync(COMPOSER_CACHE, { recursive: true });
  php.mkdirTree('/composer-cache');
  php.mount('/composer-cache', createNodeFsMountHandler(COMPOSER_CACHE));
  // The WASM build ships without a CA bundle; give it Node's (used by
  // Composer's HTTPS downloads and by curl/openssl streams).
  php.mkdirTree(posix.dirname(CA_BUNDLE));
  php.writeFile(CA_BUNDLE, tls.rootCertificates.join('\n'));
  php.chdir('/app');
  await setPhpIniEntries(php, {
    memory_limit: '1024M',
    error_reporting: 'E_ALL',
    'openssl.cafile': CA_BUNDLE,
    'curl.cainfo': CA_BUNDLE,
    upload_max_filesize: '8M',
    post_max_size: '10M',
  });
  await php.setSpawnHandler(spawnHandler(env));
  return php;
}

// proc_open()/exec() support: `php …` runs in a fresh WASM instance (Composer
// scripts, artisan calling itself); every other binary is "not found", which
// is what Composer expects when it probes for git, hg, unzip and friends.
function spawnHandler(env) {
  return createSpawnHandler(async (command, proc, options) => {
    proc.notifySpawn();
    let argv = command;
    if (argv[0] === '/bin/sh' && argv[1] === '-c') argv = splitShellCommand(argv[2]);
    if (argv[0] === 'exec') argv = argv.slice(1);
    argv = argv.filter((arg) => !/^\d?>/.test(arg)); // drop "2>/dev/null"-style redirects
    const binary = posix.basename(argv[0] ?? '');
    if (!/^php(\d[\d.]*)?$/.test(binary) && !binary.endsWith('.phar')) {
      proc.stderr(`sh: ${binary}: command not found\n`);
      proc.exit(127);
      return;
    }
    const args = binary.endsWith('.phar') ? argv : argv.slice(1);
    const child = await createPhp({ env: { ...env, ...options.env } });
    try {
      if (options.cwd) child.chdir(options.cwd);
      const response = await child.cli(['php', ...INI, ...args]);
      await Promise.all([
        response.stdout.pipeTo(new WritableStream({ write: (chunk) => proc.stdout(chunk) })),
        response.stderr.pipeTo(new WritableStream({ write: (chunk) => proc.stderr(chunk) })),
      ]);
      proc.exit(await exitCodeOf(response));
    } finally {
      child.exit();
    }
  });
}

async function exitCodeOf(response) {
  try {
    return await response.exitCode;
  } catch (error) {
    if (error?.name === 'ExitStatus') return error.status ?? 1;
    throw error;
  }
}

export async function runPhp(args, options = {}) {
  const php = await createPhp(options);
  const response = await php.cli(['php', ...INI, ...args]);
  const pipe = (stream, target) =>
    stream.pipeTo(new WritableStream({ write: (chunk) => void target.write(chunk) }));
  await Promise.all([
    pipe(response.stdout, process.stdout),
    pipe(response.stderr, process.stderr),
  ]);
  return exitCodeOf(response);
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const code = await runPhp(process.argv.slice(2));
  // php-wasm keeps handles open; exit explicitly once output is flushed.
  setTimeout(() => process.exit(code), 50);
}
