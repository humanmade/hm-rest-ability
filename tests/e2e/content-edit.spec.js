/**
 * Edits part of a post's block content over MCP: find the text, preview the
 * replacement, then save it.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { McpClient } = require( './support/mcp-client' );

const CONTENT = [
	'<!-- wp:group -->',
	'<div class="wp-block-group"><!-- wp:paragraph -->',
	'<p>First paragraph</p>',
	'<!-- /wp:paragraph -->',
	'',
	'<!-- wp:paragraph -->',
	'<p>Second paragraph</p>',
	'<!-- /wp:paragraph --></div>',
	'<!-- /wp:group -->',
].join( '\n' );

test.describe( 'MCP content editing', () => {
	test( 'finds text, previews a replacement, then saves it', async ( { request } ) => {
		const client = await McpClient.connect( request );

		const created = await client.callTool( 'rest-api-write', {
			method: 'POST',
			route: '/wp/v2/pages',
			params: { title: 'Content edit fixture', content: CONTENT, status: 'draft' },
		} );

		expect( created.isError, `create failed: ${ created.text }` ).toBe( false );

		const route = `/wp/v2/pages/${ created.data.data.id }`;

		try {
			const found = await client.callTool( 'content-find', { route, search: 'Second paragraph' } );

			expect( found.isError, `content-find failed: ${ found.text }` ).toBe( false );
			expect( found.data.match_count ).toBe( 1 );
			expect( found.data.matches[ 0 ].start_line ).toBe( 7 );
			expect( found.data.matches[ 0 ].excerpt ).toContain( '<p>Second paragraph</p>' );

			const args = {
				route,
				old_string: '<p>Second paragraph</p>',
				new_string: '<p>Edited paragraph</p>',
				content_sha256: found.data.content_sha256,
			};

			const preview = await client.callTool( 'content-replace', args );

			expect( preview.isError, `dry run failed: ${ preview.text }` ).toBe( false );
			expect( preview.data.dry_run ).toBe( true );
			expect( preview.data.changes[ 0 ].excerpt ).toContain( '<p>Edited paragraph</p>' );

			const unchanged = await client.callTool( 'content-find', { route, search: 'Edited paragraph' } );

			expect( unchanged.data.match_count ).toBe( 0 );

			const saved = await client.callTool( 'content-replace', { ...args, dry_run: false } );

			expect( saved.isError, `replace failed: ${ saved.text }` ).toBe( false );
			expect( saved.data.dry_run ).toBe( false );
			expect( saved.data.verified ).toBe( true );

			const read = await client.callTool( 'rest-api-read', {
				method: 'GET',
				route,
				params: { context: 'edit', _fields: 'content' },
			} );

			expect( read.data.data.content.raw ).toBe( CONTENT.replace( 'Second paragraph', 'Edited paragraph' ) );

			const stale = await client.callTool( 'content-replace', {
				...args,
				old_string: '<p>Edited paragraph</p>',
				new_string: '<p>Again</p>',
				content_sha256: found.data.content_sha256,
				dry_run: false,
			} );

			expect( stale.isError ).toBe( true );
			expect( stale.text ).toContain( 'Call content-find again' );
		} finally {
			await client.callTool( 'rest-api-delete', {
				method: 'DELETE',
				route,
				params: { force: true },
			} );
		}
	} );
} );
