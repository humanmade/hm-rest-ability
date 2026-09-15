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

	test( 'lists all four abilities as tools in their own right', async ( { request } ) => {
		const client = await McpClient.connect( request );
		const names = ( await client.listTools() ).map( ( tool ) => tool.name );

		expect( names ).toContain( 'rest-api-read' );
		expect( names ).toContain( 'rest-api-write' );
		expect( names ).toContain( 'rest-api-delete' );
		expect( names ).toContain( 'media-upload' );
	} );

	test( 'keeps the adapter tools available alongside them', async ( { request } ) => {
		const client = await McpClient.connect( request );
		const names = ( await client.listTools() ).map( ( tool ) => tool.name );

		expect( names ).toContain( 'mcp-adapter-discover-abilities' );
		expect( names ).toContain( 'mcp-adapter-execute-ability' );
	} );

	test( 'advertises each REST API tool schema, narrowed to its own methods', async ( { request } ) => {
		const client = await McpClient.connect( request );
		const tools = await client.listTools();

		const read = tools.find( ( t ) => t.name === 'rest-api-read' );
		const write = tools.find( ( t ) => t.name === 'rest-api-write' );
		const del = tools.find( ( t ) => t.name === 'rest-api-delete' );

		for ( const tool of [ read, write, del ] ) {
			expect( Object.keys( tool.inputSchema.properties ) ).toEqual(
				expect.arrayContaining( [ 'method', 'route', 'params' ] )
			);
			expect( tool.inputSchema.required ).toEqual(
				expect.arrayContaining( [ 'method', 'route' ] )
			);
		}

		expect( read.inputSchema.properties.method.enum ).toEqual( [ 'GET', 'OPTIONS' ] );
		expect( write.inputSchema.properties.method.enum ).toEqual( [ 'POST', 'PUT', 'PATCH' ] );
		expect( del.inputSchema.properties.method.enum ).toEqual( [ 'DELETE' ] );
	} );

	test( 'advertises the REST API annotations honestly per tool', async ( { request } ) => {
		const client = await McpClient.connect( request );
		const tools = await client.listTools();

		const read = tools.find( ( t ) => t.name === 'rest-api-read' );
		const write = tools.find( ( t ) => t.name === 'rest-api-write' );
		const del = tools.find( ( t ) => t.name === 'rest-api-delete' );

		expect( read.annotations.readOnlyHint ).toBe( true );
		expect( read.annotations.destructiveHint ).toBe( false );
		expect( read.annotations.idempotentHint ).toBe( true );

		expect( write.annotations.readOnlyHint ).toBe( false );
		expect( write.annotations.destructiveHint ).toBe( false );
		expect( write.annotations.idempotentHint ).toBe( false );

		expect( del.annotations.readOnlyHint ).toBe( false );
		expect( del.annotations.destructiveHint ).toBe( true );
		expect( del.annotations.idempotentHint ).toBe( false );

		for ( const tool of [ read, write, del ] ) {
			expect( tool.annotations.openWorldHint ).toBe( true );
		}
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
			ability_name: 'rest-api/read',
			parameters: { method: 'GET', route: '/wp/v2/users/me', params: { _fields: 'slug' } },
		} );

		expect( result.isError ).toBe( false );
		expect( result.data.data.data.slug ).toBe( 'admin' );
	} );
} );
