import { copyFile, mkdir, readFile } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const srcDir = join(root, 'node_modules', '@phosphor-icons', 'web', 'src', 'regular');
const destDir = join(root, 'public', 'fonts', 'phosphor');

const cssText = await readFile(join(srcDir, 'style.css'), 'utf8');
const fontFiles = [...cssText.matchAll(/url\(["']?([^)"']+)["']?\)/g)]
    .map((m) => m[1].split('#')[0].replace(/^\.?\//, ''));

await mkdir(destDir, { recursive: true });
await copyFile(join(srcDir, 'style.css'), join(destDir, 'style.css'));
for (const font of fontFiles) {
    await copyFile(join(srcDir, font), join(destDir, font));
}

console.log(`Copied Phosphor regular weight CSS + ${fontFiles.length} font files to public/fonts/phosphor/`);
