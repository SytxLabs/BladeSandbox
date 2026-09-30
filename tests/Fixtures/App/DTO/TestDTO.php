<?php

namespace SytxLabs\BladeSandbox\Tests\Fixtures\App\DTO;

final readonly class TestDTO extends BaseDTO
{
    public string $name;

    public string $status;

    public function __construct(array $data = [])
    {
        $this->name = (string) ($data['name'] ?? '');
        $this->status = (string) ($data['status'] ?? '');
    }

    public function getLabel(): string
    {
        return $this->name.': '.$this->status;
    }
}
