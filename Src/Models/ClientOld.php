<?php
//© 2019 Martin Madsen
namespace MTM\WsSocket\Models;

class Client
{
	protected $_uuid=null;
	protected $_socket=null;
	protected $_protocol=null;
	protected $_hostname=null;
	protected $_portNbr=null;
	protected $_timeout=null;
	protected $_uriPath="/";
	protected $_chunkSize=4096;
	protected $_headers=array();
	protected $_socketKey=null;
	
	//clients must mask their data: https://tools.ietf.org/html/rfc6455#section-5.3
	protected $_useMasking=true;
	protected $_lastReceiveTime=null;
	protected $_lastWriteTime=null;
	protected $_minWriteDelay=null;
	protected $_msgs=array();
	
	protected $_connectExpire=null;
	protected $_connectBuffer=null;
	protected $_connectEx=null;
	protected $_isConnected=false;
	protected $_termStatus=false;
	
	protected $_defaultReadTime=30000;
	protected $_defaultWriteTime=30000;
	protected $_protocols=array("tcp", "ssl", "tls");
	
	//buffered data stored from checks that require us to read the socket
	protected $_buffData=null;
	
	//ssl / tls config
	protected $_sslCertObj=null;
	protected $_sslAllowSelfSigned=false;
	protected $_sslVerifyPeer=true;
	protected $_sslVerifyPeerName=true;
	protected $_parent=null;
	
	//will be triggered when client has terminated
	protected $_termCbs=array();
	
