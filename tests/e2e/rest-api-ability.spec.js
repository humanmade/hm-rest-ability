/**
 * End-to-end tests for the `rest-api/call` ability's registration.
 *
 * The ability itself isn't visible through WordPress core's generic REST
 * abilities browser (`/wp-abilities/v1/abilities`) — it only sets
 * `meta.mcp.public`, MCP Adapter's own per-channel visibility flag, not
 * core's separate `meta.show_in_rest` flag. That's intentional: it's meant
 * for MCP clients, not as a general-purpose REST-browsable ability. So the
 * strongest available black-box signal is its category, which core's
 * categories endpoint lists unfiltered.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

test.describe( 'REST API ability', () => {
	test( 'the categories list requires authentication', async ( { request } ) => {
		const response = await request.get( '/wp-json/wp-abilities/v1/categories' );

		expect( response.status() ).toBe( 401 );
	} );

	test( 'registers the rest-api ability category', async ( { requestUtils } ) => {
		const categories = await requestUtils.rest( {
			path: '/wp-abilities/v1/categories',
		} );

		const restApiCategory = categories.find( ( category ) => category.slug === 'rest-api' );

		expect( restApiCategory ).toBeTruthy();
		expect( restApiCategory.label ).toBe( 'REST API' );
	} );
} );
