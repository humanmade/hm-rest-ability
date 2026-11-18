/**
 * Edits part of a post's block content over MCP with _content_edits on
 * rest-api-write, without sending the whole content back.
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
	test( 'edits part of the content, chains a second edit, and refuses stale or broken ones', async ( { request } ) => {
		const client = await McpClient.connect( request );

		const created = await client.callTool( 'rest-api-write', {
			method: 'POST',
			route: '/wp/v2/pages',
			params: { title: 'Content edit fixture', content: CONTENT, status: 'draft' },
		} );

		expect( created.isError, `create failed: ${ created.text }` ).toBe( false );

		const route = `/wp/v2/pages/${ created.data.data.id }`;

		try {
			const read = await client.callTool( 'rest-api-read', {
				method: 'GET',
				route,
				params: { context: 'edit', _fields: 'content' },
			} );

			expect( read.isError, `read failed: ${ read.text }` ).toBe( false );
			expect( typeof read.data.content_sha256 ).toBe( 'string' );

			const edit = ( edits, hash ) =>
				client.callTool( 'rest-api-write', {
					method: 'POST',
					route,
					params: { _content_edits: edits, _content_sha256: hash, _fields: 'content' },
				} );

			const first = await edit(
				[ { old_string: '<p>Second paragraph</p>', new_string: '<p>Edited paragraph</p>' } ],
				read.data.content_sha256
			);

			expect( first.isError, `edit failed: ${ first.text }` ).toBe( false );
			expect( first.data.data.content.raw ).toBe( CONTENT.replace( 'Second paragraph', 'Edited paragraph' ) );

			// The write returns the new hash, so the next edit needs no read.
			const second = await edit(
				[ { old_string: '<p>First paragraph</p>', new_string: '<p>Opening paragraph</p>' } ],
				first.data.content_sha256
			);

			expect( second.isError, `chained edit failed: ${ second.text }` ).toBe( false );
			expect( second.data.data.content.raw ).toBe(
				CONTENT.replace( 'Second paragraph', 'Edited paragraph' ).replace( 'First paragraph', 'Opening paragraph' )
			);

			const stale = await edit(
				[ { old_string: '<p>Edited paragraph</p>', new_string: '<p>Again</p>' } ],
				read.data.content_sha256
			);

			expect( stale.isError ).toBe( true );
			expect( stale.text ).toContain( 'The content changed since you read it' );

			const broken = await edit(
				[
					{
						old_string: '<p>Edited paragraph</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:group -->',
						new_string: '<p>Edited paragraph</p></div>\n<!-- /wp:group -->\n<!-- /wp:paragraph -->',
					},
				],
				second.data.content_sha256
			);

			expect( broken.isError ).toBe( true );
			expect( broken.text ).toContain( 'break the nesting of block comments' );

			const after = await client.callTool( 'rest-api-read', {
				method: 'GET',
				route,
				params: { context: 'edit', _fields: 'content' },
			} );

			expect( after.data.content_sha256 ).toBe( second.data.content_sha256 );
		} finally {
			await client.callTool( 'rest-api-delete', {
				method: 'DELETE',
				route,
				params: { force: true },
			} );
		}
	} );
} );
