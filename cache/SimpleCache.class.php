<?php
class f_SimpleCache
{
	const INVALID_CACHE_ENTRY = 'invalidCacheEntry';
	private $id;
	private $keyParameters;
	private $cacheSpecs;
	private $registrationPath;
	
	private $cachePath;
	private static $oldCachePath;
	
	private static $registrationFolder;
	
	public function __construct($id, $keyParameters, $cacheSpecs)
	{
		$this->id = $id;
		$this->keyParameters = md5(serialize($keyParameters));
		$this->cacheSpecs = $cacheSpecs;
	}
	
	static function isEnabled()
	{
		return constant("AG_DISABLE_SIMPLECACHE") !== true;
	}
	
	public function exists($subCache)
	{
		$result = file_exists($this->getCachePath($subCache)) && $this->isValid();
		$this->markAsBeingRegenerated();
		return $result;
	}
	
	private function isValid()
	{
		return !file_exists($this->getCachePath(self::INVALID_CACHE_ENTRY));
	}
	
	private function markAsBeingRegenerated()
	{
		if (!$this->isValid())
		{
			f_util_FileUtils::unlink($this->getCachePath(self::INVALID_CACHE_ENTRY));
		}
	}
	
	public function setInvalid()
	{
		if ($this->isValid())
		{
			@touch($this->getCachePath(self::INVALID_CACHE_ENTRY));
		}
	}
	
	public function readFromCache($subCache)
	{
		return file_get_contents($this->getCachePath($subCache));
	}
	
	public function writeToCache($subCache, $content)
	{
		$this->register();
		if ($content !== null)
		{
			$path = $this->getCachePath($subCache);
			try
			{
				f_util_FileUtils::write($path, $content, f_util_FileUtils::OVERRIDE);
			}
			catch (Exception $e)
			{
				// Do not let potential partial or broken content rest on disk
				if (file_exists($path))
				{
					@unlink($path);
				}
				throw $e;
			}
		}
	}
	
	public function getCachePath($subCache)
	{
		if ($this->cachePath === null)
		{
			$this->cachePath = f_util_FileUtils::buildCachePath('simplecache', $this->id, $this->keyParameters);
			f_util_FileUtils::mkdir($this->cachePath);
		}
		if ($subCache === null)
		{
			return $this->cachePath;
		}
		return $this->cachePath . DIRECTORY_SEPARATOR . $subCache;
	}
	
	private function isRegistered()
	{
		return file_exists($this->getRegistrationPath());
	}
	
	private function getRegistrationPath()
	{
		if (!is_null($this->registrationPath))
		{
			return $this->registrationPath;
		}
		if (is_null(self::$registrationFolder))
		{
			self::$registrationFolder = f_util_FileUtils::buildCachePath('simplecache', 'registration');
			f_util_FileUtils::mkdir(self::$registrationFolder);
		}
		$this->registrationPath = self::$registrationFolder . DIRECTORY_SEPARATOR . $this->id;
		return $this->registrationPath;
	}
	
	private function optimizeCacheSpecs($cacheSpecs)
	{
		if (f_util_ArrayUtils::isNotEmpty($cacheSpecs))
		{
			$finalCacheSpecs = array();
			foreach (array_unique($cacheSpecs) as $spec)
			{
				if (preg_match('/^modules_\w+\/\w+$/', $spec))
				{
					try
					{
						$model = f_persistentdocument_PersistentDocumentModel::getInstanceFromDocumentModelName($spec);
						$finalCacheSpecs[] = $model->getName();
					}
					catch (Exception $e)
					{
						Framework::exception($e);
					}
				}
				else if (strpos($spec, 'tags/') === 0 && strpos($spec, '*') !== false)
				{
					try
					{
						$tags = TagService::getInstance()->getAvailableTagsByPattern(substr($spec,5));
						foreach ($tags as $tag)
						{
							$finalCacheSpecs[] = 'tags/' . $tag;
						}
					}
					catch (Exception $e)
					{
						Framework::exception($e);
					}
				}
				else
				{
					$finalCacheSpecs[] = $spec;
				}
			}
			
			return $finalCacheSpecs;
		}
		return array();
	}
	
	private function register()
	{
		$registrationPath = $this->getRegistrationPath();
		if (!file_exists($registrationPath))
		{
			$tm = f_persistentdocument_TransactionManager::getInstance();
			try
			{
				$tm->beginTransaction();
				$pp = f_persistentdocument_PersistentProvider::getInstance();
				$pp->registerSimpleCache($this->id, $this->optimizeCacheSpecs($this->cacheSpecs));
				$tm->commit();
				@touch($registrationPath);
			
			}
			catch (Exception $e)
			{
				$tm->rollBack($e);
			}
		}
	}
	
	private static $clearAll = false;
	private static $idToClear = array();
	private static $dispatch = false;
	private static $shutdownRegistered = false;
	
	private static function registerShutdown()
	{
		if (!self::$shutdownRegistered)
		{
			register_shutdown_function(array('f_SimpleCache','shutdownCommitClear'));
			self::$shutdownRegistered = true;
		}
	}
	
	/**
	 * @param String $id
	 */
	public static function clear($id = null, $dispatch = true)
	{
		self::registerShutdown();
		if ($id === null)
		{
			self::$clearAll = true;
		}
		else
		{
			self::$idToClear[$id] = true;
		}
		self::$dispatch = $dispatch || self::$dispatch;
	}
	

	public final function clearSubCache($subCache, $dispatch = true)
	{
		self::registerShutdown();
		$cachePath = $this->getCachePath($subCache);
		if (Framework::isDebugEnabled())
		{
			Framework::debug(__METHOD__ . ' ' . $cachePath);
		}
		if (!array_key_exists($this->id, self::$idToClear))
		{
			self::$idToClear[$this->id] = array($this->keyParameters => $subCache);
		}
		else if (is_array(self::$idToClear[$this->id]))
		{
			self::$idToClear[$this->id][$this->keyParameters] = $subCache;
		}
		
		self::$dispatch = $dispatch || self::$dispatch;
	}
	
