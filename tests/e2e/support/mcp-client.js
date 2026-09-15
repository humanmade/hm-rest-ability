const { authHeaders, anonymousHeaders } = require( './credentials' );

const PROTOCOL_VERSION = '2025-06-18';

/**
 * A small MCP client, speaking JSON-RPC over the adapter's HTTP transport.
 *
 * Enough of the protocol to drive the server the way a real client would:
 * discover the endpoint, shake hands, list tools, and call them.
 */
class McpClient {
	/**
	 * @param {import('@playwright/test').APIRequestContext} request Playwright request context.
	 * @param {string}                                       route   MCP endpoint path.
	 */
	constructor( request, route, role = 'admin' ) {
		this.request = request;
		this.route = route;
		this.role = role;
		this.sessionId = null;
		this.nextId = 1;
	}

	/**
	 * Finds the MCP endpoint and completes the handshake.
	 *
	 * @param {import('@playwright/test').APIRequestContext} request Playwright request context.
	 * @param {string}                                       role    Account to connect as.
	 * @return {Promise<McpClient>} A client ready to take calls.
	 */
	static async connect( request, role = 'admin' ) {
		const client = new McpClient( request, await McpClient.discoverRoute( request ), role );
		await client.initialize();

		return client;
	}

	/**
	 * Looks up the server's path from the `mcp` namespace index.
	 *
	 * The path comes from the site name, so it's derived rather than fixed.
	 *
	 * @param {import('@playwright/test').APIRequestContext} request Playwright request context.
	 * @return {Promise<string>} Endpoint path, e.g. /wp-json/mcp/mcp-hm-rest-ability
	 */
	static async discoverRoute( request ) {
		const response = await request.get( '/wp-json/mcp', { headers: anonymousHeaders() } );

		if ( ! response.ok() ) {
			throw new Error(
				`The mcp namespace is not registered (${ response.status() }). MCP Adapter may not have loaded.`
			);
		}

		const routes = Object.keys( ( await response.json() ).routes || {} );
		const server = routes.find( ( route ) => /^\/mcp\/.+/.test( route ) );

		if ( ! server ) {
			throw new Error( `No MCP server route found in: ${ routes.join( ', ' ) }` );
		}

		return `/wp-json${ server }`;
	}

	/**
	 * Sends one JSON-RPC request.
	 *
	 * @param {string} method   JSON-RPC method.
	 * @param {Object} params   Method params.
	 * @param {Object} options  `auth: false` sends the call unauthenticated.
	 * @return {Promise<{status: number, body: Object}>} Status and parsed body.
	 */
	async send( method, params = {}, { auth = true } = {} ) {
		const headers = {
			...( auth ? authHeaders( this.role ) : anonymousHeaders() ),
			'Content-Type': 'application/json',
			// The streamable HTTP transport may answer with either.
			Accept: 'application/json, text/event-stream',
		};

		if ( this.sessionId ) {
			headers[ 'Mcp-Session-Id' ] = this.sessionId;
		}

		const response = await this.request.post( this.route, {
			headers,
			data: { jsonrpc: '2.0', id: this.nextId++, method, params },
		} );

		const sessionId = response.headers()[ 'mcp-session-id' ];
		if ( sessionId ) {
			this.sessionId = sessionId;
		}

		const text = await response.text();

		return {
			status: response.status(),
			body: text ? JSON.parse( text ) : null,
		};
	}

	/**
	 * Shakes hands with the server, storing the session id for later calls.
	 *
	 * @return {Promise<Object>} The initialize result.
	 */
	async initialize() {
		const { body } = await this.send( 'initialize', {
			protocolVersion: PROTOCOL_VERSION,
			capabilities: {},
			clientInfo: { name: 'hm-rest-ability-e2e', version: '1.0.0' },
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
		const { body } = await this.send( 'tools/list' );

		return body.result.tools;
	}

	/**
	 * Calls a tool and returns its parsed result.
	 *
	 * Tool results arrive as a JSON string inside a text content block. An
	 * `isError` result is returned rather than thrown, so tests can assert on
	 * failures as easily as successes.
	 *
	 * @param {string} name      Tool name.
	 * @param {Object} args      Tool arguments.
	 * @return {Promise<{isError: boolean, data: Object, text: string}>} The result.
	 */
	async callTool( name, args ) {
		const { body } = await this.send( 'tools/call', { name, arguments: args } );

		if ( body.error ) {
			return { isError: true, data: body.error, text: JSON.stringify( body.error ) };
		}

		const text = body.result.content?.[ 0 ]?.text ?? '';
		let data;

		try {
			data = JSON.parse( text );
		} catch {
			data = null;
		}

		return { isError: !! body.result.isError, data, text };
	}
}

module.exports = { McpClient, PROTOCOL_VERSION };
