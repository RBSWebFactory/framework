<?php
class commands_ApplyPatch extends commands_AbstractChangeCommand
{
	/**
	 * @return String
	 */
	function getUsage()
	{
		return "<moduleName> <patchNumber>";
	}

	function getAlias()
	{
		return "ap";
	}

	/**
	 * @return String
	 */
	function getDescription()
	{
		return "Applies a patch";
	}

	/**
	 * @param Integer $completeParamCount the parameters that are already complete in the command line
	 * @param String[] $params
	 * @param array<String, String> $options where the option array key is the option name, the potential option value or true
	 * @return String[] or null
	 */
	function getParameters($completeParamCount, $params, $options)
	{
		if ($completeParamCount > 1)
		{
			return null;
		}
		$this->loadFramework();
		$list = PatchService::getInstance()->check();
		if (f_util_ArrayUtils::isEmpty($list))
		{
			return null;
		}
		if ($completeParamCount == 0)
		{
			$components = array();
			foreach ($list as $packageName => $patchList)
			{
				$components[] = str_replace('modules_', '', $packageName);
			}
			return $components;
		}
		if ($completeParamCount == 1)
		{
			$packageName = $params[0];
			if ($packageName != "framework")
			{
				$packageName = "modules_".$packageName;
			}
			if (isset($list[$packageName]))
			{
				return $list[$packageName];
			}
		}
	}

	/**
	 * @param String[] $params
	 * @param array<String, String> $options where the option array key is the option name, the potential option value or true
	 */
	protected function validateArgs($params, $options)
	{
		return count($params) == 2;
	}

	/**
	 * @param String[] $params
	 * @param array<String, String> $options where the option array key is the option name, the potential option value or true
	 * @see c_ChangescriptCommand::parseArgs($args)
	 */
	function _execute($params, $options)
	{
		$moduleName = $params[0];
		$patchNumber = $params[1];

		$this->message("== Apply patch $moduleName/$patchNumber ==");
		$this->loadFramework();

		try
		{
			// Get a instance of class
			$className = $moduleName . '_patch_' . $patchNumber;
			if (!f_util_ClassUtils::classExists($className))
			{
				throw new ClassNotFoundException($className);
			}
			$patch = new $className($this);
			$patch->executePatch();
			PatchService::getInstance()->patchApply($moduleName, $patchNumber, $patch->isCodePatch());
			return $this->quitOk('Patch "' . $moduleName.'/'.$patchNumber . '" successfully applied.');
		}
		catch (ClassNotFoundException $e)
		{
			return $this->quitError("The patch \"".$moduleName.'/'.$patchNumber."\" cannot be applied automatically.
You may need to execute the following command before: change update-autoload.\n
Please check the README file to know how to apply this patch.");
		}
		catch (Exception $e)
		{
			return $this->quitError("Application of patch \"".$moduleName."/".$patchNumber."\" failed:\n".$e->getMessage()."\n".$e->getTraceAsString());
		}
	}
}