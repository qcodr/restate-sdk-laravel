<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Tests\Unit\Telescope;

use PHPUnit\Framework\TestCase;
use Qcodr\Restate\Laravel\Telescope\RestateWatcher;

/**
 * Unit-tests {@see RestateWatcher}'s record-shaping in isolation — without Laravel Telescope
 * installed. The watcher's value is its tag callback, which decides whether a recorded entry is
 * a Restate ingress dispatch and, if so, what `restate:*` tags to attach. These tests feed it
 * fake Telescope-shaped entry data (a type string + a content array) and assert the tags it
 * would record, exactly as Telescope's tag pipeline would invoke it.
 */
final class RestateWatcherTest extends TestCase
{
    private const BASE_URL = 'http://localhost:8080';

    public function testTagsForEntryTagsRestateClientRequest(): void
    {
        $tags = (new RestateWatcher())->tagsForEntry(
            self::BASE_URL,
            'client_request',
            ['uri' => self::BASE_URL . '/Orders/order-9/cancel/send', 'method' => 'POST'],
        );

        self::assertSame(
            [
                'restate',
                'restate:type:send',
                'restate:service:Orders',
                'restate:handler:cancel',
                'restate:key:order-9',
            ],
            $tags,
        );
    }

    public function testTagsForEntryIgnoresNonClientRequestEntries(): void
    {
        $tags = (new RestateWatcher())->tagsForEntry(
            self::BASE_URL,
            'query',
            ['uri' => self::BASE_URL . '/Orders/order-9/cancel'],
        );

        self::assertSame([], $tags);
    }

    public function testTagsForEntryIgnoresForeignHttpCalls(): void
    {
        $tags = (new RestateWatcher())->tagsForEntry(
            self::BASE_URL,
            'client_request',
            ['uri' => 'https://api.example.com/v1/charge', 'method' => 'POST'],
        );

        self::assertSame([], $tags);
    }

    public function testTagsForEntryToleratesMalformedContent(): void
    {
        $watcher = new RestateWatcher();

        self::assertSame([], $watcher->tagsForEntry(self::BASE_URL, 'client_request', null));
        self::assertSame([], $watcher->tagsForEntry(self::BASE_URL, 'client_request', ['method' => 'POST']));
        self::assertSame([], $watcher->tagsForEntry(self::BASE_URL, 'client_request', ['uri' => 42]));
    }

    public function testTagCallbackReadsPublicEntryPropertiesViaDuckTyping(): void
    {
        // Telescope hands the callback an IncomingEntry with public `type` and `content`; the
        // watcher reads them through get_object_vars, so any object exposing those works.
        $entry = new class () {
            public string $type = 'client_request';

            /** @var array<string, mixed> */
            public array $content = ['uri' => 'http://localhost:8080/GreeterService/greet'];
        };

        $callback = (new RestateWatcher())->tagCallback(self::BASE_URL);

        self::assertSame(
            [
                'restate',
                'restate:type:call',
                'restate:service:GreeterService',
                'restate:handler:greet',
            ],
            $callback($entry),
        );
    }
}
