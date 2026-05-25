<?php

namespace App\Support;

class DeveloperCommandResult
{
    public function __construct(
        public string $label,
        public string $command,
        public int $exitCode,
        public string $output,
        public bool $successful,
    ) {}

    /**
     * @return array{label: string, command: string, exit_code: int, output: string, successful: bool}
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'command' => $this->command,
            'exit_code' => $this->exitCode,
            'output' => trim($this->output) !== '' ? $this->output : 'Command completed without output.',
            'successful' => $this->successful,
        ];
    }
}
