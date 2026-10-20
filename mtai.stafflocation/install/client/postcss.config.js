/**
 * PostCSS config (ESM: package.json содержит "type": "module",
 * CommonJS-вариант с require()/module.exports Vite 6 не загружает).
 *
 * Pipeline применяется ко всем CSS-файлам (включая <style> блоки .vue SFC).
 *
 * - postcss-import: резолвит @import './foo.css' относительно файла
 * - tailwindcss: JIT-компиляция Tailwind (@tailwind директивы), content globs
 *   в tailwind.config.js
 * - postcss-nested: нативный CSS nesting (& селектор)
 * - autoprefixer: префиксы по browserslist (поле в package.json)
 * - cssnano: минификация только в продакшене
 */
import postcssImport from 'postcss-import';
import tailwindcss from 'tailwindcss';
import postcssNested from 'postcss-nested';
import autoprefixer from 'autoprefixer';
import cssnano from 'cssnano';

const isProd = process.env.NODE_ENV === 'production';

export default {
  plugins: [
    postcssImport,
    tailwindcss,
    postcssNested,
    autoprefixer,
    ...(isProd ? [cssnano({ preset: 'default' })] : []),
  ],
};
