<?php
/**
 * Test stubs for the data layer: a fake $wpdb backed by an in-memory SQLite
 * database (PDO), plus the WordPress functions used by the installer,
 * settings, storage and indexer classes.
 *
 * The fake behaves like wpdb where the plugin relies on it: prepare() with
 * %d %f %s %i and %%, query/get_results/get_row/get_var/get_col, insert/
 * update/delete with format arrays (null → NULL), insert_id, last_error,
 * esc_like, get_charset_collate. Values are fetched as strings, like
 * mysqli, so missing int casts show up in tests.
 *
 * Every function is guarded with function_exists so other stub files can
 * provide their own versions.
 *
 * @package SmartDocumentSearch
 */

if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}
if ( ! defined( 'OBJECT_K' ) ) {
	define( 'OBJECT_K', 'OBJECT_K' );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'ARRAY_N' ) ) {
	define( 'ARRAY_N', 'ARRAY_N' );
}
if ( ! defined( 'MB_IN_BYTES' ) ) {
	define( 'MB_IN_BYTES', 1048576 );
}
if ( ! defined( 'KB_IN_BYTES' ) ) {
	define( 'KB_IN_BYTES', 1024 );
}

if ( ! class_exists( 'HDS_Test_Die_Exception' ) ) {
	/**
	 * Thrown by the wp_die() stub.
	 */
	class HDS_Test_Die_Exception extends RuntimeException {

		/**
		 * Title passed to wp_die().
		 *
		 * @var string
		 */
		public $title = '';
	}
}

