export default {
	id: 'categorise-post',
	tags: [],
	task: 'Create a category called "Engineering", then create a published post titled "Build pipeline" that is filed under it.',

	async grade( wp ) {
		const { data: categories } = await wp.rest( 'GET', '/wp/v2/categories', {
			per_page: 50,
			_fields: 'id,name',
		} );

		const category = ( categories || [] ).find( ( item ) => /engineering/i.test( item.name ) );

		if ( ! category ) {
			return { pass: false, reason: 'The category was not created.' };
		}

		const { data: posts } = await wp.rest( 'GET', '/wp/v2/posts', {
			status: 'any',
			per_page: 20,
			_fields: 'id,title,status,categories',
		} );

		const post = ( posts || [] ).find( ( item ) =>
			/build pipeline/i.test( item.title?.rendered || '' )
		);

		if ( ! post ) {
			return { pass: false, reason: 'The post was not created.' };
		}

		if ( ! ( post.categories || [] ).includes( category.id ) ) {
			return { pass: false, reason: 'The post is not in the Engineering category.' };
		}

		if ( post.status !== 'publish' ) {
			return { pass: false, reason: `The post is ${ post.status }, not published.` };
		}

		return { pass: true };
	},

	script: [
		{
			name: 'rest-api-call',
			input: { method: 'POST', route: '/wp/v2/categories', params: { name: 'Engineering' } },
		},
		( results ) => ( {
			name: 'rest-api-call',
			input: {
				method: 'POST',
				route: '/wp/v2/posts',
				params: {
					title: 'Build pipeline',
					status: 'publish',
					categories: [ JSON.parse( results[ 0 ] ).data.id ],
				},
			},
		} ),
	],
};
