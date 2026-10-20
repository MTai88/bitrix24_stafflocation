/**
 * ESLint flat config (ESLint 9+, ESM — под "type": "module" в package.json;
 * require()-вариант в ESM-пакете падает «module is not defined»).
 *
 * Состав:
 *   1) js.configs.recommended               — базовые JS-правила
 *   2) typescript-eslint recommended        — TS-специфика
 *   3) eslint-plugin-vue flat/recommended   — Vue 3 правила для .vue SFC
 *   4) parser override: в .vue — vue-eslint-parser, внутри — tseslint.parser
 */
import js from '@eslint/js';
import tseslint from 'typescript-eslint';
import vue from 'eslint-plugin-vue';
import vueParser from 'vue-eslint-parser';

export default [
  // ── Global ignores ────────────────────────────────────────────────────────
  {
    ignores: [
      'dist/**',
      'node_modules/**',
      'coverage/**',
      '*.min.js',
    ],
  },

  // ── JS baseline (eslint:recommended) ──────────────────────────────────────
  js.configs.recommended,

  // ── TypeScript recommended ────────────────────────────────────────────────
  ...tseslint.configs.recommended,

  // ── Vue 3 flat recommended ────────────────────────────────────────────────
  ...vue.configs['flat/recommended'],

  // ── Парсер для .vue: vue-eslint-parser → внутри typescript-eslint ────────
  {
    files: ['**/*.vue'],
    languageOptions: {
      parser: vueParser,
      parserOptions: {
        parser: tseslint.parser,
        extraFileLanguages: ['ts'],
      },
    },
  },

  // ── Кастомные правила ─────────────────────────────────────────────────────
  {
    rules: {
      // Bitrix-компоненты часто однословные (Header, Menu, App)
      'vue/multi-word-component-names': 'off',

      // interop со сторонними пакетами без типов (filepond)
      '@typescript-eslint/no-explicit-any': 'off',
      'no-undef': 'off',
    },
  },
];
