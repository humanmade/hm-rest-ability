/**
 * Drives real content operations through the MCP endpoint, the way a client
 * would: create, read, update, delete, taxonomy, media upload, and the
 * permission boundary.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { McpClient } = require( './support/mcp-client' );

/**
 * A one pixel transparent PNG.
 */
const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

/**
 * Which of the three REST API tools handles each HTTP method.
 */
const TOOL_BY_METHOD = {
	GET: 'rest-api-read',
	OPTIONS: 'rest-api-read',
	POST: 'rest-api-write',
	PUT: 'rest-api-write',
	PATCH: 'rest-api-write',
	DELETE: 'rest-api-delete',
};

/**
 * Calls the REST API tool matching the method and returns the ability's own
 * result.
 *
 * @param {McpClient} client MCP client.
 * @param {string}    method HTTP method.
 * @param {string}    route  REST route.
 * @param {Object}    params Query or body params.
 * @return {Promise<Object>} `{status, headers, data}` from the ability.
 */
async function rest( client, method, route, params = {} ) {
	const tool = TOOL_BY_METHOD[ method ];
	const result = await client.callTool( tool, { method, route, params } );

	expect( result.isError, `${ tool } failed: ${ result.text }` ).toBe( false );

	return result.data;
}

test.describe( 'MCP content operations', () => {
	test( 'creates a post, reads it back, publishes it, then deletes it', async ( { request } ) => {
		const client = await McpClient.connect( request );

		const created = await rest( client, 'POST', '/wp/v2/posts', {
			title: 'Created over MCP',
			content: 'Hello from a tool call.',
			status: 'draft',
		} );

		expect( created.status ).toBe( 201 );
		expect( created.data.status ).toBe( 'draft' );

		const id = created.data.id;

		const read = await rest( client, 'GET', `/wp/v2/posts/${ id }`, { _fields: 'id,title,status' } );

		expect( read.status ).toBe( 200 );
		expect( read.data.title.rendered ).toBe( 'Created over MCP' );

		const updated = await rest( client, 'POST', `/wp/v2/posts/${ id }`, {
			title: 'Published over MCP',
			status: 'publish',
		} );

		expect( updated.status ).toBe( 200 );
		expect( updated.data.status ).toBe( 'publish' );

		const trashed = await rest( client, 'DELETE', `/wp/v2/posts/${ id }` );

		expect( trashed.data.status ).toBe( 'trash' );

		const deleted = await rest( client, 'DELETE', `/wp/v2/posts/${ id }`, { force: true } );

		expect( deleted.data.deleted ).toBe( true );

		// Core's permission callback for a single post rejects a missing ID
		// before the request is dispatched, so this surfaces as a tool error
		// rather than a 404 body.
		const gone = await client.callTool( 'rest-api-read', {
			method: 'GET',
			route: `/wp/v2/posts/${ id }`,
			params: {},
		} );

		expect( gone.isError ).toBe( true );
		expect( gone.text ).toContain( 'Invalid post ID' );
	} );

	test( 'creates a category and assigns it to a post', async ( { request } ) => {
		const client = await McpClient.connect( request );

		const category = await rest( client, 'POST', '/wp/v2/categories', {
			name: `Tooling ${ Date.now() }`,
		} );

		expect( category.status ).toBe( 201 );

		const post = await rest( client, 'POST', '/wp/v2/posts', {
			title: 'Categorised over MCP',
			status: 'draft',
			categories: [ category.data.id ],
		} );

		expect( post.status ).toBe( 201 );
		expect( post.data.categories ).toContain( category.data.id );
	} );

	test( 'uploads a file and attaches it to a post', async ( { request } ) => {
		const client = await McpClient.connect( request );

		const post = await rest( client, 'POST', '/wp/v2/posts', {
			title: 'Post with an image',
			status: 'draft',
		} );

		const upload = await client.callTool( 'media-upload', {
			file: PNG,
			filename: 'pixel.png',
			mime_type: 'image/png',
			alt_text: 'A single pixel',
			post: post.data.id,
		} );

		expect( upload.isError, `media-upload failed: ${ upload.text }` ).toBe( false );
		expect( upload.data.status ).toBe( 201 );
		expect( upload.data.mime_type ).toBe( 'image/png' );
		expect( upload.data.source_url ).toMatch( /\/pixel\.png$/ );

		const attachment = await rest( client, 'GET', `/wp/v2/media/${ upload.data.id }`, {
			_fields: 'id,mime_type,post',
		} );

		expect( attachment.status ).toBe( 200 );
		expect( attachment.data.post ).toBe( post.data.id );

		const featured = await rest( client, 'POST', `/wp/v2/posts/${ post.data.id }`, {
			featured_media: upload.data.id,
		} );

		expect( featured.data.featured_media ).toBe( upload.data.id );
	} );

	test( 'rejects an upload that is not valid base64', async ( { request } ) => {
		const client = await McpClient.connect( request );

		const upload = await client.callTool( 'media-upload', {
			file: 'not base64 !!!',
			filename: 'pixel.png',
			mime_type: 'image/png',
		} );

		expect( upload.data.code ).toBe( 'hm_media_invalid_base64' );
	} );
} );

