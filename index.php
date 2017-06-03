<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/lib/Buzz/Service.php';
require_once __DIR__ . '/lib/Helpers/Location.php';

try {

    $config = require __DIR__ . '/config.php';
    $bot = new \TelegramBot\Api\Client($config['bot_token'], $config['bot_tracker']);

    $bot->on(function(\TelegramBot\Api\Types\Message $message) use ($bot, $config) {

        // more config

        $givemesomemoviesbutton = [
            'text' => 'Що у кіно зараз і поруч?',
            'request_location' => true,
        ];

        // @todo move to actions
        $commands = [
            'що у кіно зараз і поруч?',
            'подалі',
            'ближче',
            'пізніше',
            'раніше',
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

        // send typing chat action

        $bot->sendChatAction($message->getChat()->getId(), 'typing');

        // try to get location from text

        $command = mb_strtolower($text, 'utf8');

        if (empty($location) && !empty($text) && !in_array($command, $commands))
        {
            $address = implode(', ', [
                'Україна',
                'Київ',
                $text
            ]);

            $location = \Helpers\Location::createFromAddress($address);
            if (empty($location)) {
                $keyboard = new \TelegramBot\Api\Types\ReplyKeyboardMarkup([[$givemesomemoviesbutton]], null, true);
                $bot->sendMessage($message->getChat()->getId(), 'Перепрошую, але треба уточнити ваше розташування. Надішліть свою локацію у вкладенні, або вкажіть точніше вашу вулицю чи район.', false, null, null, $keyboard);
                return;
            }



            $first_state = true;
        }

        if (empty($location)) {

            // load state (if exists)

            $state = @json_decode(@file_get_contents(__DIR__ . '/states/' . $state_id), 1);

            $time_d = empty($state['time_d']) ? 0 : $state['time_d'];
            $distance_d = empty($state['distance_d']) ? 1 : $state['distance_d'];

            // make decision from message test

            if (empty($state) || !in_array($command, ['подалі', 'пізніше', 'ближче', 'раніше'])) {
                $keyboard = new \TelegramBot\Api\Types\ReplyKeyboardMarkup([[$givemesomemoviesbutton]], null, true);
                $bot->sendMessage($message->getChat()->getId(), 'Щоб підібрати сеанс, надішліть мені свою локацію', false, null, null, $keyboard);
                return;
            }

            // modify location and filter params

            $location = new \TelegramBot\Api\Types\Location();
            $location->setLatitude($state['location']['latitude']);
            $location->setLongitude($state['location']['longitude']);

            if ($command == 'подалі') {
                ++$distance_d;
            }
            elseif ($command == 'ближче') {
                --$distance_d;
                if ($distance_d < 1 ) $distance_d = 1;
            }
            elseif ($command == 'пізніше') {
                ++$time_d;
            }
            elseif ($command == 'раніше') {
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

        if (empty($text) || !empty($first_state)) {
            $buttons = [
                ['Подалі', 'Пізніше'],
                [$givemesomemoviesbutton]
            ];
        }
        else {
            $buttons = [
                ['Подалі', 'Пізніше'],
                ['Ближче', 'Раніше'],
                [$givemesomemoviesbutton]
            ];
        }

        $keyboard = new \TelegramBot\Api\Types\ReplyKeyboardMarkup($buttons, null, true);

        // create filters

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

        // build and call query

        $home = new Buzz\Service('home');

        $bills = $home->call('playbill', [
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
            $bot->sendMessage($message->getChat()->getId(), 'Овва! Щось сталося із сервером, будь ласка, зайдіть пізніше.', false, null, null, $keyboard);
            return;
        }

        if (empty($bills)) {
            $bot->sendMessage($message->getChat()->getId(), 'Це сумно, але нічого немає. Спробуйте інший час або локацію.', false, null, null, $keyboard);
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

            $cinema_link = "https://kukurudza.com/cinema/item?alias={$bill['cinema']['alias']}";
            $movie_link = "https://kukurudza.com/movie/index?alias={$bill['movie']['alias']}";

            $location_query = http_build_query([
                'pos_lat' => $location->getLatitude(),
                'pos_lng' => $location->getLongitude(),
            ]);

            $output .=
                "<b>$time</b> " . PHP_EOL .
                "<a href='{$cinema_link}&{$location_query}'>{$bill['cinema']['title']}</a>" . PHP_EOL .
                "<a href='{$movie_link}&{$location_query}'>{$bill['movie']['title']}</a>" . PHP_EOL .
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
