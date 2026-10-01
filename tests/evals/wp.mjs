import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const { authHeaders } = require( '../e2e/support/credentials.js' );

/**
 * Direct REST access to the test site, for scenario setup and grading.
 *
 * Deliberately separate from the MCP path. Setting a scenario up and checking
 * its result must not depend on the thing being measured.
 */
export class WordPress {
	constructor( baseUrl, role = 'admin' ) {
		this.baseUrl = baseUrl.replace( /\/$/, '' );
		this.role = role;
	}

	/**
	 * Sends a REST request and returns the parsed body.
	 *
	 * @param {string} method HTTP method.
	 * @param {string} route  REST route, e.g. /wp/v2/posts
	 * @param {Object} params Query params for GET/DELETE, body otherwise.
	 * @return {Promise<{status: number, data: *}>} Status and parsed body.
	 */
	async rest( method, route, params = {} ) {
		const url = new URL( `${ this.baseUrl }/wp-json${ route }` );
		const options = {
			method,
			headers: { ...authHeaders( this.role ), 'Content-Type': 'application/json' },
		};

		if ( [ 'GET', 'HEAD', 'DELETE' ].includes( method ) ) {
			for ( const [ key, value ] of Object.entries( params ) ) {
				url.searchParams.set( key, value );
			}
		} else {
			options.body = JSON.stringify( params );
		}

		const response = await fetch( url, options );
		const text = await response.text();

		return { status: response.status, data: text ? JSON.parse( text ) : null };
	}

	/**
	 * Fetches a collection, returning an empty array on any error.
	 *
	 * @param {string} route  REST route.
	 * @param {Object} params Query params.
	 * @return {Promise<Array<Object>>} The items.
	 */
	async list( route, params = {} ) {
		const { data } = await this.rest( 'GET', route, params );

		return Array.isArray( data ) ? data : [];
	}

	/**
	 * Removes every post, attachment and non-default category.
	 *
	 * Runs between scenarios so each starts from the same state, and one
	 * scenario's leftovers can't make the next one pass.
	 */
	async reset() {
		for ( const type of [ 'posts', 'media' ] ) {
			const params = { per_page: 100, _fields: 'id' };

			// The media endpoint has no "any" status, and rejects the param.
			if ( type === 'posts' ) {
				params.status = 'any';
			}

			for ( const item of await this.list( `/wp/v2/${ type }`, params ) ) {
				await this.rest( 'DELETE', `/wp/v2/${ type }/${ item.id }`, { force: true } );
			}
		}

		const categories = await this.list( '/wp/v2/categories', {
			per_page: 100,
			_fields: 'id,slug',
		} );

		for ( const category of categories ) {
			if ( category.slug !== 'uncategorized' ) {
				await this.rest( 'DELETE', `/wp/v2/categories/${ category.id }`, { force: true } );
			}
		}
	}
}
