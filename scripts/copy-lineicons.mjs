import { copyFile, mkdir, readFile } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const srcDir = join(root, 'node_modules', 'lineicons', 'dist');
const destDir = join(root, 'public', 'fonts', 'lineicons');

const cssText = await readFile(join(srcDir, 'lineicons.css'), 'utf8');
const fontFiles = [...cssText.matchAll(/url\(([^)]+)\)/g)].map((m) => m[1]);

await mkdir(destDir, { recursive: true });
await copyFile(join(srcDir, 'lineicons.css'), join(destDir, 'lineicons.css'));
for (const font of fontFiles) {
    await copyFile(join(srcDir, font), join(destDir, font));
}

console.log(`Copied lineicons.css + ${fontFiles.length} font files to public/fonts/lineicons/`);
