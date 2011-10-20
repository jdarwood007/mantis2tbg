<?php
// @NOTE: THIS CONVERTER IS IN DEVELOPMENT AND IS MOSTLY THEORETICAL UNTESTED CODE.

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
*/

class tbg_coverter
{
	// Where your config_inc.php is located
	//const MBT_PATH = '';
	// Where your b2db_boostrap.inc.php is located
	//const TBG_PATH = '';

	// The host, user, and pass must be on the same server
	protected $db_driver = 'mysql';
	protected $db_host = 'localhost';
	protected $db_user = '';
	protected $db_pass = '';

	protected $mbt_db_name = 'mantis';
	protected $mbt_db_table_prefix = 'mantis_';

	protected $tbg_db_name = 'tbg';
	protected $tbg_db_table_prefix = 'tbg3_';

	// Scope (usually just 1)
	protected $tbg_scope = 1;

	// The database prefixes (including the database).
	protected $tbg_db_prefix;

	// The start timer.
	protected $start_time = 0;

	// Step info.
	private $substep = 0;
	private $step = 0;

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

		// Don't timeout!
		set_time_limit(0);

		if (function_exists('apache_reset_timeout'))
			apache_reset_timeout();

		// Try to find some settings.
		$this->loadSettings();

		// Open a new theme.
		$theme = new tbg_converter_wrapper();

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
	private function doConversion()
	{
		$this->setDatabasePrefix();
		$this->getDatabaseConnection();

		// Fire this thing off.
		$this->loadSettings();

		// Now restart.
		do
		{
			$data = $steps[$this->step];

			$this->updateSubStep($this->substep);
			$this->updateStep('doStep' . $this->step);
			$this->step_size = $data[2];
		}
		while
		{
			$count = $data[1]();

			$this->checkTimeout($data[1], $this->substep, $data[2], $count);
		}
	}

	/**
	* Ask the user for some settings, validate and then start conversion.
	*
	*/
	private function converterSetup()
	{
		global $theme;

		if (isset($_POST['save']))
		{
			$errors = $this->setSettings();

			if ($errors === null)
			{
				// From here on, its all automatic.
				$this->updateStep('doStep1');
				$this->doConversion();
			}
		}

		// Prompt for some settings.
		$theme->showSettings($this->converterSettings());
	}

	/**
	* Request these settings during setup.
	*
	*/
	private function converterSettings()
	{
		return array(
			'tbg_loc' => array('name' => 'The Bug Genie location', 'type' => 'text', 'required' => true, 'default' => dirname(__FILE__), 'validate' => 'return file_exists($data);'),
			// @TODO: Make this validate the password.
			'tbg_db_pass' => array('name' => 'The Bug Genie database password', 'type' => 'password', 'required' => true, 'validate' => true,),
		);
	}

	/**
	* Request these settings during setup.
	*
	*/
	private function loadSettings()
	{
		// Lets check our session.
		if (session_id() == '')
			session_start();

		if (isset($_SESSION['tbg_converter']))
		{
			foreach (unserialize($_SESSION['tbg_converter']) as $key => $data)
				$this->{$key} = $data;

			return;
		}

		// Load some from the url.
		$this->step = (int) $_GET['step'];
		$this->substep = (int) $_GET['substep'];
	}

