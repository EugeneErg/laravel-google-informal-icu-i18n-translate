# laravel-google-informal-icu-i18n-translate

> Laravel integration for Google Translate that automatically translates ICU MessageFormat strings with database caching and informal (conversational) style support.

[![PHP](https://img.shields.io/badge/PHP-%5E8.2-blue)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/Laravel-%5E13.3-red)](https://laravel.com/)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

---

## Table of Contents

- [Description](#description)
- [Architecture & Packages](#architecture--packages)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Usage](#usage)
    - [Translating plain text](#translating-plain-text)
    - [Translating an ICU pattern with variables](#translating-an-icu-pattern-with-variables)
    - [Translation with automatic language detection](#translation-with-automatic-language-detection)
    - [Working with translation files](#working-with-translation-files)
    - [Manual translation management](#manual-translation-management)
- [Comparison with alternatives](#comparison-with-alternatives)
- [Extending: adding a custom translator](#extending-adding-a-custom-translator)
- [How it works internally](#how-it-works-internally)
- [Error handling](#error-handling)
- [FAQ](#faq)

---

## Description

This package adds automatic machine translation of **ICU MessageFormat** strings to Laravel via the free Google Translate Informal API.

**Key features:**

- Full ICU MessageFormat support — `plural`, `select`, `selectordinal`, nested constructs
- Variables (`{count}`, `{name}`) are never sent to Google Translate — they are replaced with safe placeholders and restored after translation
- Translation results are cached in the database; no repeated requests to Google are made
- Informal / conversational style support (Google Translate Informal API)
- Automatic source language detection
- Built as part of the `eugene-erg/icu-i18n-translator` ecosystem with support for multiple translation providers simultaneously

---

## Architecture & Packages

This package is a Laravel wrapper over three layers:

```
laravel-google-informal-icu-i18n-translate  ← this package (ServiceProvider, IoC registration)
    └── google-informal-icu-i18n-translate  ← GoogleInformalTranslator + HTTP client for Google
    └── laravel-icu-i18n-translate          ← Eloquent repositories, migrations, base ServiceProvider
            └── icu-i18n-translator         ← core: Translator, TranslatorInterface, ICU parser
```

| Package | Purpose |
|---|---|
| `eugene-erg/icu-i18n-translator` | Core: ICU parsing, translation orchestration, storage |
| `eugene-erg/laravel-icu-i18n-translate` | Eloquent repositories, migrations, base DI |
| `eugene-erg/google-informal-icu-i18n-translate` | HTTP client for Google Translate Informal API |
| `eugene-erg/laravel-google-informal-icu-i18n-translate` | **This package**: registers the Google translator in the Laravel IoC container |

---

## Requirements

- PHP 8.2+
- Laravel 13.3+
- `ext-intl` (required by `MessageFormatter`)
- PSR-18 HTTP client (`guzzlehttp/guzzle` is used by default and pulled in automatically)
- PSR-16 Simple Cache (Laravel's `Cache::store()` is bound automatically)

---

## Installation

### 1. Install via Composer

```bash
composer require eugene-erg/laravel-google-informal-icu-i18n-translate
```

### 2. Run migrations

Migrations are provided by the `laravel-icu-i18n-translate` package and create four tables:

```bash
php artisan migrate
```

Tables created:

| Table | Purpose |
|---|---|
| `groups` | Groups — unique ICU patterns with optional context |
| `translates` | Individual translations (one pattern variant, one locale) |
| `group_translates` | Mappings: group → translation by key and locale |
| `paths` | Translation file tree (used by `addFile` / `getFile`) |

### 3. Service Provider auto-discovery

Laravel will automatically register both Service Providers:

- `EugeneErg\LaravelIcuI18nTranslate\Providers\ServiceProvider`
- `EugeneErg\LaravelGoogleInformalIcuI18nTranslate\Providers\ServiceProvider`

No manual registration in `config/app.php` is required.

---

## Configuration

The public Google Translate API endpoint is used by default:

```
https://translate.googleapis.com
```

To override the URL (e.g. for proxying), set the environment variable in your `.env`:

```env
GOOGLE_ICU_I18N_TRANSLATE_API_URL=https://your-proxy.example.com
```

### Cache

The package uses Laravel Cache to store the list of supported Google languages (cached for 1 day). No additional setup is needed — the default cache driver from your `config/cache.php` is used automatically.

---

## Usage

All methods are available through `EugeneErg\IcuI18nTranslator\Translator`, which is registered in the Laravel IoC container. Standard constructor injection works out of the box:

```php
use EugeneErg\IcuI18nTranslator\Translator;

class MyController extends Controller
{
    public function __construct(private Translator $translator) {}
}
```

Or resolve it directly:

```php
$translator = app(\EugeneErg\IcuI18nTranslator\Translator::class);
```

---

### Translating plain text

```php
$translated = $translator->translateText(
    text: 'Hello, welcome to our service!',
    toLocale: 'ru',
    fromLocale: 'en',  // optional
);

// "Привет, добро пожаловать в наш сервис!"
```

On the first call the string is translated via Google and persisted to the database. On subsequent calls it is returned from the database with no request to Google.

---

### Translating an ICU pattern with variables

ICU MessageFormat supports dynamic variables, pluralisation, and conditional constructs. Variables are fully protected from being sent to Google — they are replaced with placeholders `{{_0_}}`, `{{_1_}}`, etc. before the request, and restored after the response is received.

```php
// Simple variable
$result = $translator->translateMessage(
    pattern: 'Hello, {name}!',
    values: ['name' => 'Ivan'],
    toLocale: 'ru',
    fromLocale: 'en',
);
// "Привет, Иван!"

// Pluralisation (plural)
$result = $translator->translateMessage(
    pattern: 'You have {count, plural, one {# message} other {# messages}}.',
    values: ['count' => 5],
    toLocale: 'de',
    fromLocale: 'en',
);
// "Sie haben 5 Nachrichten."

// Conditional selection (select)
$result = $translator->translateMessage(
    pattern: '{gender, select, male {He ordered} female {She ordered} other {They ordered}} a pizza.',
    values: ['gender' => 'female'],
    toLocale: 'fr',
    fromLocale: 'en',
);
// "Elle a commandé une pizza."
```

---

### Translation with automatic language detection

If the source language is unknown, the package will detect it automatically via Google:

```php
$result = $translator->translateMessage(
    pattern: 'Bonjour le monde!',
    values: [],
    toLocale: 'en',
    // fromLocale is omitted
);
// "Hello world!"
```

You can also call the translator directly through `TranslatorInterface`:

```php
use EugeneErg\IcuI18nTranslator\TranslatorInterface;
use EugeneErg\IcuI18nTranslator\DataTransferObjects\Variable;

/** @var TranslatorInterface $googleTranslator */
$result = $googleTranslator->translateWithDetect(
    pattern: ['Ciao ', new Variable(0), '!'],
    toLocale: 'en',
);

// $result->locale  === 'it'
// $result->pattern === ['Hello ', Variable(0), '!']
```

---

### Working with translation files

You can import and export entire localisation files (e.g. Laravel PHP arrays):

```php
// Import a translation file (format 'php' or your custom format)
$content = file_get_contents(resource_path('lang/en/messages.php'));

$translator->addFile(
    format: 'php',
    name: 'messages',
    content: $content,
    locale: 'en',
    context: null,
);

// Export the file in another language (translated automatically on demand)
$deContent = $translator->getFile(
    format: 'php',
    name: 'messages',
    locale: 'de',
);

file_put_contents(resource_path('lang/de/messages.php'), $deContent);
```

---

### Manual translation management

```php
use EugeneErg\IcuI18nTranslator\ValueObjects\GroupId;

// List groups (paginated)
$groups = $translator->getGroups(pageSize: 20, page: 1);

// Get translations for a specific group
$translates = $translator->getTranslates(
    groupId: new GroupId(42),
    locale: 'ru',
);

// Override a translation manually
$translator->setTranslate(
    groupId: new GroupId(42),
    key: '0',        // variant key ('0' for simple strings)
    locale: 'ru',
    pattern: 'Привет, мир!',
);

// Remove a translation from a group
$translator->deleteTranslateFromGroup(
    groupId: new GroupId(42),
    key: '0',
    locale: 'ru',
);
```

---

## Comparison with alternatives

| Feature | This package | `stichoza/google-translate-php` | Laravel built-in i18n |
|---|---|---|---|
| ICU MessageFormat | ✅ full | ❌ | ❌ |
| Variable protection during translation | ✅ automatic | ⚠️ manual | — |
| DB translation cache | ✅ | ❌ | — |
| Automatic language detection | ✅ | ✅ | ❌ |
| Plural / Select | ✅ | ❌ | ⚠️ limited |
| Informal / conversational style | ✅ | ❌ | — |
| Multiple providers simultaneously | ✅ | ❌ | — |
| Localisation file import/export | ✅ | ❌ | ✅ |
| Free, no API key required | ✅ | ✅ | — |

> **Note:** The Google Translate Informal API is an unofficial endpoint. It requires no API key but provides no SLA. For high-traffic production use, consider obtaining an official Google Cloud Translation API key and implementing a custom `TranslatorInterface`.

---

## Extending: adding a custom translator

The package supports registering multiple translation providers. Each provider implements `TranslatorInterface`. Providers are tried in registration order — the first one that declares support for the required language pair via `canTranslate()` is used.

### Implement the interface

```php
use EugeneErg\IcuI18nTranslator\TranslatorInterface;
use EugeneErg\IcuI18nTranslator\DataTransferObjects\Variable;
use EugeneErg\IcuI18nTranslator\ValueObjects\Translated;

class DeepLTranslator implements TranslatorInterface
{
    public function translate(
        array $pattern,
        string $fromLocale,
        string $toLocale,
        ?string $context = null,
    ): array {
        // $pattern is an array of strings and Variable objects.
        // Variable objects must NOT be passed to DeepL — only process string elements.
        // ...
        return $translatedPattern;
    }

    public function translateWithDetect(
        array $pattern,
        string $toLocale,
        ?string $context = null,
    ): Translated {
        // ...
        return new Translated(locale: 'en', pattern: $translatedPattern);
    }

    public function canTranslate(string $toLocale, ?string $fromLocale = null): bool
    {
        return in_array($toLocale, ['en', 'de', 'fr', 'ru', 'ja']);
    }
}
```

### Register in a Service Provider

```php
use EugeneErg\IcuI18nTranslator\TranslatorInterface;

public function register(): void
{
    $this->app->extend(TranslatorInterface::class . '[]', function (array $translators): array {
        array_unshift($translators, new DeepLTranslator());  // prepend for highest priority
        return $translators;
    });
}
```

---

## How it works internally

When `translateMessage()` is called, the core executes the following steps:

```
1. Parse the ICU pattern → extract variants (plural/select may produce multiple)
2. Look up a group in the DB by original pattern + context + source locale
3. If a translation for toLocale already exists in group_translates → return from DB (cache hit)
4. Otherwise → call GoogleInformalTranslator.translate():
   a. Non-text ICU nodes (variables) are replaced with {{_0_}}, {{_1_}}, ...
   b. The text portion is sent to the Google Translate Informal API
   c. The response is parsed — placeholders are restored as Variable objects
5. The result is persisted to group_translates and translates
6. The final string is formatted by PHP's MessageFormatter (ext-intl) with the provided values
```

---

## Error handling

All exceptions implement `TranslatorExceptionInterface`:

| Exception | Cause |
|---|---|
| `UnexpectedTranslateDirectionException` | No provider supports the requested language pair |
| `FormatNotFoundException` | The requested file format is not registered |
| `FileNotFoundException` | The file was not found in the database |
| `GroupNotFoundException` | The group was not found |
| `IncorrectTransferPatternException` | The ICU pattern is invalid (`MessageFormatter` error) |

```php
use EugeneErg\IcuI18nTranslator\Exceptions\TranslatorExceptionInterface;
use EugeneErg\IcuI18nTranslator\Exceptions\UnexpectedTranslateDirectionException;

try {
    $result = $translator->translateText('Hello', toLocale: 'xx-unknown');
} catch (UnexpectedTranslateDirectionException $e) {
    // No provider supports this locale
} catch (TranslatorExceptionInterface $e) {
    // Any other translator error
}
```

---

## FAQ

**Q: Is a Google API key required?**  
A: No. An unofficial public endpoint is used. If needed, the URL can be overridden via `GOOGLE_ICU_I18N_TRANSLATE_API_URL`.

**Q: What happens to `{name}` and `{count}` during translation?**  
A: They are never sent to Google. The core replaces them with `{{_0_}}`, `{{_1_}}`, etc. before the request and restores them after the response is received.

**Q: Are all `plural` variants translated separately?**  
A: Yes. Each variant (`one`, `few`, `other`) is translated independently, ensuring grammatically correct output for every form.

**Q: How do I add DeepL or ChatGPT instead of Google?**  
A: Implement `TranslatorInterface` and register it via `extend(TranslatorInterface::class . '[]', ...)` — see the [Extending](#extending-adding-a-custom-translator) section.

**Q: Can the package be used without Laravel?**  
A: Yes, by using `eugene-erg/google-informal-icu-i18n-translate` and `eugene-erg/icu-i18n-translator` directly — you will need to wire the dependencies manually via your own DI setup.

---

## License

MIT © [EugeneErg](https://github.com/EugeneErg)