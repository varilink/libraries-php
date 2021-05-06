<?php namespace Varilink ;
/**
 * \Varilink\SiteCrawler and \Varilink\SiteCrawler\Seeds
 */

use Symfony\Component\BrowserKit\HttpBrowser ;
use Symfony\Component\HttpClient\HttpClient ;
use Symfony\Component\DomCrawler\UriResolver ;

/**
 * Crawls websites via HTTP and exposes the results
 */
class SiteCrawler {

  /**
   * Site (protocol and host) to be crawled, e.g. http://www.example.com
   */
  public $base ;
  /**
   * An array of seed paths for the crawl, e.g. /, /admin, /members
   *
   * The crawler object will conduct the crawl from one or more seed paths. Each
   * seed path must correspond to a HTML page within the site. The crawler will
   * retrieve that page and test every link within it. Where links are internal
   * to the site, it will retrieve the pages associated with those links and do
   * the same to those pages and it will keep going until it runs out of links
   * it has not tested and pages it has not parsed. Thus the seeds are the
   * "home" locations for each independent area within the site that is to be
   * crawled.
   *
   * Each item in the array must be an associative array with a key of "path"
   * providing the path within the site for the seed, e.g. "/", "/admin",
   * "/members" etc.
   *
   * If authentication is required in order to access the area within the site
   * associated with the seed, then the necessary credentials are provided with
   * the seed. This can be either for basic authentication or for form based
   * authentiation.
   *
   * To provide basic authentication credetials, provide the key 'auth_basic'
   * within the seed providing an array of user credentials as follows:
   * $seeds = [
   *   'path' => '/seed-path',
   *   'auth_basic' => [ 'username', 'password' ]
   * ];
   *
   * To provide form based authentication credentials, and to define how the
   * form should be submitted, provide the key 'auth_login' within the seed
   * $seeds = [
   *   'path' => '/seed-path',
   *   'auth_login' => [
   *     '/login-path', 'Log in', [
   *       'username' => 'username',
   *       'password' => 'password',
   *       'other-parm' => 'other-parm'
   *     ]
   *   ]
   * ];
   *
   * The string to find the logon form ('Log in') and the array of form
   * parameters and values should be as required by the Symfony browser kit,
   * see:
   * @link https://symfony.com/doc/current/components/browser_kit.html#submitting-forms Symfony Browser Kit - Submitting Forms
   */
  public $seeds = [ ] ;
  /**
   * The link that is currently being processed
   */
  public $link ;

  /**
   * @ignore
   */
  public $browser ;

  /**
   * Constructor for the SiteCrawler class
   * @param string $site The site to be crawled
   * @param array $seeds One or more seed paths for the crawl within the site
   */
  public function __construct ( string $base , array $seeds ) {

    $this -> base = $base ;
    $this -> seeds = $seeds ;

  }

