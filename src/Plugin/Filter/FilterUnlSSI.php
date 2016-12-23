<?php
/**
 * @file
 * Contains Drupal\unl_filter\Plugin\Filter\FilterUnlSSI
 */

namespace Drupal\unl_filter\Plugin\Filter;

use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;

/**
 * Provides a filter to do [[include-url:"some/path"]] includes.
 *
 * @Filter(
 *   id = "filter_unl_ssi",
 *   title = @Translation("UNL SSI Filter"),
 *   description = @Translation("Implements the [[include-url:'some/path']] Server Side Include directive."),
 *   type = Drupal\filter\Plugin\FilterInterface::TYPE_MARKUP_LANGUAGE,
 * )
 */
class FilterUnlSSI extends FilterBase {

  /**
   * {@inheritdoc}
   */
  public function process($text, $langcode) {
    $matches = NULL;
    preg_match_all('/\[\[include-url:((".*")|(\'.*\'))*\]\]/', $text, $matches);

    $replacements = array();

    foreach ($matches[1] as $match_index => $match) {
      $full_match = $matches[0][$match_index];

      // Break down the URL target then rebuild it as absolute.
      $url = substr($match, 1, -1);
      $url = html_entity_decode($url);
      $parts = parse_url($url);
      if (!isset($parts['scheme'])) {
        $parts['scheme'] = isset($_SERVER['HTTPS']) ? 'https' : 'http';
      }
      if (!isset($parts['host'])) {
        $parts['host'] = $_SERVER['SERVER_NAME'];
      }
      /* We can't do this on production because we're running on port 8080.
      if (!isset($parts['port']) && !in_array($_SERVER['SERVER_PORT'], array(80, 443))) {
        $parts['port'] = $_SERVER['SERVER_PORT'];
      }
      */
      if (isset($parts['path']) && substr($parts['path'], 0, 1) != '/') {
        if (variable_get('unl_use_base_tag')) {
          $parts['path'] = $GLOBALS['base_path'] . $parts['path'];
        } else {
          $parts['path'] = $GLOBALS['base_path'] . request_path() . '/' . $parts['path'];
        }
      }
      if (!isset($parts['path'])) {
        $parts['path'] = '/';
      }
      $url = $parts['scheme']
        . '://' . $parts['host']
        . (isset($parts['port']) ? ':' . $parts['port'] : '')
        . $parts['path'];

      // If this is a request to another UNL site, add format=partial to the query.
      if (substr($parts['host'], -7) == 'unl.edu') {
        if (isset($parts['query']) && $parts['query']) {
          $parts['query'] .= '&';
        } else {
          $parts['query'] = '';
        }
        $parts['query'] .= 'format=partial';
      }

      // Finish rebuilding the URL.
      if (isset($parts['query'])) {
        $url .= '?' . $parts['query'];
      }
      if (isset($parts['fragment'])) {
        $url .= '#' . $parts['fragment'];
      }

      // If the varnish module is enabled, and the SSI is for a URL on our server, do an ESI.
      if (\Drupal::moduleHandler()->moduleExists('varnish_purge') &&
        isset($_SERVER['HTTP_X_VARNISH']) &&
        gethostbyname($parts['host']) == gethostbyname($_SERVER['SERVER_NAME']) &&
        $parts['scheme'] == 'http'
      ) {
        $content = $this->toESI($url);
      }
      // Otherwise, emulate the SSI in drupal.
      else {
        $content = $this->emulateSSI($url);
      }

      $replacements[$full_match] = PHP_EOL
        . '<!-- Begin content from ' . $url . ' -->' . PHP_EOL
        . $content . PHP_EOL
        . '<!-- End content from ' . $url . ' -->' . PHP_EOL;
    }

    foreach ($replacements as $from => $to) {
      $text = str_replace($from, $to, $text);
    }

    $result = new FilterProcessResult($text);

    return $result;
  }

  /**
   * Emulate the SSI process inside Drupal.
   *
   * @param string $url
   *
   * @return string
   */
  public function emulateSSI($url) {
    $ssiDepth = 0;
    if (array_key_exists('HTTP_X_UNL_SSI_DEPTH', $_SERVER)) {
      $ssiDepth = $_SERVER['HTTP_X_UNL_SSI_DEPTH'];
    }
    $ssiDepth++;

    $context = stream_context_create(array(
      'http' => array(
        'header' => "x-unl-ssi-depth: $ssiDepth\r\n",
      ),
    ));

    if ($ssiDepth > 3) {
      watchdog('unl', 'Server Side Include: Recursion depth limit reached.', array(), WATCHDOG_ERROR);
      drupal_add_http_header('x-unl-ssi-error', 'Too deep!');
      $content = '<!-- Error: Too many recursive includes! Content from ' . $url . ' was not included! -->';
    }
    else {
      $headers = array();
      $content = $this->urlGetContents($url, $context, $headers);
      if (array_key_exists('x-unl-ssi-error', $headers)) {
        watchdog('unl', 'Server Side Include: An included URL reached the depth limit.', array(), WATCHDOG_WARNING);
        drupal_add_http_header('x-unl-ssi-error', 'The included URL caused recursion that was too deep!');
      }
    }

    return $content;
  }

