<?php

namespace Helpers;

class Location extends \TelegramBot\Api\Types\Location
{
    /**
     * Location constructor.
     * @param $lat
     * @param $lng
     */
    public function __construct($lat, $lng)
    {
        $this->setLatitude($lat);
        $this->setLongitude($lng);
    }

    /**
     * @param $address
     * @param int $try_better
     * @return bool|Location
     */
    static function createFromAddress($address, $try_better = 3)
    {
        // @todo save and load from memcache

        // init guzzle client

        $client = new \GuzzleHttp\Client([
            'base_uri' => 'http://maps.google.com/maps/api/geocode/'
        ]);

        $response = $client->get('json', [
            'query' => [
                'address' => $address
            ],
        ]);

        $json = $response->getBody()->getContents();

        // get answer

        $answer = json_decode($json, true);
        $location = @$answer['results'][0]['geometry']['location'];

        // return new entity
        if (!empty($location)) {
            return new self($location['lat'], $location['lng']);
        }

        // try again
        if ($answer['status'] == 'ZERO_RESULTS' && $try_better) {
            sleep(1);
            return self::createFromAddress($address, $try_better - 1);
        }

        // nothing found :-(
        return false;
    }

    /**
     * Calculates the great-circle distance between two points, with the Haversine formula.
     * @param Location $to
     * @return int
     */
    public function getDistance(self $to)
    {
        // convert from degrees to radians
        $latFrom = deg2rad($this->getLatitude());
        $lonFrom = deg2rad($this->getLongitude());
        $latTo = deg2rad($to->getLatitude());
        $lonTo = deg2rad($to->getLongitude());

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $angle = 2 * asin(sqrt(pow(sin($latDelta / 2), 2) +
                cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)));
        return $angle * 6371000;
    }

}