  /**
   * Crawls the website
   * @param array $config Optional configuration settings
   */
  public function crawl ( array $config = [ ] ) : void
  {

    if ( array_key_exists ( 'ignore' , $config ) &&
      $config [ 'ignore' ] instanceof \Closure ) {
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

      $this -> seed = $seed ;

      if ( $log ) {
        print 'Started seed ' ;
        if ( $seed -> name ) {
          print 'with name=' . $seed -> name . ' and path=' . $seed -> path ;
        } else {
          print 'with path=' . $seed -> path ;
        }
        print "\n" ;
      }

      if ( isset ( $seed -> client_config ) ) {

        $this -> browser = new HttpBrowser (
          HttpClient::create ( $seed -> client_config )
        ) ;

      } else {

        $this -> browser = new HttpBrowser ( HttpClient::create ( ) ) ;

      }

      isset ( $seed -> setup ) && $seed->setup ( $this ) ;

      /*
        The HttpBrower object returns a DomCrawler object on a successful GET
        request to a URI that returns HTML. We maintain an array of crawler
        objects for all the pages within the website that we have still to
        parse. We know that the seeds correspond to HTML pages so we don't
        have to check their content. Therefore, if the call to a seed is
        successful, use it to start the population of the crawlers array.
      */
      $crawlers [ ] = $this -> browser -> request (
        'GET' , $this -> base . $seed -> path
      ) ;

      $i = 0 ;

      while ( $crawler = array_shift ( $crawlers ) ) {
        /*
          There are pages in our crawlers array still to parse. Take the next
          one from the array and parse it.
        */

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
        $page_path = substr ( $page_uri , strlen ( $this -> base ) ) ;
        $html = '' ;
        foreach ( $crawler as $element ) {
          $html .= $element -> ownerDocument -> saveHTML ( $element ) ;
        }
        $this -> seed -> pages [ $page_path ] = gzcompress ( $html ) ;

        if ( $log && $log > 1 ) {
          print "--Started parsing page=$page_path\n" ;
        }

        # Process each <a> tag (link) found in this page
        foreach ( $crawler -> filter ( 'a , link , script' ) as $link ) {

          # Store this link's URI in a convenience variable
          if ( $link -> tagName === 'a' ) {
            $rawUri = $link -> getAttribute ( 'href' ) ;
          } elseif ( $link -> tagName === 'link' ) {
            $rawUri = $link -> getAttribute ( 'href' ) ;
          } elseif ( $link -> tagName === 'script' ) {
            $rawUri = $link -> getAttribute ( 'src' ) ;
          }

          $link_uri = UriResolver::resolve (
            $rawUri , $crawler -> getBaseHref ( )
          ) ;

          # Make the link available as one of this object's public properties
          $this -> link = $link_uri ;

          /*
            Only test http or https links. Ignore other protocols, e.g. mailto
            or javascript links.
          */
          if ( ! preg_match ( '/^(?:http|https):/' , $link_uri ) ) {
            if ( $log && $log > 2 ) {
              print " -> ignored (not http/https link)\n" ;
            }
            continue ;
          }

          if ( strpos ( $link_uri , $this -> base ) === 0 ) {
            $link_type = 'int' ;
          } elseif ( strpos ( $link_uri , $this -> base ) === FALSE ) {
            $link_type = 'ext' ;
          }

          if ( $link_type === 'ext' ) {
            if ( $log && $log > 2 ) { print "----Link=$link_uri" ; }
          } else {
            $link_path = substr ( $link_uri , strlen ( $this -> base ) ) ;
            if ( $log && $log > 2 ) { print "----Link=$link_path" ; }
          }

          if (
            $link_type === 'int'
            && in_array (
              $link_path , array_column ( $this -> seeds , 'path' )
            )
          ) {
            if ( $log && $log > 2 ) { print " -> ignored (seed)\n" ; }
            continue ;
          }

          if ( $ignore && $ignore ( $this ) ) {
            if ( $log && $log > 2 ) { print " -> ignored (user defined)\n" ; }
            continue ;
          }

          #if ( ! array_key_exists ( urlencode ( $link_uri ) , $this -> links ) )
          if ( ! array_key_exists ( $link_uri , $this -> seed -> links ) ) {
          # We haven't yet checked this link before,so check it now

################################################################################

# THIS CAN BE TRUE FOR NON EXTERNAL LINKS, E.G. javascript:void(0); OR mailto
# NEED A GENERIC TEST, E.G. ON HTTP/HTTPS PROTOCOL

################################################################################

            if ( $link_type === 'ext' ) {
              /*
                The HREF is a URI and not a path within our website. We do not
                need to use our browser object to test it, since we do not need
                to be authenticated and we are not interested in the content,
                only that the link returns something.
              */

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

              if ( gethostbyname ( $hostname ) != $hostname ) {
              /*
                gethostbyname returns an IP address if successful and the
                hostname that was used for the lookup otherwise. So, if it
                returned something other than the hostname we know the lookup
                was successful and we can go ahead to test the link.
              */

                $client = HttpClient::create ( ) ;
                try {
                  $response = $client -> request ( 'GET' , $link_uri , [
                    'timeout' => 1 , 'max_duration' => 2
                  ] ) ;
                  $status_code = $response -> getStatusCode ( ) ;
                } catch ( Exception $e ) {
                  $status_code = 404 ;
                }

              } else {
                /*
                  The hostname lookup was unsuccessful. Represent this as a HTTP
                  404 (not found) response, which isn't strictly correct but it
                  serves our purposes here.
                */
                $status_code = 404 ;
              }

              if ( $log && $log > 2 ) {
                print " -> external, tested and RC=$status_code\n" ;
              }

            } else {
              /*
                The HREF and the URI for this link are different. That tells us
                that the HREF is a path within our website and not a link to a
                page external to our website.
              */

              $candidate_crawler =
                $this -> browser -> request ( 'GET' , $link_uri ) ;
              $status_code =
                $this -> browser -> getResponse ( ) -> getStatusCode ( ) ;
              if ( $log && $log > 2 ) {
                print " -> internal, tested and RC=$status_code" ;
              }

              if ( $status_code === 200 ) {

                # The GET request was successful

                if (
                  $link -> tagName === 'a' && count ( $candidate_crawler ) > 0
                ) {

                  /*
                    The crawler returned has nodes in it, which indicates that
                    HTML was returned and not, for example, binary content such
                    as a PDF file. Since we are validating HTML and parsing it
                    for links, add this crawler to the list of crawlers to be
                    parsed.
                  */
                  if ( $log && $log > 2 ) {
                    print " (added to pages to be parsed)\n" ;
                  }
                  $crawlers [ ] = $candidate_crawler ;
                } elseif (
                  $link -> tagName === 'link' || $link -> tagName === 'script'
                ) {
                  $this -> seed -> content [ $link_path ] = gzcompress (
                    $this -> browser -> getResponse ( ) -> getContent ( )
                  ) ;
                }

              } # if ( $status_code === 200 )

              if ( $log && $log > 2 ) { print "\n" ; }

            } # if ( $link_type === 'ext' )

            $this -> seed -> links [ $link_uri ] [ 'status_code' ]
              = $status_code ;

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
        $this -> seed -> links [ $link_uri ] [ 'pages' ] [ ] = $page_uri ;

        } # foreach ( $crawler -> filter ( 'a' ) -> links ( ) as $link )

        if ( $log && $log > 1 ) {
          print "--Ended parsing page=$page_path, there are " ;
          print count ( $crawlers ) ;
          print " currently left to parse for this seed\n" ;
        }



      } # while ( $crawler = array_shift ( $crawlers ) )

      if ( $log ) { print 'Finished seed=' . $seed -> path . "\n" ; }

      isset ( $seed -> teardown ) && $seed -> teardown ( $this ) ;


    } # foreach ( $this -> seeds as $seed )

  } # function crawl ( )

}

namespace Varilink\SiteCrawler ;

class Link {

