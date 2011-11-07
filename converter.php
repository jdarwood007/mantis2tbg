<?php
// @NOTE: THIS CONVERTER IS IN DEVELOPMENT AND IS MOSTLY THEORETICAL UNTESTED CODE.
error_reporting(E_ALL);
ini_set('display_errors', 1);

/**
 * Convert Mantis Bug Tracker to The Bug Genie
 * 
 * @author Joshua Dickerson
 * @author Jeremy Darwood
 * @copyright Joshua Dickerson & Jeremy Darwood 2011
 * @license BSD
 * 
 * The gist of the license is that you may do whatever you like, just give credit where it's due.
 * 
 * Throughout this I use some acronyms:
 *  MBT = Mantis Bug Tracker
 *  TBG = The Bug Genie
 */

/*
* @TODO The developers list of things todo.	
* Add js to support ajax
* Add ajax support
* Test
* The version needs split into major, minor and revision.. Fun.
* Should move queries to be executed by a local method to handle errors.
*/

// This is my debugging for debugging queries.
// exit(var_dump($this->tbg_db->errorInfo()));
/*
* @TODO Known Bugs
* Converting categories doesn't sort them properly for which category they existed.
* Converting versions doesn't work when repeated due to the data existing in builds already and is skipped.  Step 0 anyone?
* Doesn't add the default wiki pages upon creating a project.
* Issue conversion doesn't fix state.
* Issue conversion doesn't fix issuetype.
* Issue conversion doesn't fix severity.
*/

class tbg_converter
{
	// Credits for the converter.
	protected $main_credits = 'TBG converter &copy; Joshua Dickerson & Jeremy Darwood';

	// The host, user, and pass must be on the same server
	protected $db_dsn = 'mysql:host=localhost;dbname=tbg3';
	protected $db_user = 'tb3';
	protected $db_pass = '';

	// The database prefixes (including the database).
	protected $tbg_db_prefix;
	protected $tbg_db_name = 'tbg';
	protected $tbg_db_table_prefix = 'tbg3_';

	// Scope (usually just 1)
	protected $tbg_scope = 1;

	// The start timer.
	protected $start_time = 0;

	// Step info.
	protected $substep = 0;
	protected $step = 0;

	// Is this CLI?
	protected $is_cli = false;
	protected $is_json = false;

	// The database connection resource.
	protected $db;

	function __construct()
	{
		global $theme;

		// Start your engines.
		$this->start_time = time();

		// We might be running this from terminal
		if ((php_sapi_name() == 'cli' || (isset($_SERVER['TERM']) && strpos($_SERVER['TERM'], 'xterm') !== false)) && empty($_SERVER['REMOTE_ADDR']))
			$this->processCLI();

		// Don't timeout!
		set_time_limit(0);

		if (function_exists('apache_reset_timeout'))
			apache_reset_timeout();

		// Try to find some settings.
		$this->loadSettings();

		// Open a new theme.
		$theme = new tbg_converter_wrapper($this->steps, array($this->main_credits, $this->credits));

		// We can't process anymore until this exists.
		if (empty($this->db_user))
			$this->converterSetup();
		else
			$this->doConversion();
	}

