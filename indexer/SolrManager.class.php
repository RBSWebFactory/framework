<?php
/**
 * This class handles the transactions with the Solr server
 * @date Mon May 07 09:16:53 CEST 2007
 * @author franck.stauffer
 */
class indexer_SolrManager
{
	const SELECT_TASK = "select";
	const UPDATE_TASK = "update";
	const SYNONYMS_TASK = 'admin/synonyms';
	
	private $deleteQueue = array();
	private $updateQueue = array();
	
	private $clientId;
	private $batchMode = true;
	
	/**
	 * @var XMLWriter
	 */
	private $xmlWriterAdd;
	/**
	 * @var XMLWriter
	 */
	private $xmlWriterDelete;
	
	private $autocommit = true;
	/**
	 * @var String
	 */
	private $indexURL = null;
	/**
	 * @var String
	 */
	private $indexTask = null;
	
	private $dirty = false;
	
	/**
	 * Used for select queries, if configured.
	 * See <code>project/config/indexer/SolrManager/cacheClass</code>
	 * configuration section.
	 * @var indexer_Cache
	 */
	private static $cache;
	
	/**
	 * Value of <code>project/config/indexer/SolrManager/schemaVersion</code>
	 * configuration section.
	 * @var String
	 */
	private static $schemaVersion;
	
	/**
	 * @var Boolean
	 */
	private static $confRead = false;
	
	public function __construct($indexURL)
	{
		$this->setBaseURL($indexURL);
		if (defined('SOLR_INDEXER_DISABLE_BATCH_MODE'))
		{
			$this->batchMode = (bool)SOLR_INDEXER_DISABLE_BATCH_MODE;
		}
		self::readConfiguration();
	}
	
	/**
	 * @return String
	 */
	public static function getSchemaVersion()
	{
		self::readConfiguration();
		return self::$schemaVersion;
	}
	
	/**
	 * @return boolean
	 */
	public static function hasSuggestionInOnRequest()
	{
		return self::getSchemaVersion() !== "2.0.4";
	}
	
	/**
	 * @return boolean
	 */
	public static function hasAggregateText()
	{
		return self::getSchemaVersion() !== "2.0.4";
	}
	
	/**
	 * @return boolean
	 */
	public static function hasFacetAbility()
	{
		return self::getSchemaVersion() !== "2.0.4";
	}
	
	/**
	 * @return boolean
	 */
	public static function hasVolatileDynamicFields()
	{
		return self::getSchemaVersion() !== "2.0.4";
	}
	
	protected static function readConfiguration()
	{
		if (!self::$confRead)
		{
			self::$cache = self::getCache();
			self::$schemaVersion = Framework::getConfigurationValue("indexer/SolrManager/schemaVersion", "3.0.3");
			self::$confRead = true;
		}
	}
	
	protected static function getCache()
	{
		$className = Framework::getConfigurationValue("indexer/SolrManager/cacheClass");
		if ($className !== null)
		{
			$class = new ReflectionClass($className);
			if (!$class->isSubclassOf(new ReflectionClass("indexer_Cache")))
			{
				throw new Exception($className." is not a indexer_Cache subclass");
			}
			return $class->newInstance();
		}
		return null;
	}
	
	/**
	 * @return Boolean true if some cache is configured
	 */
	public static function clearCache()
	{
		$cache = self::getCache();
		if ($cache !== null)
		{
			$cache->clear();
			return true;
		}
		return false;
	}
	