	public function __destruct()
	{
		$this->terminate(false);
	}
	public function setConnection($protocol, $hostname, $portNbr, $uriPath=null, $timeout=30)
	{
		$protocol	= strtolower(trim($protocol));
		if (in_array($protocol, $this->_protocols) === false) {
			throw new \Exception("Invalid Protocol: " . $protocol);
		}
		
		$this->_protocol	= $protocol;
		$this->_hostname	= $hostname;
		$this->_portNbr		= $portNbr;
		$this->_timeout		= $timeout;
		
		if ($uriPath !== null) {
			if (substr($uriPath, 0, 1) != "/") {
				//paths must lead with a forward slash
				$uriPath	= "/" . $uriPath;
			}
			$this->_uriPath		= $uriPath;
		}
		
		return $this;
	}
	public function setSslConnection($certObj=null, $verifyPeer=true, $verifyPeerName=true, $allowSelfSigned=false)
	{
		if ($certObj !== null && $certObj instanceof \MTM\Certs\Models\CRT === false) {
			//should be a certificate object containing enough of the chain to confirm the server authenticity
			throw new \Exception("Invalid Certificate");
		} else {
			$this->_sslCertObj			= $certObj;
			$this->_sslVerifyPeer		= $verifyPeer;
			$this->_sslVerifyPeerName	= $verifyPeerName;
			$this->_sslAllowSelfSigned	= $allowSelfSigned;
		}
		return $this;
	}
	public function terminate($throw=true)
	{
		if ($this->getTermStatus() === false) {
			$this->_termStatus	= null;
			$errObj				= null;
			if ($this->getIsConnected() === true) {
				if (is_resource($this->_socket) === true) {
					//we are initiating the shutdown, send a message to the other side
					try {
						
						//default return is "all is good" message (1000). Termination Codes Src: https://tools.ietf.org/html/rfc6455#section-7.4.1
						$termCode	= 1000;
						$termMsg	= "GoodByeServer";
						$msg		= "";
						$termbin	= sprintf("%016b", $termCode);
						$binBytes	= str_split($termbin, 8);
						foreach ($binBytes as $binByte) {
							$msg .= chr(bindec($binByte));
						}
						$msg .= $termMsg;
						
						$this->sendMessage($msg, "close");
						
						//we are expecting the server to ack the close and return our message
						//was tested on gdax
						$this->getMessages(1000);
						
					} catch (\Exception $e) {
						$errObj	= $e;
						$e		= null;
					}
				} else {
					$errObj	= new \Exception("Unable to send goodbye, socket already closed");
				}
				//clean up so the socket can be used again
				if (is_resource($this->_socket) === true) {
					fclose($this->_socket);
				}
			}
			
			$this->_socket			= null;
			$this->_isConnected		= false;
			$this->_termStatus		= true;
			$this->_buffData		= null;
			$this->_lastReceiveTime	= null;
			
			foreach($this->_termCbs as $cb) {
				try {
					call_user_func_array($cb, array($this));
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
			$this->_termCbs[]	= array($obj, $method);
		}
		return $this;
	}
	public function getMessages($timeout=-1)
	{
		$msgs			= $this->getParent()->getMessages($this, $timeout);
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
		$this->getParent()->sendMessage($this, $msg, $dataType);
		return $this;
	}
	public function ping($msg)
	{
		$this->getParent()->sendMessage($this, $msg, "ping");
		return $this;
	}
	public function sendWait($msg, $dataType="text", $msTimeout=null)
	{
		//send a message and only return when the server replies
		//ONLY the first response message is returned
		//better implement a message protocol to give you the response
		//this breaks async
		if ($msTimeout === null) {
			$msTimeout	= $this->getDefaultExecutionTime();
		}
		
		$this->sendMessage($msg, $dataType);
		
		$tTime	= time() + ($msTimeout / 1000);
		while (true) {
			$msg	= $this->getMessage();
			if ($msg !== null) {
				return $msg;
			} elseif ($tTime < time()) {
				throw new \Exception("Server timed out responding");
			} else {
				usleep(10000);
			}
		}
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
	public function getSslCertificate()
	{
		return $this->_sslCertObj;
	}
	public function getSslAllowSelfSigned()
	{
		return $this->_sslAllowSelfSigned;
	}
	public function getSslVerifyPeer()
	{
		return $this->_sslVerifyPeer;
	}
	public function getSslVerifyPeerName()
	{
		return $this->_sslVerifyPeerName;
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
	public function getUriPath()
	{
		return $this->_uriPath;
	}
	public function getProtocol()
	{
		return $this->_protocol;
	}
	public function getHostname()
	{
		return $this->_hostname;
	}
	public function getPort()
	{
		return $this->_portNbr;
	}
	public function getTimeout()
	{
		return $this->_timeout;
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
	public function getIsEmpty()
	{
		return $this->getParent()->getIsEmpty($this);
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
			throw new \Exception("Cannot get meta data, client socket terminated");
		} else {
			return null;
		}
	}
	public function connect()
	{
		if ($this->getIsConnected() === false) {
			
			$cbTool	= \MTM\Utilities\Factories::getCallBacks()->getProcess();
			
			if ($this->_connectExpire === null) {
				$this->_connectEx		= null;
				$this->_connectExpire	= \MTM\Utilities\Factories::getTime()->getMicroEpoch() + $this->getTimeout();
				
				try {
					
					//disable blocking so our reads can function in code logic without blocking
					stream_set_blocking($this->getSocket(), false);
					stream_set_chunk_size($this->getSocket(), $this->getChunkSize());
					
					//default headers, version 13 means RFC-6455 compliant
					$heads = array(
							"Host"                  => $this->getHostname() . ":" . $this->getPort(),
							"User-Agent"            => "Merlin-Ws-Client",
							"Connection"            => "Upgrade",
							"Upgrade"               => "websocket",
							"Sec-WebSocket-Key"     => $this->getSocketKey(),
							"Sec-WebSocket-Version" => 13,
					);
					
					//merge in custom headers
					$heads		= array_merge($heads, $this->getHeaders());
					
					//turn into a string we can send
					$strHeader	= "GET " . $this->getUriPath() . " HTTP/1.1";
					foreach ($heads as $key => $head) {
						$strHeader	.= "\r\n" . $key . ": " . $head;
					}
					$strHeader	.= "\r\n\r\n";
					
					//open the socket and send the header data, go directly to the raw writer function since we are sending text not binary
					$wData	= $this->getParent()->write($this, $strHeader);
					if (strlen($wData["error"]) > 0) {
						throw new \Exception("Connect failed. Error: " . $wData["error"]);
					}
					
					$cbTool->addLoopCb($this, "connectCb");
					
				} catch (\Exception $e) {
					$this->_connectExpire	= null;
					throw $e;
				}
			}
			
			//this can build up, but we have to halt execution on the thread
			while(true) {
				if ($this->getIsConnected() === true) {
					break;
				} elseif ($this->_connectEx !== null) {
					throw $this->_connectEx;
				}
				$cbTool->runOnce();
			}
		}
		return $this;
	}
	public function connectCb()
	{
		try {
			
			$cTime	= \MTM\Utilities\Factories::getTime()->getMicroEpoch();
			if ($this->_connectExpire > $cTime) {
				
				while(true) {
					
					//cannnot use the read function, there seems to be a problem with reading all bytes
					//if the server sends a message immediately after the connect we cannot place it in the buffer for some reason
					//even though the sub_str function should be binary safe.
					$rByte		= $this->getParent()->rawRead($this, 1);
					if ($rByte != "") {
						$this->_connectBuffer	.= $rByte;
						if (strpos($this->_connectBuffer, "\r\n\r\n") !== false) {
							//headers must end in \r\n\r\n, we found the end of the header
							
							//expected return sec key
							$strRfc6455	= "258EAFA5-E914-47DA-95CA-C5AB0DC85B11";
							$eSecKey	= base64_encode(pack("H*", sha1($this->getSocketKey() . $strRfc6455)));
							$rSecKey	= null;
							$lines		= explode("\n", $this->_connectBuffer);
							foreach ($lines as $line) {
								$line	= trim($line);
								if (preg_match("/Sec-WebSocket-Accept:\s(.*)$/i", $line, $lParts) == 1) {
									$rSecKey	= trim($lParts[1]);
									break;
								}
							}
							if ($eSecKey != $rSecKey) {
								throw new \Exception("Failed to connect to: ".$this->getHostname().":".$this->getPort().". Server returned invalid upgrade response");
							} else {
								\MTM\Utilities\Factories::getCallBacks()->getProcess()->removeLoopCb($this, "connectCb");
								$this->setLastReceivedTime($cTime);
								$this->setIsConnected(true);
								$this->_connectExpire	= null;
								$this->_connectBuffer	= null;
								$this->_connectEx		= null;
								//success
								
								break;
							}
						}
					} else {
						//not completed yet
						break;
					}
				}
				
			} else {
				throw new \Exception("Failed to connect to: ".$this->getHostname().":".$this->getPort().". The server failed to respond in time");
			}
			
		} catch (\Exception $e) {
			\MTM\Utilities\Factories::getCallBacks()->getProcess()->removeLoopCb($this, "connectCb");
			$this->_connectExpire	= null;
			$this->_connectBuffer	= null;
			$this->_connectEx		= $e;
		}
	}
	public function getSocket()
	{
		if ($this->_socket === null) {
			
			if ($this->getTermStatus() === false) {
				
				if ($this->getProtocol() === null || $this->getHostname() === null || $this->getPort() === null || $this->getTimeout() === null) {
					throw new \Exception("Missing connection parameters");
				}
				
				$strConn	= $this->getProtocol() . "://" . $this->getHostname() . ":" . $this->getPort() . "" . $this->getUriPath();
				if ($this->getProtocol() == "ssl" || $this->getProtocol() ==  "tls") {
					
					//PEM formatted cert
					$ssl		= stream_context_create();
					if (is_object($this->getSslCertificate()) === true) {
						$fileObj	= \MTM\FS\Factories::getFiles()->getTempFile("pem")->setContent($this->getSslCertificate()->getChainAsString());
						stream_context_set_option($ssl, "ssl", "cafile", $fileObj->getPathAsString());
					}
					
					stream_context_set_option($ssl, "ssl", "allow_self_signed", $this->getSslAllowSelfSigned());
					stream_context_set_option($ssl, "ssl", "verify_peer", $this->getSslVerifyPeer());
					stream_context_set_option($ssl, "ssl", "verify_peer_name", $this->getSslVerifyPeerName());
					
					//remove @ if you are debugging TLS issues
					//client cannot use ip with tls as the certificate hostname cannot be verified (if not part of CN)
					$sockRes 	= @stream_socket_client($strConn, $errno, $errstr, $this->getTimeout(), STREAM_CLIENT_CONNECT, $ssl);
					
				} else {
					$sockRes 	= @stream_socket_client($strConn, $errno, $errstr, $this->getTimeout(), STREAM_CLIENT_CONNECT);
				}
				
				if (is_resource($sockRes) === true) {
					$this->_socket	= $sockRes;
				} else {
					//if you get error: Address already in use, know that if the port was in use by another socket
					//that is now shutdown, it will take a few seconds before the port is available again
					//but it will be freed up eventually
					if ($errstr == "" && $errno == "") {
						$lastErr	= error_get_last();
						if ($lastErr !== null) {
							$errstr		= $lastErr["message"];
						}
					}
					throw new \Exception("Connection to: ".$this->getHostname().":".$this->getPort().", Socket Error: '".$errstr."', '".$errno."'");
				}
				
			} else {
				throw new \Exception("Socket is terminated");
			}
		}
		return $this->_socket;
	}
	protected function getSocketKey()
	{
		if ($this->_socketKey === null) {
			
			$rKey	= "";
			$aChars = "abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ";
			$cLen	= strlen($aChars) - 1;
			for ($x=0; $x < 16; $x++) {
				$rKey	.= $aChars[rand(0, $cLen)];
			}
			$this->_socketKey	= base64_encode($rKey);
		}
		
		return $this->_socketKey;
	}
}