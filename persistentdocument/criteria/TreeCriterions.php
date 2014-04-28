<?php
interface f_persistentdocument_criteria_TreeCriterion extends f_persistentdocument_criteria_Criterion
{
	
}

class f_persistentdocument_criteria_SiblingOfExpression implements f_persistentdocument_criteria_TreeCriterion
{
	/**
     * @var Integer
     */
	private $documentId;

	/**
     * Default constructor
     * @param Integer $Integer
     */
	public function __construct($documentId)
	{
		$this->documentId = $documentId;
	}

	/**
     * @return Integer
     */
	public function getDocumentId()
	{
		return $this->documentId;
	}
}

class f_persistentdocument_criteria_PreviousSiblingOfExpression implements f_persistentdocument_criteria_TreeCriterion
{
	/**
     * @var Integer
     */
    private $documentId;
    
    /**
     * Default constructor
     * @param Integer $documentId
     */
    public function __construct($documentId)
    {
        $this->documentId = $documentId;
    }

    /**
     * @return Integer
     */
    public function getDocumentId()
    {
        return $this->documentId;
    }
}

class f_persistentdocument_criteria_NextSiblingOfExpression implements f_persistentdocument_criteria_TreeCriterion
{
	/**
     * @var Integer
     */
    private $documentId;
    
    /**
     * Default constructor
     * @param Integer $documentId
     */
    public function __construct($documentId)
    {
        $this->documentId = $documentId;
    }

    /**
     * @return Integer
     */
    public function getDocumentId()
    {
        return $this->documentId;
    }
}

class f_persistentdocument_criteria_AncestorOfExpression implements f_persistentdocument_criteria_TreeCriterion
{
	/**
     * @var Integer
     */
    private $documentId;
    /**
     * @var Integer
     */
    private $level;

    /**
     * Default constructor
     * @param Integer $Integer
     * @param Integer $Integer
     */
    public function __construct($documentId, $level)
    {
        $this->documentId = $documentId;
        $this->level = $level;
    }

    /**
     * @return Integer
     */
    public function getDocumentId()
    {
        return $this->documentId;
    }

    /**
     * @return Integer
     */
    public function getLevel()
    {
        return $this->level;
    }
}

class f_persistentdocument_criteria_DescendentOfExpression implements f_persistentdocument_criteria_TreeCriterion
{
	/**
     * @var Integer
     */
    private $documentId;
    /**
     * @var Integer
     */
    private $level;
    /**
     * @var boolean
     */
    private $includeParent;

    /**
     * Default constructor
     * @param Integer $documentId
     * @param Integer $level
     * @param boolean $includeParent
     */
    public function __construct($documentId, $level, $includeParent = false)
    {
        $this->documentId = $documentId;
        $this->level = $level;
        $this->includeParent = $includeParent;
    }

    /**
     * @return Integer
     */
    public function getDocumentId()
    {
        return $this->documentId;
    }

    /**
     * @return Integer
     */
    public function getLevel()
    {
        return $this->level;
    }
    
    /**
    * @return boolean
    */
    public function includeParent()
    {
    	return $this->includeParent;
    }
}