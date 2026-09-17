<?php

use App\Console\IndentedWriter;
use Symfony\Component\Console\Output\BufferedOutput;

function written(callable $test): string
{
    $output = new BufferedOutput;

    $test(new IndentedWriter($output));

    return $output->fetch();
}

it('indents the first line and every line after a break', function (): void {
    $text = written(function (IndentedWriter $writer): void {
        $writer->write('That one is');
        $writer->write(" gin.\nShake");
        $writer->write(' and strain.');
        $writer->close();
    });

    expect($text)->toBe("  That one is gin.\n  Shake and strain.\n");
});

/**
 * A delta falls wherever the tokenizer put it, so the indent cannot be decided
 * per call -- only from a running "am I at the start of a line" flag.
 */
it('handles a delta that is nothing but a newline', function (): void {
    $text = written(function (IndentedWriter $writer): void {
        $writer->write('Evening.');
        $writer->write("\n");
        $writer->write("\n");
        $writer->write('Friend.');
        $writer->close();
    });

    expect($text)->toBe("  Evening.\n\n  Friend.\n");
});

it('leaves a blank line blank rather than indenting it', function (): void {
    $text = written(function (IndentedWriter $writer): void {
        $writer->write("One.\n\nTwo.");
        $writer->close();
    });

    expect($text)->toBe("  One.\n\n  Two.\n");
});

it('closes the last line once and only once', function (): void {
    $text = written(function (IndentedWriter $writer): void {
        $writer->write("Evening.\n");
        $writer->close();
        $writer->close();
    });

    expect($text)->toBe("  Evening.\n");
});

it('writes nothing at all when nothing was written', function (): void {
    expect(written(fn (IndentedWriter $writer) => $writer->close()))->toBe('');
});

/**
 * Symfony reads <...> as a style tag. An aside of Eddie's, or a passage quoted
 * back out of a book, containing a bare < would otherwise be recoloured or
 * swallowed outright.
 */
it('does not read what the model wrote as console markup', function (): void {
    $text = written(function (IndentedWriter $writer): void {
        $writer->write('A dash of <fg=red>bitters</> and a twist.');
        $writer->close();
    });

    expect($text)->toBe("  A dash of <fg=red>bitters</> and a twist.\n");
});
