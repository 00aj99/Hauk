<?php

// An include file containing constants and common functions for the Hauk
// backend. It loads the configuration file and declares it as a constant.

const BACKEND_VERSION = "1.2";

// Create mode for create.php. Corresponds with the constants from the Android
// app in android/app/src/main/java/info/varden/hauk/HaukConst.java.
const SHARE_MODE_CREATE_ALONE = 0;
const SHARE_MODE_CREATE_GROUP = 1;
const SHARE_MODE_JOIN_GROUP = 2;

// Type of share. This is not the same as the create mode above, which only
// indicates what type of session to create.
const SHARE_TYPE_ALONE = 0;
const SHARE_TYPE_GROUP = 1;

const SESSION_ID_SIZE = 32;
const LINK_ID_RAND_BYTES = 32;
const GROUP_PIN_MIN = 100000;
const GROUP_PIN_MAX = 999999;

const MEMCACHED = 0;
const REDIS = 1;

const KILOMETERS_PER_HOUR = array(
    // Relative distance per second multiplied by number of seconds per hour.
    "mpsMultiplier" => 3.6,
    "unit" => "km/h"
);
const MILES_PER_HOUR = array(
    // Same as for KILOMETERS_PER_HOUR, but convert kilometers to miles.
    "mpsMultiplier" => 3.6 * 0.6213712,
    "unit" => "mph"
);
const METERS_PER_SECOND = array(
    // Relative distance per second in kilometers, multiplied by meters per km.
    "mpsMultiplier" => 1,
    "unit" => "m/s"
);

include(__DIR__."/lang/en/texts.php");

const DEFAULTS = array(

    // This is the default config. This file is used as a fallback for missing
    // options in config.php.

    "storage_backend"       => MEMCACHED,
    "memcached_host"        => 'localhost',
    "memcached_port"        => 11211,
    "memcached_binary"      => false,
    "memcached_use_sasl"    => false,
    "memcached_sasl_user"   => "",
    "memcached_sasl_pass"   => "",
    "memcached_prefix"      => 'hauk',
    "redis_host"            => 'localhost',
    "redis_port"            => 6379,
    "redis_use_auth"        => false,
    "redis_auth"            => '',
    "redis_prefix"          => 'hauk',
    "password_hash"         => '$2y$10$4ZP1iY8A3dZygXoPgsXYV.S3gHzBbiT9nSfONjhWrvMxVPkcFq1Ka',
    "map_tile_uri"          => 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
    "map_attribution"       => 'Map data &copy; <a href="https://www.openstreetmap.org/">OpenStreetMap</a> contributors, <a href="https://creativecommons.org/licenses/by-sa/2.0/">CC-BY-SA</a>',
    "default_zoom"          => 14,
    "max_zoom"              => 19,
    "max_duration"          => 86400,
    "min_interval"          => 1,
    "offline_timeout"       => 30,
    "request_timeout"       => 10,
    "max_cached_pts"        => 3,
    "max_shown_pts"         => 100,
    "v_data_points"         => 2,
    "trail_color"           => '#d80037',
    "velocity_unit"         => KILOMETERS_PER_HOUR,
    "public_url"            => 'http://10.0.0.44/'

);

// Configuration can be stored either in /etc/hauk/config.php (e.g. Docker
// installations) or relative to this file as config.php. Only include the first
// one found from this list.
const CONFIG_PATHS = array(
    "/etc/hauk/config.php",
    __DIR__."/config.php"
);

foreach (CONFIG_PATHS as $path) {
    if (file_exists($path)) {
        include($path);
        break;
    }
}

if (!defined("CONFIG")) die($LANG['config_missing']."\n");

// Function that fetches the given config item from the config, or from the
// defaults as a fallback if not found in config.
function getConfig($item) {
    if (array_key_exists($item, CONFIG)) return CONFIG[$item];
    if (array_key_exists($item, DEFAULTS)) return DEFAULTS[$item];
}

define("PREFIX_SESSION", "-session-");
define("PREFIX_LOCDATA", "-locdata-");
define("PREFIX_GROUPID", "-groupid-");

