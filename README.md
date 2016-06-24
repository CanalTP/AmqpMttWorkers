README
======

AMQP Mtt workers for pdf generation and acknowledgement of those tasks


Installation
-------------

1. Use composer
    - $> php composer.phar install

Requirements
-------------

rabbitmq-server

Launch
-----
in the web folder you can launch this worker with this command below

```
$> php pdfGenerationWorker.php [file name log] [number of trials]
```