<?php

namespace App\Console;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints a stream of text deltas as the indented block bar:ask has always
 * printed.
 *
 * The blocking version could explode() the finished answer on newlines and
 * prefix each one. A stream arrives in pieces that fall wherever the tokenizer
 * put them -- "\n", " a", "ok\nnext" -- so the indent has to be decided from a
 * running "am I at the start of a line" flag instead.
 *
 * Everything the model wrote is written OUTPUT_RAW. Symfony reads <...> as a
 * style tag, and a passage or an aside of Eddie's containing a bare < would
 * otherwise be recoloured or swallowed outright.
 */
final class IndentedWriter
{
    private bool $atLineStart = true;

    public function __construct(
        private readonly OutputInterface $output,
        private readonly string $indent = '  ',
    ) {}

    public function write(string $text): void
    {
        if ($text === '') {
            return;
        }

        $lines = explode("\n", $text);

        foreach ($lines as $index => $line) {
            if ($index > 0) {
                $this->output->write("\n", false, OutputInterface::OUTPUT_RAW);

                $this->atLineStart = true;
            }

            if ($line === '') {
                continue;
            }

            if ($this->atLineStart) {
                $this->output->write($this->indent, false, OutputInterface::OUTPUT_RAW);

                $this->atLineStart = false;
            }

            $this->output->write($line, false, OutputInterface::OUTPUT_RAW);
        }
    }

    /**
     * Close off whatever line the stream stopped on.
     *
     * Called on the error path as well as the happy one, so a failure message
     * never lands on the end of a half-written sentence.
     */
    public function close(): void
    {
        if ($this->atLineStart) {
            return;
        }

        $this->output->write("\n", false, OutputInterface::OUTPUT_RAW);

        $this->atLineStart = true;
    }
}
