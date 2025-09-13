<?php
//� 2025 Martin Madsen
namespace MTM\WsSocket\Tools\V1\Read;

abstract class Raw extends Buffer
{
	public function raw($wsCli, $len)
	{
		//we are not blocking so its a max bytes to read, does not mean you will get that much data back
		$sockRes	= $wsCli->getWsSocket();
		if (is_resource($sockRes) === false) {
			throw new \Exception("Cannot read, client socket is not a resource", 1111);
		}
		
		set_error_handler(array($this, "throwErrors"));
		try {
			
			$rObj			= new \stdClass();
			$rObj->data		= fread($sockRes, $len);
			restore_error_handler();
			return $rObj;
			
		} catch (\Exception $e) {
			restore_error_handler();
			$wsCli->terminate();
			throw new \Exception($e->getMessage()." ".$e->getCode(), 88101);
		}
	}
}