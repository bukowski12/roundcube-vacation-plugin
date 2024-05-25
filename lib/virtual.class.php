<?php
/*
 * Virtual/SQL driver
 *
 * @package	plugins
 * @uses	rcube_plugin
 * @author	Jasper Slits <jaspersl at gmail dot com>
 * @version	1.9
 * @license     GPL
 * @link	https://sourceforge.net/projects/rcubevacation/
 * @todo	See README.TXT
 */

class Virtual extends VacationDriver {

    public $identity;

    private $db, $domain, $domain_id, $goto = "";
    private $db_user;
    /** @var bool $db_is_postgres sometimes query's syntax depend on database
     *            true  => means postgresql is used
     *            false => means mysql is used
     */
    private $db_is_postgres;
    
    public function init() {
        // Use the DSN from db.inc.php or a dedicated DSN defined in config.ini

        if (empty($this->cfg['dsn'])) {
            $this->db = $this->rcmail->db;
            $dsn = self::parseDSN($this->rcmail->config->get('db_dsnw'));
        } else {
            $this->db = new rcube_db($this->cfg['dsn'], '', FALSE);
            $this->db->db_connect('w');

            $this->db->set_debug((bool) $this->rcmail->config->get('sql_debug'));
            $dsn = self::parseDSN($this->cfg['dsn']);
            $this->db->set_debug(true);

        }
        // Save username for error handling
        $this->db_user = $dsn['username'];

        $this->db_is_postgres = $dsn['dbsyntax'] == 'pgsql';

        if (isset($this->cfg['createvacationconf']) && $this->cfg['createvacationconf']) {

            $this->createVirtualConfig($dsn);
        }

    }

    /*
	 * @return Array Values for the form
    */
    public function _get() {
        $vacArr = array("subject"=>"", "body"=>"");
        //   print_r($vacArr);
        $fwdArr = $this->virtual_alias();

        $sql = sprintf("SELECT subject,body,active FROM {$this->cfg['dbase']}.vacation WHERE email='%s'",
                rcube::Q($this->user->data['username']));

        $res = $this->db->query($sql);
        if ($error = $this->db->is_error()) {
            rcube::raise_error(array('code' => 601, 'type' => 'db', 'file' => __FILE__,
                        'message' => "Vacation plugin: query on {$this->cfg['dbase']}.vacation failed. Check DSN and verify that SELECT privileges on {$this->cfg['dbase']}.vacation are granted to user '{$this->db_user}'. <br/><br/>Error message:  " . $error), true, true);
        }



        if ($row = $this->db->fetch_assoc($res)) {
            $vacArr['body'] = $row['body'];
            $vacArr['subject'] = $row['subject'];
            //$vacArr['enabled'] = ($row['active'] == 1) && ($fwdArr['enabled'] == 1);
            $vacArr['enabled'] = $row['active'];
        }


        return array_merge($fwdArr, $vacArr);
    }

