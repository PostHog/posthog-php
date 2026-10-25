<?php

namespace PostHog\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PostHog\FeatureFlag;
use PostHog\InconclusiveMatchException;

class FeatureFlagRegexTest extends TestCase
{
    #[DataProvider('unsupportedRegexProvider')]
    public function testUnsupportedRegexIsInconclusive(string $operator, string $pattern, string $input): void
    {
        $this->expectException(InconclusiveMatchException::class);
        FeatureFlag::matchProperty(['key' => 'k', 'operator' => $operator, 'value' => $pattern], ['k' => $input]);
    }

    public static function unsupportedRegexProvider(): iterable
    {
        $cases = [
            'unbounded lookbehind' => ['(?<=a+)b', 'aab'],
            'lookahead' => ['a(?=b)', 'ab'],
            'backreference' => ['(a)\\1', 'aa'],
            'POSIX Unicode class' => ['^[[:alpha:]]+$', 'é'],
            'nested intersection' => ['^[a-z&&[^aeiou]]+$', 'bcdf'],
            'flat intersection' => ['^[a&&b]$', 'a'],
            'difference' => ['^[a-z--b]$', 'b'],
            'symmetric difference' => ['^[a~~b]$', '~'],
            'Unicode property vocabulary' => ['^\\p{Greek}+$', 'α'],
            'vertical whitespace escape' => ['^\\v$', "\n"],
            'absolute end before newline' => ['foo\\Z', "foo\n"],
            'empty class' => ['[]', 'a'],
            'negated empty class' => ['[^]', 'a'],
            'octal escape' => ['\\141', 'a'],
            'identity escape' => ['\\q', 'q'],
            'hex scalar' => ['^\\x{41}$', 'A'],
            'possessive repetition' => ['a++', 'aa'],
            'open lower bound' => ['a{,2}', 'aa'],
            'stacked repetition' => ['a*{2}', 'aa'],
            'literal brace' => ['{foo}', '{foo}'],
            'quantified start anchor' => ['^?https?://', 'https://a'],
            'quantified end anchor' => ['$?', 'x'],
            'leading repeat' => ['{2}', 'x'],
            'alternate leading repeat' => ['a|{2}', 'x'],
            'group leading repeat' => ['({2})', 'x'],
            'noncapturing leading repeat' => ['(?:{2})', 'x'],
            'empty capturing group repeat' => ['()+', 'x'],
            'empty noncapturing group repeat' => ['(?:)*', 'x'],
            'nested empty group repeat' => ['(()){2}', 'x'],
            'oversized count' => ['a{0,4294967296}', 'x'],
            'PCRE count limit' => ['a{65536}', 'x'],
            'shorthand range start' => ['[\\s-9]', '-'],
            'shorthand range end' => ['[a-\\d]', '-'],
            'word boundary budget' => ['.*\\bbot\\b.*', str_repeat('a', 140)],
            'nonword boundary budget' => ['.*\\Bbot\\B.*', str_repeat('a', 140)],
            'class identity punctuation' => ['[\\@]', '@'],
            'class nonword boundary' => ['[\\B]', 'B'],
            'multiline flags' => ['(?m)^foo$', "foo\nbar"],
            'PCRE verb' => ['(*ACCEPT)', 'anything'],
        ];
        foreach (['regex', 'not_regex'] as $operator) {
            foreach ($cases as $name => [$pattern, $input]) {
                yield "$operator $name" => [$operator, $pattern, $input];
            }
        }
    }

    #[DataProvider('uncertainUnicodeWordClassProvider')]
    public function testUncertainUnicodeWordClassIsInconclusive(
        string $operator,
        string $pattern,
        string $input
    ): void {
        $this->expectException(InconclusiveMatchException::class);
        FeatureFlag::matchProperty(['key' => 'k', 'operator' => $operator, 'value' => $pattern], ['k' => $input]);
    }

    public static function uncertainUnicodeWordClassProvider(): iterable
    {
        $inputs = [
            'nonjoiner' => "\u{200C}",
            'joiner' => "\u{200D}",
            'nonspacing mark' => "\u{0301}",
            'spacing mark' => "\u{0903}",
            'enclosing mark' => "\u{20DD}",
            'letter number' => "\u{2160}",
            'other number' => "\u{00B2}",
            'fraction' => "\u{00BC}",
            'connector' => "\u{203F}",
            'format' => "\u{200E}",
            'alphabetic symbol' => "\u{24B6}",
        ];
        foreach (['regex', 'not_regex'] as $operator) {
            foreach (['^\\w+$', '^\\W+$', '^[\\w]+$', '^[\\W]+$', '^[^\\w]+$', '^[^\\W]+$'] as $pattern) {
                foreach ($inputs as $name => $input) {
                    yield "$operator $pattern $name" => [$operator, $pattern, $input];
                }
                yield "$operator $pattern mixed input" => [$operator, $pattern, "a\u{200C}b"];
            }
        }
    }

    #[DataProvider('portableRegexProvider')]
    public function testPortableRegexStaysLocal(string $operator, string $pattern, mixed $input, bool $matched): void
    {
        $this->assertSame(
            $operator === 'regex' ? $matched : !$matched,
            FeatureFlag::matchProperty(['key' => 'k', 'operator' => $operator, 'value' => $pattern], ['k' => $input])
        );
    }