	/**
	 * Executes the indexer_StandardSolrSearch $solrSearch and fetches result:
	 *  - If the **BOOLEAN** SOLR_USE_POST_QUERIES is defined and set to true, we will send the query using HTTP/POST.
	 *  - If it is set to false, or if it is not a boolean or if it is not set, we will use HTTP/GET
	 * 
	 * @throws IndexException
	 * @param indexer_StandardSolrSearch $solrSearch
	 * @return String (raw server answer)
	 */
	public function query($solrSearch)
	{
		$usePost = false;
		$this->setTask(self::SELECT_TASK);
		
		// Set the clientId on the query
		$solrSearch->setClientId($this->getClientId());
		
		// If SOLR_USE_POST_QUERIES is set we use POST for queries
		if (defined("SOLR_USE_POST_QUERIES"))
		{
			if (is_bool(SOLR_USE_POST_QUERIES))
			{
				$usePost = SOLR_USE_POST_QUERIES;
			}
		}
		// Build Query String
		$queryString = $solrSearch->getQueryString();
	
		if ($usePost)
		{
			$solrQuery = $this->getReadSolrRequest($this->getUrl());
			$solrQuery->setMethod(indexer_SolrServerRequest::METHOD_POST);
			$solrQuery->setPostData($queryString);
		}
		else
		{
			$solrQuery = $this->getReadSolrRequest($this->getUrl() . '/?' . $queryString);
		}
		return $solrQuery->execute();
	}
	
	/**
	 * Update the indexable document 
	 *
	 * @param indexer_IndexableDocument $indexableDocument
	 */
	public function update($indexableDocument)
	{
		$this->add($indexableDocument->getFields());
	}
	
	/**
	 * Delete an Indexable Document fron the indexer given it's id (eg: 10006/fr)
	 *
	 * @param String $id
	 */
	public function delete($id)
	{
		$clientId = $this->getClientId();
		$indexableDocumentId = $clientId . $id;
		if ($this->batchMode)
		{
			if (isset($this->updateQueue[$clientId][$indexableDocumentId]))
			{
				unset($this->updateQueue[$clientId][$indexableDocumentId]);
			}
			
			if (!isset($this->deleteQueue[$clientId]))
			{
				$this->deleteQueue[$clientId] = array();
			}
			$this->deleteQueue[$clientId][$indexableDocumentId] = $indexableDocumentId;
		}
		else
		{
			$this->deleteInternal($indexableDocumentId);
		}
	}
	
	private function deleteInternal($id)
	{
		$this->initXmlWriterDeleteInstance();
		$this->xmlWriterDelete->startElement('delete');
		$this->xmlWriterDelete->writeElement("id", $id);
		$this->xmlWriterDelete->endElement();
		$string = $this->xmlWriterDelete->outputMemory(true);
		$this->xmlWriterDelete = null;
		$this->sendUpdate($string);
	}
	
	private $forcedClientId;
	
	protected function setClientId($clientId)
	{
		$this->forcedClientId = $clientId;
	}
	
	protected function unsetClientId()
	{
		$this->forcedClientId = null;
	}
	
	/**
	 * Add document to the solr indexer.
	 *
	 * @param indexer_IndexableDocument $indexableDocument
	 */
	public function add($indexableDocument)
	{
		$clientId = $this->getClientId();
		if ($this->batchMode)
		{
			$indexableDocumentId = $this->getFinalIdForIndexableDocument($indexableDocument);
			if (isset($this->deleteQueue[$clientId][$indexableDocumentId]))
			{
				unset($this->deleteQueue[$clientId][$indexableDocumentId]);
			}
			elseif (!isset($this->updateQueue[$clientId]))
			{
				$this->updateQueue[$clientId] = array();
			}
			$this->updateQueue[$clientId][$indexableDocumentId] = $indexableDocument;
		}
		else
		{
			$this->addInternal($indexableDocument);
		}
	}
	
	/**
	 * @param indexer_IndexedDocument $indexableDocument
	 * @return String
	 */
	private function getFinalIdForIndexableDocument($indexableDocument)
	{
		$fields = $indexableDocument->getFields();
		return strval($this->getClientId() . $fields['id']['value']);
	}
	