	/**
	* Actually does the conversion process
	*
	*/
	public function processCLI()
	{
		$this->is_cli = true;

		// First off, clean the buffers and buy us some time.
		@ob_end_clean();
		ob_implicit_flush(true);
		@set_time_limit(600);

		// This isn't good.
		if (!isset($_SERVER['argv']))
			$_SERVER['argv'] = array();

		// If its empty, force help.
		if (empty($_SERVER['argv'][1]))
			$_SERVER['argv'][1] = '--help';

		// Lets load up our possible arguments.
		$settings = $this->converterSettings();

		// We need help!.
		if (in_array('--help', $_SERVER['argv']))
		{
			// This is a special case, where echo is fine here.
			echo "
			Usage: " . (isset($_ENV['_']) ? $_ENV['_'] : '/path/to/php') . " -f " . str_replace(getcwd() . '/', '', __FILE__) . " -- [OPTION]...\n";

			foreach ($settings as $key => $setting)
				echo '
			--' . $key . '			' . $setting['name'];

			// The debug option.
			exit("
		   	--debug				Output debugging information.\n");
		}

		// Lets get the settings.
		foreach ($settings as $key => $setting)
		{
			// No special regex?
			if (!isset($setting['cli_regex']))
				$setting['cli_regex'] = '~--' . $key . '=([^\s]+)[\s+--]?~i';

			foreach ($_SERVER['argv'] as $i => $arg)
			{
				// Trim spaces.
				$arg = trim($arg);

				if (preg_match($setting['cli_regex'], $arg, $match) != 0)
					$_POST[$key] = substr($match[1], -1) == '/' ? substr($match[1], 0, -1) : trim($match[1]);
			}

			// Oh noes, we can't find it!
			if ($setting['required'] && empty($_POST[$key]))
				exit('The argument, ' . $key . ', is required to continue.');
		}

		// validate the settings.
		$errors = $this->setSettings();
	}

	/**
	* Actually does the conversion process
	*
	*/
	public function doConversion()
	{
		$this->getDatabaseConnection();

		// Now restart.
		do
		{
			$data = $this->steps[$this->step];

			$this->updateSubStep($this->substep);
			$this->updateStep($data[1]);
			$this->step_size = $data[2];

			$count = $this->$data[1]();

			$this->checkTimeout($data[1], $this->substep, $data[2], $count);
		}
		while ($this->step < count($this->steps) + 1);
	}

	/**
	* Ask the user for some settings, validate and then start conversion.
	*
	*/
	public function converterSetup()
	{
		global $theme;

		if (isset($_POST['save']))
		{
			$errors = $this->setSettings();

			if ($errors === null)
				$this->doConversion();
			else
				$theme->errors($errors);
		}

		// Prompt for some settings.
		$theme->showSettings($this->converterSettings());

		$theme->done($this->is_cli);
	}

	/**
	* Request these settings during setup.
	*
	*/
	public function converterSettings()
	{
		return array(
			'tbg_loc' => array('name' => 'The Bug Genie Core location', 'type' => 'text', 'required' => true, 'default' => dirname(__FILE__), 'validate' => 'return file_exists($data) && file_exists($data . \'/b2db_bootstrap.inc.php\');'),
			// @TODO: Make this validate the password.
			'tbg_db_pass' => array('name' => 'The Bug Genie database password', 'type' => 'password', 'required' => true, 'validate' => 'return true;',),
		);
	}

	/**
	* Request these settings during setup.
	*
	*/
	public function loadSettings()
	{
		// Lets check our session.
		if (session_id() == '' && empty($this->is_cli))
			session_start();

		if (isset($_SESSION['tbg_converter']))
		{
			foreach (unserialize($_SESSION['tbg_converter']) as $key => $data)
				$this->{$key} = $data;
		}

		// Load some from the url.
		$this->step = isset($_GET['step']) ? (int) $_GET['step'] : 1;

		$this->substep = isset($_GET['substep']) ? (int) $_GET['substep'] : 0;
	}

	/**
	* Save the settings..
	*
	*/
	public function setSettings()
	{
		$settings = $this->converterSettings();
		$new_settings = array();
		$errors = array();

		foreach ($settings as $key => $details)
		{
			// We are saving then
			if (isset($_POST[$key]))
			{
				if (isset($details['validate']))
				{
					$temp = create_function('$data', $details['validate']);

					if ($temp($_POST[$key]) === false)
						$errors[$key] = '"' . $details['name'] . '" contains invalid_data';
				}

				$new_settings[$key] = $_POST[$key];
			}
		}

		if (!empty($errors))
			return $errors;

		// Save these.
		$_SESSION['tbg_converter'] = serialize($new_settings);

		return null;
	}

	/**
	 * Sets the database username from bootstrap.
	 * @param string $db_user = The database user name.
	 */
	public function setUname($db_user)
	{
		$this->db_user = $db_user;

		return true;
	}

	/**
	 * Sets the database password from bootstrap.
	 * @param string $db_pass = The database user's password.
	 */
	public function setPasswd($db_pass)
	{
		$this->db_pass = $db_pass;

		return true;
	}

	/**
	 * Set the prefix that will be used prior to every reference of a table
	 */
	public function setTablePrefix()
	{
		$this->tbg_db_prefix = $this->tbg_db_name . '.' . $this->tbg_db_table_prefix;

		return true;
	}

	/**
	 * Set the DSN for our database connect
	 * @param string $db_dsn = The DSN used for database connections.
	 */
	public function setDSN($db_dsn)
	{
		$this->db_dsn = $db_dsn;

		return true;
	}


	/**
	 * Establish a database connection
	 */
	function getDatabaseConnection()
	{
		// Lets locate TBG Core
		require_once($this->tbg_loc . '/b2db_bootstrap.inc.php');

		// We first try to setup TBG connection.
		try
		{
			$this->tbg_db = new PDO ($this->db_dsn, $this->db_user, $this->db_pass);
		}
		catch (PDOException $e)
		{
			echo $this->db_dsn;
			exit('TBG Connection failed: ' . $e->getMessage() . "\n");
		}

		// Set the prefixes.
		$this->setTablePrefix();

		return true;
	}

	/**
	 * Get a random string for passwords
	 * 
	 * @param int $min_length = 8 The minimum length of the string
	 * @param int $max_length = 12 The maximum length of the string
	 * @return string A randomly generate string of letters, numbers, and special characters.
	 */
	function getRandomString($min_length = 8, $max_length = 12)
	{
		// @ Why don't we simply create sha1(mt_rand() . time() . uniqueid());
		$length = rand($min_length, $max_length);

		// All of the possible characters we will allow.
		$chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!@#$%^&*_=+.,?/[]{}|';
		$chars_len = strlen($chars) - 1;

		$random_string = '';

		for ($i = 0; $i !== $length; $i++)
			$random_string .= $chars[rand(0, $chars_len)];

		return $random_string;
	}

	/**
	*
	* Updates the current step
	*
	* @param init $substep new value for substep.
	*/
	function updateStep($step)
	{
		$step = str_replace('doStep', '', $step) + 1;

		$_GET['step'] = (int) $step;

		$this->step = (int) $step;

		// Reset.
		$this->updateSubStep(0);

		return true;
	}

	/**
	*
	* Gets the current substep
	*
	* @param string $step The current function we are on.
	*/
	function getSubStep($step)
	{
			return $this->substep;
	}

	/**
	*
	* Updates the current substep
	*
	* @param init $substep new value for substep.
	*/
	function updateSubStep($substep)
	{
		$_GET['substep'] = (int) $substep;
		$this->substep = (int) $substep;

		return true;
	}

	/**
	*
	* Checks our timeout status and attempts to allow us to work longer.
	*
	* @param $function The function name we should return to if we can continue.
	*/
	function checkTimeout($function, $substep, $max_step_size, $count)
	{
		global $theme;

		$this->updateSubStep($substep + $max_step_size);

		// Hold on, we had less results than we should have.
		if ($max_step_size > $count)
			$this->updateStep($function);

		// CLI conversions can just continue.
		if ($this->is_cli)
		{
			if (time() - $this->start_time > 1)
				print (".\r");
			$this->$function();
		}

		// Try to buy us more time.
		@set_time_limit(300);
		if (function_exists('apache_reset_timeout'))
			@apache_reset_timeout();

		// if we can pass go, collect $200.
		if (time() - $this->start_time < 10)
			$this->$function();

		// @ TODO: Add in timeout stuff here.
		// @ !!! If this is all done via ajax, it should be a json or xml return.
		// @ !!! Will need to strip doStep from the function name and cast as a int for security.
		$real_step = str_replace('doStep', '', $function);

		if (!is_int($real_step) && (int) $real_step == 0)
		{
			debug_print_backtrace();
			exit;
		}

		// If ajax, return some data.
		$data = array(
			'step' => $real_step,
			'time' => time(),
			'substep' => $this->substep,
		);

		if ($this->is_json)
			$theme->return_json($data);
		$theme->updateData($data);
	}
}

class mbt_to_tbg extends tbg_converter
{
	// Credits for this part of the converter.
	protected  $credits = 'Mantis -> TBG converter &copy; Joshua Dickerson & Jeremy Darwood';

