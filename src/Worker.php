<?php

namespace CanalTP\AMQPMttWorkers;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

use PhpAmqpLib\Connection\AMQPConnection;
use PhpAmqpLib\Message\AMQPMessage;

use CanalTP\MediaManagerBundle\DataCollector\MediaDataCollector;
use CanalTP\MttBundle\Services\PdfHashingLib;
use CanalTP\MttBundle\Services\PdfGenerator;
use CanalTP\MttBundle\Services\MediaManager;
use CanalTP\MttBundle\Services\CurlProxy;
use CanalTP\MttBundle\Services\Amqp\Channel;

use CanalTP\AMQPMttWorkers\TimetableMediaBuilder;

class Worker
{
    private $curlProxy;
    private $pdfHashingLib;
    private $channelLib;
    private $channel;
    private $mediaBuilder;
    private $log;

    private $queueName;
    private $nbTries;

    private function setVerboseMode($stdOut, $name)
    {
        if ($stdOut) {
            $this->log->pushHandler(new \Monolog\Handler\ErrorLogHandler());
        } else {
            $this->log->pushHandler(new StreamHandler(LOG_PATH . '/' . $name . '.log', Logger::INFO));
        }
    }

    public function __construct($name, $stdOut)
    {
        $this->curlProxy = new CurlProxy();
        $this->pdfHashingLib = new PdfHashingLib($this->curlProxy);
        $this->channelLib = new Channel(HOST, USER, PASS, PORT, VHOST);
        $this->mediaBuilder = new TimetableMediaBuilder();
        $this->nbTries = 3;
        $this->log = new Logger('Worker');
        $this->setVerboseMode($stdOut, $name);
    }

    private function logPayload($payload)
    {
        $str = '[' . $payload->taskId . ']';
        $str .= ' - ' . $payload->timetableParams->externalStopPointId;
        if (isset($payload->error) && isset($payload->generated)) {
            $str .= ($payload->generated) ? ' - [GENERATED]' : ' - [NOT GENERATED]';
        } else if (isset($payload->generated)) {
            $str .= ($payload->generated) ? ' - [GENERATED]' : ' - [ALREADY GENERATED]';
        } else {
            $str .= ' - [CHECK]';
        }
        $str .= ' - ' . $payload->url;

        $this->log->info($str);
    }

    public function setNbTries($nbTries)
    {
        $this->nbTries = $nbTries;
    }

    private function ack($msgToAck, $ackPayload)
    {
        $ackMsg = new AMQPMessage(
            json_encode($ackPayload),
            array(
                'delivery_mode' => 2,
                'content_type'  => 'application/json'
            )
        );
        // publish to ack queue
        $msgToAck->delivery_info['channel']->basic_publish(
            $ackMsg,
            $this->channelLib->getExchangeName(),
            $msgToAck->get('reply_to'),
            true
        );

        $this->logPayload($ackPayload);

        // acknowledge broker
        $msgToAck->delivery_info['channel']->basic_ack($msgToAck->delivery_info['delivery_tag']);
    }

    public function processMessage($msg)
    {
        try{
            $payload = json_decode($msg->body);
            $this->logPayload($payload);
            $html = $this->curlProxy->get($payload->url);
            if (empty($html)) {
                throw new \Exception("Got empty response $html from server, url: " . ($payload->url));
            } else {
                $hash = $this->pdfHashingLib->getPdfHash($html, $payload->cssVersion);

                if ($hash != $payload->pdfHash) {
                    $pdfGenerator = new PdfGenerator($this->curlProxy, $payload->pdfGeneratorUrl, false);
                    if (!isset($payload->layoutParams->pageSize)) {
                        $pdfPath = $pdfGenerator->getPdf($payload->url, $payload->layoutParams->orientation);
                    } else {
                        $pdfPath = $pdfGenerator->getPdf($payload->url, $payload->layoutParams->orientation, $payload->layoutParams->pageSize);
                    }
                    $filepath = $this->mediaBuilder->saveFile(
                        $pdfPath,
                        $payload->timetableParams->externalNetworkId,
                        $payload->timetableParams->externalRouteId,
                        $payload->timetableParams->externalStopPointId,
                        $payload->timetableParams->seasonId
                    );

                    $this->log->info('[' . $payload->taskId . '] - ' . $payload->timetableParams->externalStopPointId . ' - Pdf generated (' . $pdfPath . ' -> ' . $filepath . ')');
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
                $this->ack($msg, $payload);

            }
        } catch(\Exception $e){
            $payload = json_decode($msg->body);
            // is it the first try? if so initialize nbTries variable
            if (!isset($payload->nbTries)) {
                $payload->nbTries = 0;
            }
            $payload->nbTries++;

            $str = '[' . $payload->taskId . ']';
            $str .= ' - ' . $payload->timetableParams->externalStopPointId;
            $this->log->error($str . ' - Nb Tries: ' . $payload->nbTries . ' - ' . $e->getMessage());

            if ($payload->nbTries >= $this->nbTries) {
                $payload->generated = false;
                $payload->error = $e->getMessage();
                $this->ack($msg, $payload);
            } else {
                $msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag']);
                // republish it with new payload
                $newMsg = new AMQPMessage(
                    json_encode($payload),
                    array(
                        'delivery_mode' => 2,
                        'content_type'  => 'application/json',
                        'reply_to'      => $msg->get('reply_to')
                    )
                );
                $this->channelLib->getChannel()->basic_publish($newMsg, $this->channelLib->getExchangeName(), $msg->delivery_info['routing_key'], true);
            }
        }
    }

    public function init()
    {
        $this->channel = $this->channelLib->getChannel();
        list($this->queueName, ,) = $this->channel->queue_declare($this->channelLib->getPdfGenQueueName(), false, true, false, false);
        $this->channel->queue_bind($this->queueName, $this->channelLib->getExchangeName(), "*.pdf_gen");
    }

    private function shutdown($channelLib)
    {
        $this->channelLib->close();
    }

    public function run()
    {
        $this->channel->basic_qos(null, 1, null);
        $this->channel->basic_consume($this->queueName, 'pdfWorker', false, false, false, false, array($this, 'processMessage'));

        register_shutdown_function(array($this, 'shutdown'), $this->channelLib);

        // Loop as long as the channel has callbacks registered
        while (count($this->channel->callbacks)) {
            $this->channel->wait();
        }
    }
}
