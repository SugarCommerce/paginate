# paginate

Paging, sorting and search for list screens, over
[sqlite](https://github.com/sugarcommerce/sqlite).

```bash
composer require sugarcommerce/paginate
```

## Use

```php
use SugarCommerce\Paginate\Paginator;

$page = ( new Paginator( $db ) )->run(
    table: 'orders o',
    columns: 'o.*, ( SELECT COUNT(*) FROM items i WHERE i.order_id = o.id ) AS items',
    sortable: array( 'o.created_at', 'o.email', 'o.amount' ),
    searchable: array( 'o.email', 'o.reference' ),
    filters: array( 'o.status' => $status ),
    query: $request->query,
    default_sort: 'o.created_at',
);
```

It reads `q`, `sort`, `dir`, `per` and `page` from the query string, and returns
a `Page`.

## Why the allow-lists

Column names cannot be bound as parameters. A sort column arriving from a query
string is therefore an injection vector, and there is no escaping function that
makes it safe — `ORDER BY ?` is not valid SQL.

The only answer that works is an allow-list. Every caller declares its sortable
and searchable columns up front, and anything else is **discarded** rather than
quoted:

```php
$page = $paginator->run( ..., sortable: array( 'created_at', 'name' ), ... );

// ?sort=created_at;DROP TABLE orders  →  sorts by created_at
```

Page sizes are allow-listed too, for a different reason: an arbitrary `?per=`
is a way to make the server build a hundred thousand rows into memory, and no
legitimate caller needs one.

`$table`, `$columns` and the **keys** of `$filters` are interpolated without
ceremony, because they are written in code. Filter *values* are bound. Do not
pass anything from a request into the first three.

Search terms have their `%` and `_` escaped. Binding the value stops injection,
but those two are still operators inside a `LIKE` — a search for `_` otherwise
matches every row with at least one character in it, which is every row.

## Page

The result knows enough to render its own controls, so a template does not have
to recompute any of it:

```php
<?php if ( $page->is_paged() ) : ?>
  <?php foreach ( $page->window() as $n ) : ?>
    <?php if ( null === $n ) : ?>
      <span>…</span>
    <?php elseif ( $n === $page->number ) : ?>
      <span class="current"><?= $n ?></span>
    <?php else : ?>
      <a href="<?= $page->page_link( $n ) ?>"><?= $n ?></a>
    <?php endif; ?>
  <?php endforeach; ?>
<?php endif; ?>
```

| | |
|---|---|
| `$page->rows` | the rows |
| `$page->total` / `pages` / `number` / `per_page` | the counts |
| `$page->search` / `sort` / `direction` | the state in effect |
| `$page->is_empty()` / `is_paged()` | whether to render a list, and controls |
| `$page->from()` / `to()` | "showing 51–75 of 500" |
| `$page->previous()` / `next()` | or null at the ends |
| `$page->window()` | `[1, 2, null, 8, 9, 10, 11, 12, null, 19, 20]` |
| `$page->page_link()` / `sort_link()` / `size_link()` | query strings |
| `$page->sorted_by( $column )` | for the arrow on a header |

`window()` returns `null` for a gap rather than `'…'`, so the template picks its
own ellipsis and there is nothing to compare strings against. It never starts or
ends with one.

`sort_link()` flips direction when the column is already the one being sorted
on, and starts descending when it is not — which is what a list of recent things
should do on first click.

Every link keeps the rest of the query string, and **drops the page** unless the
page is what changed. Page 7 of a new search is almost never what was meant.
Empty values are dropped too, so an unfiltered list has a clean URL rather than
a row of `&q=&state=&dir=`.

## Testing

```bash
composer test
```

## License

GPL-2.0-or-later
