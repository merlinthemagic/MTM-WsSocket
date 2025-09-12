<?php
//� 2025 Martin Peter Madsen
namespace MTM\WsSocket\Factories;

class Clients extends Base
{	
	public function getV1()
	{
		$rObj	= new \MTM\WsSocket\Models\Client\V1\Remote\Zulu();
		return $rObj;
	}
}