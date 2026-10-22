<?php
/**
 * One page of results, and the state needed to render its controls.
 *
 * @package SugarCommerce\Paginate
 */

declare( strict_types=1 );

namespace SugarCommerce\Paginate;

/**
 * Class Page
 *
 * The result of a paged query. A view should be able to render a complete set
 * of controls from this object alone — the numbers to show, which link goes
 * where, whether to render anything at all — without recomputing any of it.
 *
 * That is the point of it being an object rather than the array it started as.
 * The sliding window and the list of page sizes were living in a template,
 * which meant the template was the only thing that knew them, and the second
 * list screen copied both.
 *
 * @since 1.0.0
 */
final readonly class Page {

	/**
	 * Hold one page and the state its controls are drawn from.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, mixed> $rows      The rows for this page.
	 * @param int               $total     Matching rows across every page.
	 * @param int               $number    Current page, from 1.
	 * @param int               $pages     Total pages, at least 1.
	 * @param int               $per_page  Rows per page.
	 * @param string            $search    The search term in effect.
	 * @param string            $sort      The sort column in effect.
	 * @param string            $direction 'asc' or 'desc'.
	 * @param array<int, int>   $sizes     Page sizes on offer.
	 * @param array<mixed>      $query     The request query string, for links.
	 * @param string            $param     The query parameter holding the page
	 *                                     number. Since 1.4.0.
	 */
	public function __construct(
		public array $rows,
		public int $total,
		public int $number,
		public int $pages,
		public int $per_page,
		public string $search = '',
		public string $sort = '',
		public string $direction = 'desc',
		public array $sizes = array(),
		public array $query = array(),
		public string $param = 'page',
	) {}

	/**
	 * The numbers and links for a list that runs its own query.
	 *
	 * For a page that already counts its rows and applies its own LIMIT and
	 * OFFSET — offset() is the OFFSET — and wants nothing from here but the
	 * arithmetic and the links. There are no rows on the page it returns.
	 *
	 * The page number is read as `(int)` reads it, so `?page=3x` is page 3
	 * and anything that is not a number is page 1, then clamped to the pages
	 * there are: past the end is the last page rather than an empty one.
	 * The page size is used as given — choosing it is the caller's job, and
	 * nothing from the request reaches it.
	 *
	 * ```php
	 * $page = Page::of( $total, $request->query, 50 );
	 * $rows = $db->all( 'SELECT … LIMIT 50 OFFSET :offset', array( 'offset' => $page->offset() ) );
	 * ```
	 *
	 * @since 1.4.0
	 *
	 * @param int          $total    Matching rows across every page.
	 * @param array<mixed> $query    The request query string.
	 * @param int          $per_page Rows per page.
	 * @param string       $param    The parameter holding the page number.
	 *
	 * @return self
	 *
	 * @throws \InvalidArgumentException When the page size is less than 1, or
	 *                                   the parameter name is empty.
	 */
	public static function of( int $total, array $query, int $per_page, string $param = 'page' ): self {
		if ( $per_page < 1 ) {
			throw new \InvalidArgumentException( 'A page size is a positive integer.' );
		}

		if ( '' === $param ) {
			throw new \InvalidArgumentException( 'Name the parameter that holds the page number.' );
		}

		$requested = $query[ $param ] ?? 1;
		$pages     = max( 1, (int) ceil( $total / $per_page ) );
		$number    = min( max( 1, is_int( $requested ) || is_string( $requested ) ? (int) $requested : 1 ), $pages );

		return new self(
			rows: array(),
			total: $total,
			number: $number,
			pages: $pages,
			per_page: $per_page,
			query: $query,
			param: $param,
		);
	}

	/**
	 * One page of rows that are already in memory.
	 *
	 * For lists filtered or ordered by something SQL cannot express — a
	 * forgiving search, say — so the caller loads the rows, narrows them
	 * itself, and still gets the same controls the Paginator gives. Reads
	 * `page` and `per` from the query as the Paginator does, with the same
	 * allow-list for sizes and the same clamping of the page number.
	 *
	 * @since 1.1.0
	 *
	 * @param array<int, mixed> $rows   Every matching row, in order.
	 * @param array<mixed>      $query  The request query string.
	 * @param array<int, int>   $sizes  Page sizes on offer; the first is the default.
	 * @param string            $search The search term in effect, for display.
	 *
	 * @return self
	 *
	 * @throws \InvalidArgumentException When the sizes are not a non-empty
	 *                                   list of positive integers.
	 */
	public static function slice( array $rows, array $query, array $sizes = Paginator::SIZES, string $search = '' ): self {
		Paginator::check_sizes( $sizes );

		$requested = is_numeric( $query['per'] ?? null ) ? (int) $query['per'] : 0;
		$per_page  = in_array( $requested, $sizes, true ) ? $requested : $sizes[0];
		$total     = count( $rows );
		$pages     = max( 1, (int) ceil( $total / $per_page ) );
		$number    = min( max( 1, is_numeric( $query['page'] ?? null ) ? (int) $query['page'] : 1 ), $pages );

		return new self(
			rows: array_slice( array_values( $rows ), ( $number - 1 ) * $per_page, $per_page ),
			total: $total,
			number: $number,
			pages: $pages,
			per_page: $per_page,
			search: $search,
			sizes: $sizes,
			query: $query,
		);
	}

	/**
	 * Is there anything to show?
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		return 0 === $this->total;
	}

	/**
	 * Are there enough results to be worth rendering controls for?
	 *
	 * A lone "1" button is noise, not navigation.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_paged(): bool {
		return $this->pages > 1;
	}

	/**
	 * The first row number on this page, counting from 1. 0 when empty.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function from(): int {
		return $this->total ? ( ( $this->number - 1 ) * $this->per_page ) + 1 : 0;
	}

	/**
	 * The last row number on this page.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function to(): int {
		return min( $this->number * $this->per_page, $this->total );
	}

	/**
	 * The previous page number, or null on the first page.
	 *
	 * @since 1.0.0
	 *
	 * @return int|null
	 */
	public function previous(): ?int {
		return $this->number > 1 ? $this->number - 1 : null;
	}

	/**
	 * The next page number, or null on the last page.
	 *
	 * @since 1.0.0
	 *
	 * @return int|null
	 */
	public function next(): ?int {
		return $this->number < $this->pages ? $this->number + 1 : null;
	}

	/**
	 * How many rows come before this page: the query's OFFSET.
	 *
	 * @since 1.4.0
	 *
	 * @return int
	 */
	public function offset(): int {
		return ( $this->number - 1 ) * $this->per_page;
	}

	/**
	 * A link to the first page, or null when this is it.
	 *
	 * Like every *_link() of 1.4.0, a query string alone — `?page=1&q=x` —
	 * that a browser resolves against the page it is on. The page number
	 * comes first and every other parameter follows exactly as it arrived,
	 * empty ones included; only the page changes.
	 *
	 * @since 1.4.0
	 *
	 * @return string|null
	 */
	public function first_link(): ?string {
		return $this->number > 1 ? $this->swap( 1 ) : null;
	}

	/**
	 * A link to the previous page, or null on the first.
	 *
	 * @since 1.4.0
	 *
	 * @return string|null
	 */
	public function previous_link(): ?string {
		return $this->number > 1 ? $this->swap( $this->number - 1 ) : null;
	}

	/**
	 * A link to the next page, or null on the last.
	 *
	 * @since 1.4.0
	 *
	 * @return string|null
	 */
	public function next_link(): ?string {
		return $this->number < $this->pages ? $this->swap( $this->number + 1 ) : null;
	}

	/**
	 * A link to the last page, or null when this is it.
	 *
	 * @since 1.4.0
	 *
	 * @return string|null
	 */
	public function last_link(): ?string {
		return $this->number < $this->pages ? $this->swap( $this->pages ) : null;
	}

	/**
	 * Where this page sits, for a JSON client that wants the next one.
	 *
	 * The four numbers an API puts beside its rows. Built here so every
	 * endpoint answers with the same keys — the alternative is each one
	 * writing the array out again, and the third spelling `perPage`.
	 *
	 * @since 1.2.0
	 *
	 * @return array{total: int, page: int, pages: int, per_page: int}
	 */
	public function meta(): array {
		return array(
			'total'    => $this->total,
			'page'     => $this->number,
			'pages'    => $this->pages,
			'per_page' => $this->per_page,
		);
	}

	/**
	 * Page numbers to render, with null standing in for a gap.
	 *
	 * A sliding window around the current page, always including the first
	 * and last few, so the extremes are one click away from anywhere. Twenty
	 * pages render as 1 2 … 9 10 11 … 19 20 rather than twenty buttons.
	 *
	 * @since 1.0.0
	 *
	 * @param int $window How many pages either side of the current one.
	 * @param int $ends   How many to always show at each end.
	 *
	 * @return array<int, int|null>
	 */
	public function window( int $window = 2, int $ends = 2 ): array {
		$numbers = array();

		for ( $i = 1; $i <= $this->pages; $i++ ) {
			if ( $i <= $ends || $i > $this->pages - $ends || abs( $i - $this->number ) <= $window ) {
				$numbers[] = $i;
			} elseif ( null !== end( $numbers ) ) {
				$numbers[] = null;
			}
		}

		return $numbers;
	}

	/**
	 * Is this column the one being sorted on?
	 *
	 * @since 1.0.0
	 *
	 * @param string $column Column name, as declared sortable.
	 *
	 * @return bool
	 */
	public function sorted_by( string $column ): bool {
		return $this->sort === $column;
	}

	/**
	 * A link that sorts by a column, flipping direction if already on it.
	 *
	 * @since 1.0.0
	 *
	 * @param string $column Column name, as declared sortable.
	 *
	 * @return string
	 */
	public function sort_link( string $column ): string {
		$flip = $this->sorted_by( $column ) && 'desc' === $this->direction ? 'asc' : 'desc';

		return $this->link( array( 'sort' => $column, 'dir' => $this->sorted_by( $column ) ? $flip : 'desc' ) );
	}

	/**
	 * A link to another page.
	 *
	 * @since 1.0.0
	 *
	 * @param int $number Page number.
	 *
	 * @return string
	 */
	public function page_link( int $number ): string {
		return $this->link( array( $this->param => $number ) );
	}

	/**
	 * A link that changes the page size.
	 *
	 * @since 1.0.0
	 *
	 * @param int $size Rows per page.
	 *
	 * @return string
	 */
	public function size_link( int $size ): string {
		return $this->link( array( 'per' => $size, $this->param => 1 ) );
	}

	/**
	 * A query string for a link that changes some parameters.
	 *
	 * Empty values are dropped, so the URL of an unfiltered list is clean
	 * rather than a row of `&q=&state=&dir=`.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $changes Parameters to set or replace.
	 *
	 * @return string The query string, including '?', or '' when empty.
	 */
	public function link( array $changes ): string {
		$params = array_filter(
			array_merge( $this->query, $changes ),
			static fn( $value ) => null !== $value && '' !== $value
		);

		// Changing anything other than the page returns to the first one —
		// page 7 of a new search is almost never what was meant.
		if ( ! isset( $changes[ $this->param ] ) ) {
			unset( $params[ $this->param ] );
		}

		return $params ? '?' . http_build_query( $params ) : '';
	}

	/**
	 * The query string with another page number, and nothing else changed.
	 *
	 * @since 1.4.0
	 *
	 * @param int $number Page number.
	 *
	 * @return string Including '?'.
	 */
	private function swap( int $number ): string {
		$rest = $this->query;

		unset( $rest[ $this->param ] );

		return '?' . http_build_query( array( $this->param => $number ) + $rest, '', '&' );
	}
}
