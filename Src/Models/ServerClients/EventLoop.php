<?php
//© 2022 Martin Madsen
namespace MTM\WsSocket\Models\ServerClients;

class EventLoop extends \MTM\WsSocket\Models\ServerClient
{
	protected $_eventObj=null;
	
	public function connect()
	{
		if ($this->getIsConnected() === false) {
			if ($this->getTermStatus() === false) {
				$evTool	= \MTM\Utilities\Factories::getProcesses()->getEventLoop();
				if ($this->_eventObj === null) {
					$this->_eventObj	= $evTool->addEvent($this, "connectCb");
				}
				if ($this->_connectExpire === null) {
					$this->_connectEx		= null;
					$this->_connectExpire	= \MTM\Utilities\Factories::getTime()->getMicroEpoch() + ($this->getDefaultConnectTime() / 1000);

					try {
						
						//disable blocking so our reads can function in code logic
						stream_set_blocking($this->getSocket(), false);
						stream_set_chunk_size($this->getSocket(), $this->getChunkSize());

					} catch (\Exception $e) {
						$this->_eventObj		= null;
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
					$this->_eventObj->setNextRun(0);
					$evTool->runOnce();
				}
			
			} else {
				throw new \Exception("Cannot connect, socket terminated", 2951);
			}
		}
		return $this;
	}
	public function connectCb($evObj)
	{
		try {
			
			$cTime	= \MTM\Utilities\Factories::getTime()->getMicroEpoch();
			if ($this->_connectExpire > $cTime) {
				
				//cannnot use the read function, there seems to be a problem with reading all bytes
				//if the client sends a message immediately after the connect we cannot place it in the buffer for some reason
				//even though the sub_str function should be binary safe.
				
				//update: May 3rd. no fucking idea what you are talking abouk MM. I have reads that fail, but what did you find when you
				//determined there was a problem?... before you chose to brute force the issue. Quit making me (you) re-invent the wheel!
				while(true) {
					
					$rByte		= $this->getParent()->getParent()->rawRead($this, 1);
					if ($rByte != "") {
						$this->_connectBuffer	.= $rByte;
						if (strpos($this->_connectBuffer, "\r\n\r\n") !== false) {
							//headers must end in \r\n\r\n, we found the end of the header
							
							$secKey	= null;
							$lines	= array_filter(explode("\n", $this->_connectBuffer));
							foreach ($lines as $line) {
								if (preg_match("/^Sec-WebSocket-Key: (.*)$/i", $line, $match) == 1) {
									//make sure the \r is also removed....
									$secKey	= trim($match[1]);
								}
							}
							
							if ($secKey === null) {
								
								$errorWrite	= "HTTP/1.1 400 Bad Request\r\n\r\n";
								$this->getParent()->getParent()->rawWrite($this, $errorWrite);
								throw new \Exception("Missing Header: Sec-WebSocket-Key", 2952);
							
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
									$errorWrite	= "HTTP/1.1 500 Internal Error\r\n\r\n";
									$this->getParent()->getParent()->rawWrite($this, $errorWrite);
									throw new \Exception("Header write error", 2953);
								} else {
									$this->setLastReceivedTime($cTime);
									$this->setIsConnected(true);
									$this->_connectExpire	= null;
									$this->_connectBuffer	= null;
									$this->_connectEx		= null;
									$this->_eventObj->terminate();
									$this->_eventObj		= null;
									//success
									break;
								}
							}

						} elseif ($this->_connectBuffer == "IsTestConnect") {
							//throw so the server removes this client and stops spending time on it
							throw new \Exception("This is a test connect", 2954);
						}
						
					} else {
						//not completed yet
						break;
					}
				}
				
			} else {
				$errorWrite	= "HTTP/1.1 400 Bad Request\r\n\r\n";
				$this->getParent()->getParent()->rawWrite($this, $errorWrite);
				throw new \Exception("Failed to connect. Client Timeout", 2955);
			}
			
		} catch (\Exception $e) {
			$this->_connectExpire	= null;
			$this->_connectBuffer	= null;
			$this->_connectEx		= $e;
			$this->_eventObj->terminate();
			$this->_eventObj		= null;
		}
	}
}