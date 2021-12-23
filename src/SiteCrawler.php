<?php

declare(strict_types=1);

namespace Varilink;

use Symfony\Component\BrowserKit\HttpBrowser;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

use Varilink\SiteCrawler\File;
use Varilink\SiteCrawler\Link;

/**
 * Crawls websites via HTTP and exposes the results.
 *
 * \Varilink\SiteCrawler uses Symfony components to crawl a website via HTTP and return the results of the crawl in a form that is suited to inclusion within PHPUnit tests. I have found it useful for regression testing following PHP upgrade and associated application changes. I run it against old and new versions of the affected website and compare the results in PHPUnit tests.
 *
 * To use the SiteCrawler you provide it with the URL of the website and one or more "seeds" - see [\Varilink\SiteCrawler\Seed](../Varilink-SiteCrawler-Seed.html) but in brief here, the seeds define the sections of the site to be crawled and fine tune the crawl behaviour.
 */
class SiteCrawler {

  /**
   * The URL of the website to be crawled, e.g. http://www.example.com.
   *
   * @var string $siteUrl
   */
  public $siteUrl;

  /**
   * The \Symfony\Component\BrowserKit\HttpBrowser instance being used by the \Varilink\SiteCrawler instance.
   *
   * \Varilink\SiteCrawler uses an instance of \Symfony\Component\BrowserKit\HttpBrowser as a client to crawl a site with - see [Making Externap HTTP Requests](https://symfony.com/doc/current/components/browser_kit.html#making-external-http-requests) in the documentation of [The BrowserKit](https://symfony.com/doc/current/components/browser_kit.html) Component provided by Symfony.
   *
   * @var \Symfony\Component\BrowserKit\HttpBrowser $browser
   */
  public $browser;

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
  public $seeds = [];

  /**
   * The "link" that is currently being processed by an instance of
   * Varilink\SiteCrawler. The term link is used here to refer instances of
   * <a>, <link> or <script> tags, all of which can reference content that is
   * external to the page that the instance of Varilink\SiteCrawler is
   * processing. This variable contains the absolute URI assocaited with
   * the href attribute of <a> or <link> tags or the src attribute of <link>
   * tags. Where those atributes contain URIs that aren't absolute, they will
   * be converted to absolute URIs using the
   * Symfony\Component\DomCrawler\UriResolver helper class to resolve them
   * against the base URI of the page containing the link.
   */
  public $link;

  /**
   * Constructor for the \Varilink\SiteCrawler class.
   *
   * @param string $site_url The URL of the site to be crawled, e.g. http://www.example.com.
   * @param \Varilink\SiteCrawler\Seed[] $seeds One or more instances of \Varilink\SiteCrawler\Seed that define the site sections for the crawl.
   * @return \Varilink\SiteCrawler An instance of the \Varilink\SiteCrawler class.
   */
  public function __construct(string $site_url, array $seeds) {

    $this->siteUrl = $site_url;
    $this->seeds = $seeds;

  }

