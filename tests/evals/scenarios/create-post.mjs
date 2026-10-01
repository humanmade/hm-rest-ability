export default {
	id: 'create-post',
	tags: [ 'core' ],
	task: 'Create a new draft blog post titled "Quarterly update" with a short paragraph of body text. Leave it as a draft.',

	async grade( wp ) {
		const { data } = await wp.rest( 'GET', '/wp/v2/posts', {
			status: 'draft',
			per_page: 20,
			_fields: 'id,title,content,status',
		} );

		const post = ( data || [] ).find( ( item ) =>
			/quarterly update/i.test( item.title?.rendered || '' )
		);

		if ( ! post ) {
			return { pass: false, reason: 'No draft post with that title exists.' };
		}

		if ( ! ( post.content?.rendered || '' ).trim() ) {
			return { pass: false, reason: 'The post has no body text.' };
		}

		return { pass: true };
	},

};
