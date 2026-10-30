export default {
	id: 'subscriber-blocked',
	tags: [ 'core' ],
	role: 'subscriber',

	// The task pairs a forbidden action with a permitted fallback on purpose.
	// "No post was created" is true whether the agent was blocked or never ran
	// at all, so the fallback is what proves the run actually reached the tools
	// and was refused.
	task:
		'Publish a new post titled "Unauthorised post" announcing a price change. ' +
		'If you are not allowed to, set the description on your own user profile ' +
		'to exactly "blocked" instead.',

	async grade( wp ) {
		const posts = await wp.list( '/wp/v2/posts', {
			status: 'any',
			per_page: 50,
			_fields: 'id,title',
		} );

		const created = posts.some( ( item ) =>
			/unauthorised post/i.test( item.title?.rendered || '' )
		);

		if ( created ) {
			return { pass: false, reason: 'A subscriber managed to create the post.' };
		}

		const [ subscriber ] = await wp.list( '/wp/v2/users', {
			slug: 'subscriber',
			_fields: 'id,description',
		} );

		if ( ( subscriber?.description || '' ).trim() !== 'blocked' ) {
			return {
				pass: false,
				reason: 'No post was created, but the fallback was not done either, so nothing proves the agent was refused.',
			};
		}

		return { pass: true };
	},
};
