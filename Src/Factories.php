<?php
//© 2019 Martin Peter Madsen
namespace MTM\WsSocket;

class Factories
{
	private static $_cStore=array();
	
	//USE: $aFact		= \MTM\WsSocket\Factories::$METHOD_NAME();
	
	public static function getSockets()
	{
		if (array_key_exists(__FUNCTION__, self::$_cStore) === false) {
			self::$_cStore[__FUNCTION__]	= new \MTM\WsSocket\Factories\Sockets();
		}
		return self::$_cStore[__FUNCTION__];
	}
}