  /**
   * Crawls the website
   * @param array $config Optional associative array giving configuration settings for the crawl.
   *
   * If the $config associative array is provided, it must contain one or more values as follows for these keys:
   * - 'ignore' => an anonymous function provided as an instance of \Closure
   * - 'limit' => an integer limit on the number of pages to be parsed by the SiteCrawler for a single Seed
   */
  public function crawl(array $config = []) : void
  {

    if (
      # config has been provided that includes the ignore key
      \array_key_exists ('ignore', $config)
      # and the value associated with the ignore key is a \Closure instance
      && $config['ignore'] instanceof \Closure
    ) {
      $ignore = $config['ignore'];
    } else {
      $ignore = FALSE;
    }

    if (
      # config has been provided that includes the limit key
      \array_key_exists('limit', $config)
      # and the value associated with the limit key is a positive integer
      && \is_int($config['limit']) && $config['limit'] > 0
    ) {
      $limit = $config['limit'];
    } else {
      $limit = FALSE;
    }

    if (
      # config has been provided that includes the log key
      \array_key_exists('log', $config)
      # and the value associated with the log key is integer 1, 2 or 3
      && \in_array($config['log'], [1, 2, 3, 4], TRUE)
    ) {
      $log = \intval($config['log']);
      print "Started Site $this->siteUrl\n";
    } else {
      $log = FALSE;
    }

    # process each seed in turn
    foreach ($this->seeds as $seed) {

      # make the current seed available as a property
      $this->seed = $seed;

      if ($log) {
        # we've been asked to log, so report that we've started the seed
        if ( $seed->name ) { print "--Started Seed $seed->name\n"; }
        else { print "--Started Seed $seed->path\n"; }
      }

      # create browser instance and make if available as a property
      if (isset($seed->client)) {
        $browser = new HttpBrowser($seed->client);
      } else {
        $browser = new HttpBrowser(HttpClient::create(
          ['timeout'=>1, 'max_duration'=>2, 'max_redirects'=>0]
        ));
      }
      $browser->setMaxRedirects(5);
      $this->browser = $browser;

      # if a setup closure has been provided for this seed then execute it now
      isset($seed->setup) && $seed->setup($this);

      /*
        HttpBrower returns a DomCrawler instance on a successful GET to a URI that returns HTML. Maintain an array of crawler instances for all the pages within the website that are still to be parsed. Assume that as the seed path has been provided it is valid and corresponds to a HTML page. See - github.com/varilink/libraries-php/issues/3
      */
      $crawlers[$seed->path]
        = $browser->request('GET', $this->siteUrl . $seed->path);

      $file = new File(
        $seed->path,
        $browser->getResponse()->getHeader('content-type'),
        $browser->getResponse()->getContent()
      );
      $seed->files[] = $file;

      $i = 0; # count of parsed pages

      while ($page_paths = \array_keys($crawlers)) {

        # we found a page that is yet to be parsed
        $page_path = $page_paths[0];
        $crawler = $crawlers[$page_path];
        unset($crawlers[$page_path]);

        $i++;
        if (
          $limit
          # we have chosen to limit the number of pages parsed
          && $i > $limit
          # and this page will take us over that limit so...
        ) {
          # stop parsing pages now
          $crawlers = [];
          break;
        }

        # we've not exceeded any limit set for pages parsed

        if ($log && $log > 1) {
          print "----Started Page $page_path\n";
        }

        /*
         Get all DOM elements in the page corresponding to 'a', 'link' and 'script' tags. It is these tags
        */
        foreach ( $crawler->filter('a, link, script' ) as $domElement ) {

          $link = new Link (
            $domElement,
            $crawler->getBaseHref(),
            $this->siteUrl
          );

          $this->link = $link;

          if ($log && $log > 2) {
            $link->external
              ? $link_report = "------Link $link->absUrl\n"
              : $link_report = "------Link $link->absPath\n";
          }

          if (!$link->hyperlink) {
            # ignore this link as we do NOT test non HTTP protocols like mailto
            if ($log && $log > 3) {
              $link_report .= "Skipped (not hyperlink)\n";
              print $link_report;
            }
            continue; # next link
          }

          if ($link->absUrl === $crawler->getUri()) {
            if ($log && $log > 3) {
              $link_report .= "Self referencing link\n";
              print $link_report;
            }
            continue; # next link
          }

          if ($link->internal && $link->absPath == $seed->path) {
            if ($log && $log > 3) {
              $link_report .= "Skipped (seed)\n";
              print $link_report;
            }
            continue; # next link
          }

          foreach ($seed->links as $priorLink) {

            if ($priorLink->absUrl === $link->absUrl) {
              if ($log && $log > 3) {
                $link_report .= "Skipped (already processed)\n";
                print $link_report;
              }
              $priorLink->pages[] = $page_path;
              continue 2; # next link
            }
          }

          if (isset($seed->ignore) && $seed->ignore($this)) {
            if ($log && $log > 3) {
              $link_report .= 'Ignored (' . $seed->ignore($this) . ")\n";
              print $link_report;
            }
            continue; # next link
          }

          $seed->links[] = $link;
          $link->pages[] = $page_path;

          if ($log && $log > 2) { print $link_report; }

          try {
            $candidate_crawler = $browser->request('GET', $link->absUrl );
            $link->httpCode = $browser->getResponse()->getStatusCode();
          } catch (\LogicException | TransportExceptionInterface $e) {
            $link->exception = $e->getMessage();
          }

          if ($link->exception) {

            if ($log && $log > 3) {
              print "Exception $link->exception\n";
            }

          } else { # No exception was raised so a HTTP response was received

            if ($log && $log > 3) {
              print "HTTP code $link->httpCode\n";
            }

            if ($link->httpCode === 200) {

              if ($log && $log > 3) {
                print 'Content type ' .
                  $browser->getResponse()->getHeader('content-type') . "\n";
              }

              if ($link->internal) {

                $file = new File(
                  $link->absPath,
                  $browser->getResponse()->getHeader('content-type'),
                  $browser->getResponse()->getContent()
                );
                $seed->files[] = $file;

                if (preg_match('~^text/html~', $file->contentType)) {

                  if (count($candidate_crawler) > 0) {
                    /*
                      The crawler returned has nodes in it, which indicates that HTML was returned and not, for example, binary content such as a PDF file. Since we are validating HTML and parsing it for links, add this crawler to the list of crawlers to be parsed.
                    */
                    $crawlers[$link->absPath] = $candidate_crawler;
                    if ($log && $log > 3) {
                      print "Added to pages to be parsed\n";
                    }
                  } else {
                    if ($log && $log > 3) {
                      print "Could NOT add to pages to be parsed!\n";
                    }
                  }

                } # HTML content type

              } # internal link

            } # HTTP 200 response

          } # if $link->exception... else HTTP response received

        } # foreach 'a', 'link' or 'script' tag

        if ($log && $log > 1) {
          print "----Ended Page $page_path, there are ";
          print \count($crawlers);
          print " left to parse for the seed\n";
        }

      } # while $page_paths

      if ($log) {
        if ( $seed->name ) { print "--Finished Seed $seed->name\n"; }
        else { print "--Finished Seed $seed->path\n"; }
      }

      isset($seed->teardown) && $seed->teardown($this);

    } # foreach $seed

  } # function crawl

} # class SiteCrawler

