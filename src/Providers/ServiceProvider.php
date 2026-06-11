<?php

declare(strict_types=1);

namespace EugeneErg\LaravelGoogleInformalIcuI18nTranslate\Providers;

use EugeneErg\GoogleInformalIcuI18nTranslator\Client\PsrClient;
use EugeneErg\GoogleInformalIcuI18nTranslator\GoogleInformalTranslator;
use EugeneErg\IcuI18nTranslator\TranslatorInterface;
use EugeneErg\ICUMessageFormatParser\Parser;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;

final class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(ClientInterface::class, static function () {
            if (class_exists(Client::class)) {
                return new Client();
            }

            throw new RuntimeException('No PSR-18 client found. Please install guzzlehttp/guzzle or bind ClientInterface.');
        });
        $this->app->bindIf(RequestFactoryInterface::class, static function () {
            if (class_exists(HttpFactory::class)) {
                return new HttpFactory();
            }

            throw new RuntimeException('No PSR-18 client found. Please install guzzlehttp/guzzle or bind RequestFactoryInterface.');
        });
        $this->app->bindIf(StreamFactoryInterface::class, static function () {
            if (class_exists(HttpFactory::class)) {
                return new HttpFactory();
            }

            throw new RuntimeException('No PSR-18 client found. Please install guzzlehttp/guzzle or bind StreamFactoryInterface.');
        });
        $this->app->bindIf(CacheInterface::class, function () {
            if ($this->app->has(Repository::class)) {
                return $this->app->make(Repository::class);
            }

            throw new RuntimeException('No PSR-18 cache found. Please bind Repository or CacheInterface.');
        });

        $this->app->extend(TranslatorInterface::class . '[]', function (array $result): array {
            /** @var string $apiUrl */
            $apiUrl = Config::get('GOOGLE_ICU_I18N_TRANSLATE_API_URL', 'https://translate.googleapis.com');

            $result[] = new GoogleInformalTranslator(
                client: new \EugeneErg\GoogleInformalIcuI18nTranslator\Client\Client(
                    psrClient: new PsrClient(
                        client: $this->app->make(ClientInterface::class),
                        requestFactory: $this->app->make(RequestFactoryInterface::class),
                        streamFactory: $this->app->make(StreamFactoryInterface::class),
                    ),
                    apiUrl: $apiUrl,
                ),
                parser: $this->app->make(Parser::class),
                cache: $this->app->make(CacheInterface::class),
            );

            return $result;
        });
    }
}
