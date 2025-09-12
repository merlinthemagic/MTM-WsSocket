<?php
//� 2025 Martin Peter Madsen
namespace MTM\WsSocket\Factories;

class Tools extends Base
{	
	protected $_s=array();
	
	public function getReadV1()
	{
		if (array_key_exists(__FUNCTION__, $this->_s) === false) {
			$this->_s[__FUNCTION__]		= new \MTM\WsSocket\Tools\V1\Read\Zulu();
		}
		return $this->_s[__FUNCTION__];
	}
	public function getWriteV1()
	{
		if (array_key_exists(__FUNCTION__, $this->_s) === false) {
			$this->_s[__FUNCTION__]		= new \MTM\WsSocket\Tools\V1\Write\Zulu();
		}
		return $this->_s[__FUNCTION__];
	}
}