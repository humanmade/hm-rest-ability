/**
 * End-to-end tests for the OAuth2 discovery endpoints.
 *
 * These are headless API endpoints, not block-editor UI, so requests are
 * made directly against Playground with Playwright's `request` fixture.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

test.describe( 'OAuth2 discovery', () => {
	test( 'serves RFC 8414 authorization server metadata', async ( { request, baseURL } ) => {
		const response = await request.get( '/.well-known/oauth-authorization-server' );

		expect( response.status() ).toBe( 200 );
		expect( response.headers()['content-type'] ).toContain( 'application/json' );

		const metadata = await response.json();
		expect( metadata.issuer.startsWith( baseURL ) ).toBe( true );
		expect( metadata.authorization_endpoint ).toContain( '/wp-json/oauth2/authorize' );
		expect( metadata.token_endpoint ).toContain( '/wp-json/oauth2/access_token' );
		expect( metadata.grant_types_supported ).toContain( 'authorization_code' );
		expect( metadata.code_challenge_methods_supported ).toContain( 'S256' );
	} );

	test( 'serves RFC 9728 protected resource metadata', async ( { request, baseURL } ) => {
		const response = await request.get( '/.well-known/oauth-protected-resource' );

		expect( response.status() ).toBe( 200 );

		const metadata = await response.json();
		expect( metadata.resource.startsWith( baseURL ) ).toBe( true );
		expect( metadata.authorization_servers[ 0 ].startsWith( baseURL ) ).toBe( true );
	} );

	test( 'serves both documents at the trailing-slash URL', async ( { request } ) => {
		for ( const path of [ '/.well-known/oauth-authorization-server/', '/.well-known/oauth-protected-resource/' ] ) {
			const response = await request.get( path );

			expect( response.status(), path ).toBe( 200 );
			expect( response.headers()['content-type'], path ).toContain( 'application/json' );
		}
	} );

	test( 'unrelated .well-known paths fall through to the normal 404', async ( { request } ) => {
		const response = await request.get( '/.well-known/does-not-exist' );

		expect( response.status() ).not.toBe( 200 );
	} );
} );
