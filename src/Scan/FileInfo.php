<?php
/**
 * File passed to file checks.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Scan;

defined( 'ABSPATH' ) || exit;

/**
 * File metadata with lazily computed hash and contents (shared by all file checks).
 */
class FileInfo {

	const PHP_EXTENSIONS = array( 'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phar', 'pht', 'phps', 'inc' );

	/**
	 * Path.
	 *
	 * @var string
	 */
	public $path;
	/**
	 * Path relative to the target root, with forward slashes.
	 *
	 * @var string
	 */
	public $rel_path;
	/**
	 * Basename.
	 *
	 * @var string
	 */
	public $basename;
	/**
	 * Lowercase extension without dot.
	 *
	 * @var string
	 */
	public $extension;
	/**
	 * Size.
	 *
	 * @var int
	 */
	public $size;
	/**
	 * Mtime.
	 *
	 * @var int
	 */
	public $mtime;
	/**
	 * Permission bits (0777 mask).
	 *
	 * @var int
	 */
	public $perms;
	/**
	 * Readable.
	 *
	 * @var bool
	 */
	public $readable;

	/**
	 * Hash.
	 *
	 * @var string|null|false
	 */
	private $hash = null;
	/**
	 * Contents.
	 *
	 * @var string|null|false
	 */
	private $contents = null;

	/**
	 * Builds from a path.
	 *
	 * @param string $path     Absolute path.
	 * @param string $rel_path Relative path.
	 * @return self
	 */
	public static function from_path( string $path, string $rel_path ): self {
		$info            = new self();
		$info->path      = $path;
		$info->rel_path  = $rel_path;
		$info->basename  = basename( $rel_path );
		$info->extension = strtolower( (string) pathinfo( $info->basename, PATHINFO_EXTENSION ) );
		$info->readable  = is_readable( $path );
		$size            = filesize( $path );
		$mtime           = filemtime( $path );
		$perms           = fileperms( $path );
		$info->size      = false === $size ? 0 : (int) $size;
		$info->mtime     = false === $mtime ? 0 : (int) $mtime;
		$info->perms     = false === $perms ? 0 : ( $perms & 0777 );
		return $info;
	}

	/**
	 * Whether the file is executable PHP.
	 *
	 * @return bool
	 */
	public function is_php(): bool {
		return in_array( $this->extension, self::PHP_EXTENSIONS, true );
	}

	/**
	 * The sha256 of the file, or null when unreadable.
	 *
	 * @return string|null
	 */
	public function hash(): ?string {
		if ( null === $this->hash ) {
			$hash       = $this->readable ? hash_file( 'sha256', $this->path ) : false;
			$this->hash = false === $hash ? false : $hash;
		}
		return false === $this->hash ? null : $this->hash;
	}

	/**
	 * File contents, or null when unreadable or larger than the limit.
	 *
	 * @param int $max_bytes Size limit.
	 * @return string|null
	 */
	public function contents( int $max_bytes ): ?string {
		if ( $this->size > $max_bytes || ! $this->readable ) {
			return null;
		}
		if ( null === $this->contents ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
			$data           = file_get_contents( $this->path );
			$this->contents = false === $data ? false : $data;
		}
		return false === $this->contents ? null : $this->contents;
	}

	/**
	 * Reads the first bytes of the file.
	 *
	 * @param int $length Bytes.
	 * @return string
	 */
	public function head( int $length ): string {
		if ( ! $this->readable ) {
			return '';
		}
		if ( is_string( $this->contents ) ) {
			return substr( $this->contents, 0, $length );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
		$data = file_get_contents( $this->path, false, null, 0, $length );
		return false === $data ? '' : $data;
	}

	/**
	 * Permissions as an octal string such as `644`.
	 *
	 * @return string
	 */
	public function perms_octal(): string {
		return sprintf( '%03o', $this->perms );
	}
}
