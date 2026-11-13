// Removes the previous React build from ../public_html without touching
// api/, uploads/, icons/ or .htaccess.
import { readdirSync, rmSync } from 'node:fs';
import { resolve } from 'node:path';

const out = resolve(import.meta.dirname, '../../public_html');
const generated = new Set(['assets', 'index.html', 'sw.js', 'registerSW.js', 'manifest.webmanifest']);

for (const name of readdirSync(out)) {
  if (generated.has(name) || (name.startsWith('workbox-') && name.endsWith('.js'))) {
    rmSync(resolve(out, name), { recursive: true, force: true });
  }
}
