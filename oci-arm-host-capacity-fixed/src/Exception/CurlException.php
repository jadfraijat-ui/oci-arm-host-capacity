<?php

namespace Hitrov\Exception;

use Exception;

class CurlException extends Exception
{
    private int $curlCode;

    public function __construct(string $message, int $curlCode = 0, ?Exception $previous = null)
    {
        $this->curlCode = $curlCode;
        parent::__construct($message, $curlCode, $previous);
    }

    public function getCurlCode(): int
    {
        return $this->curlCode;
    }
}
