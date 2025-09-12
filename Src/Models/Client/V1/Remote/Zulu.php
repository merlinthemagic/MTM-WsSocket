<?php
//� 2025 Martin Madsen
namespace MTM\WsSocket\Models\Client\V1\Remote;

class Zulu extends Process
{
	public function terminate()
	{
		
		try {
			
			if ($this->isTerm() === false) {
				//default return is "all is good" message (1000). Termination Codes Src: https://tools.ietf.org/html/rfc6455#section-7.4.1
				$termCode	= 1000;
				$termMsg	= "GoodByeServer";
				$msg		= "";
				$termbin	= sprintf("%016b", $termCode);
				$binBytes	= str_split($termbin, 8);
				foreach ($binBytes as $binByte) {
					$msg .= chr(bindec($binByte));
				}
				$msg .= $termMsg;
				
				$this->sendMessage($msg, "close");
			}

		} catch (\Exception $e) {
			//no throwing, terminate can have many unknowns
			$rData		= array();
			$rData[]	= "Exception";
			$rData[]	= $e->getMessage();
			$rData[]	= $e->getCode();
			$rData[]	= $e->getLine();
			$rData[]	= $e->getTraceAsString();
			echo "\n <code><pre> \nClass:  ".__CLASS__." \nMethod:  ".__FUNCTION__. "  \n";
			print_r($rData);
			echo "\n ".time()."</pre></code> \n ";
			// 			die("end");
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