import { spawn } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const require = createRequire( import.meta.url );
const { authHeaders } = require( '../e2e/support/credentials.js' );

const SERVER_NAME = 'hm';
const ACCEPT_SCRIPT = path.join( path.dirname( fileURLToPath( import.meta.url ) ), 'accept-elicitation.mjs' );

/**
 * Writes the MCP server config Claude Code connects through.
 *
 * @param {string} dir      Directory to write into.
 * @param {string} endpoint Full URL of the MCP endpoint.
 * @param {string} role     Account to connect as.
 * @return {string} Path to the config file.
 */
export function writeMcpConfig( dir, endpoint, role = 'admin' ) {
	const config = {
		mcpServers: {
			[ SERVER_NAME ]: {
				type: 'http',
				url: endpoint,
				headers: authHeaders( role ),
			},
		},
	};

	const file = path.join( dir, 'mcp.json' );
	fs.writeFileSync( file, JSON.stringify( config ) );

	return file;
}

/**
 * Writes a Claude Code settings file whose Elicitation hook accepts the
 * plugin's confirmation form.
 *
 * @param {string} dir Directory to write into.
 * @return {string} Path to the settings file.
 */
export function writeAcceptSettings( dir ) {
	const settings = {
		hooks: {
			Elicitation: [
				{ hooks: [ { type: 'command', command: `node ${ JSON.stringify( ACCEPT_SCRIPT ) }` } ] },
			],
		},
	};

	const file = path.join( dir, 'settings.json' );
	fs.writeFileSync( file, JSON.stringify( settings ) );

	return file;
}

/**
 * Reads the CLI's stream-json output.
 *
 * @param {string} stdout One JSON event per line.
 * @return {{result: Object|null, toolCalls: Array<{name: string, input: Object}>}} The final result event and every tool call the agent made.
 */
function parseStream( stdout ) {
	const toolCalls = [];
	let result = null;

	for ( const line of stdout.split( '\n' ) ) {
		if ( ! line.trim() ) {
			continue;
		}

		let event;

		try {
			event = JSON.parse( line );
		} catch {
			continue;
		}

		if ( event.type === 'assistant' && Array.isArray( event.message?.content ) ) {
			for ( const block of event.message.content ) {
				if ( block.type === 'tool_use' ) {
					toolCalls.push( { name: block.name, input: block.input || {} } );
				}
			}
		} else if ( event.type === 'result' ) {
			result = event;
		}
	}

	return { result, toolCalls };
}

/**
 * Runs one task through the local Claude Code CLI.
 *
 * Claude Code is the harness here rather than a loop of our own. It connects
 * to the MCP server itself, so the eval measures a real client against the
 * real protocol, and it bills to the local subscription rather than an API
 * key.
 *
 * Two flags carry most of the weight:
 *
 * - `--restricted` removes Bash and the other code-running tools. Without it
 *   a model can curl the REST API directly and pass without touching MCP,
 *   which would make the whole eval meaningless.
 * - `--strict-mcp-config` ignores whatever MCP servers the developer has
 *   configured, so a run only ever sees this site.
 *
 * The config files live in a temp directory that is removed when the run ends.
 *
 * @param {Object} options Task, endpoint, role, model, whether to accept confirmation prompts, and timeout.
 * @return {Promise<Object>} The CLI's result event, plus `toolCalls`, `failed` and `stderr`.
 */
export async function runAgent( { task, endpoint, role = 'admin', model = 'sonnet', acceptElicitation = false, timeoutMs = 300000 } ) {
	const dir = fs.mkdtempSync( path.join( os.tmpdir(), 'hm-evals-' ) );

	try {
		const args = [
			'-p',
			task,
			'--output-format',
			'stream-json',
			'--verbose',
			'--mcp-config',
			writeMcpConfig( dir, endpoint, role ),
			'--strict-mcp-config',
			'--restricted',
			'--allowed-tools',
			`mcp__${ SERVER_NAME }`,
			'--model',
			model,
		];

		if ( acceptElicitation ) {
			args.push( '--settings', writeAcceptSettings( dir ) );
		}

		return await new Promise( ( resolve ) => {
			const child = spawn( 'claude', args, { stdio: [ 'ignore', 'pipe', 'pipe' ] } );
			let stdout = '';
			let stderr = '';
			let timedOut = false;

			const timer = setTimeout( () => {
				timedOut = true;
				child.kill( 'SIGKILL' );
				stderr += `\nTimed out after ${ timeoutMs }ms.`;
			}, timeoutMs );

			child.stdout.on( 'data', ( chunk ) => {
				stdout += chunk;
			} );
			child.stderr.on( 'data', ( chunk ) => {
				stderr += chunk;
			} );

			child.on( 'error', ( error ) => {
				clearTimeout( timer );
				resolve( { failed: true, stderr: error.message, result: '', toolCalls: [] } );
			} );

			child.on( 'close', () => {
				clearTimeout( timer );

				const { result, toolCalls } = parseStream( stdout );

				if ( ! result || timedOut ) {
					resolve( {
						failed: true,
						stderr: stderr || `Could not find a result in the CLI output: ${ stdout.slice( -400 ) }`,
						result: '',
						toolCalls,
					} );

					return;
				}

				resolve( { ...result, toolCalls, failed: !! result.is_error, stderr } );
			} );
		} );
	} finally {
		fs.rmSync( dir, { recursive: true, force: true } );
	}
}

/**
 * Checks the CLI is installed before a run starts.
 *
 * @return {Promise<string|null>} Version string, or null when it isn't there.
 */
export function cliVersion() {
	return new Promise( ( resolve ) => {
		const child = spawn( 'claude', [ '--version' ], { stdio: [ 'ignore', 'pipe', 'ignore' ] } );
		let stdout = '';

		child.stdout.on( 'data', ( chunk ) => {
			stdout += chunk;
		} );
		child.on( 'error', () => resolve( null ) );
		child.on( 'close', ( code ) => resolve( code === 0 ? stdout.trim() : null ) );
	} );
}
