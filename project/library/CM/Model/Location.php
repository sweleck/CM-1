<?php

class CM_Model_Location extends CM_Model_Abstract implements CM_ArrayConvertible {
	const LEVEL_COUNTRY = 1;
	const LEVEL_STATE = 2;
	const LEVEL_CITY = 3;
	const LEVEL_ZIP = 4;

	/**
	 * @param int $level A LEVEL_*-const
	 * @param int $id
	 */
	public function __construct($level, $id) {
		$this->_setCacheLocal();
		$this->_construct(array('id' => $id, 'level' => $level));
	}

	/**
	 * @param int	$level
	 * @param string $key
	 * @return mixed|null
	 */
	private function _getField($level, $key) {
		$level = (int) $level;
		$fields = $this->_get('fields');
		if (!array_key_exists($level, $fields)) {
			return null;
		}
		if (!array_key_exists($key, $fields[$level])) {
			return null;
		}
		return $fields[$level][$key];
	}

	/**
	 * @param int $level
	 * @return CM_Model_Location|null
	 */
	public function get($level) {
		if (!$this->getId($level)) {
			return null;
		}
		return new self($level, $this->getId($level));
	}

	/**
	 * @return int
	 */
	public function getLevel() {
		return $this->_getId('level');
	}

	/**
	 * @param int $level OPTIONAL
	 * @return int|null
	 */
	public function getId($level = null) {
		if (null === $level) {
			return $this->_getId('id');
		}
		$id = $this->_getField($level, 'id');
		if (null === $id) {
			return null;
		}
		return (int) $id;
	}

	/**
	 * @param int $level OPTIONAL
	 * @return string|null
	 */
	public function getName($level = null) {
		if (null === $level) {
			$level = $this->getLevel();
		}
		return $this->_getField($level, 'name');
	}

	/**
	 * @param int $level OPTIONAL
	 * @return string|null
	 */
	public function getAbbreviation($level = null) {
		if (null === $level) {
			$level = $this->getLevel();
		}
		return $this->_getField($level, 'abbreviation');
	}

	/**
	 * @return float[]|null
	 */
	public function getCoordinates() {
		for ($level = $this->getLevel(); $level >= self::LEVEL_CITY; $level--) {
			$lat = $this->_getField($level, 'lat');
			$lon = $this->_getField($level, 'lon');
			if ($lat && $lon) {
				return array('lat' => (float) $lat, 'lon' => (float) $lon);
			}
		}
		return null;
	}

	protected function _loadData() {
		switch ($this->getLevel()) {
			case self::LEVEL_ZIP:
				$query = 'SELECT `1`.`id` `1.id`, `1`.`name` `1.name`, `1`.`abbreviation` `1.abbreviation`,
						`2`.`id` `2.id`, `2`.`name` `2.name`,
						`3`.`id` `3.id`, `3`.`name` `3.name`, `3`.`lat` `3.lat`, `3`.`lon` `3.lon`,
						`4`.`id` `4.id`, `4`.`name` `4.name`, `4`.`lat` `4.lat`, `4`.`lon` `4.lon`
					FROM TBL_CM_LOCATIONZIP AS `4`
					LEFT JOIN TBL_CM_LOCATIONCITY AS `3` ON(`4`.`cityId`=`3`.`id`)
					LEFT JOIN TBL_CM_LOCATIONSTATE AS `2` ON(`3`.`stateId`=`2`.`id`)
					LEFT JOIN TBL_CM_LOCATIONCOUNTRY AS `1` ON(`3`.`countryId`=`1`.`id`)
					WHERE `4`.`id` = ?';
				break;
			case self::LEVEL_CITY:
				$query = 'SELECT `1`.`id` `1.id`, `1`.`name` `1.name`, `1`.`abbreviation` `1.abbreviation`,
						`2`.`id` `2.id`, `2`.`name` `2.name`,
						`3`.`id` `3.id`, `3`.`name` `3.name`, `3`.`lat` `3.lat`, `3`.`lon` `3.lon`
					FROM TBL_CM_LOCATIONCITY AS `3`
					LEFT JOIN TBL_CM_LOCATIONSTATE AS `2` ON(`3`.`stateId`=`2`.`id`)
					LEFT JOIN TBL_CM_LOCATIONCOUNTRY AS `1` ON(`3`.`countryId`=`1`.`id`)
					WHERE `3`.`id` = ?';
				break;
			case self::LEVEL_STATE:
				$query = 'SELECT `1`.`id` `1.id`, `1`.`name` `1.name`, `1`.`abbreviation` `1.abbreviation`,
						`2`.`id` `2.id`, `2`.`name` `2.name`
					FROM TBL_CM_LOCATIONSTATE AS `2`
					LEFT JOIN TBL_CM_LOCATIONCOUNTRY AS `1` ON(`2`.`countryId`=`1`.`id`)
					WHERE `2`.`id` = ?';
				break;
			case self::LEVEL_COUNTRY:
				$query = 'SELECT `1`.`id` `1.id`, `1`.`name` `1.name`, `1`.`abbreviation` `1.abbreviation`
					FROM TBL_CM_LOCATIONCOUNTRY AS `1`
					WHERE `1`.`id` = ?';
				break;
			default:
				throw new CM_Exception_Invalid('Invalid level `' . $this->getLevel() . '`.');
				break;
		}

		$row = CM_Mysql::execRead($query, $this->getId())->fetchAssoc();
		if (!$row) {
			throw new CM_Exception_Invalid('Cannot load location `' . $this->getId() . '` on level `' . $this->getLevel() . '`.');
		}
		$fields = array();
		foreach ($row as $key => $value) {
			list($level, $field) = explode('.', $key);
			if (!array_key_exists($level, $fields)) {
				$fields[$level] = array();
			}
			$fields[$level][$field] = $value;
		}
		return array('fields' => $fields);
	}