  /**
   * Change the SSI into an ESI.
   *
   * @param string $url
   *
   * @return string
   */
  public function toESI($url) {
    // Set a header so that Varnish knows to do ESI processing on this response.
    drupal_add_http_header('X-ESI', 'yes');
    return '<esi:include src="' . check_plain($url) . '"/>';
  }

  /**
   * Fetch the contents at the given URL and cache the result using
   * Drupal's cache for as long as the response headers allow.
   *
   * @param string $url
   * @param resource $context
   *
   * @return string
   */
  public function urlGetContents($url, $context = NULL, &$headers = array()) {
//    unl_load_zend_framework();
//    if (!Zend_Uri::check($url)) {
//      watchdog('unl', 'A non-url was passed to %func().', array('%func' => __FUNCTION__), WATCHDOG_WARNING);
//      return FALSE;
//    }

    // get some per-request static storage
    $static = &drupal_static(__FUNCTION__);
    if (!isset($static)) {
      $static = array();
    }

    // If cached in the static array, return it.
    if (array_key_exists($url, $static)) {
      $headers = $static[$url]['headers'];

      // Don't let this page be cached since it contains uncacheable content.
      $GLOBALS['conf']['cache'] = FALSE;

      return $static[$url]['body'];
    }

    // If cached in the drupal cache, return it.
    $data = \Drupal::cache()->get((__FUNCTION__ . $url));
    if ($data && time() < $data->data['expires']) {
      $headers = $data->data['headers'];

      // Don't let this page be cached any longer than the retrieved content.
      $GLOBALS['conf']['page_cache_maximum_age'] = min(variable_get('page_cache_maximum_age', 0), $data->data['expires'] - time());

      return $data->data['body'];
    }

    if (!$context) {
      // Set a 5 second timeout
      $context = stream_context_create(array('http' => array('timeout' => 5)));
    }

    // Make the request
    $http_response_header = array();
    $body = file_get_contents($url, NULL, $context);

    // If an error occured, just return it now.
    if ($body === FALSE) {
      $static[$url] = $body;
      return $body;
    }

    $headers = array();
    foreach ($http_response_header as $rawHeader) {
      $headerName = trim(substr($rawHeader, 0, strpos($rawHeader, ':')));
      $headerValue = trim(substr($rawHeader, strpos($rawHeader, ':') + 1));
      if ($headerName && $headerValue) {
        $headers[$headerName] = $headerValue;
      }
    }

    $lowercaseHeaders = array_change_key_case($headers);

    $cacheable = NULL;
    $expires = 0;

    // Check for a Cache-Control header and the max-age and/or private headers.
    if (array_key_exists('cache-control', $lowercaseHeaders)) {
      $cacheControl = strtolower($lowercaseHeaders['cache-control']);
      $matches = array();
      if (preg_match('/max-age=([0-9]+)/', $cacheControl, $matches)) {
        $expires = time() + $matches[1];
        if (array_key_exists('age', $lowercaseHeaders)) {
          $expires -= $lowercaseHeaders['age'];
        }

        if ($expires > time()) {
          $cacheable = TRUE;
        }
      }
      if (strpos($cacheControl, 'private') !== FALSE) {
        $cacheable = FALSE;
      }
      if (strpos($cacheControl, 'no-cache') !== FALSE) {
        $cacheable = FALSE;
      }
    }
    // If there was no Cache-Control header, or if it wasn't helpful, check for an Expires header.
    if ($cacheable === NULL && array_key_exists('expires', $lowercaseHeaders)) {
      $cacheable = TRUE;
      $expires = DateTime::createFromFormat(DateTime::RFC1123, $lowercaseHeaders['expires'])->getTimestamp();
    }

    // Save to the drupal cache if caching is ok
    if ($cacheable && time() < $expires) {
      $data = array(
        'body' => $body,
        'headers' => $headers,
        'expires' => $expires,
      );
      cache_set(__FUNCTION__ . $url, $data, 'cache', $expires);

      // Don't let this page be cached any longer than the retrieved content.
      $GLOBALS['conf']['page_cache_maximum_age'] = min(variable_get('page_cache_maximum_age', 0), $expires - time());
    }
    // Otherwise just save to the static per-request cache
    else {
      $static[$url] = array(
        'body' => $body,
        'headers' => $headers,
      );

      // Don't let this page be cached since it contains uncacheable content.
      $GLOBALS['conf']['cache'] = FALSE;
    }

    return $body;
  }
}