	/**
	 * @param indexer_IndexedDocument $indexableDocument
	 */
	private function addInternal($indexableDocument)
	{
		$this->initXmlWriterAddInstance();
		$this->xmlWriterAdd->startElement('doc');
		
		$indexableDocumentFields = $indexableDocument->getFields();
		$this->addExtraInformation($indexableDocumentFields);
		$lang = $indexableDocumentFields['lang']['value'];
		foreach ($indexableDocumentFields as $name => $value_type)
		{
			
			$type = $value_type['type'];
			
			// If the field's flag is ignored we skip it...
			if ($type == indexer_Field::IGNORED)
			{
				continue;
			}
			
			// To handle multivalued fields and single valued fields, we make sure $value is always an array
			$values = $value_type['value'];
			if (!is_array($values))
			{
				$values = array($values);
			}
			
			// We append the correct suffix if necessary, given the field names
			switch ($name)
			{
				case "label":
				case "text":
					$name .= "_$lang";
					break;
				case 'id':
					// Add the client field
					$this->xmlWriterAdd->startElement('field');
					$this->xmlWriterAdd->writeAttribute('name', 'client');
					$this->xmlWriterAdd->text($this->getClientId());
					$this->xmlWriterAdd->endElement();
					
					// Build the finalId
					$this->xmlWriterAdd->startElement('field');
					$this->xmlWriterAdd->writeAttribute('name', 'finalId');
					$this->xmlWriterAdd->text(strval($this->getClientId() . $values[0]));
					$this->xmlWriterAdd->endElement();
					break;
				case 'lang':
				case 'documentModel':
				case 'document_accessor':
					// Do nothing here
					break;
				default:
					break;
			}
			// We build the DOMElement for the field and append it to the document.
			foreach ($values as $value)
			{
				$this->xmlWriterAdd->startElement('field');
				$this->xmlWriterAdd->writeAttribute('name', $name);
				$this->xmlWriterAdd->text(strval($value));
				$this->xmlWriterAdd->endElement();
			}
		}
		$this->xmlWriterAdd->endElement();
		if (!$this->batchMode)
		{
			$this->commitBatch();
		}
	}
	
	/**
	 * @param String $postString
	 * @return String
	 */
	public function sendUpdate($postString)
	{
		$this->dirty = true;
		$this->setTask(self::UPDATE_TASK);
		return $this->sendXMLData($postString);
	}
	
	/**
	 * @param String $id
	 * @return String
	 */
	public function sendDelete($id)
	{
		$this->dirty = true;
		$this->setTask(self::UPDATE_TASK);
		return $this->sendXMLData("<delete><query>finalId:$id</query></delete>");
	}
	/**
	 * @return String
	 */
	public function sendCommit()
	{
		$this->dirty = false;
		if (defined("SOLR_INDEXER_DISABLE_COMMIT") && SOLR_INDEXER_DISABLE_COMMIT == true)
		{
			return "";
		}
		$this->setTask(self::UPDATE_TASK);
		return $this->sendXMLData("<commit/>");
	}
	
	/**
	 * @return String
	 */
	public function sendOptimize()
	{
		$this->setTask(self::UPDATE_TASK);
		return $this->sendXMLData("<optimize/>", 60);
	}
	/**
	 * @param String $name
	 * @param String $data
	 * @return array()
	 */
	public function getArrayPropertyFromData($name, $data)
	{
		$xml = simplexml_load_string($data);
		$properties = $xml->xpath('//arr[@name="' . $name . '"]');
		$res = array();
		
		foreach ($properties as $property)
		{
			foreach ($property->str as $value)
			{
				$res[] = (string)$value;
			}
		}
		return $res;
	}
	
	/**
	 * @param Boolean $bool
	 */
	public function setAutoCommit($bool)
	{
		if (is_bool($bool))
		{
			$this->autocommit = $bool;
			if ($this->autocommit == true)
			{
				$this->sendCommit();
			}
		}
	}
	
	/**
	 * @return Boolean
	 */
	public function isDirty()
	{
		if ($this->batchMode == true)
		{
			return (count($this->deleteQueue) != 0 || count($this->updateQueue) != 0);
		}
		return (!$this->autocommit && $this->dirty);
	}
	
	/**
	 * Clear the solr index
	 */
	public function clearIndexQuery()
	{
		$this->deleteByQuery(new indexer_TermQuery('client', $this->getClientId()));
		$this->sendCommit();
	}
	
	/**
	 * Clear the solr index
	 */
	public function optimizeIndexQuery()
	{
		$queryXml = '<?xml version="1.0" encoding="UTF-8" ?><optimize/>';
		$this->setTask(self::UPDATE_TASK);
		$this->sendXMLData($queryXml);
	}
	
