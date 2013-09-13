<?php
require_once 'Util.php';

class CM_Bootloader {

	/** @var CM_Config|null */
	private $_config = null;

	/** @var string[] */
	private $_environments = array();

	/** @var boolean */
	private $_loaded = false;

	/** @var array|null */
	private $_namespacePaths;

	/** @var int|null */
	private $_exceptionOutputSeverityMin;

	/** @var CM_Bootloader */
	protected static $_instance;

	/**
	 * @param string      $pathRoot
	 * @param string|null $dirLibrary
	 * @throws CM_Exception_Invalid
	 */
	final public function __construct($pathRoot, $dirLibrary) {
		if (self::$_instance) {
			throw new CM_Exception_Invalid('Bootloader already instantiated');
		}
		self::$_instance = $this;
		define('DIR_ROOT', $pathRoot);
		define('DIR_LIBRARY', $dirLibrary);
	}

	public function defaults() {
		date_default_timezone_set(CM_Config::get()->timeZone);
		mb_internal_encoding('UTF-8');
		umask(0);
		CMService_Newrelic::getInstance()->setConfig();
	}

	public function exceptionHandler() {
		set_exception_handler(function (Exception $exception) {
			if (!headers_sent()) {
				header('Content-Type: text/plain');
			}
			CM_Bootloader::getInstance()->handleException($exception);
			exit(1);
		});
	}

	public function errorHandler() {
		error_reporting((E_ALL | E_STRICT) & ~(E_NOTICE | E_USER_NOTICE));
		set_error_handler(function ($errno, $errstr, $errfile, $errline) {
			$errorCodes = array(
				E_ERROR             => 'E_ERROR',
				E_WARNING           => 'E_WARNING',
				E_PARSE             => 'E_PARSE',
				E_NOTICE            => 'E_NOTICE',
				E_CORE_ERROR        => 'E_CORE_ERROR',
				E_CORE_WARNING      => 'E_CORE_WARNING',
				E_COMPILE_ERROR     => 'E_COMPILE_ERROR',
				E_COMPILE_WARNING   => 'E_COMPILE_WARNING',
				E_USER_ERROR        => 'E_USER_ERROR',
				E_USER_WARNING      => 'E_USER_WARNING',
				E_USER_NOTICE       => 'E_USER_NOTICE',
				E_STRICT            => 'E_STRICT',
				E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
				E_DEPRECATED        => 'E_DEPRECATED',
				E_USER_DEPRECATED   => 'E_USER_DEPRECATED',
				E_ALL               => 'E_ALL',
			);
			$errstr = $errorCodes[$errno] . ': ' . $errstr;
			if (!(error_reporting() & $errno)) {
				// This error code is not included in error_reporting
				$atSign = (0 === error_reporting()); // http://php.net/manual/en/function.set-error-handler.php
				if ($atSign) {
					return true;
				}
			}
			throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
		});
	}

	public function constants() {
		define('DIR_VENDOR', DIR_ROOT . 'vendor' . DIRECTORY_SEPARATOR);
		define('DIR_PUBLIC', DIR_ROOT . 'public' . DIRECTORY_SEPARATOR);

		define('IS_DEBUG', (bool) CM_Config::get()->debug && !CM_Bootloader::getInstance()->isEnvironment('test'));

		define('DIR_DATA', !empty(CM_Config::get()->dirData) ? CM_Config::get()->dirData : DIR_ROOT . 'data' . DIRECTORY_SEPARATOR);
		define('DIR_DATA_LOCKS', DIR_DATA . 'locks' . DIRECTORY_SEPARATOR);
		define('DIR_DATA_LOG', DIR_DATA . 'logs' . DIRECTORY_SEPARATOR);
		define('DIR_DATA_SVM', DIR_DATA . 'svm' . DIRECTORY_SEPARATOR);

		define('DIR_TMP', !empty(CM_Config::get()->dirTmp) ? CM_Config::get()->dirTmp : DIR_ROOT . 'tmp' . DIRECTORY_SEPARATOR);
		define('DIR_TMP_SMARTY', DIR_TMP . 'smarty' . DIRECTORY_SEPARATOR);
		define('DIR_TMP_CACHE', DIR_TMP . 'cache' . DIRECTORY_SEPARATOR);

		define('DIR_USERFILES', !empty(CM_Config::get()->dirUserfiles) ? CM_Config::get()->dirUserfiles :
				DIR_PUBLIC . 'userfiles' . DIRECTORY_SEPARATOR);
	}

