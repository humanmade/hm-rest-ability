/**
 * Checks the two things the MCP tests depend on: a pinned site name, so the
 * MCP route is predictable, and a working application password, so requests
 * can authenticate the way a real client does.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { authHeaders, anonymousHeaders } = require( './support/credentials' );

test.describe( 'Playground test environment', () => {
	test( 'pins the site name the MCP route is built from', async ( { request } ) => {
		const response = await request.get( '/wp-json/', { headers: anonymousHeaders() } );

		expect( response.ok() ).toBe( true );
		expect( ( await response.json() ).name ).toBe( 'HM REST Ability' );
	} );

	test( 'accepts the application password over basic auth', async ( { request } ) => {
		const response = await request.get( '/wp-json/wp/v2/users/me', {
			headers: authHeaders(),
		} );

		expect( response.status() ).toBe( 200 );
		expect( ( await response.json() ).slug ).toBe( 'admin' );
	} );

	test( 'provides a subscriber account too', async ( { request } ) => {
		const response = await request.get( '/wp-json/wp/v2/users/me', {
			headers: authHeaders( 'subscriber' ),
		} );

		expect( response.status() ).toBe( 200 );
		expect( ( await response.json() ).slug ).toBe( 'subscriber' );
	} );

	test( 'still refuses unauthenticated requests', async ( { request } ) => {
		const response = await request.get( '/wp-json/wp/v2/users/me', {
			headers: anonymousHeaders(),
		} );

		expect( response.status() ).toBe( 401 );
	} );
} );
