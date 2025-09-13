<?php
//� 2025 Martin Madsen
namespace MTM\WsSocket\Models\Server\V1;

abstract class Initialize extends CallBacks
{
	public function initialize()
	{
		if ($this->isInit() === false) {
			
			if ($this->getProtocol() === null) {
				throw new \Exception("Missing protocol", 1111);
			} elseif ($this->getHost() === null) {
				throw new \Exception("Missing host", 1111);
			} elseif ($this->getPort() === null) {
				throw new \Exception("Missing port", 1111);
			} elseif ($this->getProtocol() === "tls") {
				if ($this->getCertificate() === null) {
					throw new \Exception("Missing certificate for TLS", 1111);
				} elseif ($this->getCertificate()->getPrivateKey() === null) {
					throw new \Exception("Missing certificate private key for TLS", 1111);
				}
			}

			$strConn	= $this->getProtocol() . "://". $this->getHost() .":" . $this->getPort() . "";
			if ($this->getProtocol() ==  "tls") {
				
				//cannot delete this dir until server is down as every client will need it
				$tmpDir		= \MTM\FS\Factories::getDirectories()->getTempDirectory();
				try {
					
					$certObj	= $this->getCertificate();
					//PEM formatted cert
					$ssl		= stream_context_create();
					
					$certFile	= \MTM\FS\Factories::getFiles()->getTempFile("pem", $tmpDir)->setContent($certObj->getChainAsString());
					stream_context_set_option($ssl, "ssl", "local_cert", $certFile->getPathAsString());
					
					$keyFile	= \MTM\FS\Factories::getFiles()->getTempFile("pem", $tmpDir)->setContent($certObj->getPrivateKey()->get());
					stream_context_set_option($ssl, "ssl", "local_pk", $keyFile->getPathAsString());
					
					if ($certObj->getPrivateKey()->getPassPhrase() !== null) {
						stream_context_set_option($ssl, "ssl", "passphrase", $certObj->getPrivateKey()->getPassPhrase());
					}
					
					stream_context_set_option($ssl, "ssl", "allow_self_signed", true);
					stream_context_set_option($ssl, "ssl", "verify_peer", false);
					
					set_error_handler(array($this, "connectError"));
					try {
						
						$sockRes	= stream_socket_server($strConn, $errno, $errstr, STREAM_SERVER_BIND|STREAM_SERVER_LISTEN, $ssl);
						if (is_resource($sockRes) === true) {
							stream_socket_enable_crypto($sockRes, false);
						}
						restore_error_handler();

					} catch (\Exception $e) {
						restore_error_handler();
						throw $e;
					}
					
				} catch (\Exception $e) {
					$tmpDir->delete();
					throw $e;
				}
			} else {
				
				set_error_handler(array($this, "connectError"));
				try {
					$sockRes	= stream_socket_server($strConn, $errno, $errstr, STREAM_SERVER_BIND|STREAM_SERVER_LISTEN);
					restore_error_handler();
				} catch (\Exception $e) {
					restore_error_handler();
					throw $e;
				}
				
			}
		
			if (is_resource($sockRes) === true) {
				@stream_set_blocking($sockRes, false);
				$this->_wsSock	= $sockRes;
				$this->_isInit	= true;
			} else {
				//if you get error: Address already in use, know that if the port was in use by another socket
				//that is now shutdown, it will take a few seconds before the port is available again
				//but it will be freed up eventually
				throw new \Exception("Failed to create socket. Error: '".$errstr. "' - '".$errno."'", 42798);
			}
		}
		return $this;
	}
	public function connectError($errno, $errstr, $errfile, $errline)
	{
		throw new \Exception($errstr, $errno);
	}
}