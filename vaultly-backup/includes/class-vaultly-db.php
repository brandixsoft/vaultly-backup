<?php
/**
 * Database dump / import / URL search-replace.
 *
 * Dump format: ONE statement per line (mysqli escaping turns newlines inside values
 * into \n), so import is a plain fgets() loop that can resume from a byte offset.
 * Every statement is checked against a whitelist before it is executed.
 */
defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions,WordPress.Security.EscapeOutput.ExceptionNotEscaped,WordPress.DB.DirectDatabaseQuery,Squiz.PHP.DiscouragedFunctions,PluginCheck.CodeAnalysis.WriteFile -- backup engine: streams multi-GB archives in resumable chunks inside its own wp-content/vaultly-backups folder; WP_Filesystem cannot stream or append. Exception messages go to the admin JSON/log (never echoed as HTML), and are translated, trusted strings.

final class VAULTLY_DB {

	const ROWS  = 1000;    // rows per SELECT batch
	const CHUNK = 524288;  // max bytes per INSERT statement (keep < max_allowed_packet)

	/** Dump is split into parts of ~this size so verify/restore never has to process one giant file in one step. */
	public static $part_size = 33554432;

	/** table => SQL condition (spam comments / revisions left out). Set by the job before every dump step. */
	public static $where = array();
	/* ---------- helpers ---------- */

	public static function my(): mysqli {
		global $wpdb;
		if ( ! ( $wpdb->dbh instanceof mysqli ) ) {
			throw new RuntimeException( __( 'The mysqli driver is required.', 'vaultly-backup' ) );
		}
		return $wpdb->dbh;
	}

	public static function q( string $sql, int $mode = MYSQLI_STORE_RESULT ) {
		$r = self::my()->query( $sql, $mode );
		if ( false === $r ) {
			throw new RuntimeException( sprintf( /* translators: %s: database error */ __( 'Database error: %s', 'vaultly-backup' ), substr( self::my()->error, 0, 300 ) ) );
		}
		return $r;
	}

	public static function id( string $name ): string {
		return '`' . str_replace( '`', '``', $name ) . '`';
	}

	/** Base tables of this site (view-safe, prefix-limited; case-insensitive: Windows MySQL lower-cases table names, so VpQLe_posts is listed as vpqle_posts). */
	public static function tables(): array {
		global $wpdb;
		$out = array();
		$r   = self::q( "SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'" );
		while ( $row = $r->fetch_row() ) {
			if ( 0 === stripos( $row[0], $wpdb->prefix ) ) {
				$out[] = $row[0];
			}
		}
		return $out;
	}

	/** Column info: names, text-like columns, single integer primary key (for keyset paging). */
	public static function schema( string $table ): array {
		$cols = $text = $keys = array();
		$r    = self::q( 'SHOW COLUMNS FROM ' . self::id( $table ) );
		while ( $c = $r->fetch_assoc() ) {
			$cols[ $c['Field'] ] = $c['Type'];
			if ( preg_match( '/char|text|json/i', $c['Type'] ) ) {
				$text[] = $c['Field'];
			}
		}
		$r = self::q( 'SHOW KEYS FROM ' . self::id( $table ) . " WHERE Key_name = 'PRIMARY'" );
		while ( $k = $r->fetch_assoc() ) {
			$keys[] = $k['Column_name'];
		}
		$isint = function ( $t ) {
			return (bool) preg_match( '/^(tiny|small|medium|big)?int([( ]|$)/i', $t );
		};
		$pk    = ( 1 === count( $keys ) && $isint( $cols[ $keys[0] ] ) ) ? $keys[0] : null;
		$names = array_keys( $cols );
		return array(
			'cols'  => $names,
			'text'  => $text,
			'pk'    => $pk, // single integer PK (URL replace)
			'pkidx' => $pk ? array_search( $pk, $names, true ) : null,
			'keys'  => $keys, // any PK, in order (stable keyset paging for the dump)
			'kidx'  => array_map( function ( $k ) use ( $names ) {
				return array_search( $k, $names, true );
			}, $keys ),
			'kint'  => array_map( function ( $k ) use ( $cols, $isint ) {
				return $isint( $cols[ $k ] );
			}, $keys ),
		);
	}

