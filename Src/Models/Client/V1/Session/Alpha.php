<?php
//� 2025 Martin Madsen
namespace MTM\WsSocket\Models\Client\V1\Session;

abstract class Alpha extends \MTM\WsSocket\Models\Client\V1\Zulu
{
	protected $_pGuid=null; //server guid (parent)
	protected $_srcIp=null; //source IP of the client
	protected $_srcPort=null; //source Port of the client

	public function __construct($sockRes, $pGuid)
	{
		parent::__construct();
		$this->_wsSock		= $sockRes;
		$this->_pGuid		= $pGuid;
	}
	public function getServer()
	{
		return \MTM\WsSocket\Factories::getServers()->getByGuid($this->_pGuid, true);
	}
	
	public function isConnected()
	{
		if (
			$this->_isConn === true
			&& $this->_isTerm === false
		) {
			$metaObj	= $this->getMetaInfo(false);
			if (
				$metaObj === null
				|| $metaObj->eof === true
			) {
				//socket has been terminated by the remote end going away
				$this->_isConn	= false;
				$this->terminate();
			}
		}
		return $this->_isConn;
	}
	public function getMetaInfo($throw=true)
	{
		//$metaData->unread_bytes, this is bytes not read since last read.
		//it cannot be used to determine if there is data pending
		$sockRes	= $this->getWsSocket();
		if (is_resource($sockRes) === true) {
			$rData	= stream_get_meta_data($sockRes);
			$hObj	= new \stdClass();
			foreach ($rData as $key => $val) {
				if (is_array($val) === false) {
					$hObj->$key	= $val;
				} else {
					$hObj->$key	= new \stdClass();
					foreach ($val as $sKey => $sVal) {
						$hObj->$key->$sKey	= $sVal;
					}
				}
			}
			return $hObj;
			
		} elseif ($throw === true) {
			throw new \Exception("Cannot get meta data, server client socket terminated", 2950);
		} else {
			return null;
		}
	}
	public function setSourceIp($val)
	{
		$this->isStrMax($val, 39, true);
		$this->_srcIp	= $val;
		return $this;
	}
	public function getSourceIp()
	{
		return $this->_srcIp;
	}
	public function setSourcePort($val)
	{
		$this->isUsign32Int($val, true);
		$this->_srcPort	= $val;
		return $this;
	}
	public function getSourcePort()
	{
		return $this->_srcPort;
	}
	
}