	public function rebuildSpellCheckIndexForLang($lang)
	{
		if (self::getSchemaVersion() == "2.0.4")
		{
			$queryString = '/?client=' . $this->getClientId() . '&qt=spellchecker_' . $lang . '&cmd=rebuild';
		}
		else
		{
			$queryString = '/?client=' . $this->getClientId() .
				'&q=*:*&rows=0&spellcheck=true&spellcheck.build=true&spellcheck.q=change&qt=/spellchecker_' . $lang;
		}
		$this->setTask(self::SELECT_TASK);
		$this->getData($queryString, -1);
	}
	
	/**
	 * @return String
	 */
	protected function getClientId()
	{
		if ($this->forcedClientId !== null)
		{
			return $this->forcedClientId;
		}
		return indexer_IndexService::getInstance()->getClientId();
	}
	
	/**
	 * @return String
	 */
	protected function getUrl()
	{
		if ($this->getTask() == null)
		{
			throw new Exception("Indexer service task not defined");
		}
		return $this->getBaseURL() . $this->getTask();
	}
	
	protected function commitBatch()
	{
		if ($this->xmlWriterAdd != null)
		{
			$this->xmlWriterAdd->endElement();
			$this->xmlWriterAdd->endDocument();
			$string = $this->xmlWriterAdd->outputMemory(true);
			$this->xmlWriterAdd = null;
			$this->sendUpdate($string);
		}
		$this->sendCommit();
	}
	
	protected function setBatchMode($value)
	{
		if ($this->batchMode && !$value)
		{
			$this->commitBatch();
		}
		$this->batchMode = $value;
	}
	

	/**
	 * Set the indexer's 'Task' we want to perform
	 * For exmaple: $this->setTask('update') means the URL used for communication will be http://<serverurl>/update
	 * @param String $string
	 */
	protected function setTask($string)
	{
		$this->indexService = $string;
	}
	
	/**
	 * Get the Task
	 *
	 * @return String
	 */
	protected function getTask()
	{
		return $this->indexService;
	}
	
	/**
	 * Send the raw text/xml content to the server when in "update" mode.
	 *
	 * @throws IndexException
	 * @param String $xmlData
	 * @return String
	 */
	protected function sendXMLData($xmlData, $timeout = null)
	{
		$solrQuery = new indexer_SolrServerRequest($this->getUrl());
		if ($timeout !== null)
		{
			$solrQuery->setTimeout($timeout);
		}
		$solrQuery->setMethod(indexer_SolrServerRequest::METHOD_POST);
		$solrQuery->setPostData($xmlData);
		$solrQuery->setContentType('text/xml; charset=UTF-8');
		return $this->parseServerAnswer($solrQuery->execute());
	}
	
	/**
	 * @param unknown_type $queryString
	 * @return unknown
	 */
	protected function getData($queryString, $timeout = null)
	{
		$solrQuery = new indexer_SolrServerRequest($this->getUrl() . $queryString);
		if ($timeout !== null)
		{
			$solrQuery->setTimeout($timeout);
		}
		$solrQuery->setMethod(indexer_SolrServerRequest::METHOD_GET);
		return $this->parseServerAnswer($solrQuery->execute());
	}
	
	/**
	 * @param String $url
	 * @return indexer_SolrServerRequest
	 */
	protected function getReadSolrRequest($url)
	{
		$request = new indexer_SolrServerRequest($url);
		if (self::$cache !== null)
		{
			$request->setCache(self::$cache);
		}
		return $request;
	}
	
	/**
	 * Analyses the raw server answer
	 *
	 * @param String $data
	 */
	private function parseServerAnswer($data)
	{
		if ($data == '<result status="0"></result>')
		{
			return $data;
		}
		$xml = simplexml_load_string($data);
		if ($xml == false)
		{
			throw new IndexException(__METHOD__ . "Unexpected Server reply (URL = " . $this->getBaseURL() . $this->getTask() . ") data = " . $data);
		}
		$status = $xml->xpath('/response/lst[@name="responseHeader"]/int[@name="status"]');
		if (intval((string)$status) != 0 || $status == false)
		{
			throw new IndexException(__METHOD__ . "Unexpected Server reply (URL = " . $this->getBaseURL() . $this->getTask() . ") data = " . $data);
		}
		return $data;
	}
	
