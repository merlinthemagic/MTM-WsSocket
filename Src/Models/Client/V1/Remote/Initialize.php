<?php
//� 2025 Martin Madsen
namespace MTM\WsSocket\Models\Client\V1\Remote;

abstract class Initialize extends Alpha
{
	protected $_connExpire=null; //terminate connection attempt at this time
	
	public function connect()
	{
		if ($this->isConnected() === false) {

			if ($this->isTerm() === true) {
				throw new \Exception("Cannot connect, socket terminated", 2951);
			}
			
			//if $async === true you have keep calling poll the connect() method until it returns self
			$tFact		= \MTM\Utilities\Factories::getTime();
			if ($this->isInit() === false) {
				
				if ($this->getProtocol() === null) {
					throw new \Exception("Missing protocol", 1111);
				} elseif ($this->getHost() === null) {
					throw new \Exception("Missing host", 1111);
				} elseif ($this->getPort() === null) {
					throw new \Exception("Missing port", 1111);
				} elseif ($this->getProtocol() === "tls") {
					if ($this->getCertificate() === null) {
						throw new \Exception("Missing certificate for TLS", 1111);
					}
				}

				$this->_connExpire	= $tFact->getMicroEpoch() + ($this->_connTimeout / 1000);
				$strConn			= $this->getProtocol() . "://". $this->getHost() .":" . $this->getPort() . "";
				if ($this->getProtocol() ==  "tls") {
					
					//PEM formatted cert
					$ssl		= stream_context_create();
					$fileObj	= \MTM\FS\Factories::getFiles()->getTempFile("pem")->setContent($this->getCertificate()->getChainAsString());
					stream_context_set_option($ssl, "ssl", "cafile", $fileObj->getPathAsString());
					
					stream_context_set_option($ssl, "ssl", "allow_self_signed", true);
// 					stream_context_set_option($ssl, "ssl", "verify_peer", true);
					stream_context_set_option($ssl, "ssl", "verify_peer", false); //debugging only
					stream_context_set_option($ssl, "ssl", "verify_peer_name", true);

					//client cannot use ip with tls as the certificate hostname cannot be verified (if not part of CN)
					set_error_handler(array($this, "connectError"));
					try {
						$sockRes 	= stream_socket_client($strConn, $errno, $errstr, ($this->_connTimeout / 1000), STREAM_CLIENT_CONNECT, $ssl);
						restore_error_handler();
					} catch (\Exception $e) {
						restore_error_handler();
						throw $e;
					}
					
				} else {
					
					set_error_handler(array($this, "connectError"));
					try {
						$sockRes 	= stream_socket_client($strConn, $errno, $errstr, ($this->_connTimeout / 1000), STREAM_CLIENT_CONNECT);
						restore_error_handler();
					} catch (\Exception $e) {
						restore_error_handler();
						throw $e;
					}
				}
				
				
				if (is_resource($sockRes) === false) {
					//if you get error: Address already in use, know that if the port was in use by another socket
					//that is now shutdown, it will take a few seconds before the port is available again
					//but it will be freed up eventually
					if ($errstr == "" && $errno == "") {
						$lastErr	= error_get_last();
						if ($lastErr !== null) {
							$errstr		= $lastErr["message"];
						}
					}

					throw new \Exception("Connection to: ".$this->getHost().":".$this->getPort().", Socket Error: '".$errstr."', '".$errno."'", 86124);
				}
				
				$this->_wsSock		= $sockRes;
				
				
				//disable blocking so our reads can function in code logic without blocking
				stream_set_blocking($sockRes, false);
				stream_set_chunk_size($sockRes, $this->getChunkSize());
				
				//default headers, version 13 means RFC-6455 compliant
				$heads = array(
						"Host"                  => $this->getHost() . ":" . $this->getPort(),
						"User-Agent"            => "Merlin-Ws-Client",
						"Connection"            => "Upgrade",
						"Upgrade"               => "websocket",
						"Sec-WebSocket-Key"     => $this->getSocketKey(),
						"Sec-WebSocket-Version" => 13,
				);
				
				//merge in custom headers
				$heads		= array_merge($heads, $this->getHeaders());
				
				//turn into a string we can send
				$strHeader	= "GET " . $this->getUri() . " HTTP/1.1";
				foreach ($heads as $key => $head) {
					$strHeader	.= "\r\n" . $key . ": " . $head;
				}
				$strHeader	.= "\r\n\r\n";
				
				//open the socket and send the header data, go directly to the raw writer function since we are sending text not binary
				$this->writeTool()->write($this, $strHeader);
				$this->_isInit		= true;
			}
	
			try {
				
				while($this->isConnected() === false) {

					$cTime	= $tFact->getMicroEpoch();
					if ($this->_connExpire > $cTime) {

						$rByte		= $this->readTool()->raw($this, 1);
						if ($rByte != "") {
							$this->appendBuffer($rByte);
							if (strpos($this->getBuffer(), "\r\n\r\n") !== false) {
								//headers must end in \r\n\r\n, we found the end of the header
								//expected return sec key
								$strRfc6455	= "258EAFA5-E914-47DA-95CA-C5AB0DC85B11";
								$eSecKey	= base64_encode(pack("H*", sha1($this->getSocketKey() . $strRfc6455)));
								$rSecKey	= null;
								$lines		= explode("\n", $this->getBuffer());
								foreach ($lines as $line) {
									$line	= trim($line);
									if (preg_match("/Sec-WebSocket-Accept:\s(.*)$/i", $line, $lParts) == 1) {
										$rSecKey	= trim($lParts[1]);
										break;
									}
								}
								if ($eSecKey != $rSecKey) {
									throw new \Exception("Failed to connect to: ".$this->getHost().":".$this->getPort().". Server returned invalid upgrade response", 86127);
								} else {
									//success
									$this->setLastRxTime($cTime);
									$this->_isConn		= true;
									$this->setBuffer(null);
									
									if ($this->getConnectCb() !== null) {
										
										try {
											call_user_func_array($this->getConnectCb(), array($this));
										} catch (\Exception $e) {
											$this->terminate();
											throw $e;
										}
									}
									break;
								}
							}
						} elseif ($this->getAsyncConnect() === true) {
							//not completed yet
							break;
						}
						
					} else {
						throw new \Exception("Failed to connect to: ".$this->getHost().":".$this->getPort().". The server failed to respond in time", 86128);
					}
				}
	
			} catch (\Exception $e) {
				throw $e;
			}
		}
	}
	public function connectError($errno, $errstr, $errfile, $errline)
	{
// 		if (strpos($errstr, "certificate verify failed") !== false) {
// 			throw new \Exception("Server certificate validation failed", 1111);
// 		} else {
			throw new \Exception($errstr, $errno);
// 		}
	}
}