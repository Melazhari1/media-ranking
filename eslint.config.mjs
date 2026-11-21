import js from '@eslint/js';
import globals from 'globals';

export default [
    js.configs.recommended,
    {
        files: ['app.js', 'js/**/*.js'],
        languageOptions: {
            ecmaVersion: 2022,
            sourceType: 'module',
            globals: { ...globals.browser, Vue: 'readonly' },
        },
    },
    {
        files: ['tests/js/**/*.mjs'],
        languageOptions: { ecmaVersion: 2022, sourceType: 'module', globals: globals.node },
    },
];
