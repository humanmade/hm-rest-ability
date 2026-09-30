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

	test( 'adds guidance about block markup for a route with post content', async ( { request } ) => {
		const client = await McpClient.connect( request );

		const result = await rest( client, 'OPTIONS', '/wp/v2/posts' );

		expect( result.guidance ).toContain( 'holds WordPress block markup' );
	} );

	test( 'leaves block markup guidance off a route without post content', async ( { request } ) => {
		const client = await McpClient.connect( request );

		const result = await rest( client, 'OPTIONS', '/wp/v2/comments' );

		expect( result.guidance || '' ).not.toContain( 'holds WordPress block markup' );
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

	test( 'names the pattern routes when a client guesses /wp/v2/patterns', async ( { request } ) => {
		const client = await McpClient.connect( request );

		const result = await client.callTool( 'rest-api-read', { method: 'GET', route: '/wp/v2/patterns' } );

		expect( result.isError ).toBe( true );
		expect( result.text ).toContain( 'Did you mean: /wp/v2/block-patterns/patterns' );
		expect( result.text ).toContain( '/wp/v2/block-patterns/categories' );
		expect( result.text ).toContain( 'GET /wp/v2' );
	} );

	test( 'names the global styles routes when a client guesses /wp/v2/global-styles', async ( { request } ) => {
		const client = await McpClient.connect( request );

		const result = await client.callTool( 'rest-api-read', { method: 'OPTIONS', route: '/wp/v2/global-styles' } );

		expect( result.isError ).toBe( true );
		expect( result.text ).toContain( 'Did you mean: /wp/v2/global-styles/{id}, /wp/v2/global-styles/themes/{stylesheet}' );
		expect( result.text ).toContain( 'Replace each {name} placeholder' );
	} );

	test( 'says which methods a route accepts instead of calling it unknown', async ( { request } ) => {
		const client = await McpClient.connect( request );

		const result = await client.callTool( 'rest-api-write', { method: 'POST', route: '/wp/v2/block-patterns/patterns' } );

		expect( result.isError ).toBe( true );
		expect( result.text ).toBe( 'Route /wp/v2/block-patterns/patterns does not accept POST. It accepts GET.' );
	} );

	test( 'reads block patterns and global styles through the real routes', async ( { request } ) => {
		const client = await McpClient.connect( request );

		const patterns = await rest( client, 'GET', '/wp/v2/block-patterns/patterns', { _fields: 'name,title' } );

		expect( patterns.status ).toBe( 200 );
		expect( patterns.data.length ).toBeGreaterThan( 0 );
		expect( patterns.data[ 0 ] ).toHaveProperty( 'name' );

		const categories = await rest( client, 'GET', '/wp/v2/block-patterns/categories' );

		expect( categories.status ).toBe( 200 );

		const themes = await rest( client, 'GET', '/wp/v2/themes', { status: 'active' } );
		const theme = themes.data[ 0 ];
		const link = theme._links[ 'wp:user-global-styles' ][ 0 ].href;
		const id = link.split( '/' ).pop();

		expect( id ).toMatch( /^\d+$/ );

		const userStyles = await rest( client, 'GET', `/wp/v2/global-styles/${ id }`, { _fields: 'id,settings,styles' } );

		expect( userStyles.status ).toBe( 200 );
		expect( String( userStyles.data.id ) ).toBe( id );

		const themeStyles = await rest( client, 'GET', `/wp/v2/global-styles/themes/${ theme.stylesheet }` );

		expect( themeStyles.status ).toBe( 200 );
		expect( themeStyles.data ).toHaveProperty( 'settings' );
	} );

	test( 'explains where the global styles id and stylesheet come from', async ( { request } ) => {
		const client = await McpClient.connect( request );

		const byId = await rest( client, 'OPTIONS', '/wp/v2/global-styles/1' );

		expect( byId.guidance ).toContain( 'wp:user-global-styles' );

		const byTheme = await rest( client, 'OPTIONS', '/wp/v2/global-styles/themes/twentytwentyfive' );

		expect( byTheme.guidance ).toContain( 'theme directory slug' );

		const patterns = await rest( client, 'OPTIONS', '/wp/v2/block-patterns/patterns' );

		expect( patterns.guidance ).toContain( 'GET /wp/v2/blocks' );
	} );

	test( 'summarises the index by namespace on a site with too many routes', async ( { request } ) => {
		// The test mu-plugin registers 1,500 extra routes when it sees this header.
		const client = await McpClient.connect( request, 'admin', { 'X-HM-Bulk-Routes': '1' } );

		const index = await rest( client, 'GET', '/' );

		expect( index.truncated.reason ).toBe( 'response_too_large' );
		expect( index.truncated.hint ).toContain( 'GET /wp/v2' );
		expect( index.data.routes ).toBeUndefined();
		expect( index.data.route_counts[ 'bulk-test/v1' ] ).toBe( 1501 );
		expect( index.data.route_counts[ 'wp/v2' ] ).toBeGreaterThan( 50 );
		expect( index.data.namespaces ).toContain( 'wp/v2' );

		const core = await rest( client, 'GET', '/wp/v2' );

		expect( core.truncated ).toBeUndefined();
		expect( core.data.routes[ '/wp/v2/block-patterns/patterns' ] ).toEqual( [ 'GET' ] );
		expect( Object.keys( core.data.routes ).some( ( route ) => route.startsWith( '/wp/v2/global-styles/' ) ) ).toBe( true );
	} );
} );
