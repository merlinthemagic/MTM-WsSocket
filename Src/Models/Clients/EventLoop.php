<?php
//© 2022 Martin Madsen
namespace MTM\WsSocket\Models\Clients;

class EventLoop extends \MTM\WsSocket\Models\Client
{
	protected $_eventObj=null;
	
	public function connect()
	{
		if ($this->getIsConnected() === false) {
			
			$evTool	= \MTM\Utilities\Factories::getProcesses()->getEventLoop();
			
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
						throw new \Exception("Connect failed. Error: " . $wData["error"], 86126);
					}
					
					
					if ($this->_eventObj === null) {
						$this->_eventObj	= $evTool->addEvent($this, "connectCb");
					}
					
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
				} elseif ($this->_eventObj === null) {
					throw new \Exception("Event was removed", 86128);
				}
				$this->_eventObj->setNextRun(5);
				$evTool->runOnce();
			}
		}
		return $this;
	}
	public function connectCb($evObj)
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
								throw new \Exception("Failed to connect to: ".$this->getHostname().":".$this->getPort().". Server returned invalid upgrade response", 86127);
							} else {
								//success
								$this->setLastReceivedTime($cTime);
								$this->setIsConnected(true);
								$this->_connectExpire	= null;
								$this->_connectBuffer	= null;
								$this->_connectEx		= null;
								$this->_eventObj->terminate();
								$this->_eventObj		= null;
								break;
							}
						}
					} else {
						//not completed yet
						break;
					}
				}
				
			} else {
				throw new \Exception("Failed to connect to: ".$this->getHostname().":".$this->getPort().". The server failed to respond in time", 86128);
			}
			
		} catch (\Exception $e) {
			$this->_eventObj->terminate();
			$this->_eventObj		= null;
			$this->_connectExpire	= null;
			$this->_connectBuffer	= null;
			$this->_connectEx		= $e;
		}
	}
}