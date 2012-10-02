<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 * @version $Id: RankingQueryTest.php 6806 2012-08-17 11:17:05Z EZdesign $
 */

class RankingQueryTest extends PHPUnit_Framework_TestCase
{
	
    /**
     * @group Core
     * @group RankingQuery
     */
    public function testBasic()
    {
		$query = new Piwik_RankingQuery();
		$query->addLabelColumn('label');
		$query->addColumn('column');
		$query->addColumn('columnSum', 'sum');
		$query->setLimit(10);
		
		$innerQuery = "SELECT label, column, columnSum FROM myTable";
		
		$expected = "
			SELECT
				CASE
					WHEN counter = 11 THEN \"Others\" 
					ELSE `label`
				END AS `label`,
				`column`,
				sum(`columnSum`) AS `columnSum`
			FROM ( 
				SELECT
					`label`,
					CASE 
						WHEN @counter = 11 THEN 11
						ELSE @counter:=@counter+1
					END AS counter,
					`column`,
					`columnSum`
				FROM
					( SELECT @counter:=0 ) initCounter,
					( SELECT label, column, columnSum FROM myTable ) actualQuery
			 ) AS withCounter
			GROUP BY counter
		";
		
		$this->checkQuery($query, $innerQuery, $expected);
	}
	
	/**
	 * @group Core
	 * @group RankingQuery
	 */
	public function testExcludeRows()
	{
		$query = new Piwik_RankingQuery(20);
		$query->addLabelColumn('label');
		$query->setColumnToMarkExcludedRows('exclude_marker');
		
		$innerQuery = "SELECT label, 1 AS exclude_marker FROM myTable";
		
		$expected = "
			SELECT
				CASE
					WHEN counter = 21 THEN \"Others\" 
					ELSE `label`
				END AS `label`,
				`exclude_marker`
			FROM ( 
				SELECT
					`label`,
					CASE
						WHEN exclude_marker != 0 THEN -1 * exclude_marker
						WHEN @counter = 21 THEN 21
						ELSE @counter:=@counter+1
					END AS counter,
					`exclude_marker`
				FROM
					( SELECT @counter:=0 ) initCounter,
					( SELECT label, 1 AS exclude_marker FROM myTable ) actualQuery
			) AS withCounter
			GROUP BY counter
		";
		
		$this->checkQuery($query, $innerQuery, $expected);
	}
	
	/**
	 * @group Core
	 * @group RankingQuery
	 */
	public function testPartitionResult()
	{
		$query = new Piwik_RankingQuery(1000);
		$query->addLabelColumn('label');
		$query->partitionResultIntoMultipleGroups('partition', array(1, 2, 3));
		
		$innerQuery = "SELECT label, partition FROM myTable";
		
		$expected = "
			SELECT
				CASE
					WHEN counter = 1001 THEN \"Others\" 
					ELSE `label`
				END AS `label`,
				`partition`
			FROM ( 
				SELECT
					`label`,
					CASE
						WHEN `partition` = 1 AND @counter1 = 1001 THEN 1001
						WHEN `partition` = 1 THEN @counter1:=@counter1+1
						WHEN `partition` = 2 AND @counter2 = 1001 THEN 1001
						WHEN `partition` = 2 THEN @counter2:=@counter2+1
						WHEN `partition` = 3 AND @counter3 = 1001 THEN 1001
						WHEN `partition` = 3 THEN @counter3:=@counter3+1
						ELSE 0
					END AS counter,
					`partition`
				FROM
					( SELECT @counter1:=0 ) initCounter1,
					( SELECT @counter2:=0 ) initCounter2,
					( SELECT @counter3:=0 ) initCounter3, 
					( SELECT label, partition FROM myTable ) actualQuery
			) AS withCounter
			GROUP BY counter, `partition`
		";
		
		$this->checkQuery($query, $innerQuery, $expected);
	}

	/**
	 * @param Piwik_RankingQuery $rankingQuery
	 * @param string $innerQuerySql
	 * @param string $expected
	 */
	private function checkQuery($rankingQuery, $innerQuerySql, $expected)
	{
		$query = $rankingQuery->generateQuery($innerQuerySql);
		
		$queryNoWhitespace = preg_replace("/\s+/", "", $query);
		$expectedNoWhitespace = preg_replace("/\s+/", "", $expected);
		
		$message = 'Unexpected query: '.$query;
		$this->assertEquals($queryNoWhitespace, $expectedNoWhitespace, $message);
	}
	
}