	protected $mbt_db_name = 'mantis';
	protected $mbt_db_table_prefix = 'mantis_';
	protected $mbt_db_prefix;

	// What steps shall we take.
	protected $steps = array(
		// Key => array('Descriptive', 'functionName', (int) step_size),
		1 => array('Users', 'doStep1', 500),
		array('Projects', 'doStep2', 500),
		array('Project Permissions', 'doStep3', 1),
		array('Categories', 'doStep4', 500),
		array('Versions', 'doStep5', 500),
		array('Issues', 'doStep6', 500),
		array('Comments', 'doStep7', 500),
		array('Relationships', 'doStep8', 500),
		array('Attachments', 'doStep9', 100),
	);
	/**
	 * Set the prefix that will be used prior to every reference of a table
	 */
	public function setDatabasePrefix()
	{
		$this->mbt_db_prefix = $this->mbt_db_name . '.' . $this->mbt_db_table_prefix;
	}

	/**
	* Request these settings during setup.
	*
	*/
	public function converterSettings()
	{
		$settings = parent::converterSettings();

		// As long as we don't overwrite, this should work.
		return $settings + array(
			'mantis_loc' => array('name' => 'Mantis Location', 'type' => 'text', 'required' => true, 'validate' => 'return file_exists($data) && file_exists($data . \'/config_inc.php\');'),
		);
	}

	/**
	 * Establish a database connection
	 */
	function getDatabaseConnection()
	{
		global $g_database_name;

		// First load TBG.
		parent::getDatabaseConnection();

		// Now lets load up Mantis stuff.
		define('ON', true);
		define('OFF', false);
		define('PHPMAILER_METHOD_MAIL', 'mail');
		define('DATABASE', 'mysql');
		define('NOBODY', 'mantis');
		require_once($this->mantis_loc . '/config_inc.php');

		// Save the mantis info.
		$this->mbt_db_name = $g_database_name;
		$this->mbt_db_table_prefix = isset($g_db_table_prefix) ? $g_db_table_prefix . '_' : 'mantis_';

		// Try to start a mantis connection.
		try
		{
			$this->mantis_db = new PDO ($g_db_type . ':host=' . $g_hostname . ';dbname=' . $g_database_name, $g_db_username, $g_db_password);
		}
		catch (PDOException $e)
		{
			exit('Mantis Connection failed: ' . $e->getMessage() . "\n");
		}

		$this->setDatabasePrefix();
	}

	/**
	 * Sets the list types in TBG to be like MBT
	 * 
	 * @param array $types = array('category', 'priority', 'reproducability', 'resolution', 'severity', 'status') an array of what types to set
	 */
	function setListTypes($types = array())
	{
		$allowed_types = array('category', 'priority', 'reproducability', 'resolution', 'severity', 'status');
		$types = empty($types) ? $allowed_types : array_intersect($allowed_types, $types);

		$types_conversion = array(
			'category' => array(
				
			),
			'priority' => array(
				
			),
			'reproducability' => array(
				'Always' => 10,
				'Sometimes' => 30,
				'Random' => 50,
				'Have not tried' => 70,
				'Unable to reproduce' => 90,
				'N/A' => 100
			),
			'resolution' => array(
				
			),
			'severity' => array(
				
			),
			'status' => array(
				
			));
		foreach ($types as $type)
		{
			
		}
	}

	/**
	* Empty the tables prior to the conversion.
	*
	* @Should it empty the tables prior to conversion or just dump over?
	* @Should we do this in each step?
	*/
	function doStep0()
	{
	}

