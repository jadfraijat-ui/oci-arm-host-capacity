<?php

namespace Hitrov\Exception;

use Exception;

class ApiCallException extends Exception
{
    private string $response;

    public function __construct(string $response, int $code = 0, ?Exception $previous = null)
    {
        $this->response = $response;
        parent::__construct($this->extractMessage($response), $code, $previous);
    }

    public function getResponse(): string
    {
        return $this->response;
    }

    private function extractMessage(string $response): string
    {
        $data = json_decode($response, true);
        if (isset($data['message'])) {
            return $data['message'];
        }
        if (isset($data['error']['message'])) {
            return $data['error']['message'];
        }
        return $response;
    }
}
