#!/usr/bin/env node

/**
 * Validates etchData structure in exported WordPress post JSON files.
 * Reads post_content, extracts Gutenberg blocks, and validates metadata.etchData
 * against the expected Etch schema.
 *
 * Usage: node scripts/validate-etchdata.js [post-1.json post-2.json ...]
 */

const fs = require('fs');
const path = require('path');

const VALID_HTML_TAGS = new Set([
	'div', 'section', 'header', 'footer', 'main', 'aside', 'article', 'nav',
	'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'span', 'a', 'ul', 'ol', 'li',
	'figure', 'figcaption', 'img', 'blockquote', 'pre', 'code', 'strong', 'em'
]);

/**
 * Extract JSON object from string starting at position, respecting balanced braces.
 * @param {string} str
 * @param {number} start
 * @returns {{ json: object, endIndex: number } | null}
 */
function extractBlockAttributes(str, start) {
	if (str[start] !== '{') return null;
	let depth = 0;
	let i = start;
	let inString = false;
	let escape = false;
	let quote = null;

	while (i < str.length) {
		const c = str[i];
		if (escape) {
			escape = false;
			i++;
			continue;
		}
		if (inString) {
			if (c === '\\') escape = true;
			else if (c === quote) inString = false;
			i++;
			continue;
		}
		if (c === '"' || c === "'") {
			inString = true;
			quote = c;
			i++;
			continue;
		}
		if (c === '{') depth++;
		else if (c === '}') {
			depth--;
			if (depth === 0) {
				try {
					const json = JSON.parse(str.slice(start, i + 1));
					return { json, endIndex: i + 1 };
				} catch (e) {
					return null;
				}
			}
		}
		i++;
	}
	return null;
}

/**
 * Extract all Gutenberg blocks from post_content.
 * Includes blocks with and without JSON attributes.
 * @param {string} postContent
 * @returns {Array<{ blockName: string, attrs: object }>}
 */
