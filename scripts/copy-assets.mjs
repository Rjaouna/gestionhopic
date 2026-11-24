import { copyFile, mkdir, readdir } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('../', import.meta.url));
const files = {
  'bootstrap/dist/css/bootstrap.min.css': 'bootstrap/bootstrap.min.css',
  'bootstrap/dist/js/bootstrap.bundle.min.js': 'bootstrap/bootstrap.bundle.min.js',
  'bootstrap-icons/font/bootstrap-icons.min.css': 'icons/bootstrap-icons.min.css',
  'bootstrap-icons/font/fonts/bootstrap-icons.woff2': 'icons/fonts/bootstrap-icons.woff2',
  'bootstrap-icons/font/fonts/bootstrap-icons.woff': 'icons/fonts/bootstrap-icons.woff',
  'chart.js/dist/chart.umd.js': 'chart/chart.umd.js',
  '@hotwired/stimulus/dist/stimulus.js': 'stimulus/stimulus.js',
};

for (const font of ['inter', 'manrope']) {
  const directory = `@fontsource-variable/${font}/files`;
  for (const name of await readdir(resolve(root, 'node_modules', directory))) {
    if (name === `${font}-latin-wght-normal.woff2` || name === `${font}-latin-ext-wght-normal.woff2`) {
      files[`${directory}/${name}`] = `fonts/${name}`;
    }
  }
}

for (const [source, target] of Object.entries(files)) {
  const destination = resolve(root, 'assets/vendor', target);
  await mkdir(dirname(destination), { recursive: true });
  await copyFile(resolve(root, 'node_modules', source), destination);
}
