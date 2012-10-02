<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 * @category Piwik
 * @package Piwik
 */

/**
 * @package Piwik
 * @subpackage Piwik_Db
 */

class Piwik_Db_DAO_LogAction extends Piwik_Db_DAO_Base
{ 
	const TEMP_TABLE_NAME = 'tmp_log_actions_to_keep';

	public function __construct($db, $table)
	{
		parent::__construct($db, $table);
	}

	public function sqlIdactionFromSegment($matchType, $actionType)
	{
		$sql = 'SELECT idaction FROM ' . $this->table . ' WHERE ';
		switch ($matchType)
		{
			case '=@':
				$sql .= "(name LIKE CONCAT('%', ?, '%') AND type = $actionType )";
				break;
			case '!@':
				$sql .= "(name NOT LIKE CONCAT('%', ?, '%') AND type = $actionType )";
				break;
			default:
                throw new Exception("This match type is not available for action-segments.");
				break;
		}

		return $sql;
	}

	public function getIdaction($name, $type)
	{
		$sql = $this->sqlActionId();
		$bind = array(Piwik_Common::getCrc32($name), $name, $type);

		return $this->db->fetchOne($sql, $bind);
	}

	/**
	 *	add record
	 *
	 *	Adds a record to the log_action table and returns the id of the
	 *	the inserted row.
	 *
	 *	@param string $name
	 *	@param string $type
	 *  @returns int
	 */
	public function add($name, $type)
	{
		$sql = 'INSERT INTO ' . $this->table . ' (name, hash, type) '
			 . 'VALUES (?, ?, ?)';
		$this->db->query($sql, array($name, Piwik_Common::getCrc32($name), $type));

		return $this->db->lastInsertId();
	}

	public function loadActionId($actionNamesAndTypes)
	{
		// First, we try and select the actions that are already recorded
		$res = $this->sqlActionIdsFromNameType($actionNamesAndTypes);
		$sql  = $res['sql'];
		$bind = $res['bind'];
		// if URL and Title are empty
		if (empty($bind))
		{
			return $actionNamesAndTypes;
		}

		$actionIds = $this->db->fetchAll($sql, $bind);

		$actionsToInsert = $this->actionsToInsertFromNamesTypes($actionNamesAndTypes, $actionIds);

		// Then, we insert all new actions in the lookup table
		foreach($actionsToInsert as $actionToInsert)
		{
			list($name,$type) = $actionNamesAndTypes[$actionToInsert];
	
			$actionId = $this->add($name, $type);
			printDebug("Recorded a new action (".Piwik_Tracker_Action::getActionTypeName($type).") in the lookup table: ". $name . " (idaction = ".$actionId.")");
			
			$actionNamesAndTypes[$actionToInsert][] = $actionId;
		}
		return $actionNamesAndTypes;
	}

	/**
	 *	delete Unused actions
	 *
	 *	Deletes the data from log_action table based on the temporary table
	 */
	public function deleteUnusedActions()
	{
		$tempTable = Piwik_Common::prefixTable(self::TEMP_TABLE_NAME);
		$sql = 'DELETE LOW_PRIORITY QUICK IGNORE ' . $this->table . ' '
			  .'FROM ' . $this->table . ' AS la '
			  .'LEFT OUTER JOIN ' . $tempTable . ' AS tmp '
			  .'	ON tmp.idaction = la.idaction '
			  .'WHERE tmp.idaction IS NULL';
		$this->db->query($sql);
	}

	public function getIdactionByName($name)
	{
		$sql = 'SELECT idaction FROM ' . $this->table . ' WHERE name = ?';
		return $this->db->fetchOne($sql, array($name));
	}

	public function getCountByIdaction($idaction)
	{
		$sql = 'SELECT COUNT(*) FROM ' . $this->table . ' WHERE idaction = ?';
		return $this->db->fetchOne($idaction);
	}

	public function purgeUnused($maxIds)
	{
		$generic = Piwik_Db_Factory::getGeneric($this->db);
		$this->createTempTable();

		// do large insert (inserting everything before maxIds) w/o locking tables...
		$this->insertActionsToKeep($maxIds, $deleteOlderThanMax = true);

		// ... then do small insert w/ locked tables to minimize the amount of time tables are locked.
		$this->lockLogTables($generic);
		$this->insertActionsToKeep($maxIds, $deleteOlderThanMax = false);
		
		// delete before unlocking tables so there's no chance a new log row that references an
		// unused action will be inserted.
		$this->deleteUnusedActions();
		// unlock the log tables
		$generic->unlockAllTables();
	}

