<?php

declare(strict_types=1);

namespace Cosmira\Soda\Rules\Documentation;

use PhpParser\Comment\Doc;
use PhpParser\Node;

final class MultilinePhpDocComment
{
    /**
     * Require a multiline PHPDoc block with a description before its tags.
     */
    public function isValid(Node $node): bool
    {
        $comment = $node->getDocComment();
        $isDoc = $comment instanceof Doc;

        if (! $isDoc) {
            return false;
        }

        return $this->isMultilineWithDescription($comment);
    }

    /**
     * Check the block layout and the presence of a written description.
     */
    private function isMultilineWithDescription(Doc $comment): bool
    {
        $lines = preg_split('/\R/', trim($comment->getText()));
        $isTooShort = $lines === false || count($lines) < 3;

        if ($isTooShort) {
            return false;
        }

        $openingLine = array_shift($lines);
        $closingLine = array_pop($lines);

        return trim($openingLine) === '/**'
            && trim($closingLine) === '*/'
            && $this->hasDescription($lines);
    }

    /**
     * Find prose before the first tag, excluding tag continuations.
     *
     * @param list<string> $lines
     */
    private function hasDescription(array $lines): bool
    {
        foreach ($lines as $line) {
            $content = trim(ltrim(trim($line), '*'));
            if (str_starts_with($content, '@')) {
                return false;
            }

            if (preg_match('/\p{L}/u', $content) === 1) {
                return true;
            }
        }

        return false;
    }
}
