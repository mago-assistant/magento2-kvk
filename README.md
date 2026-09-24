# Mago Assistant skill: KVK company lookup

Answers *"which industry is this customer in?"* in the Mago Assistant admin chat. It looks up a Dutch
BV or NV in the [KVK Open Dataset Basis Bedrijfsgegevens](https://developers.kvk.nl/nl/documentation/open-dataset-basis-bedrijfsgegevens-api),
which is free and needs no API key, and returns its industry (SBI activities), legal form and status.

It is built to sit next to [mago-assistant/magento2-vies](https://github.com/mago-assistant/vies):
VIES tells you whether a VAT number is valid and whose it is, this skill tells you what that company
does.

## What it does

Ask the assistant, in the admin chat panel:

- *In welke branche zit KVK 17085815?*
- *What industry is the customer on order 000000563 in?* (needs the attribute below)
- *Check btw-nummer NL810433941B01 en in welke branche zit KVK 17085815?* (uses both skills)

It answers with the main activity (for example *Activiteiten van financiële holdings (64210)*), any
other activities, the legal form, whether the registration is active, any insolvency, the start date
and the first two digits of the postcode.

## Install

```bash
composer require mago-assistant/magento2-kvk:*
bin/magento module:enable MagoAssistant_Kvk
bin/magento setup:upgrade
bin/magento cache:flush
```

Optional, under *Stores > Configuration > Mago Assistant > Mago > KVK Lookup*:

| Setting | What it is for |
| --- | --- |
| KVK Number Attribute | The attribute code your shop keeps the KVK number in. The skill reads it from the order's billing address first and from the customer second, so the admin can ask by order number. Leave it empty when the shop does not store one. |
| Cache Lifetime (days) | How long an answer is kept. Default 7. |

## How it is put together

| File | What it is for |
| --- | --- |
| `etc/di.xml` | Adds `kvk_company_lookup` to `MagoAssistant\Mago\Service\Tool\ToolRegistry`. Nothing inside `MagoAssistant_Mago` is edited. |
| `Service/Tool/CompanyLookup.php` | The tool (`ToolInterface`): description, schema, ACL, privacy classification, the mapping of the register's answer. |
| `Service/OpenDataClient.php` | The HTTP call, the cache and the reading of the register's error codes. |
| `Service/SbiCatalog.php` + `Data/Sbi2025.php` | SBI 2025 titles in Dutch and English, so the model is handed "Activiteiten van financiële holdings" instead of a bare "64210". |

## What the open dataset cannot do

**It only knows BVs and NVs.** A sole trader (eenmanszaak), VOF, foundation or association is never
in it; the register answers `IPD0005` and the skill returns `found: false`. The instructions tell the
model that this does not mean the company does not exist.

**It only takes a KVK number.** No name search, no address search, no VAT number. VIES never returns
a KVK number and a Dutch VAT number cannot be turned into one, so this skill cannot start from a VAT
number. That is why it sits next to vies instead of on top of it, and why the order lookup depends on
a KVK number your shop stores itself.

**It returns no name or address.** Those come from vies. The upside: nothing this skill returns is
personal data, so every field is declared `PiiClass::PUBLIC`.

**One request per minute per IP address.** Every answer is cached (a miss for an hour), and a rate
limit hit is remembered for a minute so the assistant does not retry into it. For more volume or for
search by name or address, the paid KVK API (Zoeken + Basisprofiel) is the next step. The cache
entries carry the tag `MAGO_KVK` in the default cache, so `bin/magento cache:flush` empties them;
`cache:clean` does not, and lowering the lifetime only affects answers fetched after the change.

**`IPD1002` is temporary.** The register answers it with a 404 while a record is being processed. The
skill reports it as an error and does not cache it, so the company is not hidden for an hour.

## Things that are easy to get wrong

**Nested fields are classified by their own key.** Mago's privacy filter walks into
`main_activity` and `other_activities` and matches `sbi_code`, `description`, `description_en` and
`sector` against the classification map by name. They have to be listed there too, or they are dropped
silently. `CompanyLookupTest::testClassificationCoversEveryKeyTheToolReturns` guards this.

**The ACL depends on the arguments.** A bare KVK number reads no shop data, so it needs only the
assistant's own skill permission. An order number needs `Magento_Sales::actions_view`, and so does an
empty input (fail closed).

## Updating the SBI table

KVK switched to SBI 2025 in September 2025. When CBS publishes a new version, download the structure
workbook (the "code zonder puntjes, NL en EN" xlsx) from the
[CBS SBI page](https://www.cbs.nl/nl-nl/onze-diensten/methoden/classificaties/activiteiten/standaard-bedrijfsindeling--sbi--)
and run:

```bash
python3 dev/generate-sbi.py sbi2025-structuur-versie-2026-code-zonder-puntjes-nl-en-en.xlsx
```

SBI titles: © Centraal Bureau voor de Statistiek. Company data: KVK Open Dataset Basis
Bedrijfsgegevens (CC BY 4.0).

## Tests

```bash
cd dev/tests/unit
php ../../../vendor/bin/phpunit -c phpunit.xml.dist ../../../vendor/mago-assistant/magento2-kvk/Test/Unit
```
