import fs from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import { createRequire } from 'node:module';

import { McpClient } from './mcp.mjs';
import { WordPress } from './wp.mjs';
import { cliVersion, runAgent, writeMcpConfig } from './agent.mjs';

const require = createRequire( import.meta.url );

const TASK_PREAMBLE =
	'You are working on a WordPress site through the tools provided. ' +
	'Complete the task without asking for confirmation, then stop.\n\n';

/**
 * Reads command line flags.
 *
 * @return {Object} Parsed options.
 */
function parseArgs() {
	const options = {
		model: process.env.EVAL_MODEL || 'sonnet',
		repeat: 1,
		only: null,
		output: 'test-results/evals.json',
		dryRun: false,
	};

	for ( const arg of process.argv.slice( 2 ) ) {
		const [ key, value ] = arg.replace( /^--/, '' ).split( '=' );

		if ( key === 'dry-run' ) {
			options.dryRun = true;
		} else if ( key === 'repeat' ) {
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
	const scenarios = [];

	for ( const file of fs.readdirSync( dir ).filter( ( name ) => name.endsWith( '.mjs' ) ) ) {
		const module = await import( pathToFileURL( path.join( dir, file ) ).href );
		scenarios.push( module.default );
	}

	return scenarios.sort( ( a, b ) => a.id.localeCompare( b.id ) );
}

/**
 * Runs one scenario once and grades the site afterwards.
 *
 * Grading reads WordPress state over a separate REST connection, never what
 * the agent said about its own work. An agent that reports success without
 * changing anything fails.
 *
 * @param {Object} options Scenario, endpoint and run options.
 * @return {Promise<Object>} The result of this attempt.
 */
async function runScenario( { scenario, baseUrl, endpoint, options } ) {
	const admin = new WordPress( baseUrl, 'admin' );

	await admin.reset();

	if ( scenario.setup ) {
		await scenario.setup( admin );
	}

	let agent = { failed: false, result: '(not run)', num_turns: 0, total_cost_usd: 0 };

	if ( ! options.dryRun ) {
		agent = await runAgent( {
			task: TASK_PREAMBLE + scenario.task,
			mcpConfig: writeMcpConfig( endpoint, scenario.role || 'admin' ),
			model: options.model,
		} );
	}

	// Always grade as admin, whatever role the agent ran as. A restricted role
	// can't see enough to grade itself.
	const graded = await scenario.grade( admin );

	return {
		id: scenario.id,
		pass: graded.pass,
		reason: graded.reason || null,
		turns: agent.num_turns || 0,
		cost: agent.total_cost_usd || 0,
		said: typeof agent.result === 'string' ? agent.result.slice( 0, 300 ) : '',
		agentFailed: !! agent.failed,
		stderr: agent.failed ? ( agent.stderr || '' ).slice( 0, 400 ) : '',
	};
}

async function main() {
	const options = parseArgs();

	if ( ! options.dryRun ) {
		const version = await cliVersion();

		if ( ! version ) {
			console.error(
				'The `claude` CLI is not on PATH. These evals run through your local Claude Code subscription — install it, or pass --dry-run to check the harness alone.'
			);
			process.exit( 1 );
		}

		console.log( `CLI:      ${ version }` );
		console.log( `Model:    ${ options.model }` );
	}

	let scenarios = await loadScenarios();

	if ( options.only ) {
		scenarios = scenarios.filter( ( scenario ) => options.only.includes( scenario.id ) );
	}

	// Reuses the Playwright setup, so the evals and the e2e tests run against
	// an identically configured site.
	await require( '../e2e/global-setup.js' )();
	const baseUrl = process.env.WP_BASE_URL;

	const mcp = await McpClient.connect( baseUrl, 'admin' );
	const tools = ( await mcp.listTools() ).map( ( tool ) => tool.name );
	const endpoint = `${ baseUrl }${ mcp.route }`;

	console.log( `Endpoint: ${ endpoint }` );
	console.log( `Tools:    ${ tools.join( ', ' ) }` );

	if ( options.dryRun ) {
		console.log( '\nDry run: no agent is invoked. Every scenario should FAIL below —' );
		console.log( 'a scenario that passes without an agent has a grader that proves nothing.\n' );
	} else {
		console.log( '' );
	}

	const attempts = [];

	for ( const scenario of scenarios ) {
		for ( let run = 0; run < options.repeat; run++ ) {
			const result = await runScenario( { scenario, baseUrl, endpoint, options } );

			attempts.push( { ...result, run, tags: scenario.tags || [] } );

			const mark = result.pass ? 'pass' : 'FAIL';
			const detail = [
				result.reason,
				result.agentFailed ? `agent error: ${ result.stderr }` : null,
			]
				.filter( Boolean )
				.join( ' | ' );

			console.log(
				`${ mark }  ${ scenario.id }  (${ run + 1 }/${ options.repeat }, ${ result.turns } turns)` +
					( detail ? ` — ${ detail }` : '' )
			);
		}
	}

	const report = summarise( attempts, options );

	fs.mkdirSync( path.dirname( options.output ), { recursive: true } );
	fs.writeFileSync( options.output, `${ JSON.stringify( report, null, '\t' ) }\n` );

	console.log( '' );

	if ( options.dryRun ) {
		const leaked = report.scenarios.filter( ( scenario ) => scenario.pass );

		if ( leaked.length > 0 ) {
			console.error(
				`These graders passed with no agent, so they are not testing anything: ${ leaked
					.map( ( scenario ) => scenario.id )
					.join( ', ' ) }`
			);
			process.exit( 1 );
		}

		console.log( `All ${ report.total } graders correctly failed with no agent.` );
		await require( '../e2e/global-teardown.js' )();

		return;
	}

	console.log(
		`${ report.passed }/${ report.total } scenarios passed. ` +
			`List-price equivalent $${ report.cost.toFixed( 4 ) } (subscription usage, not billed).`
	);
	console.log( `Report written to ${ options.output }` );

	await require( '../e2e/global-teardown.js' )();

	process.exit( report.passed === report.total ? 0 : 1 );
}

/**
 * Reduces the attempts to a per-scenario verdict and a run summary.
 *
 * A scenario passes when more than half its attempts pass, so one unlucky run
 * out of several doesn't read as a regression.
 *
 * @param {Array<Object>} attempts All attempts.
 * @param {Object}        options  Run options.
 * @return {Object} The report.
 */
function summarise( attempts, options ) {
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
		harness: 'claude-code-cli',
		model: options.model,
		dryRun: options.dryRun,
		repeat: options.repeat,
		generatedAt: new Date().toISOString(),
		total: scenarios.length,
		passed: scenarios.filter( ( scenario ) => scenario.pass ).length,
		cost: attempts.reduce( ( total, attempt ) => total + attempt.cost, 0 ),
		scenarios,
		attempts,
	};
}

main().catch( ( error ) => {
	console.error( error );
	process.exit( 1 );
} );