if ( ! class_exists( 'HDS_Test_WPDB' ) ) {
	/**
	 * Fake wpdb on PDO SQLite (in memory).
	 */
	class HDS_Test_WPDB {

		/** @var string */
		public $prefix = 'wp_';
		/** @var string */
		public $base_prefix = 'wp_';
		/** @var string */
		public $options = 'wp_options';
		/** @var string */
		public $usermeta = 'wp_usermeta';
		/** @var string */
		public $sitemeta = 'wp_sitemeta';
		/** @var int */
		public $blogid = 1;
		/** @var int */
		public $insert_id = 0;
		/** @var string */
		public $last_error = '';
		/** @var string */
		public $last_query = '';
		/** @var array */
		public $last_result = array();
		/** @var int */
		public $num_rows = 0;
		/** @var int */
		public $rows_affected = 0;
		/** @var string[] Executed SQL (most recent last). */
		public $queries = array();
		/** @var string|null Regex: queries matching it fail (simulated DB error). */
		public $fail_pattern = null;

		/**
		 * PDO connection.
		 *
		 * @var PDO
		 */
		private $pdo;

		/**
		 * Constructor.
		 */
		public function __construct() {
			$this->pdo = new PDO( 'sqlite::memory:' );
			$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
			$this->pdo->setAttribute( PDO::ATTR_STRINGIFY_FETCHES, true );
			$this->pdo->setAttribute( PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC );
		}

		/**
		 * Underlying PDO (for test assertions).
		 *
		 * @return PDO
		 */
		public function pdo() {
			return $this->pdo;
		}

		/**
		 * Create the plugin tables plus options/usermeta in SQLite DDL.
		 *
		 * @param string|null $prefix Table prefix (default: $this->prefix).
		 * @return void
		 */
		public function create_test_tables( $prefix = null ) {
			$p = null === $prefix ? $this->prefix : $prefix;
			$this->pdo->exec(
				"CREATE TABLE IF NOT EXISTS {$p}hds_docs (
					id INTEGER PRIMARY KEY AUTOINCREMENT,
					title TEXT NOT NULL,
					file_name TEXT NOT NULL,
					original_name TEXT NOT NULL,
					ext TEXT NOT NULL,
					file_size INTEGER NOT NULL DEFAULT 0,
					file_hash TEXT NOT NULL DEFAULT '',
					status TEXT NOT NULL DEFAULT 'pending',
					status_message TEXT NULL,
					chunk_count INTEGER NOT NULL DEFAULT 0,
					char_count INTEGER NOT NULL DEFAULT 0,
					index_hash TEXT NOT NULL DEFAULT '',
					indexed_at TEXT NULL,
					created_at TEXT NOT NULL,
					updated_at TEXT NOT NULL
				)"
			);
			$this->pdo->exec( "CREATE INDEX IF NOT EXISTS {$p}hds_docs_status ON {$p}hds_docs (status)" );
			$this->pdo->exec( "CREATE INDEX IF NOT EXISTS {$p}hds_docs_file_hash ON {$p}hds_docs (file_hash)" );
			$this->pdo->exec(
				"CREATE TABLE IF NOT EXISTS {$p}hds_chunks (
					id INTEGER PRIMARY KEY AUTOINCREMENT,
					doc_id INTEGER NOT NULL,
					chunk_index INTEGER NOT NULL,
					text TEXT NOT NULL,
					embedding BLOB NULL,
					created_at TEXT NOT NULL,
					UNIQUE (doc_id, chunk_index)
				)"
			);
			$this->pdo->exec( "CREATE INDEX IF NOT EXISTS {$p}hds_chunks_doc_id ON {$p}hds_chunks (doc_id)" );
			$this->pdo->exec(
				"CREATE TABLE IF NOT EXISTS {$this->options} (
					option_id INTEGER PRIMARY KEY AUTOINCREMENT,
					option_name TEXT NOT NULL UNIQUE,
					option_value TEXT NOT NULL,
					autoload TEXT NOT NULL DEFAULT 'yes'
				)"
			);
			$this->pdo->exec(
				"CREATE TABLE IF NOT EXISTS {$this->usermeta} (
					umeta_id INTEGER PRIMARY KEY AUTOINCREMENT,
					user_id INTEGER NOT NULL DEFAULT 0,
					meta_key TEXT NULL,
					meta_value TEXT NULL
				)"
			);
		}

		/**
		 * Whether a table exists.
		 *
		 * @param string $table Table name.
		 * @return bool
		 */
		public function table_exists( $table ) {
			$stmt = $this->pdo->prepare( "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?" );
			$stmt->execute( array( $table ) );
			return false !== $stmt->fetchColumn();
		}

		/**
		 * Prefix of a blog's tables.
		 *
		 * @param int|null $blog_id Blog id.
		 * @return string
		 */
		public function get_blog_prefix( $blog_id = null ) {
			$blog_id = null === $blog_id ? $this->blogid : (int) $blog_id;
			return ( $blog_id > 1 ) ? $this->base_prefix . $blog_id . '_' : $this->base_prefix;
		}

		/**
		 * Switch the prefix to a blog (multisite emulation).
		 *
		 * @param int $blog_id Blog id.
		 * @return void
		 */
		public function set_blog_id( $blog_id ) {
			$this->blogid = (int) $blog_id;
			$this->prefix = $this->get_blog_prefix( $blog_id );
		}

		/**
		 * Quote a string literal for SQLite.
		 *
		 * @param mixed $value Value.
		 * @return string
		 */
		private function quote( $value ) {
			return "'" . str_replace( "'", "''", (string) $value ) . "'";
		}

		/**
		 * wpdb::prepare() emulation.
		 *
		 * @param string $query   Query with placeholders.
		 * @param mixed  ...$args Values (or one array of values).
		 * @return string
		 * @throws InvalidArgumentException When the number of values does not match.
		 */
		public function prepare( $query, ...$args ) {
			if ( 1 === count( $args ) && is_array( $args[0] ) ) {
				$args = $args[0];
			}
			$args  = array_values( $args );
			$query = str_replace( array( "'%s'", '"%s"' ), '%s', (string) $query );
			$index = 0;
			$used  = 0;
			$self  = $this;
			$out   = preg_replace_callback(
				'/%%|%(\d+)\$([dfFsi])|%([dfFsi])/',
				static function ( $m ) use ( &$index, &$used, $args, $self ) {
					if ( '%%' === $m[0] ) {
						return '%';
					}
					if ( '' !== $m[1] ) {
						$pos  = (int) $m[1] - 1;
						$type = $m[2];
						$used = max( $used, $pos + 1 );
					} else {
						$pos  = $index++;
						$type = $m[3];
						$used = max( $used, $index );
					}
					if ( ! array_key_exists( $pos, $args ) ) {
						throw new InvalidArgumentException( 'prepare(): missing value for placeholder ' . $m[0] );
					}
					$value = $args[ $pos ];
					switch ( $type ) {
						case 'd':
							return (string) (int) $value;
						case 'f':
						case 'F':
							return sprintf( '%F', (float) $value );
						case 'i':
							return '`' . str_replace( '`', '``', (string) $value ) . '`';
						default:
							return $self->quote_public( null === $value ? '' : $value );
					}
				},
				$query
			);
			if ( $used !== count( $args ) ) {
				throw new InvalidArgumentException( sprintf( 'prepare(): %d values for %d placeholders.', count( $args ), $used ) );
			}
			return $out;
		}

		/**
		 * Public wrapper of quote() for the prepare() closure.
		 *
		 * @param mixed $value Value.
		 * @return string
		 */
		public function quote_public( $value ) {
			return $this->quote( $value );
		}

		/**
		 * wpdb::esc_like().
		 *
		 * @param string $text Text.
		 * @return string
		 */
		public function esc_like( $text ) {
			return addcslashes( (string) $text, '_%\\' );
		}

		/**
		 * wpdb::get_charset_collate().
		 *
		 * @return string
		 */
		public function get_charset_collate() {
			return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';
		}

		/**
		 * Run a query.
		 *
		 * @param string $sql SQL.
		 * @return int|bool Rows (SELECT: number of rows; others: affected rows), true for DDL, false on error.
		 */
		public function query( $sql ) {
			$this->last_error  = '';
			$this->last_result = array();
			$this->num_rows    = 0;
			$this->last_query  = (string) $sql;
			$this->queries[]   = (string) $sql;
			if ( count( $this->queries ) > 2000 ) {
				array_shift( $this->queries );
			}

			if ( null !== $this->fail_pattern && preg_match( $this->fail_pattern, (string) $sql ) ) {
				$this->last_error = 'Simulated database error.';
				return false;
			}

			// MySQL treats backslash as the default LIKE escape; SQLite needs ESCAPE.
			$sql = (string) preg_replace( "/(\\bLIKE\\s+'(?:[^']|'')*')(?!\\s*ESCAPE)/i", "$1 ESCAPE '\\\\'", (string) $sql );

			try {
				if ( preg_match( '/^\s*(SELECT|PRAGMA|WITH)\b/i', $sql ) ) {
					$stmt              = $this->pdo->query( $sql );
					$this->last_result = $stmt->fetchAll( PDO::FETCH_ASSOC );
					$this->num_rows    = count( $this->last_result );
					return $this->num_rows;
				}
				$affected = $this->pdo->exec( $sql );
				if ( preg_match( '/^\s*(INSERT|REPLACE)\b/i', $sql ) ) {
					$this->insert_id = (int) $this->pdo->lastInsertId();
				}
				if ( preg_match( '/^\s*(CREATE|DROP|ALTER|TRUNCATE)\b/i', $sql ) ) {
					return true;
				}
				$this->rows_affected = (int) $affected;
				return $this->rows_affected;
			} catch ( PDOException $e ) {
				$this->last_error = $e->getMessage();
				return false;
			}
		}

		/**
		 * Convert result rows to the requested output type.
		 *
		 * @param array  $rows   Assoc rows.
		 * @param string $output Output type.
		 * @return array
		 */
		private function shape( array $rows, $output ) {
			switch ( $output ) {
				case ARRAY_A:
					return $rows;
				case ARRAY_N:
					return array_map( 'array_values', $rows );
				case OBJECT_K:
					$out = array();
					foreach ( $rows as $row ) {
						$key = reset( $row );
						if ( ! isset( $out[ $key ] ) ) {
							$out[ $key ] = (object) $row;
						}
					}
					return $out;
				default:
					return array_map(
						static function ( $row ) {
							return (object) $row;
						},
						$rows
					);
			}
		}

		/**
		 * wpdb::get_results().
		 *
		 * @param string|null $sql    SQL (null = last result).
		 * @param string      $output Output type.
		 * @return array|null
		 */
		public function get_results( $sql = null, $output = OBJECT ) {
			if ( null !== $sql && false === $this->query( $sql ) ) {
				return null;
			}
			return $this->shape( $this->last_result, $output );
		}

		/**
		 * wpdb::get_row().
		 *
		 * @param string|null $sql    SQL.
		 * @param string      $output Output type.
		 * @param int         $y      Row offset.
		 * @return mixed
		 */
		public function get_row( $sql = null, $output = OBJECT, $y = 0 ) {
			if ( null !== $sql && false === $this->query( $sql ) ) {
				return null;
			}
			if ( ! isset( $this->last_result[ $y ] ) ) {
				return null;
			}
			$rows = $this->shape( array( $this->last_result[ $y ] ), $output );
			return reset( $rows );
		}

		/**
		 * wpdb::get_var().
		 *
		 * @param string|null $sql SQL.
		 * @param int         $x   Column offset.
		 * @param int         $y   Row offset.
		 * @return string|null
		 */
		public function get_var( $sql = null, $x = 0, $y = 0 ) {
			if ( null !== $sql && false === $this->query( $sql ) ) {
				return null;
			}
			if ( ! isset( $this->last_result[ $y ] ) ) {
				return null;
			}
			$values = array_values( $this->last_result[ $y ] );
			return isset( $values[ $x ] ) ? $values[ $x ] : null;
		}

		/**
		 * wpdb::get_col().
		 *
		 * @param string|null $sql SQL.
		 * @param int         $x   Column offset.
		 * @return array
		 */
		public function get_col( $sql = null, $x = 0 ) {
			if ( null !== $sql && false === $this->query( $sql ) ) {
				return array();
			}
			$out = array();
			foreach ( $this->last_result as $row ) {
				$values = array_values( $row );
				$out[]  = isset( $values[ $x ] ) ? $values[ $x ] : null;
			}
			return $out;
		}

		/**
		 * Build "col = value" pieces like wpdb::process_fields().
		 *
		 * @param array             $data   Column => value.
		 * @param array|string|null $format Formats.
		 * @return string[] Column => SQL value.
		 */
		private function values( array $data, $format ) {
			$formats = is_array( $format ) ? array_values( $format ) : ( null === $format ? array() : array( $format ) );
			$out     = array();
			$i       = 0;
			foreach ( $data as $column => $value ) {
				if ( isset( $formats[ $i ] ) ) {
					$f = $formats[ $i ];
				} elseif ( ! is_array( $format ) && null !== $format ) {
					$f = $format;
				} else {
					$f = '%s';
				}
				++$i;
				if ( null === $value ) {
					$out[ $column ] = 'NULL';
					continue;
				}
				$out[ $column ] = $this->prepare( $f, $value );
			}
			return $out;
		}

		/**
		 * wpdb::insert().
		 *
		 * @param string            $table  Table.
		 * @param array             $data   Column => value.
		 * @param array|string|null $format Formats.
		 * @return int|false
		 */
		public function insert( $table, $data, $format = null ) {
			$values = $this->values( $data, $format );
			$sql    = 'INSERT INTO ' . $table . ' (' . implode( ', ', array_keys( $values ) ) . ') VALUES (' . implode( ', ', $values ) . ')';
			return $this->query( $sql );
		}

		/**
		 * wpdb::update().
		 *
		 * @param string            $table        Table.
		 * @param array             $data         Column => value.
		 * @param array             $where        Column => value.
		 * @param array|string|null $format       Formats.
		 * @param array|string|null $where_format Formats.
		 * @return int|false
		 */
		public function update( $table, $data, $where, $format = null, $where_format = null ) {
			$set = array();
			foreach ( $this->values( $data, $format ) as $column => $value ) {
				$set[] = $column . ' = ' . $value;
			}
			$conditions = array();
			foreach ( $this->values( $where, $where_format ) as $column => $value ) {
				$conditions[] = 'NULL' === $value ? $column . ' IS NULL' : $column . ' = ' . $value;
			}
			$sql = 'UPDATE ' . $table . ' SET ' . implode( ', ', $set ) . ' WHERE ' . implode( ' AND ', $conditions );
			return $this->query( $sql );
		}

		/**
		 * wpdb::delete().
		 *
		 * @param string            $table        Table.
		 * @param array             $where        Column => value.
		 * @param array|string|null $where_format Formats.
		 * @return int|false
		 */
		public function delete( $table, $where, $where_format = null ) {
			$conditions = array();
			foreach ( $this->values( $where, $where_format ) as $column => $value ) {
				$conditions[] = 'NULL' === $value ? $column . ' IS NULL' : $column . ' = ' . $value;
			}
			return $this->query( 'DELETE FROM ' . $table . ' WHERE ' . implode( ' AND ', $conditions ) );
		}

		/**
		 * Stub.
		 *
		 * @param bool $suppress Suppress.
		 * @return bool
		 */
		public function suppress_errors( $suppress = true ) {
			return false;
		}

		/**
		 * Stub.
		 *
		 * @return bool
		 */
		public function hide_errors() {
			return false;
		}

		/**
		 * Stub.
		 *
		 * @return void
		 */
		public function flush() {
			$this->last_result = array();
			$this->last_error  = '';
		}
	}
}

