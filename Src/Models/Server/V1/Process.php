<?php
//� 2025 Martin Madsen
namespace MTM\WsSocket\Models\Server\V1;

abstract class Process extends Initialize
{
	protected $_clObjs=array();
	protected $_penObjs=array();
	
	public function getClients()
	{
		//add any pending clients to the pool
		$this->pollNewClients(-1);
		return array_values($this->_clObjs);
	}
	public function pollNewClients($timeoutMs=10000)
	{
		//add class variable for async execution
		if ($this->isInit() === false) {
			return false;
		}
		foreach ($this->_penObjs as $ind => $penObj) {
			try {
				$penObj->connect();
				if ($penObj->isConnected() === true) {
					//connected
					unset($this->_penObjs[$ind]);
					$this->_clObjs[]	= $penObj;
				} elseif ($penObj->isTerm() === true) {
					unset($this->_penObjs[$ind]);
				}
			} catch (\Exception $e) {
				unset($this->_penObjs[$ind]);
			}
		}
		if ($timeoutMs == -1) {
			//get all pending clients, but dont wait around
			$timeout	= 0.01;
		} else {
			$timeout	= $timeoutMs / 1000;
		}
		
		if ($this->getProtocol() == "tcp") {
			$clRes		= @stream_socket_accept($this->getWsSocket(), $timeout, $peerName);
		} elseif ($this->getProtocol() == "tls") {
			stream_set_blocking($this->getWsSocket(), true);
			$clRes		= @stream_socket_accept($this->getWsSocket(), $timeout, $peerName);
			stream_set_blocking($this->getWsSocket(), false);
		} else {
			//http://php.net/manual/en/function.stream-socket-accept.php
			throw new \Exception("Not handled for protocol: ".$this->getProtocol()."", 42792);
		}

		if (is_resource($clRes) === true) {
			
			try {
				//found new client, add it
				$ipAddr		= substr($peerName, 0, strrpos($peerName, ":"));
				$srcPort	= intval(substr($peerName, (strrpos($peerName, ":") + 1)));
	
				$clObj		= new \MTM\WsSocket\Models\Client\V1\Session\Zulu($clRes, $this->getGuid());
				$clObj->setReadTime($this->getClientDefaultReadTime())->setWriteTime($this->getClientDefaultWriteTime());
				$clObj->setChunkSize($this->getClientDefaultChunkSize())->setSourceIp($ipAddr)->setSourcePort($srcPort);
				$clObj->setWriteDelay($this->getClientDefaultWriteDelay())->setAsyncConnect($this->getClientConnectAsync());
	
				$connCb	= $this->getClientConnectCb();
				if ($connCb !== null) {
					$clObj->setConnectCb($connCb[0], $connCb[1]);
				}
				
				$termCb	= $this->getClientTermCb();
				if ($termCb !== null) {
					$clObj->setTermCb($termCb[0], $termCb[1]);
				}

				$clObj->connect();
				
				//store as pending until the connection has been upgraded
				if ($clObj->isConnected() === false) {
					$this->_penObjs[$clObj->getGuid()]	= $clObj;
				} else {
					$this->_clObjs[$clObj->getGuid()]	= $clObj;
				}

				//continue until there are no more pending clients
				$this->pollNewClients(-1);
				
			} catch (\Exception $e) {
				//connect failed or was rejected, we do nothing
				//anyone who connects (fsockopen even) will be picked up
				//if they fail to connect we discard them
				$clObj->terminate();
			}
		}
	}
	public function removeClient($wsCli)
	{
		//called by server clients when they want to remove themself
		if ($wsCli instanceof \MTM\WsSocket\Models\Client\V1\Session\Zulu === false) {
			throw new \Exception("Invalid input", 1111);
		}
		if (array_key_exists($wsCli->getGuid(), $this->_clObjs) === true) {
			unset($this->_clObjs[$wsCli->getGuid()]);
		} elseif (array_key_exists($wsCli->getGuid(), $this->_penObjs) === true) {
			unset($this->_penObjs[$wsCli->getGuid()]);
		}
	}
}