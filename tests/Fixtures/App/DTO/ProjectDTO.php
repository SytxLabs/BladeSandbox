<?php

namespace SytxLabs\BladeSandbox\Tests\Fixtures\App\DTO;

final readonly class ProjectDTO extends BaseDTO
{
    public string $title;

    public function __construct(array $data = [])
    {
        $this->title = (string) ($data['title'] ?? '');
    }

    public function shout(): string
    {
        return strtoupper($this->title);
    }
}