// A base class for location shares. Shares contain a reference to all sessions
// that broadcasts location data to the share, but does not contain the location
// data itself. Location data is stored in the session data that the shares
// point to.
class Share {
    protected $memcache;    // A Memcached wrapper.
    protected $shareID;     // The ID displayed in the public view link.
    protected $shareData;   // An array containing the share's parameters/data.

    // Creates a Share instance from the given share ID. If not found, a share
    // will be returned whose ->exists() === false.
    public static function fromShareID($memcache, $shareID) {
        $shareData = $memcache->get(PREFIX_LOCDATA.$shareID);
        if ($shareData === false) return new Share($memcache, false);
        $share = null;
        switch ($shareData["type"]) {
            case SHARE_TYPE_ALONE:
                $share = new SoloShare($memcache, $shareData);
                break;
            case SHARE_TYPE_GROUP:
                $share = new GroupShare($memcache, $shareData);
                break;
        }
        $share->shareID = $shareID;
        return $share;
    }

    // Creates a Share instance from the given group PIN. If not found, returns
    // a share whose ->exists() === false.
    public static function fromGroupPIN($memcache, $pin) {
        $linkID = $memcache->get(PREFIX_GROUPID.$pin);
        if ($linkID === false) {
            return new GroupShare($memcache, false);
        } else {
            return self::fromShareID($memcache, $linkID);
        }
    }

    // For use by base classes only. Constructs a Share instance with the given
    // share data array, or if null, an empty share template.
    protected function __construct($memcache, $shareData = null) {
        $this->memcache = $memcache;
        if ($shareData === null) {
            $this->shareData = array(
                "expire" => 0
            );
            // Generate a new sharing link for our empty share.
            $this->shareID = $this->generateLinkID();
        } else {
            $this->shareData = $shareData;
        }
    }

    // Whether or not the share exists. Returns false if the share was not found
    // in Memcached. Returns true if the share is newly created but not saved in
    // Memcached yet.
    public function exists() {
        return $this->shareData !== false;
    }

    // Saves this share to Memcached.
    public function save() {
        if ($this->shareData["expire"] === 0) {
            throw new UnexpectedValueException("Share cannot be indefinite");
        }
        // If the share has already expired, don't share it; delete it if it
        // exists instead to clean up Memcached.
        if (!$this->hasExpired()) {
            $this->memcache->set(PREFIX_LOCDATA.$this->shareID, $this->shareData, $this->getExpirationTime());
        } else {
            $this->memcache->delete(PREFIX_LOCDATA.$this->shareID);
        }
        return $this;
    }

    // Returns the type of share, either SHARE_TYPE_ALONE or SHARE_TYPE_GROUP.
    public function getType() {
        return $this->shareData["type"];
    }

    // Returns the ID of the share, which is also used in the public view link.
    public function getShareID() {
        return $this->shareID;
    }

    // Returns a URL that can be used to track this share's participants.
    public function getViewLink() {
        return getConfig("public_url")."?".$this->shareID;
    }

    // Sets a new expiration time for the share. Does not take effect until
    // save() is called.
    public function setExpirationTime($expire) {
        $this->shareData["expire"] = $expire;
        return $this;
    }

    // Returns the current expiration time of this share.
    public function getExpirationTime() {
        return $this->shareData["expire"];
    }

    // Returns whether or not this share's expiration time is in the past.
    public function hasExpired() {
        return $this->getExpirationTime() <= time();
    }

    // Function for generating link IDs. Uses the first and last four digits of
    // the base36 SHA256 sum of random binary data. This is converted to
    // uppercase and the two parts separated by a dash.
    protected function generateLinkID() {
        $s = "";
        do {
            $s = strtoupper(base_convert(hash("sha256", openssl_random_pseudo_bytes(LINK_ID_RAND_BYTES)), 16, 36));
            $s = substr($s, 0, 4)."-".substr($s, -4);
        } while ($this->memcache->get(PREFIX_LOCDATA.$s) !== false);
        return $s;
    }
}

// An extension to the Share class for single-user shares. May be instantiated.
// Single-user shares have a single host user and may be adopted.
class SoloShare extends Share {

