<?php
/**
 * Paging, sorting and search for list screens.
 *
 * @package SugarCommerce\Paginate
 */

declare( strict_types=1 );

namespace SugarCommerce\Paginate;

use SugarCommerce\Sanitize\Input;
use SugarCommerce\Sqlite\Database;

/**
 * Class Paginator
 *
 * Runs a paged query and returns a {@see Page}.
 *
 * Column names cannot be bound as parameters, so a sort column arriving from a
 * query string is an injection vector and there is no escaping function that
 * makes it safe. The only working answer is an allow-list: every caller
 * declares its sortable and searchable columns up front, and anything else is
 * discarded rather than quoted.
 *
 * That is also why $table, $columns and the keys of $filters are interpolated
 * without ceremony — they are written in code. Do not pass anything from a
 * request into them.
 *
 * ```php
 * $page = $paginator->run(
 *     table: 'orders o',
 *     columns: 'o.*',
 *     sortable: array( 'o.created_at', 'o.email', 'o.amount' ),
 *     searchable: array( 'o.email', 'o.reference' ),
 *     filters: array( 'o.status' => $status ),
 *     query: $request->query,
 *     default_sort: 'o.created_at',
 * );
 * ```
 *
 * @since 1.0.0
 */
final readonly class Paginator {

	/**
	 * Page sizes on offer by default.
	 *
	 * @since 1.0.0
	 *
	 * @var list<int>
	 */
	public const array SIZES = array( 25, 50, 100, 200 );

	/**
	 * Parameter names the query binds itself, which a condition may not use.
	 *
	 * @since 1.3.0
	 *
	 * @var list<string>
	 */
	private const array RESERVED = array( 'limit', 'offset' );

	/**
	 * Page through a connection.
	 *
	 * @since 1.0.0
	 *
	 * @param Database        $db    Connection.
	 * @param array<int, int> $sizes Page sizes a caller may choose. Anything
	 *                               else falls back to the first.
	 *
	 * @throws \InvalidArgumentException When the sizes are not a non-empty
	 *                                   list of positive integers.
	 */
	public function __construct(
		private Database $db,
		private array $sizes = self::SIZES,
	) {
		self::check_sizes( $sizes );
	}

	/**
	 * Run a paged query.
	 *
	 * @since 1.0.0
	 *
	 * @param string                              $table        Table name or JOIN
	 *                                                          expression. Written
	 *                                                          in code, never from
	 *                                                          a request.
	 * @param string                              $columns      SELECT list. Same.
	 * @param array<int, string>                  $sortable     Columns that may be
	 *                                                          sorted on.
	 * @param array<int, string>                  $searchable   Columns the search
	 *                                                          term is matched
	 *                                                          against.
	 * @param array<string, mixed>                $filters      Literal `column =>
	 *                                                          value` predicates.
	 *                                                          Keys are written in
	 *                                                          code; values are
	 *                                                          bound. Null and ''
	 *                                                          are skipped, so an
	 *                                                          unset filter costs
	 *                                                          nothing.
	 * @param array<mixed>                        $query        The request query
	 *                                                          string. Reads `q`,
	 *                                                          `sort`, `dir`, `per`
	 *                                                          and `page`; anything
	 *                                                          there that is not a
	 *                                                          string — `?sort[]=x`
	 *                                                          — counts as absent.
	 * @param string                              $default_sort Column to sort by
	 *                                                          when none is given.
	 * @param array<string, array<string, mixed>> $conditions   Further clauses,
	 *                                                          each with the values
	 *                                                          it binds: for what
	 *                                                          `column = value`
	 *                                                          cannot say, such as
	 *                                                          one value against
	 *                                                          either of two
	 *                                                          columns. The clause
	 *                                                          is written in code;
	 *                                                          only the values may
	 *                                                          come from a request.
	 *                                                          Since 1.1.0.
	 *
	 * @return Page
	 *
	 * @throws \InvalidArgumentException When a condition's parameter name is
	 *                                   one the filters, the search or the
	 *                                   LIMIT and OFFSET use.
	 */
	public function run(
		string $table,
		string $columns,
		array $sortable,
		array $searchable,
		array $filters,
		array $query,
		string $default_sort,
		array $conditions = array(),
	): Page {
		$search    = Input::clean_text( self::text( $query['q'] ?? '' ), 120 );
		$sort      = Input::one_of( self::text( $query['sort'] ?? '' ), $sortable, $default_sort );
		$direction = 'asc' === strtolower( self::text( $query['dir'] ?? '' ) ) ? 'ASC' : 'DESC';
		$per_page  = $this->size( $query['per'] ?? 0 );
		$number    = Input::int( $query['page'] ?? 1, 1, 100_000, 1 );

		[ $where, $args ] = $this->where( $search, $searchable, $filters, $conditions );

		$total  = self::whole( $this->db->value( "SELECT COUNT(*) FROM {$table} {$where}", $args ) );
		$pages  = max( 1, (int) ceil( $total / $per_page ) );
		$number = min( $number, $pages );

		$rows = $this->db->all(
			"SELECT {$columns} FROM {$table} {$where} ORDER BY {$sort} {$direction} LIMIT :limit OFFSET :offset",
			$args + array( 'limit' => $per_page, 'offset' => ( $number - 1 ) * $per_page )
		);

		return new Page(
			rows: $rows,
			total: $total,
			number: $number,
			pages: $pages,
			per_page: $per_page,
			search: $search,
			sort: $sort,
			direction: strtolower( $direction ),
			sizes: $this->sizes,
			query: $query,
		);
	}

	/**
	 * The requested page size, or the smallest on offer.
	 *
	 * An allow-list rather than a clamp: an arbitrary size is a way to make
	 * the server build a hundred thousand rows into memory, and there is no
	 * legitimate caller who needs one.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $requested From the query string.
	 *
	 * @return int
	 */
	private function size( mixed $requested ): int {
		$size = is_numeric( $requested ) ? (int) $requested : 0;

		return in_array( $size, $this->sizes, true ) ? $size : $this->sizes[0];
	}

	/**
	 * Build the WHERE clause and its bound arguments.
	 *
	 * @since 1.0.0
	 *
	 * @param string                              $search     The search term.
	 * @param array<int, string>                  $searchable Columns to match it against.
	 * @param array<string, mixed>                $filters    Literal predicates.
	 * @param array<string, array<string, mixed>> $conditions Clauses with their own values.
	 *
	 * @return array{0: string, 1: array<string, mixed>} The clause, and the
	 *                                                  arguments to bind.
	 *
	 * @throws \InvalidArgumentException When a condition's parameter name is
	 *                                   already taken.
	 */
	private function where( string $search, array $searchable, array $filters, array $conditions = array() ): array {
		$clauses = array();
		$args    = array();

		foreach ( $filters as $column => $value ) {
			if ( null === $value || '' === $value ) {
				continue;
			}

			$name          = 'filter_' . count( $args );
			$clauses[]     = $column . ' = :' . $name;
			$args[ $name ] = $value;
		}

		if ( '' !== $search && $searchable ) {
			$ors = array();

			foreach ( $searchable as $i => $column ) {
				$ors[]                 = $column . " LIKE :search{$i} ESCAPE '\\'";
				$args[ 'search' . $i ] = '%' . self::escape_like( $search ) . '%';
			}

			$clauses[] = '(' . implode( ' OR ', $ors ) . ')';
		}

		foreach ( $conditions as $clause => $values ) {
			foreach ( $values as $name => $value ) {
				// The binder strips a leading colon, so `:filter_0` is
				// `filter_0` and must be checked as such.
				$name = ltrim( (string) $name, ':' );

				// Two values under one name would bind whichever came last,
				// and the query would quietly ask something else — or, for
				// LIMIT, return as many rows as a request liked.
				if (
					array_key_exists( $name, $args )
					|| in_array( strtolower( $name ), self::RESERVED, true )
					|| str_starts_with( $name, 'filter_' )
					|| str_starts_with( $name, 'search' )
				) {
					throw new \InvalidArgumentException( 'Condition parameter :' . $name . ' is already in use.' );
				}

				$args[ $name ] = $value;
			}

			$clauses[] = '(' . $clause . ')';
		}

		return array( $clauses ? 'WHERE ' . implode( ' AND ', $clauses ) : '', $args );
	}

	/**
	 * Neutralise the wildcards inside a LIKE term.
	 *
	 * Binding the value stops injection, but `%` and `_` are still operators
	 * inside it: a search for `_` otherwise matches every row with at least
	 * one character, which is every row.
	 *
	 * SQLite treats a backslash as ordinary unless told otherwise, so this
	 * pairs with the ESCAPE clause in the query above.
	 *
	 * @since 1.0.0
	 *
	 * @param string $term The search term.
	 *
	 * @return string
	 */
	private static function escape_like( string $term ): string {
		return str_replace( array( '\\', '%', '_' ), array( '\\\\', '\\%', '\\_' ), $term );
	}

	/**
	 * A query-string value as a string, or '' when it is not one.
	 *
	 * `?sort[]=x` arrives as an array; cast, it is "Array" and a warning.
	 *
	 * @since 1.3.0
	 *
	 * @param mixed $value From the query string.
	 *
	 * @return string
	 */
	private static function text( mixed $value ): string {
		return is_string( $value ) || is_int( $value ) ? (string) $value : '';
	}

	/**
	 * A count read back from SQLite, as an int.
	 *
	 * @since 1.3.0
	 *
	 * @param mixed $value What the query returned.
	 *
	 * @return int Zero for anything that is not a number.
	 */
	private static function whole( mixed $value ): int {
		return match ( true ) {
			is_int( $value )     => $value,
			is_numeric( $value ) => (int) $value,
			default              => 0,
		};
	}

	/**
	 * Refuse page sizes that cannot page.
	 *
	 * An empty list leaves nothing to fall back on; a zero divides by zero
	 * when the pages are counted; and a negative LIMIT is no limit at all to
	 * SQLite, so `?per=-1` would return the whole table.
	 *
	 * @since 1.3.0
	 *
	 * @param array<mixed> $sizes Page sizes on offer.
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException When one is not a positive integer,
	 *                                   or there are none.
	 */
	public static function check_sizes( array $sizes ): void {
		if ( array() === $sizes || ! array_is_list( $sizes ) ) {
			throw new \InvalidArgumentException( 'Offer at least one page size, as a list.' );
		}

		foreach ( $sizes as $size ) {
			if ( ! is_int( $size ) || $size < 1 ) {
				throw new \InvalidArgumentException( 'A page size is a positive integer.' );
			}
		}
	}
}