if ( ! function_exists( 'hds_test_create_tables' ) ) {
	/**
	 * Fresh fake $wpdb (empty in-memory database) with the plugin tables.
	 *
	 * @return HDS_Test_WPDB
	 */
	function hds_test_create_tables() {
		$GLOBALS['wpdb'] = new HDS_Test_WPDB();
		$GLOBALS['wpdb']->create_test_tables();
		return $GLOBALS['wpdb'];
	}
}

if ( ! function_exists( 'hds_test_rmdir' ) ) {
	/**
	 * Recursively delete a test directory (no symlink following).
	 *
	 * @param string $dir Directory.
	 * @return void
	 */
	function hds_test_rmdir( $dir ) {
		if ( is_link( $dir ) || is_file( $dir ) ) {
			unlink( $dir );
			return;
		}
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			hds_test_rmdir( $dir . '/' . $entry );
		}
		rmdir( $dir );
	}
}

if ( ! function_exists( 'hds_test_clean_uploads' ) ) {
	/**
	 * Delete the stub uploads directory ($GLOBALS['hds_test']['upload_dir']).
	 *
	 * @return string The (now missing) uploads directory.
	 */
	function hds_test_clean_uploads() {
		$dir = $GLOBALS['hds_test']['upload_dir'];
		hds_test_rmdir( $dir );
		clearstatcache();
		return $dir;
	}
}

