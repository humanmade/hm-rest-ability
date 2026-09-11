export default {
	id: 'publish-draft',
	tags: [ 'core' ],
	task: 'There is a draft post called "Release notes". Publish it, and change its title to "Release notes 2.0".',

	async setup( wp ) {
		await wp.rest( 'POST', '/wp/v2/posts', {
			title: 'Release notes',
			content: 'What changed in this release.',
			status: 'draft',
		} );
	},

	async grade( wp ) {
		const { data } = await wp.rest( 'GET', '/wp/v2/posts', {
			status: 'any',
			per_page: 20,
			_fields: 'id,title,status',
		} );

		const post = ( data || [] ).find( ( item ) =>
			/release notes/i.test( item.title?.rendered || '' )
		);

		if ( ! post ) {
			return { pass: false, reason: 'The post is gone.' };
		}

		if ( post.status !== 'publish' ) {
			return { pass: false, reason: `The post is still ${ post.status }.` };
		}

		if ( ! /2\.0/.test( post.title.rendered ) ) {
			return { pass: false, reason: `The title is still "${ post.title.rendered }".` };
		}

		return { pass: true };
	},

	script: [
		{
			name: 'rest-api-call',
			input: { method: 'GET', route: '/wp/v2/posts', params: { status: 'draft', _fields: 'id,title' } },
		},
		( results ) => ( {
			name: 'rest-api-call',
			input: {
				method: 'POST',
				route: `/wp/v2/posts/${ JSON.parse( results[ 0 ] ).data[ 0 ].id }`,
				params: { title: 'Release notes 2.0', status: 'publish' },
			},
		} ),
	],
};
