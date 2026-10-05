<?php
/**
 * Page tests.
 *
 * @package SugarCommerce\Paginate\Tests
 */

declare( strict_types=1 );

namespace SugarCommerce\Paginate\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Paginate\Page;

/**
 * Class PageTest
 */
final class PageTest extends TestCase {

	/**
	 * A page with the shape a case needs.
	 *
	 * @param array $overrides Constructor arguments to replace.
	 */
	private function page( array $overrides = array() ): Page {
		return new Page( ...( $overrides + array(
			'rows'     => array(),
			'total'    => 500,
			'number'   => 10,
			'pages'    => 20,
			'per_page' => 25,
			'sizes'    => array( 25, 50 ),
			'query'    => array( 'q' => 'thing' ),
		) ) );
	}

	/* ─── slice (1.1.0) ──────────────────────────────────────────────── */

	public function test_a_slice_is_the_asked_for_page_of_the_rows(): void {
		$page = Page::slice( range( 1, 120 ), array( 'page' => '2', 'per' => '50' ), array( 25, 50 ) );

		$this->assertSame( range( 51, 100 ), $page->rows );
		$this->assertSame( 120, $page->total );
		$this->assertSame( 3, $page->pages );
		$this->assertSame( 51, $page->from() );
		$this->assertSame( '?page=3&per=50', $page->page_link( 3 ) );
	}

	/**
	 * The same allow-list the Paginator has: a size off the list is the
	 * default, not whatever was asked for.
	 */
	public function test_a_slice_keeps_to_the_sizes_on_offer(): void {
		$this->assertSame( 25, Page::slice( range( 1, 500 ), array( 'per' => '100000' ), array( 25, 50 ) )->per_page );
	}

	public function test_a_page_past_the_end_is_the_last_and_before_the_start_the_first(): void {
		$this->assertSame( 3, Page::slice( range( 1, 60 ), array( 'page' => '99' ), array( 25 ) )->number );
		$this->assertSame( array( 51, 52, 53, 54, 55, 56, 57, 58, 59, 60 ), Page::slice( range( 1, 60 ), array( 'page' => '99' ), array( 25 ) )->rows );
		$this->assertSame( 1, Page::slice( range( 1, 60 ), array( 'page' => '-4' ), array( 25 ) )->number );
		$this->assertSame( 1, Page::slice( range( 1, 60 ), array( 'page' => 'x' ), array( 25 ) )->number );
	}

	public function test_nothing_is_one_empty_page(): void {
		$page = Page::slice( array(), array(), array( 25 ) );

		$this->assertTrue( $page->is_empty() );
		$this->assertFalse( $page->is_paged() );
		$this->assertSame( 1, $page->number );
	}

	public function test_keys_do_not_survive_a_slice(): void {
		$this->assertSame( array( 'b' ), Page::slice( array( 5 => 'a', 9 => 'b' ), array( 'page' => 2 ), array( 1 ) )->rows );
	}

	public function test_a_single_page_is_not_worth_controls(): void {
		$this->assertFalse( $this->page( array( 'pages' => 1 ) )->is_paged() );
		$this->assertTrue( $this->page()->is_paged() );
	}

	public function test_emptiness_is_about_the_total_not_the_page(): void {
		$this->assertTrue( $this->page( array( 'total' => 0, 'pages' => 1 ) )->is_empty() );
		$this->assertFalse( $this->page()->is_empty() );
	}

	public function test_the_row_range_counts_from_one(): void {
		$page = $this->page( array( 'number' => 1 ) );

		$this->assertSame( 1, $page->from() );
		$this->assertSame( 25, $page->to() );
	}

	public function test_the_row_range_on_a_later_page(): void {
		$page = $this->page( array( 'number' => 3 ) );

		$this->assertSame( 51, $page->from() );
		$this->assertSame( 75, $page->to() );
	}

	public function test_the_row_range_is_zero_when_empty(): void {
		$this->assertSame( 0, $this->page( array( 'total' => 0, 'pages' => 1, 'number' => 1 ) )->from() );
	}

