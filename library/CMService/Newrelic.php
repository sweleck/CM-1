<?php

class CMService_Newrelic extends CM_Class_Abstract {

	/** @var CMService_Newrelic */
	protected static $_instance;

	/**
	 * @throws CM_Exception_Invalid
	 */
	final public function __construct() {
		if (self::$_instance) {
			throw new CM_Exception_Invalid('Newrelic already instantiated');
		}
		self::$_instance = $this;
	}

	public function setConfig() {
		if ($this->_isEnabled()) {
			newrelic_set_appname($this->_getAppName());
		}
	}

	/**
	 * @param Exception $exception
	 */
	public function setNoticeError(Exception $exception) {
		if ($this->_isEnabled()) {
			newrelic_notice_error($exception->getMessage(), $exception);
		}
	}

	/**
	 * @param string $name
	 */
	public function startTransaction($name) {
		if ($this->_isEnabled()) {
			$this->endTransaction();
			newrelic_start_transaction($this->_getAppName());
			$this->setNameTransaction($name);
		}
	}

	/**
	 * @param string $name
	 */
	public function setNameTransaction($name) {
		$name = (string) $name;
		if ($this->_isEnabled()) {
			newrelic_name_transaction($name);
		}
	}

	public function endTransaction() {
		if ($this->_isEnabled()) {
			newrelic_end_transaction();
		}
	}

	/**
	 * @param bool|null $flag
	 */
	public function setBackgroundJob($flag = null) {
		if (null === $flag) {
			$flag = true;
		}
		if ($this->_isEnabled()) {
			newrelic_background_job($flag);;
		}
	}

	/**
	 * @throws CM_Exception_Invalid
	 * @return bool
	 */
	private function _isEnabled() {
		if (self::_getConfig()->enabled) {
			if (!extension_loaded('newrelic')) {
				throw new CM_Exception_Invalid('Newrelic Extension is not installed.');
			}
			return true;
		}
		return false;
	}

	/**
	 * @return string
	 */
	private function _getAppName() {
		return (string) $this->_getConfig()->appName;
	}

	/**
	 * @return CMService_Newrelic
	 * @throws Exception
	 */
	public static function getInstance() {
		if (!self::$_instance) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}
}