	/**
	 * @return CM_Config
	 */
	public function getConfig() {
		if (null === $this->_config) {
			$this->_config = new CM_Config();
		}
		return $this->_config;
	}

	/**
	 * @return string[]
	 */
	public function getNamespaces() {
		return array_keys($this->getModules());
	}

	/**
	 * @return array[]
	 */
	public function getModules() {
		$cacheKey = DIR_ROOT . '_CM_Modules';
		if (false === ($modules = apc_fetch($cacheKey))) {
			$packages = $this->_getPackages();
			$getModules = function ($packageName) use (&$getModules, $packages) {
				$package = $packages[$packageName];
				$dependencies = array();
				$modules = array();
				foreach ($package['dependencies'] as $dependencyPackageName) {
					$dependencies = array_merge($getModules($dependencyPackageName), $dependencies);
				}
				foreach ($package['modules'] as $name => $path) {
					$modules[$name] = array(
						'path' => $package['path'] . $path,
						'dependencies' => array_keys($dependencies),
					);
				}
				$modules = array_merge($dependencies, $modules);
				return $modules;
			};
			$modules = $getModules($this->_getName());
			apc_store($cacheKey, $modules);
		}
		return $modules;
	}

	/**
	 * @param $moduleName
	 * @return string[]
	 */
	public function getModuleNamespaces($moduleName) {
		$modules = $this->getModules();
		$getNamespaces = function ($moduleName) use (&$getNamespaces, $modules) {
			$namespaces = array($moduleName);
			$module = $modules[$moduleName];
			foreach ($module['dependencies'] as $dependencyName) {
				$namespaces = array_merge($namespaces, $getNamespaces($dependencyName));
			}
			return $namespaces;
		};
		return $getNamespaces($moduleName);
	}

	/**
	 * @return string[]
	 */
	public function getEnvironment() {
		return $this->_environments;
	}

	/**
	 * @param string $environment
	 * @return boolean
	 */
	public function isEnvironment($environment) {
		return in_array((string) $environment, $this->_environments);
	}

	/**
	 * @param string[]|string $environments
	 * @throws CM_Exception_Invalid
	 */
	public function setEnvironment($environments) {
		if ($this->_loaded) {
			throw new CM_Exception_Invalid('Bootloader already loaded.');
		}
		$environments = (array) $environments;
		array_walk($environments, function (&$environment) {
			$environment = (string) $environment;
		});
		$this->_environments = $environments;
	}

	/**
	 * @param string[] $functions
	 * @throws Exception
	 */
	public function load(array $functions) {
		$this->_loaded = true;
		foreach ($functions as $function) {
			if (!method_exists($this, $function)) {
				throw new Exception('Non existent bootload function `' . $function . '`');
			}
			$this->$function();
		}
	}

	/**
	 * @return array
	 */
	public function _getNamespacePaths() {
		return array_map(function($module) {
			return $module['path'];
		}, $this->getModules());
	}

	/**
	 * @param string $namespace
	 * @return string
	 */
	public function getNamespacePath($namespace) {
		$namespacePaths = $this->_getNamespacePaths();
		if (isset($namespacePaths[$namespace])) {
			return $namespacePaths[$namespace];
		}
		return '';
	}

	/**
	 * @param int|null $severity
	 */
	public function setExceptionOutputSeverityMin($severity) {
		if (null !== $severity) {
			$severity = (int) $severity;
		}
		$this->_exceptionOutputSeverityMin = $severity;
	}

