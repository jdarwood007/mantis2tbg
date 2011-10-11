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
	protected $mbt_db_prefix;
	protected $tbg_db_prefix;

	// The start timer.
	protected $start_time = 0;

	// Is this CLI?
	protected $is_cli = false;

	// The database connection resource.
	protected $db;

	function __construct()
	{
		// Start your engines.
		$this->start_time = time();

		// Don't timeout!
		set_time_limit(0);

		if (function_exists('apache_reset_timeout'))
			apache_reset_timeout();

		// Try to find some settings.
		$this->loadSettings();
		
		// We can't process anymore until this exists.
		if (empty($this->db_user))
		{
		}
		else
		{
			$this->setDatabasePrefix();
			$this->getDatabaseConnection();
		}
	}

	/**
	* Request these settings during setup.
	*
	*/
	private function converterSettings()
	{
		return array(
			'mantis_loc' => array('type' => 'text', 'required' => true, 'validate' => 'return file_exists($data);'),
			'tbg_loc' => array('type' => 'text', 'required' => true, 'default' => dirname(__FILE__), 'validate' => 'return file_exists($data);')
			// @TODO: Make this validate the password.
			'tbg_db_pass' => array('type' => 'password', 'required' => true, 'validate' => true,),
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
	}

	/**
	* Save the settings..
	*
	*/
	private function setSettings()
	{
		$settings = $this->converterSettings()
		$new_settings = array();
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

		// Save these.
		$_SESSION['tbg_converter'] = serialize($new_settings);
	}

	/**
	 * Set the prefix that will be used prior to every reference of a table
	 */
	private function setDatabasePrefix()
	{
		$this->tbg_db_prefix = $this->tbg_db_name . '.' . $this->tbg_db_table_prefix;
		$this->mbt_db_prefix = $this->mbt_db_name . '.' . $this->mbt_db_table_prefix;
	}

	/**
	 * Establish a database connection
	 */
	function getDatabaseConnection()
	{
		$this->db = new PDO ($this->db_driver . ':host=' . $this->db_host, $this->db_user, $this->db_pass);
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
	* Gets the current substep
	*
	* @param string $step The current function we are on.
	*/
	function getSubStep($step)
	{
		if (!isset($_GET['step']) || !isset($_GET['substep']) || str_replace('doStep', '', $step) != $_GET['step'])
			return 0;
		else
			return (int) $_GET['substep'];
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
	}

	/**
	*
	* Checks our timeout status and attempts to allow us to work longer.
	*
	* @param $function The function name we should return to if we can continue.
	*/
	function checkTimeout($function)
	{
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
	}
}

class mbt_to_tbg extends tbg_converter
{
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
		foreach ($this->db->query($sql) as $row)
		{
			$password = $this->getRandomString();

			$this->db->query('
				INSERT INTO ' . $this->tbg_db_prefix . 'users (id, username, buddyname, realname, email, password, enabled, lastseen, joined)
				VALUES (' . $row['id'] . ', "' . $row['username'] . '", "' . $row['buddyname'] . '", "' . $row['realname'] . '", "' . $row['email'] . '", "' . $password . '", ' . $row['enabled'] . ', ' . $row['username'] . ', ' . $row['joined'] . ')');
		}

		$this->updateSubStep($substep + $step_size);
		$this->checkTimeout(__FUNCTION__);
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

		foreach ($this->db->query($sql) as $row)
			$this->db->query('
				INSERT INTO ' . $this->tbg_db_prefix . 'projects (id, name, locked, description)
				VALUES (' . $row['id'] . ', "' . $row['name'] . '", ' . $row['locked'] . ', "' . $row['description'] . '")');

		$this->updateSubStep($substep + $step_size);
		$this->checkTimeout(__FUNCTION__);
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

		foreach ($this->db->query($sql) as $row)
			$this->db->query('
				INSERT INTO ' . $this->tbg_db_prefix . 'listtypes (name, itemtype, scope)
				VALUES (' . $row['name'] . ', "category", 1)');

		$this->updateSubStep($substep + $step_size);
		$this->checkTimeout(__FUNCTION__);
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

		foreach ($this->db->query($sql) as $row)
		{
			if (isset($builds[$version]))
				continue;

			$this->db->query('
				INSERT INTO (' . $this->tbg_db_prefix . 'builds (name) VALUES (' . $row['version'] . ')');

			$builds[$row['version']] = $this->db->lastInsertId();

		}

		$this->updateSubStep($substep + $step_size);
		$this->checkTimeout(__FUNCTION__);
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

		foreach ($this->db->query($sql) as $row)
		{
			$this->db->query('
				INSERT INTO ' . $this->tbg_db_prefix . 'issues (id, project_id, title, assigned_to, duplicate_of, posted, last_updated, state, category, resolution, priority, severity, reproducability)
				VALUES (' . $row['id'] . ', ' . $row['project_id'] . ', "' . $row['title'] . '", ' . $row['assigned_to'] . ', ' . $row['duplicate_of'] . ', ' . $row['posted'] . ', ' . $row['last_updated'] . ', ' . $row['state'] . ', ' . $row['category'] . ', ' . $row['category'] . ', ' . $row['resolution'] . ', ' . $row['priority'] . ', ' . $row['severity'] . ', ' . $row['reproducability'] . ')');

			if (!isset(builds[$row['version']]))
			{
				$this->db->query('
					INSERT INTO (' . $this->tbg_db_prefix . 'builds (name) VALUES (' . $row['version'] . ')');

				$builds[$row['version']] = $this->db->lastInsertId();	
			}

			$affect_id = $builds[$row['version']];

			$this->db->query('
				INSERT INTO (' . $this->tbg_db_prefix . 'issueaffectsbuild (id, build) VALUES(' . $row['id'] . ', ' . $affect_id . ')');
		}

		$this->updateSubStep($substep + $step_size);
		$this->checkTimeout(__FUNCTION__);
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

		foreach ($this->db->query($sql) as $row)
			$this->db->query('
				INSERT INTO ' . $this->tbg_db_prefix . 'comments (id, target_id, updated, posted, updated_by, posted_by, content)
				VALUES (' . $row['id'] . ', ' . $row['target_id'] . ', ' . $row['updated'] . ', ' . $row['posted'] . ', ' . $row['updated_by'] . ', ' . $row['posted_by'] . ', "' . $row['content'] . '")');

		$this->updateSubStep($substep + $step_size);
		$this->checkTimeout(__FUNCTION__);
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

		foreach ($this->db->query($sql) as $row)
			$this->db->query('
				INSERT INTO ' . $this->tbg_db_prefix . 'issuerelations (parent_id, child_id)
				VALUES (' . $row['parent_id'] . ', ' . $row['child_id'] . ')');


		$this->updateSubStep($substep + $step_size);
		$this->checkTimeout(__FUNCTION__);
	}

	/**
	* Attachments, the pain of our every existence)
	*
	*/
	function doStep8
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

		foreach ($this->db->query($sql) as $row)
			$this->db->query('
				INSERT INTO ' . $this->tbg_db_prefix . 'files (id, uid, scope, real_filename, original_filename, content_type, content, uploaded_at, description)
				VALUES (' . $row['id'] . ', ' . $row['uid'] . ', ' . $row['scope'] . ', "' . $row['real_filename'] . '", "' . $row['original_filename'] . '", "' . $row['content_type'] . '", "' . $row['content'] . '", ' . $row['uploaded_at'] . ', "' . $row['description'] . '")');

		$this->updateSubStep($substep + $step_size);
		$this->checkTimeout(__FUNCTION__);

	}
}

/*
* Theme wrapper.
*
*/
class tbg_converter_wrapper
{
	protected $page_title = 'Mantis to The Bug Genie converter';

	/*
	* Set the title.
	* @param $title: The page title to set
	*/
	public static function setHeader($title)
	{
		self:$page_title = $title;
	}

	/*
	* Any custom header()s
	*
	*/
	public static function header()
	{

	}

	/*
	* The upper part of the theme.
	*
	*/
	public static function upper()
	{
		echo '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/html">
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
	<title>', self:$page_title, '</title>
	<style type="text/css">
	<!--
	-->
	</style>' : ''), '
</head>
<body>
	<div>
		<div style="padding: 10px; padding-right: 0px; padding-left: 0px; width:98% ">
			<div style="padding-left: 200px; padding-right: 0px;">
				<h1>', self:$page_title, '</h1>
				<div class="panel" style="padding-right: 0px;  white-space: normal; overflow: hidden;">';
	}

	/*
	* The lower part of the theme.
	*
	*/
	public static function lower();
	{
		echo '
				</div>
			</div>
		</div>
	</div>
</body></html>';

	}
}