    // Creates a single-user share with the given share data array. The
    // $shareData argument is for internal use only. To instantiate a new
    // SoloShare, call this constructor with no share data.
    function __construct($memcache, $shareData = null) {
        parent::__construct($memcache, $shareData);
        if ($shareData === null) {
            $this->shareData["type"] = SHARE_TYPE_ALONE;
            $this->shareData["host"] = null;
            $this->shareData["adoptable"] = false;
        }
    }

    // Deletes this share from Memcached, causing it to immediately end.
    public function end() {
        $this->memcache->delete(PREFIX_LOCDATA.$this->getShareID());
    }

    // Sets whether or not this share can be adopted. Does not take effect until
    // save() is called.
    public function setAdoptable($adoptable) {
        $this->shareData["adoptable"] = $adoptable;
        return $this;
    }

    // Returns whether or not this share can be adopted by a group share.
    public function isAdoptable() {
        return $this->shareData["adoptable"];
    }

    // Sets the host session of this share. $host must be a Client instance.
    // Does not take effect until save() is called.
    public function setHost($host) {
        $this->shareData["host"] = $host->getSessionID();
        return $this;
    }

    // Returns the host user of this share as a Client instance. Callers should
    // verify that its ->exists() === true.
    public function getHost() {
        return new Client($this->memcache, $this->shareData["host"]);
    }
}

// An extension to the Share base class for group shares. Such shares can have
// multiple hosts, cannot be adopted, and have a group PIN. May be instantiated.
class GroupShare extends Share {

    // Creates a group share with the given share data array. The $shareData
    // argument is for internal use only. To instantiate a new GroupShare, call
    // this constructor with no share data.
    function __construct($memcache, $shareData = null) {
        parent::__construct($memcache, $shareData);
        if ($shareData === null) {
            $this->shareData["type"] = SHARE_TYPE_GROUP;
            $this->shareData["hosts"] = null;
            $this->shareData["groupPin"] = $this->generateGroupPIN();
        }
    }

    // Saves this share to Memcached.
    public function save() {
        parent::save();
        // Group shares must additionally save the group PIN to Memcached. This
        // is so that the correct share ID can be looked up when only the group
        // PIN is given.
        $this->memcache->set(PREFIX_GROUPID.$this->getGroupPIN(), $this->getShareID(), $this->getExpirationTime());
        return $this;
    }

    // Group shares can never be adopted.
    public function isAdoptable() {
        return false;
    }

    // Returns the group PIN of this share, used to add new participants.
    public function getGroupPIN() {
        return $this->shareData["groupPin"];
    }

    // Adds a new participant host to this group share with a given nickname.
    // The $host must be a Client instance. Does not take effect until save() is
    // called.
    public function addHost($nick, $host) {
        $this->shareData["hosts"][$nick] = $host->getSessionID();
        // If this new host has a later expiration time than the current time,
        // update that time so that the share doesn't end early.
        $this->setAutoExpirationTime();
        return $this;
    }

    // Removes non-existent host users from the share, and ends the share if no
    // hosts currently exist.
    public function clean() {
        $hosts = $this->getHosts();
        foreach ($hosts as $nick => $host) {
            if (!$host->exists() || $host->hasExpired()) {
                unset($this->shareData["hosts"][$nick]);
            }
        }
        if (empty($this->shareData["hosts"])) {
            $this->memcache->delete(PREFIX_LOCDATA.$this->getShareID());
            $this->memcache->delete(PREFIX_GROUPID.$this->getGroupPIN());
        } else {
            // After removing old hosts, the share may turn out to outlive all
            // of the sessions of the remaining hosts. Override the session
            // expiration and set it to the latest expiration time of all
            // remaining sessions.
            $this->setAutoExpirationTime()->save();
        }
    }

    // Returns a list of participants in this share, as a map of nicknames to
    // Client instances.
    public function getHosts() {
        $hosts = array();
        foreach ($this->shareData["hosts"] as $nick => $sessionID) {
            $hosts[$nick] = new Client($this->memcache, $sessionID);
        }
        return $hosts;
    }

