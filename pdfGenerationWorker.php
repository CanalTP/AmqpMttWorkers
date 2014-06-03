<?php
// This file is under development :p

require_once __DIR__ . '/vendor/autoload.php';
include(__DIR__ . '/config.inc.php');

use PhpAmqpLib\Connection\AMQPConnection;
use PhpAmqpLib\Message\AMQPMessage;

use CanalTP\MediaManagerBundle\DataCollector\MediaDataCollector;
// MTTBundle
use CanalTP\MttBundle\Services\PdfHashingLib;
use CanalTP\MttBundle\Services\PdfGenerator;
use CanalTP\MttBundle\Services\MediaManager;
use CanalTP\MttBundle\Services\CurlProxy;
use CanalTP\MttBundle\Services\Amqp\Channel;

//AmqpMttWorkers
use CanalTP\AMQPMttWorkers\TimetableMediaBuilder;

$curlProxy = new CurlProxy();
$pdfHashingLib = new PdfHashingLib($curlProxy);

// $connection = new AMQPConnection(HOST, PORT, USER, PASS, VHOST);
$channelLib = new Channel(HOST, USER, PASS, PORT, VHOST);
$channel = $channelLib->getChannel();

list($queue_name, ,) = $channel->queue_declare($channelLib->getPdfGenQueueName(), false, true, false, false);
$channel->queue_bind($queue_name, $channelLib->getExchangeName(), "*.pdf_gen");

$ttMediaBuilder = new TimetableMediaBuilder();

$process_message = function($msg) use ($curlProxy, $pdfHashingLib, $ttMediaBuilder, $channelLib)
{
    $payload = json_decode($msg->body);
    echo "\n--------\n";
    print_r($payload);
    echo "\n--------\n";
    $html = $curlProxy->get($payload->url);
    if (empty($html)) {
        echo "Got empty response $html from server, url: " . ($payload->url);
        echo "\n--------\n";
        $msg->delivery_info['channel']->basic_nack($msg->delivery_info['delivery_tag'], false, true);
    } else {
        $hash = $pdfHashingLib->getPdfHash($html, $payload->cssVersion);
        echo "pdf hash: " . ($hash);
        echo "\n--------\n";
        
        if ($hash != $payload->pdfHash) {
            $pdfGenerator = new PdfGenerator($curlProxy, $payload->pdfGeneratorUrl, false);
            $pdfPath = $pdfGenerator->getPdf($payload->url, $payload->layoutParams->orientation);
            echo "pdfPath: " . $pdfPath;
            $filepath = $ttMediaBuilder->saveFile(
                $pdfPath,
                $payload->timetableParams->externalNetworkId,
                $payload->timetableParams->externalRouteId,
                $payload->timetableParams->externalStopPointId,
                $payload->timetableParams->seasonId
            );
            echo "Generation result :" . $filepath ? $filepath : 'NOK';
            echo "\n--------\n";
        }
        
        // acknowledgement part
        // push ack data into expected queue
        if (isset($filepath)) {
            $payload->generated = true;
            $payload->generationResult = new \stdClass;
            $payload->generationResult->filepath = $filepath;
            $payload->generationResult->pdfHash = $hash;
            $payload->generationResult->created = time();
        } else {
            $payload->generated = false;
        }
        $ackMsg = new AMQPMessage(
            json_encode($payload),
            array(
                'delivery_mode' => 2,
                'content_type'  => 'application/json'
            )
        );
        // publish to ack queue
        $msg->delivery_info['channel']->basic_publish($ackMsg, $channelLib->getExchangeName(), $msg->get('reply_to'), true);
        echo " [x] Sent ",$msg->get('reply_to'),':',print_r($payload, true)," \n";
        echo "\n--------\n";
        // acknowledge broker
        $msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag']);
    }
    // sleep(10);
};
$channel->basic_qos(null, 1, null);
$channel->basic_consume($queue_name, 'pdfWorker', false, false, false, false, $process_message);

function shutdown($channelLib)
{
    $channelLib->close();
}
register_shutdown_function('shutdown', $channelLib);

// Loop as long as the channel has callbacks registered
while (count($channel->callbacks)) {
    $channel->wait();
}