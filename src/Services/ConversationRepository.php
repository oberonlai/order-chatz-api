<?php
/**
 * OrderChatz conversation / message queries.
 *
 * @package OrderChatzApi
 */

namespace OrderChatzApi\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Reads otz_users + monthly otz_messages partitions.
 */
final class ConversationRepository {

	/**
	 * List DM friends with optional filters.
	 *
	 * @param array{unread?:bool,last_from_customer?:bool,limit?:int,offset?:int} $args Query args.
	 * @return array{items: array<int,array>, total: int, limit: int, offset: int}
	 */
	public function list_conversations( array $args = array() ): array {
		global $wpdb;

		$unread             = ! empty( $args['unread'] );
		$last_from_customer = ! empty( $args['last_from_customer'] );
		$limit              = isset( $args['limit'] ) ? (int) $args['limit'] : 20;
		$offset             = isset( $args['offset'] ) ? (int) $args['offset'] : 0;

		$limit  = max( 1, min( 100, $limit ) );
		$offset = max( 0, $offset );

		$table = $wpdb->prefix . 'otz_users';

		// DM friends: active + source_type user (excludes unfollowed / groups).
		$sql = "SELECT * FROM {$table}
			WHERE status = %s
			AND source_type = %s
			ORDER BY last_active DESC, id DESC";

		$rows = $wpdb->get_results(
			$wpdb->prepare( $sql, 'active', 'user' ),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		$items = array();
		foreach ( $rows as $row ) {
			$summary = $this->build_list_item( $row );
			if ( $unread && (int) $summary['unread_count'] < 1 ) {
				continue;
			}
			if ( $last_from_customer && empty( $summary['last_from_customer'] ) ) {
				continue;
			}
			$items[] = $summary;
		}

		$total = count( $items );
		$items = array_slice( $items, $offset, $limit );

		return array(
			'items'  => array_values( $items ),
			'total'  => $total,
			'limit'  => $limit,
			'offset' => $offset,
		);
	}

	/**
	 * Single conversation: friend + messages + optional Woo summaries.
	 *
	 * @param int $id otz_users.id.
	 * @return array|null
	 */
	public function get_conversation( int $id ): ?array {
		global $wpdb;

		$table = $wpdb->prefix . 'otz_users';
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		$friend   = $this->normalize_friend( $row );
		$messages = $this->get_messages( (string) $row['line_user_id'], 100 );
		$orders   = array();

		$wp_user_id = isset( $row['wp_user_id'] ) ? (int) $row['wp_user_id'] : 0;
		if ( $wp_user_id > 0 ) {
			$orders = $this->get_woo_order_summaries( $wp_user_id );
		}

		$meta = $this->build_list_item( $row );

		return array(
			'friend'             => $friend,
			'messages'           => $messages,
			'orders'             => $orders,
			'unread_count'       => $meta['unread_count'],
			'last_message'       => $meta['last_message'],
			'last_from_customer' => $meta['last_from_customer'],
		);
	}

	/**
	 * Build list-row payload for one otz_users row.
	 *
	 * @param array $row DB row.
	 * @return array
	 */
	private function build_list_item( array $row ): array {
		$line_user_id = (string) ( $row['line_user_id'] ?? '' );
		$read_time    = $this->effective_read_time( $row['read_time'] ?? null );
		$last         = $this->get_last_message( $line_user_id );
		$unread       = $this->count_unread( $line_user_id, $read_time );

		$last_from_customer = false;
		if ( $last ) {
			$last_from_customer = $this->is_inbound_sender( (string) ( $last['sender_type'] ?? '' ) );
		}

		return array(
			'id'                 => (int) ( $row['id'] ?? 0 ),
			'line_user_id'       => $line_user_id,
			'wp_user_id'         => isset( $row['wp_user_id'] ) ? (int) $row['wp_user_id'] : 0,
			'display_name'       => (string) ( $row['display_name'] ?? '' ),
			'avatar_url'         => (string) ( $row['avatar_url'] ?? '' ),
			'source_type'        => (string) ( $row['source_type'] ?? '' ),
			'status'             => (string) ( $row['status'] ?? '' ),
			'last_active'        => $row['last_active'] ?? null,
			'read_time'          => $row['read_time'] ?? null,
			'notes'              => (string) ( $row['notes'] ?? '' ),
			'unread_count'       => $unread,
			'last_message'       => $last,
			'last_from_customer' => $last_from_customer,
		);
	}

	/**
	 * Normalize friend fields for detail response.
	 *
	 * @param array $row DB row.
	 * @return array
	 */
	private function normalize_friend( array $row ): array {
		return array(
			'id'           => (int) ( $row['id'] ?? 0 ),
			'line_user_id' => (string) ( $row['line_user_id'] ?? '' ),
			'wp_user_id'   => isset( $row['wp_user_id'] ) ? (int) $row['wp_user_id'] : 0,
			'display_name' => (string) ( $row['display_name'] ?? '' ),
			'avatar_url'   => (string) ( $row['avatar_url'] ?? '' ),
			'source_type'  => (string) ( $row['source_type'] ?? '' ),
			'group_id'     => $row['group_id'] ?? null,
			'last_active'  => $row['last_active'] ?? null,
			'read_time'    => $row['read_time'] ?? null,
			'status'       => (string) ( $row['status'] ?? '' ),
			'notes'        => (string) ( $row['notes'] ?? '' ),
		);
	}

	/**
	 * Effective read cutoff: stored read_time or NOW()-3 hours.
	 *
	 * @param mixed $read_time Raw read_time.
	 * @return string MySQL datetime.
	 */
	private function effective_read_time( $read_time ): string {
		if ( is_string( $read_time ) && '' !== trim( $read_time ) && '0000-00-00 00:00:00' !== $read_time ) {
			return $read_time;
		}

		return gmdate( 'Y-m-d H:i:s', time() - 3 * HOUR_IN_SECONDS );
	}

	/**
	 * Whether sender_type is inbound customer (user).
	 *
	 * @param string $sender_type Sender type.
	 * @return bool
	 */
	private function is_inbound_sender( string $sender_type ): bool {
		return 'user' === strtolower( $sender_type );
	}

	/**
	 * Month partition table names for current and previous month.
	 *
	 * @return string[]
	 */
	private function message_partition_tables(): array {
		global $wpdb;

		$tables = array();
		$year   = (int) gmdate( 'Y' );
		$month  = (int) gmdate( 'n' );

		for ( $i = 0; $i < 2; $i++ ) {
			$m = $month - $i;
			$y = $year;
			if ( $m < 1 ) {
				$m += 12;
				--$y;
			}
			$name  = $wpdb->prefix . sprintf( 'otz_messages_%04d_%02d', $y, $m );
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) );
			if ( $found === $name ) {
				$tables[] = $name;
			}
		}

