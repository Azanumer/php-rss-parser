<?php
/**
 * CLI example: fetch a feed and print the latest entries.
 *
 * Usage: php rss-example.php <feed-url> [max-items]
 * Example: php rss-example.php https://wordpress.org/news/feed/ 5
 */

require __DIR__ . '/../src/RssParser.php';

$url = $argv[1] ?? '';
$max = isset( $argv[2] ) ? max( 1, (int) $argv[2] ) : 5;

if ( $url === '' || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
    fwrite( STDERR, "Usage: php rss-example.php <feed-url> [max-items]\n" );
    exit( 1 );
}

$parser = new RssParser( array( 'cache_ttl' => 900 ) );
$feed   = $parser->parseUrl( $url );

if ( $feed === false ) {
    fwrite( STDERR, "Failed to fetch or parse: $url\n" );
    exit( 1 );
}

echo 'Feed: ', $feed['title'], ' (', count( $feed['entries'] ), " entries)\n";
echo str_repeat( '-', 60 ), "\n";

foreach ( array_slice( $feed['entries'], 0, $max ) as $i => $e ) {
    printf(
        "%d. %s\n   %s | %s\n   %s\n\n",
        $i + 1,
        $e['title'],
        $e['published'] ? date( 'Y-m-d H:i', $e['published'] ) : 'no date',
        $e['link'],
        $e['excerpt']
    );
}
