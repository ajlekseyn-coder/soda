<?php

declare(strict_types=1);

namespace Cosmira\Soda\Analysis;

use PhpParser\Error;
use PhpParser\ParserFactory;

use function preg_match;
use function str_starts_with;
use function strlen;
use function trim;

final class CommentedCodeDetector
{
    /**
     * Syntax-like candidates still require a complete PHP statement or fragment.
     */
    private const EXPLICIT_CODE_PATTERNS = [
        '/\b(?:final\s+|abstract\s+)?(?:class|interface|trait|enum)\s+[A-Za-z_\\\\]/',
        '/\bfunction\s+[A-Za-z_]/',
        '/\bfn\s*\(/',
        '/\bnew\s+[A-Za-z_\\\\][A-Za-z0-9_\\\\]*\s*\(/',
        '/\b(?:public|protected|private)\b(?:\s+(?:static|readonly|final))*\s+(?:function|\$|const)\b/',
    ];

    /**
     * Determine whether commented code applies to the supplied input.
     */
    public static function isCommentedCode(string $commentLine): bool
    {
        $line = trim(preg_replace('/`+[^`]*`+/', '', $commentLine) ?? $commentLine);

        if (self::isClearlyNotCode($line)) {
            return false;
        }

        return (self::isExplicitCodePatternMatch($line) || self::signalScore($line) >= 3)
            && self::isPhpSyntax(trim($commentLine));
    }

    /**
     * Determine whether clearly not code applies to the supplied input.
     */
    private static function isClearlyNotCode(string $line): bool
    {
        return $line === ''
            || strlen($line) < 3
            || str_starts_with($line, '@');
    }

    /**
     * Determine whether explicit code pattern match applies to the supplied input.
     */
    private static function isExplicitCodePatternMatch(string $line): bool
    {
        foreach (self::EXPLICIT_CODE_PATTERNS as $pattern) {
            if (self::isPatternMatch($pattern, $line)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Combine syntax-like signals to estimate whether a comment contains disabled code.
     */
    private static function signalScore(string $line): int
    {
        $score = 0;

        $score += self::isPatternMatch('/\$\w+/', $line) ? 2 : 0;
        $score += self::isPatternMatch('/(?:->|::)\s*[A-Za-z_]/', $line) ? 2 : 0;
        $score += self::isPatternMatch('/\b(?:if|elseif|foreach|for|while|switch|catch)\s*\(/', $line) ? 2 : 0;
        $score += self::isPatternMatch('/\b(?:return|throw|yield)\b/', $line) ? 1 : 0;
        $score += self::isPatternMatch('/=\s*[^=]/', $line) ? 1 : 0;
        $score += self::isPatternMatch('/[;{}]\s*$/', $line) ? 1 : 0;

        return $score + (self::isPatternMatch('/\b(?:null|true|false)\b/', $line) ? 1 : 0);
    }

    /**
     * Parse the entire line; declaration words embedded in prose are insufficient.
     */
    private static function isPhpSyntax(string $line): bool
    {
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $closing = self::closingDelimiters($line);
        foreach ([['', ';'], ['', ' {}'], ['abstract class Commented {', '}'],
            ['abstract class Commented {', ' {}}'], ['$value = [', '];'], ['$value', ';'], ['try {}', ''],
            ['if (true) {}', ''], ['switch ($value) {', '}'],
        ] as [$prefix, $suffix]) {
            try {
                $parser->parse('<?php '.$prefix."\n".$line."\n".$closing.$suffix);

                return true;
            } catch (Error) {
                continue;
            }
        }

        return false;
    }

    /**
     * Unfinished argument lists and control bodies can continue on the next comment line.
     */
    private static function closingDelimiters(string $line): string
    {
        $pairs = ['(' => ')', '[' => ']', '{' => '}'];
        $closing = [];
        foreach (token_get_all('<?php '.$line) as $token) {
            if (is_array($token)) {
                [$id] = $token;
                if (! in_array($id, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                    continue;
                }

                $token = '{';
            }

            if (isset($pairs[$token])) {
                $closing[] = $pairs[$token];
            } elseif ($token === end($closing)) {
                array_pop($closing);
            }
        }

        return implode('', array_reverse($closing));
    }

    /**
     * Determine whether pattern match applies to the supplied input.
     */
    private static function isPatternMatch(string $pattern, string $line): bool
    {
        return preg_match($pattern, $line) === 1;
    }
}
