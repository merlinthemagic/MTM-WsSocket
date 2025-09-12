<?php
//� 2025 Martin Madsen
namespace MTM\WsSocket\Tools\V1\Read;

abstract class Socket extends Raw
{
	public function socket($wsCli, $maxWaitMs=0)
	{
		//$maxWaitMs is per read loop, we do not know how large a message someone sends us
		
		//TODO: make default async loop if $maxWaitMs != 0, making it truly async
		//allow for current processing if desired, requires getMessages in this class accepts
		//loop config
		$tFact				= \MTM\Utilities\Factories::getTime();
		$remainWait			= $maxWaitMs;
		$return				= array();
		$return["error"]	= "";
		$return["dataType"]	= null;
		$return["data"]		= "";
		$return["sTime"]	= $tFact->getMicroEpoch();
		
		$done				= false;
		
		try {
			
			//need to address what happens if the initial 2 bytes come through, but
			//we then run out of time. The problem arises because the socket will have a half read message
			//and every subsequent attempt at reading will fail since we are no longer in sync
			$rData	= $this->read($wsCli, 2, $remainWait);
			
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
					throw new \Exception("Received broken header 4bit value: '".$byte1Ps[0]."'", 802);
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
					
					$cTime			= $tFact->getMicroEpoch();
					$remainWait		= $maxWaitMs - round(($cTime - $return["sTime"]) * 1000);
					$raData			= $this->read($wsCli, $bCount, $remainWait);
					
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
					$cTime			= $tFact->getMicroEpoch();
					$remainWait		= $maxWaitMs - round(($cTime - $return["sTime"]) * 1000);
					$rmData			= $this->read($wsCli, 4, $remainWait);
					
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
					$cTime			= $tFact->getMicroEpoch();
					$remainWait		= $maxWaitMs - round(($cTime - $return["sTime"]) * 1000);
					$rpData			= $this->read($wsCli, $payloadLen, $remainWait);
					
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
				$cTime			= $tFact->getMicroEpoch();
				$remainWait		= $maxWaitMs - round(($cTime - $return["sTime"]) * 1000);
				
				if ($isLast === false) {
					
					//give each read loop a full maxWait, else a large message will end up cut off
					$exReturn	= $this->socketRead($wsCli, $maxWaitMs);
					// 					$exReturn	= $this->socketRead($wsCli, $remainWait);
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
			
			if ($wsCli->getTermStatus() === false) {
				
				//other end is closing, src: https://tools.ietf.org/html/rfc6455#section-7.1.2
				$msgLen	= strlen($return["data"]);
				if ($msgLen > 1) {
					$termBin	= $return["data"][0] . $return["data"][1];
					$termStat	= bindec(sprintf("%08b%08b", ord($rData["data"][0]), ord($rData["data"][1])));
					$msg		= $termBin . "Close acknowledged: " . $termStat;
					
					try {
						$this->sendMessage($wsCli, $msg, "close");
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
				$wsCli->setIsConnected(false);
				
				if ($wsCli instanceof \MTM\WsSocket\Models\ServerClient) {
					$wsCli->getParent()->removeClient($wsCli);
				}
			}
			
			//return the rest of the message regardless of who is closing
			$return["data"]	= substr($return["data"], 2);
			
			//terminate
			$wsCli->terminate();
			
		} elseif ($return["dataType"] == "ping") {
			
			//other end is requesting a pong https://tools.ietf.org/html/rfc6455#section-5.5.3
			$wsCli->sendMessage($return["data"], "pong");
		}
		
		$return["eTime"]	= $tFact->getMicroEpoch();
		return $return;
	}
}