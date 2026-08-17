<?php

declare(strict_types=1);

namespace BondKeeper\Fns;

interface NalogBiClientInterface
{
    public function check(string $inn): NalogBiResult;
}