// A database for tests that do not set up their own.
if ( ! isset( $GLOBALS['wpdb'] ) ) {
	hds_test_create_tables();
}

if ( ! function_exists( 'wp_die' ) ) {
	/**
	 * Stub: throws HDS_Test_Die_Exception.
	 *
	 * @param string $message Message.
	 * @param string $title   Title.
	 * @param array  $args    Args.
	 * @return void
	 * @throws HDS_Test_Die_Exception Always.
	 */
	function wp_die( $message = '', $title = '', $args = array() ) {
		$e        = new HDS_Test_Die_Exception( is_string( $message ) ? $message : 'wp_die' );
		$e->title = is_string( $title ) ? $title : '';
		throw $e;
	}
}

if ( ! function_exists( 'plugin_basename' ) ) {
	/**
	 * Stub.
	 *
	 * @param string $file File.
	 * @return string
	 */
	function plugin_basename( $file ) {
		return 'smart-document-search/' . basename( (string) $file );
	}
}

if ( ! function_exists( 'deactivate_plugins' ) ) {
	/**
	 * Stub: records the call.
	 *
	 * @param string|string[] $plugins Plugins.
	 * @param bool            $silent  Silent.
	 * @param bool|null       $network Network.
	 * @return void
	 */
	function deactivate_plugins( $plugins, $silent = false, $network = null ) {
		$GLOBALS['hds_test']['deactivated'][] = $plugins;
	}
}

