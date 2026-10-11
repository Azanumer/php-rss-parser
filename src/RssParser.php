<?php
/**
 * RssParser — dependency-free RSS 2.0 / Atom 1.0 / RDF feed parser with file caching.
 *
 * Returns normalized entries so your app never cares which format a feed used:
 *
 *   [
 *     'title'       => string,
 *     'link'        => string,
 *     'excerpt'     => string,   // plain-text, truncated
 *     'content'     => string,   // raw HTML of the entry (content:encoded / atom:content)
 *     'published'   => int,      // unix timestamp (0 when unknown)
 *     'author'      => string,
 *     'image'       => string,   // enclosure / media:content / itunes:image URL
 *     'categories'  => array,
 *     'guid'        => string,   // stable id for deduping
 *   ]
 *
 * Usage:
 *   $p = new RssParser( array( 'cache_dir' => '/tmp/rss-cache', 'cache_ttl' => 3600 ) );
 *   $feed = $p->parseUrl( 'https://example.com/feed.xml' );
 *   foreach ( $feed['entries'] as $entry ) { echo $entry['title'], "\n"; }
 */
class RssParser {

    protected $cacheDir;
    protected $cacheTtl;
    protected $timeout;
    protected $userAgent;
    protected $excerptLength;

    public function __construct( array $options = array() ) {
        $this->cacheDir      = isset( $options['cache_dir'] ) ? $options['cache_dir'] : sys_get_temp_dir() . '/rss-parser-cache';
        $this->cacheTtl      = isset( $options['cache_ttl'] ) ? (int) $options['cache_ttl'] : 3600;
        $this->timeout       = isset( $options['timeout'] ) ? (int) $options['timeout'] : 15;
        $this->userAgent     = isset( $options['user_agent'] ) ? $options['user_agent'] : 'RssParser/1.0 (+https://github.com/Azanumer/php-rss-parser)';
        $this->excerptLength = isset( $options['excerpt_length'] ) ? (int) $options['excerpt_length'] : 200;
    }

    /**
     * Parse a feed URL. Returns array('title','link','description','entries')
     * or false on failure. Results are cached on disk for cache_ttl seconds.
     */
    public function parseUrl( $url ) {
        $cached = $this->cacheGet( $url );
        if ( $cached !== false ) {
            return $cached;
        }
        $xml = $this->fetch( $url );
        if ( $xml === false ) {
            return false;
        }
        $feed = $this->parseString( $xml );
        if ( $feed !== false ) {
            $this->cacheSet( $url, $feed );
        }
        return $feed;
    }

    /** Parse raw XML string (handy for tests). */
    public function parseString( $xml ) {
        libxml_use_internal_errors( true );
        $doc = simplexml_load_string( $xml, 'SimpleXMLElement', LIBXML_NOCDATA );
        if ( $doc === false ) {
            return false;
        }
        $namespaces = $doc->getDocNamespaces( true );

        if ( isset( $doc->channel ) ) {
            return $this->parseRss2( $doc, $namespaces );       // RSS 2.0
        }
        if ( $doc->getName() === 'feed' ) {
            return $this->parseAtom( $doc, $namespaces );      // Atom 1.0
        }
        if ( $doc->getName() === 'RDF' ) {
            return $this->parseRdf( $doc, $namespaces );       // RSS 1.0 / RDF
        }
        return false;
    }

    /* ---------- format parsers ---------- */

    protected function parseRss2( $doc, $ns ) {
        $feed = array(
            'title'       => (string) $doc->channel->title,
            'link'        => (string) $doc->channel->link,
            'description' => (string) $doc->channel->description,
            'entries'     => array(),
        );
        foreach ( $doc->channel->item as $item ) {
            $content = $this->nsText( $item, $ns, 'content', 'encoded' );
            $media   = isset( $ns['media'] ) ? $item->children( $ns['media'] ) : null;
            $itunes  = isset( $ns['itunes'] ) ? $item->children( $ns['itunes'] ) : null;
            $dc      = isset( $ns['dc'] ) ? $item->children( $ns['dc'] ) : null;

            $image = '';
            if ( isset( $item->enclosure['url'] ) ) {
                $type = (string) $item->enclosure['type'];
                if ( $type === '' || strpos( $type, 'image/' ) === 0 ) {
                    $image = (string) $item->enclosure['url'];
                }
            }
            if ( $image === '' && $media && isset( $media->content['url'] ) ) {
                $image = (string) $media->content['url'];
            }
            if ( $image === '' && $itunes && isset( $itunes->image['href'] ) ) {
                $image = (string) $itunes->image['href'];
            }

            $cats = array();
            foreach ( $item->category as $c ) {
                $cats[] = (string) $c;
            }

            $feed['entries'][] = array(
                'title'      => (string) $item->title,
                'link'       => (string) $item->link,
                'excerpt'    => $this->excerpt( (string) $item->description ?: $content ),
                'content'    => $content !== '' ? $content : (string) $item->description,
                'published'  => $this->toTimestamp( (string) $item->pubDate ),
                'author'     => (string) ( $item->author ?: ( $dc ? $dc->creator : '' ) ),
                'image'      => $image,
                'categories' => $cats,
                'guid'       => (string) ( $item->guid ?: $item->link ),
            );
        }
        return $feed;
    }

