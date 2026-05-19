<?php

namespace G4T\BeeQueue\Contracts;

interface JobContract
{
    public function handle(): void;
}
