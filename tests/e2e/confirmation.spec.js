/**
 * Deletes, and writes to site-config routes, ask the user to confirm when the
 * client speaks MCP 2026-07-28 and supports elicitation. Routine writes and
 * other clients run straight away.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { McpClient } = require( './support/mcp-client' );

const ELICITATION = { elicitation: { form: {} } };

/**
 * Creates a draft post through the write tool.
 *
 * @param {McpClient} client MCP client.
 * @param {string}    title  Post title.
 * @return {Promise<number>} The post ID.
 */
async function createPost( client, title ) {
	const result = await client.callTool( 'rest-api-write', {
		method: 'POST',
		route: '/wp/v2/posts',
		params: { title, status: 'draft' },
	} );

	expect( result.isError, `rest-api-write failed: ${ result.text }` ).toBe( false );

	return result.data.data.id;
}

/**
 * Reads a post's status, or null when it no longer exists.
 *
 * @param {McpClient} client MCP client.
 * @param {number}    id     Post ID.
 * @return {Promise<string|null>} Post status.
 */
async function postStatus( client, id ) {
	const result = await client.callTool( 'rest-api-read', {
		method: 'GET',
		route: `/wp/v2/posts/${ id }`,
		params: { _fields: 'id,status' },
	} );

	return result.isError ? null : result.data.data.status;
}

/**
 * The delete tool's arguments for a post, forcing a permanent delete.
 *
 * @param {number} id Post ID.
 * @return {Object} Tool arguments.
 */
function deleteArgs( id ) {
	return { method: 'DELETE', route: `/wp/v2/posts/${ id }`, params: { force: true } };
}

/**
 * Reads the site tagline through the read tool. The tagline is used because
 * the site title names the MCP server route.
 *
 * @param {McpClient} client MCP client.
 * @return {Promise<string>} The site tagline.
 */
async function siteTagline( client ) {
	const result = await client.callTool( 'rest-api-read', {
		method: 'GET',
		route: '/wp/v2/settings',
		params: { _fields: 'description' },
	} );

	expect( result.isError, `rest-api-read failed: ${ result.text }` ).toBe( false );

	return result.data.data.description;
}

/**
 * Sets the site tagline as a 2025 client, which writes without asking.
 *
 * @param {McpClient} client MCP client.
 * @param {string}    tagline Site tagline.
 */
async function setSiteTagline( client, tagline ) {
	const result = await client.callTool( 'rest-api-write', {
		method: 'POST',
		route: '/wp/v2/settings',
		params: { description: tagline },
	} );

	expect( result.isError, `rest-api-write failed: ${ result.text }` ).toBe( false );
}