    /*
	 * @return boolean True on succes, false on failure
    */
    public function setVacation() {
        // If there is an existing entry in the vacation table, delete it.
        // This also triggers the cascading delete on the vacation_notification, but's ok for now.

        // We store since version 1.6 all data into one row.
        $aliasArr = array();

        // Sets class property
        $this->domain_id = $this->domainLookup();

        $sql = sprintf("UPDATE {$this->cfg['dbase']}.vacation SET created=now(),active=FALSE WHERE email='%s'", rcube::Q($this->user->data['username']));


        $this->db->query($sql);

        $update = ($this->db->affected_rows() == 1);

        // Delete the alias for the vacation transport (Postfix)
        $sql = $this->translate($this->cfg['delete_query']);

        $this->db->query($sql);
        if ($error = $this->db->is_error()) {
            if (strpos($error, "no such field")) {
                $error = " Configure either domain_lookup_query or use %d in config.ini's delete_query rather than %i. <br/><br/>";
            }

            rcube::raise_error(array('code' => 601, 'type' => 'db', 'file' => __FILE__,
                        'message' => "Vacation plugin: Error while saving records to {$this->cfg['dbase']}.vacation table. <br/><br/>" . $error
                    ), true, true);

        }


        // Save vacation message in any case

	// LIMIT date arbitrarily put to next century (vacation.pl doesn't like NULL value)
        $interval = $this->db_is_postgres ? "INTERVAL '100 YEAR'" : 'INTERVAL 5 YEAR';
        if (!$update) {
            $sql = "INSERT INTO {$this->cfg['dbase']}.vacation ".
                "( email, subject, body, cache, domain, created, active, activefrom, activeuntil ) ".
                "VALUES ( ?, ?, ?, '', ?, NOW(), ?, NOW(), NOW() + $interval )";
        } else {
            $sql = "UPDATE {$this->cfg['dbase']}.vacation SET email=?,subject=?,body=?,domain=?,active=?, activefrom=NOW(), activeuntil=NOW() + $interval WHERE email=?";
        }
	      if ($this->enable == '') {
            $this->enable = 0;
        }

        //mysql version of postfixadmin vacation table use 'active' column with tinyint(1)
        //pgsql version of postfixadmin vacation table use 'active' column with true/false bool
        $enable_status = $this->enable;
        if($this->db_is_postgres) {
          $enable_status = $this->enable ? 'TRUE' : 'FALSE';
        }else{
          $enable_status = $this->enable ? '1' : '0';
        }

        $this->db->query($sql, 
	          rcube::Q($this->user->data['username']), 
	          $this->subject, 
	          $this->body,
	          $this->domain,
	          $enable_status,
	          rcube::Q($this->user->data['username']));
        if ($error = $this->db->is_error()) {
            if (strpos($error, "no such field")) {
                $error = " Configure either domain_lookup_query or use \%d in config.ini's insert_query rather than \%i<br/><br/>";
            }

            rcube::raise_error(array('code' => 601, 'type' => 'db', 'file' => __FILE__,
                        'message' => "Vacation plugin: Error while saving records to {$this->cfg['dbase']}.vacation table. <br/><br/>" . $error
                    ), true, true);
        }

        // (Re)enable the vacation transport alias
        if ($this->enable && $this->body != "" && $this->subject != "") {
            $aliasArr[] = '%g';
        }


        // Keep a copy of the mail if explicitly asked for or when using vacation
        $always = (isset($this->cfg['always_keep_copy']) && $this->cfg['always_keep_copy']);
        if ($this->keepcopy || in_array('%g', $aliasArr) || $always) {
            $aliasArr[] = '%e';
        }

        // Set a forward
        if ($this->forward != null) {
            $aliasArr[] = '%f';
        }

        // Aliases are re-created if $sqlArr is not empty.
        $sql = $this->translate($this->cfg['delete_query']);
        $this->db->query($sql);

        // One row to store all aliases
        if (!empty($aliasArr)) {

            $alias = join(",", $aliasArr);
            $sql = str_replace('%g', $alias, $this->cfg['insert_query']);
            $sql = $this->translate($sql);

            $this->db->query($sql);
            if ($error = $this->db->is_error()) {
                rcube::raise_error(array('code' => 601, 'type' => 'db', 'file' => __FILE__,
                            'message' => "Vacation plugin: Error while executing {$this->cfg['insert_query']} <br/><br/>" . $error
                        ), true, true);
            }
        }
        return true;
    }

    /*
	 * @return string SQL query with substituted parameters
    */
    private function translate($query) {
	// vacation.pl assume that people won't use # as a valid mailbox character
        return str_replace(array('%e', '%d', '%i', '%g', '%f', '%m'),
                array($this->user->data['username'], $this->domain, $this->domain_id,
                    rcube::Q(str_replace('@', '#', $this->user->data['username'])) . "@" . $this->cfg['transport'], $this->forward, $this->cfg['dbase']), $query);
    }

// Sets %i. Lookup the domain_id based on the domainname. Returns the domainname if the query is empty
    private function domainLookup() {
        // Sets the domain
        list($username, $this->domain) = explode("@", $this->user->get_username());
        if (!empty($this->cfg['domain_lookup_query'])) {
            $res = $this->db->query($this->translate($this->cfg['domain_lookup_query']));

            if (!$row= $this->db->fetch_array($res)) {
                rcube::raise_error(array('code' => 601, 'type' => 'db', 'file' => __FILE__,
                            'message' => "Vacation plugin: domain_lookup_query did not return any row. Check config.ini <br/><br/>" . $this->db->is_error()
                        ), true, true);

            }
            return $row[0];
        } else {
            return $this->domain;
        }
    }

