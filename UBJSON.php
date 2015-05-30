<?php

/**
 * Informs if the system's CPU uses the little endian byte order
 * 
 * @var bool
 */
define('SYS_LITTLE_ENDIAN', pack('S', 0xFF) === pack('v', 0xFF));

class UBJSON {

	const TYPE_ARRAY  = 0;
	const TYPE_OBJECT = 1;

	//internal constants
	const EOF	= 0;
	const DATA	= 1;

	const NOOP   = 'N';
	const NULL   = 'Z';
	const FALSE  = 'F';
	const TRUE   = 'T';
	const INT8   = 'i';
	const UINT8  = 'U';
	const INT16  = 'I';
	const INT32  = 'l';
	const INT64  = 'L';
	const FLOAT  = 'd';
	const DOUBLE = 'D';
	const CHAR   = 'C';
	const STRING = 'S';
	const HIGH_PRECISION = 'H';
	const ARRAY_OPEN	 = '[';
	const ARRAY_CLOSE	 = ']';
	const OBJECT_OPEN	 = '{';
	const OBJECT_CLOSE	 = '}';


	protected $_decodeType = self::TYPE_ARRAY;

	protected $_source;
	protected $_sourceLength;
	protected $_offset = 0;
	
	protected $_token = self::EOF;
	protected $_tokenValue = null;
	
	protected $_throwException = true;
	protected static $_lastErrorMessage = null;

	
	protected function __construct($source) {
		$this->_source = $source;
		if (is_string($this->_source)) {
			$this->_sourceLength = strlen($this->_source);
		}
	}
	
	/**
	 * encode data into ubson format
	 * @param mixed $value
	 * @return string
	 */
	public static function encode($value) {
		$ubjson = new self($value);
		
		return $ubjson->_encodeValue($value);
	}
	
	/**
	 * @param mixed $value
	 * @return string
	 */
	protected function _encodeValue(&$value) {
		
		$result = null;
		if (is_object($value)) {
			$result = $this->_encodeObject($value);
		} elseif (is_array($value)) {
			$result = $this->_encodeArray($value);
		} elseif (is_int($value) || is_float($value)) {
			$result = $this->_encodeNumeric($value);
		} elseif (is_string($value)) {
			$result = $this->_encodeString($value);
		} elseif ($value === null) {
			$result = self::NULL;
		} elseif (is_bool($value)) {
			$result = $value ? self::TRUE : self::FALSE;
		}
		
		return $result;
	}
	
	/**
	 * @param array $array
	 * @return string
	 */
	protected function _encodeArray(&$array) {
		
		if (!empty($array) && (array_keys($array) !== range(0, count($array) - 1))) {
			// associative array
			$result = self::OBJECT_OPEN;
			foreach ($array as $key => $value) {
				$key = (string)$key;
				$result .= $this->_encodeString($key).$this->_encodeValue($value);
			}
			$result .= self::OBJECT_CLOSE;
		} else {
			// indexed array
			$result = self::ARRAY_OPEN;
			$length = count($array);
			for ($i = 0; $i < $length; $i++) {
				$result .= $this->_encodeValue($array[$i]);
			}
			$result .= self::ARRAY_CLOSE;
		}
		
		return $result;
	}
	
	/**
	 * @param stdClass $object
	 * @return string
	 */
	protected function _encodeObject(&$object) {
		
		if ($object instanceof Iterator) {
			$propCollection = (array)$object;
		} else {
			$propCollection = get_object_vars($object);
		}
		
		return $this->_encodeArray($propCollection);
	}
	
	/**
	 * @param string $string
	 * @return string
	 */
	protected function _encodeString(&$string) {
		$result = null;
		
		$len = strlen($string);
		if ($len == 1) {
			$result = $prefix = self::CHAR.$string;
		} else {
			$prefix = self::STRING;
			if (preg_match('/^[\d]+(:?\.[\d]+)?$/', $string)) {
				$prefix = self::HIGH_PRECISION;
			}
			$result = $prefix.$this->_encodeNumeric(strlen($string)).$string;
		}
		
		return $result;
	}
	
	/**
	 * @param int|float $numeric
	 * @return string
	 */
	protected function _encodeNumeric($numeric) {
		$result = $pack = null;
                $swap   = SYS_LITTLE_ENDIAN;
		
		if (is_int($numeric)) {
			if (256 > $numeric) {
                            $swap = false;
				if (0 < $numeric) {
					$result = self::UINT8;
                                        $pack   = 'C';
				} else {
					$result = self::INT8;
                                        $pack   = 'c';
				}
			} elseif (32768 > $numeric) {
				$result = self::INT16;
                                $pack   = 's';
			} elseif (2147483648 > $numeric) {
				$result = self::INT32;
                                $pack   = 'l';
			}
		} elseif (is_float($numeric)) {
			$result = self::FLOAT;
                        $pack   = 'f';
		}
                
                $packed = pack($pack, $numeric);
                if ($swap) {
                    $packed = strrev($packed);
                }
		$result .= $packed;
		return $result;
	}
	
	/**
	 * decode ubjson format
	 * 
	 * @param string $source
	 * @param int $decodeType
	 * @return mixed
	 */
	public static function decode($source, $decodeType = self::TYPE_ARRAY, $throwException = true) {
		$ubjson = new self($source);
		$ubjson->setDecodeType($decodeType);
		$ubjson->_getNextToken();
		$ubjson->setThrowException($throwException);
		$ubjson->cleanLastErrorMessage();
		
		return $ubjson->_decodeValue();
	}
	
	public function setDecodeType($decodeType = self::TYPE_ARRAY) {
		$this->_decodeType = $decodeType;
	}
	
