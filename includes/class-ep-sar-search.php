<?php
/**
 * Finding a person in Ninja Forms submissions.
 *
 * Ninja Forms 3.x keeps each submission as an `nf_sub` post. Every answer is a postmeta row keyed
 * `_field_{field_id}`, and the form it belongs to is `_form_id`. The human-readable labels live
 * outside the posts table, in `{prefix}nf3_fields`, keyed by the same field id — so a raw dump of
 * a submission is a list of `_field_116` values that means nothing to a reader. This joins the two
 * back together.
 *
 * Verified against Ninja Forms 3.15.3.
 */

defined( 'ABSPATH' ) || exit;

class EP_SAR_Search {

	/** Form id => title, from the Ninja Forms tables. */
	public static function forms(): array {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT id, title FROM {$wpdb->prefix}nf3_forms ORDER BY id" );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r->id ] = (string) $r->title;
		}
		return $out;
	}

	/**
	 * Field id => label, for one form.
	 *
	 * Labels are not unique and can be empty (an html or submit field has no meaningful label), so
	 * the field key is used as a fallback and the id is always available to fall back on again.
	 */
	public static function fields( int $form_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, label, `key`, type FROM {$wpdb->prefix}nf3_fields WHERE parent_id = %d", $form_id )
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$label = trim( (string) $r->label );
			if ( '' === $label ) {
				$label = trim( (string) $r->key );
			}
			$out[ (int) $r->id ] = array(
				'label' => $label !== '' ? $label : sprintf( 'Field %d', (int) $r->id ),
				'type'  => (string) $r->type,
			);
		}
		return $out;
	}

	/**
	 * Fields that never hold anything about the person, so are not worth searching or exporting.
	 *
	 * A submit button and an html block are stored like any other field; including them adds noise
	 * to a document that somebody has to read and sign off.
	 */
	private static function ignorable_types(): array {
		return array( 'submit', 'html', 'hr', 'heading' );
	}

	/**
	 * Submissions where any answer contains $term.
	 *
	 * Deliberately a substring match across every answer rather than an email-only lookup: people
	 * ask by the name they gave, by a phone number, or by an address, and an exact-match-on-email
	 * tool answers half the requests it is given. The trade-off is that a short term matches a lot,
	 * which is why results are capped and reviewed rather than exported straight out.
	 *
	 * @return array{0: array, 1: bool} matches, and whether the cap was hit
	 */
	public static function find( string $term, int $limit = 500 ): array {
		global $wpdb;

		$term = trim( $term );
		if ( mb_strlen( $term ) < 3 ) {
			return array( array(), false );
		}

		$like = '%' . $wpdb->esc_like( $term ) . '%';

		// One pass over postmeta for the matching submission ids, then a second query for the full
		// record of each. Pulling every meta row for every candidate in a single join would return
		// the same submission once per field and make the cap meaningless.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID
				   FROM {$wpdb->posts} p
				   JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
				  WHERE p.post_type = 'nf_sub'
				    AND p.post_status != 'trash'
				    AND m.meta_key LIKE '\_field\_%'
				    AND m.meta_value LIKE %s
			   ORDER BY p.post_date DESC
				  LIMIT %d",
				$like,
				$limit + 1
			)
		);

		$capped = count( $ids ) > $limit;
		if ( $capped ) {
			array_pop( $ids );
		}
		if ( ! $ids ) {
			return array( array(), false );
		}

		return array( self::hydrate( $ids, $term ), $capped );
	}

	/** Turn submission ids into readable records: form title, date, and label => value answers. */
	public static function hydrate( array $ids, string $term = '' ): array {
		global $wpdb;

		$ids = array_map( 'intval', $ids );
		$ids = array_filter( $ids );
		if ( ! $ids ) {
			return array();
		}
		$in = implode( ',', $ids );

		$forms = self::forms();
		$meta  = $wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($in)" );
		$posts = $wpdb->get_results( "SELECT ID, post_date, post_status FROM {$wpdb->posts} WHERE ID IN ($in)" );

		$byId = array();
		foreach ( (array) $posts as $p ) {
			$byId[ (int) $p->ID ] = array(
				'id'      => (int) $p->ID,
				'date'    => (string) $p->post_date,
				'status'  => (string) $p->post_status,
				'form_id' => 0,
				'answers' => array(),
				'matched' => array(),
			);
		}

		$raw = array();
		foreach ( (array) $meta as $m ) {
			$pid = (int) $m->post_id;
			if ( ! isset( $byId[ $pid ] ) ) {
				continue;
			}
			if ( '_form_id' === $m->meta_key ) {
				$byId[ $pid ]['form_id'] = (int) $m->meta_value;
				continue;
			}
			if ( 0 === strpos( $m->meta_key, '_field_' ) ) {
				$raw[ $pid ][ (int) substr( $m->meta_key, 7 ) ] = $m->meta_value;
			}
		}

		$fieldCache = array();
		foreach ( $byId as $pid => &$rec ) {
			$fid = (int) $rec['form_id'];
			$rec['form_title'] = $forms[ $fid ] ?? sprintf( 'Form %d', $fid );

			if ( ! isset( $fieldCache[ $fid ] ) ) {
				$fieldCache[ $fid ] = self::fields( $fid );
			}
			$defs = $fieldCache[ $fid ];

			foreach ( (array) ( $raw[ $pid ] ?? array() ) as $field_id => $value ) {
				$def = $defs[ $field_id ] ?? array( 'label' => sprintf( 'Field %d', $field_id ), 'type' => '' );
				if ( in_array( $def['type'], self::ignorable_types(), true ) ) {
					continue;
				}
				$flat = self::flatten( $value, $def['type'] );
				if ( '' === $flat ) {
					continue;
				}
				$rec['answers'][] = array(
					'field_id' => $field_id,
					'label'    => $def['label'],
					'value'    => $flat,
				);
				if ( '' !== $term && false !== mb_stripos( $flat, $term ) ) {
					$rec['matched'][] = $def['label'];
				}
			}
		}
		unset( $rec );

		// Newest first, which is the order somebody reviewing a request expects.
		uasort( $byId, static fn( $a, $b ) => strcmp( $b['date'], $a['date'] ) );
		return array_values( $byId );
	}

	/**
	 * Turn a stored answer into something a person can read.
	 *
	 * Checkbox and list fields store arrays, and occasionally serialised ones. A single checkbox
	 * stores 1 or 0, which in an export reads as a bare "0" next to a question — accurate, and no
	 * use to the person checking whether the answer was yes.
	 */
	private static function flatten( $value, string $type = '' ): string {
		if ( 'checkbox' === $type && ! is_array( $value ) ) {
			$v = trim( (string) $value );
			if ( '1' === $v || 'checked' === $v ) {
				return __( 'Yes', 'eplatforms-sar' );
			}
			if ( '0' === $v || '' === $v || 'unchecked' === $v ) {
				return __( 'No', 'eplatforms-sar' );
			}
		}
		return self::flatten_value( $value );
	}

	private static function flatten_value( $value ): string {
		if ( is_serialized( $value ) ) {
			$value = maybe_unserialize( $value );
		}
		if ( is_array( $value ) ) {
			$parts = array();
			array_walk_recursive( $value, static function ( $v ) use ( &$parts ) {
				$v = trim( (string) $v );
				if ( '' !== $v ) {
					$parts[] = $v;
				}
			} );
			return implode( ', ', $parts );
		}
		return trim( (string) $value );
	}
}
