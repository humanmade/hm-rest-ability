import { spawn } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const { authHeaders } = require( '../e2e/support/credentials.js' );

const SERVER_NAME = 'hm';

/**
 * Writes the MCP server config Claude Code connects through.
 *
 * @param {string} endpoint Full URL of the MCP endpoint.
 * @param {string} role     Account to connect as.
 * @return {string} Path to the config file.
 */
export function writeMcpConfig( endpoint, role = 'admin' ) {
	const config = {
		mcpServers: {
			[ SERVER_NAME ]: {
				type: 'http',
				url: endpoint,
				headers: authHeaders( role ),
			},
		},
	};

	const file = path.join(
		fs.mkdtempSync( path.join( os.tmpdir(), 'hm-evals-' ) ),
		'mcp.json'
	);
	fs.writeFileSync( file, JSON.stringify( config ) );

	return file;
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
 * @param {Object} options Task, config path, model and timeout.
 * @return {Promise<Object>} Parsed CLI result, plus `failed` and `stderr`.
 */
export function runAgent( { task, mcpConfig, model = 'sonnet', timeoutMs = 300000 } ) {
	const args = [
		'-p',
		task,
		'--output-format',
		'json',
		'--mcp-config',
		mcpConfig,
		'--strict-mcp-config',
		'--restricted',
		'--allowed-tools',
		`mcp__${ SERVER_NAME }`,
		'--model',
		model,
	];

	return new Promise( ( resolve ) => {
		const child = spawn( 'claude', args, { stdio: [ 'ignore', 'pipe', 'pipe' ] } );
		let stdout = '';
		let stderr = '';

		const timer = setTimeout( () => {
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
			resolve( { failed: true, stderr: error.message, result: '' } );
		} );

		child.on( 'close', () => {
			clearTimeout( timer );

			try {
				const parsed = JSON.parse( stdout );

				resolve( { ...parsed, failed: !! parsed.is_error, stderr } );
			} catch {
				resolve( {
					failed: true,
					stderr: stderr || `Could not parse CLI output: ${ stdout.slice( 0, 400 ) }`,
					result: '',
				} );
			}
		} );
	} );
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
