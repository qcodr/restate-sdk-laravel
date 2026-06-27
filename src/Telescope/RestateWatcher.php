<?php

declare(strict_types=1);

namespace Qcodr\Restate\Laravel\Telescope;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Qcodr\Restate\Laravel\RestateManager;

/**
 * A Laravel Telescope watcher that tags Restate ingress dispatches.
 *
 * Telescope already records every outgoing HTTP call its `ClientRequestWatcher` sees, and the
 * {@see \Qcodr\Restate\Laravel\Client\RestateClient} dispatches through Laravel's HTTP client —
 * so the service/handler/payload/duration of each Restate call is *already* in Telescope as a
 * `client_request` entry. What that entry lacks is Restate identity: from a bare URI an
 * operator cannot filter to "all calls to the `Orders` workflow" or "every send to object key
 * `tenant-7`". This watcher closes that gap by registering a Telescope tag callback that
 * recognises Restate ingress URIs and attaches `restate` / `restate:*` tags ({@see RestateDispatch::tags()}).
 *
 * Telescope is an optional dependency: it appears only in the package's composer `suggest`,
 * never `require`. Every reference to it is therefore guarded by a string-literal
 * {@see \class_exists()} check (never a `::class` constant, which would require the class to be
 * loadable), and the watcher exposes its record-shaping logic as plain methods so it can be
 * unit-tested without Telescope installed. When Telescope is absent {@see self::register()} is
 * a no-op.
 *
 * @internal native-call note: the class names below are intentionally string literals, not
 *           `::class` references, precisely so static analysis and autoloading never need the
 *           optional Telescope package to be present.
 */
final class RestateWatcher
{
    /**
     * Telescope's entry point, named as a string so its absence never breaks analysis or
     * autoloading. `Telescope::tag()` registers a global callback run for every recorded entry.
     */
    private const TELESCOPE_CLASS = 'Laravel\\Telescope\\Telescope';

    /**
     * The Telescope entry type emitted for outgoing HTTP client calls — the only type this
     * watcher tags.
     */
    private const CLIENT_REQUEST_TYPE = 'client_request';

    /**
     * Register the Restate tagging callback with Telescope, when Telescope is installed.
     *
     * Resolves the configured ingress base URL (so only genuine Restate dispatches are tagged)
     * and hands Telescope a closure that delegates to {@see self::tagsForEntry()}. A no-op when
     * Telescope is absent.
     */
    public function register(Application $app): void
    {
        $telescope = self::TELESCOPE_CLASS;
        if (!\class_exists($telescope)) {
            return;
        }

        $telescope::tag($this->tagCallback($this->resolveBaseUrl($app)));
    }

    /**
     * Build the Telescope tag callback for a given ingress base URL.
     *
     * Telescope invokes the returned closure with one `IncomingEntry` argument; rather than
     * type-hint that optional class, the closure reads the entry's public `type` and `content`
     * through {@see \get_object_vars()} and delegates the actual decision to the fully testable
     * {@see self::tagsForEntry()}.
     *
     * @return Closure(object): list<string>
     */
    public function tagCallback(string $baseUrl): Closure
    {
        return function (object $entry) use ($baseUrl): array {
            $vars = \get_object_vars($entry);

            return $this->tagsForEntry($baseUrl, $vars['type'] ?? null, $vars['content'] ?? null);
        };
    }

    /**
     * The pure record-shaping core: given a Telescope entry's `type` and `content`, return the
     * Restate tags to attach, or an empty list when the entry is not a Restate ingress dispatch.
     *
     * Only `client_request` entries whose `content.uri` parses as a Restate ingress dispatch
     * ({@see RestateDispatch::fromIngressUri()}) are tagged; everything else yields `[]`, leaving
     * the entry untouched. Extracted as a public method so it can be exercised directly in unit
     * tests with plain arrays, no Telescope required.
     *
     * @param string $baseUrl the Restate ingress base URL dispatches are sent to
     * @param mixed  $type     the entry's `type` (only `client_request` is tagged)
     * @param mixed  $content  the entry's `content` (expected to be an array with a `uri` key)
     *
     * @return list<string>
     */
    public function tagsForEntry(string $baseUrl, mixed $type, mixed $content): array
    {
        if ($type !== self::CLIENT_REQUEST_TYPE || !\is_array($content)) {
            return [];
        }

        $uri = $content['uri'] ?? null;
        if (!\is_string($uri)) {
            return [];
        }

        return RestateDispatch::fromIngressUri($baseUrl, $uri)?->tags() ?? [];
    }

    /**
     * The configured Restate ingress base URL, read from the {@see RestateManager} so the
     * watcher tags exactly the host the client dispatches to.
     */
    private function resolveBaseUrl(Application $app): string
    {
        return $app->make(RestateManager::class)->ingressConfig()['url'];
    }
}
