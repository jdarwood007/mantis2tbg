<?php

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

class mbt_to_tbg
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

		$this->setDatabasePrefix();
		$this->getDatabaseConnection();
	}

	/**
	 * Set the prefix that will be used prior to every reference of a table
	 */
	function setDatabasePrefix()
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
		$substep = $this->getSubStep(__FUNCTION__);

		$query = '
			SELECT
				id, username, username AS buddyname, realname, email, password,
				enabled, last_vist AS lastseen, date_created AS joined
			FROM
				' . $this->mbt_db_prefix . 'user_table
			LIMIT ' . $substep . ' 500');

		// We could let this save up a few inserts then do them at once, but are we really worried about saving queries here?
		foreach ($this->db->query($sql) as $row)
		{
			$password = $this->getRandomString();

			$this->db->query('
				INSERT INTO ' . $this->tbg_db_prefix . 'users (id, username, buddyname, realname, email, password, enabled, lastseen, joined)
				VALUES (' . $row['id'] . ', "' . $row['username'] . '", "' . $row['buddyname'] . '", "' . $row['realname'] . '", "' . $row['email'] . '", "' . $password . '", ' . $row['enabled'] . ', ' . $row['username'] . ', ' . $row['joined'] . ')');
		}

		$this->updateSubStep($substep + 500);
		$this->checkTimeout(__FUNCTION__);
	}

	/**
	* Convert Projects.
	*
	*/
	function doStep2()
	{
		$substep = $this->getSubStep(__FUNCTION__);

		$query = '
			SELECT
				id, name, description,
				(CASE WHEN enabled = 0 THEN 1 ELSE 0) AS locked
				FROM ' . $this->mbt_db_prefix . 'project_table
			LIMIT ' . $substep . ' 500');

		foreach ($this->db->query($sql) as $row)
			$this->db->query('
				INSERT INTO ' . $this->tbg_db_prefix . 'projects (id, name, locked, description)
				VALUES (' . $row['id'] . ', "' . $row['name'] . '", ' . $row['locked'] . ', "' . $row['description'] . '")');

		$this->updateSubStep($substep + 500);
		$this->checkTimeout(__FUNCTION__);
	}

	/**
	* Convert Categories.
	*
	*/
	function doStep3()
	{
		$substep = $this->getSubStep(__FUNCTION__);

		$query = '
			SELECT
				name
				FROM ' . $this->mbt_db_prefix . 'category_table
			LIMIT ' . $substep . ' 500');

		foreach ($this->db->query($sql) as $row)
			$this->db->query('
				INSERT INTO ' . $this->tbg_db_prefix . 'listtypes (name, itemtype, scope)
				VALUES (' . $row['name'] . ', "category", 1)');

		$this->updateSubStep($substep + 500);
		$this->checkTimeout(__FUNCTION__);
	}

	/**
	* Convert Versions.
	*
	*/
	function doStep4()
	{
		$substep = $this->getSubStep(__FUNCTION__);

	}


	/**
	* Normally we want to fix them, but in this case we want to convert bugs.
	*
	*/
	function doStep5()
	{
		$substep = $this->getSubStep(__FUNCTION__);

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
			LIMIT ' . $substep . ' 500');

		foreach ($this->db->query($sql) as $row)
		{
			$this->db->query('
				INSERT INTO ' . $this->tbg_db_prefix . 'issues (id, project_id, title, assigned_to, duplicate_of, posted, last_updated, state, category, resolution, priority, severity, reproducability)
				VALUES (' . $row['id'] . ', ' . $row['project_id'] . ', "' . $row['title'] . '", ' . $row['assigned_to'] . ', ' . $row['duplicate_of'] . ', ' . $row['posted'] . ', ' . $row['last_updated'] . ', ' . $row['state'] . ', ' . $row['category'] . ', ' . $row['category'] . ', ' . $row['resolution'] . ', ' . $row['priority'] . ', ' . $row['severity'] . ', ' . $row['reproducability'] . ')');

			// @ TODO: Make this detect duplicates.
			$this->db->query('
				INSERT INTO (' . $this->tbg_db_prefix . 'builds (name) VALUES (' . $row['version'] . ')');

			$this->db->query('
				INSERT INTO (' . $this->tbg_db_prefix . 'issueaffectsbuild (id, build) VALUES(' . $row['id'] . ', ' . $this->db->lastInsertId() . ')');
		}

		$this->updateSubStep($substep + 500);
		$this->checkTimeout(__FUNCTION__);
	}

	/**
	* Bug Notes.
	*
	*/
	function doStep6()
	{
	}
}




// Not sure if these classes will be used.
// @ Why do we need these?  Its a converter only for mantis to TBG.  Don't need to make things complicated.
class tbg extends mbt_to_tbg
{
	function setReproducability()
	{
		// Delete from listtypes where itemtype = reproducability
		// Insert into listtypes, get the item
		// Update issues with reproducability number
		
	}

	function setCategory()
	{
		
	}

	function removeListType($type, $id = 0)
	{
		// Don't allow them to remove a bad type.
		if (!in_array($type, array('category', 'priority', 'reproducability', 'resolution', 'severity', 'status')))
			return;

		if (empty($id))
			return $this->db->query('
				DELETE FROM ' . $this->tbg_db_prefix . 'listtype
				WHERE itemtype=\'' . $type . '\'');
		else
			return $this->db->query('
				DELETE FROM ' . $this->tbg_db_prefix . 'listtype
				WHERE itemtype=\'' . $type . '\')
					AND id=\'' . (int) $id . '\'');	
	}
}
/*Severity: feature = 10; trivial = 20; text = 30; tweak = 40; minor = 50; major = 60; crash = 70; block = 80;

Priority: none = 10; low = 20; normal = 30; high = 40; urgent = 50; immediate = 60;*/
class mtb extends mbt_to_tbg
{
	function getAdmins()
	{
		$result = $this->db->query('
			SELECT MAX(' . $this->mbt_db_prefix . 'user_table.access_level)
		');
	}
	function getReproducability()
	{
		return array(
			'Always' => 10,
			'Sometimes' => 30,
			'Random' => 50,
			'Have not tried' => 70,
			'Unable to reproduce' => 90,
			'N/A' => 100
		);
	}

	function getSeverity()
	{
		return array(
			'Feature' => 10,
			'Trivial' => 20,
			'Text' => 30,
			'Tweak' => 40,
			'Minor' => 50,
			'Major' => 60,
			'Crash' => 70,
			'Block' => 80
		);
	}

	function getPriority()
	{
		return array(
			'None' => 10,
			'Low' => 20,
			'Normal' => 30,
			'High' => 40,
			'Urgent' => 50,
			'Immediate' => 60
		);
	}

	// TBG cannot do categories by project or user so ignore those.
	function getCategory()
	{
		/*
		 * 	SELECT *
		 * 	FROM {mtb}category_table
		 */ 
	}
} 