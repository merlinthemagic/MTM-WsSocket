<?php
//� 2019 Martin Peter Madsen
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
	public static function getServers()
	{
		if (array_key_exists(__FUNCTION__, self::$_cStore) === false) {
			self::$_cStore[__FUNCTION__]	= new \MTM\WsSocket\Factories\Servers();
		}
		return self::$_cStore[__FUNCTION__];
	}
	public static function getClients()
	{
		if (array_key_exists(__FUNCTION__, self::$_cStore) === false) {
			self::$_cStore[__FUNCTION__]	= new \MTM\WsSocket\Factories\Clients();
		}
		return self::$_cStore[__FUNCTION__];
	}
	public static function getTools()
	{
		if (array_key_exists(__FUNCTION__, self::$_cStore) === false) {
			self::$_cStore[__FUNCTION__]	= new \MTM\WsSocket\Factories\Tools();
		}
		return self::$_cStore[__FUNCTION__];
	}
}