<?php

namespace Acme\Demo;

/**
 * @api
 */
class Greeter
{
    public function greet(string $name): string
    {
        return 'Hello ' . $name;
    }

    public function farewell(string $name): string
    {
        return 'Bye ' . $name;
    }
}
