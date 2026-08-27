<?php
/*
 * VacationFactory class
 *
 * @package	plugins
 * @uses	rcube_plugin
 * @author	Jasper Slits <jaspersl@gmail.com>
 * @version	2.1.7
 * @license     GPL
 * @link	https://github.com/bukowski12/roundcube-vacation-plugin
 * @todo	See README.md
 */

class VacationDriverFactory {
	
	public function __construct()
	{
		die("Cannot instantiate this class");		
	}

	/*
	 * @param string driver class to be loaded
	 * @return object specific driver */
    public static function Create( $driver ) {
        $driver = strtolower($driver);
	$driverclass = sprintf("plugins/vacation/lib/%s.class.php",$driver);

    if (version_compare(RCMAIL_VERSION, '1.7.0', '>=')) {
      $driverclass = sprintf(INSTALL_PATH . "plugins/vacation/lib/%s.class.php",$driver);
    }

        if (! is_readable($driverclass)) {
             rcube::raise_error(array('code' => 601,'type' => 'php','file' => __FILE__,
                'message' => sprintf("Vacation plugin: Driver '%s' cannot be loaded using %s",$driver,$driverclass)
                ),true, true);
        }
        
		require $driverclass;
        return new $driver;
    }
}?>
