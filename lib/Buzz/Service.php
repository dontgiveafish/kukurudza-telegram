<?php

namespace Buzz;

class Service
{
    private $host;
    private $last_error;

    public function __construct($service, $domain = 'kukurudza.com', $ssl = false)
    {
        // get subdomain
        if (!empty($service)) {
            $service .= '.';
        }

        // ssl mode
        $ssl = $ssl ? 's' : '';

        // generate host url
        $this->host = "http{$ssl}://{$service}{$domain}/api/";

        // init guzzle client
        $this->client = new \GuzzleHttp\Client([
            'base_uri' => $this->host
        ]);
    }

    public function call($endpoint, array $data = null)
    {
        try {

            // @todo move to POST requests
            $response = $this->client->get($endpoint, [
                'query' => $data,
            ]);

            // get answer and decode

            $str = $response->getBody()->getContents();
            $answer = json_decode($str, true);

            $status_code = $response->getStatusCode();
            $reason_phrase = $response->getReasonPhrase();

            // check for errors

            if ($status_code != 200) {
                throw new \Exception("Got $status_code with message '$reason_phrase '");
            }

            if (json_last_error()) {
                throw new \Exception('JSON decode error: ' . json_last_error());
            }

            if (!empty($answer['error']['message'])) {
                throw new \Exception($answer['error']['message'], @$answer['error']['code']);
            }

            $this->last_error = false;
        }
        catch (\Exception $ex) {
            error_log('Buzz\Error: ' . $ex->getMessage());
            $this->last_error = $ex;
        }

        return @$answer['data'];
    }

    /**
     * @return \Exception
     */
    public function getLastError()
    {
        return $this->last_error;
    }
}
