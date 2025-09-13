<?php
//� 2025 Martin Madsen
namespace MTM\WsSocket\Tools\V1;

abstract class Base extends \MTM\Utilities\Tools\Validations\V1
{	
	protected $_typeCodes=array("continuation" => 0, "text" => 1, "binary" => 2, "close" => 8, "ping" => 9, "pong" => 10);
	
	protected function getDataTypeCode($type)
	{
		$this->isStrMax($type, 12, true);
		if (array_key_exists($type, $this->_typeCodes) === true) {
			return $this->_typeCodes[$type];
		} else {
			throw new \Exception("Invalid Data type name: '" . $type."'", 1111);
		}
	}
	protected function getDataTypeName($code)
	{
		$this->isUsign32Int($code, true);
		$type	= array_search($code, $this->_typeCodes);
		if ($type !== false) {
			return $type;
		} else {
			throw new \Exception("Invalid Datatype Code: ".$code, 1111);
		}
	}
	protected function microTime()
	{
		return \MTM\Utilities\Factories::getTime()->getMicroEpoch();
	}
	public function throwErrors($errno, $errstr, $errfile, $errline)
	{
		throw new \Exception($errstr, $errno);
	}
}