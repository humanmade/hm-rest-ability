/**
 * Protocol-level tests for the MCP endpoint.
 *
 * These drive the server the way a real client does — handshake, tool list,
 * tool call — rather than reading core's abilities REST routes, which answer
 * whether or not MCP Adapter is running.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { McpClient, PROTOCOL_VERSION } = require( './support/mcp-client' );

test.describe( 'MCP protocol', () => {
	test( 'registers an MCP server route', async ( { request } ) => {
		const route = await McpClient.discoverRoute( request );

		expect( route ).toBe( '/wp-json/mcp/mcp-hm-rest-ability' );
	} );

	test( 'completes the handshake and issues a session', async ( { request } ) => {
		const client = new McpClient( request, await McpClient.discoverRoute( request ) );
		const result = await client.initialize();

		expect( result.protocolVersion ).toBe( PROTOCOL_VERSION );
		expect( result.serverInfo.name ).toBe( 'HM REST Ability MCP Server' );
		expect( client.sessionId ).toBeTruthy();
	} );

	test( 'requires the session id on later calls', async ( { request } ) => {
		const client = await McpClient.connect( request );
		client.sessionId = null;

		const { body } = await client.send( 'tools/list' );

		expect( body.error.message ).toContain( 'Mcp-Session-Id' );
	} );

	test( 'refuses unauthenticated requests', async ( { request } ) => {
		const client = new McpClient( request, await McpClient.discoverRoute( request ) );

		const { status } = await client.send( 'initialize', {
			protocolVersion: PROTOCOL_VERSION,
			capabilities: {},
			clientInfo: { name: 'anon', version: '1.0.0' },
		}, { auth: false } );

		expect( status ).toBe( 401 );
	} );

	test( 'lists both abilities as tools in their own right', async ( { request } ) => {
		const client = await McpClient.connect( request );
		const names = ( await client.listTools() ).map( ( tool ) => tool.name );

		expect( names ).toContain( 'rest-api-call' );
		expect( names ).toContain( 'media-upload' );
	} );

	test( 'keeps the adapter tools available alongside them', async ( { request } ) => {
		const client = await McpClient.connect( request );
		const names = ( await client.listTools() ).map( ( tool ) => tool.name );

		expect( names ).toContain( 'mcp-adapter-discover-abilities' );
		expect( names ).toContain( 'mcp-adapter-execute-ability' );
	} );

	test( 'advertises the REST API tool schema', async ( { request } ) => {
		const client = await McpClient.connect( request );
		const tool = ( await client.listTools() ).find( ( t ) => t.name === 'rest-api-call' );

		expect( Object.keys( tool.inputSchema.properties ) ).toEqual(
			expect.arrayContaining( [ 'method', 'route', 'params' ] )
		);
		expect( tool.inputSchema.required ).toEqual(
			expect.arrayContaining( [ 'method', 'route' ] )
		);
		expect( tool.inputSchema.properties.method.enum ).toContain( 'OPTIONS' );
	} );

	test( 'advertises the media upload tool schema', async ( { request } ) => {
		const client = await McpClient.connect( request );
		const tool = ( await client.listTools() ).find( ( t ) => t.name === 'media-upload' );

		expect( tool.inputSchema.required ).toEqual(
			expect.arrayContaining( [ 'file', 'filename', 'mime_type' ] )
		);
	} );

	test( 'still reaches the abilities through the adapter wrapper', async ( { request } ) => {
		const client = await McpClient.connect( request );

		const result = await client.callTool( 'mcp-adapter-execute-ability', {
			ability_name: 'rest-api/call',
			parameters: { method: 'GET', route: '/wp/v2/users/me', params: { _fields: 'slug' } },
		} );

		expect( result.isError ).toBe( false );
		expect( result.data.data.data.slug ).toBe( 'admin' );
	} );
} );
