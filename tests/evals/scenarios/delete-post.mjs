export default {
	id: 'delete-post',
	tags: [ 'core' ],
	task: 'Permanently delete the post titled "Obsolete announcement". Do not leave it in the trash.',

	async setup( wp ) {
		await wp.rest( 'POST', '/wp/v2/posts', {
			title: 'Obsolete announcement',
			content: 'This is out of date.',
			status: 'publish',
		} );
		await wp.rest( 'POST', '/wp/v2/posts', {
			title: 'Keep this one',
			content: 'Still current.',
			status: 'publish',
		} );
	},

	async grade( wp ) {
		const { data } = await wp.rest( 'GET', '/wp/v2/posts', {
			status: 'any',
			per_page: 50,
			_fields: 'id,title',
		} );

		const titles = ( data || [] ).map( ( item ) => item.title?.rendered || '' );

		if ( titles.some( ( title ) => /obsolete announcement/i.test( title ) ) ) {
			return { pass: false, reason: 'The post is still there, possibly only trashed.' };
		}

		if ( ! titles.some( ( title ) => /keep this one/i.test( title ) ) ) {
			return { pass: false, reason: 'The wrong post was deleted.' };
		}

		return { pass: true };
	},

};
