<?php
//� 2025 Martin Madsen
namespace MTM\WsSocket\Tools\V1\Write;

abstract class Buffer extends Alpha
{
	public function write($wsCli, $data, $maxWaitMs=0)
	{
		$timeFact			= \MTM\Utilities\Factories::getTime();
		$maxWait			= $maxWaitMs / 1000;
		$return				= array();
		$return["error"]	= "";
		$return["code"]		= 0;
		$return["sTime"]	= $timeFact->getMicroEpoch();
		
		try {
			
			$done		= false;
			$tBytes		= strlen($data); //total bytes
			$sBytes		= 0; //sent bytes
			$rData		= $data; //remaining data
			while ($done === false) {
				
				$sBytes		+= $this->raw($wsCli, $rData);
				if ($tBytes == $sBytes) {
					$done				= true;
				} elseif (($timeFact->getMicroEpoch() - $return["sTime"]) > $maxWait) {
					//write timeout
					throw new \Exception("Timeout", 1886);
				} else {
					//we have time for another attempt, socket might be out of buffer space
					//wait for a tiny bit no need to saturate the CPU
					$rData		= substr($data, $sBytes);
					usleep(10000);
				}
			}
			
		} catch (\Exception $e) {
			$return["error"]	= $e->getMessage();
			$return["code"]		= $e->getCode();
		}
		
		$return["eTime"]	= $timeFact->getMicroEpoch();
		
		return $return;
	}
}