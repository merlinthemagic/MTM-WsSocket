# MTM-WsSocket

## Server:

### Listen on port:

```
$ipAddr	= "127.0.0.1";
$port		= 5433;

$sockObj	= \MTM\WsSocket\Factories::getSockets()->getNewServer();
$sockObj->setConnection("tcp", $ipAddr, $port);
```

### New clients:

When a new client registers you can get a call back, letting you validate if you will allow that
client to connect or not.

The new socket will be passed to the method after connect.

If you reject the new client throw, otherwise return true.

```
$sockObj->setNewClientCb($someObject, "someMethod");
```