namespace Varilink\SiteCrawler ;
use Symfony\Component\DomCrawler\UriResolver;

class File {
  public $absPath;
  public $contentType;
  private $content;
  public function __construct(
    string $abs_path, string $content_type, string $content
  ) {
    $this->absPath = $abs_path;
    $this->contentType = $content_type;
    $this->content($content);
  }
  public function content($content = NULL) {
    if ($content) {
      $this->content = gzcompress($content);
    } else {
      return gzuncompress($this->content);
    }
  }
}

class Link {

  private $domElement;
  public $absUrl;
  public $absPath;
  public $internal;
  public $external;
  public $httpCode = NULL;
  public $exception = NULL;
  public $hyperlink = FALSE;
  public $pages = [];

  public function __construct (
    \DomElement $domElement,
    string $base_href,
    string $site_url
  ) {

    $this->domElement = $domElement;

    # Determine the link tag's URI from the appropriate attribute for the tag
    if ($this->tagName === 'a') {
      $tag_uri = $this->getAttribute('href');
      if (preg_match('~^(.*?)#.*?$~', $tag_uri, $matches)) {
        $tag_uri = $matches[1];
      }
    } elseif ($this->tagName === 'link') {
      $tag_uri = $this->getAttribute('href');
    } elseif ($this->tagName === 'script') {
      $tag_uri = $this->getAttribute('src');
    } else {
      die(
        '\Varilink\SiteCrawler\Link used for tag other than a, link or script'
      );
    }

    # Resolve the tag URI against the page's base HREF to yield an absolute URL
    $this->absUrl = UriResolver::resolve($tag_uri, $base_href);
    # Determine if the link is internal or external to the site being crawled
    if ( \preg_match("~^$site_url(.*)~", $this->absUrl, $matches) ) {
      # Link is internal
      $this->internal = TRUE;
      $this->external = FALSE;
      $this->absPath = $matches[1];
    } else {
      # Link is external
      $this->internal = FALSE;
      $this->external = TRUE;
    }

    if ( \preg_match('~^(http|https)://~', $this->absUrl ) ) {
      $this->hyperlink = TRUE;
    }

  }

  /*
    If an undefined method of a \Varilink\SiteCrawler\Link instance is called, then attempt to satisfy it using the same method of the \DomElement instance stored in our $domElement property.
  */
  public function __call($name, $args) {
    if (\is_callable([$this->domElement, $name]) && \count($args) == 1) {
      return $this->domElement->$name($args[0]);
    }
  }

  public function __get($name) {
    return $this->domElement->$name;
  }

}

/**
 * A section of a website crawled via a \Varilink\SiteCrawler instance.
 *
 * Seed instances both control how the SiteCrawler crawls a section of the website and capture the results of the crawl for that section. The "control" is defined by the path that the crawl begins at and one or more anonymous functions provided as \Closure instances that influence the behavour of the crawl.
 */
class Seed {

