<?php

namespace SytxLabs\BladeSandbox\Tests\Fixtures\App\DTO;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use SytxLabs\BladeSandbox\Tests\Fixtures\Models\User;

/**
 * DTO exercising the object-graph rules: every return type from the specification.
 */
final readonly class DeploymentDTO extends BaseDTO
{
    public string $name;

    public string $status;

    public Carbon $createdAt;

    private string $secret;

    public function __construct(array $data = [])
    {
        $this->name = (string) ($data['name'] ?? 'api');
        $this->status = (string) ($data['status'] ?? 'running');
        $this->createdAt = $data['createdAt'] ?? Carbon::create(2026, 1, 2, 3, 4, 5);
        $this->secret = 'top-secret';
    }

    public function getLabel(): string
    {
        return $this->name.': '.$this->status;
    }

    public function label(): string
    {
        return 'Label '.$this->name;
    }

    public function greet(string $who = 'you'): string
    {
        return 'Hello '.$who;
    }

    public function getProject(): ProjectDTO
    {
        return new ProjectDTO(['title' => 'Blade Sandbox']);
    }

    public function getUser(): User
    {
        return User::query()->firstOrFail();
    }

    /** @return Collection<int, ProjectDTO> */
    public function getCollection(): Collection
    {
        return collect([new ProjectDTO(['title' => 'one']), new ProjectDTO(['title' => 'two'])]);
    }

    public function getDate(): Carbon
    {
        return Carbon::create(2026, 9, 26, 12, 0, 0);
    }

    public function getSomething(): mixed
    {
        return User::query()->firstOrFail();
    }

    private function hiddenMethod(): string
    {
        return 'private-method-called';
    }
}
