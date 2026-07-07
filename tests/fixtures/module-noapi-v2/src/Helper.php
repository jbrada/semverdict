<?php

namespace Acme\Demo;

class Helper
{
    public function compute(string $input, int $mode): string
    {
        return $input . $mode;
    }
}
