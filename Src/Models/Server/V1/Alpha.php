<?php
//� 2025 Martin Madsen
namespace MTM\WsSocket\Models\Server\V1;

abstract class Alpha extends \MTM\Utilities\Tools\Validations\V1
{
	protected $_guid=null;
	protected $_isInit=false;
	protected $_isTerm=false;
	protected $_certObj=null;
	protected $_host=null;
	protected $_port=443;
	protected $_proto="tls";
	protected $_clientReadTime=1000;
	protected $_clientWriteTime=1000;
	protected $_clientWriteDelay=0;
	protected $_clientChunkSize=4096;
	protected $_clientConnectAsync=false;
	
	protected $_wsSock=null;
	
	public function __construct()
	{
		$this->_guid		= \MTM\Utilities\Factories::getGuids()->getV4()->get(false);
	}
	public function getGuid()
	{
		return $this->_guid;
	}
	public function isInit()
	{
		return $this->_isInit;
	}
	public function isTerm()
	{
		return $this->_isTerm;
	}
	public function setProtocol($val)
	{
		$this->isStr($val, false);
		if (in_array($val, array("tcp", "tls")) === false) {
			throw new \Exception("Invalid Protocol: " . $protocol, 42790);
		}
		$this->_proto	= $val;
		return $this;
	}
	public function getProtocol()
	{
		return $this->_proto;
	}
	public function setHost($val)
	{
		$this->isStrMax($val, 255, false);
		$this->_host	= trim($val);
		return $this;
	}
	public function getHost()
	{
		return $this->_host;
	}
	public function setPort($val)
	{
		$this->isUsign32Int($val, true);
		$this->_port	= $val;
		return $this;
	}
	public function getPort()
	{
		return $this->_port;
	}
	public function setCertificate($certObj)
	{
		if ($certObj instanceof \MTM\Certs\Models\CRT === false) {
			//should be a certificate object containing the chain back to the root CA
			throw new \Exception("Invalid Certificate", 42791);
		}
		$this->_certObj		= $certObj;
		return $this;
	}
	public function getCertificate()
	{
		return $this->_certObj;
	}
	public function getWsSocket()
	{
		return $this->_wsSock;
	}
	public function getClientDefaultWriteDelay()
	{
		return $this->_clientWriteDelay;
	}
	public function getClientDefaultWriteTime()
	{
		return $this->_clientWriteTime;
	}
	public function getClientDefaultReadTime()
	{
		return $this->_clientReadTime;
	}
	public function getClientDefaultChunkSize()
	{
		return $this->_clientChunkSize;
	}
	public function getClientConnectAsync()
	{
		return $this->_clientConnectAsync;
	}
}