	protected function getIdActionColumns()
	{
		return array(
			'log_link_visit_action' => array( 'idaction_url',
											  'idaction_url_ref',
											  'idaction_name',
											  'idaction_name_ref' ),
											  
			'log_conversion' => array( 'idaction_url' ),
			
			'log_visit' => array( 'visit_exit_idaction_url',
								  'visit_exit_idaction_name',
								  'visit_entry_idaction_url',
								  'visit_entry_idaction_name' ),
								  
			'log_conversion_item' => array( 'idaction_sku',
											'idaction_name',
											'idaction_category',
											'idaction_category2',
											'idaction_category3',
											'idaction_category4',
											'idaction_category5' )
		);
	}

	protected function sqlActionId()
	{
		$sql = 'SELECT idaction, type, name '
			  .'FROM ' . $this->table . ' '
			  .'WHERE ( hash = ? AND name = ? AND type = ? ) ';
		return $sql;
	}

	protected function sqlActionIdsFromNameType(&$actionNamesAndTypes) {
		$sql = $this->sqlActionId();
		$bind = array();
		$i = 0;
		foreach ($actionNamesAndTypes as &$actionNameType)
		{
			list($name, $type) = $actionNameType;
			if (empty($name))
			{
				$actionNameType[] = false;
				continue;
			}
			if ($i > 0)
			{
				$sql .= ' OR (hash = ? AND name = ? AND type = ? )';
			}
			$bind[] = Piwik_Common::getCrc32($name);
			$bind[] = $name;
			$bind[] = $type;
			++$i;
		}

		return array('sql' => $sql, 'bind' => $bind);
	}

	protected function actionsToInsertFromNamesTypes(&$actionNamesAndTypes, $actionIds) {
		// For the Actions found in the lookup table, add the idaction in the array, 
		// If not found in lookup table, queue for INSERT
		$actionsToInsert = array();
		foreach($actionNamesAndTypes as $index => &$actionNameType)
		{
			list($name,$type) = $actionNameType;
			if(empty($name)) { continue; }
			$found = false;
			foreach($actionIds as $row)
			{
				if($name == $row['name']
					&& $type == $row['type'])
				{
					$found = true;
					$actionNameType[] = $row['idaction'];
					continue;
				}
			}
			if(!$found)
			{
				$actionsToInsert[] = $index;
			}
		}

		return $actionsToInsert;
	}

	protected function insertActionsToKeep($maxIds, $olderThan = true)
	{
		$tempTable = Piwik_Common::prefixTable(self::TEMP_TABLE_NAME);
		$idvisitCondition = $olderThan ? ' idvisit <= ? ' : ' idvisit > ? ';
		foreach ($this->getIdActionColumns as $table => $column)
		{
			foreach ($columns as $col)
			{
				$select = "SELECT $col from " . Piwik_Common::prefixTable($table) . " WHERE $idvisitCondition";
				$sql = "INSERT IGNORE INTO $tempTable $select";
				$this->db->query($sql, array($maxIds[$table]));
			}
		}

		// allow code to be executed after data is inserted. for concurrency testing purposes.
		if ($olderThan)
		{
			Piwik_PostEvent("LogDataPurger.actionsToKeepInserted.olderThan");
		}
		else
		{
			Piwik_PostEvent("LogDataPurger.actionsToKeepInserted.newerThan");
		}
	}

	protected function lockLogTables($generic)
	{
		$generic->lockTables(
			$readLocks = Piwik_Common::prefixTables('log_conversion',
													'log_link_visit_action',
													'log_visit',
													'log_conversion_item'
													),
			$writeLocks = Piwik_Common::prefixTable('log_action')
		);
	}

	protected function createTempTable()
	{
		$sql = 'CREATE TEMPORARY TABLE ' . Piwik_Common::prefixTable(self::TEMP_TABLE_NAME) . '( '
			  .'  idaction INT, '
			  .'  PRIMARY KEY(idaction) '
			  .' );';
		$this->db->query($sql);
	}
} 
