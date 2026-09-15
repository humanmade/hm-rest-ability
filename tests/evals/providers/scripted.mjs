/**
 * Collects the text of every tool result seen so far.
 *
 * @param {Array<Object>} messages Conversation so far.
 * @return {Array<string>} Tool result texts, oldest first.
 */
function toolResults( messages ) {
	return messages
		.filter( ( message ) => message.role === 'user' && Array.isArray( message.content ) )
		.flatMap( ( message ) => message.content )
		.filter( ( block ) => block.type === 'tool_result' )
		.map( ( block ) => block.content );
}

/**
 * A provider that replays a fixed sequence of tool calls instead of asking a
 * model.
 *
 * This exists so the harness itself can be tested without spending tokens or
 * needing an API key. It exercises everything except the model: the MCP
 * connection, tool conversion, the turn loop, result truncation, scenario
 * setup and grading. If the scripted run fails, the problem is the harness,
 * not the model.
 *
 * Each scenario supplies its own script via a `script` export.
 */
export class ScriptedProvider {
	constructor() {
		this.steps = [];
		this.index = 0;
	}

	get name() {
		return 'scripted';
	}

	/**
	 * Loads the tool calls to replay for the next scenario.
	 *
	 * @param {Array<Object>} steps Tool calls, as `{name, input}`.
	 */
	load( steps ) {
		this.steps = steps || [];
		this.index = 0;
	}

	prepareTools( tools ) {
		return tools;
	}

	async step( { messages = [] } = {} ) {
		const entry = this.steps[ this.index++ ];
		// Later steps often need an ID from an earlier result, so a script
		// entry may be a function of the results so far.
		const next = typeof entry === 'function' ? entry( toolResults( messages ) ) : entry;

		if ( ! next ) {
			return {
				content: [ { type: 'text', text: 'Done.' } ],
				stopReason: 'end_turn',
				usage: { input: 0, output: 0, cacheRead: 0, cacheWrite: 0 },
			};
		}

		return {
			content: [
				{
					type: 'tool_use',
					id: `scripted_${ this.index }`,
					name: next.name,
					input: next.input,
				},
			],
			stopReason: 'tool_use',
			usage: { input: 0, output: 0, cacheRead: 0, cacheWrite: 0 },
		};
	}

	cost() {
		return 0;
	}
}
