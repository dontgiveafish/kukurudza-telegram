<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/lib/Buzz/Service.php';

try {

    $config = require_once __DIR__ . '/config.php';
    $bot = new \TelegramBot\Api\Client($config['bot_token'], $config['bot_tracker']);

    $bot->on(function(\TelegramBot\Api\Types\Message $message) use ($bot, $config) {

        // more config

        $givemesomemoviesbutton = [
            'text' => 'Give me some movies!',
            'request_location' => true,
        ];

        $maxd = 2500;

        // get info from message

        $chat = $message->getChat();
        $location = $message->getLocation();
        $text = $message->getText();
        $state_id = $chat->getId();

        // track something

        $mp = Mixpanel::getInstance($config['mixpanel_project_token']);

        $mp->registerAll([
            'chat_id' => $state_id,
        ]);

        $mp->track('telegram_message_received');

        if (empty($location)) {

            // load state (if exists)

            $state = @json_decode(@file_get_contents(__DIR__ . '/states/' . $state_id), 1);

            $time_d = empty($state['time_d']) ? 0 : $state['time_d'];
            $distance_d = empty($state['distance_d']) ? 1 : $state['distance_d'];

            // make decision from message test

            if (empty($state) || !in_array($text, ['wider', 'later', 'nearer', 'earlier'])) {
                $keyboard = new \TelegramBot\Api\Types\ReplyKeyboardMarkup([[$givemesomemoviesbutton]], null, true);
                $bot->sendMessage($message->getChat()->getId(), 'Please give me your location first', false, null, null, $keyboard);
                return;
            }

            // modify location and filter params

            $location = new \TelegramBot\Api\Types\Location();
            $location->setLatitude($state['location']['latitude']);
            $location->setLongitude($state['location']['longitude']);

            if ($text == 'wider') {
                ++$distance_d;
            }
            elseif ($text == 'nearer') {
                --$distance_d;
                if ($distance_d < 1 ) $distance_d = 1;
            }
            elseif ($text == 'later') {
                ++$time_d;
            }
            elseif ($text == 'earlier') {
                --$time_d;
            }

        }

        // save state
        // @todo move to Mongo

        if (empty($state)) {
            $state = [
                'id' => $state_id,
                'time' => time(),
                'text' => $text,
                'chat' => $chat->toJson(true),
                'location' => $location->toJson(true),
                'time_d' => $time_d,
                'distance_d' => $distance_d,
            ];
        }
        else {
            $state['text'] = $text;
            $state['time_d'] = $time_d;
            $state['distance_d'] = $distance_d;
        }

        file_put_contents(__DIR__ . '/states/' . $state_id, json_encode($state));

        // create keyboard

        if (empty($text)) {
            $buttons = [
                ['wider', 'later'],
                ['go back']
            ];
        }
        else {
            $buttons = [
                ['wider', 'later'],
                ['nearer', 'earlier'],
                ['go back']
            ];
        }

        $keyboard = new \TelegramBot\Api\Types\ReplyKeyboardMarkup($buttons, null, true);

        // create filters

        $url = $config['api_url'] . 'site/filter';

        $time_from = new \DateTime('now', new \DateTimeZone('Europe/Kiev'));
        $time_to = clone $time_from;
        $time_to->modify('+1 hour');

        if (!empty($time_d)) {
            $time_from->modify("+{$time_d} hour");
            $time_to->modify("+{$time_d} hour");
        }

        if ($time_to->format('H:i') < $time_from->format('H:i')) {
            $time_to = clone $time_from;
            $time_to->setTime(23, 59, 59);
        }

        // calculate distance

        $mind = (($distance_d ?: 1) - 1) * $maxd;
        $maxd = ($distance_d ?: 1) * $maxd;

        // explain filter

        $explainer =  implode(', ', [
            'mind=' . $mind,
            'maxd=' . $maxd,
            'from=' . $time_from->format('H:i'),
            'to=' . $time_to->format('H:i'),
        ]);

        $bot->sendMessage($message->getChat()->getId(), $explainer, false, null, null, $keyboard);

        // build and call query

        $home = new Buzz\Service('cinema');

        $bills = $home->call('site/filter', [
            'time_from' => $time_from->format('H:i'),
            'time_to' => $time_to->format('H:i'),
            'position_lat' => $location->getLatitude(),
            'position_lng' => $location->getLongitude(),
            'mind' => $mind,
            'maxd' => $maxd,
        ]);

        // track playbill count

        $mp->track('telegram_playbill_request', [
            'time_d' => $time_d,
            'distance_d' => $distance_d,
            'found' => count($bills),
        ]);

        // process empty bill

        if ($home->getLastError()) {
            $bot->sendMessage($message->getChat()->getId(), 'There was a problem performing your request. Please try again later.', false, null, null, $keyboard);
            return;
        }

        if (empty($bills)) {
            $bot->sendMessage($message->getChat()->getId(), 'It is sad, but nothing is found. Try another location or time.', false, null, null, $keyboard);
            return;
        }

        // process not empty bill

        $output = '';

        foreach ($bills as $bill) {

            $time = date('H:i', strtotime($bill['time']));

            // count movie end time
            if ($bill['movie']['duration']) {

                // add trailers and ceil duration to five minutes
                $duration = $bill['movie']['duration'];
                $duration = ceil($duration / 5) * 5 + 10;

                $endtime = DateTime::createFromFormat('H:i:s', $bill['time']);
                $endtime->modify("+{$duration} minutes");
                $endtime = $endtime->format('H:i');

                $time = "{$time}-{$endtime}";
            }

            $genres = $bill['movie']['genres'] ?: '';
            if (!empty($genres)) {
                $genres = implode(', ', array_column($genres, 'title')) . PHP_EOL;
            }

            $output .=
                "<b>$time</b> " . PHP_EOL .
                "<a href='kinoafisha.ua/ua/cinema/{$bill['city']['alias']}/{$bill['cinema']['alias']}'>{$bill['cinema']['title']}</a>" . PHP_EOL .
                "<a href='http://kinoafisha.ua/ua/films/{$bill['movie']['alias']}'>{$bill['movie']['title']}</a>" . PHP_EOL .
                $genres .
                PHP_EOL;
        }

        $bot->sendMessage($message->getChat()->getId(), $output, 'HTML', true, null, $keyboard);


    }, function($message) use ($name) {
        return true;
    });

    $bot->run();

} catch (\TelegramBot\Api\Exception $e) {
    // @todo add logging

    error_log('TelegramBot\Api\Exception:' . $e->getMessage());
}
