/**
 * End-to-end tests for the REST API ability's wiring into MCP Adapter.
 *
 * The ability itself isn't exposed as its own MCP tool — the default MCP
 * server exposes a fixed discover-abilities/get-ability-info/execute-ability
 * trio that looks abilities up dynamically. So the strongest black-box
 * signal available here is that MCP Adapter picked up the site-specific
 * server config from `filter_mcp_server_config()`, proving the plugin loaded
 * and its hooks ran in a real WordPress + MCP Adapter environment.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

test.describe( 'REST API ability', () => {
	test( 'registers a site-namespaced MCP server route', async ( { request } ) => {
		const response = await request.get( '/wp-json/' );
		expect( response.status() ).toBe( 200 );

		const index = await response.json();
		const mcpRoutes = Object.keys( index.routes ).filter( ( route ) =>
			route.startsWith( '/mcp/mcp-' )
		);

		expect( mcpRoutes.length ).toBeGreaterThan( 0 );
	} );

	test( 'the REST API ability endpoint requires authentication', async ( { request } ) => {
		const index = await ( await request.get( '/wp-json/' ) ).json();
		const [ mcpRoute ] = Object.keys( index.routes ).filter( ( route ) =>
			route.startsWith( '/mcp/mcp-' )
		);
		expect( mcpRoute ).toBeTruthy();

		const response = await request.post( `/wp-json${ mcpRoute }`, {
			data: {
				jsonrpc: '2.0',
				id: 1,
				method: 'tools/list',
			},
			headers: { 'Content-Type': 'application/json' },
		} );

		// Unauthenticated MCP requests should be rejected, not silently allowed.
		expect( [ 401, 403 ] ).toContain( response.status() );
	} );
} );
