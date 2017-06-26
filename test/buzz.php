<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/Buzz/Service.php';
require_once __DIR__ . '/../lib/Buzz/Controller.php';

// build and call query

$home = new Buzz\Service('home');

$movies = $home->playbill()->premieres([
    'date_from' => '2017-06-29',
    'date_to' => '2017-07-05',
]);

print_r($movies);
