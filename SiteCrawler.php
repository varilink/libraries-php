<?php
/**
 * There is nothing to say for the file that the class description doesn't cover
 * @ignore
 */

namespace Varilink ;

/**
 * @ignore
 */
require_once '/vendor/autoload.php' ;

use Symfony\Component\BrowserKit\HttpBrowser ;
use Symfony\Component\HttpClient\HttpClient ;

/**
 * Class to provide crawler objects that crawl websites via HTTP
 */
class SiteCrawler {

  /**
   * Site (protocol and host) being crawled, e.g. http://www.example.com
   */
  public $site ;
  /**
   * Seed paths for the crawl, e.g. /, /admin, /members
   */
  public $seeds = [ ] ;
  /**
   * The pages that have been parsed
   */
  public $pages = [ ] ;
  /**
   * The link currently being tested
   */
  public $link ;
  /**
   * The links that have been found and tested
   */
  public $links = [ ] ;

  /**
   * Constructor for the SiteCrawler class
   * @param string $site The site to be crawled
   * @param array $seeds One or more seed paths for the crawl within the site
   */
  public function __construct ( string $site , array $seeds ) {

    $this -> site = $site ;
    $this -> seeds = $seeds ;

  }

  /**
   * Crawls the website
   * @param array $config Optional configuration settings
   */
  public function crawl ( array $config = [ ] ) : void {

    if ( array_key_exists ( 'ignore' , $config ) &&
      $config [ 'ignore' ] instanceof Closure ) {
      $ignore = $config [ 'ignore' ] ;
    } else {
      $ignore = FALSE ;
    }

    if ( array_key_exists ( 'limit' , $config ) && $config [ 'limit' ] ) {
      $limit = $config [ 'limit' ] ;
    } else {
      $limit = FALSE ;
    }

    if ( array_key_exists ( 'log' , $config ) && $config [ 'log' ] ) {
      $log = $config [ 'log' ] ;
    } else {
      $log = FALSE ;
    }

    foreach ( $this -> seeds as $seed ) {

      if ( $log ) { print 'Started seed=' . $seed [ 'path' ] . "\n" ; }

      if ( array_key_exists ( 'auth_basic' , $seed ) ) {

        $browser = new HttpBrowser ( HttpClient::create ( [
          'auth_basic' => $seed [ 'auth_basic' ]
        ] ) ) ;

      } else {

        $browser = new HttpBrowser ( HttpClient::create ( ) ) ;

      }

      if ( array_key_exists ( 'auth_login' , $seed ) ) {

        $browser -> request (
          'GET' , $this -> site . $seed [ 'auth_login' ] [ 0 ]
        ) ;

        $browser -> submitForm (
          $seed [ 'auth_login' ] [ 1 ] , $seed [ 'auth_login' ] [ 2 ]
        ) ;

      }


    /*
      The HttpBrower object returns a DomCrawler object on a successful GET
      request to a URI that returns HTML. We maintain an array of crawler
      objects for all the pages within the website that we have still to
      parse. We know that the seeds correspond to HTML pages so we don't
      have to check their content. Therefore, if the call to a seed is
      successful, use it to start the population of the crawlers array.
    */
    $crawlers [ ] = $browser -> request (
      'GET' , $this -> site . $seed [ 'path' ]
    ) ;

    $i = 0 ;

    while ( $crawler = array_shift ( $crawlers ) )
    /*
      There are pages in our crawlers array still to parse. Take the next
      one from the array and parse it.
    */
    {

      $i++ ;
      if ( $limit && $i > $limit ) {
        $crawlers = [ ] ;
        break ;
      }

      /*
        Create a convenience variable to hold the pages URI. Dump the HTML
        content from the crawler (when we registered the crawler we tested
        that the page contained HTML). Store the HTML with a key of the URI
        for later testing that the HTML content is valid.
      */
      $page_uri = $crawler -> getUri ( ) ;
      $page_path = substr ( $page_uri , strlen ( $this -> site ) ) ;
      $html = '' ;
      foreach ( $crawler as $element ) {
        $html .= $element -> ownerDocument -> saveHTML ( $element ) ;
      }
      $this -> pages [ $page_path ] = gzcompress ( $html ) ;

      if ( $log && $log > 1 ) {
        print "--Started parsing page=$page_path\n" ;
      }

      foreach ( $crawler -> filter ( 'a' ) -> links ( ) as $link )
      # Process each <a> tag (link) found in this page
      {

        $this -> link = $link ;

        # Store this link's URI and HREF in convenience variables
        $link_uri = $link -> getUri ( ) ;
        $href = $link -> getNode ( ) -> getAttribute ( 'href' ) ;

        if ( $link_uri === $href ) {
          if ( $log && $log > 2 ) { print "----Link=$link_uri" ; }
        } else {
                    if ( $log && $log > 2 ) {
          print
            '----Link=' . substr ( $link_uri , strlen ( $this -> site ) ) ;
          }
        }

        if ( in_array ( $href , array_column ( $this -> seeds , 'path' ) ) ) {
          if ( $log && $log > 2 ) { print " -> ignored (seed)\n" ; }
          continue ;
        }

        if ( preg_match ( '/^mailto:/' , $link_uri ) ) {
          if ( $log && $log > 2 ) { print " -> ignored (mailto link)\n" ; }
          continue ;
        }

        if ( $ignore && $ignore ( $this ) ) {
          if ( $log && $log > 2 ) { print " -> ignored (user defined)\n" ; }
          continue ;
        }

        #if ( ! array_key_exists ( urlencode ( $link_uri ) , $this -> links ) )
        if ( ! array_key_exists ( $link_uri , $this -> links ) )
        # We haven't yet checked this link before,so check it now
        {

          if ( $link_uri === $href )
          /*
            The HREF is a URI and not a path within our website. We do not
            need to use our browser object to test it, since we do not need
            to be authenticated and we are not interested in the content,
            only that the link returns something.
          */
          {

            /*
              Capture the hostname for a hostname DNS lookup. The HttpClient
              object exhibits behaviour we don't want to trigger if it is
              used to try to follow a link for which the hostname DNS lookup
              fails.
            */
            preg_match (
              '/^(?:http|https):\/\/([\w|\.|-]+)/' , $link_uri , $matches
            ) ;
            $hostname = $matches [ 1 ] ;

            if ( gethostbyname ( $hostname ) != $hostname )
            /*
              gethostbyname returns an IP address if successful and the
              hostname that was used for the lookup otherwise. So, if it
              returned something other than the hostname we know the lookup
              was successful and we can go ahead to test the link.
            */
            {

              $client = HttpClient::create ( ) ;
              try {
                $response = $client -> request ( 'GET' , $link_uri , [
                  'timeout' => 1 , 'max_duration' => 2
                ] ) ;
                $rc = $response -> getStatusCode ( ) ;
              } catch ( Exception $e ) {
                $rc = 404 ;
              }

            } else
            /*
              The hostname lookup was unsuccessful. Represent this as a HTTP
              404 (not found) response, which isn't strictly correct but it
              serves our purposes here.
            */
            {
              $rc = 404 ;
            }

            if ( $log && $log > 2 ) { print " -> external, tested and RC=$rc\n" ; }

          } else
            /*
              The HREF and the URI for this link are different. That tells us
              that the HREF is a path within our website and not a link to a
              page external to our website.
            */
          {

            $candidate_crawler = $browser -> request ( 'GET' , $link_uri ) ;
            $rc = $browser -> getResponse ( ) -> getStatusCode ( ) ;
            if ( $log && $log > 2 ) { print " -> internal, tested and RC=$rc" ; }
            if (
               $rc === 200
               # The GET request was successful
               && count ( $candidate_crawler ) > 0
               /*
                 The crawler returned has nodes in it, which indicates that
                 HTML was returned and not, for example, binary content such
                 as a PDF file. Since we are validating HTML and parsing it
                 for links, add this crawler to the list of crawlers to be
                 parsed.
               */
            ) {
              if ( $log && $log > 2 ) { print " (added to pages to be parsed)\n" ; }
              $crawlers [ ] = $candidate_crawler ;
            } else {
              if ( $log && $log > 2 ) { print "\n" ; }
            }

          }

          #$this -> links [ urlencode ( $link_uri ) ] [ 'result' ] = $rc ;
          $this -> links [ $link_uri ] [ 'result' ] = $rc ;

        } else {

          if ( $log && $log > 2 ) {
            print " -> ignored (already processed)\n" ;
          }

        } # if ( ! array_key_exists ( $link_uri , $this -> links ) )

        /*
          Whether we tested this link or didn't, because it had already been
          tested, make a not that this link was found within the page that
          is currently being parsed.
        */
#        $this -> links [ urlencode ( $link_uri ) ] [ 'pages' ] [ ] = $page_uri ;
        $this -> links [ $link_uri ] [ 'pages' ] [ ] = $page_uri ;

        } # foreach ( $crawler -> filter ( 'a' ) -> links ( ) as $link )

        if ( $log && $log > 1 ) {
          print "--Ended parsing page=$page_path, there are " ;
          print count ( $crawlers ) ;
          print " currently left to parse for this seed\n" ;
        }



      } # while ( $crawler = array_shift ( $crawlers ) )

      if ( $log ) { print 'Finished seed=' . $seed [ 'path' ] . "\n" ; }


    } # foreach ( $this -> seeds as $seed )

  } # function crawl ( )

}
