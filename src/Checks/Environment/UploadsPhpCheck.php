<?php
/**
 * Executable files in the uploads directory.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Checks\Environment;

use KeepItPeace\Checks\AbstractCheck;
use KeepItPeace\Findings\Severity;
use KeepItPeace\Scan\CheckResult;
use KeepItPeace\Scan\FileInfo;
use KeepItPeace\Scan\FileWalker;
use KeepItPeace\Scan\PathGuard;
use KeepItPeace\Scan\ScanContext;

defined( 'ABSPATH' ) || exit;

/**
 * Walks wp-content/uploads (resumable) looking for PHP and similar files.
 */
class UploadsPhpCheck extends AbstractCheck {

	const EXTENSIONS = array( 'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phar', 'pht', 'phps', 'shtml', 'cgi', 'pl', 'py', 'asp', 'aspx', 'jsp' );

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'env.uploads_php';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'Executable files in uploads', 'keep-it-peace' );
	}

	/**
	 * Category.
	 *
	 * @return string
	 */
	public function get_category(): string {
		return 'environment';
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Looks for .php, .phtml and other executable files in wp-content/uploads, where only media should be stored.', 'keep-it-peace' );
	}

	/**
	 * Whether a file is a harmless "Silence is golden" index.
	 *
	 * @param FileInfo $file File.
	 * @return bool
	 */
	public static function is_silent_index( FileInfo $file ): bool {
		if ( 'index.php' !== strtolower( $file->basename ) || $file->size > 300 ) {
			return false;
		}
		$code = $file->contents( 300 );
		return null !== $code && (bool) preg_match( '#^\s*<\?php\s*(//[^\n]*|/\*.*?\*/|\#[^\n]*|\s)*(\?>)?\s*$#s', $code );
	}

	/**
	 * Runs (resumable).
	 *
	 * @param ScanContext $context Context.
	 * @param array       $cursor  Cursor.
	 * @return CheckResult
	 */
	public function run( ScanContext $context, array $cursor ): CheckResult {
		$uploads = wp_upload_dir( null, false );
		$root    = PathGuard::normalize( (string) $uploads['basedir'] );
		$status  = PathGuard::check_directory( $root );
		if ( PathGuard::OK !== $status ) {
			if ( PathGuard::NOT_FOUND === $status ) {
				return CheckResult::complete();
			}
			$result             = CheckResult::complete(
				array(
					$this->finding(
						array(
							'rule_id'        => 'uploads.unreadable',
							'severity'       => Severity::INFO,
							'target_type'    => 'file',
							'target_scope'   => 'uploads',
							'target'         => '.',
							'title'          => __( 'Uploads directory could not be read', 'keep-it-peace' ),
							'message'        => PathGuard::describe( $status ),
							'recommendation' => __( 'Check the permissions of wp-content/uploads.', 'keep-it-peace' ),
						)
					),
				)
			);
			$result->keep_rules = array( '*' );
			return $result;
		}

		$findings = array();
		$limits   = ScanContext::file_limits( $context->settings );
		$walker   = new FileWalker( $root, array( '.git', 'node_modules' ), max( $limits['max_files'] * 5, 100000 ) );
		$check    = $this;
		$walk     = $walker->walk(
			$cursor,
			static function ( FileInfo $file ) use ( &$findings, $check ) {
				if ( ! in_array( $file->extension, self::EXTENSIONS, true ) || self::is_silent_index( $file ) ) {
					return;
				}
				$findings[] = $check->make_finding( $file );
			},
			static function () {},
			array( $context, 'has_time' )
		);

		if ( ! $walk['done'] ) {
			// Progress is unknown for a directory walk; report a rough estimate.
			return CheckResult::partial( $findings, $walk['cursor'], min( 0.9, $walk['count'] / 20000 ) );
		}
		return CheckResult::complete( $findings, array( 'files_checked' => $walk['count'] ) );
	}

	/**
	 * Finding for an executable file.
	 *
	 * @param FileInfo $file File.
	 * @return \KeepItPeace\Findings\Finding
	 */
	public function make_finding( FileInfo $file ) {
		$php = $file->is_php();
		return $this->finding(
			array(
				'rule_id'        => 'uploads.executable',
				'severity'       => $php ? Severity::CRITICAL : Severity::HIGH,
				'target_type'    => 'file',
				'target_scope'   => 'uploads',
				'target'         => $file->rel_path,
				'file_hash'      => $file->hash(),
				'title'          => $php ? __( 'PHP file in uploads', 'keep-it-peace' ) : __( 'Executable file in uploads', 'keep-it-peace' ),
				'message'        => __( 'The uploads directory should only contain media. Executable files here are a common sign of a compromised site or of an insecure upload form.', 'keep-it-peace' ),
				'recommendation' => __( 'Inspect the file and delete it if it is not required. Also block PHP execution in uploads with server configuration.', 'keep-it-peace' ),
			)
		);
	}
}
