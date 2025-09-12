<?php
//� 2025 Martin Madsen
namespace MTM\WsSocket\Models\Client\V1;

abstract class Receive extends CallBacks
{	
	protected $_msgs=array();
	
	public function getMessage($timeout=-1)
	{
		$this->receiveMessages($timeout);
		return array_shift($this->_msgs);
	}
	public function getMessages($timeout=-1)
	{
		$this->receiveMessages($timeout);
		$msgs			= $this->_msgs;
		$this->_msgs	= array();
		return $msgs;
	}
	protected function receiveMessages($timeout=-1)
	{
		//$timeout is in mili secs
		$tFact		= \MTM\Utilities\Factories::getTime();
		if ($timeout === -1) {
			$tTime	= $tFact->getMicroEpoch(); //dont wait for messages
		} else {
			$tTime	= ($tFact->getMicroEpoch() + ($timeout / 1000));
		}
		
		while (true) {
			
			$isEmpty	= true;
			if ($this->isConnected() === true) {
				//feof($this->getSocket()) is useless. there is no EOF so it always returns true
				if ($this->getBuffer() === null) {
					$nData		= $this->readTool()->raw($this, 1);
					if ($nData != "") {
						//store the extra data so the read function gets a
						$this->setBuffer($nData);
						$isEmpty	= false;
					}
				} else {
					//we have data pending in the buffer
					$isEmpty	= false;
				}
			}
			
			$cTime	= $tFact->getMicroEpoch();
			if ($isEmpty === false) {
				$this->_lastRecv	= $cTime;
				$rData				= $this->readTool()->socket($this, $this->getReadTime());
				if ($rData["dataType"] != "ping" && $rData["dataType"] != "pong") {
					$this->_msgs[]		= $rData["data"];
				} else {
					//close message???
					echo "\n <code><pre> \nClass:  ".__CLASS__." \nMethod:  ".__FUNCTION__. "  \n";
					// 			var_dump($total);
					echo "\n 2222 \n";
					//print_r($_GET);
					echo "\n 3333 \n";
					print_r($rData);
					echo "\n ".time()."</pre></code> \n ";
					die("end");
					
// 					if (count($msgs) > 0) {
// 						$this->setIdle(false);
// 						foreach ($msgs as $index => $msg) {
// 							if ($msg == "GoodByeClient" || $msg == "") {
// 								unset($msgs[$index]);
// 							}
// 						}
// 					}
				}
				
				
				
			} elseif ($cTime >= $tTime || count($msgs) > 0) {
				//done, we have emptied the message queue or run out of time
				break;
			} else {
				//no need to saturate the CPU
				usleep(10000);
			}
		}
	}
}