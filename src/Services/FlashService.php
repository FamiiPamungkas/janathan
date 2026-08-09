<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Services;

class FlashService
{
    public function add(string $type, string $message): void
    {
        $_SESSION['flash'][] = [
            'type' => $type,
            'message' => $message,
        ];
    }

    /**
     * Return queued messages and clear the queue.
     *
     * @return array<int, array{type: string, message: string}>
     */
    public function all(): array
    {
        $messages = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);

        return $messages;
    }
}