    public static function portableRegexProvider(): iterable
    {
        $cases = [
            'search' => ['pro', 'a pro plan', true],
            'negative search' => ['^pro$', 'a pro plan', false],
            'case sensitive' => ['^pro$', 'PRO', false],
            'inline case flag' => ['(?i)^pro$', 'PRO', true],
            'strict final newline' => ['^foo$', "foo\n", false],
            'internal newline' => ['foo$', "foo\nbar", false],
            'explicit newline' => ['^foo\n$', "foo\n", true],
            'carriage return dot' => ['^.$', "\r", true],
            'astral scalar dot' => ['^.$', '😀', true],
            'Unicode digits' => ['^\\d+$', '١𝟙', true],
            'Unicode word' => ['^\\w+$', 'é', true],
            'Unicode whitespace' => ['^\\s$', "\u{00a0}", true],
            'Unicode literal' => ['^é$', 'é', true],
            'ASCII email' => ['^[\\w.+-]+@example\\.com$', 'a.b+tag@example.com', true],
            'grouped repetition' => ['^(?:ab|cd){2,3}$', 'abcdab', true],
            'lazy repetition' => ['^a+?$', 'aa', true],
            'hex byte' => ['^\\x41+$', 'AA', true],
            'class backspace' => ['[\\b]', "\x08", true],
            'literal caret' => ['^\\^?$', '', true],
            'literal dollar' => ['^\\${2}$', '$$', true],
            'literal parenthesis' => ['^\\({2}$', '((', true],
            'literal alternation' => ['^\\|{2}$', '||', true],
            'class anchors' => ['^[\\^$?]+$', '$?^', true],
            'class trailing hyphen' => ['^[\\w-]+$', 'a-b', true],
            'class escaped hyphen' => ['^[a\\-b]+$', 'a-b', true],
            'escaped Unicode marker' => ['^\\\\p\\{L\\}$', '\\p{L}', true],
            'escaped inline flags' => ['^\\(\\?i\\)$', '(?i)', true],
            'empty Unicode' => ['', 'é', true],
            'empty carriage return' => ['', "\r", true],
            'explicit null' => ['^null$', null, true],
        ];
        foreach (['word' => '\\w', 'class word' => '[\\w]', 'complement nonword' => '[^\\W]'] as $name => $atom) {
            foreach (['ASCII' => 'a_1', 'Unicode letters' => 'éα', 'Unicode decimal' => '١𝟙'] as $inputName => $input) {
                $cases["$name $inputName"] = ['^' . $atom . '+$', $input, true];
            }
        }
        foreach (['nonword' => '\\W', 'class nonword' => '[\\W]', 'complement word' => '[^\\w]'] as $name => $atom) {
            foreach (['ASCII' => 'a_1', 'Unicode letters' => 'éα', 'Unicode decimal' => '١𝟙'] as $inputName => $input) {
                $cases["$name $inputName"] = ['^' . $atom . '+$', $input, false];
            }
        }
        $cases['join control without word class'] = ['^.$', "\u{200C}", true];
        $cases['mark without word class'] = ['^.$', "\u{0903}", true];
        $cases['escaped word marker'] = ['^\\\\w.$', "\\w\u{200C}", true];
        foreach (['@', ':', '_', '=', ' ', '-', '!'] as $punctuation) {
            $cases["escaped $punctuation"] = ['^a\\' . $punctuation . 'b$', 'a' . $punctuation . 'b', true];
        }
        foreach (['regex', 'not_regex'] as $operator) {
            foreach ($cases as $name => [$pattern, $input, $matched]) {
                yield "$operator $name" => [$operator, $pattern, $input, $matched];
            }
        }
    }

    public function testInvalidPortableRegexDoesNotMatchEitherOperator(): void
    {
        foreach (['[', '(', '*', '?*', '[z-a]', '[\\w'] as $pattern) {
            foreach (['regex', 'not_regex'] as $operator) {
                $this->assertFalse(FeatureFlag::matchProperty(
                    ['key' => 'k', 'operator' => $operator, 'value' => $pattern],
                    ['k' => "value\u{200C}"]
                ));
            }
        }
    }

    public function testPreflightExecutionLimitIsInconclusive(): void
    {
        $previous = ini_set('pcre.backtrack_limit', '10');
        try {
            foreach (['regex', 'not_regex'] as $operator) {
                try {
                    FeatureFlag::matchProperty(
                        ['key' => 'k', 'operator' => $operator, 'value' => '^(a?){20}$'],
                        ['k' => '']
                    );
                    $this->fail('Expected preflight execution failure');
                } catch (InconclusiveMatchException $exception) {
                    $this->assertStringStartsWith('Regex execution failed:', $exception->getMessage());
                }
            }
        } finally {
            ini_set('pcre.backtrack_limit', $previous);
        }
    }

    public function testExecutionLimitRemainsInconclusive(): void
    {
        $previous = ini_set('pcre.backtrack_limit', '10');
        try {
            foreach (['regex', 'not_regex'] as $operator) {
                try {
                    FeatureFlag::matchProperty(
                        ['key' => 'k', 'operator' => $operator, 'value' => '^(a+)+$'],
                        ['k' => str_repeat('a', 100) . '!']
                    );
                    $this->fail('Expected execution failure');
                } catch (InconclusiveMatchException $exception) {
                    $this->assertStringStartsWith('Regex execution failed:', $exception->getMessage());
                }
            }
        } finally {
            ini_set('pcre.backtrack_limit', $previous);
        }
    }
}
