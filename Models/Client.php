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
	
	protected $_isConnected=false;
	protected $_termStatus=false;
	protected $_defaultConnectTime=2000;
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
	protected $_termCb=null;
	
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
				
				//we are initiating the shutdown, send a message to the other side
				try {
					
					//default all is good message. Termination Codes Src: https://tools.ietf.org/html/rfc6455#section-7.4.1
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
			}
			
			//clean up so the socket can be used again
			if (is_resource($this->_socket) === true) {
				fclose($this->_socket);
			}
			
			$this->_socket			= null;
			$this->_isConnected		= false;
			$this->_termStatus		= true;
			$this->_buffData		= null;
			$this->_lastReceiveTime	= null;
			
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
		return $this->getParent()->getMessages($this, $timeout);
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
			$msgs	= $this->getMessages();
			if (count($msgs) > 0) {
				return reset($msgs);
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
			$this->_uuid		= uniqid("wsClient", true);
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
			$this->setIsConnected(null);
			
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

			//cannnot use the read function, there seems to be a problem with reading all bytes
			//if the server sends a message immediately after the connect we cannot place it in the buffer for some reason
			//even though the sub_str function should be binary safe.
			$error	= null;
			$tTime	= \MTM\Utilities\Factories::getTime()->getMicroEpoch() + ($this->getDefaultConnectTime() / 1000);
			$hData	= "";
			$done	= false;
			while($done === false) {
			
				$rByte		= $this->getParent()->rawRead($this, 1);
				if ($rByte != "") {
					$hData	.= $rByte;
					if (strpos($hData, "\r\n\r\n") !== false) {
						//headers must end in \r\n\r\n, we found the end of the header
						$done	= true;
					}
					
				} else {
					//no need to saturate the cpu 
					usleep(10000);
				}

				if ($tTime <= \MTM\Utilities\Factories::getTime()->getMicroEpoch()) {
					//could also be the server requires SSL/TLS
					$error		= "Failed to connect. Server Timeout";
					break;
				}
			}

			if ($error === null) {

				//expected return sec key
				$strRfc6455	= "258EAFA5-E914-47DA-95CA-C5AB0DC85B11";
				$eSecKey	= base64_encode(pack("H*", sha1($this->getSocketKey() . $strRfc6455)));
				$rSecKey	= null;
				$lines		= explode("\n", $hData);
				foreach ($lines as $line) {
					$line	= trim($line);
					if (preg_match("/Sec-WebSocket-Accept:\s(.*)$/i", $line, $lParts) == 1) {
						$rSecKey	= trim($lParts[1]);
						break;
					}
				}
	
				if ($eSecKey != $rSecKey) {
					throw new \Exception("Failed to connect. Server returned invalid upgrade response");
				} else {
					$this->setLastReceivedTime(\MTM\Utilities\Factories::getTime()->getMicroEpoch());
					$this->setIsConnected(true);
				}
				
			} else {
				throw new \Exception($error);
			}
		}
		return $this;
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
					throw new \Exception("Socket Msg: " . $errstr, "Socket Code: " . $errno);
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