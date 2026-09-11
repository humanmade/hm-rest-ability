const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

export default {
	id: 'upload-image',
	tags: [ 'core' ],
	task:
		'Upload this PNG image to the media library as "logo.png", with the alternative text "Company logo". ' +
		`The file, base64 encoded, is: ${ PNG }`,

	async grade( wp ) {
		const { data } = await wp.rest( 'GET', '/wp/v2/media', {
			per_page: 20,
			_fields: 'id,mime_type,source_url,alt_text',
		} );

		const image = ( data || [] ).find( ( item ) => /logo/i.test( item.source_url || '' ) );

		if ( ! image ) {
			return { pass: false, reason: 'No attachment called logo was created.' };
		}

		if ( image.mime_type !== 'image/png' ) {
			return { pass: false, reason: `The attachment is ${ image.mime_type }.` };
		}

		if ( ! /company logo/i.test( image.alt_text || '' ) ) {
			return { pass: false, reason: 'The alternative text was not set.' };
		}

		return { pass: true };
	},

	script: [
		{
			name: 'media-upload',
			input: {
				file: PNG,
				filename: 'logo.png',
				mime_type: 'image/png',
				alt_text: 'Company logo',
			},
		},
	],
};
