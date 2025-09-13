<?php
//� 2025 Martin Madsen
namespace MTM\WsSocket\Tools\V1\Write;

abstract class Raw extends Buffer
{
	public function raw($wsCli, $data)
	{
		$sockRes	= $wsCli->getWsSocket();
		if (is_resource($sockRes) === false) {
			throw new \Exception("Cannot write, client socket is not a resource", 1111);
		}
		
		set_error_handler(array($this, "throwErrors"));
		try {
			$len	= fwrite($sockRes, $data);
			restore_error_handler();
			if ($len === false) {
				throw new \Exception("Failed to write to socket", 1111);
			}
			return $len;
			
		} catch (\Exception $e) {
			restore_error_handler();
			$wsCli->terminate();
			throw new \Exception($e->getMessage()." ".$e->getCode(), 88001);
		}
	}
}