<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Repositories\BookRepository;
use App\Services\BookService;
use App\Support\ContentType;
use App\Support\Licence;
use PHPUnit\Framework\TestCase;

final class CatalogueQueryTest extends TestCase
{
    public function testEachWordBecomesAPrefixTerm(): void
    {
        $this->assertSame('time* machine*', BookRepository::booleanQuery('time machine'));
    }

    public function testBooleanOperatorsAreStrippedAndTheWordsKept(): void
    {
        // Left in, these would be syntax in boolean mode rather than words. The
        // operators go and what is left is searched for: a typed "-" does not
        // silently turn into a negation nobody asked for.
        $this->assertSame('darwin* origin*', BookRepository::booleanQuery('+darwin* -(origin)'));
        $this->assertSame('exact* phrase*', BookRepository::booleanQuery('"exact phrase"'));
    }

    public function testShortWordsAreDroppedBecauseFulltextIgnoresThem(): void
    {
        // MySQL's minimum word length would drop "of" anyway; the LIKE clause in
        // the same query is what catches short searches.
        $this->assertSame('origin* species*', BookRepository::booleanQuery('origin of species'));
    }

    public function testAnEmptyQueryStaysEmpty(): void
    {
        $this->assertSame('', BookRepository::booleanQuery('   '));
        $this->assertSame('', BookRepository::booleanQuery('a of'));
    }

    public function testCommaSeparatedInputIsSplitAndTrimmed(): void
    {
        $this->assertSame(
            ['Jane Austen', 'Mary Shelley'],
            BookService::split(' Jane Austen , Mary Shelley , ')
        );
        $this->assertSame([], BookService::split(' , , '));
    }

    public function testEveryContentTypeOffersFormats(): void
    {
        foreach (ContentType::all() as $type) {
            $this->assertNotSame('', $type->label());
            $this->assertNotSame([], $type->formats());
        }

        $this->assertContains('mp3', ContentType::Audiobook->formats());
        $this->assertContains('epub', ContentType::Book->formats());
    }

    public function testOnlyTheOpenLicencesSkipEvidence(): void
    {
        $this->assertFalse(Licence::PublicDomain->needsEvidence());
        $this->assertFalse(Licence::CcBy->needsEvidence());
        $this->assertTrue(Licence::AuthorPermission->needsEvidence());
        $this->assertTrue(Licence::Unknown->needsEvidence());
    }
}
