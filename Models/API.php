<?php
//© 2019 Martin Madsen
namespace MTM\WsSocket\Models;

class API
{
	protected $_dataCodes=array("continuation" => 0, "text" => 1, "binary" => 2, "close" => 8, "ping" => 9, "pong" => 10);
	protected $_cStore=array();

	public function testConnect($host, $port, $protocol="tcp", $msTimeout=1000)
	{
		$conn = @fsockopen($host, $port, $errno, $errstr, ($msTimeout / 1000));
		if (is_resource($conn) === false) {
			return false;
		} else {
			//this is a hack to make test calls disconnect faster
			//in reallity we no other client will do this, so we will need to make the serverClient connect async
			@fwrite($conn, "IsTestConnect");
			fclose($conn);
			return true;
		}
	}
	public function sendMessage($clientObj, $msg, $dataType)
	{
		if ($clientObj->getTermStatus() === true) {
			throw new \Exception("Cannot execute socket has been terminated", 800);
		} elseif ($clientObj->getIsConnected() === false) {
			$clientObj->connect();
		}
		
		$wData	= $this->socketWrite($clientObj, $msg, $dataType);
		if (strlen($wData["error"]) > 0) {
			throw new \Exception("Send Message Write Type: " . $dataType . ", Error: " . $wData["error"], $wData["code"]);
		}
		return $this;
	}
	public function getMessages($clientObj, $timeoutMs)
	{
		//TODO: make default async loop if $timeoutMs != -1, making it truly async
		//allow for current processing if desired, requires socketRead in this class accepts
		//loop config
		//return all messages
		if ($timeoutMs == -1) {
			//dont wait for messages
			$tTime	= \MTM\Utilities\Factories::getTime()->getMicroEpoch();
		} else {
			$tTime	= (\MTM\Utilities\Factories::getTime()->getMicroEpoch() + ($timeoutMs / 1000));
		}
		
		$msgs	= array();
		while (true) {
			
			$isEmpty	= $this->getIsEmpty($clientObj);
			$cTime		= \MTM\Utilities\Factories::getTime()->getMicroEpoch();
			
			if ($isEmpty === false) {
				$clientObj->setLastReceivedTime($cTime);
				$rData		= $this->socketRead($clientObj, $clientObj->getDefaultReadTime());
				if ($rData["dataType"] != "ping" && $rData["dataType"] != "pong") {
					$msgs[]		= $rData["data"];
				}

			} elseif ($cTime >= $tTime || count($msgs) > 0) {
				//done, we have emptied the message queue or run out of time
				break;
			} else {
				//no need to saturate the CPU
				usleep(10000);
			}
		}
		
		return $msgs;
	}
	public function rawRead($clientObj, $byteCount)
	{
		//we are not blocking so its a max bytes to read, does not mean you will get that much data back
		$sockRes	= $clientObj->getSocket();
		if (is_resource($sockRes) === false) {
			throw new \Exception("Cannot read, client socket is not a resource");
		}
		return @fread($sockRes, $byteCount);
	}
	public function read($clientObj, $byteCount, $maxWaitMs=0)
	{
		$maxWait			= $maxWaitMs / 1000;
		$return["error"]	= null;
		$return["data"]		= null;
		$return["sTime"]	= \MTM\Utilities\Factories::getTime()->getMicroEpoch();
		
		$done				= false;
		if ($clientObj->getBuffer() !== null) {
			//we have bytes pending in the buffer
			$buffData		= $clientObj->getBuffer();
			$buffLen		= strlen($buffData);
			if ($buffLen <= $byteCount || $byteCount == -1) {
				
				$return["data"]		= $buffData;
				$clientObj->setBuffer(null);
				if ($buffLen == $byteCount) {
					//exact match, we are done
					$done	= true;
				}
				
			} else {
				//too much data in the buffer for this request
				$return["data"]	= substr($buffData, 0, $byteCount);
				$clientObj->setBuffer(substr($buffData, $byteCount));
			}
		}
		
		try {
			
			while ($done === false) {
				
				//we are not blocking so its a max bytes to read, does not mean you will get that much data back
				$nData		= $this->rawRead($clientObj, 1);
				$exeTime	= \MTM\Utilities\Factories::getTime()->getMicroEpoch();
				
				if ($nData != "") {
					$return["data"]		.= $nData;
					
					if (strlen($return["data"]) == $byteCount) {
						$done	= true;
					}
					
				} elseif ($byteCount == -1 && $return["data"] !== null) {
					//no more data to read, and the byte count indicates we should read until there is no more
					$done	= true;
					
				} else {
					//wait for a tiny bit no need to saturate the CPU
					usleep(10000);
				}
				if ($done === false && ($exeTime - $return["sTime"]) > $maxWait) {
					//read timeout
					$return["error"]	= 'timeout';
					$done				= true;
				}
			}
			
		} catch (\Exception $e) {
			$return["error"]	= $e->getMessage();
		}
		
		$return["eTime"]	= \MTM\Utilities\Factories::getTime()->getMicroEpoch();
		
		return $return;
	}
	protected function socketRead($clientObj, $maxWaitMs=0)
	{
		//TODO: make default async loop if $maxWaitMs != 0, making it truly async
		//allow for current processing if desired, requires getMessages in this class accepts 
		//loop config
		$remainWait			= $maxWaitMs;
		$return				= array();
		$return["error"]	= null;
		$return["dataType"]	= null;
		$return["data"]		= null;
		$return["sTime"]	= \MTM\Utilities\Factories::getTime()->getMicroEpoch();
		
		$done				= false;
		
		try {
			
			//need to address what happens if the initial 2 bytes come through, but
			//we then run out of time. The problem arises because the socket will have a half read message
			//and every subsequent attempt at reading will fail since we are no longer in sync
			$rData	= $this->read($clientObj, 2, $remainWait);
			
			if (strlen($rData["error"]) > 0) {
				$return["error"]	= $rData["error"];
				$done				= true;
			}
			
			if ($done === false) {
				
				//initial read is the first 2 bytes
				$initData	= $rData["data"];
				$byte1		= decbin(ord($initData[0]));
				$byte2		= decbin(ord($initData[1]));
				$byte1Len	= strlen($byte1);
				$byte2Len	= strlen($byte2);
				//pad the strings if needed to get all 8 bits showing
				if ($byte1Len < 8) {
					$byte1	= str_repeat("0", (8 - $byte1Len)) . $byte1;
				}
				if ($byte2Len < 8) {
					$byte2	= str_repeat("0", (8 - $byte2Len)) . $byte2;
				}
				
				//split the first byte into 2
				$byte1Ps	= str_split($byte1, 4);
				if ($byte1Ps[0] == "0000") {
					$isLast	= false;
				} elseif ($byte1Ps[0] == "1000") {
					$isLast	= true;
				} else {
					//malformed
					throw new \Exception("Received broken header 4bit value: " . $byte1Ps[0]);
				}
				
				//next 4 bits are the data type
				$dtName				= $this->getDataTypeName(bindec($byte1Ps[1]));
				$return["dataType"]	= $dtName;
				//are we using a mask?
				$useMask	= true;
				if ($byte2[0] == "0") {
					$useMask	= false;
				}
				
				$payloadLen	= bindec(substr($byte2, 1));
				
				if ($payloadLen > 125) {
					//this is a large payload, need more bits to determine the length
					if ($payloadLen === 126) {
						//need an additional 2 bytes
						$bCount	= 2;
					} else {
						//need an additional 8 bytes
						$bCount	= 8;
					}
					
					$cTime			= \MTM\Utilities\Factories::getTime()->getMicroEpoch();
					$remainWait		= $maxWaitMs - round(($cTime - $return["sTime"]) * 1000);
					$raData			= $this->read($clientObj, $bCount, $remainWait);
					
					if (strlen($raData["error"]) > 0) {
						$return["error"]	= $raData["error"];
						$done				= true;
					} else {
						$adData		= $raData["data"];
						$pRawLen	= strlen($adData);
						$pRaw		= "";
						for ($x=0; $x < $pRawLen; $x++) {
							$pRaw	.= sprintf("%08b", ord($adData[$x]));
						}
						$payloadLen	= bindec($pRaw);
					}
				}
			}
			
			
			if ($done === false) {
				
				if ($useMask === true) {
					//if there is a mask get is from the next 4 bytes
					$cTime			= \MTM\Utilities\Factories::getTime()->getMicroEpoch();
					$remainWait		= $maxWaitMs - round(($cTime - $return["sTime"]) * 1000);
					$rmData			= $this->read($clientObj, 4, $remainWait);
					
					if (strlen($rmData["error"]) > 0) {
						$return["error"]	= $rmData["error"];
						$done				= true;
					} else {
						$maskData			= $rmData["data"];
					}
				}
			}
			
			if ($done === false) {
				
				if ($payloadLen > 0) {
					$cTime			= \MTM\Utilities\Factories::getTime()->getMicroEpoch();
					$remainWait		= $maxWaitMs - round(($cTime - $return["sTime"]) * 1000);
					$rpData			= $this->read($clientObj, $payloadLen, $remainWait);
					
					if (strlen($rpData["error"]) > 0) {
						$return["error"]	= $rpData["error"];
						$done				= true;
					} else {
						
						$pData		= $rpData["data"];
						if ($useMask === true) {
							
							for ($x=0; $x < $payloadLen; $x++) {
								$return["data"]	.= ($pData[$x] ^ $maskData[$x % 4]);
							}
							
						} else {
							$return["data"]		= $pData;
						}
					}
				}
			}

			if ($done === false) {
				$cTime			= \MTM\Utilities\Factories::getTime()->getMicroEpoch();
				$remainWait		= $maxWaitMs - round(($cTime - $return["sTime"]) * 1000);
				
				if ($isLast === false) {
					
					$exReturn	= $this->socketRead($clientObj, $remainWait);
					if (strlen($exReturn["error"]) > 0) {
						$return["error"]	= $exReturn["error"];
					} else {
						$return["data"]		.= $exReturn["data"];
						
						if ($exReturn["dataType"] != "continuation") {
							//last part was not a continuation, maybe a close?
							$dtName	= $exReturn["dataType"];
						}
					}	
				}
				
				$done				= true;
			}
			
		} catch (\Exception $e) {
			$return["error"]	= $e->getMessage();
		}
		
		//handle special cases
		if ($return["dataType"] == "close") {
			
			if ($clientObj->getTermStatus() === false) {
			
				//other end is closing, src: https://tools.ietf.org/html/rfc6455#section-7.1.2
				$msgLen	= strlen($return["data"]);
				if ($msgLen > 1) {
					$termBin	= $return["data"][0] . $return["data"][1];
					$termStat	= bindec(sprintf("%08b%08b", ord($rData["data"][0]), ord($rData["data"][1])));
					$msg		= $termBin . "Close acknowledged: " . $termStat;
					
					try {
						$this->sendMessage($clientObj, $msg, "close");
					} catch (\Exception $e) {
						switch ($e->getCode()) {
							case 4476:
								//the client cut the connection after close
								break;
							case 1886:
								//the client closed the socket already
								//our write timed out
								break;
							default:
								throw $e;
						}
					}
				}
				
				//tell the client that the connection is no longer open
				$clientObj->setIsConnected(false);
				
				if ($clientObj instanceof \MTM\WsSocket\Models\ServerClient) {
					$clientObj->getParent()->removeClient($clientObj);
				}
			}
			
			//return the rest of the message regardless of who is closing
			$return["data"]	= substr($return["data"], 2);

			//terminate
			$clientObj->terminate();
			
		} elseif ($return["dataType"] == "ping") {
			
			//other end is requesting a pong https://tools.ietf.org/html/rfc6455#section-5.5.3
			$clientObj->sendMessage($return["data"], "pong");
		}
		
		$return["eTime"]	= \MTM\Utilities\Factories::getTime()->getMicroEpoch();
		return $return;
	}
	public function rawWrite($clientObj, $data)
	{
		$sockRes	= $clientObj->getSocket();
		if (is_resource($sockRes) === false) {
			throw new \Exception("Cannot write, client socket is not a resource", 11988);
		}
		$wBytes	= @fwrite($sockRes, $data);
		if ($wBytes === false) {
			throw new \Exception("Failed to write to socket", 11989);
		}
		return $wBytes;
	}
	public function write($clientObj, $data, $maxWaitMs=0)
	{
		$maxWait			= $maxWaitMs / 1000;
		$return				= array();
		$return["error"]	= null;
		$return["code"]		= 0;
		$return["sTime"]	= \MTM\Utilities\Factories::getTime()->getMicroEpoch();
		
		try {
			
			$done		= false;
			$byteCount	= strlen($data);
			while ($done === false) {

				$wBytes		= $this->rawWrite($clientObj, $data);
				$exeTime	= \MTM\Utilities\Factories::getTime()->getMicroEpoch();
				
				if ($byteCount == $wBytes) {
					$done				= true;
				} elseif (($exeTime - $return["sTime"]) > $maxWait) {
					//write timeout
					throw new \Exception("Timeout", 1886);
				} elseif ($wBytes === 0) {
					//we have time for another attempt
					//socket might be out of buffer space
					//wait for a tiny bit no need to saturate the CPU
					usleep(10000);
				} else {
					//partial write
					throw new \Exception("Partial write: " . $wBytes . ", bytes of: " . $byteCount, 4476);
				}
			}

		} catch (\Exception $e) {
			$return["error"]	= $e->getMessage();
			$return["code"]		= $e->getCode();
		}
		
		$return["eTime"]	= \MTM\Utilities\Factories::getTime()->getMicroEpoch();
		
		return $return;
	}
	protected function socketWrite($clientObj, $data, $type)
	{
		$return				= array();
		$return["error"]	= null;
		$return["code"]		= 0;
		$return["sTime"]	= \MTM\Utilities\Factories::getTime()->getMicroEpoch();
		
		try {
			
			$curPos		= 0;
			$dataLen	= strlen($data);
			$done		= false;
			$dtCode		= $this->getDataTypeCode($type);
			
			while ($done === false) {
				
				$dataChunk	= substr($data, $curPos, $clientObj->getChunkSize());
				$chunckLen	= strlen($dataChunk);
				$curPos		+= $clientObj->getChunkSize();
				if ($curPos >= $dataLen) {
					$done	= true;
				}
				
				//is this the last chunk? (4 bits)
				if ($done === false) {
					$headBin	= "0000";
				} else {
					$headBin	= "1000";
				}
				
				//set type of data in the next 4 bits
				$headBin	.= sprintf("%04b", $dtCode);
				if ($type != "continuation") {
					//after the first chunk all other chunks are "continuation" types
					$type		= "continuation";
					$dtCode		= $this->getDataTypeCode($type);
				}
				
				//use mask? (1 bit)
				if ($clientObj->getMasking() === true) {
					$headBin	.= "1";
				} else {
					$headBin	.= "0";
				}
				
				//inform the payload length
				//MM change decbin() to binary strings for consistency, or change the above to use decbin()
				if ($chunckLen > 65535) {
					$headBin	.= decbin(127);
					$headBin	.= sprintf('%064b', $chunckLen);
				} elseif ($chunckLen > 125) {
					$headBin	.= decbin(126);
					$headBin	.= sprintf('%016b', $chunckLen);
				} else {
					$headBin	.= sprintf('%07b', $chunckLen);
				}
				
				$payloadBin	= "";
				
				// Write frame head to $payloadBin.
				$headBytes		= str_split($headBin, 8);
				foreach ($headBytes as $headByte) {
					$payloadBin	.= chr(bindec($headByte));
				}
				
				//Finally add payload:
				if ($clientObj->getMasking() === true) {
					//create a random mask
					$maskData	= chr(rand(0, 255)) . chr(rand(0, 255)) . chr(rand(0, 255)) . chr(rand(0, 255));
					$payloadBin	.= $maskData;
					for ($x=0; $x < $chunckLen; $x++) {
						$payloadBin	.= $dataChunk[$x] ^ $maskData[$x % 4];
					}
					
				} else {
					//no masking
					$payloadBin	.= $dataChunk;
				}
				
				
				//make sure we are not sending too fast
				$minWd	= $clientObj->getMinWriteDelay();
				if ($minWd > 0) {
					$cTime	= \MTM\Utilities\Factories::getTime()->getMicroEpoch();
					$dTime	= ceil((($clientObj->getLastWriteTime() + ($clientObj->getMinWriteDelay() / 1000)) - $cTime) * 1000000);
					if ($dTime > 0) {
						usleep($dTime);
					}
				}

				//finally send the darn thing
				$wData	= $this->write($clientObj, $payloadBin, $clientObj->getDefaultWriteTime());
				if (strlen($wData["error"]) > 0) {
					throw new \Exception("Write Error: " . $wData["error"], $wData["code"]);
				} else {
					$clientObj->setLastWriteTime(\MTM\Utilities\Factories::getTime()->getMicroEpoch());
				}
			}
			
		} catch (\Exception $e) {
			$return["error"]	= $e->getMessage();
			$return["code"]		= $e->getCode();
		}
		
		$return["eTime"]	= \MTM\Utilities\Factories::getTime()->getMicroEpoch();
		
		return $return;
	}
	public function getIsEmpty($clientObj)
	{
		if ($clientObj->getIsConnected() === true) {

			//feof($this->getSocket()) is useless. there is no EOF so it always returns true
			if ($clientObj->getBuffer() === null) {
				
				$nData		= $this->rawRead($clientObj, 1);
				if ($nData != "") {
					//store the extra data so the read function gets a
					$clientObj->setBuffer($nData);
					return false;
				}
				
			} else {
				//we have data pending in the buffer
				return false;
			}
		}
		
		return true;
	}
	protected function getDataTypeCode($type)
	{
		if (in_array($type, $this->_dataCodes) === true) {
			return $this->_dataCodes[$type];
		} else {
			throw new \Exception("Invalid Data type Name: " . $type);
		}
	}
	protected function getDataTypeName($code)
	{
		$type	= array_search($code, $this->_dataCodes);
		if ($type !== false) {
			return $type;
		} else {
			throw new \Exception("Invalid Datatype Code: " . $code);
		}
	}
}