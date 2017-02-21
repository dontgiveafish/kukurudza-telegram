<?php

namespace Buzz;

class Service
{
    private $host;
    private $last_error;

    public function __construct($service, $domain = 'dontgiveafish.com', $ssl = false)
    {
        // get subdomain
        if (!empty($service)) {
            $service .= '.';
        }

        // ssl mode
        $ssl = $ssl ? 's' : '';

        // generate host url
        $this->host = "http{$ssl}://{$service}{$domain}/";

        // init curl
        $curl = curl_init();

//        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION , 1);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_USERAGENT, 'buzz');

        $this->curl = $curl;
    }

    public function call($endpoint, array $data = null)
    {
        $last_error = false;
        $query = http_build_query($data);
        $url = "{$this->host}$endpoint?$query";

        try {
            curl_setopt($this->curl, CURLOPT_URL, $url);
//            curl_setopt($this->curl, CURLOPT_POSTFIELDS, http_build_query($data));

            // get answer and decode

            $str = curl_exec($this->curl);
            $answer = json_decode($str, true);

            // check for errors

            if (curl_error($this->curl)) {
                throw new \Exception('Curl error: ' . curl_error($this->curl));
            }

            if (json_last_error()) {
                throw new \Exception('JSON decode error: ' . json_last_error());
            }

            if (!empty($answer['error']['message'])) {
                throw new \Exception($answer['error']['message'], @$answer['error']['code']);
            }
        }
        catch (\Exception $ex) {
            $this->last_error = $ex;
        }

//        return @$answer['data'];
        return $answer;
    }

    /**
     * @return \Exception
     */
    public function getLastError()
    {
        return $this->last_error;
    }
}
