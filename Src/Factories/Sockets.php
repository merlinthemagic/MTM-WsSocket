<?php
//© 2019 Martin Peter Madsen
namespace MTM\WsSocket\Factories;

class Sockets
{	
	//USE: $sockObj		= \MTM\WsSocket\Factories::getSockets()->__METHOD__();
	protected $_cStore=array();
	
	public function getApi()
	{
		if (array_key_exists(__FUNCTION__, $this->_cStore) === false) {
			$this->_cStore[__FUNCTION__]	= new \MTM\WsSocket\Models\API();
		}
		return $this->_cStore[__FUNCTION__];
	}
	public function getNewClient()
	{
		if (\MTM\Utilities\Factories::getProcesses()->getEventLoop()->getStatus() === true) {
			$newClient	= new \MTM\WsSocket\Models\Clients\EventLoop();
		} else {
			$newClient	= new \MTM\WsSocket\Models\Clients\Callback();
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