/**
 * Looks content up by URL through GET /hm-rest-ability/v1/url-lookup, called
 * over MCP the way a client would.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { McpClient } = require( './support/mcp-client' );

/**
 * Looks a URL up through rest-api-read and returns the ability's own result.
 *
 * @param {McpClient} client MCP client.
 * @param {string}    url    URL or path to look up.
 * @return {Promise<Object>} `{status, headers, data}` from the ability.
 */
async function lookup( client, url ) {
	const result = await client.callTool( 'rest-api-read', {
		method: 'GET',
		route: '/hm-rest-ability/v1/url-lookup',
		params: { url },
	} );

	expect( result.isError, `rest-api-read failed: ${ result.text }` ).toBe( false );

	return result.data;
}

test.describe( 'URL lookup endpoint', () => {
	let page;

	test.beforeAll( async ( { requestUtils } ) => {
		page = await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/pages',
			data: { title: 'About us over MCP', status: 'publish' },
		} );
	} );

	test( 'finds a page by its full URL, with a link to its REST resource', async ( { request } ) => {
		const client = await McpClient.connect( request );
		const result = await lookup( client, `${ page.link }?utm_source=qa#team` );

		expect( result.status ).toBe( 200 );
		expect( result.data.id ).toBe( page.id );
		expect( result.data.type ).toBe( 'page' );
		expect( result.data._links.self[ 0 ].href ).toMatch( new RegExp( `/wp/v2/pages/${ page.id }$` ) );
	} );

	test( 'finds the same page by its path and by a URL on another host', async ( { request } ) => {
		const client = await McpClient.connect( request );
		const path = new URL( page.link ).pathname;

		const byPath = await lookup( client, path );
		const byOtherHost = await lookup( client, `https://www.example.com${ path }` );

		expect( byPath.data.id ).toBe( page.id );
		expect( byOtherHost.data.id ).toBe( page.id );
	} );

	test( 'finds a custom post type on its own namespace', async ( { request, requestUtils } ) => {
		const study = await requestUtils.rest( {
			method: 'POST',
			path: '/acme/v1/case-studies',
			data: { title: 'Acme rebrand', status: 'publish' },
		} );
		const client = await McpClient.connect( request );
		const result = await lookup( client, study.link );

		expect( study.link ).toContain( '/work/' );
		expect( result.data.id ).toBe( study.id );
		expect( result.data.type ).toBe( 'case_study' );
		expect( result.data._links.self[ 0 ].href ).toMatch( new RegExp( `/acme/v1/case-studies/${ study.id }$` ) );
	} );

	test( 'returns a 404 when nothing lives at the URL', async ( { request } ) => {
		const client = await McpClient.connect( request );
		const result = await lookup( client, 'https://example.com/nothing-lives-here/' );

		expect( result.status ).toBe( 404 );
		expect( result.data.code ).toBe( 'rest_url_not_found' );
	} );

	test( 'finds a draft only for a user who can read it', async ( { request, requestUtils } ) => {
		const draft = await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/pages',
			data: { title: 'Draft over MCP', status: 'draft' },
		} );

		const admin = await lookup( await McpClient.connect( request ), draft.link );
		const subscriber = await lookup( await McpClient.connect( request, 'subscriber' ), draft.link );

		expect( admin.data.id ).toBe( draft.id );
		expect( subscriber.status ).toBe( 404 );
	} );
} );