	/**
	 * This is the same as BlockCache::commitClear()
	 * but designed for the context of <code>register_shutdown_function()</code>,
	 * to be sure the correct umask is used.
	 */
	public static function shutdownCommitClear()
	{
		umask(0002);
		self::commitClear();
	}
	
	public static function commitClearDispatched($ids = null)
	{
		self::registerShutdown();
		if (Framework::isDebugEnabled())
		{
			Framework::debug("SimpleCache->commitClearDispatched");
		}
		if (is_null($ids))
		{
			self::$clearAll = true;
		}
		else
		{
			self::$idToClear = $ids;
		}
		self::$dispatch = false;
	}
	
	/**
	 */
	public static function commitClear()
	{
		if (Framework::isDebugEnabled())
		{
			Framework::debug("SimpleCache->commitClear");
		}
		$cachePath = f_util_FileUtils::buildCachePath('simplecache');
		$dirsToClear = array();
		if (self::$clearAll)
		{
			if (Framework::isDebugEnabled())
			{
				Framework::debug("Clear all");
			}
			$dirHandler = opendir($cachePath);
			while ($fileName = readdir($dirHandler))
			{
				if ($fileName != '.' && $fileName != '..' && $fileName != 'registration' && $fileName != 'old')
				{
					$dirsToClear[] = $cachePath . DIRECTORY_SEPARATOR . $fileName;
				}
			}
			self::buildInvalidCacheList($dirsToClear);
			closedir($dirHandler);
			if (self::$dispatch)
			{
				f_event_EventManager::dispatchEvent('simpleCacheCleared', null);
			}
		}
		elseif (!empty(self::$idToClear))
		{
			foreach (self::$idToClear as $id => $subKey)
			{
				if (file_exists($cachePath . DIRECTORY_SEPARATOR . $id))
				{
					$dirsToClear[] = $cachePath . DIRECTORY_SEPARATOR . $id;
				}
			}
			self::buildInvalidCacheList($dirsToClear);
			if (self::$dispatch)
			{
				f_event_EventManager::dispatchEvent('simpleCacheCleared', null, array("ids" => self::$idToClear));
			}
		}
		
		self::$clearAll = false;
		self::$idToClear = null;
	}
	
	private static function buildInvalidCacheList($dirsToClear)
	{
		$cachePath = f_util_FileUtils::buildCachePath('simplecache');
		foreach ($dirsToClear as $dir)
		{
			$dirHandler = opendir($dir);
			while ($fileName = readdir($dirHandler))
			{
				if ($fileName != '.' && $fileName != '..' && !file_exists($dir . DIRECTORY_SEPARATOR . $fileName . DIRECTORY_SEPARATOR . self::INVALID_CACHE_ENTRY))
				{
					// we ignore errors because the file can disapear
					@touch($dir . DIRECTORY_SEPARATOR . $fileName . DIRECTORY_SEPARATOR . self::INVALID_CACHE_ENTRY);
				}
			}
			closedir($dirHandler);
		}
	}
	
	public static function cleanExpiredCache()
	{
		$directoryIterator = new DirectoryIterator(f_util_FileUtils::buildChangeCachePath('simplecache'));
		foreach ($directoryIterator as $classNameDir)
		{
			if ($classNameDir->isDir())
			{
				$subDirIterator = new DirectoryIterator($classNameDir->getPathname());
				foreach ($subDirIterator as $cacheKeyDir)
				{
					$invalidCacheFilePath = $cacheKeyDir->getPathname() . DIRECTORY_SEPARATOR . self::INVALID_CACHE_ENTRY;
					if ($cacheKeyDir->isDir() && file_exists($invalidCacheFilePath))
					{
						$fileInfo = new SplFileInfo($invalidCacheFilePath);
						if (abs(date_Calendar::getInstance()->getTimestamp() - $fileInfo->getMTime()) > 86400)
						{
							f_util_FileUtils::rmdir($cacheKeyDir->getPathname());	 
						}
					}
				}
			}
		}
	}
	
	/**
	 * @param f_persistentdocument_PersistentDocumentModel $model
	 */
	public static function clearCacheByModel($model)
	{
		if (Framework::isDebugEnabled())
		{
				Framework::debug("[". __CLASS__ . "]: clear cache by model:".$model->getName());
		}
		self::clearCacheByPattern($model->getName());
		if ($model->isInjectedModel())
		{
			self::clearCacheByPattern($model->getOriginalModelName());
		}
	}
	
	/**
	 * @param f_persistentdocument_PersistentDocumentModel $model
	 */
	public static function clearCacheByTag($tag)
	{
		if (Framework::isDebugEnabled())
		{
				Framework::debug("[". __CLASS__ . "]: clear cache by tag:$tag");
		}
		self::clearCacheByPattern('tags/'.$tag );
	}
	
	/**
	 * @param String $pattern
	 */
	private static function clearCacheByPattern($pattern)
	{
		$cacheIds = self::getPersistentProvider()->getCacheIdsByPattern($pattern);
		foreach ($cacheIds as $cacheId)
		{
			if (Framework::isDebugEnabled())
			{
				Framework::debug("[". __CLASS__ . "]: clear $cacheId cache");
			}
			self::clear($cacheId);
		}
	}
	
	/**
	 * @return f_persistentdocument_PersistentProvider
	 */
	private static function getPersistentProvider()
	{
		return f_persistentdocument_PersistentProvider::getInstance();
	}
}