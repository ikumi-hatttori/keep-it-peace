<?php
/**
 * Translatable texts for bundled rules.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * Bundled rules keep their texts here so they can be translated.
 * Remote rule sets carry their own texts in the JSON.
 */
final class RuleMessages {

	/**
	 * Applies translated texts to a rule.
	 *
	 * @param Rule $rule Rule.
	 */
	public static function apply( Rule $rule ): void {
		$messages = self::all();
		if ( isset( $messages[ $rule->id ] ) ) {
			list( $rule->title, $rule->description, $rule->recommendation ) = $messages[ $rule->id ];
		}
	}

	/**
	 * Texts keyed by rule ID: [title, description, recommendation].
	 *
	 * @return array<string,string[]>
	 */
	public static function all(): array {
		return array(
			'php.eval'                    => array(
				__( 'eval() is used', 'keep-it-peace' ),
				__( 'eval() runs a string as PHP code. Injected malware often uses it, and if the string contains user input it allows remote code execution.', 'keep-it-peace' ),
				__( 'Replace eval() with regular code. If you did not write this, the file may be compromised: compare it with your original source.', 'keep-it-peace' ),
			),
			'php.assert_dynamic'          => array(
				__( 'assert() with dynamic code', 'keep-it-peace' ),
				__( 'On older PHP versions assert() evaluates a string argument as code, a common backdoor technique.', 'keep-it-peace' ),
				__( 'Remove the call or pass a boolean expression. Check whether the file was modified by someone else.', 'keep-it-peace' ),
			),
			'php.create_function'         => array(
				__( 'create_function() is used', 'keep-it-peace' ),
				__( 'create_function() evaluates code internally and was removed in PHP 8. It is also used by malware.', 'keep-it-peace' ),
				__( 'Use an anonymous function instead.', 'keep-it-peace' ),
			),
			'php.preg_replace_e'          => array(
				__( 'preg_replace() with the /e modifier', 'keep-it-peace' ),
				__( 'The /e modifier evaluates the replacement as PHP code. It only works on very old PHP and is a well-known backdoor pattern.', 'keep-it-peace' ),
				__( 'Use preg_replace_callback(). Treat the file as suspicious if you did not write it.', 'keep-it-peace' ),
			),
			'php.shell_exec'              => array(
				__( 'Shell command execution', 'keep-it-peace' ),
				__( 'Functions such as shell_exec(), system(), exec() and passthru() run operating system commands. Themes rarely need them and web shells rely on them.', 'keep-it-peace' ),
				__( 'Remove the call if it is not required. If it is, never pass user input and escape arguments with escapeshellarg().', 'keep-it-peace' ),
			),
			'php.decode_chain'            => array(
				__( 'Chained decoding functions', 'keep-it-peace' ),
				__( 'Nested calls such as base64_decode(gzinflate(...)) are typical of obfuscated malware.', 'keep-it-peace' ),
				__( 'Decode the payload safely offline to see what it does, and restore the file from a clean copy.', 'keep-it-peace' ),
			),
			'php.base64_decode'           => array(
				__( 'base64_decode() is used', 'keep-it-peace' ),
				__( 'base64_decode() has legitimate uses but is also used to hide code.', 'keep-it-peace' ),
				__( 'Confirm what is decoded. Ignore this finding if the use is legitimate.', 'keep-it-peace' ),
			),
			'php.remote_include'          => array(
				__( 'Remote or stream file inclusion', 'keep-it-peace' ),
				__( 'include/require with a URL or stream wrapper (http://, php://, data://) executes code from outside the server.', 'keep-it-peace' ),
				__( 'Include only local files. Remove the statement and investigate how it got there.', 'keep-it-peace' ),
			),
			'php.remote_fetch'            => array(
				__( 'External URL is loaded', 'keep-it-peace' ),
				__( 'The code fetches content from a fixed external URL. This can be legitimate (APIs) but is also used to download payloads.', 'keep-it-peace' ),
				__( 'Confirm that the URL is expected and that the response is not executed or output without escaping.', 'keep-it-peace' ),
			),
			'php.unserialize_input'       => array(
				__( 'unserialize() on request data', 'keep-it-peace' ),
				__( 'Unserializing user input enables PHP object injection.', 'keep-it-peace' ),
				__( 'Use json_decode() for user input, or pass allowed_classes => false.', 'keep-it-peace' ),
			),
			'php.extract_input'           => array(
				__( 'extract() on request data', 'keep-it-peace' ),
				__( 'extract() on $_GET/$_POST lets visitors overwrite any local variable.', 'keep-it-peace' ),
				__( 'Read the needed keys explicitly and sanitize each value.', 'keep-it-peace' ),
			),
			'php.error_reporting_off'     => array(
				__( 'Error reporting disabled in code', 'keep-it-peace' ),
				__( 'error_reporting(0) hides errors. Malware uses it to stay unnoticed.', 'keep-it-peace' ),
				__( 'Configure error display in php.ini or wp-config.php instead of in theme code.', 'keep-it-peace' ),
			),
			'php.webshell_marker'         => array(
				__( 'Known web shell signature', 'keep-it-peace' ),
				__( 'The file contains a string used by well-known web shells.', 'keep-it-peace' ),
				__( 'Treat the site as compromised: take a backup for analysis, remove the file, change all passwords and look for other modified files.', 'keep-it-peace' ),
			),
			'html.external_script'        => array(
				__( 'External script is loaded', 'keep-it-peace' ),
				__( 'A script is loaded from another domain. If that domain is compromised, so is your site.', 'keep-it-peace' ),
				__( 'Confirm the source is trusted. Prefer wp_enqueue_script() and consider Subresource Integrity.', 'keep-it-peace' ),
			),
			'php.variable_function'       => array(
				__( 'Variable function call', 'keep-it-peace' ),
				__( 'Calling $name(...) runs whatever function the variable contains. Malware uses it to hide calls such as system().', 'keep-it-peace' ),
				__( 'Make sure the variable cannot be controlled by users, or call the function directly.', 'keep-it-peace' ),
			),
			'php.backtick_exec'           => array(
				__( 'Backtick shell execution', 'keep-it-peace' ),
				__( 'Text between backticks is executed as a shell command.', 'keep-it-peace' ),
				__( 'Remove it. Themes should not run shell commands.', 'keep-it-peace' ),
			),
			'php.input_output'            => array(
				__( 'Request data is output without escaping', 'keep-it-peace' ),
				__( 'A value from $_GET/$_POST/$_REQUEST/$_COOKIE is printed directly, which allows cross-site scripting (XSS).', 'keep-it-peace' ),
				__( 'Escape on output with esc_html(), esc_attr() or esc_url() and sanitize on input.', 'keep-it-peace' ),
			),
			'php.input_sql'               => array(
				__( 'Request data in a SQL query', 'keep-it-peace' ),
				__( 'A request value is used in a database query without $wpdb->prepare(), which allows SQL injection.', 'keep-it-peace' ),
				__( 'Use $wpdb->prepare() with placeholders.', 'keep-it-peace' ),
			),
			'php.input_include'           => array(
				__( 'Request data in include/require', 'keep-it-peace' ),
				__( 'A request value decides which file is included, which allows local or remote file inclusion.', 'keep-it-peace' ),
				__( 'Map allowed values to fixed file names with an allowlist.', 'keep-it-peace' ),
			),
			'php.input_file'              => array(
				__( 'Request data in a file operation', 'keep-it-peace' ),
				__( 'A request value is used as a file path or content, which can allow reading or writing arbitrary files.', 'keep-it-peace' ),
				__( 'Validate against an allowlist, use basename() and sanitize_file_name(), and keep operations inside a fixed directory.', 'keep-it-peace' ),
			),
			'php.wpdb_unprepared'         => array(
				__( 'Database query without prepare()', 'keep-it-peace' ),
				__( 'A $wpdb query is built from variables without $wpdb->prepare(). If any part comes from users, this allows SQL injection.', 'keep-it-peace' ),
				__( 'Build the query with $wpdb->prepare() and placeholders.', 'keep-it-peace' ),
			),
			'php.ajax_missing_nonce'      => array(
				__( 'AJAX/admin-post handler without nonce check', 'keep-it-peace' ),
				__( 'The handler does not call check_ajax_referer(), check_admin_referer() or wp_verify_nonce(), so requests can be forged (CSRF).', 'keep-it-peace' ),
				__( 'Verify a nonce at the start of the handler.', 'keep-it-peace' ),
			),
			'php.ajax_missing_capability' => array(
				__( 'AJAX/admin-post handler without capability check', 'keep-it-peace' ),
				__( 'Any logged-in user (including subscribers) can call this handler because current_user_can() is not checked.', 'keep-it-peace' ),
				__( 'Check the required capability with current_user_can() before doing anything.', 'keep-it-peace' ),
			),
			'php.rest_missing_permission' => array(
				__( 'REST route without permission_callback', 'keep-it-peace' ),
				__( 'The route has no permission_callback, so access control is undefined.', 'keep-it-peace' ),
				__( 'Add a permission_callback that checks capabilities (use __return_true only for truly public data).', 'keep-it-peace' ),
			),
			'php.rest_public_endpoint'    => array(
				__( 'Public REST route', 'keep-it-peace' ),
				__( 'The route is open to everyone (permission_callback is __return_true).', 'keep-it-peace' ),
				__( 'Confirm that the route only exposes public data and changes nothing.', 'keep-it-peace' ),
			),
			'php.form_missing_nonce'      => array(
				__( 'Form processing without nonce check', 'keep-it-peace' ),
				__( 'The file handles $_POST and changes data, but no nonce is verified anywhere in the file.', 'keep-it-peace' ),
				__( 'Add wp_nonce_field() to the form and verify it with check_admin_referer() or wp_verify_nonce().', 'keep-it-peace' ),
			),
			'php.obfuscated_string'       => array(
				__( 'Obfuscated string', 'keep-it-peace' ),
				__( 'A very long encoded string, a run of escape sequences or a chain of chr() calls was found. This is typical of injected code.', 'keep-it-peace' ),
				__( 'Check what the string contains. Restore the file from a clean copy if you did not add it.', 'keep-it-peace' ),
			),
		);
	}
}
