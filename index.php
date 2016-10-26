<?php

require_once 'vendor/autoload.php';

try {

    $config = require_once __DIR__ . '/config.php';
    $bot = new \TelegramBot\Api\Client($config['bot_token'], $config['bot_tracker']);

    $bot->on(function($message) use ($bot, $config){

        $location = $message->getLocation();

        if (empty($location)) {
            $bot->sendMessage($message->getChat()->getId(), 'Please give me your location first');
            return;
        }

        $url = $config['api_url'] . 'site/filter';

        $time_from = new \DateTime('now', new \DateTimeZone('Europe/Kiev'));
        $time_to = clone $time_from;
        $time_to->modify('+1 hour');

        $query = http_build_query([
            'time_from' => $time_from->format('H:i'),
            'time_to' => $time_to->format('H:i'),
            'position_lat' => $location->getLatitude(),
            'position_lng' => $location->getLongitude(),
            'maxd' => 2500,
        ]);

        $str = file_get_contents("{$url}?{$query}");
        $bills = @json_decode($str, true);

        if (empty($bills)) {
            $bot->sendMessage($message->getChat()->getId(), 'It is sad, but nothing is found. Try another location or time.');
            return;
        }

        $output = '';

        foreach ($bills as $bill) {
            $output .=
                $bill['time'] . ' ' . $bill['cinema'] . PHP_EOL .
                $bill['movie'] . PHP_EOL . PHP_EOL;
        }

        $bot->sendMessage($message->getChat()->getId(), $output);


    }, function($message) use ($name) {
        return true;
    });

    $bot->run();

} catch (\TelegramBot\Api\Exception $e) {
    // @todo add logging
    $e->getMessage();
}
