<?php
// qrequest.php -- HotCRP helper class for request objects (no warnings)
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class Qrequest implements ArrayAccess, IteratorAggregate, Countable, JsonSerializable {
    /** @var ?Conf */
    private $_conf;
    /** @var ?Contact */
    private $_user;
    /** @var ?NavigationState */
    private $_navigation;
    /** @var ?string */
    private $_page;
    /** @var ?string */
    private $_path;
    /** @var string */
    private $_method;
    /** @var ?array<string,string> */
    private $_headers;
    /** @var int */
    private $_body_type = 0;
    /** @var ?string */
    private $_body;
    /** @var ?string */
    private $_body_filename;
    /** @var array<string,string> */
    private $_v;
    /** @var array<string,list> */
    private $_a = [];
    /** @var array<string,QrequestFile> */
    private $_files = [];
    private $_annexes = [];
    /** @var bool */
    private $_post_ok = false;
    /** @var bool */
    private $_post_empty = false;
    /** @var ?string */
    private $_referrer;
    /** @var null|false|SessionList */
    private $_active_list = false;
    /** @var Qsession */
    private $_qsession;

    /** @var Qrequest */
    static public $main_request;

    const ARRAY_MARKER = "__array__";
    const BODY_NONE = 0;
    const BODY_INPUT = 1;
    const BODY_SET = 2;

    /** @param string $method
     * @param array<string,string> $data */
    function __construct($method, $data = []) {
        $this->_method = $method;
        $this->_v = $data;
        $this->_qsession = new Qsession;
    }

    /** @param NavigationState $nav
     * @return $this */
    function set_navigation($nav) {
        $this->_navigation = $nav;
        $this->_page = $nav->page;
        $this->_path = $nav->path;
        return $this;
    }

    /** @param string $page
     * @param ?string $path
     * @return $this */
    function set_page($page, $path = null) {
        $this->_page = $page;
        $this->_path = $path;
        return $this;
    }

    /** @param ?string $referrer
     * @return $this */
    function set_referrer($referrer) {
        $this->_referrer = $referrer;
        return $this;
    }

    /** @param Conf $conf
     * @return $this */
    function set_conf($conf) {
        assert(!$this->_conf || $this->_conf === $conf);
        $this->_conf = $conf;
        return $this;
    }

    /** @param ?Contact $user
     * @return $this */
    function set_user($user) {
        assert(!$user || !$this->_conf || $this->_conf === $user->conf);
        if ($user) {
            $this->_conf = $user->conf;
        }
        $this->_user = $user;
        return $this;
    }

    /** @return $this */
    function set_qsession(Qsession $qsession) {
        $this->_qsession = $qsession;
        return $this;
    }

    /** @return string */
    function method() {
        return $this->_method;
    }
    /** @return bool */
    function is_get() {
        return $this->_method === "GET";
    }
    /** @return bool */
    function is_post() {
        return $this->_method === "POST";
    }
    /** @return bool */
    function is_head() {
        return $this->_method === "HEAD";
    }

    /** @return Conf */
    function conf() {
        return $this->_conf;
    }
    /** @return ?Contact */
    function user() {
        return $this->_user;
    }
    /** @return NavigationState */
    function navigation() {
        return $this->_navigation;
    }
    /** @return Qsession */
    function qsession() {
        return $this->_qsession;
    }

    /** @return ?string */
    function page() {
        return $this->_page;
    }
    /** @return ?string */
    function path() {
        return $this->_path;
    }
    /** @param int $n
     * @return ?string */
    function path_component($n, $decoded = false) {
        if ((string) $this->_path !== "") {
            $p = explode("/", substr($this->_path, 1));
            if ($n + 1 < count($p)
                || ($n + 1 === count($p) && $p[$n] !== "")) {
                return $decoded ? urldecode($p[$n]) : $p[$n];
            }
        }
        return null;
    }

    /** @return ?string */
    function referrer() {
        return $this->_referrer;
    }

    /** @param string $k
     * @return ?string */
    function header($k) {
        return $this->_headers["HTTP_" . strtoupper(str_replace("-", "_", $k))] ?? null;
    }

    /** @param string $k
     * @param ?string $v */
    function set_header($k, $v) {
        $this->_headers["HTTP_" . strtoupper(str_replace("-", "_", $k))] = $v;
    }

    /** @return ?string */
    function body() {
        if ($this->_body === null && $this->_body_type === self::BODY_INPUT) {
            $this->_body = file_get_contents("php://input");
        }
        return $this->_body;
    }

    /** @param ?string $extension
     * @return ?string */
    function body_filename($extension = null) {
        if ($this->_body_filename === null && $this->_body_type !== self::BODY_NONE) {
            if (!($tmpdir = tempdir())) {
                return null;
            }
            $extension = $extension ?? Mimetype::extension($this->header("Content-Type"));
            $fn = $tmpdir . "/" . strtolower(encode_token(random_bytes(6))) . $extension;
            if ($this->_body_type === self::BODY_INPUT) {
                $ok = copy("php://input", $fn);
            } else {
                $ok = file_put_contents($this->_body, $fn) === strlen($this->_body);
            }
            if ($ok) {
                $this->_body_filename = $fn;
            }
        }
        return $this->_body_filename;
    }

    /** @return ?string */
    function body_content_type() {
        if ($this->_body_type === self::BODY_NONE) {
            return null;
        } else if (($ct = $this->header("Content-Type"))) {
            return Mimetype::type($ct);
        }
        $b = $this->_body;
        if ($b === null && $this->_body_type === self::BODY_INPUT) {
            $b = file_get_contents("php://input", false, null, 0, 4096);
        }
        $b = (string) $b;
        if (str_starts_with($b, "\x50\x4B\x03\x04")) {
            return "application/zip";
        } else if (preg_match('/\A\s*[\[\{]/s', $b)) {
            return "application/json";
        } else {
            return null;
        }
    }

    /** @param string $body
     * @param ?string $content_type
     * @return $this */
    function set_body($body, $content_type = null) {
        $this->_body_type = self::BODY_SET;
        $this->_body_filename = null;
        $this->_body = $body;
        if ($content_type !== null) {
            $this->set_header("Content-Type", $content_type);
        }
        return $this;
    }

    #[\ReturnTypeWillChange]
    function offsetExists($offset) {
        return array_key_exists($offset, $this->_v);
    }
    #[\ReturnTypeWillChange]
    function offsetGet($offset) {
        return $this->_v[$offset] ?? null;
    }
    #[\ReturnTypeWillChange]
    function offsetSet($offset, $value) {
        if (is_array($value)) {
            error_log("array offsetSet at " . debug_string_backtrace());
        }
        $this->_v[$offset] = $value;
        unset($this->_a[$offset]);
    }
    #[\ReturnTypeWillChange]
    function offsetUnset($offset) {
        unset($this->_v[$offset]);
        unset($this->_a[$offset]);
    }
    #[\ReturnTypeWillChange]
    /** @return Iterator<string,mixed> */
    function getIterator() {
        return new ArrayIterator($this->as_array());
    }
    /** @param string $name
     * @param int|float|string $value
     * @return void */
    function __set($name, $value) {
        if (is_array($value)) {
            error_log("array __set at " . debug_string_backtrace());
        }
        $this->_v[$name] = $value;
        unset($this->_a[$name]);
    }
    /** @param string $name
     * @return ?string */
    function __get($name) {
        return $this->_v[$name] ?? null;
    }
    /** @param string $name
     * @return bool */
    function __isset($name) {
        return isset($this->_v[$name]);
    }
    /** @param string $name */
    function __unset($name) {
        unset($this->_v[$name]);
        unset($this->_a[$name]);
    }
    /** @param string $name
     * @return bool */
    function has($name) {
        return array_key_exists($name, $this->_v);
    }
    /** @param string $name
     * @return ?string */
    function get($name) {
        return $this->_v[$name] ?? null;
    }
    /** @param string $name
     * @param string $value */
    function set($name, $value) {
        $this->_v[$name] = $value;
        unset($this->_a[$name]);
    }
    /** @param string $name
     * @return bool */
    function has_a($name) {
        return isset($this->_a[$name]);
    }
    /** @param string $name
     * @return ?list */
    function get_a($name) {
        return $this->_a[$name] ?? null;
    }
    /** @param string $name
     * @param list $value */
    function set_a($name, $value) {
        $this->_v[$name] = self::ARRAY_MARKER;
        $this->_a[$name] = $value;
    }
    /** @return $this */
    function set_req($name, $value) {
        if (is_array($value)) {
            $this->_v[$name] = self::ARRAY_MARKER;
            $this->_a[$name] = $value;
        } else {
            $this->_v[$name] = $value;
            unset($this->_a[$name]);
        }
        return $this;
    }
    #[\ReturnTypeWillChange]
    /** @return int */
    function count() {
        return count($this->_v);
    }
    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        return $this->as_array();
    }
    /** @return array<string,mixed> */
    function as_array() {
        return $this->_v;
    }
    /** @param string ...$keys
     * @return array<string,mixed> */
    function subset_as_array(...$keys) {
        $d = [];
        foreach ($keys as $k) {
            if (array_key_exists($k, $this->_v))
                $d[$k] = $this->_v[$k];
        }
        return $d;
    }
    /** @return object */
    function as_object() {
        return (object) $this->as_array();
    }
    /** @return list<string> */
    function keys() {
        return array_keys($this->_v);
    }
    /** @param string $key
     * @return bool */
    function contains($key) {
        return array_key_exists($key, $this->_v);
    }
    /** @param string $name
     * @param array|QrequestFile $finfo
     * @return $this */
    function set_file($name, $finfo) {
        if (is_array($finfo)) {
            $this->_files[$name] = new QrequestFile($finfo);
        } else {
            $this->_files[$name] = $finfo;
        }
        return $this;
    }
    /** @param string $name
     * @param string $content
     * @param ?string $filename
     * @param ?string $mimetype
     * @return $this */
    function set_file_content($name, $content, $filename = null, $mimetype = null) {
        $this->_files[$name] = new QrequestFile([
            "name" => $filename ?? "__set_file_content.{$name}",
            "type" => $mimetype,
            "size" => strlen($content),
            "content" => $content
        ]);
        return $this;
    }
    /** @return bool */
    function has_files() {
        return !empty($this->_files);
    }
    /** @param string $name
     * @return bool */
    function has_file($name) {
        return isset($this->_files[$name]);
    }
    /** @param string $name
     * @return ?QrequestFile */
    function file($name) {
        return $this->_files[$name] ?? null;
    }
    /** @param string $name
     * @return string|false */
    function file_filename($name) {
        $fn = false;
        if (array_key_exists($name, $this->_files)) {
            $fn = $this->_files[$name]->name;
        }
        return $fn;
    }
    /** @param string $name
     * @return int|false */
    function file_size($name) {
        $sz = false;
        if (array_key_exists($name, $this->_files)) {
            $sz = $this->_files[$name]->size;
        }
        return $sz;
    }
    /** @param string $name
     * @param int $offset
     * @param ?int $maxlen
     * @return string|false */
    function file_contents($name, $offset = 0, $maxlen = null) {
        $data = false;
        if (array_key_exists($name, $this->_files)) {
            $finfo = $this->_files[$name];
            if (isset($finfo->content)) {
                $data = substr($finfo->content, $offset, $maxlen ?? PHP_INT_MAX);
            } else if ($maxlen === null) {
                $data = @file_get_contents($finfo->tmp_name, false, null, $offset);
            } else {
                $data = @file_get_contents($finfo->tmp_name, false, null, $offset, $maxlen);
            }
        }
        return $data;
    }
    function files() {
        return $this->_files;
    }
    /** @return bool */
    function has_annexes() {
        return !empty($this->_annexes);
    }
    /** @return array<string,mixed> */
    function annexes() {
        return $this->_annexes;
    }
    /** @param string $name
     * @return bool */
    function has_annex($name) {
        return isset($this->_annexes[$name]);
    }
    /** @param string $name */
    function annex($name) {
        return $this->_annexes[$name] ?? null;
    }
    /** @template T
     * @param string $name
     * @param class-string<T> $class
     * @return T */
    function checked_annex($name, $class) {
        $x = $this->_annexes[$name] ?? null;
        if (!$x || !($x instanceof $class)) {
            throw new Exception("Bad annex $name");
        }
        return $x;
    }
    /** @param string $name */
    function set_annex($name, $x) {
        $this->_annexes[$name] = $x;
    }
    /** @return $this */
    function approve_token() {
        $this->_post_ok = true;
        return $this;
    }
    /** @return bool */
    function valid_token() {
        return $this->_post_ok;
    }
    /** @return bool */
    function valid_post() {
        return $this->_post_ok && $this->_method === "POST";
    }
    /** @return void */
    function set_post_empty() {
        $this->_post_empty = true;
    }
    /** @return bool */
    function post_empty() {
        return $this->_post_empty;
    }

    /** @param string $e
     * @return ?bool */
    function xt_allow($e) {
        if ($e === "post") {
            return $this->_post_ok && $this->_method === "POST";
        } else if ($e === "anypost") {
            return $this->_method === "POST";
        } else if ($e === "getpost") {
            return in_array($this->_method, ["POST", "GET", "HEAD"]) && $this->_post_ok;
        } else if ($e === "get") {
            return $this->_method === "GET";
        } else if ($e === "head") {
            return $this->_method === "HEAD";
        } else if (str_starts_with($e, "req.")) {
            return $this->has(substr($e, 4));
        } else {
            return null;
        }
    }

    /** @param ?NavigationState $nav */
    static function make_minimal($nav = null) : Qrequest {
        $qreq = new Qrequest($_SERVER["REQUEST_METHOD"]);
        $qreq->set_navigation($nav ?? Navigation::get());
        if (array_key_exists("post", $_GET)) {
            $qreq->set_req("post", $_GET["post"]);
        }
        return $qreq;
    }

    /** @param ?NavigationState $nav */
    static function make_global($nav = null) : Qrequest {
        $qreq = self::make_minimal($nav);
        foreach ($_GET as $k => $v) {
            $qreq->set_req($k, $v);
        }
        foreach ($_POST as $k => $v) {
            $qreq->set_req($k, $v);
        }
        if (empty($_POST)) {
            $qreq->set_post_empty();
        }
        $qreq->_headers = $_SERVER;
        if (isset($_SERVER["HTTP_REFERER"])) {
            $qreq->set_referrer($_SERVER["HTTP_REFERER"]);
        }
        $qreq->_body_type = empty($_POST) ? self::BODY_INPUT : self::BODY_NONE;

        // $_FILES requires special processing since we want error messages.
        $errors = [];
        $too_big = false;
        foreach ($_FILES as $nx => $fix) {
            if (is_array($fix["error"])) {
                $fis = [];
                foreach (array_keys($fix["error"]) as $i) {
                    $fis[$i ? "$nx.$i" : $nx] = ["name" => $fix["name"][$i], "type" => $fix["type"][$i], "size" => $fix["size"][$i], "tmp_name" => $fix["tmp_name"][$i], "error" => $fix["error"][$i]];
                }
            } else {
                $fis = [$nx => $fix];
            }
            foreach ($fis as $n => $fi) {
                if ($fi["error"] == UPLOAD_ERR_OK) {
                    if (is_uploaded_file($fi["tmp_name"])) {
                        $qreq->set_file($n, $fi);
                    }
                } else if ($fi["error"] != UPLOAD_ERR_NO_FILE) {
                    if ($fi["error"] == UPLOAD_ERR_INI_SIZE
                        || $fi["error"] == UPLOAD_ERR_FORM_SIZE) {
                        $errors[] = $e = MessageItem::error("Uploaded file too large");
                        if (!$too_big) {
                            $errors[] = MessageItem::inform("The maximum upload size is " . ini_get("upload_max_filesie") . "B.");
                            $too_big = true;
                        }
                    } else if ($fi["error"] == UPLOAD_ERR_PARTIAL) {
                        $errors[] = $e = MessageItem::error("File upload interrupted");
                    } else {
                        $errors[] = $e = MessageItem::error("Error uploading file");
                    }
                    $e->landmark = $fi["name"] ?? null;
                }
            }
        }
        if (!empty($errors)) {
            $qreq->set_annex("upload_errors", $errors);
        }

        return $qreq;
    }

    /** @return Qrequest */
    static function set_main_request(Qrequest $qreq) {
        global $Qreq;
        Qrequest::$main_request = $Qreq = $qreq;
        return $qreq;
    }


    /** @param string $name
     * @param string $value
     * @param array $opt */
    function set_cookie_opt($name, $value, $opt) {
        $opt["path"] = $opt["path"] ?? $this->_navigation->base_path;
        $opt["domain"] = $opt["domain"] ?? $this->_conf->opt("sessionDomain") ?? "";
        $opt["secure"] = $opt["secure"] ?? $this->_conf->opt("sessionSecure") ?? false;
        if (!isset($opt["samesite"])) {
            $samesite = $this->_conf->opt("sessionSameSite") ?? "Lax";
            if ($samesite && ($opt["secure"] || $samesite !== "None")) {
                $opt["samesite"] = $samesite;
            }
        }
        if (!hotcrp_setcookie($name, $value, $opt)) {
            error_log(debug_string_backtrace());
        }
    }

    /** @param string $name
     * @param string $value
     * @param int $expires_at */
    function set_cookie($name, $value, $expires_at) {
        $this->set_cookie_opt($name, $value, ["expires" => $expires_at]);
    }

    /** @param string $name
     * @param string $value
     * @param int $expires_at */
    function set_httponly_cookie($name, $value, $expires_at) {
        $this->set_cookie_opt($name, $value, ["expires" => $expires_at, "httponly" => true]);
    }


    /** @param string|list<string> $title
     * @param string $id
     * @param array{paperId?:int|string,body_class?:string,action_bar?:string,title_div?:string,subtitle?:string,save_messages?:bool,hide_title?:bool,hide_header?:bool} $extra */
    function print_header($title, $id, $extra = []) {
        if (!$this->_conf->_header_printed) {
            $this->_conf->print_head_tag($this, $title, $extra);
            $this->_conf->print_body_entry($this, $title, $id, $extra);
        }
    }

    function print_footer() {
	$socials = [
		['facebook', 'https://www.facebook.com/uni.lu', '<svg role="img" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><title>Facebook</title><path d="M9.101 23.691v-7.98H6.627v-3.667h2.474v-1.58c0-4.085 1.848-5.978 5.858-5.978.401 0 .955.042 1.468.103a8.68 8.68 0 0 1 1.141.195v3.325a8.623 8.623 0 0 0-.653-.036 26.805 26.805 0 0 0-.733-.009c-.707 0-1.259.096-1.675.309a1.686 1.686 0 0 0-.679.622c-.258.42-.374.995-.374 1.752v1.297h3.919l-.386 2.103-.287 1.564h-3.246v8.245C19.396 23.238 24 18.179 24 12.044c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.628 3.874 10.35 9.101 11.647Z"/></svg>'],
		['linkedin', 'https://www.linkedin.com/school/university-of-luxembourg/', '<svg role="img" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><title>LinkedIn</title><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>'],
		['instagram', 'https://www.instagram.com/uni.lu/', '<svg role="img" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><title>Instagram</title><path d="M7.0301.084c-1.2768.0602-2.1487.264-2.911.5634-.7888.3075-1.4575.72-2.1228 1.3877-.6652.6677-1.075 1.3368-1.3802 2.127-.2954.7638-.4956 1.6365-.552 2.914-.0564 1.2775-.0689 1.6882-.0626 4.947.0062 3.2586.0206 3.6671.0825 4.9473.061 1.2765.264 2.1482.5635 2.9107.308.7889.72 1.4573 1.388 2.1228.6679.6655 1.3365 1.0743 2.1285 1.38.7632.295 1.6361.4961 2.9134.552 1.2773.056 1.6884.069 4.9462.0627 3.2578-.0062 3.668-.0207 4.9478-.0814 1.28-.0607 2.147-.2652 2.9098-.5633.7889-.3086 1.4578-.72 2.1228-1.3881.665-.6682 1.0745-1.3378 1.3795-2.1284.2957-.7632.4966-1.636.552-2.9124.056-1.2809.0692-1.6898.063-4.948-.0063-3.2583-.021-3.6668-.0817-4.9465-.0607-1.2797-.264-2.1487-.5633-2.9117-.3084-.7889-.72-1.4568-1.3876-2.1228C21.2982 1.33 20.628.9208 19.8378.6165 19.074.321 18.2017.1197 16.9244.0645 15.6471.0093 15.236-.005 11.977.0014 8.718.0076 8.31.0215 7.0301.0839m.1402 21.6932c-1.17-.0509-1.8053-.2453-2.2287-.408-.5606-.216-.96-.4771-1.3819-.895-.422-.4178-.6811-.8186-.9-1.378-.1644-.4234-.3624-1.058-.4171-2.228-.0595-1.2645-.072-1.6442-.079-4.848-.007-3.2037.0053-3.583.0607-4.848.05-1.169.2456-1.805.408-2.2282.216-.5613.4762-.96.895-1.3816.4188-.4217.8184-.6814 1.3783-.9003.423-.1651 1.0575-.3614 2.227-.4171 1.2655-.06 1.6447-.072 4.848-.079 3.2033-.007 3.5835.005 4.8495.0608 1.169.0508 1.8053.2445 2.228.408.5608.216.96.4754 1.3816.895.4217.4194.6816.8176.9005 1.3787.1653.4217.3617 1.056.4169 2.2263.0602 1.2655.0739 1.645.0796 4.848.0058 3.203-.0055 3.5834-.061 4.848-.051 1.17-.245 1.8055-.408 2.2294-.216.5604-.4763.96-.8954 1.3814-.419.4215-.8181.6811-1.3783.9-.4224.1649-1.0577.3617-2.2262.4174-1.2656.0595-1.6448.072-4.8493.079-3.2045.007-3.5825-.006-4.848-.0608M16.953 5.5864A1.44 1.44 0 1 0 18.39 4.144a1.44 1.44 0 0 0-1.437 1.4424M5.8385 12.012c.0067 3.4032 2.7706 6.1557 6.173 6.1493 3.4026-.0065 6.157-2.7701 6.1506-6.1733-.0065-3.4032-2.771-6.1565-6.174-6.1498-3.403.0067-6.156 2.771-6.1496 6.1738M8 12.0077a4 4 0 1 1 4.008 3.9921A3.9996 3.9996 0 0 1 8 12.0077"/></svg>'],
		['youtube', 'https://www.youtube.com/user/luxuni', '<svg role="img" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><title>YouTube</title><path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>']
	];

	$socials_text = '';
	foreach ($socials as $social) {
		$socials_text .= "<a href=\"{$social[1]}\" target=\"_blank\" rel=\"noopener\">{$social[2]}</a>";
	}

	
        echo '<hr class="c"></div>', // close #p-body
            '</div>',                // close #p-page
            '<div class="footer-text">',
	    '<div class="footer-legal">Copyright © <a href="https://uni.lu">Université du Luxembourg</a> 2024. All rights reserved.</div>',
	    '<div class="footer-socials">', $socials_text, '</div>',
	    '</div>',
	    '<div id="p-footer" class="need-banner-offset banner-bottom">',
            $this->_conf->opt("extraFooter") ?? "",
            '<a class="noq" href="https://hotcrp.com/">HotCRP</a>';
        if (!$this->_conf->opt("noFooterVersion")) {
            if ($this->_user && $this->_user->privChair) {
                echo " v", HOTCRP_VERSION, " [";
                if (($git_data = Conf::git_status())
                    && $git_data[0] !== $git_data[1]) {
                    echo substr($git_data[0], 0, 7), "... ";
                }
                echo round(memory_get_peak_usage() / (1 << 20)), "M]";
            } else {
                echo "<!-- Version ", HOTCRP_VERSION, " -->";
            }
	}
	echo ' <a class="noq" href="https://github.com/kongr45gpen/hotcrp">forked</a>';
        echo '</div>', Ht::unstash(), "</body>\n</html>\n";
    }

    static function print_footer_hook(Contact $user, Qrequest $qreq) {
        $qreq->print_footer();
    }


    /** @return bool */
    function has_active_list() {
        return !!$this->_active_list;
    }

    /** @return ?SessionList */
    function active_list() {
        if ($this->_active_list === false) {
            $this->_active_list = null;
        }
        return $this->_active_list;
    }

    function set_active_list(SessionList $list = null) {
        assert($this->_active_list === false);
        $this->_active_list = $list;
    }


    /** @return void */
    function open_session() {
        $this->_qsession->open();
    }

    /** @return ?string */
    function qsid() {
        return $this->_qsession->sid;
    }

    /** @param string $key
     * @return bool */
    function has_gsession($key) {
        return $this->_qsession->has($key);
    }

    function clear_gsession() {
        $this->_qsession->clear();
    }

    /** @param string $key
     * @return mixed */
    function gsession($key) {
        return $this->_qsession->get($key);
    }

    /** @param string $key
     * @param mixed $value */
    function set_gsession($key, $value) {
        $this->_qsession->set($key, $value);
    }

    /** @param string $key */
    function unset_gsession($key) {
        $this->_qsession->unset($key);
    }

    /** @param string $key
     * @return bool */
    function has_csession($key) {
        return $this->_conf
            && $this->_conf->session_key !== null
            && $this->_qsession->has2($this->_conf->session_key, $key);
    }

    /** @param string $key
     * @return mixed */
    function csession($key) {
        if ($this->_conf && $this->_conf->session_key !== null) {
            return $this->_qsession->get2($this->_conf->session_key, $key);
        } else {
            return null;
        }
    }

    /** @param string $key
     * @param mixed $value */
    function set_csession($key, $value) {
        if ($this->_conf && $this->_conf->session_key !== null) {
            $this->_qsession->set2($this->_conf->session_key, $key, $value);
        }
    }

    /** @param string $key */
    function unset_csession($key) {
        if ($this->_conf && $this->_conf->session_key !== null) {
            $this->_qsession->unset2($this->_conf->session_key, $key);
        }
    }

    /** @return string */
    function post_value() {
        if ($this->_qsession->sid === null) {
            $this->_qsession->open();
        }
        return $this->maybe_post_value();
    }

    /** @return string */
    function maybe_post_value() {
        $sid = $this->_qsession->sid ?? "";
        if ($sid !== "") {
            return urlencode(substr($sid, strlen($sid) > 16 ? 8 : 0, 12));
        } else {
            return ".empty";
        }
    }
}

class QrequestFile {
    /** @var string */
    public $name;
    /** @var string */
    public $type;
    /** @var int */
    public $size;
    /** @var ?string */
    public $tmp_name;
    /** @var ?string */
    public $content;
    /** @var int */
    public $error;

    /** @param array{name?:string,type?:string,size?:int,tmp_name?:?string,content?:?string,error?:int} $a */
    function __construct($a) {
        $this->name = $a["name"] ?? "";
        $this->type = $a["type"] ?? "application/octet-stream";
        $this->size = $a["size"] ?? 0;
        $this->tmp_name = $a["tmp_name"] ?? null;
        $this->content = $a["content"] ?? null;
        $this->error = $a["error"] ?? 0;
    }

    /** @return array{name:string,type:string,size:int,tmp_name?:string,content?:string,error:int}
     * @deprecated */
    function as_array() {
        $a = ["name" => $this->name, "type" => $this->type, "size" => $this->size];
        if ($this->tmp_name !== null) {
            $a["tmp_name"] = $this->tmp_name;
        }
        if ($this->content !== null) {
            $a["content"] = $this->content;
        }
        $a["error"] = $this->error;
        return $a;
    }
}