	/** Only statements produced by dump_step() may be executed. */
	public static function allowed( string $line, string $prefix ): bool {
		$t = '`' . preg_quote( $prefix, '/' ) . '[A-Za-z0-9_$]*`';
		return (bool) preg_match( "/^(DROP TABLE IF EXISTS $t;|CREATE TABLE $t \\(|INSERT INTO $t VALUES \\()/", $line );
	}

	/** WHERE fragment: rows strictly after key $last, e.g. (a > 1) OR (a = 1 AND b > 2). */
	private static function after( array $sch, array $last ): string {
		$lit = function ( $i ) use ( $sch, $last ) {
			$v = (string) base64_decode( (string) $last[ $i ] );
			return ( $sch['kint'][ $i ] && preg_match( '/^-?\d+$/', $v ) ) ? $v : "'" . self::my()->real_escape_string( $v ) . "'";
		};
		$or = array();
		foreach ( $sch['keys'] as $i => $k ) {
			$and = array();
			for ( $p = 0; $p < $i; $p++ ) {
				$and[] = self::id( $sch['keys'][ $p ] ) . ' = ' . $lit( $p );
			}
			$and[] = self::id( $k ) . ' > ' . $lit( $i );
			$or[]  = '(' . implode( ' AND ', $and ) . ')';
		}
		return implode( ' OR ', $or );
	}

	/* ---------- dump ---------- */

	public static function part_path( string $dir, int $n ): string {
		return sprintf( '%s/db-%04d.sql', $dir, $n );
	}

	/** Resumable. State $s is persisted by the caller. Writes $dir/db-0001.sql, db-0002.sql ... Returns true when finished. */
	public static function dump_step( array &$s, string $dir, float $deadline ): bool {
		$m = self::my();
		if ( ! isset( $s['tables'] ) ) {
			$s = array( 'tables' => self::tables(), 't' => 0, 'begun' => false, 'pos' => 0, 'part' => 1 );
		}
		// Drop anything written after the last persisted state (crash-safe resume).
		for ( $n = $s['part'] + 1; is_file( self::part_path( $dir, $n ) ); $n++ ) {
			wp_delete_file( self::part_path( $dir, $n ) );
		}
		$part = $s['part'];
		$out  = fopen( self::part_path( $dir, $part ), 'c+b' );
		if ( ! $out ) {
			throw new RuntimeException( __( 'Cannot write database export.', 'vaultly-backup' ) );
		}
		ftruncate( $out, $s['pos'] );
		fseek( $out, $s['pos'] );
		$w = function ( string $data ) use ( &$out, &$part, $dir ) {
			if ( strlen( $data ) !== fwrite( $out, $data ) ) {
				throw new RuntimeException( __( 'Cannot write database export (disk full?).', 'vaultly-backup' ) );
			}
			if ( ftell( $out ) > self::$part_size ) { // rotate at a statement boundary
				fclose( $out );
				$out = fopen( self::part_path( $dir, ++$part ), 'wb' );
			}
		};

		while ( $s['t'] < count( $s['tables'] ) ) {
			$table = $s['tables'][ $s['t'] ];
			$id    = self::id( $table );

			if ( ! $s['begun'] ) {
				$row = self::q( "SHOW CREATE TABLE $id" )->fetch_row();
				$w( "DROP TABLE IF EXISTS $id;\n" . preg_replace( '/\s*\R\s*/', ' ', $row[1] ) . ";\n" );
				$s['sch']   = self::schema( $table );
				$s['begun'] = true;
				$s['last']  = null;
				$s['off']   = 0;
				$s['pos']   = ftell( $out );
				$s['part']  = $part;
			}

			$sch = $s['sch'];
			$by  = implode( ',', array_map( array( __CLASS__, 'id' ), $sch['keys'] ) );
			do {
				$cond = array();
				if ( ! empty( self::$where[ $table ] ) ) {
					$cond[] = '(' . self::$where[ $table ] . ')';
				}
				if ( $sch['keys'] ) {
					// Keyset paging on the whole primary key (works for composite / non-integer keys, stable order).
					if ( null !== $s['last'] ) {
						$cond[] = '(' . self::after( $sch, $s['last'] ) . ')';
					}
					$sql = "SELECT * FROM $id" . ( $cond ? ' WHERE ' . implode( ' AND ', $cond ) : '' ) . " ORDER BY $by LIMIT " . self::ROWS;
				} else {
					$sql = "SELECT * FROM $id" . ( $cond ? ' WHERE ' . implode( ' AND ', $cond ) : '' ) . " LIMIT {$s['off']}," . self::ROWS; // ponytail: no primary key -> OFFSET paging (O(n^2), order not guaranteed).
				}
				$res = self::q( $sql, MYSQLI_USE_RESULT ); // streamed: memory stays flat even with huge rows
				$n   = 0;
				$buf = '';
				while ( $r = $res->fetch_row() ) {
					$tuple = '(' . implode( ',', array_map( function ( $v ) use ( $m ) {
						return null === $v ? 'NULL' : "'" . $m->real_escape_string( $v ) . "'";
					}, $r ) ) . ')';
					if ( '' !== $buf && strlen( $buf ) + strlen( $tuple ) > self::CHUNK ) {
						$w( "INSERT INTO $id VALUES $buf;\n" );
						$buf = '';
					}
					$buf .= ( '' === $buf ? '' : ',' ) . $tuple;
					$n++;
					if ( $sch['keys'] ) {
						$s['last'] = array_map( function ( $i ) use ( $r ) {
							return base64_encode( (string) $r[ $i ] ); // job state is JSON: key values can be binary
						}, $sch['kidx'] );
					}
				}
				$res->free();
				if ( '' !== $buf ) {
					$w( "INSERT INTO $id VALUES $buf;\n" );
				}
				$s['off'] += $n;
				$s['pos']  = ftell( $out );
				$s['part'] = $part;
				if ( self::ROWS === $n && microtime( true ) > $deadline ) { // always >= 1 batch of progress per call
					fclose( $out );
					return false;
				}
			} while ( self::ROWS === $n );

			$s['t']++;
			$s['begun'] = false;
		}
		$empty = 0 === ftell( $out ) && $part > 1; // empty trailing part after a rotation
		fclose( $out );
		if ( $empty ) {
			wp_delete_file( self::part_path( $dir, $part ) );
		}
		return true;
	}

