<?php

declare(strict_types=1);

namespace LauroGuedes\DemoMode\Doctor;

/**
 * One thing 'demo:doctor' found.
 *
 * Errors and warnings are a real distinction here, not a severity gradient:
 * an error is something that will destroy data or publish a secret, and it sets
 * the exit code so a deploy pipeline stops. A warning is something that makes
 * the demo worse but not dangerous, and it does not.
 */
final readonly class Finding
{
    private function __construct(
        public string $level,
        public string $check,
        public string $message,
        public ?string $fix = null,
    ) {}

    public static function error(string $check, string $message, ?string $fix = null): self
    {
        return new self('error', $check, $message, $fix);
    }

    public static function warning(string $check, string $message, ?string $fix = null): self
    {
        return new self('warning', $check, $message, $fix);
    }

    public function isError(): bool
    {
        return $this->level === 'error';
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'level' => $this->level,
            'check' => $this->check,
            'message' => $this->message,
            'fix' => $this->fix,
        ];
    }
}
