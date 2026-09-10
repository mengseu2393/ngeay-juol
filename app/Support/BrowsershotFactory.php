<?php

namespace App\Support;

use Spatie\Browsershot\Browsershot;

/**
 * Builds a Browsershot instance with this app's shared Chrome/Node wiring.
 *
 * Every Browsershot PDF path needs the same ~25 lines of environment plumbing
 * (node module path, sandbox, chrome path, chromium args, node/npm binaries,
 * include path) pulled from `config('services.browsershot.*')`. That block was
 * copy-pasted between `InvoicePdfService::render()` and
 * `ExportUtilityUsagesJob::generatePdf()`, and the two copies had already
 * drifted — see `nodeBinary()` below.
 *
 * Callers get a configured instance and then apply their own paper geometry,
 * orientation and output call (`pdf()` vs `save()`), because those genuinely
 * differ per document. Only the environment wiring is shared.
 *
 * Getting this wrong is expensive and SILENT: on any Throwable both callers log
 * a warning and fall back to dompdf, which cannot shape Khmer script at all.
 * After touching this class, generate a PDF and grep storage/logs/laravel.log
 * for 'render failed' — a passing test does not catch the fallback.
 */
class BrowsershotFactory
{
    /**
     * A Browsershot instance with environment wiring applied and nothing else.
     * Paper size, margins, orientation and the output call are the caller's.
     */
    public static function html(string $html): Browsershot
    {
        $browsershot = Browsershot::html($html)
            ->showBackground()
            ->setNodeModulePath(config('services.browsershot.node_module_path', base_path('node_modules')))
            ->noSandbox();

        if ($chromePath = config('services.browsershot.chrome_path')) {
            $browsershot->setChromePath($chromePath);
        }

        $browsershot->addChromiumArguments(config('services.browsershot.chromium_arguments', []));
        $browsershot->addChromiumArguments(['allow-file-access-from-files']);

        if ($nodeBinary = static::nodeBinary()) {
            $browsershot->setNodeBinary($nodeBinary);
        }

        if ($npmBinary = config('services.browsershot.npm_binary')) {
            $browsershot->setNpmBinary($npmBinary);
        }

        if ($includePath = config('services.browsershot.include_path')) {
            $browsershot->setIncludePath($includePath);
        }

        return $browsershot;
    }

    /**
     * Resolve the node binary: an explicit BROWSERSHOT_NODE_BINARY always wins,
     * otherwise fall back to the newest executable Playwright-bundled node.
     *
     * The precedence matters. The old copy in ExportUtilityUsagesJob checked the
     * Playwright glob FIRST and only consulted config when the glob came back
     * empty, so setting BROWSERSHOT_NODE_BINARY silently had no effect on the
     * utility export whenever a Playwright node happened to be installed. That
     * is the documented remedy for a broken Chrome install, so it must work.
     */
    public static function nodeBinary(): ?string
    {
        $configured = config('services.browsershot.node_binary');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $playwrightNodes = glob((string) getenv('HOME').'/.cache/ms-playwright-go/*/node') ?: [];

        usort($playwrightNodes, 'strnatcmp');

        foreach (array_reverse($playwrightNodes) as $node) {
            if (is_executable($node)) {
                return $node;
            }
        }

        return null;
    }
}
