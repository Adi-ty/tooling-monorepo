'use strict';

const wordpress = require('@wordpress/eslint-plugin');
const commentsConfigs = require('@eslint-community/eslint-plugin-eslint-comments/configs');
const jestPlugin = require('eslint-plugin-jest');

module.exports = [
	...wordpress.configs.recommended,
	{
		...commentsConfigs.recommended,
		rules: {
			...commentsConfigs.recommended.rules,
		},
	},
	{
		...jestPlugin.configs['flat/recommended'],
		files: ['**/*.test.js'],
	},
];

// release-please smoke test: breaking change 2026-11-06T15:00:18Z
