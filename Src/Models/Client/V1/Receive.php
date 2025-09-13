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
					$reObj		= $this->readTool()->raw($this, 1);
					if ($reObj->data != "") {
						//store the extra data so the read function gets a
						$this->setBuffer($reObj->data);
						$isEmpty	= false;
					}
				} else {
					//we have data pending in the buffer
					$isEmpty	= false;
				}
			}
			
			$cTime	= $tFact->getMicroEpoch();
			if ($isEmpty === false) {
				$this->setLastRxTime($cTime);
				$reObj				= $this->readTool()->socket($this, $this->getReadTime());
				if ($reObj->type != "ping" && $reObj->type != "pong" && $reObj->type != "close") {
					$this->_msgs[]		= $reObj->data;
				} else {
					//pin, pong, close message, not interested
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