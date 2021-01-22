#!/usr/bin/env node
// Composer on PHP-WASM: `node scripts/composer.mjs install` (any Composer args).
//
// Two workarounds for php-wasm:
// - Its curl keeps CURLOPT_FILE targets open, so Composer's rename of a
//   finished download fails with EBUSY on Windows. Hiding curl_multi makes
//   Composer fall back to its stream downloader, which works.
// - Composer runs scripts through a shell wrapper (`sh -c '... echo $?'`)
//   that php-wasm can't spawn, so scripts are skipped and the one Laravel
//   needs (package:discover) is run here directly.
import { runPhp } from './php.mjs';

const COMPOSER = '.cache/composer-2.11.0.phar';
const args = process.argv.slice(2);
const runsScripts = ['install', 'update', 'require', 'remove', 'dump-autoload', 'dumpautoload'].includes(args[0]);

let code = await runPhp([
  '-d', 'disable_functions=curl_multi_init,curl_multi_exec',
  COMPOSER,
  ...args,
  ...(runsScripts && !args.includes('--no-scripts') ? ['--no-scripts'] : []),
]);
if (code === 0 && runsScripts) {
  code = await runPhp(['artisan', 'package:discover', '--ansi']);
}
setTimeout(() => process.exit(code), 50);
