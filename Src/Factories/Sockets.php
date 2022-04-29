<?php
//© 2019 Martin Peter Madsen
namespace MTM\WsSocket\Factories;

class Sockets
{	
	//USE: $sockObj		= \MTM\WsSocket\Factories::getSockets()->__METHOD__();
	protected $_s=array();

	public function getApi()
	{
		if (array_key_exists(__FUNCTION__, $this->_s) === false) {
			$this->_s[__FUNCTION__]	= new \MTM\WsSocket\Models\API();
		}
		return $this->_s[__FUNCTION__];
	}
	public function getNewClient()
	{
		$asyncMethod	= \MTM\Utilities\Factories::getProcesses()->getAsyncMethod();
		if ($asyncMethod === "callback") {
			$newClient	= new \MTM\WsSocket\Models\Clients\Callback();
		} elseif ($asyncMethod === "eventloop") {
			$newClient	= new \MTM\WsSocket\Models\Clients\EventLoop();
		} else {
			throw new \Exception("Not handled for async method: ".$asyncMethod, 8564);
		}
		$newClient->setParent($this->getApi());
		return $newClient;
	}
	public function getNewServer()
	{
		$newServer	= new \MTM\WsSocket\Models\Server();
		$newServer->setParent($this->getApi());
		return $newServer;
	}
}