	/* ---------- import ---------- */

	/** Executes the dump parts in order. ($s['base'] + $s['pos']) / $s['total'] = progress. */
	public static function import_step( array &$s, array $files, string $prefix, float $deadline ): bool {
		if ( ! isset( $s['f'] ) ) {
			$s = array( 'f' => 0, 'pos' => 0, 'base' => 0, 'total' => max( 1, array_sum( array_map( 'filesize', $files ) ) ) );
		}
		// Session settings are per connection, and every HTTP step is a new connection.
		self::q( "SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'" );
		self::q( 'SET SESSION foreign_key_checks = 0' );
		self::q( 'SET SESSION unique_checks = 0' );
		self::q( 'SET autocommit = 0' ); // one commit per step instead of one fsync per INSERT (much faster restore)

		while ( $s['f'] < count( $files ) ) {
			$h = fopen( $files[ $s['f'] ], 'rb' );
			fseek( $h, $s['pos'] );
			while ( false !== ( $line = fgets( $h ) ) ) {
				$line = rtrim( $line, "\r\n" );
				if ( '' !== $line && 0 !== strpos( $line, '-- vaultly' ) ) { // header / end marker of a .sql backup
					if ( ! self::allowed( $line, $prefix ) ) {
						throw new RuntimeException( sprintf( /* translators: 1: part number, 2: byte offset */ __( 'Backup contains an unexpected SQL statement (part %1$d, byte %2$d). Restore aborted.', 'vaultly-backup' ), $s['f'] + 1, $s['pos'] ) );
					}
					self::q( $line );
				}
				$s['pos'] = ftell( $h );
				if ( microtime( true ) > $deadline ) {
					fclose( $h );
					self::q( 'COMMIT' );
					return false;
				}
			}
			fclose( $h );
			$s['base'] += filesize( $files[ $s['f'] ] );
			$s['f']++;
			$s['pos'] = 0;
		}
		self::q( 'COMMIT' );
		self::q( 'SET autocommit = 1' ); // later phases of this same request (URL replace) must commit normally
		self::q( 'SET SESSION unique_checks = 1' );
		return true;
	}

