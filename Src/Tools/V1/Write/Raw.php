<?php
//� 2025 Martin Madsen
namespace MTM\WsSocket\Tools\V1\Write;

abstract class Raw extends Buffer
{
	public function raw($wsCli, $data)
	{
		$sockRes	= $wsCli->getWsSocket();
		if (is_resource($sockRes) === false) {
			throw new \Exception("Cannot write, client socket is not a resource", 11988);
		}
		$wBytes		= fwrite($sockRes, $data);
		if ($wBytes === false) {
			throw new \Exception("Failed to write to socket", 11989);
		}
		return $wBytes;
	}
	
}