/**
 * Tailwind CSS config (ESM — под "type": "module" в package.json).
 *
 * Префикс `tw-` — чтобы утилиты не пересекались с Bitrix (adm-, bx-),
 * Bootstrap (btn, d-flex) и прочим CSS страницы.
 *
 * content globs — где Tailwind ищет классы.
 *
 * preflight отключён: у скаффолда свой reset в main.css. Встраиваемым в
 * Bitrix сборкам (как mtai.gallery) глобальный reset запрещён — там reset
 * вообще убирают из main.css и стили изолируют scoped-стилями SFC.
 */
/** @type {import('tailwindcss').Config} */
export default {
  prefix: 'tw-',
  content: ['./src/**/*.{ts,tsx,js,jsx,vue,html}'],
  corePlugins: {
    preflight: false,
  },
  theme: {
    extend: {},
  },
  plugins: [],
};
