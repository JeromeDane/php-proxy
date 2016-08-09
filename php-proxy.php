<?php

class PHPProxy {

  private $cookieFile;
  public $result;
  private $useragent = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_11_5) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/51.0.2704.103 Safari/537.36';
  private $css;

  public function __construct($opts = array()) {

    $this->cookieFile = sys_get_temp_dir().'/.php-proxy-cookies-'.substr(hash('sha256', __FILE__), 0, 7);

    if(isset($opts['cookiefile'])) {
      $this->cookieFile = $opts['cookiefile'];
    }
    if(isset($opts['css'])) {
      $this->css = $opts['css'];
    }
    if(isset($opts['useragent'])) {
      $this->useragent = $opts['useragent'];
    }
  }

  public function request($opts) {

    if(!$opts['url']) {
      throw new Exception('No "url" option supplied');
    }

    $curlOptions = array(
        CURLOPT_RETURNTRANSFER => true,     // return web page
        CURLOPT_HEADER => true,     // return headers in addition to content
        CURLOPT_FOLLOWLOCATION => true,     // follow redirects
        CURLOPT_ENCODING => "",       // handle all encodings
        CURLOPT_AUTOREFERER => true,     // set referer on redirect
        CURLOPT_CONNECTTIMEOUT => 120,      // timeout on connect
        CURLOPT_TIMEOUT => 120,      // timeout on response
        CURLOPT_MAXREDIRS => 10,       // stop after 10 redirects
        CURLINFO_HEADER_OUT => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_COOKIEJAR => $this->cookieFile,
        CURLOPT_COOKIEFILE => $this->cookieFile,
        CURLOPT_USERAGENT => $this->useragent,
    );

    $ch = curl_init($opts['url']);
    curl_setopt_array($ch, $curlOptions);

    if($opts['method'] === 'post') {
      curl_setopt($ch, CURLOPT_POST, 1);
    }

    if($opts['data']) {
      curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['data']);
    }

    if($opts['referer']) {
      curl_setopt($ch, CURLOPT_REFERER, $opts['referer']);
    }

    $rough_content = curl_exec($ch);
    $err = curl_errno($ch);
    $errmsg = curl_error($ch);
    $header = curl_getinfo($ch);
    curl_close($ch);

    $header_content = substr($rough_content, 0, $header['header_size']);
    $body_content = trim(str_replace($header_content, '', $rough_content));
    $pattern = "#Set-Cookie:\\s+(?<cookie>[^=]+=[^;]+)#m";
    preg_match_all($pattern, $header_content, $matches);
    $cookiesOut = implode("; ", $matches['cookie']);

    $header['errno']   = $err;
    $header['errmsg']  = $errmsg;
    $header['headers']  = $header_content;
    $header['content'] = $body_content;
    $header['cookies'] = $cookiesOut;
    $this->result = $header;
    return $header;
  }

  public function proxy($baseUrl, $opts = array()) {
    $data = array();
    if(count($_POST)) {
      foreach($_POST as $k => $v) {
        if(is_array($v)) {
          $this->_stringifyPostField($v, $data, $k);
        } else {
          $data[$k] = $v;
        }
      }
    }
    if(isset($opts['data'])) {
      $data = array_merge($data, $opts['data']);
    }
    $this->request(array(
      'url' => preg_replace('/\/$/', '', $baseUrl).$_SERVER['REQUEST_URI'],
      'method' => $_SERVER['REQUEST_METHOD'],
      'data' => $data,
      'referer' => $opts['referer']
    ));
    return $this->result;
  }

  public function getContent() {
    return $this->result['content'];
  }
  public function setContent($content) {
    return $this->result['content'] = $content;
  }

  public function output() {
    // output relevant headers
    foreach(explode("\n", $this->result['headers']) as $header) {
      if(preg_match('/^(Status|HTTP|Content-Type)/i', trim($header))) {
        if(preg_match('/^HTTP.+(\d\d\d)/i', trim($header), $matches)) {
          header("Status: ".$matches[1]);
          header("HTTP/1.1 ".$matches[1]);
        } else {
          header($header);
        }
      }
    }
    // inject and CSS
    if(is_array($this->css)) {
      foreach($this->css as $cssPath) {
        $this->_injectCss($cssPath);
      }
    }
    if(is_string($this->css)) {
      $this->_injectCss($this->css);
    }
    echo $this->result['content'];
  }

  private function _injectCss($path) {
    $this->result['content'] = preg_replace('/<\/head>/i', '<link rel="stylesheet" type="text/css" href="'.$path.'">'."\n".'</head>', $this->result['content']);
  }

  private function _stringifyPostField($field, &$data, $previous) {
    foreach($field as $k => $v) {
      $newKey = $previous.'['.$k.']';
      if(is_array($v)) {
        $this->_stringifyPostField($v, $data, $newKey);
      } else {
        $data[$newKey] = $v;
      }
    }
    return $data;
  }
}
