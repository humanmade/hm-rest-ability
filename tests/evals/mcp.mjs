import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const { authHeaders, anonymousHeaders } = require( '../e2e/support/credentials.js' );

const PROTOCOL_VERSION = '2025-06-18';

/**
 * MCP client for the eval harness.
 *
 * Same protocol as the Playwright client in tests/e2e/support, but built on
 * fetch so it can run outside a Playwright test context.
 */
export class McpClient {
	constructor( baseUrl, route, role = 'admin' ) {
		this.baseUrl = baseUrl.replace( /\/$/, '' );
		this.route = route;
		this.role = role;
		this.sessionId = null;
		this.nextId = 1;
	}

	/**
	 * Finds the MCP endpoint and completes the handshake.
	 *
	 * @param {string} baseUrl WordPress base URL.
	 * @param {string} role    Account to connect as.
	 * @return {Promise<McpClient>} A client ready to take calls.
	 */
	static async connect( baseUrl, role = 'admin' ) {
		const response = await fetch( `${ baseUrl }/wp-json/mcp`, { headers: anonymousHeaders() } );

		if ( ! response.ok ) {
			throw new Error(
				`The mcp namespace is not registered (${ response.status }). MCP Adapter may not have loaded.`
			);
		}

		const routes = Object.keys( ( await response.json() ).routes || {} );
		const server = routes.find( ( route ) => /^\/mcp\/.+/.test( route ) );

		if ( ! server ) {
			throw new Error( `No MCP server route found in: ${ routes.join( ', ' ) }` );
		}

		const client = new McpClient( baseUrl, `/wp-json${ server }`, role );
		await client.initialize();

		return client;
	}

	async send( method, params = {} ) {
		const headers = {
			...authHeaders( this.role ),
			'Content-Type': 'application/json',
			Accept: 'application/json, text/event-stream',
		};

		if ( this.sessionId ) {
			headers[ 'Mcp-Session-Id' ] = this.sessionId;
		}

		const response = await fetch( `${ this.baseUrl }${ this.route }`, {
			method: 'POST',
			headers,
			body: JSON.stringify( { jsonrpc: '2.0', id: this.nextId++, method, params } ),
		} );

		const sessionId = response.headers.get( 'mcp-session-id' );
		if ( sessionId ) {
			this.sessionId = sessionId;
		}

		const text = await response.text();

		return text ? JSON.parse( text ) : null;
	}

	async initialize() {
		const body = await this.send( 'initialize', {
			protocolVersion: PROTOCOL_VERSION,
			capabilities: {},
			clientInfo: { name: 'hm-rest-ability-evals', version: '1.0.0' },
		} );

		if ( ! this.sessionId ) {
			throw new Error( 'The server did not return an Mcp-Session-Id header.' );
		}

		return body.result;
	}

	/**
	 * @return {Promise<Array<Object>>} Every tool the server advertises.
	 */
	async listTools() {
		return ( await this.send( 'tools/list' ) ).result.tools;
	}

	/**
	 * Calls a tool and returns its raw text result.
	 *
	 * The text is what gets fed back to the model, so it is returned as-is
	 * rather than parsed.
	 *
	 * @param {string} name Tool name.
	 * @param {Object} args Tool arguments.
	 * @return {Promise<{isError: boolean, text: string}>} The result.
	 */
	async callTool( name, args ) {
		const body = await this.send( 'tools/call', { name, arguments: args } );

		if ( body.error ) {
			return { isError: true, text: JSON.stringify( body.error ) };
		}

		return {
			isError: !! body.result.isError,
			text: body.result.content?.[ 0 ]?.text ?? '',
		};
	}
}