	/**
	* Save the settings..
	*
	*/
	private function setSettings()
	{
		$settings = $this->converterSettings();
		$new_settings = array();
		$errors = array();

		foreach ($settings as $key => $details)
		{
			// We are saving then
			if (isset($_POST[$key]))
			{
				if (isset($details['validate']) && eval($details['validate']) !== true)
					$errors[$key] = $key . ' contains invalid_data';

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
	 * Set the prefix that will be used prior to every reference of a table
	 */
	private function setTBGDatabasePrefix()
	{
		$this->tbg_db_prefix = $this->tbg_db_name . '.' . $this->tbg_db_table_prefix;

		return true;
	}
	/**

	 * Establish a database connection
	 */
	function getDatabaseConnection()
	{
		$this->db = new PDO ($this->db_driver . ':host=' . $this->db_host, $this->db_user, $this->db_pass);

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
		$chars_len = strlen($chars);

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
		$_GET['step'] = (int) $step;
		$this->step = (int) $step;

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
		if ($max_step_size < $count)
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

		if (!is_int($real_step) || (int) $real_step == 0)
		{
			debug_print_backtrace();
			exit;
		}

		// If ajax, return some data.
		$data = array(
			'step' => $function,
			'time' => time(),
			'substep' => getSubStep($function),
		);

		if ($this->is_json)
			$theme->return_json($data);
	}
}

class mbt_to_tbg extends tbg_converter
{
	protected $mbt_db_prefix;

	// What steps shall we take.
	protected $steps = array(
		// Key => array('Descriptive', 'functionName', (int) step_size),
		0 = > array('Users', 'doStep1', 500),
		1 = > array('Projects', 'doStep2', 500),
		2 = > array('Categories', 'doStep3', 500),
		3 = > array('Versions', 'doStep4', 500),
		4 = > array('Issues', 'doStep5', 500),
		5 = > array('Comments', 'doStep6', 500),
		6 = > array('Relationships', 'doStep7', 500),
		7 = > array('Attachments', 'doStep8', 100),
	);
	/**
	 * Set the prefix that will be used prior to every reference of a table
	 */
	private function setDatabasePrefix()
	{
		$this->setTBGDatabasePrefix();
		$this->mbt_db_prefix = $this->mbt_db_name . '.' . $this->mbt_db_table_prefix;
	}

	/**
	* Request these settings during setup.
	*
	*/
	private function converterSettings()
	{
		$settings = parent::converterSettings();

		// As long as we don't overwrite, this should work.
		return $settings + array(
			'mantis_loc' => array('type' => 'text', 'required' => true, 'validate' => 'return file_exists($data);'),
		);
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
				enabled, last_vist AS lastseen, date_created AS joined
			FROM
				' . $this->mbt_db_prefix . 'user_table
			LIMIT ' . $substep . ' ' . $step_size;

		// We could let this save up a few inserts then do them at once, but are we really worried about saving queries here?
		$i = 0;
		foreach ($this->db->query($sql) as $row)
		{
			$password = $this->getRandomString();

			$this->db->query('
				INSERT INTO ' . $this->tbg_db_prefix . 'users (id, username, buddyname, realname, email, password, enabled, lastseen, joined)
				VALUES (' . $row['id'] . ', "' . $row['username'] . '", "' . $row['buddyname'] . '", "' . $row['realname'] . '", "' . $row['email'] . '", "' . $password . '", ' . $row['enabled'] . ', ' . $row['username'] . ', ' . $row['joined'] . ')');

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
				(CASE WHEN enabled = 0 THEN 1 ELSE 0) AS locked
				FROM ' . $this->mbt_db_prefix . 'project_table
			LIMIT ' . $substep . ' ' . $step_size;

		$i = 0;
		foreach ($this->db->query($sql) as $row)
		{
			$this->db->query('
				INSERT INTO ' . $this->tbg_db_prefix . 'projects (id, name, locked, description)
				VALUES (' . $row['id'] . ', "' . $row['name'] . '", ' . $row['locked'] . ', "' . $row['description'] . '")');

			++$i;
		}

		return $i;
	}

	/**
	* Convert Categories.
	*
	*/
	function doStep3()
	{
		$step_size = 500;
		$substep = $this->getSubStep(__FUNCTION__);

		$query = '
			SELECT
				name
				FROM ' . $this->mbt_db_prefix . 'category_table
			LIMIT ' . $substep . ' ' . $step_size;

		$i = 0;
		foreach ($this->db->query($sql) as $row)
		{
			$this->db->query('
				INSERT INTO ' . $this->tbg_db_prefix . 'listtypes (name, itemtype, scope)
				VALUES (' . $row['name'] . ', "category", 1)');

			++$i;
		}

		return $i;
	}

	/**
	* Convert Versions.
	*
	*/
	function doStep4()
	{
		$step_size = 500;
		$substep = $this->getSubStep(__FUNCTION__);

		// Obtain any current builds.
		$query = '
			SELECT
				id, name
				FROM ' . $this->mbt_db_prefix . 'builds';
		$builds = array();
		foreach ($this->db->query($sql) as $row)
			$builds[$row['name']] = $row['id'];

		$query = '
			SELECT
				id, project_id, version, released AS isreleased
				FROM ' . $this->mbt_db_prefix . 'project_version_table
			LIMIT ' . $substep . ' ' . $step_size;

		$i = 0;
		foreach ($this->db->query($sql) as $row)
		{
			if (isset($builds[$version]))
				continue;

			$this->db->query('
				INSERT INTO (' . $this->tbg_db_prefix . 'builds (name) VALUES (' . $row['version'] . ')');

			$builds[$row['version']] = $this->db->lastInsertId();

			++$i;
		}

		return $i;
	}


	/**
	* Normally we want to fix them, but in this case we want to convert bugs.
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
				FROM ' . $this->mbt_db_prefix . 'builds';
		$builds = array();
		foreach ($this->db->query($sql) as $row)
			$builds[$row['name']] = $row['id'];

		$query = '
			SELECT
				bt.id, bt.project_id, bt.summary AS title, bt.handler_id AS assigned_to, bt.duplicate_id AS duplicate_of,
				bt.date_submitted AS posted, bt.last_updated, bt.status AS state, bt.cagegory_id AS category,
				bt.priority,
				bt.severity /* NEEDS FIXED */,
				(CASE
					WHEN bt.reproducability = 10 THEN 12
					WHEN bt.reproducability > 30 AND bt.reproducability < 70 THEN 11
					WHEN bt.reproducability > 70 AND bt.reproducability < 90 THEN 9
					ELSE 0) AS reproducability,
					IFNULL(btt.steps_to_reproduce, "") AS reproduction_steps
					IFNULL(btt.description, "") AS description,
					version
				FROM ' . $this->mbt_db_prefix . 'bug_table AS bt
					LEFT JOIN ' . $this->mbt_db_prefix . 'bug_text_table AS btt ON (btt.id = bt.bug_text_id)
			LIMIT ' . $substep . ' ' . $step_size;

		$i = 0;
		foreach ($this->db->query($sql) as $row)
		{
			$this->db->query('
				INSERT INTO ' . $this->tbg_db_prefix . 'issues (id, project_id, title, assigned_to, duplicate_of, posted, last_updated, state, category, resolution, priority, severity, reproducability)
				VALUES (' . $row['id'] . ', ' . $row['project_id'] . ', "' . $row['title'] . '", ' . $row['assigned_to'] . ', ' . $row['duplicate_of'] . ', ' . $row['posted'] . ', ' . $row['last_updated'] . ', ' . $row['state'] . ', ' . $row['category'] . ', ' . $row['category'] . ', ' . $row['resolution'] . ', ' . $row['priority'] . ', ' . $row['severity'] . ', ' . $row['reproducability'] . ')
			');

			if (!isset($builds[$row['version']]))
			{
				$this->db->query('
					INSERT INTO (' . $this->tbg_db_prefix . 'builds (name) VALUES (' . $row['version'] . ')');

				$builds[$row['version']] = $this->db->lastInsertId();	
			}

			$affect_id = $builds[$row['version']];

			$this->db->query('
				INSERT INTO (' . $this->tbg_db_prefix . 'issueaffectsbuild (id, build) VALUES(' . $row['id'] . ', ' . $affect_id . ')');

			++$i;
		}

		return $i;
	}

	/**
	* Bug Notes.
	*
	*/
	function doStep6()
	{
		$step_size = 500;
		$substep = $this->getSubStep(__FUNCTION__);

		$query = '
			SELECT
				bn.id, bn.bug_id AS target_id, bn.last_modified AS updated, bn.date_submitted AS posted,
				bn.reporter_id AS updated_by, bn.reporter_id AS posted_by, bnt.note AS content
				FROM ' . $this->mbt_db_prefix . 'bugnote_table AS bn
					INNER JOIN ' . $this->mbt_db_prefix . 'butnote_text_table AS bnt ON (bn.id = bnt.bug_text_id)
			LIMIT ' . $substep . ' ' . $step_size;

		$i = 0;
		foreach ($this->db->query($sql) as $row)
		{
			$this->db->query('
				INSERT INTO ' . $this->tbg_db_prefix . 'comments (id, target_id, updated, posted, updated_by, posted_by, content)
				VALUES (' . $row['id'] . ', ' . $row['target_id'] . ', ' . $row['updated'] . ', ' . $row['posted'] . ', ' . $row['updated_by'] . ', ' . $row['posted_by'] . ', "' . $row['content'] . '")');

			++$i;
		}

		return $i;
	}

	/**
	* Relationships are great.
	*
	*/
	function doStep7()
	{
		$step_size = 500;
		$substep = $this->getSubStep(__FUNCTION__);

		$query = '
			SELECT
				source_bug_id AS parent_id, destination_bug_id AS child_id
				FROM ' . $this->mbt_db_prefix . 'bug_relationships_table
			LIMIT ' . $substep . ' ' . $step_size;

		$i = 0;
		foreach ($this->db->query($sql) as $row)
		{
			$this->db->query('
				INSERT INTO ' . $this->tbg_db_prefix . 'issuerelations (parent_id, child_id)
				VALUES (' . $row['parent_id'] . ', ' . $row['child_id'] . ')');

			++$i;
		}

		return $i;
	}

	/**
	* Attachments, the pain of our every existence)
	*
	*/
	function doStep8()
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
			LIMIT ' . $substep . ' ' . $step_size;

		$i = 0;
		foreach ($this->db->query($sql) as $row)
		{
			$this->db->query('
				INSERT INTO ' . $this->tbg_db_prefix . 'files (id, uid, scope, real_filename, original_filename, content_type, content, uploaded_at, description)
				VALUES (' . $row['id'] . ', ' . $row['uid'] . ', ' . $row['scope'] . ', "' . $row['real_filename'] . '", "' . $row['original_filename'] . '", "' . $row['content_type'] . '", "' . $row['content'] . '", ' . $row['uploaded_at'] . ', "' . $row['description'] . '")');

			++$i;
		}

		return $i;
	}

	// @ TODO: Duplicate function, merge or remove.
	private function getIssues()
	{
		// Get the basics - we'll fix it up in a minute.
		$this->db->query('
			INSERT INTO ' . $this->tbg_prefix . 'issues
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
	private function getComments()
	{
		$this->db->query('
			INSERT INTO ' . $this->tbg_prefix . 'comments
				target_id, updated, posted, updated_by, posted_by, content			
			SELECT bn.bug_id AS target_id, bn.last_modified AS updated, bn.date_submitted AS posted,
				bn.reporter_id AS updated_by, bn.reporter_id AS posted_by, btt.note AS content
			FROM ' . $this->mtb_prefix . 'bugnote_table AS bn
			LEFT JOIN ' . $this->mtb_prefix . 'bugnote_text_table AS btt ON(' . $this->mtb_prefix . 'bugnote_text_table.id = ' . $this->mtb_prefix . 'bugnote_table.id)'
		);
		
	}

	// @ TODO: Duplicate function, merge or remove.
	private function getProjects()
	{
		$this->db->query('
			INSERT INTO ' . $this->tbg_prefix . 'projects
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
	* Return a json array.
	* @param $data: The json data.
	*/
	public function return_json($data)
	{
		$this->setHeader('Content-Type', 'text/javascript; charset=utf8');

		echo json_encode($data);
		exit;
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
	-->
	</style>
</head>
<body>
	<div>
		<div style="padding: 10px; padding-right: 0px; padding-left: 0px; width:98% ">
			<div style="padding-left: 200px; padding-right: 0px;">
				<h1>', $this->page_title, '</h1>
				<div class="panel" style="padding-right: 0px;  white-space: normal; overflow: hidden;">';
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
	</div>
</body></html>';

	}

	/*
	* Show some settings.
	*
	*/
	public function showSettings($settings)
	{
		echo '
				<form action="', $_SERVER['PHP_SELF'], '" method="post" id="showSettings">
					<dl>';

		foreach ($settings as $key => $data)
		{
			echo '
						<dt id="', $key, '_name">', $data['name'], '</dt>
						<dd id="', $key, '_field">';

			if ($data['type'] == 'textarea')
				echo '<textarea name="', $key, '"></textarea>';
			elseif ($data['type'] == 'select')
			{
				echo '<select name="', $key, '">';
				
				foreach ($data['options'] as $opt => $value)
					echo '
							<option name="', $opt, '">', $value, '</option>';

				echo '
						</select>';
			}
			else
				echo '<input type="', $data['type'] == 'password' ? 'password' : 'text', '" name="', $key, '" />';

			echo '</dd>';
		}

		echo '
						<dt><input type="submit" value="Start conversion" /></dt>
					</dl>
				</form>';
	}


}