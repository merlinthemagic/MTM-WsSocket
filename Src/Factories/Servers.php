<?php
//� 2025 Martin Peter Madsen
namespace MTM\WsSocket\Factories;

class Servers extends Base
{	
	protected $_instObjs=array();
	
	public function getV1()
	{
		$rObj								= new \MTM\WsSocket\Models\Server\V1\Zulu();
		$this->_instObjs[$rObj->getGuid()]	= $rObj;
		return $rObj;
	}
	public function getByGuid($guid, $throw=true)
	{
		$this->isV4Guid($guid, true);
		if (array_key_exists($guid, $this->_instObjs) === true) {
			return $this->_instObjs[$guid];
		} elseif ($throw === true) {
			throw new \Exception("No WS server with that guid", 1111);
		} else {
			return null;
		}
	}
}