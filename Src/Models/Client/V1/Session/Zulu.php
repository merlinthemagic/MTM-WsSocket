<?php
//� 2025 Martin Madsen
namespace MTM\WsSocket\Models\Client\V1\Session;

class Zulu extends Process
{
	public function terminate()
	{
		if ($this->isTerm() === false && $this->_initTerm === false) {
			$this->_initTerm	= true; //without this there are times when terminate is called endlessly
			
			$this->getServer()->removeClient($this);
			
			try {

				//default server is closing message. Termination Codes Src: https://tools.ietf.org/html/rfc6455#section-7.4.1
				$termCode	= 1001;
				$termMsg	= "GoodByeClient";
				$msg		= "";
				$termbin	= sprintf("%016b", $termCode);
				$binBytes	= str_split($termbin, 8);
				foreach ($binBytes as $binByte) {
					$msg	.= chr(bindec($binByte));
				}
				$msg	.= $termMsg;
				$this->sendMessage($msg, "close");				
				
				//we are expecting the client to ack the close and return our message
				//was tested on firefox and Chrome
				
			} catch (\Exception $e) {
				//no throwing, terminate can have many unknowns
			}

			$this->_isConn		= false;
			$this->_isTerm		= true;
			if ($this->getTermCb() !== null) {
				try {
					call_user_func_array($this->getTermCb(), array($this));
				} catch (\Exception $e) {
					//user issue
				}
			}
		}
	}
}