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

class Piwik_Db_DAO_SiteUrl extends Piwik_Db_DAO_Base
{ 
	public function __construct($db, $table)
	{
		parent::__construct($db, $table);
	}

	public function getUrlByIdsite($idsite)
	{
		$sql = 'SELECT url FROM ' . $this->table . ' '
			 . 'WHERE idsite = ?';
		return $this->db->fetchAll($sql, $idsite);
	}

	public function deleteByIdsite($idsite)
	{
		$sql = 'DELETE FROM ' . $this->table . ' WHERE idsite = ?';
		$this->db->query($sql, $idsite);
	}

	public function addSiteUrls($urls, $idsite)
	{
		foreach($urls as $url)
		{
			$this->db->insert($this->table,
				array('idsite' => $idsite, 'url' => $url)
			);
		}
	}
}
