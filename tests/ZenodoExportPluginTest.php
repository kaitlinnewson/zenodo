<?php

/**
 * @file plugins/generic/zenodo/tests/ZenodoExportPluginTest.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief Unit tests for the Zenodo export plugin.
 */

namespace APP\plugins\generic\zenodo\tests;

use APP\issue\Issue;
use APP\issue\Repository as IssueRepository;
use APP\journal\Journal;
use APP\plugins\generic\zenodo\ZenodoExportPlugin;
use APP\plugins\PubObjectsExportPlugin;
use APP\publication\enums\VersionStage;
use APP\publication\Publication;
use APP\submission\Submission;
use APP\submissionFile\Repository as SubmissionFileRepository;
use Exception;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PKP\db\DAORegistry;
use PKP\doi\Doi;
use PKP\galley\Galley;
use PKP\submission\Genre;
use PKP\submission\GenreDAO;
use PKP\submissionFile\SubmissionFile;
use PKP\tests\PKPTestCase;
use ReflectionMethod;

#[CoversClass(ZenodoExportPlugin::class)]
class ZenodoExportPluginTest extends PKPTestCase
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
     * @copydoc PKPTestCase::getMockedDAOs()
     */
    protected function getMockedDAOs(): array
    {
        return ['GenreDAO'];
    }

    protected function tearDown(): void
    {
        app()->forgetInstance(IssueRepository::class);
        app()->forgetInstance(SubmissionFileRepository::class);
        parent::tearDown();
    }

    /**
     * Build the plugin with its settings stubbed out.
     *
     * @param array $settings Plugin setting name => value
     */
    private function createPlugin(array $settings = []): ZenodoExportPlugin
    {
        $plugin = $this->getMockBuilder(ZenodoExportPlugin::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getSetting'])
            ->getMock();

        $plugin->method('getSetting')
            ->willReturnCallback(fn ($contextId, $name) => $settings[$name] ?? null);

        return $plugin;
    }

    /**
     * Call a protected method on the plugin.
     */
    private function invoke(object $object, string $method, array $args = []): mixed
    {
        $reflection = new ReflectionMethod($object, $method);
        return $reflection->invokeArgs($object, $args);
    }

    private function createJournal(): Journal
    {
        $journal = new Journal();
        $journal->setId(1);
        $journal->setData('primaryLocale', 'en');
        return $journal;
    }

    /**
     * A publication carrying everything Zenodo requires.
     */
    private function createPublication(array $overrides = []): Publication
    {
        $publication = new Publication();
        $publication->setId(10);
        $publication->setData('locale', 'en');
        $publication->setData('title', ['en' => 'Signalling theory']);
        $publication->setData('authors', collect(['an author']));
        $publication->setData('datePublished', '2025-03-01');
        foreach ($overrides as $key => $value) {
            $publication->setData($key, $value);
        }
        return $publication;
    }

    private function bindIssueRepository(?Issue $issue): void
    {
        $issueRepository = $this->createMock(IssueRepository::class);
        $issueRepository->method('get')->willReturn($issue);
        app()->instance(IssueRepository::class, $issueRepository);
    }

    //
    // Export actions and settings
    //
    public function testDepositActionOfferedWhenAnApiKeyIsSet(): void
    {
        $plugin = $this->createPlugin(['apiKey' => 'secret']);

        $this->assertSame([
            PubObjectsExportPlugin::EXPORT_ACTION_DEPOSIT,
            PubObjectsExportPlugin::EXPORT_ACTION_EXPORT,
            PubObjectsExportPlugin::EXPORT_ACTION_MARKREGISTERED,
        ], $plugin->getExportActions($this->createJournal()));
    }

    public function testDepositActionWithheldWithoutAnApiKey(): void
    {
        $plugin = $this->createPlugin();

        $this->assertSame([
            PubObjectsExportPlugin::EXPORT_ACTION_EXPORT,
            PubObjectsExportPlugin::EXPORT_ACTION_MARKREGISTERED,
        ], $plugin->getExportActions($this->createJournal()));
    }

    /**
     * Settings are stored as strings, so the boolean accessors must read "1" as on.
     */
    public function testBooleanSettingsReadStoredStrings(): void
    {
        $on = $this->createPlugin(['mintDoi' => '1', 'automaticPublishing' => '1', 'automaticRegistration' => '1']);
        $off = $this->createPlugin(['mintDoi' => '0', 'automaticPublishing' => '', 'automaticRegistration' => null]);
        $journal = $this->createJournal();

        $this->assertTrue($on->mintZenodoDois($journal));
        $this->assertTrue($on->automaticPublishing($journal));
        $this->assertTrue($on->automaticRegistration($journal));
        $this->assertFalse($off->mintZenodoDois($journal));
        $this->assertFalse($off->automaticPublishing($journal));
        $this->assertFalse($off->automaticRegistration($journal));
    }

    public function testOnlyTheVersionOfRecordIsDepositable(): void
    {
        $this->assertSame([VersionStage::VERSION_OF_RECORD], $this->createPlugin()->getExportableVersionStages());
    }

    public function testTheZenodoIdIsStoredUnderThePluginPrefix(): void
    {
        $this->assertSame('zenodo::id', $this->createPlugin()->getIdSettingName());
    }

    //
    // convertErrorMessage()
    //
    public function testConvertErrorMessagePassesTheDetailAsParam(): void
    {
        $this->assertSame(
            __('plugins.importexport.zenodo.export.failure.missingMetadata', ['param' => 'Title']),
            $this->createPlugin()->convertErrorMessage(['plugins.importexport.zenodo.export.failure.missingMetadata', 'Title'])
        );
    }

    public function testConvertErrorMessageWithoutADetail(): void
    {
        $this->assertSame(
            __('plugins.importexport.zenodo.register.error.noApiKey'),
            $this->createPlugin()->convertErrorMessage(['plugins.importexport.zenodo.register.error.noApiKey'])
        );
    }

    //
    // getExceptionMessage()
    //
    public function testAnApiErrorResponseIsQuotedWithItsStatus(): void
    {
        $exception = new RequestException(
            'Client error',
            new Request('POST', 'https://zenodo.org/api/records'),
            new Response(400, [], '{"message":"A validation error occurred."}')
        );

        $this->assertSame(
            '{"message":"A validation error occurred."} (400 Bad Request)',
            $this->invoke($this->createPlugin(), 'getExceptionMessage', [$exception])
        );
    }

    /**
     * A connection failure carries no response, and used to fatal on hasResponse().
     */
    public function testAConnectionFailureFallsBackToItsMessage(): void
    {
        $exception = new ConnectException('Could not resolve host', new Request('GET', 'https://zenodo.org/api/records/1'));

        $this->assertSame(
            'Could not resolve host',
            $this->invoke($this->createPlugin(), 'getExceptionMessage', [$exception])
        );
    }

    public function testAnyOtherExceptionFallsBackToItsMessage(): void
    {
        $this->assertSame(
            'boom',
            $this->invoke($this->createPlugin(), 'getExceptionMessage', [new Exception('boom')])
        );
    }

    //
    // validateRequiredMetadata()
    //
    public function testCompleteMetadataPassesThePreflight(): void
    {
        $this->assertNull($this->createPlugin()->validateRequiredMetadata($this->createPublication()));
    }

    public function testAPublicationDateFromTheIssueIsEnough(): void
    {
        $issue = new Issue();
        $issue->setData('datePublished', '2025-03-01');
        $this->bindIssueRepository($issue);

        $publication = $this->createPublication(['datePublished' => null, 'issueId' => 3]);

        $this->assertNull($this->createPlugin()->validateRequiredMetadata($publication));
    }

    public function testMissingAuthorsAreReported(): void
    {
        $publication = $this->createPublication(['authors' => collect([])]);

        $this->assertSame(
            ['plugins.importexport.zenodo.export.failure.missingMetadata', __('submission.authors')],
            $this->createPlugin()->validateRequiredMetadata($publication)
        );
    }

    public function testEveryMissingFieldIsListed(): void
    {
        $this->bindIssueRepository(null);
        $publication = $this->createPublication([
            'title' => [],
            'authors' => collect([]),
            'datePublished' => null,
            'issueId' => 3,
        ]);

        $result = $this->createPlugin()->validateRequiredMetadata($publication);

        $this->assertSame(
            __('common.title') . ', ' . __('submission.authors') . ', ' . __('publication.datePublished'),
            $result[1]
        );
    }

    public function testASubmissionIsCheckedThroughItsCurrentPublication(): void
    {
        $publication = $this->createPublication(['authors' => collect([])]);
        $submission = new Submission();
        $submission->setData('publications', collect([$publication]));
        $submission->setData('currentPublicationId', $publication->getId());

        $result = $this->createPlugin()->validateRequiredMetadata($submission);

        $this->assertSame(__('submission.authors'), $result[1]);
    }

    public function testASubmissionWithoutACurrentPublicationFailsThePreflight(): void
    {
        $submission = new Submission();
        $submission->setData('publications', collect([]));

        $this->assertNotNull($this->createPlugin()->validateRequiredMetadata($submission));
    }

    //
    // validateGalleys() and getArticlePdfFile()
    //
    private const GENRE_ARTICLE = 1;
    private const GENRE_SUPPLEMENTARY = 2;
    private const GENRE_DEPENDENT = 3;

    /**
     * Register a genre DAO knowing an article-text genre, a supplementary genre
     * and a dependent genre.
     */
    private function bindGenres(): void
    {
        $genres = [];
        foreach ([
            self::GENRE_ARTICLE => [Genre::GENRE_CATEGORY_DOCUMENT, false, false],
            self::GENRE_SUPPLEMENTARY => [Genre::GENRE_CATEGORY_SUPPLEMENTARY, true, false],
            self::GENRE_DEPENDENT => [Genre::GENRE_CATEGORY_DOCUMENT, false, true],
        ] as $id => [$category, $supplementary, $dependent]) {
            $genre = new Genre();
            $genre->setId($id);
            $genre->setData('category', $category);
            $genre->setData('supplementary', $supplementary);
            $genre->setData('dependent', $dependent);
            $genres[$id] = $genre;
        }
        $genreDao = $this->createMock(GenreDAO::class);
        $genreDao->method('getById')->willReturnCallback(fn ($id) => $genres[$id] ?? null);
        DAORegistry::registerDAO('GenreDAO', $genreDao);
    }

    private function createSubmissionFile(int $id, string $mimetype, int $genreId = self::GENRE_ARTICLE): SubmissionFile
    {
        $submissionFile = new SubmissionFile();
        $submissionFile->setId($id);
        $submissionFile->setData('mimetype', $mimetype);
        $submissionFile->setData('genreId', $genreId);
        return $submissionFile;
    }

    public function testTheArticlePdfIsFoundAmongOtherGalleys(): void
    {
        $this->bindGenres();
        $html = $this->createSubmissionFile(100, 'text/html');
        $pdf = $this->createSubmissionFile(101, 'application/pdf');
        $this->bindSubmissionFiles([100 => $html, 101 => $pdf]);
        $publication = $this->createPublication([
            'galleys' => [$this->createGalley(5, 100), $this->createGalley(6, 101)],
        ]);

        $this->assertSame($pdf, $this->createPlugin()->getArticlePdfFile($publication));
        $this->assertNull($this->createPlugin()->validateGalleys($publication));
    }

    /**
     * A record without the full text would be metadata-only in Zenodo, which the
     * plugin refuses to create.
     */
    public function testGalleysWithoutAPdfFailTheGalleyCheck(): void
    {
        $this->bindGenres();
        $this->bindSubmissionFiles([100 => $this->createSubmissionFile(100, 'text/html')]);
        $publication = $this->createPublication(['galleys' => [$this->createGalley(5, 100)]]);

        $this->assertSame(
            ['plugins.importexport.zenodo.export.failure.noPdfGalley'],
            $this->createPlugin()->validateGalleys($publication)
        );
    }

    /**
     * A supplementary or dependent PDF is not the article, however it is labelled.
     */
    public function testSupplementaryAndDependentPdfsAreNotTheArticle(): void
    {
        $this->bindGenres();
        $this->bindSubmissionFiles([
            100 => $this->createSubmissionFile(100, 'application/pdf', self::GENRE_SUPPLEMENTARY),
            101 => $this->createSubmissionFile(101, 'application/pdf', self::GENRE_DEPENDENT),
        ]);
        $publication = $this->createPublication([
            'galleys' => [$this->createGalley(5, 100), $this->createGalley(6, 101)],
        ]);

        $this->assertNull($this->createPlugin()->getArticlePdfFile($publication));
    }

    public function testAPdfInAnotherLanguageIsNotTheArticle(): void
    {
        $this->bindGenres();
        $this->bindSubmissionFiles([100 => $this->createSubmissionFile(100, 'application/pdf')]);
        $publication = $this->createPublication(['galleys' => [$this->createGalley(5, 100, 'fr_CA')]]);

        $this->assertNull($this->createPlugin()->getArticlePdfFile($publication));
    }

    public function testARemoteGalleyIsNotTheArticle(): void
    {
        $this->bindGenres();
        $this->bindSubmissionFiles([100 => $this->createSubmissionFile(100, 'application/pdf')]);
        $remote = $this->createGalley(5, 100);
        $remote->setData('urlRemote', 'https://example.org/article.pdf');
        $publication = $this->createPublication(['galleys' => [$remote]]);

        $this->assertNull($this->createPlugin()->getArticlePdfFile($publication));
    }

    public function testAPublicationWithoutGalleysFailsTheGalleyCheck(): void
    {
        $this->bindGenres();

        $this->assertSame(
            ['plugins.importexport.zenodo.export.failure.noPdfGalley'],
            $this->createPlugin()->validateGalleys($this->createPublication())
        );
    }

    public function testASubmissionIsCheckedForGalleysThroughItsCurrentPublication(): void
    {
        $this->bindGenres();
        $this->bindSubmissionFiles([101 => $this->createSubmissionFile(101, 'application/pdf')]);
        $publication = $this->createPublication(['galleys' => [$this->createGalley(6, 101)]]);
        $submission = new Submission();
        $submission->setData('publications', collect([$publication]));
        $submission->setData('currentPublicationId', $publication->getId());

        $this->assertNull($this->createPlugin()->validateGalleys($submission));
    }

    //
    // validateDoi()
    //
    private function createPublicationWithDoi(string $doi): Publication
    {
        $doiObject = new Doi();
        $doiObject->setData('doi', $doi);
        return $this->createPublication(['doiObject' => $doiObject]);
    }

    public function testADoiPassesTheDoiCheck(): void
    {
        $this->assertNull(
            $this->createPlugin()->validateDoi($this->createPublicationWithDoi('10.1234/abc'), $this->createJournal())
        );
    }

    public function testAMissingDoiFailsTheDoiCheck(): void
    {
        $this->assertSame(
            ['plugins.importexport.zenodo.api.error.noDoi'],
            $this->createPlugin()->validateDoi($this->createPublication(), $this->createJournal())
        );
    }

    /**
     * With Zenodo minting DOIs, a record without one in OJS is still depositable.
     */
    public function testAMissingDoiPassesWhenZenodoMintsDois(): void
    {
        $this->assertNull(
            $this->createPlugin(['mintDoi' => '1'])->validateDoi($this->createPublication(), $this->createJournal())
        );
    }

    public function testASubmissionIsCheckedForADoiThroughItsCurrentPublication(): void
    {
        $publication = $this->createPublicationWithDoi('10.1234/abc');
        $submission = new Submission();
        $submission->setData('publications', collect([$publication]));
        $submission->setData('currentPublicationId', $publication->getId());

        $this->assertNull($this->createPlugin()->validateDoi($submission, $this->createJournal()));
    }

    //
    // getDepositableGalleys()
    //
    private function createGalley(int $id, ?int $submissionFileId, string $locale = 'en'): Galley
    {
        $galley = new Galley();
        $galley->setId($id);
        $galley->setData('submissionFileId', $submissionFileId);
        $galley->setData('locale', $locale);
        return $galley;
    }

    /**
     * @param array $files [submission file id => SubmissionFile] the repository knows
     */
    private function bindSubmissionFiles(array $files): void
    {
        $repository = $this->createMock(SubmissionFileRepository::class);
        $repository->method('get')->willReturnCallback(fn ($id) => $files[$id] ?? null);
        app()->instance(SubmissionFileRepository::class, $repository);
    }

    public function testGalleyFilesAreKeyedByGalleyId(): void
    {
        $pdf = new SubmissionFile();
        $pdf->setId(100);
        $html = new SubmissionFile();
        $html->setId(101);
        $this->bindSubmissionFiles([100 => $pdf, 101 => $html]);

        $publication = $this->createPublication([
            'galleys' => [$this->createGalley(5, 100), $this->createGalley(6, 101)],
        ]);

        $this->assertSame([5 => $pdf, 6 => $html], $this->createPlugin()->getDepositableGalleys($publication));
    }

    /**
     * Remote galleys carry no file, and a galley whose file has been removed is
     * nothing Zenodo can be sent either.
     */
    public function testGalleysWithoutAFileAreSkipped(): void
    {
        $pdf = new SubmissionFile();
        $pdf->setId(100);
        $this->bindSubmissionFiles([100 => $pdf]);

        $publication = $this->createPublication([
            'galleys' => [
                $this->createGalley(5, 100),
                $this->createGalley(6, null),
                $this->createGalley(7, 999),
            ],
        ]);

        $this->assertSame([5 => $pdf], $this->createPlugin()->getDepositableGalleys($publication));
    }

    public function testAPublicationWithoutGalleysHasNothingToDeposit(): void
    {
        $this->assertSame([], $this->createPlugin()->getDepositableGalleys($this->createPublication()));
    }
}