test.describe( 'MCP confirmation', () => {
	test( 'asks a client that supports elicitation to confirm before deleting', async ( { request } ) => {
		const client = await McpClient.connect2026( request, 'admin', ELICITATION );
		const id = await createPost( client, 'Confirm before deleting' );

		const asked = await client.callTool( 'rest-api-delete', deleteArgs( id ) );

		expect( asked.result.resultType ).toBe( 'input_required' );
		expect( asked.result.requestState ).toBeTruthy();
		expect( asked.result.inputRequests.confirm.method ).toBe( 'elicitation/create' );
		expect( asked.result.inputRequests.confirm.params.message ).toContain( 'Confirm before deleting' );
		expect( await postStatus( client, id ) ).toBe( 'draft' );
	} );

	test( 'deletes once the user accepts', async ( { request } ) => {
		const client = await McpClient.connect2026( request, 'admin', ELICITATION );
		const id = await createPost( client, 'Delete after accepting' );

		const asked = await client.callTool( 'rest-api-delete', deleteArgs( id ) );
		const accepted = await client.callTool( 'rest-api-delete', deleteArgs( id ), {
			inputResponses: { confirm: { action: 'accept', content: { confirm: true } } },
			requestState: asked.result.requestState,
		} );

		expect( accepted.isError, accepted.text ).toBe( false );
		expect( accepted.data.data.deleted ).toBe( true );
		expect( await postStatus( client, id ) ).toBeNull();
	} );

	test( 'does not delete when the user declines', async ( { request } ) => {
		const client = await McpClient.connect2026( request, 'admin', ELICITATION );
		const id = await createPost( client, 'Keep after declining' );

		const asked = await client.callTool( 'rest-api-delete', deleteArgs( id ) );
		const declined = await client.callTool( 'rest-api-delete', deleteArgs( id ), {
			inputResponses: { confirm: { action: 'decline' } },
			requestState: asked.result.requestState,
		} );

		expect( declined.isError ).toBe( true );
		expect( await postStatus( client, id ) ).toBe( 'draft' );
	} );

	test( 'does not delete a different post with a confirmation for another', async ( { request } ) => {
		const client = await McpClient.connect2026( request, 'admin', ELICITATION );
		const confirmed = await createPost( client, 'Confirmed post' );
		const other = await createPost( client, 'Other post' );

		const asked = await client.callTool( 'rest-api-delete', deleteArgs( confirmed ) );
		const retried = await client.callTool( 'rest-api-delete', deleteArgs( other ), {
			inputResponses: { confirm: { action: 'accept', content: { confirm: true } } },
			requestState: asked.result.requestState,
		} );

		expect( retried.isError ).toBe( true );
		expect( await postStatus( client, other ) ).toBe( 'draft' );
	} );

	test( 'deletes straight away for a 2026 client without elicitation', async ( { request } ) => {
		const client = await McpClient.connect2026( request, 'admin', {} );
		const id = await createPost( client, 'Delete without asking' );

		const deleted = await client.callTool( 'rest-api-delete', deleteArgs( id ) );

		expect( deleted.isError, deleted.text ).toBe( false );
		expect( deleted.result.resultType ).not.toBe( 'input_required' );
		expect( deleted.data.data.deleted ).toBe( true );
		expect( await postStatus( client, id ) ).toBeNull();
	} );

	test( 'deletes straight away for a 2025 client', async ( { request } ) => {
		const client = await McpClient.connect( request );
		const id = await createPost( client, 'Delete for a 2025 client' );

		const deleted = await client.callTool( 'rest-api-delete', deleteArgs( id ) );

		expect( deleted.isError, deleted.text ).toBe( false );
		expect( deleted.data.data.deleted ).toBe( true );
		expect( await postStatus( client, id ) ).toBeNull();
	} );

	test( 'refuses to delete through the generic execute-ability tool', async ( { request } ) => {
		const client = await McpClient.connect( request );
		const id = await createPost( client, 'Keep out of execute-ability' );

		const result = await client.callTool( 'mcp-adapter-execute-ability', {
			ability_name: 'rest-api/delete',
			parameters: deleteArgs( id ),
		} );

		expect( result.isError ).toBe( true );
		expect( await postStatus( client, id ) ).toBe( 'draft' );
	} );

	test( 'asks to confirm a settings write, then applies it once the user accepts', async ( { request } ) => {
		const client = await McpClient.connect2026( request, 'admin', ELICITATION );
		const legacy = await McpClient.connect( request );
		const original = await siteTagline( client );
		const args = { method: 'POST', route: '/wp/v2/settings', params: { description: 'Confirmed tagline' } };

		try {
			const asked = await client.callTool( 'rest-api-write', args );

			expect( asked.result.resultType ).toBe( 'input_required' );
			expect( asked.result.inputRequests.confirm.params.message ).toContain( '/wp/v2/settings' );
			expect( await siteTagline( client ) ).toBe( original );

			const accepted = await client.callTool( 'rest-api-write', args, {
				inputResponses: { confirm: { action: 'accept', content: { confirm: true } } },
				requestState: asked.result.requestState,
			} );

			expect( accepted.isError, accepted.text ).toBe( false );
			expect( await siteTagline( client ) ).toBe( 'Confirmed tagline' );
		} finally {
			await setSiteTagline( legacy, original );
		}

		expect( await siteTagline( client ) ).toBe( original );
	} );

	test( 'does not change a setting when the user declines', async ( { request } ) => {
		const client = await McpClient.connect2026( request, 'admin', ELICITATION );
		const original = await siteTagline( client );
		const args = { method: 'POST', route: '/wp/v2/settings', params: { description: 'Declined tagline' } };

		const asked = await client.callTool( 'rest-api-write', args );
		const declined = await client.callTool( 'rest-api-write', args, {
			inputResponses: { confirm: { action: 'decline' } },
			requestState: asked.result.requestState,
		} );

		expect( declined.isError ).toBe( true );
		expect( await siteTagline( client ) ).toBe( original );
	} );

	test( 'runs a routine write without asking', async ( { request } ) => {
		const client = await McpClient.connect2026( request, 'admin', ELICITATION );
		const created = await client.callTool( 'rest-api-write', {
			method: 'POST',
			route: '/wp/v2/posts',
			params: { title: 'Routine write', status: 'draft' },
		} );

		expect( created.isError, created.text ).toBe( false );
		expect( created.result.resultType ).not.toBe( 'input_required' );

		const id = created.data.data.id;
		const updated = await client.callTool( 'rest-api-write', {
			method: 'POST',
			route: `/wp/v2/posts/${ id }`,
			params: { title: 'Routine write, updated' },
		} );

		expect( updated.isError, updated.text ).toBe( false );
		expect( updated.result.resultType ).not.toBe( 'input_required' );
	} );

	test( 'refuses a DELETE sent to the write tool', async ( { request } ) => {
		const client = await McpClient.connect2026( request, 'admin', {} );
		const id = await createPost( client, 'Keep out of the write tool' );

		const result = await client.callTool( 'rest-api-write', deleteArgs( id ) );

		expect( result.isError ).toBe( true );
		expect( await postStatus( client, id ) ).toBe( 'draft' );
	} );

	test( 'refuses to write through the generic execute-ability tool', async ( { request } ) => {
		const client = await McpClient.connect( request );

		const result = await client.callTool( 'mcp-adapter-execute-ability', {
			ability_name: 'rest-api/write',
			parameters: { method: 'POST', route: '/wp/v2/posts', params: { title: 'Should not exist', status: 'draft' } },
		} );

		expect( result.isError ).toBe( true );

		const found = await client.callTool( 'rest-api-read', {
			method: 'GET',
			route: '/wp/v2/posts',
			params: { search: 'Should not exist', status: 'any', _fields: 'id' },
		} );

		expect( found.data.data ).toEqual( [] );
	} );
} );
