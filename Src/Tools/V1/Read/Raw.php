<?php
//� 2025 Martin Madsen
namespace MTM\WsSocket\Tools\V1\Read;

abstract class Raw extends Buffer
{
	public function raw($wsCli, $byteCount)
	{
		//we are not blocking so its a max bytes to read, does not mean you will get that much data back
		$sockRes	= $wsCli->getWsSocket();
		if (is_resource($sockRes) === false) {
			throw new \Exception("Cannot read, client socket is not a resource", 1111);
		}
		return fread($sockRes, $byteCount);
	}
	
}