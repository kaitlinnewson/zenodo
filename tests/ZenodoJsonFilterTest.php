<?php

/**
 * @file plugins/generic/zenodo/tests/ZenodoJsonFilterTest.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief Unit tests for the metadata helpers of the Zenodo JSON filter.
 */

namespace APP\plugins\generic\zenodo\tests;

use APP\plugins\generic\zenodo\filter\ZenodoJsonFilter;
use APP\publication\Publication;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PKP\tests\PKPTestCase;
use ReflectionMethod;

#[CoversClass(ZenodoJsonFilter::class)]
class ZenodoJsonFilterTest extends PKPTestCase
{
    /**
     * Localized publication data resolves its locale fallbacks through the request.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->mockRequest();
    }

    /**
     * Build the filter without the filter group its constructor expects; the
     * helpers under test do not touch it.
     */
    private function createFilter(): ZenodoJsonFilter
    {
        return $this->getMockBuilder(ZenodoJsonFilter::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
    }

    /**
     * Call a private helper on the filter.
     */
    private function invoke(string $method, array $args = []): mixed
    {
        $reflection = new ReflectionMethod(ZenodoJsonFilter::class, $method);
        return $reflection->invokeArgs($this->createFilter(), $args);
    }

    //
    // getRightsData()
    //
    public static function creativeCommonsUrlProvider(): array
    {
        return [
            'plain' => ['https://creativecommons.org/licenses/by/4.0', 'cc-by-4.0'],
            'trailing slash and http' => ['http://creativecommons.org/licenses/by-nc-sa/4.0/', 'cc-by-nc-sa-4.0'],
            'www host' => ['https://www.creativecommons.org/licenses/by-nd/4.0/', 'cc-by-nd-4.0'],
            'deed suffix' => ['https://creativecommons.org/licenses/by-nd/3.0/deed.fr', 'cc-by-nd-3.0'],
            'legalcode suffix' => ['https://creativecommons.org/licenses/by/4.0/legalcode', 'cc-by-4.0'],
            'upper case' => ['https://creativecommons.org/licenses/BY-NC/4.0/', 'cc-by-nc-4.0'],
            'CC0' => ['https://creativecommons.org/publicdomain/zero/1.0/', 'cc0-1.0'],
            'public domain mark' => ['https://creativecommons.org/publicdomain/mark/1.0/', 'cc-pdm-1.0'],
        ];
    }

    #[DataProvider('creativeCommonsUrlProvider')]
    public function testCreativeCommonsUrlsMapToVocabularyIds(string $url, string $id): void
    {
        $this->assertSame(['id' => $id], $this->invoke('getRightsData', [$url]));
    }

    public static function customLicenseUrlProvider(): array
    {
        return [
            'jurisdiction port' => ['https://creativecommons.org/licenses/by/3.0/de/'],
            'non-CC license' => ['https://www.gnu.org/licenses/gpl-3.0.html'],
        ];
    }

    /**
     * InvenioRDM accepts either a vocabulary id or a free-text title with a link,
     * never both, so anything outside the vocabulary is sent as the latter.
     */
    #[DataProvider('customLicenseUrlProvider')]
    public function testOtherUrlsBecomeCustomRights(string $url): void
    {
        $this->assertSame(
            ['title' => ['en' => $url], 'link' => $url],
            $this->invoke('getRightsData', [$url])
        );
    }

    public function testInvalidOrEmptyLicenseUrlsProduceNoRights(): void
    {
        $this->assertNull($this->invoke('getRightsData', ['']));
        $this->assertNull($this->invoke('getRightsData', ['   ']));
        $this->assertNull($this->invoke('getRightsData', ['not a url']));
    }

    //
    // isValidText()
    //
    public function testTextShorterThanThreeCharactersIsRejected(): void
    {
        $this->assertFalse($this->invoke('isValidText', [null]));
        $this->assertFalse($this->invoke('isValidText', ['']));
        $this->assertFalse($this->invoke('isValidText', ['ab']));
        $this->assertFalse($this->invoke('isValidText', ['  ab  ']));
        $this->assertFalse($this->invoke('isValidText', ['<p>ab</p>']));
    }

    public function testTextOfThreeCharactersOrMoreIsAccepted(): void
    {
        $this->assertTrue($this->invoke('isValidText', ['abc']));
        $this->assertTrue($this->invoke('isValidText', ['<p>abc</p>']));
        $this->assertTrue($this->invoke('isValidText', ['a b']));
    }

    //
    // withLanguage()
    //
    public function testLanguageIsAddedAsIso6393(): void
    {
        $this->assertSame(
            ['title' => 'x', 'lang' => ['id' => 'eng']],
            $this->invoke('withLanguage', [['title' => 'x'], 'en'])
        );
        $this->assertSame(
            ['title' => 'x', 'lang' => ['id' => 'fra']],
            $this->invoke('withLanguage', [['title' => 'x'], 'fr_CA'])
        );
    }

    public function testAnUnknownLocaleAddsNoLanguage(): void
    {
        $this->assertSame(['title' => 'x'], $this->invoke('withLanguage', [['title' => 'x'], 'xx_YY']));
    }

    //
    // dateEntry()
    //
    public function testDateEntryKeepsOnlyTheDatePart(): void
    {
        $this->assertSame(
            ['date' => '2025-03-01', 'type' => ['id' => 'accepted'], 'description' => 'Acceptance date'],
            $this->invoke('dateEntry', ['2025-03-01 14:22:10', 'accepted', 'Acceptance date'])
        );
    }

    public function testDateEntryAcceptsACarbonInstance(): void
    {
        $entry = $this->invoke('dateEntry', [Carbon::parse('2030-01-01'), 'available', 'Open access date']);
        $this->assertSame('2030-01-01', $entry['date']);
        $this->assertSame('available', $entry['type']['id']);
    }

    //
    // getAdditionalTitlesData()
    //
    private function createPublication(array $data): Publication
    {
        $publication = new Publication();
        $publication->setData('locale', 'en');
        foreach ($data as $key => $value) {
            $publication->setData($key, $value);
        }
        return $publication;
    }

    public function testSubtitleAndTranslatedTitlesAreListed(): void
    {
        $publication = $this->createPublication([
            'title' => ['en' => 'Signalling theory', 'fr_CA' => 'Théorie du signal'],
            'subtitle' => ['en' => 'A worked example', 'fr_CA' => 'Un exemple'],
        ]);

        $this->assertSame([
            ['title' => 'A worked example', 'type' => ['id' => 'subtitle'], 'lang' => ['id' => 'eng']],
            ['title' => 'Théorie du signal: Un exemple', 'type' => ['id' => 'translated-title'], 'lang' => ['id' => 'fra']],
        ], $this->invoke('getAdditionalTitlesData', [$publication, 'en']));
    }

    /**
     * A translation without its own subtitle must not borrow the main locale's.
     */
    public function testATranslatedTitleWithoutASubtitleStandsAlone(): void
    {
        $publication = $this->createPublication([
            'title' => ['en' => 'Signalling theory', 'fr_CA' => 'Théorie du signal'],
            'subtitle' => ['en' => 'A worked example'],
        ]);

        $titles = $this->invoke('getAdditionalTitlesData', [$publication, 'en']);

        $this->assertCount(2, $titles);
        $this->assertSame('Théorie du signal', $titles[1]['title']);
    }

    public function testThePrefixIsPartOfATranslatedTitle(): void
    {
        $publication = $this->createPublication([
            'title' => ['en' => 'Signalling theory', 'fr_CA' => 'Théorie du signal'],
            'prefix' => ['fr_CA' => 'La'],
        ]);

        $titles = $this->invoke('getAdditionalTitlesData', [$publication, 'en']);

        $this->assertSame('La Théorie du signal', $titles[0]['title']);
    }

    public function testNothingIsListedWithoutSubtitleOrTranslations(): void
    {
        $publication = $this->createPublication(['title' => ['en' => 'Signalling theory']]);

        $this->assertSame([], $this->invoke('getAdditionalTitlesData', [$publication, 'en']));
    }

    public function testTitlesTooShortForInvenioAreSkipped(): void
    {
        $publication = $this->createPublication([
            'title' => ['en' => 'Signalling theory', 'fr_CA' => 'Ab'],
            'subtitle' => ['en' => 'Ab'],
        ]);

        $this->assertSame([], $this->invoke('getAdditionalTitlesData', [$publication, 'en']));
    }

    //
    // resourceType() and getResourceTypeFromCitationType()
    //
    public function testResourceTypeCarriesTheVocabularyTitle(): void
    {
        $this->assertSame(
            ['id' => 'publication-preprint', 'title' => ['en' => 'Preprint']],
            $this->invoke('resourceType', ['publication-preprint'])
        );
    }

    public static function citationTypeProvider(): array
    {
        return [
            ['journal-article', 'publication-article'],
            ['book-chapter', 'publication-section'],
            ['proceedings-article', 'publication-conferencepaper'],
            ['posted-content', 'publication-preprint'],
            ['dataset', 'dataset'],
            ['something-new', 'publication-other'],
        ];
    }

    #[DataProvider('citationTypeProvider')]
    public function testCitationTypesMapToResourceTypes(string $citationType, string $resourceTypeId): void
    {
        $resourceType = $this->invoke('getResourceTypeFromCitationType', [$citationType]);
        $this->assertSame($resourceTypeId, $resourceType['id']);
    }

    public function testAnEmptyCitationTypeHasNoResourceType(): void
    {
        $this->assertNull($this->invoke('getResourceTypeFromCitationType', [null]));
        $this->assertNull($this->invoke('getResourceTypeFromCitationType', ['']));
    }
}
