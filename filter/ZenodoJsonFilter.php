<?php

/**
 * @file plugins/importexport/zenodo/filter/ZenodoJsonFilter.php
 *
 * Copyright (c) 2025 Simon Fraser University
 * Copyright (c) 2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ZenodoJsonFilter
 *
 * @ingroup plugins_importexport_zenodo
 *
 * @brief Class that converts an Article to a Zenodo JSON string.
 */

namespace APP\plugins\importexport\zenodo\filter;

use APP\author\Author;
use APP\core\Application;
use APP\decision\Decision;
use APP\facades\Repo;
use APP\issue\Issue;
use APP\journal\Journal;
use APP\plugins\importexport\zenodo\ZenodoExportDeployment;
use APP\plugins\importexport\zenodo\ZenodoExportPlugin;
use APP\submission\Submission;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use PKP\citation\CitationDAO;
use PKP\core\PKPString;
use PKP\db\DAORegistry;
use PKP\filter\FilterGroup;
use PKP\i18n\LocaleConversion;
use PKP\plugins\importexport\PKPImportExportFilter;
use PKP\plugins\PluginRegistry;

class ZenodoJsonFilter extends PKPImportExportFilter
{
    /**
     * Constructor
     *
     * @param FilterGroup $filterGroup
     */
    public function __construct($filterGroup)
    {
        $this->setDisplayName('Zenodo JSON export');
        parent::__construct($filterGroup);
    }