    /*Creates configuration file for vacation.pl
	 *
	 * @param array dsn
	 * @return void
    */
    private function createVirtualConfig(array $dsn) {

        $virtual_config = "/etc/postfixadmin/";
        if (!is_writeable($virtual_config)) {
            rcube::raise_error(array('code' => 601, 'type' => 'php', 'file' => __FILE__,
                        'message' => "Vacation plugin: Cannot create {$virtual_config}vacation.conf . Check permissions.<br/><br/>"
                    ), true, true);
        }

        // Fix for vacation.pl
        if ($dsn['phptype'] == 'pgsql') {
            $dsn['phptype'] = 'Pg';
        }

        $virtual_config .= "vacation.conf";
        // Only recreate vacation.conf if config.ini has been modified since
        if (!file_exists($virtual_config) || (filemtime("plugins/vacation/config.ini") > filemtime($virtual_config))) {
            $config = sprintf("
        \$db_type = '%s';
        \$db_username = '%s';
        \$db_password = '%s';
        \$db_name     = '%s';
        \$vacation_domain = '%s';", $dsn['phptype'], $dsn['username'], $dsn['password'], $this->cfg['dbase'], $this->cfg['transport']);
            file_put_contents($virtual_config, $config);
        }
    }

    /*
			Retrieves the localcopy and/or forward settings.
		* @return array with virtual aliases
    */
    private function virtual_alias() {
        $forward = "";
        $enabled = false;
	// vacation.pl assume that people won't use # as a valid mailbox character
        $goto = rcube::Q(str_replace('@', '#', $this->user->data['username'])) . "@" . $this->cfg['transport'];

        // Backwards compatiblity. Since >=1.6 this is no longer needed
        $sql = str_replace("='%g'", "<>''", $this->cfg['select_query']);

        $res = $this->db->query($this->translate($sql));

        $rows = array();

        while (list($row) = $this->db->fetch_array($res)) {

            // Postfix accepts multiple aliases on 1 row as well as an alias per row
            if (strpos($row, ",") !== false) {
                $rows = explode(",", $row);
            } else {
                $rows[] = $row;
            }
        }



        foreach ($rows as $row) {
            // Source = destination means keep a local copy
            if ($row == $this->user->data['username']) {
                $keepcopy = true;
            } else {
                // Neither keepcopy or postfix transport means it's an a forward address
                if ($row == $goto) {
                    $enabled = true;
                } else {
                    // Support multi forwarding addresses
                    $forward .= $row . ",";
                }
            }

        }
        // Substr removes any trailing comma
        return array("forward"=>substr($forward, 0,  - 1), "keepcopy"=>$keepcopy, "enabled"=>$enabled);
    }

// Destroy the database connection of our temporary database connection
    public function __destruct() {
        if (!empty($this->cfg['dsn']) && is_resource($this->db)) {
            $this->db = null;
        }
    }

    /**
     * Parse a data source name.
     *
     * Additional keys can be added by appending a URI query string to the
     * end of the DSN.
     *
     * The format of the supplied DSN is in its fullest form:
     * <code>
     *  phptype(dbsyntax)://username:password@protocol+hostspec/database?option=8&another=true
     * </code>
     *
     * Most variations are allowed:
     * <code>
     *  phptype://username:password@protocol+hostspec:110//usr/db_file.db?mode=0644
     *  phptype://username:password@hostspec/database_name
     *  phptype://username:password@hostspec
     *  phptype://username@hostspec
     *  phptype://hostspec/database
     *  phptype://hostspec
     *  phptype(dbsyntax)
     *  phptype
     * </code>
     *
     * @param   string  Data Source Name to be parsed
     *
     * @return  array   an associative array with the following keys:
     *  + phptype:  Database backend used in PHP (mysql, odbc etc.)
     *  + dbsyntax: Database used with regards to SQL syntax etc.
     *  + protocol: Communication protocol to use (tcp, unix etc.)
     *  + hostspec: Host specification (hostname[:port])
     *  + database: Database to use on the DBMS server
     *  + username: User name for login
     *  + password: Password for login
     *
     * @access  public
     * @author  Tomas V.V.Cox <cox@idecnet.com>
     * @note    This function was extracted & adapted from Pear->MDB2 (see https://pear.php.net/package/mdb2)
     */
    private function parseDSN($dsn)
    {
        $parsed = [
          'phptype'  => false,
          'dbsyntax' => false,
          'username' => false,
          'password' => false,
          'protocol' => false,
          'hostspec' => false,
          'port'     => false,
          'socket'   => false,
          'database' => false,
          'mode'     => false,
        ];

        if (is_array($dsn)) {
            $dsn = array_merge($parsed, $dsn);
            if (!$dsn['dbsyntax']) {
                $dsn['dbsyntax'] = $dsn['phptype'];
            }
            return $dsn;
        }

        // Find phptype and dbsyntax
        if (($pos = strpos($dsn, '://')) !== false) {
            $str = substr($dsn, 0, $pos);
            $dsn = substr($dsn, $pos + 3);
        } else {
            $str = $dsn;
            $dsn = null;
        }

        // Get phptype and dbsyntax
        // $str => phptype(dbsyntax)
        if (preg_match('|^(.+?)\((.*?)\)$|', $str, $arr)) {
            $parsed['phptype']  = $arr[1];
            $parsed['dbsyntax'] = !$arr[2] ? $arr[1] : $arr[2];
        } else {
            $parsed['phptype']  = $str;
            $parsed['dbsyntax'] = $str;
        }

        if (empty($dsn)) {
            return $parsed;
        }

        // Get (if found): username and password
        // $dsn => username:password@protocol+hostspec/database
        if (($at = strrpos($dsn,'@')) !== false) {
            $str = substr($dsn, 0, $at);
            $dsn = substr($dsn, $at + 1);
            if (($pos = strpos($str, ':')) !== false) {
                $parsed['username'] = rawurldecode(substr($str, 0, $pos));
                $parsed['password'] = rawurldecode(substr($str, $pos + 1));
            } else {
                $parsed['username'] = rawurldecode($str);
            }
        }

        // Find protocol and hostspec

        // $dsn => proto(proto_opts)/database
        if (preg_match('|^([^(]+)\((.*?)\)/?(.*?)$|', $dsn, $match)) {
            $proto       = $match[1];
            $proto_opts  = $match[2] ? $match[2] : false;
            $dsn         = $match[3];

            // $dsn => protocol+hostspec/database (old format)
        } else {
            if (strpos($dsn, '+') !== false) {
                list($proto, $dsn) = explode('+', $dsn, 2);
            }
            if (   strpos($dsn, '//') === 0
              && strpos($dsn, '/', 2) !== false
              && $parsed['phptype'] == 'oci8'
            ) {
                //oracle's "Easy Connect" syntax:
                //"username/password@[//]host[:port][/service_name]"
                //e.g. "scott/tiger@//mymachine:1521/oracle"
                $proto_opts = $dsn;
                $dsn = null;
            } elseif (strpos($dsn, '/') !== false) {
                list($proto_opts, $dsn) = explode('/', $dsn, 2);
            } else {
                $proto_opts = $dsn;
                $dsn = null;
            }
        }

        // process the different protocol options
        $parsed['protocol'] = (!empty($proto)) ? $proto : 'tcp';
        $proto_opts = rawurldecode($proto_opts);
        if (strpos($proto_opts, ':') !== false) {
            list($proto_opts, $parsed['port']) = explode(':', $proto_opts);
        }
        if ($parsed['protocol'] == 'tcp') {
            $parsed['hostspec'] = $proto_opts;
        } elseif ($parsed['protocol'] == 'unix') {
            $parsed['socket'] = $proto_opts;
        }

        // Get dabase if any
        // $dsn => database
        if ($dsn) {
            // /database
            if (($pos = strpos($dsn, '?')) === false) {
                $parsed['database'] = $dsn;
                // /database?param1=value1&param2=value2
            } else {
                $parsed['database'] = substr($dsn, 0, $pos);
                $dsn = substr($dsn, $pos + 1);
                if (strpos($dsn, '&') !== false) {
                    $opts = explode('&', $dsn);
                } else { // database?param1=value1
                    $opts = array($dsn);
                }
                foreach ($opts as $opt) {
                    list($key, $value) = explode('=', $opt);
                    if (!isset($parsed[$key])) {
                        // don't allow params overwrite
                        $parsed[$key] = rawurldecode($value);
                    }
                }
            }
        }

        return $parsed;
    }
}

?>
