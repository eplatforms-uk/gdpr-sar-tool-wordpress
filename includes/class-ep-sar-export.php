<?php
/**
 * Producing the document that goes back to the requester.
 *
 * Exports only the submissions the operator selected. There is deliberately no "export everything
 * that matched" shortcut: a substring search over eight thousand submissions will match people who
 * are not the requester, and releasing those would be a data breach committed with a compliance
 * tool.
 */

defined( 'ABSPATH' ) || exit;

class EP_SAR_Export {

	/** Send the selected submissions as a CSV download. */
	public static function csv( array $ids, string $term ): void {
		$records = EP_SAR_Search::hydrate( $ids );
		EP_SAR_Log::record( 'export', $term, count( $records ), 'csv: ' . implode( ',', array_map( 'intval', $ids ) ) );

		$filename = self::filename( $term, 'csv' );
		self::headers( $filename, 'text/csv' );

		$out = fopen( 'php://output', 'w' );
		// Excel opens a UTF-8 CSV as Windows-1252 unless the byte order mark is there, which turns
		// any accented name in the export into mojibake on the desk of whoever checks it.
		fwrite( $out, "\xEF\xBB\xBF" );

		fputcsv( $out, array( 'Submission ID', 'Form', 'Submitted', 'Question', 'Answer' ) );
		foreach ( $records as $r ) {
			foreach ( $r['answers'] as $a ) {
				fputcsv( $out, array(
					$r['id'],
					$r['form_title'],
					self::local_date( $r['date'] ),
					$a['label'],
					$a['value'],
				) );
			}
		}
		fclose( $out );
		exit;
	}

	/** Send the selected submissions as JSON, for a machine-readable request. */
	public static function json( array $ids, string $term ): void {
		$records = EP_SAR_Search::hydrate( $ids );
		EP_SAR_Log::record( 'export', $term, count( $records ), 'json: ' . implode( ',', array_map( 'intval', $ids ) ) );

		$payload = array(
			'generated'   => current_time( 'c' ),
			'site'        => home_url(),
			'search_term' => $term,
			'submissions' => array(),
		);
		foreach ( $records as $r ) {
			$answers = array();
			foreach ( $r['answers'] as $a ) {
				$answers[] = array( 'question' => $a['label'], 'answer' => $a['value'] );
			}
			$payload['submissions'][] = array(
				'id'        => $r['id'],
				'form'      => $r['form_title'],
				'submitted' => self::local_date( $r['date'] ),
				'answers'   => $answers,
			);
		}

		self::headers( self::filename( $term, 'json' ), 'application/json' );
		echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}

	private static function local_date( string $mysql ): string {
		return mysql2date( 'Y-m-d H:i', $mysql );
	}

	private static function filename( string $term, string $ext ): string {
		$slug = sanitize_title( $term );
		$slug = $slug !== '' ? $slug : 'subject-access-request';
		return sprintf( 'sar-%s-%s.%s', $slug, gmdate( 'Ymd-His' ), $ext );
	}

	private static function headers( string $filename, string $type ): void {
		nocache_headers();
		header( 'Content-Type: ' . $type . '; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );
	}

	/**
	 * Hook the same search into WordPress's own Tools > Export Personal Data.
	 *
	 * That tool emails the requester a link and handles identity confirmation itself, which is a
	 * reasonable route for a site that already uses it. It exports on email address only, so it
	 * finds less than the admin screen does — the two are complements, not duplicates.
	 */
	public static function register_wp_exporter( array $exporters ): array {
		$exporters['eplatforms-sar'] = array(
			'exporter_friendly_name' => __( 'Ninja Forms submissions', 'eplatforms-sar' ),
			'callback'               => array( __CLASS__, 'wp_exporter_callback' ),
		);
		return $exporters;
	}

	public static function wp_exporter_callback( string $email, int $page = 1 ): array {
		$page  = max( 1, $page );
		$per   = 50;
		$items = array();

		list( $records, ) = EP_SAR_Search::find( $email, 1000 );
		$slice = array_slice( $records, ( $page - 1 ) * $per, $per );

		foreach ( $slice as $r ) {
			$data = array();
			foreach ( $r['answers'] as $a ) {
				$data[] = array( 'name' => $a['label'], 'value' => $a['value'] );
			}
			$items[] = array(
				'group_id'    => 'ninja-forms-submissions',
				'group_label' => __( 'Form submissions', 'eplatforms-sar' ),
				'item_id'     => 'nf-sub-' . $r['id'],
				'data'        => array_merge(
					array(
						array( 'name' => __( 'Form', 'eplatforms-sar' ), 'value' => $r['form_title'] ),
						array( 'name' => __( 'Submitted', 'eplatforms-sar' ), 'value' => self::local_date( $r['date'] ) ),
					),
					$data
				),
			);
		}

		$done = ( ( $page - 1 ) * $per + count( $slice ) ) >= count( $records );
		if ( $items ) {
			EP_SAR_Log::record( 'export', $email, count( $items ), 'wp personal data exporter, page ' . $page );
		}
		return array( 'data' => $items, 'done' => $done );
	}
}