if ( ! function_exists( 'dbDelta' ) ) {
	/**
	 * Stub: records the SQL.
	 *
	 * @param string|string[] $queries SQL.
	 * @param bool            $execute Execute.
	 * @return array
	 */
	function dbDelta( $queries = '', $execute = true ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName
		$GLOBALS['hds_test']['dbdelta'][] = $queries;
		return array();
	}
}

if ( ! function_exists( 'add_settings_error' ) ) {
	/**
	 * Stub: records settings errors.
	 *
	 * @param string $setting Setting.
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @param string $type    Type.
	 * @return void
	 */
	function add_settings_error( $setting, $code, $message, $type = 'error' ) {
		$GLOBALS['hds_test']['settings_errors'][] = array(
			'setting' => $setting,
			'code'    => $code,
			'message' => $message,
			'type'    => $type,
		);
	}
}

if ( ! function_exists( 'get_settings_errors' ) ) {
	/**
	 * Stub.
	 *
	 * @param string $setting  Setting.
	 * @param bool   $sanitize Sanitize.
	 * @return array
	 */
	function get_settings_errors( $setting = '', $sanitize = false ) {
		$all = isset( $GLOBALS['hds_test']['settings_errors'] ) ? $GLOBALS['hds_test']['settings_errors'] : array();
		if ( '' === $setting ) {
			return $all;
		}
		return array_values(
			array_filter(
				$all,
				static function ( $e ) use ( $setting ) {
					return $e['setting'] === $setting;
				}
			)
		);
	}
}

