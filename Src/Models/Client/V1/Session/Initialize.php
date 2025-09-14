<?php
//� 2025 Martin Madsen
namespace MTM\WsSocket\Models\Client\V1\Session;

abstract class Initialize extends Alpha
{
	protected $_connExpire=null; //terminate connection attempt at this time
	
	public function connect()
	{
		try {
			
			if ($this->isConnected() === false) {
				
				if ($this->isTerm() === true) {
					throw new \Exception("Cannot connect, socket terminated", 2951);
				}
					
				$tFact		= \MTM\Utilities\Factories::getTime();
				if ($this->isInit() === false) {
					$this->_connExpire	= $tFact->getMicroEpoch() + ($this->_connTimeout / 1000);
					//disable blocking
					stream_set_blocking($this->getWsSocket(), false);
					stream_set_chunk_size($this->getWsSocket(), $this->getChunkSize());
					$this->_isInit		= true;
				}

				//cannnot use the read function, there seems to be a problem with reading all bytes
				//if the client sends a message immediately after the connect we cannot place it in the buffer for some reason
				//even though the sub_str function should be binary safe.
				
				//update: May 3rd. no fucking idea what you are talking abouk MM. I have reads that fail, but what did you find when you
				//determined there was a problem?... before you chose to brute force the issue. Quit making me (you) re-invent the wheel!
				
				//back here on September 10th 2025, still no idea what you are on about, but got a laugh out of reading the above
				while($this->isConnected() === false) {
					
					$cTime	= $tFact->getMicroEpoch();
					if ($this->_connExpire > $cTime) {
						
						$reObj		= $this->readTool()->raw($this, 1);
						if ($reObj->data != "") {
							$this->appendBuffer($reObj->data);
							if (strpos($this->getBuffer(), "\r\n\r\n") !== false) {
								//headers must end in \r\n\r\n, we found the end of the header
								
								$secKey	= null;
								$lines	= array_filter(explode("\n", $this->getBuffer()));
								foreach ($lines as $line) {
									if (preg_match("/^Sec-WebSocket-Key: (.*)$/i", $line, $match) == 1) {
										//make sure the \r is also removed....
										$secKey	= trim($match[1]);
									}
								}
								
								if ($secKey === null) {
									
									$errorWrite	= "HTTP/1.1 400 Bad Request\r\n\r\n";
									$this->writeTool()->raw($this, $errorWrite);
									throw new \Exception("Missing Header: Sec-WebSocket-Key", 44588);
									
								} else {
									
									$strRfc6455	= "258EAFA5-E914-47DA-95CA-C5AB0DC85B11";
									$oSecKey	= base64_encode(hash("sha1", $secKey . $strRfc6455, true));
									
									$heads = array(
											"Upgrade"               => "websocket",
											"Connection"            => "Upgrade",
											"Sec-WebSocket-Accept"  => $oSecKey,
											"WebSocket-Server"  	=> "MTM-WsServer",
									);
									
									//turn into a string we can send back to the client
									$strHeader	= "HTTP/1.1 101 Switching Protocols";
									foreach ($heads as $key => $head) {
										$strHeader	.= "\r\n" . $key . ": " . $head;
									}
									$strHeader	.= "\r\n\r\n";
									
									//send the return
									$wData		= $this->writeTool()->raw($this, $strHeader);
									if ($wData === false) {
										$errorWrite	= "HTTP/1.1 500 Internal Error\r\n\r\n";
										$this->writeTool()->raw($this, $errorWrite);
										throw new \Exception("Header write error", 2953);
									} else {
										$this->setLastRxTime($cTime);
										$this->_isConn			= true;
										$this->setBuffer(null);
										//success
										if ($this->getConnectCb() !== null) {
											//throw if you do not want to allow this client
											call_user_func_array($this->getConnectCb(), array($this));
										}
										break;
									}
								}
								
							} elseif ($this->getBuffer() == "IsTestConnect") {
								//throw so the server removes this client and stops spending time on it
								throw new \Exception("This is a test connect", 2954);
							}
							
						} elseif ($this->getAsyncConnect() === true) {
							//not completed yet
							break;
						}
						
					} else {
						$errorWrite	= "HTTP/1.1 408 Request Timeout\r\n\r\n";
						$this->writeTool()->raw($this, $errorWrite);
						$this->terminate();
						throw new \Exception("Failed to connect. Client Timeout", 2955);
					}
				}
			}
			
		} catch (\Exception $e) {
			$this->terminate();
			switch ($e->getCode()) {
				case 44588: //missing Sec-WebSocket-Key header
					break;
				default:
					throw $e;
			}
		}
	}
}