<?php
/**
 * WP_Sync_Object_Cache_Storage class
 *
 * @package gutenberg
 */

if ( ! class_exists( 'WP_Sync_Object_Cache_Storage' ) ) {

	/**
	 * Storage backend for sync updates using the WordPress object cache
	 * (Redis/Memcached). Eliminates DB queries entirely for sync operations.
	 *
	 * Requires a persistent external object cache. Without one, data will
	 * not survive across requests.
	 *
	 * @since 7.0.0
	 * @access private
	 */
	class WP_Sync_Object_Cache_Storage implements WP_Sync_Storage {
		/**
		 * Cache group for all sync data.
		 *
		 * @var string
		 */
		const CACHE_GROUP = 'wp_sync';

		/**
		 * TTL for sync updates in seconds.
		 * Updates older than this are automatically evicted.
		 *
		 * @var int
		 */
		const UPDATE_TTL = 300; // 5 minutes

		/**
		 * TTL for awareness state in seconds.
		 *
		 * @var int
		 */
		const AWARENESS_TTL = 60;

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
		 * Returns the hashed room key.
		 *
		 * @param string $room Room identifier.
		 * @return string MD5 hash of the room.
		 */
		private function room_hash( string $room ): string {
			return md5( $room );
		}

		/**
		 * Adds a sync update to a given room.
		 *
		 * Uses wp_cache_incr() for atomic cursor assignment.
		 *
		 * @param string $room   Room identifier.
		 * @param mixed  $update Sync update.
		 * @return bool True on success, false on failure.
		 */
		public function add_update( string $room, $update ): bool {
			$hash       = $this->room_hash( $room );
			$cursor_key = "cursor:{$hash}";

			// Initialize cursor if it doesn't exist.
			wp_cache_add( $cursor_key, 0, self::CACHE_GROUP );

			// Atomically increment to get a unique cursor for this update.
			$cursor = wp_cache_incr( $cursor_key, 1, self::CACHE_GROUP );
			if ( false === $cursor ) {
				return false;
			}

			// Store the update at its cursor position.
			$update_key = "update:{$hash}:{$cursor}";
			$result     = wp_cache_set( $update_key, $update, self::CACHE_GROUP, self::UPDATE_TTL );

			if ( $result ) {
				// Increment the count.
				$count_key = "count:{$hash}";
				wp_cache_add( $count_key, 0, self::CACHE_GROUP );
				wp_cache_incr( $count_key, 1, self::CACHE_GROUP );
			}

			return $result;
		}

		/**
		 * Gets awareness state for a given room.
		 *
		 * @param string $room Room identifier.
		 * @return array<int, mixed> Awareness state.
		 */
		public function get_awareness_state( string $room ): array {
			$hash      = $this->room_hash( $room );
			$awareness = wp_cache_get( "awareness:{$hash}", self::CACHE_GROUP );

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
			$hash = $this->room_hash( $room );
			return wp_cache_set( "awareness:{$hash}", $awareness, self::CACHE_GROUP, self::AWARENESS_TTL );
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
		 * Uses wp_cache_get_multiple() for batch fetching when available.
		 *
		 * @param string $room   Room identifier.
		 * @param int    $cursor Return updates after this cursor (cache cursor ID).
		 * @return array<int, mixed> Sync updates.
		 */
		public function get_updates_after_cursor( string $room, int $cursor ): array {
			$hash       = $this->room_hash( $room );
			$cursor_key = "cursor:{$hash}";
			$count_key  = "count:{$hash}";

			$max_cursor = wp_cache_get( $cursor_key, self::CACHE_GROUP );
			$count      = wp_cache_get( $count_key, self::CACHE_GROUP );

			if ( false === $max_cursor || 0 === (int) $max_cursor ) {
				$this->room_cursors[ $room ]       = 0;
				$this->room_update_counts[ $room ] = 0;
				return array();
			}

			$max_cursor = (int) $max_cursor;
			$count      = false !== $count ? (int) $count : 0;

			$this->room_cursors[ $room ]       = $max_cursor;
			$this->room_update_counts[ $room ] = $count;

			if ( $max_cursor <= $cursor ) {
				return array();
			}

			// Build list of keys to fetch.
			$keys = array();
			for ( $i = $cursor + 1; $i <= $max_cursor; $i++ ) {
				$keys[] = "update:{$hash}:{$i}";
			}

			// Batch fetch.
			$results = wp_cache_get_multiple( $keys, self::CACHE_GROUP );

			$updates = array();
			foreach ( $results as $value ) {
				if ( false !== $value ) {
					$updates[] = $value;
				}
			}

			return $updates;
		}

		/**
		 * Removes updates from a room that are older than the given cursor.
		 *
		 * @param string $room   Room identifier.
		 * @param int    $cursor Remove updates with cursor IDs < this cursor.
		 * @return bool True on success, false on failure.
		 */
		public function remove_updates_before_cursor( string $room, int $cursor ): bool {
			$hash      = $this->room_hash( $room );
			$count_key = "count:{$hash}";
			$deleted   = 0;

			// Delete individual update keys. We scan from a reasonable
			// starting point. Since updates expire anyway, missing keys
			// are silently skipped.
			$max_cursor = wp_cache_get( "cursor:{$hash}", self::CACHE_GROUP );
			if ( false === $max_cursor ) {
				return true;
			}

			// Only delete keys that could reasonably exist (within TTL window).
			$min_possible = max( 1, $cursor - 1000 );
			for ( $i = $min_possible; $i < $cursor; $i++ ) {
				if ( wp_cache_delete( "update:{$hash}:{$i}", self::CACHE_GROUP ) ) {
					++$deleted;
				}
			}

			// Decrement count by number of actually deleted keys.
			if ( $deleted > 0 ) {
				wp_cache_add( $count_key, 0, self::CACHE_GROUP );
				$current_count = wp_cache_get( $count_key, self::CACHE_GROUP );
				$new_count     = max( 0, (int) $current_count - $deleted );
				wp_cache_set( $count_key, $new_count, self::CACHE_GROUP );
			}

			return true;
		}
	}
}