	/* ---------- search & replace (URL change) ---------- */

	/** Serialized-data-safe replace. Objects are left untouched (ponytail: rewrite them if a plugin needs it). */
	public static function replace_value( $v, array $pairs ) {
		if ( is_array( $v ) ) {
			foreach ( $v as $k => $item ) {
				$v[ $k ] = self::replace_value( $item, $pairs );
			}
			return $v;
		}
		if ( ! is_string( $v ) || '' === $v ) {
			return $v;
		}
		if ( strlen( $v ) > 3 && ':' === $v[1] && false !== strpos( 'aOsidbCN', $v[0] ) ) {
			$u = @unserialize( $v, array( 'allowed_classes' => false ) );
			if ( false !== $u || 'b:0;' === $v ) {
				return serialize( self::replace_value( $u, $pairs ) );
			}
		}
		return strtr( $v, $pairs );
	}

	public static function replace_step( array &$s, array $pairs, float $deadline ): bool {
		global $wpdb;
		if ( ! $pairs ) {
			return true;
		}
		if ( ! isset( $s['tables'] ) ) {
			$s = array( 'tables' => self::tables(), 't' => 0, 'last' => 0, 'skipped' => array() );
		}
		$m = self::my();
		// LIKE prefilter: drop keys that contain another key (http://x/p contains //x/p), the shorter one already matches those rows.
		$keys    = array_keys( $pairs );
		$needles = array_values( array_filter( $keys, function ( $k ) use ( $keys ) {
			foreach ( $keys as $o ) {
				if ( $o !== $k && false !== strpos( $k, $o ) ) {
					return false;
				}
			}
			return true;
		} ) );
		while ( $s['t'] < count( $s['tables'] ) ) {
			$table = $s['tables'][ $s['t'] ];
			$sch   = self::schema( $table );

			if ( ! $sch['text'] || ! $sch['pk'] ) {
				if ( $sch['text'] ) {
					$s['skipped'][] = $table; // text columns but no single int PK
				}
				$s['t']++;
				$s['last'] = 0;
				continue;
			}

			$or = array();
			foreach ( $sch['text'] as $c ) {
				foreach ( $needles as $from ) {
					$or[] = self::id( $c ) . " LIKE '%" . $m->real_escape_string( addcslashes( $from, '_%\\' ) ) . "%'";
				}
			}
			$pkq = self::id( $sch['pk'] );
			$sel = implode( ',', array_map( array( __CLASS__, 'id' ), array_merge( array( $sch['pk'] ), $sch['text'] ) ) );

			do {
				$res  = self::q( "SELECT $sel FROM " . self::id( $table ) . " WHERE $pkq > " . (int) $s['last'] . ' AND (' . implode( ' OR ', $or ) . ") ORDER BY $pkq LIMIT 100" );
				$rows = $res->fetch_all( MYSQLI_ASSOC );
				$res->free();
				foreach ( $rows as $row ) {
					$new = array();
					foreach ( $sch['text'] as $c ) {
						if ( null !== $row[ $c ] ) {
							$nv = self::replace_value( $row[ $c ], $pairs );
							if ( $nv !== $row[ $c ] ) {
								$new[ $c ] = $nv;
							}
						}
					}
					if ( $new && false === $wpdb->update( $table, $new, array( $sch['pk'] => $row[ $sch['pk'] ] ) ) ) {
						throw new RuntimeException( sprintf( /* translators: %s: table name */ __( 'URL update failed in %s', 'vaultly-backup' ), $table ) );
					}
					$s['last'] = (int) $row[ $sch['pk'] ];
				}
				if ( 100 === count( $rows ) && microtime( true ) > $deadline ) {
					return false;
				}
			} while ( 100 === count( $rows ) );

			$s['t']++;
			$s['last'] = 0;
		}
		return true;
	}
}