	public function test_previous_and_next_stop_at_the_ends(): void {
		$this->assertNull( $this->page( array( 'number' => 1 ) )->previous() );
		$this->assertSame( 2, $this->page( array( 'number' => 1 ) )->next() );
		$this->assertSame( 19, $this->page( array( 'number' => 20 ) )->previous() );
		$this->assertNull( $this->page( array( 'number' => 20 ) )->next() );
	}

	public function test_the_window_gaps_the_middle(): void {
		$this->assertSame(
			array( 1, 2, null, 8, 9, 10, 11, 12, null, 19, 20 ),
			$this->page()->window(),
		);
	}

	public function test_the_window_near_the_start_has_one_gap(): void {
		$this->assertSame(
			array( 1, 2, 3, null, 19, 20 ),
			$this->page( array( 'number' => 1 ) )->window(),
		);
	}

	public function test_a_short_window_lists_every_page(): void {
		$this->assertSame(
			array( 1, 2, 3 ),
			$this->page( array( 'pages' => 3, 'number' => 2 ) )->window(),
		);
	}

	public function test_the_window_never_starts_or_ends_with_a_gap(): void {
		$window = $this->page()->window();

		$this->assertNotNull( $window[0] );
		$this->assertNotNull( end( $window ) );
	}

	public function test_a_page_link_keeps_the_rest_of_the_query(): void {
		$link = $this->page()->page_link( 3 );

		$this->assertStringContainsString( 'q=thing', $link );
		$this->assertStringContainsString( 'page=3', $link );
	}

	public function test_changing_anything_else_returns_to_the_first_page(): void {
		// Page 7 of a new search is almost never what was meant.
		$link = $this->page( array( 'query' => array( 'q' => 'thing', 'page' => 7 ) ) )->link( array( 'q' => 'other' ) );

		$this->assertStringNotContainsString( 'page=', $link );
		$this->assertStringContainsString( 'q=other', $link );
	}

	public function test_empty_parameters_are_dropped(): void {
		$page = $this->page( array( 'query' => array( 'q' => '', 'state' => null, 'dir' => 'asc' ) ) );

		$this->assertSame( '?dir=asc', $page->link( array() ) );
	}

	public function test_a_link_with_nothing_left_is_empty(): void {
		$this->assertSame( '', $this->page( array( 'query' => array() ) )->link( array() ) );
	}

	public function test_a_sort_link_flips_direction_on_the_current_column(): void {
		$page = $this->page( array( 'sort' => 'name', 'direction' => 'desc' ) );

		$this->assertStringContainsString( 'dir=asc', $page->sort_link( 'name' ) );
		$this->assertStringContainsString( 'sort=name', $page->sort_link( 'name' ) );
	}

	public function test_a_sort_link_starts_descending_on_another_column(): void {
		$page = $this->page( array( 'sort' => 'name', 'direction' => 'asc' ) );

		$this->assertStringContainsString( 'dir=desc', $page->sort_link( 'created_at' ) );
	}

	public function test_sorted_by_reports_the_current_column(): void {
		$page = $this->page( array( 'sort' => 'name' ) );

		$this->assertTrue( $page->sorted_by( 'name' ) );
		$this->assertFalse( $page->sorted_by( 'created_at' ) );
	}

	public function test_a_size_link_returns_to_the_first_page(): void {
		$link = $this->page( array( 'query' => array( 'page' => 7 ) ) )->size_link( 50 );

		$this->assertStringContainsString( 'per=50', $link );
		$this->assertStringContainsString( 'page=1', $link );
	}

	/* ─── meta (1.2.0) ───────────────────────────────────────────────── */

	public function test_meta_is_the_four_numbers_an_api_returns(): void {
		$this->assertSame(
			array( 'total' => 500, 'page' => 10, 'pages' => 20, 'per_page' => 25 ),
			$this->page()->meta()
		);
	}
}