	/**
	 * Get the base indexer URL
	 *
	 * @return String
	 */
	private function getBaseURL()
	{
		return $this->indexURL;
	}
	
	/**
	 * Set the base URL
	 *
	 * @param unknown_type $string
	 */
	private function setBaseURL($string)
	{
		$this->indexURL = $string;
	}
	
	/**
	 * @return XMLWriter
	 */
	private function initXmlWriterAddInstance()
	{
		if ($this->xmlWriterAdd == null)
		{
			$this->xmlWriterAdd = new XMLWriter();
			$this->xmlWriterAdd->openMemory();
			$this->xmlWriterAdd->startDocument('1.0', 'UTF-8');
			$this->xmlWriterAdd->startElement('add');
		}
		return $this->xmlWriterAdd;
	}
	
	/**
	 * @return XMLWriter
	 */
	private function initXmlWriterDeleteInstance()
	{
		if ($this->xmlWriterDelete == null)
		{
			$this->xmlWriterDelete = new XMLWriter();
			$this->xmlWriterDelete->openMemory();
			$this->xmlWriterDelete->startDocument('1.0', 'UTF-8');
		}
		return $this->xmlWriterDelete;
	}
	
	private function addExtraInformation(&$indexableDocumentFields)
	{
		$lang = $indexableDocumentFields['lang']['value'];
		
		if (self::getSchemaVersion() != "3.0.3")
		{
			$indexableDocumentFields[$lang . '_aggregateText']['value'] = $indexableDocumentFields['label']['value'] . "\n" . $indexableDocumentFields['text']['value'];
			$indexableDocumentFields[$lang . '_aggregateText']['type'] = indexer_Field::INDEXED;
		}
		
		$indexableDocumentFields[$lang . '_sortableLabel']['value'] = mb_strtolower(trim(preg_replace('/[\s+]/u', ' ', $indexableDocumentFields['label']['value'])), "UTF-8");
		$indexableDocumentFields[$lang . '_sortableLabel']['type'] = indexer_Field::INDEXED;
	}
	
	public function commit()
	{
		if (!$this->isDirty())
		{
			return;
		}
		try
		{
			$deleteQuery = indexer_QueryHelper::orInstance();
			foreach ($this->deleteQueue as $clientId => $documentsToDelete)
			{
				$this->setClientId($clientId);
				foreach ($documentsToDelete as $indexableDocumentId)
				{
					$deleteQuery->add(new indexer_TermQuery('finalId', $indexableDocumentId));
				}
				$this->unsetClientId();
			}
			if ($deleteQuery->getSubqueryCount() > 0)
			{
				$this->deleteByQuery($deleteQuery);
			}
			$this->deleteQueue = array();
			foreach ($this->updateQueue as $clientId => $documentsToUpdate)
			{
				$this->setClientId($clientId);
				foreach ($documentsToUpdate as $indexableDocumentId => $indexableDocument)
				{
					$this->addInternal($indexableDocument);
				}
				$this->unsetClientId();
			}
			$this->updateQueue = array();
			$this->commitBatch();
		}
		catch (Exception $e)
		{
			Framework::exception($e);
		}
	}
	
	/**
	 * @param indexer_Query $query
	 */
	private function deleteByQuery($query)
	{
		$this->setTask(self::UPDATE_TASK);
		$this->sendXMLData('<delete><query>' . urldecode($query->toSolrString()) . '</query></delete>');
	}
	
	// Deprecated
	
	/**
	 * @deprecated (will be removed in 4.0)
	 */
	public function getSynonymsLists()
	{
		return array();
	}
	
	/**
	 * @deprecated (will be removed in 4.0)
	 */
	public function updateSynonymsList($name, $content)
	{
		return;
	}
}