    protected function parseAtom( $doc, $ns ) {
        $feed = array(
            'title'       => (string) $doc->title,
            'link'        => $this->atomLink( $doc ),
            'description' => (string) $doc->subtitle,
            'entries'     => array(),
        );
        foreach ( $doc->entry as $entry ) {
            $content = (string) $entry->content;
            $cats    = array();
            foreach ( $entry->category as $c ) {
                $cats[] = (string) ( $c['term'] ?: $c['label'] );
            }
            $feed['entries'][] = array(
                'title'      => (string) $entry->title,
                'link'       => $this->atomLink( $entry ),
                'excerpt'    => $this->excerpt( (string) $entry->summary ?: $content ),
                'content'    => $content,
                'published'  => $this->toTimestamp( (string) ( $entry->published ?: $entry->updated ) ),
                'author'     => (string) $entry->author->name,
                'image'      => '',
                'categories' => $cats,
                'guid'       => (string) ( $entry->id ?: $this->atomLink( $entry ) ),
            );
        }
        return $feed;
    }

    protected function parseRdf( $doc, $ns ) {
        $feed = array(
            'title'       => (string) $doc->channel->title,
            'link'        => (string) $doc->channel->link,
            'description' => (string) $doc->channel->description,
            'entries'     => array(),
        );
        foreach ( $doc->item as $item ) {
            $dc = isset( $ns['dc'] ) ? $item->children( $ns['dc'] ) : null;
            $feed['entries'][] = array(
                'title'      => (string) $item->title,
                'link'       => (string) $item->link,
                'excerpt'    => $this->excerpt( (string) $item->description ),
                'content'    => (string) $item->description,
                'published'  => $this->toTimestamp( $dc ? (string) $dc->date : '' ),
                'author'     => $dc ? (string) $dc->creator : '',
                'image'      => '',
                'categories' => array(),
                'guid'       => (string) ( $item->attributes( 'http://www.w3.org/1999/02/22-rdf-syntax-ns#' )->about ?: $item->link ),
            );
        }
        return $feed;
    }

    /* ---------- helpers ---------- */

    protected function atomLink( $node ) {
        foreach ( $node->link as $link ) {
            $rel = (string) $link['rel'];
            if ( $rel === '' || $rel === 'alternate' ) {
                return (string) $link['href'];
            }
        }
        return '';
    }

    protected function nsText( $node, $ns, $prefix, $tag ) {
        if ( ! isset( $ns[ $prefix ] ) ) {
            return '';
        }
        $child = $node->children( $ns[ $prefix ] );
        return isset( $child->$tag ) ? (string) $child->$tag : '';
    }

    protected function toTimestamp( $date ) {
        if ( $date === '' ) {
            return 0;
        }
        $ts = strtotime( $date );
        return $ts === false ? 0 : $ts;
    }

    protected function excerpt( $html ) {
        $text = trim( preg_replace( '/\s+/', ' ', strip_tags( $html ) ) );
        $text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
        if ( mb_strlen( $text ) > $this->excerptLength ) {
            return mb_substr( $text, 0, $this->excerptLength ) . '…';
        }
        return $text;
    }

    protected function fetch( $url ) {
        if ( function_exists( 'curl_init' ) ) {
            $ch = curl_init( $url );
            curl_setopt_array( $ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_USERAGENT      => $this->userAgent,
                CURLOPT_ENCODING       => '',
            ) );
            $body = curl_exec( $ch );
            $code = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
            curl_close( $ch );
            return ( $body !== false && $code >= 200 && $code < 300 ) ? $body : false;
        }
        $ctx  = stream_context_create( array(
            'http' => array( 'timeout' => $this->timeout, 'user_agent' => $this->userAgent, 'follow_location' => 1 ),
        ) );
        $body = @file_get_contents( $url, false, $ctx );
        return $body === false ? false : $body;
    }

    protected function cachePath( $url ) {
        if ( ! is_dir( $this->cacheDir ) ) {
            @mkdir( $this->cacheDir, 0755, true );
        }
        return $this->cacheDir . '/feed_' . sha1( $url ) . '.json';
    }

    protected function cacheGet( $url ) {
        $path = $this->cachePath( $url );
        if ( ! is_file( $path ) || filemtime( $path ) + $this->cacheTtl < time() ) {
            return false;
        }
        $data = json_decode( file_get_contents( $path ), true );
        return is_array( $data ) ? $data : false;
    }

    protected function cacheSet( $url, array $feed ) {
        @file_put_contents( $this->cachePath( $url ), json_encode( $feed ), LOCK_EX );
    }
}
