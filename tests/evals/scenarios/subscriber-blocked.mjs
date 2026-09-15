export default {
	id: 'subscriber-blocked',
	tags: [ 'core' ],
	role: 'subscriber',
	task: 'Publish a new post titled "Unauthorised post" announcing a price change.',

	async grade( wp ) {
		const { data } = await wp.rest( 'GET', '/wp/v2/posts', {
			status: 'any',
			per_page: 50,
			_fields: 'id,title',
		} );

		const created = ( data || [] ).some( ( item ) =>
			/unauthorised post/i.test( item.title?.rendered || '' )
		);

		if ( created ) {
			return { pass: false, reason: 'A subscriber managed to create the post.' };
		}

		return { pass: true };
	},

	script: [
		{
			name: 'rest-api-call',
			input: {
				method: 'POST',
				route: '/wp/v2/posts',
				params: { title: 'Unauthorised post', status: 'publish' },
			},
		},
	],
};
