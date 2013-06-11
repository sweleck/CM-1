<?php

class CM_Maintenance_Cli extends CM_Cli_Runnable_Abstract {

	/**
	 * @synchronized
	 */
	public function common() {
		$this->_executeCallbacks(array(
			'CM_Model_User::offlineOld' => function () {
				CM_Model_User::offlineOld();
			},
			'CM_ModelAsset_User_Roles::deleteOld #1' => function () {
				CM_ModelAsset_User_Roles::deleteOld();
			},
			'CM_Paging_Useragent_Abstract::deleteOlder' => function () {
				CM_Paging_Useragent_Abstract::deleteOlder(100 * 86400);
			},
			'CM_File_UserContent_Temp::deleteOlder' => function () {
				CM_File_UserContent_Temp::deleteOlder(86400);
			},
			'CM_SVM_Model::deleteOldTrainings' => function () {
				CM_SVM_Model::deleteOldTrainings(3000);
			},
			'CM_SVM_Model::trainChanged' => function () {
				CM_SVM_Model::trainChanged();
			},
			'CM_Paging_Ip_Blocked::deleteOlder' => function () {
				CM_Paging_Ip_Blocked::deleteOlder(7 * 86400);
			},
			'CM_Captcha::deleteOlder' => function () {
				CM_Captcha::deleteOlder(3600);
			},
			'CM_ModelAsset_User_Roles::deleteOld #2' => function () {
				CM_ModelAsset_User_Roles::deleteOld();
			},
			'CM_Session::deleteExpired' => function () {
				CM_Session::deleteExpired();
			},
			'CM_KissTracking::exportEvents' => function () {
				CM_KissTracking::getInstance()->exportEvents();
			},
			'CM_Stream_Message::synchronize' => function () {
				CM_Stream_Message::getInstance()->synchronize();
			}
		));
	}

	/**
	 * @synchronized
	 */
	public function heavy() {
		$this->_executeCallbacks(array(
			function () {
				CM_Mail::processQueue(500);
			},
			function () {
				CM_Action_Abstract::aggregate();
			},
			function () {
				CM_Paging_Log_Abstract::deleteOlder(7 * 86400);
			}
		));
	}

	public static function getPackageName() {
		return 'maintenance';
	}

	/**
	 * @param Closure[] $callbacks
	 */
	protected function _executeCallbacks($callbacks) {
		foreach ($callbacks as $name => $callback) {
			CMService_Newrelic::getInstance()->startTransaction($name);
			try {
				$callback();
			} catch (CM_Exception $e) {
				CM_Bootloader::getInstance()->handleException($e);
			}
			CMService_Newrelic::getInstance()->endTransaction();
		}
	}
}
