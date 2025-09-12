<?php
//� 2025 Martin Madsen
namespace MTM\WsSocket\Models\Server\V1;

abstract class CallBacks extends Alpha
{
	protected $_connCb=null;
	protected $_termCb=null;
	
	public function setClientConnectCb($obj, $method)
	{
		if (is_object($obj) === false) {
			throw new \Exception("Invalid input, object expected", 1111);
		} elseif (is_string($method) === false) {
			throw new \Exception("Invalid input, string expected", 1111);
		} elseif (method_exists($obj, $method) === false) {
			throw new \Exception("Invalid input, object does not contain method", 1111);
		}
		$this->_connCb		= array($obj, $method);
		return $this;
	}
	public function getClientConnectCb()
	{
		return $this->_connCb;
	}
	public function setClientTermCb($obj, $method)
	{
		if (is_object($obj) === false) {
			throw new \Exception("Invalid input, object expected", 1111);
		} elseif (is_string($method) === false) {
			throw new \Exception("Invalid input, string expected", 1111);
		} elseif (method_exists($obj, $method) === false) {
			throw new \Exception("Invalid input, object does not contain method", 1111);
		}
		$this->_termCb		= array($obj, $method);
		return $this;
	}
	public function getClientTermCb()
	{
		return $this->_termCb;
	}
}