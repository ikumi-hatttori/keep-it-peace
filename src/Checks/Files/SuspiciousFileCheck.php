<?php
/**
 * Suspicious file names and locations.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Checks\Files;

use KeepItPeace\Checks\AbstractFileCheck;
use KeepItPeace\Findings\Finding;
use KeepItPeace\Findings\Severity;
use KeepItPeace\Scan\FileInfo;
use KeepItPeace\Scan\ScanContext;
use KeepItPeace\Scan\ScanTarget;

defined( 'ABSPATH' ) || exit;

/**
 * Double extensions, hidden files, PHP in asset directories, PHP hidden in images, web shell names.
 */
class SuspiciousFileCheck extends AbstractFileCheck {

	const ALLOWED_HIDDEN = array(
		'.gitignore',
		'.gitattributes',
		'.gitkeep',
		'.editorconfig',
		'.distignore',
		'.nvmrc',
		'.npmrc',
		'.browserslistrc',
		'.babelrc',
		'.prettierrc',
		'.prettierignore',
		'.eslintrc',
		'.eslintignore',
		'.stylelintrc',
		'.stylelintignore',
		'.wp-env.json',
		'.phpcs.xml.dist',
		'.php-cs-fixer.php',
		'.DS_Store',
	);

	const ASSET_DIRS = array( 'images', 'image', 'img', 'imgs', 'icons', 'fonts', 'font', 'css', 'media', 'uploads', 'svg', 'video', 'videos' );

	const IMAGE_EXTENSIONS = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'ico', 'bmp', 'svg', 'avif' );

	const SHELL_NAMES = '/^(wso|c99|r57|b374k|alfa|shell|cmd|adminer|wp-vcd|wp-tmp|wp-feed|class\.wp\.php|indoxploit|leaf|marijuana|priv8|up|uploader|sym|symlink|x|xx|xxx|0x|1|2|3|404|ok|bypass)\.php$/i';

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'files.suspicious';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'Suspicious files', 'keep-it-peace' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Finds double extensions, hidden files, PHP files in image or asset folders, PHP code inside images and known web shell names.', 'keep-it-peace' );
	}

	/**
	 * Analyzes a file.
	 *
	 * @param FileInfo    $file    File.
	 * @param ScanTarget  $target  Target.
	 * @param ScanContext $context Context.
	 * @return Finding[]
	 */
	public function analyze_file( FileInfo $file, ScanTarget $target, ScanContext $context ): array {
		$out  = array();
		$name = $file->basename;

		if ( preg_match( '/\.(php\d?|phtml|phar|pht|phps)\.[a-z0-9]+$/i', $name ) || preg_match( '/\.(jpe?g|png|gif|webp|ico|txt|pdf|zip|svg)\.(php\d?|phtml|phar|pht)$/i', $name ) ) {
			$out[] = $this->make( $file, $target, 'suspicious.double_extension', Severity::HIGH, __( 'Double file extension', 'keep-it-peace' ), __( 'The file name has two extensions, a trick used to upload executable files disguised as images or documents.', 'keep-it-peace' ) );
		}

		if ( '.' === $name[0] && ! in_array( $name, self::ALLOWED_HIDDEN, true ) && ! preg_match( '/^\.(eslintrc|prettierrc|stylelintrc|babelrc)(\.[a-z]+)?$/', $name ) ) {
			if ( $file->is_php() ) {
				$out[] = $this->make( $file, $target, 'suspicious.hidden_php', Severity::CRITICAL, __( 'Hidden PHP file', 'keep-it-peace' ), __( 'A PHP file whose name starts with a dot is hidden from normal directory listings. Backdoors are often stored this way.', 'keep-it-peace' ) );
			} elseif ( '.htaccess' === $name ) {
				$out[] = $this->make( $file, $target, 'suspicious.htaccess', Severity::MEDIUM, __( '.htaccess inside the directory', 'keep-it-peace' ), __( 'An .htaccess file can change how the server executes files. Attackers use it to enable PHP in unexpected places or to redirect visitors.', 'keep-it-peace' ) );
			} else {
				$out[] = $this->make( $file, $target, 'suspicious.hidden_file', Severity::LOW, __( 'Hidden file', 'keep-it-peace' ), __( 'A file whose name starts with a dot was found.', 'keep-it-peace' ) );
			}
		}

		if ( $file->is_php() ) {
			$segments = array_map( 'strtolower', explode( '/', dirname( $file->rel_path ) ) );
			if ( array_intersect( $segments, self::ASSET_DIRS ) ) {
				$out[] = $this->make( $file, $target, 'suspicious.php_in_assets', Severity::HIGH, __( 'PHP file in an asset folder', 'keep-it-peace' ), __( 'Image, font and CSS folders should not contain PHP. A PHP file here is often an uploaded backdoor.', 'keep-it-peace' ) );
			}
			if ( preg_match( self::SHELL_NAMES, $name ) ) {
				$out[] = $this->make( $file, $target, 'suspicious.shell_name', Severity::HIGH, __( 'File name used by web shells', 'keep-it-peace' ), __( 'The file name matches names commonly used by web shells and malware droppers.', 'keep-it-peace' ) );
			}
			if ( 'php' !== $file->extension && 'inc' !== $file->extension ) {
				$out[] = $this->make( $file, $target, 'suspicious.php_variant', Severity::MEDIUM, __( 'Unusual PHP extension', 'keep-it-peace' ), __( 'Extensions such as .phtml, .php5 or .phar are executable on many servers and are used to bypass upload filters.', 'keep-it-peace' ) );
			}
		}

		if ( in_array( $file->extension, self::IMAGE_EXTENSIONS, true ) && 'svg' !== $file->extension ) {
			$head = $file->head( 4096 );
			if ( false !== stripos( $head, '<?php' ) || preg_match( '/<\?=|eval\s*\(|base64_decode\s*\(/i', $head ) ) {
				$out[] = $this->make( $file, $target, 'suspicious.php_in_image', Severity::CRITICAL, __( 'PHP code inside an image', 'keep-it-peace' ), __( 'The image file contains PHP code. It may be included by another script to run a backdoor.', 'keep-it-peace' ) );
			}
		}
		return $out;
	}

	/**
	 * Builds a finding.
	 *
	 * @param FileInfo   $file     File.
	 * @param ScanTarget $target   Target.
	 * @param string     $rule_id  Rule.
	 * @param int        $severity Severity.
	 * @param string     $title    Title.
	 * @param string     $message  Message.
	 * @return Finding
	 */
	private function make( FileInfo $file, ScanTarget $target, string $rule_id, int $severity, string $title, string $message ): Finding {
		return $this->file_finding(
			$file,
			$target,
			array(
				'rule_id'        => $rule_id,
				'severity'       => $severity,
				'title'          => $title,
				'message'        => $message,
				'recommendation' => __( 'Inspect the file. If you do not know why it is there, remove it, check other files changed at the same time and change your passwords.', 'keep-it-peace' ),
			)
		);
	}
}
