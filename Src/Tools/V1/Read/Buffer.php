<?php
//� 2025 Martin Madsen
namespace MTM\WsSocket\Tools\V1\Read;

abstract class Buffer extends Alpha
{
	public function read($wsCli, $byteCount, $maxWaitMs=0)
	{
		$maxWait			= $maxWaitMs / 1000;
		$return["error"]	= "";
		$return["data"]		= "";
		$return["sTime"]	= \MTM\Utilities\Factories::getTime()->getMicroEpoch();
		
		$done				= false;
		if ($wsCli->getBuffer() !== null) {
			//we have bytes pending in the buffer
			$buffData		= $wsCli->getBuffer();
			$buffLen		= strlen($buffData);
			if ($buffLen <= $byteCount || $byteCount == -1) {
				
				$return["data"]		= $buffData;
				$wsCli->setBuffer(null);
				if ($buffLen == $byteCount) {
					//exact match, we are done
					$done	= true;
				}
				
			} else {
				//too much data in the buffer for this request
				$return["data"]	= substr($buffData, 0, $byteCount);
				$wsCli->setBuffer(substr($buffData, $byteCount));
			}
		}
		
		try {
			
			while ($done === false) {
				
				//we are not blocking so its a max bytes to read, does not mean you will get that much data back
				$nData		= $this->raw($wsCli, 1);
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
					$return["error"]	= "timeout";
					$done				= true;
				}
			}
			
		} catch (\Exception $e) {
			$return["error"]	= $e->getMessage();
		}
		
		$return["eTime"]	= \MTM\Utilities\Factories::getTime()->getMicroEpoch();
		
		return $return;
	}
}