	/**
	 * decode string
	 * 
	 * @return mixed
	 */
	protected function _decodeValue() {
		$result = null;
		
		switch ($this->_token) {
			case self::DATA:
				$result = $this->_tokenValue;
				$this->_getNextToken();
				break;
			case self::ARRAY_OPEN:
			case self::OBJECT_OPEN:
				$result = $this->_decodeStruct();
				break;
			default:
				$result = null;
		}
		
		return $result;
	}

	/**
	 * get ubjson token and extract data
	 * 
	 * @return string
	 */
	protected function _getNextToken() {
		
		$this->_token = self::EOF;
		$this->_tokenValue = null;
		
		if ($this->_offset >= $this->_sourceLength) {
			return $this->_token;
		}
		
		$val = null;
		++$this->_offset;
		$token = $this->_source{$this->_offset-1};
		$this->_token = self::DATA;
		
		switch ($token) {
			case self::INT8:
				$this->_tokenValue = $this->_unpack('c', 1);
				break;
			case self::UINT8:
				$this->_tokenValue = $this->_unpack('C', 1);
				break;
			case self::INT16:
				$this->_tokenValue = $this->_unpack('s', 2);
				break;
			case self::INT32:
				$this->_tokenValue = $this->_unpack('l', 4);
				break;
// 			case self::INT64:
// 				//unsupported
//				break;
			case self::FLOAT:
				$this->_tokenValue = $this->_unpack('f', 4);
				break;
// 			case self::DOUBLE:
// 				//unsupported
// 				break;
			case self::TRUE:
				$this->_tokenValue = true;
				break;
			case self::FALSE:
				$this->_tokenValue = false;
				break;
			case self::NULL:
				$this->_tokenValue = null;
				break;
			case self::CHAR:
				$this->_tokenValue = $this->_read(1);
				break;
// 			case self::NOOP:
// 				$this->_tokenValue = null;
// 				break;
			case self::STRING:
			case self::HIGH_PRECISION:
				++$this->_offset;
				$len = 0;
				switch ($this->_source{$this->_offset-1}) {
					case self::INT8:
						$len = $this->_unpack('c', 1);
						break;
					case self::UINT8:
						$len = $this->_unpack('C', 1);
						break;
					case self::INT16:
						$len = $this->_unpack('s', 2);
						break;
					case self::INT32:
						$len = $this->_unpack('l', 4);
						break;
					default:
						//unsupported
						$this->_token = null;
				}
				$this->_tokenValue = '';
				if ($len) {
					$this->_tokenValue = $this->_read($len);
				}
				break;
			case self::OBJECT_OPEN:
				$this->_token = self::OBJECT_OPEN;
				break;
			case self::OBJECT_CLOSE:
				$this->_token = self::OBJECT_CLOSE;
				break;
			case self::ARRAY_OPEN:
				$this->_token = self::ARRAY_OPEN;
				break;
			case self::ARRAY_CLOSE:
				$this->_token = self::ARRAY_CLOSE;
				break;
			default:
				$this->_token = self::EOF;
		}
		
		return $this->_token;
	}
	
	/**
	 * decode combined data from source
	 * 
	 * @return stdClass|array
	 */
	protected function _decodeStruct() {
		
		$key = 0;
		$tokenOpen = $this->_token;
		
		if ($tokenOpen == self::OBJECT_OPEN && $this->_decodeType == self::TYPE_OBJECT) {
			$result = new stdClass();
		} else {
			$result = array();
		}
		
		$structEnd = array(self::OBJECT_CLOSE, self::ARRAY_CLOSE);
		
		$tokenCurrent = $this->_getNextToken();
		while ($tokenCurrent && !in_array($tokenCurrent, $structEnd)) {
			
			if ($tokenOpen == self::OBJECT_OPEN) {
				$key = $this->_tokenValue;
				$tokenCurrent = $this->_getNextToken();
			}

			$value = $this->_decodeValue();
			$tokenCurrent = $this->_token;
			
			if ($tokenOpen == self::OBJECT_OPEN && $this->_decodeType == self::TYPE_OBJECT) {
				$result->$key = $value;
			} else {
				$result[$key] = $value;
			}
			
			if (in_array($tokenCurrent, $structEnd)) {
				break;
			}

			if ($tokenOpen != self::OBJECT_OPEN) {
				++$key;
			}
		}
		
		$this->_getNextToken();
		
		return $result;
	}
	
	
	public function setThrowException($throw) {
		$this->_throwException = $throw;
	}
	
	public static function getLastErrorMessage() {
		return self::$_lastErrorMessage;
	}
	
	public static function cleanLastErrorMessage() {
		self::$_lastErrorMessage = null;
	}
	
	/**
	 * read N bytes from source string
	 * 
	 * @param int $bytes
	 * @return mixed
	 */
	protected function _read($bytes = 1) {
		$result = substr($this->_source, $this->_offset, $bytes);
		$this->_offset += $bytes;
		
		return $result;
	}
	
	protected function _unpack($flag, $bytes) {
		$value = null;
		
		if ($this->_sourceLength < $this->_offset + $bytes) {
			$exception = new UbjsonDecodeException('invalid ubjson data');
			if ($this->_throwException) {
				throw $exception;
			}
			self::$_lastErrorMessage = $exception->getMessage();
		} else {
                        $packed = $this->_read($bytes);
                        switch ($flag) {
                            case 's':
                            case 'l':
                            case 'f':
                                $swap = SYS_LITTLE_ENDIAN;
                                break;
                            default:
                                $swap = false;
                        }
                        if ($swap) {
                            $packed = strrev($packed);
                        }
			list(, $value) = unpack($flag, $packed);
		}
		
		return $value;
	}
}


class UbjsonDecodeException extends Exception {
	
}

