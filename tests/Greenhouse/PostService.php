<?php

declare(strict_types=1);

namespace App\Tests\Greenhouse;

/**
 * The whole domain: build-with-next-id -> store -> return the created row. This body is written
 * ONCE and reached by all three surface projectors — the duplication Command-as-atom removes.
 */
final class PostService
{
    /** @var array<int, array<string, mixed>> */
    private array $posts = [];
    private int $seq = 0;

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function create(array $input): array
    {
        $id = ++$this->seq;
        $this->posts[$id] = ['id' => $id] + $input;

        return $this->posts[$id];
    }
}
