<?php
//� 2025 Martin Madsen
namespace MTM\WsSocket\Tools\V1\Read;

abstract class Buffer extends Alpha
{
	public function read($wsCli, $byteCount, $timeout=0)
	{
		$tFact			= \MTM\Utilities\Factories::getTime();
		$tTime			= ($tFact->getMicroEpoch() + ($timeout / 1000));
		$isDone			= false;
		
		$rObj			= new \stdClass();
		$rObj->data		= null;

		if ($wsCli->getBuffer() !== null) {
			//we have bytes pending in the buffer
			$buffData		= $wsCli->getBuffer();
			$buffLen		= strlen($buffData);
			if ($buffLen <= $byteCount || $byteCount == -1) {
				
				$rObj->data		= $buffData;
				$wsCli->setBuffer(null);
				if ($buffLen == $byteCount) {
					//exact match, we are done
					$isDone	= true;
				}
				
			} else {
				//too much data in the buffer for this request
				$rObj->data		= substr($buffData, 0, $byteCount);
				$wsCli->setBuffer(substr($buffData, $byteCount));
			}
		}
		while ($isDone === false) {
			
			//we are not blocking so its a max bytes to read, does not mean you will get that much data back
			$reObj		= $this->raw($wsCli, 1);
			if ($reObj->data != "") {
				$rObj->data		.= $reObj->data;
				if (strlen($rObj->data) == $byteCount) {
					$isDone	= true;
				}
			} elseif ($byteCount == -1 && $rObj->data !== null) {
				//no more data to read, and the byte count indicates we should read until there is no more
				$isDone	= true;
				
			} else {
				//wait for a tiny bit no need to saturate the CPU
				usleep(10000);
			}
			if ($isDone === false && ($tFact->getMicroEpoch() >= $tTime) ) {
				//read timeout
				throw new \Exception("Read Timeout", 1111);
			}
		}
		return $rObj;
	}
}