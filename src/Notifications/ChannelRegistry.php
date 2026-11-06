<?php
/**
 * Registered notification channels.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Notifications;

use KeepItPeace\Contracts\NotificationChannelInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Collects channels through `keepitpeace_notification_channels`.
 */
class ChannelRegistry {

	/**
	 * Core.
	 *
	 * @var NotificationChannelInterface[]
	 */
	private $core;
	/**
	 * Channels.
	 *
	 * @var NotificationChannelInterface[]|null
	 */
	private $channels = null;

	/**
	 * Constructor.
	 *
	 * @param NotificationChannelInterface[] $core Core channels.
	 */
	public function __construct( array $core ) {
		$this->core = $core;
	}

	/**
	 * Channels keyed by ID.
	 *
	 * @return NotificationChannelInterface[]
	 */
	public function all(): array {
		if ( null === $this->channels ) {
			/**
			 * Filters notification channels.
			 *
			 * @param NotificationChannelInterface[] $channels Channels.
			 */
			$channels       = apply_filters( 'keepitpeace_notification_channels', $this->core );
			$this->channels = array();
			foreach ( (array) $channels as $channel ) {
				if ( $channel instanceof NotificationChannelInterface ) {
					$this->channels[ $channel->get_id() ] = $channel;
				}
			}
		}
		return $this->channels;
	}

	/**
	 * Gets a channel.
	 *
	 * @param string $id ID.
	 * @return NotificationChannelInterface|null
	 */
	public function get( string $id ): ?NotificationChannelInterface {
		$all = $this->all();
		return $all[ $id ] ?? null;
	}
}
