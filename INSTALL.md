Installation procedure
----------------------
1) Unzip vacation.tar.gz in plugins/
2) Enable the vacation plugin in config/main.inc.php: $rcmail_config['plugins'] = array('vacation');
3) Open plugins/vacation/config.ini in your editor and change the settings if needed.
4) chown $apache_user plugins/vacation/config.ini
5) chmod 0400 plugins/vacation/config.ini

See README.TXT for additional information and requirements for each driver.

Valid config.ini configuration options
--------------------------------------

An optional default subject and bodytext can be specified using:

subject = "Out of office"
body = "default.txt"

The body refers to a template textfile that contains a default text.
No HTML can be used in this template.


Here you'll find a list of available configuration options per driver:

[*** None Driver ***]
This driver has no configuration options. Use this driver to disable the
'Vacation' subtab in the Settings tab.


[*** FTP Driver ***]
- server = "ftp.host.org"
If 'server' is omitted, the FTP driver will use the current IMAP-server as server. Optional.

- port = "21"
If 'port' is omitted, the FTP driver will use the default port 21. Optional.

- passive = False
If set, the FTP driver will try to establish a passive FTP connection. Optional.

- disable_forward = False
If set to True, the end-user will not be able to set forwards. Defaults to false. Optional.


[*** SSHFTP Driver ***]
- server = "ssh.host.org"
If 'server' is omitted, the SSHFTP driver will use the current IMAP-server as server. Optional.

- port = "22"
If 'port' is omitted, the SSHFTP driver will use the default port 22. Optional.

- disable_forward = False
If set to True, the end-user will not be able to set forwards. Defaults to false. Optional.


[*** Setuid Driver ***]
- executable = "/usr/bin/squirrelmail_vacation_proxy"
Path to the actual setuid binary that read/writes the .forward file in a user's $HOME directory. Required.

- disable_forward = False
If set to True, the end-user will not be able to set forwards. Defaults to false. Optional.


[*** SQL/Virtual Driver ***]

- dsn = "pgsql://roundcube:password@localhost"
Specifies a DSN (see db.inc.php) that has access to the dbase specified below. Optional.
If left empty, the DSN of db.inc.php will be used.

- transport = "vacation.yourdomain.org"
Applies to Postfix SMTP-server only. See http://www.postfix.org/VIRTUAL_README.html#autoreplies.
Required. This must match the transport in /etc/postfix/transport and /etc/postfix/master.cf

- dbase = "postfix"
Database used by the mailserver. The vacation and virtual alias tables must exist
 in this database. When use Postresql write a qualified name consisting of the schema name and table name separated by a dot. Required. 

- always_keep_copy = true
Always maintain an $email -> $email alias in the alias table. Defaults to true if omitted. Optional.

- select_query = "SELECT destination FROM %m.virtual_aliases WHERE source='%e' AND destination='%g'"
Query to retrieve aliases for the current user. Required.
Parameters: %e = email address, %d = domainname, %i = domain_id, %g = goto .
The latter is used for the transport.
%g will expands to john@domain.org@$transport
%m is required as the Roundcube database should be different from the mailserver's database.

- delete_query = "DELETE FROM %m.virtual_aliases WHERE domain_id=%i AND source='%e'"
Aliases are recreated when saving vacation settings. Required.

- insert_query = "INSERT INTO %m.virtual_aliases (domain_id,source,destination) VALUES (%i,'%e','%g')"
Query to create new aliases. Required.

- domain_lookup_query = "SELECT id FROM postfix.virtual_domains WHERE name='%d'"
Required if you use a domain_id for storing the domain's in the alias table.

- createvacationconf = true
Creates /etc/postfixadmin/vacation.conf which has database configuration for the vacation.pl script. Optional.
It uses the selected DSN to create the file. Make sure this file is only readable
 by the vacation user after it's created.
If unset or false, you must edit vacation.pl manually and set database accordingly.

- always_keep_message = true
If vacation/auto-reply gets disabled, should we delete the subject/body set by the end-user?
Optional. Defaults to true.

- disable_forward = False
If set to True, the end-user will not be able to set forwards. Defaults to false. Optional


[*** Sieve Driver ***]
TBD

[*** Dot forward ***]
Path to vacation binary
- binary = "/usr/bin/vacation"

Unsupported at the moment
- flags = ""

File that contains the body & subject of the out of office mail. Required
- message = ".vacation.msg"

Database file for vacation binary. Default should be ok. Required
- database = ".vacation.db"

If set to true use -a $alias in the .forward file and allow the user to select the aliases.
By default the identities are shown if available
- alias_identities = true

If the vacation binary supports setting the envelop sender, set this to the right parameter.
*BSD seems to use '-R' and Debian '-z'. See 'man vacation' for more info.
- set_envelop_sender = false

If vacation/auto-reply gets disabled, should we keep the .vacation.msg? Optional.
- always_keep_message = true


Protecting config.ini
---------------------
If you use the 'virtual' driver, it's recommended to protect config.ini from
being accessed directly using a browser.
In the root of the plugin directory, you'll find .htaccess that prevents DSN
password in config.ini from being exposed.


Configuration examples for config.ini
-------------------------------------
[default]
driver = "sshftp"

[mail.server.org]
driver = "none"

[mail.work.nl]
driver = "setuid"
executable = "/usr/bin/squirrelmail_vacation_proxy"

[imap.provider.org]
driver = "ftp"
server = "ftp.provider.org"
passive = True