	/**
	* Convert users.
	*
	*/
	function doStep1()
	{
		$step_size = 500;
		$substep = $this->getSubStep(__FUNCTION__);

		$query = '
			SELECT
				id, username, username AS buddyname, realname, email, password,
				enabled, last_visit AS lastseen, date_created AS joined
			FROM
				' . $this->mbt_db_prefix . 'user_table
			LIMIT ' . $step_size . ' OFFSET ' . $substep;

		// We could let this save up a few inserts then do them at once, but are we really worried about saving queries here?
		$i = 0;

		foreach ($this->mantis_db->query($query) as $row)
		{
			$password = $this->getRandomString();

			$this->tbg_db->query('
				REPLACE INTO ' . $this->tbg_db_prefix . 'users (id, username, buddyname, realname, email, password, enabled, lastseen, joined)
				VALUES (' . $row['id'] . ', "' . $row['username'] . '", "' . $row['buddyname'] . '", "' . $row['realname'] . '", "' . $row['email'] . '", "' . $password . '", ' . $row['enabled'] . ', ' . $row['lastseen'] . ', ' . $row['joined'] . ')');

			++$i;
		}

		return $i;
	}

	/**
	* Convert Projects.
	*
	*/
	function doStep2()
	{
		$step_size = 500;
		$substep = $this->getSubStep(__FUNCTION__);

		$query = '
			SELECT
				id, name, description,
				(CASE enabled WHEN 0 THEN 1 ELSE 0 END) AS locked
				FROM ' . $this->mbt_db_prefix . 'project_table
			LIMIT ' . $step_size . ' OFFSET ' . $substep;

		$i = 0;
		foreach ($this->mantis_db->query($query) as $row)
		{
			$key = strtr($row['name'], array(
				' - ' => '-',
				' ' => '_',
			));

				// We have to use ` on key otherwise mysql errors.
				$this->tbg_db->query('
				REPLACE INTO ' . $this->tbg_db_prefix . 'projects (id, name, `key`, locked, description, scope, workflow_scheme_id, issuetype_scheme_id)
				VALUES (' . $row['id'] . ', "' . $row['name'] . '", "' . $key . '", ' . $row['locked'] . ', "' . $row['description'] . '", 1, 1, 1)');

			++$i;
		}

		return $i;
	}

	/**
	* Add in default permissions.
	*
	*/
	function doStep3()
	{
		// Clean up the projects permissions index.
		$query = '
			SELECT id
			FROM ' . $this->tbg_db_prefix . 'projects';

		$projects = array();
		foreach ($this->tbg_db->query($query) as $row)
			$projects[] = $row['id'];

		$this->tbg_db->query('
				DELETE FROM ' . $this->tbg_db_prefix . 'permissions
				WHERE target_id IN (' . implode(',', $projects) . ')');

		// Lets avoid a trillionth id number.
		$this->tbg_db->query('
				ALTER TABLE ' . $this->tbg_db_prefix . 'permissions
					AUTO_INCERMENT=1');

		// We still have this handy!
		foreach ($projects as $project_id)
		{
			$this->tbg_db->query('
				INSERT INTO ' . $this->tbg_db_prefix . 'permissions (permission_type, target_id, allowed, module, uid, gid, tid, scope) VALUES
					("canseeproject", ' . $project_id . ', 1, "core", 1, 0, 0, 1),
					("canseeprojecthierarchy", ' . $project_id . ', 1, "core", 1, 0, 0, 1),
					("canmanageproject", ' . $project_id . ', 1, "core", 1, 0, 0, 1),
					("page_project_allpages_access", ' . $project_id . ', 1, "core", 1, 0, 0, 1),
					("canvoteforissues", ' . $project_id . ', 1, "core", 1, 0, 0, 1),
					("canlockandeditlockedissues", ' . $project_id . ', 1, "core", 1, 0, 0, 1),
					("cancreateandeditissues", ' . $project_id . ', 1, "core", 1, 0, 0, 1),
					("caneditissue", ' . $project_id . ', 1, "core", 1, 0, 0, 1),
					("caneditissuecustomfields", ' . $project_id . ', 1, "core", 1, 0, 0, 1),
					("canaddextrainformationtoissues", ' . $project_id . ', 1, "core", 1, 0, 0, 1),
					("canpostseeandeditallcomments", ' . $project_id . ', 1, "core", 1, 0, 0, 1)');
		}

		// We do this quickly to prevent issues with the next step.
		$this->tbg_db->query('
			DELETE FROM ' . $this->tbg_db_prefix . 'listtypes
			WHERE id NOT IN (1,2,3)
				AND itemtype = "category"');

		// We are done.
		return 2;
	}

	/**
	* Convert Categories.
	* WARNING: RUNNING THIS MULTIPLE TIMES MAY CAUSE DUPLICATE ENTRIES.
	*/
	function doStep4()
	{
		$step_size = 500;
		$substep = $this->getSubStep(__FUNCTION__);

		$query = '
			SELECT
				name
				FROM ' . $this->mbt_db_prefix . 'category_table
			LIMIT ' . $step_size . ' OFFSET ' . $substep;

		$i = 0;
		foreach ($this->mantis_db->query($query) as $row)
		{
			// Mantis has some empty category names.
			if (empty($row['name']) || trim($row['name']) == '')
				continue;

			$this->tbg_db->query('
				REPLACE INTO ' . $this->tbg_db_prefix . 'listtypes (name, itemtype, scope)
				VALUES ("' . $row['name'] . '", "category", 1)');

			++$i;
		}

		return $i;
	}

	/**
	* Convert Versions.
	*
	*/
	function doStep5()
	{
		$step_size = 500;
		$substep = $this->getSubStep(__FUNCTION__);

		// Obtain any current builds.
		$query = '
			SELECT
				id, name
				FROM ' . $this->tbg_db_prefix . 'builds';
		$builds = array();
		foreach ($this->tbg_db->query($query) as $row)
			$builds[$row['name']] = $row['id'];

		$query = '
			SELECT
				id, project_id, version, released AS isreleased, project_id AS project
				FROM ' . $this->mbt_db_prefix . 'project_version_table
			LIMIT ' . $step_size . ' OFFSET ' . $substep;

		$i = 0;
		foreach ($this->mantis_db->query($query) as $row)
		{
			if (isset($builds[$row['version']]))
				continue;

			$this->tbg_db->query('
				REPLACE INTO ' . $this->tbg_db_prefix . 'builds (name, isreleased, project) VALUES ("' . $row['version'] . '", ' . $row['isreleased'] . ', ' . $row['project'] . ')');

			$builds[$row['version']] = $this->tbg_db->lastInsertId();

			++$i;
		}

		return $i;
	}


	/**
	* Normally we want to fix them, but in this case we want to convert bugs.
	*
	*/
	function doStep6()
	{
		$step_size = 500;
		$substep = $this->getSubStep(__FUNCTION__);

		// Obtain any current builds.
		$query = '
			SELECT
				id, name
				FROM ' . $this->tbg_db_prefix . 'builds';
		$builds = array();
		foreach ($this->tbg_db->query($query) as $row)
			$builds[$row['name']] = $row['id'];

		$query = '
			SELECT
				bt.id, bt.id AS issue_no, bt.project_id, bt.summary AS title, bt.reporter_id AS posted_by, bt.handler_id AS assigned_to, bt.duplicate_id AS duplicate_of,
				bt.date_submitted AS posted, bt.last_updated,
				0 AS state /* NEEDS FIXED */,
				1 AS issuetype /* NEEDS FIXED */,
				
				bt.category_id AS category, bt.resolution,
				bt.priority,
				bt.severity /* NEEDS FIXED */,
				(CASE
					WHEN bt.reproducibility = 10 THEN 12
					WHEN bt.reproducibility > 30 AND bt.reproducibility < 70 THEN 11
					WHEN bt.reproducibility > 70 AND bt.reproducibility < 90 THEN 9
					ELSE 0
				END) AS reproducability,
				IFNULL(btt.steps_to_reproduce, "") AS reproduction_steps,
				IFNULL(btt.description, "") AS description,
				version
				FROM ' . $this->mbt_db_prefix . 'bug_table AS bt
					LEFT JOIN ' . $this->mbt_db_prefix . 'bug_text_table AS btt ON (btt.id = bt.bug_text_id)
			LIMIT ' . $step_size . ' OFFSET ' . $substep;

		$i = 0;
		foreach ($this->mantis_db->query($query) as $row)
		{
			$this->tbg_db->query('
				REPLACE INTO ' . $this->tbg_db_prefix . 'issues (id, issue_no, project_id, title, posted_by, assigned_to, duplicate_of, posted, last_updated, state, issuetype, category, resolution, priority, severity, reproducability, workflow_step_id, scope)
				VALUES (' . $row['id'] . ', ' . $row['issue_no'] . ', ' . $row['project_id'] . ', "' . $row['title'] . '", ' . $row['posted_by'] . ', ' . $row['assigned_to'] . ', ' . $row['duplicate_of'] . ', ' . $row['posted'] . ', ' . $row['last_updated'] . ', ' . $row['state'] . ', ' . $row['issuetype'] . ', ' . $row['category'] . ',  ' . $row['resolution'] . ', ' . $row['priority'] . ', ' . $row['severity'] . ', ' . $row['reproducability'] . ', 1, 1)
			');

			// This attempts to find any versions that got missed, but isn't accurate.
			if (!isset($builds[$row['version']]))
			{
				$this->tbg_db->query('
					REPLACE INTO (' . $this->tbg_db_prefix . 'builds (name, project) VALUES (' . $row['version'] . ', ' . $row['project_id'] . ')');

				$builds[$row['version']] = $this->tbg_db->lastInsertId();	
			}

			$affect_id = $builds[$row['version']];

			$this->tbg_db->query('
				REPLACE INTO (' . $this->tbg_db_prefix . 'issueaffectsbuild (id, build) VALUES(' . $row['id'] . ', ' . $affect_id . ')');

			++$i;
		}

		return $i;
	}

	/**
	* Bug Notes.
	*
	*/
	function doStep7()
	{
		$step_size = 500;
		$substep = $this->getSubStep(__FUNCTION__);

		$query = '
			SELECT
				bn.id, bn.bug_id AS target_id, bn.last_modified AS updated, bn.date_submitted AS posted,
				bn.reporter_id AS updated_by, bn.reporter_id AS posted_by, bnt.note AS content
				FROM ' . $this->mbt_db_prefix . 'bugnote_table AS bn
					INNER JOIN ' . $this->mbt_db_prefix . 'bugnote_text_table AS bnt ON (bn.id = bnt.id)
			LIMIT ' . $step_size . ' OFFSET ' . $substep;

		$i = 0;
		foreach ($this->mantis_db->query($query) as $row)
		{
			$this->tbg_db->query('
				REPLACE INTO ' . $this->tbg_db_prefix . 'comments (id, target_id, updated, posted, updated_by, posted_by, content)
				VALUES (' . $row['id'] . ', ' . $row['target_id'] . ', ' . $row['updated'] . ', ' . $row['posted'] . ', ' . $row['updated_by'] . ', ' . $row['posted_by'] . ', "' . $row['content'] . '")');

			++$i;
		}

		return $i;
	}

	/**
	* Relationships are great.
	*
	*/
	function doStep8()
	{
		$step_size = 500;
		$substep = $this->getSubStep(__FUNCTION__);

		$query = '
			SELECT
				source_bug_id AS parent_id, destination_bug_id AS child_id
				FROM ' . $this->mbt_db_prefix . 'bug_relationship_table
			LIMIT ' . $step_size . ' OFFSET ' . $substep;

		$i = 0;
		foreach ($this->mantis_db->query($query) as $row)
		{
			$this->tbg_db->query('
				REPLACE INTO ' . $this->tbg_db_prefix . 'issuerelations (parent_id, child_id)
				VALUES (' . $row['parent_id'] . ', ' . $row['child_id'] . ')');

			++$i;
		}

		return $i;
	}

	/**
	* Attachments, the pain of our every existence)
	*
	*/
	function doStep9()
	{
		$step_size = 100;
		$substep = $this->getSubStep(__FUNCTION__);

		// @TODO: GET THE ATTACHMENT LOCATION
		/*
		// TGB appears to allow storage of files in the database.  The source code appears to work it out properly whether it is in the database or local storage.
		$result = $this->db->query('SELECT upload_localpath FROM ' . $this->tbg_db_prefix . 'settings WHERE name = "upload_localpath" AND scope = 1');
		$attachment_path = $result['upload_localpath'];
		*/

		$query = '
			SELECT
				id, user_id AS uid, 1 AS scope, filename AS real_filename, filename AS original_filename,
				file_type AS content_type, content, date_added AS uploaded_at, description
				FROM ' . $this->mbt_db_prefix . 'bug_file_table
			LIMIT ' . $step_size . ' OFFSET ' . $substep;

		$i = 0;
		foreach ($this->mantis_db->query($query) as $row)
		{
			// First we have to clean this up.
			$row['content'] = $this->tbg_db->quote($row['content']);

			$this->tbg_db->query('
				REPLACE INTO ' . $this->tbg_db_prefix . 'files (id, uid, scope, real_filename, original_filename, content_type, content, uploaded_at, description)
				VALUES (' . $row['id'] . ', ' . $row['uid'] . ', ' . $row['scope'] . ', "' . $row['real_filename'] . '", "' . $row['original_filename'] . '", "' . $row['content_type'] . '", "' . $row['content'] . '", ' . $row['uploaded_at'] . ', "' . $row['description'] . '")');

			++$i;
		}

		return $i;
	}

	// @ TODO: Duplicate function, merge or remove.
	public function getIssues()
	{
		// Get the basics - we'll fix it up in a minute.
		$this->db->query('
			REPLACE INTO ' . $this->tbg_prefix . 'issues
				id, project_id, title, assigned_to, duplicate_of, posted, last_updated,
				state, category, resolution, priority, severity, reproducability
			SELECT bt.id, bt.project_id, bt.summary AS title, bt.handler_id AS assigned_to,
				bt.duplicate_id AS duplicate_of, bt.date_submitted AS posted, bt.last_updated,
				bt.status AS state, bt.category_id AS category, bt.resolution, bt.priority,
				bt.severity, bt.reproducability, btt.steps_to_reproduce AS reproduction_steps, btt.description
			FROM ' . $this->mtb_prefix . 'bug_table AS bt
			LEFT JOIN ' . $this->mtb_prefix . 'bug_text_table AS btt ON (btt.id = bt.bug_text_id)
			'
		);

		// Update reproducability
		// Mantis (always = 10; sometimes = 30; random = 50; have not tried = 70; unable to reproduce = 90; N/A = 100)
		// TBG (Always = 12; Often = 11; Rarely = 10; Can't reproduce = 9)
		// Mantis->TBG (10->12, 30->11, 50->10, 90->9, 70->null, 100->null)
		$this->db->query('
			UPDATE ' . $this->tbg_prefix . 'issues
			SET reproducability =
				CASE reproducability
					WHEN 10 THEN 12
					WHEN 30 THEN 11
					WHEN 50 THEN 10
					WHEN 90 THEN 9
					WHEN 70 THEN null
					WHEN 100 THEN null
		');

		// Update severity
		// Mantis (feature = 10; trivial = 20; text = 30; tweak = 40; minor = 50; major = 60; crash = 70; block = 80;)
		// TBG (Low = 20; Normal = 21; Critical = 22)
		// Mantis->TBG ()
		$this->db->query('
			UPDATE ' . $this->tbg_prefix . 'issues
			SET severity =
				CASE severity
					WHEN 10 THEN 21
					WHEN 20 THEN 20
					WHEN 30 THEN 20
					WHEN 40 THEN 20
					WHEN 50 THEN 20
					WHEN 60 THEN 21
					WHEN 70 THEN 22
					WHEN 80 THEN 22
					ELSE 21
				END CASE
		');

		// Update priority
		// Mantis (none = 10; low = 20; normal = 30; high = 40; urgent = 50; immediate = 60)
		// TBG()
		$this->db->query('
			UPDATE ' . $this->tbg_prefix . 'issues
			SET priority =
				CASE priority
					WHEN 10 THEN 
					WHEN 20 THEN 
					WHEN 30 THEN 
					WHEN 40 THEN 
					WHEN 50 THEN 
					WHEN 60 THEN 
					WHEN 70 THEN 
					WHEN 80 THEN 
		');
	}

	// @ TODO: Duplicate function, merge or remove.
	public function getComments()
	{
		$this->db->query('
			REPLACE INTO ' . $this->tbg_prefix . 'comments
				target_id, updated, posted, updated_by, posted_by, content			
			SELECT bn.bug_id AS target_id, bn.last_modified AS updated, bn.date_submitted AS posted,
				bn.reporter_id AS updated_by, bn.reporter_id AS posted_by, btt.note AS content
			FROM ' . $this->mtb_prefix . 'bugnote_table AS bn
			LEFT JOIN ' . $this->mtb_prefix . 'bugnote_text_table AS btt ON(' . $this->mtb_prefix . 'bugnote_text_table.id = ' . $this->mtb_prefix . 'bugnote_table.id)'
		);
		
	}

	// @ TODO: Duplicate function, merge or remove.
	public function getProjects()
	{
		$this->db->query('
			REPLACE INTO ' . $this->tbg_prefix . 'projects
				id, name, locked, description	
			SELECT id, name, enabled AS locked, description
			FROM ' . $this->mtb_prefix . 'project_table'
		);
	}
}

/** 
 * Theme wrapper.
 */
class tbg_converter_wrapper
{
	protected $page_title = 'Mantis to The Bug Genie converter';
	protected $headers = array();
	protected $errors = array();
	protected $steps = array();
	protected $step = 0;
	protected $substep = 0;
	protected $time = 0;
	protected $credits = array();

	/** 
	 * Start the HTML.
	*/
	public function __construct($steps, $credits = array())
	{
		$this->steps = $steps;
		$this->credits = $credits;

		ob_start();
	}

	/** 
	 * End of output.
	*/
	public function done($is_cli = false)
	{
		if ($is_cli)
			exit("\nConversion completed");

		$contents = ob_get_contents();
		ob_end_clean();

		// Some headers.
		$this->printHeader();

		// Upper part of theme.
		$this->upper();

		echo $contents;

		$this->lower();
		exit;
	}

	/*
	* Set the title.
	* @param $title: The page title to set
	*/
	public function setTitle($title)
	{
		$this->page_title = $title;
	}

	/*
	* Any custom header()s
	*
	*/
	public function printHeader()
	{
		foreach ($this->headers as $type => $data)
			header($type . ': ' . $data);
	}

	/*
	* Set headers.
	*
	*/
	public function setHeader($type, $data)
	{
		$this->headers[$type] = $data;
	}

	/*
	* Set the title.
	* @param $title: The page title to set
	*/
	public function updateData($data)
	{
		foreach ($data as $key => $value)
			$this->{$key} = $value;
	}

	/*
	* Return a json array.
	* @param $data: The json data.
	*/
	public function return_json($data)
	{
		header('Content-Type', 'text/javascript; charset=utf8');
		
		echo json_encode($data);
		exit;
	}

	/*
	* Set the title.
	* @param $errors: We have some errors!
	*/
	public function errors($errors)
	{
		$this->errors = array_merge($this->errors, $errors);
	}

	/*
	* The upper part of the theme.
	*
	*/
	public function upper()
	{
		echo '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/html">
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
	<title>', $this->page_title, '</title>
	<style type="text/css">
	<!--
	html, body
	{
		height: 100%;
	}
	body
	{
		/* Copy SMFs background gradient if we are cool enough */
		background-color: #E9EEF2;
		filter: progid:DXImageTransform.Microsoft.gradient(startColorstr=\'#375976\', endColorstr=\'#e9eef2\');
		background: -webkit-gradient(linear, left top, left bottom, from(#375976), to(#e9eef2));
		background: -moz-linear-gradient(top,  #375976,  #e9eef2);
		background: -o-linear-gradient(#375976, #e9eef2 30em);
		font-family: "Geneva", "verdana", sans-serif;

		padding: 1em 4em 0 4em;
		margin: auto;
		min-width: 50em;
	}

	#main_block
	{
		border: 2px solid #ffffff;
		border-top-left-radius: 10px;
		border-top-right-radius: 10px;

		/* Copy SMFs background gradient if we are cool enough */
		background-color: #c9d7e7;
		filter: progid:DXImageTransform.Microsoft.gradient(startColorstr=\'#c9d7e7\', endColorstr=\'#fcfdfe\');
		background: -webkit-gradient(linear, left top, left bottom, from(#c9d7e7), to(#fcfdfe));
		background: -moz-linear-gradient(top,  #c9d7e7,  #fcfdfe);
		background: -o-linear-gradient(#c9d7e7, #fcfdfe 10em);

	}
	#logo_block
	{
		padding: 0.3em 0 0.3em 0;
		margin: 0 0 0 1em;
	}
	#logo_block h1
	{
		padding: 0;
		margin: 0;

		color: #334466;
		text-decoration: bold;
		font-size: 1.4em;
		line-height: 45px;
	}
	#content_block
	{
		margin: 0 0.3em 0 0.3em;
		padding: 1em;

		border-top-left-radius: 10px;
		border-top-right-radius: 10px;

		/* Copy SMFs background gradient if we are cool enough */
		background-color: #c3cfde;
		filter: progid:DXImageTransform.Microsoft.gradient(startColorstr=\'#c3cfde\', endColorstr=\'#fcfdfe\');
		background: -webkit-gradient(linear, left top, left bottom, from(#c3cfde), to(#fcfdfe));
		background: -moz-linear-gradient(top,  #c3cfde,  #fcfdfe);
		background: -o-linear-gradient(#c3cfde, #fcfdfe 5em);

	}

	#footer
	{
		/* Copy SMFs background gradient if we are cool enough */
		filter: progid:DXImageTransform.Microsoft.gradient(startColorstr=\'#f3f6f9\', endColorstr=\'#e1e9f3\');
		background: -webkit-gradient(linear, left top, left bottom, from(#f3f6f9), to(#e1e9f3));
		background: -moz-linear-gradient(top,  #f3f6f9,  #e1e9f3);
		background: -o-linear-gradient(#f3f6f9, #e1e9f3);

		border-left: 1px solid white;
		border-right: 1px solid white;

		border-bottom-left-radius: 10px;
		border-bottom-right-radius: 10px;

		padding: 0.5em 0;
		margin: 0;

		text-align: center;
		font-size: 70%;
	}
	#footer span
	{
		padding-left: 5em;
	}

	dl
	{
		clear: right;
		overflow: auto;

		border: 1px solid #bbb;
		background: #f5f5f0;

		margin: 0 0 0 0;
		padding: 0.5em;
	}
	dl dt
	{
		width: 40%;
		float: left;
		margin: 0 0 10px 0;
		padding: 0;
		clear: both;
	}
	dl dd
	{
		width: 56%;
		float: right;
		overflow: auto;
		margin: 0 0 3px 0;
		padding: 0;
	}
	dl input[type=text], dl input[type=password]
	{
		width: 25em;
	}

	#steps .waiting, #steps .done, #steps .current
	{
		border-radius: 5px;
	}
	#steps li
	{
		margin: 0.5em;
		padding: 0.5em;
	}
	#steps li span
	{
		color: white;
		text-decoration: bold;
	}
	#steps li .name
	{
		width: 30em;
		min-width: 30em;
		padding-right: 3em;
	}
	#steps .waiting, #steps .done
	{
		background-color: #5a6c85;
	}
	#steps .current
	{
		background-color: #fd9604;
	}

	#steps li .progress_bar, #steps li .progress_status
	{
		position: absolute;
		left: 10em;
		padding-left: 10em;
	}
	#steps li .progress_bar .progress_box
	{
		border: 1px solid black;
		width: 20em;
		display: block;
		background-color: white;
		padding: 1px;
		border-radius: 5px;
	}
	#steps li .progress_bar .progress_box .progress_percent
	{
		background-color: red;
		display: block;
		border-radius: 5px;
		padding-left: 0.3em;
		color: black;
		text-decoration: bold;
	}
	-->
	</style>
</head>
<body>
	<div id="main_block">
		<div id="logo_block">
			<h1>', $this->page_title, '</h1>
		</div>
		<div id="content_block">';

		if (!empty($this->errors))
		{
			echo '
			<div class="error">
				<ul>
					<li>', implode("</li>\n\t\t\t\t\t<li>", $this->errors), '
					</li>
				</ul>
			</div>';
		}

		echo '
			<div class="panel">';
	}

	/*
	* The lower part of the theme.
	*
	*/
	public function lower()
	{
		echo '
			</div>
		</div>
	</div>
		<div id="footer"><span>', implode('</span><span>', $this->credits), '</span></div>
</body></html>';

	}

	/*
	* Show some settings.
	*
	*/
	public function showSettings($settings)
	{
		echo '
				<form action="', $_SERVER['PHP_SELF'], '?step=1" method="post">
					<dl>';

		foreach ($settings as $key => $data)
		{
			echo '
						<dt id="', $key, '_name">', $data['name'], '</dt>
						<dd id="', $key, '_field">';

			if ($data['type'] == 'textarea')
				echo '<textarea name="', $key, '">', isset($data['default']) ? $data['default'] : '', '</textarea>';
			elseif ($data['type'] == 'select')
			{
				echo '<select name="', $key, '">';
				
				foreach ($data['options'] as $opt => $value)
					echo '
							<option name="', $opt, '">', $value, '</option>';

				echo '
						</select>';
			}
			elseif ($data['type'] == 'password')
				echo '<input type="password" name="', $key, '" />';
			else
				echo '<input type="text" name="', $key, '"', isset($_POST[$key]) ? ' value="' . $_POST[$key] . '"' : (isset($data['default']) ? $data['default'] : ''), ' />';

			echo '</dd>';
		}

		echo '
						<dt><input name="save" type="submit" value="Start conversion" /></dt>
					</dl>
				</form>';
	}

	/*
	* We have some new data!.
	* @param $data: The json data.
	*/
	public function steps()
	{
		echo '<ol id="steps">';

		foreach ($this->steps as $id_step => $step)
		{
			echo '
				<li id="step', $id_step, '" class="';

			if ($id_step < $this->step)
				echo 'done';
			elseif ($id_step == $this->step)
				echo 'current';
			else
				echo 'waiting';

			echo '" ><span class="name">', $step[0], '</span><span class="progress_bar">';

			// The progress bar.
			if ($id_step == $this->step)
				echo '<span class="progress_box"><span class="progress_percent" id="progress_percent" style="width: 1%;">100%</span></span>';

			echo '</span><span class="progress_status">';

			if ($id_step < $this->step)
				echo 'completed';

			echo '</span></li>';
		}

		echo '</ol>';
	}
}

$convert = new mbt_to_tbg();