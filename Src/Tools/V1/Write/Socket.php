<?php
//� 2025 Martin Madsen
namespace MTM\WsSocket\Tools\V1\Write;

abstract class Socket extends Raw
{
	public function socket($wsCli, $data, $type)
	{
		$return				= array();
		$return["error"]	= "";
		$return["code"]		= 0;
		$return["sTime"]	= $this->microTime();
		
		try {
			
			$curPos		= 0;
			$dataLen	= strlen($data);
			$done		= false;
			$dtCode		= $this->getDataTypeCode($type);
			
			while ($done === false) {
				
				$dataChunk	= substr($data, $curPos, $wsCli->getChunkSize());
				$chunckLen	= strlen($dataChunk);
				$curPos		+= $wsCli->getChunkSize();
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
				if ($wsCli->useMasking() === true) {
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
				if ($wsCli->useMasking() === true) {
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
				$minWd	= $wsCli->getWriteDelay();
				if ($minWd > 0) {
					$dTime	= ceil((($wsCli->getLastTxTime() + ($wsCli->getMinWriteDelay() / 1000)) - $this->microTime()) * 1000000);
					if ($dTime > 0) {
						usleep($dTime);
					}
				}
				
				//finally send the darn thing
				$wData	= $this->write($wsCli, $payloadBin, $wsCli->getWriteTime());
				if (strlen($wData["error"]) > 0) {
					throw new \Exception("Write Error: '".$wData["error"]."'", $wData["code"]);
				} else {
					$wsCli->setLastTxTime($this->microTime());
				}
			}
			
		} catch (\Exception $e) {
			$return["error"]	= $e->getMessage();
			$return["code"]		= $e->getCode();
		}
		
		$return["eTime"]	= $this->microTime();
		
		return $return;
	}
}