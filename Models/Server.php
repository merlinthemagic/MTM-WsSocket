<?php
//© 2019 Martin Madsen
namespace MTM\WsSocket\Models;

class Server
{
	protected $_uuid=null;
	protected $_socket=null;
	protected $_protocol=null;
	protected $_hostname=null;
	protected $_portNbr=null;
	protected $_timeout=null;
	protected $_defMaxReadTime=30000;
	protected $_defMaxWriteTime=30000;
	protected $_defChunkSize=4096;
	protected $_defWriteDelay=0;//in milisecs
	protected $_protocols=array("tcp", "ssl", "tls");
	protected $_sslCertObj=null;
	protected $_parent=null;
	protected $_children=array();

	//will be triggered when new client attaches
	protected $_newClientCb=null;
	
	public function __destruct()
	{
		$this->terminate(false);
	}
	public function terminate($throw=true)
	{
		if (is_resource($this->_socket) === true) {
			try {
				fclose($this->_socket);
				$this->_socket			= null;
				$this->_isConnected		= false;
			} catch (\Exception $e) {
				if ($throw === true) {
					throw $e;
				}
			}
		}
	}
	public function setConnection($protocol, $hostname, $portNbr, $timeoutMs=30000)
	{
		$protocol	= strtolower(trim($protocol));
		if (in_array($protocol, $this->_protocols) === false) {
			throw new \Exception("Invalid Protocol: " . $protocol);
		}
		
		$timeout	= intval(round($timeoutMs / 1000));
		
		$this->_protocol	= $protocol;
		$this->_hostname	= $hostname;
		$this->_portNbr		= $portNbr;
		$this->_timeout		= $timeout;
		
		return $this;
	}
	public function setSslConnection($certObj)
	{
		if ($certObj instanceof \MTM\Certs\Models\CRT === false) {
			//should be a certificate object containing the chain back to the root CA
			throw new \Exception("Invalid Certificate");
		} else {
			$this->_sslCertObj		= $certObj;
		}
		return $this;
	}
	public function getUuid()
	{
		if ($this->_uuid === null) {
			$this->_uuid		= uniqid("wsServer.", true);
		}
		return $this->_uuid;
	}
	public function setParent($obj)
	{
		$this->_parent	= $obj;
		return $this;
	}
	public function getParent()
	{
		return $this->_parent;
	}
	public function getChildren()
	{
		return $this->_children;
	}
	public function getProtocol()
	{
		return $this->_protocol;
	}
	public function getHostname()
	{
		return $this->_hostname;
	}
	public function getPort()
	{
		return $this->_portNbr;
	}
	public function getTimeout()
	{
		return $this->_timeout;
	}
	public function getClientDefaultMaxReadTime()
	{
		return $this->_defMaxReadTime;
	}
	public function setClientDefaultMaxReadTime($miliSecs)
	{
		$this->_defMaxReadTime	= intval($miliSecs);
		return $this;
	}
	public function getClientDefaultMaxWriteTime()
	{
		return $this->_defMaxWriteTime;
	}
	public function setClientDefaultMaxWriteTime($miliSecs)
	{
		$this->_defMaxWriteTime	= intval($miliSecs);
		return $this;
	}
	public function getClientDefaultWriteDelay()
	{
		return $this->_defWriteDelay;
	}
	public function setClientDefaultWriteDelay($miliSec)
	{
		$this->_defWriteDelay	= $miliSec;
		return $this;
	}
	public function getClientDefaultChunkSize()
	{
		return $this->_defChunkSize;
	}
	public function setClientDefaultChunkSize($bytes)
	{
		$this->_defChunkSize	= intval($bytes);
		return $this;
	}
	public function getSslCertificate()
	{
		return $this->_sslCertObj;
	}
	public function getClients()
	{
		//add any pending clients to the pool
		$this->getNewClients(-1);
		return $this->getChildren();
	}
	public function removeClient($clientObj)
	{
		foreach ($this->getClients() as $cId => $eObj) {
			if ($eObj->getUuid() == $clientObj->getUuid()) {
				unset($this->_children[$cId]);
				$clientObj->terminate(false);
				break;
			}
		}
		return $this;
	}
	public function setNewClientCb($obj=null, $method=null)
	{
		if (is_object($obj) === true && is_string($method) === true) {
			$this->_newClientCb	= array($obj, $method);
		}
		return $this;
	}
	public function getNewClients($timeoutMs=10000)
	{
		$newObjs	= array();
		
		if ($timeoutMs == -1) {
			//get all pending clients, but dont wait around
			$timeout	= 0.01;
		} else {
			$timeout	= $timeoutMs / 1000;
		}

		if ($this->getProtocol() == "tcp") {
			$clientRes	= @stream_socket_accept($this->getSocket(), $timeout, $peerName);
		} elseif ($this->getProtocol() == "ssl" || $this->getProtocol() == "tls") {
			stream_set_blocking($this->getSocket(), true);
			$clientRes	= @stream_socket_accept($this->getSocket(), $timeout, $peerName);
			stream_set_blocking($this->getSocket(), false);
		} else {
			//http://php.net/manual/en/function.stream-socket-accept.php
			throw new \Exception("UDP not yet handled");
		}

		if (is_resource($clientRes) === true) {

			//found new client, add it to the
			$newScObj			= new \MTM\WsSocket\Models\ServerClient();
			$newScObj->setParent($this)->setSocket($clientRes)->setAddress($peerName);
			$newScObj->setDefaultReadTime($this->getClientDefaultMaxReadTime());
			$newScObj->setDefaultWriteTime($this->getClientDefaultMaxWriteTime());
			$newScObj->setChunkSize($this->getClientDefaultChunkSize());
			$newScObj->setMinWriteDelay($this->getClientDefaultWriteDelay());
			try {
				
				$newScObj->connect();
				if ($this->_newClientCb !== null) {
					//throw if you do not want to allow this client, only a true return will allow
					$isValid	= call_user_func_array($this->_newClientCb, array($newScObj));
					if ($isValid !== true) {
						throw new \Exception("Consumer error, rather safe than sorry. Reject!");
					}
				}
				
				$this->_children[]	= $newScObj;
				$newObjs[]			= $newScObj;

			} catch (\Exception $e) {
				//connect failed or was rejected, we do nothing
				//anyone who connects (fsockopen even) will be picked up
				//if they fail to connect we discard them
				$newScObj->terminate(false);
			}

			//continue until there are no more pending clients
			$rTime		= -1;
			$newObjs	= array_merge($newObjs, $this->getNewClients($rTime));
		}
		
		return $newObjs;
	}	
	protected function getSocket()
	{
		//server socket is only needed by the server, clients have their own resource
		if ($this->_socket === null) {
			
			if ($this->getProtocol() === null || $this->getHostname() === null || $this->getPort() === null || $this->getTimeout() === null) {
				throw new \Exception("Missing connection parameters");
			}
			
			$strConn	= $this->getProtocol() . "://". $this->getHostname() .":" . $this->getPort() . "";
			if ($this->getProtocol() == "ssl" || $this->getProtocol() ==  "tls") {

				//PEM formatted cert
				$ssl		= stream_context_create();
				if ($this->getSslCertificate() === null) {
					throw new \Exception("Missing SSL connection parameters");
				} else {
					$fileObj	= \MTM\FS\Factories::getFiles()->getTempFile("pem")->setContent($this->getSslCertificate()->getChainAsString());
					stream_context_set_option($ssl, "ssl", "local_cert", $fileObj->getPathAsString());
				}
				if ($this->getSslCertificate()->getPrivateKey() !== null) {
					$fileObj	= \MTM\FS\Factories::getFiles()->getTempFile("pem")->setContent($this->getSslCertificate()->getPrivateKey()->get());
					stream_context_set_option($ssl, "ssl", "local_pk", $fileObj->getPathAsString());
				}
				if ($this->getSslCertificate()->getPrivateKey()->getPassPhrase() !== null) {
					stream_context_set_option($ssl, "ssl", "passphrase", $this->getSslCertificate()->getPrivateKey()->getPassPhrase());
				}
				
				stream_context_set_option($ssl, "ssl", "allow_self_signed", true);
				stream_context_set_option($ssl, "ssl", "verify_peer", false);

				$sockRes	= @stream_socket_server($strConn, $errno, $errstr, STREAM_SERVER_BIND|STREAM_SERVER_LISTEN, $ssl);
				if (is_resource($sockRes) === true) {
					@stream_socket_enable_crypto($sockRes, false);
				}
					
			} else {
				$sockRes	= @stream_socket_server($strConn, $errno, $errstr, STREAM_SERVER_BIND|STREAM_SERVER_LISTEN);
			}

			if (is_resource($sockRes) === true) {

				@stream_set_blocking($sockRes, false);
				$this->_socket	= $sockRes;
				
			} else {
				//if you get error: Address already in use, know that if the port was in use by another socket
				//that is now shutdown, it will take a few seconds before the port is available again
				//but it will be freed up eventually
				throw new \Exception("Failed to create socket. Error: " . $errstr . " - " . $errno);
			}
		}
		return $this->_socket;
	}
}