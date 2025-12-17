<?php
//� 2025 Martin Madsen
namespace MTM\WsSocket\Tools\V1\Read;

abstract class Socket extends Raw
{
	public function socket($wsCli, $timeout=0)
	{
		//$maxWaitMs is per read loop, we do not know how large a message someone sends us
		
		//TODO: make default async loop if $maxWaitMs != 0, making it truly async
		//allow for current processing if desired, requires getMessages in this class accepts
		//loop config
		$tFact			= \MTM\Utilities\Factories::getTime();
		$tTime			= ($tFact->getMicroEpoch() + ($timeout / 1000));
		
		$rObj			= new \stdClass();
		$rObj->type		= null;
		$rObj->data		= null;
		
		$isDone			= false;
		
		
			
		//need to address what happens if the initial 2 bytes come through, but
		//we then run out of time. The problem arises because the socket will have a half read message
		//and every subsequent attempt at reading will fail since we are no longer in sync
		$reObj		= $this->read($wsCli, 2, $timeout);
		if ($isDone === false) {
			
			//initial read is the first 2 bytes
			$initData	= $reObj->data;
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
			$rObj->type			= $this->getDataTypeName(bindec($byte1Ps[1]));

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
				
				$rWait		= round(($tTime - $tFact->getMicroEpoch()) * 1000);
				$reObj		= $this->read($wsCli, $bCount, $rWait);

				$adData		= $reObj->data;
				$pRawLen	= strlen($adData);
				$pRaw		= "";
				for ($x=0; $x < $pRawLen; $x++) {
					$pRaw	.= sprintf("%08b", ord($adData[$x]));
				}
				$payloadLen	= bindec($pRaw);
				
			}
		}
		
		if ($isDone === false) {
			
			if ($useMask === true) {
				//if there is a mask get is from the next 4 bytes
				$rWait		= round(($tTime - $tFact->getMicroEpoch()) * 1000);
				$reObj		= $this->read($wsCli, 4, $rWait);
				$maskData	= $reObj->data;
				
			}
		}
		
		if ($isDone === false) {
			
			if ($payloadLen > 0) {
				$rWait		= round(($tTime - $tFact->getMicroEpoch()) * 1000);
				$reObj		= $this->read($wsCli, $payloadLen, $rWait);
				$pData		= $reObj->data;
				if ($useMask === true) {
					for ($x=0; $x < $payloadLen; $x++) {
						$rObj->data		.= ($pData[$x] ^ $maskData[$x % 4]);
					}
				} else {
					$rObj->data		= $pData;
				}
				
			}
		}
		
		if ($isDone === false) {
			if ($isLast === false) {
				//give each read loop a full maxWait, else a large message will end up cut off
				$rWait			= round(($tTime - $tFact->getMicroEpoch()) * 1000);
				$reObj			= $this->socket($wsCli, $timeout);
				$rObj->data		.= $reObj->data;
				if ($rObj->type != "continuation") {
					//last part was not a continuation, maybe a close?
					$dtName			= $rObj->type;
				}
			}
			$isDone				= true;
		}
		
		//handle special cases
		if ($rObj->type == "close") {
			
			if ($wsCli->isTerm() === false) {
				
				//other end is closing, src: https://tools.ietf.org/html/rfc6455#section-7.1.2
				$msgLen		= strlen($rObj->data);
				if ($msgLen > 1) {
					$termBin	= $rObj->data[0] . $rObj->data[1];
					$termStat	= bindec(sprintf("%08b%08b", ord($rObj->data[0]), ord($rObj->data[1])));
					$msg		= $termBin . "Close acknowledged: " . $termStat;
					
					try {
						$wsCli->sendMessage($msg, "close");
					} catch (\Exception $e) {
						switch ($e->getCode()) {
// 							case 4476:
// 								//the client cut the connection after close
// 								break;
// 							case 1886:
// 								//the client closed the socket already
// 								//our write timed out
// 								break;
							default:
								throw $e;
						}
					}
				}
			}
			
			//return the rest of the message regardless of who is closing
			$rObj->data		= substr($rObj->data, 2);
			
			//terminate
			$wsCli->terminate();
			
		} elseif ($rObj->type == "ping") {
			//other end is requesting a pong https://tools.ietf.org/html/rfc6455#section-5.5.3
			$wsCli->sendMessage($rObj->data, "pong");
		}
		return $rObj;
	}
}