import fs from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import { createRequire } from 'node:module';

import { McpClient } from './mcp.mjs';
import { WordPress } from './wp.mjs';
import { ScriptedProvider } from './providers/scripted.mjs';

const require = createRequire( import.meta.url );

/**
 * Hard limits on one scenario run.
 *
 * Turns and output tokens bound what a single scenario can cost. The tool
 * result cap matters most: an unbounded result would be re-sent on every
 * later turn, so one large response costs tokens for the rest of the run.
 */
const MAX_TURNS = 6;
const MAX_OUTPUT_TOKENS = 1024;
const MAX_TOOL_RESULT_CHARS = 4000;

const SYSTEM_PROMPT =
	'You are operating a WordPress site through the tools provided. ' +
	'Use the tools to make the requested changes. ' +
	'Do not ask for confirmation. When the task is done, say so briefly.';

/**
 * Reads command line flags.
 *
 * @return {Object} Parsed options.
 */
function parseArgs() {
	const args = process.argv.slice( 2 );
	const options = {
		provider: process.env.EVAL_PROVIDER || 'anthropic',
		repeat: 1,
		only: null,
		output: 'test-results/evals.json',
	};

	for ( const arg of args ) {
		const [ key, value ] = arg.replace( /^--/, '' ).split( '=' );

		if ( key === 'repeat' ) {
			options.repeat = Number( value );
		} else if ( key === 'only' ) {
			options.only = value.split( ',' );
		} else if ( key in options ) {
			options[ key ] = value;
		}
	}

	return options;
}

/**
 * Loads every scenario module.
 *
 * @return {Promise<Array<Object>>} Scenarios, sorted by id.
 */
async function loadScenarios() {
	const dir = path.join( path.dirname( new URL( import.meta.url ).pathname ), 'scenarios' );
	const files = fs.readdirSync( dir ).filter( ( file ) => file.endsWith( '.mjs' ) );
	const scenarios = [];

	for ( const file of files ) {
		const module = await import( pathToFileURL( path.join( dir, file ) ).href );
		scenarios.push( module.default );
	}

	return scenarios.sort( ( a, b ) => a.id.localeCompare( b.id ) );
}

/**
 * Builds the provider named on the command line.
 *
 * The Anthropic SDK is imported lazily so a scripted run needs neither the
 * package nor an API key.
 *
 * @param {string} name Provider name.
 * @return {Promise<Object>} The provider.
 */
async function createProvider( name ) {
	if ( name === 'scripted' ) {
		return new ScriptedProvider();
	}

	if ( name === 'anthropic' ) {
		const { AnthropicProvider } = await import( './providers/anthropic.mjs' );

		return new AnthropicProvider();
	}

	throw new Error( `Unknown provider "${ name }". Use "anthropic" or "scripted".` );
}

/**
 * Shortens a tool result before it goes back into the conversation.
 *
 * @param {string} text Tool result.
 * @return {string} Possibly shortened result.
 */
function truncate( text ) {
	if ( text.length <= MAX_TOOL_RESULT_CHARS ) {
		return text;
	}

	return `${ text.slice( 0, MAX_TOOL_RESULT_CHARS ) }\n\n[Result truncated at ${ MAX_TOOL_RESULT_CHARS } characters.]`;
}

/**
 * Runs one scenario once and grades the site afterwards.
 *
 * Grading looks at WordPress state over a separate REST connection, never at
 * what the model said. A model that claims success without changing anything
 * fails.
 *
 * @param {Object} options Scenario, provider, connections and tools.
 * @return {Promise<Object>} The result of this attempt.
 */
async function runScenario( { scenario, provider, baseUrl, tools } ) {
	const admin = new WordPress( baseUrl, 'admin' );

	await admin.reset();

	if ( scenario.setup ) {
		await scenario.setup( admin );
	}

	if ( provider instanceof ScriptedProvider ) {
		provider.load( scenario.script );
	}

	const mcp = await McpClient.connect( baseUrl, scenario.role || 'admin' );
	const messages = [ { role: 'user', content: scenario.task } ];
	const usage = { input: 0, output: 0, cacheRead: 0, cacheWrite: 0 };
	const calls = [];

	let turns = 0;
	let error = null;

	try {
		for ( ; turns < MAX_TURNS; turns++ ) {
			const reply = await provider.step( {
				system: [
					{ type: 'text', text: SYSTEM_PROMPT, cache_control: { type: 'ephemeral' } },
				],
				tools,
				messages,
				maxTokens: MAX_OUTPUT_TOKENS,
			} );

			for ( const key of Object.keys( usage ) ) {
				usage[ key ] += reply.usage[ key ] || 0;
			}

			if ( reply.stopReason !== 'tool_use' ) {
				break;
			}

			messages.push( { role: 'assistant', content: reply.content } );

			const results = [];

			for ( const block of reply.content.filter( ( item ) => item.type === 'tool_use' ) ) {
				const result = await mcp.callTool( block.name, block.input );

				calls.push( { tool: block.name, isError: result.isError } );
				results.push( {
					type: 'tool_result',
					tool_use_id: block.id,
					content: truncate( result.text ),
					is_error: result.isError,
				} );
			}

			messages.push( { role: 'user', content: results } );
		}
	} catch ( failure ) {
		error = failure.message;
	}

	const graded = error
		? { pass: false, reason: `The run failed: ${ error }` }
		: await scenario.grade( admin );

	return {
		id: scenario.id,
		pass: graded.pass,
		reason: graded.reason || null,
		turns,
		calls,
		usage,
		cost: provider.cost( usage ),
	};
}

