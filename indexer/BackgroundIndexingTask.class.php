<?php
class f_tasks_BackgroundIndexingTask extends task_SimpleSystemTask
{
	/**
	 * @see task_SimpleSystemTask::execute()
	 */
	protected function execute()
	{
		$stats = f_persistentdocument_PersistentProvider::getInstance()->getIndexingStats();
		f_persistentdocument_PersistentProvider::getInstance()->refresh();
		
		$result = array();
		foreach ($stats as $row) 
		{
			if ($row['indexing_status'] !== 'INDEXED')
			{
				$mode = intval($row['indexing_mode']);
				$maxId = intval($row['max_id']);
				if (isset($result[$mode]))
				{
					$result[$mode] = max($result[$mode], $maxId);
				}
				else
				{
					$result[$mode] =  $maxId;
				}
				
			}
		}
		
		foreach ($result as $mode => $maxId) 
		{
			while ($maxId > 0) 
			{
				$maxId = $this->backgroundIndex($mode, $maxId, 100);
			}
		}
	}
	
	private function backgroundIndex($indexingMode, $maxId, $chunkSize = 100)
	{
		$scriptPath = 'framework/indexer/backgroundDocumentIndexer.php';
		$indexerLogPath = f_util_FileUtils::buildLogPath('indexer.log');
		$modeLabel = $indexingMode == indexer_IndexService::INDEXER_MODE_BACKOFFICE ? 'BO' : 'FO';
		error_log("\n". gmdate('Y-m-d H:i:s')."\t".__METHOD__ . "\t $modeLabel \t $maxId", 3, $indexerLogPath);
				
		$output = f_util_System::execHTTPScript($scriptPath, array($indexingMode, $maxId, $chunkSize));
		if (!is_numeric($output))
		{
			$chunkInfo = " Error on processsing $modeLabel at index $maxId.";
			error_log("\n". gmdate('Y-m-d H:i:s')."\t".$chunkInfo, 3, $indexerLogPath);
			$output = -1;
		}
		else if (intval($output) <= 0)
		{
			$chunkInfo = " End on processing $modeLabel.";
			error_log("\n". gmdate('Y-m-d H:i:s')."\t".$chunkInfo, 3, $indexerLogPath);
		}
		
		return intval($output);
	}
}