import { defineConfig } from "eslint/config";
import { fixupConfigRules, fixupPluginRules } from "@eslint/compat";
import globals from "globals";
import typescriptEslint from "@typescript-eslint/eslint-plugin";
import tsParser from "@typescript-eslint/parser";
import path from "node:path";
import { fileURLToPath } from "node:url";
import js from "@eslint/js";
import { FlatCompat } from "@eslint/eslintrc";

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const compat = new FlatCompat({
    baseDirectory: __dirname,
    recommendedConfig: js.configs.recommended,
    allConfig: js.configs.all
});

export default defineConfig([{
    extends: fixupConfigRules(compat.extends(
        "eslint:recommended",
        "plugin:import/recommended",
        "plugin:jsdoc/recommended",
    )),

    plugins: {},

    languageOptions: {
        globals: {
            ...globals.browser,
            ...globals.node,
        },

        ecmaVersion: "latest",
        sourceType: "module",
    },

    settings: {
        "import/resolver": {
            node: {
                extensions: [".js", ".ts", ".jsx", ".tsx"],
            },
        },
    },

    rules: {
        "no-unused-vars": ["error", { "argsIgnorePattern": "^_", "varsIgnorePattern": "^_" }],
        "jsdoc/require-jsdoc": "off",
        "jsdoc/require-param": "off",
        "jsdoc/require-returns": "off",
        "import/no-unresolved": "off",
    },
}, {
    files: ["**/*.ts", "**/*.tsx"],

    extends: fixupConfigRules(
        compat.extends("plugin:@typescript-eslint/recommended", "plugin:import/typescript"),
    ),

    plugins: {
        "@typescript-eslint": fixupPluginRules(typescriptEslint),
    },

    languageOptions: {
        parser: tsParser,
        ecmaVersion: "latest",
        sourceType: "module",
    },

    rules: {
        "@typescript-eslint/no-unused-vars": ["warn", {
            argsIgnorePattern: "^_",
            varsIgnorePattern: "^_",
        }],
    },
}, {
    files: ["tests/playwright/**/*.ts"],

    languageOptions: {
        globals: {
            ...Object.fromEntries(Object.entries(globals.browser).map(([key]) => [key, "off"])),
            ...globals.node,
        },
    },

    rules: {
        "@typescript-eslint/no-explicit-any": "off",
    },
}]);