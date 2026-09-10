<?php
/**
 * Tests: GET message quote fields via ConversationRepository.
 *
 * @package OrderChatzApi
 */

use OrderChatzApi\Services\ConversationRepository;

/**
 * @group conversations
 * @group quote
 */
class Test_Conversation_Repository_Quote_Fields extends WP_UnitTestCase {

	/**
	 * LINE user id fixture.
	 *
	 * @var string
	 */
	private string $line_user_id = 'U-quote-fields-test';

	/**
	 * Create monthly messages table for current month.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wpdb;
		$month = gmdate( 'Y_m' );
		$msgs  = $wpdb->prefix . 'otz_messages_' . $month;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL.
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$msgs} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id varchar(100) NOT NULL,
				line_user_id varchar(100) NOT NULL,
				source_type varchar(20) NOT NULL DEFAULT 'user',
				sender_type varchar(20) NOT NULL DEFAULT 'user',
				sender_name varchar(255) DEFAULT '',
				group_id varchar(64) DEFAULT NULL,
				sent_date date NOT NULL,
				sent_time time NOT NULL,
				message_type varchar(50) NOT NULL DEFAULT 'text',
				message_content longtext,
				reply_token varchar(255) DEFAULT NULL,
				quote_token varchar(255) DEFAULT NULL,
				quoted_message_id varchar(255) DEFAULT NULL,
				line_message_id varchar(255) DEFAULT NULL,
				raw_payload longtext,
				created_by bigint(20) unsigned DEFAULT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY event_id (event_id)
			) {$wpdb->get_charset_collate()}"
		);
	}

	/**
	 * Call private normalize_message via reflection.
	 *
	 * @param array $row DB-like row.
	 * @return array
	 */
	private function normalize( array $row ): array {
		$repo   = new ConversationRepository();
		$method = new ReflectionMethod( ConversationRepository::class, 'normalize_message' );
		$method->setAccessible( true );
		return $method->invoke( $repo, $row );
	}

	/**
	 * Scenario: Normalized message exposes stored quote fields.
	 */
	public function test_normalize_message_includes_quote_fields_when_present(): void {
		$out = $this->normalize(
			array(
				'line_user_id'       => $this->line_user_id,
				'sender_type'        => 'user',
				'group_id'           => null,
				'sent_date'          => '2026-09-10',
				'sent_time'          => '08:00:00',
				'message_type'       => 'text',
				'message_content'    => 'hello',
				'sender_name'        => 'Cust',
				'line_message_id'    => 'mid-1',
				'quote_token'        => 'qt-1',
				'quoted_message_id'  => 'mid-parent',
			)
		);

		$this->assertArrayHasKey( 'line_message_id', $out );
		$this->assertArrayHasKey( 'quote_token', $out );
		$this->assertArrayHasKey( 'quoted_message_id', $out );
		$this->assertSame( 'mid-1', $out['line_message_id'] );
		$this->assertSame( 'qt-1', $out['quote_token'] );
		$this->assertSame( 'mid-parent', $out['quoted_message_id'] );
	}

	/**
	 * Scenario: Missing DB values are null (never invented).
	 */
	public function test_normalize_message_quote_fields_null_when_missing(): void {
		$out = $this->normalize(
			array(
				'line_user_id'    => $this->line_user_id,
				'sender_type'     => 'user',
				'group_id'        => null,
				'sent_date'       => '2026-09-10',
				'sent_time'       => '08:01:00',
				'message_type'    => 'text',
				'message_content' => 'no quote',
				'sender_name'     => 'Cust',
			)
		);

		$this->assertArrayHasKey( 'line_message_id', $out );
		$this->assertArrayHasKey( 'quote_token', $out );
		$this->assertArrayHasKey( 'quoted_message_id', $out );
		$this->assertNull( $out['line_message_id'] );
		$this->assertNull( $out['quote_token'] );
		$this->assertNull( $out['quoted_message_id'] );
	}

	/**
	 * Empty strings from DB normalize to null (do not invent tokens).
	 */
	public function test_normalize_message_empty_strings_become_null(): void {
		$out = $this->normalize(
			array(
				'line_user_id'       => $this->line_user_id,
				'sender_type'        => 'ACCOUNT',
				'group_id'           => '',
				'sent_date'          => '2026-09-10',
				'sent_time'           => '08:02:00',
				'message_type'       => 'text',
				'message_content'    => 'x',
				'sender_name'        => 'Shop',
				'line_message_id'    => '',
				'quote_token'        => '',
				'quoted_message_id'  => '',
			)
		);

		$this->assertNull( $out['line_message_id'] );
		$this->assertNull( $out['quote_token'] );
		$this->assertNull( $out['quoted_message_id'] );
	}

	/**
	 * Scenario: get_messages SELECT + normalize returns quote fields from DB.
	 */
	public function test_get_messages_includes_quote_fields_from_db(): void {
		global $wpdb;

		$month = gmdate( 'Y_m' );
		$msgs  = $wpdb->prefix . 'otz_messages_' . $month;
		$now   = current_time( 'mysql' );
		$date  = gmdate( 'Y-m-d' );
		$time  = gmdate( 'H:i:s' );

		$wpdb->insert(
			$msgs,
			array(
				'event_id'           => 'evt-quote-' . wp_generate_password( 8, false ),
				'line_user_id'       => $this->line_user_id,
				'source_type'        => 'user',
				'sender_type'        => 'user',
				'sender_name'        => 'Cust',
				'group_id'           => null,
				'sent_date'          => $date,
				'sent_time'           => $time,
				'message_type'       => 'text',
				'message_content'    => 'quote me',
				'quote_token'        => 'qt-db-1',
				'quoted_message_id'  => 'mid-old',
				'line_message_id'    => 'mid-db-1',
				'created_at'         => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		$repo     = new ConversationRepository();
		$messages = $repo->get_messages( $this->line_user_id, 10 );

		$this->assertNotEmpty( $messages );
		$found = null;
		foreach ( $messages as $msg ) {
			if ( 'mid-db-1' === ( $msg['line_message_id'] ?? null ) ) {
				$found = $msg;
				break;
			}
		}
		$this->assertNotNull( $found, 'Expected message with line_message_id mid-db-1' );
		$this->assertSame( 'qt-db-1', $found['quote_token'] );
		$this->assertSame( 'mid-old', $found['quoted_message_id'] );
		$this->assertSame( 'mid-db-1', $found['line_message_id'] );
	}
}
