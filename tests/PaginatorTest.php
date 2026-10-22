<?php
/**
 * Paginator tests.
 *
 * @package SugarCommerce\Paginate\Tests
 */

declare( strict_types=1 );

namespace SugarCommerce\Paginate\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Paginate\{Page, Paginator};
use SugarCommerce\Sqlite\Database;

/**
 * Class PaginatorTest
 */
final class PaginatorTest extends TestCase {

	private Database $db;
	private Paginator $paginator;

	protected function setUp(): void {
		$this->db = Database::open( ':memory:' );

		$this->db->pdo()->exec(
			'CREATE TABLE things (
				id INTEGER PRIMARY KEY,
				name TEXT NOT NULL,
				status TEXT NOT NULL DEFAULT "active",
				created_at INTEGER NOT NULL
			)'
		);

		for ( $i = 1; $i <= 120; $i++ ) {
			$this->db->insert( 'things', array(
				'name'       => 'Thing ' . str_pad( (string) $i, 3, '0', STR_PAD_LEFT ),
				'status'     => 0 === $i % 10 ? 'archived' : 'active',
				'created_at' => 1_000_000 + $i,
			) );
		}

		$this->paginator = new Paginator( $this->db );
	}

	/**
	 * Run a query over the fixture table.
	 *
	 * @param array $query The request query string.
	 */
	private function paged( array $query = array(), array $filters = array() ): Page {
		return $this->paginator->run(
			table: 'things',
			columns: '*',
			sortable: array( 'created_at', 'name' ),
			searchable: array( 'name' ),
			filters: $filters,
			query: $query,
			default_sort: 'created_at',
		);
	}

	/* ─── conditions (1.1.0) ─────────────────────────────────────────── */

	/**
	 * One value against either of two columns — what a filter, which is
	 * always `column = value`, cannot say.
	 */
	public function test_a_condition_narrows_with_its_own_bound_values(): void {
		$page = $this->paginator->run(
			table: 'things',
			columns: '*',
			sortable: array( 'created_at' ),
			searchable: array(),
			filters: array( 'status' => 'active' ),
			query: array(),
			default_sort: 'created_at',
			conditions: array( '( name = :wanted OR created_at = :at )' => array( 'wanted' => 'Thing 005', 'at' => 1_000_007 ) ),
		);

		$this->assertSame( array( 'Thing 007', 'Thing 005' ), array_column( $page->rows, 'name' ) );
		$this->assertSame( 2, $page->total, 'Counted with the same conditions.' );
	}

	public function test_a_value_is_bound_not_written_into_the_query(): void {
		$page = $this->paginator->run(
			table: 'things', columns: '*', sortable: array( 'created_at' ), searchable: array(), filters: array(),
			query: array(), default_sort: 'created_at',
			conditions: array( 'name = :name' => array( 'name' => "x' OR '1'='1" ) ),
		);

		$this->assertTrue( $page->is_empty() );
	}

	public function test_a_condition_cannot_take_a_name_the_filters_use(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->paginator->run(
			table: 'things', columns: '*', sortable: array( 'created_at' ), searchable: array(), filters: array( 'status' => 'active' ),
			query: array(), default_sort: 'created_at',
			conditions: array( 'name = :filter_0' => array( 'filter_0' => 'Thing 001' ) ),
		);
	}

	/**
	 * The binder strips a leading colon, so `:filter_0` is `filter_0` — and
	 * would quietly replace the filter's value.
	 */
	public function test_a_colon_does_not_smuggle_a_name_the_filters_use(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->paginator->run(
			table: 'things', columns: '*', sortable: array( 'created_at' ), searchable: array(), filters: array( 'status' => 'archived' ),
			query: array(), default_sort: 'created_at',
			conditions: array( 'id > 0' => array( ':filter_0' => 'active' ) ),
		);
	}

	/**
	 * A condition value called `limit` would become the LIMIT: as many rows
	 * as the request liked, past the allow-list of sizes.
	 */
	public function test_a_condition_cannot_take_the_limit_or_offset(): void {
		foreach ( array( 'limit', 'offset', ':LIMIT' ) as $name ) {
			try {
				$this->paginator->run(
					table: 'things', columns: '*', sortable: array( 'created_at' ), searchable: array(), filters: array(),
					query: array(), default_sort: 'created_at',
					conditions: array( 'id > :' . ltrim( strtolower( $name ), ':' ) => array( $name => 0 ) ),
				);
				$this->fail( 'A condition took the name ' . $name . '.' );
			} catch ( \InvalidArgumentException ) {
				$this->addToAssertionCount( 1 );
			}
		}
	}

	/**
	 * `?sort[]=x` is an array; cast to a string it is "Array" and a warning.
	 */
	public function test_array_query_values_count_as_absent(): void {
		$page = $this->paged( array( 'q' => array( 'x' ), 'sort' => array( 'name' ), 'dir' => array( 'asc' ) ) );

		$this->assertSame( 120, $page->total );
		$this->assertSame( 'created_at', $page->sort );
		$this->assertSame( 'desc', $page->direction );
	}

	/**
	 * A zero divides by zero; a negative LIMIT is no limit to SQLite.
	 *
	 * @return array<string, array{0: array<mixed>}>
	 */
	public static function unusable_sizes(): array {
		return array(
			'none'     => array( array() ),
			'zero'     => array( array( 0, 25 ) ),
			'negative' => array( array( -1 ) ),
			'string'   => array( array( '25' ) ),
		);
	}

	/**
	 * @param array<mixed> $sizes Page sizes.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'unusable_sizes' )]
	public function test_unusable_page_sizes_are_refused( array $sizes ): void {
		$this->expectException( \InvalidArgumentException::class );

		new Paginator( $this->db, $sizes );
	}

	public function test_a_slice_refuses_a_negative_size(): void {
		$this->expectException( \InvalidArgumentException::class );

		Page::slice( array( 1, 2, 3 ), array( 'per' => '-1' ), array( -1, 25 ) );
	}

	public function test_the_first_page_holds_the_default_size(): void {
		$page = $this->paged();

		$this->assertCount( 25, $page->rows );
		$this->assertSame( 120, $page->total );
		$this->assertSame( 1, $page->number );
		$this->assertSame( 5, $page->pages );
	}

	public function test_a_later_page_offsets(): void {
		$page = $this->paged( array( 'page' => 2 ) );

		$this->assertSame( 2, $page->number );
		$this->assertSame( 26, $page->from() );
		$this->assertSame( 50, $page->to() );
	}

	public function test_the_last_page_is_short(): void {
		$page = $this->paged( array( 'page' => 5, 'per' => 100 ) );

		$this->assertSame( 20, count( $page->rows ) );
		$this->assertSame( 101, $page->from() );
		$this->assertSame( 120, $page->to() );
	}

	public function test_a_page_beyond_the_end_clamps_to_the_last(): void {
		$page = $this->paged( array( 'page' => 9999 ) );

		$this->assertSame( 5, $page->number );
		$this->assertNotEmpty( $page->rows );
	}

	public function test_a_nonsense_page_falls_back_to_the_first(): void {
		$this->assertSame( 1, $this->paged( array( 'page' => 'abc' ) )->number );
		$this->assertSame( 1, $this->paged( array( 'page' => -3 ) )->number );
	}

	public function test_only_offered_page_sizes_are_honoured(): void {
		// An arbitrary size is a way to make the server build a hundred
		// thousand rows into memory.
		$this->assertSame( 25, $this->paged( array( 'per' => 99999 ) )->per_page );
		$this->assertSame( 25, $this->paged( array( 'per' => 'lots' ) )->per_page );
		$this->assertSame( 50, $this->paged( array( 'per' => 50 ) )->per_page );
	}

	public function test_sorting_is_limited_to_declared_columns(): void {
		$page = $this->paged( array( 'sort' => 'status' ) );

		// 'status' is not declared sortable, so it must not reach the SQL.
		$this->assertSame( 'created_at', $page->sort );
	}

	public function test_an_injected_sort_column_is_discarded(): void {
		$page = $this->paged( array( 'sort' => 'created_at; DROP TABLE things' ) );

		$this->assertSame( 'created_at', $page->sort );
		$this->assertSame( 120, (int) $this->db->value( 'SELECT COUNT(*) FROM things' ) );
	}

	public function test_direction_defaults_to_descending(): void {
		$page = $this->paged();

		$this->assertSame( 'desc', $page->direction );
		$this->assertSame( 'Thing 120', $page->rows[0]['name'] );
	}

	public function test_ascending_is_honoured(): void {
		$page = $this->paged( array( 'dir' => 'asc' ) );

		$this->assertSame( 'asc', $page->direction );
		$this->assertSame( 'Thing 001', $page->rows[0]['name'] );
	}

	public function test_search_narrows_the_total(): void {
		$page = $this->paged( array( 'q' => 'Thing 01' ) );

		// 'Thing 010' through 'Thing 019'.
		$this->assertSame( 10, $page->total );
	}

	public function test_search_wildcards_are_neutralised(): void {
		// Without escaping, '_' is a LIKE operator matching any character,
		// so this would return every row rather than none.
		$this->assertSame( 0, $this->paged( array( 'q' => '_' ) )->total );
		$this->assertSame( 0, $this->paged( array( 'q' => '%' ) )->total );
	}

	public function test_filters_are_bound(): void {
		$page = $this->paged( filters: array( 'status' => 'archived' ) );

		$this->assertSame( 12, $page->total );
	}

	public function test_an_empty_filter_is_skipped(): void {
		$this->assertSame( 120, $this->paged( filters: array( 'status' => '' ) )->total );
		$this->assertSame( 120, $this->paged( filters: array( 'status' => null ) )->total );
	}

	public function test_a_filter_and_a_search_combine(): void {
		$page = $this->paged( array( 'q' => 'Thing 01' ), array( 'status' => 'active' ) );

		// 010 is archived; 011 through 019 are not.
		$this->assertSame( 9, $page->total );
	}

	public function test_an_empty_result_reports_no_rows(): void {
		$page = $this->paged( array( 'q' => 'nothing matches this' ) );

		$this->assertTrue( $page->is_empty() );
		$this->assertSame( 0, $page->total );
		$this->assertSame( 1, $page->pages );
		$this->assertSame( 0, $page->from() );
	}
}
