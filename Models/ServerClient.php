<?php
//© 2019 Martin Madsen
namespace MTM\WsSocket\Models;

class ServerClient
{
	protected $_uuid=null;
	protected $_address=null;
	protected $_socket=null;
	protected $_isConnected=false;
	protected $_termStatus=false;
	protected $_useMasking=false;
	protected $_lastReceiveTime=null;
	protected $_lastWriteTime=null;
	protected $_minWriteDelay=null;
	
	protected $_chunkSize=4096;
	protected $_defaultConnectTime=2000;
	protected $_defaultReadTime=30000;
	protected $_defaultWriteTime=30000;

	//buffered data stored from checks that require us to read the socket
	protected $_buffData=null;
	protected $_parent=null;
	
	//will be triggered when client has terminated
	protected $_termCb=null;

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
			
			if ($this->_termCb !== null) {
				try {
					call_user_func_array($this->_termCb, array($this));
				} catch (\Exception $e) {
				}
			}
			if ($errObj !== null && $throw === true) {
				throw $errObj;
			}
		}
	}
	public function setTerminationCb($obj=null, $method=null)
	{
		if (is_object($obj) === true && is_string($method) === true) {
			$this->_termCb	= array($obj, $method);
		}
		return $this;
	}
	public function getMessages($timeout=-1)
	{
		return $this->getParent()->getParent()->getMessages($this, $timeout);
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
			$this->_uuid		= uniqid("wsServerClient", true);
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
			&& $this->getMetaInfo()->eof === true
		) {
			//socket has been terminated by the remote end going away
			$this->_isConnected	= false;
			$this->terminate(false);
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
	public function getMetaInfo()
	{
		//$metaData->unread_bytes, this is bytes not read since last read.
		//it cannot be used to determine if there is data pending
		$rData	= stream_get_meta_data($this->getSocket());
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
	}
	public function connect()
	{
		if ($this->getIsConnected() === false) {
			if ($this->getTermStatus() === false) {
				$this->setIsConnected(null);
				
				//disable blocking so our reads can function in code logic
				stream_set_blocking($this->getSocket(), false);
				stream_set_chunk_size($this->getSocket(), $this->getChunkSize());
				
				//cannnot use the read function, there seems to be a problem with reading all bytes
				//if the client sends a message immediately after the connect we cannot place it in the buffer for some reason
				//even though the sub_str function should be binary safe.
				
				//update: May 3rd. no fucking idea what you are talking abouk MM. I have reads that fail, but what did you find when you 
				//determined there was a problem?... before you chose to brute force the issue. Quit making me (you) re-invent the wheel!
				
				$error			= null;
				$errorWrite		= null;
				$tTime			= \MTM\Utilities\Factories::getTime()->getMicroEpoch() + ($this->getDefaultConnectTime() / 1000);
				$hData			= "";
				$done			= false;
				while($done === false) {
					
					$rByte		= $this->getParent()->getParent()->rawRead($this, 1);
					if ($rByte != "") {
						$hData	.= $rByte;
						if (strpos($hData, "\r\n\r\n") !== false) {
							//headers must end in \r\n\r\n, we found the end of the header
							$done	= true;
						} elseif ($hData == "IsTestConnect") {
							//throw so the server removes this client and stops spending time on it
							//the real fix will be to make the connects async as well.
							throw new \Exception("This is a test connect");
						}
						
					} else {
						//no need to saturate the cpu
						usleep(10000);
					}
					
					if ($tTime <= \MTM\Utilities\Factories::getTime()->getMicroEpoch()) {
						$error		= "Failed to connect. Client Timeout";
						$errorWrite	= "HTTP/1.1 400 Bad Request\r\n\r\n";
						$done		= true;
					}
				}
				//need to implement support for:
				//Sec-WebSocket-Extensions: permessage-deflate
				//Sec-WebSocket-Protocol: someProtocol, anotherProtocol
				if ($error === null) {
					
					$secKey	= null;
					$lines	= array_filter(explode("\n", $hData));
					foreach ($lines as $line) {
						if (preg_match("/^Sec-WebSocket-Key: (.*)$/i", $line, $match) == 1) {
							//make sure the \r is also removed....
							$secKey	= trim($match[1]);
						}
					}
	
					if ($secKey === null) {
						
						$error		= "Missing Header: Sec-WebSocket-Key";
						$errorWrite	= "HTTP/1.1 400 Bad Request\r\n\r\n";
						
					} else {
						
						$strRfc6455	= "258EAFA5-E914-47DA-95CA-C5AB0DC85B11";
						$oSecKey	= base64_encode(sha1($secKey . $strRfc6455, true));
						
						$heads = array(
								"Upgrade"               => "websocket",
								"Connection"            => "Upgrade",
								"Sec-WebSocket-Accept"  => $oSecKey,
								"WebSocket-Server"  	=> "Merlin-Ws-Server",
						);
	
						//turn into a string we can send back to the client
						$strHeader	= "HTTP/1.1 101 Switching Protocols";
						foreach ($heads as $key => $head) {
							$strHeader	.= "\r\n" . $key . ": " . $head;
						}
						$strHeader	.= "\r\n\r\n";
						
						//send the return
						$wData		= $this->getParent()->getParent()->rawWrite($this, $strHeader);
						if ($wData === false) {
							$error		= "Header write error: " . $wData["error"];
							$errorWrite	= "HTTP/1.1 500 Internal Error\r\n\r\n";
						}
					}
				}
				if ($error === null) {
					$this->setIsConnected(true);
					$this->setLastReceivedTime(\MTM\Utilities\Factories::getTime()->getMicroEpoch());
				} else {
					if ($errorWrite != "") {
						//tell the client how they messed up
						$this->getParent()->getParent()->rawWrite($this, $errorWrite);
					}
					throw new \Exception("Connect failed. Error: " . $error);
				}
				
			} else {
				throw new \Exception("Cannot connect, socket terminated");
			}
		}
		return $this;
	}
}