    //
    // Implement template methods from Filter
    //
    /**
     * @param Submission $pubObject
     *
     * @return string JSON
     * @throws Exception
     * @see Filter::process()
     *
     */
    public function &process(&$pubObject)
    {
        /** @var ZenodoExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();
        /** @var ZenodoExportPlugin $plugin */
        $plugin = $deployment->getPlugin();
        $cache = $plugin->getCache();

        $submissionId = $pubObject->getId();
        $publication = $pubObject->getCurrentPublication();
        $publicationId = $publication->getId();
        $publicationLocale = $publication->getData('locale');

        $issueId = $publication->getData('issueId');
        if ($cache->isCached('issues', $issueId)) {
            $issue = $cache->get('issues', $issueId); /** @var Issue $issue */
        } else {
            $issue = Repo::issue()->get($issueId);
            $issue = $issue->getJournalId() == $context->getId() ? $issue : null;
            if ($issue) {
                $cache->add($issue, null);
            }
        }

        $article = [];
        $article['metadata'] = [];

        // Upload and publication type
        $article['metadata']['upload_type'] = 'publication';
        $article['metadata']['publication_type'] = 'article';

        // $applicationName = Application::get()->getName(); // ojs2, omp, ops
        // $publicationType = match ($applicationName) {
        //     'ojs2' => 'article',
        //     'omp' => 'book',
        //     'ops' => 'preprint',
        // };

        // Publication date
        if ($publication->getData('datePublished')) {
            $publicationDate = Carbon::parse($publication->getData('datePublished'));
        } elseif ($issue->getDatePublished()) {
            $publicationDate = Carbon::parse($issue->getDatePublished());
        }

        if ($publicationDate) {
            $article['metadata']['publication_date'] = $publicationDate->format('Y-m-d');
        }

        // Article title
        if ($publication->getLocalizedTitle($publicationLocale)) {
            $article['metadata']['title'] = $publication->getLocalizedTitle($publicationLocale);
        }

        // Authors: name, affiliation and ORCID
        $articleAuthors = $publication->getData('authors');
        if ($articleAuthors->isNotEmpty()) {
            $article['metadata']['creators'] = [];

            foreach ($articleAuthors as $articleAuthor) { /** @var Author $author */
                $author = ['name' => $articleAuthor->getFullName(false, false, $publicationLocale)];
                $affiliations = $articleAuthor->getLocalizedAffiliationNamesAsString($publicationLocale);
                if (!empty($affiliations)) {
                    $author['affiliation'] = $affiliations;
                }
                if ($articleAuthor->getOrcid() && $articleAuthor->hasVerifiedOrcid()) {
                    $author['orcid'] = $articleAuthor->getOrcid();
                }
                $article['metadata']['creators'][] = $author;
            }
        }

        // Abstract
        $abstract = $publication->getData('abstract', $publicationLocale);
        if (!empty($abstract)) {
            $article['metadata']['description'] = PKPString::html2text($abstract);
        }

        // Access Rights
        // Defaults to open, which Zenodo does if not set. Subscription with no OA date is set as closed.
        $status = 'open';
        if (
            $context->getData('publishingMode') == Journal::PUBLISHING_MODE_SUBSCRIPTION &&
            $issue->getAccessStatus() == Issue::ISSUE_ACCESS_SUBSCRIPTION
        ) {
            $status = $issue->getOpenAccessDate() ? 'embargoed' : 'closed';
        }

        $article['metadata']['access_right'] = $status;

        // License and embargo date
        if ($status == 'open' || $status == 'embargoed') {
            $licenseUrl = $publication->getData('licenseUrl') ?? $context->getData('licenseUrl') ?? '';
            if (preg_match('/creativecommons\.org\/licenses\/(.*?)\//i', $licenseUrl, $match)) {
                $article['metadata']['license'] = 'cc-' . $match[1];
            }
            if ($status == 'embargoed') {
                $openAccessDate = Carbon::parse($issue->getOpenAccessDate());
                $article['metadata']['embargo_date'] = $openAccessDate->format('Y-m-d');
            }
        }

        // DOI
        $doi = $publication->getDoi();
        if (!empty($doi)) {
            $article['metadata']['doi'] = $doi;
        }

        // Keywords
        $keywords = $publication->getData('keywords', $publicationLocale);
        if (!empty($keywords)) {
            $article['metadata']['keywords'] = $keywords;
        }

        // FullText URL relation
        $request = Application::get()->getRequest();
        $url = $request->getDispatcher()->url(
            $request,
            Application::ROUTE_PAGE,
            $context->getPath(),
            'article',
            'view',
            [$submissionId],
            urlLocaleForPage: ''
        );
        $article['metadata']['related_identifiers'][] = [
            'relation' => 'isIdenticalTo',
            'identifier' => $url,
            'resource_type' => 'publication',
        ];

        // Cites relation
        // @todo once structured citations are available add related identifiers using "Cites" relation
        // @todo "dataset" is also a supported resource type and can be added when data citations are available
        // $article['metadata']['related_identifiers'][] = [
        //     'relation' => 'cites',
        //     'identifier' => '',
        //     'resource_type' => 'publication',
        // ];

        // Version relation
        // $article['metadata']['related_identifiers'][] = [
        //     'relation' => 'isVersionOf',
        //     'identifier' => '',
        //     'resource_type' => 'publication',
        // ];

        // References
        $citationDao = DAORegistry::getDAO('CitationDAO'); /** @var CitationDAO $citationDao */
        $rawCitations = $citationDao->getRawCitationsByPublicationId($publicationId)->toArray();
        if ($rawCitations) {
            $article['metadata']['references'] = $rawCitations;
        }

        // Funding metadata
        $fundingMetadata = $this->fundingMetadata($submissionId);
        if ($fundingMetadata) {
            $article['metadata']['grants'] = $fundingMetadata;
        }

        // Journal title
        $journalTitle = $context->getName($context->getPrimaryLocale());
        $article['metadata']['journal_title'] = $journalTitle;

        // Volume
        $volume = $issue->getVolume();
        if (!empty($volume)) {
            $article['metadata']['journal_volume'] = (string)$volume;
        }

        // Issue Number
        $issueNumber = $issue->getNumber();
        if (!empty($issueNumber)) {
            $article['metadata']['journal_issue'] = $issueNumber;
        }

        // Pages
        $startPage = $publication->getStartingPage();
        $endPage = $publication->getEndingPage();
        if (isset($startPage) && $startPage !== '') {
            $article['metadata']['journal_pages'] = $startPage . '-' . $endPage;
        }

        // Publisher name
        $publisher = $context->getData('publisherInstitution');
        if (!empty($publisher)) {
            $article['metadata']['imprint_publisher'] = $publisher;
        }

        // Publication version
        $version = $publication->getData('version');
        if ($version) {
            $article['metadata']['version'] = (string)$version;
        }

        // Language (ISO 639-2 or 639-3)
        $language = LocaleConversion::get3LetterFrom2LetterIsoLanguage($publicationLocale);
        if ($language) {
            $article['metadata']['language'] = $language;
        }

        // Dates
        // For an exact date, use the same value for both start and end.
        // Options: Accepted, Available, Collected, Copyrighted, Created,
        //          Issued, Other, Submitted, Updated, Valid, Withdrawn

        $editorDecision = Repo::decision()->getCollector()
            ->filterBySubmissionIds([$publicationId])
            ->getMany()
            ->first(fn (Decision $decision, $key) => $decision->getData('decision') === Decision::ACCEPT);

        if ($editorDecision) {
            $decisionDate = Carbon::parse($editorDecision->getData('dateDecided'));
            $article['metadata']['dates'][] = [
                "start" => $decisionDate->format('Y-m-d'),
                "end" => $decisionDate->format('Y-m-d'),
                "type" => "Accepted",
            ];
        }

        $json = json_encode($article, JSON_UNESCAPED_SLASHES);
        return $json;
    }

