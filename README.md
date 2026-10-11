# PHP RSS Parser

Dependency-free RSS 2.0 / Atom 1.0 / RDF feed parser (PHP 7.4+). Handles namespaces (`content:encoded`, `media:content`, `itunes:image`, `dc:creator`), CDATA, enclosures, and normalizes everything into one entry shape — so your code never cares which format a feed used.

## Usage

```php
require 'src/RssParser.php';

$parser = new RssParser( array(
    'cache_dir'      => '/tmp/rss-cache', // file cache
    'cache_ttl'      => 3600,             // seconds
    'timeout'        => 15,
    'excerpt_length' => 200,
) );

$feed = $parser->parseUrl( 'https://wordpress.org/news/feed/' );

echo $feed['title'], "\n";
foreach ( $feed['entries'] as $entry ) {
    // title, link, excerpt, content, published (unix ts),
    // author, image, categories[], guid
    echo $entry['published'] ? date( 'Y-m-d', $entry['published'] ) : '?',
         ' — ', $entry['title'], "\n";
}
```

CLI example:

```bash
php examples/rss-example.php https://wordpress.org/news/feed/ 5
```

## Details

- cURL with redirect-following when available, `file_get_contents` fallback
- File-based JSON cache with TTL — repeated parses of the same URL are free
- `guid` gives you a stable id for deduping across runs
- Excerpts are plain text, entity-decoded, truncated with `…`

## License

MIT
