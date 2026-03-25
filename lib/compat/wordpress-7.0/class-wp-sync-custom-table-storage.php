<?php
/**
 * WP_Sync_Custom_Table_Storage class
 *
 * @package gutenberg
 */

if ( ! class_exists( 'WP_Sync_Custom_Table_Storage' ) ) {

	/**
	 * Storage backend for sync updates using a dedicated database table
	 * with proper composite indexes, replacing the generic postmeta table.
	 *
	 * @since 7.0.0
	 * @access private
	 */
	class WP_Sync_Custom_Table_Storage implements WP_Sync_Storage {
		/**
		 * Option key for tracking the DB schema version.
		 *
		 * @var string
		 */
		const DB_VERSION_OPTION = 'wp_sync_db_version';

		/**
		 * Current DB schema version.
		 *
		 * @var int
		 */
		const DB_VERSION = 1;

		/**
		 * Cache of cursors by room for the current request.
		 *
		 * @var array<string, int>
		 */
		private array $room_cursors = array();

		/**
		 * Cache of update counts by room for the current request.
		 *
		 * @var array<string, int>
		 */
		private array $room_update_counts = array();

		/**
		 * Constructor. Ensures tables exist.
		 */
		public function __construct() {
			$this->maybe_create_tables();
		}

		/**
		 * Returns the updates table name.
		 *
		 * @return string
		 */
		private function updates_table(): string {
			global $wpdb;
			return $wpdb->prefix . 'sync_updates';
		}

		/**
		 * Returns the awareness table name.
		 *
		 * @return string
		 */
		private function awareness_table(): string {
			global $wpdb;
			return $wpdb->prefix . 'sync_awareness';
		}

		/**
		 * Returns the MD5 hash for a room identifier.
		 *
		 * @param string $room Room identifier.
		 * @return string MD5 hash.
		 */
		private function room_hash( string $room ): string {
			return md5( $room );
		}

		/**
		 * Creates the custom tables if they don't exist or need upgrading.
		 */
		private function maybe_create_tables(): void {
			if ( (int) get_option( self::DB_VERSION_OPTION ) >= self::DB_VERSION ) {
				return;
			}

			global $wpdb;

			$charset_collate = $wpdb->get_charset_collate();
			$updates_table   = $this->updates_table();
			$awareness_table = $this->awareness_table();

			$sql = "CREATE TABLE {$updates_table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				room_hash CHAR(32) NOT NULL,
				update_value LONGTEXT NOT NULL,
				PRIMARY KEY (id),
				KEY idx_room_cursor (room_hash, id)
			) $charset_collate;

			CREATE TABLE {$awareness_table} (
				room_hash CHAR(32) NOT NULL,
				awareness LONGTEXT NOT NULL,
				PRIMARY KEY (room_hash)
			) $charset_collate;";

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql );

			update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
		}

		/**
		 * Adds a sync update to a given room.
		 *
		 * @param string $room   Room identifier.
		 * @param mixed  $update Sync update.
		 * @return bool True on success, false on failure.
		 */
		public function add_update( string $room, $update ): bool {
			global $wpdb;

			$result = $wpdb->insert(
				$this->updates_table(),
				array(
					'room_hash'    => $this->room_hash( $room ),
					'update_value' => maybe_serialize( $update ),
				),
				array( '%s', '%s' )
			);

			return false !== $result;
		}

		/**
		 * Gets awareness state for a given room.
		 *
		 * @param string $room Room identifier.
		 * @return array<int, mixed> Awareness state.
		 */
		public function get_awareness_state( string $room ): array {
			global $wpdb;

			$row = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT awareness FROM {$this->awareness_table()} WHERE room_hash = %s",
					$this->room_hash( $room )
				)
			);

			if ( null === $row ) {
				return array();
			}

			$awareness = maybe_unserialize( $row );
			if ( ! is_array( $awareness ) ) {
				return array();
			}

			return array_values( $awareness );
		}

		/**
		 * Sets awareness state for a given room.
		 *
		 * @param string            $room      Room identifier.
		 * @param array<int, mixed> $awareness Serializable awareness state.
		 * @return bool True on success, false on failure.
		 */
		public function set_awareness_state( string $room, array $awareness ): bool {
			global $wpdb;

			$hash       = $this->room_hash( $room );
			$serialized = maybe_serialize( $awareness );

			// Use REPLACE INTO for atomic upsert.
			$result = $wpdb->query(
				$wpdb->prepare(
					"REPLACE INTO {$this->awareness_table()} (room_hash, awareness) VALUES (%s, %s)",
					$hash,
					$serialized
				)
			);

			return false !== $result;
		}

		/**
		 * Gets the current cursor for a given room.
		 *
		 * @param string $room Room identifier.
		 * @return int Current cursor for the room.
		 */
		public function get_cursor( string $room ): int {
			return $this->room_cursors[ $room ] ?? 0;
		}

		/**
		 * Gets the number of updates stored for a given room.
		 *
		 * @param string $room Room identifier.
		 * @return int Number of updates stored for the room.
		 */
		public function get_update_count( string $room ): int {
			return $this->room_update_counts[ $room ] ?? 0;
		}

		/**
		 * Retrieves sync updates from a room after the given cursor.
		 *
		 * @param string $room   Room identifier.
		 * @param int    $cursor Return updates after this cursor.
		 * @return array<int, mixed> Sync updates.
		 */
		public function get_updates_after_cursor( string $room, int $cursor ): array {
			global $wpdb;

			$hash  = $this->room_hash( $room );
			$table = $this->updates_table();

			// Single query to get count, max cursor, and updates.
			$stats = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT COUNT(*) AS total_updates, COALESCE(MAX(id), 0) AS max_id FROM {$table} WHERE room_hash = %s",
					$hash
				)
			);

			$total_updates = $stats ? (int) $stats->total_updates : 0;
			$max_id        = $stats ? (int) $stats->max_id : 0;

			$this->room_update_counts[ $room ] = $total_updates;
			$this->room_cursors[ $room ]       = $max_id;

			if ( $max_id <= $cursor ) {
				return array();
			}

			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT update_value FROM {$table} WHERE room_hash = %s AND id > %d AND id <= %d ORDER BY id ASC",
					$hash,
					$cursor,
					$max_id
				)
			);

			if ( ! $rows ) {
				return array();
			}

			$updates = array();
			foreach ( $rows as $row ) {
				$updates[] = maybe_unserialize( $row->update_value );
			}

			return $updates;
		}

		/**
		 * Removes updates from a room that are older than the given cursor.
		 *
		 * @param string $room   Room identifier.
		 * @param int    $cursor Remove updates with id < this cursor.
		 * @return bool True on success, false on failure.
		 */
		public function remove_updates_before_cursor( string $room, int $cursor ): bool {
			global $wpdb;

			$result = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$this->updates_table()} WHERE room_hash = %s AND id < %d",
					$this->room_hash( $room ),
					$cursor
				)
			);

			return false !== $result;
		}
	}
}
