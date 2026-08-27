<?php
/*
 * VacationDriver base class
 *
 * @package	plugins
 * @uses	rcube_plugin
 * @author	Jasper Slits <jaspersl@gmail.com>
 * @version	2.1.7
 * @license     GPL
 * @link	https://github.com/bukowski12/roundcube-vacation-plugin
 * @todo	See README.md
 */

abstract class VacationDriver {
	protected $cfg,$dotforward = array();
	protected $rcmail,$user,$forward,$body,$subject,$aliases = "";
	protected $enable,$keepcopy = false;

	abstract public function _get();
	abstract public function init();
	abstract protected function setVacation();

	// Provide easy access for the drivers to frequently used objects
	public function __construct() {
		$this->rcmail = rcmail::get_instance();
		$this->user = $this->rcmail->user;
		$this->identity = $this->user->get_identity();
		$child_class = strtolower( get_class($this));
	}

        /*
        
         */
	public final function setIniConfig(array $inicfg)
	{
		$this->cfg = $inicfg;
	}


	public final function setDotForwardConfig($child_class,$config) {
		// forward settings are shared by ftp,sshftp and setuid driver. 
		if (in_array($child_class ,array('ftp','sshftp','setuid'))) {
			$this->dotforward = $config;
		}
	}

	// Helper method for the template to determine if user is allowed to enter aliases
	public final function useAliases()
	{

		return (isset($this->dotforward['alias_identities']) && $this->dotforward['alias_identities']);
	}

public function loadDefaults() {
    // Load default subject and body.

    if (empty($this->cfg['body'])) return false;


    $file = "plugins/vacation/" . $this->cfg['body'];

    if (version_compare(RCMAIL_VERSION, '1.7.0', '>=')) {
      $file = INSTALL_PATH . "plugins/vacation/" . $this->cfg['body'];
    }

    if (is_readable($file)) {
        $defaults = array('subject'=>$this->cfg['subject']);
        $defaults['body'] = file_get_contents($file);
        return $defaults;
    } else {
        raise_error(array('code' => 601, 'type' => 'php', 'file' => __FILE__,
                    'message' => sprintf("Vacation plugin: s cannot be opened", $file)
                ), true, true);
    }
}
	
	

	// This method will be used from vacation.js as an JSON/Ajax call or directly
	public final function vacation_aliases($method=null)
	{
		$aliases = "";
		$identities = $this->user->list_identities();
		// Strip off the default identity, no need to alias that.
		array_shift($identities);
	  
		

		foreach($identities as $identity)
		{
			// Strip domainname off. /usr/bin/vacation only deals with system users
			
			$aliases.=array_shift(explode("@",$identity['email'])).",";
		}

		$str = substr($aliases,0,-1);

		// We use this method in both ftp.class.php and as Ajax callback

		if (empty($identities))
		{
			// No identities found.
//			$str = "To use aliases, add more identities.";
		}

		if ($method != null)
		{
			return $str;
		} else {
			// Calls the alias_callback as defined in vacation.js
			$this->rcmail->output->command('plugin.alias_callback',  array('aliases'=>$str));
		}
	}


	/*
	 * @return boolean True on succes, false on failure
	 */
	final public function save() {
		$this->enable = (NULL != rcube_utils::get_input_value('_vacation_enabled', rcube_utils::INPUT_POST));
		$this->subject = rcube_utils::get_input_value('_vacation_subject', rcube_utils::INPUT_POST);
		$this->body = rcube_utils::get_input_value('_vacation_body', rcube_utils::INPUT_POST);
		$this->keepcopy = (NULL != rcube_utils::get_input_value('_vacation_keepcopy', rcube_utils::INPUT_POST));
		$this->forward = rcube_utils::get_input_value('_vacation_forward', rcube_utils::INPUT_POST);
		$this->aliases = rcube_utils::get_input_value('_vacation_aliases', rcube_utils::INPUT_POST);

		// This method performs the actual work
		return $this->setVacation();
	}

	final public function getActionText()
	{
		if ($this->enable && empty($this->forward)) { return "enabled_and_no_forward"; };
		if ($this->enable && !empty($this->forward)) {  return "enabled_and_forward"; };
		if (! $this->enable && !empty($this->forward)) { return "disabled_and_forward"; };
		if (! $this->enable && empty($this->forward)) { return "disabled_and_no_forward"; };
		
	}
}?>