  /**
   * @var string $path The relative path within the website that the crawl for this seed starts from, e.g. /admin - see the [__construct method](classes/Varilink-SiteCrawler-Seed.html#method___construct).
   */
  public $path;

  /**
   * @var string $name The unique name of the seed if one when given when it was instantiated - see the [__construct method](classes/Varilink-SiteCrawler-Seed.html#method___construct).
   */
  public $name;

  /**
   * @var \Closure $ignore Closure that controls whether page links within the site being crawled are followed or not - see the [__construct method](classes/Varilink-SiteCrawler-Seed.html#method___construct).
   */
   public $ignore;

  /**
   * @var \Closure $setup Closure to be executed by the \Varlink\SiteCrawler instance before crawling this seed - see the [__construct method](classes/Varilink-SiteCrawler-Seed.html#method___construct).
   */
  public $setup;

  /**
   * @var \Closure $setup Closure to be executed by the \Varlink\SiteCrawler instance after crawling this seed - see the [__construct method](classes/Varilink-SiteCrawler-Seed.html#method___construct).
   */
  public $teardown;

  /**
   * @var array $links The links that have been found so far by Varilink\SiteCrawler for this seed. This is of course added to as the crawl progresses.
   *
   * The links are stored as an associative array, the keys of which are the URIs of each link and the values of which are associative arrays that contain the following information:
   * - 'result' => The status code of the HTTP response when returned when following the link.
   * - 'pages' => An array of paths corresponding to the pages within the site in links with this URI were found.
   */
  public $links = [];

  /**
   * @var array $pages The pages that have been parsed by Varilink\SiteCrawler for this seed. This is of course added to as the crawl progresses.
   *
   * The pages are stored as an associative array, the keys of which are the
   * paths within the site that the page was found at and the values of which
   * are the HTML content of the page that has been compressed using gzcompress.
   */
  public $files = [];

  /**
   * Constructor for Varilink\SiteCrawler\Seed.
   *
   * @param string $path Relative path within the site for the seed, e.g. /, /admin, /members, etc.
   * @param array $config (optional) Associative configuration array for the seed. If this is provided then it must be an associative array containing values for one or more of the following keys:
   * - name
   * - ignore
   * - setup
   * - teardown
   *
   * "name" can be used to give the seed a unique name (string). Sometimes more than one seed can have the same relative path; for example when crawling the same site section as two different authenticated users with different roles. When this is the case, a unique name for each seed is useful for clarity when reporting on the crawl results.
   *
   * "ignore", "setup" and "teardown" should be set to an anonymous function (Closure instance). If set, these are called/used by the \Varilink\SiteCrawler instance as follows:
   * - setup = Called before the crawl of the seed path begins.
   * - teardown = Called after the crawl of the seed path has finished.
   * - ignore = Called when deciding whether or not to follow a link to another page within the site. If this returns a true value then the link will NOT be followed.
   *
   * The \Varilink\SiteCrawler instance will pass itself to any provided closure so that it can be accessed by that closure.
   *
   * These closures can be used modify the behavour of the \Varilink\SiteCrawler in useful ways; for example:
   * - setup and teardown can access to $browser property of the \Varilink\SiteCrawler instance to login to and logout of the site when crawling a seed path requires the relevant authentication/authorization.
   * - ignore can be used to not follow a link that generates a view of the current page in a printable format.
   * - Etc.
   *
   * @return \Varilink\SiteCrawler\Seed An instance of the \Varilink\SiteCrawler\Seed class.
   */

  public $client;

  function __construct ( string $path, array $config = [] ) {

    $this->path = $path;

    if ( array_key_exists('name', $config) ) {
      $this->name = $config['name'];
    }
    if ( array_key_exists('ignore', $config) ) {
      $this->ignore = $config['ignore'];
    }
    if ( array_key_exists('setup', $config) ) {
      $this->setup = $config['setup'];
    }
    if ( array_key_exists('teardown', $config) ) {
      $this->teardown = $config['teardown'];
    }
    if ( array_key_exists('client', $config) ) {
      $this->client = $config['client'];
    }

  }

  /**
   * Enables the name, ignore, setup and teardown closures to be called as if they were methods of \Varilink\SiteCrawler\Seed itself. This is only used by the \Varilink\SiteCrawler class and so not included in phpDocumentor output.
   * @ignore
   */
  public function __call ( $method , $args ) {

    if ( is_callable ( array ( $this , $method ) ) ) {
      return call_user_func_array ( $this -> $method , $args ) ;
    }

  }

}