    // Removes a host from the share. After calling, also call ->clean().
    public function removeHost($session) {
        while (($key = array_search($session->getSessionID(), $this->shareData["hosts"])) !== false) {
            unset($this->shareData["hosts"][$key]);
        }
        return $this;
    }

    // Sets the expiration time of this share to the latest expiration time of
    // all active contributing sessions.
    public function setAutoExpirationTime() {
        $this->shareData["expire"] = $this->getAutoExpirationTime();
        return $this;
    }

    // Determines the latest expiration time of all contributing sessions.
    public function getAutoExpirationTime() {
        $expire = 0;
        $hosts = $this->getHosts();
        foreach ($hosts as $nick => $host) {
            if ($host->exists() && $host->getExpirationTime() > $expire) {
                $expire = $host->getExpirationTime();
            }
        }
        return $expire;
    }

    // Determines the lowest share interval of all contributing sessions.
    public function getAutoInterval() {
        $interval = PHP_INT_MAX;
        $hosts = $this->getHosts();
        foreach ($hosts as $nick => $host) {
            if ($host->exists() && $host->getInterval() < $interval) {
                $interval = $host->getInterval();
            }
        }
        return $interval;
    }

    // Returns a map of nicknames and the users' corresponding coordinates.
    public function getAllPoints() {
        $points = array();
        $hosts = $this->getHosts();
        foreach ($hosts as $nick => $host) {
            if ($host->exists()) {
                $points[$nick] = $host->getPoints();
            }
        }
        return $points;
    }

    // Generates a new 6-digit group PIN.
    private function generateGroupPIN() {
        $pin = 0;
        do $pin = rand(GROUP_PIN_MIN, GROUP_PIN_MAX);
        while ($this->memcache->get(PREFIX_GROUPID.$pin) !== false);
        return $pin;
    }
}

// A class representing a sharing session. Each session represents one user, and
// contains all location data for that user.
class Client {
    private $memcache;      // A Memcached wrapper.
    private $sessionID;     // The hexadecimal ID of this session.
    private $sessionData;   // An array containing this session's data.

    // Creates a new Client instance. If $sid is provided, fetches the
    // corresponding session from Memcached. Otherwise, a new session is
    // created.
    function __construct($memcache, $sid = null) {
        $this->memcache = $memcache;
        if ($sid === null) {
            $this->sessionData = array(
                "expire" => 0,
                "interval" => null,
                "targets" => array(),
                "points" => array()
            );
            // Generate new session IDs for new sessions.
            $this->sessionID = $this->generateSessionID();
        } else {
            $this->sessionData = $memcache->get(PREFIX_SESSION.$sid);
            $this->sessionID = $sid;
        }
    }

    // Whether or not this session exists in Memcached. Returns false if the
    // user was constructed from data fetched from Memcached, but it wasn't
    // found. If the session was just created and hasn't been saved to Memcached
    // yet, returns true.
    public function exists() {
        return $this->sessionData !== false;
    }

    // Saves the session to Memcached.
    public function save() {
        if ($this->sessionData["interval"] === null) {
            throw new UnexpectedValueException("Session interval is undefined");
        } elseif ($this->sessionData["expire"] === 0) {
            throw new UnexpectedValueException("Session cannot be indefinite");
        }
        // If the session has already expired, delete it instead of saving it to
        // clean up Memcached.
        if (!$this->hasExpired()) {
            $this->memcache->set(PREFIX_SESSION.$this->sessionID, $this->sessionData, $this->getExpirationTime());
        } else {
            $this->memcache->delete(PREFIX_SESSION.$this->sessionID);
        }
        return $this;
    }

    // Ends the current sharing session. Also ends any attached single-user
    // shares, and cleans up the user's presence in group shares. (This is why a
    // list of shares is stored in the "targets" key of each session.)
    public function end() {
        $this->memcache->delete(PREFIX_SESSION.$this->sessionID);
        $targets = $this->getTargets();
        foreach ($targets as $share) {
            if ($share->exists()) {
                switch ($share->getType()) {
                    case SHARE_TYPE_ALONE:
                        $share->end();
                        break;
                    case SHARE_TYPE_GROUP:
                        $share->clean();
                        break;
                }
            }
        }
    }

