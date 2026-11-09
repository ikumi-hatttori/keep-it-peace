<?php
/**
 * Publicly downloadable sensitive files.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Checks\External;

use KeepItPeace\Findings\Finding;
use KeepItPeace\Findings\Severity;
use KeepItPeace\Scan\ScanContext;

defined( 'ABSPATH' ) || exit;

/**
 * Requests well-known sensitive paths and reports them only when the content confirms the file
 * (so "soft 404" pages that answer 200 for everything are not reported).
 */
class ExposedFilesCheck extends AbstractExternalCheck {

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'external.exposed_files';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'Exposed sensitive files', 'keep-it-peace' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Checks whether files such as .env, .git, wp-config.php backups, SQL dumps, debug.log and phpinfo pages can be downloaded from the site.', 'keep-it-peace' );
	}

	/**
	 * Path definitions (data/sensitive-paths.json, filterable).
	 *
	 * @return array[]
	 */
	public static function definitions(): array {
		$file = KEEPITPEACE_DIR . 'data/sensitive-paths.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Bundled local file.
		$json  = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
		$paths = is_array( $json ) && isset( $json['paths'] ) ? (array) $json['paths'] : array();

		/**
		 * Filters the sensitive paths requested by the exposed files check. Each entry has
		 * path, base (root|content), kind, severity and match {type: regex|contains|prefix|magic, value}.
		 *
		 * @param array[] $paths Definitions.
		 */
		$paths = (array) apply_filters( 'keepitpeace_sensitive_paths', $paths );
		return array_values(
			array_filter(
				$paths,
				static function ( $entry ) {
					return is_array( $entry ) && ! empty( $entry['path'] ) && isset( $entry['match']['type'], $entry['match']['value'] );
				}
			)
		);
	}

	/**
	 * Items: each definition against the site root (and wp-content), plus registered URLs.
	 *
	 * @param ScanContext $context Context.
	 * @return array[]
	 */
	protected function items( ScanContext $context ): array {
		$definitions = self::definitions();
		$content     = trailingslashit( content_url() );
		$items       = array();
		foreach ( ExternalTargets::urls( $context->settings, $this->probe()->policy() ) as $target ) {
			$base = trailingslashit( (string) strtok( $target['url'], '?#' ) );
			foreach ( $definitions as $index => $definition ) {
				$on_content = 'content' === ( $definition['base'] ?? 'root' );
				if ( $on_content && 'home' !== $target['kind'] ) {
					continue;
				}
				$items[] = array(
					'url' => ( $on_content ? $content : $base ) . ltrim( (string) $definition['path'], '/' ),
					'def' => $index,
				);
			}
		}
		return $items;
	}

	/**
	 * Processes one item.
	 *
	 * @param array       $item    Item.
	 * @param ScanContext $context Context.
	 * @param array       $data    Data.
	 * @return Finding[]
	 */
	protected function process( array $item, ScanContext $context, array &$data ): array {
		$definitions = self::definitions();
		$definition  = $definitions[ (int) $item['def'] ] ?? null;
		if ( ! $definition ) {
			return array();
		}
		$response = $this->probe()->get( (string) $item['url'], array( 'max_bytes' => 16384 ) );
		if ( '' !== $response['error'] ) {
			$data['errors'] = (int) ( $data['errors'] ?? 0 ) + 1;
			// Report once per scan instead of once per path.
			if ( 1 === $data['errors'] ) {
				return array( $this->unreachable( (string) $item['url'], $response['error'], $context ) );
			}
			$context->keep( $this->get_id() );
			return array();
		}
		if ( 200 !== $response['code'] || ! self::matches( $definition, $response['body'] ) ) {
			return array();
		}
		$texts = self::texts( (string) ( $definition['kind'] ?? '' ) );
		return array(
			$this->url_finding(
				(string) $item['url'],
				array(
					'rule_id'        => 'exposed.' . preg_replace( '/[^a-z0-9_]/', '_', strtolower( (string) ( $definition['kind'] ?? 'file' ) ) ),
					'severity'       => Severity::from( (string) ( $definition['severity'] ?? 'high' ) ),
					/* translators: %s: path. */
					'title'          => sprintf( __( 'Publicly accessible: %s', 'keep-it-peace' ), (string) $definition['path'] ),
					'message'        => $texts[0],
					'recommendation' => $texts[1],
				)
			),
		);
	}

	/**
	 * Whether a response body confirms the file.
	 *
	 * @param array  $definition Definition.
	 * @param string $body       Body.
	 * @return bool
	 */
	public static function matches( array $definition, string $body ): bool {
		if ( '' === $body ) {
			return false;
		}
		$kind = (string) ( $definition['kind'] ?? '' );
		if ( 'phpinfo' !== $kind && preg_match( '/^\s*(<!doctype\s+html|<html|<head|<body)/i', $body ) ) {
			return false;
		}
		$value = (string) $definition['match']['value'];
		switch ( $definition['match']['type'] ) {
			case 'regex':
				$result = preg_match( $value, $body );
				return 1 === $result;
			case 'contains':
				return false !== strpos( $body, $value );
			case 'prefix':
				return 0 === strpos( $body, $value );
			case 'magic':
				$bytes = ctype_xdigit( $value ) && 0 === strlen( $value ) % 2 ? hex2bin( $value ) : false;
				return false !== $bytes && 0 === strpos( $body, $bytes );
		}
		return false;
	}

	/**
	 * Message and recommendation by kind.
	 *
	 * @param string $kind Kind.
	 * @return string[]
	 */
	public static function texts( string $kind ): array {
		$remove = __( 'Delete the file from the public directory, or block access to it in the server configuration. Change any passwords or keys it contains.', 'keep-it-peace' );
		switch ( $kind ) {
			case 'env':
				return array( __( 'An environment file is downloadable. It usually contains database passwords and API keys.', 'keep-it-peace' ), $remove );
			case 'vcs':
				return array( __( 'Version control data is downloadable. Attackers can rebuild your source code, including configuration and credentials committed in the past.', 'keep-it-peace' ), __( 'Remove the .git/.svn/.hg directory from the public directory or deny access to it, then rotate any credentials found in the repository history.', 'keep-it-peace' ) );
			case 'config_backup':
				return array( __( 'A backup of wp-config.php is served as plain text, revealing the database password and security keys.', 'keep-it-peace' ), __( 'Delete the backup file, change the database password and regenerate the security keys in wp-config.php.', 'keep-it-peace' ) );
			case 'log':
				return array( __( 'A PHP error log is downloadable. Logs reveal file paths, plugin names and sometimes personal data.', 'keep-it-peace' ), __( 'Delete the log, and write logs outside the public directory (set WP_DEBUG_LOG to a private path).', 'keep-it-peace' ) );
			case 'phpinfo':
				return array( __( 'A phpinfo() page is public. It shows the PHP version, extensions, paths and environment variables.', 'keep-it-peace' ), __( 'Delete the file after use.', 'keep-it-peace' ) );
			case 'sql':
				return array( __( 'A database dump is downloadable. It can contain user accounts, password hashes and customer data.', 'keep-it-peace' ), __( 'Delete the dump immediately, store backups outside the public directory, and treat the data as leaked.', 'keep-it-peace' ) );
			case 'archive':
				return array( __( 'A backup archive is downloadable. It may contain the whole site including wp-config.php.', 'keep-it-peace' ), __( 'Delete the archive and keep backups outside the public directory.', 'keep-it-peace' ) );
			case 'credentials':
				return array( __( 'A password file is downloadable. Password hashes can be cracked offline.', 'keep-it-peace' ), $remove );
			case 'manifest':
				return array( __( 'A dependency lock file is downloadable. It lists exact package versions, which helps attackers find known vulnerabilities.', 'keep-it-peace' ), __( 'Block access to lock files or move them outside the public directory.', 'keep-it-peace' ) );
			case 'listing':
				return array( __( 'A .DS_Store file is downloadable. It reveals the names of files in the directory.', 'keep-it-peace' ), $remove );
		}
		return array( __( 'A file that should not be public can be downloaded.', 'keep-it-peace' ), $remove );
	}
}
