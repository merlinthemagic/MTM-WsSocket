<?php
//� 2025 Martin Madsen
namespace MTM\WsSocket\Models\Client\V1;

abstract class Alpha extends \MTM\Utilities\Tools\Validations\V1
{	
	protected $_guid=null;
	protected $_buffData=null;
	protected $_isConn=false; //is connected
	protected $_isInit=false; //is initialized
	protected $_isTerm=false; //is terminated
	protected $_lastRx=0; //last time we got data
	protected $_lastTx=0; //last time we got data
	protected $_chunkSize=4096; //max amount of data to send at a time
	protected $_connTimeout=5000; //max time to connect
	protected $_readTime=1000; //how long we read the socket for by default
	protected $_writeTime=1000;//how long we write to the socket for by default
	protected $_writedelay=0;//how long we wait before write to the socket
	
	//clients must mask their data: https://tools.ietf.org/html/rfc6455#section-5.3
	protected $_useMasking=true;
	
	protected $_aSyncConnect=false;
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
	public function getWsSocket()
	{
		return $this->_wsSock;
	}
	public function setLastRxTime($epoch)
	{
		$this->_lastRx	= $epoch;
		return $this;
	}
	public function getLastRxTime()
	{
		return $this->_lastRx;
	}
	public function setLastTxTime($time)
	{
		$this->_lastTx	= $time;
		return $this;
	}
	public function getLastTxTime()
	{
		return $this->_lastTx;
	}
	public function setAsyncConnect($val)
	{
		$this->isBoolean($val, true);
		$this->_aSyncConnect	= $val;
		return $this;
	}
	public function getAsyncConnect()
	{
		return $this->_aSyncConnect;
	}
	public function setBuffer($val)
	{
		$this->_buffData	= $val;
		return $this;
	}
	public function appendBuffer($val)
	{
		$this->_buffData	.= $val;
		return $this;
	}
	public function getBuffer()
	{
		return $this->_buffData;
	}
	public function setChunkSize($val)
	{
		$this->isUsign32Int($val, true);
		$this->_chunkSize	= $val;
		return $this;
	}
	public function getChunkSize()
	{
		return $this->_chunkSize;
	}
	public function useMasking()
	{
		return $this->_useMasking;
	}
	public function setReadTime($val)
	{
		$this->isUsign32Int($val, true);
		$this->_readTime	= $val;
		return $this;
	}
	public function getReadTime()
	{
		return $this->_readTime;
	}
	public function setWriteTime($val)
	{
		$this->isUsign32Int($val, true);
		$this->_writeTime	= $val;
		return $this;
	}
	public function getWriteTime()
	{
		return $this->_writeTime;
	}
	public function setWriteDelay($val)
	{
		$this->isUsign32Int($val, true);
		$this->_writedelay	= $val;
		return $this;
	}
	public function getWriteDelay()
	{
		return $this->_writedelay;
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
			throw new \Exception("Cannot get meta data, client socket terminated", 1111);
		} else {
			return null;
		}
	}
	
	
	
	
	
	
	
	
	
	
	
	
	
	
	
	
	
	
	
	
	protected function readTool()
	{
		return \MTM\WsSocket\Factories::getTools()->getReadV1();
	}
	protected function writeTool()
	{
		return \MTM\WsSocket\Factories::getTools()->getWriteV1();
	}
}