    // Returns this session's ID. Used to e.g. push new location updates.
    public function getSessionID() {
        return $this->sessionID;
    }

    // Sets a new expiration time for this session. Does not take effect until
    // save() is called.
    public function setExpirationTime($expire) {
        $this->sessionData["expire"] = $expire;
        return $this;
    }

    // Gets the current expiration time of this session.
    public function getExpirationTime() {
        return $this->sessionData["expire"];
    }

    // Returns whether or not this session's expiration time is in the past.
    public function hasExpired() {
        return $this->getExpirationTime() <= time();
    }

    // Sets a new sharing interval for this session, in seconds. Does not take
    // effect until save() is called.
    public function setInterval($interval) {
        $this->sessionData["interval"] = $interval;
        return $this;
    }

    // Gets the current sharing interval of this session, in seconds.
    public function getInterval() {
        return $this->sessionData["interval"];
    }

    // Adds a new share that this session is contributing location data to. Used
    // so that shares can be cleaned up when end() is called. $share must be a
    // Share instance. Does not take effect until save() is called.
    public function addTarget($share) {
        $this->sessionData["targets"][] = $share->getShareID();
        return $this;
    }

    // Returns a list of Share instances representing shares that this session
    // is contributing to.
    public function getTargets() {
        $shares = array();
        foreach ($this->sessionData["targets"] as $shareID) {
            $shares[] = Share::fromShareID($this->memcache, $shareID);
        }
        return $shares;
    }

    // Removes a target from the session.
    public function removeTarget($share) {
        if (($key = array_search($share->getShareID(), $this->sessionData["targets"])) !== false) {
            unset($this->sessionData["targets"][$key]);
            $this->sessionData["targets"] = array_values($this->sessionData["targets"]);
        }
        return $this;
    }

    // Returns a list of share IDs representing shares that this session is
    // contributing to.
    public function getTargetIDs() {
        return $this->sessionData["targets"];
    }

    // Adds a new coordinate point to the session. $point is an array containing
    // a latitude, longitude, timestamp, accuracy and speed, in that order. The
    // latter two elements may be null. Does not take effect until save() is
    // called.
    public function addPoint($point) {
        $this->sessionData["points"][] = $point;
        // Ensure that we don't exceed the maximum number of points stored in
        // memcached.
        while (count($this->sessionData["points"]) > getConfig("max_cached_pts")) {
            array_shift($this->sessionData["points"]);
        }
        return $this;
    }

    // Returns a list of all point arrays for this session.
    public function getPoints() {
        return $this->sessionData["points"];
    }

    // Generates a random session ID for new sessions.
    private function generateSessionID() {
        $sid = "";
        do $sid = bin2hex(openssl_random_pseudo_bytes(SESSION_ID_SIZE));
        while ($this->memcache->get(PREFIX_SESSION.$this->sessionID) !== false);
        return $sid;
    }
}

// A header function for requiring certain POST parameters to be present in web
// requests.
function requirePOST(...$args) {
    foreach ($args as $field) {
        if (!isset($_POST[$field])) die("Missing data!\n");
    }
}

// Hauk maintains compatibility with both `memcache` and `memcached`, meaning we
// need an abstraction layer between Hauk and the extensions to ensure a unified
// interface for both. This code loads the correct memcache wrapper.

switch (getConfig("storage_backend")) {
    case MEMCACHED:
        if (extension_loaded("memcached")) {
            include_once(__DIR__."/wrapper/memcached.php");
        } else if (extension_loaded("memcache")) {
            include_once(__DIR__."/wrapper/memcache.php");
        } else {
            die($LANG['no_memcached_ext']."\n");
        }
        break;
    case REDIS:
        if (extension_loaded("redis")) {
            include_once(__DIR__."/wrapper/redis.php");
        } else {
            die($LANG['no_redis_ext']."\n");
        }
        break;
    default:
        die($LANG['invalid_storage']."\n");
}


// Returns a memcached instance.
function memConnect() {
    return new MemWrapper();
}