		return $tables;
	}

	/**
	 * Empty group_id SQL fragment (NULL or empty string).
	 *
	 * @return string
	 */
	private function empty_group_sql(): string {
		return "( group_id IS NULL OR group_id = '' OR group_id = '0' )";
	}

	/**
	 * Count unread inbound DM messages after read_time across 2 partitions.
	 *
	 * @param string $line_user_id LINE user id.
	 * @param string $read_time    Cutoff datetime.
	 * @return int
	 */
	private function count_unread( string $line_user_id, string $read_time ): int {
		global $wpdb;

		if ( '' === $line_user_id ) {
			return 0;
		}

		$total   = 0;
		$empty_g = $this->empty_group_sql();

		foreach ( $this->message_partition_tables() as $table ) {
			$sql = "SELECT COUNT(*) FROM {$table}
				WHERE line_user_id = %s
				AND {$empty_g}
				AND LOWER(sender_type) = %s
				AND CONCAT(sent_date, ' ', sent_time) > %s";

			$count = (int) $wpdb->get_var(
				$wpdb->prepare( $sql, $line_user_id, 'user', $read_time )
			);
			$total += $count;
		}

		return $total;
	}

	/**
	 * Latest DM message for line_user_id (empty group).
	 *
	 * @param string $line_user_id LINE user id.
	 * @return array|null
	 */
	private function get_last_message( string $line_user_id ): ?array {
		$messages = $this->get_messages( $line_user_id, 1 );
		// get_messages returns chronological; with limit 1 that is the newest.
		return $messages[0] ?? null;
	}

	/**
	 * Recent DM messages (empty group), newest first then reversed to chronological for detail?
	 * List last_message wants newest; detail may want chronological.
	 * Return newest-first for last; for get_conversation return chronological oldest-first.
	 *
	 * @param string $line_user_id LINE user id.
	 * @param int    $limit        Max messages.
	 * @return array<int,array>
	 */
	public function get_messages( string $line_user_id, int $limit = 100 ): array {
		global $wpdb;

		if ( '' === $line_user_id ) {
			return array();
		}

		$limit   = max( 1, min( 500, $limit ) );
		$empty_g = $this->empty_group_sql();
		$all     = array();

		foreach ( $this->message_partition_tables() as $table ) {
			$sql  = "SELECT line_user_id, sender_type, group_id, sent_date, sent_time,
					message_type, message_content, sender_name,
					line_message_id, quote_token, quoted_message_id
				FROM {$table}
				WHERE line_user_id = %s AND {$empty_g}
				ORDER BY sent_date DESC, sent_time DESC
				LIMIT %d";
			$rows = $wpdb->get_results(
				$wpdb->prepare( $sql, $line_user_id, $limit ),
				ARRAY_A
			);
			if ( is_array( $rows ) ) {
				foreach ( $rows as $row ) {
					$all[] = $this->normalize_message( $row );
				}
			}
		}

		usort(
			$all,
			static function ( array $a, array $b ): int {
				return strcmp(
					(string) ( $b['sent_at'] ?? '' ),
					(string) ( $a['sent_at'] ?? '' )
				);
			}
		);

		$all = array_slice( $all, 0, $limit );

		// Chronological (oldest first) for consumers.
		return array_reverse( $all );
	}

	/**
	 * Normalize a message row.
	 *
	 * @param array $row DB row.
	 * @return array
	 */
	private function normalize_message( array $row ): array {
		$sent_at = trim( (string) ( $row['sent_date'] ?? '' ) . ' ' . (string) ( $row['sent_time'] ?? '' ) );

		return array(
			'line_user_id'      => (string) ( $row['line_user_id'] ?? '' ),
			'sender_type'       => (string) ( $row['sender_type'] ?? '' ),
			'group_id'          => $row['group_id'] ?? null,
			'sent_date'         => (string) ( $row['sent_date'] ?? '' ),
			'sent_time'         => (string) ( $row['sent_time'] ?? '' ),
			'sent_at'           => $sent_at,
			'message_type'      => (string) ( $row['message_type'] ?? '' ),
			'message_content'   => (string) ( $row['message_content'] ?? '' ),
			'sender_name'       => (string) ( $row['sender_name'] ?? '' ),
			'line_message_id'   => $this->nullable_string( $row['line_message_id'] ?? null ),
			'quote_token'       => $this->nullable_string( $row['quote_token'] ?? null ),
			'quoted_message_id' => $this->nullable_string( $row['quoted_message_id'] ?? null ),
		);
	}

	/**
	 * Non-empty string or null (never invent quote/LINE ids).
	 *
	 * @param mixed $value Raw DB value.
	 * @return string|null
	 */
	private function nullable_string( $value ): ?string {
		if ( null === $value ) {
			return null;
		}
		$str = trim( (string) $value );

		return '' === $str ? null : $str;
	}

	/**
	 * Latest WooCommerce order summaries for a WP user.
	 *
	 * @param int $wp_user_id WP user ID.
	 * @return array<int,array>
	 */
	private function get_woo_order_summaries( int $wp_user_id ): array {
		if ( ! function_exists( 'wc_get_orders' ) || $wp_user_id < 1 ) {
			return array();
		}

		$orders = wc_get_orders(
			array(
				'customer_id' => $wp_user_id,
				'limit'       => 5,
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);

		$data = array();
		foreach ( $orders as $order ) {
			$created = $order->get_date_created();
			$data[]  = array(
				'id'           => $order->get_id(),
				'order_number' => $order->get_order_number(),
				'status'       => $order->get_status(),
				'status_name'  => function_exists( 'wc_get_order_status_name' )
					? wc_get_order_status_name( $order->get_status() )
					: $order->get_status(),
				'date_created' => $created ? $created->date( 'c' ) : null,
				'total'        => $order->get_total(),
				'currency'     => $order->get_currency(),
			);
		}

		return $data;
	}
}
