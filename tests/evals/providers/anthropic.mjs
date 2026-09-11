import Anthropic from '@anthropic-ai/sdk';

const DEFAULT_MODEL = 'claude-haiku-4-5';

/**
 * Per-million-token prices, for reporting what a run cost.
 *
 * @see https://www.anthropic.com/pricing
 */
const PRICES = {
	'claude-haiku-4-5': { input: 1, output: 5 },
	'claude-sonnet-5': { input: 2, output: 10 },
	'claude-opus-5': { input: 5, output: 25 },
};

/**
 * Runs scenarios against a Claude model.
 *
 * Token spend is held down three ways: the system prompt and tool block are
 * cached, tool results are truncated before they go back into context, and the
 * loop is capped at a small number of turns.
 */
export class AnthropicProvider {
	constructor( { model = process.env.EVAL_MODEL || DEFAULT_MODEL } = {} ) {
		this.model = model;
		this.client = new Anthropic();
	}

	get name() {
		return `anthropic:${ this.model }`;
	}

	/**
	 * Converts MCP tool definitions into Anthropic tool definitions.
	 *
	 * The definitions come from the live server, so the eval measures the
	 * schema that actually ships.
	 *
	 * @param {Array<Object>} tools MCP tools.
	 * @return {Array<Object>} Anthropic tools.
	 */
	prepareTools( tools ) {
		return tools.map( ( tool, index ) => ( {
			name: tool.name,
			description: tool.description,
			input_schema: tool.inputSchema,
			// Caching the last tool caches the whole tools + system prefix.
			...( index === tools.length - 1
				? { cache_control: { type: 'ephemeral' } }
				: {} ),
		} ) );
	}

	/**
	 * Asks the model for its next step.
	 *
	 * @param {Object} options System prompt, tools and messages.
	 * @return {Promise<{content: Array, stopReason: string, usage: Object}>} The reply.
	 */
	async step( { system, tools, messages, maxTokens } ) {
		const response = await this.client.messages.create( {
			model: this.model,
			max_tokens: maxTokens,
			system,
			tools,
			messages,
		} );

		return {
			content: response.content,
			stopReason: response.stop_reason,
			usage: {
				input: response.usage.input_tokens,
				output: response.usage.output_tokens,
				cacheRead: response.usage.cache_read_input_tokens ?? 0,
				cacheWrite: response.usage.cache_creation_input_tokens ?? 0,
			},
		};
	}

	/**
	 * Estimates what a run cost, in US dollars.
	 *
	 * @param {Object} usage Accumulated token counts.
	 * @return {number} Estimated cost.
	 */
	cost( usage ) {
		const price = PRICES[ this.model ];

		if ( ! price ) {
			return 0;
		}

		const input = usage.input + usage.cacheWrite * 1.25 + usage.cacheRead * 0.1;

		return ( input * price.input + usage.output * price.output ) / 1_000_000;
	}
}