    /*
     * Helper function for funding metadata
     */
    private function fundingMetadata(int $submissionId): false|array
    {
        /** @var ZenodoExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();
        /** @var ZenodoExportPlugin $plugin */
        $plugin = $deployment->getPlugin();

        if (!PluginRegistry::getPlugin('generic', 'FundingPlugin')) {
            return false;
        }

        $funderIds = DB::table('funders')
            ->where('submission_id', $submissionId)
            ->pluck('funder_identification', 'funder_id');

        if (!$funderIds->isEmpty()) {
            foreach ($funderIds as $funderId => $funderIdentification) {
                if ($funderRor = $this->getFunderROR($funderIdentification)) {
                    $awardIds = DB::table('funder_awards')
                        ->where('funder_id', $funderId)
                        ->pluck('funder_award_number');

                    foreach ($awardIds as $awardId) {
                        if ($plugin->isValidAward($context, $funderRor, $awardId) === true) {
                            $fundData[] = [
                                'id' => str_replace('https://doi.org/', '', $funderIdentification) . '::' . $awardId
                            ];
                        }
                    }
                }
            }
        }
        return $fundData ?? false;
    }

    /*
     * May not be needed when funding plugin migrates to use ROR
     * List based on:
     * https://github.com/zenodo/zenodo/blob/master/zenodo/modules/deposit/static/json/zenodo_deposit/deposit_form.json#L538
     * https://github.com/zenodo/zenodo/issues/2371
     * mapping:
     * https://github.com/zenodo/zenodo-rdm/blob/master/legacy/zenodo_legacy/funders.py#L13
     */
    private function getFunderROR(string $funderIdentification): string|bool
    {
        return match ($funderIdentification) {
            'https://doi.org/10.13039/501100002341' => '05k73zm37', // Academy of Finland
            'https://doi.org/10.13039/501100001665' => '00rbzpz17', // Agence Nationale de la Recherche
            'https://doi.org/10.13039/100018231'    => '03zj4c476', // Aligning Science Across Parkinson’s
            'https://doi.org/10.13039/501100000923' => '05mmh0f86', // Australian Research Council
            'https://doi.org/10.13039/501100002428' => '013tf3c58', // Austrian Science Fund
            'https://doi.org/10.13039/501100000024' => '01gavpb45', // Canadian Institutes of Health Research
            'https://doi.org/10.13039/501100000780' => '00k4n6c32', // European Commission
            'https://doi.org/10.13039/501100000806' => '02k4b9v70', // European Environment Agency
            'https://doi.org/10.13039/501100001871' => '00snfqn58', // Fundação para a Ciência e a Tecnologia
            'https://doi.org/10.13039/501100004488' => '03n51vw80', // Hrvatska Zaklada za Znanost
            'https://doi.org/10.13039/501100006364' => '03m8vkq32', // Institut National Du Cancer
            'https://doi.org/10.13039/501100004564' => '01znas443', // Ministarstvo Prosvete, Nauke i Tehnološkog Razvoja
            'https://doi.org/10.13039/501100006588' => '0507etz14', // Ministarstvo Znanosti, Obrazovanja i Sporta //
            'https://doi.org/10.13039/501100000925' => '011kf5r70', // National Health and Medical Research Council
            'https://doi.org/10.13039/100000002'    => '01cwqze88', // National Institutes of Health
            'https://doi.org/10.13039/100000001'    => '021nxhr62', // National Science Foundation
            'https://doi.org/10.13039/501100000038' => '01h531d29', // Natural Sciences and Engineering Research Council of Canada
            'https://doi.org/10.13039/501100003246' => '04jsz6e67', // Nederlandse Organisatie voor Wetenschappelijk Onderzoek
            'https://doi.org/10.13039/501100001711' => '00yjd3n13', // Schweizerischer Nationalfonds zur Förderung der wissenschaftlichen Forschung
            'https://doi.org/10.13039/501100001602' => '0271asj38', // Science Foundation Ireland
            'https://doi.org/10.13039/100001345'    => '006cvnv84', // Social Science Research Council
            'https://doi.org/10.13039/501100011730' => '00x0z1472', // Templeton World Charity Foundation
            'https://doi.org/10.13039/501100004410' => '04w9kkr77', // Türkiye Bilimsel ve Teknolojik Araştırma Kurumu
            'https://doi.org/10.13039/501100000690',
            'https://doi.org/10.13039/100014013'    => '001aqnf71', // Research Councils UK, UK Research and Innovation
            'https://doi.org/10.13039/100004440'    => '029chgv08', // Wellcome Trust
            default => false,
        };
    }
}
