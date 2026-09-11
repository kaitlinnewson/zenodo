# Zenodo Plugin for OJS

An OJS plugin for exporting articles to [Zenodo](https://zenodo.org/).

## Compatibility

Compatible with OJS 3.6 and later.

## Installation

### For Development

- Create [a Zenodo sandbox account and API key](https://sandbox.zenodo.org)
- Copy the plugin files to `plugins/generic/zenodo`
- Run the installation tool: `php lib/pkp/tools/installPluginVersion.php plugins/generic/zenodo/version.xml`
- Set Zenodo plugin settings in Tools > Zenodo Export Plugin:
  - Enter the API key from your sandbox account
  - Enable test mode
  - If you don't have DOIs set up for your publications, enable Zenodo DOIs

## Zenodo API

This plugin uses the Invenio RDM API and does not use Zenodo's legacy API. Refer to
[the Invenio RDM documentation](https://inveniordm.docs.cern.ch/reference/rest_api_index/) for more details.

## Deposit Workflow

A workflow diagram is available in the `docs` directory which outlines the steps in the plugin's workflow from selection
of a record to deposit in Zenodo.

## Using the Plugin

### Required Metadata

Zenodo requires a title, at least one author and a publication date, and the plugin requires an article
PDF so that every record carries the full text: a PDF galley in the article's language whose file type is
the article text (not a supplementary or dependent file). A record missing any of these is not sent; it is
marked as failed with a message naming what is missing. Metadata-only records are never created. The
journal's publisher and ISSN are included in every record, and the plugin page shows a reminder when
either is not set.

### DOIs

By default, the plugin expects that exported records have a DOI, and records will not be exported if a DOI
is not set. Zenodo is able to mint their own DOIs, and this option can be enabled in the plugin settings.
The DOI minted in Zenodo is not saved in OJS.

### DOI Versioning

If DOI versioning is enabled in OJS, then the user can deposit each major version of an article to Zenodo as an
individual record. The previous version will be included in the relations metadata.

### Automatic Publishing

By default, the plugin will create a draft record in Zenodo, which can then be published in the Zenodo application.
This allows users to review the accuracy of the record or add additional metadata before publishing. This plugin includes
a setting for automatic publishing, but it's important to note that a record in Zenodo
**can't easily be deleted once it has been published** (metadata can be updated for the record).

### Funder Metadata

If the Funder metadata is enabled, the plugin will add funding metadata to the exported record.

### Embargoes and Restricted Data

If an article is embargoed and sent to Zenodo, the same embargo date will be set in Zenodo. If an article is
only accessible via a subscription model, then the data will be set as restricted in Zenodo.

### Communities

If a community is enabled in the plugin settings, the plugin will attempt to submit the record to the community in
Zenodo. Depending on the community settings, the record may be published immediately or may be published after
review. If the community submission fails for any reason, such as insufficient permissions or an API error,
the record will still be exported to Zenodo and the status and identifier will be saved.

## Tests

Unit tests live in `tests/` and a Cypress functional test in `cypress/tests/functional/`. Both run in
the GitHub workflow through `.github/actions/tests.sh`. To run them from the OJS root:

```bash
php lib/pkp/lib/vendor/bin/phpunit --configuration lib/pkp/tests/phpunit.xml plugins/generic/zenodo/tests
npx cypress run --config '{"specPattern":["plugins/generic/zenodo/cypress/tests/functional/*.cy.js"]}'
```

## License

This plugin is licensed under the GNU General Public License v3.
