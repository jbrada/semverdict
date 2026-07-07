<?php

namespace Acme\Demo;

/**
 * @api
 */
class Greeter
{
    public function greet(string $name, string $salutation): string
    {
        return 'Hello ' . $salutation . ' ' . $name;
    }
}
