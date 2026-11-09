<?php

namespace Tests\Unit;

use App\Services\Text\TermMatcher;
use PHPUnit\Framework\TestCase;

class TermMatcherTest extends TestCase
{
    public function test_terms_are_word_prefixes(): void
    {
        $this->assertTrue(TermMatcher::contains('ищу квартиру в центре', 'квартир'));
        $this->assertTrue(TermMatcher::contains('we have two cats', 'cat'));
        $this->assertFalse(TermMatcher::contains('educational', 'cat'));   // must start a word
    }

    public function test_short_latin_last_word_must_end_the_word(): void
    {
        $this->assertTrue(TermMatcher::contains('need a studio', 'need a'));
        $this->assertFalse(TermMatcher::contains('need advice please', 'need a'));
        $this->assertFalse(TermMatcher::contains('need any tips', 'need a'));
    }
}
