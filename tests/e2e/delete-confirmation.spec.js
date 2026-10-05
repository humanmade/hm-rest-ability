/**
 * Deleting through `rest-api-delete` asks the user to confirm when the client
 * speaks MCP 2026-07-28 and supports elicitation. Other clients delete
 * straight away.
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

test.describe( 'MCP delete confirmation', () => {
	test( 'asks a client that supports elicitation to confirm before deleting', async ( { request } ) => {
		const client = await McpClient.connect2026( request, 'admin', ELICITATION );
		const id = await createPost( client, 'Confirm before deleting' );

		const asked = await client.callTool( 'rest-api-delete', deleteArgs( id ) );

		expect( asked.result.resultType ).toBe( 'input_required' );
		expect( asked.result.requestState ).toBeTruthy();
		expect( asked.result.inputRequests.confirm_delete.method ).toBe( 'elicitation/create' );
		expect( asked.result.inputRequests.confirm_delete.params.message ).toContain( 'Confirm before deleting' );
		expect( await postStatus( client, id ) ).toBe( 'draft' );
	} );

	test( 'deletes once the user accepts', async ( { request } ) => {
		const client = await McpClient.connect2026( request, 'admin', ELICITATION );
		const id = await createPost( client, 'Delete after accepting' );

		const asked = await client.callTool( 'rest-api-delete', deleteArgs( id ) );
		const accepted = await client.callTool( 'rest-api-delete', deleteArgs( id ), {
			inputResponses: { confirm_delete: { action: 'accept', content: { confirm: true } } },
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
			inputResponses: { confirm_delete: { action: 'decline' } },
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
			inputResponses: { confirm_delete: { action: 'accept', content: { confirm: true } } },
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
} );