if ( ! function_exists( 'is_multisite' ) ) {
	/**
	 * Stub: $GLOBALS['hds_test']['multisite'].
	 *
	 * @return bool
	 */
	function is_multisite() {
		return ! empty( $GLOBALS['hds_test']['multisite'] );
	}
}

if ( ! function_exists( 'get_sites' ) ) {
	/**
	 * Stub: $GLOBALS['hds_test']['sites'] (ids), default array( 1 ).
	 *
	 * @param array $args Args.
	 * @return array
	 */
	function get_sites( $args = array() ) {
		$ids = isset( $GLOBALS['hds_test']['sites'] ) ? $GLOBALS['hds_test']['sites'] : array( 1 );
		if ( isset( $args['fields'] ) && 'ids' === $args['fields'] ) {
			return $ids;
		}
		return array_map(
			static function ( $id ) {
				return (object) array( 'blog_id' => (string) $id );
			},
			$ids
		);
	}
}

if ( ! function_exists( 'get_current_blog_id' ) ) {
	/**
	 * Stub.
	 *
	 * @return int
	 */
	function get_current_blog_id() {
		return isset( $GLOBALS['hds_test']['blog_id'] ) ? (int) $GLOBALS['hds_test']['blog_id'] : 1;
	}
}

if ( ! function_exists( 'switch_to_blog' ) ) {
	/**
	 * Stub: records the switch and changes the $wpdb prefix.
	 *
	 * @param int $blog_id Blog id.
	 * @return bool
	 */
	function switch_to_blog( $blog_id, $deprecated = null ) {
		$GLOBALS['hds_test']['blog_stack'][] = get_current_blog_id();
		$GLOBALS['hds_test']['switched'][]   = (int) $blog_id;
		$GLOBALS['hds_test']['blog_id']      = (int) $blog_id;
		if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'set_blog_id' ) ) {
			$GLOBALS['wpdb']->set_blog_id( (int) $blog_id );
		}
		return true;
	}
}

if ( ! function_exists( 'restore_current_blog' ) ) {
	/**
	 * Stub.
	 *
	 * @return bool
	 */
	function restore_current_blog() {
		$prev                           = empty( $GLOBALS['hds_test']['blog_stack'] ) ? 1 : array_pop( $GLOBALS['hds_test']['blog_stack'] );
		$GLOBALS['hds_test']['blog_id'] = (int) $prev;
		if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'set_blog_id' ) ) {
			$GLOBALS['wpdb']->set_blog_id( (int) $prev );
		}
		return true;
	}
}

if ( ! function_exists( 'esc_sql' ) ) {
	/**
	 * Stub.
	 *
	 * @param string $data Data.
	 * @return string
	 */
	function esc_sql( $data ) {
		return str_replace( "'", "''", (string) $data );
	}
}