test.describe( 'MCP permissions', () => {
	test( 'lets a subscriber read published posts', async ( { request } ) => {
		const client = await McpClient.connect( request, 'subscriber' );

		const result = await rest( client, 'GET', '/wp/v2/posts', { per_page: 1, _fields: 'id' } );

		expect( result.status ).toBe( 200 );
	} );

	test( 'stops a subscriber creating a post', async ( { request } ) => {
		const client = await McpClient.connect( request, 'subscriber' );

		const result = await client.callTool( 'rest-api-write', {
			method: 'POST',
			route: '/wp/v2/posts',
			params: { title: 'Should not exist', status: 'publish' },
		} );

		expect( result.isError ).toBe( true );
		expect( result.text ).not.toContain( 'Fatal error' );
	} );

	test( 'stops a subscriber uploading a file', async ( { request } ) => {
		const client = await McpClient.connect( request, 'subscriber' );

		const result = await client.callTool( 'media-upload', {
			file: PNG,
			filename: 'pixel.png',
			mime_type: 'image/png',
		} );

		expect( result.isError ).toBe( true );
	} );
} );

test.describe( 'MCP discovery', () => {
	test( 'returns every route and its methods, within the cap', async ( { request } ) => {
		const client = await McpClient.connect( request );

		const result = await client.callTool( 'rest-api-read', { method: 'GET', route: '/' } );
		const routes = result.data.data.routes;

		expect( result.text.length ).toBeLessThan( 50000 );
		expect( result.data.truncated ).toBeUndefined();
		expect( Object.keys( routes ).length ).toBeGreaterThan( 50 );
		expect( routes[ '/wp/v2/posts' ] ).toEqual(
			expect.arrayContaining( [ 'GET', 'POST' ] )
		);
	} );

	test( 'returns one route\'s parameters for OPTIONS', async ( { request } ) => {
		const client = await McpClient.connect( request );

		const result = await rest( client, 'OPTIONS', '/wp/v2/posts' );

		expect( result.status ).toBe( 200 );
		expect( result.data.methods ).toEqual( expect.arrayContaining( [ 'GET', 'POST' ] ) );

		const args = result.data.endpoints.flatMap( ( endpoint ) => Object.keys( endpoint.args || {} ) );

		expect( args ).toContain( 'title' );
		expect( args ).toContain( 'status' );
	} );

	test( 'adds guidance about the status field for a publishable route', async ( { request } ) => {
		const client = await McpClient.connect( request );

		const result = await rest( client, 'OPTIONS', '/wp/v2/posts' );

		expect( result.guidance ).toContain( 'already defaults to draft' );
	} );

	test( 'denies an unknown route rather than dispatching it', async ( { request } ) => {
		const client = await McpClient.connect( request );

		const result = await client.callTool( 'rest-api-read', {
			method: 'OPTIONS',
			route: '/wp/v2/not-a-route',
		} );

		expect( result.isError ).toBe( true );
		expect( result.text ).toContain( 'No route matches' );
	} );
} );
