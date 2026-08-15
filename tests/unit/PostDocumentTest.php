<?php

/*
 * This file is part of linkrobins/flarum-forage.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Forage\Tests\unit;

use LinkRobins\Forage\PostDocument;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PostDocumentTest extends TestCase
{
    protected PostDocument $documents;

    protected function setUp(): void
    {
        parent::setUp();

        $this->documents = new PostDocument();
    }

    /**
     * Flarum stores posts as s9e XML. If that went to the search server as-is,
     * a search for "url" would match every post containing a link.
     *
     * @test
     */
    #[Test]
    public function it_strips_markup_to_plain_text(): void
    {
        $content = '<r><p>Hello <EM><s>*</s>there<e>*</e></EM> <URL url="https://example.com">example.com</URL></p></r>';

        $this->assertEquals('Hello * there * example.com', $this->documents->plainText($content));
    }

    /** @test */
    #[Test]
    public function it_collapses_whitespace(): void
    {
        $this->assertEquals('one two three', $this->documents->plainText("<t><p>one\n\n  two\t\tthree</p></t>"));
    }

    /** @test */
    #[Test]
    public function it_decodes_entities(): void
    {
        $this->assertEquals('Tom & Jerry', $this->documents->plainText('<t><p>Tom &amp; Jerry</p></t>'));
    }

    /**
     * An enormous post must not be able to push a request past the search
     * server's body limit and take the whole batch down with it.
     *
     * @test
     */
    #[Test]
    public function it_caps_the_length_of_a_document(): void
    {
        $long = str_repeat('a', PostDocument::MAX_CONTENT + 5000);

        $this->assertEquals(PostDocument::MAX_CONTENT, mb_strlen($this->documents->plainText($long)));
    }

    /** @test */
    #[Test]
    public function it_counts_characters_not_bytes(): void
    {
        // A naive substr would cut a multi-byte character in half and produce
        // invalid UTF-8, which the search server rejects.
        $long = str_repeat('한', PostDocument::MAX_CONTENT + 100);

        $text = $this->documents->plainText($long);

        $this->assertEquals(PostDocument::MAX_CONTENT, mb_strlen($text));
        $this->assertTrue(mb_check_encoding($text, 'UTF-8'));
    }
}
