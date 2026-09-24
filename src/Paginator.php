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
	 */
	public const array SIZES = array( 25, 50, 100, 200 );

	/**
	 * @param Database        $db    Connection.
	 * @param array<int, int> $sizes Page sizes a caller may choose. Anything
	 *                               else falls back to the first.
	 */
	public function __construct(
		private Database $db,
		private array $sizes = self::SIZES,
	) {}

	/**
	 * Run a paged query.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $table        Table name or JOIN expression.
	 *                                           Written in code, never from a
	 *                                           request.
	 * @param string               $columns      SELECT list. Same.
	 * @param array<int, string>   $sortable     Columns that may be sorted on.
	 * @param array<int, string>   $searchable   Columns the search term is
	 *                                           matched against.
	 * @param array<string, mixed> $filters      Literal `column => value`
	 *                                           predicates. Keys are written
	 *                                           in code; values are bound.
	 *                                           Null and '' are skipped, so an
	 *                                           unset filter costs nothing.
	 * @param array                $query        The request query string.
	 *                                           Reads `q`, `sort`, `dir`,
	 *                                           `per` and `page`.
	 * @param string               $default_sort Column to sort by when none
	 *                                           is given.
	 *
	 * @return Page
	 */
	public function run(
		string $table,
		string $columns,
		array $sortable,
		array $searchable,
		array $filters,
		array $query,
		string $default_sort,
	): Page {
		$search    = Input::clean_text( (string) ( $query['q'] ?? '' ), 120 );
		$sort      = Input::one_of( (string) ( $query['sort'] ?? '' ), $sortable, $default_sort );
		$direction = 'asc' === strtolower( (string) ( $query['dir'] ?? '' ) ) ? 'ASC' : 'DESC';
		$per_page  = $this->size( $query['per'] ?? 0 );
		$number    = Input::int( $query['page'] ?? 1, 1, 100_000, 1 );

		[ $where, $args ] = $this->where( $search, $searchable, $filters );

		$total  = (int) $this->db->value( "SELECT COUNT(*) FROM {$table} {$where}", $args );
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
	 * @param string               $search     The search term.
	 * @param array<int, string>   $searchable Columns to match it against.
	 * @param array<string, mixed> $filters    Literal predicates.
	 *
	 * @return array{0: string, 1: array} The clause, and the arguments to bind.
	 */
	private function where( string $search, array $searchable, array $filters ): array {
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
}
