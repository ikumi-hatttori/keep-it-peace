<?php
/**
 * Findings list table.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Admin;

use KeepItPeace\Findings\Finding;
use KeepItPeace\Findings\FindingStatus;
use KeepItPeace\Findings\Severity;
use KeepItPeace\Plugin;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Filterable, sortable list with bulk status changes.
 */
class FindingsListTable extends \WP_List_Table {

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
		parent::__construct(
			array(
				'singular' => 'finding',
				'plural'   => 'findings',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Current filters from the query string.
	 *
	 * @return array
	 */
	public static function filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters.
		$status = isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( $_REQUEST['status'] ) ) : 'open';
		return array(
			'status'   => $status,
			'severity' => isset( $_REQUEST['severity'] ) ? absint( $_REQUEST['severity'] ) : 0,
			'category' => isset( $_REQUEST['category'] ) ? sanitize_key( wp_unslash( $_REQUEST['category'] ) ) : '',
			'check'    => isset( $_REQUEST['check'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['check'] ) ) : '',
			'search'   => isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '',
			'orderby'  => isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'severity',
			'order'    => isset( $_REQUEST['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_REQUEST['order'] ) ) ) ? 'ASC' : 'DESC',
		);
		// phpcs:enable
	}

	/**
	 * Status argument for the repository.
	 *
	 * @param string $status Filter value.
	 * @return string[]
	 */
	private static function statuses( string $status ): array {
		if ( 'open' === $status ) {
			return FindingStatus::open();
		}
		if ( 'all' === $status ) {
			return FindingStatus::all();
		}
		return FindingStatus::is_valid( $status ) ? array( $status ) : FindingStatus::open();
	}

	/**
	 * Loads items.
	 */
	public function prepare_items() {
		$filters  = self::filters();
		$per_page = $this->get_items_per_page( 'keepitpeace_findings_per_page', 50 );
		$result   = $this->plugin->findings()->query(
			array(
				'status'   => self::statuses( $filters['status'] ),
				'severity' => $filters['severity'],
				'category' => $filters['category'],
				'check_id' => $filters['check'],
				'search'   => $filters['search'],
				'orderby'  => $filters['orderby'],
				'order'    => $filters['order'],
				'per_page' => $per_page,
				'page'     => $this->get_pagenum(),
			)
		);

		$this->items           = $result['items'];
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'           => '<input type="checkbox" />',
			'severity'     => __( 'Severity', 'keep-it-peace' ),
			'title'        => __( 'Issue', 'keep-it-peace' ),
			'category'     => __( 'Category', 'keep-it-peace' ),
			'location'     => __( 'Location', 'keep-it-peace' ),
			'status'       => __( 'Status', 'keep-it-peace' ),
			'last_seen_at' => __( 'Last detected', 'keep-it-peace' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'severity'     => array( 'severity', true ),
			'title'        => array( 'title', false ),
			'category'     => array( 'category', false ),
			'status'       => array( 'status', false ),
			'last_seen_at' => array( 'last_seen_at', true ),
		);
	}

	/**
	 * Bulk actions.
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		return array(
			'acknowledged' => __( 'Mark as acknowledged', 'keep-it-peace' ),
			'ignored'      => __( 'Ignore', 'keep-it-peace' ),
			'resolved'     => __( 'Mark as resolved', 'keep-it-peace' ),
			'new'          => __( 'Reopen', 'keep-it-peace' ),
		);
	}

	/**
	 * Status views.
	 *
	 * @return array
	 */
	protected function get_views() {
		$current = self::filters()['status'];
		$views   = array(
			'open' => __( 'Open', 'keep-it-peace' ),
		);
		foreach ( FindingStatus::all() as $status ) {
			$views[ $status ] = FindingStatus::label( $status );
		}
		$views['all'] = __( 'All', 'keep-it-peace' );
		$links        = array();
		foreach ( $views as $key => $label ) {
			$links[ $key ] = sprintf(
				'<a href="%1$s"%2$s>%3$s</a>',
				esc_url( Admin::url( Admin::FINDINGS_SLUG, array( 'status' => $key ) ) ),
				$current === $key ? ' class="current" aria-current="page"' : '',
				esc_html( $label )
			);
		}
		return $links;
	}

	/**
	 * Severity and category filters.
	 *
	 * @param string $which top|bottom.
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		$filters = self::filters();
		echo '<div class="alignleft actions">';
		echo '<label class="screen-reader-text" for="kip-filter-severity">' . esc_html__( 'Severity', 'keep-it-peace' ) . '</label>';
		echo '<select name="severity" id="kip-filter-severity"><option value="0">' . esc_html__( 'All severities', 'keep-it-peace' ) . '</option>';
		foreach ( Severity::all() as $level ) {
			printf( '<option value="%1$d" %2$s>%3$s</option>', (int) $level, selected( $filters['severity'], $level, false ), esc_html( Severity::label( $level ) ) );
		}
		echo '</select>';
		echo '<label class="screen-reader-text" for="kip-filter-category">' . esc_html__( 'Category', 'keep-it-peace' ) . '</label>';
		echo '<select name="category" id="kip-filter-category"><option value="">' . esc_html__( 'All categories', 'keep-it-peace' ) . '</option>';
		foreach ( $this->plugin->findings()->categories() as $category ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $category ), selected( $filters['category'], $category, false ), esc_html( Ui::category_label( $category ) ) );
		}
		echo '</select>';
		if ( '' !== $filters['check'] ) {
			$check = $this->plugin->checks()->get( $filters['check'] );
			echo '<input type="hidden" name="check" value="' . esc_attr( $filters['check'] ) . '">';
		}
		submit_button( __( 'Filter', 'keep-it-peace' ), '', 'filter_action', false );
		if ( '' !== $filters['check'] ) {
			/* translators: %s: check name. */
			echo ' <span class="kip-filter-chip">' . esc_html( sprintf( __( 'Check: %s', 'keep-it-peace' ), $check ? $check->get_name() : $filters['check'] ) ) . ' <a href="' . esc_url( remove_query_arg( array( 'check', 'paged' ) ) ) . '">' . esc_html__( 'Clear', 'keep-it-peace' ) . '</a></span>';
		}
		echo '</div>';
	}

	/**
	 * Checkbox column.
	 *
	 * @param Finding $item Item.
	 * @return string
	 */
	protected function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="ids[]" value="%d" />', (int) $item->id );
	}

	/**
	 * Severity column.
	 *
	 * @param Finding $item Item.
	 * @return string
	 */
	protected function column_severity( $item ) {
		return Ui::severity_badge( $item->severity );
	}

	/**
	 * Title column.
	 *
	 * @param Finding $item Item.
	 * @return string
	 */
	protected function column_title( $item ) {
		$url = Admin::url( Admin::FINDINGS_SLUG, array( 'finding' => $item->id ) );
		return sprintf( '<strong><a class="row-title" href="%1$s">%2$s</a></strong>', esc_url( $url ), esc_html( $item->title ) );
	}

	/**
	 * Default column.
	 *
	 * @param Finding $item        Item.
	 * @param string  $column_name Column.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'category':
				return esc_html( Ui::category_label( $item->category ) );
			case 'location':
				return '<span class="kip-mono">' . esc_html( Ui::location( $item ) ) . '</span>';
			case 'status':
				return Ui::status_badge( $item->status );
			case 'last_seen_at':
				return esc_html( Ui::datetime( $item->last_seen_at ) );
		}
		return '';
	}

	/**
	 * Empty message.
	 */
	public function no_items() {
		esc_html_e( 'No findings match the current filters.', 'keep-it-peace' );
	}
}