async function main() {
	const options = parseArgs();
	const provider = await createProvider( options.provider );

	let scenarios = await loadScenarios();

	if ( options.only ) {
		scenarios = scenarios.filter( ( scenario ) => options.only.includes( scenario.id ) );
	}

	// Reuses the Playwright setup, so the evals and the e2e tests run against
	// an identically configured site.
	await require( '../e2e/global-setup.js' )();
	const baseUrl = process.env.WP_BASE_URL;

	const discovery = await McpClient.connect( baseUrl, 'admin' );
	const tools = provider.prepareTools( await discovery.listTools() );

	console.log( `Provider: ${ provider.name }` );
	console.log( `Tools:    ${ tools.map( ( tool ) => tool.name ).join( ', ' ) }` );
	console.log( '' );

	const attempts = [];

	for ( const scenario of scenarios ) {
		for ( let run = 0; run < options.repeat; run++ ) {
			const result = await runScenario( { scenario, provider, baseUrl, tools } );

			attempts.push( { ...result, run, tags: scenario.tags || [] } );

			const mark = result.pass ? 'pass' : 'FAIL';
			const suffix = result.reason ? ` — ${ result.reason }` : '';
			console.log(
				`${ mark }  ${ scenario.id }  (run ${ run + 1 }/${ options.repeat }, ${ result.turns } turns)${ suffix }`
			);
		}
	}

	const report = summarise( attempts, provider, options );

	fs.mkdirSync( path.dirname( options.output ), { recursive: true } );
	fs.writeFileSync( options.output, `${ JSON.stringify( report, null, '\t' ) }\n` );

	console.log( '' );
	console.log(
		`${ report.passed }/${ report.total } scenarios passed. Cost $${ report.cost.toFixed( 4 ) }.`
	);
	console.log( `Report written to ${ options.output }` );

	await require( '../e2e/global-teardown.js' )();

	process.exit( report.coreFailures.length > 0 ? 1 : 0 );
}

/**
 * Reduces the attempts to a per-scenario verdict and a run summary.
 *
 * A scenario passes when more than half its attempts pass. Models are not
 * deterministic, so a single bad run shouldn't be treated as a regression.
 * Only scenarios tagged `core` can fail the run.
 *
 * @param {Array<Object>} attempts All attempts.
 * @param {Object}        provider The provider used.
 * @param {Object}        options  Run options.
 * @return {Object} The report.
 */
function summarise( attempts, provider, options ) {
	const ids = [ ...new Set( attempts.map( ( attempt ) => attempt.id ) ) ];
	const scenarios = ids.map( ( id ) => {
		const runs = attempts.filter( ( attempt ) => attempt.id === id );
		const passes = runs.filter( ( attempt ) => attempt.pass ).length;

		return {
			id,
			tags: runs[ 0 ].tags,
			passes,
			runs: runs.length,
			pass: passes * 2 > runs.length,
			reasons: [ ...new Set( runs.map( ( run ) => run.reason ).filter( Boolean ) ) ],
			turns: runs.reduce( ( total, run ) => total + run.turns, 0 ) / runs.length,
		};
	} );

	return {
		provider: provider.name,
		repeat: options.repeat,
		generatedAt: new Date().toISOString(),
		total: scenarios.length,
		passed: scenarios.filter( ( scenario ) => scenario.pass ).length,
		coreFailures: scenarios
			.filter( ( scenario ) => ! scenario.pass && scenario.tags.includes( 'core' ) )
			.map( ( scenario ) => scenario.id ),
		cost: attempts.reduce( ( total, attempt ) => total + attempt.cost, 0 ),
		usage: attempts.reduce(
			( total, attempt ) => ( {
				input: total.input + attempt.usage.input,
				output: total.output + attempt.usage.output,
				cacheRead: total.cacheRead + attempt.usage.cacheRead,
				cacheWrite: total.cacheWrite + attempt.usage.cacheWrite,
			} ),
			{ input: 0, output: 0, cacheRead: 0, cacheWrite: 0 }
		),
		scenarios,
	};
}

main().catch( ( error ) => {
	console.error( error );
	process.exit( 1 );
} );
