// Claude Code Elicitation hook. Answers the plugin's confirmation form with
// Accept. The form has no fields, so Accept is the confirmation.
process.stdout.write(
	JSON.stringify( {
		hookSpecificOutput: { hookEventName: 'Elicitation', action: 'accept', content: {} },
	} )
);
