<?php
//� 2025 Martin Madsen
namespace MTM\WsSocket\Models\Client\V1;

abstract class Transmit extends Receive
{
	public function sendMessage($msg, $type="text")
	{
		if ($this->isTerm() === true) {
			throw new \Exception("Cannot execute socket has been terminated", 1111);
		} elseif ($this->isInit() === false) {
			throw new \Exception("Cannot execute socket has not been initialized", 1111);
		}
		\MTM\WsSocket\Factories::getTools()->getWriteV1()->socket($this, $msg, $type);
		return $this;
	}
	public function ping($msg)
	{
		$this->sendMessage($msg, "ping");
		return $this;
	}
}