	/**
	 * @param Exception                      $exception
	 * @param CM_OutputStream_Interface|null $output
	 */
	public function handleException(Exception $exception, CM_OutputStream_Interface $output = null) {
		if (null === $output) {
			$output = new CM_OutputStream_Stream_Output();
		}
		$exceptionFormatter = function (Exception $exception) {
			$text = get_class($exception) . ' (' . $exception->getCode() . '): ' . $exception->getMessage() . PHP_EOL;
			$text .= '## ' . $exception->getFile() . '(' . $exception->getLine() . '):' . PHP_EOL;
			$text .= $exception->getTraceAsString() . PHP_EOL;
			return $text;
		};

		if (!CM_Bootloader::getInstance()->isEnvironment('cli') && !CM_Bootloader::getInstance()->isEnvironment('test')) {
			header('HTTP/1.1 500 Internal Server Error');
		}

		try {
			if ($exception instanceof CM_Exception) {
				$log = $exception->getLog();
			} else {
				$log = new CM_Paging_Log_Error();
			}
			$log->add($exceptionFormatter($exception));
		} catch (Exception $loggerException) {
			$logEntry = '[' . date('d.m.Y - H:i:s', time()) . ']' . PHP_EOL;
			$logEntry .= '### Cannot log error: ' . PHP_EOL;
			$logEntry .= $exceptionFormatter($loggerException);
			$logEntry .= '### Original Exception: ' . PHP_EOL;
			$logEntry .= $exceptionFormatter($exception) . PHP_EOL;
			file_put_contents(DIR_DATA_LOG . 'error.log', $logEntry, FILE_APPEND);
		}

		$severity = CM_Exception::ERROR;
		if ($exception instanceof CM_Exception) {
			$severity = $exception->getSeverity();
		}

		$outputEnabled = true;
		if (null !== $this->_exceptionOutputSeverityMin) {
			$outputEnabled = ($severity >= $this->_exceptionOutputSeverityMin);
		}
		if ($outputEnabled) {
			$outputVerbose = IS_DEBUG || CM_Bootloader::getInstance()->isEnvironment('cli') || CM_Bootloader::getInstance()->isEnvironment('test');
			if ($outputVerbose) {
				$output->writeln(get_class($exception) . ' (' . $exception->getCode() . '): ' . $exception->getMessage());
				$output->writeln('Thrown in: ' . $exception->getFile() . ':' . $exception->getLine());
				$output->writeln($exception->getTraceAsString());
			} else {
				$output->writeln('Internal server error');
			}
		}

		if ($severity >= CM_Exception::ERROR) {
			CMService_Newrelic::getInstance()->setNoticeError($exception);
		}
	}

	/**
	 * @return \Composer\Composer
	 */
	private function _getComposer() {
		static $composer;
		if (!$composer) {
			$oldCwd = getcwd();
			chdir(DIR_ROOT);
			$io = new Composer\IO\NullIO();
			$composer = Composer\Factory::create($io, DIR_ROOT . 'composer.json');
			chdir($oldCwd);
		}
		return $composer;
	}

	/**
	 * @return \Composer\Package\CompletePackage[]
	 */
	private function _getComposerPackages() {

		$composer = $this->_getComposer();
		$repo = $composer->getRepositoryManager()->getLocalRepository();

		$packages = $repo->getPackages();
		$packages[] = $composer->getPackage();
		$packages = array_filter($packages, function ($package) {
			/** @var \Composer\Package\CompletePackage $package */
			return array_key_exists('cm-modules', $package->getExtra());
		});
		return $packages;
	}

	/**
	 * @return array[]
	 */
	private function _getPackages() {
		$installationManager = $this->_getComposer()->getInstallationManager();
		$composerPackages = $this->_getComposerPackages();

		$packages = array();
		foreach ($composerPackages as $package) {
			$path = '';
			if (!$package instanceof \Composer\Package\RootPackage) {
				$path = $installationManager->getInstallPath($package);
				$path = preg_replace('/^' . preg_quote(DIR_ROOT, '/') . '/', '', $path) . '/';
			}

			$extra = $package->getExtra();
			$modules = $extra['cm-modules'];

			$dependencies = array();
			foreach ($composerPackages as $possibleDependency) {
				$dependencyName = $possibleDependency->getName();
				if (array_key_exists($dependencyName, $package->getRequires())) {
					$dependencies[] = $dependencyName;
				}
			}

			$packages[$package->getName()] = array(
				'path'         => $path,
				'modules'      => $modules,
				'dependencies' => $dependencies,
			);
		}
		return $packages;
	}

	/**
	 * @return string
	 */
	private function _getName() {
		return $this->_getComposer()->getPackage()->getName();
	}

	/**
	 * @return CM_Bootloader
	 * @throws Exception
	 */
	public static function getInstance() {
		if (!self::$_instance) {
			throw new Exception('No bootloader instance');
		}
		return self::$_instance;
	}
}