  private $domElement ;

  function __construct ( DomElement $domElement ) {

  }

}

class Seed {

  /**
   * The path within the website from which the crawl starts
   */
  public $path ;

  /**
   * Optional name for the seed
   *
   * Sometimes there may be a need to crawl a website more than once for the
   * same seed path; for example, to crawl a /user area multiple areas with
   * for different users. Where this is the case, setting a unique seed name for
   * each of those crawls allows the results of each crawl to be identified in
   * reports of the crawls.
   */
  public $name ;

  /**
   * Closure that can be used to prevent links being follows
   *
   * When c
   */
  public $ignore ;
  public $setup ;
  public $teardown ;

  /**
   * The links that have been found by Varilink\SiteCrawler for this seed
   *
   * The links are stored as an associative array, the keys of which are the
   * URIs of each link and the values of which are associative arrays that
   * contain the following information:
   * 'result' => The status code of the HTTP response when returned when
   * following the link.
   * 'pages' => An array of paths corresponding to the pages within the site in
   * links with this URI were found.
   */
  public $links = [ ] ;

  /**
   * The pages that have been parsed by Varilink\SiteCrawler for this seed
   *
   * The pages are stored as an associative array, the keys of which are the
   * paths within the site that the page was found at and the values of which
   * is the HTML content of the page that has been compressed using gzcompress.
   */
  public $pages = [ ] ;

  public $content = [ ] ;

  /**
   * Constructor for Varilink\SiteCrawler\Seed
   *
   * @param string $path Mandatory path for the seed, e.g. /admin
   * @param array $config Optional associative configuration array for the seed.
   * If this is provided, one or more of the following parameters can be set:
   * - name
   * - ignore
   * - setup
   * - teardown
   */
  function __construct ( string $path , array $config = [ ] ) {

    $this -> path = $path ;

    if ( array_key_exists ( 'name' , $config ) ) {
      $this -> name = $config [ 'name' ] ;
    }
    if ( array_key_exists ( 'ignore' , $config ) ) {
      $this -> ignore = $config [ 'ignore' ] ;
    }
    if ( array_key_exists ( 'setup' , $config ) ) {
      $this -> setup = $config [ 'setup' ] ;
    }
    if ( array_key_exists ( 'teardown' , $config ) ) {
      $this -> teardown = $config [ 'teardown' ] ;
    }

  }

  public function __call ( $method , $args ) {

    if ( is_callable ( array ( $this , $method ) ) ) {
      return call_user_func_array ( $this -> $method , $args ) ;
    }

  }

}
