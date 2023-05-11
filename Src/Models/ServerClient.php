<?php
//� 2019 Martin Madsen
namespace MTM\WsSocket\Models;

abstract class ServerClient
{
	protected $_uuid=null;
	protected $_address=null;
	protected $_socket=null;
	
	protected $_connectExpire=null;
	protected $_connectBuffer=null;
	protected $_connectEx=null;
	
	protected $_isConnected=false;
	protected $_termStatus=false;
	protected $_useMasking=false;
	protected $_lastReceiveTime=null;
	protected $_lastWriteTime=null;
	protected $_minWriteDelay=null;
	protected $_msgs=array();
	
	protected $_chunkSize=4096;
	protected $_defaultConnectTime=5000;
	protected $_defaultReadTime=30000;
	protected $_defaultWriteTime=30000;

	//buffered data stored from checks that require us to read the socket
	protected $_buffData=null;
	protected $_parent=null;
	
	//will be triggered when client has terminated
	protected $_termCbs=array();

	public function __destruct()
	{
		$this->terminate(false);
	}
	public function terminate($throw=true)
	{
		if ($this->getTermStatus() === false) {
			$this->_termStatus	= null;
			$errObj				= null;
			
			$pObj	= $this->getParent();
			if (is_object($pObj) === true) {

				//remove from parent
				$pObj->removeClient($this);

				if ($this->getIsConnected() === true && $pObj->isInit() === true && $pObj->isTerm() === false) {
					
					//we are initiating the shutdown, send a message to the other side
					//server is still live so we can send the message
				
					try {
						
						//default server is closing message. Termination Codes Src: https://tools.ietf.org/html/rfc6455#section-7.4.1
						$termCode	= 1001;
						$termMsg	= "GoodByeClient";
						$msg		= "";
						$termbin	= sprintf("%016b", $termCode);
						$binBytes	= str_split($termbin, 8);
						foreach ($binBytes as $binByte) {
							$msg	.= chr(bindec($binByte));
						}
						$msg	.= $termMsg;
						
						$pObj->getParent()->sendMessage($this, $msg, "close");
						
						//we are expecting the client to ack the close and return our message
						//was tested on firefox and Chrome
						//what? $this->getMessages(1000);
	
					} catch (\Exception $e) {
						$errObj	= $e;
						$e		= null;
					}
				}
			}
			if (is_resource($this->_socket) === true) {
				fclose($this->_socket);
			}

			$this->_socket			= null;
			$this->_isConnected		= false;
			$this->_termStatus		= true;
			
			foreach ($this->_termCbs as $cb) {
				try {
					call_user_func_array($cb, array($this));
				} catch (\Exception $e) {
				}
			}
			$this->_termCbs			= array();
			if ($errObj !== null && $throw === true) {
				throw $errObj;
			}
		}
	}
	public function setTerminationCb($obj=null, $method=null)
	{
		if (is_object($obj) === true && is_string($method) === true) {
			$this->_termCbs[]	= array($obj, $method);
		}
		return $this;
	}
	public function getMessages($timeout=-1)
	{
		$msgs			= $this->getParent()->getParent()->getMessages($this, $timeout);
		$msgs			= array_merge($this->_msgs, $msgs);
		$this->_msgs	= array();
		return $msgs;
	}
	public function getMessage($timeout=-1)
	{
		$this->_msgs	= $this->getMessages($timeout);
		return array_shift($this->_msgs);
	}
	public function sendMessage($msg, $dataType="text")
	{
		$this->getParent()->getParent()->sendMessage($this, $msg, $dataType);
		return $this;
	}
	public function ping($msg)
	{
		$this->getParent()->getParent()->sendMessage($this, $msg, "ping");
		return $this;
	}
	public function getUuid()
	{
		if ($this->_uuid === null) {
			$this->_uuid		= \MTM\Utilities\Factories::getGuids()->getV4()->get(false);
		}
		return $this->_uuid;
	}
	public function setParent($obj)
	{
		$this->_parent	= $obj;
		return $this;
	}
	public function getParent()
	{
		return $this->_parent;
	}
	public function setChunkSize($value)
	{
		$value	= intval($value);
		if ($this->_chunkSize != $value) {
			
			$this->_chunkSize	= $value;
			if ($this->_socket !== null) {
				stream_set_chunk_size($this->getSocket(), $this->_chunkSize);
			}
		}
		return $this;
	}
	public function getChunkSize()
	{
		//max amount of data to send at a time
		return $this->_chunkSize;
	}
	public function setDefaultConnectTime($mSecs)
	{
		$this->_defaultConnectTime	= intval($mSecs);
		return $this;
	}
	public function getDefaultConnectTime()
	{
		return $this->_defaultConnectTime;
	}
	public function setDefaultReadTime($mSecs)
	{
		$this->_defaultReadTime	= intval($mSecs);
		return $this;
	}
	public function getDefaultReadTime()
	{
		return $this->_defaultReadTime;
	}
	public function setDefaultWriteTime($mSecs)
	{
		$this->_defaultWriteTime	= intval($mSecs);
		return $this;
	}
	public function getDefaultWriteTime()
	{
		return $this->_defaultWriteTime;
	}
	public function getBuffer()
	{
		return $this->_buffData;
	}
	public function setBuffer($data)
	{
		$this->_buffData	= $data;
		return $this;
	}
	public function getMasking()
	{
		return $this->_useMasking;
	}
	public function setMasking($bool)
	{
		$this->_useMasking	= $bool;
		return $this;
	}
	public function getTermStatus()
	{
		return $this->_termStatus;
	}
	public function getIsConnected()
	{
		if (
			$this->_isConnected === true
			&& $this->_termStatus === false
		) {
			$metaObj	= $this->getMetaInfo(false);
			if (
				$metaObj === null
				|| $metaObj->eof === true
			) {
				//socket has been terminated by the remote end going away
				$this->_isConnected	= false;
				$this->terminate(false);
			}
		}
		return $this->_isConnected;
	}
	public function setIsConnected($bool)
	{
		$this->_isConnected	= $bool;
		return $this;
	}
	public function getLastReceivedTime()
	{
		return $this->_lastReceiveTime;
	}
	public function setLastReceivedTime($epoch)
	{
		$this->_lastReceiveTime	= $epoch;
		return $this;
	}
	public function getLastWriteTime()
	{
		return $this->_lastWriteTime;
	}
	public function setLastWriteTime($time)
	{
		$this->_lastWriteTime	= $time;
		return $this;
	}
	public function getMinWriteDelay()
	{
		return $this->_minWriteDelay;
	}
	public function setMinWriteDelay($miliSec)
	{
		$this->_minWriteDelay	= $miliSec;
		return $this;
	}
	public function getAddress()
	{
		return $this->_address;
	}
	public function getIp()
	{
		return substr($this->_address, 0, strrpos($this->_address, ":"));
	}
	public function getPort()
	{
		return substr($this->_address, (strrpos($this->_address, ":") + 1));
	}
	public function setAddress($ipPort)
	{
		$this->_address		= $ipPort;
		return $this;
	}
	public function getSocket()
	{
		return $this->_socket;
	}
	public function setSocket($sockRes)
	{
		$this->_socket		= $sockRes;
		return $this;
	}
	public function getIsEmpty()
	{
		return $this->getParent()->getParent()->getIsEmpty($this);
	}
	public function getMetaInfo($throw=true)
	{
		//$metaData->unread_bytes, this is bytes not read since last read.
		//it cannot be used to determine if there is data pending
		$sockRes	= $this->getSocket();
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
}