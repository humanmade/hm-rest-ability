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

	test( 'reads a single plugin by its directory and file name', async ( { request } ) => {
		const client = await McpClient.connect( request );

		const plugin = await rest( client, 'GET', '/wp/v2/plugins/hm-rest-ability/hm-rest-ability', { _fields: 'plugin,status' } );

		expect( plugin.status ).toBe( 200 );
		expect( plugin.data.plugin ).toBe( 'hm-rest-ability/hm-rest-ability' );
		expect( plugin.data.status ).toBe( 'active' );
	} );

	test( 'reads a route whose callback returns a plain object', async ( { request } ) => {
		// The test mu-plugin's route returns a WP_Post, which core's output validation would reject as it stands.
		const client = await McpClient.connect( request );

		const result = await rest( client, 'GET', '/object-test/v1/post' );

		expect( result.status ).toBe( 200 );
		expect( result.data.ID ).toBe( 1 );
		expect( result.data.post_title ).toBe( 'Object fixture' );
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

test( 'preserves requested embedded authors and leaves unrequested relations absent', async ( { request } ) => {
	const client = await McpClient.connect( request );
	const created = await rest( client, 'POST', '/wp/v2/posts', { title: 'Embedded relation fixture', status: 'draft' } );
	const route = `/wp/v2/posts/${ created.data.id }`;
	try {
		const plain = await rest( client, 'GET', route );
		expect( plain.data._embedded ).toBeUndefined();
		const embedded = await rest( client, 'GET', route, { _embed: 'author', _fields: 'id,author,_links,_embedded' } );
		expect( embedded.data._embedded.author[ 0 ].id ).toBe( plain.data.author );
		expect( embedded.data._embedded[ 'wp:term' ] ).toBeUndefined();
	} finally {
		await rest( client, 'DELETE', route, { force: true } );
	}
} );

test( 'continues a large raw body within the cap and rejects a changed response', async ( { request } ) => {
	const client = await McpClient.connect( request );
	const content = 'A paragraph with Unicode café. '.repeat( 2000 );
	const created = await rest( client, 'POST', '/wp/v2/pages', { title: 'Continuation fixture', content, status: 'draft' } );
	const route = `/wp/v2/pages/${ created.data.id }`;
	const args = { method: 'GET', route, params: { context: 'edit', _fields: 'id,content.raw' } };
	try {
		const full = await rest( client, 'GET', route, { context: 'edit' } );
		expect( created.truncated.sha256 ).toBe( full.truncated.sha256 );
		const initial = await client.callTool( 'rest-api-read', args );
		expect( initial.data.truncated.response_cursor ).toEqual( expect.any( String ) );
		const firstCursor = initial.data.truncated.response_cursor;
		const refused = await client.callTool( 'rest-api-write', { method: 'POST', route, params: { title: 'Must not be written' }, response_cursor: firstCursor } );
		expect( refused.data.status ).toBe( 400 );
		const unchanged = await rest( client, 'GET', route, { _fields: 'id,title' } );
		expect( unchanged.data.title.rendered ).toBe( 'Continuation fixture' );
		let cursor = firstCursor;
		const chunks = [];
		do {
			const chunk = await client.callTool( 'rest-api-read', { ...args, response_cursor: cursor } );
			expect( chunk.isError ).toBe( false );
			expect( Buffer.byteLength( JSON.stringify( chunk.data.data ) ) ).toBeLessThanOrEqual( 50000 );
			expect( chunk.data.response.encoding ).toBe( 'base64-json' );
			chunks.push( Buffer.from( chunk.data.data, 'base64' ) );
			cursor = chunk.data.response.next_cursor;
		} while ( cursor );
		expect( JSON.parse( Buffer.concat( chunks ).toString( 'utf8' ) ).content.raw ).toBe( content );
		await rest( client, 'POST', route, { content: 'Changed since the first read.' } );
		const stale = await client.callTool( 'rest-api-read', { ...args, response_cursor: firstCursor } );
		expect( stale.data.status ).toBe( 409 );
		expect( stale.data.data.code ).toBe( 'rest_response_changed' );
		const subscriber = await McpClient.connect( request, 'subscriber' );
		const denied = await subscriber.callTool( 'rest-api-read', { ...args, response_cursor: firstCursor } );
		expect( denied.isError ).toBe( true );
	} finally {
		await rest( client, 'DELETE', route, { force: true } );
	}
} );
