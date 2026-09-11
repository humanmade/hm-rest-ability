import fs from 'node:fs';

/**
 * Renders the eval report as a GitHub job summary.
 *
 * Reads test-results/evals.json and writes markdown to stdout.
 */
const report = JSON.parse( fs.readFileSync( 'test-results/evals.json', 'utf8' ) );

const lines = [
	'## Eval results',
	'',
	`**${ report.passed }/${ report.total } scenarios passed** with \`${ report.provider }\`, ` +
		`${ report.repeat } run(s) each. Estimated cost $${ report.cost.toFixed( 4 ) }.`,
	'',
	'| Scenario | Result | Passes | Avg turns | Notes |',
	'| --- | --- | --- | --- | --- |',
];

for ( const scenario of report.scenarios ) {
	const core = scenario.tags.includes( 'core' ) ? ' (core)' : '';
	const result = scenario.pass ? 'pass' : 'FAIL';
	const notes = scenario.reasons.join( '; ' ).replace( /\|/g, '\\|' ) || '—';

	lines.push(
		`| ${ scenario.id }${ core } | ${ result } | ${ scenario.passes }/${ scenario.runs } | ` +
			`${ scenario.turns.toFixed( 1 ) } | ${ notes } |`
	);
}

if ( report.coreFailures.length > 0 ) {
	lines.push( '', `Core scenarios that failed: ${ report.coreFailures.join( ', ' ) }.` );
}

const { input, output, cacheRead, cacheWrite } = report.usage;

lines.push(
	'',
	`Tokens: ${ input } in, ${ output } out, ${ cacheRead } cache read, ${ cacheWrite } cache write.`
);

console.log( lines.join( '\n' ) );