	/**
	 * @param int $ip
	 * @return CM_Model_Location|null
	 */
	public static function findByIp($ip) {
		$cacheKey = CM_CacheConst::Location_ByIp . '_ip:' . $ip;
		if ((list($level, $id) = CM_CacheLocal::get($cacheKey)) === false) {
			$level = $id = null;
			if ($id = self::_getLocationIdByIp(TBL_CM_LOCATIONCITYIP, 'cityId', $ip)) {
				$level = self::LEVEL_CITY;
			} elseif ($id = self::_getLocationIdByIp(TBL_CM_LOCATIONCOUNTRYIP, 'countryId', $ip)) {
				$level = self::LEVEL_COUNTRY;
			}
			CM_CacheLocal::set($cacheKey, array($level, $id));
		}
		if (!$level && !$id) {
			return null;
		}
		return new self($level, $id);
	}

	/**
	 * @param string $db_table
	 * @param string $db_column
	 * @param int	$ip
	 * @return int|false
	 */
	private static function _getLocationIdByIp($db_table, $db_column, $ip) {
		$result = CM_Mysql::execRead("SELECT `ipStart`, `?` FROM `?`
			WHERE `ipEnd` >= ?
			ORDER BY `ipEnd` ASC
			LIMIT 1", $db_column, $db_table, $ip)->fetchAssoc();
		if ($result) {
			if ($result['ipStart'] <= $ip) {
				return (int) $result[$db_column];
			}
		}
		return false;
	}

	public function toArray() {
		return array('level' => $this->getLevel(), 'id' => $this->getId());
	}

	public static function fromArray(array $data) {
		return new self($data['level'], $data['id']);
	}

	public static function dumpToTable() {
		CM_Mysql::exec('TRUNCATE TABLE `' . TBL_CM_TMP_LOCATION . '`');
		CM_Mysql::exec('INSERT `' . TBL_CM_TMP_LOCATION . '` (`level`,`id`,`1Id`,`2Id`,`3Id`,`4Id`,`name`, `abbreviation`, `lat`,`lon`)
			SELECT 1, `1`.`id`, `1`.`id`, NULL, NULL, NULL,
					`1`.`name`, `1`.`abbreviation`, NULL, NULL
			FROM `' . TBL_CM_LOCATIONCOUNTRY . '` AS `1`
			UNION
			SELECT 2, `2`.`id`, `1`.`id`, `2`.`id`, NULL, NULL,
					`2`.`name`, NULL, NULL, NULL
			FROM `' . TBL_CM_LOCATIONSTATE . '` AS `2`
			LEFT JOIN `' . TBL_CM_LOCATIONCOUNTRY . '` AS `1` ON(`2`.`countryId`=`1`.`id`)
			UNION
			SELECT 3, `3`.`id`, `1`.`id`, `2`.`id`, `3`.`id`, NULL,
					`3`.`name`, NULL, `3`.`lat`, `3`.`lon`
			FROM `' . TBL_CM_LOCATIONCITY . '` AS `3`
			LEFT JOIN `' . TBL_CM_LOCATIONSTATE . '` AS `2` ON(`3`.`stateId`=`2`.`id`)
			LEFT JOIN `' . TBL_CM_LOCATIONCOUNTRY . '` AS `1` ON(`3`.`countryId`=`1`.`id`)
			UNION
			SELECT 4, `4`.`id`, `1`.`id`, `2`.`id`, `3`.`id`, `4`.`id`, 
					`4`.`name`, NULL, `4`.`lat`, `4`.`lon`
			FROM `' . TBL_CM_LOCATIONZIP . '` AS `4`
			LEFT JOIN `' . TBL_CM_LOCATIONCITY . '` AS `3` ON(`4`.`cityId`=`3`.`id`)
			LEFT JOIN `' . TBL_CM_LOCATIONSTATE . '` AS `2` ON(`3`.`stateId`=`2`.`id`)
			LEFT JOIN `' . TBL_CM_LOCATIONCOUNTRY . '` AS `1` ON(`3`.`countryId`=`1`.`id`)');
	}
}