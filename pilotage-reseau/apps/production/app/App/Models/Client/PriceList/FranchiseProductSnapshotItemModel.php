<?php

namespace App\Models\Client\PriceList;

class FranchiseProductSnapshotItemModel implements \JsonSerializable
{
    public function __construct(private array $data)
    {
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public function jsonSerialize(): mixed
    {
        return $this->data;
    }
}
