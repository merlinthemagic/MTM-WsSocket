<?php
//� 2025 Martin Madsen
namespace MTM\WsSocket\Models\Client\V1\Remote;

abstract class Alpha extends \MTM\WsSocket\Models\Client\V1\Zulu
{
	protected $_certObj=null;
	protected $_host=null;
	protected $_port=443;
	protected $_proto="tls";
	
	protected $_sockKey=null;
	protected $_headers=array();
	protected $_uri="/";
	
	public function __construct()
	{
		parent::__construct();
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
	public function getSocketKey()
	{
		if ($this->_sockKey === null) {
			
			$rKey	= "";
			$aChars = "abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ";
			$cLen	= strlen($aChars) - 1;
			for ($x=0; $x < 16; $x++) {
				$rKey	.= $aChars[rand(0, $cLen)];
			}
			$this->_sockKey	= base64_encode($rKey);
		}
		
		return $this->_sockKey;
	}
	public function getHeaders()
	{
		return $this->_headers;
	}
	public function setHeaders($heads)
	{
		//i.e. to set basic authentication
		//$heads	= array("Basic" => base64_encode($user . ":" . $pass));
		$heads		= array();
		foreach ($heads as $name => $value) {
			$this->_headers[$name]	= $value;
		}
		return $this;
	}
	public function getUri()
	{
		return $this->_uri;
	}
}