function extractBlocks(postContent) {
	const blocks = [];
	// Match <!-- wp:blockName or <!-- wp:blockName { (with optional JSON)
	const blockStart = /<!--\s*wp:([^\s>]+)(?:\s+(\{))?/g;
	let match;

	while ((match = blockStart.exec(postContent)) !== null) {
		const blockName = match[1];
		const hasJson = match[2] === '{';
		const afterName = match.index + match[0].length;
		// When hasJson, include the leading '{' so JSON starts at index 0 (subtract 1 from slice start).
		const rest = postContent.slice(hasJson ? afterName - 1 : afterName);

		if (hasJson) {
			const extracted = extractBlockAttributes(rest, 0);
			if (extracted) {
				blocks.push({ blockName, attrs: extracted.json });
			} else {
				blocks.push({ blockName, attrs: {} });
			}
		} else {
			// Block with no attributes (e.g. <!-- wp:paragraph -->)
			blocks.push({ blockName, attrs: {} });
		}
	}

	return blocks;
}

/**
 * Infer element type from block attributes (etchData or tagName).
 * @param {object} attrs
 * @returns {string}
 */
function getElementType(attrs) {
	const etch = attrs?.metadata?.etchData;
	if (etch?.block?.tag) return etch.block.tag;
	if (attrs?.tagName) return attrs.tagName;
	return attrs?.metadata?.name ? 'unknown' : 'none';
}

/**
 * Validate a single block's metadata.etchData.
 * @param {object} etchData
 * @returns {string[]} List of error messages (empty if valid)
 */
function validateEtchData(etchData) {
	const errors = [];

	if (!etchData || typeof etchData !== 'object') {
		return ['etchData is missing or not an object'];
	}

	if (etchData.origin !== 'etch') {
		errors.push(`origin must be "etch", got: ${JSON.stringify(etchData.origin)}`);
	}

	if (typeof etchData.name !== 'string' || etchData.name.trim() === '') {
		errors.push(`name must be a non-empty string, got: ${JSON.stringify(etchData.name)}`);
	}

	if (!Array.isArray(etchData.styles)) {
		errors.push(`styles must be an array, got: ${typeof etchData.styles}`);
	}

	if (!etchData.attributes || typeof etchData.attributes !== 'object') {
		errors.push('attributes must be an object');
	} else if (
		Object.prototype.hasOwnProperty.call(etchData.attributes, 'class') &&
		!(typeof etchData.attributes.class === 'string')
	) {
		errors.push(`attributes.class must be a string (not array), got: ${typeof etchData.attributes.class}`);
	}

	const block = etchData.block;
	if (!block || typeof block !== 'object') {
		errors.push('block must be an object');
	} else {
		if (block.type !== 'html') {
			errors.push(`block.type must be "html", got: ${JSON.stringify(block.type)}`);
		}
		if (typeof block.tag !== 'string' || block.tag.trim() === '') {
			errors.push(`block.tag must be a non-empty string, got: ${JSON.stringify(block.tag)}`);
		} else if (!VALID_HTML_TAGS.has(block.tag.toLowerCase())) {
			errors.push(`block.tag should be a valid HTML tag, got: ${block.tag}`);
		}
	}

	return errors;
}

/**
 * Process a single JSON file and return validation results.
 * @param {string} filePath
 * @returns {{ file: string, blocks: Array<{ attrs: object, etchData: object, valid: boolean, errors: string[], elementType: string }>, total: number, valid: number, invalid: number }}
 */
function processFile(filePath) {
	let raw = fs.readFileSync(filePath, 'utf8');
	if (raw.charCodeAt(0) === 0xfeff) {
		raw = raw.slice(1);
	}
	let data;

	try {
		data = JSON.parse(raw);
	} catch (e) {
		throw new Error(`Invalid JSON in ${filePath}: ${e.message}`);
	}

	const postContent = data.post_content;
	if (typeof postContent !== 'string') {
		return {
			file: path.basename(filePath),
			blocks: [],
			total: 0,
			valid: 0,
			invalid: 0,
			elementBreakdown: {}
		};
	}

	const blocks = extractBlocks(postContent);
	const results = [];
	const elementBreakdown = {};

	for (const { attrs } of blocks) {
		const etchData = attrs?.metadata?.etchData;
		const elementType = getElementType(attrs);

		if (!elementBreakdown[elementType]) {
			elementBreakdown[elementType] = { count: 0, valid: 0, invalid: 0 };
		}
		elementBreakdown[elementType].count++;

		if (!etchData) {
			results.push({
				attrs,
				etchData: null,
				valid: false,
				errors: ['Block has no metadata.etchData'],
				elementType
			});
			elementBreakdown[elementType].invalid++;
			continue;
		}

		const errors = validateEtchData(etchData);
		const valid = errors.length === 0;
		results.push({
			attrs,
			etchData,
			valid,
			errors,
			elementType
		});
		if (valid) {
			elementBreakdown[elementType].valid++;
		} else {
			elementBreakdown[elementType].invalid++;
		}
	}

	return {
		file: path.basename(filePath),
		blocks: results,
		total: results.length,
		valid: results.filter(r => r.valid).length,
		invalid: results.filter(r => !r.valid).length,
		elementBreakdown
	};
}

/**
 * Merge element breakdowns from multiple files.
 * @param {Array<object>} breakdowns
 * @returns {object}
 */
function mergeElementBreakdown(breakdowns) {
	const merged = {};
	for (const b of breakdowns) {
		for (const [type, stats] of Object.entries(b)) {
			if (!merged[type]) merged[type] = { count: 0, valid: 0, invalid: 0 };
			merged[type].count += stats.count;
			merged[type].valid += stats.valid;
			merged[type].invalid += stats.invalid;
		}
	}
	return merged;
}

function expandFiles(args) {
	const out = [];
	for (const f of args) {
		if (!f || f.startsWith('-')) continue;
		const stat = fs.existsSync(f) && fs.statSync(f);
		if (stat && stat.isDirectory()) {
			const names = fs.readdirSync(f).filter(n => n.endsWith('.json'));
			for (const n of names.sort()) {
				out.push(path.join(f, n));
			}
		} else {
			out.push(f);
		}
	}
	return out;
}

function main() {
	const files = expandFiles(process.argv.slice(2));

	if (files.length === 0) {
		console.error('Usage: node scripts/validate-etchdata.js <file.json|dir> [file2.json|dir2 ...]');
		process.exit(1);
	}

	const allResults = [];
	const allBreakdowns = [];
	let totalBlocks = 0;
	let totalValid = 0;
	let totalInvalid = 0;

	for (const file of files) {
		if (!fs.existsSync(file)) {
			console.error(`File not found: ${file}`);
			process.exit(1);
		}
		try {
			const result = processFile(file);
			allResults.push(result);
			allBreakdowns.push(result.elementBreakdown);
			totalBlocks += result.total;
			totalValid += result.valid;
			totalInvalid += result.invalid;
		} catch (e) {
			console.error(`Error processing ${file}: ${e.message}`);
			process.exit(1);
		}
	}

	const mergedBreakdown = mergeElementBreakdown(allBreakdowns);

	// Report
	console.log('\n=== etchData Validation Report ===\n');
	console.log(`Files processed: ${files.length}`);
	console.log(`Total blocks analyzed: ${totalBlocks}`);
	console.log(`Blocks with valid etchData: ${totalValid}`);
	console.log(`Blocks with invalid etchData: ${totalInvalid}`);
	console.log('');

	console.log('Element type breakdown:');
	console.log('| Element Type | Count | Valid | Invalid |');
	console.log('|-------------|-------|-------|---------|');
	const types = Object.keys(mergedBreakdown).sort();
	for (const t of types) {
		const s = mergedBreakdown[t];
		console.log(`| ${t.padEnd(12)} | ${String(s.count).padStart(5)} | ${String(s.valid).padStart(5)} | ${String(s.invalid).padStart(7)} |`);
	}
	console.log('');

	for (const result of allResults) {
		if (result.invalid > 0) {
			console.log(`\n--- Invalid blocks in ${result.file} ---`);
			for (const block of result.blocks.filter(b => !b.valid)) {
				console.log(`  Element type: ${block.elementType}`);
				for (const err of block.errors) {
					console.log(`    - ${err}`);
				}
			}
		}
	}

	console.log('\n=== End of Report ===\n');

	// Exit with error if any invalid blocks
	process.exit(totalInvalid > 0 ? 1 : 0);
}

main();
